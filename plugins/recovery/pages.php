<?php
/**
 * Recovery plugin — page synthesis.
 *
 * Turns a matched route into a render payload (content_blocks + seo + title) that
 * page.php renders through the SHARED block library + site-template.
 *
 * PREFERRED PATH: each type is mapped (in the panel) to a TEMPLATE in the site's
 * templates.json — authored with the full block editor + AI in the Templates tab.
 * We load that template's blocks/seo and set entity site_vars so all shortcodes
 * ({city}, {SS}, {city_state}, {company}, …) resolve per instance at render time.
 *
 * FALLBACK: until a type is mapped, a placeholder `text` block renders (so routing
 * stays verifiable and the site never errors mid-setup). Nothing here re-implements
 * block editing or AI — that all lives in the reused Templates tab.
 *
 * Return shape (consumed by page.php's route_request seam):
 *   ['content_blocks'=>[...], 'seo'=>[...], 'title'=>'...', 'site_vars'=>[...], 'status'=>int opt]
 */

require_once __DIR__ . '/data.php';

function recovery_render_route(array $m, array $data, string $path = ''): array {
    // 1. Resolve the matched entity → site_vars overrides + token context.
    $sv  = [];
    $ctx = ['type' => $m['type']];
    $ss  = '';

    if (!empty($m['state'])) {
        $s = recovery_state($m['state']);
        if ($s) {
            $ss = $s['ss'] ?? '';
            $sv['state'] = $s['name'] ?? '';   $sv['SS'] = $ss;
            $ctx['state'] = $s['name'] ?? '';  $ctx['ss'] = $ss;
        }
    }
    if (!empty($m['city'])) {
        $c = recovery_city($m['state'], $m['city']);
        if ($c) {
            $sv['city']       = $c['name'] ?? '';
            $sv['city_slug']  = $c['slug'] ?? '';
            $sv['city_state'] = trim(($c['name'] ?? '') . ', ' . $ss, ', ');
            $ctx['city']      = $c['name'] ?? '';
        }
    }
    if (!empty($m['company'])) {
        $co = recovery_carrier($m['company']);
        $ctx['company']      = $co['name'] ?? $m['company'];
        $ctx['company_slug'] = $m['company'];
    }
    recovery_set_ctx($ctx);   // {company}* tokens read this (plugin.php shortcode_tokens hook)

    // 2. Preferred: render the mapped template's blocks. Fallback: placeholder.
    $cfg = recovery_config();
    $tpl = recovery_template_by_id($cfg['templates'][$m['type']] ?? '');
    if ($tpl && !empty($tpl['content_blocks'])) {
        $payload = [
            'title'          => $tpl['title'] ?? '',
            'seo'            => is_array($tpl['seo'] ?? null) ? $tpl['seo'] : [],
            'content_blocks' => $tpl['content_blocks'],
            'site_vars'      => $sv,
        ];
    } else {
        $payload = _recovery_placeholder_for($m);
        $payload['site_vars'] = $sv;
    }

    // Canonical URL: the plugin renders before page.php sets $slug, so the site-template's
    // lb_url-based canonical can't derive here. Set it explicitly from the request path;
    // site-template resolves {website} and forces a trailing slash.
    $payload['seo'] = is_array($payload['seo'] ?? null) ? $payload['seo'] : [];
    if (trim($path, '/') !== '') {
        $payload['seo']['canonical_url'] = '{website}/' . trim($path, '/') . '/';
    }

    // Breadcrumbs (Home › State › City › Carrier) — matches the nested URL; drives the
    // visible bar + BreadcrumbList microdata. page.php sets $bcItems from this.
    $payload['breadcrumbs'] = recovery_breadcrumbs($m);

    // 3. Append the internal-link mesh (SEO silo: hubs → entities, entities → up).
    $nav = recovery_nav_block($m);
    if ($nav) $payload['content_blocks'][] = $nav;
    return $payload;
}

