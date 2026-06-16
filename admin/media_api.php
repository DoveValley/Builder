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
define('MAX_WIDTH',  1800);

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

function compute_dhash(string $filepath, string $mime): ?string {
    if (!extension_loaded('gd')) return null;
    $src = match($mime) {
        'image/jpeg' => @imagecreatefromjpeg($filepath),
        'image/png'  => @imagecreatefrompng($filepath),
        'image/webp' => @imagecreatefromwebp($filepath),
        default      => null,
    };
    if (!$src) return null;

    // Resize to 9×8: 8 horizontal comparisons per row = 64-bit hash
    $small = imagecreatetruecolor(9, 8);
    imagecopyresampled($small, $src, 0, 0, 0, 0, 9, 8, imagesx($src), imagesy($src));
    imagedestroy($src);

    $bits = '';
    for ($y = 0; $y < 8; $y++) {
        for ($x = 0; $x < 8; $x++) {
            $l  = imagecolorat($small, $x,     $y);
            $r  = imagecolorat($small, $x + 1, $y);
            $gl = (($l >> 16 & 0xFF) * 299 + ($l >> 8 & 0xFF) * 587 + ($l & 0xFF) * 114) / 1000;
            $gr = (($r >> 16 & 0xFF) * 299 + ($r >> 8 & 0xFF) * 587 + ($r & 0xFF) * 114) / 1000;
            $bits .= $gl >= $gr ? '1' : '0';
        }
    }
    imagedestroy($small);

    $hex = '';
    for ($i = 0; $i < 8; $i++) {
        $hex .= sprintf('%02x', bindec(substr($bits, $i * 8, 8)));
    }
    return $hex; // 16-char hex = 64-bit perceptual hash
}

function hamming_distance(string $h1, string $h2): int {
    $dist = 0;
    $len  = min(strlen($h1), strlen($h2));
    for ($i = 0; $i < $len; $i++) {
        $xor  = hexdec($h1[$i]) ^ hexdec($h2[$i]);
        $dist += substr_count(sprintf('%04b', $xor), '1');
    }
    return $dist; // 0 = identical, 64 = completely different
}

function get_variation_params(int $seed, string $filename): array {
    // Separate hashes per dimension for independence
    $hf = abs(crc32('flip'   . $seed . $filename));
    $hb = abs(crc32('bright' . $seed . $filename));
    $hs = abs(crc32('side'   . $seed . $filename));
    $hp = abs(crc32('pct'    . $seed . $filename));

    $flip     = ($hf % 2) === 1;
    $cropSide = $hs % 5; // 0=none, 1=top, 2=bottom, 3=left, 4=right
    if (!$flip && $cropSide === 0) $flip = true; // always flip or crop

    return [
        'flip'       => $flip,
        'brightness' => (int)($hb % 17) - 8,  // -8 to +8
        'crop_side'  => $cropSide,
        'crop_pct'   => (int)($hp % 4) + 2,   // 2–5%
    ];
}

