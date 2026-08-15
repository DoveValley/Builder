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
 * Has this box been set up yet? A machine can be bought, running and pingable
 * long before Hestia is on it, and the console has to be able to hold it in that
 * state — otherwise a new VPS cannot be written down until it is finished.
 * No key pair means there is nothing to authenticate with, so there is no point
 * asking the network anything.
 */
function hestia_server_configured(array $server): bool
{
    return trim((string) ($server['access_key'] ?? '')) !== ''
        && trim((string) ($server['secret_key'] ?? '')) !== '';
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
    // A box with no key pair is not "down" — it is not finished. Say so without
    // spending a request, and without caching it: the moment the keys are pasted
    // in, the next page load should try for real rather than serve this back.
    if (!hestia_server_configured($server)) {
        return ['ok' => false, 'error' => '', 'unconfigured' => true, 'info' => null,
                'sites' => [], 'users' => [], 'calls' => 0, 'ms' => 0, 'at' => date('c')];
    }

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
 * Is this vhost infrastructure rather than a site we deployed?
 *
 * Hestia's installer creates a vhost for the box's own hostname (box2.q111.xyz),
 * whose docroot holds nothing but its placeholder index.html and robots.txt.
 * Counting it as a deployed site made every empty box report "1 website" — the
 * number looked like work and was an artefact of installing the panel.
 *
 * One rule, in one place, because it is asked from the Servers tab, the dashboard
 * and the batch page, and three copies of it would drift.
 */
function hestia_is_infra_vhost(string $domain, array $server): bool
{
    $d = strtolower(trim($domain));
    if ($d === '') return true;
    if ($d === strtolower(trim((string) ($server['host'] ?? '')))) return true;
    return (bool) preg_match('/^box\d+\./i', $d);
}

/**
 * The whole fleet in one canonical shape — the answer to "what have I got".
 *
 * Every caller used to run the same loop (list the registry, discover each box,
 * dig the facts out) and each dug slightly differently: one counted the panel's
 * own hostname as a site, another did not. This is that loop, once.
 *
 * Cached exactly as infra_discover_hestia() is, so calling it costs nothing extra
 * over the discovery the page was doing anyway.
 *
 * @return array<int,array{server:array,id:string,label:string,host:string,ok:bool,
 *   pending:bool,error:string,version:string,platform:string,hostname:string,
 *   sites:array,deployed:int,accounts:array,calls:int,ms:int}>
 */
function infra_hestia_fleet(int $ttl = INFRA_HESTIA_TTL): array
{
    $out = [];
    foreach (infra_hestia_servers() as $srv) {
        $d = infra_discover_hestia($srv, $ttl);
        $f = infra_hestia_facts($d['info'] ?? null);
        $deployed = 0;
        foreach ($d['sites'] as $s) {
            if (!hestia_is_infra_vhost((string) ($s['name'] ?? ''), $srv)) $deployed++;
        }
        $out[] = [
            'server'   => $srv,
            'id'       => (string) ($srv['id'] ?? ''),
            'label'    => (string) ($srv['label'] ?? ($srv['id'] ?? '')),
            'host'     => (string) ($srv['host'] ?? ''),
            'ok'       => (bool) $d['ok'],
            'pending'  => !empty($d['unconfigured']),
            'error'    => (string) ($d['error'] ?? ''),
            'version'  => $f['panel_version'],
            'platform' => $f['platform'],
            'hostname' => $f['hostname'],
            'sites'    => $d['sites'],
            'deployed' => $deployed,
            'accounts' => infra_hestia_accounts($d),
            'calls'    => (int) ($d['calls'] ?? 0),
            'ms'       => (int) ($d['ms'] ?? 0),
        ];
    }
    return $out;
}

/**
 * Accounts on a box, split by whether they actually hold a site.
 *
 * ⚠ "total" is accounts the console can SEE, which is not always every account
 * on the machine: v-list-users returns only the account the access key belongs
 * to (verified 2026-08-15), so an unrelated account holding nothing is
 * invisible. Accounts holding sites always appear, because every site carries
 * its owner — so "with a site" is exact and "without" is a floor, not a count.
 * Said plainly on the card rather than presented as a full census.
 *
 * Costs no extra API calls: both inputs are already in the discovery bundle.
 *
 * @return array{total:int,with_site:int,without_site:int,exact:bool}
 */
function infra_hestia_accounts(array $d): array
{
    $owners = [];
    foreach ($d['sites'] ?? [] as $s) {
        $u = (string) ($s['user'] ?? '');
        if ($u !== '') $owners[$u] = true;
    }
    $known = $owners;
    foreach ($d['users'] ?? [] as $u) {
        if ((string) $u !== '') $known[(string) $u] = true;
    }
    $total = count($known);
    $with  = count($owners);
    return ['total' => $total, 'with_site' => $with, 'without_site' => max(0, $total - $with),
            // Exact only when every known account holds a site — then there is
            // nothing the blind spot could be hiding a count of.
            'exact' => $total === $with];
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

    // VERSION is the OPERATING SYSTEM's version; the panel's own is under HESTIA.
    // Reading VERSION here put "12.15" in a row labelled "Hestia version" — a real
    // number, in the right shape, in the wrong field, which is the kind of wrong
    // that never looks wrong. (There is no OS_VERSION key; that read empty.)
    $os = trim((string) ($row['OS'] ?? '') . ' ' . (string) ($row['VERSION'] ?? ''));

    return [
        'panel_version' => (string) ($row['HESTIA'] ?? ''),
        'hostname'      => (string) ($row['HOSTNAME'] ?? (is_array($info[$host] ?? null) ? $host : '')),
        'platform'      => $os,
    ];
}
