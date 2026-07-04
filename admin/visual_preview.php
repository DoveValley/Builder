<?php
/**
 * Live preview for the Multisite → Visual Identity editor. Renders + streams a
 * logo or favicon PNG for a given preset config (accent/dark/icon) + a sample
 * business name. Reuses the real build's ms_generate_logo() via a throwaway temp
 * dir — no changes to the build code. Auth required; writes only to system temp.
 *
 * GET: accent=#hex dark=#hex icon=name.svg name=Business type=logo|favicon
 */
require_once __DIR__ . '/../config.php';
if (empty($_SESSION['admin_logged_in'])) { http_response_code(403); header('Content-Type: text/plain'); exit('Not authenticated.'); }
require_once __DIR__ . '/../includes/multisite/visual.php';

$accent = preg_match('/^#[0-9a-fA-F]{6}$/', $_GET['accent'] ?? '') ? $_GET['accent'] : '#fd783b';
$dark   = preg_match('/^#[0-9a-fA-F]{6}$/', $_GET['dark']   ?? '') ? $_GET['dark']   : '#120575';
$name   = trim((string)($_GET['name'] ?? '')); if ($name === '') $name = 'Acme Pest Control';
$name   = mb_substr($name, 0, 40);
$type   = ($_GET['type'] ?? 'logo') === 'favicon' ? 'favicon' : 'logo';

// Icon restricted to this master's icons/ dir (basename only, must exist).
$icon     = basename((string)($_GET['icon'] ?? ''));
$iconPath = ($icon !== '' && preg_match('/\.svg$/i', $icon)) ? ACTIVE_SITE_DIR . '/multisite/icons/' . $icon : null;
if ($iconPath && !is_file($iconPath)) $iconPath = null;

if (ms_convert_bin() === null) { http_response_code(500); header('Content-Type: text/plain'); exit('ImageMagick not available.'); }

// Throwaway working dir + minimal data → reuse the real generator, then stream.
$wd = sys_get_temp_dir() . '/ms_vp_' . getmypid() . '_' . mt_rand(1000, 9999999);
@mkdir($wd . '/uploads', 0775, true);
$data = ['theme' => ['accent_color' => $accent, 'heading_color' => $dark, 'header_top_bg' => '#ffffff'], 'header' => []];
$logoRel = ms_generate_logo($data, $wd, $name, 'preview', $iconPath);

$rel  = $type === 'favicon' ? ($data['header']['favicon'] ?? '') : ($logoRel ?? '');
$file = $rel !== '' ? $wd . '/' . $rel : '';

if ($file === '' || !is_file($file)) {
    foreach (glob($wd . '/uploads/*') ?: [] as $f) @unlink($f);
    @rmdir($wd . '/uploads'); @rmdir($wd);
    http_response_code(400); header('Content-Type: text/plain'); exit('Could not render preview.');
}

header('Content-Type: image/png');
header('Cache-Control: no-store, max-age=0');
header('Content-Length: ' . filesize($file));
readfile($file);

foreach (glob($wd . '/uploads/*') ?: [] as $f) @unlink($f);
@rmdir($wd . '/uploads'); @rmdir($wd);
