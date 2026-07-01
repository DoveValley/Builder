<?php
/**
 * Multisite landing-page generator worker (CLI, worker config mode).
 *
 * Runs in WORKER config mode: config.php roots all path constants at the prepared
 * working site via MULTISITE_SITE_BASE (set by the parent, build_one.php). Generates
 * the template × cities landing pages into the working dir's data/pages/, scoped to
 * whatever cities the parent wrote into the working dir's cities.json (derived from
 * the deploy row's `landing_cities`). STRUCTURE ONLY — shortcodes are resolved here;
 * AI blocks are filled later by generate.py's process_landing_pages pass.
 *
 * Must run in its own process because config's path constants are immutable once
 * defined (same reason render_site.php is a separate worker).
 *
 * Emits a single JSON-line result on stdout for the parent to relay.
 */
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "generate_landing.php is CLI only\n"); exit(2); }

require __DIR__ . '/../config.php';
require __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/generation/engine.php';

if (!ACTIVE_SITE_DIR) {
    fwrite(STDERR, "Worker config not active — MULTISITE_SITE_BASE missing or invalid\n");
    exit(2);
}

// Cities to generate = every row the parent put in the working-dir cities.json.
$cities = [];
if (file_exists(CITIES_FILE)) {
    $raw = json_decode(file_get_contents(CITIES_FILE), true);
    $cities = is_array($raw) ? array_values($raw) : [];
}
if (!$cities) {
    fwrite(STDOUT, json_encode(['success' => true, 'written' => 0, 'note' => 'no landing cities']) . "\n");
    exit(0);
}
$cityIds = array_values(array_filter(array_map(fn($c) => $c['id'] ?? '', $cities), 'strlen'));

$res = generate_city_pages([
    'city_ids'       => $cityIds,
    'confirmed_cost' => true,   // structure-only steps are free; templates with paid steps still proceed under the campaign
    'force_locked'   => false,
]);

fwrite(STDOUT, json_encode($res) . "\n");
// generate_city_pages returns ['success'=>false,...] on hard failure; treat missing 'success' (normal completion) as success.
exit((isset($res['success']) && $res['success'] === false) ? 1 : 0);
