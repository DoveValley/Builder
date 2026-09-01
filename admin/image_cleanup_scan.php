<?php
// Image Name Cleanup — scan endpoint. Streams NDJSON: one line per result, so the
// panel can render progress as it goes rather than waiting for every image.
// GET only (SSE-style long request); token passed in the query string like
// generate_static.php, since a real <form> POST can't stream a response body here.
//
// Read-only except for the AI spend itself — this endpoint never renames or
// deletes anything. That only happens via image_cleanup_apply.php, and only for
// items the admin explicitly approved in the review table.

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/media_lib.php';
require_once __DIR__ . '/../includes/image_cleanup.php';

while (ob_get_level()) ob_end_clean();
header('Content-Type: application/x-ndjson');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');

function imgclean_emit(array $row): void
{
    echo json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    @ob_flush();
    flush();
}

if (empty($_SESSION['admin_logged_in'])) {
    http_response_code(403);
    imgclean_emit(['type' => 'fatal', 'msg' => 'Not authenticated.']);
    exit;
}
if (!hash_equals($_SESSION['csrf_token'] ?? '', $_GET['token'] ?? '')) {
    http_response_code(403);
    imgclean_emit(['type' => 'fatal', 'msg' => 'Invalid security token.']);
    exit;
}
$ready = anthropic_ready();
if (!$ready['ok']) {
    imgclean_emit(['type' => 'fatal', 'msg' => $ready['error']]);
    exit;
}

session_write_close();
set_time_limit(1800);

$siteData = load_data();
$classified = imgclean_classify($siteData);

$businessDescriptor = 'a local service business';
if (defined('ACTIVE_SITE_DIR')) {
    $brief = @json_decode((string) @file_get_contents(ACTIVE_SITE_DIR . '/multisite/niche_brief.json'), true);
    if (is_array($brief) && !empty($brief['business_descriptor'])) {
        $businessDescriptor = (string) $brief['business_descriptor'];
    }
}

foreach ($classified['orphaned'] as $f) {
    imgclean_emit(['type' => 'orphan', 'filename' => $f]);
}

$toAnalyze = [];
foreach ($classified['referenced'] as $filename => $contexts) {
    if (imgclean_is_content_file($filename)) $toAnalyze[$filename] = $contexts;
}

$total = count($toAnalyze);
$done  = 0;
imgclean_emit(['type' => 'progress', 'done' => 0, 'total' => $total]);

foreach ($toAnalyze as $filename => $contexts) {
    $absPath = MEDIA_DIR . $filename;
    $result  = imgclean_analyze($absPath, $filename, $contexts, $businessDescriptor);
    $done++;

    if ($result === null) {
        imgclean_emit(['type' => 'skipped', 'filename' => $filename, 'pages' => $contexts]);
    } else {
        imgclean_emit([
            'type'               => 'result',
            'filename'           => $filename,
            'pages'              => $contexts,
            'description'        => $result['description'],
            'matches_topic'      => $result['matches_topic'],
            'suggested_filename' => $result['suggested_filename'],
            // Never propose "renaming" a file to the name it already has.
            'no_change_needed'   => $result['suggested_filename'] === $filename,
        ]);
    }
    imgclean_emit(['type' => 'progress', 'done' => $done, 'total' => $total]);
}

imgclean_emit(['type' => 'done', 'analyzed' => $done, 'orphaned' => count($classified['orphaned'])]);
