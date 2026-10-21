<?php
/**
 * infra/lib/cf_alloc.php — which Cloudflare account a domain's zone belongs in.
 *
 * WHY THIS EXISTS. Bulk provisioning picked a box and a Cloudflare account from two
 * unrelated rotations, so a site's nameservers had nothing to do with the machine it
 * ran on. That sounds harmless and is not: every domain in one Cloudflare account
 * shares ONE nameserver pair, so the pair links it to that account's other domains,
 * while its IP links it to a DIFFERENT set on its box. Those links then CHAIN — A
 * shares nameservers with B, B shares an IP with C — and one discovered site unravels
 * the fleet. Binding each account to exactly one box makes both links land on the same
 * twenty domains: isolated islands instead of one connected web.
 *
 * THE PAIRING LIVES ON THE ACCOUNT, not on the server. `server_id` is one field holding
 * one value, so "an account can only be on one box" cannot be violated — there is
 * nowhere to write a second answer.
 *
 * (Considered 2026-08-18 and NOT done: moving the binding onto the box, so one account
 * could own a block of boxes. That is sound — what causes the chain is OVERLAP, not an
 * account having several boxes — and it would let five accounts cover twenty boxes as
 * islands of four. It was rejected because the estate has twenty accounts for twenty
 * boxes, where one-to-one is both the tightest isolation available and already enforced
 * by this field. Revisit only if the account count ever drops below the box count.)
 *
 * ⚠ CLOUDFLARE CANNOT MOVE A ZONE BETWEEN ACCOUNTS. Correcting a misplaced zone means
 * delete + recreate, which issues a NEW nameserver pair, needs a registrar change and
 * re-propagation — downtime, once the site is live. So a zone placed wrongly is placed
 * wrongly for good, which is what makes the next paragraph the design of this file.
 *
 * ── PLACEMENT NEVER DEPENDS ON A NUMBER FETCHED OVER THE NETWORK ──────────────
 *
 * This began as a numeric cap: each account had max_zones, compared against a live
 * count of what it held. Every bug it produced lived in that one dependency:
 *
 *   · the count came from an unfiltered /zones call, so a brand-new empty account
 *     reported another account's 31 zones and offered "19 free"
 *   · the count had never been fetched, was read as 0, and the account looked open
 *     when it was sealed
 *   · the count had never been fetched, so free came out 0, and an account holding
 *     nothing was drawn "full"
 *
 * One chain, three ways of being wrong, each invisible and each permanent once a zone
 * landed. So the cap is gone. An account is OPEN or CLOSED — a flag a person set — and
 * allocation reads nothing else. Zone counts are still shown, because knowing how big a
 * group is getting is useful, but they are DISPLAY ONLY: a wrong count is now a wrong
 * label, fixed by pressing Refresh, and can no longer put a domain anywhere.
 */

require_once __DIR__ . '/store.php';
require_once __DIR__ . '/cache.php';
require_once __DIR__ . '/cloudflare.php';
require_once __DIR__ . '/fleet.php';
// Self-contained: the console's bootstrap happens to load this already, the CLI does
// not, and a lib that only works when something else loaded its dependencies fails the
// first time it is used somewhere new.
require_once __DIR__ . '/hestia_fleet.php';

/**
 * A stored account id as the label a person recognises.
 *
 * Lives here rather than with the pipeline because accounts are this file's subject,
 * and the grid should not have to reach up a layer to name one.
 */
function infra_cf_account_label(string $id): string
{
    $id = trim($id);
    if ($id === '') return '';
    foreach (infra_cf_accounts() as $a) {
        if ((string) ($a['id'] ?? '') === $id) return (string) ($a['label'] ?? $id);
    }
    // Said out loud rather than printed as a bare id: a zone can never be moved out of
    // the account holding it, so an account the console has lost is a thing to fix,
    // not a cosmetic gap. 31 domains are in this state today — all of them acct-01.
    return $id . ' — NOT IN THE CONSOLE';
}

