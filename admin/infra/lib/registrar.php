<?php
/**
 * infra/lib/registrar.php — nameserver switching at the registrar (Phase-3 go-live).
 * Pluggable per registrar type. NameSilo is wired; others fall back to MANUAL.
 * Config: config/registrar.json = { "registrars": { "namesilo": {"type":"namesilo","api_key":"…"} } }
 * A domain's stored `registrar` name is matched (lowercased) to a config key.
 */
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/http.php';

function infra_registrar_config(string $name): array
{
    $name = strtolower(trim($name));
    $cfg  = infra_load_json(infra_config_path('registrar.json'), []);
    $row  = $cfg['registrars'][$name] ?? [];

    // Cloudflare falls back to the account registry ONLY when nothing has been
    // saved on the Registrars page — so it works before you fill the form in, and
    // once you do, what you typed is exactly what is used.
    if ($name === 'cloudflare' && !array_filter(array_diff_key($row, ['type' => 1]))) {
        $acct = infra_cf_accounts()[0] ?? [];
        if ($acct) return $acct + ['type' => 'cloudflare'];
    }
    return $row;
}

/**
 * Registrars available to assign. Keys of config/registrar.json, plus Cloudflare
 * whenever a Cloudflare account exists — it is a real registrar you can hold
 * domains at, even though its credentials live on the Cloudflare side.
 * @return string[]
 */
function infra_registrar_names(): array
{
    $cfg   = infra_load_json(infra_config_path('registrar.json'), []);
    $names = array_keys($cfg['registrars'] ?? []);
    if (!in_array('cloudflare', $names, true) && infra_cf_accounts()) $names[] = 'cloudflare';
    return $names;
}

/**
 * Supported registrar types and what each can actually do over its API.
 *
 * `fields` drives the admin form (and which keys are treated as secrets).
 *
 * Two SEPARATE buy flags, because conflating them would let the UI promise
 * something the code cannot deliver:
 *   `buy`       — the registrar's API is capable of registering a domain.
 *                 Drives planning: which registrars may be assigned / spread.
 *   `buy_wired` — a purchase adapter is implemented HERE. Only NameSilo today;
 *                 the rest land in pass two. The UI says so rather than implying
 *                 a scheduled buy will complete.
 * Neither is a promise the account is funded or the key valid — that is Test.
 *
 * Only types listed here can be added. Spaceship was dropped deliberately: it is
 * Namecheap-owned, so it adds cost without adding nameserver independence.
 */
function infra_registrar_types(): array
{
    return [
        'namecheap' => [
            'label'  => 'Namecheap',
            'fields' => [
                'api_user'  => ['label' => 'API user',  'secret' => false],
                'api_key'   => ['label' => 'API key',   'secret' => true],
                'username'  => ['label' => 'Username',  'secret' => false],
                'client_ip' => ['label' => 'Whitelisted IP', 'secret' => false, 'default' => '187.127.254.206'],
            ],
            'check' => true, 'buy' => true, 'buy_wired' => false, 'ns' => true, 'balance' => true,
            'note'  => 'Needs API access enabled, a funded balance, and this server\'s IP whitelisted in your Namecheap profile. Buying requires a contact set.',
        ],
        'namesilo' => [
            'label'  => 'NameSilo',
            'fields' => ['api_key' => ['label' => 'API key', 'secret' => true]],
            'check' => true, 'buy' => true, 'buy_wired' => true, 'ns' => true, 'balance' => true,
            'note'  => 'No IP allowlist. Availability check returns a price. Free WHOIS privacy on registration. The only registrar whose purchase adapter is written today.',
        ],
        'porkbun' => [
            'label'  => 'Porkbun',
            'fields' => [
                'api_key'        => ['label' => 'API key',        'secret' => true],
                'secret_api_key' => ['label' => 'Secret API key', 'secret' => true],
            ],
            'check' => true, 'buy' => false, 'buy_wired' => false, 'ns' => true, 'balance' => false,
            'note'  => 'API Access must be toggled ON per-domain in the Porkbun dashboard. No registration endpoint at all — buy in the dashboard, then mark owned here. No balance endpoint. Availability is limited to ONE CHECK PER 10 SECONDS, so use NameSilo or Dynadot for bulk lists and keep Porkbun for spot checks.',
        ],
        'dynadot' => [
            'label'  => 'Dynadot',
            'fields' => ['api_key' => ['label' => 'API key', 'secret' => true]],
            'check' => true, 'buy' => true, 'buy_wired' => true, 'ns' => true, 'balance' => true,
            'note'  => 'Cheapest .com of the five. Registration uses the account\'s default contact, so no contact set is needed. May require external nameservers be added to the account before they can be set on a domain.',
        ],
        'cloudflare' => [
            'label'  => 'Cloudflare Registrar',
            // Credentials are entered here like every other registrar. They are the
            // same values as the Cloudflare account registry, so the form PREFILLS
            // the non-secret ones from it — but what is saved here is what is used,
            // with no hidden fallback to reason about.
            'fields' => [
                'account_id' => ['label' => 'Account ID', 'secret' => false,
                                 'hint' => 'Cloudflare dashboard → any domain → Overview, right-hand column'],
                'api_token'  => ['label' => 'API token', 'secret' => true, 'optional' => true,
                                 'hint' => 'needs Account → Domain Registration: Read'],
                'email'      => ['label' => 'Email (global-key auth)', 'secret' => false, 'optional' => true],
                'global_key' => ['label' => 'Global API key', 'secret' => true, 'optional' => true,
                                 'hint' => 'alternative to the token — used when email + global key are both set'],
            ],
            'prefill_from_cf' => ['account_id', 'email'],
            'check' => false, 'buy' => false, 'buy_wired' => false, 'ns' => false, 'balance' => false,
            'note'  => 'Sells at cost, which makes it the cheapest place to hold a domain — but its API only LISTS and updates domains, so registering has to happen in the Cloudflare dashboard. It also has no availability endpoint, and a Cloudflare-registered domain is always on Cloudflare nameservers, so there is nothing to switch at go-live. Verified live: the list endpoint works.',
        ],
    ];
}

