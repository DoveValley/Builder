<?php
/**
 * infra/lib/provision.php — the shared per-domain provisioning routine used by
 * both the single "New Site" action and the bulk runner. Creates the web host
 * (vhost + folder + scoped FTP login) on HestiaCP and fully stages the
 * Cloudflare zone, then persists the record to fleet state.
 *
 * This is the ONLY panel-specific step in the whole pipeline. Deploying — the
 * multisite batch upload and the factory's single-site deploy — speaks plain
 * FTP/SFTP to a host with a username and password, and neither knows nor cares
 * which panel issued them. Swapping panels here changes nothing downstream.
 */
require_once __DIR__ . '/fleet.php';        // store/cloudflare/state + infra_registrar_map()
require_once __DIR__ . '/hestia_fleet.php'; // hestia_* client + the Hestia registry
require_once __DIR__ . '/acquire.php'; // infra_domain_buy() — the ONE guarded purchase path
// infra_valid_domain() now lives in store.php (shared with the domain loader).

/**
 * Issue a Cloudflare Origin CA cert for $domain and install it on the box, via
 * the domain's own FTP login (Hestia's API has no upload verb — see
 * hestia_install_cert()'s docblock). Returns ok:false, not an exception, when
 * the account has no origin_ca_key configured yet — that is the expected state
 * for every account until one is added by hand, not a failure worth aborting a
 * provision run over.
 */
function infra_install_origin_cert(array $server, array $account, string $domain, string $ftpUser, string $ftpPass): array
{
    if ($ftpUser === '' || $ftpPass === '') {
        return ['ok' => false, 'message' => 'no FTP credentials on record for this domain'];
    }
    $cert = cf_create_origin_ca_cert($account, $domain);
    if (!$cert['ok']) return $cert;

    $host   = $server['default_ip'] ?? ($server['host'] ?? '');
    $user   = hestia_fleet_user($server);
    $sslDir = "/home/{$user}/web/{$domain}/public_html/ssl";
    $crtRel = 'ssl/' . $domain . '.crt';
    $keyRel = 'ssl/' . $domain . '.key';

    try {
        $upCert = hestia_ftp_put($host, $ftpUser, $ftpPass, $crtRel, $cert['cert']);
        $upKey  = hestia_ftp_put($host, $ftpUser, $ftpPass, $keyRel, $cert['key']);
        if (!$upCert['ok'] || !$upKey['ok']) {
            return ['ok' => false, 'message' => 'could not stage cert on the box: '
                . trim(($upCert['message'] ?? '') . ' ' . ($upKey['message'] ?? ''))];
        }
        return hestia_install_cert($server, $domain, $user, $sslDir);
    } finally {
        // The key sits inside public_html while staged, so it is reachable over
        // plain HTTP until Hestia's own copy takes over — remove it the moment
        // v-add-web-domain-ssl has read it, whether or not that call succeeded.
        hestia_ftp_delete($host, $ftpUser, $ftpPass, $crtRel);
        hestia_ftp_delete($host, $ftpUser, $ftpPass, $keyRel);
    }
}

/**
 * Provision one domain end-to-end (idempotent, staged-only), persist to state.
 * @param array $opts { register:bool, registrar:string, years:int, site:bool, cf:bool,
 *                        restart:bool — false in a batch; caller restarts once at the end }
 * @return array{ok:bool, lines:string[]}
 */
