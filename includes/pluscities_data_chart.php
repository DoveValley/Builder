<?php
/**
 * includes/pluscities_data_chart.php — "Local Housing Snapshot", Phase 3 of
 * the bland-vs-reference-sites plan (the same scoping pass that produced
 * Safety Toolkit and Area Map). Shares Area Map's exact SVG→webp rendering
 * pipeline — pluscities_area_map_rasterise() / pluscities_area_map_convert_
 * bin() (includes/pluscities_area_map.php) are called directly here, not
 * duplicated, since both files live inside this same engine rather than
 * across the OLD system's deliberate per-plugin independence boundary (that
 * boundary is why plugins/image-area-map and plugins/image-data-chart each
 * carry their own copy of the same raster helpers — a rule that applies
 * between separately-removable plugins, not between two files of one
 * engine).
 *
 * Needs NO new network dependency at all: its data is the exact same
 * per-city Census ACS dataset (pluscities_dataset_get($city_id,
 * 'census_acs')) the housing_stock block already renders as a plain bullet
 * list — this draws the same four numbers as a real picture instead.
 *
 * Deliberately ONE bespoke chart, not a generic engine. The old system's
 * image-data-chart plugin is a generic bars/compare/timeline engine driven
 * by per-niche JSON definitions, several of which are backed by a Python
 * NOAA bridge (plugins/image-data-chart/providers/lpc_bridge.py) — a real
 * new network/process dependency this phase's own plan explicitly rules
 * out ("no new network dependency at all"). Building a generic engine here
 * for the one chart PlusCities' current data can actually support would be
 * the premature abstraction CLAUDE.md warns against; if a second chart type
 * gets real data behind it later, generalizing then is cheap.
 *
 * City-level furniture, cached by city_id alone — same as Area Map. Census
 * figures don't vary by client or niche, so every client covering a city
 * shares one drawing.
 */

require_once __DIR__ . '/pluscities_area_map.php'; // pluscities_area_map_rasterise(), pluscities_area_map_convert_bin()

const PLUSCITIES_DATA_CHART_THEME = ['bg' => '#f4f7fa', 'ink' => '#12314f', 'accent' => '#1f78d1', 'muted' => '#6b7f95'];

function pluscities_data_chart_paths(string $city_id): array
{
    $base = PLUSCITIES_UPLOADS_DIR . 'data_charts/' . $city_id;
    return [
        'svg'  => $base . '.svg',
        'webp' => $base . '.webp',
        'json' => $base . '.json',
        'url'  => PLUSCITIES_UPLOADS_URL . 'data_charts/' . $city_id,
    ];
}

/** "$146,158" / "51.5%" / "1983" — same display rule the housing_stock
 *  bullet list already uses (includes/pluscities_writer.php), so the image
 *  and the text under it never show the number formatted two different ways. */
function pluscities_data_chart_format(string $key, float $v): string
{
    if ($key === 'income_median' || $key === 'home_value_median') return '$' . number_format($v);
    if ($key === 'homeownership_pct') return rtrim(rtrim(number_format($v, 1), '0'), '.') . '%';
    return (string) (int) $v;
}

/**
 * @param array $values  pluscities_dataset_get()'s 'values' shape: income_median,
 *   home_value_median, year_built_median, homeownership_pct — any may be
 *   missing/null (ACS suppresses figures for some small places).
 */
