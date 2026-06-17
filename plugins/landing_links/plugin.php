<?php
// Landing Links: Multi-City — renders [locations] shortcode.
// Reads cities.json, templates.json, and page-index.json at render time.
// Groups generated pages by city, optionally by state.

register_plugin(
    'landing_links',
    'Landing Links: Multi-City',
    'Use [locations] in any Custom HTML block to list all generated city landing pages, grouped by city and state.',
    '&#127758;',
    __DIR__
);

add_hook('shortcode_content', function(string $html, string $pathPrefix = ''): string {
    if (strpos($html, '[locations]') === false) return $html;
    global $data;
    $cfg = $data['landing_links'] ?? [];
    return str_replace('[locations]', _landing_links_render($cfg), $html);
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
        // Split on _city_ — everything before is template_id, after is city id part
        if (!preg_match('/^(.+?)_city_(.+)$/', $base, $m)) continue;
        $templateId = $m[1];
        $cityId     = 'city_' . $m[2];

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
                    echo '<li><a href="/' . h($p['slug']) . '">' . h($p['text']) . '</a></li>';
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
                echo '<li><a href="/' . h($p['slug']) . '">' . h($p['text']) . '</a></li>';
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
                echo '<li><a href="/' . h($p['slug']) . '">' . h($p['text']) . '</a></li>';
            }
            echo '</ul></li>';
        }
        echo '</ul>';
    }

    return ob_get_clean();
}
