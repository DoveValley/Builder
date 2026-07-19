<?php
// Services Links plugin — renders [services_links] as a styled grid of service
// links. Supports optional filter attributes so a hub page can auto-list just
// its children (drift-free — the subset is derived from slugs, not hand-kept):
//   [services_links]                                  full list (unchanged)
//   [services_links brand="whirlpool"]                that brand's leaf pages
//   [services_links appliance_type="refrigerator"]    that appliance across brands
//   [services_links role="brand_hub"]                 all brand hubs (homepage)
//   [services_links role="type_hub"]                  all appliance-type hubs
// Optional: heading="..." cols="4" override the config for that instance.

require_once __DIR__ . '/../../includes/appliance_taxonomy.php';

register_plugin(
    'services_links',
    'Service Links: 1-City',
    'Manage your service page list. Use [services_links] in any Custom HTML block to render a city-resolved grid of service links. Add brand="…", appliance_type="…", or role="…" to render only a matching subset (auto-derived from slugs).',
    '&#128279;',
    __DIR__
);

// Hook into custom_html shortcode pipeline — replace each [services_links …] with rendered HTML.
add_hook('shortcode_content', function(string $html, string $pathPrefix = ''): string {
    if (strpos($html, '[services_links') === false) return $html;
    global $data;
    $cfg = $data['services_links'] ?? [];
    return preg_replace_callback('/\[services_links\b([^\]]*)\]/', function($m) use ($cfg, $pathPrefix) {
        return _services_links_render($cfg, $pathPrefix, _services_links_parse_attrs($m[1]));
    }, $html);
});

// Parse `key="value"` / `key='value'` attribute pairs out of a shortcode tail.
function _services_links_parse_attrs(string $raw): array {
    $attrs = [];
    if (preg_match_all('/([a-z_]+)\s*=\s*"([^"]*)"|([a-z_]+)\s*=\s*\'([^\']*)\'/i', $raw, $mm, PREG_SET_ORDER)) {
        foreach ($mm as $p) {
            if (($p[1] ?? '') !== '') $attrs[strtolower($p[1])] = $p[2];
            elseif (($p[3] ?? '') !== '') $attrs[strtolower($p[3])] = $p[4];
        }
    }
    return $attrs;
}

// slugify an attribute value for taxonomy comparison ("Sub Zero" -> "sub-zero").
function _services_links_norm(string $v): string {
    return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower(trim($v))), '-');
}

// Normalise one service entry (either a {name,url} pair or a legacy bare name)
// into [name, resolved_url].
function _services_links_row($svc, string $pattern): array {
    if (is_array($svc)) { $name = trim($svc['name'] ?? ''); $url = trim($svc['url'] ?? ''); }
    else                { $name = trim((string)$svc);       $url = ''; }
    if ($name === '') return ['', ''];
    if ($url === '')  $url = str_replace('{service_slug}', slugify($name), $pattern);
    return [$name, resolve_shortcodes($url)];
}

// Does a service (by its raw url slug) pass the filter attributes?
function _services_links_passes(array $filter, $svc): bool {
    if (empty($filter['_active'])) return true;
    $rawUrl = is_array($svc) ? trim($svc['url'] ?? '') : '';
    $base = preg_replace('#-\{city_slug\}$#', '', ltrim($rawUrl, '/'));
    if ($base === '') return false;
    $d = appliance_derive_slug($base);
    if ($filter['role']  !== '' && $d['role']  !== $filter['role'])  return false;
    if ($filter['brand'] !== '' && $d['brand'] !== $filter['brand']) return false;
    if ($filter['type']  !== '' && $d['appliance_type'] !== appliance_type_canonical($filter['type'])) return false;
    return true;
}

