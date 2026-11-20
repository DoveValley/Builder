<?php
/**
 * On-demand theme + running check for the Demo Sites page — split out of
 * lpc_demo_sites.php's page load (see lpc_demo_check_sites_status()) so the
 * expensive live check only runs when the "Check Status" button is clicked,
 * not on every visit to the page.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/lpc_demo_box.php';

header('Content-Type: application/json');

if (empty($_SESSION['admin_logged_in'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}
if (lpc_current_actor_role() !== 'super_admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden: platform-operator access only.']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required.']);
    exit;
}
$token = $_POST['csrf_token'] ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid request token']);
    exit;
}

echo json_encode(['ok' => true, 'sites' => lpc_demo_check_sites_status()]);
