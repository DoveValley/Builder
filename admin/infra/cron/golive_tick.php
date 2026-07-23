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

$cap = max(1, (int) ($argv[1] ?? 20));
$ts  = gmdate('c');

infra_cache_force();                 // cron always polls Cloudflare live
$pre = infra_golive_refresh_live();
$due = infra_golive_due();

$released = 0;
foreach ($due as $domain => $rec) {
    if ($released >= $cap) break;
    $r = infra_golive_release($domain);
    echo "{$ts} release {$domain}: {$r['message']}\n";
    $released++;
}

$post = infra_golive_refresh_live();
$remaining = max(0, count($due) - $released);
echo "{$ts} tick done — newly-live: " . ($pre + $post) . ", released: {$released}/{$cap}, still-due: {$remaining}\n";
