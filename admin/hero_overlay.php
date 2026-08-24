<?php
/**
 * Test Lab · Hero text-overlay generator (item 4c).
 * Streams a hero image with up to 3 lines of text baked on, for previewing the
 * per-site hero styling before it's wired into the multisite build.
 *
 * READ-ONLY: reads a source image and streams a generated PNG. It never writes
 * into any site's uploads — the source file is never modified.
 *
 * Auth required. GET params (all optional, all sanitized/clamped):
 *   src        relative image path under the project (default: the pest hero)
 *   line1/2/3  text lines (line3 optional)     s1/s2  point sizes
 *   x/y        text anchor, % of image width/height (top-left of the text block)
 *   j1         left | center | right — line 1 justified relative to the image width
 *   j2/j3      left | center | right — line 2/3 justified relative to line 1's width
 *   c2         #rrggbb city color
 *   bg_side    top | bottom | full | none      bg_height  % of image height
 *   bg_fade    1 (gradient) | 0 (flat)          bg_opacity  0-100
 */
require_once __DIR__ . '/../config.php';
if (empty($_SESSION['admin_logged_in'])) { http_response_code(403); header('Content-Type: text/plain'); exit('Not authenticated.'); }

$root = realpath(BASE_DIR);

// ── source image: must resolve to a real image inside the project ─────────────
$defaultSrc = 'sites/pest-template/uploads/media/about-whitefly-treatment-katy_93c79d.webp';
$srcRel = (string)($_GET['src'] ?? $defaultSrc);
$src    = realpath($root . '/' . ltrim($srcRel, '/'));
if ($src === false || strncmp($src, $root . DIRECTORY_SEPARATOR, strlen($root) + 1) !== 0 || !is_file($src)) {
    http_response_code(400); header('Content-Type: text/plain'); exit('Invalid source image.');
}
$info = @getimagesize($src);
if (!$info || (int)$info[0] < 1) { http_response_code(400); header('Content-Type: text/plain'); exit('Source is not a readable image.'); }
$W = (int)$info[0]; $H = (int)$info[1];

// ── text: keep letters/numbers/basic punctuation, cap length ──────────────────
function _hl_clean($s): string {
    $s = preg_replace('/[^\p{L}\p{N} ,.&\'\-\/()]+/u', '', (string)$s);
    return trim(mb_substr($s ?? '', 0, 60));
}
$line1 = _hl_clean($_GET['line1'] ?? 'Cockroach Exterminator');
$line2 = _hl_clean($_GET['line2'] ?? 'Dallas, TX');
$line3 = _hl_clean($_GET['line3'] ?? '');

// ── style: clamp everything ───────────────────────────────────────────────────
$clamp = fn($v, $lo, $hi, $d) => is_numeric($v) ? max($lo, min($hi, (int)$v)) : $d;
$pct   = fn($v, $d) => is_numeric($v) ? max(0, min(100, (float)$v)) : $d;
$s1    = $clamp($_GET['s1'] ?? null, 12, 140, 44);
$s2    = $clamp($_GET['s2'] ?? null, 10, 120, 40);
$x     = $pct($_GET['x'] ?? null, 5);
$y     = $pct($_GET['y'] ?? null, 80);
$j1    = in_array($_GET['j1'] ?? '', ['left', 'center', 'right'], true) ? $_GET['j1'] : 'left';
$j2    = in_array($_GET['j2'] ?? '', ['left', 'center', 'right'], true) ? $_GET['j2'] : 'left';
$j3    = in_array($_GET['j3'] ?? '', ['left', 'center', 'right'], true) ? $_GET['j3'] : 'left';
$c2    = preg_match('/^#[0-9a-fA-F]{6}$/', (string)($_GET['c2'] ?? '')) ? $_GET['c2'] : '#fd783b';
$c1    = '#ffffff';
$bgSide    = in_array($_GET['bg_side'] ?? '', ['top', 'bottom', 'full', 'none'], true) ? $_GET['bg_side'] : 'bottom';
$bgHeight  = $pct($_GET['bg_height'] ?? null, 55);
$bgFade    = ($_GET['bg_fade'] ?? '1') !== '0';
$bgOpacity = $pct($_GET['bg_opacity'] ?? null, 100);

// ── render via the shared overlay core (identical to the multisite build) ─────
require_once __DIR__ . '/../includes/multisite/image_overlay.php';

$outPng = tempnam(sys_get_temp_dir(), 'herolab_');
if ($outPng === false) $outPng = sys_get_temp_dir() . '/herolab_' . getmypid() . mt_rand();
@unlink($outPng);
$outPng .= '.png';

$r = ms_hero_overlay_render($src, $outPng, [
    'line1' => $line1, 'line2' => $line2, 'line3' => $line3,
    'x' => $x, 'y' => $y, 'j1' => $j1, 'j2' => $j2, 'j3' => $j3, 'c1' => $c1, 'c2' => $c2, 's1' => $s1, 's2' => $s2,
    'bg_side' => $bgSide, 'bg_height' => $bgHeight, 'bg_fade' => $bgFade, 'bg_opacity' => $bgOpacity,
    'W' => $W, 'H' => $H,
]);

if (isset($_GET['debug'])) {
    @unlink($outPng);
    header('Content-Type: text/plain');
    echo 'ok      : ' . (!empty($r['ok']) ? 'yes' : 'no') . "\n";
    echo "src     : {$src}\n";
    echo "size    : {$W}x{$H}\n\n";
    echo "command :\n" . ($r['cmd'] ?? '') . "\n\n";
    echo "error   :\n" . ($r['error'] ?? '') . "\n";
    exit;
}

if (empty($r['ok'])) {
    @unlink($outPng);
    http_response_code(500); header('Content-Type: text/plain');
    exit('Image generation failed: ' . ($r['error'] ?? 'unknown'));
}

header('Content-Type: image/png');
header('Cache-Control: no-store');
header('Content-Length: ' . filesize($outPng));
readfile($outPng);
@unlink($outPng);
