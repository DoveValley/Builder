<?php
// Multisite admin API (Phase A — intake). JSON responses.
// Wraps the params intake cores (includes/multisite/params.php). The active site
// is the campaign master. All POSTs require the admin CSRF token.

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/multisite/params.php';

header('Content-Type: application/json');

// ── Auth ──────────────────────────────────────────────────────────────────────
if (empty($_SESSION['admin_logged_in'])) { http_response_code(403); echo json_encode(['error' => 'Not authenticated.']); exit; }
if (!ACTIVE_SITE_ID)                      { http_response_code(400); echo json_encode(['error' => 'No active site selected.']); exit; }
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
    http_response_code(403); echo json_encode(['error' => 'Invalid security token.']); exit;
}

$action     = $_REQUEST['action'] ?? '';
$masterId   = ACTIVE_SITE_ID;
$paramsPath = ACTIVE_SITE_DIR . '/multisite/params.csv';

/** Build browser-safe display rows — never sends ftp_pass to the client. */
function ms_rows_for_ui(array $v): array {
    $out = [];
    foreach ($v['rows'] as $r) {
        $d = $r['data'];
        $out[] = [
            'line'     => $r['line'],
            'domain'   => $r['domain'],
            'business' => $d['business'] ?? '',
            'city'     => trim(($d['city'] ?? '') . (($d['SS'] ?? '') !== '' ? ', ' . $d['SS'] : '')),
            'has_ftp'  => ($d['ftp_host'] ?? '') !== '' && ($d['ftp_user'] ?? '') !== '',
            'status'   => $r['errors'] ? 'error' : ($r['warnings'] ? 'warn' : 'ok'),
            'errors'   => $r['errors'],
            'warnings' => $r['warnings'],
        ];
    }
    return $out;
}

function ms_validation_payload(array $v): array {
    return [
        'summary'         => ['total' => count($v['rows']), 'ok' => $v['ok'], 'warn' => $v['warn'], 'error' => $v['error']],
        'unknown_columns' => $v['unknown_columns'],
        'rows'            => ms_rows_for_ui($v),
    ];
}

/** True if a process id is alive (Linux /proc, or posix). */
function ms_pid_alive(int $pid): bool {
    if ($pid <= 0) return false;
    if (function_exists('posix_kill')) return @posix_kill($pid, 0);
    return file_exists('/proc/' . $pid);
}

/** The newest run status file for this master, or ''. */
function ms_latest_run_file(string $runsDir): string {
    $files = glob($runsDir . '/*.json') ?: [];
    if (!$files) return '';
    usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));
    return $files[0];
}

/** Read a run status file and mark a dead 'running' run as 'stale'. */
function ms_read_run(string $file): ?array {
    if (!is_file($file)) return null;
    $d = json_decode(file_get_contents($file), true);
    if (!is_array($d)) return null;
    if (($d['state'] ?? '') === 'running' && !ms_pid_alive((int)($d['pid'] ?? 0))) $d['state'] = 'stale';
    return $d;
}

/** The currently-active (genuinely running) run for this master, or null. */
function ms_active_run(string $runsDir): ?array {
    $latest = ms_latest_run_file($runsDir);
    if (!$latest) return null;
    $cur = ms_read_run($latest);
    return ($cur && ($cur['state'] ?? '') === 'running') ? $cur : null;
}

/** Launch run_campaign as a detached background process. Returns the run_id. */
function ms_launch_campaign(string $masterId, string $runsDir, string $flags): string {
    if (!is_dir($runsDir)) mkdir($runsDir, 0775, true);
    $runId = gmdate('Ymd-His') . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
    $out   = $runsDir . '/' . $runId . '.out';
    $cmd = 'setsid ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(BASE_DIR . '/multisite/run_campaign.php')
         . ' ' . escapeshellarg($masterId) . ' --run-id=' . escapeshellarg($runId) . ' --no-preflight' . $flags
         . ' > ' . escapeshellarg($out) . ' 2>&1 &';
    exec($cmd);
    return $runId;
}

/** Build run_campaign flags from a set of options. */
function ms_run_flags(array $o): string {
    $flags = ' --jobs=' . max(1, min(16, (int)($o['jobs'] ?? 1)));
    $rtr = max(0, min(5, (int)($o['retries'] ?? 0))); if ($rtr > 0) $flags .= ' --retries=' . $rtr;
    $lim = max(0, (int)($o['limit'] ?? 0));            if ($lim > 0) $flags .= ' --limit=' . $lim;
    if (!empty($o['no_ai'])) $flags .= ' --no-ai';
    if (!empty($o['force'])) $flags .= ' --force';
    if (!empty($o['only']))  $flags .= ' --only=' . escapeshellarg(implode(',', (array)$o['only']));
    return $flags;
}

