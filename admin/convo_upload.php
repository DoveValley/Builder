<?php
/**
 * Test Lab · "Share with Claude" upload. Auth + CSRF. Saves a validated image
 * OR document into uploads/convo/ and returns its ABSOLUTE server path, so it can
 * be pasted into a Claude Code conversation for the assistant to read off the VPS.
 * Persistent-ish scratch: pruned after 7 days so it never accumulates.
 *
 * Images are validated by real content (getimagesize). Everything else is
 * validated against a whitelist of safe document/data extensions — executable
 * and script types (php, phtml, phar, cgi, pl, py, sh, svg, html, ...) are never
 * accepted, since this folder is web-served.
 */
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json');

if (empty($_SESSION['admin_logged_in']))   { http_response_code(403); echo json_encode(['error' => 'Not authenticated.']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST')  { http_response_code(405); echo json_encode(['error' => 'POST required.']); exit; }
if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
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

if ($isImage) {
    $ext = $imgTypes[$info['mime']];
} else {
    // Not an image → allow common document/data/text formats by extension.
    // Executable/script types are intentionally excluded (folder is web-served).
    $docExts = [
        'pdf', 'txt', 'md', 'markdown', 'csv', 'tsv', 'json', 'xml', 'yaml', 'yml', 'log',
        'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'rtf', 'odt', 'ods', 'odp', 'zip',
    ];
    $rawExt = strtolower(pathinfo($f['name'] ?? '', PATHINFO_EXTENSION));
    if (!in_array($rawExt, $docExts, true)) {
        echo json_encode(['error' => 'Unsupported file type. Allowed: images, PDF, text/markdown/CSV/JSON/XML, Office docs (doc/xls/ppt), rtf, zip.']); exit;
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
if (!move_uploaded_file($f['tmp_name'], $dest)) { echo json_encode(['error' => 'Could not save the uploaded file.']); exit; }
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