function _services_links_render(array $cfg, string $pathPrefix = '', array $attrs = []): string {
    global $data;

    // ── resolve "self" against the current page's slug ──────────────────────────
    // [services_links brand="self"] on a brand-hub page lists that page's own
    // brand; appliance_type="self" on a type-hub page lists its own appliance.
    // So one hub master works for every clone (filter derived from the page slug).
    if (($attrs['brand'] ?? '') === 'self' || ($attrs['appliance_type'] ?? '') === 'self' || ($attrs['type'] ?? '') === 'self') {
        global $slug;
        $base = (string)($slug ?? '');
        $citySlug = $data['site_vars']['city_slug'] ?? '';
        if ($citySlug !== '') $base = preg_replace('/-' . preg_quote($citySlug, '/') . '$/', '', $base);
        $self = appliance_derive_slug($base);
        if (($attrs['brand'] ?? '')          === 'self') $attrs['brand']          = $self['brand'];
        if (($attrs['appliance_type'] ?? '') === 'self') $attrs['appliance_type'] = $self['appliance_type'];
        if (($attrs['type'] ?? '')           === 'self') $attrs['type']           = $self['appliance_type'];
    }

    // ── resolve filter ────────────────────────────────────────────────────────
    $fBrand = isset($attrs['brand'])          ? _services_links_norm($attrs['brand'])          : '';
    $fType  = isset($attrs['appliance_type']) ? _services_links_norm($attrs['appliance_type']) : (isset($attrs['type']) ? _services_links_norm($attrs['type']) : '');
    $fRole  = isset($attrs['role'])           ? _services_links_norm($attrs['role'])           : '';
    // brand/appliance filters implicitly target leaf pages unless a role is named
    if ($fRole === '' && ($fBrand !== '' || $fType !== '')) $fRole = 'leaf';
    $filter = ['brand' => $fBrand, 'type' => $fType, 'role' => str_replace('-', '_', $fRole),
               '_active' => ($fBrand !== '' || $fType !== '' || $fRole !== '')];

    $services = $cfg['services'] ?? [];
    $pattern  = $cfg['url_pattern'] ?? '/{service_slug}-{city_slug}';
    // per-instance heading / cols overrides (useful on hub pages)
    $heading  = resolve_shortcodes($attrs['heading'] ?? $cfg['heading']  ?? '');
    $subtext  = resolve_shortcodes($cfg['subtext']  ?? '');
    $sublabel = resolve_shortcodes($cfg['sublabel'] ?? '');
    $style    = $cfg['style']    ?? 'dark';
    $cols     = max(2, min(6, (int)($attrs['cols'] ?? $cfg['cols'] ?? 5)));
    $photo    = $cfg['bg_photo'] ?? '';
    $overlay  = $cfg['overlay']  ?? '0.20';
    $bgColor  = $cfg['bg_color'] ?? '#ffffff';
    $anchor   = $attrs['anchor'] ?? $cfg['anchor'] ?? '';
    $accentC  = $cfg['accent']        ?? 'accent';
    $accentCC = $cfg['accent_custom'] ?? '#fd783b';

    $accentStyle = resolve_color($accentC, $accentCC);
    $anchorAttr  = $anchor ? ' id="' . h($anchor) . '"' : '';

    $photoSrc = '';
    if ($photo) {
        $photoSrc = (str_starts_with($photo, 'http') || str_starts_with($photo, '//'))
            ? $photo : $pathPrefix . $photo;
    }

    // Resolve rows to [name, url]; apply the filter, then drop links whose landing
    // page isn't built for this city (avoids 404s on partial builds; skipped when
    // no page-index is available).
    $existSlugs = null;
    if (defined('PAGE_INDEX_FILE') && file_exists(PAGE_INDEX_FILE)) {
        $pi = json_decode((string)file_get_contents(PAGE_INDEX_FILE), true);
        $existSlugs = is_array($pi) ? $pi : [];
    }
    $links = [];
    $candidates = 0;   // passed the filter + resolved a name, before the page-exists guard
    foreach ($services as $svc) {
        if (!_services_links_passes($filter, $svc)) continue;
        [$name, $url] = _services_links_row($svc, $pattern);
        if ($name === '') continue;
        $candidates++;
        if ($existSlugs !== null && isset($url[0]) && $url[0] === '/') {
            $slug = trim((string)parse_url($url, PHP_URL_PATH), '/');
            if ($slug !== '' && strpos($slug, '/') === false && !isset($existSlugs[$slug])) continue;
        }
        $links[] = [$name, $url];
    }
    // Diagnostic: the page-exists guard drops links silently. If it dropped ALL (or
    // a majority) of them, that's almost always a city_slug mismatch between the
    // service URLs ({city_slug} from site_vars) and the slugs in page-index.json —
    // surface it instead of rendering a link-less grid with no explanation.
    if ($existSlugs !== null && $candidates > 0 && count($links) < $candidates
        && (count($links) === 0 || ($candidates - count($links)) >= (int)ceil($candidates / 2))) {
        $cslug = $data['site_vars']['city_slug'] ?? '';
        $msg = 'services_links: dropped ' . ($candidates - count($links)) . '/' . $candidates
             . ' links not found in page-index.json — likely a city_slug mismatch (service URLs use {city_slug}=\'' . $cslug . '\').';
        if (function_exists('progress_log')) progress_log($msg, 'warn');
        else error_log('[services_links] ' . $msg);
    }
    // A filtered grid that matched nothing renders nothing (no empty shell).
    if ($filter['_active'] && empty($links)) return '';

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