/** Resolve a configured registrar name to its type definition. */
function infra_registrar_type_def(string $name): array
{
    $cfg  = infra_registrar_config($name);
    $type = strtolower($cfg['type'] ?? $name);
    return infra_registrar_types()[$type] ?? [];
}

/** Configured registrars that can complete a purchase over the API. @return string[] */
function infra_registrar_buyable(): array
{
    $out = [];
    foreach (infra_registrar_names() as $n) {
        if (!empty(infra_registrar_type_def($n)['buy'])) $out[] = $n;
    }
    return $out;
}

/* ============================= VERIFY + BALANCE ============================= */

/**
 * Credential test for the admin UI: does the key work, and what is the balance?
 * Read-only for every registrar. Balance is the number that decides whether a
 * scheduled buy can actually complete, so it is reported alongside reachability.
 * @return array{ok:bool, message:string, balance:?string, currency:string}
 */
function infra_registrar_verify(string $name): array
{
    $cfg  = infra_registrar_config($name);
    $type = strtolower($cfg['type'] ?? $name);
    if (!$cfg) return ['ok' => false, 'message' => 'no credentials saved', 'balance' => null, 'currency' => ''];

    switch ($type) {
        case 'namesilo':   return infra_reg_namesilo_verify2($cfg);
        case 'namecheap':  return infra_reg_namecheap_verify($cfg);
        case 'porkbun':    return infra_reg_porkbun_verify2($cfg);
        case 'dynadot':    return infra_reg_dynadot_verify($cfg);
        case 'cloudflare': return infra_reg_cloudflare_verify($cfg);
        default:          return ['ok' => false, 'message' => "no test adapter for type '{$type}'", 'balance' => null, 'currency' => ''];
    }
}

function infra_reg_namesilo_verify2(array $cfg): array
{
    $r = infra_reg_namesilo_call($cfg, 'getAccountBalance');
    if (!$r['ok']) return ['ok' => false, 'message' => "NameSilo error {$r['code']}: {$r['detail']}", 'balance' => null, 'currency' => ''];
    return ['ok' => true, 'message' => 'NameSilo API OK', 'balance' => (string) ($r['reply']['balance'] ?? ''), 'currency' => 'USD'];
}

function infra_reg_namecheap_verify(array $cfg): array
{
    $r = infra_reg_namecheap_call($cfg, 'namecheap.users.getBalances');
    if (!$r['ok']) return ['ok' => false, 'message' => 'Namecheap: ' . $r['message'], 'balance' => null, 'currency' => ''];
    $b = $r['xml']->CommandResponse->UserGetBalancesResult ?? null;
    return ['ok' => true, 'message' => 'Namecheap API OK',
            'balance' => $b ? (string) $b['AvailableBalance'] : '', 'currency' => $b ? (string) $b['Currency'] : ''];
}

function infra_reg_porkbun_verify2(array $cfg): array
{
    // NOT /ping — that endpoint answers SUCCESS without checking credentials at
    // all (verified: empty keys still return SUCCESS), so it would report a
    // working connection for a typo'd key. listAll actually requires auth.
    $r = infra_reg_porkbun_call($cfg, '/domain/listAll', ['start' => 0]);
    if (!$r['ok']) {
        return ['ok' => false, 'message' => 'Porkbun: ' . $r['message'], 'balance' => null, 'currency' => ''];
    }
    $n = count($r['json']['domains'] ?? []);
    // Porkbun exposes no balance endpoint — absence is reported, not faked as 0.
    return ['ok' => true, 'message' => "Porkbun API OK — {$n} domain(s) held", 'balance' => null, 'currency' => ''];
}

function infra_reg_dynadot_verify(array $cfg): array
{
    $r = infra_http('GET', 'https://api.dynadot.com/api3.json?' . http_build_query(
        ['key' => $cfg['api_key'] ?? '', 'command' => 'account_info']), ['verify' => true, 'timeout' => 30]);
    $j    = $r['json'] ?? [];
    $resp = $j['AccountInfoResponse'] ?? $j['Response'] ?? $j;
    $stat = strtolower((string) ($resp['ResponseCode'] ?? $resp['Status'] ?? ''));
    $ok   = $r['code'] >= 200 && $r['code'] < 300 && ($stat === '0' || $stat === 'success');
    $bal  = $resp['AccountInfo']['Balance'] ?? $resp['Balance'] ?? null;
    return ['ok' => $ok,
            'message' => $ok ? 'Dynadot API OK' : ('Dynadot: ' . (($resp['Error'] ?? '') ?: ('HTTP ' . $r['code']))),
            'balance' => $bal !== null ? (string) $bal : null, 'currency' => 'USD'];
}


