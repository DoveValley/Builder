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
        if (!is_dir($runsDir)) mkdir($runsDir, 0775, true);

        // Refuse if a run is already active for this master.
        $latest = ms_latest_run_file($runsDir);
        if ($latest) {
            $cur = ms_read_run($latest);
            if ($cur && ($cur['state'] ?? '') === 'running') {
                echo json_encode(['error' => 'A campaign is already running.', 'run_id' => $cur['run_id'] ?? null]);
                break;
            }
        }

        $runId = gmdate('Ymd-His') . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
        $flags = ' --no-preflight';   // pre-flight is its own button
        $jobs  = max(1, min(16, (int)($_POST['jobs'] ?? 1)));   $flags .= ' --jobs=' . $jobs;
        $rtr   = max(0, min(5,  (int)($_POST['retries'] ?? 0)));if ($rtr > 0) $flags .= ' --retries=' . $rtr;
        $lim   = max(0, (int)($_POST['limit'] ?? 0));           if ($lim > 0) $flags .= ' --limit=' . $lim;
        if (!empty($_POST['no_ai'])) $flags .= ' --no-ai';
        if (!empty($_POST['force'])) $flags .= ' --force';

        $out = $runsDir . '/' . $runId . '.out';
        $cmd = 'setsid ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(BASE_DIR . '/multisite/run_campaign.php')
             . ' ' . escapeshellarg($masterId) . ' --run-id=' . escapeshellarg($runId) . $flags
             . ' > ' . escapeshellarg($out) . ' 2>&1 &';
        exec($cmd);
        echo json_encode(['started' => true, 'run_id' => $runId]);
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

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action.']);
}
