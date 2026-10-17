<?php
/**
 * infra/actions/domainlist_delete.php — remove a saved domain list. Only deletes
 * the list itself (name + membership rows); it never touches the domains, the
 * fleet, or any cached check result for them.
 */
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/domain_lists.php';

$back = '../index.php?view=domaincheck';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !infra_check_csrf()) {
    infra_set_flash('err', 'Invalid request (bad CSRF token).');
    header('Location: ' . $back); exit;
}
infra_session_release();

$id = (int) ($_POST['id'] ?? 0);
infra_domain_list_delete($id);

infra_set_flash('ok', 'List deleted.');
header('Location: ' . $back);
