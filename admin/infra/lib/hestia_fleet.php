<?php
/**
 * infra/lib/hestia_fleet.php — registry + cached discovery for HestiaCP boxes.
 *
 * ⚠ TRIAL CODE. This exists so the Servers tab can show a Hestia box beside the
 * Plesk one and let you compare them on live data. It is deliberately kept apart
 * from the Plesk path: its own config file (hestia.json), its own cache prefix
 * (hestia:), its own loader. Nothing here can disturb servers.json,
 * infra_discover_server(), or anything currently provisioning.
 *
 * When the panel decision is made, one of the two halves gets deleted whole.
 *
 * Mirrors lib/fleet.php's infra_discover_server(), with one addition: it reports
 * what the sweep COST. Both panels render near-identical cards, which makes them
 * look equivalent; the real difference is how many API calls it takes to answer
 * "what is on this box", and that only shows up if you measure it.
 */
require_once __DIR__ . '/hestia.php';
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/cache.php';

if (!defined('INFRA_HESTIA_TTL')) define('INFRA_HESTIA_TTL', 180);

/** @return array list of HestiaCP server registry entries */
function infra_hestia_servers(): array
{
    $cfg = infra_load_json(infra_config_path('hestia.json'), []);
    return $cfg['servers'] ?? [];
}

/**
 * Everything the Servers tab shows about one Hestia box, cached.
 * Key hestia:{id} — a separate namespace from the Plesk `server:{id}` keys, so
 * ?refresh=1 and cache invalidation on one side never touch the other.
 *
 * @return array{ok:bool,error:string,info:?array,sites:array,users:array,calls:int,ms:int,at:string}
 */
function infra_discover_hestia(array $server, int $ttl = INFRA_HESTIA_TTL): array
{
    $key = 'hestia:' . ($server['id'] ?? md5((string) json_encode($server)));
    $c   = infra_cache_get($key, $ttl);
    if ($c !== null) return $c;

    $before = infra_http_calls();
    $t0     = microtime(true);

    $probe = hestia_probe($server);
    $info  = $probe['ok'] ? hestia_server_info($server)  : null;
    $users = $probe['ok'] ? hestia_list_users($server)   : [];
    $sites = $probe['ok'] ? hestia_list_sites($server)   : [];

    $bundle = [
        'ok'    => $probe['ok'],
        'error' => $probe['error'],
        'info'  => $info,
        'sites' => $sites,
        'users' => $users,
        // The measurement that makes the side-by-side worth having.
        'calls' => infra_http_calls() - $before,
        'ms'    => (int) round((microtime(true) - $t0) * 1000),
        'at'    => date('c'),
    ];
    infra_cache_put($key, $bundle);
    return $bundle;
}

/**
 * Flatten Hestia's v-list-sys-info into the same four facts the Plesk card shows,
 * so the two cards are read the same way. Hestia keys its reply by hostname and
 * uses SHOUTING_SNAKE_CASE; Plesk returns a flat lowercase object.
 *
 * @return array{panel_version:string,hostname:string,platform:string}
 */
function infra_hestia_facts(?array $info): array
{
    if (!is_array($info) || $info === []) {
        return ['panel_version' => '', 'hostname' => '', 'platform' => ''];
    }
    // Reply shape is {"<hostname>": {VERSION: ..., OS: ...}}; tolerate a flat one too.
    $host = (string) array_key_first($info);
    $row  = is_array($info[$host] ?? null) ? $info[$host] : $info;

    $os = trim((string) ($row['OS'] ?? '') . ' ' . (string) ($row['OS_VERSION'] ?? ''));

    return [
        'panel_version' => (string) ($row['VERSION'] ?? ''),
        'hostname'      => (string) ($row['HOSTNAME'] ?? (is_array($info[$host] ?? null) ? $host : '')),
        'platform'      => $os,
    ];
}
