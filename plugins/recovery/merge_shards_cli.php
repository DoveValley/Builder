<?php
/**
 * Merge per-state intersection shards (ai_intersections.<state>.json, written by parallel
 * enrich processes) into the main ai_intersections.json, then delete the shards.
 *   MULTISITE_SITE_BASE=.../sites/recovery-site php plugins/recovery/merge_shards_cli.php
 * The glob pattern ai_intersections.*.json never matches the single-dot main file.
 */
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(2); }
require __DIR__ . '/../../config.php';
require __DIR__ . '/../../includes/functions.php';
if (ACTIVE_SITE_ID !== 'recovery-site') { fwrite(STDERR, "Set MULTISITE_SITE_BASE.\n"); exit(2); }

$dir  = ACTIVE_SITE_DIR . '/data/recovery/';
$main = is_file($dir . 'ai_intersections.json') ? (json_decode(file_get_contents($dir . 'ai_intersections.json'), true) ?: []) : [];
$added = 0;
foreach (glob($dir . 'ai_intersections.*.json') as $f) {
    $shard = json_decode(file_get_contents($f), true) ?: [];
    foreach ($shard as $k => $v) { if (!empty($v['intro_html'])) { if (!isset($main[$k])) $added++; $main[$k] = $v; } }
    @unlink($f);
}
file_put_contents($dir . 'ai_intersections.json', json_encode($main, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
@exec('chown -R www-data:www-data ' . escapeshellarg(rtrim($dir, '/')));
echo "merged: total=" . count($main) . " added=$added\n";
