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

/**
 * Every chart in a niche that belongs to a named group, in a stable order.
 *
 * A group is how a PAGE says what it is about without naming one chart: a flood page asks for
 * "weather" and gets whichever of the weather charts this domain draws. Sorted by id so the
 * order never depends on the filesystem — the pick must be reproducible.
 */
function city_chart_group(string $niche, string $group): array
{
    $out = [];
    foreach (city_chart_definitions($niche) as $id => $def) {
        $groups = array_map('strval', (array) ($def['groups'] ?? []));
        if (in_array($group, $groups, true)) $out[$id] = $def;
    }
    ksort($out);
    return $out;
}

/** Every group name this niche defines. Scanned, never listed. */
function city_chart_groups(string $niche): array
{
    $out = [];
    foreach (city_chart_definitions($niche) as $def) {
        foreach ((array) ($def['groups'] ?? []) as $g) {
            $g = trim((string) $g);
            if ($g !== '') $out[$g] = true;
        }
    }
    $names = array_keys($out);
    sort($names);
    return $names;
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
    if (($def['type'] ?? 'bars') === 'compare')  return city_chart_compare_series($def, $city);
    if (($def['type'] ?? 'bars') === 'timeline') return city_chart_timeline_series($def, $city);

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

/**
 * A TIMELINE series: the years something happened, plotted along a time axis.
 *
 * A different question from the bar charts. Those ask "how much"; this asks "when, and how
 * recently" — which for flood history is the fact a homeowner actually reacts to. "The last
 * major flood here was 2016" lands harder than any average.
 *
 * Years only, sanity-checked to a plausible range: a stray 12 or 20166 is a mis-parse, not a
 * flood, and one bad value would stretch the axis and make the whole picture wrong.
 *
 * @return array{values:float[],labels:string[],source:string}|null
 */
function city_chart_timeline_series(array $def, array $city): ?array
{
    $key = (string) ($def['data_key'] ?? '');
    if ($key === '' || !isset($city[$key]) || !is_array($city[$key])) return null;

    $years = [];
    foreach ($city[$key] as $y) {
        // An entry may be a bare year or an object carrying one.
        if (is_array($y)) $y = $y['year'] ?? null;
        if (!is_numeric($y)) continue;
        $y = (int) $y;
        if ($y < 1800 || $y > (int) date('Y')) continue;
        $years[$y] = true;                       // de-duplicate
    }
    $years = array_keys($years);
    if (!$years) return null;
    sort($years);

    return [
        'values' => array_map('floatval', $years),
        'labels' => array_map('strval', $years),
        'source' => (string) ($city[$def['source_key'] ?? '__none'] ?? ''),
    ];
}

/** Fill {city}/{SS}/{unit}/{summary} in a definition's title or alt string. */
function city_chart_text(string $tpl, array $city, array $def, array $series): string
{
    $vals = $series['values'];
    $labels = $series['labels'];
    $unit = (string) ($def['unit'] ?? '');
    // Same rule as the drawing: "%" attaches, other units take a space.
    $u = $unit === '' ? '' : ($unit === '%' ? '%' : ' ' . $unit);
    $maxI = array_keys($vals, max($vals))[0];
    $minI = array_keys($vals, min($vals))[0];
    $fmt = fn($n) => rtrim(rtrim(number_format($n, 1), '0'), '.');

    // A comparison's point is the gap, not the extremes. "Highest Lufkin, lowest US average" is
    // true and says nothing; describe it the way the picture does, so the alt text carries the
    // same argument to someone who cannot see it.
    // A timeline's point is recency and frequency, not a maximum. "Highest 2016, lowest 1994"
    // would be arithmetically true and completely useless.
    if (($def['type'] ?? 'bars') === 'timeline') {
        $last = (int) max($vals);
        $n    = count($vals);
        $summary = 'Most recent: ' . $last . '. '
                 . ($n === 1 ? 'One recorded event' : $n . ' recorded events since ' . (int) min($vals)) . '.';
    } elseif (($def['type'] ?? 'bars') === 'compare' && count($vals) > 1) {
        $parts = [];
        foreach ($vals as $i => $v) {
            $parts[] = ($labels[$i] ?? '') . ' ' . $fmt($v) . $u;
        }
        $summary = implode(', ', $parts) . '. ' . city_chart_compare_summary($series, $def);
    } else {
        $summary = $labels
            ? 'Highest ' . $labels[$maxI] . ' at ' . $fmt($vals[$maxI]) . $u
              . ', lowest ' . $labels[$minI] . ' at ' . $fmt($vals[$minI]) . $u . '.'
            : 'Ranges from ' . $fmt(min($vals)) . ' to ' . $fmt(max($vals)) . $u . '.';
    }

    // Figures a CAPTION can quote. The alt text and title only ever needed {summary}; a caption
    // wants to build its own sentence, so it gets the individual numbers — and gets them from
    // the same series the picture was drawn from, so a caption can never contradict its image.
    $sum = array_sum($vals);
    $extra = [
        '{peak}'        => $labels[$maxI] ?? '',
        '{peak_value}'  => $fmt($vals[$maxI]) . $u,
        '{low}'         => $labels[$minI] ?? '',
        '{low_value}'   => $fmt($vals[$minI]) . $u,
        '{total}'       => $fmt($sum) . $u,
        '{count}'       => (string) count($vals),
        // So a caption can write "{count} flood event{count_plural}" and read correctly at one.
        // Lufkin came back with a single flood year on the mould site and three on the water
        // site, and "1 documented flood events" is the kind of thing nobody notices in review
        // and everybody notices on the page.
        '{count_plural}' => count($vals) === 1 ? '' : 's',
    ];
    if (($def['type'] ?? 'bars') === 'timeline') {
        $extra['{most_recent}'] = (string) (int) max($vals);
        $extra['{earliest}']    = (string) (int) min($vals);
        $extra['{years_since}'] = (string) max(0, (int) date('Y') - (int) max($vals));
    } elseif (($def['type'] ?? 'bars') === 'compare' && count($vals) > 1) {
        $extra['{self_value}']       = $fmt($vals[0]) . $u;
        $extra['{benchmark}']        = $labels[1] ?? '';
        $extra['{benchmark_value}']  = $fmt($vals[1]) . $u;
        $extra['{difference}']       = city_chart_compare_summary($series, $def);
    }

    return strtr($tpl, $extra + [
        '{city}' => (string) ($city['city'] ?? ''),
        '{SS}' => (string) ($city['SS'] ?? ''),
        '{state}' => (string) ($city['state'] ?? ''),
        '{unit}' => $unit,
        '{summary}' => $summary,
        '{source}' => $series['source'],
    ]);
}

/**
 * The caption that sits UNDER the picture — the same facts as text, because everything inside
 * the image is pixels Google cannot read.
 *
 * PHRASINGS VARY BY DOMAIN. A definition holds several, and `ms_variant()` picks one. Without
 * that, every site built from this master would carry the identical sentence with only the
 * numbers swapped — a template shared across the whole network, which is precisely the
 * duplicate-content pattern the variance work exists to remove. Adding one while removing
 * another would be no progress at all.
 *
 * The city is the subject. Deliberately NO list of nearby towns: repeated on 27 pages that is
 * boilerplate and a well-known low-quality local-SEO pattern, it dilutes the one entity the
 * site is about, and it would compete with a future site for those towns. The town names stay
 * in the diagram, where being pixels is a FEATURE.
 *
 * Returns '' when the definition declares no captions — no data, no caption, same rule as the
 * chart itself.
 */
function city_chart_caption(array $def, array $city, array $series, string $domain = ''): string
{
    $caps = array_values(array_filter(array_map('trim', (array) ($def['captions'] ?? []))));
    if (!$caps) return '';
    // ms_variant() is the project's existing per-domain primitive, and it measures
    // evenly here (150/150/150/150 across 600 domains on four phrasings).
    $pick = function_exists('ms_variant') ? ms_variant($domain, count($caps), 'chart_caption_' . ($def['id'] ?? '')) : 0;
    $text = city_chart_text($caps[$pick] ?? $caps[0], $city, $def, $series);

    // The citation belongs in the text too, not only burned into the image — a sourced figure
    // on a page advising a homeowner is worth more than an unsourced one.
    $src = trim((string) ($series['source'] ?? ''));
    if ($src !== '' && stripos($text, $src) === false) $text .= ' Source: ' . $src . '.';
    return trim($text);
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
    $label = city_chart_article($series['labels'][1] ?? 'average');
    if (abs($pct) < 1) return 'About the same as ' . $label . '.';
    return abs($pct) . '% ' . ($pct > 0 ? 'more' : 'less') . ' than ' . $label . '.';
}

/**
 * Give a benchmark label its article, so it reads inside a sentence.
 *
 * Labels are authored as noun phrases and some already carry one: "{state} average" resolves to
 * "Texas average", while "the hard-water threshold" has its own. Without this the summary said
 * "46% more than Texas average" — a missing "the" that appeared on every comparison chart on
 * every site.
 */
function city_chart_article(string $label): string
{
    $label = trim($label);
    if ($label === '') return 'average';
    // Already articled, or a proper name doing the job of one.
    if (preg_match('/^(the|a|an)\s/i', $label)) return $label;
    return 'the ' . $label;
}