function pluscities_data_chart_svg(string $cityLabel, array $values): string
{
    if ($cityLabel === '') return '';
    $ownPct = is_numeric($values['homeownership_pct'] ?? null) ? (float) $values['homeownership_pct'] : null;
    $tiles = [];
    if (is_numeric($values['income_median'] ?? null)) {
        $tiles[] = ['label' => 'Median Household Income', 'value' => pluscities_data_chart_format('income_median', (float) $values['income_median'])];
    }
    if (is_numeric($values['home_value_median'] ?? null)) {
        $tiles[] = ['label' => 'Median Home Value', 'value' => pluscities_data_chart_format('home_value_median', (float) $values['home_value_median'])];
    }
    if (is_numeric($values['year_built_median'] ?? null)) {
        $tiles[] = ['label' => 'Median Year Built', 'value' => pluscities_data_chart_format('year_built_median', (float) $values['year_built_median'])];
    }
    // NO DATA MEANS NO CHART — same rule as every other data-gated piece of
    // this engine. A city with neither the ownership split nor a single
    // stat tile has nothing real to draw.
    if ($ownPct === null && !$tiles) return '';

    $theme  = PLUSCITIES_DATA_CHART_THEME;
    $bg = $theme['bg']; $ink = $theme['ink']; $accent = $theme['accent']; $muted = $theme['muted'];
    $esc = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES | ENT_XML1, 'UTF-8');

    $W = 900;
    $y = 92; // running cursor — leaves room below the title before the first element
    $barH = 0; $barTop = 0;
    if ($ownPct !== null) { $barTop = $y; $barH = 96; $y += $barH + 36; }
    $tilesTop = $y;
    $tileH = $tiles ? 118 : 0;
    if ($tiles) $y += $tileH + 30;
    $H = $y + 34;

    $o = [];
    $o[] = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $W . ' ' . $H . '" width="' . $W . '" height="' . $H . '" role="img">';
    $o[] = '<defs><linearGradient id="hcg" x1="0" y1="0" x2="0" y2="1">'
         . '<stop offset="0" stop-color="#ffffff"/><stop offset="1" stop-color="' . $esc($bg) . '"/></linearGradient></defs>';
    $o[] = '<rect width="' . $W . '" height="' . $H . '" rx="16" fill="url(#hcg)"/>';
    $o[] = '<text x="40" y="38" font-family="system-ui,-apple-system,Segoe UI,sans-serif" font-size="22" font-weight="800" fill="'
         . $esc($ink) . '">Housing in ' . $esc($cityLabel) . '</text>';

    if ($ownPct !== null) {
        $ownPct = max(0.0, min(100.0, $ownPct));
        $rentPct = 100.0 - $ownPct;
        $barX = 40; $barW = $W - 80;
        $splitX = $barX + $barW * ($ownPct / 100.0);
        $o[] = '<text x="' . $barX . '" y="' . ($barTop - 10) . '" font-family="system-ui,-apple-system,Segoe UI,sans-serif" font-size="14"'
             . ' font-weight="700" letter-spacing="0.4" fill="' . $esc($muted) . '">OWNER VS. RENTER OCCUPIED</text>';
        $o[] = '<rect x="' . $barX . '" y="' . $barTop . '" width="' . $barW . '" height="' . $barH . '" rx="12" fill="' . $esc($accent) . '" fill-opacity=".18"/>';
        if ($ownPct > 0) {
            $o[] = '<rect x="' . $barX . '" y="' . $barTop . '" width="' . round($barW * ($ownPct / 100.0), 1) . '" height="' . $barH . '" rx="12" fill="' . $esc($accent) . '"/>';
        }
        $ownLabel = 'Owner-occupied ' . rtrim(rtrim(number_format($ownPct, 1), '0'), '.') . '%';
        $rentLabel = 'Renter-occupied ' . rtrim(rtrim(number_format($rentPct, 1), '0'), '.') . '%';
        $ownInside = $ownPct >= 28; // enough room to set the label inside its own segment
        $o[] = '<text x="' . ($ownInside ? $barX + 18 : min($splitX + 14, $barX + $barW - 10)) . '" y="' . ($barTop + $barH / 2 + 6)
             . '" font-family="system-ui,-apple-system,Segoe UI,sans-serif" font-size="17" font-weight="700" fill="'
             . ($ownInside ? '#ffffff' : $esc($ink)) . '">' . $esc($ownLabel) . '</text>';
        $rentInside = $rentPct >= 28;
        $rentX = $rentInside ? ($splitX + 18) : max($barX + 10, $splitX - 14);
        $rentAnchor = $rentInside ? '' : ' text-anchor="end"';
        $o[] = '<text x="' . $rentX . '" y="' . ($barTop + $barH / 2 + 6) . '"' . $rentAnchor
             . ' font-family="system-ui,-apple-system,Segoe UI,sans-serif" font-size="17" font-weight="700" fill="' . $esc($ink) . '">'
             . $esc($rentLabel) . '</text>';
    }

    if ($tiles) {
        $n = count($tiles);
        $gap = 24;
        $tileW = ($W - 80 - $gap * ($n - 1)) / $n;
        foreach ($tiles as $i => $t) {
            $tx = 40 + $i * ($tileW + $gap);
            $o[] = '<rect x="' . round($tx, 1) . '" y="' . $tilesTop . '" width="' . round($tileW, 1) . '" height="' . $tileH . '" rx="12" fill="#ffffff" stroke="'
                 . $esc($accent) . '" stroke-opacity=".25" stroke-width="1.5"/>';
            $o[] = '<text x="' . round($tx + $tileW / 2, 1) . '" y="' . ($tilesTop + 54) . '" text-anchor="middle" font-family="system-ui,-apple-system,Segoe UI,sans-serif"'
                 . ' font-size="27" font-weight="800" fill="' . $esc($ink) . '">' . $esc($t['value']) . '</text>';
            $o[] = '<text x="' . round($tx + $tileW / 2, 1) . '" y="' . ($tilesTop + 84) . '" text-anchor="middle" font-family="system-ui,-apple-system,Segoe UI,sans-serif"'
                 . ' font-size="13.5" font-weight="600" fill="' . $esc($muted) . '">' . $esc($t['label']) . '</text>';
        }
    }

    $o[] = '<text x="40" y="' . ($H - 16) . '" font-family="system-ui,-apple-system,Segoe UI,sans-serif" font-size="12.5" fill="'
         . $esc($muted) . '">Source: US Census ACS 5-Year Estimates</text>';
    $o[] = '</svg>';
    return implode("\n", $o);
}

