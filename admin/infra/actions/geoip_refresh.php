<?php
/**
 * infra/actions/geoip_refresh.php — "Refresh locations" on the Map tab. One real
 * outbound call (ip-api.com's batch endpoint, the whole fleet in one request), so
 * it gets the same CSRF-guarded POST treatment as every other state/API-touching
 * action here rather than a plain GET a crawler could trigger for free.
 */
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/hestia_fleet.php';
require_once __DIR__ . '/../lib/geoip.php';

$back = '../index.php?view=map';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !infra_check_csrf()) {
    infra_set_flash('err', 'Invalid request (bad CSRF token).');
    header('Location: ' . $back); exit;
}
infra_session_release();

$ips = [];
foreach (infra_hestia_servers() as $srv) {
    $ip = (string) ($srv['default_ip'] ?? $srv['host'] ?? '');
    if ($ip !== '') $ips[] = $ip;
}

$results = infra_geoip_lookup_many($ips);
$ok = 0; $failed = 0;
foreach ($results as $r) { $r['ok'] ? $ok++ : $failed++; }

infra_set_flash(
    $failed > 0 ? 'warn' : 'ok',
    'Located ' . $ok . ' of ' . count($results) . ' box(es).'
    . ($failed > 0 ? ' ' . $failed . ' could not be located — see each box\'s popup for why.' : '')
);
header('Location: ' . $back);
