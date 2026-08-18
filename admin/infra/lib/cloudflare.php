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
    // ASKS ABOUT THE ACCOUNT, not just the credential.
    //
    // This used to fetch /zones and call any 200 a pass — which tested the token and
    // nothing else. With a token scoped to all accounts that meant an account id of
    // thirty-two zeros probed "connected", and a mistyped id stayed invisible until a
    // zone was created in the wrong place. Measured: bogus id → /zones 200 (pass),
    // /accounts/{id} 403 "Invalid account identifier".
    //
    // Returning the account's NAME matters as much as the verdict: seeing which
    // account answered is what tells you the id you pasted is the one you meant.
    $acct = trim((string) ($account['account_id'] ?? ''));
    if ($acct === '') {
        return ['ok' => false, 'code' => 0, 'error' => 'no account id on this record'];
    }

    // Zones filtered BY ACCOUNT, in one call, using only the zone-read permission these
    // tokens are told to carry. Cloudflare validates the filter — a bad id comes back
    // 400 "account with given Tag doesn't exist" — so this proves the token AND the id
    // together.
    //
    // NOT /accounts/{id}, which looks like the obvious check and is not: reading an
    // account needs an account-level permission the recommended token does not have,
    // so it answers 403 for a perfectly good account and would have marked every
    // correctly-configured entry as rejected. (It works for a global key, which is why
    // it passed the first account tested and failed the second.)
    $r = cf_api($account, 'GET', '/zones', ['per_page' => 1, 'account.id' => $acct]);
    if (!($r['code'] === 200 && !empty($r['json']['success']))) {
        return ['ok' => false, 'state' => 'bad', 'name' => '', 'code' => $r['code'],
                'error' => $r['json']['errors'][0]['message'] ?? ('HTTP ' . $r['code'])];
    }

    // ── RECOGNISED IS NOT THE SAME AS USABLE ────────────────────────────────────
    //
    // The filter above only proves Cloudflare KNOWS this id. An account that exists and
    // that this credential simply may not touch answers 200 with an empty zone list —
    // identical, byte for byte, to a working account holding no zones yet. Only an id
    // that exists NOWHERE returns 400.
    //
    // Measured 2026-08-18: fourteen accounts probed "OK" this way and every one of them
    // answered `Permission denied` the moment a zone was actually created in it. The
    // console reported 20/20 healthy while 14 boxes could not have gone live — the same
    // "confident green over an unasked question" this function's own history is about.
    //
    // So ask the second question. GET /accounts lists what the credential may actually
    // use; if the id is not in its own list, it is not ours no matter what /zones said.
    $list = cf_list_accounts($account);
    if (!empty($list['ok']) && $list['accounts']) {
        foreach ($list['accounts'] as $x) {
            if (strcasecmp((string) $x['id'], $acct) === 0) {
                return ['ok' => true, 'state' => 'verified', 'name' => (string) $x['name'],
                        'code' => 200, 'error' => ''];
            }
        }
        return ['ok' => false, 'state' => 'foreign', 'name' => '', 'code' => 200,
                'error' => 'Cloudflare knows this account id, but this credential cannot use it — '
                         . 'it is not among the ' . count($list['accounts']) . ' account(s) the token can reach. '
                         . 'Zone creation here would fail with "Permission denied".'];
    }

    // The credential cannot enumerate its accounts (no Account Settings: Read), so the
    // question is unanswerable rather than answered. Say so instead of promoting a
    // silence to a pass — 'unverified' is deliberately NOT drawn as healthy.
    return ['ok' => true, 'state' => 'unverified', 'name' => '', 'code' => 200,
            'error' => 'the token cannot list its accounts, so access to this one could not be '
                     . 'confirmed — add "Account Settings: Read" to it to make this checkable'];
}

/**
 * The account's name at Cloudflare, or '' when this credential may not read it.
 *
 * Separate from cf_probe() and deliberately allowed to fail: reading an account needs a
 * permission a zone-scoped token does not have, and not knowing the name is no reason
 * to call a working account broken. Used where a human is looking — seeing WHICH
 * account answered is what confirms the id pasted is the one intended — and never on
 * the cached sweep, where it would double the calls for a label.
 */
function cf_account_name(array $account): string
{
    $acct = trim((string) ($account['account_id'] ?? ''));
    if ($acct === '') return '';
    $r = cf_api($account, 'GET', '/accounts/' . $acct);
    return $r['code'] === 200 ? (string) ($r['json']['result']['name'] ?? '') : '';
}

/**
 * Every Cloudflare account this credential can see.
 *
 * Belongs here rather than in the caller for the same reason every other Cloudflare
 * call does: one module owns the base URL, the auth headers, the error shape and the
 * envelope-unwrapping, so a second reader cannot drift from the first.
 *
 * A token is normally scoped to one account, but one user invited into several can list
 * them all — which is what makes binding twenty accounts a discovery rather than twenty
 * hand-typed ids, each of which is invisible when wrong.
 *
 * @return array{ok:bool, error:string, accounts:array<int,array{id:string,name:string}>}
 */
function cf_list_accounts(array $account): array
{
    $r = cf_api($account, 'GET', '/accounts', ['per_page' => 50]);
    if ($r['code'] !== 200 || empty($r['json']['success'])) {
        return ['ok' => false, 'accounts' => [],
                'error' => $r['json']['errors'][0]['message'] ?? ('HTTP ' . $r['code'])];
    }
    $out = [];
    foreach ((array) ($r['json']['result'] ?? []) as $a) {
        if (($a['id'] ?? '') === '') continue;
        $out[] = ['id' => (string) $a['id'], 'name' => (string) ($a['name'] ?? '')];
    }
    return ['ok' => true, 'error' => '', 'accounts' => $out];
}