/** Shared Namecheap XML call. @return array{ok:bool,message:string,xml:?SimpleXMLElement} */
function infra_reg_namecheap_call(array $cfg, string $command, array $params = []): array
{
    $q = array_merge([
        'ApiUser'  => $cfg['api_user'] ?? '',
        'ApiKey'   => $cfg['api_key'] ?? '',
        'UserName' => $cfg['username'] ?? ($cfg['api_user'] ?? ''),
        'ClientIp' => $cfg['client_ip'] ?? '',
        'Command'  => $command,
    ], $params);
    $r   = infra_http('GET', 'https://api.namecheap.com/xml.response?' . http_build_query($q), ['verify' => true, 'timeout' => 40]);
    $xml = @simplexml_load_string($r['raw']);
    if ($xml === false) {
        return ['ok' => false, 'message' => 'unparseable response (HTTP ' . $r['code'] . ')', 'xml' => null];
    }
    if ((string) $xml['Status'] !== 'OK') {
        $err = (string) ($xml->Errors->Error ?? 'error');
        // The single most common Namecheap failure — name it explicitly.
        if (stripos($err, 'ip') !== false) $err .= ' (is ' . ($cfg['client_ip'] ?? '?') . ' whitelisted in your Namecheap API settings?)';
        return ['ok' => false, 'message' => $err, 'xml' => $xml];
    }
    return ['ok' => true, 'message' => '', 'xml' => $xml];
}

/* ============================= AVAILABILITY ============================= */

/**
 * Is each domain registrable? Batched where the API allows it, because 400 names
 * must not become 400 requests.
 *
 * Never guesses: a domain whose check failed comes back 'unknown', not 'taken',
 * so a network blip can never mark a good name as dead.
 *
 * @param  string[] $domains
 * @return array domain => {available:?bool, price:string, note:string}
 */
function infra_registrar_check_availability(array $domains, string $registrarName): array
{
    $domains = array_values(array_unique(array_filter(array_map(
        fn($d) => strtolower(trim((string) $d)), $domains))));
    if (!$domains) return [];

    $cfg  = infra_registrar_config($registrarName);
    $type = strtolower($cfg['type'] ?? $registrarName);
    $out  = [];
    foreach ($domains as $d) $out[$d] = ['available' => null, 'price' => '', 'note' => ''];

    switch ($type) {
        case 'namesilo':  return infra_reg_namesilo_check($domains, $cfg, $out);
        case 'namecheap': return infra_reg_namecheap_check($domains, $cfg, $out);
        case 'porkbun':   return infra_reg_porkbun_check($domains, $cfg, $out);
        case 'dynadot':   return infra_reg_dynadot_check($domains, $cfg, $out);
        case 'cloudflare':
            foreach ($out as $d => $_) $out[$d]['note'] = 'Cloudflare has no availability API — check with another registrar';
            return $out;
        default:
            foreach ($out as $d => $_) $out[$d]['note'] = "no availability adapter for '{$registrarName}'";
            return $out;
    }
}

/** NameSilo: batched, and the only one that returns a price. */
function infra_reg_namesilo_check(array $domains, array $cfg, array $out): array
{
    foreach (array_chunk($domains, 50) as $chunk) {
        $r = infra_reg_namesilo_call($cfg, 'checkRegisterAvailability', ['domains' => implode(',', $chunk)]);
        if (!$r['ok']) {
            foreach ($chunk as $d) $out[$d]['note'] = "check failed: {$r['detail']}";
            continue;
        }
        // NameSilo returns TWO DIFFERENT SHAPES and the difference is easy to miss:
        //   one result   → reply.available.domain = {…}      (wrapped in "domain")
        //   many results → reply.available        = [{…},{…}] (no wrapper at all)
        // Reading only the wrapped form made every batch of 2+ return nothing —
        // which failed safe (verdict left unchanged) but meant bulk checking, the
        // entire reason this is batched, never actually worked.
        foreach (infra_reg_ns_results($r['reply']['available'] ?? []) as $a) {
            $name = strtolower(is_array($a) ? ($a['domain'] ?? '') : (string) $a);
            if (!isset($out[$name])) continue;
            $out[$name]['available'] = true;
            if (is_array($a) && isset($a['price'])) $out[$name]['price'] = (string) $a['price'];
            if (is_array($a) && !empty($a['premium'])) $out[$name]['note'] = 'premium';
        }
        foreach (infra_reg_ns_results($r['reply']['unavailable'] ?? []) as $u) {
            $name = strtolower(is_array($u) ? ($u['domain'] ?? '') : (string) $u);
            if (isset($out[$name])) { $out[$name]['available'] = false; $out[$name]['note'] = 'taken'; }
        }
    }
    return $out;
}

/** Namecheap: DomainList takes a comma list; no price for non-premium names. */
function infra_reg_namecheap_check(array $domains, array $cfg, array $out): array
{
    foreach (array_chunk($domains, 40) as $chunk) {
        $r = infra_reg_namecheap_call($cfg, 'namecheap.domains.check', ['DomainList' => implode(',', $chunk)]);
        if (!$r['ok']) {
            foreach ($chunk as $d) $out[$d]['note'] = 'check failed: ' . $r['message'];
            continue;
        }
        foreach ($r['xml']->CommandResponse->DomainCheckResult as $d) {
            $name = strtolower((string) $d['Domain']);
            if (!isset($out[$name])) continue;
            $avail = strtolower((string) $d['Available']) === 'true';
            $out[$name]['available'] = $avail;
            if (strtolower((string) $d['IsPremiumName']) === 'true') {
                $out[$name]['note']  = 'premium';
                $out[$name]['price'] = (string) $d['PremiumRegistrationPrice'];
            } elseif (!$avail) {
                $out[$name]['note'] = 'taken';
            }
        }
    }
    return $out;
}

