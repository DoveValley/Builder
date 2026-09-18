<?php
/**
 * Live full-page preview for a Color Preset — renders the ACTIVE site's real
 * homepage with a chosen preset's colors substituted into $data['theme'],
 * entirely in memory. Nothing is read from or written to any preset file;
 * this is a render-time overlay only, same "no save, just show" spirit as
 * admin/visual_preview.php (the existing logo/favicon preview).
 *
 * Looks up the REAL saved preset by id and applies it via the real
 * ms_apply_theme_preset() — does not reconstruct any part of its shape by
 * hand. A first cut of this file hand-built an 8-key theme array from raw
 * accent/dark/radius GET params, which meant every field a preset carries
 * that ISN'T one of those 8 (skins.dark, skins.accent, accent2_color, ...)
 * silently fell back to whatever the MASTER's own site.json happened to
 * have — found real, twice, both times because Scott looked at a non-
 * default preset and a button or section came out visibly wrong. Looking
 * the preset up by id and applying it for real is the only way this can't
 * happen a third time for some field not yet discovered to matter.
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
 * GET: i=<0-based position in the library>  name=label (cosmetic, error text
 *      only). Position, not the stored `id` field — admin/tabs/multisite_visual.php's
 *      MSV array (what the button actually reads from) is rebuilt fresh from
 *      theme_presets.json on every page load and indexed by array position,
 *      same as every other action on that card (data-i, "Use for this site").
 */
require_once __DIR__ . '/../config.php';
if (empty($_SESSION['admin_logged_in'])) { http_response_code(403); header('Content-Type: text/plain'); exit('Not authenticated.'); }
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/multisite/visual.php';

$GLOBALS['_ms_preview_no_write'] = true;

$i    = (int) ($_GET['i'] ?? -1);
$name = trim((string) ($_GET['name'] ?? 'Preview'));

$presets = ms_load_theme_presets(ACTIVE_SITE_ID);
$preset  = $presets[$i] ?? null;
if ($preset === null) {
    http_response_code(404);
    header('Content-Type: text/plain');
    exit("Preset #{$i} (\"{$name}\") not found — save the library first (it auto-saves on every edit), then try Preview again.");
}

$data = load_data();
ms_apply_theme_preset($data, $preset);

// The logo/favicon are pre-rendered PNGs (ms_generate_logo()), only ever
// regenerated when a preset is actually APPLIED — so left alone here they'd
// keep showing whatever colors were baked in last time, same stale-field bug
// as skins.dark/accent above, just for an image instead of CSS. Point them at
// the existing safe logo-preview endpoint (admin/visual_preview.php — already
// used by the Preset/Logo Library cards, renders into system temp only, never
// touches this site's real uploads/) instead of regenerating a file here.
$logoDoc      = @json_decode((string)@file_get_contents(ACTIVE_SITE_DIR . '/multisite/logo_configs.json'), true) ?: [];
$logoConfigs  = is_array($logoDoc['logos'] ?? null) ? $logoDoc['logos'] : [];
$singleLogoId = (int)($logoDoc['single_logo_id'] ?? 0);
$logoConfig   = null;
foreach ($logoConfigs as $idx => $l) {
    if ((int)($l['id'] ?? ($idx + 1)) === $singleLogoId) { $logoConfig = $l; break; }
}
$siteVars = [
    'business' => trim((string)($data['site_vars']['business'] ?? '')),
    'city'     => trim((string)($data['site_vars']['city']     ?? '')),
    'state'    => trim((string)($data['site_vars']['state']    ?? '')),
    'SS'       => trim((string)($data['site_vars']['SS']       ?? '')),
];
$logoLines = ms_resolve_logo_lines($logoConfig, $siteVars, ACTIVE_SITE_ID);

// Same accent/dark resolution ms_generate_logo() itself uses (visual.php ~line 357).
$logoAccent = preg_match('/^#[0-9a-fA-F]{6}$/', $data['theme']['accent_color'] ?? '') ? $data['theme']['accent_color'] : '#fd783b';
$logoDark   = '#120575';
foreach (['heading_color', 'footer_bg', 'header_bg'] as $f) {
    if (preg_match('/^#[0-9a-fA-F]{6}$/', $data['theme'][$f] ?? '')) { $logoDark = $data['theme'][$f]; break; }
}
$logoQuery = http_build_query([
    'accent'      => $logoAccent,
    'dark'        => $logoDark,
    'line1'       => $logoLines['line1'],
    'line2'       => $logoLines['line2'],
    'line1_color' => $logoLines['line1Color'],
    'line2_color' => $logoLines['line2Color'],
    'icon_bg'     => $logoLines['iconBg'],
    'icon'        => $logoLines['iconPath'] ? basename($logoLines['iconPath']) : '',
]);
$data['header']['logo']    = 'admin/visual_preview.php?' . $logoQuery;
$data['header']['favicon'] = 'admin/visual_preview.php?' . $logoQuery . '&type=favicon';
if (!empty($data['footer']['logo'])) $data['footer']['logo'] = $data['header']['logo'];

$contentBlocks   = $data['content_blocks'];
$seo             = $data['seo'];
$pageTitle       = 'Preview: ' . ($name !== '' ? $name : 'Untitled preset');
$assetPathPrefix = '/';
$homeUrl         = '/';

require __DIR__ . '/../includes/site-template.php';
