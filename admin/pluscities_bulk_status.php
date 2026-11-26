<?php
/**
 * admin/pluscities_bulk_status.php — thin JSON polling endpoint for
 * admin/pluscities_bulk_progress.php. GET only, same access check as every
 * other client-scoped screen (pluscities_actor_can_access_client()) so this
 * can't be used to peek at a different agency's job progress.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
header('Content-Type: application/json');

if (!lpc_actor_is_authenticated() || lpc_current_actor_role() === 'end_client') {
    http_response_code(403); echo json_encode(['error' => 'Not authenticated']); exit;
}
$clientId = $_GET['client_id'] ?? '';
$runId    = $_GET['run_id'] ?? '';
if (!pluscities_actor_can_access_client($clientId)) {
    http_response_code(403); echo json_encode(['error' => 'Client not found']); exit;
}
$status = pluscities_bulk_job_status($clientId, $runId);
if ($status === null) {
    echo json_encode(['state' => 'pending', 'done' => 0, 'total' => 0, 'results' => []]); exit;
}
echo json_encode($status);
