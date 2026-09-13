<?php
/**
 * Generate Sites batch panel · save every checkbox's current state (7 parent steps + all
 * sub-switches beneath them) as THIS BATCH's defaults for next time the panel loads. Auth +
 * CSRF. Writes sites/{master}/batches/{batch}/batch_options.json — not shared with any other
 * batch off the same master. Purely a UI convenience — see the doc comment on
 * ms_batch_options_defaults() in includes/multisite/batch_options.php for why
 * build_one.php never reads this file. Which batch is derived from the SESSION (same
 * active-batch state admin/batch.php itself uses), never trusted from the client.
 *
 * Expects one POST field, `options`, a JSON object: {"steps":{...},"subs":{...}}.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/multisite/batch_options.php'; // ms_batch_options_settings()
require_once __DIR__ . '/../includes/multisite/batch.php'; // ms_active_batch(), ms_batch_file_write()
header('Content-Type: application/json');

if (empty($_SESSION['admin_logged_in']))   { http_response_code(403); echo json_encode(['error' => 'Not authenticated.']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST')  { http_response_code(405); echo json_encode(['error' => 'POST required.']); exit; }
if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
    http_response_code(403); echo json_encode(['error' => 'Invalid security token.']); exit;
}

$active = ms_active_batch();
if (!$active) { http_response_code(400); echo json_encode(['error' => 'No batch is open.']); exit; }

$raw = json_decode((string) ($_POST['options'] ?? ''), true);
if (!is_array($raw)) { http_response_code(400); echo json_encode(['error' => 'Malformed options payload.']); exit; }

$settings = ms_batch_options_settings($raw);

$res = ms_batch_file_write($active['master_id'], $active['batch_id'], 'batch_options.json', $settings);
if (!$res['ok']) { http_response_code(500); echo json_encode(['error' => $res['error']]); exit; }
echo json_encode(['ok' => true, 'settings' => $settings, 'master_id' => $active['master_id'], 'batch_id' => $active['batch_id']]);
