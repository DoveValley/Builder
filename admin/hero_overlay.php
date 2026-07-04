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
 *   src    relative image path under the project (default: the pest hero)
 *   line1  keyword line          line2  city, ST line          line3  optional
 *   pos    bl | bc | tl          s1/s2  point sizes            scrim  gradient px
 *   c2     #rrggbb city color
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
$s1    = $clamp($_GET['s1'] ?? null, 12, 140, 44);
$s2    = $clamp($_GET['s2'] ?? null, 10, 120, 40);
$scrim = $clamp($_GET['scrim'] ?? null, 0, $H, (int)round($H * 0.5));
$pos   = in_array($_GET['pos'] ?? '', ['bl', 'bc', 'tl'], true) ? $_GET['pos'] : 'bl';
$c2    = preg_match('/^#[0-9a-fA-F]{6}$/', (string)($_GET['c2'] ?? '')) ? $_GET['c2'] : '#fd783b';
$c1    = '#ffffff';

// ── render via the shared overlay core (identical to the multisite build) ─────
require_once __DIR__ . '/../includes/multisite/image_overlay.php';

$outPng = tempnam(sys_get_temp_dir(), 'herolab_');
if ($outPng === false) $outPng = sys_get_temp_dir() . '/herolab_' . getmypid() . mt_rand();
@unlink($outPng);
$outPng .= '.png';

$r = ms_hero_overlay_render($src, $outPng, [
    'line1' => $line1, 'line2' => $line2, 'line3' => $line3,
    'pos' => $pos, 'c1' => $c1, 'c2' => $c2, 's1' => $s1, 's2' => $s2, 'scrim' => $scrim,
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