/** Breadcrumb trail matching the nested URL. Returns [['name'=>..,'url'=>..], ...]. */
function recovery_breadcrumbs(array $m): array {
    $st = $m['state'] ?? ''; $ci = $m['city'] ?? ''; $co = $m['company'] ?? '';
    $sName = $st ? (recovery_state($st)['name'] ?? $st) : '';
    $ciName = ($st && $ci) ? (recovery_city($st, $ci)['name'] ?? $ci) : '';
    $coName = $co ? (recovery_carrier($co)['name'] ?? $co) : '';
    $bc = [['name' => 'Home', 'url' => '/']];
    switch ($m['type']) {
        case 'hub':
            $bc[] = ['name' => 'Insurance', 'url' => '/insurance/']; break;
        case 'company_national':
            $bc[] = ['name' => 'Insurance', 'url' => '/insurance/'];
            $bc[] = ['name' => $coName, 'url' => "/insurance/$co/"]; break;
        case 'state':
            $bc[] = ['name' => $sName, 'url' => "/$st/"]; break;
        case 'city':
            $bc[] = ['name' => $sName, 'url' => "/$st/"];
            $bc[] = ['name' => $ciName, 'url' => "/$st/$ci/"]; break;
        case 'state_company':
            $bc[] = ['name' => $sName, 'url' => "/$st/"];
            $bc[] = ['name' => $coName, 'url' => "/$st/$co/"]; break;
        case 'city_company':
            $bc[] = ['name' => $sName, 'url' => "/$st/"];
            $bc[] = ['name' => $ciName, 'url' => "/$st/$ci/"];
            $bc[] = ['name' => $coName, 'url' => "/$st/$ci/$co/"]; break;
    }
    return $bc;
}

/**
 * Build the contextual internal-link block for a page (custom_html), with keyword-rich
 * anchors. This is what wires the matrix together for crawlers + users. Links are built
 * in PHP from the matrix data (not tokens), so anchors carry real carrier/city names.
 */
function recovery_nav_block(array $m): ?array {
    $heading = '';
    $links   = [];   // [url, anchor]
    $st = $m['state'] ?? ''; $ci = $m['city'] ?? ''; $co = $m['company'] ?? '';
    $sRow = $st ? recovery_state($st) : null; $sName = $sRow['name'] ?? $st; $ss = $sRow['ss'] ?? '';
    $ciRow = ($st && $ci) ? recovery_city($st, $ci) : null; $ciName = $ciRow['name'] ?? $ci;
    $coRow = $co ? recovery_carrier($co) : null; $coName = $coRow['name'] ?? $co;

    switch ($m['type']) {
        case 'hub':
            $heading = 'Check Rehab Coverage by Insurance Carrier';
            foreach (recovery_carriers() as $c) $links[] = ["/insurance/{$c['slug']}", "{$c['name']} rehab coverage"];
            foreach (recovery_states() as $s)   $links[] = ["/{$s['slug']}", "Rehab that takes insurance in {$s['name']}"];
            break;
        case 'company_national':
            $heading = "$coName Rehab Coverage by State";
            foreach (recovery_states() as $s) $links[] = ["/{$s['slug']}/$co", "$coName rehab coverage in {$s['name']}"];
            break;
        case 'state':
            $heading = "Rehab Coverage by City in $sName";
            foreach (recovery_cities() as $c) if (($c['state'] ?? '') === $st) $links[] = ["/$st/{$c['slug']}", "Rehab that takes insurance in {$c['name']}, $ss"];
            foreach (recovery_carriers() as $c) $links[] = ["/$st/{$c['slug']}", "{$c['name']} coverage in $sName"];
            break;
        case 'city':
            $heading = "Insurance Carriers Accepted in $ciName, $ss";
            foreach (recovery_carriers() as $c) $links[] = ["/$st/$ci/{$c['slug']}", "{$c['name']} rehab coverage in $ciName"];
            break;
        case 'state_company':
            $heading = "$coName Rehab Coverage by City in $sName";
            foreach (recovery_cities() as $c) if (($c['state'] ?? '') === $st) $links[] = ["/$st/{$c['slug']}/$co", "$coName coverage in {$c['name']}, $ss"];
            break;
        case 'city_company':
            $heading = 'Related Coverage';
            $links[] = ["/$st/$ci", "All rehab coverage in $ciName, $ss"];
            $links[] = ["/insurance/$co", "$coName rehab coverage nationwide"];
            $links[] = ["/$st/$co", "$coName rehab coverage in $sName"];
            break;
    }
    if (!$links) return null;

    $lis = '';
    foreach ($links as [$url, $anchor]) {
        $lis .= '<li style="margin:0 0 8px;"><a href="' . htmlspecialchars($url) . '">' . htmlspecialchars($anchor) . '</a></li>';
    }
    $html = '<div style="max-width:1100px;margin:0 auto;padding:8px 0;">'
          . '<h2 style="font-size:1.4rem;margin:0 0 16px;">' . htmlspecialchars($heading) . '</h2>'
          . '<ul style="list-style:none;padding:0;margin:0;column-count:2;column-gap:40px;line-height:1.6;">'
          . $lis . '</ul></div>';
    return ['type' => 'custom_html', 'html' => $html];
}

