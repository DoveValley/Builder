<?php
/**
 * Multisite render worker (child process) — Phase 0d.
 *
 * Runs in WORKER config mode: config.php roots all path constants at the prepared
 * working site via MULTISITE_SITE_BASE (set by the parent, build_one.php). Renders
 * that site to MULTISITE_OUTPUT_BASE and exits. Build ONLY — deploy is done by the
 * parent, since deploy_site() needs no config constants.
 *
 * This is the fresh-process-per-site unit for the config-dependent work: because
 * config's path constants are immutable once defined, the render must happen in a
 * process whose config was loaded in worker mode from the start (see §3).
 *
 * Progress is emitted as JSON-lines on stdout for the parent to relay.
 */
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "render_site.php is CLI only\n"); exit(2); }

require __DIR__ . '/../config.php';
require __DIR__ . '/../includes/functions.php';

$outBase = getenv('MULTISITE_OUTPUT_BASE') ?: '';
if ($outBase === '') {
    fwrite(STDERR, "MULTISITE_OUTPUT_BASE is required\n");
    exit(2);
}
// If worker-mode config didn't engage, ACTIVE_SITE_DIR is '' (legacy) — refuse rather
// than silently render the wrong site.
if (!ACTIVE_SITE_DIR) {
    fwrite(STDERR, "Worker config not active — MULTISITE_SITE_BASE missing or invalid\n");
    exit(2);
}

progress_set_sink(progress_jsonlines_sink());
$canonical = getenv('MULTISITE_CANONICAL') ?: '';
$web3      = getenv('MULTISITE_WEB3FORMS') ?: '';

build_static_site(rtrim($outBase, '/') . '/', $canonical, $web3);
exit(0);
