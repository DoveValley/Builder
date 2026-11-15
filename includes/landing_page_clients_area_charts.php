<?php
/**
 * Landing Page Clients — real climate Data Chart (redesign plan item #4).
 *
 * Reuses plugins/image-data-chart's real engine in two separate halves, same
 * separation the niche fleet's own generate.py already uses (its
 * _apply_chart_fetches() is a standalone pass over cities.json, decoupled
 * from city_chart_render() which never itself fetches anything):
 *
 *   1. FETCHING (this file's lpc_fetch_climate_data()) — admin-triggered,
 *      explicit, same "real external data, not automatic" pattern the
 *      wizard's own "Fetch Keyword Data" button already uses. Shells out to
 *      plugins/image-data-chart/providers/lpc_bridge.py, which loads
 *      datasets.py by file path (mirroring generate.py's own
 *      importlib.spec_from_file_location approach) and calls its real
 *      fetch_city() — never reimplemented in PHP. A value is retrieved or
 *      absent; this file adds no estimate of its own.
 *
 *   2. RENDERING (lpc_resolve_data_chart(), called at publish time) — purely
 *      reads whatever was already fetched, zero network calls inline, so a
 *      slow/flaky NOAA round-trip can never slow down or break a Publish
 *      click the way it would if fetching happened there instead.
 *
 * Fetched data is stored in a SHARED cache keyed by city_id (not per
 * client) — climate figures for Plano, TX are the same fact regardless of
 * which client's page shows them, same "drawn once per city, every site
 * reuses it" principle landing_page_clients_area_images.php's map already
 * follows, mirroring cities.json itself being one shared file per niche
 * master rather than per site.
 */

require_once __DIR__ . '/../plugins/image-data-chart/charts.php';
require_once __DIR__ . '/../plugins/image-data-chart/render.php';
require_once __DIR__ . '/../admin/infra/lib/cities.php';

/**
 * One real, topically-relevant chart per niche — not a generic pick. All 4
 * are the simplest category this engine supports (a single NOAA monthly
 * station value, no ACS/storm-event lookups, no statewide benchmark
 * comparison), deliberately chosen as the first integration.
 */
function lpc_chart_file_for_niche($niche_id) {
    $map = [
        'water-restoration' => ['folder' => 'water-restoration', 'file' => 'heavy_rain_days.json'], // ties to flood risk
        'mold-remediation'  => ['folder' => 'mold-remediation',  'file' => 'wet_days.json'],         // moisture conditions that drive mold
        'pest-control'      => ['folder' => 'pest',              'file' => 'hot_days.json'],         // folder name mismatch, same as safety_toolkit's
        'appliance-repair'  => ['folder' => 'appliance-repair',  'file' => 'hot_days.json'],          // refrigeration compressor stress
    ];
    return $map[$niche_id] ?? null;
}

function lpc_chart_definition_for_niche($niche_id) {
    $picked = lpc_chart_file_for_niche($niche_id);
    if (!$picked) return null;
    $path = __DIR__ . '/../plugins/image-data-chart/niches/' . $picked['folder'] . '/' . $picked['file'];
    if (!is_file($path)) return null;
    $def = json_decode(file_get_contents($path), true);
    return is_array($def) ? $def : null;
}

/** [{data_key, source_key, provider, data_type, dataset, extras, benchmark}]
 *  shape chart_fetch_decls()/fetch_city() expect, built from one chart
 *  definition's own `fetch` block — mirrors chart_fetch_decls() in Python,
 *  just reading one already-known file instead of globbing a niche folder. */
function lpc_chart_decl($def) {
    $fetch = $def['fetch'] ?? [];
    return [
        'data_key'   => (string) ($def['data_key'] ?? ''),
        'source_key' => (string) ($def['source_key'] ?? ''),
        'provider'   => $fetch['provider'] ?? 'noaa_normals_monthly',
        'data_type'  => (string) ($fetch['data_type'] ?? ''),
        'dataset'    => ($fetch['provider'] ?? '') === 'noaa_normals_annual' ? 'normals-annualseasonal-1991-2020' : 'normals-monthly-1991-2020',
        'extras'     => [],
        'benchmark'  => [],
    ];
}

function lpc_climate_cache_path() {
    return LPC_DELIVERY_DATA_DIR . 'shared/climate_cache.json';
}

