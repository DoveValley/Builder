<?php
/**
 * Pest Season — published seasonal guidance for one pest, set against this city's own
 * retrieved climate normals, both cited.
 *
 * WHY THIS AND NOT THE AI BLOCK
 * pest's seasonal_calendar archetype now receives the real NOAA figures and uses them well,
 * but ai_render_as is `steps` and its schema is heading/text strings — there is no field for
 * a link, so it cannot carry a citation. This block exists only to do the thing that one
 * cannot: quote a named source verbatim with a working URL.
 *
 * WHY ONLY THREE PAGES
 * A pest gets an entry only if its seasonal behaviour is stated verbatim by CDC or a
 * land-grant extension service. Three qualify today. Termite is blocked — UGA gives four
 * different swarm windows by species and which species is present depends on region, so one
 * national window would be wrong; it is also the only PINNED page, making it the highest-value
 * gap. Mosquito has no citable source at all: the ubiquitous "50F and they go dormant" traces
 * only to a 1970s CDC handbook PDF. Bed bug and cockroach pages get nothing deliberately —
 * they are indoor year-round pests and a freeze window there is filler.
 *
 * Adding a pest is a JSON entry. No code change.
 */

if (!function_exists('register_plugin')) return;   // not loaded outside the factory

register_plugin(
    'pest_season',
    'Pest Season',
    'On a pest page, states what the published source actually says about that pest\'s season '
    . '— quoted verbatim from CDC or a land-grant extension service with a live link — and sets '
    . 'it against this city\'s own retrieved NOAA climate normals (freeze dates, days above '
    . '90&deg;F), which carry their own station-and-distance citation. Place '
    . '<code>&lt;!--pest_season--&gt;</code> in a Custom HTML block; the pest is derived from '
    . 'the page slug, so nothing needs configuring. A pest with no citable published statement '
    . 'renders NOTHING rather than a generic season — currently only tick, fire ant and stink '
    . 'bug qualify. Data and sources are in plugins/pest-season/season.json and can be '
    . 'reviewed there.',
    '&#128027;',   // 🐛
    __DIR__
);

/** The dataset, decoded once. A malformed file is skipped, never fatal. */
function pest_season_data(): array {
    static $d = null;
    if ($d !== null) return $d;
    $f = __DIR__ . '/season.json';
    $raw = is_file($f) ? (string) @file_get_contents($f) : '';
    $j = $raw !== '' ? json_decode($raw, true) : null;
    $d = is_array($j) ? $j : [];
    return $d;
}

/** This page's slug with the city suffix removed. Mirrors mold_materials_current_slug(). */
function pest_season_current_slug(): string {
    global $slug, $data;
    $base = (string) ($slug ?? '');
    $city = $data['site_vars']['city_slug'] ?? '';
    if ($city !== '') $base = preg_replace('/-' . preg_quote($city, '/') . '$/', '', $base);
    return trim((string) $base, '-');
}

/** Which pest key this slug is, or ''. Longest match wins — same trap page_pool.php:231 documents. */
function pest_season_key_for(string $slug): string {
    $best = '';
    foreach (array_keys(pest_season_data()['pests'] ?? []) as $k) {
        if ($slug === $k || str_starts_with($slug, $k . '-')) {
            if (strlen($k) > strlen($best)) $best = $k;
        }
    }
    return $best;
}

/** "05/02" -> "May 2". Returns '' for anything that isn't MM/DD, never a guess. */
function pest_season_date(string $v): string {
    $v = trim($v);
    if (!preg_match('#^(\d{1,2})/(\d{1,2})$#', $v, $m)) return '';
    $months = [1 => 'January', 'February', 'March', 'April', 'May', 'June', 'July',
               'August', 'September', 'October', 'November', 'December'];
    $mo = (int) $m[1];
    return isset($months[$mo]) ? $months[$mo] . ' ' . (int) $m[2] : '';
}

/** Trim a float for display: 160.0 -> "160", 6.6 -> "6.6". */
function pest_season_num($v): string {
    if (!is_numeric($v)) return '';
    $f = (float) $v;
    return $f == (int) $f ? (string) (int) $f : (string) round($f, 1);
}

