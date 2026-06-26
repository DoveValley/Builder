<?php
// Lock / unlock a single AI-generated block in a city page file.
// POST only. Returns JSON.

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

if (!ACTIVE_SITE_ID) {
    echo json_encode(['success' => false, 'error' => 'No active site.']);
    exit;
}

$action     = $_POST['action']      ?? '';   // lock | unlock
$pageFile   = basename(trim($_POST['page_file']   ?? ''));
$blockIndex = (int)($_POST['block_index'] ?? -1);

if (!in_array($action, ['lock', 'unlock'], true)) {
    echo json_encode(['success' => false, 'error' => 'Invalid action.']);
    exit;
}

if (!preg_match('/^[a-z0-9_\-]+\.json$/', $pageFile)) {
    echo json_encode(['success' => false, 'error' => 'Invalid page file name.']);
    exit;
}

$fullPath = PAGES_DIR . $pageFile;
if (!file_exists($fullPath)) {
    echo json_encode(['success' => false, 'error' => 'Page file not found.']);
    exit;
}

$page = json_decode(file_get_contents($fullPath), true);
if (!is_array($page)) {
    echo json_encode(['success' => false, 'error' => 'Could not parse page file.']);
    exit;
}

$blocks = $page['content_blocks'] ?? [];
if ($blockIndex < 0 || $blockIndex >= count($blocks)) {
    echo json_encode(['success' => false, 'error' => 'Block index out of range.']);
    exit;
}

$block = $blocks[$blockIndex];
if ($block['type'] !== 'ai_block' || empty($block['_ai_generated'])) {
    echo json_encode(['success' => false, 'error' => 'Target block is not an AI-generated block.']);
    exit;
}

$locked = ($action === 'lock');
$page['content_blocks'][$blockIndex]['_ai_locked'] = $locked;

$content = json_encode($page, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$tmp     = $fullPath . '.tmp.' . getmypid();
if (file_put_contents($tmp, $content) === false || !rename($tmp, $fullPath)) {
    echo json_encode(['success' => false, 'error' => 'Could not save page file.']);
    exit;
}

echo json_encode(['success' => true, 'locked' => $locked]);
exit;
