<?php
/**
 * Live full-page preview for a Color Preset — renders the ACTIVE site's real
 * homepage with a given accent/dark/radius substituted into $data['theme'],
 * entirely in memory. Nothing is read from or written to any preset file;
 * this is a render-time overlay only, same "no save, just show" spirit as
 * admin/visual_preview.php (the existing logo/favicon preview).
 *
 * GET: accent=#hex  dark=#hex  radius=0-50  name=label (cosmetic, page title only)
 */
require_once __DIR__ . '/../config.php';
if (empty($_SESSION['admin_logged_in'])) { http_response_code(403); header('Content-Type: text/plain'); exit('Not authenticated.'); }
require_once __DIR__ . '/../includes/functions.php';

$accent = preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($_GET['accent'] ?? '')) ? $_GET['accent'] : '#fd783b';
$dark   = preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($_GET['dark']   ?? '')) ? $_GET['dark']   : '#1e293b';
$radius = isset($_GET['radius']) && ctype_digit((string) $_GET['radius']) ? (int) $_GET['radius'] : 8;
$name   = trim((string) ($_GET['name'] ?? 'Preview'));

$data = load_data();

// Same keys a stored preset carries (includes/multisite/visual.php's
// ms_apply_theme_preset() / theme_presets.json) — header/footer/heading all
// share the one "dark" value, text stays white, matching every real preset.
$data['theme']['accent_color']  = $accent;
$data['theme']['header_bg']     = $dark;
$data['theme']['footer_bg']     = $dark;
$data['theme']['heading_color'] = $dark;
$data['theme']['header_text']   = '#ffffff';
$data['theme']['footer_text']   = '#ffffff';
$data['theme']['header_top_bg'] = '#ffffff';
$data['theme']['button_radius'] = $radius;

$contentBlocks   = $data['content_blocks'];
$seo             = $data['seo'];
$pageTitle       = 'Preview: ' . ($name !== '' ? $name : 'Untitled preset');
$assetPathPrefix = '/';
$homeUrl         = '/';

require __DIR__ . '/../includes/site-template.php';
