<?php
/**
 * admin/sales_status.php — thin JSON polling endpoint for admin/sales.php's
 * progress banner. Same shape as admin/pluscities_bulk_status.php.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/sales_prospecting.php';
header('Content-Type: application/json');

if (empty($_SESSION['admin_logged_in']) || lpc_current_actor_role() !== 'super_admin') {
    http_response_code(403); echo json_encode(['error' => 'Forbidden']); exit;
}

$runId = $_GET['run_id'] ?? '';
$status = sales_job_status($runId);
if ($status === null) {
    echo json_encode(['state' => 'pending', 'done' => 0, 'total' => 0, 'results' => []]); exit;
}
echo json_encode($status);
