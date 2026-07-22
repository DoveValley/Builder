<?php
/**
 * FTP deploy core (Phase 0c).
 *
 * Extracted from admin/deploy_ftp.php so the same incremental upload can be driven
 * by the admin SSE endpoint and the multisite CLI worker. Behavior is preserved
 * verbatim; the only decoupling is that FTP config + the manifest path are passed
 * in (from a stored deploy.json in the endpoint, or from the params row + the
 * per-domain manifest in the worker), and progress goes through includes/progress.php.
 *
 * Incremental: uploads only files whose md5 differs from the manifest, then rewrites
 * the manifest. For ephemeral multisite builds the manifest must live OUTSIDE the
 * temp build dir (which is deleted each run) — e.g.
 * sites/{master}/multisite/manifests/{domain}.json — or every redeploy re-uploads all.
 */

/** Ensure a remote directory path exists, creating each segment once. */
function ms_ftp_ensure_dir($conn, string $remotePath, array &$createdDirs): void {
    if (isset($createdDirs[$remotePath])) return;
    $parts = explode('/', ltrim($remotePath, '/'));
    $cur   = '';
    foreach ($parts as $part) {
        if ($part === '') continue;
        $cur .= '/' . $part;
        if (!isset($createdDirs[$cur])) {
            @ftp_mkdir($conn, $cur);
            $createdDirs[$cur] = true;
        }
    }
}

/**
 * Upload a set of files over SFTP using the vendored phpseclib (pure PHP).
 *
 * Used when deploy config specifies ftp_protocol=sftp. We deliberately do NOT
 * use curl's sftp:// here: this host's curl (8.5 + libssh) can't accept an SSH
 * host key (no known_hosts, and CURLOPT_SSH_HOSTKEYFUNCTION only works with the
 * libssh backend on curl >= 8.6). phpseclib has no such limitation — it connects
 * trust-on-first-use, the same no-verify posture as the FTP path, and needs no
 * PHP extension. One SFTP session is opened and reused for the whole push.
 *
 * Remote paths are HOME-relative (no leading slash) so a configured path like
 * '/public_html' means the docroot under the login user's home, matching the
 * FTP path field's meaning rather than the server's filesystem root.
 *
 * @return bool  false = fatal (library missing / couldn't connect / auth failed).
 *               Per-file failures increment $failed but do not abort.
 */
function ms_sftp_upload_all(string $host, int $port, string $user, string $pass, string $path,
                            array $toUpload, int $ftpTotal,
                            array &$newManifest, int &$uploaded, int &$failed): bool {
    require_once __DIR__ . '/../vendor/autoload.php';
    if (!class_exists('phpseclib3\\Net\\SFTP')) {
        progress_log('SFTP library (phpseclib) not found in includes/vendor/.', 'fatal');
        return false;
    }

    progress_log("Connecting to sftp://{$host}:{$port}…");
    try {
        $sftp = new \phpseclib3\Net\SFTP($host, $port, 30);
        if (!$sftp->login($user, $pass)) {
            progress_log('SFTP login failed — check credentials.', 'fatal');
            return false;
        }
    } catch (\Throwable $e) {
        progress_log('SFTP connection failed: ' . $e->getMessage(), 'fatal');
        return false;
    }
    progress_log('Connected.');

    // Home-relative base (no leading slash): phpseclib resolves relative paths
    // against the login cwd (the user's home), matching the FTP path semantics.
    $base = trim($path, '/');
    $createdDirs = [];

    foreach ($toUpload as $rel => $info) {
        $remoteFile = ($base !== '' ? $base . '/' : '') . $rel;
        $remoteDir  = dirname($remoteFile);

        // phpseclib throws (not returns false) on protocol errors and mid-transfer
        // disconnects — unlike ftp_put(). Catch so one bad file / a dropped link
        // degrades to a per-file failure + manifest save, the same as the FTP path,
        // instead of a fatal uncaught error that loses all progress.
        try {
            if ($remoteDir !== '.' && !isset($createdDirs[$remoteDir])) {
                $sftp->mkdir($remoteDir, -1, true); // recursive; harmless if it exists
                $createdDirs[$remoteDir] = true;
            }
            $ok = $sftp->put($remoteFile, $info['path'], \phpseclib3\Net\SFTP::SOURCE_LOCAL_FILE);
        } catch (\Throwable $e) {
            $ok = false;
            progress_log("Failed:   {$rel} — {$e->getMessage()}", 'error');
        }

        if ($ok) {
            $newManifest[$rel] = $info['hash'];
            $uploaded++;
            progress_log("Uploaded: {$rel}");
        } else {
            $failed++;
            if (empty($e)) progress_log("Failed:   {$rel}", 'error'); // non-exception put() failure
        }
        unset($e);
        progress_tick($uploaded + $failed, $ftpTotal);

        // A dropped connection makes every remaining put() fail the same way —
        // stop early rather than grind through the whole list. Count the files we
        // never got to as failures so the caller sees an incomplete deploy (a
        // drop right after a successful put would otherwise report 0 failed).
        if (!$sftp->isConnected()) {
            $remaining = $ftpTotal - ($uploaded + $failed);
            if ($remaining > 0) $failed += $remaining;
            progress_log("SFTP connection lost — {$remaining} file(s) not uploaded.", 'error');
            break;
        }
    }

    return true;
}

