<?php
/**
 * infra/cron/research_tick.php — City Research batch runner (CLI only).
 *
 * A run's progress otherwise depends entirely on a browser tab: the web UI's
 * "Continue" auto-fires via a JS setTimeout, which a backgrounded tab can have
 * silently throttled or frozen by the browser, and a closed tab can't run at
 * all. This does the same ticks (infra_research_tick(), lib/research.php)
 * from real cron instead, so a run keeps moving with no browser involved.
 *
 * Suggested crontab line (every 2 minutes; each tick is already time-boxed to
 * INFRA_RESEARCH_TIME_BUDGET seconds, so more often than that just wastes a
 * cron wakeup doing nothing) - "star-slash-2" for the minute field, written
 * out here so it doesn't close this comment block early:
 *   [star-slash-2] * * * *  www-data  php /var/www/homepage-builder-new/admin/infra/cron/research_tick.php >> /var/log/infra-research.log 2>&1
 *
 * Ticks every non-done run once per invocation (not in a loop to "finish" one
 * run before starting another) - if several niches are mid-run, all of them
 * get a turn each wakeup instead of one starving the rest.
 *
 * Optional: php research_tick.php <niche> — only tick that niche's run(s).
 *
 * Non-blocking self-lock: even with the SERP-phase pooling below, a volume-
 * phase run still gets its own sequential INFRA_RESEARCH_TIME_BUDGET turn, so
 * several active niches can still add up to more than the 2-minute cron
 * interval. Cron does not wait for a prior invocation to finish before
 * starting the next, so a slow cycle would otherwise overlap with itself: two
 * processes reading and ticking the same run's JSON, issuing the same SERP/
 * volume API calls twice (real double spend), then racing to
 * file_put_contents() the result - no locking there, so whichever finishes
 * last silently wins and the other process's cost and progress are just
 * gone. Skipping an overlapping invocation outright is fine because the
 * run's own state carries over to the next un-skipped tick unchanged -
 * nothing is lost by waiting one more cycle.
 *
 * SERP-phase pooling (2026-09-29): every run currently in the serp phase is
 * ticked together via infra_research_tick_serp_pool() (lib/research.php),
 * sharing ONE time budget and ONE concurrency pool instead of each niche
 * getting its own sequential 90s turn. Before this, N niches simultaneously
 * in the serp phase could push a single invocation to N * 90s, which is
 * exactly what caused whole cron cycles to be skipped by the self-lock above
 * on 2026-09-29 (2 active niches, invocations running 3+ minutes). Only
 * volume-phase runs still tick one at a time below — that phase's own
 * batching (infra_kw_fetch(), many keywords per request) is a different
 * mechanism, and volume tends to finish in one or two passes rather than
 * being the source of long overlapping runs.
 */
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(1); }

require_once __DIR__ . '/../lib/research.php';

$ts = gmdate('c');
$lockDir = dirname(__DIR__) . '/state/locks';
if (!is_dir($lockDir)) @mkdir($lockDir, 0700, true);
$lockFh = @fopen($lockDir . '/research_tick.lock', 'c');
if ($lockFh && !flock($lockFh, LOCK_EX | LOCK_NB)) {
    echo "{$ts} skipped — a previous research_tick.php invocation is still running.\n";
    exit(0);
}
// $lockFh === false (directory unreadable/unwritable) fails OPEN rather than
// blocking every research run over what's usually a permissions slip, same
// convention as infra_pipeline_lock() elsewhere in this console.

$nicheFilter = $argv[1] ?? null;

$runs = infra_research_list_runs();
$active = array_filter($runs, fn($r) =>
    ($r['phase'] ?? '') !== 'done' && ($nicheFilter === null || ($r['niche'] ?? '') === $nicheFilter));

if (!$active) {
    echo "{$ts} nothing to do — no in-progress runs" . ($nicheFilter ? " for {$nicheFilter}" : '') . ".\n";
    exit(0);
}

// Keyed by run id so infra_research_tick_serp_pool() can mutate every
// serp-phase run directly, and so the volume loop below and the pool below it
// can both find the same in-memory copy of each run.
$byId = [];
foreach ($active as $run) $byId[$run['id']] = $run;

$ticked = 0;
$messages = [];

// Volume-phase runs still tick one at a time — see this file's header comment
// for why that phase is left out of the pool.
foreach ($byId as $rid => &$run) {
    if ($run['phase'] !== 'volume') continue;
    $result = infra_research_tick($run);
    $messages[$rid] = $result['msg'];
    $ticked++;
}
unset($run);

// Every run still in the serp phase — including one that just left 'volume'
// above, in this SAME invocation — shares one time budget and one
// concurrency pool here instead of a sequential per-niche turn.
foreach (infra_research_tick_serp_pool($byId) as $rid => $result) {
    $messages[$rid] = $result['msg'];
    $ticked++;
}

foreach ($byId as $rid => $run) {
    infra_research_save_run($run);
    if (isset($messages[$rid])) echo "{$ts} {$run['niche']} {$rid}: {$messages[$rid]}\n";
}

@file_put_contents(dirname(__DIR__) . '/state/research_tick.json', json_encode([
    'at' => time(), 'ticked' => $ticked,
], JSON_PRETTY_PRINT));
