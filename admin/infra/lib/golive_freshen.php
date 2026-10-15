<?php
/**
 * infra/lib/golive_freshen.php — refresh a domain's on-disk file dates + CDN
 * cache the moment it is CONFIRMED live, so its file footprint matches the
 * date it actually went live rather than the date its batch was built.
 *
 * Why this exists: a batch of N domains is built and uploaded together, then
 * released on N different staggered dates. Every file on every domain still
 * carries the ORIGINAL build/upload mtime, so a static site served directly
 * shows a Last-Modified header (and matching ETag) from build day, not its
 * real go-live day — a real, externally-visible mismatch. Verified against a
 * real live domain 2026-09-24: HTTP mtime does NOT update on its own.
 *
 * How it works — no new upload mechanism, no SSH/SFTP dependency (confirmed
 * fleet-wide, every domain's FTP creds are plain FTP, and Hestia's per-domain
 * FTP accounts have no shell access — see project memory):
 *   1. Read the domain's existing deploy manifest (written once at build time
 *      by includes/multisite/deploy.php — the exact file list already deployed).
 *   2. For each file: FTP-download it, check the bytes still hash to what the
 *      manifest recorded at upload time, then FTP-upload those same bytes
 *      right back. A STOR always resets mtime to write-time on every FTP
 *      server — ordinary filesystem behavior, not a protocol extension like
 *      MFMT, so it needs nothing box-specific to work identically everywhere.
 *      The hash gate is what makes the download/upload round trip safe: a
 *      file that changed under us belongs to a newer upload, and rewriting
 *      the bytes we just read would silently revert it.
 *   3. Purge the domain's Cloudflare cache. Confirmed necessary: the origin
 *      mtime updates immediately, but Cloudflare serves the OLD cached
 *      Last-Modified/ETag until its cache is purged or naturally expires.
 *
 * Deliberately separate from includes/multisite/deploy.php: that module's job
 * is "get new content onto the server"; this one's job is "make what's already
 * there look like it was deployed today", a different operation with a
 * different trigger (confirmed-live, not build-time).
 */

require_once __DIR__ . '/state.php';
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/cloudflare.php';
require_once __DIR__ . '/fleet.php';   // infra_cf_zone_index()
// batch.php resolves paths through BASE_DIR, which only config.php defines — and
// config.php is a WEB bootstrap (it calls session_start()), never loaded by the cron
// that does this module's actual work. So every freshen attempted from cron died on
// "Undefined constant BASE_DIR" before it opened a single connection: 34 fatals in
// infra-golive.log, taking the rest of that tick with them, from the day this module
// was written until 2026-10-15. The feature had never once run outside a web request
// or a hand-run script, and nothing said so — found only by reading the cron log while
// testing something else. Same value as BASE_DIR (config.php sets it to its own dir,
// which is this file's third parent), defined here so the module stands alone in CLI
// without dragging a session into a cron.
if (!defined('BASE_DIR')) define('BASE_DIR', dirname(__DIR__, 3));
require_once dirname(__DIR__, 3) . '/includes/multisite/batch.php';    // ms_domain_slug(), ms_batch_dir()
require_once dirname(__DIR__, 3) . '/includes/multisite/params.php';   // ms_parse_csv()

/** @return array{masterId:string,batchId:string}|null */
function infra_golive_freshen_split_batch(string $batch): ?array
{
    $parts = explode('/', $batch, 2);
    if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') return null;
    return ['masterId' => $parts[0], 'batchId' => $parts[1]];
}

function infra_golive_freshen_manifest_path(string $domain, string $masterId): ?string
{
    $path = dirname(__DIR__, 3) . '/sites/' . $masterId . '/multisite/manifests/' . ms_domain_slug($domain) . '.json';
    return is_file($path) ? $path : null;
}

/**
 * The real per-domain FTP connection details — same row deploy_site() itself
 * used, not a reconstruction. fleet.db's own `domains` table does not carry
 * ftp_path (confirmed by schema inspection), and guessing it wrong is a
 * 100%-failure bug, not a partial one: found exactly this way on first real
 * test against baileyrestoration.com (ftp_path is a non-obvious
 * "/home/<ftp_user>", not blank as pipeline.php's provisioning default
 * would suggest — export_creds.php fills in the real value from Hestia
 * later, params.csv is the only place that later value lives).
 *
 * @return array|null the row (ftp_host/port/user/pass/path/passive/protocol keys)
 */
function infra_golive_freshen_params_row(string $domain, string $masterId, string $batchId): ?array
{
    $csv = ms_batch_dir($masterId, $batchId) . '/params.csv';
    $parsed = ms_parse_csv($csv);
    foreach ($parsed['rows'] as $row) {
        if (strcasecmp((string) ($row['domain'] ?? ''), $domain) === 0) return $row;
    }
    return null;
}

