<?php
/**
 * infra/actions/provision.php — POST endpoint for Phase-1 provisioning steps.
 * CSRF-guarded. Runs the selected steps (create Plesk site / create CF zone),
 * flashes per-step results, and redirects back (PRG). Idempotent where possible.
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

// basic domain validation
if (!preg_match('/^(?=.{1,253}$)([a-z0-9](-?[a-z0-9])*\.)+[a-z]{2,}$/', $domain)) {
    infra_set_flash('err', "Invalid domain: '$domain'");
    header('Location: ' . $back); exit;
}
if (!$doPlesk && !$doCf) {
    infra_set_flash('err', 'Nothing selected to provision.');
    header('Location: ' . $back); exit;
}

// resolve server + account from registries
$server = null;
foreach (infra_servers() as $s) if (($s['id'] ?? '') === $srvId) $server = $s;
$account = null;
foreach (infra_cf_accounts() as $a) if (($a['id'] ?? '') === $acctId) $account = $a;

$results = [];
$allOk   = true;

/* ---- Plesk: create site + system user ---- */
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
            $results[] = "Plesk: ✓ {$r['message']}\n         FTP user: {$r['ftp_user']}\n         FTP pass: {$r['ftp_pass']}  (save this — needed for deploy)";
        } else {
            $results[] = "Plesk: ✗ {$r['message']}"; $allOk = false;
        }
    }
}

/* ---- Cloudflare: create zone ---- */
if ($doCf) {
    if (!$account) {
        $results[] = 'Cloudflare: ✗ no CF account selected'; $allOk = false;
    } else {
        $r = cf_create_zone($account, $domain);
        if ($r['ok']) {
            $results[] = "Cloudflare: ✓ zone created\n            Nameservers: " . implode(', ', $r['name_servers']) . "\n            (set these at the registrar to go live)";
        } else {
            $results[] = "Cloudflare: ✗ {$r['message']}"; $allOk = false;
        }
    }
}

infra_set_flash($allOk ? 'ok' : 'warn', "Provision '$domain':\n" . implode("\n", $results));
header('Location: ' . $back);
