<?php
/**
 * Schema.org assembly + the publish step. Split from landing_page_clients.php
 * (CRUD) the same way includes/schema.php is split from includes/blocks.php
 * in the rest of this codebase — one file per concern.
 *
 * Deliberately stricter than the niche fleet's AI schema generator: no AI
 * involved here at all, pure templated code pulling only from verified,
 * human-entered client fields (hub address/phone, service labels) plus the
 * page's own authored FAQ content. See project_schema_ai_generator memory
 * for why Service+areaServed (never a LocalBusiness per city) is the rule.
 */

function lpc_find_by_id($list, $idKey, $id) {
    foreach ((array)$list as $item) {
        if (($item[$idKey] ?? null) === $id) return $item;
    }
    return null;
}

/**
 * Page ids follow the {city_id}__{service_id} convention (matches the
 * client's target_cities[].pages map) so schema assembly can look up the
 * right hub/service without the page JSON needing to duplicate those refs.
 */
function lpc_assemble_schema($client, $page_id, $page) {
    [$city_id, $service_id] = array_pad(explode('__', $page_id, 2), 2, '');

    $target  = lpc_find_by_id($client['target_cities'] ?? [], 'city_id', $city_id);
    $hub     = $target ? lpc_find_by_id($client['hubs'] ?? [], 'hub_id', $target['served_by_hub'] ?? null) : null;
    $service = lpc_find_by_id($client['services'] ?? [], 'service_id', $service_id);

    $domain     = $client['identity']['domain'] ?? '';
    $businessId = 'https://' . $domain . '/#business-' . ($hub['hub_id'] ?? 'unknown');

    $graph = [];

    if ($hub) {
        $business = [
            '@type'     => 'LocalBusiness',
            '@id'       => $businessId,
            'name'      => $client['identity']['business_name'] ?? '',
            'telephone' => $hub['phone'] ?? '',
            'address'   => [
                '@type'           => 'PostalAddress',
                'streetAddress'   => $hub['address']['street'] ?? '',
                'addressLocality' => $hub['address']['city'] ?? '',
                'addressRegion'   => $hub['address']['state'] ?? '',
                'postalCode'      => $hub['address']['zip'] ?? '',
            ],
        ];
        if (!empty($hub['geo']['lat']) && !empty($hub['geo']['lng'])) {
            $business['geo'] = [
                '@type'     => 'GeoCoordinates',
                'latitude'  => $hub['geo']['lat'],
                'longitude' => $hub['geo']['lng'],
            ];
        }
        $graph[] = $business;
    }

    $basePath = rtrim($client['delivery']['base_path'] ?? '/locations', '/');
    $serviceNode = [
        '@type'      => 'Service',
        // Missing on this node before — real pre-deployment review finding:
        // a Service entity with no url of its own is a gap, not just untidy,
        // for structured data describing content that actually lives at a
        // real URL.
        'url'        => 'https://' . $domain . $basePath . '/' . $page_id,
        'name'       => $service['label'] ?? $service_id,
        'areaServed' => trim(($target['city'] ?? '') . ', ' . ($target['state'] ?? ''), ', '),
    ];
    // Omit the key entirely when there's no hub, rather than a literal
    // "provider":null — same real finding as above, independently flagged
    // twice in pre-deployment review; every other optional field in this
    // function already follows the omit-don't-null convention (the LocalBusiness
    // entity itself, geo) except this one had been missed.
    if ($hub) {
        $serviceNode['provider'] = ['@id' => $businessId];
    }
    $graph[] = $serviceNode;

    // FAQPage — mechanically derived, never hand-authored, so it can never
    // drift out of sync with the page's actual FAQ section.
    foreach ($page['sections'] ?? [] as $section) {
        if (($section['type'] ?? '') !== 'faq' || empty($section['items'])) continue;
        $mainEntity = [];
        foreach ($section['items'] as $item) {
            $mainEntity[] = [
                '@type'          => 'Question',
                'name'           => $item['q'] ?? '',
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $item['a'] ?? ''],
            ];
        }
        if ($mainEntity) {
            $graph[] = ['@type' => 'FAQPage', 'mainEntity' => $mainEntity];
        }
        break;
    }

    return ['@context' => 'https://schema.org', '@graph' => $graph];
}

/**
 * Sibling services at the same city, same convention as the design doc's
 * related_links spec — "auto-computed server-side, not authored." Only
 * links to siblings that are themselves published, so a related_links block
 * never points at a page that would 404. Recomputed at every publish (see
 * lpc_publish_page()), the same "resolved at generation, not render" pattern
 * this project already uses elsewhere for schema — not computed live per
 * visit, and not something the editor lets anyone hand-edit.
 *
 * The original design also mentioned a link back to the hub itself, but
 * there's no hub-URL field anywhere in the client schema to link to (hubs
 * have an address/phone, not a page we control) — sibling-service links
 * only, for now.
 */
