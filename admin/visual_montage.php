<?php
/**
 * Export the site's Visual Identity presets as ONE image — each preset's generated
 * logo + favicon (in its colors, with its brand icon), labeled, tiled 2-up. Reuses
 * the real ms_generate_logo() via throwaway temp dirs, then `montage`s the cells.
 * Auth required; read-only (no writes to the site). Streams image/png.
 */
require_once __DIR__ . '/../config.php';
if (empty($_SESSION['admin_logged_in'])) { http_response_code(403); header('Content-Type: text/plain'); exit('Not authenticated.'); }
if (!ACTIVE_SITE_ID)                      { http_response_code(400); header('Content-Type: text/plain'); exit('No active site.'); }
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/multisite/visual.php';   // ms_generate_logo(), ms_convert_run(), ms_convert_bin()

if (ms_convert_bin() === null) { http_response_code(500); header('Content-Type: text/plain'); exit('ImageMagick not available.'); }

$doc     = @json_decode((string)@file_get_contents(ACTIVE_SITE_DIR . '/multisite/theme_presets.json'), true) ?: [];
$presets = is_array($doc['presets'] ?? null) ? $doc['presets'] : [];
if (!$presets) { http_response_code(400); header('Content-Type: text/plain'); exit('No presets to export.'); }

$data0 = @json_decode((string)@file_get_contents(DATA_FILE), true);
$name  = trim($data0['site_vars']['business'] ?? '') ?: 'Your Business';

$bin  = ms_convert_bin();
$mont = is_file('/usr/bin/montage') ? '/usr/bin/montage' : trim((string)@shell_exec('command -v montage'));
if ($mont === '') { http_response_code(500); header('Content-Type: text/plain'); exit('montage not available.'); }
$font = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';

$base = sys_get_temp_dir() . '/vm_' . getmypid() . '_' . mt_rand(1000, 9999999);
@mkdir($base, 0775, true);

$cells = [];
foreach ($presets as $idx => $p) {
    $t      = $p['theme'] ?? [];
    $accent = $t['accent_color'] ?? '#333333';
    $dark   = $t['heading_color'] ?? ($t['header_bg'] ?? '#111111');
    $iconF  = trim((string)($p['icon'] ?? ''));
    $iconPath = $iconF !== '' ? ACTIVE_SITE_DIR . '/multisite/icons/' . basename($iconF) : null;
    if ($iconPath && !is_file($iconPath)) $iconPath = null;

    $wd = $base . '/p' . $idx; @mkdir($wd . '/uploads', 0775, true);
    $d  = ['theme' => ['accent_color' => $accent, 'heading_color' => $dark, 'header_top_bg' => '#ffffff'], 'header' => []];
    $logoRel = ms_generate_logo($d, $wd, $name, 'montage' . $idx, $iconPath);
    if (!$logoRel || !is_file($wd . '/' . $logoRel)) continue;
    $logo = $wd . '/' . $logoRel;

    // Favicon: generator's bug tile (icon present) else a monogram tile.
    $fav = ($d['header']['favicon'] ?? '') !== '' ? $wd . '/' . $d['header']['favicon'] : '';
    if ($fav === '' || !is_file($fav)) {
        $fav = $wd . '/fav.png';
        $letter = strtoupper(mb_substr($name, 0, 1));
        ms_convert_run([$bin, '-size', '96x96', 'xc:none', '-fill', $dark,
            '-draw', 'roundrectangle 0,0,95,95,18,18', '-gravity', 'center', '-font', $font,
            '-pointsize', '58', '-fill', '#ffffff', '-annotate', '0', $letter, '-strip', $fav], $fav);
    } else {
        $s = $fav . '.96.png';
        if (ms_convert_run([$bin, $fav, '-resize', '96x96', $s], $s)) $fav = $s;
    }
    if (!is_file($fav)) continue;

    // Cell = favicon | logo on white, labeled by preset name (filename → montage %t).
    $lbl  = sprintf('%02d', $idx) . '_' . (preg_replace('/[^A-Za-z0-9]+/', '_', (string)($p['name'] ?? 'Preset')) ?: 'Preset');
    $cell = $base . '/' . $lbl . '.png';
    if (ms_convert_run([$bin, $fav, '-bordercolor', 'white', '-border', '12',
                        $logo, '-background', 'white', '-gravity', 'center', '+append',
                        '-bordercolor', 'white', '-border', '10', $cell], $cell)) {
        $cells[] = $cell;
    }
}

if (!$cells) {
    _vm_rrm($base);
    http_response_code(500); header('Content-Type: text/plain'); exit('Could not render any preset.');
}
sort($cells);
$out = $base . '/montage.png';
ms_convert_run(array_merge([$mont, '-label', '%t'], $cells,
    ['-tile', '2x', '-geometry', '+14+12', '-background', '#eef2f7', '-pointsize', '13', $out]), $out);

if (!is_file($out)) { _vm_rrm($base); http_response_code(500); header('Content-Type: text/plain'); exit('Montage failed.'); }

header('Content-Type: image/png');
header('Content-Disposition: inline; filename="visual-identity-presets.png"');
header('Cache-Control: no-store');
header('Content-Length: ' . filesize($out));
readfile($out);
_vm_rrm($base);

function _vm_rrm(string $d): void {
    foreach (glob($d . '/*') ?: [] as $f) { is_dir($f) ? _vm_rrm($f) : @unlink($f); }
    @rmdir($d);
}
