<?php
/**
 * Test Lab · lock the hero-overlay style into the build.
 * Auth + CSRF. Writes multisite/hero_style.json, which multisite/build_one.php
 * reads (per-master override at sites/{master}/multisite/hero_style.json wins if
 * present). Sizes are stored with the reference image dimensions so the build can
 * scale them proportionally to each hero's actual size.
 */
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json');

if (empty($_SESSION['admin_logged_in']))   { http_response_code(403); echo json_encode(['error' => 'Not authenticated.']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST')  { http_response_code(405); echo json_encode(['error' => 'POST required.']); exit; }
if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
    http_response_code(403); echo json_encode(['error' => 'Invalid security token.']); exit;
}

$hex = fn($v, $d) => preg_match('/^#[0-9a-fA-F]{6}$/', (string)$v) ? $v : $d;
$int = fn($v, $lo, $hi, $d) => is_numeric($v) ? max($lo, min($hi, (int)$v)) : $d;

$style = [
    'pos'   => in_array($_POST['pos'] ?? '', ['bl', 'bc', 'tl'], true) ? $_POST['pos'] : 'bl',
    'c1'    => $hex($_POST['c1'] ?? '', '#ffffff'),
    'c2'    => $hex($_POST['c2'] ?? '', '#ffffff'),
    's1'    => $int($_POST['s1'] ?? null, 8, 400, 44),
    's2'    => $int($_POST['s2'] ?? null, 8, 400, 40),
    'scrim' => $int($_POST['scrim'] ?? null, 0, 4000, 300),
    'ref_w' => $int($_POST['ref_w'] ?? null, 1, 20000, 715),
    'ref_h' => $int($_POST['ref_h'] ?? null, 1, 20000, 600),
];

$file = BASE_DIR . '/multisite/hero_style.json';
if (!is_dir(dirname($file))) { http_response_code(500); echo json_encode(['error' => 'multisite/ directory missing.']); exit; }
$tmp = $file . '.tmp.' . getmypid();
if (file_put_contents($tmp, json_encode($style, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false || !rename($tmp, $file)) {
    @unlink($tmp);
    echo json_encode(['error' => 'Could not write the style file.']); exit;
}
echo json_encode(['ok' => true, 'style' => $style]);
