<?php
// Landing Links: Multi-City — renders [locations] shortcode.
// Reads cities.json, templates.json, and page-index.json at render time.
// Groups generated pages by city, optionally by state.

register_plugin(
    'landing_links',
    'Service Links: Multi-City',
    'Use [locations] in any Custom HTML block to list all generated city landing pages, grouped by city and state.',
    '&#127758;',
    __DIR__
);

add_hook('shortcode_content', function(string $html, string $pathPrefix = ''): string {
    if (strpos($html, '[locations') === false) return $html;
    global $data;
    $baseCfg = $data['landing_links'] ?? [];
    // Supports both the bare [locations] (uses the admin-configured global defaults) and
    // an inline-attribute form, e.g. [locations template="tpl_pmp_bootcamp_city" format="columns" cols="4"],
    // which a page can embed to show only ITS OWN service's current city pages — reading live
    // from page-index.json/templates.json/cities.json means it never goes stale as cities are
    // added, removed, or renamed (the alternative, a hand-maintained city list per page, is
    // exactly the staleness bug this was built to replace).
    return preg_replace_callback('/\[locations([^\]]*)\]/', function($m) use ($baseCfg) {
        $attrs = [];
        if (trim($m[1]) !== '' && preg_match_all('/(\w+)="([^"]*)"/', $m[1], $am, PREG_SET_ORDER)) {
            foreach ($am as $a) $attrs[$a[1]] = $a[2];
        }
        $cfg = $baseCfg;
        foreach (['template_filter' => 'template', 'format' => 'format', 'cols' => 'cols', 'link_text' => 'link_text'] as $cfgKey => $attrKey) {
            if (isset($attrs[$attrKey])) $cfg[$cfgKey] = $attrs[$attrKey];
        }
        // Default link_text to city-only when a page filters to one template and doesn't
        // override it — set here in PHP, NEVER as a literal {city}/{SS} token typed into a
        // custom_html block's stored text, because custom_html runs through
        // apply_course_shortcodes() (site_vars.city/SS, the business's own HQ default)
        // BEFORE this shortcode hook ever sees it — a {city}/{SS} token embedded in the
        // stored HTML gets silently resolved to the business's default city for every row,
        // not the row's own city (the exact bug this comment is here to prevent repeating).
        if (isset($attrs['template']) && !isset($attrs['link_text'])) {
            $cfg['link_text'] = '{city}, {SS}';
        }
        return _landing_links_render($cfg);
    }, $html);
});

