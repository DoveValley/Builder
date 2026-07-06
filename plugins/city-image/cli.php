<?php
/**
 * City Image plugin — CLI entry point.
 *
 * Fetches + self-hosts a scenic city image and prints a JSON result. Called by
 * the multisite generator (generate.py) once per researched city, and usable
 * standalone for testing.
 *
 * Usage:
 *   php plugins/city-image/cli.php \
 *     --city=Lufkin --state=Texas --ss=TX --city-slug=lufkin-tx \
 *     --out-dir=/abs/path/to/uploads/media \
 *     --store-prefix=sites/pest-template/uploads/media
 *
 * Prints: {"path":..,"alt":..,"credit":..,"source":..,"title":..}  (exit 0)
 *   or:   {"error":".."}                                            (exit 1)
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

define('BASE_DIR', dirname(__DIR__, 2));   // plugins/city-image -> project root
require_once __DIR__ . '/fetch.php';

$args = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([^=]+)=(.*)$/s', $a, $m)) $args[$m[1]] = $m[2];
}

$res = city_image_fetch_for([
    'city'         => $args['city']         ?? '',
    'state'        => $args['state']        ?? '',
    'ss'           => $args['ss']           ?? '',
    'city_slug'    => $args['city-slug']    ?? '',
    'out_dir'      => $args['out-dir']      ?? '',
    'store_prefix' => $args['store-prefix'] ?? '',
]);

echo json_encode($res ?? ['error' => 'no suitable image found'], JSON_UNESCAPED_SLASHES) . "\n";
exit($res ? 0 : 1);
