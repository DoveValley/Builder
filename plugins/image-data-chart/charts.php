<?php
/**
 * Chart DEFINITIONS — what a niche charts, declared as data rather than code.
 *
 * One JSON file per chart in the master's own multisite/charts/ folder. Drop a file in and the
 * chart exists; delete it and it stops. Nothing here or anywhere else holds a list of chart
 * names, so adding one never means touching PHP — the rule the brief sets for every option in
 * this system.
 *
 * That is also why this is a generic engine rather than a rainfall chart: rainfall matters for
 * water damage and means nothing for appliance repair. Each niche declares its own series.
 *
 * A definition looks like:
 * {
 *   "id":        "rainfall",
 *   "name":      "Monthly rainfall",
 *   "type":      "bars",
 *   "data_key":  "rainfall_monthly",     // the field to read from this city's cities.json row
 *   "labels":    ["Jan","Feb","Mar", ...],
 *   "unit":      "in",
 *   "title":     "Average monthly rainfall in {city}, {SS}",
 *   "alt":       "Monthly rainfall for {city}, {SS}. {summary}",
 *   "source_key":"rainfall_source"       // optional: field holding the citation
 * }
 *
 * NO DATA MEANS NO CHART. A city whose row lacks data_key is skipped, never drawn with
 * defaults or estimates — a chart makes a number look authoritative, so an invented one is
 * worse than no chart at all, especially on a page advising someone about their home.
 */

/**
 * The niche this site belongs to, taken from its own niche_brief.json — the field that
 * already names it ("water restoration", "pest", "appliance repair"). Slugified, because it
 * is used as a folder name.
 */
function city_chart_niche(string $siteDir): string
{
    $b = json_decode((string) @file_get_contents($siteDir . '/multisite/niche_brief.json'), true);
    $niche = is_array($b) ? trim((string) ($b['niche'] ?? '')) : '';
    if ($niche === '') return '';
    return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($niche)), '-');
}

/**
 * Every chart defined for a niche. Lives INSIDE this plugin, so the plugin is the whole unit:
 * drop the folder in and its niches come with it; take it out and nothing is left behind in
 * the sites. Adding a niche is a new folder, adding a chart is a new file — no code, ever.
 *
 *   plugins/image-data-chart/niches/{niche-slug}/{chart}.json
 *
 * A niche with no folder simply has no charts, which is a valid state rather than an error.
 */
function city_chart_definitions(string $niche): array
{
    $out = [];
    if ($niche === '') return $out;
    $dir = __DIR__ . '/niches/' . basename($niche);
    foreach (glob($dir . '/*.json') ?: [] as $f) {
        $d = json_decode((string) @file_get_contents($f), true);
        if (!is_array($d)) continue;
        $id = trim((string) ($d['id'] ?? basename($f, '.json')));
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $id)) continue;   // token-safe ids only
        $d['id'] = $id;
        $d['type'] = $d['type'] ?? 'bars';
        $out[$id] = $d;
    }
    ksort($out);
    return $out;
}

/** Which niches this plugin ships charts for. Scan, never a list. */
function city_chart_niches(): array
{
    $out = [];
    foreach (glob(__DIR__ . '/niches/*', GLOB_ONLYDIR) ?: [] as $d) $out[] = basename($d);
    sort($out);
    return $out;
}

/**
 * The numbers for one chart on one city, or null when this city has none.
 * @return array{values:float[],labels:string[],source:string}|null
 */
function city_chart_series(array $def, array $city): ?array
{
    if (($def['type'] ?? 'bars') === 'compare') return city_chart_compare_series($def, $city);

    $key = (string) ($def['data_key'] ?? '');
    if ($key === '' || !isset($city[$key])) return null;

    $raw = $city[$key];
    if (!is_array($raw) || !$raw) return null;

    // Two accepted shapes: a plain list of numbers, or {label: value}.
    $values = [];
    $labels = [];
    if (array_keys($raw) === range(0, count($raw) - 1)) {
        foreach ($raw as $v) { if (!is_numeric($v)) return null; $values[] = (float) $v; }
        $labels = array_map('strval', (array) ($def['labels'] ?? []));
    } else {
        foreach ($raw as $k => $v) { if (!is_numeric($v)) return null; $labels[] = (string) $k; $values[] = (float) $v; }
    }
    if (!$values) return null;
    // Labels are optional, but a partial set would mislabel bars — drop them rather than
    // line them up wrongly.
    if (count($labels) !== count($values)) $labels = [];

    $src = (string) ($city[$def['source_key'] ?? '__none'] ?? '');
    return ['values' => $values, 'labels' => $labels, 'source' => $src];
}