/**
 * Porkbun: one request per domain, and it allows only ONE CHECK PER 10 SECONDS.
 * The response says so itself ("1 out of 1 checks within 10 seconds used", with
 * ttlRemaining) — an earlier 0.6s spacing sailed straight through the limit and
 * every domain after the first came back unchecked.
 *
 * That makes Porkbun unsuitable for bulk availability: 400 domains would take
 * over an hour. Use NameSilo (batched) or Dynadot for lists; this is for spot
 * checks. It honours the limit and retries once on the server's own timing.
 */
function infra_reg_porkbun_check(array $domains, array $cfg, array $out): array
{
    $domains = array_values($domains);
    foreach ($domains as $i => $d) {
        if ($i > 0) sleep(10);
        $r = infra_reg_porkbun_call($cfg, '/domain/checkDomain/' . $d);

        // Rate-limited anyway? Wait exactly as long as it asks, then try once more.
        if (!$r['ok'] && strtoupper((string) ($r['json']['code'] ?? '')) === 'RATE_LIMIT_EXCEEDED') {
            sleep(max(1, min(30, (int) ($r['json']['ttlRemaining'] ?? 10) + 1)));
            $r = infra_reg_porkbun_call($cfg, '/domain/checkDomain/' . $d);
        }
        if (!$r['ok']) { $out[$d]['note'] = 'check failed: ' . $r['message']; continue; }

        $resp = $r['json']['response'] ?? [];
        $av   = strtolower((string) ($resp['avail'] ?? ''));
        if ($av !== 'yes' && $av !== 'no') { $out[$d]['note'] = 'check inconclusive'; continue; }
        $out[$d]['available'] = ($av === 'yes');
        $out[$d]['price']     = (string) ($resp['price'] ?? '');
        if ($av === 'no')                              $out[$d]['note'] = 'taken';
        elseif (strtolower((string) ($resp['premium'] ?? '')) === 'yes') $out[$d]['note'] = 'premium';
    }
    return $out;
}

/**
 * Dynadot: ONE DOMAIN PER REQUEST. Its search command rejects multiple domains
 * outright ("too many domains entered, please search one domain per command"),
 * so the batched version silently returned a verdict for nothing. Throttled,
 * because 400 domains here means 400 requests.
 */
function infra_reg_dynadot_check(array $domains, array $cfg, array $out): array
{
    foreach (array_values($domains) as $i => $d) {
        if ($i > 0) usleep(400000);
        $r = infra_reg_dynadot_call($cfg, 'search',
                 ['domain0' => $d, 'show_price' => 1, 'currency' => 'usd']);
        if (!$r['ok']) { $out[$d]['note'] = 'check failed: ' . $r['message']; continue; }

        $res = $r['data']['SearchResults'][0] ?? null;
        if (!$res) { $out[$d]['note'] = 'check inconclusive'; continue; }

        $av = strtolower((string) ($res['Available'] ?? ''));
        if ($av === 'yes' || $av === 'no') {
            $out[$d]['available'] = ($av === 'yes');
            if ($av === 'no') $out[$d]['note'] = 'taken';
        } else {
            $out[$d]['note'] = 'check inconclusive';
        }
        // Dynadot returns prose, not a number:
        // "Registration Price: 10.88 in USD and Renewal price: 10.88 in USD…"
        if (!empty($res['Price']) && preg_match('/Registration Price:\s*([\d.]+)/i', (string) $res['Price'], $pm)) {
            $out[$d]['price'] = $pm[1];
        }
    }
    return $out;
}

/**
 * One Dynadot api3 call. Their JSON nests the payload under a per-command key
 * ("RegisterResponse", "SetRenewOptionResponse", …) and sometimes just
 * "Response", so the envelope is unwrapped defensively rather than assumed.
 * @return array{ok:bool, code:string, message:string, data:array}
 */
function infra_reg_dynadot_call(array $cfg, string $command, array $params = []): array
{
    $q = array_merge(['key' => $cfg['api_key'] ?? '', 'command' => $command], $params);
    $r = infra_http('GET', 'https://api.dynadot.com/api3.json?' . http_build_query($q),
                    ['verify' => true, 'timeout' => 60]);   // registration is slow

    $j = $r['json'] ?? [];
    $resp = null;
    foreach ($j as $k => $v) {                       // first object-valued key is the envelope
        if (is_array($v) && (isset($v['ResponseCode']) || isset($v['Status']))) { $resp = $v; break; }
    }
    if ($resp === null) $resp = $j['Response'] ?? $j;

    $code = (string) ($resp['ResponseCode'] ?? '');
    $stat = strtolower((string) ($resp['Status'] ?? ''));
    $ok   = ($r['code'] >= 200 && $r['code'] < 300) && ($code === '0' || $stat === 'success');
    // Dynadot reports some failures ONLY in Status (e.g. "insufficient_funds")
    // with no Error field at all — without this the message was raw JSON.
    $err = $resp['Error'] ?? $resp['error'] ?? '';
    if ($err === '' && !$ok && $stat !== '') $err = str_replace('_', ' ', $stat);
    return [
        'ok'      => $ok,
        'code'    => $code,
        'message' => $ok ? 'ok' : ($err !== '' ? (string) $err : ('HTTP ' . $r['code'] . ' ' . substr($r['raw'], 0, 120))),
        'data'    => is_array($resp) ? $resp : [],
    ];
}

