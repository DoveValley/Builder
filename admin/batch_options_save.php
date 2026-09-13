<?php
/**
 * Generate Sites batch panel · save every checkbox's current state (7 parent steps + all
 * sub-switches beneath them) as this master's defaults for next time the panel loads.
 * Auth + CSRF. Writes sites/{master}/multisite/batch_options.json. Purely a UI convenience
 * — see the doc comment on ms_batch_options_defaults() in
 * includes/multisite/batch_options.php for why build_one.php never reads this file.
 *
 * Expects one POST field, `options`, a JSON object: {"steps":{...},"subs":{...}}.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/multisite/batch_options.php'; // ms_batch_options_settings()
require_once __DIR__ . '/../includes/multisite/image_overlay.php'; // ms_image_settings_write()
header('Content-Type: application/json');

if (empty($_SESSION['admin_logged_in']))   { http_response_code(403); echo json_encode(['error' => 'Not authenticated.']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST')  { http_response_code(405); echo json_encode(['error' => 'POST required.']); exit; }
if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
    http_response_code(403); echo json_encode(['error' => 'Invalid security token.']); exit;
}

$raw = json_decode((string) ($_POST['options'] ?? ''), true);
if (!is_array($raw)) { http_response_code(400); echo json_encode(['error' => 'Malformed options payload.']); exit; }

$settings = ms_batch_options_settings($raw);

$res = ms_image_settings_write(ACTIVE_SITE_DIR, 'batch_options.json', $settings);
if (!$res['ok']) { http_response_code(500); echo json_encode(['error' => $res['error']]); exit; }
echo json_encode(['ok' => true, 'settings' => $settings, 'scope' => 'master', 'site' => ACTIVE_SITE_ID]);
