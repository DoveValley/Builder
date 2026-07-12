<?php
/**
 * Recovery plugin — static build runner (CLI).
 *
 * Usage:
 *   MULTISITE_SITE_BASE=/var/www/homepage-builder-new/sites/recovery-site \
 *   php plugins/recovery/build_cli.php
 *
 * Optional: RECOVERY_OUTPUT=/abs/output/dir (default: sites/recovery-site/output).
 *
 * Steps: clean output → factory build_static_site() (home/legal/assets/uploads) →
 * plugin recovery_build_static() (matrix) → complete sitemap + manifest. The factory
 * is only CALLED, never modified.
 */
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(2); }

require __DIR__ . '/../../config.php';
require __DIR__ . '/../../includes/functions.php';

if (ACTIVE_SITE_ID !== 'recovery-site') {
    fwrite(STDERR, "Set MULTISITE_SITE_BASE to the recovery-site dir (active: '" . ACTIVE_SITE_ID . "')\n");
    exit(2);
}
require_once __DIR__ . '/build.php';
require_once BASE_DIR . '/includes/static_build.php';
if (function_exists('progress_set_sink')) progress_set_sink(function (...$a) {});

$out       = rtrim(getenv('RECOVERY_OUTPUT') ?: (BASE_DIR . '/sites/recovery-site/output'), '/') . '/';
$canonical = 'https://r.q111.xyz';

// 1. clean output (so the factory prune is a no-op; no stale nested dirs)
if (is_dir($out)) exec('rm -rf ' . escapeshellarg(rtrim($out, '/')));
@mkdir($out, 0755, true);

// 2. factory base build — homepage, legal pages, assets, uploads, 404 (UNCHANGED core)
$base = build_static_site($out, $canonical, '');

// 3. plugin matrix build — the nested state/city/carrier pages
$siteData = load_data();
$matrix   = recovery_build_static($out, $siteData);

// 4. complete sitemap + manifest from what is actually on disk
$locs = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($out, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if ($f->getFilename() !== 'index.html') continue;
    $rel = trim(substr($f->getPath(), strlen(rtrim($out, '/'))), '/');
    $locs[] = $rel === '' ? '/' : '/' . $rel . '/';
}
sort($locs);
$sm = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
    . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($locs as $l) $sm .= '  <url><loc>' . htmlspecialchars($canonical . $l) . '</loc></url>' . "\n";
$sm .= '</urlset>' . "\n";
file_put_contents($out . 'sitemap.xml', $sm);

// 5. manifest (so we know exactly what is deployed)
$bd = recovery_url_breakdown();
$matrixTotal = array_sum($bd);
$man  = "Recovery Dawn — static build manifest\n" . str_repeat('=', 42) . "\n";
$man .= "Output:    $out\nCanonical: $canonical\nGated:     city×company " . (recovery_config()['phasing']['publish_city_company'] ? 'ON' : 'OFF') . "\n\n";
$man .= "Total pages on disk: " . count($locs) . "\n\n";
$man .= "Matrix pages (by type):\n";
foreach ($bd as $k => $v) $man .= sprintf("  %-18s %5d\n", $k, $v);
$man .= sprintf("  %-18s %5d\n", 'matrix total', $matrixTotal);
$man .= "\nBase pages (home + legal + 404 + non-matrix): " . (count($locs) - $matrixTotal) . "\n";
file_put_contents($out . '_manifest.txt', $man);

echo $man;
echo "\n[base build] " . json_encode($base) . "\n[matrix build] pages=" . $matrix['pages'] . "\n[sitemap] " . count($locs) . " URLs\n";