/** Fill {city}/{SS}/{unit}/{summary} in a definition's title or alt string. */
function city_chart_text(string $tpl, array $city, array $def, array $series): string
{
    $vals = $series['values'];
    $labels = $series['labels'];
    $unit = (string) ($def['unit'] ?? '');
    $maxI = array_keys($vals, max($vals))[0];
    $minI = array_keys($vals, min($vals))[0];
    $fmt = fn($n) => rtrim(rtrim(number_format($n, 1), '0'), '.');

    $summary = $labels
        ? 'Highest ' . $labels[$maxI] . ' at ' . $fmt($vals[$maxI]) . ($unit ? " $unit" : '')
          . ', lowest ' . $labels[$minI] . ' at ' . $fmt($vals[$minI]) . ($unit ? " $unit" : '') . '.'
        : 'Ranges from ' . $fmt(min($vals)) . ' to ' . $fmt(max($vals)) . ($unit ? " $unit" : '') . '.';

    return strtr($tpl, [
        '{city}' => (string) ($city['city'] ?? ''),
        '{SS}' => (string) ($city['SS'] ?? ''),
        '{state}' => (string) ($city['state'] ?? ''),
        '{unit}' => $unit,
        '{summary}' => $summary,
        '{source}' => $series['source'],
    ]);
}

/**
 * A COMPARISON series: this city's single figure against one or more benchmarks.
 *
 * A lone number is a fact; a number against a benchmark is an argument. "48 inches of rain"
 * says little — "48 against the Texas average of 28" says why this city needs the service.
 *
 * A benchmark may be a constant carried in the definition (a published national average) or a
 * per-city field (a state average that differs by city). Either way it needs its own source:
 * the whole point of the comparison is the claim it makes, so an unsourced benchmark is worse
 * than none.
 *
 * The city's own figure is REQUIRED. No figure means no chart — never a bar drawn at the
 * benchmark's height to fill the space.
 *
 * @return array{values:float[],labels:string[],source:string,highlight:int}|null
 */
function city_chart_compare_series(array $def, array $city): ?array
{
    $key = (string) ($def['data_key'] ?? '');
    if ($key === '' || !isset($city[$key]) || !is_numeric($city[$key])) return null;

    $sub = fn($s) => strtr((string) $s, [
        '{city}' => (string) ($city['city'] ?? ''),
        '{SS}' => (string) ($city['SS'] ?? ''),
        '{state}' => (string) ($city['state'] ?? ''),
    ]);

    $values = [(float) $city[$key]];
    $labels = [$sub($def['self_label'] ?? '{city}')];
    $sources = [];
    $s = trim((string) ($city[$def['source_key'] ?? '__none'] ?? ''));
    if ($s !== '') $sources[] = $s;

    foreach ((array) ($def['benchmarks'] ?? []) as $b) {
        if (!is_array($b)) continue;
        // A per-city field wins over a constant, so a definition can ship a national default
        // and still use a state figure wherever research has one.
        $v = null;
        $vk = (string) ($b['value_key'] ?? '');
        if ($vk !== '' && isset($city[$vk]) && is_numeric($city[$vk])) $v = (float) $city[$vk];
        elseif (isset($b['value']) && is_numeric($b['value']))         $v = (float) $b['value'];
        if ($v === null) continue;                 // no figure for this benchmark — omit it

        $values[] = $v;
        $labels[] = $sub($b['label'] ?? 'Average');
        $bs = trim((string) ($b['source'] ?? ''));
        if ($bs !== '' && !in_array($bs, $sources, true)) $sources[] = $bs;
    }

    // One bar on its own is not a comparison.
    if (count($values) < 2) return null;

    return ['values' => $values, 'labels' => $labels,
            'source' => implode(' · ', $sources), 'highlight' => 0];
}

/**
 * The one-line takeaway, computed from the figures rather than asserted: "72% more than the
 * Texas average". Arithmetic on sourced numbers is fair; a judgement about what that means
 * would not be, so this states the difference and stops.
 */
function city_chart_compare_summary(array $series, array $def): string
{
    $vals = $series['values'];
    if (count($vals) < 2 || $vals[1] == 0.0) return '';
    $pct = round((($vals[0] - $vals[1]) / abs($vals[1])) * 100);
    if (abs($pct) < 1) return 'About the same as ' . ($series['labels'][1] ?? 'average') . '.';
    return abs($pct) . '% ' . ($pct > 0 ? 'more' : 'less') . ' than ' . ($series['labels'][1] ?? 'average') . '.';
}
