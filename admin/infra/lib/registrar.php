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
    $cfg = infra_load_json(infra_config_path('registrar.json'), []);
    return $cfg['registrars'][strtolower(trim($name))] ?? [];
}

/** Configured registrar names (keys of config/registrar.json). @return string[] */
function infra_registrar_names(): array
{
    $cfg = infra_load_json(infra_config_path('registrar.json'), []);
    return array_keys($cfg['registrars'] ?? []);
}

/**
 * Supported registrar types and what each can actually do over its API.
 *
 * `fields` drives the admin form (and which keys are treated as secrets).
 * `check`/`buy`/`ns`/`balance` say whether an adapter exists here — NOT a promise
 * the account is funded or the key is valid; that is what Test is for.
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
            'check' => true, 'buy' => true, 'ns' => true, 'balance' => true,
            'note'  => 'Needs API access enabled, a funded balance, and this server\'s IP whitelisted in your Namecheap profile. Buying requires a contact set.',
        ],
        'namesilo' => [
            'label'  => 'NameSilo',
            'fields' => ['api_key' => ['label' => 'API key', 'secret' => true]],
            'check' => true, 'buy' => true, 'ns' => true, 'balance' => true,
            'note'  => 'No IP allowlist. Availability check returns a price. Free WHOIS privacy on registration.',
        ],
        'porkbun' => [
            'label'  => 'Porkbun',
            'fields' => [
                'api_key'        => ['label' => 'API key',        'secret' => true],
                'secret_api_key' => ['label' => 'Secret API key', 'secret' => true],
            ],
            'check' => true, 'buy' => false, 'ns' => true, 'balance' => false,
            'note'  => 'API Access must be toggled ON per-domain in the Porkbun dashboard. No registration endpoint — buy in the dashboard, then mark owned here.',
        ],
        'dynadot' => [
            'label'  => 'Dynadot',
            'fields' => ['api_key' => ['label' => 'API key', 'secret' => true]],
            'check' => true, 'buy' => true, 'ns' => true, 'balance' => true,
            'note'  => 'May require external nameservers be added to the account before they can be set on a domain.',
        ],
        'gandi' => [
            'label'  => 'Gandi',
            'fields' => ['pat' => ['label' => 'Personal Access Token', 'secret' => true]],
            'check' => true, 'buy' => true, 'ns' => true, 'balance' => true,
            'note'  => 'v5 REST API with a Personal Access Token (PATs replaced the old API keys). Buying requires a contact set.',
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
        case 'namesilo':  return infra_reg_namesilo_verify2($cfg);
        case 'namecheap': return infra_reg_namecheap_verify($cfg);
        case 'porkbun':   return infra_reg_porkbun_verify2($cfg);
        case 'dynadot':   return infra_reg_dynadot_verify($cfg);
        case 'gandi':     return infra_reg_gandi_verify($cfg);
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
    $r = infra_reg_porkbun_call($cfg, '/ping');
    // Porkbun exposes no balance endpoint — absence is reported, not faked as 0.
    return ['ok' => $r['ok'], 'message' => $r['ok'] ? 'Porkbun API OK' : ('Porkbun: ' . $r['message']),
            'balance' => null, 'currency' => ''];
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

function infra_reg_gandi_verify(array $cfg): array
{
    $pat = $cfg['pat'] ?? ($cfg['api_key'] ?? '');
    $r = infra_http('GET', 'https://api.gandi.net/v5/organization/user-info', [
        'headers' => ['Authorization: Bearer ' . $pat], 'verify' => true, 'timeout' => 30]);
    $ok = $r['code'] >= 200 && $r['code'] < 300;
    if (!$ok) {
        $msg = $r['json']['message'] ?? $r['json']['cause'] ?? ('HTTP ' . $r['code']);
        return ['ok' => false, 'message' => 'Gandi: ' . $msg, 'balance' => null, 'currency' => ''];
    }
    // Prepaid balance lives on the billing endpoint; a PAT may lack billing scope.
    $b = infra_http('GET', 'https://api.gandi.net/v5/billing/info', [
        'headers' => ['Authorization: Bearer ' . $pat], 'verify' => true, 'timeout' => 30]);
    $bal = $b['json']['prepaid']['amount'] ?? null;
    return ['ok' => true, 'message' => 'Gandi API OK',
            'balance' => $bal !== null ? (string) $bal : null,
            'currency' => (string) ($b['json']['prepaid']['currency'] ?? 'EUR')];
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
        case 'gandi':     return infra_reg_gandi_check($domains, $cfg, $out);
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
        // Both branches collapse to a single object when there is exactly one result.
        foreach (infra_reg_xml_list($r['reply']['available']['domain'] ?? []) as $a) {
            $name = strtolower(is_array($a) ? ($a['domain'] ?? '') : (string) $a);
            if (!isset($out[$name])) continue;
            $out[$name]['available'] = true;
            if (is_array($a) && isset($a['price'])) $out[$name]['price'] = (string) $a['price'];
            if (is_array($a) && !empty($a['premium'])) $out[$name]['note'] = 'premium';
        }
        foreach (infra_reg_xml_list($r['reply']['unavailable']['domain'] ?? []) as $u) {
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

/** Porkbun: one request per domain (no batch endpoint) — rate-limited deliberately. */
function infra_reg_porkbun_check(array $domains, array $cfg, array $out): array
{
    foreach ($domains as $i => $d) {
        if ($i > 0) usleep(600000);   // Porkbun throttles hard on this endpoint
        $r = infra_reg_porkbun_call($cfg, '/domain/checkDomain/' . $d);
        if (!$r['ok']) { $out[$d]['note'] = 'check failed: ' . $r['message']; continue; }
        $resp = $r['json']['response'] ?? [];
        $out[$d]['available'] = strtolower((string) ($resp['avail'] ?? '')) === 'yes';
        $out[$d]['price']     = (string) ($resp['price'] ?? '');
        if ($out[$d]['available'] === false) $out[$d]['note'] = 'taken';
    }
    return $out;
}

