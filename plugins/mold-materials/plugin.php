<?php
/**
 * Mold Materials — "what gets cleaned, and what comes out", per page location.
 *
 * The mold counterpart to the appliance error_codes block, and it works the same way:
 * every row traces to a public EPA page, so a claim this block makes can always be
 * checked, and one it cannot source it simply does not make.
 *
 * WHY A SHORTCODE RATHER THAN A BLOCK TYPE
 * A new block type needs seven touch points across core (four labels/description/icon
 * entries in blocks.php, the render case, blocks_from_post.php to save it, editor.php
 * for its fields). This needs none of them, because there is nothing to configure: the
 * location comes from the page's own slug. One hook on 'shortcode_content' — the same
 * one services_links uses — and the whole feature lives in this directory. Delete the
 * directory and it is gone.
 *
 * WHY AN HTML COMMENT MARKER, NOT [mold_materials]
 * blocks.php:785 passes a custom_html block's HTML through the hook and leaves it
 * unchanged if nothing matches. A bracket tag would therefore print as visible junk on
 * every page if this plugin were ever removed or renamed; an HTML comment renders as
 * nothing. Less idiomatic, but it fails invisibly, which matters across ~1,400 pages.
 *
 * WHAT IT WILL NEVER DO
 *  - Invent a row. If EPA does not cover a material it gets no row; a four-row table is
 *    correct and an invented fifth is not.
 *  - Paraphrase a quote. `verbatim` strings and method `detail` text are reproduced as
 *    written, because the citation is worthless if the words have been reworded.
 *  - Drop rows to manufacture variety. Hiding a material would hide the very thing a
 *    visitor searched for. Only OUR prose varies (one of three intros, chosen by domain);
 *    which rows appear never does.
 *  - Make a health claim. It describes building materials, not health effects.
 */

if (!function_exists('register_plugin')) return;   // not loaded outside the factory