/** Is this account taking new zones? Absent = yes, so existing records keep working. */
function infra_cf_is_open(array $account): bool
{
    return (string) ($account['closed'] ?? '') !== 'yes';
}

/**
 * How many zones each account holds, from the last sweep. DISPLAY ONLY — nothing about
 * where a zone goes is decided by this.
 *
 * -1 means nobody has ever swept it, which the UI shows as "not counted yet". That is
 * still worth keeping apart from a genuine zero: one is a measurement, the other is an
 * absence of one, and a page that renders them the same teaches you to distrust it.
 *
 * @return array<string,int> account id => zones held, or -1 for never counted
 */
function infra_cf_zone_counts(): array
{
    $out = [];
    foreach (infra_cf_accounts() as $a) {
        $id = (string) ($a['id'] ?? '');
        if ($id === '') continue;
        $out[$id] = infra_cf_zones_swept($a) ? count(infra_cf_zones_cached($a)) : -1;
    }
    return $out;
}

/**
 * The accounts bound to one box, in fill order.
 *
 * @return array<int,array> each: the account record + open, zones, counted
 */
function infra_cf_accounts_for_server(string $serverId): array
{
    $serverId = trim($serverId);
    if ($serverId === '') return [];

    $counts = infra_cf_zone_counts();
    $out = [];
    foreach (infra_cf_accounts() as $a) {
        if (trim((string) ($a['server_id'] ?? '')) !== $serverId) continue;
        $n = $counts[(string) ($a['id'] ?? '')] ?? -1;
        $out[] = $a + [
            'open'    => infra_cf_is_open($a),
            'zones'   => max(0, $n),
            'counted' => $n >= 0,
        ];
    }
    usort($out, fn($x, $y) => [(int) ($x['order'] ?? 0), (string) ($x['label'] ?? '')]
                         <=> [(int) ($y['order'] ?? 0), (string) ($y['label'] ?? '')]);
    return $out;
}

/**
 * The account a new zone for this box goes in: the first bound account that is open.
 *
 * Reads stored state only. It cannot be wrong because a sweep is stale, because a count
 * came back from the wrong account, or because nobody has pressed Refresh — none of
 * which it consults.
 *
 * REFUSES rather than falling back to another box's account. Spilling over would break
 * the containment silently, mid-batch, at the one moment nobody is watching.
 *
 * @return array{ok:bool, account:?array, why:string}
 */
function infra_cf_account_for_server(string $serverId): array
{
    $bound = infra_cf_accounts_for_server($serverId);
    if (!$bound) {
        return ['ok' => false, 'account' => null,
                'why' => 'no Cloudflare account is bound to this box — bind one on the Cloudflare tab'];
    }
    foreach ($bound as $a) {
        if ($a['open']) return ['ok' => true, 'account' => $a, 'why' => ''];
    }
    return ['ok' => false, 'account' => null,
            'why' => 'every account bound to this box is closed to new zones — open one, or bind another'];
}

/**
 * The account in a given position (1st, 2nd, 3rd...) among the accounts bound to one
 * box — for a human manually overriding the normal first-open pick, one box at a time,
 * across a batch of domains that may sit on different boxes. Open/closed is ignored
 * here: a manual pick is a deliberate choice, not the "skip closed accounts" rule that
 * exists for the automatic path.
 *
 * Returns null, not a refusal-shaped array, when the box has no account in that slot —
 * callers treat that the same as "clear the pick," never as an error.
 */
function infra_cf_account_at_slot(string $serverId, int $slot): ?array
{
    if ($slot < 1) return null;
    $bound = infra_cf_accounts_for_server($serverId);
    return $bound[$slot - 1] ?? null;
}

/**
 * Accounts bound to no box. They still work; they are simply outside the scheme, and an
 * account nobody bound is the reason some box reports "no account" — so it needs to be
 * visible rather than merely absent.
 *
 * @return array<int,array>
 */