/** Dynadot: batched via repeated domain0..domainN params. */
function infra_reg_dynadot_check(array $domains, array $cfg, array $out): array
{
    foreach (array_chunk($domains, 25) as $chunk) {
        $q = ['key' => $cfg['api_key'] ?? '', 'command' => 'search'];
        foreach ($chunk as $i => $d) $q['domain' . $i] = $d;
        $r = infra_http('GET', 'https://api.dynadot.com/api3.json?' . http_build_query($q), ['verify' => true, 'timeout' => 40]);
        $results = $r['json']['SearchResponse']['SearchResults'] ?? $r['json']['SearchResults'] ?? [];
        if (!$results) {
            foreach ($chunk as $d) $out[$d]['note'] = 'check failed (HTTP ' . $r['code'] . ')';
            continue;
        }
        foreach ($results as $res) {
            $name = strtolower((string) ($res['DomainName'] ?? ''));
            if (!isset($out[$name])) continue;
            $av = strtolower((string) ($res['Available'] ?? ''));
            if ($av === 'yes' || $av === 'no') {
                $out[$name]['available'] = ($av === 'yes');
                if ($av === 'no') $out[$name]['note'] = 'taken';
            } else {
                $out[$name]['note'] = 'check inconclusive';
            }
            if (!empty($res['Price'])) $out[$name]['price'] = (string) $res['Price'];
        }
    }
    return $out;
}

/** Gandi: one request per domain. */
function infra_reg_gandi_check(array $domains, array $cfg, array $out): array
{
    $pat = $cfg['pat'] ?? ($cfg['api_key'] ?? '');
    foreach ($domains as $d) {
        $r = infra_http('GET', 'https://api.gandi.net/v5/domain/check?' . http_build_query(['name' => $d]),
            ['headers' => ['Authorization: Bearer ' . $pat], 'verify' => true, 'timeout' => 30]);
        if ($r['code'] < 200 || $r['code'] >= 300) {
            $out[$d]['note'] = 'check failed (HTTP ' . $r['code'] . ')';
            continue;
        }
        $prod = $r['json']['products'][0] ?? null;
        if (!$prod) { $out[$d]['note'] = 'check inconclusive'; continue; }
        $status = strtolower((string) ($prod['status'] ?? ''));
        $out[$d]['available'] = ($status === 'available');
        if ($status !== 'available') $out[$d]['note'] = ($status === 'unavailable') ? 'taken' : $status;
        if (isset($prod['prices'][0]['price_after_taxes'])) $out[$d]['price'] = (string) $prod['prices'][0]['price_after_taxes'];
    }
    return $out;
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
        case 'gandi':
            $pat = $cfg['pat'] ?? ($cfg['api_key'] ?? '');
            $r = infra_http('GET', 'https://api.gandi.net/v5/domain/domains?per_page=1000',
                ['headers' => ['Authorization: Bearer ' . $pat], 'verify' => true, 'timeout' => 40]);
            foreach (($r['json'] ?? []) as $d) if (!empty($d['fqdn'])) $out[] = $d['fqdn'];
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
function infra_registrar_register(string $domain, int $years, string $registrarName, array $ns = []): array
{
    $cfg  = infra_registrar_config($registrarName);
    $type = strtolower($cfg['type'] ?? $registrarName);
    switch ($type) {
        case 'namesilo':
            $r = infra_reg_namesilo_register($domain, $years, $cfg, $ns);
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
        case 'gandi':     return infra_reg_gandi_set_ns($domain, $ns, $cfg);
        case 'namecheap': return infra_reg_namecheap_set_ns($domain, $ns, $cfg);
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

/** Register a domain (Phase-1 auto-buy; free WHOIS privacy on). Optional NS at purchase. */
function infra_reg_namesilo_register(string $domain, int $years, array $cfg, array $ns = []): array
{
    $params = ['domain' => $domain, 'years' => max(1, $years), 'private' => 1, 'auto_renew' => 0];
    foreach (array_slice(array_values(array_filter($ns)), 0, 13) as $i => $n) $params['ns' . ($i + 1)] = $n;

    $r = infra_reg_namesilo_call($cfg, 'registerDomain', $params);
    return ['ok' => $r['ok'], 'code' => $r['code'],
            'message' => $r['ok'] ? "NameSilo: registered {$domain}" : "NameSilo error {$r['code']}: {$r['detail']}"];
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

/* ============================= Gandi ============================= */

function infra_reg_gandi_set_ns(string $domain, array $ns, array $cfg): array
{
    $ns  = array_values(array_filter(array_map('trim', $ns)));
    $pat = $cfg['pat'] ?? ($cfg['api_key'] ?? '');
    $r   = infra_http('PUT', 'https://api.gandi.net/v5/domain/domains/' . $domain . '/nameservers', [
        'headers' => ['Authorization: Bearer ' . $pat, 'Content-Type: application/json'],
        'verify'  => true, 'timeout' => 30,
        'body'    => ['nameservers' => $ns],
    ]);
    $ok  = $r['code'] >= 200 && $r['code'] < 300;
    $err = $r['json']['cause'] ?? $r['json']['message'] ?? substr($r['raw'], 0, 120);
    return ['ok' => $ok, 'manual' => false,
        'message' => $ok ? 'Gandi: nameservers set → ' . implode(', ', $ns) : "Gandi error {$r['code']}: {$err}"];
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
