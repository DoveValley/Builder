<?php
// Image Name Cleanup — apply endpoint. POST only, CSRF-protected.
//
// Only acts on what's explicitly submitted — nothing here re-derives "what should
// change" from the last scan; the browser sends exactly the rows the admin ticked
// in the review table. Orphan deletion re-checks (fresh scan) that a file is still
// unreferenced right before deleting it, in case data changed since the scan ran.

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/media_lib.php';
require_once __DIR__ . '/../includes/image_cleanup.php';

header('Content-Type: application/json');

if (empty($_SESSION['admin_logged_in'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Not authenticated.']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required.']);
    exit;
}
if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid security token.']);
    exit;
}

$renames = json_decode($_POST['renames'] ?? '[]', true);
$deletes = json_decode($_POST['deletes'] ?? '[]', true);
if (!is_array($renames)) $renames = [];
if (!is_array($deletes)) $deletes = [];

$renameResults = [];
foreach ($renames as $row) {
    $old = basename((string) ($row['old'] ?? ''));
    $new = basename((string) ($row['new'] ?? ''));
    if ($old === '' || $new === '') continue;
    $r = imgclean_apply_rename($old, $new);
    $renameResults[] = ['old' => $old, 'new' => $new] + $r;
}

// Re-load data fresh — a rename above may have just changed it — before checking
// which delete requests are still genuinely orphaned.
$siteData = load_data();
$deleteResults = [];
foreach ($deletes as $filename) {
    $filename = basename((string) $filename);
    if ($filename === '') continue;
    $r = imgclean_delete_orphan($filename, $siteData);
    $deleteResults[] = ['filename' => $filename] + $r;
}

echo json_encode([
    'renames' => $renameResults,
    'deletes' => $deleteResults,
]);