/**
 * Open + authenticate a phpseclib SFTP session from a deploy config array.
 *
 * Shared by the audit / delete endpoints so they speak SFTP exactly the way
 * ms_sftp_upload_all() does (same host/port normalization, trust-on-first-use).
 * Returns the connected SFTP object, or null with $err set on any failure.
 *
 * @return \phpseclib3\Net\SFTP|null
 */
function ms_sftp_open(array $cfg, int $timeout = 20, ?string &$err = null) {
    $err = null;
    require_once __DIR__ . '/../vendor/autoload.php';
    if (!class_exists('phpseclib3\\Net\\SFTP')) {
        $err = 'SFTP library (phpseclib) not found in includes/vendor/.';
        return null;
    }
    $host = preg_replace('#^s?ftps?://#i', '', trim($cfg['ftp_host'] ?? ''));
    $port = (int)($cfg['ftp_port'] ?? 0) ?: 22;
    $user = $cfg['ftp_user'] ?? '';
    $pass = $cfg['ftp_pass'] ?? '';
    if ($host === '' || $user === '' || $pass === '') { $err = 'SFTP credentials incomplete.'; return null; }
    try {
        $sftp = new \phpseclib3\Net\SFTP($host, $port, $timeout);
        if (!$sftp->login($user, $pass)) { $err = 'SFTP login failed — check credentials.'; return null; }
    } catch (\Throwable $e) {
        $err = 'SFTP connection failed: ' . $e->getMessage();
        return null;
    }
    return $sftp;
}

/**
 * Recursively list a remote SFTP tree for the audit. Fills $files[relPath]=size
 * and $dirs[]=relPath, both relative to $base (the home-relative deploy root),
 * so the result reconciles 1:1 with what ms_sftp_upload_all() pushed. rawlist()
 * type codes: 2 = NET_SFTP_TYPE_DIRECTORY, 1 = NET_SFTP_TYPE_REGULAR.
 */
function ms_sftp_walk($sftp, string $base, string $sub, array &$files, array &$dirs): void {
    $dir = $sub === '' ? ($base === '' ? '.' : $base) : (($base === '' ? '' : $base . '/') . $sub);
    $list = @$sftp->rawlist($dir);
    if (!is_array($list)) return;
    foreach ($list as $name => $attrs) {
        if ($name === '.' || $name === '..') continue;
        $rel  = $sub === '' ? $name : $sub . '/' . $name;
        $type = (int)($attrs['type'] ?? 0);
        if ($type === 2) {
            $dirs[] = $rel;
            ms_sftp_walk($sftp, $base, $rel, $files, $dirs);
        } elseif ($type === 1) {
            $files[$rel] = (int)($attrs['size'] ?? 0);
        }
    }
}

/**
 * Recursively delete everything UNDER $dir (but not $dir itself) over SFTP.
 * Mirrors the FTP force-delete: files are removed individually and counted,
 * emptied directories are rmdir'd. $log is called as $log(string $msg, string
 * $type) for progress ('log') and per-item failures ('warn'). Per-item errors
 * are caught so one bad entry never aborts the whole sweep.
 */
