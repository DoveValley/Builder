<?php
/**
 * infra/lib/plesk.php — Plesk REST v2 client (READ-ONLY for the dashboard scaffold).
 * Auth = X-API-Key token (basic auth 401s on data endpoints). Self-contained.
 */
require_once __DIR__ . '/http.php';

function plesk_api(array $server, string $method, string $path): array
{
    $host = $server['host'] ?? '';
    $port = $server['port'] ?? 8443;
    $base = 'https://' . $host . ':' . $port . '/api/v2';
    return infra_http($method, $base . $path, [
        'headers' => [
            'X-API-Key: ' . ($server['api_token'] ?? ''),
            'Content-Type: application/json',
        ],
        'verify'  => false,
        'timeout' => 20,
    ]);
}

/** Server info (also serves as a reachability/auth check). @return array|null */
function plesk_server_info(array $server): ?array
{
    $r = plesk_api($server, 'GET', '/server');
    return ($r['code'] === 200 && is_array($r['json'])) ? $r['json'] : null;
}

/** Reachability + auth probe. @return array{ok:bool,code:int,error:string} */
function plesk_probe(array $server): array
{
    $r = plesk_api($server, 'GET', '/server');
    $msg = $r['error'];
    if ($msg === '' && $r['code'] !== 200) {
        $msg = is_array($r['json']) && isset($r['json']['message'])
            ? $r['json']['message']
            : ('HTTP ' . $r['code']);
    }
    return ['ok' => $r['code'] === 200, 'code' => $r['code'], 'error' => $msg];
}

/** List domains/sites on a server. @return array */
function plesk_list_sites(array $server): array
{
    $r = plesk_api($server, 'GET', '/domains');
    return ($r['code'] === 200 && is_array($r['json'])) ? $r['json'] : [];
}
