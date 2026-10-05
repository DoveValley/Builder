<?php
/**
 * Mold Materials — "what gets cleaned, and what comes out", per page location.
 *
 * The mold counterpart to the appliance error_codes block: every row traces to a public
 * EPA page, so a claim this block makes can always be checked, and one it cannot source
 * it simply does not make.
 *
 * WHY A SHORTCODE RATHER THAN A BLOCK TYPE
 * A new block type needs seven touch points across core (four labels/description/icon
 * entries in blocks.php, the render case, blocks_from_post.php to save it, editor.php
 * for its fields). This needs none of them, because there is nothing to configure: the
 * location comes from the page's own slug. One hook on 'shortcode_content' — the same
 * one services_links uses — and the whole feature lives in this directory. Delete the
 * directory and it is gone. (error_codes is a CORE block type, not this mechanism; it is
 * the precedent for the index-2 placement, not for how this is wired.)
 *
 * WHY AN HTML COMMENT MARKER, NOT [mold_materials]
 * blocks.php:785 passes a custom_html block's HTML through the hook and leaves it
 * unchanged if nothing matches. A bracket tag would therefore print as visible junk on
 * every page if this plugin were ever removed or renamed; an HTML comment renders as
 * nothing. Less idiomatic, but it fails invisibly, which matters across ~1,400 pages.
 *
 * THREE BUCKETS, AND WHY
 * A material either carries EPA's per-area methods (goes in the table), or carries a
 * flat-rule quote. The flat-rule ones split by `always_removed`:
 *   always_removed: true  -> "Removed and replaced, whatever the area"
 *   (absent)              -> "Cleaned in place, not replaced"
 * The original build had only two buckets and sent EVERY quoted material to the removal
 * box. That put duct_metal there, asserting sheet-metal ducts must be ripped out when
 * EPA lists that quote as a condition for duct CLEANING and says bare sheet metal can be
 * cleaned and treated — the opposite of the source, and a contradiction of our own note
 * two paragraphs below it. Hence the flag, and hence `context` being rendered.
 *
 * WHAT IT WILL NEVER DO
 *  - Invent a row. If EPA does not cover a material it gets no row; a four-row table is
 *    correct and an invented fifth is not.
 *  - Cite something the reader cannot check. The ceiling-tile rule was originally quoted
 *    from EPA's flood PDF, which is a scanned image with no text layer — unverifiable, so
 *    it was re-sourced to Table 1, which states "Discard and replace." in readable HTML.
 *  - Paraphrase a quote. `verbatim` strings and method `detail` text are reproduced as
 *    written, because the citation is worthless if the words have been reworded.
 *  - Drop rows to manufacture variety. Hiding a material would hide the very thing a
 *    visitor searched for. Only OUR prose varies (one of three intros, chosen by domain);
 *    which rows appear never does.
 *  - Make a health claim. It describes building materials, not health effects.
 *  - State EPA's guidance without its own scope caveat: the tables are for CLEAN water,
 *    and that caveat is rendered, quoted, on every page.
 */

if (!function_exists('register_plugin')) return;   // not loaded outside the factory

register_plugin(
    'mold_materials',
    'Mold Materials',
    'Adds a per-location table of what gets cleaned in place versus what has to be removed, '
    . 'taken from the EPA\'s own published remediation guidance and shown across all three of '
    . 'its affected-area bands (under 10 sq ft, 10-100, over 100), with each band labelled in '
    . 'plain terms ("about a cabinet door"). Under the table it prints EPA\'s verbatim '
    . 'definitions of the cleanup methods it names, so "HEPA vacuum" is not left as jargon, '
    . 'then the materials EPA removes whatever the area, the ones it cleans in place, and '
    . 'EPA\'s own clean-water scope caveat. Place <code>&lt;!--mold_materials--&gt;</code> '
    . 'inside a Custom HTML block; the location is derived from the page\'s slug, so nothing '
    . 'needs configuring. Every quote links to the EPA page that supports it, and a source '
    . 'must be readable HTML — a scanned PDF is not citable here. A material whose quote '
    . 'could be read as saying more than EPA said carries a <code>context</code> line that is '
    . 'rendered with it. A page whose location is not in plugins/mold-materials/materials.json '
    . 'renders nothing at all — never a guess. Data and sources live in that one file and can '
    . 'be reviewed there.',
    '&#129529;',   // 🧹
    __DIR__
);

