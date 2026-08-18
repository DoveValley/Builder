<?php
/**
 * infra/lib/health.php — is the estate still correct?
 *
 * A DIFFERENT QUESTION from the go-live grid, and kept apart on purpose. The grid asks
 * "is this batch progressing" — a pipeline that ends, whose rows retire once they are
 * live. This asks "is what we already own still right", forever, across every domain.
 * Mixing them is how the grid ends up twenty columns wide.
 *
 * Three things it reads, none of which had anywhere to live before:
 *
 *   1. AUTO-RENEW AND EXPIRY, read back FROM THE REGISTRAR. `auto_renew` was only ever
 *      written at purchase time, parsed out of the buy message — so 44 domains sat at
 *      "unknown" simply because nobody had ever asked, and an expiry date was recorded
 *      nowhere at all. Every registrar here can be asked; six answer for the whole
 *      account in ONE call.
 *   2. NAMESERVER DELEGATION, from PUBLIC DNS rather than any registrar API. It needs
 *      no key, works for registrars with no API at all, and is the same answer the
 *      world gets — which is the answer that matters.
 *   3. ZONE CONFORMANCE — is there a proxied A record pointing at the right box, is SSL
 *      on Full, is HSTS on.
 *
 * Everything is on demand and stored. Nothing here runs on page load.
 */

require_once __DIR__ . '/state.php';
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/cache.php';
require_once __DIR__ . '/cloudflare.php';
require_once __DIR__ . '/registrar.php';
require_once __DIR__ . '/fleet.php';
// Self-contained on purpose: the console's bootstrap happens to load this already, but
// the cron and the CLI do not, and a lib that only works when something else loaded its
// dependencies is a lib that fails the first time it is used somewhere new.
require_once __DIR__ . '/hestia_fleet.php';

/* ─────────────────────── 1. auto-renew and expiry ─────────────────────── */

/**
 * Which registrars can be asked, and how much it costs to ask.
 *
 * 'account' — one request returns every domain in the account with its renewal state.
 * 'domain'  — one request PER DOMAIN. NameSilo's listDomains gives names and dates but
 *             not auto-renew; only getDomainInfo carries it.
 * 'none'    — no API at all. Saying so, with a link to their dashboard, is the honest
 *             answer; a button that cannot work is not.
 *
 * @return array<string,string> registrar => 'account' | 'domain' | 'none'
 */
function infra_health_readers(): array
{
    return [
        'porkbun'    => 'account',
        'gandi'      => 'account',
        'spaceship'  => 'account',
        'cloudflare' => 'account',
        'namecheap'  => 'account',
        'dynadot'    => 'account',
        'namesilo'   => 'domain',
        'cosmotown'  => 'none',
    ];
}

/** yes / no / unknown, from whatever shape a registrar chose to answer in. */
function infra_health_yn($v): string
{
    if (is_bool($v))            return $v ? 'yes' : 'no';
    if ($v === null || $v === '') return 'unknown';
    $s = strtolower(trim((string) $v));
    if (in_array($s, ['1', 'yes', 'true', 'on', 'auto-renew', 'auto'], true))    return 'yes';
    if (in_array($s, ['0', 'no', 'false', 'off', 'donot-renew', 'no-renew'], true)) return 'no';
    return 'unknown';
}

/** Anything date-ish (ISO, 'Y-m-d', Dynadot's milliseconds) to 'YYYY-MM-DD', or ''. */
function infra_health_date($v): string
{
    if ($v === null || $v === '') return '';
    if (is_numeric($v)) {
        // Dynadot answers in MILLISECONDS. Read as seconds it lands in 1970 and every
        // domain looks fifty years overdue.
        $t = (float) $v;
        if ($t > 100000000000) $t /= 1000;
        return date('Y-m-d', (int) $t);
    }
    $t = strtotime((string) $v);
    return $t ? date('Y-m-d', $t) : '';
}

/**
 * Ask ONE registrar about every domain it holds.
 *
 * @return array{ok:bool, msg:string, calls:int, domains:array<string,array{auto_renew:string,expires:string,locked:?bool}>}
 */
