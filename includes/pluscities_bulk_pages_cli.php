<?php
/**
 * includes/pluscities_bulk_pages_cli.php — CLI-only worker for the "Multiple
 * pages" New Client wizard mode (2026-11-26): creates every (city x service)
 * combination for one client, sequentially, each one a real
 * pluscities_create_page() call using that service's family happy-path
 * blocks (no per-combo customization — swap blocks on an individual page
 * afterward if needed, same editor as always).
 *
 * Launched detached via ms_launch_job_now()/ms_detach_prefix() (includes/
 * multisite/batch.php) — the fleet's own proven background-job mechanism,
 * reused as-is rather than rebuilt, since it already solved the hard bugs
 * (a systemd cgroup kill taking the job down with an apache2 restart, a
 * recycled PID reading as "still running"). See pluscities_bulk_create_
 * pages_launch() in includes/pluscities_lifecycle.php for the launch side.
 *
 * Writes its OWN structured run-status file (runs/{run_id}.json), same
 * shape/intent as multisite/run_campaign.php's: an immediate 'running'
 * marker before any real work starts (so a poller never sees "nothing
 * happened yet"), then a fresh write after EVERY SINGLE combo completes —
 * not just periodically — so progress is visible the whole way through a
 * run that can take minutes (each combo is a real AI-generation call), not
 * just at the end. Atomic tmp-then-rename, so a poller never reads a
 * half-written file.
 *
 * Usage: php includes/pluscities_bulk_pages_cli.php <client_id> --run-id=X
 *   --cities=city1,city2 --services=slug1,slug2 --actor=foo
 */
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "pluscities_bulk_pages_cli.php is CLI only\n"); exit(2); }

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/multisite/batch.php'; // ms_pid_start_time() — liveness bookkeeping, shared not reinvented

$clientId = $argv[1] ?? '';
$runId = ''; $citiesArg = ''; $servicesArg = ''; $actor = 'system:bulk'; $driveMinutes = 15;
foreach (array_slice($argv, 2) as $a) {
    if (str_starts_with($a, '--run-id='))  $runId = substr($a, 9);
    if (str_starts_with($a, '--cities='))  $citiesArg = substr($a, 9);
    if (str_starts_with($a, '--services=')) $servicesArg = substr($a, 11);
    if (str_starts_with($a, '--actor='))   $actor = substr($a, 8);
    if (str_starts_with($a, '--drive-minutes=')) $driveMinutes = (int) substr($a, 16);
}
if ($clientId === '' || $runId === '' || !preg_match('/^[A-Za-z0-9._-]{1,64}$/', $runId)) {
    fwrite(STDERR, "Missing or invalid client_id/run-id\n"); exit(2);
}
$cities   = array_values(array_filter(array_map('trim', explode(',', $citiesArg))));
$services = array_values(array_filter(array_map('trim', explode(',', $servicesArg))));

$runsDir = PLUSCITIES_BULK_RUNS_DIR . $clientId . '/runs/';
if (!is_dir($runsDir)) mkdir($runsDir, 0775, true);
$statusFile = $runsDir . $runId . '.json';
$startedAt  = gmdate('c');

$writeStatus = function (string $state, array $results) use ($statusFile, $runId, $clientId, $startedAt, $cities, $services) {
    $total  = count($cities) * count($services);
    $done   = count($results);
    $ok     = count(array_filter($results, fn($r) => $r['status'] === 'ok'));
    $payload = [
        'run_id'      => $runId,
        'client_id'   => $clientId,
        'state'       => $state,   // running | done | failed  (ms_read_run() downgrades a dead 'running' to 'stale')
        'pid'         => getmypid(),
        'pid_started' => ms_pid_start_time(getmypid()),
        'started_at'  => $startedAt,
        'finished_at' => $state === 'running' ? null : gmdate('c'),
        'cities'      => $cities,
        'services'    => $services,
        'total'       => $total,
        'done'        => $done,
        'ok'          => $ok,
        'failed'      => $done - $ok,
        'results'     => $results,   // [{city_id, service_key, status: ok|failed, msg, page_id}]
    ];
    $tmp = $statusFile . '.tmp.' . getmypid();
    if (file_put_contents($tmp, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) !== false) {
        rename($tmp, $statusFile);
    }
};

$writeStatus('running', []);   // immediate marker — the wizard is already polling by the time this line runs

if (!$cities || !$services) {
    $writeStatus('failed', []);
    fwrite(STDERR, "No cities or no services given\n");
    exit(1);
}

$client = json_decode((string) @file_get_contents(PLUSCITIES_CLIENTS_DIR . $clientId . '.json'), true);
$niche  = $client ? pluscities_niche_resolved($client['niche_id'] ?? '') : null;
if (!$niche) {
    $writeStatus('failed', []);
    fwrite(STDERR, "Client or niche not found\n");
    exit(1);
}
// Every combo uses this niche's REAL happy-path picks, clipped to its resolved
// pool (already done by pluscities_niche_resolved()) — no per-combo
// customization, same "simple" decision made for the wizard's bulk mode:
// swap individual blocks afterward on a specific page if one needs to differ.
$cityPicks    = $niche['happy_path_city_blocks'] ?? [];
$servicePicks = $niche['happy_path_service_blocks'] ?? [];

$results = [];
foreach ($cities as $cityId) {
    foreach ($services as $serviceKey) {
        $r = pluscities_create_page($clientId, $cityId, $serviceKey, $cityPicks, $servicePicks, $actor);
        $results[] = [
            'city_id'     => $cityId,
            'service_key' => $serviceKey,
            'status'      => $r['ok'] ? 'ok' : 'failed',
            'msg'         => $r['msg'] ?? '',
            'page_id'     => $r['page_id'] ?? null,
        ];
        $writeStatus('running', $results);   // after EVERY combo, not just at the end
    }
}

// Target-city tracking lives on the client record itself (target_cities[]) —
// same shape admin/pluscities_page_add_save.php already writes for a single
// new page, applied here once per batch for every city that got at least one
// real page out of this run. Re-read the client fresh (not the copy loaded
// before the loop) since pluscities_create_page() never touches this field
// itself, so nothing else could have changed it mid-run.
$okCities = array_unique(array_column(array_filter($results, fn($r) => $r['status'] === 'ok'), 'city_id'));
if ($okCities) {
    $freshClient = json_decode((string) @file_get_contents(PLUSCITIES_CLIENTS_DIR . $clientId . '.json'), true) ?: [];
    $tracked = array_column($freshClient['target_cities'] ?? [], 'city_id');
    $changed = false;
    foreach ($okCities as $cityId) {
        if (in_array($cityId, $tracked, true)) continue;
        $freshClient['target_cities'][] = ['city_id' => $cityId, 'drive_minutes' => $driveMinutes, 'confirmed_by_client' => false];
        $changed = true;
    }
    if ($changed) pluscities_atomic_write_json(PLUSCITIES_CLIENTS_DIR . $clientId . '.json', $freshClient);
}

$failedCount = count(array_filter($results, fn($r) => $r['status'] === 'failed'));
$writeStatus($failedCount > 0 ? 'failed' : 'done', $results);
exit($failedCount > 0 ? 1 : 0);
