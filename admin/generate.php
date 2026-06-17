<?php
// Generation endpoint — called via AJAX POST from the City Pages tab (Phase 6).
// Always returns JSON.

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/generation/engine.php';

header('Content-Type: application/json');

if (empty($_SESSION['admin_logged_in'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'POST required.']);
    exit;
}

// CSRF check
$token = $_POST['csrf_token'] ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
    echo json_encode(['success' => false, 'message' => 'Invalid request token.']);
    exit;
}

if (!ACTIVE_SITE_ID) {
    echo json_encode(['success' => false, 'message' => 'No active site selected.']);
    exit;
}

// Parse options from POST
$options = [
    'template_ids'   => !empty($_POST['template_ids'])  ? (array)$_POST['template_ids']  : null,
    'city_ids'       => !empty($_POST['city_ids'])       ? (array)$_POST['city_ids']       : null,
    'tag_filter'     => trim($_POST['tag_filter']        ?? ''),
    'confirmed_cost' => !empty($_POST['confirmed_cost']),
    'force_locked'   => !empty($_POST['force_locked']),
    'dry_run'        => !empty($_POST['dry_run']),
];

$result = generate_city_pages($options);

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
exit;
