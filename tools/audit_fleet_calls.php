<?php
/**
 * Real inbound calls to live sites, via CallTrackingMetrics.
 *
 * A THIN WRAPPER. All logic is in admin/infra/lib/calls_audit.php, which owns its own
 * tables; this is the shell face of it and the web "Sync now" button runs the same
 * calls_audit_run(), so the terminal and the browser can never disagree.
 *
 * Free to run (CTM's own API, no per-call charge) — unlike the rank sweep, there is
 * no cost gate and no --if-due; a daily cron and the on-demand button both just call
 * calls_audit_run() directly.
 *
 * Usage:
 *   php tools/audit_fleet_calls.php
 */

require_once dirname(__DIR__) . '/config.php';            // CTM_ACCESS_KEY/CTM_SECRET_KEY live here
require_once dirname(__DIR__) . '/admin/infra/lib/calls_audit.php';

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(1); }

function say(string $s = ''): void { echo $s, "\n"; }

$run = calls_audit_run('say');
if (!$run['accounts']) { exit(1); }

$failed = 0;
foreach ($run['accounts'] as $a) {
    if (empty($a['ok'])) { $failed++; say("{$a['account_id']}: FAILED — {$a['error']}"); continue; }
    say("{$a['account_id']}: {$a['from']} to {$a['to']} — {$a['pulled']} seen, {$a['ours']} ours, {$a['other']} belong to other business(es) sharing this account");
}

say('');
$l = calls_audit_latest(30);
say('LAST 30 DAYS: ' . $l['calls'] . ' inbound call(s) to a tracked number — '
    . $l['engaged'] . ' engaged (talk time >= ' . $l['engaged_seconds'] . 's), '
    . $l['rang'] . ' rang and never connected.');
say('');
say('BY SITE');
foreach ($l['domains'] as $d) {
    printf("   %-32s %-3d call(s)  %-3d engaged  last %s\n", $d['domain'], $d['calls'], $d['engaged'], $d['last_call_at']);
}

exit($failed > 0 ? 1 : 0);
