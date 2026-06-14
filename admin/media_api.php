<?php
/**
 * admin/media_api.php — AJAX endpoints for the media library
 *
 * POST action=upload   multipart file field "file"
 * POST action=delete   field "filename"
 * POST action=update   fields "filename", "alt", "tags"
 * GET  action=list     returns JSON array of all media items
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

if (empty($_SESSION['admin_logged_in'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

define('MEDIA_DIR',  BASE_DIR . '/uploads/media/');
define('MEDIA_JSON', BASE_DIR . '/data/media.json');
define('MAX_WIDTH',  1600);

header('Content-Type: application/json');

function media_load(): array {
    if (!file_exists(MEDIA_JSON)) return [];
    $d = json_decode(file_get_contents(MEDIA_JSON), true);
    return is_array($d) ? $d : [];
}

function media_save(array $items): void {
    file_put_contents(MEDIA_JSON, json_encode(array_values($items), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function img_optimize(string $tmp, string $dest, string $mime): bool {
    if (!extension_loaded('gd')) return copy($tmp, $dest);
    $src = match($mime) {
        'image/jpeg' => @imagecreatefromjpeg($tmp),
        'image/png'  => @imagecreatefrompng($tmp),
        'image/webp' => @imagecreatefromwebp($tmp),
        'image/gif'  => @imagecreatefromgif($tmp),
        default      => false,
    };
    if (!$src) return copy($tmp, $dest);

    $ow = imagesx($src); $oh = imagesy($src);
    if ($ow > MAX_WIDTH) {
        $nw = MAX_WIDTH;
        $nh = (int) round($oh * MAX_WIDTH / $ow);
        $dst = imagecreatetruecolor($nw, $nh);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $ow, $oh);
        imagedestroy($src);
        $src = $dst;
    }
    $ok = imagewebp($src, $dest, 82);
    imagedestroy($src);
    return $ok;
}

$action = $_REQUEST['action'] ?? 'list';

// ── LIST ─────────────────────────────────────────────────────────────────────
if ($action === 'list') {
    $items = media_load();
    // newest first
    usort($items, fn($a,$b) => strcmp($b['added_at'] ?? '', $a['added_at'] ?? ''));
    echo json_encode($items);
    exit;
}

// ── UPLOAD ───────────────────────────────────────────────────────────────────
if ($action === 'upload') {
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['error' => 'Upload error']);
        exit;
    }

    $allowed = ['image/jpeg','image/png','image/gif','image/webp'];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($_FILES['file']['tmp_name']);

    if (!in_array($mime, $allowed)) {
        echo json_encode(['error' => 'Invalid file type']);
        exit;
    }
    if ($_FILES['file']['size'] > 20 * 1024 * 1024) {
        echo json_encode(['error' => 'File too large (max 20 MB)']);
        exit;
    }

    if (!is_dir(MEDIA_DIR)) mkdir(MEDIA_DIR, 0775, true);

    $orig     = pathinfo($_FILES['file']['name'], PATHINFO_FILENAME);
    $orig     = strtolower(preg_replace('/[^a-z0-9_-]/i', '-', $orig));
    $filename = $orig . '_' . substr(md5(uniqid('', true)), 0, 6) . '.webp';
    $dest     = MEDIA_DIR . $filename;

    if (!img_optimize($_FILES['file']['tmp_name'], $dest, $mime)) {
        echo json_encode(['error' => 'Failed to save image']);
        exit;
    }

    [$w, $h] = @getimagesize($dest) ?: [0, 0];
    $item = [
        'filename'   => $filename,
        'url'        => 'uploads/media/' . $filename,
        'width'      => $w,
        'height'     => $h,
        'size'       => filesize($dest),
        'alt'        => $_POST['alt'] ?? '',
        'tags'       => [],
        'source_url' => '',
        'added_at'   => date('Y-m-d H:i:s'),
    ];

    $items   = media_load();
    $items[] = $item;
    media_save($items);

    echo json_encode(['success' => true, 'item' => $item]);
    exit;
}

// ── DELETE ───────────────────────────────────────────────────────────────────
if ($action === 'delete') {
    $filename = basename($_POST['filename'] ?? '');
    if (!$filename) { echo json_encode(['error' => 'No filename']); exit; }

    $items   = media_load();
    $new     = array_filter($items, fn($i) => $i['filename'] !== $filename);
    media_save($new);

    $path = MEDIA_DIR . $filename;
    if (file_exists($path)) @unlink($path);

    echo json_encode(['success' => true]);
    exit;
}

// ── UPDATE ALT ───────────────────────────────────────────────────────────────
if ($action === 'update') {
    $filename = basename($_POST['filename'] ?? '');
    $alt      = trim($_POST['alt'] ?? '');
    if (!$filename) { echo json_encode(['error' => 'No filename']); exit; }

    $items = media_load();
    foreach ($items as &$item) {
        if ($item['filename'] === $filename) {
            $item['alt'] = $alt;
        }
    }
    media_save($items);
    echo json_encode(['success' => true]);
    exit;
}

echo json_encode(['error' => 'Unknown action']);
