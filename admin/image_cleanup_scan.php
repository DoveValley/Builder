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

// Occurrences (richer than $classified's plain page-label list) group by basename so a
// file used in more than one place still gets one vision call with the single best
// context — see imgclean_best_occurrence()'s docblock for the hero/keyword priority.
$occurrences = imgclean_usage_occurrences($siteData);
$occByFile = [];
foreach ($occurrences as $o) {
    $occByFile[basename($o['path'])][] = $o;
}

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
    $best    = imgclean_best_occurrence($occByFile[$filename] ?? []);
    $result  = imgclean_analyze($absPath, $filename, $contexts, $businessDescriptor, $best['primary_keyword'], $best['is_hero']);
    $done++;

    if ($result === null) {
        imgclean_emit(['type' => 'skipped', 'filename' => $filename, 'pages' => $contexts]);
    } else {
        $suggestedAlt = $result['suggested_alt_text'];
        imgclean_emit([
            'type'                => 'result',
            'filename'            => $filename,
            'pages'               => $contexts,
            'description'         => $result['description'],
            'matches_topic'       => $result['matches_topic'],
            'suggested_filename'  => $result['suggested_filename'],
            // Never propose "renaming" a file to the name it already has.
            'no_change_needed'    => $result['suggested_filename'] === $filename,
            'is_hero'             => $best['is_hero'],
            'primary_keyword'     => $best['primary_keyword'],
            'current_alt'         => $best['current_alt'],
            'suggested_alt_text'  => $suggestedAlt,
            // No alt field at all for this slot (e.g. a background photo) — nothing to
            // propose or apply, whatever Claude returned for it is meaningless here.
            'has_alt_field'       => $best['alt_field'] !== null,
            'alt_no_change'       => $suggestedAlt !== '' && $suggestedAlt === $best['current_alt'],
        ]);
    }
    imgclean_emit(['type' => 'progress', 'done' => $done, 'total' => $total]);
}

imgclean_emit(['type' => 'done', 'analyzed' => $done, 'orphaned' => count($classified['orphaned'])]);
