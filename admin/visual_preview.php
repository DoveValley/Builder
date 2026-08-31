<?php
/**
 * Live preview for the Visual Identity preset library AND the Logo Library.
 * Renders + streams a logo or favicon PNG for a given accent/dark/icon +
 * either explicit line1/line2 text (Logo Library) or a sample business name,
 * split the old auto way, for backward compat (preset-library cards, which
 * have no line1/line2 concept of their own). Reuses the real build's
 * ms_generate_logo() via a throwaway temp dir — no changes to the build code.
 * Auth required; writes only to system temp.
 *
 * GET: accent=#hex dark=#hex icon=name.svg type=logo|favicon
 *      + either line1=...&line2=... (explicit) or name=Business (legacy split)
 *      + optional line1_color=accent|dark, line2_color=accent|dark, icon_bg=dark|accent (Logo Library only)
 *      + optional fill_pct/bg_style/corner_pct/trace_pct (Brand Icons live-editing only —
 *        when absent, the icon's own SAVED style is used, so every other preview on the
 *        page reflects reality instead of a hardcoded default)
 */
require_once __DIR__ . '/../config.php';
if (empty($_SESSION['admin_logged_in'])) { http_response_code(403); header('Content-Type: text/plain'); exit('Not authenticated.'); }
require_once __DIR__ . '/../includes/multisite/visual.php';

$accent = preg_match('/^#[0-9a-fA-F]{6}$/', $_GET['accent'] ?? '') ? $_GET['accent'] : '#fd783b';
$dark   = preg_match('/^#[0-9a-fA-F]{6}$/', $_GET['dark']   ?? '') ? $_GET['dark']   : '#120575';
$type   = ($_GET['type'] ?? 'logo') === 'favicon' ? 'favicon' : 'logo';

if (isset($_GET['line1']) || isset($_GET['line2'])) {
    // Logo Library: explicit lines, no derivation.
    $line1 = mb_substr(trim((string)($_GET['line1'] ?? '')), 0, 40);
    $line2 = mb_substr(trim((string)($_GET['line2'] ?? '')), 0, 40);
} else {
    // Preset-library cards: same "first word / rest" split ms_generate_logo()
    // used to do internally, kept here ONLY as this endpoint's legacy fallback
    // — the real build never derives lines from a name anymore.
    $name = trim((string)($_GET['name'] ?? '')); if ($name === '') $name = 'Acme Pest Control';
    $words = preg_split('/\s+/', mb_substr($name, 0, 40));
    $line1 = $words[0];
    $line2 = count($words) > 1 ? implode(' ', array_slice($words, 1)) : '';
}

$line1Color = ($_GET['line1_color'] ?? '') === 'dark'   ? 'dark'   : 'accent';
$line2Color = ($_GET['line2_color'] ?? '') === 'accent' ? 'accent' : 'dark';
$iconBg     = ($_GET['icon_bg']    ?? '') === 'accent' ? 'accent' : 'dark';

// Icon restricted to this master's icons/ dir (basename only, must exist).
$icon     = basename((string)($_GET['icon'] ?? ''));
$iconPath = ($icon !== '' && preg_match('/\.svg$/i', $icon)) ? ACTIVE_SITE_DIR . '/multisite/icons/' . $icon : null;
if ($iconPath && !is_file($iconPath)) $iconPath = null;

// Icon render style: start from the icon's own SAVED style (so a preview on
// ANY card — Presets, Logo Library — matches what the real build would do),
// then let explicit overrides win (the Brand Icons card's own live-editing
// controls, previewing a change before it's auto-saved).
$iconStyle = $icon !== '' ? ms_resolve_icon_style(ms_load_icon_styles(ACTIVE_SITE_ID), $icon) : ms_default_icon_style();
if (isset($_GET['fill_pct']))   $iconStyle['fill_pct']   = $_GET['fill_pct'];
if (isset($_GET['bg_style']))   $iconStyle['bg_style']   = $_GET['bg_style'];
if (isset($_GET['corner_pct'])) $iconStyle['corner_pct'] = $_GET['corner_pct'];
if (isset($_GET['trace_pct']))  $iconStyle['trace_pct']  = $_GET['trace_pct'];
$iconStyle = ms_sanitize_icon_style($iconStyle);

if (ms_convert_bin() === null) { http_response_code(500); header('Content-Type: text/plain'); exit('ImageMagick not available.'); }

// Throwaway working dir + minimal data → reuse the real generator, then stream.
$wd = sys_get_temp_dir() . '/ms_vp_' . getmypid() . '_' . mt_rand(1000, 9999999);
@mkdir($wd . '/uploads', 0775, true);
$data = ['theme' => ['accent_color' => $accent, 'heading_color' => $dark, 'header_top_bg' => '#ffffff'], 'header' => []];
$logoRel = ms_generate_logo($data, $wd, $line1, $line2, 'preview', $iconPath, $line1Color, $line2Color, $iconBg, $iconStyle);

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
