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
 *   2. For each file: FTP-download it, then FTP-upload the same bytes right
 *      back. A STOR always resets mtime to write-time on every FTP server —
 *      ordinary filesystem behavior, not a protocol extension like MFMT, so
 *      it needs nothing box-specific to work identically everywhere.
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

    $touched = 0; $failed = 0; $mismatched = 0;
    foreach (array_keys($manifest) as $file) {
        $tmp = tmpfile();
        $localPath = stream_get_meta_data($tmp)['uri'];
        if (!@ftp_get($conn, $localPath, $file, FTP_BINARY)) { $failed++; fclose($tmp); continue; }
        $hashBefore = md5_file($localPath);
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

    $ok = $failed === 0 && $mismatched === 0;
    $msg = "{$touched} file(s) refreshed";
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
        'skipped' => false,
        'message' => 'files: ' . $touch['message'] . ' cache: ' . ($purge['ok'] ? 'purged.' : 'purge FAILED — ' . $purge['message']),
    ];
}
