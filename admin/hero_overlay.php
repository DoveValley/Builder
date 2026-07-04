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

// Resolve the ImageMagick binary by absolute path — the web SAPI's exec() PATH
// is often minimal and won't find a bare "convert".
$convert = 'convert';
foreach (['/usr/bin/convert', '/usr/local/bin/convert', '/bin/convert'] as $cand) {
    if (@is_executable($cand)) { $convert = $cand; break; }
}

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
$FONT  = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';

// ── lay out lines top→bottom, then assign y from the correct gravity edge ─────
$lines = [['t' => $line1, 's' => $s1, 'c' => $c1]];
if ($line2 !== '') $lines[] = ['t' => $line2, 's' => $s2, 'c' => $c2];
if ($line3 !== '') $lines[] = ['t' => $line3, 's' => $s2, 'c' => $c2];

$pad = 30;
$gap = (int)round($s2 * 0.30);
if ($pos === 'tl') { $grav = 'northwest'; $scrimGrav = 'north'; $scrimGrad = 'gradient:black-none'; $topDown = true;  $xoff = $pad; }
elseif ($pos === 'bc') { $grav = 'south'; $scrimGrav = 'south'; $scrimGrad = 'gradient:none-black'; $topDown = false; $xoff = 0; }
else { $grav = 'southwest'; $scrimGrav = 'south'; $scrimGrad = 'gradient:none-black'; $topDown = false; $xoff = $pad; }

$n = count($lines);
$yoff = array_fill(0, $n, $pad);
if ($topDown) {
    $y = $pad;
    for ($i = 0; $i < $n; $i++) { $yoff[$i] = $y; $y += $lines[$i]['s'] + $gap; }
} else {
    $y = $pad;
    for ($i = $n - 1; $i >= 0; $i--) { $yoff[$i] = $y; $y += $lines[$i]['s'] + $gap; }
}

// ── build the convert command (each token escaped; no shell interpolation) ────
$cmd = [$convert, $src];
if ($scrim > 0) {
    $cmd = array_merge($cmd, ['(', '-size', "{$W}x{$scrim}", $scrimGrad, ')', '-gravity', $scrimGrav, '-composite']);
}
$cmd = array_merge($cmd, ['-font', $FONT, '-gravity', $grav]);
foreach ($lines as $i => $ln) {
    $at = '+' . $xoff . '+' . $yoff[$i];
    // halo pass (dark stroke) for legibility on any photo, then the fill pass
    $cmd = array_merge($cmd, [
        '-pointsize', (string)$ln['s'],
        '-strokewidth', '3', '-stroke', 'rgba(0,0,0,0.55)', '-fill', 'rgba(0,0,0,0.55)', '-annotate', $at, $ln['t'],
        '-strokewidth', '0', '-stroke', 'none', '-fill', $ln['c'], '-annotate', $at, $ln['t'],
    ]);
}

$tmp = tempnam(sys_get_temp_dir(), 'herolab_') ?: (sys_get_temp_dir() . '/herolab_' . getmypid());
$cmd[] = 'png:' . $tmp;

$shell = implode(' ', array_map('escapeshellarg', $cmd));
exec($shell . ' 2>&1', $out, $rc);

if (isset($_GET['debug'])) {
    @unlink($tmp);
    header('Content-Type: text/plain');
    echo "convert : {$convert}\n";
    echo "rc      : {$rc}\n";
    echo "src     : {$src}\n";
    echo "size    : {$W}x{$H}\n\n";
    echo "command :\n{$shell}\n\n";
    echo "output  :\n" . implode("\n", $out) . "\n";
    exit;
}

if ($rc !== 0 || !is_file($tmp) || filesize($tmp) < 1) {
    @unlink($tmp);
    http_response_code(500); header('Content-Type: text/plain');
    exit('Image generation failed: ' . implode("\n", array_slice($out, 0, 5)));
}

header('Content-Type: image/png');
header('Cache-Control: no-store');
header('Content-Length: ' . filesize($tmp));
readfile($tmp);
@unlink($tmp);
