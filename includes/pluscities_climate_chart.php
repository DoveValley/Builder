<?php
/**
 * includes/pluscities_climate_chart.php — "Rainfall vs. Average", Phase 4 of
 * the bland-vs-reference-sites plan (see /root/.claude/plans/crispy-napping-
 * meerkat.md at the time this was built — Safety Toolkit/Area Map/Data Chart
 * were Phases 1-3). A real NOAA-sourced chart comparing one city's annual
 * rainfall against its state and the US average — the exact chart
 * baileyrestoration.com's homepage shows with real validated numbers
 * (Provo, UT: 17.2in vs 13.5in state vs 30.3in US).
 *
 * This is ONE bespoke chart ported from the old site-building system's
 * generic bars/compare/timeline engine (plugins/image-data-chart/), not a
 * generic multi-chart-type engine in PlusCities — same "one bespoke thing"
 * discipline as includes/pluscities_data_chart.php's Housing Snapshot.
 *
 * Reuses the old engine directly rather than re-deriving its logic —
 * docs/lpc-vs-site-factory-boundary.md §3 already blesses plugins/image-
 * data-chart/* as "pure data-in/image-out rendering engines, zero
 * dependency on fleet.db or any fleet session state," already reused
 * directly by LPC's own code the same way:
 *   - plugins/image-data-chart/charts.php's city_chart_compare_series() /
 *     city_chart_compare_summary() / city_chart_text() / city_chart_article()
 *     — pure functions, no side effects, required directly below.
 *   - plugins/image-data-chart/providers/datasets.py (via lpc_bridge.py) —
 *     stdlib-only Python, fetches NOAA Climate Normals + Climate-at-a-Glance
 *     statewide averages, each independently cached under cache/.
 *   - plugins/image-data-chart/draw.php's city_chart_svg_compare() — ported
 *     below as pluscities_climate_chart_svg() rather than required directly,
 *     since it needs PlusCities' own theme, not the old system's $data
 *     global.
 *
 * The ONE new piece of old-system coupling here: pluscities_ensure_climate_
 * chart() reads one chart's definition JSON (plugins/image-data-chart/
 * niches/{old_slug}/{chart_id}.json) directly, at GENERATION time only
 * (never at serve time — pluscities_render_climate_chart() only ever reads
 * PlusCities' own cache). This is read-only static config, generation-time
 * only, same risk class as the already-blessed engine-file reuse above —
 * not copied into the niche blueprint, because copying would duplicate the
 * exact kind of tuned 'fetch' declaration that datasets.py's own
 * chart_fetch_decls() comment documents breaking once already (silently
 * dropping the 'benchmark' sub-block).
 *
 * City-level furniture, keyed on (city_id, chart_id) — NOT shared across
 * niches the way Area Map is, since different niches may pick different
 * chart_ids for the same city. See pluscities_dataset_get()'s
 * 'noaa_climate:{old_niche_slug}' provider branch (includes/pluscities_
 * research.php) for the matching cache-collision reasoning on the
 * datasets-table side.
 */

require_once __DIR__ . '/pluscities_area_map.php'; // pluscities_area_map_rasterise(), ..._convert_bin()
require_once BASE_DIR . '/plugins/image-data-chart/charts.php'; // city_chart_compare_series() etc — pure functions, already blessed for direct reuse

const PLUSCITIES_CLIMATE_CHART_THEME = ['bg' => '#f4f7fa', 'ink' => '#12314f', 'accent' => '#1f78d1', 'muted' => '#6b7f95'];

/** PlusCities niche_id -> the old system's niche-folder slug. These strings
 *  were never meant to match 1:1 (e.g. "water-damage-restoration" vs
 *  "water-restoration") — hand-authored, one niche at a time, same rollout
 *  discipline as Safety Toolkit. */
const PLUSCITIES_CLIMATE_CHART_NICHE_SLUGS = [
    'water-damage-restoration' => 'water-restoration',
];

function pluscities_climate_chart_paths(string $city_id, string $chart_id): array
{
    $base = PLUSCITIES_UPLOADS_DIR . 'climate_charts/' . $city_id . '_' . $chart_id;
    return [
        'svg'  => $base . '.svg',
        'webp' => $base . '.webp',
        'json' => $base . '.json',
        'url'  => PLUSCITIES_UPLOADS_URL . 'climate_charts/' . $city_id . '_' . $chart_id,
    ];
}

