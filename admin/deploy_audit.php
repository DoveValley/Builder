<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

if (empty($_SESSION['admin_logged_in'])) { http_response_code(403); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
    http_response_code(403); exit;
}

header('Content-Type: application/json');

function audit_error(string $msg): void {
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

// ── Load deploy config ────────────────────────────────────────────────────────
$deployFile = ACTIVE_SITE_DIR . '/deploy.json';
if (!file_exists($deployFile)) audit_error('No deploy.json found — save FTP settings first.');
$deploy = json_decode(file_get_contents($deployFile), true) ?: [];

$host    = ltrim($deploy['ftp_host'] ?? '', 'ftp://');
$user    = $deploy['ftp_user']    ?? '';
$pass    = $deploy['ftp_pass']    ?? '';
$port    = (int)($deploy['ftp_port'] ?? 21);
$path    = rtrim($deploy['ftp_path'] ?? '/public_html', '/');
$passive = !empty($deploy['ftp_passive']);

if (!$host || !$user || !$pass) audit_error('FTP credentials incomplete — check FTP settings.');

// ── Local file list ───────────────────────────────────────────────────────────
$localDir = BASE_DIR . '/output/' . ACTIVE_SITE_ID;
if (!is_dir($localDir)) audit_error('No local build found — run Generate Static Site first.');

$localFiles = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($localDir, FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    if (!$file->isFile()) continue;
    $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($localDir) + 1));
    $localFiles[$rel] = $file->getSize();
}

// ── FTP connect ───────────────────────────────────────────────────────────────
if (!function_exists('ftp_connect')) audit_error('PHP FTP extension is not available on this server.');

$conn = @ftp_connect($host, $port, 10);
if (!$conn) audit_error("Could not connect to FTP host: $host:$port");

if (!@ftp_login($conn, $user, $pass)) {
    ftp_close($conn);
    audit_error('FTP login failed — check username and password.');
}

if ($passive) ftp_pasv($conn, true);

// ── Recursive remote file list ────────────────────────────────────────────────
function ftp_parse_rawlist(array $raw): array {
    $entries = [];
    foreach ($raw as $item) {
        $item = rtrim($item, "\r\n");
        if ($item === '') continue;

        // UNIX format: -rw-r--r-- 1 user group 12345 Jun 27 10:00 name
        if (preg_match('/^([-dl])\S+\s+\d+\s+\S+\s+\S+\s+(\d+)\s+\S+\s+\S+\s+\S+\s+(.+)$/', $item, $m)) {
            $entries[] = ['type' => $m[1], 'size' => (int)$m[2], 'name' => trim($m[3])];
            continue;
        }
        // Windows/IIS format: 06-27-26  10:00AM  <DIR>  name  or  12345  name
        if (preg_match('/^\d{2}-\d{2}-\d{2,4}\s+\d+:\d+[AP]M\s+(<DIR>|\d+)\s+(.+)$/', $item, $m)) {
            $isDir = $m[1] === '<DIR>';
            $entries[] = ['type' => $isDir ? 'd' : '-', 'size' => $isDir ? 0 : (int)$m[1], 'name' => trim($m[2])];
        }
    }
    return $entries;
}

function ftp_list_recursive($conn, string $dir): array {
    $files = [];
    $raw = @ftp_rawlist($conn, $dir);
    if (!is_array($raw)) return $files;
    foreach (ftp_parse_rawlist($raw) as $entry) {
        $name = $entry['name'];
        if ($name === '.' || $name === '..') continue;
        $fullPath = rtrim($dir, '/') . '/' . $name;
        if ($entry['type'] === 'd') {
            $files = array_merge($files, ftp_list_recursive($conn, $fullPath));
        } elseif ($entry['type'] === '-') {
            $files[$fullPath] = $entry['size'];
        }
    }
    return $files;
}

$remoteRaw = ftp_list_recursive($conn, $path);
ftp_close($conn);

// Normalize remote paths to relative (strip the remote base path)
$remoteFiles = [];
$baseLen = strlen($path) + 1;
foreach ($remoteRaw as $fullPath => $size) {
    $rel = substr($fullPath, $baseLen);
    $remoteFiles[$rel] = $size;
}

// ── Reconcile ────────────────────────────────────────────────────────────────
$missing  = []; // in local, not on server
$orphaned = []; // on server, not in local
$changed  = []; // in both, size differs
$matched  = 0;

foreach ($localFiles as $rel => $localSize) {
    if (!isset($remoteFiles[$rel])) {
        $missing[] = ['path' => $rel, 'size' => $localSize];
    } elseif ($remoteFiles[$rel] !== $localSize) {
        $changed[] = ['path' => $rel, 'local_size' => $localSize, 'remote_size' => $remoteFiles[$rel]];
    } else {
        $matched++;
    }
}

foreach ($remoteFiles as $rel => $remoteSize) {
    if (!isset($localFiles[$rel])) {
        $orphaned[] = ['path' => $rel, 'size' => $remoteSize];
    }
}

// Sort each list by path
usort($missing,  fn($a,$b) => strcmp($a['path'], $b['path']));
usort($orphaned, fn($a,$b) => strcmp($a['path'], $b['path']));
usort($changed,  fn($a,$b) => strcmp($a['path'], $b['path']));

echo json_encode([
    'success'  => true,
    'matched'  => $matched,
    'missing'  => $missing,
    'orphaned' => $orphaned,
    'changed'  => $changed,
    'local_total'  => count($localFiles),
    'remote_total' => count($remoteFiles),
]);
