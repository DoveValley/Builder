<?php
// Appliance-repair taxonomy — derive brand + appliance_type + page role from a
// landing slug. The slug is the single source of truth (nothing stored in the
// keyword map), so this can never drift and survives Keywords-tab re-saves.
// Used by the silo hub-generation and the brand/appliance-filtered service links.

/** Known brands, most-specific first so "ge-monogram" wins over "ge". */
function appliance_brands(): array {
    return [
        'ge-monogram', 'sub-zero', 'whirlpool', 'maytag', 'ge', 'kitchenaid',
        'lg', 'samsung', 'frigidaire', 'bosch', 'kenmore', 'amana', 'jennair',
        'dacor', 'wolf', 'viking', 'thermador', 'miele',
    ];
}

/** Fold synonym appliance types onto one canonical key (so the "washing machine"
 *  hub and the "{brand} washer" leaves share an appliance_type and link up). */
function appliance_type_canonical(string $t): string {
    static $map = ['washing-machine' => 'washer'];
    return $map[$t] ?? $t;
}

/**
 * Classify a landing slug.
 * @return array{role:string,brand:string,appliance_type:string}
 *   role ∈ home | type_hub | brand_hub | leaf
 */
function appliance_derive_slug(string $slug): array {
    $s = preg_replace('/-repair$/', '', trim($slug));   // strip trailing -repair
    $brand = '';
    $rest  = $s;
    foreach (appliance_brands() as $b) {
        if ($s === $b || strncmp($s, $b . '-', strlen($b) + 1) === 0) {
            $brand = $b;
            $rest  = ltrim(substr($s, strlen($b)), '-');
            break;
        }
    }
    if ($brand === '') {
        if ($s === 'appliance') return ['role' => 'home', 'brand' => '', 'appliance_type' => ''];
        return ['role' => 'type_hub', 'brand' => '', 'appliance_type' => appliance_type_canonical($s)];
    }
    if ($rest === '' || $rest === 'appliance') {
        return ['role' => 'brand_hub', 'brand' => $brand, 'appliance_type' => ''];
    }
    return ['role' => 'leaf', 'brand' => $brand, 'appliance_type' => appliance_type_canonical($rest)];
}
