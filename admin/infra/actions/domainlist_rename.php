<?php
/**
 * infra/actions/domainlist_rename.php — rename a saved domain list. Membership
 * (which domains, their cached check results) is untouched; only the name column
 * changes.
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
$name = trim((string) ($_POST['name'] ?? ''));

if ($name === '') {
    infra_set_flash('err', 'List name can\'t be blank — nothing changed.');
} elseif (!infra_domain_list_rename($id, $name)) {
    infra_set_flash('err', 'That list no longer exists.');
} else {
    infra_set_flash('ok', 'Renamed to "' . $name . '".');
}
header('Location: ' . $back);
