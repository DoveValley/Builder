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
 *   --cities=<json array of {city,state,ss,lat,lng,radius_km}>
 *   --businesses-per-city=20 --domain-rank=0|1
 */
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "sales_prospecting_cli.php is CLI only\n"); exit(2); }

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/sales_prospecting.php';
require_once __DIR__ . '/multisite/batch.php'; // ms_pid_start_time() — liveness bookkeeping, shared not reinvented
require_once __DIR__ . '/../admin/infra/lib/keywords.php';

$runId = ''; $niche = ''; $citiesArg = ''; $businessesPerCity = 20; $domainRankEnabled = false;
foreach (array_slice($argv, 1) as $a) {
    if (str_starts_with($a, '--run-id='))              $runId = substr($a, 9);
    if (str_starts_with($a, '--niche='))                $niche = substr($a, 8);
    if (str_starts_with($a, '--cities='))                $citiesArg = substr($a, 9);
    if (str_starts_with($a, '--businesses-per-city='))    $businessesPerCity = (int) substr($a, 22);
    if (str_starts_with($a, '--domain-rank='))            $domainRankEnabled = substr($a, 14) === '1';
}
if ($runId === '' || !preg_match('/^[A-Za-z0-9._-]{1,64}$/', $runId)) {
    fwrite(STDERR, "Missing or invalid run-id\n"); exit(2);
}
$cities = json_decode($citiesArg, true);
$cities = is_array($cities) ? $cities : [];
$states = array_values(array_unique(array_column($cities, 'ss'))); // cosmetic only, for the status JSON's summary line

$runsDir = sales_runs_dir() . '/runs/';
if (!is_dir($runsDir)) mkdir($runsDir, 0775, true);
$statusFile = $runsDir . $runId . '.json';
$startedAt  = gmdate('c');

$results = []; // rolling window only, capped below — the real list lives in sales.db
$costUsd = 0.0; $costAhrefsUnits = 0; // running REAL totals, not the pre-launch estimate — mirrored into sales_cost_log too
/**
 * $detail carries WITHIN-city / within-phase progress — which city is active right
 * now, how many businesses it returned vs. how many have been checked so far, and
 * (in the final rank phase) how many domains have been rank-checked so far. Without
 * this, a city with a large businesses-per-city cap goes silent for the entire time
 * it takes to check every business (a real WordPress + sitemap fetch each), which
 * reads as "stuck" even though it's working — this is what makes that visible.
 */
$writeStatus = function (string $state, int $citiesDone, int $citiesTotal, int $foundNew, int $skippedKnown, int $errors, array $detail = []) use (
    $statusFile, $runId, $startedAt, $niche, $states, &$results, &$costUsd, &$costAhrefsUnits
) {
    $payload = array_merge([
        'run_id'                   => $runId,
        'job_type'                  => 'discovery', // tells the shared progress banner which label set to use
        'state'                     => $state,   // running | done | failed  (ms_read_run() downgrades a dead 'running' to 'stale')
        'pid'                       => getmypid(),
        'pid_started'               => ms_pid_start_time(getmypid()),
        'started_at'                => $startedAt,
        'finished_at'               => $state === 'running' ? null : gmdate('c'),
        'niche'                     => $niche,
        'states'                    => $states,
        'cities_done'               => $citiesDone,
        'cities_total'              => $citiesTotal,
        'done'                      => $citiesDone,   // same field names the shared progress-poll pattern expects
        'total'                     => $citiesTotal,
        'found_new'                 => $foundNew,
        'skipped_known'             => $skippedKnown,
        'errors'                    => $errors,
        'phase'                     => 'searching',   // searching | checking_businesses | looking_up_rank
        'current_city'              => null,
        'city_businesses_found'     => null,
        'city_businesses_checked'   => null,
        'rank_checked'              => null,
        'rank_total'                => null,
        'cost_dataforseo_usd'       => round($costUsd, 4),    // REAL spend so far this run, read straight from DataForSEO's own responses
        'cost_ahrefs_units'         => $costAhrefsUnits,       // REAL units so far this run, read from Ahrefs' response headers
        'results'                   => array_slice($results, -30), // rolling window, not the full list
    ], $detail);
    $tmp = $statusFile . '.tmp.' . getmypid();
    if (file_put_contents($tmp, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) !== false) {
        rename($tmp, $statusFile);
    }
};

$writeStatus('running', 0, 0, 0, 0, 0); // immediate marker — the progress screen is already polling by the time this line runs

if ($niche === '' || !$cities) {
    $writeStatus('failed', 0, 0, 0, 0, 0);
    fwrite(STDERR, "Missing niche or cities\n");
    exit(1);
}
// Not every real niche has a matching DataForSEO/GMB category (confirmed live:
// "foundation repair" has none, Google's own taxonomy doesn't have one either) —
// when $niche doesn't match a real category, it's used as a free-text title/
// keyword search instead (sales_dataforseo_business_search()'s $isCategory flag),
// computed once here so every city in this run searches the same way.
$isCategory = sales_category_exists($niche);
$c = infra_kw_provider('dataforseo');
if (empty($c['login'])) {
    $writeStatus('failed', 0, 0, 0, 0, 0);
    fwrite(STDERR, "DataForSEO is not configured\n");
    exit(1);
}

$citiesTotal = count($cities);
$citiesDone = 0; $foundNew = 0; $skippedKnown = 0; $errors = 0;
$newDomainsThisRun = [];

