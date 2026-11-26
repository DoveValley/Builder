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
    // Fields with no form input yet (always_city_blocks, always_service_blocks)
    // must be preserved from the existing record, same as save_niche already
    // does below — pluscities_family_save() has no "keep what's not passed"
    // behavior of its own (pluscities_deep_merge_defaults() only fills in the
    // SCHEMA default, [], for anything missing, it doesn't look at what's
    // currently on disk). Omitting this fallback would silently wipe both
    // fields back to [] on every single save through this form — the exact
    // bug class image_slots hit on the niche side (found+fixed 2026-11-23).
    $existingFamily = pluscities_family_load($familyId) ?? [];
    pluscities_family_save($familyId, [
        'label'                  => trim($_POST['label'] ?? ''),
        'schema_type_family'     => trim($_POST['schema_type_family'] ?? ''),
        'compliance_preset'      => trim($_POST['compliance_preset'] ?? ''),
        'default_radius_minutes' => max(1, (int) ($_POST['default_radius_minutes'] ?? 25)),
        'default_city_blocks'    => pluscities_split_csv_field((string) ($_POST['default_city_blocks'] ?? '')),
        'default_service_blocks' => pluscities_split_csv_field((string) ($_POST['default_service_blocks'] ?? '')),
        'approved_sources'       => pluscities_split_csv_field((string) ($_POST['approved_sources'] ?? '')),
        'research_topics'        => pluscities_split_csv_field((string) ($_POST['research_topics'] ?? '')),
        'always_city_blocks'     => $existingFamily['always_city_blocks'] ?? [],
        'always_service_blocks'  => $existingFamily['always_service_blocks'] ?? [],
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

    // Custom research fields: "key|ask" per line.
    $customResearchFields = [];
    foreach (explode("\n", (string) ($_POST['custom_research_fields_lines'] ?? '')) as $line) {
        $line = trim($line);
        if ($line === '') continue;
        $parts = array_map('trim', explode('|', $line, 2));
        if (($parts[0] ?? '') === '') continue;
        $customResearchFields[] = ['key' => $parts[0], 'ask' => $parts[1] ?? ''];
    }

    // Blog topics: "slug|title|focus_keyword|tag|related,slugs" per line — the
    // 5th segment is optional and comma-separated within itself.
    $blogTopics = [];
    foreach (explode("\n", (string) ($_POST['blog_topics_lines'] ?? '')) as $line) {
        $line = trim($line);
        if ($line === '') continue;
        $parts = array_map('trim', explode('|', $line, 5));
        $slug  = $parts[0] ?? '';
        if ($slug === '') continue;
        $blogTopics[] = [
            'slug' => $slug, 'title' => $parts[1] ?? '', 'focus_keyword' => $parts[2] ?? '',
            'tag' => $parts[3] ?? '', 'related_topics' => pluscities_split_csv_field($parts[4] ?? ''),
        ];
    }

    // Reference table: first non-blank line is the column headers, every line
    // after it is one row, both pipe-separated. An empty textarea means "no
    // table for this niche" (null), same as before any table was ever authored.
    $nicheTableLines = array_values(array_filter(
        array_map('rtrim', explode("\n", (string) ($_POST['niche_table_lines'] ?? ''))),
        fn($l) => trim($l) !== ''
    ));
    $nicheTable = null;
    if ($nicheTableLines) {
        $columns = array_map('trim', explode('|', array_shift($nicheTableLines)));
        $rows = array_map(fn($line) => array_map('trim', explode('|', $line)), $nicheTableLines);
        $nicheTable = ['title' => trim($_POST['niche_table_title'] ?? ''), 'columns' => $columns, 'rows' => $rows, 'source_topic' => null];
    }

    pluscities_niche_save($nicheId, [
        'family_id'         => trim($_POST['family_id'] ?? ''),
        'label'              => trim($_POST['label'] ?? ''),
        'schema_subtype'     => trim($_POST['schema_subtype'] ?? ''),
        'service_noun'       => trim($_POST['service_noun'] ?? ''),
        'local_angle'        => trim($_POST['local_angle'] ?? ''),
        'seasonal_calendar'  => trim($_POST['seasonal_calendar'] ?? ''),
        'blocks_off'         => pluscities_split_csv_field((string) ($_POST['blocks_off'] ?? '')),
        'service_blocks_off' => pluscities_split_csv_field((string) ($_POST['service_blocks_off'] ?? '')),
        'services'           => $services,
        'trust_bar_items'         => pluscities_split_csv_field((string) ($_POST['trust_bar_items'] ?? '')),
        'approved_sources_extra'  => pluscities_split_csv_field((string) ($_POST['approved_sources_extra'] ?? '')),
        'custom_research_fields'  => $customResearchFields,
        'blog_topics'             => $blogTopics,
        'niche_table'             => $nicheTable,
        // Fields still not exposed in the form keep their existing value
        // rather than getting silently cleared on every save.
        'research_topics'            => $existing['research_topics'] ?? [],
        'radius_minutes_override'    => $existing['radius_minutes_override'] ?? null,
        'compliance_preset_override' => $existing['compliance_preset_override'] ?? null,
        'match_keywords'             => $existing['match_keywords'] ?? [],
        'image_slots'                => $existing['image_slots'] ?? [],
        'safety_toolkit_items'       => $existing['safety_toolkit_items'] ?? [],
        'climate_chart_ids'          => $existing['climate_chart_ids'] ?? [],
    ]);
    header('Location: pluscities_niches.php?msg=success:Niche+saved&edit_niche=' . urlencode($nicheId)); exit;
}

if ($action === 'upload_niche_image') {
    $nicheId = trim((string) ($_POST['niche_id'] ?? ''));
    $slotId  = (string) ($_POST['slot_id'] ?? '');
    $niche   = pluscities_niche_load($nicheId);
    if (!$niche) { header('Location: pluscities_niches.php?msg=error:Niche+not+found'); exit; }
    if (empty($_FILES['image_file']['tmp_name']) || !is_uploaded_file($_FILES['image_file']['tmp_name'])) {
        header('Location: pluscities_niches.php?edit_niche=' . urlencode($nicheId) . '&msg=error:No+image+selected'); exit;
    }

    // Same helper admin/pluscities_photos_save.php's upload_photo action uses
    // for a client's own images — one level up, same mechanics (validate,
    // resize, convert to WebP), just a different destination directory and no
    // client record to touch.
    $saved = save_uploaded_file(
        $_FILES['image_file']['tmp_name'], $nicheId . '_' . $slotId, $_FILES['image_file']['name'],
        BASE_DIR . '/niche_default_images/' . $nicheId . '/', 'niche_default_images/' . $nicheId . '/'
    );
    if (!$saved) {
        header('Location: pluscities_niches.php?edit_niche=' . urlencode($nicheId) . '&msg=error:Could+not+save+that+image'); exit;
    }

    $found = false;
    foreach ($niche['image_slots'] as &$slot) {
        if ($slot['slot_id'] === $slotId) { $slot['default_image_path'] = $saved; $found = true; break; }
    }
    unset($slot);
    if (!$found) {
        header('Location: pluscities_niches.php?edit_niche=' . urlencode($nicheId) . '&msg=error:Slot+not+found'); exit;
    }

    pluscities_niche_save($nicheId, $niche);
    pluscities_log_event(lpc_current_actor_role() . ':' . (lpc_current_actor_identity()['name'] ?? 'unknown'), 'upload_niche_image', 'niche:' . $nicheId . ' / ' . $slotId, '');
    header('Location: pluscities_niches.php?edit_niche=' . urlencode($nicheId) . '&msg=success:Image+saved'); exit;
}

if ($action === 'add_image_slot') {
    $nicheId = trim((string) ($_POST['niche_id'] ?? ''));
    $niche   = pluscities_niche_load($nicheId);
    if (!$niche) { header('Location: pluscities_niches.php?msg=error:Niche+not+found'); exit; }

    $slotId = trim((string) ($_POST['slot_id'] ?? ''));
    // slot_id must be a REAL block type, not an arbitrary name — a slot only
    // ever gets used because some block's render code looks up its own
    // block_type as the slot_id (pluscities_resolve_image_url()), so the
    // label is derived from the same shared catalog the picker itself reads
    // from rather than trusted from the form, and anything not in that
    // catalog is rejected outright.
    $label = null;
    foreach (platform_info_city_page_blocks_data() as $section) {
        foreach ($section['rows'] as $r) {
            if ($r['slug'] === $slotId) { $label = $r['block']; break 2; }
        }
    }
    if ($label === null) {
        header('Location: pluscities_niches.php?edit_niche=' . urlencode($nicheId) . '&msg=error:Unknown+block+type'); exit;
    }
    foreach ($niche['image_slots'] as $slot) {
        if ($slot['slot_id'] === $slotId) {
            header('Location: pluscities_niches.php?edit_niche=' . urlencode($nicheId) . '&msg=error:That+slot+ID+already+exists'); exit;
        }
    }

    // Fixed 1536x1024 — matches every image slot built so far; not something
    // worth asking an admin to pick pixel dimensions for.
    $niche['image_slots'][] = [
        'slot_id' => $slotId, 'label' => $label, 'width' => 1536, 'height' => 1024, 'default_image_path' => '',
    ];
    pluscities_niche_save($nicheId, $niche);
    pluscities_log_event(lpc_current_actor_role() . ':' . (lpc_current_actor_identity()['name'] ?? 'unknown'), 'add_image_slot', 'niche:' . $nicheId . ' / ' . $slotId, '');
    header('Location: pluscities_niches.php?edit_niche=' . urlencode($nicheId) . '&msg=success:Slot+added'); exit;
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