/**
 * All zones IN THIS ACCOUNT (paginated). @return array of zone objects
 *
 * ⚠ THE account.id FILTER IS NOT OPTIONAL. GET /zones returns every zone the CREDENTIAL
 * can see, not every zone in the account it was fetched for. That distinction did not
 * exist while each account had its own token — one token, one account, same answer. It
 * appears the moment one token is scoped to all accounts, which is the arrangement that
 * makes twenty accounts manageable at all.
 *
 * Without it, a brand-new empty account reported the 31 zones belonging to a different
 * one: the box bound to it read "31/50 · 19 free", the estate totalled 62 zones held
 * across two accounts holding 31 between them, and the allocator would have believed
 * there was room where there was none and none where there was room. Every number on
 * the page was wrong in a way that looked entirely plausible.
 */
function cf_list_zones(array $account): array
{
    $zones = [];
    $page  = 1;
    // A record with no account id is a broken record; ask unfiltered rather than
    // silently returning nothing, and let the page's own checks show it up.
    $acct  = trim((string) ($account['account_id'] ?? ''));
    do {
        $q = ['per_page' => 50, 'page' => $page];
        if ($acct !== '') $q['account.id'] = $acct;
        $r = cf_api($account, 'GET', '/zones', $q);
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

/** Look up a single zone by exact name. @return array|null zone object */
function cf_get_zone(array $account, string $domain): ?array
{
    $r = cf_api($account, 'GET', '/zones', ['name' => strtolower($domain)]);
    return ($r['code'] === 200 && !empty($r['json']['result'][0])) ? $r['json']['result'][0] : null;
}

/** Create or update an A record (idempotent). @return array{ok:bool,message:string} */
function cf_upsert_a_record(array $account, string $zoneId, string $name, string $ip, bool $proxied = true): array
{
    $list = cf_api($account, 'GET', "/zones/{$zoneId}/dns_records", ['type' => 'A', 'name' => $name]);
    $existing = $list['json']['result'][0]['id'] ?? null;
    $body = ['type' => 'A', 'name' => $name, 'content' => $ip, 'proxied' => $proxied, 'ttl' => 1];
    $r = $existing
        ? cf_api($account, 'PUT',  "/zones/{$zoneId}/dns_records/{$existing}", [], $body)
        : cf_api($account, 'POST', "/zones/{$zoneId}/dns_records", [], $body);
    $ok = in_array($r['code'], [200, 201], true) && !empty($r['json']['success']);
    return ['ok' => $ok, 'message' => $ok ? ($existing ? 'updated' : 'created')
        : ($r['json']['errors'][0]['message'] ?? ('HTTP ' . $r['code']))];
}

/** Set SSL mode: off|flexible|full|strict. @return array{ok:bool,message:string} */
function cf_set_ssl_mode(array $account, string $zoneId, string $mode = 'full'): array
{
    $r  = cf_api($account, 'PATCH', "/zones/{$zoneId}/settings/ssl", [], ['value' => $mode]);
    $ok = $r['code'] === 200 && !empty($r['json']['success']);
    return ['ok' => $ok, 'message' => $ok ? "ssl={$mode}"
        : ($r['json']['errors'][0]['message'] ?? ('HTTP ' . $r['code']))];
}

/**
 * Read a zone's settings — every one of them, in a single request.
 *
 * The setters above have had no counterpart, so nothing could ever answer "is SSL
 * actually on Full, is HSTS actually on" — only "we once asked for it". /settings
 * returns the whole set at once, so checking two of them costs one call rather than
 * two, and a third check later costs nothing.
 *
 * @return array{ok:bool, ssl:string, hsts:bool, always_https:bool, raw:array}
 */
function cf_get_settings(array $account, string $zoneId): array
{
    $r   = cf_api($account, 'GET', "/zones/{$zoneId}/settings");
    $out = ['ok' => false, 'ssl' => '', 'hsts' => false, 'always_https' => false, 'raw' => []];
    if ($r['code'] !== 200 || empty($r['json']['success'])) return $out;

    foreach ((array) ($r['json']['result'] ?? []) as $s) {
        $id = (string) ($s['id'] ?? '');
        $out['raw'][$id] = $s['value'] ?? null;
        if ($id === 'ssl')                $out['ssl']  = (string) ($s['value'] ?? '');
        if ($id === 'security_header')    $out['hsts'] = !empty($s['value']['strict_transport_security']['enabled']);
        if ($id === 'always_use_https')   $out['always_https'] = ($s['value'] ?? '') === 'on';
    }
    $out['ok'] = true;
    return $out;
}

/** Enable HSTS at the edge (applies when the zone is proxied/live). @return array{ok:bool,message:string} */
function cf_set_hsts(array $account, string $zoneId, int $maxAge = 15552000): array
{
    $body = ['value' => ['strict_transport_security' => [
        'enabled' => true, 'max_age' => $maxAge, 'include_subdomains' => false, 'preload' => false, 'nosniff' => true,
    ]]];
    $r  = cf_api($account, 'PATCH', "/zones/{$zoneId}/settings/security_header", [], $body);
    $ok = $r['code'] === 200 && !empty($r['json']['success']);
    return ['ok' => $ok, 'message' => $ok ? 'hsts on'
        : ($r['json']['errors'][0]['message'] ?? ('HTTP ' . $r['code']))];
}
