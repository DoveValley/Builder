<?php
/**
 * Gen-Image tab · save the photo-variation ranges into the build.
 * Auth + CSRF. Writes multisite/image_variation.json, which multisite/build_one.php
 * reads (per-master override at sites/{master}/multisite/image_variation.json wins
 * if present) and ms_perturb_image() jitters within. A missing/blank file means the
 * original hardcoded defaults — see ms_image_variation_defaults() in
 * includes/multisite/image_overlay.php.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/multisite/image_overlay.php';
header('Content-Type: application/json');

if (empty($_SESSION['admin_logged_in']))   { http_response_code(403); echo json_encode(['error' => 'Not authenticated.']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST')  { http_response_code(405); echo json_encode(['error' => 'POST required.']); exit; }
if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
    http_response_code(403); echo json_encode(['error' => 'Invalid security token.']); exit;
}

// ms_image_variation_ranges() clamps to sane bounds and swaps a flipped min/max —
// the one place both this save and the build itself validate, so a hand-edited
// file can never produce a broken crop either.
$ranges = ms_image_variation_ranges([
    'crop_min'       => $_POST['crop_min']       ?? null,
    'crop_max'       => $_POST['crop_max']       ?? null,
    'brightness_min' => $_POST['brightness_min'] ?? null,
    'brightness_max' => $_POST['brightness_max'] ?? null,
    'saturation_min' => $_POST['saturation_min'] ?? null,
    'saturation_max' => $_POST['saturation_max'] ?? null,
    'quality_min'    => $_POST['quality_min']    ?? null,
    'quality_max'    => $_POST['quality_max']    ?? null,
]);

$file = BASE_DIR . '/multisite/image_variation.json';
if (!is_dir(dirname($file))) { http_response_code(500); echo json_encode(['error' => 'multisite/ directory missing.']); exit; }
$tmp = $file . '.tmp.' . getmypid();
if (file_put_contents($tmp, json_encode($ranges, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false || !rename($tmp, $file)) {
    @unlink($tmp);
    echo json_encode(['error' => 'Could not write the ranges file.']); exit;
}
echo json_encode(['ok' => true, 'ranges' => $ranges]);
