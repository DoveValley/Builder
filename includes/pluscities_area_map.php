<?php
/**
 * includes/pluscities_area_map.php — Area Map, ported from
 * plugins/image-area-map/* (the OLD site-building system, proven on the
 * fleet's own reference sites — Wren/Bailey/Gann all have this exact
 * section). A real basemap centred on the city's own verified coordinates
 * (admin/infra/lib/cities.php's `cities` table, ~43,900 rows, already
 * geocoded), the city's real OSM administrative boundary when one is on
 * record, and a real "nearby towns served" list — computed here from that
 * same `cities` table via a straightforward haversine distance query, never
 * researched/guessed (the old plugin's own prior approach broke exactly this
 * way: invented neighbourhoods and wrong-by-132-degrees bearings — see
 * plugins/image-area-map/draw.php's file header).
 *
 * City-level furniture, like Safety Toolkit (includes/pluscities_writer.php's
 * pluscities_render_safety_toolkit()) — drawn ONCE PER CITY and cached keyed
 * on city_id ALONE, shared across every client/page that serves that city
 * (not per-client — no theme/branding tie-in, unlike the old plugin which
 * drew one copy per SITE). "Ten sites covering the same city draw it once"
 * becomes "every client on the whole platform covering the same city draws
 * it once."
 *
 * Generation (pluscities_ensure_city_map(), network + shell-out, several
 * real seconds cold) is deliberately separate from rendering
 * (pluscities_render_area_map(), a cache read only) — the former runs from
 * pluscities_create_page() (includes/pluscities_lifecycle.php), never from
 * the public, unauthenticated-traffic-serving admin/pluscities_sync.php
 * endpoint. A city whose map hasn't been generated yet just renders nothing,
 * same graceful-degradation rule as every other data-gated piece of this
 * engine (no data means no image, never a placeholder).
 *
 * Tile/boundary fetch logic mirrors plugins/image-area-map/tiles.php closely
 * (same OSM family, same usage-policy obligations, same caching discipline —
 * see that file for the fuller rationale) and deliberately SHARES its on-disk
 * caches: cache/osm-tiles (keyed only on z/x/y, never on which system asked)
 * and cache/osm-boundaries (keyed on the same name-state slug). A city
 * either system has already drawn costs the other nothing to re-fetch.
 * Renamed pluscities_area_map_* throughout (not city_map_*) because both
 * this file and the old plugin load in the same PHP process — includes/
 * functions.php requires this file directly, and also auto-loads every
 * plugins/*\/plugin.php, image-area-map's included.
 */

require_once __DIR__ . '/../admin/infra/lib/cities.php';

const PLUSCITIES_AREA_MAP_TILE_SERVER   = 'https://tile.openstreetmap.org';
const PLUSCITIES_AREA_MAP_TILE_UA       = 'PlusCities-MapTiles/1.0 (+area-map basemap; cached per tile, low volume)';
const PLUSCITIES_AREA_MAP_BOUNDARY_UA   = 'PlusCities-MapBoundary/1.0 (+city-limits outline; cached per city, fetched once)';
const PLUSCITIES_AREA_MAP_TILE_SIZE     = 256;
// Fixed neutral palette — this image is shared across every client/niche
// covering a city, so it deliberately does NOT pick up any one client's
// brand color (unlike the Hero photo or CTA button elsewhere on the page).
const PLUSCITIES_AREA_MAP_THEME = ['bg' => '#f4f7fa', 'ink' => '#12314f', 'accent' => '#1f78d1', 'muted' => '#6b7f95'];

// ── Tile + boundary fetch (ported from plugins/image-area-map/tiles.php) ───

function pluscities_area_map_convert_bin(): ?string
{
    static $bin = false;
    if ($bin !== false) return $bin;
    foreach (['/usr/bin/convert', '/usr/local/bin/convert', '/bin/convert'] as $c) {
        if (@is_executable($c)) return $bin = $c;
    }
    $found = trim((string) @shell_exec('command -v convert 2>/dev/null'));
    return $bin = ($found !== '' ? $found : null);
}

/** Shared with the old plugin on purpose — same path, same cache. */
function pluscities_area_map_tile_cache_dir(): string
{
    return BASE_DIR . '/cache/osm-tiles';
}

/** Shared with the old plugin on purpose — same path, same cache. */
function pluscities_area_map_boundary_cache_dir(): string
{
    return BASE_DIR . '/cache/osm-boundaries';
}

function pluscities_area_map_latlon_to_tile(float $lat, float $lon, int $zoom): array
{
    $latRad = deg2rad($lat);
    $n = 2 ** $zoom;
    $x = ($lon + 180) / 360 * $n;
    $y = (1 - log(tan($latRad) + 1 / cos($latRad)) / M_PI) / 2 * $n;
    return [$x, $y];
}

function pluscities_area_map_meters_per_pixel(int $zoom, float $lat): float
{
    return 156543.03392 * cos(deg2rad($lat)) / (2 ** $zoom);
}

