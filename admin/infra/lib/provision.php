<?php
/**
 * infra/lib/provision.php — the shared per-domain provisioning routine used by
 * both the single "New Site" action and the bulk runner. Creates the Plesk site
 * + fully stages the Cloudflare zone, then persists the record to fleet state.
 */
require_once __DIR__ . '/fleet.php';   // pulls store/plesk/cloudflare/state + infra_registrar_map()
require_once __DIR__ . '/acquire.php'; // infra_domain_buy() — the ONE guarded purchase path
// infra_valid_domain() now lives in store.php (shared with the domain loader).

/**
 * Provision one domain end-to-end (idempotent, staged-only), persist to state.
 * @param array $opts { register:bool, registrar:string, years:int, plesk:bool, cf:bool }
 * @return array{ok:bool, lines:string[]}
 */
function infra_provision_one(string $domain, ?array $server, ?array $account, array $opts): array
{
    $domain    = strtolower(trim($domain));
    $doReg     = !empty($opts['register']);
    $regName   = strtolower(trim($opts['registrar'] ?? ''));
    $years     = max(1, (int) ($opts['years'] ?? 1));
    $doPlesk   = !empty($opts['plesk']);
    $doCf      = !empty($opts['cf']);
    $lines     = [];
    $ok        = true;
    $prov      = ['domain' => $domain, 'server_id' => $server['id'] ?? '', 'cf_account_id' => $account['id'] ?? ''];
    if ($regName !== '') $prov['registrar'] = $regName;   // record chosen registrar even without auto-buy

    /* 0) Register (buy) the domain — real money. If it fails, do NOT provision further.
     *
     * Goes through infra_domain_buy(), the same call the Buy button uses, rather
     * than straight to infra_registrar_register(). Calling the adapter directly
     * meant this path had NONE of the rails the Buy button has — no already-owned
     * check, no availability re-check in the moment before paying — and, worse, it
     * never wrote the ownership receipt: a domain bought here got status 'staged'
     * and an empty `owned` column, so the table said "Own: No" for a domain it had
     * just paid for, and the "refusing to buy it twice" guard could not fire on it.
     *
     * One purchase path. A rail that only some of the buttons use is not a rail. */
    if ($doReg) {
        if ($regName === '') {
            return ['ok' => false, 'lines' => ['Registrar: ✗ no registrar selected for registration']];
        }
        $ex = infra_state_get_domain($domain);
        if (($ex['owned'] ?? '') === 'yes') {
            $lines[] = 'Registrar: — already owned (skipped, nothing charged)';
        } else {
            // infra_domain_buy() reads the registrar off the record, so the choice
            // made on the form is recorded before it runs.
            infra_state_add_new_domain($domain);
            infra_state_upsert_domain(['domain' => $domain, 'buy_registrar' => $regName]);

            if ($regName === 'namecheap' && $years < 3) {
                $lines[] = 'Registrar: ⚠ Namecheap cannot set auto-renew over its API — a '
                         . $years . 'yr term will lapse unless you renew it by hand (3yr costs ~10c/yr more)';
            }
            $rr = infra_domain_buy($domain, ['years' => $years, 'auto_renew' => true]);
            if ($rr['ok']) {
                $lines[] = "Registrar: ✓ {$rr['message']}";
            } else {
                $lines[] = "Registrar: ✗ {$rr['message']}";
                // infra_domain_buy() has already recorded 'buy-failed' + the reason;
                // persist only the assignment here so that record survives intact.
                infra_state_upsert_domain($prov);
                return ['ok' => false, 'lines' => $lines];   // abort — don't provision a domain we don't own
            }
        }
    }

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

    if (empty($prov['registrar'])) $prov['registrar'] = infra_registrar_map()[$domain]['registrar'] ?? '';
    // 'staged' means infrastructure exists. Buying alone does not make it so — a
    // register-only run leaves the domain at 'owned', which is what it is.
    if ($doPlesk || $doCf) $prov['status'] = $ok ? 'staged' : 'partial';
    infra_state_upsert_domain($prov);

    return ['ok' => $ok, 'lines' => $lines];
}
