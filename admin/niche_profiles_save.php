<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

if (empty($_SESSION['admin_logged_in'])) { header('Location: login.php'); exit; }
// Same Scott-only gate as niche_profiles.php — keep both in sync.
if (lpc_current_actor_role() !== 'super_admin') {
    header('Location: clients.php?msg=error:Niche+profiles+are+managed+by+the+platform+only');
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: niche_profiles.php'); exit; }

$token = $_POST['csrf_token'] ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
    header('Location: niche_profiles.php?msg=error:Invalid+request+token');
    exit;
}

$action = $_POST['action'] ?? '';

// ── Pull facts from the shared Niche Library ──────────────────────────────────
// Copy-in, not a live link — see docs/lpc-vs-site-factory-boundary.md §4. Only
// the business-model-agnostic fields are touched; guardrails/business_descriptor
// are never written here, since LPC's clients perform the work themselves and
// must keep their own real-operator framing, never the library's neutral facts
// mistaken for finished copy.
if ($action === 'pull_from_library') {
    $libId = trim($_POST['library_niche_id'] ?? '');
    $lib = niche_library_load($libId);
    if (!$lib) { header('Location: niche_profiles.php?msg=error:That+niche+is+not+in+the+library'); exit; }

    $targetId = trim($_POST['existing_niche_id'] ?? '') ?: $libId;
    $profile = lpc_load_niche_profile($targetId) ?? lpc_default_niche_profile();
    $profile['niche_id'] = $targetId;
    if (empty($profile['label'])) $profile['label'] = $lib['label'];
    if ($lib['tone'] !== '') $profile['default_tone'] = $lib['tone'];

    $slots = array_merge(lpc_default_content_slots(), $profile['content_slots'] ?? []);
    if ($lib['local_angle'] !== '') $slots['local_relevance'] = $lib['local_angle'];
    if ($lib['seasonal_calendar'] !== '') $slots['seasonal_calendar'] = $lib['seasonal_calendar'];
    $profile['content_slots'] = $slots;

    $profile['default_services'] = array_map(
        fn($s) => ['label' => $s['label'], 'keyword_pattern' => $s['keyword_pattern'] ?? ''],
        $lib['services']
    );
    if ($lib['trust_bar_items']) $profile['trust_bar_items'] = $lib['trust_bar_items'];
    // Union, not replace — a match keyword Scott already tuned by hand is
    // never dropped by a library pull, only ever added to.
    $profile['match_keywords'] = array_values(array_unique(array_merge(
        (array) ($profile['match_keywords'] ?? []), $lib['match_keywords']
    )));

    if (!lpc_save_niche_profile($targetId, $profile)) {
        header('Location: niche_profiles.php?msg=error:Could+not+write+the+niche+profile');
        exit;
    }
    header('Location: niche_profiles.php?edit=' . urlencode($targetId)
        . '&msg=success:' . rawurlencode('Pulled facts from "' . $lib['label'] . '" — review below, then Save.'));
    exit;
}

if ($action === 'save') {
    $label = trim($_POST['label'] ?? '');
    if ($label === '') {
        header('Location: niche_profiles.php?msg=error:Label+is+required');
        exit;
    }
    // Editing an existing profile keeps its id (relabeling shouldn't silently
    // orphan every client already pointing at this niche); creating new slugs
    // from the label, same pattern as client_id.
    $niche_id = trim($_POST['existing_niche_id'] ?? '') ?: slugify($label);
    if (!lpc_valid_niche_id($niche_id)) {
        header('Location: niche_profiles.php?msg=error:Could+not+derive+a+valid+id+from+that+label');
        exit;
    }

    $keywords = array_values(array_filter(array_map('trim', explode(',', $_POST['match_keywords'] ?? ''))));

    $dsLabels   = $_POST['ds_label'] ?? [];
    $dsPatterns = $_POST['ds_pattern'] ?? [];
    $defaultServices = [];
    foreach ($dsLabels as $i => $label) {
        $label = trim($label);
        if ($label === '') continue;
        $defaultServices[] = ['label' => $label, 'keyword_pattern' => trim($dsPatterns[$i] ?? '')];
    }

    lpc_save_niche_profile($niche_id, [
        'label'               => $label,
        'match_keywords'      => $keywords,
        'business_descriptor' => trim($_POST['business_descriptor'] ?? ''),
        'default_tone'        => trim($_POST['default_tone'] ?? 'professional'),
        'guardrails'          => trim($_POST['guardrails'] ?? ''),
        'content_slots' => [
            'feature_columns_local' => trim($_POST['slot_feature_columns_local'] ?? ''),
            'local_relevance'       => trim($_POST['slot_local_relevance'] ?? ''),
            'seasonal_calendar'     => trim($_POST['slot_seasonal_calendar'] ?? ''),
            'why_choose_us'         => trim($_POST['slot_why_choose_us'] ?? ''),
            'how_it_works'          => trim($_POST['slot_how_it_works'] ?? ''),
            'comparison'            => trim($_POST['slot_comparison'] ?? ''),
        ],
        'default_services' => $defaultServices,
        'trust_bar_items' => array_values(array_filter(array_map('trim', explode("\n", $_POST['trust_bar_items'] ?? '')))),
    ]);
    header('Location: niche_profiles.php?msg=success:Niche+profile+saved');
    exit;
}

if ($action === 'delete') {
    $niche_id = trim($_POST['niche_id'] ?? '');

    // Don't silently orphan a client's inherited guardrails — same guard
    // shape as blocking a hub delete while a target city still serves from it.
    $inUse = false;
    foreach (lpc_list_clients() as $client_id) {
        $client = lpc_load_client($client_id);
        if ($client && ($client['content_profile']['source'] ?? '') === 'niche:' . $niche_id) { $inUse = true; break; }
    }
    if ($inUse) {
        header('Location: niche_profiles.php?msg=error:Cannot+delete+-+at+least+one+client+still+uses+this+niche');
        exit;
    }

    lpc_delete_niche_profile($niche_id);
    header('Location: niche_profiles.php?msg=success:Niche+profile+deleted');
    exit;
}

header('Location: niche_profiles.php?msg=error:Unknown+action');
