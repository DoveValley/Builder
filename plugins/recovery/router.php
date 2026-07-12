<?php
/**
 * Recovery plugin — URL matcher.
 *
 * Maps a raw request path to one of the 6 matrix page types, or null when the path
 * is NOT part of the recovery matrix (core routing then handles it as usual).
 *
 * URL structure (frozen — see memory project_recovery_insurance_site):
 *   /insurance/                 -> hub
 *   /insurance/{company}        -> company_national
 *   /{state}                    -> state
 *   /{state}/{city}             -> city
 *   /{state}/{company}          -> state_company
 *   /{state}/{city}/{company}   -> city_company
 *
 * A bare 2nd/3rd segment (city vs company) is disambiguated by known-carrier
 * lookup — safe because all slugs are closed sets we own.
 *
 * Returns: ['type'=>..., 'state'=>?slug, 'city'=>?slug, 'company'=>?slug]  or null.
 */

require_once __DIR__ . '/data.php';

function recovery_match_route(string $path): ?array {
    $path = trim($path, '/');
    if ($path === '') return null;
    $seg = explode('/', $path);
    $n   = count($seg);

    // ── /insurance ... (hub + national company page) ──
    if ($seg[0] === 'insurance') {
        if ($n === 1) return ['type' => 'hub'];
        if ($n === 2 && recovery_is_carrier($seg[1])) {
            return ['type' => 'company_national', 'company' => $seg[1]];
        }
        return null;
    }

    // ── /{state} ... (state / city / company matrix) ──
    if (recovery_state($seg[0]) === null) return null;   // not a known state -> not ours
    $state = $seg[0];

    if ($n === 1) return ['type' => 'state', 'state' => $state];

    if ($n === 2) {
        if (recovery_is_carrier($seg[1])) {
            return ['type' => 'state_company', 'state' => $state, 'company' => $seg[1]];
        }
        if (recovery_city($state, $seg[1]) !== null) {
            return ['type' => 'city', 'state' => $state, 'city' => $seg[1]];
        }
        return null;
    }

    if ($n === 3) {
        if (recovery_city($state, $seg[1]) !== null && recovery_is_carrier($seg[2])) {
            return ['type' => 'city_company', 'state' => $state, 'city' => $seg[1], 'company' => $seg[2]];
        }
        return null;
    }

    return null;   // 4+ segments -> not a matrix URL
}
