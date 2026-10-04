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

    return [
        'key' => $key,
        'label'  => $loc['label'] ?? $key,
        'phrase' => $loc['phrase'] ?? ('a ' . ($loc['label'] ?? $key)),
        'where'  => $loc['where']  ?? ('in a ' . ($loc['label'] ?? $key)),
        'note' => $loc['note'] ?? '',
        'banded' => $banded, 'always' => $always, 'kept' => $kept,
        'bands' => $d['bands'] ?? [], 'methods' => $d['methods'] ?? [], 'legend' => $legend,
        'intros' => $d['intros'] ?? [], 'universal' => $d['universal'] ?? [],
        // id => url, so a universal quote or a legend entry can cite itself
        'source_urls'   => array_map(fn($s) => $s['url'] ?? '', $d['sources'] ?? []),
        'source_shorts' => array_map(fn($s) => $s['short'] ?? 'EPA', $d['sources'] ?? []),
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
        . '.mm-src{font-size:.78rem}'
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
        . '@media(max-width:700px){'
        . '.mm-tbl thead{display:none}'
        . '.mm-tbl tr{display:block;border-bottom:1px solid #e2e8f0;padding:10px 0}'
        . '.mm-tbl td,.mm-tbl tbody th{display:block;border:0;padding:2px 0}'
        . '.mm-tbl td[data-b]:before{content:attr(data-b);display:block;font-size:.72rem;'
        . 'text-transform:uppercase;letter-spacing:.04em;color:#94a3b8;margin-top:6px}'
        . '}</style>';
}

/** One cited link, using the source's own short title rather than a bare "EPA". */
function mold_materials_cite(string $url, string $short): string {
    if ($url === '') return '';
    return ' <a class="mm-src" href="' . h($url) . '" target="_blank" rel="noopener">'
        . h($short !== '' ? $short : 'EPA') . '</a>';
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
                $h .= '<span class="mm-as mm-src">'
                    . trim(mold_materials_cite($row['source_url'], $row['source_short'])) . '</span>';
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
                . '<dd>&ldquo;' . h((string) ($m['detail'] ?? '')) . '&rdquo;'
                . mold_materials_cite($su, $r['source_shorts'][$m['source'] ?? ''] ?? 'EPA')
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
                . '&ldquo;' . h($row['verbatim']) . '&rdquo;'
                . mold_materials_cite($row['source_url'], $row['source_short']);
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
            $h .= '<li><em>&ldquo;' . h($u['verbatim']) . '&rdquo;</em>'
                . mold_materials_cite($r['source_urls'][$u['source'] ?? ''] ?? '',
                                      $r['source_shorts'][$u['source'] ?? ''] ?? 'EPA')
                . '</li>';
        }
        $h .= '</ul>';
    }

    if ($r['note'] !== '') {
        $h .= '<p class="mm-note">' . h(resolve_shortcodes($r['note'])) . '</p>';
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