/**
 * Generates (or refreshes) one city's Housing Snapshot chart and caches it.
 * Called from pluscities_create_page() right alongside the existing
 * housing_stock Census fetch — never from the public sync endpoint. Cheap
 * either way: no network call of its own (the dataset is already fetched
 * by the caller), just one SVG build + one shell-out to rasterise.
 *
 * @return array{ok:bool,drawn:bool}
 */
function pluscities_ensure_housing_chart(string $city_id, bool $force = false): array
{
    $city = infra_city_get($city_id);
    if (!$city) return ['ok' => false, 'drawn' => false];
    $dataset = pluscities_dataset_get($city_id, 'census_acs');
    if (!$dataset) return ['ok' => false, 'drawn' => false];

    $cityLabel = function_exists('pluscities_city_label') ? pluscities_city_label($city_id) : $city['city'];
    $svg = pluscities_data_chart_svg($cityLabel, $dataset['values'] ?? []);
    if ($svg === '') return ['ok' => false, 'drawn' => false];

    $p = pluscities_data_chart_paths($city_id);
    if (!$force && is_file($p['webp']) && is_file($p['svg']) && @file_get_contents($p['svg']) === $svg) {
        return ['ok' => true, 'drawn' => false];
    }

    if (!is_dir(dirname($p['svg'])) && !@mkdir(dirname($p['svg']), 0775, true) && !is_dir(dirname($p['svg']))) {
        return ['ok' => false, 'drawn' => false];
    }
    if (@file_put_contents($p['svg'], $svg) === false) return ['ok' => false, 'drawn' => false];

    if (!pluscities_area_map_rasterise($p['svg'], $p['webp'])) {
        @unlink($p['svg']);
        return ['ok' => false, 'drawn' => false]; // no converter on this box — no image, never raw SVG
    }

    @file_put_contents($p['json'], json_encode([
        'alt' => 'Housing snapshot for ' . $cityLabel . ': ' . implode(', ', array_filter([
            is_numeric($dataset['values']['income_median'] ?? null) ? 'median household income ' . pluscities_data_chart_format('income_median', (float) $dataset['values']['income_median']) : null,
            is_numeric($dataset['values']['home_value_median'] ?? null) ? 'median home value ' . pluscities_data_chart_format('home_value_median', (float) $dataset['values']['home_value_median']) : null,
            is_numeric($dataset['values']['homeownership_pct'] ?? null) ? pluscities_data_chart_format('homeownership_pct', (float) $dataset['values']['homeownership_pct']) . ' homeownership rate' : null,
        ])) . '.',
        'values'     => $dataset['values'] ?? [],
        'updated_at' => gmdate('c'),
    ]));

    return ['ok' => true, 'drawn' => true];
}

