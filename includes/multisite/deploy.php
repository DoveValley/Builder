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
 * Deploy a built static site over FTP.
 *
 * @param array  $ftp          host, port, user, pass, path, passive.
 * @param string $outputBase   Built site dir (must end in '/').
 * @param string $manifestFile Path to the incremental-upload manifest (persisted across runs).
 * @param bool   $forceAll     Ignore the manifest and upload everything.
 * @return array  ['status' => 'done'|'fatal', 'uploaded' => int, 'failed' => int, 'msg' => string]
 */
function deploy_site(array $ftp, string $outputBase, string $manifestFile, bool $forceAll = false): array {
    $host    = preg_replace('#^ftps?://#i', '', trim($ftp['ftp_host'] ?? ''));
    $port    = (int)($ftp['ftp_port'] ?? 21);
    $user    = $ftp['ftp_user'] ?? '';
    $pass    = $ftp['ftp_pass'] ?? '';
    $path    = rtrim($ftp['ftp_path'] ?? '/public_html', '/');
    $passive = !empty($ftp['ftp_passive']);

    if ($host === '' || $user === '' || $pass === '') {
        progress_log('FTP credentials incomplete.', 'fatal');
        return ['status' => 'fatal', 'uploaded' => 0, 'failed' => 0, 'msg' => 'FTP credentials incomplete.'];
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

    // ── Connect FTP ───────────────────────────────────────────────────────────
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

    // ── Upload files ──────────────────────────────────────────────────────────
    $createdDirs = [];
    $uploaded = 0;
    $failed   = 0;
    $newManifest = $manifest;

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