/**
 * Download-then-reupload every file in the manifest over plain FTP, from the
 * SAME connection details (host/port/user/pass/path/passive) deploy_site()
 * itself would use for this domain. No SFTP path: pipeline.php provisions
 * every domain's credentials as plain FTP — see project_city_research_tool
 * session notes for why SFTP isn't a real fleet-wide option (Hestia's
 * per-domain FTP accounts have no shell access, and no SSH credential is
 * stored for any box in this app).
 *
 * @param array $ftp same shape as deploy_site()'s $ftp param (a params.csv row)
 * @param array $manifest relative-path => hash (only the keys are used)
 * @return array{ok:bool,touched:int,failed:int,message:string}
 */
function infra_golive_freshen_ftp_touch(array $ftp, array $manifest): array
{
    if (strtolower(trim((string) ($ftp['ftp_protocol'] ?? 'ftp'))) === 'sftp') {
        return ['ok' => false, 'touched' => 0, 'failed' => 0,
                'message' => 'this domain is configured for SFTP, which this module does not support '
                           . '(Hestia FTP accounts have no shell access, so SFTP is not a real fleet-wide option).'];
    }
    if (!function_exists('ftp_connect')) {
        return ['ok' => false, 'touched' => 0, 'failed' => 0, 'message' => 'PHP FTP extension not available.'];
    }
    $host = preg_replace('#^s?ftps?://#i', '', trim((string) ($ftp['ftp_host'] ?? '')));
    $port = (int) ($ftp['ftp_port'] ?? 0) ?: 21;
    $user = (string) ($ftp['ftp_user'] ?? '');
    $pass = (string) ($ftp['ftp_pass'] ?? '');
    $path = trim((string) ($ftp['ftp_path'] ?? ''));
    $pasvRaw = strtolower(trim((string) ($ftp['ftp_passive'] ?? '')));
    $passive = !in_array($pasvRaw, ['0', 'no', 'false', 'off', 'active'], true);

    if ($host === '' || $user === '' || $pass === '') {
        return ['ok' => false, 'touched' => 0, 'failed' => 0, 'message' => 'FTP credentials incomplete.'];
    }

    $conn = @ftp_connect($host, $port, 15);
    if (!$conn) return ['ok' => false, 'touched' => 0, 'failed' => 0, 'message' => "Could not connect to {$host}:{$port}."];
    if (!@ftp_login($conn, $user, $pass)) {
        return ['ok' => false, 'touched' => 0, 'failed' => 0, 'message' => 'FTP login failed.'];
    }
    ftp_pasv($conn, $passive);
    // Same rule as deploy.php: blank ftp_path means the login's own cwd already
    // IS the docroot; a set path needs an explicit chdir before anything else.
    if ($path !== '' && !@ftp_chdir($conn, rtrim($path, '/'))) {
        return ['ok' => false, 'touched' => 0, 'failed' => 0, 'message' => "Could not chdir to {$path}."];
    }

    $touched = 0; $failed = 0; $mismatched = 0; $changed = 0;
    foreach ($manifest as $file => $expectHash) {
        $tmp = tmpfile();
        $localPath = stream_get_meta_data($tmp)['uri'];
        if (!@ftp_get($conn, $localPath, $file, FTP_BINARY)) { $failed++; fclose($tmp); continue; }
        $hashBefore = md5_file($localPath);
        // Only rewrite bytes we can prove are the ones the last deploy put there.
        // This is a date refresh, not a deploy: download-then-reupload means an
        // upload landing in the gap would be silently overwritten with the older
        // file we had just read. The manifest already stores every file's md5 at
        // upload time (includes/multisite/deploy.php), so anything that doesn't
        // match is newer than the manifest — exactly the file we must not touch.
        // Verified against a real live domain 2026-10-15: text files uploaded in
        // FTP_ASCII still hash identically when read back in FTP_BINARY, so this
        // compares cleanly for every file type in the manifest.
        $expectHash = is_string($expectHash) ? strtolower(trim($expectHash)) : '';
        if ($expectHash === '' || $hashBefore !== $expectHash) { $changed++; fclose($tmp); continue; }
        if (!@ftp_put($conn, $file, $localPath, FTP_BINARY)) { $failed++; fclose($tmp); continue; }
        fclose($tmp);
        // Trust nothing that isn't checked: re-read what actually landed and
        // compare, rather than assuming a successful ftp_put() means the
        // bytes on the server match what was sent.
        $tmp2 = tmpfile();
        $localPath2 = stream_get_meta_data($tmp2)['uri'];
        if (@ftp_get($conn, $localPath2, $file, FTP_BINARY) && md5_file($localPath2) === $hashBefore) {
            $touched++;
        } else {
            $mismatched++;
        }
        fclose($tmp2);
    }
    ftp_close($conn);

    // Nothing refreshed is not success, even when every single file was skipped
    // for a good reason — that run left the dates exactly as it found them.
    $ok = $failed === 0 && $mismatched === 0 && $changed === 0 && $touched > 0;
    if ($changed > 0 && $changed === count($manifest)) {
        // Not the upload race this check exists for: a race touches a file or two.
        // Everything differing means the manifest no longer describes what is on
        // the server at all (stale manifest, or files replaced outside the panel).
        return ['ok' => false, 'touched' => 0, 'failed' => $failed,
                'message' => "nothing refreshed — all {$changed} file(s) differ from the deploy manifest, "
                           . 'so the manifest is stale rather than one upload racing this refresh. '
                           . 'Re-upload this domain to rebuild the manifest.'];
    }
    $msg = "{$touched} file(s) refreshed";
    if ($changed) $msg .= ", {$changed} skipped (changed since the last deploy — left alone so a newer upload is not overwritten)";
    if ($failed) $msg .= ", {$failed} failed to transfer";
    if ($mismatched) $msg .= ", {$mismatched} came back changed (content mismatch — investigate before trusting this domain's files)";
    return ['ok' => $ok, 'touched' => $touched, 'failed' => $failed, 'message' => $msg . '.'];
}

