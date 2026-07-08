<?php
// Services Links plugin — renders [services_links] shortcode as a styled grid.

register_plugin(
    'services_links',
    'Service Links: 1-City',
    'Manage your service page list. Use [services_links] in any Custom HTML block to render a city-resolved grid of service links.',
    '&#128279;',
    __DIR__
);

// Hook into custom_html shortcode pipeline — replace [services_links] with rendered HTML.
add_hook('shortcode_content', function(string $html, string $pathPrefix = ''): string {
    if (strpos($html, '[services_links]') === false) return $html;
    global $data;
    $cfg = $data['services_links'] ?? [];
    return str_replace('[services_links]', _services_links_render($cfg, $pathPrefix), $html);
});

// Normalise one service entry (either a {name,url} pair or a legacy bare name)
// into [name, resolved_url]. Falls back to the url_pattern + slugify(name) when a
// row has no explicit url (legacy configs and blank link fields).
function _services_links_row($svc, string $pattern): array {
    if (is_array($svc)) { $name = trim($svc['name'] ?? ''); $url = trim($svc['url'] ?? ''); }
    else                { $name = trim((string)$svc);       $url = ''; }
    if ($name === '') return ['', ''];
    if ($url === '')  $url = str_replace('{service_slug}', slugify($name), $pattern);
    return [$name, resolve_shortcodes($url)];
}

function _services_links_render(array $cfg, string $pathPrefix = ''): string {
    global $data;

    $services = $cfg['services'] ?? [];
    $pattern  = $cfg['url_pattern'] ?? '/{service_slug}-{city_slug}';
    $heading  = resolve_shortcodes($cfg['heading']  ?? '');
    $subtext  = resolve_shortcodes($cfg['subtext']  ?? '');
    $sublabel = resolve_shortcodes($cfg['sublabel'] ?? '');
    $style    = $cfg['style']    ?? 'dark';
    $cols     = max(2, min(6, (int)($cfg['cols'] ?? 5)));
    $photo    = $cfg['bg_photo'] ?? '';
    $overlay  = $cfg['overlay']  ?? '0.20';
    $bgColor  = $cfg['bg_color'] ?? '#ffffff';
    $anchor   = $cfg['anchor']   ?? '';
    $accentC  = $cfg['accent']        ?? 'accent';
    $accentCC = $cfg['accent_custom'] ?? '#fd783b';

    $accentStyle = resolve_color($accentC, $accentCC);
    $anchorAttr  = $anchor ? ' id="' . h($anchor) . '"' : '';

    $photoSrc = '';
    if ($photo) {
        $photoSrc = (str_starts_with($photo, 'http') || str_starts_with($photo, '//'))
            ? $photo : $pathPrefix . $photo;
    }

    // Resolve every row to [name, url], and — when a page-index is available —
    // drop links whose landing page hasn't been built for this city (avoids 404s
    // on partial builds). Single-segment relative slugs only; external/deep/custom
    // links always pass. If PAGE_INDEX_FILE is missing we can't tell, so show all.
    $existSlugs = null;
    if (defined('PAGE_INDEX_FILE') && file_exists(PAGE_INDEX_FILE)) {
        $pi = json_decode((string)file_get_contents(PAGE_INDEX_FILE), true);
        $existSlugs = is_array($pi) ? $pi : [];
    }
    $links = [];
    foreach ($services as $svc) {
        [$name, $url] = _services_links_row($svc, $pattern);
        if ($name === '') continue;
        if ($existSlugs !== null && isset($url[0]) && $url[0] === '/') {
            $slug = trim((string)parse_url($url, PHP_URL_PATH), '/');
            if ($slug !== '' && strpos($slug, '/') === false && !isset($existSlugs[$slug])) continue;
        }
        $links[] = [$name, $url];
    }

    ob_start();

    if ($style === 'light') {
        echo '<div class="content-block block-links-grid block-links-light"' . $anchorAttr . ' style="background:' . h($bgColor) . ';">';
        echo '<div class="container">';
        if ($sublabel || $heading) {
            echo '<div class="lg-light-header">';
            if ($sublabel) echo '<div class="lg-sublabel" style="color:' . $accentStyle . ';">' . h($sublabel) . '</div>';
            if ($heading)  echo '<h2 class="lg-light-heading">' . h($heading) . '</h2>';
            echo '</div>';
        }
        echo '<div class="lg-grid lg-light-grid lg-cols-' . $cols . '">';
        foreach ($links as [$name, $url]) {
            echo '<a href="' . h($url) . '" class="lg-light-link">' . h($name) . '</a>';
        }
        echo '</div></div></div>';
    } else {
        $bgStyle = $photoSrc
            ? 'background-image:url(' . h($photoSrc) . ');background-size:cover;background-position:center;'
            : 'background:#1a1a2e;';
        echo '<div class="content-block block-links-grid"' . $anchorAttr . ' style="' . $bgStyle . '">';
        echo '<div class="lg-overlay" style="background:rgba(0,0,0,' . h($overlay) . ');">';
        if ($heading || $subtext) {
            echo '<div class="lg-header container">';
            if ($heading) echo '<h2 class="lg-heading">' . h($heading) . '</h2>';
            if ($subtext) echo '<p class="lg-subtext">' . h($subtext) . '</p>';
            echo '</div>';
        }
        echo '<div class="lg-grid-wrap container">';
        echo '<div class="lg-grid lg-cols-' . $cols . '">';
        foreach ($links as [$name, $url]) {
            echo '<a href="' . h($url) . '" class="lg-link">' . h($name) . '</a>';
        }
        echo '</div></div>';
        echo '</div></div>';
    }

    return ob_get_clean();
}
