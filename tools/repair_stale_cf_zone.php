<?php
/**
 * Give a domain a fresh Cloudflare zone under the account bound to the box it is
 * ACTUALLY on, when its current zone/account describe a different box entirely.
 *
 * ── THE FAULT THIS REPAIRS (the opposite direction from repair_zone_box_mismatch.php) ──
 * That script repairs a domain moved AWAY from the box its zone/account correctly
 * describe (the 2026-10-21 pipeline bug, now patched) — the fix there is to move the
 * site back, since the zone/account were the original truth.
 *
 * This script repairs the other direction: a domain's box (server_id) was
 * deliberately changed — e.g. to rebalance registrar/IP distribution across the fleet
 * by hand — but nothing created a fresh zone under an account bound to the new box, so
 * its Cloudflare zone/account are stale, still describing where it USED to live. Here
 * the box move was the correct, intentional action; the zone/account are what is wrong.
 * First found 2026-10-14 (5 water-site/b5 domains, fixed by hand, one new CF account
 * each) and again 2026-10-29 at far larger scale (43 domains, same batch family) via
 * the fingerprint checker (admin/infra/lib/fingerprint_audit.php).
 *
 * Since Cloudflare cannot move a zone between accounts, and the box is the side that is
 * correct here, the fix is: create a new zone under the account the domain's ACTUAL box
 * is bound to, point it at that box's real IP, apply the same SSL mode + HSTS every
 * other zone in this fleet gets, then delete the old (stale) zone. The box and its
 * already-uploaded site content are never touched — this is Cloudflare-side only.
 *
 * ── SAFETY ────────────────────────────────────────────────────────────────────────
 * For queued/staged domains this is zero visitor-facing risk: infra_golive_release()
 * (admin/infra/lib/golive.php) is the ONLY place that ever pushes nameservers to the
 * registrar, and it reads the 'nameservers' field fresh at whatever moment go-live
 * actually happens — so correcting it now, before go-live, changes nothing a visitor
 * could ever see.
 *
 * For 'live'/'releasing' domains, fixing this means an actual DNS cutover — the OLD
 * zone's nameservers are already live at the registrar. This script does NOT touch
 * live domains unless --include-live is passed, since that is a different risk profile
 * than the pre-launch case. When it is passed, for each live domain it additionally
 * calls infra_registrar_set_ns() (the same call infra_golive_release() makes) to switch
 * the registrar to the NEW zone's nameservers, and only deletes the old zone once that
 * switch succeeds — if the switch fails, the old (stale but working) zone is left alone
 * rather than deleted out from under a domain whose DNS still points at it. 2026-10-29:
 * run with --include-live on 23 water-site/b5 domains at Scott's explicit instruction,
 * after confirming these are low-traffic rank-and-rent domains with no real customer
 * interaction, i.e. the brief-propagation-gap risk this still carries was accepted.
 *
 * Also refuses: a zone id found in zero or several accounts (stale cache, don't guess),
 * an account already matching the domain's box (nothing to do), no open account bound
 * to the domain's actual box (bind one on the Cloudflare tab first).
 *
 * Usage:
 *   php tools/repair_stale_cf_zone.php                        # dry run, non-live only
 *   php tools/repair_stale_cf_zone.php --include-live          # dry run, live too
 *   php tools/repair_stale_cf_zone.php --domain=foo.com        # dry run, one domain
 *   php tools/repair_stale_cf_zone.php --apply                 # write for real
 *   php tools/repair_stale_cf_zone.php --apply --include-live  # write, including live
 *
 * Defaults to a DRY RUN. Nothing is written, created or deleted without --apply.
 */

$base = dirname(__DIR__);
require_once $base . '/admin/infra/lib/store.php';
require_once $base . '/admin/infra/lib/state.php';
require_once $base . '/admin/infra/lib/http.php';
require_once $base . '/admin/infra/lib/cache.php';
require_once $base . '/admin/infra/lib/cloudflare.php';
require_once $base . '/admin/infra/lib/hestia_fleet.php';
require_once $base . '/admin/infra/lib/cf_alloc.php';
require_once $base . '/admin/infra/lib/pipeline.php';
require_once $base . '/admin/infra/lib/registrar.php';