/** Full-zone purge (simpler and safer than per-file purging near the CF API's per-request URL limit). */
function infra_golive_freshen_purge(string $domain): array
{
    $idx = infra_cf_zone_index();
    $z = $idx[strtolower($domain)] ?? null;
    if (!$z || !$z['zone_id']) return ['ok' => false, 'message' => 'No Cloudflare zone found for this domain.'];

    $account = null;
    foreach (infra_cf_accounts() as $a) {
        if (($a['id'] ?? '') === $z['account_id']) { $account = $a; break; }
    }
    if (!$account) return ['ok' => false, 'message' => 'Cloudflare account for this zone is not configured here.'];

    $r = cf_purge_cache($account, $z['zone_id']);
    return $r;
}

/**
 * The one entry point: touch every deployed file's mtime + purge the CDN
 * cache for one domain. Called once, right when a domain is CONFIRMED live
 * (infra_golive_refresh_live()) — not at release, since NS propagation means
 * "released" and "actually resolving" can be hours apart.
 *
 * @return array{ok:bool,message:string,skipped:bool}
 */
function infra_golive_freshen_domain(string $domain): array
{
    $rec = infra_state_get_domain($domain);
    if (!$rec) return ['ok' => false, 'skipped' => true, 'message' => 'not in fleet state'];

    $split = infra_golive_freshen_split_batch((string) ($rec['batch'] ?? ''));
    if (!$split) return ['ok' => false, 'skipped' => true, 'message' => 'no batch on record — nothing to refresh'];

    $manifestPath = infra_golive_freshen_manifest_path($domain, $split['masterId']);
    if (!$manifestPath) {
        return ['ok' => false, 'skipped' => true, 'message' => 'no deploy manifest found — nothing to refresh'];
    }
    $manifest = json_decode((string) file_get_contents($manifestPath), true);
    if (!is_array($manifest) || !$manifest) {
        return ['ok' => false, 'skipped' => true, 'message' => 'manifest is empty or unreadable'];
    }

    $ftp = infra_golive_freshen_params_row($domain, $split['masterId'], $split['batchId']);
    if (!$ftp) {
        return ['ok' => false, 'skipped' => true, 'message' => 'no params.csv row found for this domain — cannot resolve its real FTP path'];
    }

    $touch = infra_golive_freshen_ftp_touch($ftp, $manifest);
    $purge = infra_golive_freshen_purge($domain);

    return [
        'ok'      => $touch['ok'] && $purge['ok'],
        // The files alone, reported separately because they are the part that either
        // worked or didn't. The Cloudflare purge is a known-degraded extra — several
        // accounts' API tokens still lack Cache Purge permission, which only delays
        // the new date on edge-cached assets by a few hours. Folding that into one
        // flag would leave the panel's warning permanently lit for a condition we
        // already know about, which is how a warning stops being read. The purge
        // outcome stays in the message either way.
        'files_ok' => $touch['ok'],
        'skipped' => false,
        'message' => 'files: ' . $touch['message'] . ' cache: ' . ($purge['ok'] ? 'purged.' : 'purge FAILED — ' . $purge['message']),
    ];
}

