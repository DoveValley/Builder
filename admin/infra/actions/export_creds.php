<?php
/**
 * infra/actions/export_creds.php — export provisioned FTP creds as a params-CSV.
 * Bridges this console → a batch's target list: the columns match
 * includes/multisite/params.php exactly, so the rows can be merged into a master's
 * params CSV. Auth via bootstrap; GET download.
 *
 * NOTE: the batch page's "Create host" writes these straight into the target list
 * now, which is the path to prefer. This export remains for domains provisioned
 * outside a batch, and for getting a copy of the credentials on paper.
 */
require_once __DIR__ . '/../bootstrap.php';

$servers = [];
foreach (infra_hestia_servers() as $s) $servers[$s['id'] ?? ''] = $s;

$cols = ['domain', 'ftp_protocol', 'ftp_host', 'ftp_port', 'ftp_user', 'ftp_pass', 'ftp_path', 'ftp_passive'];

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="params_ftp_creds.csv"');

$out = fopen('php://output', 'w');
fputcsv($out, $cols);
foreach (infra_state_all_domains() as $dom => $r) {
    if (($r['ftp_user'] ?? '') === '') continue;   // only domains that were provisioned
    $srv  = $servers[$r['server_id'] ?? ''] ?? [];
    $host = $srv['default_ip'] ?? ($srv['host'] ?? '');
    if ($host === '') continue;                    // no box on record: a blank host in a
                                                   // credentials file is worse than no row
    // The docroot is the FTP login's own home on Hestia — NOT /httpdocs, which is the
    // Plesk convention this file used to hardcode. Uploading to the wrong one puts
    // every file in a directory nginx never reads, and reports success doing it.
    fputcsv($out, [$dom, 'ftp', $host, 21, $r['ftp_user'], $r['ftp_pass'], '/home/' . $r['ftp_user'], 1]);
}
fclose($out);
