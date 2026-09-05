<?php
/**
 * City Chart plugin — per-city data charts, defined per niche, entirely inside this plugin.
 *
 * WHY. Every site from a master shares the same photo library, and cropping 1-2% does not stop
 * Google recognising the same photograph. A chart drawn from a city's own figures is unique by
 * construction. Unlike the city-map diagram, whose value is words trapped in pixels, a chart is
 * the right medium for its content: twelve numbers nobody reads as prose, whose SHAPE reads at
 * a glance.
 *
 * MODULAR PER PLUGIN. Everything lives here:
 *   niches/{niche-slug}/{chart}.json   what each niche charts
 *   charts.php  definitions + series extraction      draw.php  SVG
 *   render.php  write + rasterise + cache            plugin.php  tokens
 * The niche comes from the site's own niche_brief.json ("water restoration" -> water-restoration).
 * Adding a niche is a folder; adding a chart is a file. You go into the plugin once and never
 * again — no code changes, and nothing is left behind in the sites if the plugin is removed.
 *
 * Tokens, one pair per chart the niche defines:
 *   {chart_rainfall}   {chart_rainfall_alt}
 *
 * NO DATA MEANS NO CHART. The figures live per city in cities.json, put there by research. A
 * city without them is skipped rather than drawn from estimates — a chart makes a number look
 * authoritative, so an invented one is worse than none, especially on a page advising someone
 * about their home.
 */

require_once __DIR__ . '/render.php';

register_plugin(
    'image_data_chart',
    'Image · Data Chart',
    'Per-city data charts, defined per niche inside the plugin. Each chart becomes a {chart_ID} token. Drawn once per city, cached, and skipped entirely for cities with no data.',
    '&#128202;',   // 📊
    __DIR__
);

/** The city being rendered — the page's own city on a landing page, else the site's. */
function city_chart_current_city(): array
{
    global $data;
    $v = $data['site_vars'] ?? [];
    $city = [
        'city'      => $v['city']      ?? '',
        'SS'        => $v['SS']        ?? '',
        'state'     => $v['state']     ?? '',
        'city_slug' => $v['city_slug'] ?? '',
    ];
    if (defined('ACTIVE_SITE_DIR')) {
        $rows = json_decode((string) @file_get_contents(ACTIVE_SITE_DIR . '/data/cities.json'), true);
        foreach ((is_array($rows) ? $rows : []) as $row) {
            if (!is_array($row)) continue;
            $sameSlug = ($row['city_slug'] ?? '') !== '' && $row['city_slug'] === $city['city_slug'];
            $sameName = strcasecmp((string) ($row['city'] ?? ''), (string) $city['city']) === 0;
            if ($sameSlug || $sameName) return array_merge($city, $row);
        }
    }
    return $city;
}

/** Site theme colours, so a chart matches the site it sits on. */
function city_chart_theme(): array
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

// Registered lazily: a site that never places a chart token never draws anything.
add_hook('shortcode_tokens', function (array $map): array {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        if (defined('ACTIVE_SITE_DIR') && ACTIVE_SITE_DIR) {
            $niche = city_chart_niche(ACTIVE_SITE_DIR);
            $city  = city_chart_current_city();
            $theme = city_chart_theme();
            foreach (city_chart_definitions($niche) as $id => $def) {
                $r = city_chart_render(ACTIVE_SITE_DIR, $city, $def, $theme);
                // Tokens are registered even when empty, so an unplaced or dataless chart
                // resolves to nothing rather than printing "{chart_rainfall}" on the page.
                $cache['{chart_' . $id . '}']      = $r['path'] ?? '';
                $cache['{chart_' . $id . '_alt}']  = $r['alt']  ?? '';
            }
        }
    }
    return array_merge($map, $cache);
});
