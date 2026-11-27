<?php
/**
 * includes/sales_detect_offices_cli.php — CLI-only detached worker that backfills
 * office_cities for every SAVED lead matching a filter that doesn't have one yet.
 * Launched via sales_launch_office_detect_job() (includes/sales_prospecting.php),
 * same ms_launch_job_now() mechanism and same run-status JSON shape as the main
 * discovery worker (includes/sales_prospecting_cli.php) — a poller doesn't need to
 * know which kind of job it's watching, just `job_type` to label it correctly.
 *
 * Usage: php includes/sales_detect_offices_cli.php --run-id=X
 *   --filters=<json {niche?,state?,status?,is_wordpress?}>
 */
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "sales_detect_offices_cli.php is CLI only\n"); exit(2); }

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/sales_prospecting.php';
require_once __DIR__ . '/multisite/batch.php'; // ms_pid_start_time() — liveness bookkeeping, shared not reinvented

$runId = ''; $filtersArg = '';
foreach (array_slice($argv, 1) as $a) {
    if (str_starts_with($a, '--run-id='))  $runId = substr($a, 9);
    if (str_starts_with($a, '--filters=')) $filtersArg = substr($a, 10);
}
if ($runId === '' || !preg_match('/^[A-Za-z0-9._-]{1,64}$/', $runId)) {
    fwrite(STDERR, "Missing or invalid run-id\n"); exit(2);
}
$filters = json_decode($filtersArg, true);
$filters = is_array($filters) ? array_filter($filters, fn($v) => $v !== '') : [];

$runsDir = sales_runs_dir() . '/runs/';
if (!is_dir($runsDir)) mkdir($runsDir, 0775, true);
$statusFile = $runsDir . $runId . '.json';
$startedAt  = gmdate('c');

$results = []; // rolling window only — the real list is the updated rows in sales.db
$writeStatus = function (string $state, int $done, int $total, int $foundCount) use ($statusFile, $runId, $startedAt, &$results) {
    $payload = [
        'run_id'      => $runId,
        'job_type'    => 'office_backfill', // tells the shared progress banner which label set to use
        'state'       => $state,            // running | done | failed  (ms_read_run() downgrades a dead 'running' to 'stale')
        'pid'         => getmypid(),
        'pid_started' => ms_pid_start_time(getmypid()),
        'started_at'  => $startedAt,
        'finished_at' => $state === 'running' ? null : gmdate('c'),
        'done'        => $done,
        'total'       => $total,
        'found_new'   => $foundCount, // reusing the discovery job's field name: "how many got a real answer"
        'results'     => array_slice($results, -30),
    ];
    $tmp = $statusFile . '.tmp.' . getmypid();
    if (file_put_contents($tmp, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) !== false) {
        rename($tmp, $statusFile);
    }
};

$writeStatus('running', 0, 0, 0); // immediate marker — the progress screen is already polling by the time this line runs

// WordPress-only, by request — non-WP leads aren't actionable via the
// pluscities-delivery WP plugin yet, so their office location isn't worth checking.
$rows = array_values(array_filter(
    sales_list_prospects($filters),
    fn($r) => $r['office_cities'] === '' && $r['is_wordpress'] === 'yes'
));
$total = count($rows);
$done = 0; $foundCount = 0;
$upd = sales_db()->prepare('UPDATE sales_prospects SET office_cities = :office_cities, last_checked_at = :checked WHERE id = :id');

foreach ($rows as $row) {
    $url = $row['website_url'] !== '' ? $row['website_url'] : ('https://' . $row['domain']);
    $homepage = sales_fetch_homepage($url);
    $cities = sales_detect_office_cities($homepage);
    $upd->execute([':office_cities' => implode('; ', $cities), ':checked' => gmdate('c'), ':id' => $row['id']]);
    if ($cities) {
        $foundCount++;
        $results[] = ['city' => $row['domain'], 'state' => '', 'status' => 'ok', 'msg' => implode('; ', $cities)];
    }
    $done++;
    $writeStatus('running', $done, $total, $foundCount);
}

$writeStatus('done', $done, $total, $foundCount);
exit(0);