function lpc_compute_related_links($client, $page_id) {
    [$city_id, $service_id] = array_pad(explode('__', $page_id, 2), 2, '');
    $target = lpc_find_by_id($client['target_cities'] ?? [], 'city_id', $city_id);
    if (!$target) return [];

    $basePath = rtrim($client['delivery']['base_path'] ?? '/locations', '/');
    $links = [];
    foreach ($target['services'] ?? [] as $siblingServiceId) {
        if ($siblingServiceId === $service_id) continue;
        $siblingPageId = $city_id . '__' . $siblingServiceId;
        $siblingPage = lpc_load_page($client['client_id'], $siblingPageId);
        if (!$siblingPage || $siblingPage['status'] !== 'published') continue;
        $service = lpc_find_by_id($client['services'] ?? [], 'service_id', $siblingServiceId);
        $links[] = [
            'label' => $service['label'] ?? $siblingServiceId,
            'url'   => $basePath . '/' . $siblingPageId,
        ];
    }
    return $links;
}

/**
 * Plain facts about this page's service area — state, which hub metro
 * serves it, and which other target cities share that same hub ("nearby
 * cities"). Deliberately NOT links yet (parked per Scott, 2026-11-15 — come
 * back to cross-page internal linking later); this is display-only text,
 * same auto-computed-fresh-every-publish rule as lpc_compute_related_links()
 * just above, pulling straight from client.json's hubs[]/target_cities[]
 * rather than being hand-typed or AI-authored.
 */
function lpc_compute_service_area($client, $page_id) {
    [$city_id, $service_id] = array_pad(explode('__', $page_id, 2), 2, '');
    $target = lpc_find_by_id($client['target_cities'] ?? [], 'city_id', $city_id);
    if (!$target) return ['city' => '', 'state' => '', 'office_city' => '', 'nearby_cities' => []];

    $hub = lpc_find_by_id($client['hubs'] ?? [], 'hub_id', $target['served_by_hub'] ?? null);
    // Hub address city is hand-typed in the wizard with no case normalization
    // enforced there (confirmed: real data has "southlake", not "Southlake") —
    // ucwords() here only, so the raw field isn't silently rewritten on save.
    $officeCity = $hub ? trim(ucwords($hub['address']['city'] ?? '') . ', ' . ($hub['address']['state'] ?? ''), ', ') : '';

    // Real links, 2026-11-15 — same-service, nearby-city (the "city cluster"
    // pattern), same safety rule lpc_compute_related_links() already proves
    // out: only ever link to a page that's actually published, never a
    // guess. A nearby city without that service published yet still shows
    // (label with no url) — service-area breadth is real information even
    // before every combination has its own page; it just isn't a link yet.
    $basePath = rtrim($client['delivery']['base_path'] ?? '/locations', '/');
    $nearby = [];
    foreach ($client['target_cities'] ?? [] as $tc) {
        if ($tc['city_id'] === $city_id) continue;
        if (($tc['served_by_hub'] ?? null) !== ($target['served_by_hub'] ?? null)) continue;
        $label = trim(($tc['city'] ?? '') . ', ' . ($tc['state'] ?? ''), ', ');
        $url = null;
        if ($service_id !== '' && in_array($service_id, $tc['services'] ?? [], true)) {
            $siblingPageId = $tc['city_id'] . '__' . $service_id;
            $siblingPage = lpc_load_page($client['client_id'], $siblingPageId);
            if ($siblingPage && $siblingPage['status'] === 'published') {
                $url = $basePath . '/' . $siblingPageId;
            }
        }
        $nearby[] = ['label' => $label, 'url' => $url];
    }

    return [
        'city'           => $target['city'] ?? '',
        'state'          => $target['state'] ?? '',
        'office_city'    => $officeCity,
        'nearby_cities'  => $nearby,
    ];
}

/**
 * Preview Site mode's sibling list — same lookup as lpc_compute_related_links()
 * above, deliberately WITHOUT the "must be published" filter. Preview Site
 * mode exists to review the whole site as it will look once everything is
 * published, not just what's already live, so a draft sibling should still
 * show up here (linking into the preview itself, not a real /locations/ URL
 * that may not exist yet) with its status visible.
 */
