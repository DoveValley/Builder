<?php
/**
 * Water Drying — what EPA says can be dried in place and what has to be replaced, per page,
 * with a per-CITY second half built from retrieved figures.
 *
 * THE SOURCE IS NAMED FOR THIS NICHE
 * EPA Table 1 is titled "Water Damage — Cleanup and Mold Prevention" and EPA describes it as
 * guidance for responding "within 24-48 hours to prevent mold growth". That window is the
 * commercial core of water restoration, and it comes from EPA rather than from us. (Mold uses
 * Table 2, which is a different question: mold already growing, by affected area.)
 *
 * ANTI-FINGERPRINT, which is why this is shaped differently from mold-materials
 * The EPA rows are identical fleet-wide — that is what makes them quotes. Everything else
 * varies, and varies on TWO axes:
 *   - by city, because the second half is generated from that city's own retrieved figures
 *     through `when` conditions rather than hand-written;
 *   - by page, because each page uses a DIFFERENT metric — freezing nights on the burst-pipe
 *     page, flood history on the basement page, heavy-rain days on the roof page, housing age
 *     on the appliance pages, rainfall elsewhere.
 * So two pages on one site differ from each other, and the same page differs between sites.
 * mold-materials cannot do the second: its location notes are hand-written per location and
 * therefore identical on all 100 sites.
 *
 * THREE MODES, because the source's own scope has to be respected
 *   table        the drying table, for the 21 pages it covers
 *   window       the 24-48 hour window only — roof-tarping and emergency-board-up are
 *                prevention services, where a drying table would be beside the point
 *   contaminated sewage-cleanup gets EPA's clean-water caveat AS its content. Table 1
 *                explicitly does not cover contaminated water, so applying it there would
 *                misrepresent the source.
 */

if (!function_exists('register_plugin')) return;   // not loaded outside the factory

register_plugin(
    'water_drying',
    'Water Drying',
    'On a water-damage page, shows what EPA says can be dried in place and what has to be '
    . 'removed — quoted verbatim from Table 1 of its water-damage guidance, with a live link '
    . 'per row — inside the 24-48 hour window EPA itself sets. The second half is built from '
    . 'this city\'s own retrieved figures, and each page uses a different one (freezing nights, '
    . 'flood history, heavy-rain days, housing age or rainfall) so no two pages or sites read '
    . 'alike. Place <code>&lt;!--water_drying--&gt;</code> in a Custom HTML block; the page is '
    . 'derived from the slug. Pages EPA does not cover get something else: sewage-cleanup gets '
    . 'the clean-water caveat instead of a drying table. Data and sources are in '
    . 'plugins/water-drying/drying.json.',
    '&#128167;',   // 💧
    __DIR__
);

/** The dataset, decoded once. A malformed file is skipped, never fatal. */
function water_drying_data(): array {
    static $d = null;
    if ($d !== null) return $d;
    $f = __DIR__ . '/drying.json';
    $raw = is_file($f) ? (string) @file_get_contents($f) : '';
    $j = $raw !== '' ? json_decode($raw, true) : null;
    $d = is_array($j) ? $j : [];
    return $d;
}

/** This page's slug with the city suffix removed. */
function water_drying_current_slug(): string {
    global $slug, $data;
    $base = (string) ($slug ?? '');
    $city = $data['site_vars']['city_slug'] ?? '';
    if ($city !== '') $base = preg_replace('/-' . preg_quote($city, '/') . '$/', '', $base);
    return trim((string) $base, '-');
}

/** Which page entry this slug is, or ''. Longest match wins — page_pool.php:231's trap. */
function water_drying_key_for(string $slug): string {
    $best = '';
    foreach (array_keys(water_drying_data()['pages'] ?? []) as $k) {
        if ($slug === $k || str_starts_with($slug, $k . '-')) {
            if (strlen($k) > strlen($best)) $best = $k;
        }
    }
    return $best;
}

/** Trim a float for display: 24.0 -> "24", 6.6 -> "6.6". */
function water_drying_num($v): string {
    if (!is_numeric($v)) return '';
    $f = (float) $v;
    return $f == (int) $f ? (string) (int) $f : (string) round($f, 1);
}

