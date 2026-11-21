<?php
/**
 * admin/pluscities_plugin_download.php — zips wp-plugin/pluscities-delivery/
 * fresh on every request, same "never a stale pre-built artifact" pattern as
 * the old engine's admin/lpc_plugin_download.php, but with one real
 * difference: spec Section 14 requires the plugin's own name/description
 * (what the CLIENT sees in their own WordPress admin) to carry the agency's
 * branding, not "PlusCities" — and WordPress reads Plugin Name/Description
 * as a static header comment, so true per-agency branding means generating
 * a text-substituted copy at download time, not a config option read at
 * runtime (the plugin can't template its own header).
 *
 * An Agency Admin/Editor downloads their own agency's branded copy; Platform
 * Owner can download for any agency via ?agency_id=.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

if (!lpc_actor_is_authenticated()) {
    http_response_code(403);
    echo 'Not authenticated.';
    exit;
}

$role = lpc_current_actor_role();
$agencyId = $role === 'super_admin'
    ? trim((string) ($_GET['agency_id'] ?? ''))
    : lpc_current_actor_agency_id();

$agency = $agencyId !== '' ? lpc_load_agency($agencyId) : null;
if (!$agency) {
    http_response_code(404);
    echo 'Agency not found.';
    exit;
}

// Falls back to "PlusCities" only if an agency genuinely hasn't set their own
// branding yet — not a silent default masquerading as white-label.
$displayName = trim((string) ($agency['branding']['display_name'] ?? '')) ?: 'PlusCities';

$pluginDir = BASE_DIR . '/wp-plugin/pluscities-delivery';
if (!is_dir($pluginDir)) {
    http_response_code(404);
    echo 'Plugin source not found.';
    exit;
}
if (!class_exists('ZipArchive')) {
    http_response_code(500);
    echo 'ZipArchive extension not available on this server.';
    exit;
}

$slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($displayName)) . '-delivery';
$zipName = $slug . '.zip';
$tmpZip  = sys_get_temp_dir() . '/' . $zipName . '.' . uniqid() . '.zip';

$zip = new ZipArchive();
$zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE);

foreach (new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($pluginDir, FilesystemIterator::SKIP_DOTS)
) as $file) {
    if (!$file->isFile()) continue;
    $rel = $slug . '/' . substr($file->getPathname(), strlen($pluginDir) + 1);

    if ($file->getExtension() === 'php') {
        // Text substitution, not a runtime option — see docblock. Every
        // literal "PlusCities" occurrence in plugin source becomes the
        // agency's own name, including the WP header WordPress parses
        // statically before any of the plugin's own code ever runs.
        $contents = str_replace('PlusCities', $displayName, (string) file_get_contents($file->getPathname()));
        $zip->addFromString($rel, $contents);
    } else {
        $zip->addFile($file->getPathname(), $rel);
    }
}

$zip->close();

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $zipName . '"');
header('Content-Length: ' . filesize($tmpZip));
header('Pragma: no-cache');
readfile($tmpZip);
unlink($tmpZip);
