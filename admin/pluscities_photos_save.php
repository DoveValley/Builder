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
    // Saving a color here is always a manual override — it must win over
    // whatever's detected from the client's own site, per
    // pluscities_client_colors()'s resolution order.
    $accent = trim((string) ($_POST['accent_color'] ?? ''));
    if (preg_match('/^#[0-9a-fA-F]{6}$/', $accent)) $client['branding']['accent_override'] = $accent;
    $dark = trim((string) ($_POST['dark_color'] ?? ''));
    if (preg_match('/^#[0-9a-fA-F]{6}$/', $dark)) $client['branding']['dark_override'] = $dark;

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

if ($action === 'detect_colors') {
    $found = pluscities_detect_colors($client['domain']);
    if ($found['accent'] !== null) $client['branding']['detected_accent'] = $found['accent'];
    if ($found['dark'] !== null)   $client['branding']['detected_dark']   = $found['dark'];
    $client['branding']['colors_detected_at'] = gmdate('c');
    pluscities_atomic_write_json($clientFile, $client);
    pluscities_log_event($actor, 'detect_colors', 'client:' . $clientId, '');

    $msg = ($found['accent'] !== null || $found['dark'] !== null)
        ? 'success:Found colors on your site'
        : 'warning:Could+not+detect+colors+from+your+site+-+try+setting+them+manually';
    header('Location: ' . $back . '&msg=' . $msg); exit;
}

if ($action === 'clear_color_override') {
    // Doesn't re-detect — just drops back to whatever was last detected (or
    // the fallback if nothing ever was), same "re-detect never erases an
    // override, clearing an override never forces a re-detect" separation.
    $client['branding']['accent_override'] = null;
    $client['branding']['dark_override']   = null;
    pluscities_atomic_write_json($clientFile, $client);
    pluscities_log_event($actor, 'clear_color_override', 'client:' . $clientId, '');
    header('Location: ' . $back . '&msg=success:Switched+to+your+site+colors'); exit;
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
