<?php
/**
 * infra/lib/geoip.php — "where is this IP, physically" — one module, reused by
 * anything that needs a server's real-world location (today: the fleet Map tab).
 *
 * Uses ip-api.com's free tier: no key, batch endpoint takes up to 100 queries in
 * one call, so the whole ~20-box fleet is a single outbound request. HTTP only —
 * HTTPS is a paid-plan feature there, which is fine: an IP and a city name are not
 * secrets. Knows nothing about Hestia, servers.json, or "the fleet" — callers hand
 * it IPs/hostnames and get locations back, same shape every time.
 *
 * Cached indefinitely (PHP_INT_MAX ttl): a datacenter IP's city does not move, so
 * this follows the same contract as infra_hestia_cached() — a page load reads the
 * last answer for free, and a Refresh action is the only thing that goes and asks
 * again.
 */
require_once __DIR__ . '/http.php';
require_once __DIR__ . '/cache.php';

const INFRA_GEOIP_FIELDS = 'status,message,country,regionName,city,lat,lon,query';

function infra_geoip_cache_key(string $ip): string { return 'geoip:' . $ip; }

/** One IP/hostname, from cache only — never touches the network. */
function infra_geoip_cached(string $ip): ?array
{
    return $ip === '' ? null : infra_cache_get(infra_geoip_cache_key($ip), PHP_INT_MAX);
}

/** How long ago this one was looked up, or null if never. */
function infra_geoip_age(string $ip): ?int
{
    return $ip === '' ? null : infra_cache_age(infra_geoip_cache_key($ip));
}

/**
 * One row from ip-api.com's batch response into the shape every caller gets,
 * success or failure alike — so nothing downstream has to know this came from
 * ip-api.com specifically, or what its error shape looks like.
 *
 * @return array{ok:bool,city:string,region:string,country:string,lat:?float,lon:?float,error:string}
 */
function infra_geoip_shape(?array $row, string $httpError): array
{
    $blank = ['ok' => false, 'city' => '', 'region' => '', 'country' => '', 'lat' => null, 'lon' => null, 'error' => ''];
    if (!$row) {
        $blank['error'] = $httpError !== '' ? $httpError : 'no response';
        return $blank;
    }
    if (($row['status'] ?? '') !== 'success') {
        $blank['error'] = (string) ($row['message'] ?? 'lookup failed');
        return $blank;
    }
    return [
        'ok'      => true,
        'city'    => (string) ($row['city'] ?? ''),
        'region'  => (string) ($row['regionName'] ?? ''),
        'country' => (string) ($row['country'] ?? ''),
        'lat'     => isset($row['lat']) ? (float) $row['lat'] : null,
        'lon'     => isset($row['lon']) ? (float) $row['lon'] : null,
        'error'   => '',
    ];
}

/**
 * Look up every given IP/hostname in as few calls as the batch endpoint allows
 * (100 per call — one call covers the whole fleet today) and cache each result
 * individually, so a later infra_geoip_cached() for any single one is instant.
 *
 * @param  string[] $ips
 * @return array<string,array> ip => shaped result (see infra_geoip_shape)
 */
function infra_geoip_lookup_many(array $ips): array
{
    $ips = array_values(array_unique(array_filter($ips, fn($v) => (string) $v !== '')));
    $out = [];
    if (!$ips) return $out;

    foreach (array_chunk($ips, 100) as $chunk) {
        $body = json_encode(array_map(
            fn($ip) => ['query' => $ip, 'fields' => INFRA_GEOIP_FIELDS],
            $chunk
        ));
        $r    = infra_http('POST', 'http://ip-api.com/batch', ['body' => $body, 'timeout' => 15]);
        $rows = is_array($r['json']) ? $r['json'] : [];
        foreach ($chunk as $i => $ip) {
            $shaped    = infra_geoip_shape($rows[$i] ?? null, (string) $r['error']);
            $out[$ip]  = $shaped;
            infra_cache_put(infra_geoip_cache_key($ip), $shaped);
        }
    }
    return $out;
}
