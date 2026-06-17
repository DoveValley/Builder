<?php
// Popups plugin save handler.
// Auth + CSRF already verified by admin/plugin_save.php before this runs.

$base   = 'index.php?tab=plugins&plugin=popups';
$action = $_POST['action'] ?? 'save';

if ($action !== 'save') {
    header('Location: ' . $base);
    exit;
}

$data = load_data();

$existing = trim($_POST['popup_info_image_existing'] ?? '');
$imgPath  = $existing;
if (!empty($_POST['popup_info_remove_image'])) $imgPath = '';

$up = upload_image('popup_info_image', 'popup');
if ($up === false) {
    header('Location: ' . $base . '&msg=error:Popup+image+upload+failed.');
    exit;
}
if ($up !== null) $imgPath = $up;

$data['popups']['info'] = [
    'enabled' => !empty($_POST['popup_info_enabled']),
    'heading' => trim($_POST['popup_info_heading'] ?? 'How Your Calls Are Handled'),
    'image'   => $imgPath,
    'body'    => trim($_POST['popup_info_body'] ?? ''),
];

$saved = save_data($data);
if (!$saved) {
    header('Location: ' . $base . '&msg=error:Could+not+save+—+check+file+permissions.');
    exit;
}
header('Location: ' . $base . '&msg=success:Popup+settings+saved.');
exit;