/**
 * Resolve the {tokens} in a `local` sentence from the city record.
 *
 * Only the tokens listed here exist. A token whose field is missing makes the WHOLE sentence
 * unusable and returns '' — a half-finished sentence with a gap in it is worse than no
 * sentence, and this block's only job is to be checkable.
 */
function pest_season_local(string $tpl, array $city): string {
    $mon = ['January', 'February', 'March', 'April', 'May', 'June', 'July',
            'August', 'September', 'October', 'November', 'December'];

    $peak = function (string $field) use ($city, $mon) {
        $a = $city[$field] ?? null;
        if (!is_array($a) || count($a) !== 12) return [null, null, null];
        $vals = array_map('floatval', array_values($a));
        $i = array_search(max($vals), $vals, true);
        return [max($vals), $mon[$i] ?? null, array_sum($vals)];
    };
    [$hotPeak, $hotMonth, $hotYear] = $peak('hot_days_monthly');
    [, , $freezeYear] = $peak('freeze_days_monthly');

    $map = [
        '{city}'                  => (string) ($city['city'] ?? ''),
        '{SS}'                    => (string) ($city['SS'] ?? ''),
        '{freeze_last_spring}'    => pest_season_date((string) ($city['freeze_last_spring'] ?? '')),
        '{freeze_first_fall}'     => pest_season_date((string) ($city['freeze_first_fall'] ?? '')),
        '{frost_free_days}'       => pest_season_num($city['frost_free_days'] ?? null),
        '{hot_days_peak}'         => pest_season_num($hotPeak),
        '{hot_days_peak_month}'   => (string) ($hotMonth ?? ''),
        '{hot_days_year}'         => pest_season_num($hotYear),
        '{freeze_days_year}'      => pest_season_num($freezeYear),
    ];
    $out = strtr($tpl, $map);
    // any token still unresolved, or resolved to nothing, voids the sentence
    if (preg_match('/\{[a-z_]+\}/', $out)) return '';
    foreach ($map as $tok => $val) {
        if (strpos($tpl, $tok) !== false && trim((string) $val) === '') return '';
    }
    return $out;
}

/** Everything this page needs, or null when there is nothing citable to say. */
function pest_season_resolve(string $override = ''): ?array {
    $d = pest_season_data();
    if (!$d || empty($d['pests'])) return null;
    $key = $override !== '' && $override !== 'auto'
        ? $override : pest_season_key_for(pest_season_current_slug());
    $p = $d['pests'][$key] ?? null;
    if (!is_array($p) || empty($p['verbatim'])) return null;
    $src = $d['sources'][$p['source'] ?? ''] ?? null;
    if (!is_array($src)) return null;                 // no citable source: no block

    $city = function_exists('city_chart_current_city') ? city_chart_current_city() : [];
    // The local half is optional: without it the quote still stands on its own, cited.
    $local = $local_src = '';
    if (!empty($p['local'])) {
        $local = pest_season_local((string) $p['local'], $city);
        if ($local !== '') {
            $local_src = trim((string) ($city[$p['local_source_field'] ?? ''] ?? ''));
        }
    }
    return [
        'key' => $key, 'label' => $p['label'] ?? $key,
        'heading' => (string) ($p['heading'] ?? ''),
        'verbatim' => (string) $p['verbatim'],
        'also' => array_values(array_filter((array) ($p['also'] ?? []))),
        'context' => (string) ($p['context'] ?? ''),
        'source_url' => (string) ($src['url'] ?? ''),
        'source_short' => (string) ($src['short'] ?? 'source'),
        'source_label' => (string) ($src['label'] ?? ''),
        'local' => $local, 'local_source' => $local_src,
        'intros' => (array) ($d['intros'] ?? []),
        'city' => (string) ($city['city'] ?? ''), 'SS' => (string) ($city['SS'] ?? ''),
    ];
}

/** Intro choice, seeded on the business DOMAIN — never the page or city, or two pages on one
 *  site would describe the same thing in different voices. Mirrors mold_materials_lane(). */
function pest_season_lane(string $domain, int $lanes = 3): int {
    $domain = strtolower(trim($domain));
    if ($domain === '' || strpos($domain, '{') !== false) return 0;
    return $lanes > 0 ? (int) (crc32($domain) % $lanes) : 0;
}

