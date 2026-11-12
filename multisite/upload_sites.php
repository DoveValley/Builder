<?php
/**
 * Phase 5 — upload the sites this batch has already generated.
 *
 *   php multisite/upload_sites.php <master_id> --batch=bN [--jobs=N] [--limit=N] [--force] [--only=DOMAIN[,DOMAIN...]] [--wipe]
 *
 * --jobs=N uploads N domains concurrently, each in its own upload_one.php subprocess
 * (default 1 = sequential, today's long-standing behavior, unchanged unless asked
 * for). Safe to raise well past the box's core count — each upload is one FTP/SFTP
 * session to its own remote host, spent almost entirely waiting on the network, not
 * the CPU. Clamped to 16 regardless of what's requested.
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
require __DIR__ . '/../includes/multisite/run_pool.php';   // ms_run_pool()/ms_parse_line() — shared with run_campaign.php
require __DIR__ . '/../admin/infra/lib/state.php';   // infra_state_get_domain() — already-live skip

// Each domain's deploy_site() now runs in its own upload_one.php subprocess (see
// below), reporting progress as JSON lines — ms_parse_line() (includes/multisite/
// run_pool.php, shared with run_campaign.php) already surfaces warn/error/fatal
// lines and suppresses the per-file "Uploaded: x.html" noise, same effect this
// file used to get from its own progress_set_sink() override.

$args     = array_slice($argv, 1);
$masterId = (string) ($args[0] ?? '');
$batchId  = ''; $only = ''; $limit = 0; $jobs = 1;
$force    = in_array('--force', $args, true);
$wipe     = in_array('--wipe', $args, true);
foreach ($args as $a) {
    if (str_starts_with($a, '--batch='))  $batchId = substr($a, 8);
    if (str_starts_with($a, '--only='))   $only    = strtolower(substr($a, 7));
    if (str_starts_with($a, '--limit='))  $limit   = max(0, (int) substr($a, 8));
    // Clamped regardless of what's asked for — uploads are network-wait, not CPU
    // work, so this isn't bounded by core count the way build's --jobs is, but an
    // unbounded number would open that many simultaneous FTP/SFTP connections at
    // once (own outbound bandwidth, and to the fleet's remote hosts).
    if (str_starts_with($a, '--jobs='))    $jobs    = max(1, min(16, (int) substr($a, 7)));
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

echo ($jobs > 1 ? "Uploading {$jobs} at a time…\n" : "Uploading sequentially…\n");

// One temp row file + upload_one.php command per domain — same shape run_campaign.php
// uses for build_one.php. Deleted once the whole pool finishes (see below).
$jobList = []; $rowFiles = [];
foreach ($ready as $t) {
    $r = $t['row'];
    $rowFile = sys_get_temp_dir() . '/ms_upload_row_' . ms_domain_slug($t['domain']) . '.json';
    file_put_contents($rowFile, json_encode([
        'domain'       => $t['domain'],
        'master_id'    => $masterId,
        'dir'          => $t['dir'],
        'files'        => $t['files'],
        'force'        => $force,
        'wipe'         => $wipe,
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
    ]));
    $rowFiles[] = $rowFile;
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/upload_one.php') . ' ' . escapeshellarg($rowFile);
    $jobList[] = ['domain' => $t['domain'], 'cmd' => $cmd, 'attempts' => 0, 'files' => $t['files']];
}

// Reconstructs the exact "  domain   NNN file(s) … ✓/✗ …" line this file has always
// printed per domain — admin/multisite_api.php's 'upload_status' case greps stdout
// for this literal shape (no separate machine-readable channel exists for uploads),
// so --jobs=1 (or any concurrency) must keep emitting it, one complete line per
// finished domain, never interleaved with another domain's line.
$ok = 0; $fail = 0; $sent = 0;
// Per-domain ground truth from upload_one.php's own 'upload_result' event — used
// below to override the generic post-upload status refresh for any domain THIS run
// already knows failed, rather than trusting a separate, shallower check to re-derive
// the same answer.
$failedDomains = [];
$onComplete = function (array $job, array $m, int $done, int $total, int $dur) use (&$ok, &$fail, &$sent, &$failedDomains) {
    $files         = (int) ($m['files'] ?? $job['files'] ?? 0);
    $uploaded      = (int) ($m['uploaded'] ?? 0);
    $failedN       = (int) ($m['failed'] ?? ($m['status'] === 'failed' ? 1 : 0));
    $verifyMissing = $m['verify_missing'] ?? [];

    if ($m['status'] === 'ok' && $failedN === 0) {
        $ok++; $sent += $uploaded;
        return sprintf("  %-34s %d file(s) … ✓ %s\n", $job['domain'], $files, $uploaded > 0 ? "{$uploaded} uploaded" : 'already up to date');
    }
    $fail++;
    $failedDomains[$job['domain']] = $failedN . ' file(s) failed to upload'
        . ($verifyMissing ? ' — confirmed still missing on the server: '
            . implode(', ', array_slice($verifyMissing, 0, 5))
            . (count($verifyMissing) > 5 ? ', +' . (count($verifyMissing) - 5) . ' more' : '') : '.');
    $msg = $failedN > 0 ? "{$failedN} file(s) failed" : ($m['last'] ?: 'failed');
    return sprintf("  %-34s %d file(s) … ✗ %s\n", $job['domain'], $files, $msg);
};

// retries=0 — deploy_site() has no retry today (see its own comment on this), and
// this refactor isn't the place to introduce one; a domain that fails once still
// fails once, just now possibly alongside others instead of strictly in turn.
ms_run_pool($jobList, $jobs, 0, false, null, $onComplete);
foreach ($rowFiles as $rf) @unlink($rf);

/* Tell the go-live pipeline what this run just confirmed, same reason create_hosts.php
 * does for assign/host: its "upload" cell only trusts a stored, checked answer, and a
 * domain built entirely through the multisite wizard would otherwise never get one —
 * the Go Live button gates on exactly this cell. A full batch run (no --only) refreshes
 * the whole batch in one call — cheap, since the check is priced per BOX
 * (infra_hestia_content_run() caches per server_id). A --only run (a few named domains,
 * e.g. the Simple view's "Build & upload selected") scopes to just those domains instead
 * — re-verifying every other domain in a 100-row batch for a 1-domain upload paid for
 * boxes that have nothing to do with what was just sent. */
