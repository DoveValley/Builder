<?php
/**
 * Recovery plugin — AI enrichment runner (CLI, background-friendly).
 *
 *   MULTISITE_SITE_BASE=/var/www/homepage-builder-new/sites/recovery-site \
 *   php plugins/recovery/enrich_cli.php [--refresh]
 *
 * Generates the ai bundle for every state / carrier / city (skipping those that already
 * have one unless --refresh). Incremental save per row, 429 backoff, paced. Idempotent.
 */
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(2); }

require __DIR__ . '/../../config.php';
require __DIR__ . '/../../includes/functions.php';
if (ACTIVE_SITE_ID !== 'recovery-site') { fwrite(STDERR, "Set MULTISITE_SITE_BASE to the recovery-site dir.\n"); exit(2); }
require_once __DIR__ . '/enrich.php';

$refresh = in_array('--refresh', $argv, true);
$dir = ACTIVE_SITE_DIR . '/data/recovery/';
$log = fopen('/tmp/enrich.log', 'w');

function _gen($type, $primary, $secondary, $log) {
    for ($t = 0; $t < 4; $t++) {
        $r = recovery_ai_generate($type, $primary, $secondary);
        if (is_array($r) && !empty($r['__rate__'])) { fwrite($log, "  rate-limited; backoff\n"); fflush($log); sleep(20 * ($t + 1)); continue; }
        return $r;   // array (ok) or null (fail)
    }
    return null;
}

$total = 0; $ok = 0;
// carriers + states first (fewer), then cities
foreach (['states.json' => 'state', 'carriers.json' => 'carrier', 'cities.json' => 'city'] as $file => $type) {
    $rows = json_decode(file_get_contents($dir . $file), true);
    $sSS = [];
    if ($type === 'city') foreach (json_decode(file_get_contents($dir . 'states.json'), true) as $s) $sSS[$s['slug']] = $s['ss'];
    foreach ($rows as $i => $row) {
        if (!$refresh && !empty($row['ai'])) continue;
        $total++;
        $primary   = $row['name'] ?? '';
        $secondary = $type === 'city' ? ($sSS[$row['state'] ?? ''] ?? '') : '';
        $ai = _gen($type, $primary, $secondary, $log);
        if ($ai) {
            $rows[$i]['ai'] = $ai; $ok++;
            file_put_contents($dir . $file, json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            fwrite($log, "OK   $type: $primary\n");
        } else {
            fwrite($log, "FAIL $type: $primary\n");
        }
        fflush($log);
        usleep(700000);   // ~0.7s pace
    }
}
exec('chown -R www-data:www-data ' . escapeshellarg(rtrim($dir, '/')));
fwrite($log, "\nDONE ok=$ok/$total\n"); fclose($log);
