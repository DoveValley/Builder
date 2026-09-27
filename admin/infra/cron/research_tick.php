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
 */
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(1); }

require_once __DIR__ . '/../lib/research.php';

$nicheFilter = $argv[1] ?? null;
$ts = gmdate('c');

$runs = infra_research_list_runs();
$active = array_filter($runs, fn($r) =>
    ($r['phase'] ?? '') !== 'done' && ($nicheFilter === null || ($r['niche'] ?? '') === $nicheFilter));

if (!$active) {
    echo "{$ts} nothing to do — no in-progress runs" . ($nicheFilter ? " for {$nicheFilter}" : '') . ".\n";
    exit(0);
}

$ticked = 0;
foreach ($active as $run) {
    $result = infra_research_tick($run);
    infra_research_save_run($run);
    echo "{$ts} {$run['niche']} {$run['id']}: {$result['msg']}\n";
    $ticked++;
}

@file_put_contents(dirname(__DIR__) . '/state/research_tick.json', json_encode([
    'at' => time(), 'ticked' => $ticked,
], JSON_PRETTY_PRINT));
