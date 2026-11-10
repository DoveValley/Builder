<?php
/**
 * Target List card · save the "Strict positional box mode" toggle. Auth + CSRF.
 * Writes THIS BATCH's own sites/{master}/batches/{batch}/box_assignment.json via
 * ms_batch_file_write(), read back by Create Host (multisite/create_hosts.php) and
 * the batch panel via ms_batch_file_read() + ms_box_assignment_settings(). Which
 * batch is derived from the SESSION (same active-batch state admin/batch.php itself
 * uses), never trusted from the client — same pattern as rotation_settings_save.php.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/multisite/batch.php';          // ms_active_batch(), ms_batch_file_write()
require_once __DIR__ . '/../includes/multisite/box_assignment.php'; // ms_box_assignment_settings()
header('Content-Type: application/json');

if (empty($_SESSION['admin_logged_in']))   { http_response_code(403); echo json_encode(['error' => 'Not authenticated.']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST')  { http_response_code(405); echo json_encode(['error' => 'POST required.']); exit; }
if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
    http_response_code(403); echo json_encode(['error' => 'Invalid security token.']); exit;
}

$active = ms_active_batch();
if (!$active) { http_response_code(400); echo json_encode(['error' => 'No batch is open.']); exit; }

$settings = ms_box_assignment_settings(['strict_mode' => $_POST['strict_mode'] ?? '']);

$res = ms_batch_file_write($active['master_id'], $active['batch_id'], 'box_assignment.json', $settings);
if (!$res['ok']) { http_response_code(500); echo json_encode(['error' => $res['error']]); exit; }
echo json_encode(['ok' => true, 'settings' => $settings, 'master_id' => $active['master_id'], 'batch_id' => $active['batch_id']]);