// ── The queue ─────────────────────────────────────────────────────────────────
//
// Freshening used to run inline, once, the moment a domain was confirmed live. Two
// holes in that, both visible on the real fleet on 2026-10-15:
//
//   1. It never ran again. Any later rebuild + upload re-stamped every file with that
//      batch run's clock, so four domains released weeks apart all carried homepage
//      dates inside the same 98 seconds of one October night — exactly the batch
//      footprint the module was written to remove.
//   2. Running inline means several domains confirmed live in one cron tick get
//      freshened within seconds of each other, rebuilding the cluster on a new date.
//
// So: enqueue with a per-domain delay instead, and let the cron drain it. An FTP STOR
// can only ever stamp a file "now", so WHEN it runs is the only lever on the date —
// which makes the delay the whole mechanism, not a nicety.

/**
 * How long after being queued this domain's freshen comes due, in minutes.
 *
 * Deterministic per domain (hash, not rand) so a domain keeps the same slot across
 * re-uploads rather than drawing a new one every time, and so two domains in one batch
 * land hours apart instead of seconds. ~20 minutes to ~20 hours.
 */
function infra_golive_freshen_offset_minutes(string $domain): int
{
    $h = substr(md5('freshen|' . strtolower(trim($domain))), 0, 8);
    return 20 + (hexdec($h) % 1180);
}

/**
 * Queue this domain for a file-date refresh. Cheap — one state write, no FTP.
 *
 * $force re-queues even when something is already pending; the default leaves an
 * existing (earlier) slot alone so repeated uploads can't keep pushing it later.
 */
function infra_golive_freshen_queue(string $domain, bool $force = false): string
{
    $domain = strtolower(trim($domain));
    $rec = infra_state_get_domain($domain);
    if (!$rec) return '';
    if (!$force && trim((string) ($rec['freshen_due_at'] ?? '')) !== '') {
        return (string) $rec['freshen_due_at'];
    }
    $due = (new DateTime('now', new DateTimeZone(INFRA_TZ)))
        ->modify('+' . infra_golive_freshen_offset_minutes($domain) . ' minutes')
        ->format('Y-m-d H:i:s');
    infra_state_upsert_domain(['domain' => $domain, 'freshen_due_at' => $due]);
    return $due;
}

/** Domains whose queued freshen is due now, oldest slot first. */
function infra_golive_freshen_due(): array
{
    $now = infra_now();
    $out = [];
    foreach (infra_state_all_domains() as $dom => $rec) {
        $due = trim((string) ($rec['freshen_due_at'] ?? ''));
        if ($due !== '' && $due <= $now) $out[$dom] = $due;
    }
    asort($out);
    return $out;
}

/**
 * Run the due freshens, newest-due last, up to $cap.
 *
 * Capped because each one downloads and re-uploads every file on the domain over FTP —
 * minutes of work, not milliseconds — and this is called from a cron that fires every
 * 10 minutes. The queue slot is cleared whether the refresh worked or not: a domain
 * that fails (no manifest, bad credentials) would otherwise be retried on every single
 * tick forever, and its next upload or go-live will queue it again anyway.
 *
 * Which is exactly why the outcome is RECORDED as the slot is cleared. A cleared
 * failure leaves a record identical to a success — nothing pending, nothing wrong —
 * and the only trace was a line in a cron log nobody reads. The panel reads these
 * three fields back (infra_fleet_view_rows() → the rollout detail popup), so a
 * refresh that failed, or that skipped every file, says so on the domain it
 * happened to. Same lesson as the job-status liveness fix: work that quietly did
 * not happen has to be visible, not inferred from silence.
 *
 * @return array<string,string> domain => what happened
 */
function infra_golive_freshen_run_due(int $cap = 3): array
{
    require_once __DIR__ . '/pipeline.php';
    $done = [];
    foreach (infra_golive_freshen_due() as $domain => $due) {
        if (count($done) >= $cap) break;
        // Same per-domain lock an interactive go-live click takes, so a freshen can't
        // run twice at once if one tick overruns into the next.
        $r = infra_pipeline_lock($domain, 'golive', fn() => infra_golive_freshen_domain($domain));
        $msg = trim((string) ($r['message'] ?? 'no result'));
        infra_state_upsert_domain([
            'domain'            => $domain,
            'freshen_due_at'    => '',
            'freshen_last_at'   => infra_now(),
            // files_ok, not ok — see infra_golive_freshen_domain(). A skipped domain
            // (no manifest, no params row) has neither key and correctly reads 'no'.
            'freshen_last_ok'   => !empty($r['files_ok']) ? 'yes' : 'no',
            'freshen_last_note' => $msg,
        ]);
        $done[$domain] = $msg;
    }
    return $done;
}