/** A domain's current renew setting at Dynadot, or null if it cannot be read. */
function infra_reg_dynadot_renew_option(array $cfg, string $domain): ?string
{
    $l = infra_reg_dynadot_call($cfg, 'list_domain');
    foreach (($l['data']['MainDomains'] ?? []) as $d) {
        if (strcasecmp((string) ($d['Name'] ?? ''), $domain) === 0) {
            return (string) ($d['RenewOption'] ?? '');
        }
    }
    return null;
}

/**
 * Register a domain at Dynadot. Uses the account's DEFAULT CONTACT — verified on
 * the account, so no contact set is required here (same as NameSilo).
 *
 * Auto-renew is applied as a SEPARATE call after purchase: Dynadot's register
 * command has no auto-renew parameter, and a domain that registered but silently
 * kept the default renew setting is exactly the kind of thing that only surfaces
 * a year later. A failure to set it is reported rather than swallowed.
 */
function infra_reg_dynadot_register(string $domain, int $years, array $cfg, array $opts = []): array
{
    $years = max(1, min(10, $years));
    $r = infra_reg_dynadot_call($cfg, 'register',
             ['domain' => $domain, 'duration' => $years, 'currency' => 'usd']);
    if (!$r['ok']) {
        return ['ok' => false, 'code' => $r['code'], 'message' => 'Dynadot: ' . $r['message']];
    }

    $msg = "Dynadot: registered {$domain} for {$years}yr";
    $autoRenew = array_key_exists('auto_renew', $opts) ? (bool) $opts['auto_renew'] : true;

    // A freshly-registered domain is not immediately queryable — the first
    // set_renew_option comes back "could not find domain in your account", so it
    // gets a moment and a second try.
    $ar = infra_reg_dynadot_call($cfg, 'set_renew_option',
              ['domain' => $domain, 'renew_option' => $autoRenew ? 'auto' : 'no']);
    if (!$ar['ok']) {
        sleep(3);
        $ar = infra_reg_dynadot_call($cfg, 'set_renew_option',
                  ['domain' => $domain, 'renew_option' => $autoRenew ? 'auto' : 'no']);
    }

    // REPORT WHAT IS TRUE, NOT WHAT WAS ATTEMPTED. The first purchase said
    // "auto-renew NOT SET" while the domain was sitting there on auto-renew —
    // the call had failed but the account default had already done it. Same
    // principle as plesk_delete_site(), which judges by whether the site is
    // actually gone rather than by the CLI's exit code.
    $actual = infra_reg_dynadot_renew_option($cfg, $domain);
    if ($actual !== null) {
        $isAuto = (stripos($actual, 'auto') !== false);
        $msg .= ', auto-renew ' . ($isAuto ? 'ON' : 'OFF') . ' (verified)';
        if ($isAuto !== $autoRenew) $msg .= ' — NOT what was asked for; change it in the dashboard';
    } else {
        $msg .= ', auto-renew ' . ($ar['ok'] ? 'set but UNVERIFIED' : 'COULD NOT BE SET (' . $ar['message'] . ') — check the dashboard');
    }
    return ['ok' => true, 'code' => $r['code'], 'message' => $msg];
}

/* ============================= Cloudflare Registrar ============================= */
/* Sells at cost, but the API only lists/updates — there is no register endpoint,
   and no availability endpoint. A Cloudflare-registered domain is always on
   Cloudflare nameservers, so go-live has nothing to switch. Verified live:
   GET /accounts/{id}/registrar/domains returns 200. */

/** Registrar-scoped Cloudflare call. Accepts a token OR the global-key pair. */
function infra_reg_cloudflare_call(array $cfg, string $method, string $path, array $query = []): array
{
    $headers = (!empty($cfg['email']) && !empty($cfg['global_key']))
        ? ['X-Auth-Email: ' . $cfg['email'], 'X-Auth-Key: ' . $cfg['global_key'], 'Content-Type: application/json']
        : ['Authorization: Bearer ' . ($cfg['api_token'] ?? ''), 'Content-Type: application/json'];
    $url = 'https://api.cloudflare.com/client/v4/accounts/' . rawurlencode($cfg['account_id'] ?? '') . $path;
    if ($query) $url .= '?' . http_build_query($query);
    return infra_http($method, $url, ['headers' => $headers, 'verify' => true, 'timeout' => 30]);
}

function infra_reg_cloudflare_verify(array $cfg): array
{
    if (($cfg['account_id'] ?? '') === '') {
        return ['ok' => false, 'message' => 'Account ID is required', 'balance' => null, 'currency' => ''];
    }
    $r  = infra_reg_cloudflare_call($cfg, 'GET', '/registrar/domains', ['per_page' => 5]);
    $ok = $r['code'] === 200 && !empty($r['json']['success']);
    if (!$ok) {
        return ['ok' => false, 'balance' => null, 'currency' => '',
                'message' => 'Cloudflare: ' . ($r['json']['errors'][0]['message'] ?? ('HTTP ' . $r['code']))];
    }
    $n = (int) ($r['json']['result_info']['total_count'] ?? count($r['json']['result'] ?? []));
    // Cloudflare bills a card rather than a prepaid balance, so there is none to report.
    return ['ok' => true, 'message' => "Cloudflare Registrar API OK — {$n} domain(s) held",
            'balance' => null, 'currency' => ''];
}

/**
 * Normalise a NameSilo availability branch to a flat list, whichever shape it
 * arrived in — the bare list (many results) or the {"domain": …} wrapper (one).
 */
function infra_reg_ns_results($branch): array
{
    if ($branch === null || $branch === '') return [];
    if (is_array($branch) && isset($branch['domain'])) $branch = $branch['domain'];
    return infra_reg_xml_list($branch);
}

