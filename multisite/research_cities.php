<?php
/**
 * Campaign research step (item 1e). Seed the master's cities.json with every city in
 * the params table, then run the niche-aware research (generate.py --research-only) so
 * each city gets real local facts before a campaign generates copy. Research is stored
 * ONCE in the persistent cities.json (not per ephemeral build), so it's reused + free.
 *
 *   php multisite/research_cities.php <master_id> [--dry-run]
 *
 * No-op (exit 0) if the niche brief has uses_research_fields=false — that niche localizes
 * through its own angle + shortcodes and needs no per-city lookup.
 */
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "research_cities.php is CLI only\n"); exit(2); }

require __DIR__ . '/../config.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/multisite/params.php';

$args     = array_slice($argv, 1);
$dry      = in_array('--dry-run', $args, true);
$masterId = $args[0] ?? '';
if ($masterId === '' || !is_dir(BASE_DIR . '/sites/' . $masterId)) {
    fwrite(STDERR, "usage: research_cities.php <master_id> [--dry-run]\n");
    exit(2);
}

$masterDir  = BASE_DIR . '/sites/' . $masterId;
$csvPath    = $masterDir . '/multisite/params.csv';
$briefPath  = $masterDir . '/multisite/niche_brief.json';
$citiesFile = $masterDir . '/data/cities.json';

if (!is_file($csvPath)) { fwrite(STDERR, "No params.csv for {$masterId} — upload it first.\n"); exit(2); }

// ── Seed cities.json from the params table (city|SS uniqueness) ────────────────
$parsed = ms_parse_csv($csvPath);
if ($parsed['error']) { fwrite(STDERR, 'CSV error: ' . $parsed['error'] . "\n"); exit(2); }

$cities = json_decode((string)@file_get_contents($citiesFile), true);
if (!is_array($cities)) $cities = [];
$have = [];
foreach ($cities as $c) { $have[strtolower(trim(($c['city'] ?? '') . '|' . ($c['SS'] ?? '')))] = true; }

$added = 0;
foreach ($parsed['rows'] as $r) {
    $city = trim($r['city'] ?? ''); $SS = trim($r['SS'] ?? ''); $state = trim($r['state'] ?? '');
    if ($city === '' || $SS === '') continue;
    $key = strtolower("{$city}|{$SS}");
    if (isset($have[$key])) continue;
    $have[$key] = true;
    $slug = slugify("{$city}-{$SS}");
    $cities[] = ['id' => $slug, 'city' => $city, 'state' => $state, 'SS' => $SS, 'city_slug' => $slug];
    $added++;
}
if ($added > 0) {
    file_put_contents($citiesFile, json_encode(array_values($cities), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}
echo "Seeded {$added} new city row(s) into cities.json (" . count($cities) . " total).\n";

// ── Geocode: fill any missing city lat/lng from OpenStreetMap (authoritative, not AI) ──
// Runs for every niche — coordinates power the LocalBusiness schema regardless of AI research.
require_once __DIR__ . '/../includes/multisite/geocode.php';
echo "Geocoding cities (city-center lat/lng from OpenStreetMap)…\n";
$g = ms_geocode_cities_file($citiesFile, function ($m) { echo $m . "\n"; }, false);
echo "Coordinates: filled {$g['filled']}, already had {$g['skipped']}, failed {$g['failed']}.\n";

// Gate: niches that don't use AI research localize via their angle + shortcodes.
$brief = json_decode((string)@file_get_contents($briefPath), true) ?: [];
if (empty($brief['uses_research_fields'])) {
    echo "uses_research_fields=false — coordinates done; no AI city research needed.\n";
    exit(0);
}

// ── Run the niche-aware research (fills only cities lacking data) ──────────────
if (defined('ANTHROPIC_API_KEY') && ANTHROPIC_API_KEY !== '') putenv('ANTHROPIC_API_KEY=' . ANTHROPIC_API_KEY);
$cmd = 'python3 ' . escapeshellarg(BASE_DIR . '/generate.py')
     . ' --site-dir ' . escapeshellarg($masterDir) . ' --research-only'
     . ($dry ? ' --dry-run' : '') . ' 2>&1';
echo "Running research (" . ($dry ? 'dry-run' : 'live') . ")…\n";
passthru($cmd, $code);
exit((int)$code);
