<?php
// Force delete all files on the remote server — SSE endpoint.
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

if (empty($_SESSION['admin_logged_in'])) { http_response_code(403); exit; }
if (!hash_equals($_SESSION['csrf_token'] ?? '', $_GET['token'] ?? '')) { http_response_code(403); exit; }

session_write_close();

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');
set_time_limit(0);

function fdel_sse(string $msg, string $type = 'log'): void {
    echo 'data: ' . json_encode(['type' => $type, 'msg' => $msg]) . "\n\n";
    @ob_flush(); flush();
}

$deployFile = ACTIVE_SITE_DIR . '/deploy.json';
if (!file_exists($deployFile)) { fdel_sse('No deploy.json found.', 'fatal'); exit; }
$cfg = json_decode(file_get_contents($deployFile), true) ?: [];

$host    = preg_replace('#^ftps?://#i', '', trim($cfg['ftp_host'] ?? ''));
$port    = (int)($cfg['ftp_port'] ?? 21);
$user    = $cfg['ftp_user'] ?? '';
$pass    = $cfg['ftp_pass'] ?? '';
$path    = rtrim($cfg['ftp_path'] ?? '/public_html', '/');
$passive = !empty($cfg['ftp_passive']);

if (!$host || !$user || !$pass) { fdel_sse('FTP credentials incomplete.', 'fatal'); exit; }
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

// ── Diagnose the raw listing format ──────────────────────────────────────────
$testRaw = @ftp_rawlist($conn, $path);
if (!is_array($testRaw) || count($testRaw) === 0) {
    ftp_close($conn);
    fdel_sse("Remote path '$path' returned no listing. Check FTP Remote Path setting.", 'fatal');
    exit;
}
// Show first 3 lines so format is visible in the log
foreach (array_slice($testRaw, 0, 3) as $sample) {
    fdel_sse('  [dir] ' . $sample);
}

// ── Parse any common FTP rawlist format ──────────────────────────────────────
function fdel_parse_entry(string $item): ?array {
    $item = rtrim($item, "\r\n ");
    if ($item === '') return null;

    // UNIX: -rw-r--r-- 1 user group 12345 Jun 27 10:00 name
    if (preg_match('/^([-dl])\S*\s+\d+\s+\S+\s+\S+\s+(\d+)\s+\w+\s+[\d:]+\s+[\d:]+\s+(.+)$/', $item, $m)) {
        return ['type' => $m[1], 'name' => trim($m[3])];
    }
    // UNIX variant (fewer columns, e.g. some shared hosts):
    if (preg_match('/^([-dl])\S*\s+\d+\s+\S+\s+\S+\s+(\d+)\s+.{6,15}\s+(.+)$/', $item, $m)) {
        return ['type' => $m[1], 'name' => trim($m[3])];
    }
    // Windows/IIS: 06-27-26  10:00AM  <DIR>  name
    if (preg_match('/^\d{2}-\d{2}-\d{2,4}\s+\d+:\d+[AP]M\s+(<DIR>|\d+)\s+(.+)$/', $item, $m)) {
        return ['type' => $m[1] === '<DIR>' ? 'd' : '-', 'name' => trim($m[2])];
    }
    return null;
}

// ── Single-pass recursive delete ─────────────────────────────────────────────
$deleted = 0;
$failed  = 0;

function fdel_recurse($conn, string $dir, string $base, int &$deleted, int &$failed): void {
    $raw = @ftp_rawlist($conn, $dir);
    if (!is_array($raw)) return;

    foreach ($raw as $item) {
        $e = fdel_parse_entry($item);
        if (!$e) continue;
        $name = $e['name'];
        if ($name === '.' || $name === '..') continue;
        $full = rtrim($dir, '/') . '/' . $name;

        if ($e['type'] === 'd') {
            fdel_recurse($conn, $full, $base, $deleted, $failed);
            @ftp_rmdir($conn, $full);
        } else {
            if (@ftp_delete($conn, $full)) {
                $deleted++;
                if ($deleted % 20 === 0) fdel_sse("Deleted $deleted files…");
            } else {
                $failed++;
                fdel_sse("Failed: $full", 'warn');
            }
        }
    }
}

fdel_sse("Deleting all files under $path …", 'warn');
fdel_recurse($conn, $path, $path, $deleted, $failed);

// Clear local manifest so next push re-uploads everything
$manifestFile = ACTIVE_SITE_DIR . '/deploy_manifest.json';
if (file_exists($manifestFile)) @unlink($manifestFile);

ftp_close($conn);

$msg = "Done — deleted $deleted file" . ($deleted !== 1 ? 's' : '');
if ($failed) $msg .= ", $failed failed";
$msg .= '. Manifest cleared.';
fdel_sse($msg, 'done');
