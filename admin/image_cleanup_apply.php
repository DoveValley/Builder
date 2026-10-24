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

$renames  = json_decode($_POST['renames'] ?? '[]', true);
$deletes  = json_decode($_POST['deletes'] ?? '[]', true);
$altTexts = json_decode($_POST['alt_texts'] ?? '[]', true);
if (!is_array($renames))  $renames  = [];
if (!is_array($deletes))  $deletes  = [];
if (!is_array($altTexts)) $altTexts = [];

$renameResults = [];
foreach ($renames as $row) {
    $old = basename((string) ($row['old'] ?? ''));
    $new = basename((string) ($row['new'] ?? ''));
    if ($old === '' || $new === '') continue;
    $r = imgclean_apply_rename($old, $new);
    $renameResults[] = ['old' => $old, 'new' => $new] + $r;
}

// Alt text runs AFTER renames, matching on whatever filename is CURRENT right now — the
// browser sends each row's post-rename name already (see admin/index.php's imgcleanApply:
// it resolves this per-row before building the request), but resolving it again here
// too means a row whose rename failed above still gets its alt text applied against the
// name that's actually still on disk, not one that silently never took effect.
$renameNewByOld = [];
foreach ($renameResults as $r) { if ($r['ok']) $renameNewByOld[$r['old']] = $r['new']; }
$altResults = [];
foreach ($altTexts as $row) {
    $filename = basename((string) ($row['filename'] ?? ''));
    $alt      = (string) ($row['alt'] ?? '');
    if ($filename === '' || $alt === '') continue;
    $filename = $renameNewByOld[$filename] ?? $filename;
    $r = imgclean_apply_alt_text($filename, $alt);
    $altResults[] = ['filename' => $filename, 'alt' => $alt] + $r;
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
    'renames'   => $renameResults,
    'deletes'   => $deleteResults,
    'alt_texts' => $altResults,
]);