/** The dataset, decoded once per request. A malformed file is skipped, never fatal. */
function mold_materials_data(): array {
    static $d = null;
    if ($d !== null) return $d;
    $f = __DIR__ . '/materials.json';
    $raw = is_file($f) ? (string) @file_get_contents($f) : '';
    $j = $raw !== '' ? json_decode($raw, true) : null;
    $d = is_array($j) ? $j : [];
    return $d;
}

/**
 * This page's slug with the city suffix removed, so a location can be matched.
 * Same approach as error_codes_current_slug().
 */
function mold_materials_current_slug(): string {
    global $slug, $data;
    $base = (string) ($slug ?? '');
    $city = $data['site_vars']['city_slug'] ?? '';
    if ($city !== '') $base = preg_replace('/-' . preg_quote($city, '/') . '$/', '', $base);
    return trim((string) $base, '-');
}

/**
 * Which location key a slug belongs to, or '' when none.
 *
 * Longest match wins, not first: "air-conditioner-mold-removal" must not be claimed by
 * a shorter key that happens to be a prefix of it. Same trap page_pool.php:231 documents.
 */
function mold_materials_location_for(string $slug): string {
    $d = mold_materials_data();
    $best = '';
    foreach (array_keys($d['locations'] ?? []) as $key) {
        if ($slug === $key || str_starts_with($slug, $key . '-')) {
            if (strlen($key) > strlen($best)) $best = $key;
        }
    }
    return $best;
}

/** Trim a float for display: 24.0 -> "24", 6.6 -> "6.6". */
function mold_materials_num($v): string {
    if (!is_numeric($v)) return '';
    $f = (float) $v;
    return $f == (int) $f ? (string) (int) $f : (string) round($f, 1);
}

/**
 * Display strings and a numeric map, derived from one city record.
 *
 * Kept in one place because the per-city paragraph is the entire anti-fingerprint mechanism:
 * a token that silently resolves to nothing voids the sentence, and a voided sentence means
 * this page falls back to text another city also shows.
 */
function mold_materials_tokens(array $city): array {
    $mon = ['January', 'February', 'March', 'April', 'May', 'June', 'July',
            'August', 'September', 'October', 'November', 'December'];
    $agg = function (string $field) use ($city, $mon) {
        $a = $city[$field] ?? null;
        if (!is_array($a) || count($a) !== 12) return [null, null, null];
        $v = array_map('floatval', array_values($a));
        $i = array_search(max($v), $v, true);
        return [array_sum($v), max($v), $mon[$i] ?? null];
    };
    [$wdY, $wdP, $wdM] = $agg('wet_days_monthly');
    [$hrY, ,     $hrM] = $agg('heavy_rain_days_monthly');
    [$fzY, ,     $fzM] = $agg('freeze_days_monthly');
    [$htY, $htP, $htM] = $agg('hot_days_monthly');
    [$rnY, ,     ]     = $agg('rainfall_monthly');

    $pre = $p2010 = null;
    $h = $city['homes_by_decade'] ?? null;
    if (is_array($h)) {
        if (isset($h['Before 1960']) && is_numeric($h['Before 1960']))     $pre   = (float) $h['Before 1960'];
        if (isset($h['2010 or later']) && is_numeric($h['2010 or later'])) $p2010 = (float) $h['2010 or later'];
    }
    $peakDecade = null;
    $fd = $city['flood_events_by_decade'] ?? null;
    if (is_array($fd) && $fd) { arsort($fd); $peakDecade = (string) array_key_first($fd); }
    $pct = fn($v) => $v === null ? '' : mold_materials_num($v) . '%';

    return [[
        '{city}'                    => (string) ($city['city'] ?? ''),
        '{SS}'                      => (string) ($city['SS'] ?? ''),
        '{state}'                   => (string) ($city['state'] ?? ''),
        '{wet_days_year}'           => mold_materials_num($wdY),
        '{wet_days_peak}'           => mold_materials_num($wdP),
        '{wet_days_peak_month}'     => (string) ($wdM ?? ''),
        '{rainfall_year}'           => mold_materials_num($rnY),
        '{heavy_rain_year}'         => mold_materials_num($hrY),
        '{heavy_rain_peak_month}'   => (string) ($hrM ?? ''),
        '{freeze_nights_year}'      => mold_materials_num($fzY),
        '{freeze_peak_month}'       => (string) ($fzM ?? ''),
        '{hot_days_year}'           => mold_materials_num($htY),
        '{hot_days_peak}'           => mold_materials_num($htP),
        '{hot_days_peak_month}'     => (string) ($htM ?? ''),
        '{homes_pre_1960}'          => $pct($pre),
        '{homes_2010_plus}'         => $pct($p2010),
        '{flood_county}'            => (string) ($city['flood_county'] ?? ''),
        '{flood_years_with_events}' => (string) ($city['flood_years_with_events'] ?? ''),
        '{flood_most_recent}'       => (string) ($city['flood_most_recent'] ?? ''),
        '{flood_peak_decade}'       => (string) ($peakDecade ?? ''),
    ], [
        'wet_days_year'           => $wdY,
        'heavy_rain_year'         => $hrY,
        'freeze_nights_year'      => $fzY,
        'hot_days_year'           => $htY,
        'homes_pre_1960_num'      => $pre,
        'flood_years_with_events' => $city['flood_years_with_events'] ?? null,
    ]];
}