/** Guaranteed-to-render placeholder built from the core `text` block. */
function _recovery_placeholder(string $h1, string $body): array {
    return [
        'title' => $h1,
        'seo'   => ['seo_title' => $h1, 'meta_description' => $body],
        'content_blocks' => [[
            'type'           => 'text',
            'heading_level'  => 'h1',
            'text'           => $h1 . "\n\n" . $body
                              . "\n\n(No template mapped for this page type yet — set one in "
                              . "Admin → Plugins → Recovery Insurance.)",
            'photo'          => '',
            'photo_ratio'    => 'landscape',
            'photo_position' => 'center',
            'photo_alt'      => '',
        ]],
    ];
}

function _recovery_placeholder_for(array $m): array {
    switch ($m['type']) {
        case 'hub':
            $names = array_map(fn($c) => $c['name'] ?? $c['slug'], recovery_carriers());
            return _recovery_placeholder('Insurance Coverage for Addiction Recovery',
                '[hub] Carriers: ' . (implode(', ', $names) ?: '(none yet)') . '.');
        case 'company_national':
            $c = recovery_carrier($m['company']); $n = $c['name'] ?? $m['company'];
            return _recovery_placeholder("$n Rehab Coverage", "[company_national] $n nationwide.");
        case 'state':
            $s = recovery_state($m['state']); $n = $s['name'] ?? $m['state'];
            return _recovery_placeholder("Addiction Recovery Insurance in $n", "[state] $n hub.");
        case 'city':
            $s = recovery_state($m['state']); $ci = recovery_city($m['state'], $m['city']);
            $cn = $ci['name'] ?? $m['city']; $ssv = $s['ss'] ?? '';
            return _recovery_placeholder("Recovery Insurance in $cn, $ssv", "[city] $cn.");
        case 'state_company':
            $s = recovery_state($m['state']); $c = recovery_carrier($m['company']);
            $sn = $s['name'] ?? $m['state']; $cn = $c['name'] ?? $m['company'];
            return _recovery_placeholder("$cn Rehab Coverage in $sn", "[state_company] $cn in $sn.");
        case 'city_company':
            $s = recovery_state($m['state']); $ci = recovery_city($m['state'], $m['city']); $c = recovery_carrier($m['company']);
            $ssv = $s['ss'] ?? ''; $cn = $ci['name'] ?? $m['city']; $co = $c['name'] ?? $m['company'];
            return _recovery_placeholder("$co Rehab Coverage in $cn, $ssv", "[city_company] $co in $cn, $ssv.");
    }
    return _recovery_placeholder('Recovery', 'Unknown page type.');
}