require_once __DIR__ . '/../admin/infra/lib/pipeline.php';
if ($onlyList) {
    infra_pipeline_refresh('upload', '', implode(',', $onlyList));
} else {
    infra_pipeline_refresh('upload', $masterId . '/' . $batchId);
}
// Override with ground truth for anything THIS run already proved failed. Found
// needed 2026-10-28: the refresh above only lists the REMOTE folder's top level,
// so a page folder an FTP drop left created-but-empty still reads as "has a real
// folder" — a connection loss deploy_site() correctly counted as failed came back
// out of the SAME refresh call as "ok" moments later, and every later consumer
// (the Bulk grid, the go-live gate, Batch Simple's own column) trusted that
// instead of what this run actually watched happen.
foreach ($failedDomains as $fdDomain => $fdNote) {
    infra_pipeline_set($fdDomain, 'upload', INFRA_STEP_FAIL, $fdNote, true);
}

/* Queue a file-date refresh for anything that is ALREADY PUBLIC. An upload stamps every
 * file it sends with this run's clock, so a batch re-uploaded in one go leaves its
 * domains carrying timestamps minutes apart from each other — measured on the real
 * fleet 2026-10-15, four domains released weeks apart all showed homepage dates inside
 * the same 98 seconds, because a later batch rebuild had overwritten the staggered
 * go-live dates. Before this, nothing ever ran the refresh a second time.
 *
 * Only live/releasing domains: a staged domain's file dates are not visible to anyone
 * yet, and its confirmed-live moment queues one anyway — queueing the other 87 rows of
 * a typical batch would be a lot of FTP work nobody can see the result of. */
require_once __DIR__ . '/../admin/infra/lib/golive_freshen.php';
$freshenQueued = 0;
foreach ($ready as $t) {
    $dom = strtolower(trim((string) ($t['domain'] ?? '')));
    if ($dom === '') continue;
    $rec = infra_state_get_domain($dom);
    if (!in_array((string) ($rec['status'] ?? ''), ['live', 'releasing'], true)) continue;
    if (infra_golive_freshen_queue($dom) !== '') $freshenQueued++;
}
if ($freshenQueued > 0) {
    echo "\nFile-date refresh queued for {$freshenQueued} live domain(s) — each runs at its own\n"
       . "staggered time (20 min to ~20 h) so the batch does not leave one shared timestamp.\n";
}

echo "\n" . str_repeat('=', 58) . "\n";
printf("DONE — %d uploaded, %d failed, %d file(s) sent.\n", $ok, $fail, $sent);
exit($fail > 0 ? 1 : 0);