/**
 * Everything derivable from a city record, as display strings plus a numeric map for `when`.
 *
 * Kept in one place because the per-city sentences are the whole anti-fingerprint mechanism:
 * if a token silently resolves to nothing the sentence is voided, and a voided sentence means
 * that page falls back to something another city also says.
 */
function water_drying_tokens(array $city): array {
    $mon = ['January', 'February', 'March', 'April', 'May', 'June', 'July',
            'August', 'September', 'October', 'November', 'December'];

    $sumPeak = function (string $field) use ($city, $mon) {
        $a = $city[$field] ?? null;
        if (!is_array($a) || count($a) !== 12) return [null, null, null];
        $v = array_map('floatval', array_values($a));
        $i = array_search(max($v), $v, true);
        return [array_sum($v), max($v), $mon[$i] ?? null];
    };
    [$fzYear, $fzPeak, $fzMonth] = $sumPeak('freeze_days_monthly');
    [$hrYear, , $hrMonth]        = $sumPeak('heavy_rain_days_monthly');
    [$wdYear, , ]                = $sumPeak('wet_days_monthly');

    $pre = $p2010 = null;
    $h = $city['homes_by_decade'] ?? null;
    if (is_array($h)) {
        if (isset($h['Before 1960']) && is_numeric($h['Before 1960']))   $pre = (float) $h['Before 1960'];
        if (isset($h['2010 or later']) && is_numeric($h['2010 or later'])) $p2010 = (float) $h['2010 or later'];
    }

    $peakDecade = null;
    $fd = $city['flood_events_by_decade'] ?? null;
    if (is_array($fd) && $fd) {
        arsort($fd);
        $peakDecade = (string) array_key_first($fd);
    }

    $pct = fn($v) => $v === null ? '' : water_drying_num($v) . '%';

    $str = [
        '{city}'                    => (string) ($city['city'] ?? ''),
        '{SS}'                      => (string) ($city['SS'] ?? ''),
        '{state}'                   => (string) ($city['state'] ?? ''),
        '{freeze_nights_year}'      => water_drying_num($fzYear),
        '{freeze_peak}'             => water_drying_num($fzPeak),
        '{freeze_peak_month}'       => (string) ($fzMonth ?? ''),
        '{heavy_rain_year}'         => water_drying_num($hrYear),
        '{heavy_rain_peak_month}'   => (string) ($hrMonth ?? ''),
        '{wet_days_year}'           => water_drying_num($wdYear),
        '{rainfall_annual}'         => water_drying_num($city['rainfall_annual'] ?? null),
        '{rainfall_state_avg}'      => water_drying_num($city['rainfall_state_avg'] ?? null),
        '{homes_pre_1960}'          => $pct($pre),
        '{homes_2010_plus}'         => $pct($p2010),
        '{flood_county}'            => (string) ($city['flood_county'] ?? ''),
        '{flood_years_with_events}' => (string) ($city['flood_years_with_events'] ?? ''),
        '{flood_most_recent}'       => (string) ($city['flood_most_recent'] ?? ''),
        '{flood_peak_decade}'       => (string) ($peakDecade ?? ''),
    ];
    $numeric = [
        'freeze_nights_year'      => $fzYear,
        'heavy_rain_year'         => $hrYear,
        'homes_pre_1960_num'      => $pre,
        'rainfall_annual'         => $city['rainfall_annual'] ?? null,
        'flood_years_with_events' => $city['flood_years_with_events'] ?? null,
    ];
    return [$str, $numeric];
}

/**
 * Pick the per-city sentence this city's own figures justify, for this page's metric.
 *
 * A variant whose `when` field is missing or non-numeric is SKIPPED rather than treated as
 * matching; a variant with an unresolvable token falls through to the next. The unconditional
 * variant is last, so there is always something true to say.
 */
