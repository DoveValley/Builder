<?php
// Generate brand assets (logo + favicon) from the business name + current palette.
// Runs in save.php scope ($data, $message, $activeTab). First applies the posted
// palette (reusing the theme handler) so "change colors → Generate" is one click,
// then renders a two-tone wordmark logo + a monogram favicon in those colors.
require __DIR__ . '/theme.php';                               // apply posted palette to $data['theme']
require_once __DIR__ . '/../../includes/multisite/visual.php'; // ms_generate_logo(), ms_convert_run(), ms_convert_bin()

$activeTab = 'theme';
$business  = trim($data['site_vars']['business'] ?? '');

if ($business === '') {
    $message = 'error:Set+a+business+name+first+(Header+tab).';
    return;
}
if (!function_exists('ms_convert_bin') || ms_convert_bin() === null) {
    $message = 'error:ImageMagick+not+available+on+this+server.';
    return;
}

// ── logo: two-tone wordmark (first word = accent, rest = heading color) ──
$logo = ms_generate_logo($data, ACTIVE_SITE_DIR, $business, 'brand', null);

// ── favicon: monogram tile (first initial, white on the header/primary color) ──
$bin  = ms_convert_bin();
$font = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';
$tile = preg_match('/^#[0-9a-fA-F]{6}$/', $data['theme']['header_bg'] ?? '')
        ? $data['theme']['header_bg']
        : (preg_match('/^#[0-9a-fA-F]{6}$/', $data['theme']['heading_color'] ?? '') ? $data['theme']['heading_color'] : '#1e5fa8');
$letter = strtoupper(mb_substr($business, 0, 1));
$slug   = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($business)), '-') ?: 'site';
$favRel = 'uploads/favicon_' . $slug . '.png';
$favAbs = ACTIVE_SITE_DIR . '/' . $favRel;
if (is_file($font) && ms_convert_run([
        $bin, '-size', '128x128', 'xc:none',
        '-fill', $tile, '-draw', 'roundrectangle 0,0,127,127,24,24',
        '-gravity', 'center', '-font', $font, '-pointsize', '78', '-fill', '#ffffff',
        '-annotate', '0', $letter, '-strip', $favAbs], $favAbs)) {
    $data['header']['favicon'] = $favRel;
}

// point the LocalBusiness schema logo at the generated file
if ($logo && isset($data['local_business'])) {
    $data['local_business']['lb_logo'] = '{website}/' . $logo;
}

$message = $logo
    ? 'success:Logo+%26+favicon+generated+from+your+palette.'
    : 'error:Could+not+generate+the+logo.';
