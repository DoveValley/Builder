<?php
/**
 * Mold Testing — what sampling can and cannot establish, quoted from EPA, against this city's
 * own flood history and housing age.
 *
 * WHY IT EXISTS
 * Three live mold pages had no factual block at all: mold-inspection (PINNED, so it is on
 * every site), air-quality-testing and real-estate-mold-inspection. mold-materials does not
 * fit them — it answers "what comes out", and these pages are about finding, not removing.
 *
 * FRAMING, CHOSEN DELIBERATELY
 * It leads with what sampling CAN do, then who should do it, then the honest context that EPA
 * considers it unnecessary once growth is visible. Every quote is accurate in any order, but
 * leading with "sampling is usually unnecessary" on a testing page talks a caller out of the
 * service, and revenue here is per call. The line still appears — omitting it would be
 * dishonest, and it is the reason nobody can quote a safe spore count — as context rather than
 * as the headline.
 *
 * SEO
 * Each page gets a DIFFERENT question-shaped h2, because an inspection query, a testing query
 * and a real-estate query are three different intents and should not compete with each other.
 * The per-city half means the prose differs per site rather than only the city name.
 */

if (!function_exists('register_plugin')) return;   // not loaded outside the factory

register_plugin(
    'mold_testing',
    'Mold Testing',
    'On a mold inspection or testing page, states what sampling can and cannot establish — '
    . 'quoted verbatim from EPA with live links — and sets it against this city\'s own '
    . 'retrieved flood history and housing age, which carry their own citations. Place '
    . '<code>&lt;!--mold_testing--&gt;</code> in a Custom HTML block; the page is derived from '
    . 'the slug, so nothing needs configuring. Only mold-inspection, air-quality-testing and '
    . 'real-estate-mold-inspection have entries; every other page renders nothing. Data and '
    . 'sources live in plugins/mold-testing/testing.json and can be reviewed there.',
    '&#128300;',   // 🔬
    __DIR__
);

/** The dataset, decoded once. A malformed file is skipped, never fatal. */
function mold_testing_data(): array {
    static $d = null;
    if ($d !== null) return $d;
    $f = __DIR__ . '/testing.json';
    $raw = is_file($f) ? (string) @file_get_contents($f) : '';
    $j = $raw !== '' ? json_decode($raw, true) : null;
    $d = is_array($j) ? $j : [];
    return $d;
}

/** This page's slug with the city suffix removed. */
function mold_testing_current_slug(): string {
    global $slug, $data;
    $base = (string) ($slug ?? '');
    $city = $data['site_vars']['city_slug'] ?? '';
    if ($city !== '') $base = preg_replace('/-' . preg_quote($city, '/') . '$/', '', $base);
    return trim((string) $base, '-');
}

/** Which entry this slug is, or ''. Longest match wins — the trap page_pool.php:231 documents. */
function mold_testing_key_for(string $slug): string {
    $best = '';
    foreach (array_keys(mold_testing_data()['pages'] ?? []) as $k) {
        if ($slug === $k || str_starts_with($slug, $k . '-')) {
            if (strlen($k) > strlen($best)) $best = $k;
        }
    }
    return $best;
}

/** Normalise a quote list to [{verbatim, url, short, label}], dropping any unknown source. */
function mold_testing_quotes($list, array $sources, ?array $default): array {
    $out = [];
    foreach ((array) $list as $q) {
        if (is_string($q)) {
            if (trim($q) === '') continue;
            $out[] = ['verbatim' => $q, 'url' => (string) ($default['url'] ?? ''),
                      'short' => (string) ($default['short'] ?? 'EPA'),
                      'label' => (string) ($default['label'] ?? '')];
            continue;
        }
        if (!is_array($q) || trim((string) ($q['verbatim'] ?? '')) === '') continue;
        $s = $sources[$q['source'] ?? ''] ?? null;
        if (!is_array($s)) continue;             // named a source we do not have: drop it
        $out[] = ['verbatim' => (string) $q['verbatim'], 'url' => (string) ($s['url'] ?? ''),
                  'short' => (string) ($s['short'] ?? 'EPA'),
                  'label' => (string) ($s['label'] ?? '')];
    }
    return $out;
}

/**
 * Resolve the {tokens} in a local sentence from the city record.
 *
 * A token whose field is missing voids the WHOLE sentence — a half-finished sentence with a
 * gap in it is worse than none, and this block's only job is to be checkable.
 */
