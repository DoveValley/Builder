<?php
/**
 * Test Lab · "Share with Claude" upload. Auth + CSRF. Saves a validated image
 * OR document into uploads/convo/ and returns its ABSOLUTE server path, so it can
 * be pasted into a Claude Code conversation for the assistant to read off the VPS.
 * Persistent-ish scratch: pruned after 7 days so it never accumulates.
 *
 * Images are validated by real content (getimagesize). SVG is accepted too, but
 * only after sanitize_svg() strips <script>/handlers/javascript: URIs — the
 * SANITIZED content is what gets written, never the raw upload. Everything else
 * is validated against a whitelist of safe document/data extensions — executable
 * and script types (php, phtml, phar, cgi, pl, py, sh, html, ...) are never
 * accepted, since this folder is web-served.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/convo_uploads.php';   // the accepted-types list, shared with the picker
require_once __DIR__ . '/../includes/helpers.php';          // sanitize_svg()
header('Content-Type: application/json');

if (empty($_SESSION['admin_logged_in']))   { http_response_code(403); echo json_encode(['error' => 'Not authenticated.']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST')  { http_response_code(405); echo json_encode(['error' => 'POST required.']); exit; }
// The empty check is not redundant. hash_equals('', '') is TRUE, so a session with
// no token stored would accept a POST that carries none — the check passes by having
// nothing to compare. In normal use login.php always sets one, so this is depth
// rather than a live hole, but a guard that fails open is the wrong shape to leave
// lying around in an upload endpoint.
if (($_SESSION['csrf_token'] ?? '') === ''
    || !hash_equals($_SESSION['csrf_token'], (string) ($_POST['csrf_token'] ?? ''))) {
    http_response_code(403); echo json_encode(['error' => 'Invalid security token.']); exit;
}
if (empty($_FILES['image'])) {
    // When the whole POST body exceeds post_max_size, PHP discards it and
    // $_FILES arrives empty even though bytes were sent.
    $sent = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    echo json_encode(['error' => $sent > 0
        ? 'File too large for the server to accept (exceeds post_max_size).'
        : 'No file uploaded.']); exit;
}
$uerr = $_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE;
if ($uerr !== UPLOAD_ERR_OK) {
    $errmap = [
        UPLOAD_ERR_INI_SIZE   => 'File exceeds the server upload limit (upload_max_filesize).',
        UPLOAD_ERR_FORM_SIZE  => 'File is too large.',
        UPLOAD_ERR_PARTIAL    => 'Upload was interrupted — please try again.',
        UPLOAD_ERR_NO_FILE    => 'No file uploaded.',
        UPLOAD_ERR_NO_TMP_DIR => 'Server is missing a temp folder for uploads.',
        UPLOAD_ERR_CANT_WRITE => 'Server could not write the uploaded file to disk.',
    ];
    echo json_encode(['error' => $errmap[$uerr] ?? ('Upload failed (code ' . $uerr . ').')]); exit;
}

$f = $_FILES['image'];
if ($f['size'] > 20 * 1024 * 1024) { echo json_encode(['error' => 'File too large (max 20 MB).']); exit; }

// First try to validate as an image (real content check, gives w/h).
$info      = @getimagesize($f['tmp_name']);
$imgTypes  = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
$isImage   = $info && isset($imgTypes[$info['mime']]);
$rawExt    = strtolower(pathinfo($f['name'] ?? '', PATHINFO_EXTENSION));
$isSvg     = false;
$svgClean  = null;

if ($isImage) {
    $ext = $imgTypes[$info['mime']];
} elseif ($rawExt === 'svg') {
    // Not an image by getimagesize() (SVG is XML, not raster) — sanitize the raw
    // content the same way the Brand Icons upload does (includes/helpers.php),
    // stripping <script>/handlers/javascript: URIs, before it ever touches disk.
    // The SANITIZED string is what gets saved below, never the original upload.
    $raw = @file_get_contents($f['tmp_name']);
    $svgClean = $raw !== false ? sanitize_svg($raw) : false;
    if ($svgClean === false) {
        echo json_encode(['error' => 'Invalid or unsafe SVG content — could not sanitize.']); exit;
    }
    $isSvg = true;
    $ext = 'svg';
} else {
    // Not an image or SVG → allow document/data/source formats by extension. The
    // list is in includes/convo_uploads.php, shared with the file input's accept=""
    // on the Test Lab page, because two copies of it had already disagreed: .jsx was
    // added here and the picker still would not let one be selected.
    //
    // This check is the one that matters. accept="" is a hint the file dialog
    // applies; drag-and-drop and paste go straight past it.
    $docExts = convo_doc_exts();
    if (!in_array($rawExt, $docExts, true)) {
        echo json_encode(['error' => 'Unsupported file type (.' . $rawExt . '). Allowed: ' . convo_accept_note() . '.']); exit;
    }
    $ext = $rawExt;
}

$dir = BASE_DIR . '/uploads/convo';
if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) { echo json_encode(['error' => 'Could not create the convo folder.']); exit; }

// Prune files older than 7 days so this never accumulates.
foreach (glob($dir . '/*') ?: [] as $old) {
    if (is_file($old) && (time() - filemtime($old)) > 7 * 86400) @unlink($old);
}

// Keep the original basename (sanitized) as a hint, plus a unique stamp.
$origBase = pathinfo($f['name'] ?? '', PATHINFO_FILENAME);
$origBase = preg_replace('/[^a-zA-Z0-9_-]+/', '-', (string)$origBase);
$origBase = trim(substr($origBase, 0, 40), '-');
$name = date('YmdHis') . '_' . bin2hex(random_bytes(2)) . ($origBase !== '' ? '_' . $origBase : '') . '.' . $ext;
$dest = $dir . '/' . $name;
if ($isSvg) {
    // Write the SANITIZED string, not the original upload — move_uploaded_file()
    // would put the raw (pre-sanitize) bytes on disk instead.
    if (@file_put_contents($dest, $svgClean) === false) { echo json_encode(['error' => 'Could not save the uploaded file.']); exit; }
    @unlink($f['tmp_name']);
} elseif (!move_uploaded_file($f['tmp_name'], $dest)) {
    echo json_encode(['error' => 'Could not save the uploaded file.']); exit;
}
@chmod($dest, 0664);

echo json_encode([
    'ok'       => true,
    'name'     => $name,
    'ext'      => $ext,
    'is_image' => $isImage,
    'web'      => '/uploads/convo/' . $name,   // browser preview / download
    'abs_path' => $dest,                        // <-- paste THIS to Claude
    'w'        => $isImage ? (int)$info[0] : 0,
    'h'        => $isImage ? (int)$info[1] : 0,
    'size'     => (int)$f['size'],
]);