function lpc_preview_sibling_links($client, $page_id, $previewBaseUrl) {
    [$city_id, $service_id] = array_pad(explode('__', $page_id, 2), 2, '');
    $target = lpc_find_by_id($client['target_cities'] ?? [], 'city_id', $city_id);
    if (!$target) return [];

    $links = [];
    foreach ($target['services'] ?? [] as $siblingServiceId) {
        if ($siblingServiceId === $service_id) continue;
        $siblingPageId = $city_id . '__' . $siblingServiceId;
        $siblingPage = lpc_load_page($client['client_id'], $siblingPageId);
        $service = lpc_find_by_id($client['services'] ?? [], 'service_id', $siblingServiceId);
        $links[] = [
            'label'  => $service['label'] ?? $siblingServiceId,
            'url'    => $previewBaseUrl . '&page=' . rawurlencode($siblingPageId),
            'status' => $siblingPage['status'] ?? 'not_created',
        ];
    }
    return $links;
}

/**
 * Mirrors wp-plugin/landing-page-client/includes/renderer.php's rules
 * exactly (bare semantic HTML, no CSS classes, content starts at H2 — never
 * H1, proven the hard way against a real theme) using plain htmlspecialchars
 * instead of WordPress's esc_* functions, since this runs in the admin panel,
 * not inside WordPress. If that file's rendering rules ever change, this
 * needs the matching update — there is no way to literally share the code
 * across a WP plugin and this non-WP admin panel. Shared by the single-page
 * editor's own Preview toggle and the multi-page Preview Site mode, rather
 * than each maintaining its own copy.
 */
function lpc_preview_render($sections) {
    $out = '';
    foreach ($sections as $s) {
        switch ($s['type'] ?? '') {
            case 'hero':
                $out .= '<section><h2>' . h($s['headline']) . '</h2>';
                if ($s['subheading']) $out .= '<p>' . h($s['subheading']) . '</p>';
                if (!empty($s['image'])) {
                    $dims = (!empty($s['image_width']) && !empty($s['image_height'])) ? ' width="' . (int) $s['image_width'] . '" height="' . (int) $s['image_height'] . '"' : '';
                    $out .= '<figure><img src="' . h($s['image']) . '" alt="' . h($s['image_alt'] ?? '') . '"' . $dims . ' loading="lazy"></figure>';
                }
                if ($s['phone']) $out .= '<p><a href="tel:">' . h($s['cta_text'] ?: $s['phone']) . '</a></p>';
                $out .= '</section>';
                break;
            case 'trust_bar':
                if (empty($s['items'])) break;
                $out .= '<section><ul>';
                foreach ($s['items'] as $item) $out .= '<li>&#10003; ' . h($item) . '</li>';
                $out .= '</ul></section>';
                break;
            case 'text':
                $out .= '<section>' . ($s['heading'] ? '<h2>' . h($s['heading']) . '</h2>' : '') . '<p>' . h($s['body']) . '</p></section>';
                break;
            case 'comparison':
                if (empty($s['items_a']) && empty($s['items_b'])) break;
                $out .= '<section>' . ($s['heading'] ? '<h2>' . h($s['heading']) . '</h2>' : '');
                if (!empty($s['intro'])) $out .= '<p>' . h($s['intro']) . '</p>';
                $out .= '<div><strong>' . h($s['label_a'] ?? '') . '</strong><ul>';
                foreach ($s['items_a'] ?? [] as $item) $out .= '<li>' . h($item) . '</li>';
                $out .= '</ul></div><div><strong>' . h($s['label_b'] ?? '') . '</strong><ul>';
                foreach ($s['items_b'] ?? [] as $item) $out .= '<li>' . h($item) . '</li>';
                $out .= '</ul></div></section>';
                break;
            case 'highlights':
            case 'services_list':
                $out .= '<section>' . ($s['heading'] ? '<h2>' . h($s['heading']) . '</h2>' : '') . '<ul>';
                foreach ($s['items'] as $item) $out .= '<li><strong>' . h($item['title']) . '</strong> — ' . h($item['text']) . '</li>';
                $out .= '</ul></section>';
                break;
            case 'steps':
                $out .= '<section>' . ($s['heading'] ? '<h2>' . h($s['heading']) . '</h2>' : '') . '<ol>';
                foreach ($s['items'] as $item) $out .= '<li><strong>' . h($item['title']) . '</strong> — ' . h($item['text']) . '</li>';
                $out .= '</ol></section>';
                break;
            case 'faq':
                $out .= '<section>' . ($s['heading'] ? '<h2>' . h($s['heading']) . '</h2>' : '');
                foreach ($s['items'] as $item) $out .= '<h3>' . h($item['q']) . '</h3><p>' . h($item['a']) . '</p>';
                $out .= '</section>';
                break;
            case 'cta':
                $out .= '<section>' . ($s['heading'] ? '<h2>' . h($s['heading']) . '</h2>' : '') . '<p>' . h($s['body']) . '</p>';
                if ($s['phone']) $out .= '<p><a href="tel:">' . h($s['button_text'] ?: $s['phone']) . '</a></p>';
                $out .= '</section>';
                break;
            case 'safety_toolkit':
                if (empty($s['items'])) break;
                $out .= '<section>' . ($s['heading'] ? '<h2>' . h($s['heading']) . '</h2>' : '');
                if (!empty($s['intro'])) $out .= '<p>' . h($s['intro']) . '</p>';
                $out .= '<ul>';
                foreach ($s['items'] as $item) {
                    $out .= '<li><strong>' . h($item['heading'] ?? '') . '</strong> — ' . h($item['text'] ?? '')
                        . ($item['link'] ? ' <a href="' . h($item['link']) . '">' . h($item['btn_text'] ?: 'Learn More') . '</a>' : '')
                        . '</li>';
                }
                $out .= '</ul></section>';
                break;
            case 'related_links':
                if (empty($s['links'])) break;
                $out .= '<section>' . ($s['heading'] ? '<h2>' . h($s['heading']) . '</h2>' : '') . '<ul>';
                foreach ($s['links'] as $link) $out .= '<li><a href="' . h($link['url']) . '">' . h($link['label']) . '</a></li>';
                $out .= '</ul></section>';
                break;
            case 'service_area':
                $out .= '<section>' . ($s['heading'] ? '<h2>' . h($s['heading']) . '</h2>' : '');
                if (!empty($s['intro'])) $out .= '<p>' . h($s['intro']) . '</p>';
                $out .= '<ul>';
                if (!empty($s['state'])) $out .= '<li>State: ' . h($s['state']) . '</li>';
                if (!empty($s['office_city'])) $out .= '<li>Office City: ' . h($s['office_city']) . '</li>';
                if (!empty($s['nearby_cities'])) {
                    $parts = array_map(fn($nc) => $nc['url'] ? '<a href="' . h($nc['url']) . '">' . h($nc['label']) . '</a>' : h($nc['label']), $s['nearby_cities']);
                    $out .= '<li>Nearby Cities: ' . implode(', ', $parts) . '</li>'; // phpcs:ignore -- each part already escaped above
                }
                $out .= '</ul></section>';
                break;
            case 'area_map':
                if (empty($s['image'])) break;
                $dims = (!empty($s['image_width']) && !empty($s['image_height'])) ? ' width="' . (int) $s['image_width'] . '" height="' . (int) $s['image_height'] . '"' : '';
                $out .= '<section>' . ($s['heading'] ? '<h2>' . h($s['heading']) . '</h2>' : '')
                    . '<figure><img src="' . h($s['image']) . '" alt="' . h($s['image_alt'] ?? '') . '"' . $dims . ' loading="lazy"></figure></section>';
                break;
            case 'data_chart':
                if (empty($s['image'])) break;
                $dims = (!empty($s['image_width']) && !empty($s['image_height'])) ? ' width="' . (int) $s['image_width'] . '" height="' . (int) $s['image_height'] . '"' : '';
                $out .= '<section>' . ($s['heading'] ? '<h2>' . h($s['heading']) . '</h2>' : '')
                    . '<figure><img src="' . h($s['image']) . '" alt="' . h($s['image_alt'] ?? '') . '"' . $dims . ' loading="lazy"></figure>'
                    . ($s['table_html'] ?? '') . '</section>'; // phpcs:ignore -- city_chart_table_html() already escapes every value itself
                break;
        }
    }
    return $out;
}

