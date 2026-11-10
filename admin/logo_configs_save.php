<?php
/**
 * Save the Logo Library. Writes the active master's logo_configs.json from the
 * posted config list (1–10). Same shape/pattern as visual_presets_save.php —
 * separate file/library on purpose, since a domain's logo arrangement and its
 * color theme are picked independently (see ms_pick_logo_config() /
 * ms_pick_theme_preset() in includes/multisite/visual.php).
 *
 * Line 1/2 text+color apply to the WHOLE library, not per logo (changed
 * 2026-11-10) — see ms_load_logo_configs()'s own docblock for why. Each
 * posted logo entry carries only icon/name/rotation; any line1/2 fields on an
 * individual entry are ignored on purpose, not merely unused, so an old
 * client POSTing the pre-change shape can't accidentally resurrect per-entry
 * drift. Saved as TWO arrangements ("Option 1"/"Option 2", same day) plus
 * which one is active — both stay on file regardless of which is active.
 *
 * POST: csrf_token, logos = JSON [{name, icon, icon_bg, in_rotation}, …],
 *       text = JSON {active: 1|2, options: [{line1_source, line1_custom,
 *       line1_color, line2_source, line2_custom, line2_color}, {…}]},
 *       single_logo_id
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
$validSources = ['business', 'business_word1', 'business_rest', 'city', 'custom'];
$logos = [];
$i = 0;
foreach ($in as $l) {
    $i++;
    if (!is_array($l)) continue;
    $name = trim((string)($l['name'] ?? '')); if ($name === '') $name = 'Logo ' . $i;
    $icon = basename((string)($l['icon'] ?? ''));
    if ($icon !== '' && !is_file($iconDir . $icon)) $icon = '';   // drop a missing icon
    $iconBg = ($l['icon_bg'] ?? '') === 'accent' ? 'accent' : 'dark';

    // Multisite rotation pool membership (default true = in the pool).
    $inRotation = array_key_exists('in_rotation', $l) ? (bool)$l['in_rotation'] : true;

    $logos[] = [
        'id'          => $i,
        'name'        => $name,
        'icon'        => $icon,
        'icon_bg'     => $iconBg,
        'in_rotation' => $inRotation,
    ];
}
if (!$logos) { echo json_encode(['ok' => false, 'error' => 'No valid logo configs.']); exit; }

// Shared line1/2 text+color for the WHOLE library, as two saved options (not
// per entry — see ms_load_logo_configs()'s docblock). Same validation the
// per-entry fields used to get, just run once per option.
function ll_save_normalize_option($o, array $validSources): array {
    $o = is_array($o) ? $o : [];
    return [
        'line1_source' => in_array($o['line1_source'] ?? '', $validSources, true) ? $o['line1_source'] : 'business',
        'line1_custom' => mb_substr(trim((string)($o['line1_custom'] ?? '')), 0, 60),
        'line1_color'  => ($o['line1_color'] ?? '') === 'dark'   ? 'dark'   : 'accent',
        'line2_source' => in_array($o['line2_source'] ?? '', $validSources, true) ? $o['line2_source'] : 'city',
        'line2_custom' => mb_substr(trim((string)($o['line2_custom'] ?? '')), 0, 60),
        'line2_color'  => ($o['line2_color'] ?? '') === 'accent' ? 'accent' : 'dark',
    ];
}
$t = json_decode($_POST['text'] ?? '', true);
if (!is_array($t)) $t = [];
$tOptions = is_array($t['options'] ?? null) ? array_values($t['options']) : [];
$text = [
    'active'  => in_array((int)($t['active'] ?? 1), [1, 2], true) ? (int)($t['active'] ?? 1) : 1,
    'options' => [
        ll_save_normalize_option($tOptions[0] ?? [], $validSources),
        ll_save_normalize_option($tOptions[1] ?? [], $validSources),
    ],
];

$file     = ACTIVE_SITE_DIR . '/multisite/logo_configs.json';
$existing = @json_decode((string)@file_get_contents($file), true) ?: [];

// Single-site brand selection: which config id this site itself uses (0/blank = none).
$singleId = isset($_POST['single_logo_id']) && ctype_digit((string)$_POST['single_logo_id'])
    ? (int)$_POST['single_logo_id'] : (int)($existing['single_logo_id'] ?? 0);
if ($singleId > count($logos)) $singleId = 0;   // stale id (config removed) → clear

$doc = [
    '_about' => $existing['_about'] ?? 'Per-site Logo Library. Each config = icon only (text/name/rotation) — independent of the Visual Identity color presets. `text` = two saved line1/2 source+color arrangements ("Option 1"/"Option 2") shared by every config in this library, plus `active` (1 or 2) picking which one applies. `single_logo_id` = the config applied to THIS site; `in_rotation` per config = whether the multisite build rotates through it when generating clones.',
    'single_logo_id' => $singleId,
    'text' => $text,
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
