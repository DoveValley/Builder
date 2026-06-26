<?php
// Save Anthropic API key to BASE_DIR/.ai_key
// POST only. CSRF protected.

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

if (empty($_SESSION['admin_logged_in'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Not authenticated.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'POST required.']);
    exit;
}

if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token.']);
    exit;
}

$key = trim($_POST['api_key'] ?? '');

// Basic format check — Anthropic keys start with sk-ant-
if ($key !== '' && !str_starts_with($key, 'sk-ant-')) {
    echo json_encode(['success' => false, 'error' => 'Key must start with sk-ant-']);
    exit;
}

$file = BASE_DIR . '/.ai_key';

if ($key === '') {
    // Clear the key
    if (file_exists($file)) unlink($file);
    echo json_encode(['success' => true, 'cleared' => true]);
    exit;
}

if (file_put_contents($file, $key) === false) {
    echo json_encode(['success' => false, 'error' => 'Could not write .ai_key file.']);
    exit;
}

echo json_encode(['success' => true]);
exit;