function lpc_manifest_path($client_id) {
    return LPC_DELIVERY_DATA_DIR . 'clients/' . $client_id . '/manifest.json';
}

function lpc_delivery_page_path($client_id, $page_id) {
    return LPC_DELIVERY_DATA_DIR . 'clients/' . $client_id . '/pages/' . $page_id . '.json';
}

/**
 * Pushes the entire local staging tree (LPC_DELIVERY_DATA_DIR) to the real
 * dedicated delivery server (Box 1) via rsync-over-SSH, using the
 * narrowly-scoped sync key (see config.php's LPC_DELIVERY_SYNC_KEY comment
 * for why this is a separate key from the root fleet-wide one). Whole-tree
 * sync rather than tracking individual changed files — the data is a
 * handful of small JSON files per client, so this is cheap, and it's
 * self-healing (any drift between staging and remote corrects itself on
 * the next call) rather than needing careful per-file change tracking.
 *
 * Host-key checking is disabled deliberately for this one call: www-data's
 * home directory isn't writable (so ssh/rsync can't persist known_hosts
 * there anyway), and this is a single, fixed, already-verified destination
 * — not a general-purpose SSH client connecting to arbitrary hosts.
 */
function lpc_sync_delivery_to_remote() {
    $cmd = sprintf(
        'rsync -az -e %s %s %s@%s:%s 2>&1',
        escapeshellarg('ssh -i ' . LPC_DELIVERY_SYNC_KEY . ' -o UserKnownHostsFile=/dev/null -o StrictHostKeyChecking=no -o ConnectTimeout=10'),
        escapeshellarg(LPC_DELIVERY_DATA_DIR),
        escapeshellarg(LPC_DELIVERY_REMOTE_USER),
        escapeshellarg(LPC_DELIVERY_REMOTE_HOST),
        escapeshellarg(LPC_DELIVERY_REMOTE_PATH)
    );
    exec($cmd, $output, $exitCode);
    if ($exitCode !== 0) {
        error_log('landing-page-clients: delivery sync to remote failed (exit ' . $exitCode . '): ' . implode(' | ', $output));
        return false;
    }
    return true;
}

