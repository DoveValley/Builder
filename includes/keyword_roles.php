<?php
// Keyword page-role derivation for the Keywords tab structure summary + badges.
// Niche-agnostic by default (Home / Hub / Leaf, inferred from slug-token nesting
// + section). When the map is the appliance set, a precise 4-role refinement
// (Home / Type Hub / Brand Hub / Leaf) plugs in via includes/appliance_taxonomy.php.
require_once __DIR__ . '/appliance_taxonomy.php';

/**
 * @return array{mode:string, roles:array<string,array{key:string,label:string}>,
 *               counts:array<string,int>, byBrand:array, byType:array}
 */
function keyword_map_roles(array $map): array {
    $services = $map['services'] ?? [];
    $niche    = strtolower(trim($map['niche'] ?? ''));

    // ── Appliance-precise mode ──────────────────────────────────────────────
    if (strpos($niche, 'appliance') !== false) {
        $labels = ['home' => 'Home', 'type_hub' => 'Type Hub', 'brand_hub' => 'Brand Hub', 'leaf' => 'Leaf'];
        $roles = []; $counts = []; $byBrand = []; $byType = [];
        foreach ($services as $s) {
            $slug = $s['slug'] ?? '';
            $d    = appliance_derive_slug($slug);
            $lab  = $labels[$d['role']] ?? 'Leaf';
            $roles[$slug] = ['key' => $d['role'], 'label' => $lab];
            $counts[$lab] = ($counts[$lab] ?? 0) + 1;
            if ($d['role'] === 'leaf') {
                if ($d['brand'])          $byBrand[$d['brand']]          = ($byBrand[$d['brand']] ?? 0) + 1;
                if ($d['appliance_type']) $byType[$d['appliance_type']] = ($byType[$d['appliance_type']] ?? 0) + 1;
            }
        }
        ksort($byBrand); ksort($byType);
        return ['mode' => 'appliance', 'roles' => $roles, 'counts' => $counts, 'byBrand' => $byBrand, 'byType' => $byType];
    }

    // ── Generic mode ────────────────────────────────────────────────────────
    // Tokenise slugs; drop the "universal" token (present in every slug, e.g.
    // "repair") so the service word doesn't swamp the nesting test.
    $tok = [];
    foreach ($services as $s) { $tok[$s['slug'] ?? ''] = array_fill_keys(array_filter(explode('-', $s['slug'] ?? '')), true); }
    $universal = null;
    foreach ($tok as $set) { $universal = ($universal === null) ? $set : array_intersect_key($universal, $set); if (!$universal) break; }
    $universal = $universal ?: [];
    $core = [];
    foreach ($tok as $slug => $set) { $core[$slug] = array_diff_key($set, $universal); }

    $roles = []; $counts = [];
    foreach ($services as $s) {
        $slug = $s['slug'] ?? '';
        if (($s['section'] ?? 'landing') === 'home') {
            $roles[$slug] = ['key' => 'home', 'label' => 'Home'];
            $counts['Home'] = ($counts['Home'] ?? 0) + 1;
            continue;
        }
        // Hub = a broader term nested inside >=2 other services' core tokens.
        $t = $core[$slug]; $isHub = false; $n = 0;
        if (count($t) > 0) {
            foreach ($core as $os => $oset) {
                if ($os === $slug) continue;
                if (count($t) < count($oset) && !array_diff_key($t, $oset)) { if (++$n >= 2) { $isHub = true; break; } }
            }
        }
        $lab = $isHub ? 'Hub' : 'Leaf';
        $roles[$slug] = ['key' => $isHub ? 'hub' : 'leaf', 'label' => $lab];
        $counts[$lab] = ($counts[$lab] ?? 0) + 1;
    }
    return ['mode' => 'generic', 'roles' => $roles, 'counts' => $counts, 'byBrand' => [], 'byType' => []];
}

/** Small inline style for a role chip, keyed by role label. */
function keyword_role_chip_style(string $label): string {
    $c = [
        'Home'      => '#0369a1',
        'Type Hub'  => '#7c3aed',
        'Brand Hub' => '#be185d',
        'Hub'       => '#7c3aed',
        'Leaf'      => '#475569',
    ][$label] ?? '#475569';
    return 'display:inline-block;padding:1px 8px;background:' . $c . ';color:#fff;border-radius:10px;font-size:.68rem;font-weight:700;vertical-align:middle;';
}