function ms_sftp_delete_tree($sftp, string $dir, callable $log, int &$deleted, int &$failed): void {
    try {
        $list = $sftp->rawlist($dir === '' ? '.' : $dir);
    } catch (\Throwable $e) {
        // e.g. the SFTP subsystem never started (restricted shell) or the path is
        // unreadable — report the real reason instead of letting it kill the stream.
        $failed++;
        $log('Failed to list ' . ($dir !== '' ? $dir : '~') . ' — ' . $e->getMessage(), 'warn');
        return;
    }
    if (!is_array($list)) return;
    foreach ($list as $name => $attrs) {
        if ($name === '.' || $name === '..') continue;
        $full = ($dir === '' || $dir === '.') ? $name : $dir . '/' . $name;
        $type = (int)($attrs['type'] ?? 0);
        try {
            if ($type === 2) {
                ms_sftp_delete_tree($sftp, $full, $log, $deleted, $failed);
                if (!$sftp->rmdir($full)) { $failed++; $log("Failed to remove dir: {$full}", 'warn'); }
            } else {
                if ($sftp->delete($full, false)) {
                    $deleted++;
                    if ($deleted % 20 === 0) $log("Deleted {$deleted} files…", 'log');
                } else {
                    $failed++; $log("Failed: {$full}", 'warn');
                }
            }
        } catch (\Throwable $e) {
            $failed++; $log("Failed: {$full} — {$e->getMessage()}", 'warn');
        }
    }
}

/**
 * Deploy a built static site over FTP or SFTP.
 *
 * @param array  $ftp          host, port, user, pass, path, passive, protocol ('ftp'|'sftp').
 * @param string $outputBase   Built site dir (must end in '/').
 * @param string $manifestFile Path to the incremental-upload manifest (persisted across runs).
 * @param bool   $forceAll     Ignore the manifest and upload everything.
 * @return array  ['status' => 'done'|'fatal', 'uploaded' => int, 'failed' => int, 'msg' => string]
 */
