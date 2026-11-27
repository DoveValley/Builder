<?php
/**
 * admin/sales_save.php — POST-only JSON endpoint, two actions on one saved
 * lead by a posted `action` field:
 *   - update:  save this row's status/notes (inline edit on the results table)
 *   - refresh: re-check WordPress/sitemap/(domain rank, if ever looked up)
 *              for this one row, in-request — a single domain, no background
 *              job needed.
 * Folded into one file rather than two, per the plan's file-count discipline.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/sales_prospecting.php';
header('Content-Type: application/json');

if (empty($_SESSION['admin_logged_in']) || lpc_current_actor_role() !== 'super_admin') {
    http_response_code(403); echo json_encode(['error' => 'Forbidden']); exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'POST only']); exit; }

$token = $_POST['csrf_token'] ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
    http_response_code(403); echo json_encode(['error' => 'Invalid request token']); exit;
}

$id = (int) ($_POST['id'] ?? 0);
if ($id <= 0 || !sales_get_prospect($id)) { echo json_encode(['error' => 'Lead not found']); exit; }

$action = $_POST['action'] ?? 'update';
if ($action === 'refresh') {
    $ok = sales_refresh_prospect($id);
    echo json_encode($ok ? ['ok' => true, 'prospect' => sales_get_prospect($id)] : ['error' => 'Refresh failed']);
    exit;
}

$status = (string) ($_POST['status'] ?? '');
$allowedStatus = ['new', 'contacted', 'qualified', 'not_interested', 'won', 'lost'];
$fields = [];
if ($status !== '') {
    if (!in_array($status, $allowedStatus, true)) { echo json_encode(['error' => 'Invalid status']); exit; }
    $fields['status'] = $status;
}
if (isset($_POST['notes'])) $fields['notes'] = (string) $_POST['notes'];

$ok = $fields ? sales_update_prospect($id, $fields) : true;
echo json_encode($ok ? ['ok' => true] : ['error' => 'Save failed']);
