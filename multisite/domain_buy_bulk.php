<?php
/**
 * Bulk domain purchase — Batch Detailed's "Target list — edit rows directly" section,
 * as a detached background job, same shape as multisite/ctm_bulk.php. Does NOT touch
 * params.csv — a row's `domain` field is already set by the time it's buyable (that's
 * the readiness gate); ownership lives in fleet.db (admin/infra/lib/state.php), not here.
 *
 *   php multisite/domain_buy_bulk.php <master_id> --batch=bN --domains=a.com,b.com,...
 *       --registrar=namesilo
 *
 * The actual purchase is 100% infra_domain_buy() (admin/infra/lib/acquire.php) — the
 * same function the Infra console's own single-domain buy already uses. This script
 * does not re-implement availability re-checking, pending/async-registrar confirmation,
 * or receipt-writing; it only adds the one thing a batch row needs that a tracked Infra
 * domain already has: a fleet-state record with a registrar assigned. A domain already
 * tracked (e.g. from an earlier D.Finder/D.Buy pass) keeps its existing niche/status —
 * only buy_registrar is overwritten, to whatever this run's dropdown says.
 *
 * Sequential, not parallel — same reasoning as ctm_bulk.php: these are real-money,
 * one-at-a-time purchases, and a registrar's purchase endpoint is not something to
 * hammer concurrently.
 */
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "domain_buy_bulk.php is CLI only\n"); exit(2); }

require __DIR__ . '/../config.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/multisite/params.php';
require __DIR__ . '/../includes/multisite/batch.php';
require __DIR__ . '/../admin/infra/lib/state.php';
require __DIR__ . '/../admin/infra/lib/registrar.php';
require __DIR__ . '/../admin/infra/lib/acquire.php';

// Same stdout-buffering fix as ctm_bulk.php/check_area_codes.php — without an explicit
// flush, PHP holds output until the whole script exits when stdout isn't a tty (exactly
// the case here, redirected to a .out file by ms_launch_job).
function dbb_say(string $line): void { echo $line; @flush(); }

$args      = array_slice($argv, 1);
$masterId  = (string) ($args[0] ?? '');
$batchId = ''; $domainsArg = ''; $registrar = '';
foreach ($args as $a) {
    if (str_starts_with($a, '--batch='))     $batchId    = substr($a, 8);
    if (str_starts_with($a, '--domains='))   $domainsArg = substr($a, 10);
    if (str_starts_with($a, '--registrar=')) $registrar  = strtolower(substr($a, 12));
}
if (!ms_valid_master_id($masterId) || !ms_batch_exists($masterId, $batchId)) {
    fwrite(STDERR, "usage: domain_buy_bulk.php <master_id> --batch=bN --domains=a.com,b.com --registrar=namesilo\n");
    exit(2);
}
$domainsIn = array_values(array_filter(array_map(fn($d) => strtolower(trim($d)), explode(',', $domainsArg))));
if (!$domainsIn) { fwrite(STDERR, "No domains given.\n"); exit(2); }
if (!in_array($registrar, infra_registrar_buyable_for_new_purchases(), true)) {
    fwrite(STDERR, "'{$registrar}' is not a configured, buy-wired registrar.\n"); exit(2);
}
if (!infra_registrar_config($registrar)) {
    fwrite(STDERR, "No saved credentials for '{$registrar}'.\n"); exit(2);
}

$batchDir   = ms_batch_dir($masterId, $batchId);
$paramsPath = $batchDir . '/params.csv';
$byDomain   = ms_rows_by_domain($paramsPath);
$niche      = infra_niche((string) strtok($masterId, '-'));
$results    = [];

foreach ($domainsIn as $dom) {
    $row = $byDomain[$dom] ?? null;
    $cityNote = $row ? trim((string) ($row['city'] ?? '')) . ', ' . trim((string) ($row['SS'] ?? '')) : '(not in this batch\'s target list)';

    // Track it if this is the first time anything has looked at this domain —
    // a fresh batch row has never been through D.Finder/D.Buy, so there is no
    // existing fleet-state record yet. An already-tracked domain (e.g. a prior
    // pass through the Infra console) keeps every other field as-is.
    if (!infra_state_get_domain($dom)) {
        infra_state_upsert_domain(['domain' => $dom, 'niche' => $niche, 'status' => 'ready', 'ready_to_buy' => 'yes']);
    }
    infra_state_upsert_domain(['domain' => $dom, 'buy_registrar' => $registrar]);

    $r = infra_domain_buy($dom, ['years' => 1, 'auto_renew' => true]);
    if (!empty($r['pending'])) {
        $results[] = ['domain' => $dom, 'ok' => true, 'pending' => true, 'message' => $r['message']];
        dbb_say("  {$dom} ({$cityNote}): PENDING — {$r['message']}\n");
        continue;
    }
    if (empty($r['ok'])) {
        $results[] = ['domain' => $dom, 'ok' => false, 'error' => $r['message'] ?? 'purchase failed'];
        dbb_say("  {$dom} ({$cityNote}): FAILED — {$r['message']}\n");
        continue;
    }
    $results[] = ['domain' => $dom, 'ok' => true, 'message' => $r['message']];
    dbb_say("  {$dom} ({$cityNote}): bought via {$registrar}\n");
}

$ok = count(array_filter($results, fn($r) => !empty($r['ok'])));
dbb_say("\n" . count($results) . " domain(s) processed — {$ok} ok, " . (count($results) - $ok) . " failed.\n");

$report = [
    'checked_at' => gmdate('c'),
    'master_id'  => $masterId,
    'batch_id'   => $batchId,
    'action'     => 'buy_domains',
    'registrar'  => $registrar,
    'results'    => $results,
];
file_put_contents($batchDir . '/domain_buy_report.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

exit(0);