/**
 * Rebuilds clients/{id}/manifest.json on the delivery server from every
 * published page — the list the plugin's hub nav reads. Deliberately
 * excludes draft/needs_update pages so nothing half-finished appears on a
 * client's live site.
 */
function lpc_rebuild_manifest($client_id) {
    $client = lpc_load_client($client_id);
    if (!$client) return false;

    $pages = [];
    foreach (lpc_list_pages($client_id) as $page_id) {
        $page = lpc_load_page($client_id, $page_id);
        if (!$page || $page['status'] !== 'published') continue;

        [$city_id, $service_id] = array_pad(explode('__', $page_id, 2), 2, '');
        $target  = lpc_find_by_id($client['target_cities'] ?? [], 'city_id', $city_id);
        $service = lpc_find_by_id($client['services'] ?? [], 'service_id', $service_id);
        $hub     = lpc_find_by_id($client['hubs'] ?? [], 'hub_id', $target['served_by_hub'] ?? null);

        $pages[] = [
            'slug'      => $page_id,
            'title'     => $page['seo']['page_title'] ?? $page_id,
            'city'      => $target['city'] ?? '',
            'state'     => $target['state'] ?? '',
            'service'   => $service['label'] ?? $service_id,
            // Lets the hub page group State > Hub Area > City > Services
            // instead of one flat, unlabeled grid — hub_city/hub_state come
            // from the serving hub's own address, not the client-facing
            // hub "name" field (which is often a contact person's name, e.g.
            // "Scott Parr", not a location — unsuitable as a group label).
            'hub_city'  => $hub['address']['city'] ?? '',
            'hub_state' => $hub['address']['state'] ?? '',
        ];
    }

    $manifest = [
        'client_id'     => $client_id,
        'business_name' => $client['identity']['business_name'] ?? '',
        'updated_at'    => gmdate('c'),
        // Lets the plugin style its own output (hero/cards/buttons) using the
        // real client's brand colors instead of inventing a look of its own —
        // this is the only consumer of these fields; the admin Branding tab
        // itself is otherwise just internal labeling, per the original design.
        'branding'      => [
            'primary_color' => $client['branding']['primary_color'] ?? '',
            'accent_color'  => $client['branding']['accent_color'] ?? '',
            'font'          => $client['branding']['font'] ?? '',
        ],
        'settings'      => [
            'base_path'                => $client['delivery']['base_path'] ?? '/locations',
            'refresh_interval_minutes' => $client['delivery']['refresh_interval_minutes'] ?? 360,
            'support_phone'            => $client['delivery']['support_phone'] ?? '',
            'support_message'          => $client['delivery']['support_message'] ?? '',
            'nav_link_placement'       => $client['delivery']['nav_link_placement'] ?? 'footer',
            'nav_link_label'           => $client['delivery']['nav_link_label'] ?? 'Service Area',
            'hub_heading'              => $client['delivery']['hub_heading'] ?? '',
            'hub_intro'                => $client['delivery']['hub_intro'] ?? '',
            'hub_footer_text'          => $client['delivery']['hub_footer_text'] ?? '',
        ],
        'pages' => $pages,
    ];

    if (!lpc_atomic_write_json(lpc_manifest_path($client_id), $manifest)) return false;
    return lpc_sync_delivery_to_remote();
}

/**
 * Publishing one page: assemble its schema, write it to this client's data
 * file (git-tracked source of truth), stage it into the local delivery
 * mirror, then rebuild the manifest — which also rsyncs the whole staging
 * tree out to the real dedicated delivery server (Box 1, see config.php).
 */
