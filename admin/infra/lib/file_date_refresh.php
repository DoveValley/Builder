<?php
/**
 * infra/lib/file_date_refresh.php — "File Date Refresh" tab: on-demand file-date
 * spreading for already-live domains, so a handful picked at once don't all end up
 * with the same on-disk timestamp.
 *
 * Deliberately independent of golive_freshen.php — own queue table
 * (file_date_refresh in fleet.db), own cron (cron/filedate_tick.php), own log.
 * Deleting this feature entirely does not touch golive_freshen's state or schedule.
 * The only things reused are the two primitives this whole codebase shares for
 * their respective services: the Cloudflare client (cloudflare.php) and the
 * params.csv FTP-creds convention every deploy caller already resolves itself.
 *
 * What it does, and does not do: re-uploads a picked domain's files byte-for-byte
 * unchanged over FTP (an FTP STOR always resets mtime to write-time — ordinary
 * filesystem behavior) and purges Cloudflare's cache so the new date is actually
 * visible. No content is generated, regenerated or altered — this only touches
 * dates, which is why it's safe to run with no API cost and no risk to locked
 * content blocks.
 */

require_once __DIR__ . '/state.php';
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/cloudflare.php';
require_once __DIR__ . '/fleet.php';   // infra_cf_zone_index()
// batch.php/params.php resolve paths through BASE_DIR, which only config.php
// defines — and config.php is a WEB bootstrap (session_start()), never loaded by
// the cron that drains this queue. See golive_freshen.php's docblock for the 34
// silent cron fatals this exact gap caused there before anyone noticed.
if (!defined('BASE_DIR')) define('BASE_DIR', dirname(__DIR__, 3));
require_once dirname(__DIR__, 3) . '/includes/multisite/batch.php';    // ms_domain_slug(), ms_batch_dir()
require_once dirname(__DIR__, 3) . '/includes/multisite/params.php';   // ms_parse_csv()

/** @return array{masterId:string,batchId:string}|null */
function infra_filedate_split_batch(string $batch): ?array
{
    $parts = explode('/', $batch, 2);
    if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') return null;
    return ['masterId' => $parts[0], 'batchId' => $parts[1]];
}

function infra_filedate_manifest_path(string $domain, string $masterId): ?string
{
    $path = dirname(__DIR__, 3) . '/sites/' . $masterId . '/multisite/manifests/' . ms_domain_slug($domain) . '.json';
    return is_file($path) ? $path : null;
}

/**
 * The real per-domain FTP connection details — same row deploy_site() itself uses.
 * Own copy, not a call into golive_freshen.php's identical-looking function: this
 * feature stays independent of that module's files entirely, per design. Nothing
 * in this codebase exports this lookup as a shared helper (every deploy_site()
 * caller resolves its own params.csv row), so a private copy here matches existing
 * practice rather than inventing a new cross-module dependency.
 *
 * @return array|null the row (ftp_host/port/user/pass/path/passive/protocol keys)
 */
function infra_filedate_params_row(string $domain, string $masterId, string $batchId): ?array
{
    $csv = ms_batch_dir($masterId, $batchId) . '/params.csv';
    $parsed = ms_parse_csv($csv);
    foreach ($parsed['rows'] as $row) {
        if (strcasecmp((string) ($row['domain'] ?? ''), $domain) === 0) return $row;
    }
    return null;
}

/**
 * Download-then-reupload every file in the manifest over plain FTP, byte for byte.
 * Own copy of golive_freshen.php's proven technique (same hash-gate safety: a file
 * that no longer matches the manifest's recorded hash belongs to a newer upload and
 * is left alone rather than silently reverted) — kept separate on purpose.
 *
 * @param array $ftp same shape as deploy_site()'s $ftp param (a params.csv row)
 * @param array $manifest relative-path => hash (only the keys are used)
 * @return array{ok:bool,touched:int,failed:int,message:string}
 */
