<?php
/**
 * infra/actions/provision.php — POST endpoint for Phase-1 provisioning.
 * CSRF-guarded. Creates Plesk site + fully stages the Cloudflare zone
 * (DNS apex/www → VPS IP proxied, SSL, HSTS), persists the record to fleet
 * state, flashes per-step results, and redirects (PRG). Idempotent.
 */
require_once __DIR__ . '/../bootstrap.php';

$back = '../index.php?view=new';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !infra_check_csrf()) {
    infra_set_flash('err', 'Invalid request (missing or bad CSRF token).');
    header('Location: ' . $back); exit;
}

$domain  = strtolower(trim($_POST['domain'] ?? ''));
$srvId   = $_POST['server_id'] ?? '';
$acctId  = $_POST['cf_account_id'] ?? '';
$doPlesk = !empty($_POST['do_plesk']);
$doCf    = !empty($_POST['do_cf']);

if (!preg_match('/^(?=.{1,253}$)([a-z0-9](-?[a-z0-9])*\.)+[a-z]{2,}$/', $domain)) {
    infra_set_flash('err', "Invalid domain: '$domain'");
    header('Location: ' . $back); exit;
}
if (!$doPlesk && !$doCf) {
    infra_set_flash('err', 'Nothing selected to provision.');
    header('Location: ' . $back); exit;
}

$server = null;  foreach (infra_servers() as $s)     if (($s['id'] ?? '') === $srvId)  $server = $s;
$account = null; foreach (infra_cf_accounts() as $a)  if (($a['id'] ?? '') === $acctId) $account = $a;

$results = [];
$allOk   = true;
$prov    = ['domain' => $domain, 'server_id' => $srvId, 'cf_account_id' => ($account ? $acctId : '')];

/* ---- Plesk: create site + system (FTP) user ---- */
if ($doPlesk) {
    if (!$server) {
        $results[] = 'Plesk: ✗ no server selected'; $allOk = false;
    } elseif (plesk_site_exists($server, $domain)) {
        $results[] = 'Plesk: — already exists on ' . ($server['label'] ?? $server['id']) . ' (skipped)';
    } else {
        $base    = preg_replace('/[^a-z0-9]/', '', explode('.', $domain)[0]);
        $ftpUser = substr($base, 0, 12) . '_' . bin2hex(random_bytes(3));
        $ftpPass = bin2hex(random_bytes(10)) . 'Aa1!';
        $ip      = $server['default_ip'] ?? ($server['host'] ?? '');
        $r = plesk_create_site($server, $domain, $ftpUser, $ftpPass, $ip);
        if ($r['ok']) {
            $prov['ftp_user'] = $ftpUser;
            $prov['ftp_pass'] = $ftpPass;
            $results[] = "Plesk: ✓ {$r['message']}\n         FTP user: {$r['ftp_user']}\n         FTP pass: {$r['ftp_pass']}  (saved to fleet state for deploy)";
        } else {
            $results[] = "Plesk: ✗ {$r['message']}"; $allOk = false;
        }
    }
}

/* ---- Cloudflare: zone + DNS(apex/www) + SSL + HSTS (staged) ---- */
if ($doCf) {
    if (!$account) {
        $results[] = 'Cloudflare: ✗ no CF account selected'; $allOk = false;
    } elseif (!$server) {
        $results[] = 'Cloudflare: ✗ need a server selected (its IP is the DNS target)'; $allOk = false;
    } else {
        $ip = $server['default_ip'] ?? ($server['host'] ?? '');
        $zoneId = ''; $ns = [];
        $existing = cf_get_zone($account, $domain);
        if ($existing) {
            $zoneId = $existing['id']; $ns = $existing['name_servers'] ?? [];
            $results[] = 'Cloudflare zone: — already exists';
        } else {
            $z = cf_create_zone($account, $domain);
            if ($z['ok']) { $zoneId = $z['zone_id']; $ns = $z['name_servers']; $results[] = 'Cloudflare zone: ✓ created'; }
            else { $results[] = "Cloudflare zone: ✗ {$z['message']}"; $allOk = false; }
        }
        if ($zoneId) {
            $prov['cf_zone_id']  = $zoneId;
            $prov['nameservers'] = implode(',', $ns);

            $a1 = cf_upsert_a_record($account, $zoneId, $domain, $ip, true);
            $results[] = "  A  @   -> {$ip} (proxied): " . ($a1['ok'] ? '✓ ' . $a1['message'] : '✗ ' . $a1['message']);
            if (!$a1['ok']) $allOk = false;

            $a2 = cf_upsert_a_record($account, $zoneId, 'www.' . $domain, $ip, true);
            $results[] = "  A  www -> {$ip} (proxied): " . ($a2['ok'] ? '✓ ' . $a2['message'] : '✗ ' . $a2['message']);
            if (!$a2['ok']) $allOk = false;

            $ssl = cf_set_ssl_mode($account, $zoneId, 'full');
            $results[] = '  SSL mode: ' . ($ssl['ok'] ? '✓ ' . $ssl['message'] . ' (upgrade to strict at go-live w/ Origin CA)' : '✗ ' . $ssl['message']);
            if (!$ssl['ok']) $allOk = false;

            $h = cf_set_hsts($account, $zoneId);
            $results[] = '  HSTS: ' . ($h['ok'] ? '✓ ' . $h['message'] : '✗ ' . $h['message']);
            if (!$h['ok']) $allOk = false;

            $results[] = "  Nameservers (set at registrar to go live):\n    " . implode("\n    ", $ns);
        }
    }
}

/* ---- persist to fleet state ---- */
$prov['registrar'] = infra_registrar_map()[$domain]['registrar'] ?? '';
$prov['status']    = $allOk ? 'staged' : 'partial';
infra_state_upsert_domain($prov);

infra_set_flash($allOk ? 'ok' : 'warn', "Provision '$domain':\n" . implode("\n", $results));
header('Location: ' . $back);