/**
 * The per-city paragraph for this location's metric.
 *
 * Band chosen by the city's own figures; PHRASING chosen by crc32 of the city slug, so two
 * cities in the same band get different sentence structures rather than one skeleton with
 * different numbers. A band whose `when` field is missing or non-numeric is skipped rather
 * than treated as matching, and a phrasing with an unresolvable token falls through.
 */
function mold_materials_pick_local(string $metric, array $city, string $loc = ''): string {
    $d = mold_materials_data();
    $bank = $d['local'][$metric] ?? null;
    if (!is_array($bank)) return '';
    [$str, $num] = mold_materials_tokens($city);
    // Seeded on city AND location. On the city alone, every location sharing a metric showed
    // the identical paragraph — basement, carpet and crawl space all use flood history, so one
    // site got 6 distinct paragraphs across 14 pages instead of 14. Including the location
    // keeps it deterministic and still lets two sites covering the same city agree with each
    // other, which they should: the facts are the same.
    $slug = (string) ($city['city_slug'] ?? ($city['city'] ?? '')) . '|' . $loc;

    foreach ($bank as $band) {
        if (!is_array($band)) continue;
        $w = $band['when'] ?? null;
        if (is_array($w) && !empty($w['field'])) {
            $v = $num[$w['field']] ?? null;
            if (!is_numeric($v)) continue;
            $v = (float) $v;
            if (isset($w['gt']) && !($v >  (float) $w['gt'])) continue;
            if (isset($w['lt']) && !($v <  (float) $w['lt'])) continue;
        }
        $texts = $band['texts'] ?? (isset($band['text']) ? [$band['text']] : []);
        $texts = array_values(array_filter((array) $texts, fn($t) => trim((string) $t) !== ''));
        if (!$texts) continue;
        // city-seeded, so the skeleton differs between cities and is stable for one city
        $start = $slug !== '' ? (int) (crc32(strtolower($slug)) % count($texts)) : 0;
        for ($i = 0; $i < count($texts); $i++) {
            $tpl = (string) $texts[($start + $i) % count($texts)];
            $out = strtr($tpl, $str);
            if (preg_match('/\{[a-z_0-9]+\}/', $out)) continue;
            $empty = false;
            foreach ($str as $tok => $sv) {
                if (strpos($tpl, $tok) !== false && trim((string) $sv) === '') $empty = true;
            }
            if (!$empty) return $out;
        }
    }
    return '';
}

/**
 * Everything the block needs for one location, or NULL when there is nothing to show.
 * Null is the normal case on a non-mold page, which is why no caller needs a guard.
 */
