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

/**
 * What is actually inside one zone, boiled down to the bit that matters.
 *
 * A zone can exist, have its nameservers set, and hold nothing at all — which is
 * what a domain bought at Cloudflare Registrar looks like before anything is built.
 * "Nameservers set" and "serving a website" are different states and this is what
 * tells them apart.
 *
 * Read-only, cached, and never fetched automatically: it is one API call per zone.
 *
 * @return array{n:int,a:array<int,array{name:string,ip:string,proxied:bool}>,at:string}
 */
function infra_zone_contents(array $account, string $zoneId): array
{
    $records = cf_zone_dns($account, $zoneId);
    $a = [];
    foreach ((array) $records as $r) {
        if (($r['type'] ?? '') !== 'A') continue;
        $a[] = ['name' => (string) ($r['name'] ?? ''), 'ip' => (string) ($r['content'] ?? ''),
                'proxied' => !empty($r['proxied'])];
    }
    return ['n' => count((array) $records), 'a' => $a, 'at' => gmdate('c')];
}

/** A previous look inside a zone, or null when it has never been checked. */
function infra_zone_contents_cached(string $zoneId, int $ttl = 3600): ?array
{
    return infra_cache_get('cf_dns:' . $zoneId, $ttl);
}

/** Look inside a zone now and remember what was there. */
function infra_zone_contents_run(array $account, string $zoneId): array
{
    $res = infra_zone_contents($account, $zoneId);
    infra_cache_put('cf_dns:' . $zoneId, $res);
    return $res;
}

/**
 * Group an account's zones by the nameserver pair Cloudflare gave them.
 *
 * Cloudflare hands out a pair per account, so everything in one account carries the
 * same pair in public DNS. Spreading domains across registrars hides who owns them;
 * a shared nameserver pair puts it straight back — anyone can look up one domain's
 * nameservers and find every other domain using the same two.
 *
 * @return array<string,int> "ns1 + ns2" => how many zones use it
 */
function infra_ns_pairs(array $zones): array
{
    $pairs = [];
    foreach ($zones as $z) {
        $ns = array_map(fn($n) => (string) $n, (array) ($z['name_servers'] ?? []));
        if (!$ns) continue;
        sort($ns);
        $key = implode(' + ', $ns);
        $pairs[$key] = ($pairs[$key] ?? 0) + 1;
    }
    arsort($pairs);
    return $pairs;
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

        // A domain still in the acquisition stage has no infrastructure BY DESIGN,
        // so it must never be reported as drift — otherwise loading 400 names to
        // buy would light up 400 false alarms. Shared definition (state.php), so
        // this view and the Go-Live queue cannot drift apart on what "acquiring" means.
        $acquiring = infra_is_acquiring($st);

        if     ($acquiring)  $drift = null;
        elseif ($p && !$z)   $drift = 'no-cf-zone';     // on a VPS but no CF zone
        elseif (!$p && $z)   $drift = 'orphan-zone';    // CF zone with no VPS site
        else                 $drift = null;

        // An acquisition-stage domain reports the status we recorded, whatever
        // Cloudflare says about it. A CLOUDFLARE-REGISTERED domain gets an active
        // zone the moment it is bought — that is the registrar parking it on
        // Cloudflare nameservers, not a site serving traffic — so reading zone
        // status first counted 15 empty, unprovisioned domains as live sites in the
        // inventory tiles. What we know about our own purchase beats what a zone
        // status implies about it.
        //
        // Deliberately NOT also requiring a Plesk site for 'live': when a Plesk box
        // is unreachable the whole server is skipped from the index, so that test
        // would flip every genuinely live domain on it to 'staged' at once — a worse
        // lie than the one being fixed, and one that appears exactly during an outage.
        if     ($acquiring)                               $state = $st['status'] ?: 'begin';
        elseif ($z && ($z['status'] ?? '') === 'active')  $state = 'live';    // NS switched → serving
        elseif ($z && ($z['status'] ?? '') === 'pending') $state = 'staged'; // zone exists, NS not switched
        elseif ($p)                                       $state = 'staged'; // plesk only
        elseif ($st && !empty($st['status']))             $state = $st['status']; // stored
        elseif ($st)                                      $state = 'begin';  // tracked but statusless
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
            // ── acquisition stage (cols 2–5 of the Domains table) ──
            'ready_to_buy'     => $st['ready_to_buy'] ?? '',
            'buy_registrar'    => $st['buy_registrar'] ?? '',
            'buy_at'           => $st['buy_at'] ?? '',
            'owned'            => $st['owned'] ?? '',
            'avail_note'       => $st['avail_note'] ?? '',
            'avail_price'      => $st['avail_price'] ?? '',
            'avail_checked_at' => $st['avail_checked_at'] ?? '',
            'buy_error'        => $st['buy_error'] ?? '',
            'auto_renew'       => $st['auto_renew'] ?? '',
        ];
    }
    return $rows;
}
