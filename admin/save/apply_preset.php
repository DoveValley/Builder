<?php
// Apply a Visual Identity preset to THIS site (single-site).
// Runs in save.php scope ($data loaded, saved after). Given a preset id from the
// site's own theme_presets.json: merges the preset's colors (+ font/buttons when
// "apply_typography" is on) into $data['theme'], regenerates the logo + favicon in
// those colors, points the LocalBusiness logo at it, and records single_preset_id.
require_once __DIR__ . '/../../includes/multisite/visual.php'; // ms_apply_theme_preset(), ms_generate_logo(), ms_convert_*()

$activeTab = 'theme';

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

// 2. Regenerate logo (+ bug-tile favicon if the preset has an icon) in the preset colors.
$business = trim($data['site_vars']['business'] ?? '');
if ($business === '') { $message = 'error:Set+a+business+name+first+(Header+tab).'; return; }
$iconFile = trim((string)($preset['icon'] ?? ''));
$iconPath = $iconFile !== '' ? ACTIVE_SITE_DIR . '/multisite/icons/' . basename($iconFile) : null;
if ($iconPath && !is_file($iconPath)) $iconPath = null;
$logo = ms_generate_logo($data, ACTIVE_SITE_DIR, $business, 'brand', $iconPath);

// 3. Favicon fallback (preset has no bug icon → monogram tile, like the Brand card).
if ($logo && empty($iconPath)) {
    $bin  = ms_convert_bin();
    $font = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';
    $tile = preg_match('/^#[0-9a-fA-F]{6}$/', $data['theme']['header_bg'] ?? '')
            ? $data['theme']['header_bg']
            : (preg_match('/^#[0-9a-fA-F]{6}$/', $data['theme']['heading_color'] ?? '') ? $data['theme']['heading_color'] : '#1e5fa8');
    $letter = strtoupper(mb_substr($business, 0, 1));
    $slug   = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($business)), '-') ?: 'site';
    $favRel = 'uploads/favicon_' . $slug . '.png';
    if (is_file($font) && ms_convert_run([
            $bin, '-size', '128x128', 'xc:none',
            '-fill', $tile, '-draw', 'roundrectangle 0,0,127,127,24,24',
            '-gravity', 'center', '-font', $font, '-pointsize', '78', '-fill', '#ffffff',
            '-annotate', '0', $letter, '-strip', ACTIVE_SITE_DIR . '/' . $favRel], ACTIVE_SITE_DIR . '/' . $favRel)) {
        $data['header']['favicon'] = $favRel;
    }
}

// 4. Point the LocalBusiness schema logo at the generated file.
if ($logo && isset($data['local_business'])) {
    $data['local_business']['lb_logo'] = '{website}/' . $logo;
}

// 5. Record which preset this site now uses (persist single_preset_id).
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
