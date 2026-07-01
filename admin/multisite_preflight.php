<?php
// Multisite FTP pre-flight — SSE endpoint (Phase B).
// Streams a per-row connect+login check for the stored params.csv, so bad
// credentials surface before any build. No uploads. GET (token in query), like
// the other SSE endpoints.

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/multisite/params.php';

function ms_pf_sse(array $obj): void { echo 'data: ' . json_encode($obj) . "\n\n"; @ob_flush(); flush(); }

if (empty($_SESSION['admin_logged_in'])) { http_response_code(403); ms_pf_sse(['type' => 'fatal', 'msg' => 'Not authenticated.']); exit; }
if (!ACTIVE_SITE_ID)                      { http_response_code(400); ms_pf_sse(['type' => 'fatal', 'msg' => 'No active site.']); exit; }
if (!hash_equals($_SESSION['csrf_token'] ?? '', $_GET['token'] ?? '')) { http_response_code(403); ms_pf_sse(['type' => 'fatal', 'msg' => 'Invalid security token.']); exit; }

session_write_close();
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');
set_time_limit(0);

$paramsPath = ACTIVE_SITE_DIR . '/multisite/params.csv';
if (!is_file($paramsPath)) { ms_pf_sse(['type' => 'fatal', 'msg' => 'No params.csv stored — upload it first.']); exit; }

$parsed = ms_parse_csv($paramsPath);
if ($parsed['error']) { ms_pf_sse(['type' => 'fatal', 'msg' => 'CSV error: ' . $parsed['error']]); exit; }
$v = ms_validate_rows($parsed['rows'], $parsed['header']);

// Only error-free rows that actually carry FTP credentials.
$rows = array_values(array_filter($v['rows'], fn($r) => !$r['errors'] && ($r['data']['ftp_host'] ?? '') !== ''));
$total = count($rows);
ms_pf_sse(['type' => 'start', 'total' => $total]);
if ($total === 0) { ms_pf_sse(['type' => 'done', 'ok' => 0, 'fail' => 0, 'total' => 0]); exit; }

$done = 0; $ok = 0; $fail = 0;
foreach ($rows as $r) {
    $done++;
    $pf = ms_ftp_preflight($r['data'], 8);   // 8s timeout to bound wall-clock
    $pf['ok'] ? $ok++ : $fail++;
    ms_pf_sse(['type' => 'row', 'domain' => $r['domain'], 'ok' => $pf['ok'], 'msg' => $pf['msg'], 'done' => $done, 'total' => $total]);
}
ms_pf_sse(['type' => 'done', 'ok' => $ok, 'fail' => $fail, 'total' => $total]);
