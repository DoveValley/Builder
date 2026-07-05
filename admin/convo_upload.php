<?php
/**
 * Test Lab · "Share with Claude" upload. Auth + CSRF. Saves a validated image
 * into uploads/convo/ and returns its ABSOLUTE server path, so it can be pasted
 * into a Claude Code conversation for the assistant to read off the VPS.
 * Persistent-ish scratch: pruned after 7 days so it never accumulates.
 */
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json');

if (empty($_SESSION['admin_logged_in']))   { http_response_code(403); echo json_encode(['error' => 'Not authenticated.']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST')  { http_response_code(405); echo json_encode(['error' => 'POST required.']); exit; }
if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
    http_response_code(403); echo json_encode(['error' => 'Invalid security token.']); exit;
}
if (empty($_FILES['image']) || ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    echo json_encode(['error' => 'No file uploaded.']); exit;
}

$f = $_FILES['image'];
if ($f['size'] > 12 * 1024 * 1024) { echo json_encode(['error' => 'Image too large (max 12 MB).']); exit; }

$info    = @getimagesize($f['tmp_name']);
$allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
if (!$info || !isset($allowed[$info['mime']])) { echo json_encode(['error' => 'Not a supported image (jpg, png, webp, gif).']); exit; }
$ext = $allowed[$info['mime']];

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
    'web'      => '/uploads/convo/' . $name,   // browser preview
    'abs_path' => $dest,                        // <-- paste THIS to Claude
    'w'        => (int)$info[0],
    'h'        => (int)$info[1],
    'size'     => (int)$f['size'],
]);