function mold_testing_local(string $tpl, array $city): string {
    $pre1960 = '';
    $h = $city['homes_by_decade'] ?? null;
    if (is_array($h) && isset($h['Before 1960']) && is_numeric($h['Before 1960'])) {
        $pre1960 = rtrim(rtrim(number_format((float) $h['Before 1960'], 1), '0'), '.') . '%';
    }
    $map = [
        '{city}'                    => (string) ($city['city'] ?? ''),
        '{SS}'                      => (string) ($city['SS'] ?? ''),
        '{flood_county}'            => (string) ($city['flood_county'] ?? ''),
        '{flood_years_with_events}' => (string) ($city['flood_years_with_events'] ?? ''),
        '{flood_events_total}'      => (string) ($city['flood_events_total'] ?? ''),
        '{flood_most_recent}'       => (string) ($city['flood_most_recent'] ?? ''),
        '{homes_pre_1960}'          => $pre1960,
    ];
    $out = strtr($tpl, $map);
    if (preg_match('/\{[a-z_0-9]+\}/', $out)) return '';
    foreach ($map as $tok => $val) {
        if (strpos($tpl, $tok) !== false && trim((string) $val) === '') return '';
    }
    return $out;
}

/**
 * Pick the local sentence this city's own figures justify.
 *
 * Variation BECAUSE THE DATA DIFFERS: a county with flood events in 25 separate years and one
 * with none on record warrant different sentences. A `when` field that is missing or
 * non-numeric SKIPS that variant rather than matching it.
 */
function mold_testing_pick_local($local, array $city): string {
    if (is_string($local)) return mold_testing_local($local, $city);
    foreach ((array) $local as $v) {
        if (!is_array($v) || trim((string) ($v['text'] ?? '')) === '') continue;
        $w = $v['when'] ?? null;
        if (is_array($w) && !empty($w['field'])) {
            $raw = $city[$w['field']] ?? null;
            // homes_pre_1960 is derived, not a stored field
            if ($w['field'] === 'homes_pre_1960') {
                $h = $city['homes_by_decade'] ?? null;
                $raw = (is_array($h) && isset($h['Before 1960'])) ? $h['Before 1960'] : null;
            }
            if (!is_numeric($raw)) continue;
            $val = (float) $raw;
            if (isset($w['gt']) && !($val >  (float) $w['gt'])) continue;
            if (isset($w['lt']) && !($val <  (float) $w['lt'])) continue;
        }
        $s = mold_testing_local((string) $v['text'], $city);
        if ($s !== '') return $s;
    }
    return '';
}

/** Everything this page needs, or null when there is nothing citable to say. */
function mold_testing_resolve(string $override = ''): ?array {
    $d = mold_testing_data();
    if (!$d || empty($d['pages'])) return null;
    $key = $override !== '' && $override !== 'auto'
        ? $override : mold_testing_key_for(mold_testing_current_slug());
    $p = $d['pages'][$key] ?? null;
    if (!is_array($p) || empty($p['verbatim'])) return null;
    $srcs = $d['sources'] ?? [];
    $src = $srcs[$p['source'] ?? ''] ?? null;
    if (!is_array($src)) return null;                 // no citable source: no block

    $city = function_exists('city_chart_current_city') ? city_chart_current_city() : [];
    $local = $localSrc = '';
    if (!empty($p['local'])) {
        $local = mold_testing_pick_local($p['local'], $city);
        if ($local !== '') {
            $localSrc = trim((string) ($city[$p['local_source_field'] ?? ''] ?? ''));
        }
    }
    return [
        'key' => $key, 'label' => (string) ($p['label'] ?? $key),
        'heading' => (string) ($p['heading'] ?? ''),
        'lead' => mold_testing_quotes([['verbatim' => $p['verbatim'],
                                        'source' => $p['source']]], $srcs, $src),
        'also' => mold_testing_quotes($p['also'] ?? [], $srcs, $src),
        'context' => (string) ($p['context'] ?? ''),
        'context_also' => mold_testing_quotes($p['context_also'] ?? [], $srcs, $src),
        'local' => $local, 'local_source' => $localSrc,
        'intros' => (array) ($d['intros'] ?? []),
        'city' => (string) ($city['city'] ?? ''), 'SS' => (string) ($city['SS'] ?? ''),
    ];
}

/** Intro choice seeded on the business DOMAIN, never the page or city. */
function mold_testing_lane(string $domain, int $lanes = 3): int {
    $domain = strtolower(trim($domain));
    if ($domain === '' || strpos($domain, '{') !== false) return 0;
    return $lanes > 0 ? (int) (crc32($domain) % $lanes) : 0;
}

