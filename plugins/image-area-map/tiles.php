<?php
/**
 * OSM raster-tile fetch + stitch — turns a city's lat/lng into a real basemap PNG.
 *
 * Same OSM family as includes/multisite/geocode.php's Nominatim lookup, same usage-policy
 * obligations: identify with a real User-Agent, keep volume low. Every tile is cached to disk
 * forever, keyed only on z/x/y (never on city) — two cities whose view happens to share a tile
 * fetch it once between them, and the SAME city is re-fetched only if its cached basemap is
 * invalidated upstream (render.php's existing per-city cache, unchanged).
 *
 * Stitching uses the ImageMagick `convert` binary (ms_convert_bin()), NOT the GD extension —
 * GD is not guaranteed present on this box (render.php's own WebP step already falls back away
 * from it), while `convert` is the one binary the rest of this codebase already treats as
 * always-available.
 */

const CITY_MAP_TILE_SERVER = 'https://tile.openstreetmap.org';
const CITY_MAP_TILE_UA     = 'SiteFactory-MapTiles/1.0 (+service-area basemap; cached per tile, low volume)';
const CITY_MAP_TILE_SIZE   = 256;

/**
 * ImageMagick's `convert`, resolved without depending on includes/multisite/image_overlay.php's
 * ms_convert_bin() — that file isn't autoloaded everywhere this plugin runs (e.g. plain
 * config.php + draw.php, as the Test Lab demo does). Deliberately duplicated rather than
 * required: "a plugin is the whole unit" is the same rule render.php's own WebP step already
 * follows for this exact binary.
 */
function city_map_convert_bin(): ?string
{
    static $bin = false;
    if ($bin !== false) return $bin;
    foreach (['/usr/bin/convert', '/usr/local/bin/convert', '/bin/convert'] as $c) {
        if (@is_executable($c)) return $bin = $c;
    }
    $found = trim((string) @shell_exec('command -v convert 2>/dev/null'));
    return $bin = ($found !== '' ? $found : null);
}

/** Where fetched tiles live — shared across every site/city, never committed (see /cache/ in .gitignore). */
function city_map_tile_cache_dir(): string
{
    return (defined('BASE_DIR') ? BASE_DIR : dirname(__DIR__, 2)) . '/cache/osm-tiles';
}

/** lat/lng -> fractional tile coordinate at a zoom level (standard Web Mercator slippy-map math). */
function city_map_latlon_to_tile(float $lat, float $lon, int $zoom): array
{
    $latRad = deg2rad($lat);
    $n = 2 ** $zoom;
    $x = ($lon + 180) / 360 * $n;
    $y = (1 - log(tan($latRad) + 1 / cos($latRad)) / M_PI) / 2 * $n;
    return [$x, $y];
}

/** Ground resolution (meters/pixel) at a zoom level and latitude. */
function city_map_meters_per_pixel(int $zoom, float $lat): float
{
    return 156543.03392 * cos(deg2rad($lat)) / (2 ** $zoom);
}

/**
 * The most zoomed-in level whose ground resolution still fits $radiusMiles within $halfPixels
 * of the image centre — i.e. the farthest nearby town's mileage reaches roughly to the edge.
 * Clamped to a sane range so a tiny radius doesn't zoom into a single driveway and a huge one
 * doesn't zoom out past "this is a real place."
 */
function city_map_pick_zoom(float $lat, float $radiusMiles, int $halfPixels): int
{
    $radiusMeters = max($radiusMiles, 3.0) * 1609.344;
    for ($z = 14; $z >= 5; $z--) {
        if ($radiusMeters / city_map_meters_per_pixel($z, $lat) <= $halfPixels) return $z;
    }
    return 5;
}