function mold_materials_resolve(string $locationOverride = '', string $slugOverride = ''): ?array {
    $d = mold_materials_data();
    if (!$d || empty($d['locations'])) return null;

    $key = $locationOverride !== '' && $locationOverride !== 'auto'
        ? $locationOverride
        : mold_materials_location_for($slugOverride !== '' ? $slugOverride : mold_materials_current_slug());
    $loc = $d['locations'][$key] ?? null;
    if (!is_array($loc) || empty($loc['rows'])) return null;

    $banded = [];   // rows carrying EPA's per-area methods -> the table
    $always = [];   // flat rule, always_removed: true      -> "removed whatever the area"
    $kept   = [];   // flat rule, no such flag              -> "cleaned in place"
    $used   = [];   // which method numbers this page actually names, for the legend
    foreach ($loc['rows'] as $row) {
        $mid = $row[0] ?? '';
        $as  = $row[1] ?? '';
        $m   = $d['materials'][$mid] ?? null;
        if (!is_array($m)) continue;                    // unknown material: skipped, not guessed
        $srcKey = $m['source'] ?? '';
        $src    = $d['sources'][$srcKey] ?? null;
        if (!is_array($src)) continue;                  // no citable source: no row
        $entry = [
            'id' => $mid, 'label' => $m['label'] ?? $mid, 'as' => $as,
            'context' => (string) ($m['context'] ?? ''),
            'source_url' => $src['url'] ?? '',
            'source_short' => $src['short'] ?? 'EPA',
            'source_label' => $src['label'] ?? '',
        ];
        if (!empty($m['methods'])) {
            $entry['methods'] = $m['methods'];
            foreach ($m['methods'] as $ns) {
                foreach ((array) $ns as $n) $used[(string) $n] = true;
            }
            $banded[] = $entry;
        } elseif (!empty($m['verbatim'])) {
            $entry['verbatim'] = $m['verbatim'];
            if (!empty($m['always_removed'])) $always[] = $entry;
            else                              $kept[]   = $entry;
        }
    }
    if (!$banded && !$always && !$kept) return null;

    // legend holds only the methods this page's table actually names, in numeric order
    $legend = [];
    foreach ($d['methods'] ?? [] as $n => $m) {
        if (isset($used[(string) $n])) $legend[(string) $n] = $m;
    }
    ksort($legend, SORT_NUMERIC);

    $city = function_exists('city_chart_current_city') ? city_chart_current_city() : [];
    $metric = (string) ($loc['metric'] ?? '');
    $local = $metric !== '' ? mold_materials_pick_local($metric, $city, $key) : '';
    $localSrc = [];
    if ($local !== '') {
        foreach ((array) ($d['local_sources'][$metric] ?? []) as $f) {
            $v = trim((string) ($city[$f] ?? ''));
            if ($v !== '' && !in_array($v, $localSrc, true)) $localSrc[] = $v;
        }
    }

    return [
        'key' => $key,
        'label'  => $loc['label'] ?? $key,
        'metric' => $metric, 'local' => $local, 'local_sources' => $localSrc,
        'phrase' => $loc['phrase'] ?? ('a ' . ($loc['label'] ?? $key)),
        'where'  => $loc['where']  ?? ('in a ' . ($loc['label'] ?? $key)),
        'note' => $loc['note'] ?? '',
        'banded' => $banded, 'always' => $always, 'kept' => $kept,
        'bands' => $d['bands'] ?? [], 'methods' => $d['methods'] ?? [], 'legend' => $legend,
        'intros' => $d['intros'] ?? [], 'universal' => $d['universal'] ?? [],
        // id => url, so a universal quote or a legend entry can cite itself
        'source_urls'   => array_map(fn($s) => $s['url'] ?? '', $d['sources'] ?? []),
        'source_shorts' => array_map(fn($s) => $s['short'] ?? 'EPA', $d['sources'] ?? []),
        // full document titles, for the link title attribute
        'source_labels' => array_map(fn($s) => $s['label'] ?? '', $d['sources'] ?? []),
    ];
}

/**
 * Which intro to use, keyed off the business domain.
 *
 * Seeded on the DOMAIN, never the page or the city: two pages on one site must not
 * describe the same guidance in different voices. Mirrors error_codes_lane().
 */
function mold_materials_lane(string $domain, int $lanes = 3): int {
    $domain = strtolower(trim($domain));
    if ($domain === '' || strpos($domain, '{') !== false) return 0;   // unresolved token
    return $lanes > 0 ? (int) (crc32($domain) % $lanes) : 0;
}

