<?php
/**
 * infra/cron/buy_recheck_tick.php — finalizes any domain purchase still sitting
 * in "accepted but not yet confirmed" limbo (CLI only).
 *
 * Fully separate from golive_tick.php/filedate_tick.php: own heartbeat
 * (state/buy_recheck_tick.json), own log file. Reads nothing from, and writes
 * nothing to, either of those other two pipelines — see lib/buy_recheck.php's
 * docblock for why this exists. No new purchase is ever made here; this only
 * reads each pending domain's real registrar list and, if it's actually there,
 * writes the receipt that should have been written at buy time.
 */
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(1); }

require_once __DIR__ . '/../lib/buy_recheck.php';

$ts = gmdate('c');
$r  = infra_buy_recheck_run();

foreach ($r['confirmed'] as $domain) echo "{$ts} confirmed {$domain}\n";
foreach ($r['still_pending'] as $domain) echo "{$ts} still pending {$domain}\n";
echo "{$ts} tick done — checked: {$r['checked']}, confirmed: " . count($r['confirmed'])
   . ", still pending: " . count($r['still_pending']) . "\n";

// Same convention as filedate_tick.json/golive_tick.json — a run that leaves no
// footprint looks identical to a cron that silently stopped firing.
@file_put_contents(dirname(__DIR__) . '/state/buy_recheck_tick.json', json_encode([
    'at' => time(), 'checked' => $r['checked'], 'confirmed' => count($r['confirmed']),
    'still_pending' => count($r['still_pending']),
], JSON_PRETTY_PRINT));
