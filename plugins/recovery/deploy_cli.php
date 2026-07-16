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
// Reuse the start time the seed wrote (so elapsed measures from the user's click and
// `started` matches what the panel is waiting for); fall back to now if seed is absent.
$seed       = is_file($statusFile) ? json_decode((string) @file_get_contents($statusFile), true) : null;
$started    = (is_array($seed) && !empty($seed['started'])) ? (int) $seed['started'] : time();

// Rich, pollable state. The panel reads all of this to render phase steps, a
// live log tail, per-phase counters, elapsed/ETA, and a persistent issue list.
$lastMsg = 'Building pages…';
$counts  = ['build_done' => 0, 'build_total' => 0, 'up_done' => 0, 'up_total' => 0];
$log     = [];   // rolling last ~30 events (for liveness)
$issues  = [];   // every warn/error/fatal (capped) so failures never scroll away

$write = function (array $extra = []) use ($statusFile, &$phase, $started, &$lastMsg, &$counts, &$log, &$issues) {
    $base = array_merge([
        'phase'   => $phase,
        'running' => true,
        'ts'      => time(),
        'started' => $started,
        'msg'     => $lastMsg,
        'log'     => $log,
        'issues'  => $issues,
    ], $counts);
    // Write atomically so the panel never reads a half-written file.
    $tmp = $statusFile . '.tmp';
    if (@file_put_contents($tmp, json_encode(array_merge($base, $extra))) !== false) {
        @rename($tmp, $statusFile);
    }
};

// Route factory progress events into the status file.
progress_set_sink(function (array $p) use ($write, &$phase, &$lastMsg, &$counts, &$log, &$issues) {
    $type = $p['type'] ?? 'log';

    // Counters — keep build and upload tallies separate so each phase shows its own bar.
    if (isset($p['done'])) {
        if ($phase === 'build') { $counts['build_done'] = (int) $p['done']; $counts['build_total'] = (int) $p['total']; }
        else                    { $counts['up_done']    = (int) $p['done']; $counts['up_total']    = (int) $p['total']; }
    }

    if (isset($p['msg']) && $p['msg'] !== '') {
        $lastMsg = $p['msg'];
        $log[] = ['t' => $type, 'm' => $p['msg'], 'ts' => time()];
        if (count($log) > 30) array_shift($log);
        // Real problems get pinned to the issues list. The "Force push" line is an
        // informational warn (not a failure) — keep it in the log tail only, so a
        // normal --force run doesn't read as "1 issue".
        if (in_array($type, ['warn', 'error', 'fatal'], true) && stripos($p['msg'], 'force push') === false) {
            $issues[] = ['t' => $type, 'm' => $p['msg']];
            if (count($issues) > 60) array_shift($issues);
        }
    }
    $write();
});

$write(['msg' => 'Building pages…']);

$cfg       = is_file(ACTIVE_SITE_DIR . '/deploy.json') ? (json_decode(file_get_contents(ACTIVE_SITE_DIR . '/deploy.json'), true) ?: []) : [];
$canonical = $cfg['canonical_domain'] ?: 'https://recoverydawn.com';
$out       = ACTIVE_SITE_DIR . '/output/';

$b = recovery_full_build($out, $canonical);
$phase = 'upload';
$write(['msg' => 'Built ' . $b['pages'] . ' pages. Connecting to FTP…', 'done' => 0, 'total' => 0]);

$r = deploy_site($cfg, $out, ACTIVE_SITE_DIR . '/deploy_manifest.json', $force);

$ok    = ($r['status'] ?? '') !== 'fatal';
$phase = $ok ? 'done' : 'error';
$lastMsg = $ok
    ? ('Deployed — built ' . ($b['pages'] ?? 0) . ' pages, uploaded ' . ($r['uploaded'] ?? 0) . ' file(s)'
        . (!empty($r['failed']) ? ', ' . $r['failed'] . ' failed' : '') . '.')
    : ('Deploy failed: ' . ($r['msg'] ?? 'error'));
$log[] = ['t' => $ok ? 'done' : 'fatal', 'm' => $lastMsg, 'ts' => time()];
if (count($log) > 30) array_shift($log);
if (!$ok && !empty($r['msg'])) $issues[] = ['t' => 'fatal', 'm' => $r['msg']];

$tmp = $statusFile . '.tmp';
if (@file_put_contents($tmp, json_encode(array_merge([
    'phase'    => $phase,
    'running'  => false,
    'ts'       => time(),
    'started'  => $started,
    'pages'    => $b['pages'] ?? 0,
    'uploaded' => $r['uploaded'] ?? 0,
    'failed'   => $r['failed'] ?? 0,
    'msg'      => $lastMsg,
    'log'      => $log,
    'issues'   => $issues,
], $counts))) !== false) {
    @rename($tmp, $statusFile);
}
