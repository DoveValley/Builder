<?php
/**
 * infra/actions/filedate_run.php — queue a File Date Refresh run (CSRF, PRG).
 * Writes only to the file_date_refresh table — never touches golive_freshen's state.
 */
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/file_date_refresh.php';

$back = '../index.php?view=filedate';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !infra_check_csrf()) {
    infra_set_flash('err', 'Invalid request (bad CSRF token).');
    header('Location: ' . $back); exit;
}
infra_session_release();

$count        = max(1, (int) ($_POST['count'] ?? 0));
$cooldownDays = max(0, (int) ($_POST['cooldown_days'] ?? 0));
$niches       = array_values(array_unique(array_filter(array_map('infra_niche', (array) ($_POST['niches'] ?? [])))));

if (!$niches) {
    infra_set_flash('err', 'Pick at least one niche.');
    header('Location: ' . $back); exit;
}

$result = infra_filedate_enqueue($count, $niches, $cooldownDays);

if ($result['picked'] === 0) {
    infra_set_flash('warn', 'No eligible domains found — ' . $result['eligible'] . ' domain(s) matched '
        . 'the niche(s) picked, but all were either already queued or touched within the last '
        . $cooldownDays . ' day(s).');
} else {
    infra_set_flash('ok', 'Queued ' . $result['picked'] . ' domain(s) for a file-date refresh (out of '
        . $result['eligible'] . ' eligible). Each will run at its own staggered time over the next '
        . '~20 hours — check the log as they complete.');
}
header('Location: ' . $back);
