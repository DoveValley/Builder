<?php
/** admin/pluscities_photos_save.php — save handler for admin/pluscities_photos.php. */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/pluscities_images.php';

if (!lpc_actor_is_authenticated() || lpc_current_actor_role() === 'end_client') {
    header('Location: login.php'); exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: pluscities_photos.php'); exit; }

$token = $_POST['csrf_token'] ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
    header('Location: pluscities_photos.php?msg=error:Invalid+request+token'); exit;
}

$clientId = trim($_POST['client_id'] ?? '');
$back     = 'pluscities_photos.php?client_id=' . urlencode($clientId);
$actor    = lpc_current_actor_role() . ':' . (lpc_current_actor_identity()['name'] ?? 'unknown');
$action   = $_POST['action'] ?? '';

if (!$clientId || !pluscities_actor_can_access_client($clientId)) {
    header('Location: pluscities_photos.php?msg=error:Client+not+found'); exit;
}

$clientFile = PLUSCITIES_CLIENTS_DIR . $clientId . '.json';
$client = json_decode((string) @file_get_contents($clientFile), true);
if (!$client) { header('Location: pluscities_photos.php?msg=error:Client+not+found'); exit; }

if ($action === 'save_branding') {
    if (!empty($_FILES['logo_file']['tmp_name']) && is_uploaded_file($_FILES['logo_file']['tmp_name'])) {
        $saved = save_uploaded_file(
            $_FILES['logo_file']['tmp_name'], $clientId . '_logo', $_FILES['logo_file']['name'],
            PLUSCITIES_UPLOADS_DIR . 'logos/', PLUSCITIES_UPLOADS_URL . 'logos/'
        );
        if ($saved) $client['branding']['logo_url'] = $saved;
    }
    $color = trim((string) ($_POST['brand_color'] ?? ''));
    if (preg_match('/^#[0-9a-fA-F]{6}$/', $color)) $client['branding']['brand_color'] = $color;

    pluscities_atomic_write_json($clientFile, $client);
    pluscities_log_event($actor, 'save_branding', 'client:' . $clientId, '');

    // Refresh every slot's default photo right away — the person on this
    // screen never sees "regenerate" as its own step, just their saved color.
    $niche = pluscities_niche_resolved($client['niche_id']);
    foreach (($niche['image_slots'] ?? []) as $slot) {
        pluscities_generate_branded_image($clientId, $niche, $slot['slot_id']);
    }
    header('Location: ' . $back . '&msg=success:Saved'); exit;
}

if ($action === 'upload_photo') {
    $slotId = (string) ($_POST['slot_id'] ?? '');
    if (empty($_FILES['photo_file']['tmp_name']) || !is_uploaded_file($_FILES['photo_file']['tmp_name'])) {
        header('Location: ' . $back . '&msg=error:No+photo+selected'); exit;
    }
    $saved = save_uploaded_file(
        $_FILES['photo_file']['tmp_name'], $clientId . '_' . $slotId, $_FILES['photo_file']['name'],
        PLUSCITIES_UPLOADS_DIR . 'photos/', PLUSCITIES_UPLOADS_URL . 'photos/'
    );
    if (!$saved) { header('Location: ' . $back . '&msg=error:Could+not+save+that+photo'); exit; }

    $client['images'][$slotId]['uploaded_path'] = $saved;
    pluscities_atomic_write_json($clientFile, $client);
    pluscities_log_event($actor, 'upload_photo', 'client:' . $clientId . ' / ' . $slotId, '');
    header('Location: ' . $back . '&msg=success:Photo+saved'); exit;
}

if ($action === 'revert_photo') {
    $slotId = (string) ($_POST['slot_id'] ?? '');
    unset($client['images'][$slotId]['uploaded_path']);
    pluscities_atomic_write_json($clientFile, $client);
    pluscities_log_event($actor, 'revert_photo', 'client:' . $clientId . ' / ' . $slotId, '');
    header('Location: ' . $back . '&msg=success:Switched+back+to+the+default+photo'); exit;
}

header('Location: ' . $back . '&msg=error:Unknown+action');
