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

// Field names here are what the panel's JS actually sends (msSaveRotation() — data-field
// values match the top_field/bottom_field keys in admin/_batch_panels.php's tuples, which
// also match build_one.php's --rot-*= CLI flag names). ms_rotation_settings()'s own schema
// keys (home_top, etc.) are an internal detail of section_rotation.json — do not conflate
// the two; a mismatch here silently falls back to the default every time (found exactly
// this bug via real browser testing 2026-09-13 — the direct-PHP round-trip test that
// verified ms_rotation_settings()/ms_image_settings_write() never exercised this mapping).
$settings = ms_rotation_settings([
    'home_top'       => $_POST['rot_home_top']       ?? null,
    'home_bottom'    => $_POST['rot_home_bottom']    ?? null,
    'landing_top'    => $_POST['rot_landing_top']    ?? null,
    'landing_bottom' => $_POST['rot_landing_bottom'] ?? null,
]);

$res = ms_image_settings_write(ACTIVE_SITE_DIR, 'section_rotation.json', $settings);
if (!$res['ok']) { http_response_code(500); echo json_encode(['error' => $res['error']]); exit; }
echo json_encode(['ok' => true, 'settings' => $settings, 'scope' => 'master', 'site' => ACTIVE_SITE_ID]);
