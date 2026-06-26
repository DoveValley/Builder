<?php
// AI generation trigger — runs generate.py as a subprocess and returns captured output.
// POST only. Requires admin auth + CSRF token.
// Returns JSON: {success, output, exit_code, duration_ms, error?}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

// ── Auth ──────────────────────────────────────────────────────────────────────
if (empty($_SESSION['admin_logged_in'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Not authenticated.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'POST required.']);
    exit;
}

if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid request token.']);
    exit;
}

if (!ACTIVE_SITE_ID) {
    echo json_encode(['success' => false, 'error' => 'No active site selected.']);
    exit;
}

// ── Parse options ─────────────────────────────────────────────────────────────
$action   = $_POST['action']   ?? 'generate';  // generate | research | sync
$cityId   = trim($_POST['city_id'] ?? '');
$scope    = in_array($_POST['scope'] ?? '', ['landing', 'all'], true) ? $_POST['scope'] : 'landing';
$research = !empty($_POST['research']);
$refresh  = !empty($_POST['refresh']);
$dryRun   = !empty($_POST['dry_run']);

// Sanitize city_id: allow only safe slugs
if ($cityId && !preg_match('/^[a-z0-9][a-z0-9-]{0,59}$/', $cityId)) {
    $cityId = '';
}

// ── API key (not needed for sync-templates) ───────────────────────────────────
$apiKey = ANTHROPIC_API_KEY;
if (!$apiKey && $action !== 'sync') {
    echo json_encode([
        'success' => false,
        'error'   => 'ANTHROPIC_API_KEY is not configured. Add it to config.php or set it as a server environment variable.',
    ]);
    exit;
}

// ── Build command ─────────────────────────────────────────────────────────────
$python = _find_python();
if (!$python) {
    echo json_encode(['success' => false, 'error' => 'python3 not found in PATH.']);
    exit;
}

$script = BASE_DIR . '/generate.py';
if (!file_exists($script)) {
    echo json_encode(['success' => false, 'error' => 'generate.py not found.']);
    exit;
}

$parts = [escapeshellarg($python), escapeshellarg($script), '--site', escapeshellarg(ACTIVE_SITE_ID)];

switch ($action) {
    case 'sync':
        $parts[] = '--sync-templates';
        if ($dryRun) $parts[] = '--dry-run';
        break;

    case 'research':
        $parts[] = '--research-only';
        if ($cityId) { $parts[] = '--file'; $parts[] = escapeshellarg($cityId); }
        if ($dryRun) $parts[] = '--dry-run';
        break;

    default: // generate
        if ($scope === 'all') {
            $parts[] = '--all';
        } else {
            $parts[] = '--page'; $parts[] = 'landing';
        }
        if ($research) $parts[] = '--research';
        if ($refresh)  $parts[] = '--refresh';
        if ($cityId)   { $parts[] = '--file'; $parts[] = escapeshellarg($cityId); }
        if ($dryRun)   $parts[] = '--dry-run';
        break;
}

$cmd = implode(' ', $parts) . ' 2>&1';

// ── Run ───────────────────────────────────────────────────────────────────────
set_time_limit(300); // 5 minutes max

$env = _build_env($apiKey);
$descriptors = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];

$startMs = intval(microtime(true) * 1000);
$process = proc_open($cmd, $descriptors, $pipes, BASE_DIR, $env);

if (!is_resource($process)) {
    echo json_encode(['success' => false, 'error' => 'Failed to start generate.py.']);
    exit;
}

fclose($pipes[0]);
$stdout   = stream_get_contents($pipes[1]);
$stderr   = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$exitCode = proc_close($process);
$duration = intval(microtime(true) * 1000) - $startMs;

// Merge stderr into output (generate.py uses 2>&1 redirect, so stderr is already merged)
$output = $stdout ?: $stderr;

// Strip ANSI colour codes so the browser can display clean text
$output = preg_replace('/\033\[[0-9;]*m/', '', $output);

// ── Read last log entry for stats ─────────────────────────────────────────────
$lastLog = null;
$logFile = GEN_LOG_FILE;
if ($action !== 'sync' && file_exists($logFile)) {
    $raw = json_decode(file_get_contents($logFile), true);
    if (is_array($raw) && !empty($raw)) {
        $lastLog = end($raw);
    }
}

echo json_encode([
    'success'     => $exitCode === 0,
    'exit_code'   => $exitCode,
    'output'      => $output,
    'duration_ms' => $duration,
    'last_log'    => $lastLog,
    'error'       => $exitCode !== 0 ? "Process exited with code $exitCode" : null,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
exit;


// ── Helpers ───────────────────────────────────────────────────────────────────

function _find_python(): string {
    foreach (['python3', '/usr/bin/python3', '/usr/local/bin/python3'] as $p) {
        if (@is_executable($p) || trim((string)@shell_exec("which $p 2>/dev/null"))) {
            return $p;
        }
    }
    return '';
}

function _build_env(string $apiKey): array {
    $base = [];
    foreach (['PATH', 'HOME', 'USER', 'LANG', 'PYTHONPATH', 'VIRTUAL_ENV'] as $k) {
        $v = getenv($k);
        if ($v !== false) $base[$k] = $v;
    }
    // Ensure a sane PATH that includes common Python install locations
    $base['PATH'] = $base['PATH'] ?? '/usr/bin:/usr/local/bin:/bin:/usr/sbin:/sbin';
    $base['ANTHROPIC_API_KEY'] = $apiKey;
    return $base;
}