function water_drying_local(string $metric, array $city): string {
    $bank = water_drying_data()['local'][$metric] ?? null;
    if (!is_array($bank)) return '';
    [$str, $num] = water_drying_tokens($city);
    foreach ($bank as $v) {
        if (!is_array($v) || trim((string) ($v['text'] ?? '')) === '') continue;
        $w = $v['when'] ?? null;
        if (is_array($w) && !empty($w['field'])) {
            $val = $num[$w['field']] ?? null;
            if (!is_numeric($val)) continue;
            $val = (float) $val;
            if (isset($w['gt']) && !($val >  (float) $w['gt'])) continue;
            if (isset($w['lt']) && !($val <  (float) $w['lt'])) continue;
        }
        $out = strtr((string) $v['text'], $str);
        if (preg_match('/\{[a-z_0-9]+\}/', $out)) continue;          // unresolved: next variant
        $empty = false;
        foreach ($str as $tok => $sv) {
            if (strpos($v['text'], $tok) !== false && trim((string) $sv) === '') $empty = true;
        }
        if ($empty) continue;
        return $out;
    }
    return '';
}

/** Everything this page needs, or null when there is nothing citable to say. */
function water_drying_resolve(string $override = ''): ?array {
    $d = water_drying_data();
    if (!$d || empty($d['pages'])) return null;
    $key = $override !== '' && $override !== 'auto'
        ? $override : water_drying_key_for(water_drying_current_slug());
    $p = $d['pages'][$key] ?? null;
    if (!is_array($p)) return null;

    $srcs = $d['sources'] ?? [];
    $city = function_exists('city_chart_current_city') ? city_chart_current_city() : [];

    $rows = [];
    foreach ((array) ($p['rows'] ?? []) as $r) {
        $m = $d['materials'][$r[0] ?? ''] ?? null;
        if (!is_array($m) || empty($m['verbatim'])) continue;     // unknown: skipped, not guessed
        $s = $srcs[$m['source'] ?? ''] ?? null;
        if (!is_array($s)) continue;                              // no citable source: no row
        $rows[] = [
            'id' => $r[0], 'label' => (string) ($m['label'] ?? $r[0]),
            'as' => (string) ($r[1] ?? ''), 'verbatim' => (string) $m['verbatim'],
            'context' => (string) ($m['context'] ?? ''),
            'url' => (string) ($s['url'] ?? ''), 'short' => (string) ($s['short'] ?? 'EPA'),
            'slabel' => (string) ($s['label'] ?? ''),
        ];
    }
    $mode = (string) ($p['mode'] ?? 'table');
    if ($mode === 'table' && !$rows) return null;                 // nothing to show

    $q = function (array $u) use ($srcs) {
        $s = $srcs[$u['source'] ?? ''] ?? null;
        if (!is_array($s) || empty($u['verbatim'])) return null;
        return ['verbatim' => (string) $u['verbatim'], 'url' => (string) ($s['url'] ?? ''),
                'short' => (string) ($s['short'] ?? 'EPA'), 'slabel' => (string) ($s['label'] ?? '')];
    };
    $universal = array_values(array_filter(array_map($q, (array) ($d['universal'] ?? []))));
    $byId = [];
    foreach ((array) ($d['universal'] ?? []) as $u) {
        if (!empty($u['id'])) $byId[$u['id']] = $q($u);
    }

    $metric = (string) ($p['metric'] ?? '');
    $local = water_drying_local($metric, $city);
    $localSrc = [];
    foreach ((array) (($d['local_sources'][$metric] ?? [])) as $f) {
        $v = trim((string) ($city[$f] ?? ''));
        if ($v !== '' && !in_array($v, $localSrc, true)) $localSrc[] = $v;
    }

    return [
        'key' => $key, 'mode' => $mode,
        'label' => (string) ($p['label'] ?? $key),
        'heading' => (string) ($p['heading'] ?? ''),
        'lead' => (string) ($p['lead'] ?? ''),
        'rows' => $rows,
        'window' => $q(($d['window'] ?? []) + ['source' => ($d['window']['source'] ?? '')]),
        'window_lead' => (string) (($d['window'] ?? [])['lead_in'] ?? ''),
        'universal' => $universal, 'u' => $byId,
        'local' => $local, 'local_sources' => $localSrc,
        'intros' => (array) ($d['intros'] ?? []),
        'city' => (string) ($city['city'] ?? ''), 'SS' => (string) ($city['SS'] ?? ''),
    ];
}

