<?php
// Services Links plugin save handler.
// Auth + CSRF already verified by admin/plugin_save.php before this runs.

$base   = 'index.php?tab=plugins&plugin=services_links';
$action = $_POST['action'] ?? 'save';

if ($action !== 'save') {
    header('Location: ' . $base);
    exit;
}

$data = load_data();

// Parse services from textarea — one per line, trim blanks
$rawText  = $_POST['services_text'] ?? '';
$services = array_values(array_filter(array_map('trim', explode("\n", $rawText)), fn($s) => $s !== ''));

// Background photo upload
$existing = trim($_POST['bg_photo_existing'] ?? '');
$bgPhoto  = $existing;
if (!empty($_POST['bg_photo_remove'])) $bgPhoto = '';
$up = upload_image('bg_photo_upload', 'services');
if ($up === false) {
    header('Location: ' . $base . '&msg=error:Background+photo+upload+failed.');
    exit;
}
if ($up !== null) $bgPhoto = $up;

// Numeric fields — clamp to safe ranges
$cols    = max(2, min(6, (int)($_POST['cols']    ?? 5)));
$overlay = max(0.0, min(1.0, (float)($_POST['overlay'] ?? 0.20)));

$data['services_links'] = [
    'services'      => $services,
    'url_pattern'   => sanitize_url(trim($_POST['url_pattern'] ?? '/{service_slug}-{city_slug}')),
    'heading'       => trim($_POST['heading']        ?? ''),
    'sublabel'      => trim($_POST['sublabel']       ?? ''),
    'subtext'       => trim($_POST['subtext']        ?? ''),
    'style'         => in_array($_POST['style'] ?? '', ['dark', 'light'], true) ? $_POST['style'] : 'dark',
    'cols'          => $cols,
    'bg_photo'      => $bgPhoto,
    'overlay'       => number_format($overlay, 2, '.', ''),
    'bg_color'      => trim($_POST['bg_color']       ?? '#ffffff'),
    'accent'        => trim($_POST['accent']         ?? 'accent'),
    'accent_custom' => trim($_POST['accent_custom']  ?? '#fd783b'),
    'anchor'        => trim($_POST['anchor']         ?? ''),
];

$saved = save_data($data);
if (!$saved) {
    header('Location: ' . $base . '&msg=error:Could+not+save+—+check+file+permissions.');
    exit;
}
header('Location: ' . $base . '&msg=success:Services+Links+saved.');
exit;
