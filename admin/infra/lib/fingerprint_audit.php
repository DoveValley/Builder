<?php
/**
 * infra/lib/fingerprint_audit.php — does the fleet still hold together as isolated
 * islands, or does something link one box's domains to another's?
 *
 * ── SELF-CONTAINED ON PURPOSE ────────────────────────────────────────────────────
 * Everything this feature knows lives here. Nothing in the core libs knows it exists,
 * so it can be changed or removed whole. It borrows only what it would otherwise be
 * duplicating: the Cloudflare client (lib/cloudflare.php), the account↔box binding
 * rules (lib/cf_alloc.php) and the box registry (lib/hestia_fleet.php). All read-only.
 *
 * ── TWO CHECKS, ONE SET OF RULES ─────────────────────────────────────────────────
 * There are deliberately two entry points, answering the same question at different
 * cost, and they were previously two separate implementations in two separate files —
 * which meant a rule changed in one made the two screens disagree:
 *
 *   fingerprint_offline_check()  — reads stored state plus the last sweep. ~0.02s, no
 *                                  network. Cheap enough to run on every page load, so
 *                                  the Cloudflare tab draws it unprompted.
 *   fingerprint_live_sweep()     — asks Cloudflare about every domain. A minute or two.
 *                                  The authoritative answer, behind a button.
 *
 * Offline can only see as far as the last Refresh; live cannot be run on every page
 * load. Both are needed, and both now share one definition of what counts as a fault.
 *
 * ── WHAT A "FINGERPRINT" IS ──────────────────────────────────────────────────────
 * Every domain in one Cloudflare account shares ONE nameserver pair, tying it to that
 * account's other domains. Its A record ties it to everything else on its box.
 * cf_alloc.php binds one account to exactly one box so both ties land on the SAME
 * twenty domains — an island. So a fingerprint is not "two domains are related", which
 * inside an island is the design; it is a link that CROSSES an island boundary, because
 * that is what chains islands into one web.
 */

require_once __DIR__ . '/store.php';
require_once __DIR__ . '/state.php';
require_once __DIR__ . '/cache.php';
require_once __DIR__ . '/http.php';
require_once __DIR__ . '/cloudflare.php';
require_once __DIR__ . '/fleet.php';
require_once __DIR__ . '/hestia_fleet.php';
require_once __DIR__ . '/cf_alloc.php';

/** Where the live sweep's verdict is kept for the banner to read. */
function fingerprint_state_path(): string
{
    return dirname(__DIR__) . '/state/fingerprint.json';
}

/** The last live sweep's stored verdict, or null if there has never been one. */
function fingerprint_last(): ?array
{
    $f = fingerprint_state_path();
    $d = is_file($f) ? json_decode((string) file_get_contents($f), true) : null;
    return is_array($d) ? $d : null;
}

/**
 * Has anything gone live since that verdict? The one condition under which a stored
 * "clean" is actively misleading, so it is computed rather than left to the eye.
 */
function fingerprint_is_stale(?array $last): bool
{
    if (!$last || empty($last['at'])) return false;
    try {
        $newest = (string) (infra_state_db()
            ->query("SELECT MAX(go_live_at) FROM domains WHERE status = 'live'")->fetchColumn() ?: '');
        return $newest !== '' && strtotime($newest) > strtotime((string) $last['at']);
    } catch (Throwable $e) {
        return false;   // unreadable state is not a reason to claim staleness either way
    }
}

/**
 * Every way a domain's zone, account and box can disagree with each other.
 *
 * WHY THIS IS A PANEL AND NOT A SCRIPT. On 2026-10-21 eleven domains had their zone in
 * one account and their site on a box bound to a different one, for nine hours, with
 * every cell on the pipeline grid green. Nothing was watching the relationship BETWEEN
 * the three facts — each one was individually fine. The check costs ~0.05s against
 * stored state, so there is no reason for it to be something somebody remembers to run.
 *
 * Reads NOTHING from the network: stored fleet state plus the last sweep. That is the
 * same rule the rest of this file follows, and it has the useful property that the
 * check cannot itself fail because Cloudflare is slow. The cost is that it can only
 * see as far as the last Refresh — so `swept` is returned, and a finding derived from
 * an account nobody has swept is reported as unknown rather than as a pass.
 *
 * @return array{findings:array<int,array{domain:string,what:string,detail:string}>,
 *               checked:int, unswept:int}
 */
