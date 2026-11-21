<?php
/**
 * includes/pluscities_niche_library.php — the real niche library from
 * docs/PLUSCITIES_FEATURE_SPEC.md Section 2, replacing includes/niche_library.php's
 * schema outright rather than sitting alongside it as a second, richer config.
 *
 * Supersedes niche_library.php: that file was deliberately narrow (copy-in
 * only, business-model-agnostic, no real keyword data — "costs money, goes
 * stale, only matters for the fleet's own domain-buying decision"). That
 * restriction only made sense while the library was narrow by design. Now
 * that this IS the real shared asset the architecture note in the spec
 * describes — sitting above both PlusCities and the Site Factory — excluding
 * real keyword data would be the half-measure, not keeping it. Real keyword
 * validation lives here, shared, validated once per niche via the same
 * infra_kw_score() formula the fleet already uses (admin/infra/lib/keywords.php).
 *
 * Two levels: Family sets defaults, Niche inherits and overrides — spec
 * Section 2. A third, separate shape — Compliance Preset — is a named,
 * reusable config referenced by family and swappable per niche.
 *
 * Same conventions as every other entity loader in this codebase: atomic
 * tmp-file-then-rename writes, deep-merge-against-defaults on every load so a
 * field added later never breaks a file written before it existed.
 */

function pluscities_valid_id($id)
{
    return is_string($id) && preg_match('/^[a-z0-9][a-z0-9-]{0,59}$/', $id) === 1;
}

function pluscities_atomic_write_json(string $file, array $data): bool
{
    $dir = dirname($file);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) return false;
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) return false;
    $tmp = $file . '.tmp.' . getmypid();
    if (file_put_contents($tmp, $json) === false) return false;
    return rename($tmp, $file);
}

function pluscities_deep_merge_defaults(array $data, array $defaults): array
{
    foreach ($defaults as $key => $value) {
        if (!array_key_exists($key, $data)) $data[$key] = $value;
    }
    return $data;
}

// ── Families ────────────────────────────────────────────────────────────

/** The 6 fixed families from spec Section 2 — not user-creatable, hand-authored. */
const PLUSCITIES_FAMILY_IDS = [
    'home-trades', 'outdoor-cleaning', 'restoration',
    'legal', 'medical-wellness', 'professional-services',
];

function pluscities_family_defaults(): array
{
    return [
        'family_id'              => '',
        'label'                  => '',
        'schema_type_family'     => '',
        'default_city_blocks'    => [],   // baseline pool, before a niche's blocks_off denylist
        'compliance_preset'      => '',   // preset_id, this family's default
        'approved_sources'       => [],   // baseline trusted domains
        'default_radius_minutes' => 25,
        'research_topics'        => [],   // which of the standard city/service topics apply
    ];
}

function pluscities_family_file(string $family_id): ?string
{
    if (!pluscities_valid_id($family_id)) return null;
    return PLUSCITIES_NICHE_FAMILIES_DIR . $family_id . '.json';
}

function pluscities_family_load(string $family_id): ?array
{
    $file = pluscities_family_file($family_id);
    if ($file === null || !file_exists($file)) return null;
    $data = json_decode((string) file_get_contents($file), true);
    if (!is_array($data)) return null;
    return pluscities_deep_merge_defaults($data, pluscities_family_defaults());
}

function pluscities_family_save(string $family_id, array $data): bool
{
    $file = pluscities_family_file($family_id);
    if ($file === null) return false;
    $data['family_id'] = $family_id;
    return pluscities_atomic_write_json($file, pluscities_deep_merge_defaults($data, pluscities_family_defaults()));
}

function pluscities_family_list(): array
{
    if (!is_dir(PLUSCITIES_NICHE_FAMILIES_DIR)) return [];
    $ids = [];
    foreach (glob(PLUSCITIES_NICHE_FAMILIES_DIR . '*.json') as $f) $ids[] = basename($f, '.json');
    sort($ids);
    return $ids;
}

// ── Compliance presets ──────────────────────────────────────────────────

function pluscities_preset_defaults(): array
{
    return [
        'preset_id'             => '',
        'label'                 => '',
        'banned_phrases'        => [],
        'validator_rule_toggles' => [],   // e.g. {show_license: true, no_unverifiable_pricing: true, no_outcome_claims: true}
    ];
}

function pluscities_preset_file(string $preset_id): ?string
{
    if (!pluscities_valid_id($preset_id)) return null;
    return PLUSCITIES_COMPLIANCE_PRESETS_DIR . $preset_id . '.json';
}

