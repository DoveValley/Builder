<?php
/**
 * Save per-icon render styles for the Brand Icons library (fill %, box vs
 * traced background, corner/trace %). Written to
 * sites/{site}/multisite/icon_styles.json, keyed by icon filename — a single
 * doc for the whole library, same shape as logo_configs_save.php /
 * visual_presets_save.php. Consumed by ms_resolve_icon_style() at render time.
 *
 * POST: csrf_token, styles = JSON {"icon.svg": {fill_pct, bg_style, corner_pct, trace_pct}, …}
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/multisite/visual.php';   // ms_sanitize_icon_style()
header('Content-Type: application/json');
if (empty($_SESSION['admin_logged_in']))                                      { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'Not authenticated.']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST')                                    { echo json_encode(['ok' => false, 'error' => 'POST only.']); exit; }
if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? ''))  { echo json_encode(['ok' => false, 'error' => 'Bad CSRF token.']); exit; }
if (!ACTIVE_SITE_ID)                                                          { echo json_encode(['ok' => false, 'error' => 'No active site.']); exit; }

$in = json_decode($_POST['styles'] ?? '', true);
if (!is_array($in)) { echo json_encode(['ok' => false, 'error' => 'No styles supplied.']); exit; }

$iconDir = ACTIVE_SITE_DIR . '/multisite/icons/';
$styles = [];
foreach ($in as $iconName => $style) {
    $icon = basename((string)$iconName);
    if ($icon === '' || !is_file($iconDir . $icon) || !is_array($style)) continue;   // drop unknown/missing icons
    $styles[$icon] = ms_sanitize_icon_style($style);
}

$file     = ACTIVE_SITE_DIR . '/multisite/icon_styles.json';
$existing = @json_decode((string)@file_get_contents($file), true) ?: [];
$doc = [
    '_about' => $existing['_about'] ?? 'Per-icon render style overrides for the Brand Icons library. Keyed by icon filename. fill_pct = how much of the tile the icon occupies; bg_style = box (rounded-square tile) or trace (background follows the icon\'s own silhouette); corner_pct = box corner roundness; trace_pct = how far the traced background extends past the icon\'s edge. An icon with no entry here renders with the historical fixed defaults (see ms_default_icon_style() in includes/multisite/visual.php).',
    'styles' => $styles,
];

$dir = dirname($file);
if (!is_dir($dir)) @mkdir($dir, 0775, true);
$tmp = $file . '.tmp.' . getmypid();
if (@file_put_contents($tmp, json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) === false || !@rename($tmp, $file)) {
    @unlink($tmp);
    echo json_encode(['ok' => false, 'error' => 'Could not write icon_styles.json (check file permissions).']);
    exit;
}
echo json_encode(['ok' => true, 'count' => count($styles)]);
