<?php
/**
 * Save the Logo Library. Writes the active master's logo_configs.json from the
 * posted config list (1–10). Same shape/pattern as visual_presets_save.php —
 * separate file/library on purpose, since a domain's logo arrangement and its
 * color theme are picked independently (see ms_pick_logo_config() /
 * ms_pick_theme_preset() in includes/multisite/visual.php).
 *
 * POST: csrf_token, logos = JSON [{name, icon, line1_source, line1_custom,
 *       line2_source, line2_custom, in_rotation}, …], single_logo_id
 */
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json');
if (empty($_SESSION['admin_logged_in']))                                      { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'Not authenticated.']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST')                                    { echo json_encode(['ok' => false, 'error' => 'POST only.']); exit; }
if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? ''))  { echo json_encode(['ok' => false, 'error' => 'Bad CSRF token.']); exit; }

$in = json_decode($_POST['logos'] ?? '', true);
if (!is_array($in) || count($in) < 1)  { echo json_encode(['ok' => false, 'error' => 'No logo configs supplied.']); exit; }
if (count($in) > 10)                   { echo json_encode(['ok' => false, 'error' => 'Maximum 10 logo configs.']); exit; }

$iconDir = ACTIVE_SITE_DIR . '/multisite/icons/';
$validSources = ['business', 'city', 'custom'];
$logos = [];
$i = 0;
foreach ($in as $l) {
    $i++;
    if (!is_array($l)) continue;
    $name = trim((string)($l['name'] ?? '')); if ($name === '') $name = 'Logo ' . $i;
    $icon = basename((string)($l['icon'] ?? ''));
    if ($icon !== '' && !is_file($iconDir . $icon)) $icon = '';   // drop a missing icon

    $line1Source = in_array($l['line1_source'] ?? '', $validSources, true) ? $l['line1_source'] : 'business';
    $line2Source = in_array($l['line2_source'] ?? '', $validSources, true) ? $l['line2_source'] : 'city';
    $line1Custom = mb_substr(trim((string)($l['line1_custom'] ?? '')), 0, 60);
    $line2Custom = mb_substr(trim((string)($l['line2_custom'] ?? '')), 0, 60);

    // Multisite rotation pool membership (default true = in the pool).
    $inRotation = array_key_exists('in_rotation', $l) ? (bool)$l['in_rotation'] : true;

    $logos[] = [
        'id'           => $i,
        'name'         => $name,
        'icon'         => $icon,
        'line1_source' => $line1Source,
        'line1_custom' => $line1Custom,
        'line2_source' => $line2Source,
        'line2_custom' => $line2Custom,
        'in_rotation'  => $inRotation,
    ];
}
if (!$logos) { echo json_encode(['ok' => false, 'error' => 'No valid logo configs.']); exit; }

$file     = ACTIVE_SITE_DIR . '/multisite/logo_configs.json';
$existing = @json_decode((string)@file_get_contents($file), true) ?: [];

// Single-site brand selection: which config id this site itself uses (0/blank = none).
$singleId = isset($_POST['single_logo_id']) && ctype_digit((string)$_POST['single_logo_id'])
    ? (int)$_POST['single_logo_id'] : (int)($existing['single_logo_id'] ?? 0);
if ($singleId > count($logos)) $singleId = 0;   // stale id (config removed) → clear

$doc = [
    '_about' => $existing['_about'] ?? 'Per-site Logo Library. Each config = icon + line1/line2 text source (business name, city, or custom text) — independent of the Visual Identity color presets. `single_logo_id` = the config applied to THIS site; `in_rotation` per config = whether the multisite build rotates through it when generating clones.',
    'single_logo_id' => $singleId,
    'logos' => $logos,
];

$dir = dirname($file);
if (!is_dir($dir)) @mkdir($dir, 0775, true);
$tmp = $file . '.tmp.' . getmypid();
if (@file_put_contents($tmp, json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) === false || !@rename($tmp, $file)) {
    @unlink($tmp);
    echo json_encode(['ok' => false, 'error' => 'Could not write logo_configs.json (check file permissions).']);
    exit;
}
echo json_encode(['ok' => true, 'count' => count($logos)]);
