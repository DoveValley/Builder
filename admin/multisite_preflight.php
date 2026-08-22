<?php
// Multisite FTP pre-flight — SSE endpoint (Phase B).
// Streams a per-row connect+login check for the stored params.csv, so bad
// credentials surface before any build. No uploads. GET (token in query), like
// the other SSE endpoints.

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/multisite/params.php';
require_once __DIR__ . '/../includes/multisite/batch.php';

function ms_pf_sse(array $obj): void { echo 'data: ' . json_encode($obj) . "\n\n"; @ob_flush(); flush(); }

if (empty($_SESSION['admin_logged_in'])) { http_response_code(403); ms_pf_sse(['type' => 'fatal', 'msg' => 'Not authenticated.']); exit; }
if (!ACTIVE_SITE_ID)                      { http_response_code(400); ms_pf_sse(['type' => 'fatal', 'msg' => 'No active site.']); exit; }
// A dedicated, single-use, 60s token (minted by action=preflight_token) — NOT the
// general csrf_token. EventSource can't send a header or POST body, so this token
// has to travel in the URL; using the real csrf_token here (as this used to) meant
// a leaked URL (an access log, an intermediate proxy) exposed the same secret every
// other admin POST trusts. Consumed immediately so it can't be replayed.
$pfOk = isset($_SESSION['ms_pf_token'], $_SESSION['ms_pf_token_exp'])
    && hash_equals($_SESSION['ms_pf_token'], (string) ($_GET['token'] ?? ''))
    && time() <= $_SESSION['ms_pf_token_exp'];
unset($_SESSION['ms_pf_token'], $_SESSION['ms_pf_token_exp']);
if (!$pfOk) { http_response_code(403); ms_pf_sse(['type' => 'fatal', 'msg' => 'Invalid or expired security token.']); exit; }

// Resolve the open batch while the session is still open.
$batchDir = ms_active_batch_dir();
if ($batchDir === '') { http_response_code(400); ms_pf_sse(['type' => 'fatal', 'msg' => 'No batch open.']); exit; }

session_write_close();
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');
set_time_limit(0);

$paramsPath = $batchDir . '/params.csv';
if (!is_file($paramsPath)) { ms_pf_sse(['type' => 'fatal', 'msg' => 'No target list stored — upload it first.']); exit; }

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