function pluscities_area_map_pick_zoom(float $lat, float $radiusMiles, int $halfPixels): int
{
    $radiusMeters = max($radiusMiles, 3.0) * 1609.344;
    for ($z = 14; $z >= 5; $z--) {
        if ($radiusMeters / pluscities_area_map_meters_per_pixel($z, $lat) <= $halfPixels) return $z;
    }
    return 5;
}

function pluscities_area_map_fetch_tile(int $z, int $x, int $y): ?string
{
    $n = 2 ** $z;
    $x = (($x % $n) + $n) % $n;
    if ($y < 0 || $y >= $n) return null;

    $cachePath = pluscities_area_map_tile_cache_dir() . "/{$z}/{$x}/{$y}.png";
    if (is_file($cachePath)) {
        $bytes = @file_get_contents($cachePath);
        if ($bytes !== false && $bytes !== '') return $bytes;
    }
    if (!function_exists('curl_init')) return null;

    $ch = curl_init(PLUSCITIES_AREA_MAP_TILE_SERVER . "/{$z}/{$x}/{$y}.png");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_USERAGENT      => PLUSCITIES_AREA_MAP_TILE_UA,
    ]);
    $body = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    usleep(150000); // courtesy pause after a REAL fetch only — cache hits never reach here
    if ($body === false || $http !== 200 || $body === '') return null;

    if (!is_dir(dirname($cachePath))) @mkdir(dirname($cachePath), 0775, true);
    @file_put_contents($cachePath, $body);
    return $body;
}

/** Same slug algorithm as the old plugin's city_map_boundary_cache_path(), on purpose — same cache. */
function pluscities_area_map_boundary_cache_path(string $name, string $ss): string
{
    $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', trim("{$name}-{$ss}")));
    return pluscities_area_map_boundary_cache_dir() . '/' . trim($slug, '-') . '.json';
}

function pluscities_area_map_fetch_boundary(string $name, string $ss): ?array
{
    $name = trim($name);
    if ($name === '') return null;

    $cachePath = pluscities_area_map_boundary_cache_path($name, $ss);
    if (is_file($cachePath)) {
        $cached = json_decode((string) @file_get_contents($cachePath), true);
        if (is_array($cached)) return $cached ?: null;
    }
    if (!function_exists('curl_init')) return null;

    $q = $ss !== '' ? "{$name}, {$ss}, USA" : "{$name}, USA";
    $url = 'https://nominatim.openstreetmap.org/search?' . http_build_query([
        'q' => $q, 'format' => 'json', 'limit' => 1,
        'polygon_geojson' => 1, 'polygon_threshold' => 0.002,
    ]);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_USERAGENT      => PLUSCITIES_AREA_MAP_BOUNDARY_UA,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);
    $body = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $rings = [];
    if ($body !== false && $http === 200) {
        $rows = json_decode($body, true);
        $geo = $rows[0]['geojson'] ?? null;
        if (is_array($geo)) {
            if (($geo['type'] ?? '') === 'Polygon') {
                foreach ($geo['coordinates'] ?? [] as $ring) $rings[] = $ring;
            } elseif (($geo['type'] ?? '') === 'MultiPolygon') {
                foreach ($geo['coordinates'] ?? [] as $poly) foreach ($poly as $ring) $rings[] = $ring;
            }
        }
    }
    if (!is_dir(dirname($cachePath))) @mkdir(dirname($cachePath), 0775, true);
    @file_put_contents($cachePath, json_encode($rings));
    return $rings ?: null;
}

function pluscities_area_map_boundary_radius(array $rings): float
{
    $lats = []; $lngs = [];
    foreach ($rings as $ring) {
        foreach ($ring as $pt) {
            if (!isset($pt[0], $pt[1])) continue;
            $lngs[] = (float) $pt[0];
            $lats[] = (float) $pt[1];
        }
    }
    if (count($lats) < 3) return 0.0;
    $latMid = (min($lats) + max($lats)) / 2;
    $latSpanMi = (max($lats) - min($lats)) * 69.0;
    $lngSpanMi = (max($lngs) - min($lngs)) * 69.0 * cos(deg2rad($latMid));
    return max(2.0, max($latSpanMi, $lngSpanMi) / 2 * 1.7);
}

function pluscities_area_map_blank_tile(string $bin, string $dir): ?string
{
    $path = $dir . '/blank.png';
    @exec(escapeshellarg($bin) . ' -size ' . PLUSCITIES_AREA_MAP_TILE_SIZE . 'x' . PLUSCITIES_AREA_MAP_TILE_SIZE
        . " xc:'#e6ebf0' " . escapeshellarg($path) . ' 2>/dev/null', $o, $c);
    return ($c === 0 && is_file($path)) ? $path : null;
}

function pluscities_area_map_projection(float $lat, float $lng, float $radiusMiles, int $outW, int $outH): array
{
    $zoom = pluscities_area_map_pick_zoom($lat, $radiusMiles, (int) round(min($outW, $outH) / 2 * 0.85));
    [$cxTile, $cyTile] = pluscities_area_map_latlon_to_tile($lat, $lng, $zoom);
    $left = $cxTile * PLUSCITIES_AREA_MAP_TILE_SIZE - $outW / 2;
    $top  = $cyTile * PLUSCITIES_AREA_MAP_TILE_SIZE - $outH / 2;
    return ['zoom' => $zoom, 'left' => $left, 'top' => $top];
}