function lpc_publish_page($client_id, $page_id) {
    $client = lpc_load_client($client_id);
    $page   = lpc_load_page($client_id, $page_id);
    if (!$client || !$page) return false;

    $page['schema_jsonld'] = lpc_assemble_schema($client, $page_id, $page);
    $niche_brief = lpc_ai_niche_brief($client['content_profile']['source'] ?? '');
    foreach ($page['sections'] as &$section) {
        if (($section['type'] ?? '') === 'trust_bar') {
            $items = $niche_brief['trust_bar_items'] ?? [];
            $section['items'] = !empty($items) ? $items : lpc_default_trust_bar_items();
        }
        if (($section['type'] ?? '') === 'safety_toolkit') {
            $items = lpc_safety_toolkit_items($niche_brief['niche_id'] ?? '', $client_id);
            // Icons are raw inline SVG markup — sanitized here (sanitize_svg(),
            // includes/helpers.php) before they ever reach the delivered page
            // JSON, same rule this codebase already applies to every other
            // SVG it stores. The WP plugin side has no equivalent sanitizer of
            // its own (it's a separate WordPress codebase), so this is the one
            // point in the pipeline that can do it.
            foreach ($items as &$item) {
                if (!empty($item['icon'])) $item['icon'] = sanitize_svg($item['icon']);
            }
            unset($item);
            $section['items'] = $items;
        }
        if (($section['type'] ?? '') === 'related_links') {
            $section['links'] = lpc_compute_related_links($client, $page_id);
        }
        if (($section['type'] ?? '') === 'service_area') {
            $area = lpc_compute_service_area($client, $page_id);
            $section['state']         = $area['state'];
            $section['office_city']   = $area['office_city'];
            $section['nearby_cities'] = $area['nearby_cities'];
            // Recomputed fresh every publish, same as the facts above — a hub
            // reassignment or a newly added nearby city should show up here on
            // the next publish without needing a manual edit.
            $business = $client['identity']['business_name'] ?: 'We';
            $cityState = trim($area['city'] . ', ' . $area['state'], ', ');
            $section['intro'] = $cityState !== ''
                ? "{$business} proudly serves {$cityState} and the surrounding area."
                : '';
        }
        if (($section['type'] ?? '') === 'area_map') {
            [$city_id, ] = array_pad(explode('__', $page_id, 2), 2, '');
            $target = lpc_find_by_id($client['target_cities'] ?? [], 'city_id', $city_id);
            $map = $target ? lpc_resolve_area_map($client_id, $city_id, $target['city'], $target['state'], $client['branding'] ?? []) : null;
            // Never overwrites a manually-set image — same "only fill if blank"
            // rule lpc_fill_service_image() already uses, in case an admin ever
            // pastes a manual override here. A city with no real map data
            // (unresolved coords, no nearby towns on record) simply keeps
            // whatever it had before rather than clearing a working image.
            if ($map && empty($section['image'])) {
                $section['image']        = $map['url'];
                $section['image_alt']    = $map['alt'];
                $section['image_width']  = $map['width'];
                $section['image_height'] = $map['height'];
            }
        }
        if (($section['type'] ?? '') === 'data_chart') {
            [$city_id, ] = array_pad(explode('__', $page_id, 2), 2, '');
            $target = lpc_find_by_id($client['target_cities'] ?? [], 'city_id', $city_id);
            $chart = ($target && $niche_brief)
                ? lpc_resolve_data_chart($client_id, $city_id, $target['city'], $target['state'], $niche_brief['niche_id'] ?? '', $client['branding'] ?? [])
                : null;
            // Read-only — never fetches. A city nobody has run "Fetch Climate
            // Data" for yet simply renders nothing, same as area_map's own
            // "no real data, no image" rule, not a blank/broken section.
            if ($chart) {
                $section['heading']      = $chart['heading'];
                $section['image']        = $chart['url'];
                $section['image_alt']    = $chart['alt'];
                $section['image_width']  = $chart['width'];
                $section['image_height'] = $chart['height'];
                $section['table_html']   = $chart['table_html'];
            } else {
                $section['image'] = '';
                $section['table_html'] = '';
            }
        }
    }
    unset($section);

    // Re-resolved at publish time, not just at generation time, so a service's
    // image can change later and reach already-drafted pages on their next
    // publish without needing an AI Regenerate — only fills blanks, so a
    // manual per-page image override is never overwritten.
    [, $service_id] = array_pad(explode('__', $page_id, 2), 2, '');
    $service = lpc_find_by_id($client['services'] ?? [], 'service_id', $service_id);
    if ($service) {
        $altText = $page['seo']['primary_keyword'] ?: $service['label'];
        lpc_fill_service_image($page, $client_id, $service, $altText);
    }
    $page['status']        = 'published';
    $page['published_at']  = $page['published_at'] ?: gmdate('c');
    $page['updated_at']    = gmdate('c');

    if (!lpc_save_page($client_id, $page_id, $page)) return false;
    if (!lpc_atomic_write_json(lpc_delivery_page_path($client_id, $page_id), $page)) return false;

    lpc_ensure_delivery_key($client_id);

    return lpc_rebuild_manifest($client_id);
}

