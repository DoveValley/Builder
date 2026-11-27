<?php
/**
 * admin/sales_save.php — POST-only JSON endpoint, by a posted `action` field:
 *   - update:    save one row's status/notes (inline edit on the results table)
 *   - refresh:   re-check WordPress/sitemap/domain-rank for one row, in-request
 *   - bulk_rank: domain-rank lookup for every SAVED lead matching the current
 *                results-table filters that doesn't already have one — the
 *                "found the WordPress ones, now rank them" action. One batched
 *                DataForSEO call either way (sales_dataforseo_bulk_rank()).
 * Folded into one file rather than several, per the plan's file-count discipline.
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

$action = $_POST['action'] ?? 'update';

if ($action === 'bulk_rank') {
    $filters = [
        'niche'        => trim((string) ($_POST['niche'] ?? '')),
        'state'        => trim((string) ($_POST['state'] ?? '')),
        'status'       => trim((string) ($_POST['status'] ?? '')),
        'is_wordpress' => trim((string) ($_POST['is_wordpress'] ?? '')),
    ];
    $result = sales_bulk_lookup_rank(array_filter($filters, fn($v) => $v !== ''));
    echo json_encode($result);
    exit;
}

$id = (int) ($_POST['id'] ?? 0);
if ($id <= 0 || !sales_get_prospect($id)) { echo json_encode(['error' => 'Lead not found']); exit; }

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
