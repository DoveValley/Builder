<?php
/**
 * Multisite clone primitives (Phase 0c).
 *
 * Adapted from admin/site_api.php's clone logic, decoupled from the session /
 * active-site so the generator can drive them against explicit paths. site_api.php
 * itself is left untouched.
 *
 * Two-level cloning (see docs/multisite-generator-architecture.md §5):
 *   snapshot_master()      — ONCE per run: freeze the master into a run-scoped
 *                            snapshot. The original master is never touched again.
 *   clone_to_working_dir() — per row: a cheap throwaway working dir cloned FROM the
 *                            snapshot. data/ is copied (so per-row injection can
 *                            mutate it); uploads/ is symlinked from the snapshot
 *                            (shared, near-zero cost) with a copy fallback.
 *
 * Worker mode uses UPLOAD_URL='uploads/', so upload paths in the cloned JSON are
 * flattened from sites/{master}/uploads/ → uploads/ (same rewrite site_export uses).
 */

/**
 * Deterministic, collision-resistant slug for a domain — used for cache, manifest
 * and temp-dir keys. A short hash suffix guarantees two distinct domains that
 * differ only in punctuation (a.b.com vs a-b.com) never share a key.
 */
function ms_domain_slug(string $domain): string {
    $d = strtolower(trim($domain));
    $base = preg_replace('/[^a-z0-9-]+/', '_', $d);
    return $base . '_' . substr(sha1($d), 0, 8);
}

/** Recursively copy a directory (dirs + files). */
function ms_copy_dir(string $src, string $dst): void {
    if (!is_dir($src)) return;
    if (!is_dir($dst)) mkdir($dst, 0775, true);
    foreach (new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    ) as $item) {
        $target = $dst . '/' . substr($item->getPathname(), strlen($src) + 1);
        if ($item->isDir()) {
            if (!is_dir($target)) mkdir($target, 0775, true);
        } else {
            copy($item->getPathname(), $target);
        }
    }
}

/** Recursively delete a directory. Does not follow symlinks — unlinks the link itself. */
function ms_delete_dir(string $dir): void {
    if (!is_dir($dir) && !is_link($dir)) return;
    if (is_link($dir)) { unlink($dir); return; }
    foreach (new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    ) as $item) {
        if ($item->isLink()) { unlink($item->getPathname()); }
        elseif ($item->isDir()) { rmdir($item->getPathname()); }
        else { unlink($item->getPathname()); }
    }
    rmdir($dir);
}

/**
 * Flatten upload paths in every JSON file under $dataDir:
 * sites/{masterId}/uploads/  →  uploads/
 * so worker-mode rendering (UPLOAD_URL='uploads/') emits correct /uploads/ URLs.
 */
function ms_flatten_upload_paths(string $dataDir, string $masterId): void {
    if (!is_dir($dataDir)) return;
    $from = 'sites/' . $masterId . '/uploads/';
    foreach (new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dataDir, FilesystemIterator::SKIP_DOTS)
    ) as $file) {
        if ($file->getExtension() !== 'json') continue;
        $path = $file->getPathname();
        $content = file_get_contents($path);
        if (strpos($content, $from) === false) continue;
        $new = str_replace($from, 'uploads/', $content);
        $tmp = $path . '.tmp.' . getmypid();
        if (file_put_contents($tmp, $new) !== false) { rename($tmp, $path); }
        else { @unlink($tmp); }
    }
}

/**
 * Snapshot the master ONCE per run. Copies data/ + uploads/ into $snapshotDir.
 * The multisite/ dir is NOT copied (it holds this run's own params/cache/creds).
 * Returns $snapshotDir.
 *
 * @throws RuntimeException if the master has no data/ dir.
 */