function apply_variation(string $filepath, string $mime, array $p): bool {
    $src = match($mime) {
        'image/jpeg' => @imagecreatefromjpeg($filepath),
        'image/png'  => @imagecreatefrompng($filepath),
        'image/webp' => @imagecreatefromwebp($filepath),
        default      => null,
    };
    if (!$src) return false;

    if ($p['flip']) {
        imageflip($src, IMG_FLIP_HORIZONTAL);
    }

    if ($p['brightness'] !== 0) {
        imagefilter($src, IMG_FILTER_BRIGHTNESS, $p['brightness'] * 3); // ±24 GD units
    }

    if ($p['crop_side'] > 0) {
        $ow = imagesx($src); $oh = imagesy($src);
        $px = (int) round(min($ow, $oh) * $p['crop_pct'] / 100);
        $rect = match($p['crop_side']) {
            1 => ['x' => 0,   'y' => $px, 'width' => $ow,       'height' => $oh - $px],
            2 => ['x' => 0,   'y' => 0,   'width' => $ow,       'height' => $oh - $px],
            3 => ['x' => $px, 'y' => 0,   'width' => $ow - $px, 'height' => $oh      ],
            4 => ['x' => 0,   'y' => 0,   'width' => $ow - $px, 'height' => $oh      ],
            default => null,
        };
        if ($rect) {
            $cropped = imagecrop($src, $rect);
            if ($cropped) { imagedestroy($src); $src = $cropped; }
        }
    }

    $ok = imagewebp($src, $filepath, 82);
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

// ── BATCH VARIATION ──────────────────────────────────────────────────────────
if ($action === 'vary_batch') {
    $seed = (int) ($_POST['seed'] ?? 0);
    if ($seed < 1 || $seed > 9999) { echo json_encode(['error' => 'Seed must be 1–9999']); exit; }
    if (!extension_loaded('gd'))    { echo json_encode(['error' => 'GD not available']);    exit; }

    $items   = media_load();
    $varied  = 0; $skipped = 0; $failed = 0;
    $finfo   = new finfo(FILEINFO_MIME_TYPE);

    foreach ($items as &$item) {
        if (($item['varied_seed'] ?? null) === $seed) { $skipped++; continue; }
        $path = MEDIA_DIR . $item['filename'];
        if (!file_exists($path)) { $failed++; continue; }
        $mime = $finfo->file($path);
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'])) { $skipped++; continue; }

        $params = get_variation_params($seed, $item['filename']);
        if (apply_variation($path, $mime, $params)) {
            [$nw, $nh]           = @getimagesize($path) ?: [0, 0];
            $item['varied_seed'] = $seed;
            $item['width']       = $nw;
            $item['height']      = $nh;
            $item['size']        = filesize($path);
            $item['dhash']       = compute_dhash($path, 'image/webp') ?? ($item['dhash'] ?? null);
            $varied++;
        } else {
            $failed++;
        }
    }
    unset($item);
    media_save($items);

    file_put_contents(
        BASE_DIR . '/data/variation.json',
        json_encode(['seed' => $seed, 'applied_at' => date('Y-m-d H:i:s'), 'count' => $varied], JSON_PRETTY_PRINT)
    );

    echo json_encode(['success' => true, 'varied' => $varied, 'skipped' => $skipped, 'failed' => $failed]);
    exit;
}

// ── HASH ALL (backfill perceptual hashes) ────────────────────────────────────
if ($action === 'hash_all') {
    if (!extension_loaded('gd')) { echo json_encode(['error' => 'GD not available']); exit; }
    $items   = media_load();
    $updated = 0;
    $finfo   = new finfo(FILEINFO_MIME_TYPE);
    foreach ($items as &$item) {
        if (!empty($item['dhash'])) continue;
        $path = MEDIA_DIR . $item['filename'];
        if (!file_exists($path)) continue;
        $hash = compute_dhash($path, $finfo->file($path));
        if ($hash !== null) { $item['dhash'] = $hash; $updated++; }
    }
    unset($item);
    if ($updated > 0) media_save($items);
    echo json_encode(['success' => true, 'updated' => $updated]);
    exit;
}

// ── DUPLICATE DETECT ─────────────────────────────────────────────────────────
if ($action === 'dupes') {
    $threshold = 10; // Hamming distance out of 64 bits (~84% similarity)
    $items     = array_values(array_filter(media_load(), fn($i) => !empty($i['dhash'])));
    $n         = count($items);
    $used      = array_fill(0, $n, false);
    $groups    = [];

    for ($i = 0; $i < $n; $i++) {
        if ($used[$i]) continue;
        $group = [$items[$i]];
        for ($j = $i + 1; $j < $n; $j++) {
            if ($used[$j]) continue;
            if (hamming_distance($items[$i]['dhash'], $items[$j]['dhash']) <= $threshold) {
                $group[]  = $items[$j];
                $used[$j] = true;
            }
        }
        if (count($group) > 1) {
            $used[$i] = true;
            $groups[] = $group;
        }
    }

    echo json_encode($groups);
    exit;
}

// ── USAGE SCAN ───────────────────────────────────────────────────────────────
if ($action === 'usage') {
    $siteData = file_exists(DATA_FILE) ? (json_decode(file_get_contents(DATA_FILE), true) ?? []) : [];
    $usages   = [];

    $blockLabels = [
        'hero'            => 'Hero Banner',    'hero_split'    => 'Hero Split',
        'feature_split'   => 'Feature Split',  'image_features'=> 'Image Features',
        'wide_banner'     => 'Wide Banner',    'hero_grid'     => 'Hero Grid',
        'tab_services'    => 'Tab Services',   'service_cards' => 'Service Cards',
        'image_left'      => 'Image Left',     'image_right'   => 'Image Right',
        'feature_columns' => 'Feature Cols',   'steps'         => 'Steps',
        'cards'           => 'Cards',          'gallery'       => 'Gallery',
        'map_info'        => 'Map & Info',     'links_grid'    => 'Links Grid',
        'image_text'      => 'Image & Text',   'cta_card'      => 'CTA Card',
        'split_cta'       => 'Split CTA',      'stats'         => 'Stats',
    ];

    $addUsage = function(string $url, string $ctx) use (&$usages): void {
        if (!in_array($ctx, $usages[$url] ?? [], true)) {
            $usages[$url][] = $ctx;
        }
    };

    $scanBlock = function(array $block, string $page) use ($blockLabels, $addUsage): void {
        $type    = $block['type'] ?? 'block';
        $label   = $blockLabels[$type] ?? ucwords(str_replace('_', ' ', $type));
        $context = $page . ' › ' . $label;
        array_walk_recursive($block, function($v) use ($context, $addUsage): void {
            if (is_string($v) && str_starts_with($v, 'uploads/')) {
                $addUsage($v, $context);
            }
        });
    };

    // Homepage
    foreach ($siteData['content_blocks'] ?? [] as $block) {
        $scanBlock($block, 'Home');
    }

    // Landing pages
    foreach ($siteData['pages'] ?? [] as $page) {
        $rawTitle = $page['title'] ?? ($page['slug'] ?? 'Page');
        $title    = preg_replace('/\s*[|\-–—].*$/', '', trim($rawTitle));
        foreach ($page['content_blocks'] ?? [] as $block) {
            $scanBlock($block, $title);
        }
        $pageOg = $page['seo']['og_image'] ?? '';
        if ($pageOg && str_starts_with($pageOg, 'uploads/')) {
            $addUsage($pageOg, $title . ' › OG Image');
        }
    }

    // Header (logo, etc.)
    array_walk_recursive($siteData['header'] ?? [], function($v) use ($addUsage): void {
        if (is_string($v) && str_starts_with($v, 'uploads/')) {
            $addUsage($v, 'Global › Header');
        }
    });

    // Global SEO OG image
    $globalOg = $siteData['seo']['og_image'] ?? '';
    if ($globalOg && str_starts_with($globalOg, 'uploads/')) {
        $addUsage($globalOg, 'Global › OG Image');
    }

    echo json_encode((object) $usages); // (object) preserves {} when empty
    exit;
}

// ── FOCAL POINT ──────────────────────────────────────────────────────────────
if ($action === 'focal') {
    $filename = basename($_POST['filename'] ?? '');
    $fx = max(0.0, min(100.0, (float) ($_POST['focal_x'] ?? 50)));
    $fy = max(0.0, min(100.0, (float) ($_POST['focal_y'] ?? 50)));

    if (!$filename) { echo json_encode(['error' => 'No filename']); exit; }

    $items = media_load();
    $found = false;
    foreach ($items as &$item) {
        if ($item['filename'] === $filename) {
            $item['focal_x'] = round($fx, 1);
            $item['focal_y'] = round($fy, 1);
            $found = true;
            break;
        }
    }
    unset($item);
    if (!$found) { echo json_encode(['error' => 'File not found']); exit; }
    media_save($items);

    echo json_encode(['success' => true, 'focal_x' => round($fx, 1), 'focal_y' => round($fy, 1)]);
    exit;
}

// ── CROP ─────────────────────────────────────────────────────────────────────
if ($action === 'crop') {
    $filename = basename($_POST['filename'] ?? '');
    $x = (int) round((float) ($_POST['x']      ?? 0));
    $y = (int) round((float) ($_POST['y']      ?? 0));
    $w = (int) round((float) ($_POST['width']  ?? 0));
    $h = (int) round((float) ($_POST['height'] ?? 0));

    if (!$filename || $w < 1 || $h < 1) {
        echo json_encode(['error' => 'Invalid crop parameters']); exit;
    }

    $filepath = MEDIA_DIR . $filename;
    if (!file_exists($filepath) || !is_file($filepath)) {
        echo json_encode(['error' => 'File not found']); exit;
    }

    if (!extension_loaded('gd')) {
        echo json_encode(['error' => 'GD not available']); exit;
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($filepath);
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'])) {
        echo json_encode(['error' => 'Unsupported format for crop']); exit;
    }

    $src = match($mime) {
        'image/jpeg' => @imagecreatefromjpeg($filepath),
        'image/png'  => @imagecreatefrompng($filepath),
        'image/webp' => @imagecreatefromwebp($filepath),
        default      => false,
    };
    if (!$src) { echo json_encode(['error' => 'Failed to open image']); exit; }

    $ow = imagesx($src); $oh = imagesy($src);
    $x = max(0, min($x, $ow - 1));
    $y = max(0, min($y, $oh - 1));
    $w = max(1, min($w, $ow - $x));
    $h = max(1, min($h, $oh - $y));

    $cropped = imagecrop($src, ['x' => $x, 'y' => $y, 'width' => $w, 'height' => $h]);
    imagedestroy($src);
    if (!$cropped) { echo json_encode(['error' => 'Crop failed']); exit; }

    $tmp = tempnam(sys_get_temp_dir(), 'crop_') . '.webp';
    $ok  = imagewebp($cropped, $tmp, 92);
    imagedestroy($cropped);
    if (!$ok) { @unlink($tmp); echo json_encode(['error' => 'Encode failed']); exit; }

    if (!img_optimize($tmp, $filepath, 'image/webp')) {
        @unlink($tmp); echo json_encode(['error' => 'Failed to save']); exit;
    }
    @unlink($tmp);

    [$nw, $nh] = @getimagesize($filepath) ?: [0, 0];
    $newSize   = filesize($filepath);

    $items = media_load();
    foreach ($items as &$item) {
        if ($item['filename'] === $filename) {
            $item['width']  = $nw;
            $item['height'] = $nh;
            $item['size']   = $newSize;
            break;
        }
    }
    unset($item);
    media_save($items);

    echo json_encode(['success' => true, 'width' => $nw, 'height' => $nh, 'size' => $newSize]);
    exit;
}

echo json_encode(['error' => 'Unknown action']);
