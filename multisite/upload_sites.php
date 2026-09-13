<?php
/**
 * Phase 5 — upload the sites this batch has already generated.
 *
 *   php multisite/upload_sites.php <master_id> --batch=bN [--jobs=N] [--limit=N] [--force] [--only=DOMAIN[,DOMAIN...]] [--wipe]
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
 *
 * --wipe deletes every file already on a row's remote host before uploading fresh —
 * for a domain whose live files have drifted (a renamed/removed page left orphaned
 * remotely, which --force alone does not clean up: force re-sends everything the
 * CURRENT build has, it never deletes what the build no longer produces). Requires
 * --only, on purpose — there is no batch-wide "wipe everything" here, the same way
 * there is no batch-wide teardown in the Infrastructure console's Danger Zone.
 */
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "upload_sites.php is CLI only\n"); exit(2); }

require __DIR__ . '/../config.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/multisite/params.php';
require __DIR__ . '/../includes/multisite/batch.php';
require __DIR__ . '/../includes/multisite/deploy.php';
require __DIR__ . '/../admin/infra/lib/state.php';   // infra_state_get_domain() — already-live skip

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
$batchId  = ''; $only = ''; $limit = 0;
$force    = in_array('--force', $args, true);
$wipe     = in_array('--wipe', $args, true);
foreach ($args as $a) {
    if (str_starts_with($a, '--batch='))  $batchId = substr($a, 8);
    if (str_starts_with($a, '--only='))   $only    = strtolower(substr($a, 7));
    if (str_starts_with($a, '--limit='))  $limit   = max(0, (int) substr($a, 8));
}
// One or more comma-separated domains — same list shape run_campaign.php's --only
// already accepts, so the batch page's "Only this domain" field means the same thing
// on both the Generate and Upload cards.
$onlyList = $only !== '' ? array_values(array_filter(array_map('trim', explode(',', $only)))) : [];
if ($masterId === '' || $batchId === '') { fwrite(STDERR, "usage: upload_sites.php <master_id> --batch=bN [--only=DOMAIN[,DOMAIN...]] [--limit=N] [--force] [--wipe]\n"); exit(2); }
if (!ms_batch_exists($masterId, $batchId)) { fwrite(STDERR, "No such batch: {$masterId}/{$batchId}\n"); exit(2); }
// Wiping is scoped to explicitly named domains only — never the whole batch. This is
// enforced again in admin/multisite_api.php before the job is even launched; checking
// here too means a direct CLI call is just as safe as one driven by the panel.
if ($wipe && !$onlyList) { fwrite(STDERR, "--wipe requires --only=DOMAIN[,DOMAIN...] — refusing to wipe an entire batch.\n"); exit(2); }

$csvPath = ms_batch_dir($masterId, $batchId) . '/params.csv';
if (!is_file($csvPath)) { fwrite(STDERR, "This batch has no target list.\n"); exit(2); }

$parsed = ms_parse_csv($csvPath);
$built  = ms_batch_built($masterId, $batchId);

/* Line up each row with the build waiting for it. Three states worth telling apart:
   nothing generated, no credentials, and ready. */
$ready = []; $noBuild = []; $noCreds = []; $liveSkipped = [];
foreach ($parsed['rows'] as $r) {
    $domain = strtolower(trim((string) ($r['domain'] ?? '')));
    if ($domain === '') continue;
    if ($onlyList && !in_array($domain, $onlyList, true)) continue;
    // Same rule as Generate: a domain already confirmed live is skipped unless named
    // explicitly in --only, so re-uploading a batch that's grown new sibling rows
    // never silently re-pushes files onto a site that's already public.
    if (!$onlyList) {
        $rec = infra_state_get_domain($domain);
        if ($rec && ($rec['status'] ?? '') === 'live') { $liveSkipped[] = $domain; continue; }
    }
    // Must match ms_batch_output_dir()'s own derivation (ms_domain_slug()) — ms_batch_built()
    // keys its results by the actual directory name on disk.
    $slug = ms_domain_slug($domain);

    if (!isset($built[$slug]))                         { $noBuild[] = $domain; continue; }
    if (trim((string) ($r['ftp_host'] ?? '')) === ''
        || trim((string) ($r['ftp_user'] ?? '')) === '') { $noCreds[] = $domain; continue; }
    $ready[] = ['domain' => $domain, 'row' => $r, 'dir' => $built[$slug]['dir'], 'files' => $built[$slug]['files']];
}
if ($limit > 0) $ready = array_slice($ready, 0, $limit);

printf("Batch %s/%s — %d ready to upload", $masterId, $batchId, count($ready));
if ($noBuild) printf(", %d not generated yet", count($noBuild));
if ($noCreds) printf(", %d without credentials", count($noCreds));
if ($liveSkipped) printf(", %d already-live skipped", count($liveSkipped));
echo ($force ? "  [--force: full re-upload]" : "") . ($wipe ? "  [--wipe: remote files deleted first]" : "") . "\n";
foreach ($noBuild as $d) echo "  – {$d}: nothing generated yet — run Generate sites first\n";
foreach ($noCreds as $d) echo "  – {$d}: no FTP credentials — run Create host first\n";
foreach ($liveSkipped as $d) echo "  – {$d}: already live — name it in --only to upload to it anyway\n";
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
    // Must derive the same way as ms_batch_output_dir() (includes/multisite/batch.php)
    // — see ms_domain_slug()'s own docblock for why a lossy fold here is a real
    // cross-domain-deploy risk, not just a cosmetic mismatch.
    $slug     = ms_domain_slug($t['domain']);
    $manifest = $manifestDir . '/' . $slug . '.json';

    if ($wipe) {
        printf("  %-34s wiping remote files … ", $t['domain']);
        $wiped = ms_wipe_remote($ftp);
        // ms_wipe_remote() reports status 'done' even when some files/dirs failed to
        // delete (permission error, mid-sweep disconnect) — it only distinguishes that
        // in the 'failed' count and a lower log level, not in 'status'. Checking only
        // for 'fatal' here meant a partial wipe still printed a checkmark and fell
        // straight through to uploading fresh content on top of a remote that may
        // still hold stale files — on a REAL client domain, not just the disposable
        // test slot.
        if (($wiped['status'] ?? '') === 'fatal' || (int)($wiped['failed'] ?? 0) > 0) {
            $fail++;
            printf("✗ %s\n", $wiped['msg'] ?? 'wipe failed');
            continue;   // don't upload into a host we couldn't confirm is now empty
        }
        printf("✓ %s\n", $wiped['msg'] ?? 'wiped');
        // The manifest recorded what was on the server before the wipe — now false,
        // so leaving it would make deploy_site() skip re-sending files it thinks
        // (wrongly) are already there.
        @unlink($manifest);
    }

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

/* Tell the go-live pipeline what this run just confirmed, same reason create_hosts.php
 * does for assign/host: its "upload" cell only trusts a stored, checked answer, and a
 * domain built entirely through the multisite wizard would otherwise never get one —
 * the Go Live button gates on exactly this cell. One call for the whole batch: the
 * check is priced per BOX (infra_hestia_content_run() caches per server_id), so this
 * costs the same whether the batch is 1 domain or 50. */
require_once __DIR__ . '/../admin/infra/lib/pipeline.php';
infra_pipeline_refresh('upload', $masterId . '/' . $batchId);

echo "\n" . str_repeat('=', 58) . "\n";
printf("DONE — %d uploaded, %d failed, %d file(s) sent.\n", $ok, $fail, $sent);
exit($fail > 0 ? 1 : 0);