function infra_health_registrar_read(string $name): array
{
    $name = strtolower(trim($name));
    $mode = infra_health_readers()[$name] ?? 'none';
    $cfg  = infra_registrar_config($name);
    $out  = ['ok' => false, 'msg' => '', 'calls' => 0, 'domains' => []];

    if ($mode === 'none') { $out['msg'] = 'no API — read it in their dashboard'; return $out; }
    if (!$cfg)            { $out['msg'] = 'not configured'; return $out; }

    $add = function (string $dom, $renew, $exp, $locked = null) use (&$out) {
        $dom = strtolower(trim($dom));
        if ($dom === '') return;
        $out['domains'][$dom] = [
            'auto_renew' => infra_health_yn($renew),
            'expires'    => infra_health_date($exp),
            'locked'     => $locked === null ? null : (bool) $locked,
        ];
    };

    switch ($name) {

        case 'porkbun':
            // listAll is paged at 1000 and carries autoRenew, expireDate and the lock.
            $r = infra_reg_porkbun_call($cfg, '/domain/listAll', ['start' => 0, 'includeLabels' => 'no']);
            $out['calls'] = 1;
            if (empty($r['ok'])) { $out['msg'] = $r['message'] ?? 'listAll failed'; return $out; }
            // ['json']['domains'] — NOT ['response'], which is what the availability
            // helper beside it returns. Reading the wrong one answers 0 domains with
            // ok=true, which looks like an empty account rather than a wrong key.
            foreach ((array) ($r['json']['domains'] ?? []) as $d) {
                $add($d['domain'] ?? '', $d['autoRenew'] ?? null, $d['expireDate'] ?? '', !empty($d['securityLock']));
            }
            break;

        case 'gandi':
            $r = infra_reg_gandi_call($cfg, 'GET', '/v5/domain/domains?per_page=1000');
            $out['calls'] = 1;
            if (empty($r['ok'])) { $out['msg'] = $r['message'] ?? 'domain list failed'; return $out; }
            foreach ((array) ($r['json'] ?? []) as $d) {
                $add($d['fqdn'] ?? '', $d['autorenew'] ?? null, $d['dates']['registry_ends_at'] ?? '',
                     in_array('clientTransferProhibited', (array) ($d['status'] ?? []), true));
            }
            break;

        case 'spaceship':
            $r = infra_reg_spaceship_call($cfg, 'GET', '/domains?take=100&skip=0');
            $out['calls'] = 1;
            if (empty($r['ok'])) { $out['msg'] = $r['message'] ?? 'domain list failed'; return $out; }
            foreach ((array) ($r['json']['items'] ?? $r['json'] ?? []) as $d) {
                if (!is_array($d)) continue;
                $add($d['name'] ?? '', $d['autoRenew'] ?? null, $d['expirationDate'] ?? '',
                     in_array('clientTransferProhibited', (array) ($d['eppStatuses'] ?? []), true));
            }
            break;

        case 'cloudflare':
            // The account id is already in the path this helper builds — passing it
            // again produces "No route for that URI", which reads like a permissions
            // problem and is not.
            // Paged at 50 — Cloudflare answers "Per page too big" to anything larger,
            // as an HTTP 400 that reads like a permissions problem and is not.
            //
            // ⚠ AND THE PAGE NUMBER IS 0-INDEXED, unlike every other Cloudflare
            // endpoint. Starting at 1 asks for the SECOND page and gets 200 OK with an
            // empty result — 31 domains read as an empty account, with no error to
            // explain it. This console has been caught by exactly this once before.
            for ($page = 0; $page < 40; $page++) {
                $r = infra_reg_cloudflare_call($cfg, 'GET', '/registrar/domains', ['per_page' => 50, 'page' => $page]);
                $out['calls']++;
                if (($r['code'] ?? 0) !== 200) {
                    if ($page === 1) { $out['msg'] = $r['json']['errors'][0]['message'] ?? ('HTTP ' . ($r['code'] ?? 0)); return $out; }
                    break;
                }
                $list = (array) ($r['json']['result'] ?? []);
                foreach ($list as $d) {
                    $add($d['name'] ?? '', $d['auto_renew'] ?? null, $d['expires_at'] ?? '', $d['locked'] ?? null);
                }
                if (count($list) < 50) break;
            }
            break;

        case 'namecheap':
            // Paged at 100. Namecheap's own API cannot SET auto-renew, but it reports
            // it perfectly well — which is exactly why reading it back matters here.
            for ($page = 1; $page <= 20; $page++) {
                $l = infra_reg_namecheap_call($cfg, 'namecheap.domains.getList', ['PageSize' => 100, 'Page' => $page]);
                $out['calls']++;
                if (empty($l['ok'])) { if ($page === 1) { $out['msg'] = $l['message'] ?? 'getList failed'; return $out; } break; }
                $list = $l['xml']->CommandResponse->DomainGetListResult->Domain ?? [];
                $n = 0;
                foreach ($list as $d) {
                    $add((string) $d['Name'], (string) $d['AutoRenew'], (string) $d['Expires']);
                    $n++;
                }
                if ($n < 100) break;
            }
            break;

        case 'dynadot':
            $l = infra_reg_dynadot_call($cfg, 'list_domain');
            $out['calls'] = 1;
            foreach ((array) ($l['data']['MainDomains'] ?? []) as $d) {
                $add((string) ($d['Name'] ?? ''), (string) ($d['RenewOption'] ?? ''),
                     $d['Expiration'] ?? '', strtolower((string) ($d['Locked'] ?? '')) === 'yes');
            }
            if (!$out['domains']) { $out['msg'] = 'list_domain returned nothing'; return $out; }
            break;

        case 'namesilo':
            // ONE CALL PER DOMAIN — listDomains carries the dates but not auto-renew,
            // so only the domains we actually hold here are asked, not the account.
            foreach (infra_state_all_domains() as $dom => $rec) {
                if (($rec['owned'] ?? '') !== 'yes') continue;
                if (strtolower((string) ($rec['registrar'] ?? '')) !== 'namesilo') continue;
                $r = infra_reg_namesilo_call($cfg, 'getDomainInfo', ['domain' => $dom]);
                $out['calls']++;
                if (empty($r['ok'])) continue;
                $d = $r['reply'] ?? [];
                $add($dom, $d['auto_renew'] ?? null, $d['expires'] ?? '',
                     strtolower((string) ($d['locked'] ?? '')) === 'yes');
            }
            break;
    }

    $out['ok']  = true;
    $out['msg'] = count($out['domains']) . ' domain(s) read in ' . $out['calls'] . ' call(s)';
    return $out;
}

