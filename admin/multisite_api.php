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

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action.']);
}
