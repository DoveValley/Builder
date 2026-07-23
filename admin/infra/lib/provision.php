<?php
/**
 * infra/lib/provision.php — the shared per-domain provisioning routine used by
 * both the single "New Site" action and the bulk runner. Creates the Plesk site
 * + fully stages the Cloudflare zone, then persists the record to fleet state.
 */
require_once __DIR__ . '/fleet.php';   // pulls store/plesk/cloudflare/state + infra_registrar_map()

function infra_valid_domain(string $d): bool
{
    return (bool) preg_match('/^(?=.{1,253}$)([a-z0-9](-?[a-z0-9])*\.)+[a-z]{2,}$/', strtolower(trim($d)));
}

/**
 * Provision one domain end-to-end (idempotent, staged-only), persist to state.
 * @return array{ok:bool, lines:string[]}
 */
function infra_provision_one(string $domain, ?array $server, ?array $account, bool $doPlesk, bool $doCf): array
{
    $domain = strtolower(trim($domain));
    $lines  = [];
    $ok     = true;
    $prov   = ['domain' => $domain, 'server_id' => $server['id'] ?? '', 'cf_account_id' => $account['id'] ?? ''];

    /* Plesk site + FTP user */
    if ($doPlesk) {
        if (!$server) {
            $lines[] = 'Plesk: ✗ no server'; $ok = false;
        } elseif (plesk_site_exists($server, $domain)) {
            $lines[] = 'Plesk: — already exists (skipped)';
        } else {
            $base    = preg_replace('/[^a-z0-9]/', '', explode('.', $domain)[0]);
            $ftpUser = substr($base, 0, 12) . '_' . bin2hex(random_bytes(3));
            $ftpPass = bin2hex(random_bytes(10)) . 'Aa1!';
            $ip      = $server['default_ip'] ?? ($server['host'] ?? '');
            $r = plesk_create_site($server, $domain, $ftpUser, $ftpPass, $ip);
            if ($r['ok']) {
                $prov['ftp_user'] = $ftpUser; $prov['ftp_pass'] = $ftpPass;
                $lines[] = "Plesk: ✓ {$r['message']} (ftp {$ftpUser})";
            } else {
                $lines[] = "Plesk: ✗ {$r['message']}"; $ok = false;
            }
        }
    }

    /* Cloudflare zone + DNS + SSL + HSTS (staged) */
    if ($doCf) {
        if (!$account) {
            $lines[] = 'Cloudflare: ✗ no CF account'; $ok = false;
        } elseif (!$server) {
            $lines[] = 'Cloudflare: ✗ need a server (DNS target IP)'; $ok = false;
        } else {
            $ip = $server['default_ip'] ?? ($server['host'] ?? '');
            $zoneId = ''; $ns = [];
            $ex = cf_get_zone($account, $domain);
            if ($ex) {
                $zoneId = $ex['id']; $ns = $ex['name_servers'] ?? [];
                $lines[] = 'Cloudflare zone: — already exists';
            } else {
                $z = cf_create_zone($account, $domain);
                if ($z['ok']) { $zoneId = $z['zone_id']; $ns = $z['name_servers']; $lines[] = 'Cloudflare zone: ✓ created'; }
                else { $lines[] = "Cloudflare zone: ✗ {$z['message']}"; $ok = false; }
            }
            if ($zoneId) {
                $prov['cf_zone_id']  = $zoneId;
                $prov['nameservers'] = implode(',', $ns);
                $a1 = cf_upsert_a_record($account, $zoneId, $domain, $ip, true);
                $lines[] = '  A @   -> ' . $ip . ': ' . ($a1['ok'] ? '✓ ' . $a1['message'] : '✗ ' . $a1['message']); if (!$a1['ok']) $ok = false;
                $a2 = cf_upsert_a_record($account, $zoneId, 'www.' . $domain, $ip, true);
                $lines[] = '  A www -> ' . $ip . ': ' . ($a2['ok'] ? '✓ ' . $a2['message'] : '✗ ' . $a2['message']); if (!$a2['ok']) $ok = false;
                $s = cf_set_ssl_mode($account, $zoneId, 'full');
                $lines[] = '  SSL: ' . ($s['ok'] ? '✓ full' : '✗ ' . $s['message']); if (!$s['ok']) $ok = false;
                $h = cf_set_hsts($account, $zoneId);
                $lines[] = '  HSTS: ' . ($h['ok'] ? '✓ on' : '✗ ' . $h['message']); if (!$h['ok']) $ok = false;
                $lines[] = '  NS: ' . implode(', ', $ns);
            }
        }
    }

    $prov['registrar'] = infra_registrar_map()[$domain]['registrar'] ?? '';
    $prov['status']    = $ok ? 'staged' : 'partial';
    infra_state_upsert_domain($prov);

    return ['ok' => $ok, 'lines' => $lines];
}