foreach ($cities as $city) {
    $cityLabel = $city['city'] . ', ' . $city['ss'];
    $writeStatus('running', $citiesDone, $citiesTotal, $foundNew, $skippedKnown, $errors, [
        'phase' => 'searching', 'current_city' => $cityLabel,
        'niche_mode' => $isCategory ? 'category' : 'keyword',
    ]);

    $radiusKm = max(1, min(100000, (int) ($city['radius_km'] ?? 20))); // each picked city carries its OWN radius
    $search = sales_dataforseo_business_search($c, $niche, $isCategory, (string) $city['lat'], (string) $city['lng'], $radiusKm, $businessesPerCity);
    $costUsd += $search['cost'];
    sales_log_cost($runId, 'dataforseo', 'business_search', $search['cost'], 'usd');
    if (!$search['ok']) {
        $errors++;
        $results[] = ['city' => $city['city'], 'state' => $city['ss'], 'status' => 'failed', 'msg' => $search['msg']];
        $citiesDone++;
        $writeStatus('running', $citiesDone, $citiesTotal, $foundNew, $skippedKnown, $errors);
        continue;
    }

    $itemsTotal = count($search['items']);
    $cityFoundNew = 0; $citySkipped = 0; $itemsChecked = 0;
    $writeStatus('running', $citiesDone, $citiesTotal, $foundNew, $skippedKnown, $errors, [
        'phase' => 'checking_businesses', 'current_city' => $cityLabel,
        'city_businesses_found' => $itemsTotal, 'city_businesses_checked' => 0,
    ]);

    foreach ($search['items'] as $item) {
        $website = (string) ($item['url'] ?? '');
        if ($website === '') { $itemsChecked++; continue; } // no website at all — nothing to evaluate, not saved
        $domain = sales_normalize_domain((string) ($item['domain'] ?? $website));
        if ($domain === '') { $itemsChecked++; continue; }

        if (sales_prospect_exists($domain)) {
            $citySkipped++; $skippedKnown++; $itemsChecked++;
            continue; // skip-known-domains, by design
        }

        $homepage = sales_fetch_homepage($website); // one fetch, shared by the WordPress check and the office-city detector
        $isWp = sales_check_wordpress($website, $homepage);
        $sitemapCount = sales_count_sitemap_urls($domain);
        // Office-city detection is WordPress-only, by request — non-WP leads aren't
        // actionable via the pluscities-delivery WP plugin anyway, so detecting their
        // office location is wasted parsing on a lead that isn't in scope yet.
        $officeCities = $isWp === 'yes' ? sales_detect_office_cities($homepage) : [];
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
            'office_cities'        => $officeCities,
            'found_run_id'         => $runId,
        ]);
        if ($inserted) { $cityFoundNew++; $foundNew++; $newDomainsThisRun[] = $domain; }
        $itemsChecked++;
        $writeStatus('running', $citiesDone, $citiesTotal, $foundNew, $skippedKnown, $errors, [
            'phase' => 'checking_businesses', 'current_city' => $cityLabel,
            'city_businesses_found' => $itemsTotal, 'city_businesses_checked' => $itemsChecked,
        ]);
    }
    $results[] = [
        'city' => $city['city'], 'state' => $city['ss'], 'status' => 'ok',
        'msg' => $cityFoundNew . ' new, ' . $citySkipped . ' already known',
    ];
    $citiesDone++;
    $writeStatus('running', $citiesDone, $citiesTotal, $foundNew, $skippedKnown, $errors);
}

// Domain Rating comes from Ahrefs, not DataForSEO (swapped 2026-11-27 — DataForSEO's
// backlinks/bulk_ranks/live is a raw 0-1000 graph score, not the familiar 0-100 DR).
// Done after discovery, only for domains this run actually found, never re-spent on
// anything already in sales.db. Ahrefs has no multi-domain batch endpoint, so this is
// N paced calls (sales_ahrefs_bulk_rank()'s own rate-limit delay), not one.
if ($domainRankEnabled && $newDomainsThisRun) {
    $ahrefs = infra_kw_provider('ahrefs');
    if (empty($ahrefs['api_key'])) {
        fwrite(STDERR, "Domain rank requested but Ahrefs is not configured — skipping, leads are still saved\n");
    }
    if (!empty($ahrefs['api_key'])) {
        $rankTotal = count(array_unique(array_filter($newDomainsThisRun)));
        $writeStatus('running', $citiesDone, $citiesTotal, $foundNew, $skippedKnown, $errors, [
            'phase' => 'looking_up_rank', 'rank_checked' => 0, 'rank_total' => $rankTotal,
        ]);
        $result = sales_ahrefs_bulk_rank($ahrefs, $newDomainsThisRun, function ($done, $total) use (
            $writeStatus, $citiesDone, $citiesTotal, $foundNew, $skippedKnown, $errors
        ) {
            $writeStatus('running', $citiesDone, $citiesTotal, $foundNew, $skippedKnown, $errors, [
                'phase' => 'looking_up_rank', 'rank_checked' => $done, 'rank_total' => $total,
            ]);
        });
        $costAhrefsUnits += $result['units_total'];
        sales_log_cost($runId, 'ahrefs', 'domain_rank', (float) $result['units_total'], 'units');
        if ($result['ranks']) {
            $upd = sales_db()->prepare('UPDATE sales_prospects SET domain_rank = :rank, domain_rank_checked_at = :checked WHERE domain = :domain');
            $checkedAt = gmdate('c');
            foreach ($result['ranks'] as $domain => $rank) {
                $upd->execute([':rank' => $rank, ':checked' => $checkedAt, ':domain' => $domain]);
            }
        }
    }
}

$failed = $errors > 0 && $foundNew === 0 && $skippedKnown === 0;
$writeStatus($failed ? 'failed' : 'done', $citiesDone, $citiesTotal, $foundNew, $skippedKnown, $errors);
exit($failed ? 1 : 0);