function infra_provision_one(string $domain, ?array $server, ?array $account, array $opts): array
{
    $domain    = strtolower(trim($domain));
    $doReg     = !empty($opts['register']);
    $regName   = strtolower(trim($opts['registrar'] ?? ''));
    $years     = max(1, (int) ($opts['years'] ?? 1));
    // 'plesk' accepted as an alias so an in-flight form post cannot silently
    // provision nothing after the rename.
    $doSite    = !empty($opts['site']) || !empty($opts['plesk']);
    $doRestart = !array_key_exists('restart', $opts) || !empty($opts['restart']);
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

            if ($regName === 'namecheap') {
                $lines[] = 'Registrar: ⚠ Namecheap cannot set auto-renew over its API — this '
                         . $years . 'yr term will lapse unless you switch auto-renew on in their'
                         . ' dashboard, or buy a longer term here';
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

    /* Web host: the vhost, its folder, and an FTP login scoped to it */
    if ($doSite) {
        if (!$server) {
            $lines[] = 'Host: ✗ no server'; $ok = false;
        } elseif (hestia_site_exists($server, $domain)) {
            $lines[] = 'Host: — already exists (skipped)';
        } else {
            $base    = preg_replace('/[^a-z0-9]/', '', explode('.', $domain)[0]);
            $ftpUser = substr($base, 0, 12) . '_' . bin2hex(random_bytes(3));
            $ftpPass = bin2hex(random_bytes(10)) . 'Aa1!';
            $ip      = $server['default_ip'] ?? ($server['host'] ?? '');
            // $restart=false for a batch: nginx is restarted ONCE after the whole
            // run, not once per domain. See the caller (bulk_run.php).
            $r = hestia_create_site($server, $domain, $ftpUser, $ftpPass, $ip, '', $doRestart);
            if ($r['ok']) {
                // Store the LOGIN Hestia actually created, not the name we asked
                // for. Hestia prefixes it with the owning account, so storing the
                // argument yields a credential that looks entirely reasonable and
                // fails every deploy with "login incorrect".
                $prov['ftp_user'] = $r['ftp_user'] ?: $ftpUser;
                $prov['ftp_pass'] = $ftpPass;
                $lines[] = "Host: ✓ {$r['message']} (ftp {$prov['ftp_user']})";
                // ⚠ The upload path, stated because it differs from Plesk and the
                // difference is silent. On Hestia the FTP login lands IN the
                // docroot — there is no public_html beneath it. Deploy config
                // carrying Plesk's '/public_html' default will upload into a
                // folder nginx never reads: every file transfers, nothing serves.
                $lines[] = "      upload to the login's own home (docroot {$r['docroot']}) — NOT /public_html";
            } else {
                $lines[] = "Host: ✗ {$r['message']}"; $ok = false;
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

                // 'full' only ever means anything if the origin can answer HTTPS for
                // this domain. It never could — hestia_install_cert() had zero callers
                // — and asserting 'full' anyway took nelsonrestoration.com down twice
                // in one night (2026-08-22): once on its first Go Live, once again the
                // moment "Create zone" re-ran after a Take offline reset this same
                // setting. Try to install a real Origin CA cert first; only claim
                // 'full' when one is actually on the box.
                $ftpUser = $prov['ftp_user'] ?? '';
                $ftpPass = $prov['ftp_pass'] ?? '';
                if ($ftpUser === '' || $ftpPass === '') {
                    $exFtp   = infra_state_get_domain($domain);
                    $ftpUser = $ftpUser !== '' ? $ftpUser : (string) ($exFtp['ftp_user'] ?? '');
                    $ftpPass = $ftpPass !== '' ? $ftpPass : (string) ($exFtp['ftp_pass'] ?? '');
                }
                $cert = infra_install_origin_cert($server, $account, $domain, $ftpUser, $ftpPass);
                $lines[] = '  Origin cert: ' . ($cert['ok'] ? '✓ ' . $cert['message'] : '— ' . $cert['message']);
                $sslMode = $cert['ok'] ? 'full' : 'flexible';
                $s = cf_set_ssl_mode($account, $zoneId, $sslMode);
                $lines[] = '  SSL: ' . ($s['ok'] ? "✓ {$sslMode}" : '✗ ' . $s['message']); if (!$s['ok']) $ok = false;
                $h = cf_set_hsts($account, $zoneId);
                $lines[] = '  HSTS: ' . ($h['ok'] ? '✓ on' : '✗ ' . $h['message']); if (!$h['ok']) $ok = false;
                $lines[] = '  NS: ' . implode(', ', $ns);
            }
        }
    }

    // Only fill registrar from the acquisition map when it actually knows one — a
    // domain marked owned by hand (never bought through that flow) has no map entry,
    // and writing '' here would overwrite a registrar someone already recorded by
    // hand (upsert treats a present key as an explicit value, not "leave alone").
    $mappedRegistrar = infra_registrar_map()[$domain]['registrar'] ?? '';
    if (empty($prov['registrar']) && $mappedRegistrar !== '') $prov['registrar'] = $mappedRegistrar;
    // 'staged' means infrastructure exists. Buying alone does not make it so — a
    // register-only run leaves the domain at 'owned', which is what it is.
    //
    // But never REGRESS a domain that is already ahead of 'staged' — a Take
    // offline followed by Create zone re-runs this exact function on a domain
    // that is already 'live'/'releasing', and this line used to stomp it back
    // to 'staged' unconditionally, silently re-arming it for the daily go-live
    // cron sweep (which only reconsiders staged/queued/releasing/awaiting-ns
    // domains) and turning a working site's status into a lie on the grid.
    $curStatus = (string) (infra_state_get_domain($domain)['status'] ?? '');
    $alreadyAdvanced = in_array($curStatus, ['releasing', 'live', 'awaiting-ns'], true);
    if (($doSite || $doCf) && !($ok && $alreadyAdvanced)) $prov['status'] = $ok ? 'staged' : 'partial';
    infra_state_upsert_domain($prov);

    return ['ok' => $ok, 'lines' => $lines];
}

/**
 * infra_provision_one(), locked against whichever of the 'host'/'zone' pipeline
 * steps this call will touch — the same per-domain-per-step lock
 * infra_pipeline_do() takes for those steps. The "New Site" form and the bulk
 * runner used to call infra_provision_one() directly, unlocked, so either one
 * could race the pipeline grid's own Host/Zone buttons (or each other) on the
 * same domain. Always locks 'host' before 'zone' when both apply, so this can
 * never deadlock against infra_pipeline_do() (which only ever holds one step's
 * lock at a time).
 */
function infra_provision_locked(string $domain, ?array $server, ?array $account, array $opts): array
{
    require_once __DIR__ . '/pipeline.php';
    $call = fn() => infra_provision_one($domain, $server, $account, $opts);
    $doSite = !empty($opts['site']) || !empty($opts['plesk']);
    $doCf   = !empty($opts['cf']);
    if ($doSite && $doCf) {
        return infra_pipeline_lock($domain, 'host', fn() => infra_pipeline_lock($domain, 'zone', $call));
    }
    if ($doSite) return infra_pipeline_lock($domain, 'host', $call);
    if ($doCf)   return infra_pipeline_lock($domain, 'zone', $call);
    return $call();   // register-only — no host/zone step to lock
}
