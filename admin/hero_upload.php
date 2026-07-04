<?php
/**
 * Test Lab · scratch image upload (for previewing the hero overlay on your own
 * image). Auth + CSRF. Saves a validated image into uploads/lab/ so the overlay
 * generator can read it as a source. Scratch only — pruned after a day.
 */
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json');

if (empty($_SESSION['admin_logged_in']))            { http_response_code(403); echo json_encode(['error' => 'Not authenticated.']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST')          { http_response_code(405); echo json_encode(['error' => 'POST required.']); exit; }
if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
    http_response_code(403); echo json_encode(['error' => 'Invalid security token.']); exit;
}
if (empty($_FILES['image']) || ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    echo json_encode(['error' => 'No file uploaded.']); exit;
}

$f = $_FILES['image'];
if ($f['size'] > 8 * 1024 * 1024) { echo json_encode(['error' => 'Image too large (max 8 MB).']); exit; }

$info    = @getimagesize($f['tmp_name']);
$allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
if (!$info || !isset($allowed[$info['mime']])) { echo json_encode(['error' => 'Not a supported image (jpg, png, webp, gif).']); exit; }
$ext = $allowed[$info['mime']];

$dir = BASE_DIR . '/uploads/lab';
if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) { echo json_encode(['error' => 'Could not create scratch folder.']); exit; }

// Prune scratch files older than a day so this never accumulates.
foreach (glob($dir . '/*') ?: [] as $old) {
    if (is_file($old) && (time() - filemtime($old)) > 86400) @unlink($old);
}

$name = 'lab_' . date('YmdHis') . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
$dest = $dir . '/' . $name;
if (!move_uploaded_file($f['tmp_name'], $dest)) { echo json_encode(['error' => 'Could not save the uploaded file.']); exit; }
@chmod($dest, 0664);

echo json_encode(['ok' => true, 'src' => 'uploads/lab/' . $name, 'name' => $name, 'w' => (int)$info[0], 'h' => (int)$info[1]]);
