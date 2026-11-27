<?php
/**
 * includes/sales_prospecting_cli.php — CLI-only detached worker for the Sales
 * prospecting tool (see admin/sales.php / admin/sales_launch.php).
 *
 * Launched via ms_launch_job_now() (includes/multisite/batch.php) — the
 * fleet's own proven background-job mechanism, reused as-is rather than
 * rebuilt (see includes/sales_prospecting.php's own docblock for why).
 *
 * Writes its own structured run-status file (sales_runs/runs/{run_id}.json),
 * same shape/intent as pluscities_bulk_pages_cli.php's: an immediate
 * 'running' marker before any real work starts, then a fresh write after
 * EVERY CITY completes — not just at the end — so progress is visible the
 * whole way through a run that can take minutes. Atomic tmp-then-rename, so
 * a poller never reads a half-written file. The `results` list in this file
 * is a short ROLLING window for the progress screen, not the real list —
 * the real list is every row in sales.db, written as soon as it's found.
 *
 * Usage: php includes/sales_prospecting_cli.php --run-id=X --niche=slug
 *   --states=TX,OK --cities-per-state=5 --businesses-per-city=20
 *   --domain-rank=0|1 --radius-km=20
 */
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "sales_prospecting_cli.php is CLI only\n"); exit(2); }

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/sales_prospecting.php';
require_once __DIR__ . '/multisite/batch.php'; // ms_pid_start_time() — liveness bookkeeping, shared not reinvented
require_once __DIR__ . '/../admin/infra/lib/keywords.php';

$runId = ''; $niche = ''; $statesArg = ''; $citiesPerState = 5; $businessesPerCity = 20;
$domainRankEnabled = false; $radiusKm = 20;
foreach (array_slice($argv, 1) as $a) {
    if (str_starts_with($a, '--run-id='))              $runId = substr($a, 9);
    if (str_starts_with($a, '--niche='))                $niche = substr($a, 8);
    if (str_starts_with($a, '--states='))                $statesArg = substr($a, 9);
    if (str_starts_with($a, '--cities-per-state='))       $citiesPerState = (int) substr($a, 19);
    if (str_starts_with($a, '--businesses-per-city='))    $businessesPerCity = (int) substr($a, 22);
    if (str_starts_with($a, '--domain-rank='))            $domainRankEnabled = substr($a, 14) === '1';
    if (str_starts_with($a, '--radius-km='))               $radiusKm = (int) substr($a, 12);
}
if ($runId === '' || !preg_match('/^[A-Za-z0-9._-]{1,64}$/', $runId)) {
    fwrite(STDERR, "Missing or invalid run-id\n"); exit(2);
}
$states = array_values(array_filter(array_map('trim', explode(',', $statesArg))));

$runsDir = sales_runs_dir() . '/runs/';
if (!is_dir($runsDir)) mkdir($runsDir, 0775, true);
$statusFile = $runsDir . $runId . '.json';
$startedAt  = gmdate('c');

$results = []; // rolling window only, capped below — the real list lives in sales.db
$writeStatus = function (string $state, int $citiesDone, int $citiesTotal, int $foundNew, int $skippedKnown, int $errors) use (
    $statusFile, $runId, $startedAt, $niche, $states, &$results
) {
    $payload = [
        'run_id'         => $runId,
        'state'          => $state,   // running | done | failed  (ms_read_run() downgrades a dead 'running' to 'stale')
        'pid'            => getmypid(),
        'pid_started'    => ms_pid_start_time(getmypid()),
        'started_at'     => $startedAt,
        'finished_at'    => $state === 'running' ? null : gmdate('c'),
        'niche'          => $niche,
        'states'         => $states,
        'cities_done'    => $citiesDone,
        'cities_total'   => $citiesTotal,
        'done'           => $citiesDone,   // same field names the shared progress-poll pattern expects
        'total'          => $citiesTotal,
        'found_new'      => $foundNew,
        'skipped_known'  => $skippedKnown,
        'errors'         => $errors,
        'results'        => array_slice($results, -30), // rolling window, not the full list
    ];
    $tmp = $statusFile . '.tmp.' . getmypid();
    if (file_put_contents($tmp, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) !== false) {
        rename($tmp, $statusFile);
    }
};