function pluscities_preset_load(string $preset_id): ?array
{
    $file = pluscities_preset_file($preset_id);
    if ($file === null || !file_exists($file)) return null;
    $data = json_decode((string) file_get_contents($file), true);
    if (!is_array($data)) return null;
    return pluscities_deep_merge_defaults($data, pluscities_preset_defaults());
}

function pluscities_preset_save(string $preset_id, array $data): bool
{
    $file = pluscities_preset_file($preset_id);
    if ($file === null) return false;
    $data['preset_id'] = $preset_id;
    return pluscities_atomic_write_json($file, pluscities_deep_merge_defaults($data, pluscities_preset_defaults()));
}

// ── Niche blueprints ────────────────────────────────────────────────────

function pluscities_niche_defaults(): array
{
    return [
        'niche_id'       => '',
        'family_id'      => '',
        'label'          => '',
        'schema_subtype' => '',   // real schema.org vocabulary, e.g. HVACBusiness

        // Kept from the old niche_library.php shape — genuinely shared,
        // business-model-agnostic facts, still true regardless of who's
        // consuming them.
        'service_noun'      => '',
        'local_angle'       => '',
        'seasonal_calendar' => '',

        // Keyword-validated service list — spec Section 2's "each service
        // carries a real keyword profile, not a fill-in-the-blank pattern".
        // [{slug, label, primary_keyword, primary_keyword_data:{volume,kd,cpc,score,checked_at}, secondary_keywords:[]}]
        'services' => [],

        'blocks_off'             => [],   // denylist against the family's default_city_blocks
        'approved_sources_extra' => [],   // on top of the family baseline
        'research_topics'        => [],   // subset of the family's relevant topics this niche actually uses
        'custom_research_fields' => [],   // [{key, ask}] — same shape generate.py's niche_research_fields() already reads
        'niche_table'            => null, // {columns:[...], rows:[[...]], source_topic: null} or null — one real reference table per niche, shared by every page (spec Section 5's "niche data table" service-pool block)
        'radius_minutes_override' => null,
        'compliance_preset_override' => null, // null = inherit the family's preset

        'trust_bar_items' => [],
        'blog_topics'     => [],   // [{slug, title, focus_keyword, tag, related_topics:[]}]
        'match_keywords'  => [],   // LPC-only: auto-matches a new client's typed industry to this niche

        // One real stock photo per image slot a page actually has, shared by
        // every client in this niche — "research once, reuse everywhere"
        // applied to photos instead of facts. A client's own real upload or
        // branded version (includes/pluscities_images.php) always takes
        // priority over this; this is only the bottom-of-the-chain default.
        // [{slot_id, label, width, height, default_image_path}]
        'image_slots' => [],

        'created_at' => '',
        'updated_at' => '',
    ];
}

function pluscities_niche_file(string $niche_id): ?string
{
    if (!pluscities_valid_id($niche_id)) return null;
    return PLUSCITIES_NICHE_BLUEPRINTS_DIR . $niche_id . '.json';
}

function pluscities_niche_load(string $niche_id): ?array
{
    $file = pluscities_niche_file($niche_id);
    if ($file === null || !file_exists($file)) return null;
    $data = json_decode((string) file_get_contents($file), true);
    if (!is_array($data)) return null;
    return pluscities_deep_merge_defaults($data, pluscities_niche_defaults());
}

function pluscities_niche_save(string $niche_id, array $data): bool
{
    $file = pluscities_niche_file($niche_id);
    if ($file === null) return false;
    $data['niche_id']   = $niche_id;
    $data['updated_at'] = gmdate('c');
    if (empty($data['created_at'])) {
        $existing = pluscities_niche_load($niche_id);
        $data['created_at'] = $existing['created_at'] ?? gmdate('c');
    }
    return pluscities_atomic_write_json($file, pluscities_deep_merge_defaults($data, pluscities_niche_defaults()));
}

function pluscities_niche_list(): array
{
    if (!is_dir(PLUSCITIES_NICHE_BLUEPRINTS_DIR)) return [];
    $ids = [];
    foreach (glob(PLUSCITIES_NICHE_BLUEPRINTS_DIR . '*.json') as $f) $ids[] = basename($f, '.json');
    sort($ids);
    return $ids;
}

