<?php
/**
 * infra/lib/hestia_fleet.php — registry + cached discovery for HestiaCP boxes.
 *
 * This began as a trial half, running beside a Plesk one so the two could be
 * compared on live data. Hestia won and the other half was deleted; this is now the
 * only fleet reader. Its own config file (hestia.json) and cache prefix (hestia:)
 * are left as they are — renaming them would break every stored key for nothing.
 *
 * It reports what a sweep COST (calls + ms), which is what settled the comparison:
 * two panels' cards look identical, and the real difference is how much work it
 * takes to answer "what is on this box".
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
    $users = $probe['ok'] ? hestia_account_list($server) : [];
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
 * One discovery bundle, shaped into the row every consumer reads.
 *
 * Split out so the live sweep and the cached read below cannot produce two
 * slightly different shapes — the exact drift this file was written to end.
 *
 * @return array{server:array,id:string,label:string,host:string,ok:bool,
 *   pending:bool,never:bool,at:string,error:string,version:string,platform:string,
 *   hostname:string,sites:array,deployed:int,accounts:array,calls:int,ms:int}
 */
function infra_hestia_shape(array $srv, array $d): array
{
    $f = infra_hestia_facts($d['info'] ?? null);
    $deployed = 0;
    foreach ($d['sites'] ?? [] as $s) {
        if (!hestia_is_infra_vhost((string) ($s['name'] ?? ''), $srv)) $deployed++;
    }
    return [
        'server'   => $srv,
        'id'       => (string) ($srv['id'] ?? ''),
        'label'    => (string) ($srv['label'] ?? ($srv['id'] ?? '')),
        'host'     => (string) ($srv['host'] ?? ''),
        'ok'       => (bool) ($d['ok'] ?? false),
        'pending'  => !empty($d['unconfigured']),
        // Never asked is not the same fact as asked and got nothing, and only one
        // of them is a fault. A box with no stored answer has an empty 'at'.
        'never'    => ((string) ($d['at'] ?? '')) === '' && empty($d['unconfigured']),
        'at'       => (string) ($d['at'] ?? ''),
        'error'    => (string) ($d['error'] ?? ''),
        'version'  => $f['panel_version'],
        'platform' => $f['platform'],
        'hostname' => $f['hostname'],
        'sites'    => $d['sites'] ?? [],
        'deployed' => $deployed,
        'accounts' => infra_hestia_accounts($d, $srv),
        'calls'    => (int) ($d['calls'] ?? 0),
        'ms'       => (int) ($d['ms'] ?? 0),
    ];
}

/**
 * The whole fleet in one canonical shape — the answer to "what have I got".
 *
 * Every caller used to run the same loop (list the registry, discover each box,
 * dig the facts out) and each dug slightly differently: one counted the panel's
 * own hostname as a site, another did not. This is that loop, once.
 *
 * ⚠ This one GOES TO THE NETWORK: four calls per box, in series. On a twenty-box
 * fleet that is ~124 calls and a measured 64 seconds from cold. Do not put it on
 * a page load — use infra_hestia_fleet_cached() there and let the Refresh button
 * spend the time. This stays for callers that genuinely want live state now.
 *
 * @return array<int,array>
 */
function infra_hestia_fleet(int $ttl = INFRA_HESTIA_TTL): array
{
    $out = [];
    foreach (infra_hestia_servers() as $srv) {
        $out[] = infra_hestia_shape($srv, infra_discover_hestia($srv, $ttl));
    }
    return $out;
}

/**
 * The last answer about one box, at any age, without opening a connection.
 *
 * Returns null when the box has never been swept — which the caller must show as
 * "not checked yet", never as "down". Reaching a box and failing, and never having
 * asked, look identical if you flatten them, and only the first is a fault.
 *
 * Ignores the ?refresh=1 "fresh" flag for the same reason infra_hestia_content_cached()
 * does: that flag means "go and look", and this function's whole contract is that it
 * does not. The Refresh button now does the looking, one request per box.
 */