/**
 * Shells out to the old system's lpc_bridge.py for ONE city's full set of
 * NOAA-backed figures for a niche (every chart that niche declares, not
 * just one — chart_fetch_decls() already batches them, so a second chart
 * type added later needs zero new fetch). Wrapped in a hard `timeout` and
 * set_time_limit(0): datasets.py's own _get() retries each URL 3x with
 * backoff (worst case ~155s for ONE url), and this chart needs two
 * independent provider calls — far beyond Area Map's 15s cURL timeout,
 * which isn't a reusable precedent for this subprocess's real worst case.
 * A timeout is a normal "couldn't fetch this time" failure, same as Area
 * Map's "no converter, no network — no chart" rule, never fatal.
 *
 * @return array{updates:array,problems:array}|null
 */
function pluscities_climate_chart_shell_fetch(array $city, string $old_niche_slug): ?array
{
    $lat = $city['lat'] ?? null;
    $lng = $city['lng'] ?? null;
    if (!is_numeric($lat) || !is_numeric($lng)) return null;

    $cityPayload = [
        'lat'   => (float) $lat,
        'lng'   => (float) $lng,
        'state' => (string) ($city['state'] ?? ''),
        'city'  => (string) ($city['city'] ?? ''),
        'SS'    => (string) ($city['ss'] ?? ''),
    ];
    $req = json_encode(['city' => $cityPayload, 'niche' => $old_niche_slug]);
    if ($req === false) return null;

    $bridgePath = BASE_DIR . '/plugins/image-data-chart/providers/lpc_bridge.py';
    if (!is_file($bridgePath)) return null;

    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $prevLimit = (int) ini_get('max_execution_time');
    // This runs only from page-creation/backfill, never the public sync
    // endpoint — a long-running subprocess here is the same tradeoff
    // admin/new_niche_rewrite.php and admin/multisite_api.php already make
    // for their own long python3 calls.
    set_time_limit(0);

    $proc = @proc_open('timeout 90 python3 ' . escapeshellarg($bridgePath), $descriptors, $pipes);
    if (!is_resource($proc)) {
        set_time_limit($prevLimit);
        return null;
    }

    fwrite($pipes[0], $req);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    stream_get_contents($pipes[2]); // drain stderr, discard — problems[] already carries user-facing failures
    fclose($pipes[2]);
    $exitCode = proc_close($proc);
    set_time_limit($prevLimit);

    if ($exitCode !== 0 || $out === false || trim($out) === '') return null;
    $decoded = json_decode($out, true);
    return is_array($decoded) ? $decoded : null;
}

/**
 * Direct port of plugins/image-data-chart/draw.php's city_chart_svg_compare()
 * — same geometry, same horizontal-bar-with-track layout — with PlusCities'
 * own fixed neutral theme instead of a per-site $theme (this image is shared
 * platform-wide by city+chart_id, not branded per client, same reasoning as
 * Area Map's fixed palette).
 *
 * @param array $series ['values'=>float[],'labels'=>string[],'source'=>string,'highlight'=>int]
 */