/**
 * The one function everything else in the engine calls — never the raw
 * family/niche files directly. Resolves a niche against its family's
 * defaults (family fields first, niche fields override), per spec Section 2:
 * "Family sets defaults; Niche inherits and overrides."
 *
 * @return array|null null if the niche or its family doesn't exist
 */
function pluscities_niche_resolved(string $niche_id): ?array
{
    $niche = pluscities_niche_load($niche_id);
    if ($niche === null) return null;
    $family = pluscities_family_load($niche['family_id']);
    if ($family === null) return null;

    // City blocks: family's default pool, minus the niche's denylist.
    $cityBlocks = array_values(array_diff($family['default_city_blocks'], $niche['blocks_off']));

    return [
        'niche_id'       => $niche['niche_id'],
        'family_id'      => $family['family_id'],
        'label'          => $niche['label'],
        'schema_subtype' => $niche['schema_subtype'],
        'schema_type_family' => $family['schema_type_family'],
        'service_noun'      => $niche['service_noun'],
        'local_angle'       => $niche['local_angle'],
        'seasonal_calendar' => $niche['seasonal_calendar'],
        'services'          => $niche['services'],
        'city_blocks'       => $cityBlocks,
        'approved_sources'  => array_values(array_unique(array_merge(
            $family['approved_sources'], $niche['approved_sources_extra']
        ))),
        'research_topics'   => $niche['research_topics'] ?: $family['research_topics'],
        'custom_research_fields' => $niche['custom_research_fields'],
        'niche_table'       => $niche['niche_table'],
        'radius_minutes'    => $niche['radius_minutes_override'] ?? $family['default_radius_minutes'],
        'compliance_preset' => pluscities_preset_load(
            $niche['compliance_preset_override'] ?: $family['compliance_preset']
        ),
        'trust_bar_items'   => $niche['trust_bar_items'],
        'blog_topics'       => $niche['blog_topics'],
        'match_keywords'    => $niche['match_keywords'],
        'image_slots'       => $niche['image_slots'],
    ];
}

/**
 * Validates one service's primary keyword against real search data, using
 * the SAME shared module the fleet already uses for its own city-expansion
 * decisions (admin/infra/lib/keywords.php) — per CLAUDE.md's "one module per
 * service, reused everywhere" rule, not a second parallel keyword system
 * built just for PlusCities.
 *
 * Runs ONCE per niche-service when authored, not per page — writes the
 * result into that niche blueprint's services[].primary_keyword_data. Every
 * client's city pages for that service then reuse this validated keyword,
 * with the city name appended at generation time (spec Section 2).
 *
 * @return array{ok:bool,msg:string} — ok=false (e.g. no provider credentials
 *         configured) is a real, expected outcome in a fresh environment,
 *         not an error to crash on; the service keeps its label as a
 *         fallback until this is re-run once credentials exist.
 */
function pluscities_validate_service_keyword(string $niche_id, string $service_slug, string $provider = 'dataforseo'): array
{
    $niche = pluscities_niche_load($niche_id);
    if ($niche === null) return ['ok' => false, 'msg' => 'Niche not found.'];

    $idx = null;
    foreach ($niche['services'] as $i => $svc) {
        if (($svc['slug'] ?? '') === $service_slug) { $idx = $i; break; }
    }
    if ($idx === null) return ['ok' => false, 'msg' => 'Service not found on this niche.'];

    $phrase = $niche['services'][$idx]['primary_keyword'] ?: $niche['services'][$idx]['label'];
    if (!function_exists('infra_kw_fetch')) return ['ok' => false, 'msg' => 'Keyword module not loaded.'];

    $result = infra_kw_fetch($provider, [$phrase]);
    if (!($result['ok'] ?? false) || empty($result['rows'])) {
        return ['ok' => false, 'msg' => $result['msg'] ?? 'No data returned for "' . $phrase . '".'];
    }
    $row   = $result['rows'][0];
    $score = infra_kw_score($row, $provider);

    $niche['services'][$idx]['primary_keyword_data'] = [
        'volume'     => $row['volume'] ?? null,
        'kd'         => $row['kd'] ?? null,
        'cpc'        => $row['cpc'] ?? null,
        'score'      => $score,
        'provider'   => $provider,
        'checked_at' => gmdate('c'),
    ];
    pluscities_niche_save($niche_id, $niche);
    return ['ok' => true, 'msg' => 'Validated "' . $phrase . '" — score ' . ($score ?? 'n/a') . '/10.'];
}
