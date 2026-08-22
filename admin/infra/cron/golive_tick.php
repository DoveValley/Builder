<?php
/**
 * infra/cron/golive_tick.php — daily go-live batch runner (CLI only).
 *
 * Run from real cron as www-data, e.g.:
 *   0 9 * * *  www-data  php /var/www/homepage-builder-new/admin/infra/cron/golive_tick.php 20 >> /var/log/infra-golive.log 2>&1
 *
 * Each run: (1) refresh live status from Cloudflare, (2) release up to <cap>
 * domains that are due today, (3) refresh again. Idempotent and safe to re-run.
 */
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(1); }

require_once __DIR__ . '/../lib/golive.php';
require_once __DIR__ . '/../lib/cache.php';
require_once __DIR__ . '/../lib/pipeline.php';

$cap = max(1, (int) ($argv[1] ?? 20));
$ts  = gmdate('c');

infra_cache_force();                 // cron always polls Cloudflare live
$pre = infra_golive_refresh_live();
$due = infra_golive_due();

// A domain refused by the gate (nothing uploaded, or nobody has checked) does NOT
// count against the cap: it never went anywhere, and letting it eat a slot would
// quietly shrink the day's release run. It stays due, and says so every morning
// until somebody either uploads the site or overrides deliberately from the grid.
$released = 0; $gated = 0;
foreach ($due as $domain => $rec) {
    if ($released >= $cap) break;
    // Locked the same way an interactive Go Live click is (infra_pipeline_do()'s
    // 'golive' step) — this unattended run used to be the one caller that never
    // took the lock at all, so it could race a manual click on the same domain.
    $r = infra_pipeline_lock($domain, 'golive', fn() => infra_golive_release($domain));
    if (!empty($r['gated'])) {
        $gated++;
        echo "{$ts} HELD {$domain}: {$r['message']}\n";
        continue;
    }
    echo "{$ts} release {$domain}: {$r['message']}\n";
    $released++;
}

$post = infra_golive_refresh_live();
$remaining = max(0, count($due) - $released - $gated);
echo "{$ts} tick done — newly-live: " . ($pre + $post) . ", released: {$released}/{$cap}"
   . ($gated ? ", held: {$gated} (nothing uploaded)" : '') . ", still-due: {$remaining}\n";

// A run that leaves a footprint is a run you can ask about later. The grid reads this
// to say when the tick last ran — a scheduler that has silently stopped is worse than
// no scheduler, and until this line existed there was no way to tell the difference.
@file_put_contents(dirname(__DIR__) . '/state/golive_tick.json', json_encode([
    'at' => time(), 'due' => count($due), 'released' => $released, 'held' => $gated, 'cap' => $cap,
], JSON_PRETTY_PRINT));
