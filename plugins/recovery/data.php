<?php
/**
 * Recovery plugin — matrix data + config loaders.
 *
 * The recovery-insurance site is a programmatic directory: its pages are the
 * cross-product of STATES × CITIES × CARRIERS. That matrix + its config live in the
 * ACTIVE site's own data dir (NOT in core), so it never touches the shared factory:
 *
 *   sites/{site}/data/recovery/carriers.json  [{slug,name}, ...]   (~25 carriers)
 *   sites/{site}/data/recovery/states.json    [{slug,name,ss}, ...]
 *   sites/{site}/data/recovery/cities.json    [{slug,name,state,population?}, ...]
 *   sites/{site}/data/recovery/config.json    { templates:{type=>template_id}, phasing:{} }
 *
 * The 6 page types map to TEMPLATES in the site's normal templates.json (authored
 * with the full block editor + AI in the Templates tab) — we only store which
 * template id each type uses. Nothing here re-implements editing or generation.
 *
 * Loaders fail soft (missing file -> []).
 */

function recovery_data_dir(): string {
    // ACTIVE_SITE_DIR is set by config.php in multi-site mode; fall back to root data/.
    $base = (defined('ACTIVE_SITE_DIR') && ACTIVE_SITE_DIR !== '') ? ACTIVE_SITE_DIR : BASE_DIR;
    return $base . '/data/recovery/';
}

function _recovery_load_json(string $file): array {
    static $cache = [];   // memoize per request/build (state/national pages read facilities.json many times)
    if (array_key_exists($file, $cache)) return $cache[$file];
    $path = recovery_data_dir() . $file;
    $rows = is_file($path) ? (json_decode(file_get_contents($path), true) ?: []) : [];
    return $cache[$file] = (is_array($rows) ? $rows : []);
}

function recovery_carriers(): array { return _recovery_load_json('carriers.json'); }
function recovery_states():   array { return _recovery_load_json('states.json'); }
function recovery_cities():   array { return _recovery_load_json('cities.json'); }

/** slug => row lookups. */
function recovery_carrier(string $slug): ?array {
    foreach (recovery_carriers() as $r) if (($r['slug'] ?? '') === $slug) return $r;
    return null;
}
function recovery_state(string $slug): ?array {
    foreach (recovery_states() as $r) if (($r['slug'] ?? '') === $slug) return $r;
    return null;
}
function recovery_city(string $stateSlug, string $citySlug): ?array {
    foreach (recovery_cities() as $r) {
        if (($r['slug'] ?? '') === $citySlug && ($r['state'] ?? '') === $stateSlug) return $r;
    }
    return null;
}
function recovery_is_carrier(string $slug): bool { return recovery_carrier($slug) !== null; }

// ── The 6 page types (single source of truth) ───────────────────────────────
function recovery_types(): array {
    return [
        'hub'              => ['label' => 'Insurance hub',        'url' => '/insurance/'],
        'company_national' => ['label' => 'Company — national',   'url' => '/insurance/{company}'],
        'state'            => ['label' => 'State',                'url' => '/{state}'],
        'city'             => ['label' => 'City',                 'url' => '/{state}/{city}'],
        'state_company'    => ['label' => 'State × company',      'url' => '/{state}/{company}'],
        'city_company'     => ['label' => 'City × company',       'url' => '/{state}/{city}/{company}'],
    ];
}

// ── Config (type→template map + phasing) ─────────────────────────────────────
function recovery_config(): array {
    $cfg = _recovery_load_json('config.json');
    $cfg['templates'] = ($cfg['templates'] ?? []) + [
        'hub' => '', 'company_national' => '', 'state' => '',
        'city' => '', 'state_company' => '', 'city_company' => '',
    ];
    $cfg['phasing'] = ($cfg['phasing'] ?? []) + [
        'publish_city_company' => true, 'min_city_population' => 0,
    ];
    return $cfg;
}
function recovery_save_config(array $cfg): bool {
    return _recovery_write_json('config.json', $cfg);
}
function _recovery_write_json(string $file, $data): bool {
    $dir = recovery_data_dir();
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    return $json !== false && file_put_contents($dir . $file, $json) !== false;
}
/** Persist a matrix list (carriers/states/cities). Re-indexes to a clean array. */
function recovery_save_rows(string $file, array $rows): bool {
    return _recovery_write_json($file, array_values($rows));
}

// ── Templates (read the site's normal templates.json — same file the Templates tab edits) ──
function recovery_all_templates(): array {
    $path = defined('TEMPLATES_FILE') ? TEMPLATES_FILE : '';
    if ($path === '' || !is_file($path)) return [];
    $t = json_decode(file_get_contents($path), true);
    return is_array($t) ? $t : [];
}
function recovery_template_by_id(string $id): ?array {
    if ($id === '') return null;
    foreach (recovery_all_templates() as $t) if (($t['id'] ?? '') === $id) return $t;
    return null;
}

// ── SAMHSA facility listings (real .gov data), keyed by city slug ────────────
function recovery_facilities(string $citySlug): array {
    if ($citySlug === '') return [];
    $all = _recovery_load_json('facilities.json');
    return $all[$citySlug] ?? [];
}

// ── Per-intersection AI bundles (unique city×carrier / state×carrier copy) ───
// Keyed "{state}/{city}/{company}" and "{state}/{company}". Falls back to composed
// entity fragments (recovery_ai_sources) when an intersection bundle is absent.
function recovery_intersection_ai(string $key): ?array {
    $all = _recovery_load_json('ai_intersections.json');
    $b = $all[$key] ?? null;
    return (is_array($b) && !empty($b['intro_html'])) ? $b : null;
}

// ── Target keywords per page type (REFERENCE ONLY — do not generate pages) ───
function recovery_keywords(): array {
    $k = _recovery_load_json('keywords.json');
    return $k['types'] ?? [];
}

// ── Per-request entity context (drives {company}* tokens; see plugin.php) ────
function recovery_set_ctx(array $ctx): void { $GLOBALS['_recovery_ctx'] = $ctx; }
function recovery_ctx(): array { return $GLOBALS['_recovery_ctx'] ?? []; }
