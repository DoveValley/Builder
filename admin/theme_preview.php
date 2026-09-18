<?php
/**
 * Live full-page preview for a Color Preset — renders the ACTIVE site's real
 * homepage with a given accent/dark/radius substituted into $data['theme'],
 * entirely in memory. Nothing is read from or written to any preset file;
 * this is a render-time overlay only, same "no save, just show" spirit as
 * admin/visual_preview.php (the existing logo/favicon preview).
 *
 * Builds the SAME shape a stored preset in theme_presets.json carries and
 * applies it via the real ms_apply_theme_preset() — not a hand-picked subset
 * of keys — so this preview and an actual multisite build never drift apart.
 * A first cut of this file only set 8 base keys and missed `skins.dark.bg` /
 * `accent2_color`, which stayed frozen at the master's own values regardless
 * of preset — real bug, found because Scott looked at a non-default preset
 * here for the first time (see includes/multisite/visual.php's
 * ms_apply_theme_preset() docblock for why `skins` is a preset-carried key).
 *
 * DANGER FOUND + FIXED: this points ACTIVE_SITE_DIR at the real master on
 * purpose (it needs the master's real content), but rendering ALSO fires the
 * image-data-chart / image-area-map plugins' "regenerate if the theme doesn't
 * match the cached drawing" logic — previewing a color that differs from
 * whatever's actually saved silently overwrote the master's real chart/map
 * images with the PREVIEW's colors, live, no confirmation. Caught this for
 * real (site.json + a dozen chart files came back modified after three test
 * renders) before it shipped. `_ms_preview_no_write` tells both plugins
 * (plugins/image-data-chart/render.php, plugins/image-area-map/render.php) to
 * reuse whatever's already on disk instead of writing — a chart may show the
 * saved theme's colors rather than the previewed one, which is a fair trade
 * for never corrupting live assets from a "just looking" click.
 *
 * GET: accent=#hex  dark=#hex  radius=0-50  name=label (cosmetic, page title only)
 */
require_once __DIR__ . '/../config.php';
if (empty($_SESSION['admin_logged_in'])) { http_response_code(403); header('Content-Type: text/plain'); exit('Not authenticated.'); }
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/multisite/visual.php';

$GLOBALS['_ms_preview_no_write'] = true;

$accent = preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($_GET['accent'] ?? '')) ? $_GET['accent'] : '#fd783b';
$dark   = preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($_GET['dark']   ?? '')) ? $_GET['dark']   : '#1e293b';
$radius = isset($_GET['radius']) && ctype_digit((string) $_GET['radius']) ? (int) $_GET['radius'] : 8;
$name   = trim((string) ($_GET['name'] ?? 'Preview'));

$data = load_data();

// Same shape as a real theme_presets.json entry — see ms_apply_theme_preset().
$preset = ['theme' => [
    'accent_color'   => $accent,
    'header_bg'      => $dark,
    'footer_bg'      => $dark,
    'heading_color'  => $dark,
    'header_text'    => '#ffffff',
    'footer_text'    => '#ffffff',
    'header_top_bg'  => '#ffffff',
    'button_radius'  => $radius,
    'accent2_color'  => $accent,
    'skins'          => ['dark' => ['bg' => $dark, 'heading' => '#ffffff', 'text' => '#e2e8f0']],
]];
ms_apply_theme_preset($data, $preset);

$contentBlocks   = $data['content_blocks'];
$seo             = $data['seo'];
$pageTitle       = 'Preview: ' . ($name !== '' ? $name : 'Untitled preset');
$assetPathPrefix = '/';
$homeUrl         = '/';

require __DIR__ . '/../includes/site-template.php';
