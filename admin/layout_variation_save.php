<?php
/**
 * Gen-Mod tab · save the "vary block order per city" parameters.
 * Auth + CSRF. Writes THIS master's own
 * sites/{master}/multisite/layout_variation.json — the copy
 * includes/generation/engine.php prefers when a Landing City Page Gen run has
 * "Vary block order per city" checked. A missing file (neither master nor
 * global) means the original hardcoded default — see
 * ms_layout_variation_defaults() in includes/layout_variations.php.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php'; // ms_layout_variation_settings()
require_once __DIR__ . '/../includes/multisite/image_overlay.php'; // ms_image_settings_write()
header('Content-Type: application/json');

if (empty($_SESSION['admin_logged_in']))   { http_response_code(403); echo json_encode(['error' => 'Not authenticated.']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST')  { http_response_code(405); echo json_encode(['error' => 'POST required.']); exit; }
if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
    http_response_code(403); echo json_encode(['error' => 'Invalid security token.']); exit;
}

$settings = ms_layout_variation_settings(['variant_count' => $_POST['variant_count'] ?? null]);

$res = ms_image_settings_write(ACTIVE_SITE_DIR, 'layout_variation.json', $settings);
if (!$res['ok']) { http_response_code(500); echo json_encode(['error' => $res['error']]); exit; }
echo json_encode(['ok' => true, 'settings' => $settings, 'scope' => 'master', 'site' => ACTIVE_SITE_ID]);