function pluscities_area_map_project_point(float $lat, float $lng, array $proj): array
{
    [$tx, $ty] = pluscities_area_map_latlon_to_tile($lat, $lng, $proj['zoom']);
    return [$tx * PLUSCITIES_AREA_MAP_TILE_SIZE - $proj['left'], $ty * PLUSCITIES_AREA_MAP_TILE_SIZE - $proj['top']];
}

function pluscities_area_map_fetch_basemap(float $lat, float $lng, float $radiusMiles, int $outW, int $outH): ?string
{
    $bin = pluscities_area_map_convert_bin();
    if (!$bin) return null;

    $proj = pluscities_area_map_projection($lat, $lng, $radiusMiles, $outW, $outH);
    $zoom = $proj['zoom']; $left = $proj['left']; $top = $proj['top'];

    $firstTileX = (int) floor($left / PLUSCITIES_AREA_MAP_TILE_SIZE);
    $firstTileY = (int) floor($top / PLUSCITIES_AREA_MAP_TILE_SIZE);
    $lastTileX  = (int) floor(($left + $outW - 1) / PLUSCITIES_AREA_MAP_TILE_SIZE);
    $lastTileY  = (int) floor(($top + $outH - 1) / PLUSCITIES_AREA_MAP_TILE_SIZE);

    $tmp = sys_get_temp_dir() . '/pluscities-citymap-' . bin2hex(random_bytes(6));
    if (!@mkdir($tmp, 0775, true)) return null;

    $cleanup = function () use ($tmp) {
        foreach (glob($tmp . '/*') ?: [] as $f) @unlink($f);
        @rmdir($tmp);
    };

    try {
        $rowFiles = [];
        $anyRealTile = false;
        for ($ty = $firstTileY; $ty <= $lastTileY; $ty++) {
            $tileFiles = [];
            for ($tx = $firstTileX; $tx <= $lastTileX; $tx++) {
                $bytes = pluscities_area_map_fetch_tile($zoom, $tx, $ty);
                if ($bytes !== null) {
                    $anyRealTile = true;
                    $p = $tmp . "/t_{$tx}_{$ty}.png";
                    file_put_contents($p, $bytes);
                    $tileFiles[] = $p;
                } else {
                    $blank = pluscities_area_map_blank_tile($bin, $tmp);
                    if ($blank === null) return null;
                    $tileFiles[] = $blank;
                }
            }
            $rowFile = $tmp . "/row_{$ty}.png";
            $cmd = escapeshellarg($bin) . ' ' . implode(' ', array_map('escapeshellarg', $tileFiles))
                 . ' +append ' . escapeshellarg($rowFile);
            @exec($cmd . ' 2>/dev/null', $o, $c);
            if ($c !== 0 || !is_file($rowFile)) return null;
            $rowFiles[] = $rowFile;
        }
        if (!$anyRealTile) return null;

        $mosaic = $tmp . '/mosaic.png';
        $cmd = escapeshellarg($bin) . ' ' . implode(' ', array_map('escapeshellarg', $rowFiles))
             . ' -append ' . escapeshellarg($mosaic);
        @exec($cmd . ' 2>/dev/null', $o2, $c2);
        if ($c2 !== 0 || !is_file($mosaic)) return null;

        $cropLeft = (int) round($left - $firstTileX * PLUSCITIES_AREA_MAP_TILE_SIZE);
        $cropTop  = (int) round($top - $firstTileY * PLUSCITIES_AREA_MAP_TILE_SIZE);
        // -strip is load-bearing, not cosmetic — see plugins/image-area-map/
        // tiles.php's own comment: without it, ImageMagick writes a live
        // date chunk into the PNG, so identical pixels produce different
        // bytes on every call, and the SVG-compare cache below would never
        // hit.
        $cropped = $tmp . '/cropped.png';
        $cmd = escapeshellarg($bin) . ' ' . escapeshellarg($mosaic)
             . " -crop {$outW}x{$outH}+{$cropLeft}+{$cropTop} +repage -strip " . escapeshellarg($cropped);
        @exec($cmd . ' 2>/dev/null', $o3, $c3);
        if ($c3 !== 0 || !is_file($cropped)) return null;

        $png = file_get_contents($cropped);
        return $png === false ? null : $png;
    } finally {
        $cleanup();
    }
}

// ── Nearby towns — a straightforward haversine query against `cities`, not a
// researched/guessed list (plan: "reuse the existing cities table's lat/lng
// with a straightforward distance query against neighboring city_id rows"). ─