$opt          = getopt('', ['domain::', 'apply', 'include-live']);
$apply        = isset($opt['apply']);
$includeLive  = isset($opt['include-live']);
$only         = strtolower(trim((string) ($opt['domain'] ?? '')));

function say(string $s = ''): void { echo $s, "\n"; }

/* ── Who is a candidate. A zone+account on record, server_id present. Live/releasing
 *    is included only with --include-live — see the SAFETY section above. ──────── */
$sql = "SELECT domain, server_id, cf_zone_id, cf_account_id, status, batch, registrar
         FROM domains
         WHERE cf_zone_id != '' AND cf_account_id != '' AND server_id != ''"
     . ($includeLive ? '' : " AND status NOT IN ('live', 'releasing')")
     . ' ORDER BY domain';
$rows = infra_state_db()->query($sql)->fetchAll(PDO::FETCH_ASSOC);
if ($only) $rows = array_values(array_filter($rows, fn($r) => $r['domain'] === $only));

if (!$rows) { say($only ? "{$only}: not a candidate (no zone+account+box on record, or it's live and --include-live was not passed)." : 'Nothing to check.'); exit(0); }

/* ── Where each zone actually lives right now. One listing per account, then a lookup. */
$accounts  = infra_cf_accounts();
$byAcctId  = [];
foreach ($accounts as $a) $byAcctId[(string) ($a['id'] ?? '')] = $a;
$zoneOwner = [];
foreach ($accounts as $a) {
    foreach (cf_list_zones($a) as $z) $zoneOwner[$z['id']][] = $a;
}

say(($apply ? 'APPLYING' : 'DRY RUN — nothing will be written') . '. ' . count($rows) . ' candidate(s).');
say(str_repeat('─', 78));

