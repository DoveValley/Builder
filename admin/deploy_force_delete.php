<?php
// Force delete all files on the remote server — SSE endpoint.
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/multisite/deploy.php';

if (empty($_SESSION['admin_logged_in'])) { http_response_code(403); exit; }
if (!hash_equals($_SESSION['csrf_token'] ?? '', $_GET['token'] ?? '')) { http_response_code(403); exit; }

session_write_close();

progress_sse_begin();

function fdel_sse(string $msg, string $type = 'log'): void {
    echo 'data: ' . json_encode(['type' => $type, 'msg' => $msg]) . "\n\n";
    @ob_flush(); flush();
}

if (!ACTIVE_SITE_ID) { fdel_sse('No active site selected.', 'fatal'); exit; }

$deployFile = ACTIVE_SITE_DIR . '/deploy.json';
if (!file_exists($deployFile)) { fdel_sse('No deploy.json found.', 'fatal'); exit; }
$cfg = json_decode(file_get_contents($deployFile), true) ?: [];

$protocol = (($cfg['ftp_protocol'] ?? 'ftp') === 'sftp') ? 'sftp' : 'ftp';
$host    = preg_replace('#^s?ftps?://#i', '', trim($cfg['ftp_host'] ?? ''));
$port    = (int)($cfg['ftp_port'] ?? 0) ?: ($protocol === 'sftp' ? 22 : 21);
$user    = $cfg['ftp_user'] ?? '';
$pass    = $cfg['ftp_pass'] ?? '';
$path    = rtrim($cfg['ftp_path'] ?? '/public_html', '/');
$passive = !empty($cfg['ftp_passive']);

if (!$host || !$user || !$pass) { fdel_sse(strtoupper($protocol) . ' credentials incomplete.', 'fatal'); exit; }

// ── SFTP branch (phpseclib): recursive delete of everything under the base ─────
if ($protocol === 'sftp') {
    $sftpErr = null;
    $sftp = ms_sftp_open($cfg, 20, $sftpErr);
    if (!$sftp) { fdel_sse($sftpErr ?: 'SFTP connection failed.', 'fatal'); exit; }

    $base = trim($path, '/'); // home-relative, matching the SFTP push
    fdel_sse("Connected to {$host} (SFTP)");
    fdel_sse('Deleting all files under ' . ($base !== '' ? $base : '~') . ' …', 'warn');

    $deleted = 0; $failed = 0;
    ms_sftp_delete_tree($sftp, $base, fn($m, $t = 'log') => fdel_sse($m, $t), $deleted, $failed);

    $manifestFile = ACTIVE_SITE_DIR . '/deploy_manifest.json';
    if (file_exists($manifestFile)) @unlink($manifestFile);

    $msg = "Done — deleted {$deleted} file" . ($deleted !== 1 ? 's' : '');
    if ($failed) $msg .= ", {$failed} failed";
    $msg .= '. Manifest cleared.';
    fdel_sse($msg, 'done');
    exit;
}

if (!function_exists('ftp_connect')) { fdel_sse('PHP FTP extension not available.', 'fatal'); exit; }

$conn = @ftp_connect($host, $port, 15);
if (!$conn) { fdel_sse("Could not connect to $host:$port", 'fatal'); exit; }
if (!@ftp_login($conn, $user, $pass)) {
    ftp_close($conn);
    fdel_sse('FTP login failed.', 'fatal');
    exit;
}
if ($passive) ftp_pasv($conn, true);

fdel_sse("Connected to $host");

// ── Enter the target dir so all operations use paths RELATIVE to it ───────────
// On chrooted FTP servers (e.g. Hostinger Pure-FTPd) the login home already IS
// the configured path, so passing absolute "/public_html/..." to DELE/RMD/CWD
// yields "550 No such file or directory" even though LIST tolerates it. We chdir
// once and then recurse using bare/relative names, which the server accepts.
if (!@ftp_chdir($conn, $path)) {
    ftp_close($conn);
    fdel_sse("Could not change to remote path '$path'. Check FTP Remote Path setting.", 'fatal');
    exit;
}
$path = rtrim(@ftp_pwd($conn) ?: $path, '/') ?: '/';
fdel_sse("Working directory: $path");

// ── Diagnose the raw listing format ──────────────────────────────────────────
$testRaw = @ftp_rawlist($conn, '.');
if (!is_array($testRaw) || count($testRaw) === 0) {
    ftp_close($conn);
    fdel_sse("Remote path '$path' returned no listing (it may already be empty). Nothing to delete.", 'fatal');
    exit;
}
// Show first 3 lines so format is visible in the log
foreach (array_slice($testRaw, 0, 3) as $sample) {
    fdel_sse('  [dir] ' . $sample);
}

// ── Single-pass recursive delete ─────────────────────────────────────────────
// ms_ftp_delete_tree() (includes/multisite/deploy.php) — shared with the
// per-batch-domain wipe feature so the recursive-delete + rawlist-parsing logic
// exists in exactly one place rather than two copies drifting apart.
$deleted = 0;
$failed  = 0;

fdel_sse("Deleting all files under $path …", 'warn');
ms_ftp_delete_tree($conn, fn($m, $t = 'log') => fdel_sse($m, $t), $deleted, $failed);

// Clear local manifest so next push re-uploads everything
$manifestFile = ACTIVE_SITE_DIR . '/deploy_manifest.json';
if (file_exists($manifestFile)) @unlink($manifestFile);

ftp_close($conn);

$msg = "Done — deleted $deleted file" . ($deleted !== 1 ? 's' : '');
if ($failed) $msg .= ", $failed failed";
$msg .= '. Manifest cleared.';
fdel_sse($msg, 'done');