function _landing_links_render(array $cfg): string {
    // Load data sources
    $pageIndex = (defined('PAGE_INDEX_FILE') && file_exists(PAGE_INDEX_FILE))
        ? (json_decode(file_get_contents(PAGE_INDEX_FILE), true) ?: []) : [];
    $cities    = (defined('CITIES_FILE') && file_exists(CITIES_FILE))
        ? (json_decode(file_get_contents(CITIES_FILE), true) ?: []) : [];
    $templates = (defined('TEMPLATES_FILE') && file_exists(TEMPLATES_FILE))
        ? (json_decode(file_get_contents(TEMPLATES_FILE), true) ?: []) : [];

    if (empty($pageIndex)) return '';

    // Build lookup maps
    $citiesMap    = [];
    foreach ($cities as $c) {
        if (!empty($c['id'])) $citiesMap[$c['id']] = $c;
    }
    $templatesMap = [];
    foreach ($templates as $t) {
        if (!empty($t['id'])) $templatesMap[$t['id']] = $t;
    }

    $tplFilter   = $cfg['template_filter'] ?? '';
    $linkPattern = $cfg['link_text'] ?? '{template_title} in {city}, {SS}';

    // Parse page-index: slug => filename (e.g. tpl_pmp_certification_city_houston_tx.json)
    // Group by city_id
    $cityPages = []; // city_id => [ ['slug'=>, 'text'=>, 'sort'=>], ... ]

    foreach ($pageIndex as $slug => $filename) {
        $base = basename($filename, '.json');
        // Match against known template IDs to find the correct split point
        $templateId = null;
        $cityId     = null;
        foreach ($templatesMap as $tplId => $_) {
            $prefix = $tplId . '_';
            if (str_starts_with($base, $prefix)) {
                $templateId = $tplId;
                $cityId     = substr($base, strlen($prefix));
                break;
            }
        }
        if ($templateId === null) continue;

        if ($tplFilter !== '' && $templateId !== $tplFilter) continue;

        $cityData = $citiesMap[$cityId] ?? null;
        if (!$cityData) continue;

        $tpl           = $templatesMap[$templateId] ?? [];
        $templateTitle = $tpl['title'] ?? ucwords(str_replace(['tpl_', '_'], ['', ' '], $templateId));
        $sortOrder     = (int)($tpl['sort_order'] ?? 0);

        $text = str_replace(
            ['{template_title}', '{city}', '{SS}', '{state}', '{city_slug}', '{city_state}'],
            [
                $templateTitle,
                $cityData['city']      ?? '',
                $cityData['SS']        ?? '',
                $cityData['state']     ?? '',
                $cityData['city_slug'] ?? '',
                trim(($cityData['city'] ?? '') . ', ' . ($cityData['SS'] ?? ''), ', '),
            ],
            $linkPattern
        );

        $cityPages[$cityId][] = [
            'slug' => $slug,
            'text' => $text,
            'sort' => $sortOrder,
        ];
    }

    if (empty($cityPages)) return '';

    // Sort pages within each city by template sort_order
    foreach ($cityPages as &$pages) {
        usort($pages, fn($a, $b) => $a['sort'] <=> $b['sort']);
    }
    unset($pages);

    // Sort cities: state then city name
    uksort($cityPages, function($a, $b) use ($citiesMap) {
        $ca = $citiesMap[$a] ?? [];
        $cb = $citiesMap[$b] ?? [];
        $sc = strcmp($ca['state'] ?? '', $cb['state'] ?? '');
        return $sc !== 0 ? $sc : strcmp($ca['city'] ?? '', $cb['city'] ?? '');
    });

    $format = $cfg['format'] ?? 'by_state';
    $cols   = max(2, min(4, (int)($cfg['cols'] ?? 3)));

    ob_start();

    if ($format === 'by_state') {
        // Group cities by state
        $byState = [];
        foreach ($cityPages as $cityId => $pages) {
            $state = $citiesMap[$cityId]['state'] ?? 'Other';
            $byState[$state][$cityId] = $pages;
        }
        ksort($byState);

        echo '<div class="ll-locations ll-by-state">';
        foreach ($byState as $state => $stateCities) {
            echo '<div class="ll-state-group">';
            echo '<h3 class="ll-state-heading">' . h($state) . '</h3>';
            foreach ($stateCities as $cityId => $pages) {
                $c = $citiesMap[$cityId] ?? [];
                $cityLabel = trim(($c['city'] ?? '') . ', ' . ($c['SS'] ?? ''), ', ');
                echo '<div class="ll-city-group">';
                echo '<h4 class="ll-city-heading">' . h($cityLabel) . '</h4>';
                echo '<ul class="ll-pages">';
                foreach ($pages as $p) {
                    echo '<li><a href="/' . h($p['slug']) . '/">' . h($p['text']) . '</a></li>';
                }
                echo '</ul></div>';
            }
            echo '</div>';
        }
        echo '</div>';

    } elseif ($format === 'columns') {
        echo '<div class="ll-locations ll-columns" style="column-count:' . $cols . ';column-gap:2rem;">';
        foreach ($cityPages as $cityId => $pages) {
            $c = $citiesMap[$cityId] ?? [];
            $cityLabel = trim(($c['city'] ?? '') . ', ' . ($c['SS'] ?? ''), ', ');
            echo '<div class="ll-city-group" style="break-inside:avoid;margin-bottom:1.25rem;">';
            echo '<strong class="ll-city-heading">' . h($cityLabel) . '</strong>';
            echo '<ul class="ll-pages">';
            foreach ($pages as $p) {
                echo '<li><a href="/' . h($p['slug']) . '/">' . h($p['text']) . '</a></li>';
            }
            echo '</ul></div>';
        }
        echo '</div>';

    } else {
        // flat list
        echo '<ul class="ll-locations ll-list">';
        foreach ($cityPages as $cityId => $pages) {
            $c = $citiesMap[$cityId] ?? [];
            $cityLabel = trim(($c['city'] ?? '') . ', ' . ($c['SS'] ?? ''), ', ');
            echo '<li><strong>' . h($cityLabel) . '</strong>';
            echo '<ul class="ll-pages">';
            foreach ($pages as $p) {
                echo '<li><a href="/' . h($p['slug']) . '/">' . h($p['text']) . '</a></li>';
            }
            echo '</ul></li>';
        }
        echo '</ul>';
    }

    return ob_get_clean();
}
