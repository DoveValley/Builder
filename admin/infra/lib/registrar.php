<?php
/**
 * infra/lib/registrar.php — nameserver switching at the registrar (Phase-3 go-live).
 * Pluggable per registrar type; defaults to MANUAL (surfaces the NS to set by hand).
 * Config: config/registrar.json = { "registrars": { "<name-lower>": {"type":"porkbun","api_key":...} } }
 * API integrations (porkbun/namecheap/cloudflare) plug into the switch below later.
 */
require_once __DIR__ . '/store.php';

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
        // Future API integrations, e.g.:
        // case 'porkbun':   return infra_reg_porkbun_set_ns($domain, $ns, $cfg);
        // case 'namecheap': return infra_reg_namecheap_set_ns($domain, $ns, $cfg);
        default:
            return [
                'ok'      => false,
                'manual'  => true,
                'message' => 'manual — set nameservers at the registrar: ' . implode(', ', $ns),
            ];
    }
}
