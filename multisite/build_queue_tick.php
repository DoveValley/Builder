<?php
/**
 * multisite/build_queue_tick.php — drains every batch's build queue (CLI only).
 *
 * Run from real cron every 2 minutes, see /etc/cron.d/infra-buildqueue for the
 * exact line (not shown here — a literal cron "every N minutes" field contains
 * the same two characters that close a PHP block comment).
 *
 * A "Run selected" or row-level Correct & Regenerate click that lands while that
 * same batch is already building doesn't get refused anymore — multisite_api.php's
 * rollout_build action queues it instead (ms_build_queue_launch_or_queue(), see its
 * docblock in includes/multisite/batch.php). But nothing is watching the browser:
 * once the page closes, queued work needs something OTHER than the click itself to
 * notice the batch freed up and actually launch it. This is that something.
 *
 * Idempotent and safe to run often: ms_build_queue_drain() takes the exact same
 * lock + busy-check ms_launch_job() always has, so a batch genuinely still
 * building is simply left alone this tick — there is no way for this script to
 * start a second job alongside one that's already running.
 *
 * Scans every batch's buildupload/queue.json, not a list of "batches that might
 * have something queued" — cheap (file_exists + a tiny json_decode per batch),
 * and a batch with no queue.json is skipped before anything else runs.
 */
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(1); }

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/multisite/batch.php';

const BUILDQ_SENTINEL = '__MS_BUILDUP_DONE__';

$ts = gmdate('c');
$drained = 0;
$checked = 0;

foreach (glob(BASE_DIR . '/sites/*/batches/*/buildupload/queue.json') ?: [] as $queueFile) {
    $dir = dirname($queueFile);
    $checked++;
    $r = ms_build_queue_drain($dir, BUILDQ_SENTINEL);
    if ($r === null) continue;
    $drained++;
    $batchLabel = basename(dirname(dirname($dir))) . '/' . basename(dirname($dir));
    echo "{$ts} launched queued build for {$batchLabel}: " . implode(',', $r['domains'] ?? [])
       . ($r['run_id'] ? " (run {$r['run_id']})" : " (launch failed — no run_id)") . "\n";
}

echo "{$ts} tick done — {$checked} batch(es) with a queue checked, {$drained} launched.\n";

// Same footprint pattern as golive_tick.json — lets a future "when did this last
// run" check tell a silently-stopped scheduler apart from one with nothing to do.
@file_put_contents(__DIR__ . '/../admin/infra/state/build_queue_tick.json', json_encode([
    'at' => time(), 'checked' => $checked, 'drained' => $drained,
], JSON_PRETTY_PRINT));
