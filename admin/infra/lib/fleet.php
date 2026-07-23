<?php
/**
 * infra/lib/fleet.php — reconciliation across Plesk + Cloudflare + registrar map.
 * Builds the domain-centric fleet inventory (the Domains view) and detects drift.
 * Self-contained; read-only.
 */
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/plesk.php';
require_once __DIR__ . '/cloudflare.php';
require_once __DIR__ . '/state.php';
require_once __DIR__ . '/cache.php';

const INFRA_DISCOVER_TTL = 180;   // seconds a discovery sweep stays cached

/** Cached per-server discovery bundle: {ok,error,info,sites}. Key server:{id}. */
function infra_discover_server(array $server, int $ttl = INFRA_DISCOVER_TTL): array
{
    $key = 'server:' . ($server['id'] ?? md5((string) json_encode($server)));
    $c = infra_cache_get($key, $ttl);
    if ($c !== null) return $c;
    $probe  = plesk_probe($server);
    $bundle = [
        'ok'    => $probe['ok'],
        'error' => $probe['error'],
        'info'  => $probe['ok'] ? plesk_server_info($server) : null,
        'sites' => $probe['ok'] ? plesk_list_sites($server) : [],
    ];
    infra_cache_put($key, $bundle);
    return $bundle;
}

/** Cached CF zone list for one account. Key cf_zones:{id}. */
function infra_discover_cf_zones(array $account, int $ttl = INFRA_DISCOVER_TTL): array
{
    $key = 'cf_zones:' . ($account['id'] ?? md5((string) json_encode($account)));
    $c = infra_cache_get($key, $ttl);
    if ($c !== null) return $c;
    $zones = cf_list_zones($account);
    infra_cache_put($key, $zones);
    return $zones;
}

/** domain(lower) => {account_id,account_label,zone_id,status,name_servers[]} across ALL CF accounts */
function infra_cf_zone_index(): array
{
    $idx = [];
    foreach (infra_cf_accounts() as $a) {
        foreach (infra_discover_cf_zones($a) as $z) {
            $name = strtolower($z['name'] ?? '');
            if ($name === '') continue;
            $idx[$name] = [
                'account_id'    => $a['id'] ?? '',
                'account_label' => $a['label'] ?? ($a['id'] ?? 'cf'),
                'zone_id'       => $z['id'] ?? '',
                'status'        => $z['status'] ?? '',
                'name_servers'  => $z['name_servers'] ?? [],
            ];
        }
    }
    return $idx;
}

/** domain(lower) => {server_id,server_label,www_root,hosting_type} across ALL servers */
function infra_plesk_domain_index(): array
{
    $idx = [];
    foreach (infra_servers() as $s) {
        $disc = infra_discover_server($s);
        if (!$disc['ok']) continue;
        foreach ($disc['sites'] as $d) {
            $name = strtolower($d['name'] ?? '');
            if ($name === '') continue;
            $idx[$name] = [
                'server_id'    => $s['id'] ?? '',
                'server_label' => $s['label'] ?? ($s['id'] ?? ''),
                'www_root'     => $d['www_root'] ?? '',
                'hosting_type' => $d['hosting_type'] ?? '',
            ];
        }
    }
    return $idx;
}

/** domain(lower) => {registrar:...} from config/domains.json (stored field; WHOIS enrich later) */
function infra_registrar_map(): array
{
    $cfg = infra_load_json(infra_config_path('domains.json'), []);
    $out = [];
    foreach (($cfg['domains'] ?? []) as $name => $meta) {
        $out[strtolower($name)] = $meta;
    }
    return $out;
}

/**
 * Reconciled domain rows joining all three systems.
 * @return array of {domain, plesk|null, cf|null, registrar, state, drift|null}
 */
function infra_fleet_domains(): array
{
    $plesk = infra_plesk_domain_index();
    $cf    = infra_cf_zone_index();
    $reg   = infra_registrar_map();
    $stored = infra_state_all_domains();   // domain(lower) => stored record

    $names = array_values(array_unique(array_merge(
        array_keys($plesk), array_keys($cf), array_keys($reg), array_keys($stored)
    )));
    sort($names);

    $rows = [];
    foreach ($names as $n) {
        $p  = $plesk[$n]  ?? null;
        $z  = $cf[$n]     ?? null;
        $r  = $reg[$n]    ?? [];
        $st = $stored[$n] ?? null;

        if     ($p && !$z) $drift = 'no-cf-zone';     // on a VPS but no CF zone
        elseif (!$p && $z) $drift = 'orphan-zone';    // CF zone with no VPS site
        else               $drift = null;

        if     ($z && ($z['status'] ?? '') === 'active') $state = 'live';    // NS switched → serving
        elseif ($z && ($z['status'] ?? '') === 'pending') $state = 'staged'; // zone exists, NS not switched
        elseif ($p)                                       $state = 'staged'; // plesk only
        elseif ($st && !empty($st['status']))             $state = $st['status']; // stored (not yet discovered live)
        else                                              $state = 'unknown';

        $rows[] = [
            'domain'    => $n,
            'plesk'     => $p,
            'cf'        => $z,
            'registrar' => ($st['registrar'] ?? '') ?: ($r['registrar'] ?? ''),
            'state'     => $state,
            'drift'     => $drift,
            'managed'   => (bool) $st,          // provisioned/tracked by this console
            'ftp_user'  => $st['ftp_user'] ?? '',
            'niche'     => $st['niche'] ?? '',
        ];
    }
    return $rows;
}
