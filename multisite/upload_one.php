<?php
/**
 * Upload ONE already-built domain to its remote host. The per-domain worker spawned
 * by upload_sites.php's process pool (mirrors build_one.php's role for run_campaign.php
 * — see includes/multisite/run_pool.php, shared by both orchestrators).
 *
 *   php multisite/upload_one.php <row_file.json>
 *
 * <row_file.json> is a throwaway temp file written by upload_sites.php containing
 * exactly what this one domain's upload needs: domain, master_id, dir (local build
 * output), files (informational count), the row's ftp_* fields, force, wipe. Deleted
 * by upload_sites.php once the whole pool finishes, same as run_campaign.php's own
 * row files.
 *
 * Emits progress as JSON lines (progress_jsonlines_sink(), same as build_one.php) so
 * the parent's existing ms_parse_line() can follow along — including a final
 * 'upload_result' event carrying everything upload_sites.php needs to print its
 * legacy per-domain log line and build its post-run fleet.db failure note, without
 * re-deriving them from deploy_site() a second time.
 */
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "upload_one.php is CLI only\n"); exit(2); }

require __DIR__ . '/../config.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/multisite/batch.php';
require __DIR__ . '/../includes/multisite/deploy.php';

progress_set_sink(progress_jsonlines_sink());

$rowFile = $argv[1] ?? '';
$row     = $rowFile !== '' ? json_decode((string) @file_get_contents($rowFile), true) : null;
if (!is_array($row) || empty($row['domain']) || empty($row['master_id'])) {
    fwrite(STDERR, "usage: upload_one.php <row_file.json>\n");
    exit(2);
}

$domain   = (string) $row['domain'];
$masterId = (string) $row['master_id'];
$dir      = (string) ($row['dir'] ?? '');
$files    = (int) ($row['files'] ?? 0);
$force    = !empty($row['force']);
$wipe     = !empty($row['wipe']);

$ftp = [
    'ftp_protocol' => (($row['ftp_protocol'] ?? 'ftp') === 'sftp') ? 'sftp' : 'ftp',
    'ftp_host'     => $row['ftp_host'] ?? '',
    'ftp_port'     => $row['ftp_port'] ?? '',
    'ftp_user'     => $row['ftp_user'] ?? '',
    'ftp_pass'     => $row['ftp_pass'] ?? '',
    'ftp_path'     => $row['ftp_path'] ?? '',
    'ftp_passive'  => $row['ftp_passive'] ?? true,
];

$manifestDir = BASE_DIR . '/sites/' . $masterId . '/multisite/manifests';
if (!is_dir($manifestDir)) @mkdir($manifestDir, 0775, true);
$slug     = ms_domain_slug($domain);
$manifest = $manifestDir . '/' . $slug . '.json';

if ($wipe) {
    $wiped = ms_wipe_remote($ftp);
    // Same check as upload_sites.php's own pre-refactor loop: a partial wipe (some
    // files/dirs failed to delete) must not fall through to uploading fresh content
    // on top of a remote that may still hold stale files.
    if (($wiped['status'] ?? '') === 'fatal' || (int) ($wiped['failed'] ?? 0) > 0) {
        // failed=0 here on purpose — this is a wipe error, not a file-upload count,
        // so the parent's onComplete falls through to the real message below
        // ('last', captured from this fatal event) instead of a misleading "N
        // file(s) failed".
        progress_emit(['type' => 'upload_result', 'files' => $files, 'uploaded' => 0, 'failed' => 0,
                        'verify_missing' => [], 'msg' => $wiped['msg'] ?? 'wipe failed']);
        progress_log('Wipe failed: ' . ($wiped['msg'] ?? 'unknown error'), 'fatal');
        exit(1);
    }
    // The manifest recorded what was on the server before the wipe — now false,
    // so leaving it would make deploy_site() skip re-sending files it thinks
    // (wrongly) are already there.
    @unlink($manifest);
}

$dep = deploy_site($ftp, rtrim($dir, '/') . '/', $manifest, $force);
$up  = (int) ($dep['uploaded'] ?? 0);
$bad = (int) ($dep['failed'] ?? 0);
$ok  = ($dep['status'] ?? '') !== 'fatal' && $bad === 0;

progress_emit([
    'type' => 'upload_result',
    'files' => $files,
    'uploaded' => $up,
    'failed' => $bad,
    'verify_missing' => $dep['verify_missing'] ?? [],
    'msg' => $dep['msg'] ?? '',
]);

if (!$ok) {
    progress_log($bad > 0 ? "{$bad} file(s) failed" : ($dep['msg'] ?? 'failed'), 'fatal');
    exit(1);
}
exit(0);
