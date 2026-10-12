<?php
/**
 * build_upload.php — "Build & upload now" for a few domains, as ONE background job.
 *
 *   php multisite/build_upload.php <master> --batch=bN --only=a.com,b.com
 *
 * An orchestrator only — it runs the existing pieces in order and adds no logic of its own:
 *   1. multisite/run_campaign.php  (Generate, --no-deploy) with the batch's SAVED Generate
 *      settings (batch_options.json / section_rotation.json — what the detailed page's
 *      checkboxes were last left at), same flags the detailed page's Generate button sends;
 *   2. multisite/upload_sites.php  for the domains that built OK;
 *   3. infra_pipeline_refresh('built'/'upload') — the same check the Refresh buttons run —
 *      so the fleet's Built/Uploaded cells (and therefore the go-live gate) see the result.
 * Launched by multisite_api.php's rollout_build action through ms_launch_job().
 */
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(2); }

require __DIR__ . '/../config.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/multisite/batch.php';
require __DIR__ . '/../includes/multisite/batch_options.php';

$args = array_slice($argv, 1);
$masterId = (string) ($args[0] ?? '');
$batchId = ''; $only = '';
foreach ($args as $a) {
    if (str_starts_with($a, '--batch=')) $batchId = substr($a, 8);
    if (str_starts_with($a, '--only='))  $only    = strtolower(substr($a, 7));
}
$domains = array_values(array_filter(array_map('trim', explode(',', $only))));
if (!ms_valid_master_id($masterId) || !ms_batch_exists($masterId, $batchId) || !$domains) {
    echo "Usage: build_upload.php <master> --batch=bN --only=a.com,b.com\n"; exit(2);
}
$php = ms_php_cli();
$tag = $masterId . '/' . $batchId;

// 1. Generate — the batch's saved settings, never deploying.
$saved = ms_batch_saved_run_settings($masterId, $batchId);
$runId = gmdate('Ymd-His') . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
$cmd = [$php, BASE_DIR . '/multisite/run_campaign.php', $masterId, '--batch=' . $batchId, '--run-id=' . $runId,
        '--no-preflight', '--jobs=1', '--retries=1', '--no-deploy', '--only=' . implode(',', $domains)];
if ($saved['skip']) $cmd[] = '--skip=' . implode(',', $saved['skip']);
foreach ($saved['rotation'] as $flag => $n) $cmd[] = '--' . $flag . '=' . (int) $n;
echo "== 1/3 Build " . count($domains) . " domain(s)" . ($saved['skip'] ? ' (skipping: ' . implode(', ', $saved['skip']) . ')' : '') . "\n";
passthru(implode(' ', array_map('escapeshellarg', $cmd)), $rc);

$run = json_decode((string) @file_get_contents(ms_batch_dir($masterId, $batchId) . '/runs/' . $runId . '.json'), true) ?: [];
$built = [];
foreach (($run['results'] ?? []) as $r) if (($r['status'] ?? '') === 'ok') $built[] = strtolower((string) $r['domain']);
echo "\n   built OK: " . count($built) . " of " . count($domains) . "\n";

// 2. Upload what built.
if ($built) {
    echo "\n== 2/3 Upload " . count($built) . " domain(s)\n";
    passthru(implode(' ', array_map('escapeshellarg', [$php, BASE_DIR . '/multisite/upload_sites.php', $masterId,
        '--batch=' . $batchId, '--only=' . implode(',', $built)])), $rcUp);
} else {
    echo "\n== 2/3 Upload skipped — nothing built\n";
}

// 3. Re-check the fleet cells the go-live gate reads.
echo "\n== 3/3 Check Built and Uploaded\n";
require_once BASE_DIR . '/admin/infra/lib/pipeline.php';
foreach (['built', 'upload'] as $step) {
    $r = infra_pipeline_refresh($step, $tag);
    echo "   {$step}: {$r['ok']} ok, {$r['todo']} not yet, {$r['fail']} failed (of {$r['checked']} checked)\n";
}
$st = infra_pipeline_stored($domains);
$up = 0;
foreach ($domains as $d) if (($st[$d]['upload']['state'] ?? '') === 'ok') $up++;
echo "\nDONE: {$up} of " . count($domains) . " uploaded and ready to schedule.\n";
// Exit reflects UPLOAD success, not just build success — rollout.php shows exit 0 as a
// flat "finished" success message, so a build that succeeded but whose upload failed
// (bad FTP creds, box down) must still report non-zero or that failure reads as success.
exit($up === count($domains) ? 0 : 1);