/**
 * Fetches the REAL live page and checks what it's actually serving against
 * what our own data says it should be — closing a real blind spot found
 * live (2026-11-16): the admin dashboard's own completeness check
 * (lpc_page_completeness_warnings()) only ever looks at data on OUR side,
 * so it reported 27/27 pages perfect while several were still visibly
 * serving a stale placeholder photo on the real site — the client's own
 * WordPress plugin caches each page independently (up to its refresh
 * interval), so different pages can be at different points in that cache
 * lifecycle at any given moment. "Looks good on the dashboard" and "correct
 * on the live site" are two different claims; this is the one check that
 * actually verifies the second one instead of assuming it follows from the
 * first.
 *
 * Compares by filename hash, not full URL — the WP plugin's own image cache
 * (lpc_cache_image_locally(), wp-plugin/landing-page-client/includes/
 * image_cache.php) names each cached file 'lpc-' + md5(remote URL), so the
 * live HTML's own <img> src already encodes exactly which remote image the
 * site last fetched, without needing to download and diff the actual bytes.
 *
 * Result is cached onto the client record (not the page content file, which
 * stays purely canonical/synced content, and not session-only like the
 * keyword-check cache, since this needs to stay visible across reloads the
 * way Research/Climate Data's own cached results already do).
 */
function lpc_check_live_page($client_id, $page_id) {
    $client = lpc_load_client($client_id);
    if (!$client) return ['ok' => false, 'msg' => 'Client not found'];

    $domain = trim($client['identity']['domain'] ?? '');
    if ($domain === '') return ['ok' => false, 'msg' => 'No domain set for this client'];

    $page = lpc_load_page($client_id, $page_id);
    if (!$page) return ['ok' => false, 'msg' => 'Page not found'];
    if (($page['status'] ?? '') !== 'published') return ['ok' => false, 'msg' => 'Page is not published'];

    $basePath = trim($client['delivery']['base_path'] ?? '/locations', '/');
    $url = 'https://' . $domain . '/' . $basePath . '/' . rawurlencode($page_id) . '/';

    // PHP's curl extension, not file_get_contents() — gives explicit control
    // over timeout/UA/SSL rather than relying on stream-context defaults.
    // NOTE (2026-11-16): a real investigation here initially suspected
    // file_get_contents() itself was getting a different (stale) response
    // than curl — it wasn't. Running shell curl and PHP curl in the same
    // second showed the real cause: multirestoration1.kinsta.cloud resolves
    // to MULTIPLE distinct Cloudflare edge IPs, and they can have different
    // cache states at any given moment — a fresh connection can land on
    // either one essentially at random. A "stale" result here can mean
    // "this specific edge node hasn't picked up the purge yet," which
    // normally clears on its own as Cloudflare's purge finishes propagating
    // — not necessarily a real, permanent problem. Re-running the check a
    // few minutes later is a legitimate way to confirm which one it is.
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 12,
        // A real browser User-Agent, not a self-identifying one — found
        // live (2026-11-16): a custom UA that announces itself as an
        // automated checker ("LPC-LiveCheck/1.0") got served a cached
        // response (cf-cache-status: HIT, up to 24h old) by Kinsta's own
        // infrastructure, while a plain browser-style UA to the SAME edge
        // node at the SAME moment got DYNAMIC/MISS every time. This isn't
        // our own no-cache headers failing — it's Kinsta/Cloudflare
        // reasonably caching anything that doesn't look like a real visitor
        // (bot/crawler traffic), which a "Check Live" tool needs to NOT look
        // like, since its entire point is seeing what a real visitor sees.
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $html = curl_exec($ch);
    curl_close($ch);
    if ($html === false || $html === '') {
        return ['ok' => false, 'msg' => 'Could not reach the live page', 'stale' => null];
    }

    $staleTypes = [];
    $figureClass = ['hero' => 'lpc-hero-image', 'area_map' => 'lpc-area-map', 'data_chart' => 'lpc-data-chart'];
    foreach ($page['sections'] as $section) {
        $type = $section['type'] ?? '';
        if (!isset($figureClass[$type])) continue;
        $expectedUrl = $section['image'] ?? '';
        if ($expectedUrl === '') continue; // already flagged by lpc_page_completeness_warnings()
        $expectedHash = 'lpc-' . md5($expectedUrl);

        if (!preg_match('/class="' . preg_quote($figureClass[$type], '/') . '"[^>]*>.*?<img[^>]*src="([^"]*)"/s', $html, $m)) {
            $staleTypes[] = ucfirst(str_replace('_', ' ', $type)) . ' not found on the live page';
            continue;
        }
        if (strpos($m[1], $expectedHash) === false) {
            $staleTypes[] = ucfirst(str_replace('_', ' ', $type)) . ' is stale on the live site (still serving an older cached image)';
        }
    }

    $result = ['ok' => true, 'checked_at' => gmdate('c'), 'stale' => !empty($staleTypes), 'issues' => $staleTypes];

    $client['live_check'][$page_id] = $result;
    lpc_save_client($client_id, $client);

    return $result;
}