/**
 * Read a registrar and WRITE what it said into fleet state.
 *
 * Only domains we already track are touched: the account may hold names that are
 * nothing to do with this fleet, and inventing rows for them would quietly turn a
 * personal domain into a fleet asset.
 *
 * @return array{ok:bool, msg:string, read:int, updated:int, changed:array<string,string>}
 */
function infra_health_registrar_sync(string $name): array
{
    $r   = infra_health_registrar_read($name);
    $out = ['ok' => $r['ok'], 'msg' => $r['msg'], 'read' => count($r['domains']), 'updated' => 0, 'changed' => []];
    if (!$r['ok']) return $out;

    foreach ($r['domains'] as $dom => $info) {
        $rec = infra_state_get_domain($dom);
        if (!$rec) continue;
        $was = (string) ($rec['auto_renew'] ?? '');
        $now = $info['auto_renew'];
        $fields = ['domain' => $dom, 'auto_renew' => $now];
        if ($info['expires'] !== '') $fields['expires_at'] = $info['expires'];
        if ($was === $now && (string) ($rec['expires_at'] ?? '') === ($info['expires'] ?? '')) continue;
        infra_state_upsert_domain($fields);
        $out['updated']++;
        // Only a CHANGE of answer is worth reporting. "36 unknown are still unknown"
        // is noise; "3 you believed were on are off" is the whole point.
        if ($was !== $now) $out['changed'][$dom] = ($was ?: 'unknown') . ' → ' . $now;
    }
    return $out;
}

/* ─────────────────────── 2. nameserver delegation ─────────────────────── */

/**
 * What the WORLD sees as this domain's nameservers.
 *
 * Public DNS, not a registrar API: it needs no key, it works for registrars that have
 * no API at all, and it is the same answer a visitor's resolver gets — which is the
 * only one that decides where traffic goes. A registrar reporting the right pair while
 * public DNS reports another is precisely the drift worth catching.
 *
 * @return array{ns:string[], at:int, error:string}
 */
