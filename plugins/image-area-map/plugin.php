<?php
/**
 * City Map plugin — a real map centred on the city's own verified coordinates.
 *
 * WHY THIS EXISTS. Every site generated from a master shares the same photo library, and a
 * 1-2% crop does not stop Google recognising the same photograph. A map drawn from each city's
 * own coordinates and nearby towns is unique BY CONSTRUCTION — different place, different
 * picture — costs nothing per site, and writes its own alt text, so it fixes the
 * duplicate-alt-text problem in the same stroke.
 *
 * The map is centred on ONE point: coordinates from includes/multisite/geocode.php (OpenStreetMap
 * Nominatim) — already verified, already used for this site's LocalBusiness schema. No other
 * town is marked: cities.json's nearby_towns carry no coordinates of their own, and geocoding a
 * whole list of similarly-named small towns correctly is exactly the kind of guess that already
 * went wrong once for this plugin (see plugins/image-area-map/draw.php's file header). They
 * stay in the list below instead, named and with real driving distances.
 *
 * Tokens:
 *   {city_map}      — path to this city's map, for any photo field
 *   {city_map_alt}  — alt text, written from the same data the picture was drawn from
 *
 * When OSM has the city's real administrative boundary on record, it's drawn as a bold outline
 * and the map zooms to fit IT rather than the farthest nearby town — a real, checkable line, not
 * a guess. A city with no recorded boundary just gets the centred map, no marker drawn on it.
 *
 * Drawn once per city and cached; a city that already has one is skipped. Ten sites covering
 * the same city reuse the same drawing. Underlying map tiles and boundary polygons are cached
 * independently per-city forever (plugins/image-area-map/tiles.php), so even a city's first
 * build rarely needs a fresh network fetch for everything it touches.
 */

require_once __DIR__ . '/render.php';

register_plugin(
    'image_area_map',
    'Image · Area Map',
    'Draws a real map centred on the city\'s own verified coordinates, with its real OSM boundary outlined where one is on record and the nearby towns it serves listed below, and exposes it as {city_map}. Unique per city by construction, costs nothing per site, and writes its own alt text.',
    '&#128506;',   // 🗺
    __DIR__
);

/**
 * The city row this site is currently rendering. Prefers the per-page city (a landing page
 * carries its own city_vars) and falls back to the site's own city, so a landing page gets
 * ITS city's diagram rather than the homepage's.
 */
function city_map_current_city(): array
{
    global $data;
    $v = $data['site_vars'] ?? [];
    $city = [
        'city'          => $v['city']       ?? '',
        'SS'            => $v['SS']         ?? '',
        'city_slug'     => $v['city_slug']  ?? '',
        'neighborhoods' => $v['neighborhoods'] ?? [],
    ];
    // cities.json is ALWAYS consulted, because it is the fuller record: site_vars rarely
    // carries neighbourhoods and never carries nearby_towns. This used to be skipped whenever
    // site_vars happened to have neighbourhoods, which silently meant a page could never show
    // the town list — the data was there and simply never loaded.
    if (defined('ACTIVE_SITE_DIR') && ACTIVE_SITE_DIR) {
        $rows = json_decode((string) @file_get_contents(ACTIVE_SITE_DIR . '/data/cities.json'), true);
        foreach ((is_array($rows) ? $rows : []) as $row) {
            if (!is_array($row)) continue;
            $sameSlug = ($row['city_slug'] ?? '') !== '' && $row['city_slug'] === $city['city_slug'];
            $sameName = strcasecmp((string) ($row['city'] ?? ''), (string) $city['city']) === 0;
            // The row wins on everything it actually has; site_vars fills the rest.
            if ($sameSlug || $sameName) { $city = array_merge($city, array_filter($row, fn($x) => $x !== null && $x !== '' && $x !== [])); break; }
        }
    }
    return $city;
}

// ── {city_map} / {city_map_alt} / {city_map_dims} ────────────────────────────
// Rendered lazily on first use: a site that never places the token never draws anything.
add_hook('shortcode_tokens', function (array $map): array {
    static $cache = null;
    if ($cache === null) {
        $cache = ['{city_map}' => '', '{city_map_alt}' => '', '{city_map_caption}' => '', '{city_map_dims}' => '', '{city_map_link}' => ''];
        if (defined('ACTIVE_SITE_DIR') && ACTIVE_SITE_DIR) {
            $city = city_map_current_city();
            $theme = city_map_theme();
            $r = city_map_render(ACTIVE_SITE_DIR, $city, $theme);
            if ($r) $cache = [
                '{city_map}'         => $r['path'],
                '{city_map_alt}'     => $r['alt'],
                // The coverage claim as TEXT. City-focused: no list of nearby towns, which
                // repeated site-wide would be boilerplate and would dilute the one entity
                // this site is about.
                '{city_map_caption}' => city_map_caption($city, city_map_domain()),
                // Ready-to-embed width="W" height="H" attrs (same convention as every other
                // image on the page, img_intrinsic_attrs()) — the hand-written <img> markup
                // for this plugin had no way to reserve layout space before this existed,
                // which Lighthouse flags as a CLS risk even on a page where nothing actually
                // shifted yet.
                '{city_map_dims}'    => img_intrinsic_attrs($r['path']),
                // A real, clickable Google Maps link for the same point the diagram is
                // centred on — see city_map_link_url() for why this is a link, not an embed.
                '{city_map_link}'    => city_map_link_url($city),
            ];
        }
    }
    return array_merge($map, $cache);
});

/** Colours from the site's own theme, so the diagram matches the site it is on rather than
 *  looking like a stock graphic pasted in. */
function city_map_theme(): array
{
    global $data;
    $t = $data['theme'] ?? [];
    $pick = function ($keys, $fallback) use ($t) {
        foreach ($keys as $k) {
            $v = trim((string) ($t[$k] ?? ''));
            if (preg_match('/^#[0-9a-f]{6}$/i', $v)) return $v;
        }
        return $fallback;
    };
    return [
        'bg'     => '#f4f7fa',
        'ink'    => $pick(['color_heading', 'heading_color', 'color_primary'], '#12314f'),
        'accent' => $pick(['color_accent', 'accent_color'], '#1f78d1'),
        'muted'  => '#6b7f95',
    ];
}

/** The domain this build is for, so caption phrasing differs between sites off one master. */
function city_map_domain(): string
{
    $d = (string) (getenv('MULTISITE_CANONICAL') ?: '');
    if ($d === '') {
        global $data;
        $d = (string) ($data['seo']['canonical'] ?? $data['site_vars']['domain'] ?? '');
    }
    if ($d === '' && defined('ACTIVE_SITE_ID')) $d = (string) ACTIVE_SITE_ID;
    return preg_replace('#^https?://#i', '', trim($d));
}