/** One tile's PNG bytes, from the shared on-disk cache or a fresh fetch. Null on failure. */
function city_map_fetch_tile(int $z, int $x, int $y): ?string
{
    $n = 2 ** $z;
    $x = (($x % $n) + $n) % $n; // a wide mosaic can cross the 180th meridian; tiles themselves don't wrap
    if ($y < 0 || $y >= $n) return null;

    $cachePath = city_map_tile_cache_dir() . "/{$z}/{$x}/{$y}.png";
    if (is_file($cachePath)) {
        $bytes = @file_get_contents($cachePath);
        if ($bytes !== false && $bytes !== '') return $bytes;
    }
    if (!function_exists('curl_init')) return null;

    $ch = curl_init(CITY_MAP_TILE_SERVER . "/{$z}/{$x}/{$y}.png");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_USERAGENT      => CITY_MAP_TILE_UA,
    ]);
    $body = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    // A courtesy pause after a REAL fetch only — cache hits above never reach here. Keeps a
    // cold mosaic (10-20 tiles for a city with no cache yet) well under OSM's tile policy.
    usleep(150000);
    if ($body === false || $http !== 200 || $body === '') return null;

    if (!is_dir(dirname($cachePath))) @mkdir(dirname($cachePath), 0775, true);
    @file_put_contents($cachePath, $body);
    return $body;
}

const CITY_MAP_BOUNDARY_UA = 'SiteFactory-MapBoundary/1.0 (+city-limits outline; cached per city, fetched once)';

/** Where a city's fetched boundary polygon lives — cached forever, keyed on city identity. */
function city_map_boundary_cache_path(string $name, string $ss): string
{
    $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', trim("{$name}-{$ss}")));
    return city_map_tile_cache_dir() . '/../osm-boundaries/' . trim($slug, '-') . '.json';
}

/**
 * The city's real administrative boundary, as a list of rings (each ring a list of [lat,lng]
 * pairs) — a MultiPolygon becomes multiple rings. Null means "no boundary available", which is
 * cached too (as an empty rings list) so a city OSM has no recorded limits for isn't re-queried
 * on every build — the same "declined once, left alone" rule the research pipeline already uses.
 *
 * Self-contained to this plugin rather than folded into includes/multisite/geocode.php: that
 * module's other caller (plugins/recovery/facilities_cli.php) has no use for boundary data, and
 * "a plugin is the whole unit" already governs this file's other OSM calls (see tile fetch above).
 */
