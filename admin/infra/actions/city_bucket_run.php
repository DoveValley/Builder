<?php
/**
 * infra/actions/city_bucket_run.php — Data 3's "Run Data 3" button (CSRF, PRG).
 * Joins one completed City Research run against Census City Data and writes
 * the bucketed xlsx — see lib/research.php's infra_city_bucket_run().
 */
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/research.php';

$niche = infra_niche_slug((string) ($_POST['niche'] ?? ''));
$back = '../index.php?view=research' . ($niche !== '' ? '&niche=' . urlencode($niche) : '');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !infra_check_csrf()) {
    infra_set_flash('err', 'Invalid request (bad CSRF token).');
    header('Location: ' . $back); exit;
}
infra_session_release();

if ($niche === '' || !isset(infra_niches()[$niche])) {
    infra_set_flash('err', 'Pick a niche first.');
    header('Location: ' . $back); exit;
}

$runId = (string) ($_POST['run_id'] ?? '');
$popFloor = (int) ($_POST['pop_floor'] ?? 15000);

$result = infra_city_bucket_run($niche, $runId, $popFloor);
infra_set_flash($result['ok'] ? 'ok' : 'err',
    $result['ok'] ? $result['msg'] . ' Saved as ' . $result['file'] . ' (Downloads for Scott).' : $result['msg']);
header('Location: ' . $back);