function lpc_climate_cache_load() {
    $path = lpc_climate_cache_path();
    if (!is_file($path)) return [];
    $data = json_decode(file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

function lpc_climate_cache_save($cache) {
    lpc_atomic_write_json(lpc_climate_cache_path(), $cache);
}

/**
 * Admin-triggered fetch — real NOAA network call, explicit opt-in, same
 * reasoning as the wizard's "Fetch Keyword Data" button (slow/external, not
 * something every publish should pay for automatically). Idempotent: a city
 * that already has this chart's data cached is skipped unless $force,
 * same "don't re-hit the API every run" check _apply_chart_fetches() uses
 * (there, keyed on figures_fetched_at; here, on cache-entry presence).
 */
function lpc_fetch_climate_data($city_id, $niche_id, $force = false) {
    $def = lpc_chart_definition_for_niche($niche_id);
    if (!$def) return ['ok' => false, 'msg' => 'No climate chart defined for this niche yet.'];

    $cacheKey = $city_id . '__' . $def['id'];
    $cache = lpc_climate_cache_load();
    if (!$force && isset($cache[$cacheKey])) {
        return ['ok' => true, 'msg' => 'Already fetched — ' . ($cache[$cacheKey]['noaa_station'] ?? 'cached'), 'cached' => true];
    }

    $cityRow = infra_city_get($city_id);
    if (!$cityRow || $cityRow['lat'] === '' || $cityRow['lng'] === '') {
        return ['ok' => false, 'msg' => 'No geocoded coordinates on record for this city.'];
    }

    $request = [
        'city' => [
            'city'  => $cityRow['city'],
            'state' => strtolower($cityRow['state']),
            'lat'   => (float) $cityRow['lat'],
            'lng'   => (float) $cityRow['lng'],
        ],
        'decls' => [lpc_chart_decl($def)],
    ];

    $bridge = __DIR__ . '/../plugins/image-data-chart/providers/lpc_bridge.py';
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open(['python3', $bridge], $descriptors, $pipes);
    if (!is_resource($proc)) {
        return ['ok' => false, 'msg' => 'Could not start the NOAA fetch process.'];
    }
    fwrite($pipes[0], json_encode($request));
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($proc);

    if ($exitCode !== 0) {
        return ['ok' => false, 'msg' => 'NOAA fetch failed: ' . trim($stderr ?: 'unknown error')];
    }
    $result = json_decode($stdout, true);
    if (!is_array($result)) {
        return ['ok' => false, 'msg' => 'NOAA fetch returned an unreadable response.'];
    }
    $updates = $result['updates'] ?? [];
    if (empty($updates)) {
        $problems = implode('; ', $result['problems'] ?? []);
        return ['ok' => false, 'msg' => 'No data retrieved' . ($problems ? ": {$problems}" : '.')];
    }

    $cache[$cacheKey] = $updates;
    lpc_climate_cache_save($cache);
    return ['ok' => true, 'msg' => 'Fetched from ' . ($updates['noaa_station'] ?? 'NOAA') . ' (' . ($updates['noaa_station_miles'] ?? '?') . ' mi away).'];
}

/**
 * Read-only at publish time — never fetches. Returns
 * ['url'=>,'alt'=>,'width'=>,'height'=>,'table_html'=>] or null when nothing
 * has been fetched yet for this city/niche (same "no data means nothing
 * renders" rule as every other auto-computed section).
 */
function lpc_resolve_data_chart($client_id, $city_id, $city_name, $state, $niche_id, $branding) {
    $def = lpc_chart_definition_for_niche($niche_id);
    if (!$def) return null;

    $cacheKey = $city_id . '__' . $def['id'];
    $cache = lpc_climate_cache_load();
    if (!isset($cache[$cacheKey])) return null;

    $cityRow = infra_city_get($city_id);
    $city = array_merge($cache[$cacheKey], [
        'city'      => $city_name,
        'SS'        => $state,
        'city_slug' => 'chart_' . $city_id,
    ]);

    $series = city_chart_series($def, $city);
    if ($series === null) return null;

    $theme = [
        'bg'     => '#f4f7fa',
        'ink'    => !empty($branding['primary_color']) ? $branding['primary_color'] : '#12314f',
        'accent' => !empty($branding['accent_color']) ? $branding['accent_color'] : '#1f78d1',
        'muted'  => '#6b7f95',
    ];

    $dir = lpc_service_image_dir($client_id);
    if (!is_dir($dir)) mkdir($dir, 0775, true);

    $result = city_chart_render($dir, $city, $def, $theme);
    if (!$result) return null;
    if (!str_ends_with(parse_url($result['path'], PHP_URL_PATH), '.webp')) return null; // same raster-only rule as the area map

    // Same nested-path relocation fix area_images.php needed — city_chart_
    // render() writes into $dir/uploads/media/, delivery-server/public/
    // api.php's `image` action only serves a flat clients/{id}/images/.
    $filename = basename(parse_url($result['path'], PHP_URL_PATH));
    $nested   = $dir . 'uploads/media/' . $filename;
    $flat     = $dir . $filename;
    // Check the actual copy() result, not just "does a file exist afterward"
    // — see area_images.php's identical fix for why.
    $copied = is_file($nested) && copy($nested, $flat);
    if (!$copied || !is_file($flat)) return null;

    $dims = @getimagesize($flat);
    return [
        'heading'    => city_chart_text((string) ($def['name'] ?? ''), $city, $def, $series),
        // filemtime($flat), not city_chart_render()'s own ?v= (dropped by
        // parse_url(..., PHP_URL_PATH) above) — re-added here so a theme-
        // color change actually busts any CDN/browser cache of this URL,
        // same fix as area_images.php's lpc_resolve_area_map().
        'url'        => lpc_service_image_url($client_id, $filename) . '&v=' . filemtime($flat),
        'alt'        => $result['alt'],
        'width'      => $dims[0] ?? null,
        'height'     => $dims[1] ?? null,
        'table_html' => city_chart_table_html($def, $series, $city),
    ];
}
