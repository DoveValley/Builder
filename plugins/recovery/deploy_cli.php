<?php
/**
 * Recovery plugin — background Build & Deploy runner (for the panel progress meter).
 *
 *   MULTISITE_SITE_BASE=.../sites/recovery-site php plugins/recovery/deploy_cli.php [--force]
 *
 * Runs recovery_full_build() then deploy_site(), routing the factory's progress events
 * (progress_log / progress_tick) into a small status JSON the panel polls. Spawned in the
 * background by save.php so the browser gets a live meter instead of a blocking request.
 */
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(2); }
require __DIR__ . '/../../config.php';
require __DIR__ . '/../../includes/functions.php';
if (ACTIVE_SITE_ID !== 'recovery-site') { fwrite(STDERR, "Set MULTISITE_SITE_BASE.\n"); exit(2); }
require_once __DIR__ . '/build.php';
require_once BASE_DIR . '/includes/static_build.php';
require_once BASE_DIR . '/includes/multisite/deploy.php';

$force      = in_array('--force', $argv, true);
$statusFile = ACTIVE_SITE_DIR . '/deploy_status.json';
$phase      = 'build';

$write = function (array $extra = []) use ($statusFile, &$phase) {
    @file_put_contents($statusFile, json_encode(array_merge(
        ['phase' => $phase, 'running' => true, 'ts' => time()], $extra
    )));
};

// Route factory progress into the status file (last message + counter).
progress_set_sink(function (array $p) use ($write, &$phase) {
    $e = ['type' => $p['type'] ?? 'log'];
    if (isset($p['msg']))  $e['msg']  = $p['msg'];
    if (isset($p['done'])) { $e['done'] = (int) $p['done']; $e['total'] = (int) $p['total']; }
    $write($e);
});

$write(['msg' => 'Building pages…']);

$cfg       = is_file(ACTIVE_SITE_DIR . '/deploy.json') ? (json_decode(file_get_contents(ACTIVE_SITE_DIR . '/deploy.json'), true) ?: []) : [];
$canonical = $cfg['canonical_domain'] ?: 'https://recoverywellspring.com';
$out       = ACTIVE_SITE_DIR . '/output/';

$b = recovery_full_build($out, $canonical);
$phase = 'upload';
$write(['msg' => 'Built ' . $b['pages'] . ' pages. Connecting to FTP…', 'done' => 0, 'total' => 0]);

$r = deploy_site($cfg, $out, ACTIVE_SITE_DIR . '/deploy_manifest.json', $force);

$ok    = ($r['status'] ?? '') !== 'fatal';
$phase = $ok ? 'done' : 'error';
@file_put_contents($statusFile, json_encode([
    'phase'    => $phase,
    'running'  => false,
    'ts'       => time(),
    'pages'    => $b['pages'] ?? 0,
    'uploaded' => $r['uploaded'] ?? 0,
    'failed'   => $r['failed'] ?? 0,
    'msg'      => $ok
        ? ('Deployed — built ' . ($b['pages'] ?? 0) . ' pages, uploaded ' . ($r['uploaded'] ?? 0) . ' file(s)'
            . (!empty($r['failed']) ? ', ' . $r['failed'] . ' failed' : '') . '.')
        : ('Deploy failed: ' . ($r['msg'] ?? 'error')),
]));
