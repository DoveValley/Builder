<?php
/**
 * admin/pluscities_niches_save.php — save handler for admin/pluscities_niches.php.
 * Same CSRF/role gate as admin/niche_profiles_save.php.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/infra/lib/keywords.php'; // infra_kw_fetch()/infra_kw_score() — validate_keyword action

if (empty($_SESSION['admin_logged_in'])) { header('Location: login.php'); exit; }
if (lpc_current_actor_role() !== 'super_admin') {
    header('Location: clients.php?msg=error:The+niche+library+is+managed+by+the+platform+only');
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: pluscities_niches.php'); exit; }

$token = $_POST['csrf_token'] ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
    header('Location: pluscities_niches.php?msg=error:Invalid+request+token');
    exit;
}

/** "a, b , c" -> ['a','b','c'], blanks dropped. */
function pluscities_split_csv_field(string $raw): array
{
    return array_values(array_filter(array_map('trim', explode(',', $raw)), fn($v) => $v !== ''));
}

$action = $_POST['action'] ?? '';

if ($action === 'save_preset') {
    $presetId = trim($_POST['existing_preset_id'] ?? '') ?: trim($_POST['preset_id'] ?? '');
    if (!pluscities_valid_id($presetId)) {
        header('Location: pluscities_niches.php?msg=error:Invalid+preset+ID'); exit;
    }
    $toggles = [];
    foreach (pluscities_split_csv_field((string) ($_POST['validator_rule_toggles'] ?? '')) as $key) {
        $toggles[$key] = true;
    }
    pluscities_preset_save($presetId, [
        'label'                  => trim($_POST['label'] ?? ''),
        'banned_phrases'         => pluscities_split_csv_field((string) ($_POST['banned_phrases'] ?? '')),
        'validator_rule_toggles' => $toggles,
    ]);
    header('Location: pluscities_niches.php?msg=success:Preset+saved'); exit;
}

if ($action === 'save_family') {
    $familyId = trim($_POST['existing_family_id'] ?? '') ?: trim($_POST['family_id'] ?? '');
    if (!in_array($familyId, PLUSCITIES_FAMILY_IDS, true)) {
        header('Location: pluscities_niches.php?msg=error:Invalid+family+ID'); exit;
    }
    pluscities_family_save($familyId, [
        'label'                  => trim($_POST['label'] ?? ''),
        'schema_type_family'     => trim($_POST['schema_type_family'] ?? ''),
        'compliance_preset'      => trim($_POST['compliance_preset'] ?? ''),
        'default_radius_minutes' => max(1, (int) ($_POST['default_radius_minutes'] ?? 25)),
        'default_city_blocks'    => pluscities_split_csv_field((string) ($_POST['default_city_blocks'] ?? '')),
        'approved_sources'       => pluscities_split_csv_field((string) ($_POST['approved_sources'] ?? '')),
        'research_topics'        => pluscities_split_csv_field((string) ($_POST['research_topics'] ?? '')),
    ]);
    header('Location: pluscities_niches.php?msg=success:Family+saved&edit_family=' . urlencode($familyId)); exit;
}

if ($action === 'save_niche') {
    $nicheId = trim($_POST['existing_niche_id'] ?? '') ?: trim($_POST['niche_id'] ?? '');
    if (!pluscities_valid_id($nicheId)) {
        header('Location: pluscities_niches.php?msg=error:Invalid+niche+ID'); exit;
    }
    $existing = pluscities_niche_load($nicheId);

    // Services: "slug|label|primary_keyword" per line. Preserve any already-
    // validated primary_keyword_data for a slug that still exists — editing
    // the niche's other fields must never silently wipe a real keyword score.
    $existingBySlug = [];
    foreach (($existing['services'] ?? []) as $svc) $existingBySlug[$svc['slug']] = $svc;
    $services = [];
    foreach (explode("\n", (string) ($_POST['services_lines'] ?? '')) as $line) {
        $line = trim($line);
        if ($line === '') continue;
        $parts = array_map('trim', explode('|', $line, 3));
        $slug  = $parts[0] ?? '';
        if ($slug === '') continue;
        $services[] = [
            'slug'                 => $slug,
            'label'                => $parts[1] ?? $slug,
            'primary_keyword'      => $parts[2] ?? '',
            'primary_keyword_data' => $existingBySlug[$slug]['primary_keyword_data'] ?? null,
            'secondary_keywords'   => $existingBySlug[$slug]['secondary_keywords'] ?? [],
        ];
    }

    pluscities_niche_save($nicheId, [
        'family_id'         => trim($_POST['family_id'] ?? ''),
        'label'              => trim($_POST['label'] ?? ''),
        'schema_subtype'     => trim($_POST['schema_subtype'] ?? ''),
        'service_noun'       => trim($_POST['service_noun'] ?? ''),
        'local_angle'        => trim($_POST['local_angle'] ?? ''),
        'seasonal_calendar'  => trim($_POST['seasonal_calendar'] ?? ''),
        'blocks_off'         => pluscities_split_csv_field((string) ($_POST['blocks_off'] ?? '')),
        'services'           => $services,
        // Fields not yet exposed in the form keep their existing value rather
        // than getting silently cleared on every save.
        'approved_sources_extra'     => $existing['approved_sources_extra'] ?? [],
        'research_topics'            => $existing['research_topics'] ?? [],
        'custom_research_fields'     => $existing['custom_research_fields'] ?? [],
        'niche_table'                => $existing['niche_table'] ?? null,
        'radius_minutes_override'    => $existing['radius_minutes_override'] ?? null,
        'compliance_preset_override' => $existing['compliance_preset_override'] ?? null,
        'trust_bar_items'            => $existing['trust_bar_items'] ?? [],
        'blog_topics'                => $existing['blog_topics'] ?? [],
        'match_keywords'             => $existing['match_keywords'] ?? [],
        'image_slots'                => $existing['image_slots'] ?? [],
    ]);
    header('Location: pluscities_niches.php?msg=success:Niche+saved&edit_niche=' . urlencode($nicheId)); exit;
}

if ($action === 'validate_keyword') {
    $nicheId = trim($_POST['niche_id'] ?? '');
    $slug    = trim($_POST['service_slug'] ?? '');
    $result  = pluscities_validate_service_keyword($nicheId, $slug);
    $type    = $result['ok'] ? 'success' : 'error';
    header('Location: pluscities_niches.php?msg=' . $type . ':' . urlencode($result['msg']) . '&edit_niche=' . urlencode($nicheId));
    exit;
}

header('Location: pluscities_niches.php?msg=error:Unknown+action');
