<?php
/**
 * infra/actions/domainlist_check.php — "Check this batch now" on the Domain Check
 * tab. Runs every domain in one saved list through infra_site_check_run() (same
 * checker the Servers tab and go-live pipeline use) and reports a tally; the view
 * reads the per-domain detail back from cache afterward.
 */
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/domain_lists.php';

$back = '../index.php?view=domaincheck';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !infra_check_csrf()) {
    infra_set_flash('err', 'Invalid request (bad CSRF token).');
    header('Location: ' . $back); exit;
}
infra_session_release();
set_time_limit(0);   // one domain per real outbound request; a long list takes a while

$id = (int) ($_POST['id'] ?? 0);
$result = infra_domain_list_check($id);

if ($result['checked'] === 0) {
    infra_set_flash('err', 'That list has no domains to check (or no longer exists).');
} else {
    infra_set_flash($result['down'] > 0 ? 'warn' : 'ok',
        'Checked ' . $result['checked'] . ' domain' . ($result['checked'] === 1 ? '' : 's') . ': '
        . $result['up'] . ' up, ' . $result['down'] . ' down.');
}
header('Location: ' . $back);