function pluscities_climate_chart_svg(string $title, array $series, array $def): string
{
    $vals = $series['values'];
    $labels = $series['labels'];
    if (count($vals) < 2) return '';

    $theme  = PLUSCITIES_CLIMATE_CHART_THEME;
    $bg = $theme['bg']; $ink = $theme['ink']; $accent = $theme['accent']; $muted = $theme['muted'];
    $unit = (string) ($def['unit'] ?? '');
    $hl   = (int) ($series['highlight'] ?? 0);

    $n = count($vals);
    $rowH = 62;
    $W = 900;
    $padL = 34; $padR = 34; $padT = 96;

    // A compare chart often joins 2+ benchmark sources with " · "
    // (city_chart_compare_series()) — long enough on a real station name to
    // run off a fixed 900px canvas (confirmed live: Bedford, TX's combined
    // source ran to 113 characters). Wrapped at " · " boundaries rather than
    // truncated, so no citation is ever silently dropped.
    $src = trim((string) ($series['source'] ?? ''));
    $srcLines = [];
    if ($src !== '') {
        $cur = '';
        foreach (explode(' · ', $src) as $seg) {
            $candidate = $cur === '' ? $seg : $cur . ' · ' . $seg;
            if (strlen($candidate) > 95 && $cur !== '') {
                $srcLines[] = $cur;
                $cur = $seg;
            } else {
                $cur = $candidate;
            }
        }
        if ($cur !== '') $srcLines[] = $cur;
        $srcLines[0] = 'Source: ' . $srcLines[0];
    }
    $srcBlockH = $srcLines ? (count($srcLines) * 20 + 16) : 0;
    $H = $padT + $n * $rowH + 40 + $srcBlockH;
    $labelW = 250;
    $trackX = $padL + $labelW;
    $trackW = $W - $trackX - $padR - 110;
    $max = max(max($vals), 0.0001);

    $esc = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    $fmt = fn($x) => rtrim(rtrim(number_format($x, 1), '0'), '.');

    $o = [];
    $o[] = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $W . ' ' . $H . '" width="' . $W . '" height="' . $H . '" role="img">';
    $o[] = '<defs>'
         . '<linearGradient id="cbg2" x1="0" y1="0" x2="0" y2="1">'
         . '<stop offset="0" stop-color="#ffffff"/><stop offset="1" stop-color="' . $esc($bg) . '"/></linearGradient>'
         . '<linearGradient id="hbar" x1="0" y1="0" x2="1" y2="0">'
         . '<stop offset="0" stop-color="' . $esc($ink) . '"/><stop offset="1" stop-color="' . $esc($accent) . '"/></linearGradient>'
         . '</defs>';
    $o[] = '<rect width="' . $W . '" height="' . $H . '" fill="url(#cbg2)"/>';
    $o[] = '<rect x="0" y="0" width="' . $W . '" height="6" fill="' . $esc($accent) . '" fill-opacity=".9"/>';
    $o[] = '<text x="' . $padL . '" y="46" font-family="system-ui,-apple-system,Segoe UI,sans-serif" font-size="25" font-weight="700" fill="'
         . $esc($ink) . '">' . $esc($title) . '</text>';

    $summary = city_chart_compare_summary($series, $def);
    if ($summary !== '') {
        $o[] = '<text x="' . $padL . '" y="74" font-family="system-ui,-apple-system,Segoe UI,sans-serif" font-size="18" font-weight="600" fill="'
             . $esc($accent) . '">' . $esc($summary) . '</text>';
    }

    foreach ($vals as $i => $v) {
        $y = $padT + $i * $rowH;
        $bw = max(($v / $max) * $trackW, 3);
        $isSelf = $i === $hl;

        $o[] = '<text x="' . ($trackX - 16) . '" y="' . ($y + 30) . '" text-anchor="end"'
             . ' font-family="system-ui,-apple-system,Segoe UI,sans-serif" font-size="18" font-weight="'
             . ($isSelf ? '700' : '500') . '" fill="' . $esc($isSelf ? $ink : $muted) . '">'
             . $esc($labels[$i] ?? '') . '</text>';

        $o[] = '<rect x="' . $trackX . '" y="' . ($y + 10) . '" width="' . $trackW . '" height="30" rx="15" fill="'
             . $esc($muted) . '" fill-opacity=".10"/>';
        $o[] = '<rect x="' . $trackX . '" y="' . ($y + 10) . '" width="' . round($bw, 1) . '" height="30" rx="15" fill="'
             . ($isSelf ? 'url(#hbar)' : $esc($muted)) . '"' . ($isSelf ? '' : ' fill-opacity=".42"') . '/>';
        $o[] = '<text x="' . round($trackX + $bw + 14, 1) . '" y="' . ($y + 31) . '"'
             . ' font-family="system-ui,-apple-system,Segoe UI,sans-serif" font-size="19" font-weight="700" fill="'
             . $esc($isSelf ? $ink : $muted) . '">' . $esc($fmt($v) . ($unit !== '' ? ' ' . $unit : '')) . '</text>';
    }

    foreach ($srcLines as $li => $line) {
        $ly = $H - 22 - (count($srcLines) - 1 - $li) * 20;
        $o[] = '<text x="' . $padL . '" y="' . $ly . '" font-family="system-ui,-apple-system,Segoe UI,sans-serif"'
             . ' font-size="14" fill="' . $esc($muted) . '">' . $esc($line) . '</text>';
    }
    $o[] = '<rect x="2" y="2" width="' . ($W - 4) . '" height="' . ($H - 4) . '" rx="16" fill="none" stroke="'
         . $esc($accent) . '" stroke-opacity=".55" stroke-width="4"/>';
    $o[] = '</svg>';
    return implode("\n", $o);
}

/**
 * Generates (or refreshes) one city's climate chart for one niche and
 * caches it. Called from pluscities_create_page() and a one-time backfill —
 * NEVER from the public sync endpoint. Graceful no-op (same "no data means
 * no image" rule as everywhere else) if: the niche has no climate_chart_id
 * configured, the old chart definition is missing, NOAA has no station data
 * for this city, or there's no SVG converter on this box.
 *
 * @return array{ok:bool,drawn:bool}
 */
