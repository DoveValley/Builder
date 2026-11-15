<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

if (empty($_SESSION['admin_logged_in'])) { header('Location: login.php'); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: niche_profiles.php'); exit; }

$token = $_POST['csrf_token'] ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
    header('Location: niche_profiles.php?msg=error:Invalid+request+token');
    exit;
}

$action = $_POST['action'] ?? '';

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
        ],
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
