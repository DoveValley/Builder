<?php
/**
 * infra/lib/cloudflare.php — Cloudflare API v4 client (READ-ONLY, multi-account).
 * Each account in config/cloudflare.json has its own API token. Self-contained.
 */
require_once __DIR__ . '/http.php';

function cf_api(array $account, string $method, string $path, array $query = []): array
{
    $url = 'https://api.cloudflare.com/client/v4' . $path;
    if ($query) $url .= '?' . http_build_query($query);
    return infra_http($method, $url, [
        'headers' => [
            'Authorization: Bearer ' . ($account['api_token'] ?? ''),
            'Content-Type: application/json',
        ],
        'verify'  => true,   // api.cloudflare.com has a valid cert
        'timeout' => 25,
    ]);
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