$writeStatus('running', 0, 0, 0, 0, 0); // immediate marker — the progress screen is already polling by the time this line runs

if ($niche === '' || !$states) {
    $writeStatus('failed', 0, 0, 0, 0, 0);
    fwrite(STDERR, "Missing niche or states\n");
    exit(1);
}
if (!sales_category_exists($niche)) {
    $writeStatus('failed', 0, 0, 0, 0, 0);
    fwrite(STDERR, "'$niche' is not a recognized DataForSEO business category\n");
    exit(1);
}
$c = infra_kw_provider('dataforseo');
if (empty($c['login'])) {
    $writeStatus('failed', 0, 0, 0, 0, 0);
    fwrite(STDERR, "DataForSEO is not configured\n");
    exit(1);
}

$cities = sales_resolve_cities($states, $citiesPerState);
$citiesTotal = count($cities);
$citiesDone = 0; $foundNew = 0; $skippedKnown = 0; $errors = 0;
$newDomainsThisRun = [];

foreach ($cities as $city) {
    $search = sales_dataforseo_business_search($c, $niche, (string) $city['lat'], (string) $city['lng'], $radiusKm, $businessesPerCity);
    if (!$search['ok']) {
        $errors++;
        $results[] = ['city' => $city['city'], 'state' => $city['ss'], 'status' => 'failed', 'msg' => $search['msg']];
        $citiesDone++;
        $writeStatus('running', $citiesDone, $citiesTotal, $foundNew, $skippedKnown, $errors);
        continue;
    }

    $cityFoundNew = 0; $citySkipped = 0;
    foreach ($search['items'] as $item) {
        $website = (string) ($item['url'] ?? '');
        if ($website === '') continue; // no website at all — nothing to evaluate, not saved
        $domain = sales_normalize_domain((string) ($item['domain'] ?? $website));
        if ($domain === '') continue;

        if (sales_prospect_exists($domain)) { $citySkipped++; $skippedKnown++; continue; } // skip-known-domains, by design

        $isWp = sales_check_wordpress($website);
        $sitemapCount = sales_count_sitemap_urls($domain);
        $inserted = sales_insert_prospect([
            'domain'               => $domain,
            'website_url'          => $website,
            'business_name'        => (string) ($item['title'] ?? ''),
            'phone'                => (string) ($item['phone'] ?? ''),
            'niche'                => $niche,
            'city'                 => $city['city'],
            'state'                => $city['ss'],
            'google_rating'        => $item['rating']['value'] ?? null,
            'google_review_count'  => $item['rating']['votes_count'] ?? null,
            'is_wordpress'         => $isWp,
            'sitemap_url_count'    => $sitemapCount,
            'found_run_id'         => $runId,
        ]);
        if ($inserted) { $cityFoundNew++; $foundNew++; $newDomainsThisRun[] = $domain; }
    }
    $results[] = [
        'city' => $city['city'], 'state' => $city['ss'], 'status' => 'ok',
        'msg' => $cityFoundNew . ' new, ' . $citySkipped . ' already known',
    ];
    $citiesDone++;
    $writeStatus('running', $citiesDone, $citiesTotal, $foundNew, $skippedKnown, $errors);
}

// Domain rank is batched ONE call per up to 1,000 domains (backlinks/bulk_ranks/live),
// never once per business — done after discovery, only for domains this run actually
// found, never re-spent on anything already in sales.db.
if ($domainRankEnabled && $newDomainsThisRun) {
    $ranks = sales_dataforseo_bulk_rank($c, $newDomainsThisRun);
    if ($ranks) {
        $db = sales_db();
        $upd = $db->prepare('UPDATE sales_prospects SET domain_rank = :rank, domain_rank_checked_at = :checked WHERE domain = :domain');
        $checkedAt = gmdate('c');
        foreach ($ranks as $domain => $rank) {
            if ($rank === null) continue;
            $upd->execute([':rank' => $rank, ':checked' => $checkedAt, ':domain' => $domain]);
        }
    }
}

$failed = $errors > 0 && $foundNew === 0 && $skippedKnown === 0;
$writeStatus($failed ? 'failed' : 'done', $citiesDone, $citiesTotal, $foundNew, $skippedKnown, $errors);
exit($failed ? 1 : 0);