/** Normalise "one result = object, many = array" API shapes into a list. */
function infra_reg_xml_list($v): array
{
    if ($v === null || $v === '') return [];
    if (is_string($v)) return [$v];
    if (is_array($v)) {
        // A single associative record (has string keys) vs a list of records.
        return array_keys($v) !== range(0, count($v) - 1) ? [$v] : $v;
    }
    return [];
}

/**
 * Domains already in one of OUR registrar accounts. Used to tell "taken by
 * someone else" apart from "you already own this" — Scott's other projects hold
 * hundreds of domains that would otherwise read as dead names.
 * @return array lowercase domain => registrar name
 */
function infra_registrar_owned_index(): array
{
    $idx = [];
    foreach (infra_registrar_names() as $name) {
        foreach (infra_registrar_list_owned($name) as $d) $idx[strtolower($d)] = $name;
    }
    return $idx;
}

/** Domains held at one registrar. Read-only; [] when unsupported/unconfigured. */
function infra_registrar_list_owned(string $name): array
{
    $cfg  = infra_registrar_config($name);
    $type = strtolower($cfg['type'] ?? $name);
    $out  = [];
    switch ($type) {
        case 'namesilo':
            $r = infra_reg_namesilo_call($cfg, 'listDomains');
            foreach (infra_reg_xml_list($r['reply']['domains']['domain'] ?? []) as $d) {
                $out[] = is_array($d) ? ($d['domain'] ?? '') : (string) $d;
            }
            break;
        case 'namecheap':
            for ($page = 1; $page <= 40; $page++) {
                $r = infra_reg_namecheap_call($cfg, 'namecheap.domains.getList', ['PageSize' => 100, 'Page' => $page]);
                if (!$r['ok']) break;
                $n = 0;
                foreach (($r['xml']->CommandResponse->DomainGetListResult->Domain ?? []) as $d) { $out[] = (string) $d['Name']; $n++; }
                $total = (int) ($r['xml']->CommandResponse->Paging->TotalItems ?? 0);
                if ($n === 0 || count($out) >= $total) break;
            }
            break;
        case 'cloudflare':
            $r = infra_reg_cloudflare_call($cfg, 'GET', '/registrar/domains', ['per_page' => 200]);
            foreach (($r['json']['result'] ?? []) as $d) if (!empty($d['name'])) $out[] = $d['name'];
            break;
        case 'porkbun':
            $r = infra_reg_porkbun_call($cfg, '/domain/listAll');
            foreach (($r['json']['domains'] ?? []) as $d) if (!empty($d['domain'])) $out[] = $d['domain'];
            break;
        case 'dynadot':
            $r = infra_http('GET', 'https://api.dynadot.com/api3.json?' . http_build_query(
                ['key' => $cfg['api_key'] ?? '', 'command' => 'list_domain']), ['verify' => true, 'timeout' => 40]);
            $list = $r['json']['ListDomainInfoResponse']['MainDomains'] ?? $r['json']['MainDomains'] ?? [];
            foreach ($list as $d) if (!empty($d['Name'])) $out[] = $d['Name'];
            break;
    }
    return array_values(array_filter(array_map('strval', $out)));
}

/**
 * Register (buy) a domain at the given registrar. Costs real money.
 * @return array{ok:bool, message:string}
 */
function infra_registrar_register(string $domain, int $years, string $registrarName, array $ns = [], array $opts = []): array
{
    $cfg  = infra_registrar_config($registrarName);
    $type = strtolower($cfg['type'] ?? $registrarName);
    switch ($type) {
        case 'namesilo':
            $r = infra_reg_namesilo_register($domain, $years, $cfg, $ns, $opts);
            return ['ok' => $r['ok'], 'message' => $r['message']];
        case 'dynadot':
            $r = infra_reg_dynadot_register($domain, $years, $cfg, $opts);
            return ['ok' => $r['ok'], 'message' => $r['message']];
        // porkbun/spaceship/dynadot/gandi registration can plug in here later
        default:
            return ['ok' => false, 'message' => "auto-registration not wired for '{$registrarName}' — register manually"];
    }
}

/**
 * Point a domain's nameservers at Cloudflare.
 * @return array{ok:bool, manual:bool, message:string}
 */
function infra_registrar_set_ns(string $domain, array $ns, string $registrarName): array
{
    $cfg  = infra_registrar_config($registrarName);
    $type = strtolower($cfg['type'] ?? $registrarName);

    switch ($type) {
        case 'namesilo':  return infra_reg_namesilo_set_ns($domain, $ns, $cfg);
        case 'porkbun':   return infra_reg_porkbun_set_ns($domain, $ns, $cfg);
        case 'spaceship': return infra_reg_spaceship_set_ns($domain, $ns, $cfg);
        case 'dynadot':   return infra_reg_dynadot_set_ns($domain, $ns, $cfg);
        case 'namecheap': return infra_reg_namecheap_set_ns($domain, $ns, $cfg);
        case 'cloudflare':
            // A Cloudflare-registered domain is always on Cloudflare nameservers.
            // There is nothing to switch, so go-live is a no-op rather than a failure.
            return ['ok' => true, 'manual' => false,
                    'message' => 'Cloudflare Registrar: already on Cloudflare nameservers — no switch needed'];
        default:
            return [
                'ok'      => false,
                'manual'  => true,
                'message' => 'manual — set nameservers at the registrar: ' . implode(', ', $ns),
            ];
    }
}

/* ============================= NameSilo ============================= */

/**
 * Low-level NameSilo API call. All ops are GET; success = reply.code "300".
 * @return array{ok:bool, code:string, detail:string, reply:array}
 */
