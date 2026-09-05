<?php
/**
 * City Map plugin — a service-area diagram drawn from the city's own named areas.
 *
 * WHY THIS EXISTS. Every site generated from a master shares the same photo library, and a
 * 1-2% crop does not stop Google recognising the same photograph. A diagram drawn from each
 * city's own data is unique BY CONSTRUCTION — different areas, different picture — costs
 * nothing per site, and writes its own alt text, so it fixes the duplicate-alt-text problem
 * in the same stroke.
 *
 * Deliberately a coverage DIAGRAM, not a map: cities.json holds area names with no
 * coordinates, and placing them on a real map would be a geographic claim the data cannot
 * support. The image says so on its face.
 *
 * Tokens:
 *   {city_map}      — path to this city's diagram, for any photo field
 *   {city_map_alt}  — alt text, written from the same data the picture was drawn from
 *
 * Drawn once per city and cached; a city that already has one is skipped. Ten sites covering
 * the same city reuse the same drawing.
 */

require_once __DIR__ . '/render.php';

register_plugin(
    'image_area_map',
    'Image · Area Map',
    'Draws a service-area diagram from the city\'s own named neighbourhoods and exposes it as {city_map}. Unique per city by construction, costs nothing per site, and writes its own alt text.',
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
    // cities.json is the fuller record — site_vars rarely carries neighbourhoods.
    if (!$city['neighborhoods'] && defined('ACTIVE_SITE_DIR')) {
        $rows = json_decode((string) @file_get_contents(ACTIVE_SITE_DIR . '/data/cities.json'), true);
        foreach ((is_array($rows) ? $rows : []) as $row) {
            if (!is_array($row)) continue;
            $sameSlug = ($row['city_slug'] ?? '') !== '' && $row['city_slug'] === $city['city_slug'];
            $sameName = strcasecmp((string) ($row['city'] ?? ''), (string) $city['city']) === 0;
            if ($sameSlug || $sameName) { $city = array_merge($city, $row); break; }
        }
    }
    return $city;
}

// ── {city_map} / {city_map_alt} ──────────────────────────────────────────────
// Rendered lazily on first use: a site that never places the token never draws anything.
add_hook('shortcode_tokens', function (array $map): array {
    static $cache = null;
    if ($cache === null) {
        $cache = ['{city_map}' => '', '{city_map_alt}' => ''];
        if (defined('ACTIVE_SITE_DIR') && ACTIVE_SITE_DIR) {
            $city = city_map_current_city();
            $theme = city_map_theme();
            $r = city_map_render(ACTIVE_SITE_DIR, $city, $theme);
            if ($r) $cache = ['{city_map}' => $r['path'], '{city_map_alt}' => $r['alt']];
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
