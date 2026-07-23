<?php
/**
 * infra/lib/plesk.php — Plesk REST v2 client (READ-ONLY for the dashboard scaffold).
 * Auth = X-API-Key token (basic auth 401s on data endpoints). Self-contained.
 */
require_once __DIR__ . '/http.php';

function plesk_api(array $server, string $method, string $path, ?array $body = null): array
{
    $host = $server['host'] ?? '';
    $port = $server['port'] ?? 8443;
    $base = 'https://' . $host . ':' . $port . '/api/v2';
    $opts = [
        'headers' => [
            'X-API-Key: ' . ($server['api_token'] ?? ''),
            'Content-Type: application/json',
        ],
        'verify'  => false,
        'timeout' => 30,
    ];
    if ($body !== null) $opts['body'] = $body;
    return infra_http($method, $base . $path, $opts);
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

/** True if a domain already exists on the server (idempotency guard). */
function plesk_site_exists(array $server, string $domain): bool
{
    foreach (plesk_list_sites($server) as $d) {
        if (strcasecmp($d['name'] ?? '', $domain) === 0) return true;
    }
    return false;
}

/**
 * Create a virtual-hosting site + system (FTP/SFTP) user via REST.
 * @return array{ok:bool,id:int|null,ftp_user:string,ftp_pass:string,message:string}
 */
function plesk_create_site(array $server, string $domain, string $ftpUser, string $ftpPass, string $ip = ''): array
{
    $body = [
        'name'             => $domain,
        'hosting_type'     => 'virtual',
        'hosting_settings' => ['ftp_login' => $ftpUser, 'ftp_password' => $ftpPass],
    ];
    if ($ip !== '') $body['ipv4'] = [$ip];

    $r  = plesk_api($server, 'POST', '/domains', $body);
    $ok = in_array($r['code'], [200, 201], true) && isset($r['json']['id']);
    $msg = $ok
        ? 'created (id ' . $r['json']['id'] . ')'
        : ($r['json']['message'] ?? ($r['error'] ?: ('HTTP ' . $r['code'])));
    return [
        'ok'       => $ok,
        'id'       => $ok ? (int) $r['json']['id'] : null,
        'ftp_user' => $ftpUser,
        'ftp_pass' => $ftpPass,
        'message'  => $msg,
    ];
}