function infra_reg_namesilo_call(array $cfg, string $op, array $params = []): array
{
    $key = $cfg['api_key'] ?? '';
    $q   = array_merge(['version' => 1, 'type' => 'json', 'key' => $key], $params);
    $url = 'https://www.namesilo.com/api/' . $op . '?' . http_build_query($q);
    $r   = infra_http('GET', $url, ['verify' => true, 'timeout' => 30]);

    $reply = $r['json']['reply'] ?? [];
    $code  = (string) ($reply['code'] ?? '');
    return [
        'ok'     => $code === '300',
        'code'   => $code,
        'detail' => $reply['detail'] ?? ($r['error'] ?: ('HTTP ' . $r['code'])),
        'reply'  => is_array($reply) ? $reply : [],
    ];
}

/** Verify the API key works (read-only listDomains). @return array{ok:bool,message:string} */
function infra_reg_namesilo_verify(array $cfg): array
{
    $r = infra_reg_namesilo_call($cfg, 'listDomains');
    return ['ok' => $r['ok'], 'message' => $r['ok'] ? 'NameSilo API OK' : "NameSilo error {$r['code']}: {$r['detail']}"];
}

/** Set a domain's nameservers (the go-live switch). NameSilo needs ≥2 NS. */
function infra_reg_namesilo_set_ns(string $domain, array $ns, array $cfg): array
{
    $ns = array_values(array_filter(array_map('trim', $ns)));
    if (count($ns) < 2) {
        return ['ok' => false, 'manual' => false, 'message' => 'NameSilo requires at least 2 nameservers'];
    }
    $params = ['domain' => $domain];
    foreach (array_slice($ns, 0, 13) as $i => $n) $params['ns' . ($i + 1)] = $n;

    $r = infra_reg_namesilo_call($cfg, 'changeNameServers', $params);
    return [
        'ok'      => $r['ok'],
        'manual'  => false,
        'message' => $r['ok']
            ? 'NameSilo: nameservers set → ' . implode(', ', $ns)
            : "NameSilo error {$r['code']}: {$r['detail']}",
    ];
}

/**
 * Register a domain (free WHOIS privacy on). Optional NS at purchase.
 *
 * AUTO-RENEW DEFAULTS ON. A lapsed domain in a rank-and-rent fleet is not a small
 * problem — the site dies and the ranking goes with it, and the name may not come
 * back. Off is available per-purchase, but it must be chosen deliberately.
 */
function infra_reg_namesilo_register(string $domain, int $years, array $cfg, array $ns = [], array $opts = []): array
{
    $autoRenew = array_key_exists('auto_renew', $opts) ? (int) (bool) $opts['auto_renew'] : 1;
    $params = ['domain' => $domain, 'years' => max(1, $years), 'private' => 1, 'auto_renew' => $autoRenew];
    foreach (array_slice(array_values(array_filter($ns)), 0, 13) as $i => $n) $params['ns' . ($i + 1)] = $n;

    $r = infra_reg_namesilo_call($cfg, 'registerDomain', $params);
    return ['ok' => $r['ok'], 'code' => $r['code'],
            'message' => $r['ok']
                ? "NameSilo: registered {$domain} for {$params['years']}yr, auto-renew "
                  . ($autoRenew ? 'ON' : 'OFF') . ', WHOIS privacy on'
                : "NameSilo error {$r['code']}: {$r['detail']}"];
}

/**
 * Turn auto-renew on/off for a domain already registered. Needed both to correct
 * anything bought before auto-renew defaulted on, and to change one's mind later.
 * @return array{ok:bool, message:string}
 */
function infra_registrar_set_autorenew(string $domain, bool $on, string $registrarName): array
{
    $cfg  = infra_registrar_config($registrarName);
    $type = strtolower($cfg['type'] ?? $registrarName);
    switch ($type) {
        case 'namesilo':
            $r = infra_reg_namesilo_call($cfg, $on ? 'addAutoRenewal' : 'removeAutoRenewal', ['domain' => $domain]);
            return ['ok' => $r['ok'],
                    'message' => $r['ok'] ? "NameSilo: auto-renew " . ($on ? 'ON' : 'OFF') . " for {$domain}"
                                          : "NameSilo error {$r['code']}: {$r['detail']}"];
        case 'dynadot':
            $r = infra_reg_dynadot_call($cfg, 'set_renew_option',
                     ['domain' => $domain, 'renew_option' => $on ? 'auto' : 'no']);
            return ['ok' => $r['ok'],
                    'message' => $r['ok'] ? 'Dynadot: auto-renew ' . ($on ? 'ON' : 'OFF') . " for {$domain}"
                                          : 'Dynadot: ' . $r['message']];
        default:
            return ['ok' => false, 'message' => "auto-renew toggling not wired for '{$registrarName}' — set it in their dashboard"];
    }
}

/* ============================= Porkbun ============================= */
/* NOTE: Porkbun requires "API Access" toggled ON per-domain in the dashboard. */

/** Low-level Porkbun call; success = json.status "SUCCESS". @return array{ok:bool,message:string,json:mixed} */
function infra_reg_porkbun_call(array $cfg, string $path, array $body = []): array
{
    $b = array_merge(['apikey' => $cfg['api_key'] ?? '', 'secretapikey' => $cfg['secret_api_key'] ?? ''], $body);
    $r = infra_http('POST', 'https://api.porkbun.com/api/json/v3' . $path, ['verify' => true, 'timeout' => 30, 'body' => $b]);
    $status = $r['json']['status'] ?? '';
    return ['ok' => $status === 'SUCCESS', 'message' => $r['json']['message'] ?? ($r['error'] ?: ('HTTP ' . $r['code'])), 'json' => $r['json']];
}