function fingerprint_offline_check(): array
{
    require_once __DIR__ . '/state.php';

    // zone id => account, from the last sweep of each account.
    $zoneAcct = [];
    $unswept  = 0;
    foreach (infra_cf_accounts() as $a) {
        if (!infra_cf_zones_swept($a)) { $unswept++; continue; }
        foreach (infra_cf_zones_cached($a) as $z) {
            if (!empty($z['id'])) $zoneAcct[(string) $z['id']] = $a;
        }
    }

    $findings = [];
    $checked  = 0;
    foreach (infra_state_all_domains() as $domain => $rec) {
        $zone = trim((string) ($rec['cf_zone_id'] ?? ''));
        $acct = trim((string) ($rec['cf_account_id'] ?? ''));
        $sid  = trim((string) ($rec['server_id'] ?? ''));
        if ($zone === '' && $acct === '') continue;          // nothing claimed yet; nothing to contradict
        $checked++;

        $add = function (string $what, string $detail) use (&$findings, $domain) {
            $findings[] = ['domain' => $domain, 'what' => $what, 'detail' => $detail];
        };

        $actual = $zoneAcct[$zone] ?? null;                   // where the zone really is
        $known  = false;
        foreach (infra_cf_accounts() as $a) if ((string) ($a['id'] ?? '') === $acct) { $known = true; break; }

        if ($zone !== '' && $acct === '') {
            $add('zone with no account on record',
                 'the zone exists' . ($actual ? ' in ' . $actual['label'] : '')
                 . ' but nothing records which account — this is what disarms the box guard');
        } elseif ($acct !== '' && !$known) {
            // Distinct from "the zone is gone", and the distinction matters: the console
            // holds no credential for this account, so it cannot see the zone to say
            // either way. Reporting it as missing would be a guess dressed as a fact.
            $add('account is not in the console',
                 'recorded against ' . $acct . ', which is not in cloudflare.json — so this zone '
                 . 'cannot be read, moved or deleted from here. Add the account back, or clear the reference.');
        } elseif ($zone !== '' && $actual && (string) $actual['id'] !== $acct) {
            $add('recorded account is wrong',
                 'recorded ' . infra_cf_account_label($acct) . ', actually in ' . $actual['label']);
        } elseif ($zone !== '' && !$actual && $unswept === 0) {
            $add('zone is in none of the known accounts',
                 'zone ' . substr($zone, 0, 12) . '… was not found in any swept account — it has probably been deleted at Cloudflare');
        }

        // The pairing itself: a domain must sit on the box its account is bound to, or
        // its nameserver pair and its IP point at two different groups of domains.
        $useAcct = $actual ?: null;
        if (!$useAcct && $acct !== '') {
            foreach (infra_cf_accounts() as $a) if ((string) ($a['id'] ?? '') === $acct) { $useAcct = $a; break; }
        }
        if ($useAcct && $sid !== '') {
            $bound = trim((string) ($useAcct['server_id'] ?? ''));
            if ($bound === '') {
                $add('account is bound to no box', $useAcct['label'] . ' has no box — bind it below');
            } elseif ($bound !== $sid) {
                $here = infra_hestia_server($sid);
                $there = infra_hestia_server($bound);
                $add('on the wrong box',
                     'its account ' . $useAcct['label'] . ' is bound to ' . ($there['label'] ?? $bound)
                     . ', but the domain is on ' . ($here['label'] ?? $sid)
                     . ($zone !== '' ? ' — the zone cannot move, so the domain has to' : ''));
            }
        }
    }

    return ['findings' => $findings, 'checked' => $checked, 'unswept' => $unswept];
}

