<?php
/**
 * infra/actions/domainlist_edit.php — replace a saved list's domains with a
 * resubmitted textarea (add/remove domains in one edit, not a diff). Same parsing
 * as domainlist_create.php — dedupe, validate, report how many lines were dropped.
 */
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/domain_lists.php';

$back = '../index.php?view=domaincheck';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !infra_check_csrf()) {
    infra_set_flash('err', 'Invalid request (bad CSRF token).');
    header('Location: ' . $back); exit;
}
infra_session_release();

$id      = (int) ($_POST['id'] ?? 0);
$raw     = (string) ($_POST['domains'] ?? '');
$pieces  = preg_split('/[\s,]+/', trim($raw)) ?: [];
$wanted  = count(array_filter(array_map('trim', $pieces), fn($p) => $p !== ''));
$domains = infra_domain_list_parse($raw);

if (!infra_domain_list_set_domains($id, $domains)) {
    infra_set_flash('err', 'That list no longer exists.');
    header('Location: ' . $back); exit;
}

$dropped = $wanted - count($domains);
$msg = 'Saved — now ' . count($domains) . ' domain' . (count($domains) === 1 ? '' : 's') . '.';
if ($dropped > 0) $msg .= ' ' . $dropped . ' line' . ($dropped === 1 ? '' : 's') . ' dropped (duplicates or not a real domain).';

infra_set_flash('ok', $msg);
header('Location: ' . $back);