/** Scoped CSS, emitted once per page. Plugins here ship no stylesheet, so it rides along. */
function mold_materials_css(): string {
    static $done = false;
    if ($done) return '';
    $done = true;
    return '<style>'
        . '.mm-wrap{margin:0 0 6px}'
        . '.mm-tbl{width:100%;border-collapse:collapse;font-size:.95rem}'
        . '.mm-tbl th,.mm-tbl td{padding:10px 8px;border-bottom:1px solid #e2e8f0;text-align:left;vertical-align:top}'
        // thead only: the row headers in tbody must NOT inherit the small-caps treatment
        . '.mm-tbl thead th{font-size:.78rem;text-transform:uppercase;letter-spacing:.04em;color:#64748b}'
        . '.mm-tbl tbody th{font-weight:400}'
        . '.mm-cap{caption-side:top;text-align:left;font-size:.86rem;color:#64748b;padding:0 0 8px}'
        . '.mm-ab{display:block;margin-top:3px;font-size:.72rem;text-transform:none;letter-spacing:0;color:#94a3b8;font-weight:400}'
        . '.mm-mat{display:block;font-weight:700;color:#1e3a5f;font-size:1.02rem}'
        . '.mm-as{display:block;font-weight:400;color:#475569;font-size:.88rem;margin-top:2px}'
        . '.mm-m{display:block;font-size:.86rem;color:#334155;line-height:1.5}'
        . '.mm-rm{color:#991b1b;font-weight:700}'
        . '.mm-src{font-size:.78rem;font-style:normal}'
        . '.mm-leg{margin:12px 0 0;padding:12px 14px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px}'
        . '.mm-leg h3,.mm-box h3{margin:0 0 8px;font-size:.92rem;color:#1e3a5f}'
        . '.mm-leg dt{font-weight:700;color:#1e3a5f;font-size:.88rem;margin-top:6px}'
        . '.mm-leg dd{margin:2px 0 0;font-size:.88rem;color:#334155;line-height:1.55}'
        . '.mm-box{margin:14px 0 0;padding:12px 14px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px}'
        . '.mm-box li{margin-bottom:10px;font-size:.9rem;line-height:1.55}'
        . '.mm-ctx{display:block;margin-top:3px;color:#475569;font-size:.86rem}'
        . '.mm-assume{margin:14px 0 0;padding:0;list-style:none}'
        . '.mm-assume li{margin-bottom:6px;color:#475569;font-size:.88rem;line-height:1.55}'
        . '.mm-note{margin:14px 0 0;color:#334155}'
        . '.mm-local{padding-top:10px;border-top:1px solid #e2e8f0}'
        . '@media(max-width:700px){'
        . '.mm-tbl thead{display:none}'
        . '.mm-tbl tr{display:block;border-bottom:1px solid #e2e8f0;padding:10px 0}'
        . '.mm-tbl td,.mm-tbl tbody th{display:block;border:0;padding:2px 0}'
        . '.mm-tbl td[data-b]:before{content:attr(data-b);display:block;font-size:.72rem;'
        . 'text-transform:uppercase;letter-spacing:.04em;color:#94a3b8;margin-top:6px}'
        . '}</style>';
}

/**
 * One cited link, using the source's own short title rather than a bare "EPA".
 *
 * Wrapped in <cite>, which is the element for the title of a referenced work, with the full
 * document title on the link's title attribute so the visible text stays short. Neither is a
 * ranking factor — Google ignores the cite attribute and no rich result reads <cite>. The
 * signal is the followed .gov link itself; this is correct markup around it.
 */
function mold_materials_cite(string $url, string $short, string $label = ''): string {
    if ($url === '') return '';
    return ' <cite class="mm-src"><a href="' . h($url) . '" target="_blank" rel="noopener"'
        . ($label !== '' ? ' title="' . h($label) . '"' : '')
        . '>' . h($short !== '' ? $short : 'EPA') . '</a></cite>';
}

/**
 * An inline quotation with its source.
 *
 * <q> rather than <blockquote> because every EPA quote here sits inside an <li> or <dd>
 * alongside our own prose, not as a standalone block. The manual &ldquo;/&rdquo; entities are
 * deliberately NOT emitted: the browser supplies quotation marks for <q>, and doing both
 * produces doubled quotes.
 */