/** Verify Porkbun credentials (read-only /ping). @return array{ok:bool,message:string} */
function infra_reg_porkbun_verify(array $cfg): array
{
    $r = infra_reg_porkbun_call($cfg, '/ping');
    return ['ok' => $r['ok'], 'message' => $r['ok'] ? 'Porkbun API OK' : ('Porkbun: ' . $r['message'])];
}

/** Set a domain's nameservers (the go-live switch). */
function infra_reg_porkbun_set_ns(string $domain, array $ns, array $cfg): array
{
    $ns = array_values(array_filter(array_map('trim', $ns)));
    if (count($ns) < 2) {
        return ['ok' => false, 'manual' => false, 'message' => 'Porkbun requires at least 2 nameservers'];
    }
    $r = infra_reg_porkbun_call($cfg, '/domain/updateNs/' . $domain, ['ns' => $ns]);
    return [
        'ok'      => $r['ok'],
        'manual'  => false,
        'message' => $r['ok']
            ? 'Porkbun: nameservers set → ' . implode(', ', $ns)
            : ('Porkbun error: ' . $r['message'] . ' (is "API Access" enabled for this domain?)'),
    ];
}

/* ============================= Spaceship ============================= */

function infra_reg_spaceship_set_ns(string $domain, array $ns, array $cfg): array
{
    $ns = array_values(array_filter(array_map('trim', $ns)));
    $r  = infra_http('PUT', 'https://spaceship.dev/api/v1/domains/' . $domain . '/nameservers', [
        'headers' => [
            'X-Api-Key: ' . ($cfg['api_key'] ?? ''),
            'X-Api-Secret: ' . ($cfg['api_secret'] ?? ''),
            'Content-Type: application/json',
        ],
        'verify'  => true, 'timeout' => 30,
        'body'    => ['provider' => 'custom', 'hosts' => $ns],
    ]);
    $ok  = $r['code'] >= 200 && $r['code'] < 300;
    $err = $r['json']['detail'] ?? $r['json']['message'] ?? substr($r['raw'], 0, 120);
    return ['ok' => $ok, 'manual' => false,
        'message' => $ok ? 'Spaceship: nameservers set → ' . implode(', ', $ns) : "Spaceship error {$r['code']}: {$err}"];
}

/* ============================= Dynadot ============================= */
/* NOTE: Dynadot may require external nameservers be added to the account first. */

function infra_reg_dynadot_set_ns(string $domain, array $ns, array $cfg): array
{
    $ns = array_values(array_filter(array_map('trim', $ns)));
    $q  = ['key' => $cfg['api_key'] ?? '', 'command' => 'set_ns', 'domain' => $domain];
    foreach (array_slice($ns, 0, 13) as $i => $n) $q['ns' . $i] = $n;   // ns0..ns12
    $r = infra_http('GET', 'https://api.dynadot.com/api3.json?' . http_build_query($q), ['verify' => true, 'timeout' => 30]);

    $j    = $r['json'] ?? [];
    $resp = $j['SetNsResponse'] ?? $j['Response'] ?? $j;
    $sc   = $resp['ResponseCode'] ?? $resp['SetNsHeader']['SuccessCode'] ?? null;
    $stat = strtolower((string) ($resp['Status'] ?? $resp['SetNsHeader']['Status'] ?? ''));
    $ok   = ($r['code'] >= 200 && $r['code'] < 300) && ($sc === 0 || $sc === '0' || $stat === 'success');
    $err  = $resp['Error'] ?? $resp['SetNsHeader']['Error'] ?? substr($r['raw'], 0, 120);
    return ['ok' => $ok, 'manual' => false,
        'message' => $ok ? 'Dynadot: nameservers set → ' . implode(', ', $ns) : "Dynadot error: {$err}"];
}



/* ============================= Namecheap ============================= */
/* Requires: funded account, API access enabled, and ClientIp whitelisted. */

function infra_reg_namecheap_set_ns(string $domain, array $ns, array $cfg): array
{
    $ns    = array_values(array_filter(array_map('trim', $ns)));
    $parts = explode('.', $domain, 2);
    $sld   = $parts[0];
    $tld   = $parts[1] ?? '';
    $q = [
        'ApiUser'     => $cfg['api_user'] ?? '',
        'ApiKey'      => $cfg['api_key'] ?? '',
        'UserName'    => $cfg['username'] ?? ($cfg['api_user'] ?? ''),
        'ClientIp'    => $cfg['client_ip'] ?? '',
        'Command'     => 'namecheap.domains.dns.setCustom',
        'SLD'         => $sld,
        'TLD'         => $tld,
        'NameServers' => implode(',', $ns),
    ];
    $r = infra_http('GET', 'https://api.namecheap.com/xml.response?' . http_build_query($q), ['verify' => true, 'timeout' => 30]);

    $ok = false; $msg = 'unparseable response';
    $xml = @simplexml_load_string($r['raw']);
    if ($xml !== false) {
        if ((string) $xml['Status'] === 'OK') { $ok = true; $msg = ''; }
        else { $msg = (string) ($xml->Errors->Error ?? 'error'); }
    }
    return ['ok' => $ok, 'manual' => false,
        'message' => $ok ? 'Namecheap: nameservers set → ' . implode(', ', $ns) : "Namecheap error: {$msg}"];
}
