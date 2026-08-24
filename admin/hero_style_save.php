<?php
/**
 * Gen-Image tab · lock the hero-overlay style into the build.
 * Auth + CSRF. Writes THIS master's own
 * sites/{master}/multisite/hero_style.json — the copy multisite/build_one.php
 * prefers. It used to write the repo-global multisite/hero_style.json, which every
 * master shares: a lock made on one master silently changed all of them, and was
 * ignored by any master that had its own file. The global copy is still read as a
 * fallback when a master has never locked a style, but nothing writes it now.
 * Sizes are stored with the reference image dimensions so the build can scale them
 * proportionally to each hero's actual size.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/multisite/image_overlay.php'; // ms_image_settings_write()
header('Content-Type: application/json');

if (empty($_SESSION['admin_logged_in']))   { http_response_code(403); echo json_encode(['error' => 'Not authenticated.']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST')  { http_response_code(405); echo json_encode(['error' => 'POST required.']); exit; }
if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
    http_response_code(403); echo json_encode(['error' => 'Invalid security token.']); exit;
}

$hex = fn($v, $d) => preg_match('/^#[0-9a-fA-F]{6}$/', (string)$v) ? $v : $d;
$int = fn($v, $lo, $hi, $d) => is_numeric($v) ? max($lo, min($hi, (int)$v)) : $d;
$pct = fn($v, $d) => is_numeric($v) ? max(0, min(100, (float)$v)) : $d;

$style = [
    'x'          => $pct($_POST['x'] ?? null, 5),
    'y'          => $pct($_POST['y'] ?? null, 80),
    'j1'         => in_array($_POST['j1'] ?? '', ['left', 'center', 'right'], true) ? $_POST['j1'] : 'left',
    'j2'         => in_array($_POST['j2'] ?? '', ['left', 'center', 'right'], true) ? $_POST['j2'] : 'left',
    'j3'         => in_array($_POST['j3'] ?? '', ['left', 'center', 'right'], true) ? $_POST['j3'] : 'left',
    'c1'         => $hex($_POST['c1'] ?? '', '#ffffff'),
    'c2'         => $hex($_POST['c2'] ?? '', '#ffffff'),
    's1'         => $int($_POST['s1'] ?? null, 8, 400, 44),
    's2'         => $int($_POST['s2'] ?? null, 8, 400, 40),
    'bg_side'    => in_array($_POST['bg_side'] ?? '', ['top', 'bottom', 'full', 'none'], true) ? $_POST['bg_side'] : 'bottom',
    'bg_height'  => $pct($_POST['bg_height'] ?? null, 55),
    'bg_fade'    => ($_POST['bg_fade'] ?? '1') !== '0',
    'bg_opacity' => $pct($_POST['bg_opacity'] ?? null, 100),
    'ref_w'      => $int($_POST['ref_w'] ?? null, 1, 20000, 715),
];

$res = ms_image_settings_write(ACTIVE_SITE_DIR, 'hero_style.json', $style);
if (!$res['ok']) { http_response_code(500); echo json_encode(['error' => $res['error']]); exit; }
echo json_encode(['ok' => true, 'style' => $style, 'scope' => 'master', 'site' => ACTIVE_SITE_ID]);
