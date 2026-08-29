<?php
// Apply a Visual Identity preset to THIS site (single-site).
// Runs in save.php scope ($data loaded, saved after). Given a preset id from the
// site's own theme_presets.json: merges the preset's colors (+ font/buttons when
// "apply_typography" is on) into $data['theme'], regenerates the logo + favicon in
// the NEW colors (text/icon from this site's own Logo Config, unaffected — see
// admin/tabs/logo_library.php), points the LocalBusiness logo at it, and
// records single_preset_id.
require_once __DIR__ . '/../../includes/multisite/visual.php'; // ms_apply_theme_preset(), ms_generate_logo(), ms_convert_*()

$activeTab = 'genvisual';

$presetsFile = ACTIVE_SITE_DIR . '/multisite/theme_presets.json';
$doc = @json_decode((string)@file_get_contents($presetsFile), true) ?: [];
$presets = is_array($doc['presets'] ?? null) ? $doc['presets'] : [];

$presetId = (int)($_POST['preset_id'] ?? 0);
$applyType = !empty($_POST['apply_typography']);

// Find the preset by its stored id (1-based), else by ordinal fallback.
$preset = null;
foreach ($presets as $idx => $p) {
    if ((int)($p['id'] ?? ($idx + 1)) === $presetId) { $preset = $p; break; }
}
if ($preset === null) { $message = 'error:Preset+not+found+—+save+the+library+first.'; return; }

if (!function_exists('ms_convert_bin') || ms_convert_bin() === null) {
    $message = 'error:ImageMagick+not+available+on+this+server.';
    return;
}

// Colors-only vs. full identity: drop typography keys when the toggle is off.
if (!$applyType) {
    foreach (['primary_font', 'heading_font', 'button_radius'] as $k) unset($preset['theme'][$k]);
}

// 1. Merge the preset's theme + header fragments into the site.
ms_apply_theme_preset($data, $preset);

// 2. Regenerate the logo in the preset's new colors — text/icon come from this
// site's own Logo Config (single_logo_id in logo_configs.json), a fully
// independent choice from which theme preset was just applied. A theme
// preset's own `icon` field no longer drives the real logo (kept only for
// that preset's own mini-preview in the library above).
$siteVars = [
    'business' => trim((string)($data['site_vars']['business'] ?? '')),
    'city'     => trim((string)($data['site_vars']['city']     ?? '')),
    'state'    => trim((string)($data['site_vars']['state']    ?? '')),
    'SS'       => trim((string)($data['site_vars']['SS']       ?? '')),
];
if ($siteVars['business'] === '') { $message = 'error:Set+a+business+name+first+(Header+tab).'; return; }

$logoDoc     = @json_decode((string)@file_get_contents(ACTIVE_SITE_DIR . '/multisite/logo_configs.json'), true) ?: [];
$logoConfigs = is_array($logoDoc['logos'] ?? null) ? $logoDoc['logos'] : [];
$singleLogoId = (int)($logoDoc['single_logo_id'] ?? 0);
$logoConfig = null;
foreach ($logoConfigs as $idx => $l) {
    if ((int)($l['id'] ?? ($idx + 1)) === $singleLogoId) { $logoConfig = $l; break; }
}
$lines = ms_resolve_logo_lines($logoConfig, $siteVars, ACTIVE_SITE_ID);
$logo  = ms_generate_logo($data, ACTIVE_SITE_DIR, $lines['line1'], $lines['line2'], 'brand', $lines['iconPath'], $lines['line1Color'], $lines['line2Color'], $lines['iconBg']);

// 3. Point the LocalBusiness schema logo at the generated file.
if ($logo && isset($data['local_business'])) {
    $data['local_business']['lb_logo'] = '{website}/' . $logo;
}

// 4. Record which preset this site now uses (persist single_preset_id).
$doc['single_preset_id'] = $presetId;
$tmp = $presetsFile . '.tmp.' . getmypid();
if (@file_put_contents($tmp, json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) !== false) {
    @rename($tmp, $presetsFile);
} else {
    @unlink($tmp);
}

$name = (string)($preset['name'] ?? ('#' . $presetId));
$message = $logo
    ? 'success:Applied+preset+"' . rawurlencode($name) . '"+' . ($applyType ? '(colors,+font+%26+buttons)' : '(colors+only)') . '+—+logo+%26+favicon+regenerated.'
    : 'error:Preset+applied+but+the+logo+could+not+be+generated.';
