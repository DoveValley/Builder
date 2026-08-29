<?php
/**
 * Export this site's Brand Icons library as a .zip of the ORIGINAL uploaded SVG
 * files — no colorizing, no PNG rendering (that's what visual_montage.php's
 * "Export logos + favicons" already covers). This is for getting the true vector
 * source back out, e.g. to hand to another tool or back it up.
 * Auth required; read-only (no writes to the site). Streams application/zip.
 */
require_once __DIR__ . '/../config.php';
if (empty($_SESSION['admin_logged_in'])) { http_response_code(403); header('Content-Type: text/plain'); exit('Not authenticated.'); }
if (!ACTIVE_SITE_ID)                      { http_response_code(400); header('Content-Type: text/plain'); exit('No active site.'); }

$iconDir = ACTIVE_SITE_DIR . '/multisite/icons/';
$icons   = glob($iconDir . '*.svg') ?: [];
if (!$icons) { http_response_code(400); header('Content-Type: text/plain'); exit('No icons to export.'); }

$tmp = sys_get_temp_dir() . '/icons_export_' . getmypid() . '_' . mt_rand(1000, 9999999) . '.zip';
try {
    $zip = new PharData($tmp, 0, null, Phar::ZIP);
    foreach ($icons as $path) {
        $zip->addFile($path, basename($path));
    }
} catch (\Throwable $e) {
    @unlink($tmp);
    http_response_code(500); header('Content-Type: text/plain'); exit('Could not build the zip.');
}
unset($zip);

if (!is_file($tmp)) { http_response_code(500); header('Content-Type: text/plain'); exit('Zip build failed.'); }

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . ACTIVE_SITE_ID . '-brand-icons.zip"');
header('Cache-Control: no-store');
header('Content-Length: ' . filesize($tmp));
readfile($tmp);
@unlink($tmp);