function infra_health_ns_run(string $domain): array
{
    $domain = strtolower(trim($domain));
    $res = ['ns' => [], 'at' => time(), 'error' => ''];
    $rows = @dns_get_record($domain, DNS_NS);
    if ($rows === false) {
        $res['error'] = 'lookup failed';
    } else {
        foreach ($rows as $r) if (!empty($r['target'])) $res['ns'][] = strtolower((string) $r['target']);
        sort($res['ns']);
        if (!$res['ns']) $res['error'] = 'no NS records — the domain does not resolve';
    }
    infra_cache_put('ns:' . $domain, $res);
    return $res;
}

/** The last public-DNS answer for a domain, or null if never looked up. */
function infra_health_ns_cached(string $domain, int $ttl = 86400): ?array
{
    return infra_cache_get('ns:' . strtolower(trim($domain)), $ttl);
}

/**
 * Compare what the world sees with what Cloudflare expects.
 *
 * @return array{state:string, note:string} state: ok | drift | elsewhere | none | unknown
 */
function infra_health_ns_verdict(array $rec, ?array $zone, ?array $seen): array
{
    if ($seen === null)               return ['state' => 'unknown',   'note' => 'not looked up yet'];
    if (!empty($seen['error']))       return ['state' => 'none',      'note' => $seen['error']];

    $have = array_map(fn($n) => rtrim($n, '.'), $seen['ns']);
    $want = array_map(fn($n) => rtrim(strtolower($n), '.'), (array) ($zone['name_servers'] ?? []));
    if (!$want) {
        $want = array_filter(array_map('trim', explode(',', (string) ($rec['nameservers'] ?? ''))));
        $want = array_map(fn($n) => rtrim(strtolower($n), '.'), $want);
    }
    // Looked up, resolves, but we have no zone and no recorded pair to compare against:
    // it is sitting wherever the registrar put it. That is a real and useful state —
    // reporting it as "unknown" alongside domains nobody has ever queried threw 136
    // answered questions into the same bucket as the unasked ones.
    if (!$want) return ['state' => 'parked', 'note' => 'at the registrar: ' . implode(', ', $have)];

    sort($have); sort($want);
    if ($have === $want)                            return ['state' => 'ok',    'note' => implode(', ', $have)];
    if (array_intersect($have, $want))              return ['state' => 'drift', 'note' => 'partly switched: ' . implode(', ', $have)];
    return ['state' => 'elsewhere', 'note' => 'points at ' . implode(', ', $have) . ' — expected ' . implode(', ', $want)];
}

/* ─────────────────────── 3. zone conformance ─────────────────────── */

/**
 * Everything worth knowing about one zone, in two calls: its records, and its settings.
 *
 * /zones/{id}/settings returns EVERY setting in one response, so SSL mode and HSTS cost
 * one request between them rather than one each.
 *
 * @return array{a:array, ssl:string, hsts:bool, at:int}
 */
function infra_health_zone_run(array $account, string $zoneId): array
{
    $recs = infra_zone_contents_run($account, $zoneId);
    $set  = cf_get_settings($account, $zoneId);
    $res  = ['a' => $recs['a'] ?? [], 'ssl' => (string) ($set['ssl'] ?? ''),
             'hsts' => !empty($set['hsts']), 'at' => time()];
    infra_cache_put('zonehealth:' . $zoneId, $res);
    return $res;
}

/** The last zone inspection, or null. */
function infra_health_zone_cached(string $zoneId, int $ttl = 86400): ?array
{
    return infra_cache_get('zonehealth:' . $zoneId, $ttl);
}

/**
 * Does the zone do what the architecture assumes — proxied A record, on the right box,
 * SSL Full, HSTS on?
 *
 * @return array{state:string, note:string} state: ok | warn | none | unknown
 */
