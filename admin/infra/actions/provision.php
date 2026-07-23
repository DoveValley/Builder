<?php
/**
 * infra/actions/provision.php — single-domain provisioning (PRG + flash).
 * Thin wrapper over infra_provision_one(). CSRF-guarded.
 */
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/provision.php';

$back = '../index.php?view=new';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !infra_check_csrf()) {
    infra_set_flash('err', 'Invalid request (missing or bad CSRF token).');
    header('Location: ' . $back); exit;
}

$domain = strtolower(trim($_POST['domain'] ?? ''));
$opts = [
    'register'  => !empty($_POST['do_register']),
    'registrar' => $_POST['registrar'] ?? '',
    'years'     => (int) ($_POST['years'] ?? 1),
    'plesk'     => !empty($_POST['do_plesk']),
    'cf'        => !empty($_POST['do_cf']),
];

if (!infra_valid_domain($domain)) {
    infra_set_flash('err', "Invalid domain: '$domain'");
    header('Location: ' . $back); exit;
}
if (!$opts['register'] && !$opts['plesk'] && !$opts['cf']) {
    infra_set_flash('err', 'Nothing selected to do.');
    header('Location: ' . $back); exit;
}

$server = null;  foreach (infra_servers() as $s)    if (($s['id'] ?? '') === ($_POST['server_id'] ?? ''))    $server = $s;
$account = null; foreach (infra_cf_accounts() as $a) if (($a['id'] ?? '') === ($_POST['cf_account_id'] ?? '')) $account = $a;

$res = infra_provision_one($domain, $server, $account, $opts);
infra_set_flash($res['ok'] ? 'ok' : 'warn', "Provision '$domain':\n" . implode("\n", $res['lines']));
header('Location: ' . $back);