function snapshot_master(string $masterId, string $snapshotDir): string {
    $masterDir = BASE_DIR . '/sites/' . $masterId;
    if (!is_dir($masterDir . '/data')) {
        throw new RuntimeException("Master site '{$masterId}' has no data/ directory at {$masterDir}");
    }
    if (is_dir($snapshotDir) || is_link($snapshotDir)) ms_delete_dir($snapshotDir);
    mkdir($snapshotDir, 0775, true);
    ms_copy_dir($masterDir . '/data',    $snapshotDir . '/data');
    ms_copy_dir($masterDir . '/uploads', $snapshotDir . '/uploads');
    // multisite/icons/ ONLY, not the whole multisite/ dir — that still holds this run's own
    // params, cache and credentials and must not travel into a clone.
    //
    // The icons have to, though: blocks.php inlines an icon's SVG when the file is present at
    // ACTIVE_SITE_DIR/multisite/icons/, and falls back to <img src="/name.svg"> when it isn't.
    // In a clone the file was never there, so every flip-tile fell back to an image at the
    // site root that nothing writes — six broken images on the homepage of every generated
    // site, while the master itself looked perfect. The "multisite/ is not copied" rule was
    // written before icons lived under it.
    if (is_dir($masterDir . '/multisite/icons')) {
        ms_copy_dir($masterDir . '/multisite/icons', $snapshotDir . '/multisite/icons');
    }
    // niche_brief.json for the same reason, and it cost the same way. The chart plugin reads
    // ACTIVE_SITE_DIR/multisite/niche_brief.json to learn which niche a site is, and that is
    // what selects its chart folder. In a clone the file was never there, so the niche resolved
    // to nothing, no chart definitions loaded, and every {chart_*} token stayed unregistered —
    // charts silently absent from every generated site while the master rendered all eight.
    foreach (['niche_brief.json'] as $f) {
        if (is_file($masterDir . '/multisite/' . $f)) {
            if (!is_dir($snapshotDir . '/multisite')) @mkdir($snapshotDir . '/multisite', 0775, true);
            @copy($masterDir . '/multisite/' . $f, $snapshotDir . '/multisite/' . $f);
        }
    }
    return $snapshotDir;
}

/**
 * Clone a cheap per-row working dir FROM the snapshot.
 *   - data/    : deep-copied (per-row injection mutates it), upload paths flattened
 *   - uploads/ : symlinked to the snapshot's uploads/ (shared, cheap); copy fallback
 *   - deploy.json: stripped (FTP creds come from params at deploy time)
 *
 * $masterId is needed to flatten sites/{master}/uploads/ → uploads/.
 * Returns $workingDir.
 */
function clone_to_working_dir(string $snapshotDir, string $workingDir, string $masterId): string {
    if (is_dir($workingDir) || is_link($workingDir)) ms_delete_dir($workingDir);
    mkdir($workingDir, 0775, true);

    // data/ — deep copy so injection can mutate freely without touching the snapshot.
    ms_copy_dir($snapshotDir . '/data', $workingDir . '/data');
    ms_flatten_upload_paths($workingDir . '/data', $masterId);

    // Do not carry FTP credentials into a working site.
    $wd = $workingDir . '/data/deploy.json';
    if (file_exists($wd)) @unlink($wd);

    // uploads/ — share via symlink (near-zero cost); copy if symlink unsupported.
    $srcUploads = $snapshotDir . '/uploads';
    $dstUploads = $workingDir . '/uploads';
    if (is_dir($srcUploads)) {
        if (!@symlink($srcUploads, $dstUploads)) {
            ms_copy_dir($srcUploads, $dstUploads);
        }
    }

    // multisite/icons/ — shared the same way. blocks.php reads these from
    // ACTIVE_SITE_DIR/multisite/icons/ at render time, which is the WORKING dir, so getting
    // them into the snapshot alone is not enough. Nothing mutates them, so a symlink is fine.
    $srcIcons = $snapshotDir . '/multisite/icons';
    $dstIcons = $workingDir . '/multisite/icons';
    if (is_dir($srcIcons)) {
        if (!is_dir(dirname($dstIcons))) @mkdir(dirname($dstIcons), 0775, true);
        if (!@symlink($srcIcons, $dstIcons)) {
            ms_copy_dir($srcIcons, $dstIcons);
        }
    }

    // Same again for niche_brief.json — the snapshot alone is not enough, because the plugin
    // reads it from the WORKING dir at render time. Copied rather than symlinked: it is one
    // small file and a copy cannot be broken by the snapshot being cleaned up mid-run.
    foreach (['niche_brief.json'] as $f) {
        $src = $snapshotDir . '/multisite/' . $f;
        if (is_file($src)) {
            if (!is_dir($workingDir . '/multisite')) @mkdir($workingDir . '/multisite', 0775, true);
            @copy($src, $workingDir . '/multisite/' . $f);
        }
    }
    return $workingDir;
}
