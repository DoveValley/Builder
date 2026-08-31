<?php
// Zips the generated output/{ACTIVE_SITE_ID}/ directory (from Deploy → Generate
// Static Site) and streams it as a download. Read-only — never touches site data.

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

if (empty($_SESSION['admin_logged_in'])) {
    http_response_code(403);
    echo 'Not authenticated.';
    exit;
}
if (!ACTIVE_SITE_ID) {
    http_response_code(400);
    echo 'No active site selected.';
    exit;
}

$outputDir = BASE_DIR . '/output/' . ACTIVE_SITE_ID . '/';
if (!is_dir($outputDir)) {
    http_response_code(404);
    echo 'No static build found — click "Generate Static Site" first.';
    exit;
}
if (!class_exists('ZipArchive')) {
    http_response_code(500);
    echo 'ZipArchive extension not available on this server.';
    exit;
}

$zipName = ACTIVE_SITE_ID . '-static.zip';
$tmpZip  = sys_get_temp_dir() . '/' . $zipName . '.' . uniqid() . '.zip';

$zip = new ZipArchive();
$zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE);

foreach (new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($outputDir, FilesystemIterator::SKIP_DOTS)
) as $file) {
    if ($file->isFile()) {
        $rel = substr($file->getPathname(), strlen($outputDir));
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
