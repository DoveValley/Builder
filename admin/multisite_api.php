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

    // Download a ready-to-edit sample CSV (all columns, 5 example cities).
    case 'sample_csv':
        header_remove('Content-Type');
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="multisite-sample.csv"');
        $cols = ['domain', 'business', 'phone', 'tel', 'email', 'address', 'city', 'state', 'SS', 'zip',
                 'lat', 'lng', 'rating', 'review_count', 'analytics_id', 'logo',
                 'ftp_host', 'ftp_port', 'ftp_user', 'ftp_pass', 'ftp_path', 'ftp_passive'];
        // Example rows — realistic format, obvious placeholders (replace before a real run).
        $sample = [
            ['pmtraining-dallas.com',    'Dallas PM Academy',      '214-555-0100', '+12145550100', 'info@pmtraining-dallas.com',    '100 Main St, Suite 400', 'Dallas',      'Texas',          'TX', '75201', '32.7767',  '-96.7970', '4.8', '126', 'G-XXXXXXXXX1', '', 'ftp.pmtraining-dallas.com',    '21', 'deploy@pmtraining-dallas.com',    'CHANGEME', '/public_html', '1'],
            ['pmtraining-austin.com',    'Austin PM Academy',      '512-555-0100', '+15125550100', 'info@pmtraining-austin.com',    '200 Congress Ave',       'Austin',      'Texas',          'TX', '78701', '30.2672',  '-97.7431', '4.9', '203', 'G-XXXXXXXXX2', '', 'ftp.pmtraining-austin.com',    '21', 'deploy@pmtraining-austin.com',    'CHANGEME', '/public_html', '1'],
            ['pmtraining-charlotte.com', 'Charlotte PM Academy',   '704-555-0100', '+17045550100', 'info@pmtraining-charlotte.com', '300 Tryon St',           'Charlotte',   'North Carolina', 'NC', '28202', '35.2271',  '-80.8431', '4.7', '88',  'G-XXXXXXXXX3', '', 'ftp.pmtraining-charlotte.com', '21', 'deploy@pmtraining-charlotte.com', 'CHANGEME', '/public_html', '1'],
            ['pmtraining-tampa.com',     'Tampa PM Academy',       '813-555-0100', '+18135550100', 'info@pmtraining-tampa.com',     '400 Ashley Dr',          'Tampa',       'Florida',        'FL', '33602', '27.9506',  '-82.4572', '4.8', '154', 'G-XXXXXXXXX4', '', 'ftp.pmtraining-tampa.com',     '21', 'deploy@pmtraining-tampa.com',     'CHANGEME', '/public_html', '1'],
            ['pmtraining-phoenix.com',   'Phoenix PM Academy',     '602-555-0100', '+16025550100', 'info@pmtraining-phoenix.com',   '500 Central Ave',        'Phoenix',     'Arizona',        'AZ', '85004', '33.4484',  '-112.0740','4.6', '71',  'G-XXXXXXXXX5', '', 'ftp.pmtraining-phoenix.com',   '21', 'deploy@pmtraining-phoenix.com',   'CHANGEME', '/public_html', '1'],
        ];
        // Optional landing_cities column — service landing pages this deploy also gets.
        // Format: "City, ST; City, ST". Blank = none (home + core pages only).
        $cols[] = 'landing_cities';
        $landingExamples = ['Plano, TX; Irving, TX; Frisco, TX', 'Round Rock, TX; Cedar Park, TX', '', '', ''];
        foreach ($sample as $i => &$row) { $row[] = $landingExamples[$i] ?? ''; }
        unset($row);
        $out = fopen('php://output', 'w');
        fputcsv($out, $cols);
        foreach ($sample as $row) fputcsv($out, $row);
        fclose($out);
        exit;

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
        // Swap any masked (__KEEP__) passwords back to the stored real ones before validating.
        $rows = ms_rehydrate_ftp_pass($parsed['rows'], $paramsPath);
        $v = ms_validate_rows($rows, $parsed['header']);

        $stored = false;
        if ($v['error'] === 0 && count($v['rows']) > 0) {
            $rehydrated = tempnam(sys_get_temp_dir(), 'mscsv');
            ms_write_csv($rehydrated, $parsed['header'], $rows);
            ms_store_params_csv($masterId, $rehydrated);
            @unlink($rehydrated);
            $stored = true;
        }
        echo json_encode(['stored' => $stored, 'filename' => basename($_FILES['csv']['name'] ?? 'upload.csv')] + ms_validation_payload($v));
        break;

    // Download the current stored params.csv with FTP passwords masked (__KEEP__).
    // Re-uploading the file preserves the real passwords (matched by domain).
    case 'download_csv':
        if (!is_file($paramsPath)) { http_response_code(404); echo json_encode(['error' => 'No params.csv stored — upload it first.']); break; }
        $parsed = ms_parse_csv($paramsPath);
        if ($parsed['error']) { http_response_code(400); echo json_encode(['error' => $parsed['error']]); break; }
        header_remove('Content-Type');
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="params-' . preg_replace('/[^A-Za-z0-9._-]/', '_', $masterId) . '.csv"');
        ms_write_csv('php://output', $parsed['header'], ms_mask_ftp_pass($parsed['rows']));
        exit;

    // List saved upload versions (last 15), newest first.
    case 'list_versions':
        echo json_encode(['versions' => ms_list_params_versions($masterId)]);
        break;

    // Download one saved version, FTP masked.
    case 'download_version':
        $id = (string)($_GET['id'] ?? '');
        if (!ms_valid_version_id($id)) { http_response_code(400); echo json_encode(['error' => 'Invalid version id.']); break; }
        $vf = ACTIVE_SITE_DIR . '/multisite/params_versions/' . $id . '.csv';
        if (!is_file($vf)) { http_response_code(404); echo json_encode(['error' => 'Version not found.']); break; }
        $parsed = ms_parse_csv($vf);
        header_remove('Content-Type');
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="params-' . $id . '.csv"');
        ms_write_csv('php://output', $parsed['header'], ms_mask_ftp_pass($parsed['rows']));
        exit;

    // Restore a saved version as the current params.csv (real passwords, re-validated).
    case 'restore_version':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'POST required.']); break; }
        $id = (string)($_POST['id'] ?? '');
        if (!ms_valid_version_id($id)) { echo json_encode(['error' => 'Invalid version id.']); break; }
        $vf = ACTIVE_SITE_DIR . '/multisite/params_versions/' . $id . '.csv';
        if (!is_file($vf)) { echo json_encode(['error' => 'Version not found.']); break; }
        $parsed = ms_parse_csv($vf);
        if ($parsed['error']) { echo json_encode(['error' => $parsed['error']]); break; }
        $v = ms_validate_rows($parsed['rows'], $parsed['header']);
        if ($v['error'] > 0) { echo json_encode(['error' => 'That version has validation errors and was not restored.'] + ms_validation_payload($v)); break; }
        ms_store_params_csv($masterId, $vf);
        echo json_encode(['restored' => true, 'stored' => true] + ms_validation_payload($v));
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
                'params_version' => $d['params_version'] ?? '',
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
