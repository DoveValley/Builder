<?php
/**
 * Bulk domain purchase — Batch Detailed's "Target list — edit rows directly" section,
 * as a detached background job, same shape as multisite/ctm_bulk.php. Does NOT touch
 * params.csv's domain/business/etc. — a row's `domain` field is already set by the
 * time it's buyable (that's the readiness gate); ownership lives in fleet.db
 * (admin/infra/lib/state.php), not here.
 *
 *   php multisite/domain_buy_bulk.php <master_id> --batch=bN --domains=a.com,b.com,...
 *       [--registrar=namesilo]
 *
 * --registrar is OPTIONAL. Given: every domain buys through that one registrar,
 * overriding whatever is in its own row (the original, simpler behavior). Omitted:
 * EACH domain resolves its OWN registrar from that row's params.csv `registrar`
 * column — the mechanism that makes a pre-planned spread across registrars (e.g. the
 * fair-scheduling assignment used for appliance's next-400 batch) executable in one
 * click instead of checking rows and clicking Buy once per registrar by hand. A row
 * with nothing in that column and no override is skipped and reported, not guessed.
 *
 * The actual purchase is 100% infra_domain_buy() (admin/infra/lib/acquire.php) — the
 * same function the Infra console's own single-domain buy already uses. This script
 * does not re-implement availability re-checking, pending/async-registrar confirmation,
 * or receipt-writing; it only adds the one thing a batch row needs that a tracked Infra
 * domain already has: a fleet-state record with a registrar assigned. A domain already
 * tracked (e.g. from an earlier D.Finder/D.Buy pass) keeps its existing niche/status —
 * only buy_registrar is overwritten, to whichever registrar this domain resolved to.
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
$batchId = ''; $domainsArg = ''; $overrideRegistrar = '';
foreach ($args as $a) {
    if (str_starts_with($a, '--batch='))     $batchId           = substr($a, 8);
    if (str_starts_with($a, '--domains='))   $domainsArg        = substr($a, 10);
    if (str_starts_with($a, '--registrar=')) $overrideRegistrar = strtolower(substr($a, 12));
}
if (!ms_valid_master_id($masterId) || !ms_batch_exists($masterId, $batchId)) {
    fwrite(STDERR, "usage: domain_buy_bulk.php <master_id> --batch=bN --domains=a.com,b.com [--registrar=namesilo]\n");
    exit(2);
}
$domainsIn = array_values(array_filter(array_map(fn($d) => strtolower(trim($d)), explode(',', $domainsArg))));
if (!$domainsIn) { fwrite(STDERR, "No domains given.\n"); exit(2); }
if ($overrideRegistrar !== '') {
    if (!in_array($overrideRegistrar, infra_registrar_buyable_for_new_purchases(), true)) {
        fwrite(STDERR, "'{$overrideRegistrar}' is not a configured, buy-wired registrar.\n"); exit(2);
    }
    if (!infra_registrar_config($overrideRegistrar)) {
        fwrite(STDERR, "No saved credentials for '{$overrideRegistrar}'.\n"); exit(2);
    }
}

$batchDir   = ms_batch_dir($masterId, $batchId);
$paramsPath = $batchDir . '/params.csv';
$byDomain   = ms_rows_by_domain($paramsPath);
$niche      = infra_niche((string) strtok($masterId, '-'));
$buyableSet = array_flip(infra_registrar_buyable_for_new_purchases());
$results    = [];

foreach ($domainsIn as $dom) {
    $row = $byDomain[$dom] ?? null;
    $cityNote = $row ? trim((string) ($row['city'] ?? '')) . ', ' . trim((string) ($row['SS'] ?? '')) : '(not in this batch\'s target list)';

    // Per-domain resolution: an explicit override wins outright; otherwise this
    // row's own `registrar` column decides. Validated HERE, per domain, not once
    // upfront — a mixed-registrar run can't bail the whole job over one bad row.
    $registrar = $overrideRegistrar !== '' ? $overrideRegistrar : strtolower(trim((string) ($row['registrar'] ?? '')));
    if ($registrar === '') {
        $results[] = ['domain' => $dom, 'ok' => false, 'error' => 'no registrar assigned on this row and no override selected'];
        dbb_say("  {$dom} ({$cityNote}): SKIPPED — no registrar assigned\n");
        continue;
    }
    if (!isset($buyableSet[$registrar])) {
        $results[] = ['domain' => $dom, 'ok' => false, 'registrar' => $registrar, 'error' => "'{$registrar}' is not a configured, buy-wired registrar"];
        dbb_say("  {$dom} ({$cityNote}): SKIPPED — '{$registrar}' is not a configured, buy-wired registrar\n");
        continue;
    }
    if (!infra_registrar_config($registrar)) {
        $results[] = ['domain' => $dom, 'ok' => false, 'registrar' => $registrar, 'error' => "no saved credentials for '{$registrar}'"];
        dbb_say("  {$dom} ({$cityNote}): SKIPPED — no saved credentials for '{$registrar}'\n");
        continue;
    }

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
        $results[] = ['domain' => $dom, 'ok' => true, 'pending' => true, 'registrar' => $registrar, 'message' => $r['message']];
        dbb_say("  {$dom} ({$cityNote}): PENDING via {$registrar} — {$r['message']}\n");
        continue;
    }
    if (empty($r['ok'])) {
        $results[] = ['domain' => $dom, 'ok' => false, 'registrar' => $registrar, 'error' => $r['message'] ?? 'purchase failed'];
        dbb_say("  {$dom} ({$cityNote}): FAILED via {$registrar} — {$r['message']}\n");
        continue;
    }
    $results[] = ['domain' => $dom, 'ok' => true, 'registrar' => $registrar, 'message' => $r['message']];
    dbb_say("  {$dom} ({$cityNote}): bought via {$registrar}\n");
}

$ok = count(array_filter($results, fn($r) => !empty($r['ok'])));
dbb_say("\n" . count($results) . " domain(s) processed — {$ok} ok, " . (count($results) - $ok) . " failed.\n");

$report = [
    'checked_at' => gmdate('c'),
    'master_id'  => $masterId,
    'batch_id'   => $batchId,
    'action'     => 'buy_domains',
    'registrar'  => $overrideRegistrar !== '' ? $overrideRegistrar : '(per-row)',
    'results'    => $results,
];
file_put_contents($batchDir . '/domain_buy_report.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

exit(0);
