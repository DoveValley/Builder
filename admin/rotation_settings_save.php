<?php
/**
 * Generate Sites · Site structure variance card · save the section-order rotation pin
 * counts ("Don't rotate top/bottom", both scopes). Auth + CSRF. Writes THIS master's own
 * sites/{master}/multisite/section_rotation.json — the copy build_one.php's own
 * --rot-*= flags default to when the batch panel doesn't send an explicit value (it
 * always does today, but a hand-run CLI build without them falls back to this). A
 * missing file (neither master nor global) means the original hardcoded default — see
 * ms_rotation_defaults() in includes/layout_variations.php.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php'; // ms_rotation_settings()
require_once __DIR__ . '/../includes/multisite/image_overlay.php'; // ms_image_settings_write()
header('Content-Type: application/json');

if (empty($_SESSION['admin_logged_in']))   { http_response_code(403); echo json_encode(['error' => 'Not authenticated.']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST')  { http_response_code(405); echo json_encode(['error' => 'POST required.']); exit; }
if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
    http_response_code(403); echo json_encode(['error' => 'Invalid security token.']); exit;
}

$settings = ms_rotation_settings([
    'home_top'       => $_POST['home_top']       ?? null,
    'home_bottom'    => $_POST['home_bottom']    ?? null,
    'landing_top'    => $_POST['landing_top']    ?? null,
    'landing_bottom' => $_POST['landing_bottom'] ?? null,
]);

$res = ms_image_settings_write(ACTIVE_SITE_DIR, 'section_rotation.json', $settings);
if (!$res['ok']) { http_response_code(500); echo json_encode(['error' => $res['error']]); exit; }
echo json_encode(['ok' => true, 'settings' => $settings, 'scope' => 'master', 'site' => ACTIVE_SITE_ID]);
