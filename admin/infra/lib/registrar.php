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

/**
 * Point a domain's nameservers at Cloudflare.
 * @return array{ok:bool, manual:bool, message:string}
 */
function infra_registrar_set_ns(string $domain, array $ns, string $registrarName): array
{
    $cfg  = infra_registrar_config($registrarName);
    $type = strtolower($cfg['type'] ?? $registrarName);

    switch ($type) {
        case 'namesilo':
            return infra_reg_namesilo_set_ns($domain, $ns, $cfg);
        case 'porkbun':
            return infra_reg_porkbun_set_ns($domain, $ns, $cfg);
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
