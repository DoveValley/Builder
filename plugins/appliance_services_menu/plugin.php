<?php
/**
 * Appliance Services Menu plugin — groups this site's service pages into the header
 * nav's mega-menu structure: one column per appliance TYPE (Refrigerator, Washer, ...),
 * each headed by that type's own generic page and listing its brand-specific combo
 * pages; a separate "Shop by Brand" list; and a small "Specialty" row for the minor
 * generic types (stove, garbage disposal, ice maker, ...).
 *
 * Appliance-only, deliberately isolated from plugins/services_links/plugin.php (used
 * by every other niche) — mold/water/pest never call anything in this file. The one
 * shared touch point is includes/headers/standard.php, which checks
 * function_exists('appliance_services_menu_groups') the same soft way it already
 * checks for services_links_resolve(), so a site that never uses this plugin's
 * sentinel is completely unaffected by its presence.
 *
 * Reuses services_links_resolve() (plugins/services_links/plugin.php) for the actual
 * per-domain page-existence check — this file only re-groups what that function
 * already resolved, so a page pruned by Page Pool or never built for this city can
 * never appear here either; there is exactly one source of truth for "does this page
 * really exist," not two that could drift.
 */

register_plugin(
    'appliance_services_menu',
    'Appliance Services Menu',
    'Groups this site\'s service pages into type/brand columns for the header nav mega-menu. Appliance-repair niche only.',
    '&#129469;',
    __DIR__
);

/** Canonical appliance types this niche's real column headers use, in a fixed display order. */
function _appliance_services_menu_type_labels(): array {
    return [
        'refrigerator' => 'Refrigerator Repair', 'washer'     => 'Washer Repair',
        'dryer'        => 'Dryer Repair',        'dishwasher' => 'Dishwasher Repair',
        'oven'         => 'Oven Repair',         'range'      => 'Range Repair',
        'cooktop'      => 'Cooktop Repair',      'freezer'    => 'Freezer Repair',
    ];
}

/** Minor/specialty appliance types — real pages, but too thin individually to earn their own column. */
function _appliance_services_menu_specialty_types(): array {
    return ['stove', 'garbage-disposal', 'ice-machine', 'ice-maker', 'wine-cooler', 'outdoor-grill', 'trash-compactor'];
}

/**
 * Group this site's resolved service links into the mega-menu shape.
 * @param array $data The site data array (same $data global standard.php already has).
 * @return array{groups:array,brands:array,specialty:array} Empty arrays if the
 *         dependencies this needs (services_links_resolve, appliance_derive_slug) —
 *         or this site's own services_links config — aren't present.
 */
function appliance_services_menu_groups(array $data): array {
    $empty = ['groups' => [], 'brands' => [], 'specialty' => []];
    if (!function_exists('services_links_resolve') || !function_exists('appliance_derive_slug')) return $empty;

    $cfg = $data['services_links'] ?? [];
    $rawServices = $cfg['services'] ?? [];
    if (!$rawServices) return $empty;

    // Real, per-domain-existing pages only — services_links_resolve() already checks
    // page-index.json, so anything Page Pool pruned or never built for this city is
    // already absent here. Keyed by name (its url is per-city resolved; the raw
    // config's own url pattern, used below, is what we classify by).
    $available = [];
    foreach (services_links_resolve($cfg) as [$name, $url]) { $available[$name] = $url; }
    if (!$available) return $empty;

    $typeLabels     = _appliance_services_menu_type_labels();
    $specialtyTypes = _appliance_services_menu_specialty_types();

    $typeGroups = [];   // canonical type => ['title'=>string, 'hub_url'=>string, 'items'=>[[label,url],...]]
    $brandHubs  = [];   // [[label,url],...]
    $specialty  = [];   // [[label,url],...]

    foreach ($rawServices as $svc) {
        $name = is_array($svc) ? trim($svc['name'] ?? '') : trim((string) $svc);
        if ($name === '' || !isset($available[$name])) continue;   // not built for this domain
        $url = $available[$name];

        $rawUrl = is_array($svc) ? trim($svc['url'] ?? '') : '';
        $base   = preg_replace('#-\{city_slug\}$#', '', ltrim($rawUrl, '/'));
        if ($base === '') continue;
        $d = appliance_derive_slug($base);

        if ($d['role'] === 'brand_hub') {
            $brandHubs[] = [$name, $url];
        } elseif ($d['role'] === 'type_hub') {
            $t = $d['appliance_type'];
            if (in_array($t, $specialtyTypes, true)) {
                $specialty[] = [$name, $url];
            } else {
                $typeGroups[$t]['title']   = $typeGroups[$t]['title']   ?? $name;
                $typeGroups[$t]['hub_url'] = $typeGroups[$t]['hub_url'] ?? $url;
                $typeGroups[$t]['items']   = $typeGroups[$t]['items']   ?? [];
            }
        } elseif ($d['role'] === 'leaf') {
            $t = $d['appliance_type'];
            $brandLabel = ucwords(str_replace('-', ' ', $d['brand']));
            if (in_array($t, $specialtyTypes, true)) {
                $specialty[] = [$name, $url];   // a brand + minor-type combo, rare
            } else {
                $typeGroups[$t]['items'][] = [$brandLabel, $url];
                $typeGroups[$t]['title']   = $typeGroups[$t]['title']   ?? ($typeLabels[$t] ?? ucwords(str_replace('-', ' ', $t)) . ' Repair');
                $typeGroups[$t]['hub_url'] = $typeGroups[$t]['hub_url'] ?? '';
            }
        }
    }

    // Fixed display order (the 8 canonical types); a type with zero pages on this
    // domain is simply absent, not an empty column.
    $groups = [];
    foreach ($typeLabels as $t => $label) {
        if (!isset($typeGroups[$t])) continue;
        $items = $typeGroups[$t]['items'];
        usort($items, fn($a, $b) => strcmp($a[0], $b[0]));
        $groups[] = [
            'title'   => $typeGroups[$t]['title'] ?: $label,
            'hub_url' => $typeGroups[$t]['hub_url'] ?: '#',
            'items'   => $items,
        ];
    }
    usort($brandHubs, fn($a, $b) => strcmp($a[0], $b[0]));
    usort($specialty, fn($a, $b) => strcmp($a[0], $b[0]));

    return ['groups' => $groups, 'brands' => $brandHubs, 'specialty' => $specialty];
}
