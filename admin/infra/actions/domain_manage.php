<?php
/**
 * infra/actions/domain_manage.php — edit / remove one domain (CSRF, PRG).
 * action = edit | delete_zone | delete_site | untrack | teardown
 * Destructive actions require a typed-domain confirm field.
 */
require_once __DIR__ . '/../bootstrap.php';

$domain = strtolower(trim($_POST['domain'] ?? ''));
$action = $_POST['action'] ?? '';
$back    = '../index.php?view=domain&d=' . urlencode($domain);
$toList  = '../index.php?view=domains';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !infra_check_csrf()) {
    infra_set_flash('err', 'Invalid request (bad CSRF token).');
    header('Location: ' . $toList); exit;
}
$rec = infra_state_get_domain($domain);
if (!$rec) { infra_set_flash('err', "Not in fleet state: {$domain}"); header('Location: ' . $toList); exit; }

// destructive actions require re-typing the domain
$destructive = ['delete_zone', 'delete_site', 'untrack', 'teardown'];
if (in_array($action, $destructive, true) && strtolower(trim($_POST['confirm'] ?? '')) !== $domain) {
    infra_set_flash('err', 'Confirmation did not match the domain — nothing changed.');
    header('Location: ' . $back); exit;
}

$server = null;  foreach (infra_servers() as $s)    if (($s['id'] ?? '') === ($rec['server_id'] ?? ''))     $server = $s;
$account = null; foreach (infra_cf_accounts() as $a) if (($a['id'] ?? '') === ($rec['cf_account_id'] ?? '')) $account = $a;

switch ($action) {
    case 'edit':
        infra_state_upsert_domain([
            'domain'    => $domain,
            'registrar' => strtolower(trim($_POST['registrar'] ?? $rec['registrar'])),
            'niche'     => trim($_POST['niche'] ?? $rec['niche']),
            'status'    => trim($_POST['status'] ?? $rec['status']),
        ]);
        infra_set_flash('ok', "Updated {$domain}.");
        header('Location: ' . $back); exit;

    case 'delete_zone':
        if (!$account || ($rec['cf_zone_id'] ?? '') === '') { infra_set_flash('warn', 'No Cloudflare zone on record.'); header('Location: ' . $back); exit; }
        $r = cf_delete_zone($account, $rec['cf_zone_id']);
        if ($r['ok']) infra_state_upsert_domain(['domain' => $domain, 'cf_zone_id' => '', 'nameservers' => '']);
        infra_set_flash($r['ok'] ? 'ok' : 'err', "Delete CF zone: {$r['message']}");
        header('Location: ' . $back); exit;

    case 'delete_site':
        if (!$server) { infra_set_flash('warn', 'No server on record.'); header('Location: ' . $back); exit; }
        $r = plesk_delete_site($server, $domain);
        if ($r['ok']) infra_state_upsert_domain(['domain' => $domain, 'ftp_user' => '', 'ftp_pass' => '']);
        infra_set_flash($r['ok'] ? 'ok' : 'err', "Delete Plesk site: {$r['message']}");
        header('Location: ' . $back); exit;

    case 'untrack':
        infra_state_delete_domain($domain);
        infra_set_flash('ok', "Removed {$domain} from fleet state (infrastructure left intact).");
        header('Location: ' . $toList); exit;

    case 'teardown':
        $parts = [];
        if ($account && ($rec['cf_zone_id'] ?? '') !== '') { $z = cf_delete_zone($account, $rec['cf_zone_id']); $parts[] = 'CF zone: ' . $z['message']; }
        if ($server) { $p = plesk_delete_site($server, $domain); $parts[] = 'Plesk site: ' . $p['message']; }
        infra_state_delete_domain($domain);
        $parts[] = 'fleet state: removed';
        infra_set_flash('warn', "Teardown {$domain} —\n" . implode("\n", $parts));
        header('Location: ' . $toList); exit;

    default:
        infra_set_flash('err', 'Unknown action.');
        header('Location: ' . $back); exit;
}