function city_map_fetch_boundary(string $name, string $ss): ?array
{
    $name = trim($name);
    if ($name === '') return null;

    $cachePath = city_map_boundary_cache_path($name, $ss);
    if (is_file($cachePath)) {
        $cached = json_decode((string) @file_get_contents($cachePath), true);
        if (is_array($cached)) return $cached ?: null; // [] cached = "checked, none found"
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
        CURLOPT_USERAGENT      => CITY_MAP_BOUNDARY_UA,
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
    // Cache the outcome either way — including "none found" — so a city without recorded
    // limits in OSM isn't re-queried on every future render.
    if (!is_dir(dirname($cachePath))) @mkdir(dirname($cachePath), 0775, true);
    @file_put_contents($cachePath, json_encode($rings));
    return $rings ?: null;
}

/**
 * The radius (miles) that frames a city's real boundary with room to breathe — half the
 * boundary's larger span, padded 70%. Used to zoom the map to the CITY, not to whichever listed
 * town happens to be farthest: at a reach-sized zoom (a 50-mile town is common) a typical city's
 * 5-10 mile limits render as a few illegible pixels next to the pin. A floor keeps a handful of
 * stray micro-vertices (a tiny recorded exclave, bad OSM data) from forcing an absurd zoom-in.
 */
function city_map_boundary_radius(array $rings): float
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

/** A flat placeholder tile for a fetch that failed, so the mosaic stays a rectangle. */
function city_map_blank_tile(string $bin, string $dir): ?string
{
    $path = $dir . '/blank.png';
    @exec(escapeshellarg($bin) . ' -size ' . CITY_MAP_TILE_SIZE . 'x' . CITY_MAP_TILE_SIZE
        . " xc:'#e6ebf0' " . escapeshellarg($path) . ' 2>/dev/null', $o, $c);
    return ($c === 0 && is_file($path)) ? $path : null;
}

/**
 * The projection for a given centre/radius/canvas — zoom level plus the pixel offset of the
 * canvas's top-left corner in that zoom's global tile-pixel space. Shared by the basemap fetch
 * AND the boundary overlay (city_map_project_point()) so a polygon point and the map beneath it
 * are never computed from two slightly different zoom choices.
 */
function city_map_projection(float $lat, float $lng, float $radiusMiles, int $outW, int $outH): array
{
    $zoom = city_map_pick_zoom($lat, $radiusMiles, (int) round(min($outW, $outH) / 2 * 0.85));
    [$cxTile, $cyTile] = city_map_latlon_to_tile($lat, $lng, $zoom);
    $left = $cxTile * CITY_MAP_TILE_SIZE - $outW / 2;
    $top  = $cyTile * CITY_MAP_TILE_SIZE - $outH / 2;
    return ['zoom' => $zoom, 'left' => $left, 'top' => $top];
}

/** A lat/lng to its x,y pixel position WITHIN the canvas described by $proj (city_map_projection()). */
function city_map_project_point(float $lat, float $lng, array $proj): array
{
    [$tx, $ty] = city_map_latlon_to_tile($lat, $lng, $proj['zoom']);
    return [$tx * CITY_MAP_TILE_SIZE - $proj['left'], $ty * CITY_MAP_TILE_SIZE - $proj['top']];
}

/**
 * Fetch and stitch the tiles covering a $outW x $outH box centred on ($lat,$lng), cropped so
 * the city sits dead centre. Returns raw PNG bytes, or null if no converter / no tiles at all.
 */
function city_map_fetch_basemap(float $lat, float $lng, float $radiusMiles, int $outW, int $outH): ?string
{
    $bin = city_map_convert_bin();
    if (!$bin) return null;

    $proj = city_map_projection($lat, $lng, $radiusMiles, $outW, $outH);
    $zoom = $proj['zoom']; $left = $proj['left']; $top = $proj['top'];

    $firstTileX = (int) floor($left / CITY_MAP_TILE_SIZE);
    $firstTileY = (int) floor($top / CITY_MAP_TILE_SIZE);
    $lastTileX  = (int) floor(($left + $outW - 1) / CITY_MAP_TILE_SIZE);
    $lastTileY  = (int) floor(($top + $outH - 1) / CITY_MAP_TILE_SIZE);

    $tmp = sys_get_temp_dir() . '/citymap-' . bin2hex(random_bytes(6));
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
                $bytes = city_map_fetch_tile($zoom, $tx, $ty);
                if ($bytes !== null) {
                    $anyRealTile = true;
                    $p = $tmp . "/t_{$tx}_{$ty}.png";
                    file_put_contents($p, $bytes);
                    $tileFiles[] = $p;
                } else {
                    $blank = city_map_blank_tile($bin, $tmp);
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
        if (!$anyRealTile) return null; // every tile failed — nothing real to show

        $mosaic = $tmp . '/mosaic.png';
        $cmd = escapeshellarg($bin) . ' ' . implode(' ', array_map('escapeshellarg', $rowFiles))
             . ' -append ' . escapeshellarg($mosaic);
        @exec($cmd . ' 2>/dev/null', $o2, $c2);
        if ($c2 !== 0 || !is_file($mosaic)) return null;

        $cropLeft = (int) round($left - $firstTileX * CITY_MAP_TILE_SIZE);
        $cropTop  = (int) round($top - $firstTileY * CITY_MAP_TILE_SIZE);
        // -strip is load-bearing, not cosmetic: without it ImageMagick writes a live
        // date:create/date:modify chunk into the PNG, so identical pixels produce different
        // bytes on every call. render.php's cache compares the SVG this ends up embedded in
        // byte-for-byte — a non-deterministic PNG would make every rebuild think the city
        // changed and re-rasterise the whole image, defeating "drawn once per city."
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
