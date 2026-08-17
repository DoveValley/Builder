<?php
/**
 * infra/lib/fleet.php — reconciliation across Plesk + Cloudflare + registrar map.
 * Builds the domain-centric fleet inventory (the Domains view) and detects drift.
 * Self-contained; read-only.
 */
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/cloudflare.php';
require_once __DIR__ . '/state.php';
require_once __DIR__ . '/cache.php';

const INFRA_DISCOVER_TTL = 180;   // seconds a discovery sweep stays cached


/** Cached CF zone list for one account. Key cf_zones:{id}. */
function infra_cf_zones_key(array $account): string
{
    return 'cf_zones:' . ($account['id'] ?? md5((string) json_encode($account)));
}

function infra_discover_cf_zones(array $account, int $ttl = INFRA_DISCOVER_TTL): array
{
    $key = infra_cf_zones_key($account);
    $c = infra_cache_get($key, $ttl);
    if ($c !== null) return $c;
    $zones = cf_list_zones($account);
    infra_cache_put($key, $zones);
    return $zones;
}

/**
 * "Do these credentials work?" — from the last answer, not a fresh call.
 *
 * cf_probe() is a live request, and the Cloudflare tab ran one PER ACCOUNT on every
 * page load, so simply opening the tab cost a round trip before anything rendered.
 *
 * Never-checked is reported as its own state rather than as failure. A page that
 * says "credentials rejected" because nobody has asked yet invents a problem out of
 * an absence of information — the same rule the server cards already follow.
 *
 * @return array{ok:bool,never:bool,error:string}
 */
function infra_cf_probe_cached(array $account): array
{
    $key = 'cf_probe:' . ($account['id'] ?? md5((string) json_encode($account)));

    if (infra_cache_fresh()) {                 // the Refresh button, and only that
        $p = cf_probe($account);
        infra_cache_put($key, ['ok' => !empty($p['ok']), 'error' => (string) ($p['error'] ?? $p['message'] ?? '')]);
        return ['ok' => !empty($p['ok']), 'never' => false, 'error' => (string) ($p['error'] ?? $p['message'] ?? '')];
    }

    $wasFresh = infra_cache_fresh();
    if ($wasFresh) infra_cache_force(false);
    $c = infra_cache_get($key, PHP_INT_MAX);
    if ($wasFresh) infra_cache_force(true);

    return $c === null
        ? ['ok' => false, 'never' => true,  'error' => '']
        : ['ok' => !empty($c['ok']), 'never' => false, 'error' => (string) ($c['error'] ?? '')];
}

/**
 * The last zone list that was fetched — instant, and never touches the network.
 *
 * What PAGE LOADS must use. The TTL version above goes live the moment its answer
 * is three minutes old, and nobody comes back inside three minutes, so in practice
 * every visit to the Cloudflare tab paid for a live sweep and sat there while it
 * ran. Same lesson as the Servers tab, which was 63 seconds a visit for exactly
 * this reason: a cache tuned for a loop is not a cache for a person.
 *
 * Going live is now something you ask for (the Refresh button), not something a
 * click costs you by accident.
 *
 * @return array zones, or [] if this account has never been swept
 */
function infra_cf_zones_cached(array $account): array
{
    // Ignore any ?refresh=1 force for THIS read: the caller that wants live data
    // says so by calling infra_discover_cf_zones() itself.
    $wasFresh = infra_cache_fresh();
    if ($wasFresh) infra_cache_force(false);
    $c = infra_cache_get(infra_cf_zones_key($account), PHP_INT_MAX);
    if ($wasFresh) infra_cache_force(true);
    return $c ?? [];
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
        // Stored answer by default; live only when a caller has asked for it with
        // infra_cache_force() — the Refresh button, and the go-live cron, which
        // must see a zone flip to active.
        $zones = infra_cache_fresh() ? infra_discover_cf_zones($a, 0) : infra_cf_zones_cached($a);
        foreach ($zones as $z) {
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

/**
 * domain(lower) => {server_id,server_label,www_root,hosting_type} across ALL boxes.
 *
 * This is what tells the Domains tab which machine a domain actually lives on —
 * read from the boxes themselves, not from stored state, so it reports what IS
 * rather than what was intended. Reads the Hestia fleet; it used to read the
 * Plesk registry, which after the migration would have found nothing and quietly
 * reported every provisioned domain as living nowhere.
 *
 */
function infra_host_domain_index(): array
{
    require_once __DIR__ . '/hestia_fleet.php';
    $idx = [];
    foreach (infra_hestia_servers() as $s) {
        // ⚠ THIS IS THE ONE THAT COST 64 SECONDS. It runs once per box, and with the
        // TTL version it went to the network for every box whose stored answer was
        // over three minutes old — which is every visit, because nobody comes back
        // inside three minutes. D.Buy calls this on page load through
        // infra_fleet_domains(), so the most-clicked tab in the console swept the
        // whole fleet before printing anything, and got slower with every box added.
        // Stored answer by default; live only when a caller sets infra_cache_force().
        $disc = infra_cache_fresh() ? infra_discover_hestia($s, 0) : infra_hestia_cached($s);
        if (!$disc || empty($disc['ok'])) continue;
        foreach ($disc['sites'] as $d) {
            $name = strtolower($d['name'] ?? '');
            if ($name === '') continue;
            $idx[$name] = [
                'server_id'    => $s['id'] ?? '',
                'server_label' => $s['label'] ?? ($s['id'] ?? ''),
                // Hestia names it docroot; the table column is www_root.
                'www_root'     => $d['docroot'] ?? '',
                'hosting_type' => $d['ssl'] ?? '',
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
 * How old the discovered half of the fleet picture is, in seconds.
 *
 * The OLDEST of the two sources, and null when either has never been read: a page
 * is only as current as its stalest input, and averaging that away would let a
 * screen call itself fresh while half of it was a week old.
 */
function infra_fleet_data_age(): ?int
{
    require_once __DIR__ . '/hestia_fleet.php';
    $oldest = null;
    foreach (infra_hestia_servers() as $s) {
        if (!hestia_server_configured($s)) continue;   // no keys: never going to be asked
        $t = infra_cache_age('hestia:' . ($s['id'] ?? ''));
        if ($t === null) return null;
        $oldest = $oldest === null ? $t : max($oldest, $t);
    }
    foreach (infra_cf_accounts() as $a) {
        $t = infra_cache_age(infra_cf_zones_key($a));
        if ($t === null) return null;
        $oldest = $oldest === null ? $t : max($oldest, $t);
    }
    return $oldest;
}

/**
 * Reconciled domain rows joining all three systems.
 * @return array of {domain, host|null, cf|null, registrar, state, drift|null}
 */
function infra_fleet_domains(): array
{
    $hosts = infra_host_domain_index();
    $cf    = infra_cf_zone_index();
    $reg   = infra_registrar_map();
    $stored = infra_state_all_domains();   // domain(lower) => stored record

    $names = array_values(array_unique(array_merge(
        array_keys($hosts), array_keys($cf), array_keys($reg), array_keys($stored)
    )));
    sort($names);

    $rows = [];
    foreach ($names as $n) {
        $p  = $hosts[$n]  ?? null;
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
        elseif ($p)                                       $state = 'staged'; // host only
        elseif ($st && !empty($st['status']))             $state = $st['status']; // stored
        elseif ($st)                                      $state = 'begin';  // tracked but statusless
        else                                              $state = 'unknown';

        $rows[] = [
            'domain'    => $n,
            'host'      => $p,
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