/**
 * The caption's phrasing varies per CLIENT DOMAIN (same crc32 technique as
 * pluscities_render_safety_toolkit()/pluscities_area_map_caption()) even
 * though the underlying image is shared platform-wide.
 */
function pluscities_data_chart_caption(string $cityLabel, array $values, string $domain = ''): string
{
    if ($cityLabel === '') return '';
    $home  = is_numeric($values['home_value_median'] ?? null) ? pluscities_data_chart_format('home_value_median', (float) $values['home_value_median']) : null;
    $own   = is_numeric($values['homeownership_pct'] ?? null) ? pluscities_data_chart_format('homeownership_pct', (float) $values['homeownership_pct']) : null;
    $built = is_numeric($values['year_built_median'] ?? null) ? pluscities_data_chart_format('year_built_median', (float) $values['year_built_median']) : null;

    $opts = [];
    if ($home && $own) $opts[] = "{$cityLabel}'s median home value runs {$home}, with a {$own} homeownership rate — real local figures that shape how common this need is here.";
    if ($built) $opts[] = "Half of {$cityLabel}'s homes were built by {$built}, per the latest Census estimates — a useful marker for how often this comes up locally.";
    if ($home) $opts[] = "Census ACS figures put {$cityLabel}'s median home value at {$home}, the kind of real local detail generic advice skips.";
    if ($own) $opts[] = "{$own} of {$cityLabel} households own rather than rent, per the latest Census ACS estimates.";
    if (!$opts) return '';

    $domain = strtolower(trim($domain));
    $idx = $domain !== '' ? (crc32('data-chart-caption|' . $domain) % count($opts)) : 0;
    return $opts[$idx];
}

/**
 * Renders the Housing Snapshot as its own section (eyebrow + heading + image
 * + caption) — a cache READ only, never triggers generation (same split as
 * pluscities_render_area_map()). Returns '' if this city's chart hasn't been
 * generated yet or generation failed. Inserted from the production loop
 * right after the housing_stock block, same pattern as Area Map after
 * service_area — not threaded into pluscities_render_block()'s own switch,
 * so that function's signature never needed a city_id parameter at all.
 */
function pluscities_render_housing_chart(string $city_id, string $domain = ''): string
{
    if ($city_id === '') return '';
    $p = pluscities_data_chart_paths($city_id);
    if (!is_file($p['webp']) || !is_file($p['json'])) return '';
    $meta = json_decode((string) @file_get_contents($p['json']), true);
    if (!is_array($meta)) return '';

    $cityLabel = function_exists('pluscities_city_label') ? pluscities_city_label($city_id) : $city_id;
    $caption = pluscities_data_chart_caption($cityLabel, $meta['values'] ?? [], $domain);
    $imgUrl = PLUSCITIES_PUBLIC_BASE . $p['url'] . '.webp?v=' . filemtime($p['webp']);

    $html = '<section class="lpc-data-chart lpc-fullbleed"><div class="lpc-band-inner">'
        . '<p class="lpc-eyebrow">Local Data</p>'
        . '<h3>Housing Snapshot: ' . htmlspecialchars($cityLabel) . '</h3>'
        . '<img class="lpc-data-chart-img" src="' . htmlspecialchars($imgUrl) . '" alt="' . htmlspecialchars((string) ($meta['alt'] ?? '')) . '" loading="lazy">';
    if ($caption !== '') $html .= '<p class="lpc-data-chart-caption">' . htmlspecialchars($caption) . '</p>';
    return $html . '</div></section>';
}