function infra_cf_accounts_unbound(): array
{
    $counts = infra_cf_zone_counts();
    $out = [];
    foreach (infra_cf_accounts() as $a) {
        if (trim((string) ($a['server_id'] ?? '')) !== '') continue;
        // Some accounts are unbound on purpose and must STAY that way — a human decided this
        // one, not a box, and a bind dropdown listing it next to every real candidate is the
        // click that undoes the decision. `excluded` is the only thing that hides an account
        // here; closed/open still binds fine, closed just will not take a zone once bound.
        if ((string) ($a['excluded'] ?? '') === 'yes') continue;
        $n = $counts[(string) ($a['id'] ?? '')] ?? -1;
        $out[] = $a + ['open' => infra_cf_is_open($a), 'zones' => max(0, $n), 'counted' => $n >= 0];
    }
    return $out;
}

/**
 * The estate in one line: can every box have a zone made for it, and how big are the
 * groups getting?
 *
 * `zones` is a running total for information. `boxes_ready` is the one that matters —
 * it is computed from the open/closed flags alone, so it is true whether or not anyone
 * has swept anything.
 *
 * @return array{boxes:int, bound:int, unbound:int, zones:int, uncounted:int,
 *               boxes_ready:int, boxes_with_none:int, boxes_closed:int}
 */
function infra_cf_capacity(): array
{
    $out = ['boxes' => 0, 'bound' => 0, 'unbound' => count(infra_cf_accounts_unbound()),
            'zones' => 0, 'uncounted' => 0,
            'boxes_ready' => 0, 'boxes_with_none' => 0, 'boxes_closed' => 0];

    foreach (infra_hestia_servers() as $s) {
        $out['boxes']++;
        $bound = infra_cf_accounts_for_server((string) ($s['id'] ?? ''));
        if (!$bound) { $out['boxes_with_none']++; continue; }

        $anyOpen = false;
        foreach ($bound as $a) {
            $out['bound']++;
            $a['counted'] ? $out['zones'] += $a['zones'] : $out['uncounted']++;
            if ($a['open']) $anyOpen = true;
        }
        $anyOpen ? $out['boxes_ready']++ : $out['boxes_closed']++;
    }
    return $out;
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
function infra_cf_consistency(): array
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

/**
 * Accounts this credential can SEE at Cloudflare, whether or not the console knows
 * them. Twenty account ids typed by hand is twenty chances to paste the wrong one, and
 * a wrong id is invisible until a zone lands in the wrong place — so the console asks
 * Cloudflare instead.
 *
 * Any one stored credential can list every account its user belongs to, which is what
 * makes "one admin email invited into all twenty" worth doing.
 *
 * @return array{ok:bool, msg:string, accounts:array<int,array{id:string,name:string,known:bool}>}
 */
function infra_cf_discover(): array
{
    $known = [];
    foreach (infra_cf_accounts() as $a) $known[strtolower((string) ($a['account_id'] ?? ''))] = true;

    $seen = [];
    $errs = [];
    foreach (infra_cf_accounts() as $a) {
        // Through the Cloudflare client, not a hand-rolled call: the base URL, the auth
        // headers and the error shape are its business, and a second copy of them here
        // would be free to drift from the first.
        $r = cf_list_accounts($a);
        if (!$r['ok']) { $errs[] = ($a['label'] ?? $a['id']) . ': ' . $r['error']; continue; }
        foreach ($r['accounts'] as $x) {
            if (isset($seen[$x['id']])) continue;
            $seen[$x['id']] = $x + ['known' => isset($known[strtolower($x['id'])])];
        }
    }

    $accounts = array_values($seen);
    $new = count(array_filter($accounts, fn($a) => !$a['known']));
    return [
        'ok'  => (bool) $accounts,
        'msg' => $accounts
            ? count($accounts) . ' account(s) visible, ' . $new . ' not yet in the console'
              . ($errs ? ' — ' . implode('; ', $errs) : '')
            : ('nothing could be listed' . ($errs ? ': ' . implode('; ', $errs) : '')),
        'accounts' => $accounts,
    ];
}
