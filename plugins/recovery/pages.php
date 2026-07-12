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

function recovery_render_route(array $m, array $data): array {
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

    // 2. Preferred: render the mapped template's blocks.
    $cfg = recovery_config();
    $tpl = recovery_template_by_id($cfg['templates'][$m['type']] ?? '');
    if ($tpl && !empty($tpl['content_blocks'])) {
        return [
            'title'          => $tpl['title'] ?? '',
            'seo'            => is_array($tpl['seo'] ?? null) ? $tpl['seo'] : [],
            'content_blocks' => $tpl['content_blocks'],
            'site_vars'      => $sv,
        ];
    }

    // 3. Fallback placeholder (unmapped type).
    $p = _recovery_placeholder_for($m);
    $p['site_vars'] = $sv;
    return $p;
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