$done = 0; $skipped = 0;
foreach ($rows as $rec) {
    $domain = $rec['domain'];
    say('');
    say($domain);

    $skip = function (string $why) use (&$skipped) { say('   ⏭  SKIPPED — ' . $why); $skipped++; };

    $owners = $zoneOwner[$rec['cf_zone_id']] ?? [];
    if (count($owners) !== 1) {
        $skip(count($owners) === 0
            ? "its zone id is in none of the configured accounts — it may have been deleted at Cloudflare"
            : 'its zone id appears in ' . count($owners) . ' accounts, which should be impossible — resolve by hand');
        continue;
    }
    $oldAcct = $owners[0];
    if ((string) $oldAcct['id'] !== (string) $rec['cf_account_id']) {
        $skip('the zone is actually in ' . $oldAcct['label'] . ', not ' . $rec['cf_account_id']
            . ' as recorded — the account reference is stale too; resolve by hand before this script touches it');
        continue;
    }

    $target = infra_hestia_server((string) $rec['server_id']);
    if (!$target) { $skip('its box, ' . $rec['server_id'] . ', is not in the box registry'); continue; }

    $oldBound = trim((string) ($oldAcct['server_id'] ?? ''));
    if ($oldBound === (string) $rec['server_id']) {
        $skip('its account is already bound to the box it is on — nothing stale here');
        continue;
    }

    $pick = infra_cf_account_for_server((string) $rec['server_id']);
    if (!$pick['ok']) { $skip($pick['why']); continue; }
    $newAcct = $pick['account'];

    $ip = $target['default_ip'] ?? ($target['host'] ?? '');
    if ($ip === '') { $skip('its box has no known IP on record'); continue; }

    $isLive   = in_array($rec['status'], ['live', 'releasing'], true);
    $wrongBox = infra_hestia_server($oldBound);
    say('   status ' . $rec['status'] . ($isLive ? '  ⚠ LIVE — this run will switch registrar NS too' : ''));
    say('   zone   ' . $oldAcct['label'] . '  bound to ' . ($wrongBox['label'] ?? $oldBound) . '  ← stale, describes where this domain used to be');
    say('   actual ' . ($target['label'] ?? $rec['server_id']) . '  (' . $ip . ')  ← the box it is really on, correctly, and stays on');
    $plan = '   plan   create a new zone under ' . $newAcct['label'] . ' → A records → ' . $ip . ' → SSL full + HSTS';
    $plan .= $isLive
        ? (' → switch ' . ($rec['registrar'] ?: '(no registrar on record)') . ' NS to the new zone → delete the old zone in ' . $oldAcct['label'])
        : (' → delete the old zone in ' . $oldAcct['label']);
    say($plan);
    if ($isLive && trim((string) $rec['registrar']) === '') {
        $skip('it is live but has no registrar on record — cannot switch NS');
        continue;
    }

    if (!$apply) { $done++; continue; }

    /* 1 — the new zone, under the account the domain's real box is bound to */
    $z = cf_create_zone($newAcct, $domain);
    if (!$z['ok']) { say('   zone    ✗ ' . $z['message']); $skipped++; continue; }
    $newZoneId = $z['zone_id'];
    $newNs     = $z['name_servers'];
    say('   zone    ✓ created ' . substr($newZoneId, 0, 12) . '…');

    /* 2 — A records, pointed at the box this domain is already, correctly, sitting on */
    $a1 = cf_upsert_a_record($newAcct, $newZoneId, $domain, $ip, true);
    say('   A @     ' . ($a1['ok'] ? '✓ ' . $a1['message'] : '✗ ' . $a1['message']));
    $a2 = cf_upsert_a_record($newAcct, $newZoneId, 'www.' . $domain, $ip, true);
    say('   A www   ' . ($a2['ok'] ? '✓ ' . $a2['message'] : '✗ ' . $a2['message']));

    /* 3 — same baseline every zone in this fleet gets at provision time */
    $ssl = cf_set_ssl_mode($newAcct, $newZoneId, 'full');
    say('   SSL     ' . ($ssl['ok'] ? '✓ full' : '✗ ' . $ssl['message']));
    $hsts = cf_set_hsts($newAcct, $newZoneId);
    say('   HSTS    ' . ($hsts['ok'] ? '✓ on' : '✗ ' . $hsts['message']));

    /* 4 — for a live domain, the actual cutover: the registrar must point at the
     *     NEW zone's nameservers before the old zone is safe to delete. Same call
     *     infra_golive_release() makes, so this is the one proven NS-switch path,
     *     not a second hand-rolled one. */
    $nsOk = true;
    if ($isLive) {
        $sw = infra_registrar_set_ns($domain, $newNs, (string) $rec['registrar']);
        $nsOk = (bool) $sw['ok'];
        say('   NS switch ' . ($nsOk ? '✓ ' . $sw['message'] : '✗ ' . $sw['message']));
    }

    /* 5 — the old zone, now orphaned, IF it is safe to remove it. For the non-live
     *     path nothing at the registrar points at it, so deleting is always safe.
     *     For the live path, only once the NS switch actually took — otherwise the
     *     domain's current DNS still depends on this zone, and deleting it would
     *     take the live site down with no fallback. */
    if (!$isLive || $nsOk) {
        $del = cf_delete_zone($oldAcct, $rec['cf_zone_id']);
        say('   old zone delete ' . ($del['ok'] ? '✓ removed' : '✗ ' . $del['message']));
    } else {
        say('   old zone kept — NS switch did not take, deleting it now would break the live site');
    }

    /* 6 — the record of truth. nameservers is what infra_golive_release() reads
     *     at actual go-live time — correcting it here is the whole point. For an
     *     already-live domain the switch just happened above; this just catches
     *     the record up to match. */
    infra_state_upsert_domain([
        'domain'         => $domain,
        'cf_zone_id'     => $newZoneId,
        'cf_account_id'  => (string) $newAcct['id'],
        'nameservers'    => implode(',', $newNs),
    ]);

    /* 7 — stale pipeline markers from the old zone would otherwise still show green
     *     for a zone id that no longer exists until something re-derives it. */
    infra_pipeline_set($domain, 'zone', INFRA_STEP_TODO, 'repair: new zone issued under the correct account');
    infra_pipeline_set($domain, 'assign', INFRA_STEP_TODO, 'repair: re-deriving after zone correction');

    $done++;
}

say('');
say(str_repeat('─', 78));
say(($apply ? 'DONE' : 'WOULD PROCESS') . ' — ' . $done . ' of ' . count($rows) . ' (' . $skipped . ' skipped).');
