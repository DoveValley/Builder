<?php
/**
 * Multisite per-row driver = process_row() — Phase 0d.
 *
 * Runs in NORMAL config mode (BASE_DIR available) and performs one row end-to-end:
 *   snapshot (once) → clone working dir → inject identity → spawn render child
 *   (build, worker-mode config) → deploy over FTP → clean up temp dirs.
 *
 * The build runs in a child process (render_site.php) because config's path
 * constants are immutable and must be worker-rooted from process start (§3). Deploy
 * runs here in the parent since deploy_site() takes explicit params, not constants.
 *
 * This unit is self-contained so the Phase 3 orchestrator can call it per row
 * (passing a shared --snapshot so the master is frozen once for the whole run).
 *
 * Usage:
 *   php multisite/build_one.php <row.json> [--snapshot=DIR] [--keep] [--force]
 *
 * row.json fields: master_id, domain (required); business, phone, tel, email,
 *   address, city, state, SS, zip, lat, lng, analytics_id, logo, web3forms_key,
 *   ftp_host, ftp_port, ftp_user, ftp_pass, ftp_path, ftp_passive.
 *
 * Progress: JSON-lines on stdout.
 */
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "build_one.php is CLI only\n"); exit(2); }

require __DIR__ . '/../config.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/multisite/clone.php';
require __DIR__ . '/../includes/multisite/inject.php';
require __DIR__ . '/../includes/multisite/deploy.php';

progress_set_sink(progress_jsonlines_sink());

// ── Parse args ──────────────────────────────────────────────────────────────
$rowFile = null; $snapshotArg = null; $keep = false; $force = false;
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--keep')                       $keep = true;
    elseif ($a === '--force')                  $force = true;
    elseif (str_starts_with($a, '--snapshot=')) $snapshotArg = substr($a, 11);
    elseif ($rowFile === null)                 $rowFile = $a;
}
if (!$rowFile || !is_file($rowFile)) {
    fwrite(STDERR, "usage: build_one.php <row.json> [--snapshot=DIR] [--keep] [--force]\n");
    exit(2);
}
$params = json_decode(file_get_contents($rowFile), true);
if (!is_array($params)) { fwrite(STDERR, "row.json is not valid JSON\n"); exit(2); }

$masterId = $params['master_id'] ?? '';
$domain   = $params['domain'] ?? '';
if ($masterId === '' || $domain === '') { fwrite(STDERR, "master_id and domain are required\n"); exit(2); }

// Slugify per the documented convention: keep hyphens, other unsafe chars → '_'
// (pmtraining-dallas.com → pmtraining-dallas_com).
$domainSlug = preg_replace('/[^a-z0-9-]+/i', '_', strtolower($domain));
$tmp = sys_get_temp_dir();
progress_log("Row: {$domain} (master {$masterId})");

// ── Snapshot (once). Reuse a shared --snapshot if given; otherwise own one. ──
$ownSnapshot = false;
if ($snapshotArg) {
    $snapshotDir = $snapshotArg;
    if (!is_dir($snapshotDir . '/data')) { progress_log("Snapshot invalid: {$snapshotDir}", 'fatal'); exit(1); }
} else {
    $snapshotDir = $tmp . '/ms_snapshot_' . $masterId;
    progress_log('Snapshotting master…');
    try { snapshot_master($masterId, $snapshotDir); }
    catch (Throwable $e) { progress_log('Snapshot failed: ' . $e->getMessage(), 'fatal'); exit(1); }
    $ownSnapshot = true;
}

$workingDir = $tmp . '/ms_work_' . $domainSlug;
$outputDir  = $tmp . '/ms_out_'  . $domainSlug;

// Best-effort cleanup helper.
$cleanup = function () use ($workingDir, $outputDir, $snapshotDir, $ownSnapshot, $keep) {
    if ($keep) { progress_log("--keep: working={$workingDir} output={$outputDir}"); return; }
    ms_delete_dir($workingDir);
    ms_delete_dir($outputDir);
    if ($ownSnapshot) ms_delete_dir($snapshotDir);
    progress_log('Cleaned up temp dirs.');
};

// ── Clone + inject ────────────────────────────────────────────────────────────
progress_log('Cloning working dir…');
clone_to_working_dir($snapshotDir, $workingDir, $masterId);
progress_log('Injecting identity…');
inject_params_into_working_dir($workingDir, $params);

// ── Build in a worker-mode child process ──────────────────────────────────────
$canonical = 'https://' . preg_replace('#^https?://#i', '', rtrim($domain, '/'));
$env = getenv();                       // inherit, then add worker vars
$env['MULTISITE_SITE_BASE']   = $workingDir;
$env['MULTISITE_OUTPUT_BASE'] = $outputDir;
$env['MULTISITE_CANONICAL']   = $canonical;
$env['MULTISITE_WEB3FORMS']   = $params['web3forms_key'] ?? '';

$cmd  = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/render_site.php');
$proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
if (!is_resource($proc)) { progress_log('Failed to spawn render worker.', 'fatal'); $cleanup(); exit(1); }

// Relay the child's JSON-lines progress straight through.
while (($line = fgets($pipes[1])) !== false) { echo $line; @flush(); }
fclose($pipes[1]);
$childErr  = stream_get_contents($pipes[2]); fclose($pipes[2]);
$buildCode = proc_close($proc);
if ($buildCode !== 0) {
    progress_log("Build worker failed (code {$buildCode}): " . trim($childErr), 'fatal');
    $cleanup();
    exit(1);
}

// ── Deploy (parent — deploy_site needs only params) ──────────────────────────
if (!empty($params['ftp_host']) && !empty($params['ftp_user'])) {
    $ftp = [
        'ftp_host'    => $params['ftp_host'] ?? '',
        'ftp_port'    => $params['ftp_port'] ?? 21,
        'ftp_user'    => $params['ftp_user'] ?? '',
        'ftp_pass'    => $params['ftp_pass'] ?? '',
        'ftp_path'    => $params['ftp_path'] ?? '/',
        'ftp_passive' => $params['ftp_passive'] ?? true,
    ];
    // Manifest persists per-domain OUTSIDE the ephemeral build (which is deleted).
    $manifestFile = BASE_DIR . '/sites/' . $masterId . '/multisite/manifests/' . $domainSlug . '.json';
    deploy_site($ftp, rtrim($outputDir, '/') . '/', $manifestFile, $force);
} else {
    progress_log('No FTP creds in row — skipping deploy (build only).', 'warn');
}

// ── Cleanup ──────────────────────────────────────────────────────────────────
$cleanup();
progress_log("Row complete: {$domain}", 'done');
exit(0);