function pluscities_ensure_climate_chart(string $city_id, string $niche_id, bool $force = false): array
{
    $oldSlug = PLUSCITIES_CLIMATE_CHART_NICHE_SLUGS[$niche_id] ?? null;
    if (!$oldSlug) return ['ok' => false, 'drawn' => false];

    $niche = pluscities_niche_resolved($niche_id);
    $chartId = trim((string) ($niche['climate_chart_id'] ?? ''));
    if ($chartId === '' || !preg_match('/^[a-z][a-z0-9_]*$/', $chartId)) return ['ok' => false, 'drawn' => false];

    $city = infra_city_get($city_id);
    if (!$city) return ['ok' => false, 'drawn' => false];

    $defPath = BASE_DIR . "/plugins/image-data-chart/niches/{$oldSlug}/{$chartId}.json";
    $def = json_decode((string) @file_get_contents($defPath), true);
    if (!is_array($def)) return ['ok' => false, 'drawn' => false];

    $dataset = pluscities_dataset_get($city_id, 'noaa_climate:' . $oldSlug);
    if (!$dataset) return ['ok' => false, 'drawn' => false];

    // charts.php's pure functions expect one flat row (city/SS/state plus
    // every fetched field) — the same shape the old system's cities.json
    // rows always were. Rebuilt here from infra_city_get() + the cached
    // dataset values rather than carried through disk anywhere.
    $cityRow = array_merge(
        ['city' => $city['city'], 'SS' => $city['ss'], 'state' => $city['state']],
        $dataset['values']
    );

    $series = city_chart_compare_series($def, $cityRow);
    if (!$series) return ['ok' => false, 'drawn' => false]; // no figure for this city — never a filled-in guess

    $title = city_chart_text((string) ($def['title'] ?? ''), $cityRow, $def, $series);
    $svg = pluscities_climate_chart_svg($title, $series, $def);
    if ($svg === '') return ['ok' => false, 'drawn' => false];

    $p = pluscities_climate_chart_paths($city_id, $chartId);
    if (!$force && is_file($p['webp']) && is_file($p['svg']) && @file_get_contents($p['svg']) === $svg) {
        return ['ok' => true, 'drawn' => false];
    }

    if (!is_dir(dirname($p['svg'])) && !@mkdir(dirname($p['svg']), 0775, true) && !is_dir(dirname($p['svg']))) {
        return ['ok' => false, 'drawn' => false];
    }
    if (@file_put_contents($p['svg'], $svg) === false) return ['ok' => false, 'drawn' => false];

    if (!pluscities_area_map_rasterise($p['svg'], $p['webp'])) {
        @unlink($p['svg']);
        return ['ok' => false, 'drawn' => false];
    }

    $alt = city_chart_text((string) ($def['alt'] ?? ''), $cityRow, $def, $series);
    @file_put_contents($p['json'], json_encode([
        'alt'        => $alt,
        'updated_at' => gmdate('c'),
    ]));

    return ['ok' => true, 'drawn' => true];
}

/**
 * Renders the climate chart image — cache READ only, never triggers
 * generation (same split as every other chart this engine draws). Returns
 * '' if this (city, niche) combination hasn't been generated yet.
 */
function pluscities_render_climate_chart(string $city_id, string $niche_id, string $domain = ''): string
{
    $oldSlug = PLUSCITIES_CLIMATE_CHART_NICHE_SLUGS[$niche_id] ?? null;
    if (!$oldSlug) return '';
    $niche = pluscities_niche_resolved($niche_id);
    $chartId = trim((string) ($niche['climate_chart_id'] ?? ''));
    if ($chartId === '') return '';

    $p = pluscities_climate_chart_paths($city_id, $chartId);
    if (!is_file($p['webp']) || !is_file($p['json'])) return '';
    $meta = json_decode((string) @file_get_contents($p['json']), true);
    if (!is_array($meta)) return '';

    $imgUrl = PLUSCITIES_PUBLIC_BASE . $p['url'] . '.webp?v=' . filemtime($p['webp']);
    return '<section class="lpc-climate-chart lpc-fullbleed"><div class="lpc-band-inner">'
        . '<p class="lpc-eyebrow">Local Climate Data</p>'
        . '<img class="lpc-climate-chart-img" src="' . htmlspecialchars($imgUrl) . '" alt="' . htmlspecialchars((string) ($meta['alt'] ?? '')) . '" loading="lazy">'
        . '</div></section>';
}
