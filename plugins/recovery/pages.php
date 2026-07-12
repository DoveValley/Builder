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
            // Expose the matched city's photo so {city_image}* (city-image plugin tokens)
            // resolve per page — used by the map_info block. Absent → tokens stay empty.
            if (!empty($c['image'])) {
                $sv['city_image']        = $c['image'];
                $sv['city_image_alt']    = $c['image_alt'] ?? '';
                $sv['city_image_credit'] = $c['image_credit'] ?? '';
            }
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

    // Fill AI-enriched blocks (ai_fill markers) from the matched entity's cached ai bundle.
    $payload['content_blocks'] = recovery_apply_ai($payload['content_blocks'] ?? [], $m);

    // Real SAMHSA facility listings on every geo/carrier page, inserted after the AI intro(s).
    if (in_array($m['type'], ['city', 'city_company', 'state', 'state_company', 'hub', 'company_national'], true)) {
        $fb = recovery_facilities_block($m);
        if ($fb) {
            $ins = 1;
            foreach ($payload['content_blocks'] as $idx => $b) {
                if (in_array($b['ai_fill'] ?? '', ['intro', 'intro2'], true)) $ins = $idx + 1;
            }
            array_splice($payload['content_blocks'], $ins, 0, [$fb]);
        }
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
/**
 * Which entity's ai bundle feeds a page: primary (main subject) + secondary (local
 * flavor on intersections). Returns ['primary'=>?ai, 'secondary'=>?ai].
 */
function recovery_ai_sources(array $m): array {
    $carrier = !empty($m['company']) ? (recovery_carrier($m['company'])['ai'] ?? null) : null;
    $city    = (!empty($m['state']) && !empty($m['city'])) ? (recovery_city($m['state'], $m['city'])['ai'] ?? null) : null;
    $state   = !empty($m['state']) ? (recovery_state($m['state'])['ai'] ?? null) : null;
    switch ($m['type']) {
        case 'company_national': return ['primary' => $carrier, 'secondary' => null];
        case 'state':            return ['primary' => $state,   'secondary' => null];
        case 'city':             return ['primary' => $city,    'secondary' => null];
        case 'state_company':    return ['primary' => $carrier, 'secondary' => $state];
        case 'city_company':     return ['primary' => $carrier, 'secondary' => $city];
    }
    return ['primary' => null, 'secondary' => null];
}

/**
 * Fill blocks tagged with `ai_fill` from the matched entity's cached ai bundle. Untagged
 * blocks and missing-ai cases are left as their hand-written template content (graceful).
 *   ai_fill: intro → primary intro_html · intro2 → secondary intro_html ·
 *            features → primary feature_points · faq → primary+secondary faq (deduped) ·
 *            local → secondary (else primary) local_note
 */
function recovery_apply_ai(array $blocks, array $m): array {
    $s = recovery_ai_sources($m);
    $P = $s['primary']; $S = $s['secondary'];
    foreach ($blocks as $i => $b) {
        $fill = $b['ai_fill'] ?? '';
        if ($fill === '') continue;
        if ($fill === 'intro'  && !empty($P['intro_html'])) $blocks[$i]['text'] = $P['intro_html'];
        if ($fill === 'intro2' && !empty($S['intro_html'])) $blocks[$i]['text'] = $S['intro_html'];
        if ($fill === 'features' && !empty($P['feature_points'])) {
            $cols = [];
            foreach ($P['feature_points'] as $fp) {
                $cols[] = ['icon' => '', 'image' => '', 'alt' => $fp['heading'] ?? '', 'heading' => $fp['heading'] ?? '', 'text' => $fp['text'] ?? ''];
            }
            if ($cols) { $blocks[$i]['columns'] = $cols; $blocks[$i]['fc_num_cols'] = count($cols); }
        }
        if ($fill === 'faq') {
            $items = []; $seen = [];
            foreach (array_merge($P['faq'] ?? [], $S['faq'] ?? []) as $q) {
                $k = strtolower(trim($q['q'] ?? ''));
                if ($k === '' || isset($seen[$k])) continue;
                $seen[$k] = 1;
                $items[] = ['question' => $q['q'], 'answer' => $q['a']];
            }
            if ($items) $blocks[$i]['fq_items'] = array_slice($items, 0, 8);
        }
        if ($fill === 'local') {
            $note = $S['local_note'] ?? ($P['local_note'] ?? '');
            if ($note !== '') $blocks[$i]['mi_info_text'] = '<p>' . $note . '</p>';
        }
    }
    return $blocks;
}

/** True if the city×company page for this city is published under the current phase gate. */
function recovery_cc_published(string $stateSlug, string $citySlug): bool {
    $cfg = recovery_config();
    if (empty($cfg['phasing']['publish_city_company'])) return false;
    $c = recovery_city($stateSlug, $citySlug);
    return $c !== null && (int) ($c['population'] ?? 0) >= (int) ($cfg['phasing']['min_city_population'] ?? 0);
}

/** Map a carrier to the SAMHSA payment category it falls under. */
function recovery_carrier_paytype(string $slug): string {
    if ($slug === 'medicaid') return 'Medicaid';
    if ($slug === 'medicare') return 'Medicare';
    if ($slug === 'tricare')  return 'Military insurance';
    return 'Private insurance';
}

/**
 * Real facility-listings block (custom_html) from SAMHSA data for the matched city.
 * On carrier pages, filters to facilities accepting that carrier's payment category and
 * frames it honestly ("call to verify {carrier}") — SAMHSA doesn't confirm specific carriers.
 */
function recovery_facilities_block(array $m): ?array {
    // City page → that city's facilities; state page → aggregate the state's cities (deduped).
    $facs = [];
    if (!empty($m['city'])) {
        $facs = recovery_facilities($m['city']);
    } else {
        // state page → that state's cities; national (hub / company) → all cities. Deduped.
        $seen = [];
        foreach (recovery_cities() as $c) {
            if (!empty($m['state']) && ($c['state'] ?? '') !== $m['state']) continue;
            foreach (recovery_facilities($c['slug']) as $f) {
                $k = strtolower(($f['name'] ?? '') . '|' . ($f['city'] ?? ''));
                if ($k === '|' || isset($seen[$k])) continue;
                $seen[$k] = 1; $facs[] = $f;
            }
        }
    }
    if (!$facs) return null;

    $carrier = !empty($m['company']) ? (recovery_carrier($m['company'])['name'] ?? '') : '';
    if (!empty($m['company'])) {
        $pt = recovery_carrier_paytype($m['company']);
        $facs = array_values(array_filter($facs, fn($f) => in_array($pt, $f['payment'] ?? [], true)));
    }
    if (!$facs) return null;

    $sRow = recovery_state($m['state'] ?? '');
    if (!empty($m['city'])) {
        $cityName = recovery_city($m['state'], $m['city'])['name'] ?? $m['city'];
        $place = 'Near ' . htmlspecialchars($cityName) . ', ' . ($sRow['ss'] ?? '');
        $facs  = array_slice($facs, 0, 6);
        $heading = $carrier ? "Treatment Centers {$place} That Accept Insurance" : "Treatment Centers {$place}";
    } elseif (!empty($m['state'])) {
        $place = 'in ' . htmlspecialchars($sRow['name'] ?? $m['state']);
        $facs  = array_slice($facs, 0, 8);
        $heading = $carrier ? "Treatment Centers {$place} That Accept Insurance" : "Treatment Centers {$place}";
    } else {
        $facs  = array_slice($facs, 0, 8);   // national (hub / company_national)
        $heading = $carrier ? "Treatment Centers That Accept " . htmlspecialchars($carrier) . " Insurance" : "Featured Treatment Centers";
    }
    $note = 'Verified facility data from <a href="https://findtreatment.gov" target="_blank" rel="noopener">SAMHSA&rsquo;s FindTreatment.gov</a>. {business} is a free referral service'
          . ($carrier ? " &mdash; call each center to confirm " . htmlspecialchars($carrier) . " coverage." : ".");

    $cards = '';
    foreach ($facs as $f) {
        $addr  = htmlspecialchars(trim(($f['street'] ?? '') . ', ' . ($f['city'] ?? '') . ', ' . ($f['state'] ?? '') . ' ' . ($f['zip'] ?? ''), ', '));
        $tags  = '';
        foreach (($f['levels'] ?? []) as $lv) $tags .= '<span class="rd-tag">' . htmlspecialchars($lv) . '</span>';
        $pay   = htmlspecialchars(implode(' &middot; ', $f['payment'] ?? []));
        $tel   = preg_replace('/[^0-9]/', '', $f['phone'] ?? '');
        $miles = ($f['miles'] ?? '') !== '' ? '<span class="rd-miles">' . round((float) $f['miles'], 1) . ' mi</span>' : '';
        $dir   = (!empty($f['lat']) && !empty($f['lng'])) ? '<a class="rd-dir" href="https://maps.google.com/?q=' . $f['lat'] . ',' . $f['lng'] . '" target="_blank" rel="noopener">Get directions</a>' : '';
        $verify= $carrier ? '<p class="rd-verify">Accepts private insurance &mdash; call to verify ' . htmlspecialchars($carrier) . ' insurance coverage.</p>' : '';
        $cards .= '<div class="rd-card">'
            . '<div class="rd-badge"><span class="rd-verified">&#10003; SAMHSA-listed</span>' . $miles . '</div>'
            . '<div class="rd-info"><h3>' . htmlspecialchars($f['name'] ?? '') . '</h3>'
            . '<p class="rd-addr">&#128205; ' . $addr . '</p>'
            . ($tags ? '<div class="rd-tags">' . $tags . '</div>' : '')
            . ($pay ? '<p class="rd-pay"><strong>Accepts:</strong> ' . $pay . '</p>' : '')
            . $verify . '</div>'
            . '<div class="rd-cta"><a class="rd-phone" href="tel:' . $tel . '">&#128222; ' . htmlspecialchars($f['phone'] ?? '') . '</a>' . $dir . '</div>'
            . '</div>';
    }
    $css = '<style>.rd-listings{max-width:1040px;margin:0 auto}.rd-card{display:flex;gap:18px;border:1px solid #e2e8f0;border-radius:12px;padding:18px;margin-bottom:16px;background:#fff;flex-wrap:wrap;box-shadow:0 1px 3px rgba(0,0,0,.04)}.rd-badge{display:flex;flex-direction:column;gap:6px;align-items:center;justify-content:center;min-width:110px}.rd-verified{background:#e8f0fe;color:#1f5c86;font-size:12px;font-weight:700;padding:5px 12px;border-radius:999px;white-space:nowrap}.rd-miles{color:#64748b;font-size:12px}.rd-info{flex:1;min-width:230px}.rd-info h3{margin:0 0 6px;font-size:1.18rem;color:#0e2a45}.rd-addr{margin:0 0 8px;color:#475569;font-size:.92rem}.rd-tags{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:8px}.rd-tag{background:#0e2a45;color:#fff;font-size:12px;font-weight:600;padding:3px 10px;border-radius:6px}.rd-pay{margin:0 0 6px;font-size:.9rem;color:#334155}.rd-verify{margin:0;font-size:.85rem;color:#64748b;font-style:italic}.rd-cta{display:flex;flex-direction:column;gap:8px;justify-content:center;min-width:180px}.rd-phone{background:#1f5c86;color:#fff;font-weight:700;padding:12px 18px;border-radius:8px;text-decoration:none;text-align:center}.rd-dir{color:#1f5c86;text-align:center;font-size:.88rem;text-decoration:none}</style>';
    $html = '<div class="rd-listings">' . $css
        . '<h2 style="text-align:center;font-size:1.6rem;margin:0 0 8px;color:#0e2a45;">' . $heading . '</h2>'
        . '<p style="text-align:center;color:#64748b;max-width:740px;margin:0 auto 24px;font-size:.9rem;">' . $note . '</p>'
        . $cards . '</div>';
    return ['type' => 'custom_html', 'html' => $html];
}

function recovery_nav_block(array $m): ?array {
    $heading = '';
    $links   = [];   // [url, anchor]
    $st = $m['state'] ?? ''; $ci = $m['city'] ?? ''; $co = $m['company'] ?? '';
    $sRow = $st ? recovery_state($st) : null; $sName = $sRow['name'] ?? $st; $ss = $sRow['ss'] ?? '';
    $ciRow = ($st && $ci) ? recovery_city($st, $ci) : null; $ciName = $ciRow['name'] ?? $ci;
    $coRow = $co ? recovery_carrier($co) : null; $coName = $coRow['name'] ?? $co;

    switch ($m['type']) {
        case 'hub':
            $heading = 'Best Rehabs by Insurance Carrier';
            foreach (recovery_carriers() as $c) $links[] = ["/insurance/{$c['slug']}", "Best rehabs accepting {$c['name']}"];
            foreach (recovery_states() as $s)   $links[] = ["/{$s['slug']}", "Drug & alcohol rehabs in {$s['name']}"];
            break;
        case 'company_national':
            $heading = "Rehabs Accepting $coName by State";
            foreach (recovery_states() as $s) $links[] = ["/{$s['slug']}/$co", "Best rehabs accepting $coName in {$s['name']}"];
            break;
        case 'state':
            $heading = "Rehabs by City in $sName";
            foreach (recovery_cities() as $c) if (($c['state'] ?? '') === $st) $links[] = ["/$st/{$c['slug']}", "Drug & alcohol rehabs in {$c['name']}, $ss"];
            foreach (recovery_carriers() as $c) $links[] = ["/$st/{$c['slug']}", "Rehabs accepting {$c['name']} in $sName"];
            break;
        case 'city':
            // Link to city×company only if that level is published; else fall back to the
            // national carrier pages (which ARE published) so no link 404s under the gate.
            if (recovery_cc_published($st, $ci)) {
                $heading = "Rehabs by Insurance in $ciName, $ss";
                foreach (recovery_carriers() as $c) $links[] = ["/$st/$ci/{$c['slug']}", "Best rehabs accepting {$c['name']} in $ciName"];
            } else {
                $heading = 'Rehabs by Insurance Carrier';
                foreach (recovery_carriers() as $c) $links[] = ["/insurance/{$c['slug']}", "Best rehabs accepting {$c['name']}"];
            }
            break;
        case 'state_company':
            // City×company if published, else the city hubs (published).
            foreach (recovery_cities() as $c) {
                if (($c['state'] ?? '') !== $st) continue;
                if (recovery_cc_published($st, $c['slug'])) $links[] = ["/$st/{$c['slug']}/$co", "Rehabs accepting $coName in {$c['name']}, $ss"];
            }
            if ($links) { $heading = "Rehabs Accepting $coName by City in $sName"; }
            else {
                $heading = "Drug & Alcohol Rehabs by City in $sName";
                foreach (recovery_cities() as $c) if (($c['state'] ?? '') === $st) $links[] = ["/$st/{$c['slug']}", "Drug & alcohol rehabs in {$c['name']}, $ss"];
            }
            break;
        case 'city_company':
            $heading = 'Related';
            $links[] = ["/$st/$ci", "All drug & alcohol rehabs in $ciName, $ss"];
            $links[] = ["/insurance/$co", "Best rehabs accepting $coName nationwide"];
            $links[] = ["/$st/$co", "Best rehabs accepting $coName in $sName"];
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
