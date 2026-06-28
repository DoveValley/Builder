<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

if (empty($_SESSION['admin_logged_in'])) { http_response_code(403); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
    http_response_code(403); exit;
}

header('Content-Type: application/json');

function del_error(string $msg): void {
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

$paths = $_POST['paths'] ?? [];
if (empty($paths) || !is_array($paths)) del_error('No file paths provided.');

// Sanitize paths — no traversal, no empty segments
$cleanPaths = [];
foreach ($paths as $p) {
    $p = trim($p);
    if ($p === '' || strpos($p, '..') !== false) continue;
    $cleanPaths[] = $p;
}
if (empty($cleanPaths)) del_error('No valid paths after sanitization.');

// ── Load FTP config ───────────────────────────────────────────────────────────
$deployFile = ACTIVE_SITE_DIR . '/deploy.json';
if (!file_exists($deployFile)) del_error('No deploy.json found.');
$deploy = json_decode(file_get_contents($deployFile), true) ?: [];

$host    = ltrim($deploy['ftp_host'] ?? '', 'ftp://');
$user    = $deploy['ftp_user']    ?? '';
$pass    = $deploy['ftp_pass']    ?? '';
$port    = (int)($deploy['ftp_port'] ?? 21);
$ftpPath = rtrim($deploy['ftp_path'] ?? '/public_html', '/');
$passive = !empty($deploy['ftp_passive']);

if (!$host || !$user || !$pass) del_error('FTP credentials incomplete.');

if (!function_exists('ftp_connect')) del_error('PHP FTP extension not available.');

$conn = @ftp_connect($host, $port, 10);
if (!$conn) del_error("Could not connect to FTP host: $host:$port");

if (!@ftp_login($conn, $user, $pass)) {
    ftp_close($conn);
    del_error('FTP login failed.');
}

if ($passive) ftp_pasv($conn, true);

// ── Delete each file ──────────────────────────────────────────────────────────
$deleted = 0;
$failed  = [];

foreach ($cleanPaths as $rel) {
    $remote = $ftpPath . '/' . $rel;
    if (@ftp_delete($conn, $remote)) {
        $deleted++;
    } else {
        $failed[] = $rel;
    }
}

ftp_close($conn);

echo json_encode([
    'success' => true,
    'deleted' => $deleted,
    'failed'  => $failed,
]);
