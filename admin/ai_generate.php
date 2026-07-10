<?php
// AI generation trigger — streams generate.py output as NDJSON, then emits a done event.
// POST only. Requires admin auth + CSRF token.
//
// Each line: {"type":"line","text":"..."}
// Final line: {"type":"done","success":bool,"exit_code":int,"last_log":obj|null,"error":str|null}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

// Flush all existing output buffers so lines stream immediately
while (ob_get_level()) ob_end_clean();

header('Content-Type: application/x-ndjson');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no'); // tell nginx not to buffer

function ndjson_emit(array $obj): void {
    echo json_encode($obj, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    @ob_flush(); flush();
}

function ndjson_done(bool $ok, ?int $exitCode = null, ?array $lastLog = null, ?string $error = null): void {
    $r = ['type' => 'done', 'success' => $ok];
    if ($exitCode !== null) $r['exit_code'] = $exitCode;
    if ($lastLog  !== null) $r['last_log']  = $lastLog;
    if ($error    !== null) $r['error']     = $error;
    ndjson_emit($r);
}

// ── Auth ──────────────────────────────────────────────────────────────────────
if (empty($_SESSION['admin_logged_in'])) {
    http_response_code(403);
    ndjson_done(false, null, null, 'Not authenticated.');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    ndjson_done(false, null, null, 'POST required.');
    exit;
}

if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    ndjson_done(false, null, null, 'Invalid request token.');
    exit;
}

if (!ACTIVE_SITE_ID) {
    ndjson_done(false, null, null, 'No active site selected.');
    exit;
}

// ── Parse options ─────────────────────────────────────────────────────────────
$action        = $_POST['action']   ?? 'generate';  // generate | research | sync
$cityId        = trim($_POST['city_id'] ?? '');
$scope         = in_array($_POST['scope'] ?? '', ['landing', 'all'], true) ? $_POST['scope'] : 'landing';
$research      = !empty($_POST['research']);
$refresh       = !empty($_POST['refresh']);
$dryRun        = !empty($_POST['dry_run']);
$modelOverride = '';
$_mo = trim($_POST['model_override'] ?? '');
if (model_is_valid($_mo)) {
    $modelOverride = $_mo;
}

// Sanitize city_id: allow only safe slugs
if ($cityId && !preg_match('/^[a-z0-9][a-z0-9-]{0,59}$/', $cityId)) {
    $cityId = '';
}

// ── API key (not needed for sync-templates) ───────────────────────────────────
$apiKey = ANTHROPIC_API_KEY;
if (!$apiKey && $action !== 'sync') {
    ndjson_done(false, null, null, 'ANTHROPIC_API_KEY is not configured. Add it to config.php or set it as a server environment variable.');
    exit;
}

// ── Build command ─────────────────────────────────────────────────────────────
$python = _find_python();
if (!$python) {
    ndjson_done(false, null, null, 'python3 not found in PATH.');
    exit;
}

$script = BASE_DIR . '/generate.py';
if (!file_exists($script)) {
    ndjson_done(false, null, null, 'generate.py not found.');
    exit;
}

$parts = [escapeshellarg($python), '-u', escapeshellarg($script), '--site', escapeshellarg(ACTIVE_SITE_ID)];

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
        if ($research)       $parts[] = '--research';
        if ($refresh)        $parts[] = '--refresh';
        if ($cityId)         { $parts[] = '--file'; $parts[] = escapeshellarg($cityId); }
        if ($dryRun)         $parts[] = '--dry-run';
        if ($modelOverride)  { $parts[] = '--model'; $parts[] = escapeshellarg($modelOverride); }
        break;
}

// Merge stderr into stdout so everything appears on one pipe
$cmd = implode(' ', $parts) . ' 2>&1';

// ── Run ───────────────────────────────────────────────────────────────────────
set_time_limit(1800); // 30 minutes — long runs (25 pages × 4 blocks) can take 15–20 min

$env = _build_env($apiKey);
$descriptors = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'], // required by proc_open; not used because 2>&1 merges into 1
];

$startMs = intval(microtime(true) * 1000);
$process = proc_open($cmd, $descriptors, $pipes, BASE_DIR, $env);

if (!is_resource($process)) {
    ndjson_done(false, null, null, 'Failed to start generate.py.');
    exit;
}

fclose($pipes[0]);
fclose($pipes[2]); // stderr merged into stdout via 2>&1

// Stream stdout one line at a time — fgets() blocks until a full line or pipe closes
while (!feof($pipes[1])) {
    $line = fgets($pipes[1]);
    if ($line === false) break;
    $clean = preg_replace('/\033\[[0-9;]*m/', '', rtrim($line));
    if ($clean === '') continue;
    // Progress marker emitted by generate.py — route as typed event, not log line
    if (str_starts_with($clean, '__PROGRESS__ ')) {
        $frac = substr($clean, 13); // "D/T"
        [$done, $tot] = array_map('intval', explode('/', $frac, 2));
        ndjson_emit(['type' => 'progress', 'done' => $done, 'total' => $tot]);
    } elseif (str_starts_with($clean, '__WORKERS__ ')) {
        // Effective worker count — tells the UI how many per-worker bars to draw
        ndjson_emit(['type' => 'workers_init', 'count' => (int) substr($clean, 12)]);
    } elseif (str_starts_with($clean, '__WORKER__ ')) {
        // "slot done total page" — one worker's current page + block progress
        $p = explode(' ', substr($clean, 11), 4);
        if (count($p) === 4) {
            ndjson_emit([
                'type'  => 'worker',
                'slot'  => (int) $p[0],
                'done'  => (int) $p[1],
                'total' => (int) $p[2],
                'page'  => $p[3],
            ]);
        }
    } else {
        ndjson_emit(['type' => 'line', 'text' => $clean]);
    }
}

fclose($pipes[1]);
$exitCode = proc_close($process);

// ── Read last log entry for stats ─────────────────────────────────────────────
$lastLog = null;
$logFile = GEN_LOG_FILE;
if ($action !== 'sync' && file_exists($logFile)) {
    $raw = json_decode(file_get_contents($logFile), true);
    if (is_array($raw) && !empty($raw)) {
        $lastLog = end($raw);
    }
}

ndjson_done(
    $exitCode === 0,
    $exitCode,
    $lastLog,
    $exitCode !== 0 ? "Process exited with code $exitCode" : null
);
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
    $base['PYTHONUNBUFFERED'] = '1'; // force Python to flush stdout immediately (no pipe buffering)
    $base['ANTHROPIC_API_KEY'] = $apiKey;
    return $base;
}