function infra_hestia_cached(array $server): ?array
{
    // No key pair — nothing was ever going to be asked, so there is a definite
    // answer without a cache entry.
    if (!hestia_server_configured($server)) {
        return ['ok' => false, 'error' => '', 'unconfigured' => true, 'info' => null,
                'sites' => [], 'users' => [], 'calls' => 0, 'ms' => 0, 'at' => date('c')];
    }
    $wasFresh = infra_cache_fresh();
    if ($wasFresh) infra_cache_force(false);
    $c = infra_cache_get('hestia:' . ($server['id'] ?? md5((string) json_encode($server))), PHP_INT_MAX);
    if ($wasFresh) infra_cache_force(true);
    return $c;
}

/**
 * The whole fleet from the last sweep — instant, and never touches the network.
 *
 * This is what page loads read. Rows carry 'never' (no sweep has ever stored an
 * answer for this box) and 'at' (when the stored answer was taken), so a screen can
 * say how old what it is showing is instead of presenting it as the present.
 *
 * @return array<int,array>
 */
function infra_hestia_fleet_cached(): array
{
    $blank = ['ok' => false, 'error' => '', 'info' => null, 'sites' => [],
              'users' => [], 'calls' => 0, 'ms' => 0, 'at' => ''];
    $out = [];
    foreach (infra_hestia_servers() as $srv) {
        $out[] = infra_hestia_shape($srv, infra_hestia_cached($srv) ?? $blank);
    }
    return $out;
}

/**
 * How stale is the fleet on screen? Age in seconds of the OLDEST stored answer,
 * or null if nothing has ever been swept.
 *
 * Oldest, not newest: "as of 4 minutes ago" has to be true of everything shown,
 * and a headline that quotes the freshest box would understate the rest.
 */
function infra_hestia_fleet_age(array $fleet): ?int
{
    $oldest = null;
    foreach ($fleet as $b) {
        if ($b['pending'] || $b['at'] === '') continue;   // nothing to be stale
        $t = strtotime($b['at']);
        if ($t === false) continue;
        $age = max(0, time() - $t);
        if ($oldest === null || $age > $oldest) $oldest = $age;
    }
    return $oldest;
}

/**
 * How many of a box's host areas actually contain a site, checked now and cached.
 *
 * ON DEMAND, not on page load: it costs one API call per host area, so a box with 50
 * sites would add 50 calls to every visit. Same rule as "check if they're up" — the
 * page shows the last answer and asks when you press.
 *
 * @return array{checked:int,with_files:int,empty:int,at:string}
 */
function infra_hestia_content_run(array $server): array
{
    $d = infra_discover_hestia($server, 0);
    $withFiles = 0; $empty = 0;
    foreach ($d['sites'] as $s) {
        $name = (string) ($s['name'] ?? '');
        if ($name === '' || hestia_is_infra_vhost($name, $server)) continue;
        $r = hestia_docroot_files($server, (string) ($s['user'] ?? ''), $name);
        $r['placeholder_only'] ? $empty++ : $withFiles++;
    }
    $res = ['checked' => $withFiles + $empty, 'with_files' => $withFiles, 'empty' => $empty, 'at' => date('c')];
    infra_cache_put('content:' . ($server['id'] ?? ''), $res);
    return $res;
}

/**
 * The last content check for a box, or null if it has never been asked.
 *
 * Deliberately ignores the ?refresh=1 "fresh" flag. That flag means "re-read the
 * boxes' live state", and it was blanking this column too — so pressing Refresh made
 * an answer you had explicitly asked for look like one you had never requested. This
 * is a measurement someone chose to take; a refresh does not un-choose it.
 */
function infra_hestia_content_cached(array $server, int $ttl = 86400): ?array
{
    $wasFresh = infra_cache_fresh();
    if ($wasFresh) infra_cache_force(false);
    $c = infra_cache_get('content:' . ($server['id'] ?? ''), $ttl);
    if ($wasFresh) infra_cache_force(true);
    return $c;
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
function infra_hestia_accounts(array $d, array $server = []): array
{
    // "Holds a site" must mean the same thing as the Websites count beside it, or the
    // card contradicts itself: a box with 0 websites was reporting an account WITH a
    // site, because that account's only vhost was the panel's own hostname. One rule
    // for what counts as a site, applied in both places.
    $owners = [];
    foreach ($d['sites'] ?? [] as $s) {
        $u = (string) ($s['user'] ?? '');
        if ($u === '') continue;
        if ($server && hestia_is_infra_vhost((string) ($s['name'] ?? ''), $server)) continue;
        $owners[$u] = true;
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