/**
 * Calls the client's own WordPress site directly — the one place in this
 * codebase that reaches INTO a client's site rather than the other way
 * around. Only works once that site is running a plugin build that actually
 * registers wp-json/lpc/v1/refresh (2026-11-22 or later); older installs
 * 404, which is reported as-is rather than guessed at.
 */
function lpc_trigger_remote_refresh($client_id) {
    $client = lpc_load_client($client_id);
    if (!$client) return ['ok' => false, 'msg' => 'Client not found'];

    $domain = trim($client['identity']['domain'] ?? '');
    if ($domain === '') return ['ok' => false, 'msg' => 'No domain set for this client'];

    $key = lpc_get_client_api_key($client_id);
    if ($key === '') return ['ok' => false, 'msg' => 'No API key set for this client'];

    $url = 'https://' . $domain . '/wp-json/lpc/v1/refresh';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query(['key' => $key]),
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $body = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false) {
        return ['ok' => false, 'msg' => 'Could not reach the live site'];
    }
    if ($httpCode === 404) {
        return ['ok' => false, 'msg' => 'This site is running an older plugin version that doesn\'t support remote refresh yet — redistribute the plugin zip from the Delivery tab'];
    }
    $data = json_decode($body, true);
    if ($httpCode !== 200 || !is_array($data) || empty($data['ok'])) {
        return ['ok' => false, 'msg' => 'The site rejected the request (HTTP ' . $httpCode . ')'];
    }
    return ['ok' => true, 'msg' => 'Refreshed'];
}

/**
 * Keeps the delivery server's API-key lookup in sync with this client's
 * actual key/active flag. Temporary hand-authored _keys.json lookup (Phase
 * 1 scaffolding) — every publish call refreshes this client's one entry
 * rather than replacing the whole file, so other clients' entries survive.
 */
function lpc_ensure_delivery_key($client_id) {
    $keysFile = LPC_DELIVERY_DATA_DIR . '_keys.json';
    $keys = file_exists($keysFile) ? (json_decode(file_get_contents($keysFile), true) ?: []) : [];

    // Drop any existing entry for this client before re-adding, so a key
    // rotation (Phase 4 feature, not built yet) can't leave the old key
    // live alongside the new one.
    foreach ($keys as $k => $v) {
        if (($v['client_id'] ?? null) === $client_id) unset($keys[$k]);
    }

    $client = lpc_load_client($client_id);
    $key = lpc_get_client_api_key($client_id);
    if ($key === '') return false;

    $keys[$key] = [
        'client_id' => $client_id,
        'active'    => !empty($client['delivery']['active']),
    ];

    if (!lpc_atomic_write_json($keysFile, $keys)) return false;
    return lpc_sync_delivery_to_remote();
}

/**
 * Connected / Stale / Never Connected, same thresholding the plugin itself
 * uses (overdue past 2x the refresh interval). Fetched live from the
 * delivery server's own check-in record over HTTP (action=status) — it's a
 * genuinely separate machine (Box 1), so the local staging copy of the data
 * dir is never updated by a real check-in and can't be read directly here.
 * If the delivery server can't be reached at all, that's treated the same
 * as Stale: a client whose plugin can't check in is down either way.
 */
function lpc_plugin_status($client_id) {
    $client = lpc_load_client($client_id);
    if (!$client || !lpc_valid_client_id($client_id)) return 'Never Connected';

    $apiKey = lpc_get_client_api_key($client_id);
    if ($apiKey === '') return 'Never Connected';

    $url = LPC_DELIVERY_PUBLIC_BASE . '?action=status&key=' . urlencode($apiKey);
    $context = stream_context_create(['http' => ['timeout' => 3, 'ignore_errors' => true]]);
    $raw = @file_get_contents($url, false, $context);
    $status = $raw !== false ? (json_decode($raw, true) ?: []) : [];

    $lastCheckin = (int) ($status['last_checkin'] ?? 0);
    if ($lastCheckin === 0) {
        return $raw === false ? 'Stale' : 'Never Connected';
    }

    $intervalMinutes = (int) ($client['delivery']['refresh_interval_minutes'] ?? 360);
    $ageMinutes = (time() - $lastCheckin) / 60;

    return $ageMinutes <= ($intervalMinutes * 2) ? 'Connected' : 'Stale';
}