function pluscities_area_map_haversine_miles(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $r = 3958.8; // Earth radius, miles
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

/**
 * Nearest real, incorporated places to $city (from the SAME seeded `cities`
 * table the Cities/Niche tab uses, ~43,900 rows) — bounding-box pre-filtered
 * in SQL, exact haversine distance computed in PHP (SQLite's PDO driver here
 * has no guaranteed trig functions). Never invented, never researched by an
 * LLM — the exact failure mode this plugin's predecessor hit twice (see
 * plugins/image-area-map/draw.php's file header).
 */
function pluscities_area_map_nearby_towns(array $city, int $limit = 12, float $maxMiles = 60.0): array
{
    $lat = (float) $city['lat'];
    $lng = (float) $city['lng'];
    $latDelta = $maxMiles / 69.0;
    $lngDelta = $maxMiles / (69.0 * max(0.2, cos(deg2rad($lat))));

    // lat/lng are TEXT columns (SQLite TEXT affinity) — BETWEEN against bare
    // float literals does a STRING comparison there ("9.0" sorts after
    // "40.5"), which silently returned zero rows for every real query.
    // CAST forces numeric comparison.
    $db = infra_cities_init();
    $st = $db->prepare('SELECT id, city, ss, lat, lng, population FROM cities
        WHERE id != ? AND lat != "" AND lng != ""
        AND CAST(lat AS REAL) BETWEEN ? AND ? AND CAST(lng AS REAL) BETWEEN ? AND ?');
    $st->execute([$city['id'], $lat - $latDelta, $lat + $latDelta, $lng - $lngDelta, $lng + $lngDelta]);

    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (!is_numeric($r['lat']) || !is_numeric($r['lng'])) continue;
        $rlat = (float) $r['lat']; $rlng = (float) $r['lng'];
        if ($rlat === 0.0 && $rlng === 0.0) continue;
        $miles = pluscities_area_map_haversine_miles($lat, $lng, $rlat, $rlng);
        // A floor drops near-duplicate records of the same place (a city and
        // its own CDP sharing almost the same point); the ceiling matches
        // the bounding-box pre-filter.
        if ($miles <= 0.3 || $miles > $maxMiles) continue;
        $out[] = ['name' => (string) $r['city'], 'miles' => $miles];
    }
    usort($out, fn($a, $b) => $a['miles'] <=> $b['miles']);
    return array_slice($out, 0, $limit);
}

/** Trim a float for display: 10.0 -> "10", 10.5 -> "10.5". */
function pluscities_area_map_num(float $n): string
{
    return rtrim(rtrim(number_format($n, 1, '.', ''), '0'), '.');
}

function pluscities_area_map_join(array $items): string
{
    if (count($items) > 1) return implode(', ', array_slice($items, 0, -1)) . ' and ' . end($items);
    return $items[0] ?? '';
}

// ── SVG drawing (ported from plugins/image-area-map/draw.php) ──────────────

/**
 * @param array $city  an admin/infra/lib/cities.php `cities` row: id, city, ss, lat, lng
 * @param array $towns pluscities_area_map_nearby_towns()'s output
 */
function pluscities_area_map_svg(array $city, float $lat, float $lng, array $towns): string
{
    $name = trim((string) ($city['city'] ?? ''));
    $ss   = trim((string) ($city['ss'] ?? ''));
    if ($name === '') return '';

    $allTowns = $towns;
    $towns    = array_slice($allTowns, 0, 12);
    $dropped  = count($allTowns) - count($towns);

    $theme  = PLUSCITIES_AREA_MAP_THEME;
    $bg     = $theme['bg'];
    $ink    = $theme['ink'];
    $accent = $theme['accent'];
    $muted  = $theme['muted'];

    $maxMi = 0.0;
    foreach ($towns as $t) if ($t['miles'] !== null) $maxMi = max($maxMi, $t['miles']);

    // The real city-limits boundary, from the SAME OSM family as the
    // coordinates, not a guess. Fetched BEFORE the zoom is chosen: when a
    // boundary exists, it drives the zoom (city-first, clear outline)
    // rather than the farthest listed town.
    $boundaryRings = pluscities_area_map_fetch_boundary($name, $ss);
    $boundaryRadius = $boundaryRings ? pluscities_area_map_boundary_radius($boundaryRings) : 0.0;
    $radiusMiles = $boundaryRadius > 0 ? $boundaryRadius : ($maxMi > 0 ? $maxMi : 15.0);

    $W = 900;
    $hasList = (bool) $towns;
    $rowH    = 31;
    $mapTop  = 60;
    $mapH    = 520;
    $cx = $W / 2;
    $cy = $mapTop + $mapH / 2;
    $diagramBottom = $mapTop + $mapH;
    $listX   = 60;
    $listW   = $W - $listX * 2;
    $listTop = $diagramBottom + 40;
    $listBottom = $hasList ? $listTop + 22 + count($towns) * $rowH + ($dropped > 0 ? 26 : 0) : $diagramBottom;
    $H = (int) round($listBottom + 46);

    $mapPng = pluscities_area_map_fetch_basemap($lat, $lng, $radiusMiles, $W, $mapH);
    if ($mapPng === null) return '';
    $mapB64 = base64_encode($mapPng);

    $esc = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    $o = [];
    $o[] = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"'
         . ' viewBox="0 0 ' . $W . ' ' . $H . '" width="' . $W . '" height="' . $H . '" role="img">';

    $o[] = '<defs>'
         . '<linearGradient id="bgg" x1="0" y1="0" x2="0" y2="1">'
         . '<stop offset="0" stop-color="#ffffff"/><stop offset="1" stop-color="' . $esc($bg) . '"/></linearGradient>'
         . '<linearGradient id="dotg" x1="0" y1="0" x2="0" y2="1">'
         . '<stop offset="0" stop-color="' . $esc($accent) . '"/>'
         . '<stop offset="1" stop-color="' . $esc($ink) . '"/></linearGradient>'
         . '<clipPath id="mapclip"><rect x="0" y="' . $mapTop . '" width="' . $W . '" height="' . $mapH . '" rx="12"/></clipPath>'
         . '</defs>';
    $o[] = '<rect width="' . $W . '" height="' . $H . '" fill="url(#bgg)"/>';

    $o[] = '<image x="0" y="' . $mapTop . '" width="' . $W . '" height="' . $mapH
         . '" clip-path="url(#mapclip)" preserveAspectRatio="xMidYMid slice"'
         . ' href="data:image/png;base64,' . $mapB64 . '" xlink:href="data:image/png;base64,' . $mapB64 . '"/>';

    $boundaryPxBBox = null;
    if ($boundaryRings) {
        $proj = pluscities_area_map_projection($lat, $lng, $radiusMiles, $W, $mapH);
        foreach ($boundaryRings as $ring) {
            if (count($ring) < 3) continue;
            $pts = [];
            foreach ($ring as $pt) {
                if (!isset($pt[0], $pt[1])) continue;
                [$px, $py] = pluscities_area_map_project_point((float) $pt[1], (float) $pt[0], $proj);
                $py += $mapTop;
                $pts[] = round($px, 1) . ',' . round($py, 1);
                if ($boundaryPxBBox === null) {
                    $boundaryPxBBox = ['minX' => $px, 'maxX' => $px, 'minY' => $py, 'maxY' => $py];
                } else {
                    $boundaryPxBBox['minX'] = min($boundaryPxBBox['minX'], $px);
                    $boundaryPxBBox['maxX'] = max($boundaryPxBBox['maxX'], $px);
                    $boundaryPxBBox['minY'] = min($boundaryPxBBox['minY'], $py);
                    $boundaryPxBBox['maxY'] = max($boundaryPxBBox['maxY'], $py);
                }
            }
            if (count($pts) < 3) continue;
            $pointsAttr = $esc(implode(' ', $pts));
            $o[] = '<polygon points="' . $pointsAttr . '" fill="none" stroke="#ffffff" stroke-opacity=".9"'
                 . ' stroke-width="4" stroke-linejoin="round" clip-path="url(#mapclip)"/>';
            $o[] = '<polygon points="' . $pointsAttr . '" fill="none" stroke="' . $esc($ink) . '" stroke-width="2"'
                 . ' stroke-linejoin="round" clip-path="url(#mapclip)"/>';
        }
    }

    // OSM's tile usage policy requires visible attribution.
    $o[] = '<rect x="' . ($W - 222) . '" y="' . ($mapTop + $mapH - 26) . '" width="206" height="20" rx="4" fill="#ffffff" fill-opacity=".78"/>';
    $o[] = '<text x="' . ($W - 16) . '" y="' . ($mapTop + $mapH - 12) . '" text-anchor="end"'
         . ' font-family="system-ui,-apple-system,Segoe UI,sans-serif" font-size="11" fill="#333333">'
         . '&#169; OpenStreetMap contributors</text>';

    $label = $ss !== '' ? "$name, $ss" : $name;
    $labelW = 20 + strlen($label) * 10.5;
    $gap = 14; $chipH = 34; $margin = 20;
    $labelCx = $cx; $labelTop = $cy + 18;
    if ($boundaryPxBBox !== null) {
        $midY = max($mapTop + $margin + $chipH / 2, min($mapTop + $mapH - $margin - $chipH / 2, $cy));
        if ($boundaryPxBBox['maxX'] + $gap + $labelW <= $W - $margin) {
            $labelCx = $boundaryPxBBox['maxX'] + $gap + $labelW / 2;
            $labelTop = $midY - $chipH / 2;
        } elseif ($boundaryPxBBox['minX'] - $gap - $labelW >= $margin) {
            $labelCx = $boundaryPxBBox['minX'] - $gap - $labelW / 2;
            $labelTop = $midY - $chipH / 2;
        } elseif ($boundaryPxBBox['maxY'] + $gap + $chipH <= $mapTop + $mapH - $margin) {
            $labelCx = max($margin + $labelW / 2, min($W - $margin - $labelW / 2, $cx));
            $labelTop = $boundaryPxBBox['maxY'] + $gap;
        }
    }
    $o[] = '<rect x="' . round($labelCx - $labelW / 2, 1) . '" y="' . round($labelTop, 1) . '" width="' . round($labelW, 1)
         . '" height="' . $chipH . '" rx="8" fill="#ffffff" fill-opacity=".92"/>';
    $o[] = '<text x="' . round($labelCx, 1) . '" y="' . round($labelTop + 23, 1) . '" text-anchor="middle" font-family="system-ui,-apple-system,Segoe UI,sans-serif"'
         . ' font-size="19" font-weight="700" fill="' . $esc($ink) . '">' . $esc($label) . '</text>';

    if ($towns) {
        $o[] = '<rect x="18" y="14" width="' . (34 + strlen('AROUND ' . strtoupper($name)) * 8.4) . '" height="30" rx="6" fill="#ffffff" fill-opacity=".85"/>';
        $o[] = '<circle cx="33" cy="29" r="5.5" fill="url(#dotg)"/>';
        $o[] = '<text x="47" y="34" font-family="system-ui,-apple-system,Segoe UI,sans-serif" font-size="15"'
             . ' font-weight="700" letter-spacing="0.6" fill="' . $esc($ink) . '" fill-opacity=".8">AROUND '
             . $esc(strtoupper($name)) . '</text>';
    }

    if ($hasList) {
        $ly = $listTop;
        $o[] = '<text x="' . $listX . '" y="' . $ly . '" font-family="system-ui,-apple-system,Segoe UI,sans-serif"'
             . ' font-size="15" font-weight="700" letter-spacing="0.6" fill="' . $esc($ink)
             . '" fill-opacity=".65">NEARBY TOWNS SERVED</text>';
        $ly += 22;

        foreach ($towns as $i => $t) {
            $ly += $rowH;
            if ($i > 0) {
                $o[] = '<line x1="' . $listX . '" y1="' . round($ly - $rowH + 9, 1) . '" x2="' . ($listX + $listW)
                     . '" y2="' . round($ly - $rowH + 9, 1) . '" stroke="' . $esc($muted) . '" stroke-opacity=".16" stroke-width="1"/>';
            }
            $o[] = '<circle cx="' . ($listX + 12) . '" cy="' . round($ly - 6, 1) . '" r="12" fill="url(#dotg)"/>';
            $o[] = '<text x="' . ($listX + 12) . '" y="' . round($ly - 1.5, 1) . '" text-anchor="middle"'
                 . ' font-family="system-ui,-apple-system,Segoe UI,sans-serif" font-size="12" font-weight="700" fill="#ffffff">'
                 . ($i + 1) . '</text>';
            $o[] = '<text x="' . ($listX + 32) . '" y="' . round($ly, 1) . '"'
                 . ' font-family="system-ui,-apple-system,Segoe UI,sans-serif" font-size="17" font-weight="600" fill="'
                 . $esc($ink) . '">' . $esc($t['name']) . '</text>';
            if ($t['miles'] !== null) {
                $o[] = '<text x="' . ($listX + $listW) . '" y="' . round($ly, 1) . '" text-anchor="end"'
                     . ' font-family="system-ui,-apple-system,Segoe UI,sans-serif" font-size="15" font-weight="600" fill="'
                     . $esc($muted) . '">' . $esc(pluscities_area_map_num($t['miles'])) . ' mi</text>';
            }
        }
        if ($dropped > 0) {
            $o[] = '<text x="' . ($listX + 32) . '" y="' . round($ly + 26, 1) . '"'
                 . ' font-family="system-ui,-apple-system,Segoe UI,sans-serif" font-size="15" font-style="italic" fill="'
                 . $esc($muted) . '">and ' . $dropped . ' more</text>';
        }
    }

    $footer = $boundaryRings
        ? 'Outline marks ' . $esc($name) . '&#8217;s official city limits'
        : 'Map centred on ' . $esc($name) . ' &#8212; approximate coverage area, not an exact boundary';
    $o[] = '<text x="' . $cx . '" y="' . ($H - 22) . '" text-anchor="middle" font-family="system-ui,-apple-system,Segoe UI,sans-serif"'
         . ' font-size="16" fill="' . $esc($muted) . '">' . $footer . '</text>';
    $o[] = '<rect x="2" y="2" width="' . ($W - 4) . '" height="' . ($H - 4) . '" rx="16" fill="none" stroke="'
         . $esc($accent) . '" stroke-opacity=".55" stroke-width="4"/>';
    $o[] = '</svg>';

    return implode("\n", $o);
}

// ── Rasterise (ported from plugins/image-area-map/render.php) ──────────────

function pluscities_area_map_png_to_webp(string $png, string $webpPath): bool
{
    $bin = pluscities_area_map_convert_bin();
    if ($bin) {
        @exec(escapeshellarg($bin) . ' ' . escapeshellarg($png) . ' -background white -flatten'
            . ' -quality 88 -define webp:method=6 ' . escapeshellarg($webpPath) . ' 2>/dev/null', $o, $c);
        if ($c === 0 && is_file($webpPath) && filesize($webpPath) > 0) return true;
    }
    if (function_exists('imagewebp')) {
        $im = @imagecreatefrompng($png);
        if ($im) {
            $flat = imagecreatetruecolor(imagesx($im), imagesy($im));
            imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
            imagecopy($flat, $im, 0, 0, 0, 0, imagesx($im), imagesy($im));
            $ok = @imagewebp($flat, $webpPath, 88);
            imagedestroy($im);
            imagedestroy($flat);
            if ($ok && is_file($webpPath) && filesize($webpPath) > 0) return true;
        }
    }
    return false;
}

function pluscities_area_map_rasterise(string $svgPath, string $webpPath): bool
{
    // rsvg-convert FIRST — ImageMagick's built-in MSVG delegate silently
    // drops gradient fills (see plugins/image-area-map/render.php).
    foreach (['rsvg-convert', 'inkscape'] as $alt) {
        $which = trim((string) @shell_exec('command -v ' . escapeshellarg($alt) . ' 2>/dev/null'));
        if ($which === '') continue;
        $png = $webpPath . '.png';
        $cmd = $alt === 'rsvg-convert'
            ? escapeshellarg($which) . ' -o ' . escapeshellarg($png) . ' ' . escapeshellarg($svgPath)
            : escapeshellarg($which) . ' --export-type=png --export-filename=' . escapeshellarg($png) . ' ' . escapeshellarg($svgPath);
        @exec($cmd . ' 2>/dev/null', $o2, $c2);
        if ($c2 === 0 && is_file($png) && pluscities_area_map_png_to_webp($png, $webpPath)) {
            @unlink($png);
            return true;
        }
        @unlink($png);
    }
    $bin = pluscities_area_map_convert_bin();
    if ($bin) {
        $cmd = escapeshellarg($bin) . ' -background white -flatten ' . escapeshellarg($svgPath)
             . ' -quality 88 -define webp:method=6 ' . escapeshellarg($webpPath) . ' 2>/dev/null';
        @exec($cmd, $out, $code);
        if ($code === 0 && is_file($webpPath) && filesize($webpPath) > 0) return true;
    }
    return false;
}

// ── Orchestration: generate (expensive, explicit) vs. render (cheap, cached) ─

function pluscities_area_map_paths(string $city_id): array
{
    $base = PLUSCITIES_UPLOADS_DIR . 'city_maps/' . $city_id;
    return [
        'svg'  => $base . '.svg',
        'webp' => $base . '.webp',
        'json' => $base . '.json',
        'url'  => PLUSCITIES_UPLOADS_URL . 'city_maps/' . $city_id,
    ];
}

/**
 * Generates (or refreshes) one city's Area Map and caches it. Called from
 * pluscities_create_page() — NEVER from the public sync endpoint. Several
 * real seconds cold (throttled tile fetches + one Nominatim call + two
 * shell-outs); idempotent and cheap on a re-run with nothing changed (same
 * SVG-compare-before-rasterise discipline as pluscities_generate_branded_
 * image()'s own cache check).
 *
 * @return array{ok:bool,drawn:bool}  drawn=false means the cache already had
 *   this exact picture; ok=false means not enough data (no coordinates, no
 *   nearby towns, or no converter on this box) — never a fatal error, same
 *   "no data means no image" rule as every other data-gated piece here.
 */
function pluscities_ensure_city_map(string $city_id, bool $force = false): array
{
    $city = infra_city_get($city_id);
    if (!$city) return ['ok' => false, 'drawn' => false];

    $lat = $city['lat'] ?? null; $lng = $city['lng'] ?? null;
    if (!is_numeric($lat) || !is_numeric($lng)) return ['ok' => false, 'drawn' => false];
    $lat = (float) $lat; $lng = (float) $lng;
    if (($lat === 0.0 && $lng === 0.0) || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
        return ['ok' => false, 'drawn' => false];
    }

    // Nothing to show around the city means the diagram would be a lone dot
    // — worse than no image, same rule as the old plugin.
    $towns = pluscities_area_map_nearby_towns($city);
    if (!$towns) return ['ok' => false, 'drawn' => false];

    $p = pluscities_area_map_paths($city_id);
    $svg = pluscities_area_map_svg($city, $lat, $lng, $towns);
    if ($svg === '') return ['ok' => false, 'drawn' => false];

    // Cache on the DRAWING, not merely on the file existing — a city whose
    // nearby-towns list changes (a new city gets added to `cities` nearby)
    // gets redrawn; one that hasn't is skipped, same as the old plugin.
    if (!$force && is_file($p['webp']) && is_file($p['svg']) && @file_get_contents($p['svg']) === $svg) {
        return ['ok' => true, 'drawn' => false];
    }

    if (!is_dir(dirname($p['svg'])) && !@mkdir(dirname($p['svg']), 0775, true) && !is_dir(dirname($p['svg']))) {
        return ['ok' => false, 'drawn' => false];
    }
    if (@file_put_contents($p['svg'], $svg) === false) return ['ok' => false, 'drawn' => false];

    if (!pluscities_area_map_rasterise($p['svg'], $p['webp'])) {
        @unlink($p['svg']);
        return ['ok' => false, 'drawn' => false]; // no converter on this box — degrade to no image, never ship raw SVG
    }

    $meta = [
        'alt'        => pluscities_area_map_alt($city, $towns),
        'towns'      => $towns,
        'gmaps_link' => pluscities_area_map_link_url($city, $lat, $lng),
        'updated_at' => gmdate('c'),
    ];
    @file_put_contents($p['json'], json_encode($meta));

    return ['ok' => true, 'drawn' => true];
}

function pluscities_area_map_alt(array $city, array $towns): string
{
    $name = trim((string) ($city['city'] ?? ''));
    $ss   = trim((string) ($city['ss'] ?? ''));
    $where = $ss !== '' ? "$name, $ss" : $name;
    $top = array_slice($towns, 0, 8);
    if (!$top) return "Map of the {$where} area.";
    $desc = [];
    foreach ($top as $t) {
        $desc[] = $t['name'] . ($t['miles'] !== null ? ' ' . pluscities_area_map_num($t['miles']) . ' miles away' : '');
    }
    return "Map of the {$where} area, covering " . pluscities_area_map_join($desc) . '.';
}

/** Same reasoning as plugins/image-area-map/draw.php's city_map_link_url():
 *  a real "View on Maps" LINK, never an embed, with a labelled pin. */
function pluscities_area_map_link_url(array $city, float $lat, float $lng): string
{
    $name  = trim((string) ($city['city'] ?? ''));
    $ss    = trim((string) ($city['ss'] ?? ''));
    $label = $ss !== '' ? "$name, $ss" : $name;
    $q = $lat . ',' . $lng . ($label !== '' ? "($label)" : '');
    return 'https://www.google.com/maps/search/?api=1&query=' . urlencode($q);
}

/**
 * The caption's phrasing varies per CLIENT DOMAIN (deterministic crc32 pick,
 * same technique as pluscities_render_safety_toolkit()'s 3-of-5 selection)
 * even though the underlying image is shared platform-wide — two different
 * clients covering the same city get the same picture but not the same
 * sentence under it.
 */
function pluscities_area_map_caption(string $cityLabel, int $townCount, string $domain = ''): string
{
    if ($cityLabel === '' || $townCount <= 0) return '';
    $opts = [
        "Coverage centres on {$cityLabel} and reaches {$townCount} surrounding communities. Response time is what matters most in the hours after a problem is found, so knowing the ground is part of the job.",
        "Based in {$cityLabel}, with {$townCount} nearby communities inside the service area — near enough to reach quickly, not just listed on a map.",
        "{$cityLabel} is the centre of the service area, which extends to {$townCount} surrounding towns. The map shows how far that reaches.",
        "Serving {$cityLabel} and {$townCount} communities around it. Local knowledge of how each area is built shapes how the work is handled there.",
    ];
    $domain = strtolower(trim($domain));
    $idx = $domain !== '' ? (crc32('area-map-caption|' . $domain) % count($opts)) : 0;
    return $opts[$idx];
}

/**
 * Renders the Area Map section — a cache READ only, never triggers
 * generation (see pluscities_ensure_city_map()'s own docblock for why).
 * Returns '' if this city's map hasn't been generated yet or generation
 * failed — graceful no-op, same as every other data-gated block here.
 */
function pluscities_render_area_map(string $city_id, string $domain = ''): string
{
    if ($city_id === '') return '';
    $p = pluscities_area_map_paths($city_id);
    if (!is_file($p['webp']) || !is_file($p['json'])) return '';
    $meta = json_decode((string) @file_get_contents($p['json']), true);
    if (!is_array($meta) || empty($meta['towns'])) return '';

    $cityLabel = function_exists('pluscities_city_label') ? pluscities_city_label($city_id) : $city_id;
    $caption = pluscities_area_map_caption($cityLabel, count($meta['towns']), $domain);

    $imgUrl = PLUSCITIES_PUBLIC_BASE . $p['url'] . '.webp?v=' . filemtime($p['webp']);
    $html = '<section class="lpc-area-map lpc-fullbleed"><div class="lpc-band-inner">'
        . '<p class="lpc-eyebrow">Service Area</p>'
        . '<h3>Where We Work Around ' . htmlspecialchars($cityLabel) . '</h3>'
        . '<div class="lpc-area-map-grid">'
        . '<img class="lpc-area-map-img" src="' . htmlspecialchars($imgUrl) . '" alt="' . htmlspecialchars((string) $meta['alt']) . '" loading="lazy">'
        . '<div class="lpc-area-map-side">';
    if ($caption !== '') $html .= '<p>' . htmlspecialchars($caption) . '</p>';
    if (!empty($meta['gmaps_link'])) {
        $html .= '<a class="lpc-area-map-link" href="' . htmlspecialchars((string) $meta['gmaps_link']) . '" target="_blank" rel="noopener noreferrer">View on Google Maps &rarr;</a>';
    }
    return $html . '</div></div></div></section>';
}
