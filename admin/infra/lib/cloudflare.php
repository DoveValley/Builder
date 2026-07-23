<?php
/**
 * infra/lib/cloudflare.php — Cloudflare API v4 client (READ-ONLY, multi-account).
 * Each account in config/cloudflare.json has its own API token. Self-contained.
 */
require_once __DIR__ . '/http.php';

function cf_auth_headers(array $account): array
{
    // Global API Key (full account access) takes precedence when configured…
    if (!empty($account['email']) && !empty($account['global_key'])) {
        return [
            'X-Auth-Email: ' . $account['email'],
            'X-Auth-Key: ' . $account['global_key'],
            'Content-Type: application/json',
        ];
    }
    // …otherwise a scoped API token (Bearer).
    return [
        'Authorization: Bearer ' . ($account['api_token'] ?? ''),
        'Content-Type: application/json',
    ];
}

function cf_api(array $account, string $method, string $path, array $query = [], ?array $body = null): array
{
    $url = 'https://api.cloudflare.com/client/v4' . $path;
    if ($query) $url .= '?' . http_build_query($query);
    $opts = [
        'headers' => cf_auth_headers($account),
        'verify'  => true,   // api.cloudflare.com has a valid cert
        'timeout' => 25,
    ];
    if ($body !== null) $opts['body'] = $body;
    return infra_http($method, $url, $opts);
}

/** Reachability + token validity probe. @return array{ok:bool,code:int,error:string} */
function cf_probe(array $account): array
{
    $r  = cf_api($account, 'GET', '/zones', ['per_page' => 1]);
    $ok = $r['code'] === 200 && !empty($r['json']['success']);
    $msg = $ok ? '' : ($r['json']['errors'][0]['message'] ?? ('HTTP ' . $r['code']));
    return ['ok' => $ok, 'code' => $r['code'], 'error' => $msg];
}

/** All zones in an account (paginated). @return array of zone objects */
function cf_list_zones(array $account): array
{
    $zones = [];
    $page  = 1;
    do {
        $r = cf_api($account, 'GET', '/zones', ['per_page' => 50, 'page' => $page]);
        if ($r['code'] !== 200 || empty($r['json']['success']) || !isset($r['json']['result'])) break;
        foreach ($r['json']['result'] as $z) $zones[] = $z;
        $totalPages = (int) ($r['json']['result_info']['total_pages'] ?? 1);
        $page++;
    } while ($page <= $totalPages && $page < 200);
    return $zones;
}

/** DNS records for a zone (used by drill-down to check A-record → VPS IP). @return array */
function cf_zone_dns(array $account, string $zoneId): array
{
    $r = cf_api($account, 'GET', "/zones/{$zoneId}/dns_records", ['per_page' => 100]);
    return ($r['code'] === 200 && !empty($r['json']['success'])) ? ($r['json']['result'] ?? []) : [];
}

/**
 * Create a zone under the account. Needs a token with Zone:Edit + the account_id.
 * @return array{ok:bool,zone_id:string,name_servers:array,message:string}
 */
function cf_create_zone(array $account, string $domain): array
{
    $acctId = $account['account_id'] ?? '';
    if ($acctId === '') {
        return ['ok' => false, 'zone_id' => '', 'name_servers' => [],
                'message' => 'account_id missing in config/cloudflare.json (required to create a zone)'];
    }
    $r  = cf_api($account, 'POST', '/zones', [], [
        'name'    => $domain,
        'account' => ['id' => $acctId],
        'type'    => 'full',
    ]);
    $ok = in_array($r['code'], [200, 201], true) && !empty($r['json']['success']);
    if ($ok) {
        return [
            'ok'           => true,
            'zone_id'      => $r['json']['result']['id'] ?? '',
            'name_servers' => $r['json']['result']['name_servers'] ?? [],
            'message'      => 'zone created',
        ];
    }
    return [
        'ok'           => false,
        'zone_id'      => '',
        'name_servers' => [],
        'message'      => $r['json']['errors'][0]['message'] ?? ($r['error'] ?: ('HTTP ' . $r['code'])),
    ];
}

/** Delete a zone. @return array{ok:bool,message:string} */
function cf_delete_zone(array $account, string $zoneId): array
{
    $r  = cf_api($account, 'DELETE', "/zones/{$zoneId}");
    $ok = $r['code'] === 200 && !empty($r['json']['success']);
    return ['ok' => $ok, 'message' => $ok ? 'deleted'
        : ($r['json']['errors'][0]['message'] ?? ($r['error'] ?: ('HTTP ' . $r['code'])))];
}
