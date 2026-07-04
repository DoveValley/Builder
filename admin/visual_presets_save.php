<?php
/**
 * Save the Multisite → Visual Identity editor. Writes the active master's
 * theme_presets.json from the posted preset list (1–10). Isolated endpoint —
 * same CSRF pattern as the other admin POST handlers. Returns JSON.
 *
 * POST: csrf_token, presets = JSON [{name, accent, dark, font, radius, icon}, …]
 */
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json');
if (empty($_SESSION['admin_logged_in']))                                      { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'Not authenticated.']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST')                                    { echo json_encode(['ok' => false, 'error' => 'POST only.']); exit; }
if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? ''))  { echo json_encode(['ok' => false, 'error' => 'Bad CSRF token.']); exit; }

$in = json_decode($_POST['presets'] ?? '', true);
if (!is_array($in) || count($in) < 1)  { echo json_encode(['ok' => false, 'error' => 'No presets supplied.']); exit; }
if (count($in) > 10)                   { echo json_encode(['ok' => false, 'error' => 'Maximum 10 presets.']); exit; }

$iconDir = ACTIVE_SITE_DIR . '/multisite/icons/';
$presets = [];
$i = 0;
foreach ($in as $p) {
    $i++;
    if (!is_array($p)) continue;
    $name   = trim((string)($p['name'] ?? '')); if ($name === '') $name = 'Preset ' . $i;
    $accent = preg_match('/^#[0-9a-fA-F]{6}$/', $p['accent'] ?? '') ? $p['accent'] : '#333333';
    $dark   = preg_match('/^#[0-9a-fA-F]{6}$/', $p['dark']   ?? '') ? $p['dark']   : '#111111';
    $font   = (isset($p['font']) && preg_match('/^[a-zA-Z0-9 ,\-]+$/', $p['font'])) ? trim($p['font']) : 'Inclusive Sans, sans-serif';
    $radius = (string)max(0, min(50, (int)($p['radius'] ?? 5)));
    $icon   = basename((string)($p['icon'] ?? ''));
    if ($icon !== '' && !is_file($iconDir . $icon)) $icon = '';   // drop a missing icon
    $presets[] = [
        'id'   => $i,
        'name' => $name,
        'note' => trim((string)($p['note'] ?? '')),
        'icon' => $icon,
        'theme' => [
            'accent_color'  => $accent,
            'header_bg'     => $dark,
            'footer_bg'     => $dark,
            'heading_color' => $dark,
            'header_text'   => '#ffffff',
            'footer_text'   => '#ffffff',
            'header_top_bg' => '#ffffff',
            'primary_font'  => $font,
            'heading_font'  => '',
            'button_radius' => $radius,
        ],
        'header' => ['nav_bg' => 'accent'],
    ];
}
if (!$presets) { echo json_encode(['ok' => false, 'error' => 'No valid presets.']); exit; }

$file     = ACTIVE_SITE_DIR . '/multisite/theme_presets.json';
$existing = @json_decode((string)@file_get_contents($file), true) ?: [];
$doc = [
    '_about'  => $existing['_about'] ?? 'Per-niche Theme Presets. Applied per site by the multisite build (merge preset.theme→data theme, preset.header→data header) + a generated logo/favicon in the preset colors with preset.icon.',
    'niche'   => $existing['niche'] ?? '',
    'presets' => $presets,
];

$dir = dirname($file);
if (!is_dir($dir)) @mkdir($dir, 0775, true);
$tmp = $file . '.tmp.' . getmypid();
if (@file_put_contents($tmp, json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) === false || !@rename($tmp, $file)) {
    @unlink($tmp);
    echo json_encode(['ok' => false, 'error' => 'Could not write theme_presets.json (check file permissions).']);
    exit;
}
echo json_encode(['ok' => true, 'count' => count($presets)]);