switch ($action) {

    // Current stored params.csv state (tab load).
    case 'status':
        if (!is_file($paramsPath)) { echo json_encode(['stored' => false]); break; }
        $parsed = ms_parse_csv($paramsPath);
        if ($parsed['error']) { echo json_encode(['stored' => true, 'error' => $parsed['error']]); break; }
        $v = ms_validate_rows($parsed['rows'], $parsed['header']);
        echo json_encode(['stored' => true] + ms_validation_payload($v));
        break;

    // Upload a CSV → validate → store only if error-free.
    case 'upload_csv':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'POST required.']); break; }
        if (empty($_FILES['csv']) || ($_FILES['csv']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
            || !is_uploaded_file($_FILES['csv']['tmp_name'])) {
            echo json_encode(['error' => 'No file uploaded.']); break;
        }
        $tmp = $_FILES['csv']['tmp_name'];
        if (filesize($tmp) > 2 * 1024 * 1024) { echo json_encode(['error' => 'File too large (max 2 MB).']); break; }

        $parsed = ms_parse_csv($tmp);
        if ($parsed['error']) { echo json_encode(['error' => 'CSV error: ' . $parsed['error']]); break; }
        $v = ms_validate_rows($parsed['rows'], $parsed['header']);

        $stored = false;
        if ($v['error'] === 0 && count($v['rows']) > 0) {
            ms_store_params_csv($masterId, $tmp);
            $stored = true;
        }
        echo json_encode(['stored' => $stored, 'filename' => basename($_FILES['csv']['name'] ?? 'upload.csv')] + ms_validation_payload($v));
        break;

    // Launch a campaign as a detached background process.
    case 'run':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'POST required.']); break; }
        if (!is_file($paramsPath)) { echo json_encode(['error' => 'No params.csv stored — upload it first.']); break; }
        $runsDir = ACTIVE_SITE_DIR . '/multisite/runs';
        if ($active = ms_active_run($runsDir)) { echo json_encode(['error' => 'A campaign is already running.', 'run_id' => $active['run_id'] ?? null]); break; }
        $flags = ms_run_flags([
            'jobs' => $_POST['jobs'] ?? 1, 'retries' => $_POST['retries'] ?? 0, 'limit' => $_POST['limit'] ?? 0,
            'no_ai' => !empty($_POST['no_ai']), 'force' => !empty($_POST['force']),
        ]);
        echo json_encode(['started' => true, 'run_id' => ms_launch_campaign($masterId, $runsDir, $flags)]);
        break;

    // Poll the latest run (or a specific run_id) for live progress.
    case 'run_status':
        $runsDir = ACTIVE_SITE_DIR . '/multisite/runs';
        $rid = $_GET['run_id'] ?? '';
        $file = ($rid !== '' && preg_match('/^[A-Za-z0-9._-]{1,64}$/', $rid))
            ? $runsDir . '/' . $rid . '.json'
            : ms_latest_run_file($runsDir);
        $d = $file ? ms_read_run($file) : null;
        echo json_encode($d ?: ['none' => true]);
        break;

    // List recent runs (history).
    case 'list_runs':
        $runsDir = ACTIVE_SITE_DIR . '/multisite/runs';
        $files = glob($runsDir . '/*.json') ?: [];
        usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));
        $runs = [];
        foreach (array_slice($files, 0, 30) as $f) {
            $d = ms_read_run($f);
            if (!$d) continue;
            $runs[] = [
                'run_id'      => $d['run_id'] ?? basename($f, '.json'),
                'state'       => $d['state'] ?? '?',
                'started_at'  => $d['started_at'] ?? null,
                'finished_at' => $d['finished_at'] ?? null,
                'total'       => $d['total'] ?? 0, 'ok' => $d['ok'] ?? 0, 'failed' => $d['failed'] ?? 0,
                'cost'        => $d['totals']['cost_usd'] ?? 0,
            ];
        }
        echo json_encode(['runs' => $runs]);
        break;

    // Re-run only the failed rows of a past run (carrying its options forward).
    case 'retry_failed':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'POST required.']); break; }
        $runsDir = ACTIVE_SITE_DIR . '/multisite/runs';
        $rid = $_POST['run_id'] ?? '';
        if ($rid === '' || !preg_match('/^[A-Za-z0-9._-]{1,64}$/', $rid)) { echo json_encode(['error' => 'Invalid run id.']); break; }
        $d = ms_read_run($runsDir . '/' . $rid . '.json');
        if (!$d) { echo json_encode(['error' => 'Run not found.']); break; }
        $failed = array_values(array_unique(array_map(fn($r) => $r['domain'], array_filter($d['results'] ?? [], fn($r) => ($r['status'] ?? '') === 'failed'))));
        if (!$failed) { echo json_encode(['error' => 'No failed rows to retry.']); break; }
        if ($active = ms_active_run($runsDir)) { echo json_encode(['error' => 'A campaign is already running.', 'run_id' => $active['run_id'] ?? null]); break; }
        $o = $d['options'] ?? [];
        $o['only'] = $failed;   // scope the new run to just the failed domains
        echo json_encode(['started' => true, 'run_id' => ms_launch_campaign($masterId, $runsDir, ms_run_flags($o)), 'retrying' => count($failed)]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action.']);
}
