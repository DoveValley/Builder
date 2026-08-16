<?php
/**
 * Recovery plugin — SAMHSA facility fetcher (CLI, background).
 *
 *   MULTISITE_SITE_BASE=/var/www/homepage-builder-new/sites/recovery-site \
 *   php plugins/recovery/facilities_cli.php [--refresh]
 *
 * For each city: geocode (OSM Nominatim) → pull nearest SAMHSA substance-use facilities
 * → parse levels of care + payment/insurance types → store in facilities.json[city_slug].
 * Incremental save, paced (Nominatim = 1 req/sec), idempotent.
 */
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(2); }
require __DIR__ . '/../../config.php';
require __DIR__ . '/../../includes/functions.php';
require __DIR__ . '/../../includes/multisite/geocode.php';   // the Nominatim client
require __DIR__ . '/findtreatment.php';                      // the SAMHSA locator client
if (ACTIVE_SITE_ID !== 'recovery-site') { fwrite(STDERR, "Set MULTISITE_SITE_BASE.\n"); exit(2); }

$refresh = in_array('--refresh', $argv, true);
$dir = ACTIVE_SITE_DIR . '/data/recovery/';
$log = fopen('/tmp/facilities.log', 'w');

// _get(), geocode(), svc(), levels() and payments() used to live here. The geocoder
// duplicated includes/multisite/geocode.php — a second Nominatim client beside one
// that already honoured the usage policy — and the rest is now findtreatment.php.

$cities = json_decode(file_get_contents($dir.'cities.json'), true);
$sName = []; foreach (json_decode(file_get_contents($dir.'states.json'), true) as $s) $sName[$s['slug']] = $s['name'];
$fac = is_file($dir.'facilities.json') ? (json_decode(file_get_contents($dir.'facilities.json'), true) ?: []) : [];

$ok=0; $fail=0;
foreach ($cities as $c) {
    $slug = $c['slug'];
    if (!$refresh && !empty($fac[$slug])) continue;
    $ll = ms_geocode_city($c['name'], '', $sName[$c['state']] ?? '');
    usleep(1200000); // Nominatim asks for ~1 request/second
    if (!$ll) { $fail++; fwrite($log,"GEO-FAIL {$c['name']}\n"); fflush($log); continue; }
    [$lat, $lng] = [$ll['lat'], $ll['lng']];
    $list = findtreatment_near($lat, $lng, 10);
    if (!$list) { $fail++; fwrite($log,"NO-FAC {$c['name']}\n"); fflush($log); continue; }
    $fac[$slug] = $list; $ok++;
    file_put_contents($dir.'facilities.json', json_encode($fac, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
    fwrite($log, "OK   {$c['name']}: ".count($list)." facilities\n"); fflush($log);
    usleep(500000);
}
exec('chown -R www-data:www-data '.escapeshellarg(rtrim($dir,'/')));
fwrite($log, "\nDONE ok=$ok fail=$fail\n"); fclose($log);
