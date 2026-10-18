<?php
/**
 * infra/actions/domainlist_delete.php — remove a saved domain list. Only deletes
 * the list itself (name + membership rows); it never touches the domains, the
 * fleet, or any cached check result for them.
 *
 * Requires typing the list's own name to confirm — same "retype the thing you're
 * about to destroy" pattern as the domain Danger Zone (domain_manage.php), not a
 * plain JS confirm() dialog, which is just a click away from an absent-minded yes.
 */
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/domain_lists.php';

$back = '../index.php?view=domaincheck';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !infra_check_csrf()) {
    infra_set_flash('err', 'Invalid request (bad CSRF token).');
    header('Location: ' . $back); exit;
}
infra_session_release();

$id   = (int) ($_POST['id'] ?? 0);
$list = infra_domain_list_get($id);

if (!$list) {
    infra_set_flash('err', 'That list no longer exists.');
    header('Location: ' . $back); exit;
}

$typed    = trim((string) ($_POST['confirm'] ?? ''));
$expected = trim((string) $list['name']);
if (strtolower($typed) !== strtolower($expected)) {
    infra_set_flash('err', 'Type the list name exactly ("' . $expected . '") to delete it — nothing changed.');
    header('Location: ' . $back); exit;
}

infra_domain_list_delete($id);
infra_set_flash('ok', 'Deleted "' . $expected . '".');
header('Location: ' . $back);
