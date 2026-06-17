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
        foreach ($services as $name) {
            $name = trim($name);
            if ($name === '') continue;
            $url = resolve_shortcodes(str_replace('{service_slug}', slugify($name), $pattern));
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
        foreach ($services as $name) {
            $name = trim($name);
            if ($name === '') continue;
            $url = resolve_shortcodes(str_replace('{service_slug}', slugify($name), $pattern));
            echo '<a href="' . h($url) . '" class="lg-link">' . h($name) . '</a>';
        }
        echo '</div></div>';
        echo '</div></div>';
    }

    return ob_get_clean();
}