/** Intro choice seeded on the business DOMAIN, never the page or city. */
function water_drying_lane(string $domain, int $lanes = 3): int {
    $domain = strtolower(trim($domain));
    if ($domain === '' || strpos($domain, '{') !== false) return 0;
    return $lanes > 0 ? (int) (crc32($domain) % $lanes) : 0;
}

function water_drying_css(): string {
    static $done = false;
    if ($done) return '';
    $done = true;
    return '<style>'
        . '.wd-wrap{margin:0 0 6px}'
        . '.wd-window{margin:14px 0 0;padding:12px 15px;background:#fff7ed;'
        . 'border-left:3px solid #fd783b;border-radius:0 8px 8px 0;color:#1e3a5f;'
        . 'font-size:1.02rem;line-height:1.55}'
        . '.wd-tbl{width:100%;border-collapse:collapse;margin-top:14px;font-size:.95rem}'
        . '.wd-cap{caption-side:top;text-align:left;font-size:.86rem;color:#64748b;padding:0 0 8px}'
        . '.wd-tbl th,.wd-tbl td{padding:11px 9px;border-bottom:1px solid #e2e8f0;'
        . 'text-align:left;vertical-align:top}'
        . '.wd-tbl thead th{font-size:.78rem;text-transform:uppercase;letter-spacing:.04em;'
        . 'color:#64748b}'
        . '.wd-tbl tbody th{font-weight:400}'
        . '.wd-mat{display:block;font-weight:700;color:#1e3a5f;font-size:1.02rem}'
        . '.wd-as{display:block;font-weight:400;color:#475569;font-size:.88rem;margin-top:2px}'
        . '.wd-act{color:#334155;line-height:1.55}'
        . '.wd-ctx{display:block;margin-top:5px;color:#475569;font-size:.86rem}'
        . '.wd-cite{font-size:.78rem;font-style:normal}'
        . '.wd-fine{margin:14px 0 0;padding:0;list-style:none}'
        . '.wd-fine li{margin-bottom:6px;color:#475569;font-size:.88rem;line-height:1.55}'
        . '.wd-local{margin:14px 0 0;color:#334155;line-height:1.6}'
        . '.wd-local .wd-cite{display:block;margin-top:4px;color:#64748b}'
        . '@media(max-width:700px){'
        . '.wd-tbl thead{display:none}'
        . '.wd-tbl tr{display:block;border-bottom:1px solid #e2e8f0;padding:10px 0}'
        . '.wd-tbl td,.wd-tbl tbody th{display:block;border:0;padding:3px 0}'
        . '}</style>';
}

/** One cited link: <cite> around the source, full document title on the title attribute. */
function water_drying_cite(string $url, string $short, string $label = ''): string {
    if ($url === '') return '';
    return ' <cite class="wd-cite"><a href="' . h($url) . '" target="_blank" rel="noopener"'
        . ($label !== '' ? ' title="' . h($label) . '"' : '')
        . '>' . h($short !== '' ? $short : 'EPA') . '</a></cite>';
}

/** An inline quotation with its source. <q>, so no manual quote entities — see mold-materials. */
function water_drying_q(string $text, string $url): string {
    if (trim($text) === '') return '';
    return '<q' . ($url !== '' ? ' cite="' . h($url) . '"' : '') . '>' . h($text) . '</q>';
}