function mold_materials_q(string $text, string $url): string {
    if (trim($text) === '') return '';
    return '<q' . ($url !== '' ? ' cite="' . h($url) . '"' : '') . '>' . h($text) . '</q>';
}

/** Render the block, or '' when this page has nothing to show. */
function mold_materials_render(array $attrs = []): string {
    global $data;
    $r = mold_materials_resolve((string) ($attrs['location'] ?? ''));
    if ($r === null) return '';

    $domain = (string) ($data['site_vars']['website'] ?? '');
    $host   = parse_url($domain, PHP_URL_HOST) ?: $domain;
    $intro  = '';
    if ($r['intros']) {
        $intro = (string) $r['intros'][mold_materials_lane($host, count($r['intros']))];
        // {phrase} = "an attic"; {where} = "in an attic". Two fields rather than one so the
        // article is never assembled in code: "a attic" shipped on three intros before this.
        $intro = str_replace(['{phrase}', '{where}', '{location}'],
                             [$r['phrase'], $r['where'], $r['label']], $intro);
    }
    $bandKeys = array_keys($r['bands']);

    // The content-block div MUST be the first thing in the string. blocks.php:788 only
    // takes the raw path (skipping the custom_html wrapper, and injecting our anchor id)
    // when the HTML *starts* with a content-block div. A leading <style> broke that match,
    // so this block got nested inside .content-block -- which is `display:flex; gap:40px`
    // (style.src.css:202), making us a flex ITEM instead of a full-width section.
    $h  = '<div class="content-block block-mold-materials">';
    $h .= mold_materials_css();
    $h .= '<div class="container mm-wrap">';
    // The location goes in the h2: without it this heading was byte-identical on all 14
    // pages of all 100 sites, which wastes the one heading Google weights most.
    $h .= '<h2>' . h('What gets cleaned ' . $r['where'] . ', and what comes out') . '</h2>';
    if ($intro !== '') $h .= '<p>' . h(resolve_shortcodes($intro)) . '</p>';

    if ($r['banded']) {
        $h .= '<table class="mm-tbl"><caption class="mm-cap">'
            . h('EPA\'s published guidance for the materials usually found ' . $r['where']
                . ', by the size of the affected area.')
            . '</caption><thead><tr><th scope="col">Material</th>';
        foreach ($bandKeys as $b) {
            $aside = (string) ($r['bands'][$b]['aside'] ?? '');
            $h .= '<th scope="col">' . h($r['bands'][$b]['label'] ?? $b)
                . ($aside !== '' ? '<span class="mm-ab">' . h($aside) . '</span>' : '')
                . '</th>';
        }
        $h .= '</tr></thead><tbody>';
        foreach ($r['banded'] as $row) {
            // the material is the row's header, not a data cell
            $h .= '<tr><th scope="row"><span class="mm-mat">' . h($row['label']) . '</span>'
                . '<span class="mm-as">' . h($row['as']) . '</span>';
            if ($row['source_url'] !== '') {
                $h .= '<span class="mm-as">'
                    . trim(mold_materials_cite($row['source_url'], $row['source_short'],
                                               (string) ($row['source_label'] ?? ''))) . '</span>';
            }
            $h .= '</th>';
            foreach ($bandKeys as $b) {
                $label = h($r['bands'][$b]['label'] ?? $b);
                $h .= '<td data-b="' . $label . '">';
                foreach ((array) ($row['methods'][$b] ?? []) as $n) {
                    $m = $r['methods'][(string) $n] ?? null;
                    if (!$m) continue;
                    $cls = ((string) $n === '4') ? ' mm-rm' : '';
                    $h .= '<span class="mm-m' . $cls . '">' . h($m['label']) . '</span>';
                }
                $h .= '</td>';
            }
            $h .= '</tr>';
        }
        $h .= '</tbody></table>';
    }

    // EPA's own definitions of the methods named above. Without this the table reads as
    // jargon -- "HEPA vacuum" tells a homeowner nothing, and Method 4 quietly means their
    // wall leaves in a bag. Only the methods this page actually names are listed.
    if ($r['legend']) {
        $h .= '<div class="mm-leg"><h3>' . h('What those methods mean, in EPA\'s words') . '</h3><dl>';
        foreach ($r['legend'] as $n => $m) {
            $cls = ((string) $n === '4') ? ' class="mm-rm"' : '';
            $su  = $r['source_urls'][$m['source'] ?? ''] ?? '';
            $h .= '<dt' . $cls . '>' . h($m['label']) . '</dt>'
                . '<dd>' . mold_materials_q((string) ($m['detail'] ?? ''), $su)
                . mold_materials_cite($su, $r['source_shorts'][$m['source'] ?? ''] ?? 'EPA',
                                      $r['source_labels'][$m['source'] ?? ''] ?? '')
                . '</dd>';
        }
        $h .= '</dl></div>';
    }

    // Cleaned in place: a quoted material EPA does NOT say to replace. Rendering this
    // separately is the fix for the original bug -- duct_metal was being shown under
    // "removed and replaced", which is the opposite of what its source says.
    foreach ([
        ['kept',   'Cleaned in place, not replaced'],
        ['always', 'Removed and replaced, whatever the area'],
    ] as [$bucket, $heading]) {
        if (!$r[$bucket]) continue;
        $h .= '<div class="mm-box"><h3>' . h($heading) . '</h3><ul>';
        foreach ($r[$bucket] as $row) {
            $h .= '<li><strong>' . h($row['label']) . '</strong> — ' . h($row['as']) . '. '
                . mold_materials_q((string) $row['verbatim'], (string) $row['source_url'])
                . mold_materials_cite($row['source_url'], $row['source_short'],
                                      (string) ($row['source_label'] ?? ''));
            if ($row['context'] !== '') {
                $h .= '<span class="mm-ctx">' . h($row['context']) . '</span>';
            }
            $h .= '</li>';
        }
        $h .= '</ul></div>';
    }

    // Scope and limits, in EPA's words: the clean-water caveat (the tables do not cover
    // sewage or chemically contaminated water), why porous materials come out, and the
    // 24-hour window. These apply to every location, so they are rendered once here
    // rather than duplicated into each location's rows.
    if ($r['universal']) {
        $h .= '<ul class="mm-assume">';
        foreach ($r['universal'] as $u) {
            if (empty($u['verbatim'])) continue;
            $h .= '<li><em>'
                . mold_materials_q((string) $u['verbatim'],
                                   $r['source_urls'][$u['source'] ?? ''] ?? '')
                . '</em>'
                . mold_materials_cite($r['source_urls'][$u['source'] ?? ''] ?? '',
                                      $r['source_shorts'][$u['source'] ?? ''] ?? 'EPA',
                                      $r['source_labels'][$u['source'] ?? ''] ?? '')
                . '</li>';
        }
        $h .= '</ul>';
    }

    if ($r['note'] !== '') {
        $h .= '<p class="mm-note">' . h(resolve_shortcodes($r['note'])) . '</p>';
    }

    // The per-city paragraph — the only part of this block that differs between sites. The EPA
    // rows are identical fleet-wide because they are quotes, and the note above is written per
    // LOCATION, so until this existed 14 pages x 100 sites were byte-identical.
    if ($r['local'] !== '') {
        $h .= '<p class="mm-note mm-local">' . h($r['local']);
        if ($r['local_sources']) {
            $h .= '<span class="mm-as mm-src">'
                . h('Source: ' . implode(' · ', $r['local_sources'])) . '</span>';
        }
        $h .= '</p>';
    }

    $h .= '</div></div>';
    return $h;
}

/**
 * Replace the marker in a custom_html block with the rendered table.
 * Returns the HTML untouched when the marker is absent, so every other block is
 * unaffected; replaces with '' when this page has no data, so the marker never shows.
 */
add_hook('shortcode_content', function (string $html, string $pathPrefix = ''): string {
    if (strpos($html, 'mold_materials') === false) return $html;
    return preg_replace_callback(
        '/<!--\s*mold_materials([^>]*?)-->/i',
        function ($m) {
            $attrs = function_exists('_csm_parse_sc_attrs') ? _csm_parse_sc_attrs($m[1]) : [];
            return mold_materials_render($attrs);
        },
        $html
    );
});
