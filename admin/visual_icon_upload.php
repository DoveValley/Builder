<?php
/**
 * Upload / delete brand icons for the Visual Identity library (isolated endpoint,
 * CSRF-protected). Sanitized SVGs are saved to sites/{site}/multisite/icons/ —
 * the same folder the preset "Brand icon" dropdown and the logo/favicon generator
 * read from. SVG only; each file runs through sanitize_svg() before it's written.
 *
 * POST: csrf_token, action=upload|delete
 *   upload → icons[] (one or more .svg files)
 *   delete → icon=name.svg
 */
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json');
if (empty($_SESSION['admin_logged_in']))                                     { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'Not authenticated.']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST')                                   { echo json_encode(['ok' => false, 'error' => 'POST only.']); exit; }
if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) { echo json_encode(['ok' => false, 'error' => 'Bad CSRF token.']); exit; }
if (!ACTIVE_SITE_ID)                                                         { echo json_encode(['ok' => false, 'error' => 'No active site.']); exit; }
require_once __DIR__ . '/../includes/functions.php';   // sanitize_svg()

$dir = ACTIVE_SITE_DIR . '/multisite/icons/';
if (!is_dir($dir)) @mkdir($dir, 0775, true);

$action = $_POST['action'] ?? 'upload';

if ($action === 'delete') {
    $name = basename((string)($_POST['icon'] ?? ''));
    if ($name === '' || !preg_match('/\.svg$/i', $name) || !is_file($dir . $name)) {
        echo json_encode(['ok' => false, 'error' => 'Icon not found.']); exit;
    }
    @unlink($dir . $name);
    echo json_encode(['ok' => true, 'deleted' => $name]); exit;
}

// ── upload (one or more) ────────────────────────────────────────────────────────
if (empty($_FILES['icons']) || !is_array($_FILES['icons']['tmp_name'] ?? null)) {
    echo json_encode(['ok' => false, 'error' => 'No files uploaded.']); exit;
}
$added = []; $skipped = [];
$existing = array_map('basename', glob($dir . '*.svg') ?: []);
foreach ($_FILES['icons']['tmp_name'] as $i => $tmp) {
    $orig = (string)($_FILES['icons']['name'][$i] ?? ('#' . $i));
    if (($_FILES['icons']['error'][$i] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($tmp)) { $skipped[] = $orig; continue; }
    if (!preg_match('/\.svg$/i', $orig))       { $skipped[] = $orig . ' (not .svg)'; continue; }
    if ((int)filesize($tmp) > 512 * 1024)      { $skipped[] = $orig . ' (>512KB)';  continue; }
    $clean = sanitize_svg((string)file_get_contents($tmp));
    if ($clean === false)                      { $skipped[] = $orig . ' (invalid/unsafe SVG)'; continue; }
    $base = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower(pathinfo($orig, PATHINFO_FILENAME))), '-') ?: 'icon';
    $fname = $base . '.svg'; $n = 1;
    while (in_array($fname, $existing, true) || is_file($dir . $fname)) { $fname = $base . '-' . (++$n) . '.svg'; }
    if (@file_put_contents($dir . $fname, $clean) !== false) { $added[] = $fname; $existing[] = $fname; }
    else $skipped[] = $orig . ' (write failed)';
}
echo json_encode([
    'ok'      => count($added) > 0,
    'added'   => $added,
    'skipped' => $skipped,
    'count'   => count(glob($dir . '*.svg') ?: []),
]);
