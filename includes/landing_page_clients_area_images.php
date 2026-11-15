<?php
/**
 * Landing Page Clients — City Map image, reusing the site factory's own
 * engine directly (Phase 3 backlog: "look more like a real competitor page").
 *
 * plugins/image-area-map/render.php's city_map_render() is cleanly
 * parameterized (explicit $siteDir/$city/$theme args) even though its own
 * plugin.php wrapper is wired into the site-factory's global $data/
 * ACTIVE_SITE_DIR/shortcode-token machinery — so this file calls the real
 * drawing logic directly and skips that registration layer entirely, same
 * "call the real logic, not the site-factory-specific wiring" pattern
 * includes/openai_images.php already follows for image generation.
 *
 * Coordinates and "nearby towns" both come from the existing 43,901-row
 * cities SQLite table (admin/infra/lib/cities.php) — already real, already
 * geocoded, zero network calls needed (unlike the Data Chart plugin's real
 * climate data, which requires a live NOAA fetch — see
 * landing_page_clients_area_charts.php for that half).
 *
 * Written into the client's existing images/ directory (same one
 * lpc_service_image_dir() already uses for per-service photos), not a
 * separate folder — delivery-server/public/api.php's `image` action
 * hardcodes that one subdirectory, so a new subdir would need new
 * server-side wiring for no real benefit. A distinct filename prefix
 * ('areamap_') avoids any collision with service photos.
 */

require_once __DIR__ . '/../plugins/image-area-map/render.php';
require_once __DIR__ . '/../admin/infra/lib/cities.php';
require_once __DIR__ . '/multisite/geocode.php'; // ms_haversine_miles()

/**
 * Up to $limit real towns within $radiusMiles of a city, closest first — same
 * cities table + haversine math lpc_city_candidates() already uses for the
 * wizard's city-suggestion screen, just sorted by distance (not population):
 * a map caption should name what's actually nearby, not just what's biggest.
 */
function lpc_nearby_towns_for_map($city_id, $limit = 6, $radiusMiles = 25) {
    $city = infra_city_get($city_id);
    if (!$city || $city['lat'] === '' || $city['lng'] === '') return [];

    $db = infra_cities_init();
    $rows = $db->query('SELECT city, ss, lat, lng FROM cities WHERE lat <> "" AND lng <> "" AND population > 0')->fetchAll(PDO::FETCH_ASSOC);
    $towns = [];
    foreach ($rows as $r) {
        if (strcasecmp($r['city'], $city['city']) === 0 && strcasecmp($r['ss'], $city['ss']) === 0) continue;
        $dist = ms_haversine_miles((float) $city['lat'], (float) $city['lng'], (float) $r['lat'], (float) $r['lng']);
        if ($dist > $radiusMiles || $dist < 0.1) continue;
        $towns[] = ['name' => $r['city'], 'miles' => round($dist, 1)];
    }
    usort($towns, fn($a, $b) => $a['miles'] <=> $b['miles']);
    return array_slice($towns, 0, $limit);
}

/**
 * Resolves/generates this city's area map image. Returns ['url'=>,'alt'=>] or
 * null when there's not enough real data to draw (no coords on record, or no
 * real nearby towns within range) — same "no data means nothing renders"
 * rule the niche-fleet plugin itself already follows, never a guess.
 *
 * Also returns null if rasterization fails (no SVG-capable renderer on this
 * box) rather than falling back to serving an SVG: delivery-server/public/
 * api.php's `image` action only allows webp/jpg/png/gif by design (its
 * filename regex), and loosening that for one rare fallback case isn't worth
 * the surface-area increase — a city map is a nice-to-have visual, not
 * something a page should ever be broken without.
 */
function lpc_resolve_area_map($client_id, $city_id, $city_name, $state, $branding) {
    $cityRow = infra_city_get($city_id);
    if (!$cityRow || $cityRow['lat'] === '' || $cityRow['lng'] === '') return null;

    $nearby = lpc_nearby_towns_for_map($city_id);
    if (empty($nearby)) return null;

    $city = [
        'city'         => $city_name,
        'SS'           => $state,
        'city_slug'    => $city_id,
        'lat'          => (float) $cityRow['lat'],
        'lng'          => (float) $cityRow['lng'],
        'nearby_towns' => $nearby,
    ];
    $theme = [
        'bg'     => '#f4f7fa',
        'ink'    => !empty($branding['primary_color']) ? $branding['primary_color'] : '#12314f',
        'accent' => !empty($branding['accent_color']) ? $branding['accent_color'] : '#1f78d1',
        'muted'  => '#6b7f95',
    ];

    $dir = lpc_service_image_dir($client_id);
    if (!is_dir($dir)) mkdir($dir, 0775, true);

    // city_map_paths() names the file from city_slug alone ('service-area-map-
    // {slug}'), with no prefix of its own — add one here so it can never
    // collide with a service photo's own filename in the same directory.
    $result = city_map_render($dir, array_merge($city, ['city_slug' => 'areamap_' . $city_id]), $theme);
    if (!$result) return null;

    // Only accept a real raster output — see function doc above for why an
    // SVG fallback is treated the same as "could not draw" here.
    if (!str_ends_with(parse_url($result['path'], PHP_URL_PATH), '.webp')) return null;

    // city_map_render() writes into $dir/uploads/media/ (the niche-fleet's own
    // site-relative convention — $siteDir is normally a whole SITE, with its
    // own uploads/ subfolder, not a flat image bucket like ours). COPY (not
    // move) the one file we want up into $dir itself, which is what
    // delivery-server/public/api.php's `image` action expects (one flat
    // directory per client, no subpaths) — the nested original has to stay
    // put, since city_map_render()'s own "skip redrawing if unchanged" cache
    // check (render.php) looks for it at that same nested path on the next
    // call; moving it away would make every publish redraw from scratch.
    $filename = basename(parse_url($result['path'], PHP_URL_PATH));
    $nested   = $dir . 'uploads/media/' . $filename;
    $flat     = $dir . $filename;
    if (is_file($nested)) {
        copy($nested, $flat);
    }
    if (!is_file($flat)) return null;

    $dims = @getimagesize($flat);
    return [
        'url'    => lpc_service_image_url($client_id, $filename),
        'alt'    => $result['alt'],
        'width'  => $dims[0] ?? null,
        'height' => $dims[1] ?? null,
    ];
}