function infra_health_zone_verdict(array $rec, ?array $zh, ?array $box): array
{
    if ($zh === null) return ['state' => 'unknown', 'note' => 'not inspected yet'];

    $dom  = strtolower((string) $rec['domain']);
    $apex = null;
    foreach ($zh['a'] as $a) if (strtolower($a['name']) === $dom) { $apex = $a; break; }

    $bad = [];
    if (!$apex) {
        // 'empty', NOT 'none'. A zone that exists and contains nothing is a job half
        // done and the actionable half of the estate; a domain with no zone at all has
        // not been started. Reporting both as "no zone / no record" put 31 fixable
        // things in the same bucket as 174 that were never begun.
        return ['state' => 'empty', 'note' => 'zone exists but holds no A record for the apex — it resolves nowhere'];
    }
    if (empty($apex['proxied'])) $bad[] = 'NOT proxied (origin exposed, no CDN)';
    $want = $box ? trim((string) ($box['default_ip'] ?? $box['host'] ?? '')) : '';
    if ($want !== '' && $apex['ip'] !== $want) $bad[] = 'points at ' . $apex['ip'] . ', box is ' . $want;
    if (strtolower($zh['ssl']) !== 'full' && strtolower($zh['ssl']) !== 'strict') $bad[] = 'SSL is ' . ($zh['ssl'] ?: '?') . ', not Full';
    if (empty($zh['hsts'])) $bad[] = 'HSTS off';

    return $bad ? ['state' => 'warn', 'note' => implode(' · ', $bad)]
                : ['state' => 'ok',   'note' => 'proxied → ' . $apex['ip'] . ' · SSL ' . $zh['ssl'] . ' · HSTS on'];
}

/* ─────────────────────────────── the rows ─────────────────────────────── */

/**
 * Every owned domain with everything this tab knows about it. Reads STORED answers
 * only — the buttons are what go and look.
 *
 * @return array<int,array>
 */
function infra_health_rows(): array
{
    $zones = infra_cf_zone_index();
    $boxes = [];
    foreach (infra_hestia_servers() as $s) $boxes[(string) ($s['id'] ?? '')] = $s;

    $out = [];
    foreach (infra_state_all_domains() as $dom => $rec) {
        if (($rec['owned'] ?? '') !== 'yes') continue;
        $z  = $zones[$dom] ?? null;
        $zh = $z ? infra_health_zone_cached((string) $z['zone_id']) : null;
        $out[] = [
            'domain'    => $dom,
            'rec'       => $rec,
            'zone'      => $z,
            'ns'        => infra_health_ns_verdict($rec, $z, infra_health_ns_cached($dom)),
            'zone_v'    => $z ? infra_health_zone_verdict($rec, $zh, $boxes[(string) ($rec['server_id'] ?? '')] ?? null)
                              : ['state' => 'none', 'note' => 'no Cloudflare zone'],
        ];
    }
    return $out;
}

/**
 * The renewal picture, per registrar: how many owned domains, how many confirmed on,
 * how many off, how many nobody has asked about.
 *
 * @return array<string,array{total:int,yes:int,no:int,unknown:int,soonest:string,reader:string}>
 */
function infra_health_renewal_summary(): array
{
    $readers = infra_health_readers();
    $out = [];
    foreach (infra_state_all_domains() as $dom => $rec) {
        if (($rec['owned'] ?? '') !== 'yes') continue;
        $reg = strtolower(trim((string) ($rec['registrar'] ?? ''))) ?: '(none recorded)';
        $out[$reg] ??= ['total' => 0, 'yes' => 0, 'no' => 0, 'unknown' => 0, 'soonest' => '',
                        'reader' => $readers[$reg] ?? 'none'];
        $out[$reg]['total']++;
        $ar = strtolower(trim((string) ($rec['auto_renew'] ?? ''))) ?: 'unknown';
        $out[$reg][in_array($ar, ['yes', 'no'], true) ? $ar : 'unknown']++;
        $exp = (string) ($rec['expires_at'] ?? '');
        if ($exp !== '' && ($out[$reg]['soonest'] === '' || $exp < $out[$reg]['soonest'])) $out[$reg]['soonest'] = $exp;
    }
    // Worst first: the registrars with unconfirmed renewals are the reason to be here.
    uasort($out, fn($a, $b) => [$b['no'] + $b['unknown'], $b['total']] <=> [$a['no'] + $a['unknown'], $a['total']]);
    return $out;
}