/** Render, or '' when this page has nothing to show. */
function water_drying_render(array $attrs = []): string {
    global $data;
    $r = water_drying_resolve((string) ($attrs['page'] ?? ''));
    if ($r === null) return '';

    $host = parse_url((string) ($data['site_vars']['website'] ?? ''), PHP_URL_HOST)
        ?: (string) ($data['site_vars']['website'] ?? '');
    $intro = '';
    if ($r['intros']) {
        $intro = (string) $r['intros'][water_drying_lane($host, count($r['intros']))];
        $intro = str_replace('{label}', $r['label'], $intro);
    }

    // The content-block div MUST be first: blocks.php:788 only takes the raw path (skipping the
    // custom_html wrapper and injecting the anchor id) when the HTML starts with one. A leading
    // <style> breaks the match and nests this inside .content-block, which is display:flex.
    $h  = '<div class="content-block block-water-drying">';
    $h .= water_drying_css();
    $h .= '<div class="container wd-wrap">';

    // Each page's h2 is its own question, so 24 pages do not compete in the same SERP.
    $head = $r['heading'] !== ''
        ? strtr($r['heading'], ['{city}' => $r['city'], '{SS}' => $r['SS']])
        : ('Water damage in ' . $r['city'] . ', ' . $r['SS']);
    $h .= '<h2>' . h($head) . '</h2>';

    if ($r['mode'] === 'contaminated') {
        if ($r['lead'] !== '') $h .= '<p>' . h($r['lead']) . '</p>';
        $cw = $r['u']['clean_water'] ?? null;
        if ($cw) {
            $h .= '<p class="wd-window">' . water_drying_q($cw['verbatim'], $cw['url'])
                . water_drying_cite($cw['url'], $cw['short'], $cw['slabel']) . '</p>';
        }
        $h .= '<p class="wd-act">Which is the whole difference: the drying-and-saving decisions '
            . 'EPA sets out for clean water do not apply here, and the job becomes one of '
            . 'removal and containment rather than salvage.</p>';
    } else {
        if ($intro !== '') $h .= '<p>' . h(resolve_shortcodes($intro)) . '</p>';
        if ($r['window']) {
            $h .= '<p class="wd-window">' . h($r['window_lead']) . ' '
                . water_drying_q($r['window']['verbatim'], $r['window']['url'])
                . water_drying_cite($r['window']['url'], $r['window']['short'],
                                    $r['window']['slabel']) . '</p>';
        }
    }

    if ($r['mode'] === 'table' && $r['rows']) {
        $h .= '<table class="wd-tbl"><caption class="wd-cap">'
            . h('EPA\'s published guidance for the materials involved, by material.')
            . '</caption><thead><tr><th scope="col">Material</th>'
            . '<th scope="col">What EPA says to do</th></tr></thead><tbody>';
        foreach ($r['rows'] as $row) {
            $h .= '<tr><th scope="row"><span class="wd-mat">' . h($row['label']) . '</span>';
            if ($row['as'] !== '') $h .= '<span class="wd-as">' . h($row['as']) . '</span>';
            $h .= '</th><td class="wd-act">'
                . water_drying_q($row['verbatim'], $row['url'])
                . water_drying_cite($row['url'], $row['short'], $row['slabel']);
            if ($row['context'] !== '') {
                $h .= '<span class="wd-ctx">' . h($row['context']) . '</span>';
            }
            $h .= '</td></tr>';
        }
        $h .= '</tbody></table>';
    }

    // the per-city half — the part that differs by city AND by page
    if ($r['local'] !== '') {
        $h .= '<p class="wd-local">' . h($r['local']);
        if ($r['local_sources']) {
            $h .= '<span class="wd-cite">'
                . h('Source: ' . implode(' · ', $r['local_sources'])) . '</span>';
        }
        $h .= '</p>';
    }

    // scope and limits, in EPA's words — skipped in contaminated mode, where the caveat led
    if ($r['mode'] !== 'contaminated' && $r['universal']) {
        $h .= '<ul class="wd-fine">';
        foreach ($r['universal'] as $u) {
            $h .= '<li>' . water_drying_q($u['verbatim'], $u['url'])
                . water_drying_cite($u['url'], $u['short'], $u['slabel']) . '</li>';
        }
        $h .= '</ul>';
    }

    $h .= '</div></div>';
    return $h;
}

/**
 * Replace the marker in a custom_html block. Untouched when absent; replaced with '' when this
 * page has nothing to show, so the marker never surfaces.
 */
add_hook('shortcode_content', function (string $html, string $pathPrefix = ''): string {
    if (strpos($html, 'water_drying') === false) return $html;
    return preg_replace_callback(
        '/<!--\s*water_drying([^>]*?)-->/i',
        function ($m) {
            $attrs = function_exists('_csm_parse_sc_attrs') ? _csm_parse_sc_attrs($m[1]) : [];
            return water_drying_render($attrs);
        },
        $html
    );
});