function pest_season_css(): string {
    static $done = false;
    if ($done) return '';
    $done = true;
    return '<style>'
        . '.ps-wrap{margin:0 0 6px}'
        . '.ps-quote{margin:14px 0 0;padding:14px 16px;background:#f8fafc;'
        . 'border-left:3px solid #fd783b;border-radius:0 8px 8px 0}'
        . '.ps-quote p{margin:0 0 6px;font-size:1.02rem;color:#1e3a5f;line-height:1.55}'
        . '.ps-quote p:last-child{margin-bottom:0}'
        . '.ps-also{margin:8px 0 0;font-size:.94rem;color:#334155;line-height:1.55}'
        . '.ps-cite{font-size:.8rem}'
        . '.ps-ctx{display:block;margin-top:8px;color:#475569;font-size:.88rem;line-height:1.55}'
        . '.ps-local{margin:14px 0 0;color:#334155;line-height:1.6}'
        . '.ps-local .ps-cite{display:block;margin-top:4px;color:#64748b}'
        . '</style>';
}

/** Render, or '' when this page has nothing citable. */
function pest_season_render(array $attrs = []): string {
    global $data;
    $r = pest_season_resolve((string) ($attrs['pest'] ?? ''));
    if ($r === null) return '';

    $host = parse_url((string) ($data['site_vars']['website'] ?? ''), PHP_URL_HOST)
        ?: (string) ($data['site_vars']['website'] ?? '');
    $intro = $r['intros']
        ? (string) $r['intros'][pest_season_lane($host, count($r['intros']))] : '';

    $cite = function (string $url, string $short): string {
        if ($url === '') return '';
        return ' <a class="ps-cite" href="' . h($url) . '" target="_blank" rel="noopener">'
            . h($short) . '</a>';
    };

    // The content-block div MUST come first: blocks.php:788 only takes the raw path (skipping
    // the custom_html wrapper, and injecting our anchor id) when the HTML starts with one. A
    // leading <style> breaks that match and nests this inside .content-block, which is
    // display:flex — see the same mistake and fix in mold-materials.
    $h  = '<div class="content-block block-pest-season">';
    $h .= pest_season_css();
    $h .= '<div class="container ps-wrap">';

    $head = $r['heading'] !== ''
        ? strtr($r['heading'], ['{city}' => $r['city'], '{SS}' => $r['SS']])
        : ('When is ' . $r['label'] . ' season in ' . $r['city'] . ', ' . $r['SS'] . '?');
    // The heading is phrased as the question people actually search, which is the whole
    // reason this block is worth having over a generic "Seasonal activity" header.
    $h .= '<h2>' . h($head) . '</h2>';
    if ($intro !== '') $h .= '<p>' . h(resolve_shortcodes($intro)) . '</p>';

    $h .= '<blockquote class="ps-quote"><p>&ldquo;' . h($r['verbatim']) . '&rdquo;'
        . $cite($r['source_url'], $r['source_short']) . '</p>';
    foreach ($r['also'] as $a) {
        $h .= '<p class="ps-also">&ldquo;' . h((string) $a) . '&rdquo;</p>';
    }
    if ($r['context'] !== '') {
        $h .= '<span class="ps-ctx">' . h($r['context']) . '</span>';
    }
    $h .= '</blockquote>';

    if ($r['local'] !== '') {
        $h .= '<p class="ps-local">' . h($r['local']);
        if ($r['local_source'] !== '') {
            $h .= '<span class="ps-cite">' . h('Source: ' . $r['local_source']) . '</span>';
        }
        $h .= '</p>';
    }

    $h .= '</div></div>';
    return $h;
}

/**
 * Replace the marker in a custom_html block. Returns the HTML untouched when the marker is
 * absent; replaces with '' when this page has nothing citable, so the marker never shows.
 */
add_hook('shortcode_content', function (string $html, string $pathPrefix = ''): string {
    if (strpos($html, 'pest_season') === false) return $html;
    return preg_replace_callback(
        '/<!--\s*pest_season([^>]*?)-->/i',
        function ($m) {
            $attrs = function_exists('_csm_parse_sc_attrs') ? _csm_parse_sc_attrs($m[1]) : [];
            return pest_season_render($attrs);
        },
        $html
    );
});
