<?php
/**
 * Public preview dispatcher for *.preview.q111.xyz.
 *
 * Deliberately NOT auth-gated, unlike admin/batch_preview.php — the whole point is that
 * Google's crawlers (PageSpeed Insights, Rich Results Test, Mobile-Friendly Test) and
 * anyone else can fetch it exactly like a real deployed site. It only ever serves
 * already-generated static output; it never writes anything.
 *
 * Hostname shape: {master}--{batch}--{domain-slug}.preview.q111.xyz
 * The three pieces map straight to sites/{master}/batches/{batch}/output/{domain-slug}/,
 * so unlike batch_preview.php there is no need to rewrite root-absolute paths — this
 * output folder genuinely IS the site root for this hostname, the same as it would be
 * once actually deployed.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/multisite/batch.php';

$host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
$host = preg_replace('/:\d+$/', '', $host); // strip a port, if a request carries one

if (!preg_match('/^([a-z0-9][a-z0-9-]*?)--(b[0-9]{1,6})--([a-z0-9_]+)\.preview\.q111\.xyz$/', $host, $m)) {
    http_response_code(404); header('Content-Type: text/plain'); exit('Unknown preview host.');
}
[, $masterId, $batchId, $domainSlug] = $m;

if (!ms_valid_master_id($masterId) || !ms_valid_batch_id($batchId)) {
    http_response_code(404); header('Content-Type: text/plain'); exit('Unknown preview host.');
}

$root = realpath(ms_batch_dir($masterId, $batchId) . '/output/' . $domainSlug);
if ($root === false || !is_dir($root)) {
    http_response_code(404); header('Content-Type: text/plain');
    exit('Nothing generated yet for this preview — run "4. Generate sites" first.');
}

$rel = ltrim((string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/'), '/');
if ($rel === '') $rel = 'index.html';

$full = realpath($root . '/' . $rel);
if ($full === false || strncmp($full, $root . DIRECTORY_SEPARATOR, strlen($root) + 1) !== 0) {
    http_response_code(404); header('Content-Type: text/plain'); exit('Not found.');
}
if (is_dir($full)) {
    $withIndex = realpath($full . '/index.html');
    if ($withIndex === false) { http_response_code(404); header('Content-Type: text/plain'); exit('Not found.'); }
    $full = $withIndex;
}

$ext  = strtolower(pathinfo($full, PATHINFO_EXTENSION));
$mime = [
    'html' => 'text/html; charset=utf-8', 'css' => 'text/css', 'js' => 'application/javascript',
    'json' => 'application/json', 'xml' => 'application/xml', 'txt' => 'text/plain',
    'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif',
    'webp' => 'image/webp', 'svg' => 'image/svg+xml', 'ico' => 'image/x-icon',
    'woff' => 'font/woff', 'woff2' => 'font/woff2', 'ttf' => 'font/ttf',
][$ext] ?? 'application/octet-stream';

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($full));
readfile($full);