function mold_testing_css(): string {
    static $done = false;
    if ($done) return '';
    $done = true;
    return '<style>'
        . '.mt-wrap{margin:0 0 6px}'
        . '.mt-quotes{margin:14px 0 0;padding:14px 16px;background:#f8fafc;'
        . 'border-left:3px solid #fd783b;border-radius:0 8px 8px 0}'
        . '.mt-q{margin:0 0 8px;padding:0;border:0}'
        . '.mt-q:last-of-type{margin-bottom:0}'
        . '.mt-q p{margin:0;font-size:1.02rem;color:#1e3a5f;line-height:1.55}'
        . '.mt-sub p{font-size:.94rem;color:#334155}'
        . '.mt-cite{font-size:.8rem;font-style:normal}'
        . '.mt-ctx{margin:14px 0 0;color:#334155;line-height:1.6}'
        . '.mt-fine{margin:10px 0 0;padding:10px 14px;background:#fff;border:1px solid #e2e8f0;'
        . 'border-radius:8px}'
        . '.mt-fine .mt-q p{font-size:.92rem;color:#475569}'
        . '.mt-local{margin:14px 0 0;color:#334155;line-height:1.6}'
        . '.mt-local .mt-cite{display:block;margin-top:4px;color:#64748b}'
        . '</style>';
}

/** One quoted sentence with its own attribution. */
function mold_testing_quote(array $q, bool $lead): string {
    if (trim((string) $q['verbatim']) === '') return '';
    $url = (string) $q['url'];
    $out = '<blockquote class="mt-q' . ($lead ? '' : ' mt-sub') . '"'
         . ($url !== '' ? ' cite="' . h($url) . '"' : '') . '><p><q'
         . ($url !== '' ? ' cite="' . h($url) . '"' : '') . '>' . h((string) $q['verbatim'])
         . '</q>';
    if ($url !== '') {
        $out .= ' <cite class="mt-cite"><a href="' . h($url) . '" target="_blank"'
              . ' rel="noopener"'
              . ($q['label'] !== '' ? ' title="' . h((string) $q['label']) . '"' : '')
              . '>' . h((string) $q['short']) . '</a></cite>';
    }
    return $out . '</p></blockquote>';
}

/** Render, or '' when this page has nothing citable. */
function mold_testing_render(array $attrs = []): string {
    global $data;
    $r = mold_testing_resolve((string) ($attrs['page'] ?? ''));
    if ($r === null) return '';

    $host = parse_url((string) ($data['site_vars']['website'] ?? ''), PHP_URL_HOST)
        ?: (string) ($data['site_vars']['website'] ?? '');
    $intro = $r['intros']
        ? (string) $r['intros'][mold_testing_lane($host, count($r['intros']))] : '';

    // The content-block div MUST be first: blocks.php:788 only takes the raw path (skipping the
    // custom_html wrapper, injecting the anchor id) when the HTML starts with one. A leading
    // <style> breaks that and nests this inside .content-block, which is display:flex.
    $h  = '<div class="content-block block-mold-testing">';
    $h .= mold_testing_css();
    $h .= '<div class="container mt-wrap">';

    $head = $r['heading'] !== ''
        ? strtr($r['heading'], ['{city}' => $r['city'], '{SS}' => $r['SS']])
        : ('Mold testing in ' . $r['city'] . ', ' . $r['SS']);
    $h .= '<h2>' . h($head) . '</h2>';
    if ($intro !== '') $h .= '<p>' . h(resolve_shortcodes($intro)) . '</p>';

    // what it CAN do, then who should do it
    $h .= '<div class="mt-quotes">';
    foreach ($r['lead'] as $q) $h .= mold_testing_quote($q, true);
    foreach ($r['also'] as $q) $h .= mold_testing_quote($q, false);
    $h .= '</div>';

    // then our own framing, then the honest limits — present, but not the headline
    if ($r['context'] !== '') {
        $h .= '<p class="mt-ctx">' . h(resolve_shortcodes($r['context'])) . '</p>';
    }
    if ($r['context_also']) {
        $h .= '<div class="mt-fine">';
        foreach ($r['context_also'] as $q) $h .= mold_testing_quote($q, false);
        $h .= '</div>';
    }

    if ($r['local'] !== '') {
        $h .= '<p class="mt-local">' . h($r['local']);
        if ($r['local_source'] !== '') {
            $h .= '<span class="mt-cite">' . h('Source: ' . $r['local_source']) . '</span>';
        }
        $h .= '</p>';
    }

    $h .= '</div></div>';
    return $h;
}

/**
 * Replace the marker in a custom_html block. Untouched when the marker is absent; replaced
 * with '' when this page has nothing citable, so the marker never shows.
 */
add_hook('shortcode_content', function (string $html, string $pathPrefix = ''): string {
    if (strpos($html, 'mold_testing') === false) return $html;
    return preg_replace_callback(
        '/<!--\s*mold_testing([^>]*?)-->/i',
        function ($m) {
            $attrs = function_exists('_csm_parse_sc_attrs') ? _csm_parse_sc_attrs($m[1]) : [];
            return mold_testing_render($attrs);
        },
        $html
    );
});