register_plugin(
    'mold_materials',
    'Mold Materials',
    'Adds a per-location table of what gets cleaned in place versus what has to be removed, '
    . 'taken from the EPA\'s own published remediation guidance and shown across all three of '
    . 'its affected-area bands (under 10 sq ft, 10-100, over 100). Place '
    . '<code>&lt;!--mold_materials--&gt;</code> inside a Custom HTML block; the location is '
    . 'derived from the page\'s slug, so nothing needs configuring. Every row links to the EPA '
    . 'page that supports it. A page whose location is not in '
    . 'plugins/mold-materials/materials.json renders nothing at all — never a guess. Data and '
    . 'sources live in that one file and can be reviewed there.',
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

    $banded = [];   // rows with EPA's per-area methods
    $always = [];   // rows whose guidance is a flat rule, no area dimension
    foreach ($loc['rows'] as $row) {
        $mid = $row[0] ?? '';
        $as  = $row[1] ?? '';
        $m   = $d['materials'][$mid] ?? null;
        if (!is_array($m)) continue;                    // unknown material: skipped, not guessed
        $srcKey = $m['source'] ?? '';
        $src    = $d['sources'][$srcKey] ?? null;
        if (!is_array($src)) continue;                  // no citable source: no row
        $entry = ['id' => $mid, 'label' => $m['label'] ?? $mid, 'as' => $as,
                  'source_url' => $src['url'] ?? '', 'source_label' => $src['label'] ?? ''];
        if (!empty($m['methods'])) {
            $entry['methods'] = $m['methods'];
            $banded[] = $entry;
        } elseif (!empty($m['verbatim'])) {
            $entry['verbatim'] = $m['verbatim'];
            $always[] = $entry;
        }
    }
    if (!$banded && !$always) return null;

    return [
        'key' => $key, 'label' => $loc['label'] ?? $key, 'note' => $loc['note'] ?? '',
        'banded' => $banded, 'always' => $always,
        'bands' => $d['bands'] ?? [], 'methods' => $d['methods'] ?? [],
        'intros' => $d['intros'] ?? [], 'universal' => $d['universal'] ?? [],
        // id => url, so a universal quote can cite itself without re-reading the file
        'source_urls' => array_map(fn($s) => $s['url'] ?? '', $d['sources'] ?? []),
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
        . '.mm-tbl th{font-size:.78rem;text-transform:uppercase;letter-spacing:.04em;color:#64748b;white-space:nowrap}'
        . '.mm-mat{font-weight:700;color:#1e3a5f;font-size:1.02rem}'
        . '.mm-as{display:block;font-weight:400;color:#475569;font-size:.88rem;margin-top:2px}'
        . '.mm-m{display:block;font-size:.86rem;color:#334155;line-height:1.5}'
        . '.mm-rm{color:#991b1b;font-weight:700}'
        . '.mm-src{font-size:.78rem}'
        . '.mm-always{margin:14px 0 0;padding:12px 14px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px}'
        . '.mm-always h3{margin:0 0 8px;font-size:.92rem;color:#1e3a5f}'
        . '.mm-always li{margin-bottom:8px;font-size:.9rem;line-height:1.55}'
        . '.mm-note{margin:14px 0 0;color:#334155}'
        . '@media(max-width:700px){'
        . '.mm-tbl thead{display:none}'
        . '.mm-tbl tr{display:block;border-bottom:1px solid #e2e8f0;padding:10px 0}'
        . '.mm-tbl td{display:block;border:0;padding:2px 0}'
        . '.mm-tbl td[data-b]:before{content:attr(data-b);display:block;font-size:.72rem;'
        . 'text-transform:uppercase;letter-spacing:.04em;color:#94a3b8;margin-top:6px}'
        . '}</style>';
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
        $intro = str_replace('{location}', $r['label'], $intro);
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
    $h .= '<h2>' . h('What gets cleaned, and what comes out') . '</h2>';
    if ($intro !== '') $h .= '<p>' . h(resolve_shortcodes($intro)) . '</p>';

    if ($r['banded']) {
        $h .= '<table class="mm-tbl"><thead><tr><th>Material</th>';
        foreach ($bandKeys as $b) {
            $h .= '<th>' . h($r['bands'][$b]['label'] ?? $b) . '</th>';
        }
        $h .= '</tr></thead><tbody>';
        foreach ($r['banded'] as $row) {
            $h .= '<tr><td><span class="mm-mat">' . h($row['label']) . '</span>'
                . '<span class="mm-as">' . h($row['as']) . '</span>';
            if ($row['source_url'] !== '') {
                $h .= '<span class="mm-as mm-src"><a href="' . h($row['source_url'])
                    . '" target="_blank" rel="noopener">' . h('Source: EPA') . '</a></span>';
            }
            $h .= '</td>';
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

    if ($r['always']) {
        $h .= '<div class="mm-always"><h3>'
            . h('Removed and replaced regardless of how large the area is') . '</h3><ul>';
        foreach ($r['always'] as $row) {
            $h .= '<li><strong>' . h($row['label']) . '</strong> — ' . h($row['as']) . '. '
                . '&ldquo;' . h($row['verbatim']) . '&rdquo;';
            if ($row['source_url'] !== '') {
                $h .= ' <a class="mm-src" href="' . h($row['source_url'])
                    . '" target="_blank" rel="noopener">' . h('EPA') . '</a>';
            }
            $h .= '</li>';
        }
        $h .= '</ul></div>';
    }

    // The 24-hour line applies to every location, so it is rendered once here rather
    // than duplicated into each location's rows. It carries its own citation for the
    // same reason every other row does.
    if (!empty($r['universal']['wet_window']['verbatim'])) {
        $u  = $r['universal']['wet_window'];
        $su = $r['source_urls'][$u['source'] ?? ''] ?? '';
        $h .= '<p class="mm-note"><em>&ldquo;' . h($u['verbatim']) . '&rdquo;</em>'
            . ($su !== ''
                ? ' <a class="mm-src" href="' . h($su) . '" target="_blank" rel="noopener">'
                  . h('EPA') . '</a>'
                : '')
            . '</p>';
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
