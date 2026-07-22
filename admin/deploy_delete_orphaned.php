<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/multisite/deploy.php';

if (empty($_SESSION['admin_logged_in'])) { http_response_code(403); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
    http_response_code(403); exit;
}

header('Content-Type: application/json');

// Deleting many files/dirs can exceed the default execution cap on large sites.
set_time_limit(0);

function del_error(string $msg): void {
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

if (!ACTIVE_SITE_ID) del_error('No active site selected.');

$paths    = $_POST['paths']     ?? [];
$dirPaths = $_POST['dir_paths'] ?? [];
if ((empty($paths) || !is_array($paths)) && (empty($dirPaths) || !is_array($dirPaths))) {
    del_error('No file or directory paths provided.');
}

// Sanitize paths — no traversal, no empty segments
$cleanPaths = [];
foreach ($paths as $p) {
    $p = trim($p);
    if ($p === '' || strpos($p, '..') !== false) continue;
    $cleanPaths[] = $p;
}
if (empty($cleanPaths)) del_error('No valid paths after sanitization.');

// Sanitize directory paths (deepest-first so children are removed before parents).
$cleanDirPaths = [];
foreach ((array)$dirPaths as $p) {
    $p = trim($p);
    if ($p === '' || strpos($p, '..') !== false) continue;
    $cleanDirPaths[] = $p;
}
usort($cleanDirPaths, fn($a, $b) => substr_count($b, '/') - substr_count($a, '/'));

// ── Load deploy config ────────────────────────────────────────────────────────
$deployFile = ACTIVE_SITE_DIR . '/deploy.json';
if (!file_exists($deployFile)) del_error('No deploy.json found.');
$deploy = json_decode(file_get_contents($deployFile), true) ?: [];

$protocol = (($deploy['ftp_protocol'] ?? 'ftp') === 'sftp') ? 'sftp' : 'ftp';
// preg_replace, not ltrim() with a mask — the latter eats a leading f/t/p of a
// bare host like "ftp.example.com".
$host    = preg_replace('#^s?ftps?://#i', '', trim($deploy['ftp_host'] ?? ''));
$user    = $deploy['ftp_user']    ?? '';
$pass    = $deploy['ftp_pass']    ?? '';
$port    = (int)($deploy['ftp_port'] ?? 0) ?: ($protocol === 'sftp' ? 22 : 21);
$ftpPath = rtrim($deploy['ftp_path'] ?? '/public_html', '/');
$passive = !empty($deploy['ftp_passive']);

if (!$host || !$user || !$pass) del_error(strtoupper($protocol) . ' credentials incomplete.');

$deleted = 0;
$failed  = [];

if ($protocol === 'sftp') {
    // ── SFTP branch (phpseclib) ────────────────────────────────────────────────
    $sftpErr = null;
    $sftp = ms_sftp_open($deploy, 15, $sftpErr);
    if (!$sftp) del_error($sftpErr ?: 'SFTP connection failed.');
    $base = trim($ftpPath, '/'); // home-relative, matching the SFTP push
    $pfx  = $base !== '' ? $base . '/' : '';
    foreach ($cleanPaths as $rel) {
        try { if ($sftp->delete($pfx . $rel, false)) $deleted++; else $failed[] = $rel; }
        catch (\Throwable $e) { $failed[] = $rel; }
    }
    foreach ($cleanDirPaths as $rel) {
        try { if ($sftp->rmdir($pfx . $rel)) $deleted++; else $failed[] = $rel; }
        catch (\Throwable $e) { $failed[] = $rel; }
    }
} else {
    // ── FTP branch ─────────────────────────────────────────────────────────────
    if (!function_exists('ftp_connect')) del_error('PHP FTP extension not available.');

    $conn = @ftp_connect($host, $port, 10);
    if (!$conn) del_error("Could not connect to FTP host: $host:$port");

    if (!@ftp_login($conn, $user, $pass)) {
        ftp_close($conn);
        del_error('FTP login failed.');
    }

    if ($passive) ftp_pasv($conn, true);

    foreach ($cleanPaths as $rel) {
        if (@ftp_delete($conn, $ftpPath . '/' . $rel)) $deleted++;
        else $failed[] = $rel;
    }
    foreach ($cleanDirPaths as $rel) {
        if (@ftp_rmdir($conn, $ftpPath . '/' . $rel)) $deleted++;
        else $failed[] = $rel;
    }

    ftp_close($conn);
}

echo json_encode([
    'success' => true,
    'deleted' => $deleted,
    'failed'  => $failed,
]);
