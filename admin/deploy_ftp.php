<?php
// FTP deploy — SSE endpoint.
// Reads output/{site_id}/, compares against deploy_manifest.json,
// uploads only new or changed files, then updates the manifest.

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

// ── Auth ──────────────────────────────────────────────────────────────────────
if (empty($_SESSION['admin_logged_in'])) {
    http_response_code(403);
    echo "data: " . json_encode(['type' => 'fatal', 'msg' => 'Not authenticated.']) . "\n\n";
    exit;
}
if (!ACTIVE_SITE_ID) {
    http_response_code(400);
    echo "data: " . json_encode(['type' => 'fatal', 'msg' => 'No active site selected.']) . "\n\n";
    exit;
}

session_write_close();

// ── SSE headers ───────────────────────────────────────────────────────────────
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');

set_time_limit(0);

function ftp_sse(string $msg, string $type = 'log'): void {
    echo 'data: ' . json_encode(['type' => $type, 'msg' => $msg]) . "\n\n";
    @ob_flush();
    flush();
}

// ── Load config ───────────────────────────────────────────────────────────────
$deployFile = ACTIVE_SITE_DIR . '/deploy.json';
if (!file_exists($deployFile)) {
    ftp_sse('No deploy.json found — save FTP settings first.', 'fatal');
    exit;
}
$cfg = json_decode(file_get_contents($deployFile), true) ?: [];

$host    = preg_replace('#^ftps?://#i', '', trim($cfg['ftp_host'] ?? ''));
$port    = (int)($cfg['ftp_port'] ?? 21);
$user    = $cfg['ftp_user'] ?? '';
$pass    = $cfg['ftp_pass'] ?? '';
$path    = rtrim($cfg['ftp_path'] ?? '/public_html', '/');
$passive = !empty($cfg['ftp_passive']);

if ($host === '' || $user === '' || $pass === '') {
    ftp_sse('FTP credentials incomplete — save FTP settings first.', 'fatal');
    exit;
}

// ── Check output exists ───────────────────────────────────────────────────────
$outputBase = BASE_DIR . '/output/' . ACTIVE_SITE_ID . '/';
if (!is_dir($outputBase)) {
    ftp_sse('No output directory found — run Generate Static Site first.', 'fatal');
    exit;
}

// ── Load manifest ─────────────────────────────────────────────────────────────
$manifestFile = ACTIVE_SITE_DIR . '/deploy_manifest.json';
$manifest = file_exists($manifestFile) ? (json_decode(file_get_contents($manifestFile), true) ?: []) : [];

// ── Build file list ───────────────────────────────────────────────────────────
$files = [];
$iter = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($outputBase, FilesystemIterator::SKIP_DOTS)
);
foreach ($iter as $item) {
    if ($item->isFile()) {
        $rel = str_replace('\\', '/', substr($item->getPathname(), strlen($outputBase)));
        $files[$rel] = $item->getPathname();
    }
}

ftp_sse('Found ' . count($files) . ' files in output/.');

// ── Determine what needs uploading ────────────────────────────────────────────
$toUpload = [];
foreach ($files as $rel => $absPath) {
    $hash = md5_file($absPath);
    if (($manifest[$rel] ?? '') !== $hash) {
        $toUpload[$rel] = ['path' => $absPath, 'hash' => $hash];
    }
}

if (empty($toUpload)) {
    ftp_sse('Nothing to upload — all files are up to date.', 'done');
    exit;
}

ftp_sse(count($toUpload) . ' file' . (count($toUpload) !== 1 ? 's' : '') . ' to upload.');

// ── Connect FTP ───────────────────────────────────────────────────────────────
if (!function_exists('ftp_connect')) {
    ftp_sse('PHP FTP extension not available on this server.', 'fatal');
    exit;
}

ftp_sse("Connecting to {$host}:{$port}…");
$conn = ftp_connect($host, $port, 30);
if (!$conn) {
    ftp_sse("Could not connect to {$host}:{$port}.", 'fatal');
    exit;
}

if (!ftp_login($conn, $user, $pass)) {
    ftp_close($conn);
    ftp_sse('FTP login failed — check credentials.', 'fatal');
    exit;
}

if ($passive) {
    ftp_pasv($conn, true);
}

ftp_sse('Connected.');

// ── Ensure remote directories exist ───────────────────────────────────────────
$createdDirs = [];

function ftp_ensure_dir($conn, string $remotePath): void {
    global $createdDirs;
    if (isset($createdDirs[$remotePath])) return;
    $parts = explode('/', ltrim($remotePath, '/'));
    $cur   = '';
    foreach ($parts as $part) {
        if ($part === '') continue;
        $cur .= '/' . $part;
        if (!isset($createdDirs[$cur])) {
            @ftp_mkdir($conn, $cur);
            $createdDirs[$cur] = true;
        }
    }
}

// ── Upload files ──────────────────────────────────────────────────────────────
$uploaded = 0;
$failed   = 0;
$newManifest = $manifest;

foreach ($toUpload as $rel => $info) {
    $remoteDir  = $path . '/' . (dirname($rel) !== '.' ? dirname($rel) : '');
    $remoteFile = $path . '/' . $rel;

    ftp_ensure_dir($conn, $remoteDir);

    $mode = FTP_BINARY;
    $ext  = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
    if (in_array($ext, ['html', 'htm', 'txt', 'xml', 'css', 'js', 'json', 'htaccess'], true)) {
        $mode = FTP_ASCII;
    }

    if (ftp_put($conn, $remoteFile, $info['path'], $mode)) {
        $newManifest[$rel] = $info['hash'];
        $uploaded++;
        ftp_sse("Uploaded: {$rel}");
    } else {
        $failed++;
        ftp_sse("Failed:   {$rel}", 'error');
    }
}

ftp_close($conn);

// Remove entries for files that no longer exist locally
foreach (array_keys($newManifest) as $rel) {
    if (!isset($files[$rel])) unset($newManifest[$rel]);
}

file_put_contents($manifestFile, json_encode($newManifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

$summary = "Deploy complete — {$uploaded} uploaded";
if ($failed > 0) $summary .= ", {$failed} failed";
$summary .= '.';
ftp_sse($summary, $failed > 0 ? 'warn' : 'done');
