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

    $graph[] = [
        '@type'      => 'Service',
        'name'       => $service['label'] ?? $service_id,
        'areaServed' => trim(($target['city'] ?? '') . ', ' . ($target['state'] ?? ''), ', '),
        'provider'   => $hub ? ['@id' => $businessId] : null,
    ];

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

function lpc_manifest_path($client_id) {
    return LPC_DELIVERY_DATA_DIR . 'clients/' . $client_id . '/manifest.json';
}

function lpc_delivery_page_path($client_id, $page_id) {
    return LPC_DELIVERY_DATA_DIR . 'clients/' . $client_id . '/pages/' . $page_id . '.json';
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

        $pages[] = [
            'slug'    => $page_id,
            'title'   => $page['seo']['page_title'] ?? $page_id,
            'city'    => $target['city'] ?? '',
            'state'   => $target['state'] ?? '',
            'service' => $service['label'] ?? $service_id,
        ];
    }

    $manifest = [
        'client_id'     => $client_id,
        'business_name' => $client['identity']['business_name'] ?? '',
        'updated_at'    => gmdate('c'),
        'settings'      => [
            'base_path'                => $client['delivery']['base_path'] ?? '/locations',
            'refresh_interval_minutes' => $client['delivery']['refresh_interval_minutes'] ?? 360,
            'support_phone'            => $client['delivery']['support_phone'] ?? '',
            'support_message'          => $client['delivery']['support_message'] ?? '',
        ],
        'pages' => $pages,
    ];

    return lpc_atomic_write_json(lpc_manifest_path($client_id), $manifest);
}

/**
 * Publishing one page: assemble its schema, write it to this client's data
 * file (git-tracked source of truth), copy it out to the delivery server
 * (today: a local file write; once the delivery server is remote, this is
 * the one call site that changes to an includes/multisite/deploy.php push),
 * then rebuild the manifest so the hub nav picks it up.
 */
function lpc_publish_page($client_id, $page_id) {
    $client = lpc_load_client($client_id);
    $page   = lpc_load_page($client_id, $page_id);
    if (!$client || !$page) return false;

    $page['schema_jsonld'] = lpc_assemble_schema($client, $page_id, $page);
    $page['status']        = 'published';
    $page['published_at']  = $page['published_at'] ?: gmdate('c');
    $page['updated_at']    = gmdate('c');

    if (!lpc_save_page($client_id, $page_id, $page)) return false;
    if (!lpc_atomic_write_json(lpc_delivery_page_path($client_id, $page_id), $page)) return false;

    lpc_ensure_delivery_key($client_id);

    return lpc_rebuild_manifest($client_id);
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

    return lpc_atomic_write_json($keysFile, $keys);
}

/**
 * Connected / Stale / Never Connected, same thresholding the plugin itself
 * uses (overdue past 2x the refresh interval). Reads the delivery server's
 * own check-in record directly — same-box file read today; becomes a small
 * sync step once the delivery server is a separate machine, not a redesign.
 */
function lpc_plugin_status($client_id) {
    $client = lpc_load_client($client_id);
    if (!$client || !lpc_valid_client_id($client_id)) return 'Never Connected';
    $statusFile = LPC_DELIVERY_DATA_DIR . 'clients/' . $client_id . '/_status.json';

    if (!file_exists($statusFile)) {
        return 'Never Connected';
    }

    $status = json_decode(file_get_contents($statusFile), true) ?: [];
    $lastCheckin = (int) ($status['last_checkin'] ?? 0);
    if ($lastCheckin === 0) {
        return 'Never Connected';
    }

    $intervalMinutes = (int) ($client['delivery']['refresh_interval_minutes'] ?? 360);
    $ageMinutes = (time() - $lastCheckin) / 60;

    return $ageMinutes <= ($intervalMinutes * 2) ? 'Connected' : 'Stale';
}
