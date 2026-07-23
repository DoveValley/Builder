<?php
/**
 * infra/actions/export_creds.php — export provisioned FTP creds as a params-CSV.
 * Bridges Phase 1 (this console) → Phase 2 (multisite content upload): the columns
 * match includes/multisite/params.php exactly, so the rows can be merged straight
 * into a master site's params CSV — then build+deploy uploads content to the
 * infra-provisioned Plesk box using these creds. Auth via bootstrap; GET download.
 */
require_once __DIR__ . '/../bootstrap.php';

$servers = [];
foreach (infra_servers() as $s) $servers[$s['id'] ?? ''] = $s;

$cols = ['domain', 'ftp_protocol', 'ftp_host', 'ftp_port', 'ftp_user', 'ftp_pass', 'ftp_path', 'ftp_passive'];

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="params_ftp_creds.csv"');

$out = fopen('php://output', 'w');
fputcsv($out, $cols);
foreach (infra_state_all_domains() as $dom => $r) {
    if (($r['ftp_user'] ?? '') === '') continue;   // only domains actually provisioned on Plesk
    $srv  = $servers[$r['server_id'] ?? ''] ?? [];
    $host = $srv['default_ip'] ?? ($srv['host'] ?? '');
    fputcsv($out, [$dom, 'ftp', $host, 21, $r['ftp_user'], $r['ftp_pass'], '/httpdocs', 1]);
}
fclose($out);
