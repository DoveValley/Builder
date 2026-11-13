<?php
/**
 * infra/lib/buy_recheck.php — finalizes a domain purchase that was accepted by an
 * async registrar (Gandi, Spaceship) but hadn't confirmed registered within
 * infra_domain_buy()'s own short polling window (see admin/infra/lib/acquire.php).
 *
 * WHY THIS EXISTS. That poll window is deliberately short (20s for Gandi) — long
 * enough to catch the common case without holding up a whole batch buy for one
 * slow domain, but real purchases do sometimes land after it gives up. When that
 * happens the code CORRECTLY refuses to write a receipt (no false "owned"), but
 * nothing ever came back to check again — found three real domains sitting in
 * exactly this limbo, one for six weeks, all genuinely owned the whole time.
 * acquire.php's own comment says "the next availability sweep marks it
 * self-owned", but no sweep was ever built to do that. This is that sweep.
 *
 * Self-contained for cron (see infra/cron/buy_recheck_tick.php) — same BASE_DIR
 * bootstrap as golive_freshen.php, for the same reason: config.php is a web-only
 * bootstrap (session_start()), never loaded by cron.
 */
if (!defined('BASE_DIR')) define('BASE_DIR', dirname(__DIR__, 3));
require_once __DIR__ . '/state.php';
require_once __DIR__ . '/registrar.php';

/**
 * Every domain still waiting on this confirmation — not owned yet, but recorded
 * as accepted-and-pending at a real registrar (acquire.php's own pending-write:
 * `avail_note = 'purchase pending at {registrar}'`, `registrar` set to the one
 * that accepted it). Matches on avail_note rather than parsing buy_error's free
 * text, since avail_note is the structured field meant for exactly this.
 *
 * @return array<int,array{domain:string,registrar:string}>
 */
function infra_buy_recheck_pending(): array
{
    $db = infra_state_db();
    $rows = $db->query(
        "SELECT domain, registrar FROM domains WHERE owned != 'yes' AND avail_note LIKE 'purchase pending at %'"
    )->fetchAll(PDO::FETCH_ASSOC);
    return array_map(fn($r) => ['domain' => strtolower(trim($r['domain'])), 'registrar' => strtolower(trim($r['registrar']))], $rows);
}

/**
 * Check every pending domain against its real registrar's own domain list —
 * ONE list call per registrar involved, not one per domain, same reasoning
 * infra_registrar_checkers() already uses elsewhere (availability is a public
 * fact, asking per-domain would be needlessly slow and rate-limit-prone).
 *
 * A domain found confirmed gets EXACTLY the fields infra_domain_buy()'s own
 * success path writes (admin/infra/lib/acquire.php lines 299-311) — owned=yes
 * is a receipt, not a guess, written here the same deliberate way it would have
 * been written at buy time if the registrar had just answered a few seconds
 * sooner.
 *
 * @return array{checked:int, confirmed:array<int,string>, still_pending:array<int,string>}
 */
function infra_buy_recheck_run(): array
{
    $pending = infra_buy_recheck_pending();
    $byRegistrar = [];
    foreach ($pending as $p) {
        if ($p['registrar'] === '') continue;
        $byRegistrar[$p['registrar']][] = $p['domain'];
    }

    $confirmed = [];
    $stillPending = [];
    foreach ($byRegistrar as $registrar => $domains) {
        $owned = array_map('strtolower', infra_registrar_list_owned($registrar));
        $ownedSet = array_fill_keys($owned, true);
        foreach ($domains as $domain) {
            if (!isset($ownedSet[$domain])) { $stillPending[] = $domain; continue; }
            $write = [
                'domain'     => $domain,
                'owned'      => 'yes',
                'owned_at'   => infra_now(),
                'registrar'  => $registrar,
                'buy_at'     => infra_today(),
                'buy_error'  => '',
                'avail_note' => '',
            ];
            // Same rule as infra_domain_buy()/infra_domain_mark_owned() (admin/infra/
            // lib/acquire.php): don't regress a domain already staged/queued/further
            // back down to 'owned'. This sweep exists for a purchase that was
            // ACCEPTED but didn't confirm within infra_domain_buy()'s own short poll
            // window — exactly the slow-async case most likely to land on a domain
            // the batch pipeline already provisioned and scheduled while the buy was
            // still pending at the registrar.
            $cur = infra_state_get_domain($domain);
            if (!in_array($cur['status'] ?? '', INFRA_STATUSES_MANUAL, true)) {
                $write['status'] = 'owned';
            }
            infra_state_upsert_domain($write);
            $confirmed[] = $domain;
        }
    }

    return ['checked' => count($pending), 'confirmed' => $confirmed, 'still_pending' => $stillPending];
}