function deploy_site(array $ftp, string $outputBase, string $manifestFile, bool $forceAll = false): array {
    $host     = preg_replace('#^s?ftps?://#i', '', trim($ftp['ftp_host'] ?? ''));
    $protocol = strtolower(trim($ftp['ftp_protocol'] ?? 'ftp')) === 'sftp' ? 'sftp' : 'ftp';
    $port     = (int)($ftp['ftp_port'] ?? 0);
    if ($port < 1) $port = ($protocol === 'sftp' ? 22 : 21);
    $user     = $ftp['ftp_user'] ?? '';
    $pass     = $ftp['ftp_pass'] ?? '';
    $path     = rtrim($ftp['ftp_path'] ?? '/public_html', '/');
    $passive  = !empty($ftp['ftp_passive']);

    $protoLabel = strtoupper($protocol);
    if ($host === '' || $user === '' || $pass === '') {
        progress_log("{$protoLabel} credentials incomplete.", 'fatal');
        return ['status' => 'fatal', 'uploaded' => 0, 'failed' => 0, 'msg' => "{$protoLabel} credentials incomplete."];
    }
    if (!is_dir($outputBase)) {
        progress_log('No output directory found — build first.', 'fatal');
        return ['status' => 'fatal', 'uploaded' => 0, 'failed' => 0, 'msg' => 'No output directory.'];
    }

    // ── Load manifest ─────────────────────────────────────────────────────────
    $manifest = (!$forceAll && file_exists($manifestFile)) ? (json_decode(file_get_contents($manifestFile), true) ?: []) : [];
    if ($forceAll) progress_log('Force push — uploading all files regardless of manifest.', 'warn');

    // ── Build file list ─────────────────────────────────────────────────────────
    $files = [];
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($outputBase, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iter as $item) {
        if ($item->isFile()) {
            $rel = str_replace('\\', '/', substr($item->getPathname(), strlen($outputBase)));
            $files[$rel] = $item->getPathname();
        }
    }
    progress_log('Found ' . count($files) . ' files in output/.');

    // ── Determine what needs uploading ──────────────────────────────────────────
    $toUpload = [];
    foreach ($files as $rel => $absPath) {
        $hash = md5_file($absPath);
        if (($manifest[$rel] ?? '') !== $hash) {
            $toUpload[$rel] = ['path' => $absPath, 'hash' => $hash];
        }
    }

    if (empty($toUpload)) {
        progress_log('Nothing to upload — all files are up to date.', 'done');
        return ['status' => 'done', 'uploaded' => 0, 'failed' => 0, 'msg' => 'Up to date.'];
    }

    $ftpTotal = count($toUpload);
    progress_log($ftpTotal . ' file' . ($ftpTotal !== 1 ? 's' : '') . ' to upload.');
    progress_tick(0, $ftpTotal);

    $uploaded    = 0;
    $failed      = 0;
    $newManifest = $manifest;

    if ($protocol === 'sftp') {
        // ── Upload over SFTP (phpseclib) ────────────────────────────────────────
        $ok = ms_sftp_upload_all($host, $port, $user, $pass, $path, $toUpload, $ftpTotal, $newManifest, $uploaded, $failed);
        if (!$ok) {
            return ['status' => 'fatal', 'uploaded' => $uploaded, 'failed' => $failed, 'msg' => 'SFTP connection failed.'];
        }
    } else {
        // ── Connect FTP ─────────────────────────────────────────────────────────
        if (!function_exists('ftp_connect')) {
            progress_log('PHP FTP extension not available on this server.', 'fatal');
            return ['status' => 'fatal', 'uploaded' => 0, 'failed' => 0, 'msg' => 'No FTP extension.'];
        }

        progress_log("Connecting to {$host}:{$port}…");
        $conn = ftp_connect($host, $port, 30);
        if (!$conn) {
            progress_log("Could not connect to {$host}:{$port}.", 'fatal');
            return ['status' => 'fatal', 'uploaded' => 0, 'failed' => 0, 'msg' => 'Connect failed.'];
        }

        if (!ftp_login($conn, $user, $pass)) {
            ftp_close($conn);
            progress_log('FTP login failed — check credentials.', 'fatal');
            return ['status' => 'fatal', 'uploaded' => 0, 'failed' => 0, 'msg' => 'Login failed.'];
        }

        if ($passive && !ftp_pasv($conn, true)) {
            progress_log('Warning: could not enable passive mode — uploads may fail behind NAT/firewall.', 'warn');
        }

        progress_log('Connected.');

        // ── Upload files ────────────────────────────────────────────────────────
        $createdDirs = [];

        foreach ($toUpload as $rel => $info) {
            $remoteDir  = rtrim($path . '/' . (dirname($rel) !== '.' ? dirname($rel) : ''), '/');
            $remoteFile = $path . '/' . $rel;

            ms_ftp_ensure_dir($conn, $remoteDir, $createdDirs);

            $mode = FTP_BINARY;
            $ext  = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
            if (in_array($ext, ['html', 'htm', 'txt', 'xml', 'css', 'js', 'json', 'htaccess'], true)) {
                $mode = FTP_ASCII;
            }

            if (ftp_put($conn, $remoteFile, $info['path'], $mode)) {
                $newManifest[$rel] = $info['hash'];
                $uploaded++;
                progress_log("Uploaded: {$rel}");
            } else {
                $failed++;
                progress_log("Failed:   {$rel}", 'error');
            }
            progress_tick($uploaded + $failed, $ftpTotal);
        }

        ftp_close($conn);
    }

    // Remove entries for files that no longer exist locally
    foreach (array_keys($newManifest) as $rel) {
        if (!isset($files[$rel])) unset($newManifest[$rel]);
    }

    // Persist the manifest (create parent dir — for multisite it lives under multisite/manifests/).
    $manifestDir = dirname($manifestFile);
    if (!is_dir($manifestDir)) @mkdir($manifestDir, 0775, true);
    $manifestJson = json_encode($newManifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    $manifestTmp  = $manifestFile . '.tmp.' . getmypid();
    if (file_put_contents($manifestTmp, $manifestJson) !== false) {
        rename($manifestTmp, $manifestFile);
    } else {
        @unlink($manifestTmp);
        progress_log('Warning: could not save deploy manifest — next push will re-upload all files.', 'warn');
    }

    $summary = "Deploy complete — {$uploaded} uploaded";
    if ($failed > 0) $summary .= ", {$failed} failed";
    $summary .= '.';
    progress_log($summary, $failed > 0 ? 'warn' : 'done');

    return ['status' => 'done', 'uploaded' => $uploaded, 'failed' => $failed, 'msg' => $summary];
}
