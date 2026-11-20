<?php
// Zips wp-plugin/landing-page-client/ fresh on every request and streams it —
// same pattern as download_static.php. Built fresh (not a pre-built file
// sitting in uploads/) so this can never go stale against the real plugin
// source as it evolves. One universal plugin for every client, per the
// design (project_landing_page_clients_feature_design) — nothing client-
// specific baked into the zip.

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

if (!lpc_actor_is_authenticated()) {
    http_response_code(403);
    echo 'Not authenticated.';
    exit;
}

$pluginDir = BASE_DIR . '/wp-plugin/landing-page-client';
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

$zipName = 'landing-page-client.zip';
$tmpZip  = sys_get_temp_dir() . '/' . $zipName . '.' . uniqid() . '.zip';

$zip = new ZipArchive();
$zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE);

foreach (new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($pluginDir, FilesystemIterator::SKIP_DOTS)
) as $file) {
    if ($file->isFile()) {
        $rel = 'landing-page-client/' . substr($file->getPathname(), strlen($pluginDir) + 1);
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
