<?php
/**
 * infra/actions/servers_check.php — "Check for files" / "Check if they're up" from the
 * Servers tab. Both make real outbound calls against one or every Hestia box, so they
 * get the same CSRF-guarded POST treatment as every other state/API-touching action
 * here — these used to be plain GET links (index.php?view=servers&content=...), the
 * one place in this console where visiting a URL (a bookmark, a crawler, a link on
 * another page) fired real API calls with no token check at all.
 */
require_once __DIR__ . '/../bootstrap.php';

$back = 'index.php?view=servers#hestia';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !infra_check_csrf()) {
    infra_set_flash('err', 'Invalid request (bad CSRF token).');
    header('Location: ' . $back); exit;
}
infra_session_release();

$contentId = (string) ($_POST['content'] ?? '');
$hCheckId  = (string) ($_POST['hcheck'] ?? '');

if ($contentId !== '') {
    $tot = ['checked' => 0, 'with_files' => 0, 'empty' => 0];
    foreach (infra_hestia_servers() as $srv) {
        if ($contentId !== 'all' && ($srv['id'] ?? '') !== $contentId) continue;
        $r = infra_hestia_content_run($srv);
        foreach (['checked', 'with_files', 'empty'] as $k) $tot[$k] += $r[$k];
    }
    infra_set_flash($tot['empty'] > 0 ? 'warn' : 'ok',
        $tot['checked'] === 0 ? 'No host areas to check.'
        : ($tot['with_files'] . ' of ' . $tot['checked'] . ' host area(s) contain a site'
           . ($tot['empty'] > 0 ? ' — ' . $tot['empty'] . ' still hold only the placeholder, so nothing has been uploaded into them.' : '.')));
    header('Location: ' . $back); exit;
}

if ($hCheckId !== '') {
    $n = 0;
    foreach (infra_hestia_servers() as $srv) {
        if (($srv['id'] ?? '') !== $hCheckId) continue;
        foreach (infra_discover_hestia($srv)['sites'] as $s) {
            if (($s['name'] ?? '') !== '') { infra_site_check_run($s['name']); $n++; }
        }
    }
    infra_set_flash('ok', 'Checked ' . $n . ' website' . ($n === 1 ? '' : 's') . ' on the Hestia box.');
    header('Location: ' . $back); exit;
}

infra_set_flash('err', 'Nothing to check.');
header('Location: ' . $back);
