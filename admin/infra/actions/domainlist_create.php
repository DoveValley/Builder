<?php
/**
 * infra/actions/domainlist_create.php — save a new named domain list from the
 * "Domain Check" tab's textarea.
 */
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/domain_lists.php';

$back = '../index.php?view=domaincheck';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !infra_check_csrf()) {
    infra_set_flash('err', 'Invalid request (bad CSRF token).');
    header('Location: ' . $back); exit;
}
infra_session_release();

$name    = trim((string) ($_POST['name'] ?? ''));
$raw     = (string) ($_POST['domains'] ?? '');
$pieces  = preg_split('/[\s,]+/', trim($raw)) ?: [];
$wanted  = count(array_filter(array_map('trim', $pieces), fn($p) => $p !== ''));
$domains = infra_domain_list_parse($raw);

if (!$domains) {
    infra_set_flash('err', 'No valid domains found in that list — nothing saved.');
    header('Location: ' . $back); exit;
}

$id      = infra_domain_list_create($name, $domains);
$dropped = $wanted - count($domains);

$msg = 'Saved "' . ($name !== '' ? $name : 'Untitled list') . '" with ' . count($domains)
     . ' domain' . (count($domains) === 1 ? '' : 's') . '.';
if ($dropped > 0) $msg .= ' ' . $dropped . ' line' . ($dropped === 1 ? '' : 's') . ' dropped (duplicates or not a real domain).';

infra_set_flash('ok', $msg);
header('Location: ' . $back);
