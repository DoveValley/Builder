<?php
/**
 * Recovery plugin — static build runner (CLI).
 *
 *   MULTISITE_SITE_BASE=/var/www/homepage-builder-new/sites/recovery-site \
 *   php plugins/recovery/build_cli.php
 *
 * Optional: RECOVERY_OUTPUT=/abs/dir (default sites/recovery-site/output).
 * Builds only — deploy is the panel's Build & Deploy action (or deploy_cli.php).
 */
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(2); }

require __DIR__ . '/../../config.php';
require __DIR__ . '/../../includes/functions.php';

if (ACTIVE_SITE_ID !== 'recovery-site') {
    fwrite(STDERR, "Set MULTISITE_SITE_BASE to the recovery-site dir (active: '" . ACTIVE_SITE_ID . "')\n");
    exit(2);
}
require_once __DIR__ . '/build.php';
require_once BASE_DIR . '/includes/static_build.php';
if (function_exists('progress_set_sink')) progress_set_sink(function (...$a) {});

$out = getenv('RECOVERY_OUTPUT') ?: (BASE_DIR . '/sites/recovery-site/output');
// Canonical domain from the site's deploy.json (falls back to staging) — keeps CLI builds
// in sync with the panel's Build & Deploy so canonicals/sitemap match the live domain.
$deployFile = ACTIVE_SITE_DIR . '/deploy.json';
$dcfg = is_file($deployFile) ? (json_decode(file_get_contents($deployFile), true) ?: []) : [];
$canonical = $dcfg['canonical_domain'] ?: 'https://r.q111.xyz';
$b   = recovery_full_build($out, $canonical);

echo "Recovery build → " . rtrim($out, '/') . "/\n";
echo "Total pages: {$b['pages']}  (matrix: {$b['matrix']})\n";
foreach ($b['breakdown'] as $k => $v) printf("  %-18s %d\n", $k, $v);
