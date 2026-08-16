<?php
/**
 * Phase 5 — upload the sites this batch has already generated.
 *
 *   php multisite/upload_sites.php <master_id> --batch=bN [--jobs=N] [--limit=N] [--force] [--only=DOMAIN]
 *
 * Generating and uploading used to be one act: build to a temp directory, push it, then
 * delete it. That made "build fifty and look at them before anything goes live"
 * impossible, and it meant a deploy failure cost the build as well as the upload.
 *
 * Now the generate step keeps each build under the batch's output/ and this step sends
 * it. Which means it can be run again after a failure without rebuilding anything, and
 * it can be run for one domain while the rest wait.
 *
 * Credentials come from the target list — ftp_host, ftp_user, ftp_pass and ftp_path,
 * written there by "Create host". A row without them is reported and skipped, because
 * a site with nowhere to go is a fact worth seeing, not an error to abort on.
 *
 * Uploads are INCREMENTAL by md5 manifest, and the manifest lives outside the output
 * directory, so re-running sends only what changed. --force sends everything.
 */
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "upload_sites.php is CLI only\n"); exit(2); }

require __DIR__ . '/../config.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/multisite/params.php';
require __DIR__ . '/../includes/multisite/batch.php';
require __DIR__ . '/../includes/multisite/deploy.php';

/* deploy_site() reports through the progress system, whose default sink is SSE — which
 * on a CLI script means "data: {...}" frames wrapping every file. This log is read by a
 * person and by the panel, so keep only the events worth a line and print them plainly.
 * Per-file "Uploaded: x.html" is noise at 17 files and unreadable at 4,000. */
progress_set_sink(function (array $e): void {
    $t = $e['type'] ?? '';
    if ($t === 'progress') return;                       // counted in the summary instead
    if ($t === 'log' && str_starts_with((string) ($e['msg'] ?? ''), 'Uploaded: ')) return;
    $msg = trim((string) ($e['msg'] ?? ''));
    if ($msg === '') return;
    if ($t === 'fatal' || $t === 'error') { echo "      ! {$msg}\n"; return; }
    if ($t === 'warn')                    { echo "      ~ {$msg}\n"; return; }
});

$args     = array_slice($argv, 1);
$masterId = (string) ($args[0] ?? '');
$batchId  = ''; $only = ''; $limit = 0; $force = in_array('--force', $args, true);
foreach ($args as $a) {
    if (str_starts_with($a, '--batch='))  $batchId = substr($a, 8);
    if (str_starts_with($a, '--only='))   $only    = strtolower(substr($a, 7));
    if (str_starts_with($a, '--limit='))  $limit   = max(0, (int) substr($a, 8));
}
if ($masterId === '' || $batchId === '') { fwrite(STDERR, "usage: upload_sites.php <master_id> --batch=bN [--only=DOMAIN] [--limit=N] [--force]\n"); exit(2); }
if (!ms_batch_exists($masterId, $batchId)) { fwrite(STDERR, "No such batch: {$masterId}/{$batchId}\n"); exit(2); }

$csvPath = ms_batch_dir($masterId, $batchId) . '/params.csv';
if (!is_file($csvPath)) { fwrite(STDERR, "This batch has no target list.\n"); exit(2); }

$parsed = ms_parse_csv($csvPath);
$built  = ms_batch_built($masterId, $batchId);

/* Line up each row with the build waiting for it. Three states worth telling apart:
   nothing generated, no credentials, and ready. */
$ready = []; $noBuild = []; $noCreds = [];
foreach ($parsed['rows'] as $r) {
    $domain = strtolower(trim((string) ($r['domain'] ?? '')));
    if ($domain === '') continue;
    if ($only !== '' && $domain !== $only) continue;
    $slug = trim(preg_replace('/[^a-z0-9]+/', '_', $domain), '_');

    if (!isset($built[$slug]))                         { $noBuild[] = $domain; continue; }
    if (trim((string) ($r['ftp_host'] ?? '')) === ''
        || trim((string) ($r['ftp_user'] ?? '')) === '') { $noCreds[] = $domain; continue; }
    $ready[] = ['domain' => $domain, 'row' => $r, 'dir' => $built[$slug]['dir'], 'files' => $built[$slug]['files']];
}
if ($limit > 0) $ready = array_slice($ready, 0, $limit);

printf("Batch %s/%s — %d ready to upload", $masterId, $batchId, count($ready));
if ($noBuild) printf(", %d not generated yet", count($noBuild));
if ($noCreds) printf(", %d without credentials", count($noCreds));
echo ($force ? "  [--force: full re-upload]" : "") . "\n";
foreach ($noBuild as $d) echo "  – {$d}: nothing generated yet — run Generate sites first\n";
foreach ($noCreds as $d) echo "  – {$d}: no FTP credentials — run Create host first\n";
if (!$ready) { echo "\nNothing to upload.\n"; exit(0); }
echo str_repeat('-', 58) . "\n";

$manifestDir = BASE_DIR . '/sites/' . $masterId . '/multisite/manifests';
if (!is_dir($manifestDir)) @mkdir($manifestDir, 0775, true);

$ok = 0; $fail = 0; $sent = 0;
foreach ($ready as $t) {
    $r = $t['row'];
    $ftp = [
        'ftp_protocol' => (($r['ftp_protocol'] ?? 'ftp') === 'sftp') ? 'sftp' : 'ftp',
        'ftp_host'     => $r['ftp_host'] ?? '',
        'ftp_port'     => $r['ftp_port'] ?? '',
        'ftp_user'     => $r['ftp_user'] ?? '',
        'ftp_pass'     => $r['ftp_pass'] ?? '',
        // Written by Create host. Left blank, deploy_site() detects it after login —
        // the two host shapes disagree and a wrong guess uploads into a folder nginx
        // never reads.
        'ftp_path'     => $r['ftp_path'] ?? '',
        'ftp_passive'  => $r['ftp_passive'] ?? true,
    ];
    $slug     = trim(preg_replace('/[^a-z0-9]+/', '_', $t['domain']), '_');
    $manifest = $manifestDir . '/' . $slug . '.json';

    printf("  %-34s %d file(s) … ", $t['domain'], $t['files']);
    $dep = deploy_site($ftp, rtrim($t['dir'], '/') . '/', $manifest, $force);
    $up  = (int) ($dep['uploaded'] ?? 0);
    $bad = (int) ($dep['failed'] ?? 0);

    if (($dep['status'] ?? '') === 'fatal' || $bad > 0) {
        $fail++;
        printf("✗ %s\n", $bad > 0 ? "{$bad} file(s) failed" : ($dep['msg'] ?? 'failed'));
    } else {
        $ok++; $sent += $up;
        printf("✓ %s\n", $up > 0 ? "{$up} uploaded" : 'already up to date');
    }
}

echo "\n" . str_repeat('=', 58) . "\n";
printf("DONE — %d uploaded, %d failed, %d file(s) sent.\n", $ok, $fail, $sent);
exit($fail > 0 ? 1 : 0);
