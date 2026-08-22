<?php
/**
 * Phase 6 — "Create zone: all" / "Go Live: all", for the whole batch. Detached, like
 * create_hosts.php and upload_sites.php.
 *
 *   php multisite/golive_run.php <master_id> --batch=bN --step=zone|golive
 *
 * WHY DETACHED. Every other bulk button on the batch page (create host, upload) runs
 * as a background process the page polls, because provisioning fifty domains is
 * minutes of work and a synchronous request times out somewhere in the middle with no
 * record of how far it got. "Go Live: all" used to call infra_pipeline_run() directly
 * inside the AJAX request instead — the one bulk action on the page that could still
 * die mid-run (a slow registrar, a proxy timeout) and leave the operator with no way
 * to tell which domains it actually finished. This is that same fix, applied here.
 *
 * Prints one line per domain as infra_pipeline_run() works through it, so a stalled or
 * killed run's log still shows exactly how far it got — not just a final tally that
 * never arrives.
 */
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "golive_run.php is CLI only\n"); exit(2); }

require __DIR__ . '/../config.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/multisite/batch.php';
require __DIR__ . '/../admin/infra/lib/pipeline.php';

$args     = array_slice($argv, 1);
$masterId = (string) ($args[0] ?? '');
$batchId  = '';
$step     = '';
foreach ($args as $a) {
    if (str_starts_with($a, '--batch=')) $batchId = substr($a, 8);
    if (str_starts_with($a, '--step='))  $step    = substr($a, 7);
}

if ($masterId === '' || $batchId === '' || !in_array($step, ['zone', 'golive'], true)) {
    fwrite(STDERR, "usage: golive_run.php <master_id> --batch=bN --step=zone|golive\n");
    exit(2);
}
if (!ms_batch_exists($masterId, $batchId)) { fwrite(STDERR, "No such batch: {$masterId}/{$batchId}\n"); exit(2); }

$tag   = $masterId . '/' . $batchId;
$label = $step === 'zone' ? 'Create zone' : 'Go Live';
printf("%s — batch %s\n", $label, $tag);
echo str_repeat('-', 52) . "\n";

$out = infra_pipeline_run($step, $tag, function (string $domain, array $d) {
    $glyph = $d['ok'] ? '✓' : (str_starts_with($d['msg'], 'blocked:') ? '⧗' : '✗');
    printf("  %s %-34s %s\n", $glyph, $domain, $d['msg']);
    // Flushed per line, not buffered for the whole run — the page polling this file is
    // reading it while the process is still alive.
    @flush();
});

echo "\n" . str_repeat('=', 52) . "\n";
printf(
    "DONE — ran %d, %d ok%s%s%s.\n",
    $out['ran'], $out['ok'],
    $out['failed']  > 0 ? ', ' . $out['failed'] . ' failed' : '',
    $out['blocked'] > 0 ? ', ' . $out['blocked'] . ' blocked by an earlier step' : '',
    $out['skipped'] > 0 ? ' (' . $out['skipped'] . ' already done)' : ''
);
if (!empty($out['restarted'])) printf("Web server restarted on %d box(es).\n", $out['restarted']);

exit($out['failed'] > 0 ? 1 : 0);