function infra_filedate_ftp_touch(array $ftp, array $manifest): array
{
    if (strtolower(trim((string) ($ftp['ftp_protocol'] ?? 'ftp'))) === 'sftp') {
        return ['ok' => false, 'touched' => 0, 'failed' => 0,
                'message' => 'this domain is configured for SFTP, which this module does not support.'];
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
    if ($path !== '' && !@ftp_chdir($conn, rtrim($path, '/'))) {
        return ['ok' => false, 'touched' => 0, 'failed' => 0, 'message' => "Could not chdir to {$path}."];
    }

    $touched = 0; $failed = 0; $mismatched = 0; $changed = 0;
    foreach ($manifest as $file => $expectHash) {
        $tmp = tmpfile();
        $localPath = stream_get_meta_data($tmp)['uri'];
        if (!@ftp_get($conn, $localPath, $file, FTP_BINARY)) { $failed++; fclose($tmp); continue; }
        $hashBefore = md5_file($localPath);
        $expectHash = is_string($expectHash) ? strtolower(trim($expectHash)) : '';
        if ($expectHash === '' || $hashBefore !== $expectHash) { $changed++; fclose($tmp); continue; }
        if (!@ftp_put($conn, $file, $localPath, FTP_BINARY)) { $failed++; fclose($tmp); continue; }
        fclose($tmp);
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

    $ok = $failed === 0 && $mismatched === 0 && $changed === 0 && $touched > 0;
    if ($changed > 0 && $changed === count($manifest)) {
        return ['ok' => false, 'touched' => 0, 'failed' => $failed,
                'message' => "nothing refreshed — all {$changed} file(s) differ from the deploy manifest, "
                           . 'so the manifest is stale rather than one upload racing this refresh.'];
    }
    $msg = "{$touched} file(s) refreshed";
    if ($changed) $msg .= ", {$changed} skipped (changed since the last deploy)";
    if ($failed) $msg .= ", {$failed} failed to transfer";
    if ($mismatched) $msg .= ", {$mismatched} came back changed (content mismatch)";
    return ['ok' => $ok, 'touched' => $touched, 'failed' => $failed, 'message' => $msg . '.'];
}

/** Full-zone purge via the shared Cloudflare client — reused, not duplicated. */
function infra_filedate_purge(string $domain): array
{
    $idx = infra_cf_zone_index();
    $z = $idx[strtolower($domain)] ?? null;
    if (!$z || !$z['zone_id']) return ['ok' => false, 'message' => 'No Cloudflare zone found for this domain.'];

    $account = null;
    foreach (infra_cf_accounts() as $a) {
        if (($a['id'] ?? '') === $z['account_id']) { $account = $a; break; }
    }
    if (!$account) return ['ok' => false, 'message' => 'Cloudflare account for this zone is not configured here.'];

    return cf_purge_cache($account, $z['zone_id']);
}

/** Touch every deployed file's mtime + purge CDN cache for one domain. No content changes. */
function infra_filedate_touch_domain(string $domain): array
{
    $rec = infra_state_get_domain($domain);
    if (!$rec) return ['ok' => false, 'message' => 'not in fleet state'];

    $split = infra_filedate_split_batch((string) ($rec['batch'] ?? ''));
    if (!$split) return ['ok' => false, 'message' => 'no batch on record — nothing to refresh'];

    $manifestPath = infra_filedate_manifest_path($domain, $split['masterId']);
    if (!$manifestPath) return ['ok' => false, 'message' => 'no deploy manifest found — nothing to refresh'];

    $manifest = json_decode((string) file_get_contents($manifestPath), true);
    if (!is_array($manifest) || !$manifest) return ['ok' => false, 'message' => 'manifest is empty or unreadable'];

    $ftp = infra_filedate_params_row($domain, $split['masterId'], $split['batchId']);
    if (!$ftp) return ['ok' => false, 'message' => 'no params.csv row found for this domain'];

    $touch = infra_filedate_ftp_touch($ftp, $manifest);
    $purge = infra_filedate_purge($domain);

    return [
        'ok'      => $touch['ok'] && $purge['ok'],
        'message' => 'files: ' . $touch['message'] . ' cache: ' . ($purge['ok'] ? 'purged.' : 'purge FAILED — ' . $purge['message']),
    ];
}

// ── The queue ─────────────────────────────────────────────────────────────────

/**
 * How long after being queued this domain's touch comes due, in minutes.
 * Own seed string ('filedaterefresh|'), deliberately different from
 * golive_freshen's ('freshen|') so the two features never land the same domain on
 * the same offset by coincidence of sharing a hash input. Same ~20min-20hr shape
 * for the same reason golive_freshen uses it — an FTP STOR can only ever stamp a
 * file "now", so WHEN it runs is the only lever on the date.
 */
function infra_filedate_offset_minutes(string $domain): int
{
    $h = substr(md5('filedaterefresh|' . strtolower(trim($domain))), 0, 8);
    return 20 + (hexdec($h) % 1180);
}

/**
 * Live domains in the given niches, excluding anything already queued (status
 * 'pending') and anything whose last completed run was more recent than the
 * cooldown window. "Touched" is tracked in this table's own done_at, not
 * golive_freshen's freshen_last_at — the two features' histories are independent.
 */
function infra_filedate_eligible_domains(array $niches, int $cooldownDays): array
{
    $niches = array_values(array_unique(array_filter(array_map('infra_niche', $niches))));
    if (!$niches) return [];

    $db = infra_state_db();
    $pendingSet = array_fill_keys(array_map('strtolower', $db
        ->query("SELECT domain FROM file_date_refresh WHERE status = 'pending'")
        ->fetchAll(PDO::FETCH_COLUMN)), true);

    $lastDone = [];
    foreach ($db->query("SELECT domain, MAX(done_at) AS last_done FROM file_date_refresh
                          WHERE status = 'done' GROUP BY domain")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $lastDone[strtolower((string) $r['domain'])] = (string) $r['last_done'];
    }
    $cutoff = (new DateTime('now', new DateTimeZone(INFRA_TZ)))
        ->modify('-' . max(0, $cooldownDays) . ' days')->format('Y-m-d H:i:s');

    $out = [];
    foreach (infra_state_all_domains() as $domain => $rec) {
        if (($rec['status'] ?? '') !== 'live') continue;
        if (!in_array((string) ($rec['niche'] ?? ''), $niches, true)) continue;
        if (isset($pendingSet[$domain])) continue;
        $last = $lastDone[$domain] ?? '';
        if ($last !== '' && $last > $cutoff) continue;
        $out[] = $domain;
    }
    return $out;
}

/**
 * Pick up to $count eligible domains at random and queue each on its own
 * staggered due time. Cheap — state writes only, no FTP, nothing runs here; the
 * actual touch happens later, drained by filedate_tick.php.
 *
 * @return array{picked:int,eligible:int,items:array}
 */
function infra_filedate_enqueue(int $count, array $niches, int $cooldownDays): array
{
    $eligible = infra_filedate_eligible_domains($niches, $cooldownDays);
    shuffle($eligible);
    $picked = array_slice($eligible, 0, max(1, $count));

    $db  = infra_state_db();
    $now = infra_now();
    $items = [];
    foreach ($picked as $domain) {
        $rec = infra_state_get_domain($domain);
        $due = (new DateTime('now', new DateTimeZone(INFRA_TZ)))
            ->modify('+' . infra_filedate_offset_minutes($domain) . ' minutes')
            ->format('Y-m-d H:i:s');
        $db->prepare('INSERT INTO file_date_refresh (domain, niche, queued_at, due_at, status)
                      VALUES (?, ?, ?, ?, "pending")')
           ->execute([$domain, (string) ($rec['niche'] ?? ''), $now, $due]);
        $items[] = ['domain' => $domain, 'due_at' => $due];
    }
    return ['picked' => count($items), 'eligible' => count($eligible), 'items' => $items];
}

/** Queued rows whose due time has arrived, oldest first. */
function infra_filedate_due(): array
{
    $stmt = infra_state_db()->prepare("SELECT * FROM file_date_refresh
                                        WHERE status = 'pending' AND due_at <= ? ORDER BY due_at ASC");
    $stmt->execute([infra_now()]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Run due touches, up to $cap. Capped for the same reason golive_freshen's drain
 * is: each one is a full FTP download+reupload of every file on a domain, minutes
 * of work, called from a cron tick that runs every 10 minutes. Reuses the SAME
 * per-domain 'golive' lock golive_freshen.php and an interactive Go Live click
 * take — a generic infra-wide mutex, not a dependency on that module's code — so
 * this can never race another FTP writer touching the same domain's files.
 *
 * @return array<string,string> domain => what happened
 */
function infra_filedate_run_due(int $cap = 3): array
{
    require_once __DIR__ . '/pipeline.php';
    $db   = infra_state_db();
    $done = [];
    foreach (infra_filedate_due() as $row) {
        if (count($done) >= $cap) break;
        $domain = (string) $row['domain'];
        $r   = infra_pipeline_lock($domain, 'golive', fn() => infra_filedate_touch_domain($domain));
        $msg = trim((string) ($r['message'] ?? 'no result'));
        $db->prepare("UPDATE file_date_refresh SET status = 'done', done_at = ?, ok = ?, note = ? WHERE id = ?")
           ->execute([infra_now(), !empty($r['ok']) ? 'yes' : 'no', $msg, $row['id']]);
        $done[$domain] = $msg;
    }
    return $done;
}

/** Most recent rows, newest first — the tab's log. */
function infra_filedate_recent_log(int $limit = 100): array
{
    $stmt = infra_state_db()->prepare('SELECT * FROM file_date_refresh ORDER BY id DESC LIMIT ?');
    $stmt->bindValue(1, max(1, $limit), PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
