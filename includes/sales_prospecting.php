<?php
/**
 * includes/sales_prospecting.php — the Sales prospecting tool's own module.
 * Standalone by design (see /root/.claude/plans/adaptive-shimmying-lark.md):
 * owns its own sqlite file (admin/infra/state/sales.db) and its own
 * sales_prospects table — nothing outside this module and its CLI worker/
 * admin pages reads or writes that table. The only things borrowed from
 * elsewhere are existing DATA, not another feature's code:
 *   - fleet.db's existing `cities` table (read-only), to resolve "state ->
 *     its biggest cities" — there's no metro/CBSA table in this codebase, so
 *     "metro area" here just means a city ranked by population within the
 *     chosen state.
 *   - the existing DataForSEO account credentials in
 *     admin/infra/config/keywords.json, via admin/infra/lib/keywords.php's
 *     infra_kw_provider()/infra_kw_dfs_post()/infra_kw_dfs_headers() — CLAUDE.md's
 *     "one module per external API" rule means a second hand-rolled DataForSEO
 *     client here would repeat a mistake this codebase has already made once,
 *     not avoid coupling.
 *   - admin/infra/lib/http.php's infra_http() for the plain (non-DataForSEO)
 *     fetches this feature does on its own: checking a candidate's own site
 *     for WordPress and counting its sitemap.
 *   - includes/multisite/batch.php's ms_launch_job()/ms_read_run() for
 *     detached-job launching — reused because it already found and fixed two
 *     real bugs (an apache restart silently killing a "detached" job; a
 *     recycled PID reading as "still running" forever), not because of the
 *     one-module-per-API rule.
 *
 * Assumes config.php is already loaded by the caller (same convention as
 * includes/wp_builder.php).
 */

const SALES_CATEGORIES_PER_TASK = 10;     // DataForSEO's own cap on categories[] per search task
const SALES_BULK_RANK_CHUNK     = 1000;   // backlinks/bulk_ranks/live's own cap on targets[] per task

// Placeholders — confirm the real per-call prices on your DataForSEO account's
// own pricing page and adjust these two constants; shown in the cost estimate
// as a low/high range, not billed from here.
const SALES_COST_PER_CITY_SEARCH      = 0.01;
const SALES_COST_PER_DOMAIN_RANK_CALL = 0.0005;

function sales_runs_dir(): string { return BASE_DIR . '/sales_runs'; }
function sales_state_dir(): string { return BASE_DIR . '/admin/infra/state'; }
function sales_categories_cache_path(): string { return sales_state_dir() . '/sales_categories.json'; }

/* ------------------------------------------------------------- data stores */

/** This module's own sqlite file. Never written to by anything else. */
function sales_db(): PDO
{
    static $db = null;
    if ($db instanceof PDO) return $db;

    $dir = sales_state_dir();
    if (!is_dir($dir)) @mkdir($dir, 0700, true);

    $db = new PDO('sqlite:' . $dir . '/sales.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA journal_mode=WAL');
    $db->exec('PRAGMA busy_timeout=5000'); // the CLI worker writes while the admin page may be reading
    $db->exec('CREATE TABLE IF NOT EXISTS sales_prospects (
        id                      INTEGER PRIMARY KEY AUTOINCREMENT,
        domain                  TEXT UNIQUE NOT NULL,
        website_url             TEXT DEFAULT "",
        business_name           TEXT DEFAULT "",
        phone                   TEXT DEFAULT "",
        niche                   TEXT DEFAULT "",
        city                    TEXT DEFAULT "",
        state                   TEXT DEFAULT "",
        google_rating           REAL,
        google_review_count     INTEGER,
        is_wordpress            TEXT DEFAULT "unknown",
        sitemap_url_count       INTEGER,
        domain_rank             REAL,
        domain_rank_checked_at  TEXT,
        status                  TEXT DEFAULT "new",
        notes                   TEXT DEFAULT "",
        found_run_id            TEXT DEFAULT "",
        found_at                TEXT DEFAULT "",
        last_checked_at         TEXT DEFAULT ""
    )');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_sales_prospects_niche_state ON sales_prospects(niche, state)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_sales_prospects_status ON sales_prospects(status)');
    return $db;
}

/** Read-only connection into the EXISTING fleet.db, for its `cities` table only. Never written to. */
function sales_fleet_db(): PDO
{
    static $db = null;
    if ($db instanceof PDO) return $db;
    $db = new PDO('sqlite:' . sales_state_dir() . '/fleet.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $db;
}

/* --------------------------------------------------------------- geography */

function sales_us_states(): array
{
    return [
        'AL' => 'Alabama', 'AK' => 'Alaska', 'AZ' => 'Arizona', 'AR' => 'Arkansas',
        'CA' => 'California', 'CO' => 'Colorado', 'CT' => 'Connecticut', 'DE' => 'Delaware',
        'DC' => 'District of Columbia', 'FL' => 'Florida', 'GA' => 'Georgia', 'HI' => 'Hawaii',
        'ID' => 'Idaho', 'IL' => 'Illinois', 'IN' => 'Indiana', 'IA' => 'Iowa', 'KS' => 'Kansas',
        'KY' => 'Kentucky', 'LA' => 'Louisiana', 'ME' => 'Maine', 'MD' => 'Maryland',
        'MA' => 'Massachusetts', 'MI' => 'Michigan', 'MN' => 'Minnesota', 'MS' => 'Mississippi',
        'MO' => 'Missouri', 'MT' => 'Montana', 'NE' => 'Nebraska', 'NV' => 'Nevada',
        'NH' => 'New Hampshire', 'NJ' => 'New Jersey', 'NM' => 'New Mexico', 'NY' => 'New York',
        'NC' => 'North Carolina', 'ND' => 'North Dakota', 'OH' => 'Ohio', 'OK' => 'Oklahoma',
        'OR' => 'Oregon', 'PA' => 'Pennsylvania', 'RI' => 'Rhode Island', 'SC' => 'South Carolina',
        'SD' => 'South Dakota', 'TN' => 'Tennessee', 'TX' => 'Texas', 'UT' => 'Utah',
        'VT' => 'Vermont', 'VA' => 'Virginia', 'WA' => 'Washington', 'WV' => 'West Virginia',
        'WI' => 'Wisconsin', 'WY' => 'Wyoming',
    ];
}

/** "Metro area" = a city ranked by population within the state — no CBSA table exists here. */
function sales_resolve_cities(array $stateAbbrevs, int $perState): array
{
    $perState = max(1, min(50, $perState));
    $out = [];
    $stmt = sales_fleet_db()->prepare(
        'SELECT city, state, ss, population, lat, lng FROM cities WHERE ss = ? ORDER BY population DESC LIMIT ?'
    );
    foreach ($stateAbbrevs as $ss) {
        $ss = strtoupper(trim((string) $ss));
        if ($ss === '' || !isset(sales_us_states()[$ss])) continue;
        $stmt->bindValue(1, $ss, PDO::PARAM_STR);
        $stmt->bindValue(2, $perState, PDO::PARAM_INT);
        $stmt->execute();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $out[] = $row;
    }
    return $out;
}

/* ------------------------------------------------------- category taxonomy */

/**
 * DataForSEO's business_listings/search endpoint takes `categories` from its
 * OWN fixed taxonomy (Google-My-Business-style slugs like "roofing_contractor",
 * "personal_injury_attorney") — NOT arbitrary free text. ~5,156 of them.
 * Cached to disk once (a plain GET, no task body) so the admin page can offer
 * a <datalist> instead of Scott guessing a slug blind.
 */
function sales_categories_refresh(): array
{
    require_once __DIR__ . '/../admin/infra/lib/keywords.php';
    $c = infra_kw_provider('dataforseo');
    if (empty($c['login'])) {
        return ['ok' => false, 'msg' => 'DataForSEO is not configured yet — set it up on the Cities/Niche tab first.', 'count' => 0];
    }
    $r = infra_http('GET', 'https://api.dataforseo.com/v3/business_data/business_listings/categories',
        ['headers' => infra_kw_dfs_headers($c), 'timeout' => 30]);
    $unwrapped = infra_kw_dfs_interpret($r);
    if (!$unwrapped['ok']) return ['ok' => false, 'msg' => $unwrapped['msg'], 'count' => 0];

    $rows = $unwrapped['result'];
    if (isset($rows[0]['items']) && is_array($rows[0]['items'])) $rows = $rows[0]['items']; // defensive, in case of a nested shape
    $cats = [];
    foreach ($rows as $row) {
        $name = (string) ($row['category_name'] ?? '');
        if ($name !== '') $cats[] = $name;
    }
    sort($cats);
    file_put_contents(sales_categories_cache_path(), json_encode(['fetched_at' => gmdate('c'), 'categories' => $cats]));
    return ['ok' => true, 'msg' => '', 'count' => count($cats)];
}

function sales_categories_cached(): array
{
    $path = sales_categories_cache_path();
    if (!is_file($path)) sales_categories_refresh();
    $j = json_decode((string) @file_get_contents($path), true);
    return is_array($j['categories'] ?? null) ? $j['categories'] : [];
}

function sales_category_exists(string $slug): bool
{
    $slug = strtolower(trim($slug));
    if ($slug === '') return false;
    foreach (sales_categories_cached() as $c) {
        if (strtolower($c) === $slug) return true;
    }
    return false;
}

function sales_category_label(string $slug): string
{
    return ucwords(str_replace('_', ' ', $slug));
}

/* --------------------------------------------------------------- DataForSEO */

/** One category search near one lat/lng. $lat/$lng are the plain decimal strings fleet.db's cities table already stores. */
function sales_dataforseo_business_search(array $c, string $categorySlug, string $lat, string $lng, int $radiusKm, int $limit): array
{
    $radiusKm = max(1, min(100000, $radiusKm));
    $limit    = max(1, min(1000, $limit));
    $task = [
        'categories'          => [$categorySlug],
        'location_coordinate' => $lat . ',' . $lng . ',' . $radiusKm,
        'limit'               => $limit,
    ];
    $r = infra_kw_dfs_post($c, 'business_data/business_listings/search/live', $task);
    if (!$r['ok']) return ['ok' => false, 'msg' => $r['msg'], 'items' => []];
    $items = $r['result'];
    if (isset($items[0]['items']) && is_array($items[0]['items'])) $items = $items[0]['items'];
    return ['ok' => true, 'msg' => '', 'items' => $items];
}

/**
 * Domain-authority-style rank (0-1000) for many domains in one call —
 * backlinks/bulk_ranks/live takes up to 1,000 targets per task, so this is
 * called once per batch of newly-found domains, never once per business.
 * @param string[] $domains bare hosts, no scheme/www
 * @return array<string,?float> domain => rank, or null if DataForSEO had no answer for it
 */
function sales_dataforseo_bulk_rank(array $c, array $domains): array
{
    $domains = array_values(array_unique(array_filter($domains)));
    $out = [];
    foreach (array_chunk($domains, SALES_BULK_RANK_CHUNK) as $chunk) {
        $r = infra_kw_dfs_post($c, 'backlinks/bulk_ranks/live', ['targets' => $chunk]);
        if (!$r['ok']) continue;
        $items = $r['result'];
        if (isset($items[0]['items']) && is_array($items[0]['items'])) $items = $items[0]['items'];
        foreach ($items as $it) {
            $t = (string) ($it['target'] ?? '');
            if ($t !== '') $out[$t] = isset($it['rank']) ? (float) $it['rank'] : null;
        }
    }
    return $out;
}

/* ----------------------------------------------------- plain-site checks */

function sales_normalize_domain(string $urlOrDomain): string
{
    $s = trim($urlOrDomain);
    if ($s === '') return '';
    if (!preg_match('#^https?://#i', $s)) $s = 'https://' . $s;
    $host = (string) (parse_url($s, PHP_URL_HOST) ?: '');
    $host = strtolower($host);
    if (strncmp($host, 'www.', 4) === 0) $host = substr($host, 4);
    return $host;
}

/** Best-effort, not a guarantee — labelled as a heuristic in the results table. */
function sales_check_wordpress(string $url): string
{
    require_once __DIR__ . '/../admin/infra/lib/http.php';
    $r = infra_http('GET', $url, ['timeout' => 12, 'verify' => false]);
    if ($r['error'] === '' && $r['code'] >= 200 && $r['code'] < 400 && $r['raw'] !== '') {
        $body = $r['raw'];
        if (stripos($body, 'wp-content/') !== false
            || stripos($body, 'wp-includes/') !== false
            || preg_match('/<meta[^>]+name=["\']generator["\'][^>]+content=["\']WordPress/i', $body)) {
            return 'yes';
        }
    }
    // Secondary check: the WP REST API root always carries a 'namespaces' array.
    $r2 = infra_http('GET', rtrim($url, '/') . '/wp-json/', ['timeout' => 10, 'verify' => false]);
    if ($r2['error'] === '' && is_array($r2['json']) && isset($r2['json']['namespaces'])) return 'yes';

    if ($r['error'] === '' && $r['code'] > 0) return 'no';   // the site answered, just not as WordPress
    return 'unknown';                                          // couldn't reach the site at all
}

/** NULL = couldn't determine (no sitemap, unreachable) — distinct from 0 (a real, empty sitemap). */
function sales_count_sitemap_urls(string $domain): ?int
{
    require_once __DIR__ . '/../admin/infra/lib/http.php';
    $r = infra_http('GET', 'https://' . $domain . '/sitemap.xml', ['timeout' => 12, 'verify' => false]);
    if ($r['error'] !== '' || $r['code'] < 200 || $r['code'] >= 300 || $r['raw'] === '') return null;

    $xml = @simplexml_load_string($r['raw']);
    if ($xml === false) return null;
    $rootName = strtolower($xml->getName());

    if ($rootName === 'urlset') {
        return count($xml->xpath('//*[local-name()="url"]') ?: []);
    }
    if ($rootName === 'sitemapindex') {
        $locs  = $xml->xpath('//*[local-name()="loc"]') ?: [];
        $total = 0;
        foreach (array_slice($locs, 0, 5) as $loc) { // cap child sitemaps fetched, not a full crawl
            $r2 = infra_http('GET', (string) $loc, ['timeout' => 12, 'verify' => false]);
            if ($r2['error'] !== '' || $r2['raw'] === '') continue;
            $cx = @simplexml_load_string($r2['raw']);
            if ($cx !== false) $total += count($cx->xpath('//*[local-name()="url"]') ?: []);
            if ($total >= 5000) break; // cap the total count, not a precision claim past this point
        }
        return $total;
    }
    return null;
}

/* --------------------------------------------------------------- prospects */

function sales_prospect_exists(string $domain): bool
{
    $stmt = sales_db()->prepare('SELECT 1 FROM sales_prospects WHERE domain = ?');
    $stmt->execute([$domain]);
    return (bool) $stmt->fetchColumn();
}

/** Returns false (via the UNIQUE constraint + OR IGNORE) if the domain is already tracked — never overwrites. */
function sales_insert_prospect(array $row): bool
{
    $stmt = sales_db()->prepare('INSERT OR IGNORE INTO sales_prospects
        (domain, website_url, business_name, phone, niche, city, state, google_rating, google_review_count,
         is_wordpress, sitemap_url_count, domain_rank, domain_rank_checked_at, status, found_run_id, found_at, last_checked_at)
        VALUES
        (:domain, :website_url, :business_name, :phone, :niche, :city, :state, :google_rating, :google_review_count,
         :is_wordpress, :sitemap_url_count, :domain_rank, :domain_rank_checked_at, \'new\', :found_run_id, :found_at, :last_checked_at)');
    $now = gmdate('c');
    $stmt->execute([
        ':domain'                 => $row['domain'],
        ':website_url'            => $row['website_url'] ?? '',
        ':business_name'          => $row['business_name'] ?? '',
        ':phone'                  => $row['phone'] ?? '',
        ':niche'                  => $row['niche'] ?? '',
        ':city'                   => $row['city'] ?? '',
        ':state'                  => $row['state'] ?? '',
        ':google_rating'          => $row['google_rating'] ?? null,
        ':google_review_count'    => $row['google_review_count'] ?? null,
        ':is_wordpress'           => $row['is_wordpress'] ?? 'unknown',
        ':sitemap_url_count'      => $row['sitemap_url_count'] ?? null,
        ':domain_rank'            => $row['domain_rank'] ?? null,
        ':domain_rank_checked_at' => $row['domain_rank_checked_at'] ?? null,
        ':found_run_id'           => $row['found_run_id'] ?? '',
        ':found_at'               => $now,
        ':last_checked_at'        => $now,
    ]);
    // INSERT OR IGNORE: rowCount() is 0 when the UNIQUE(domain) constraint silently
    // skipped it — lastInsertId() is unreliable here (SQLite only updates it on an
    // actual insert, so it would still report a PREVIOUS successful insert's id).
    return $stmt->rowCount() > 0;
}

/** Only status/notes — everything else is written by discovery/refresh, never by the inline-edit endpoint. */
function sales_update_prospect(int $id, array $fields): bool
{
    $allowed = ['status', 'notes'];
    $sets = []; $params = [':id' => $id];
    foreach ($fields as $k => $v) {
        if (!in_array($k, $allowed, true)) continue;
        $sets[] = "$k = :$k";
        $params[":$k"] = $v;
    }
    if (!$sets) return false;
    return sales_db()->prepare('UPDATE sales_prospects SET ' . implode(', ', $sets) . ' WHERE id = :id')->execute($params);
}

function sales_get_prospect(int $id): ?array
{
    $stmt = sales_db()->prepare('SELECT * FROM sales_prospects WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function sales_list_prospects(array $filters = []): array
{
    $where = []; $params = [];
    foreach (['niche', 'state', 'status', 'is_wordpress'] as $key) {
        if (!empty($filters[$key])) { $where[] = "$key = ?"; $params[] = $filters[$key]; }
    }
    $sql = 'SELECT * FROM sales_prospects';
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);

    $allowedSort = ['found_at', 'domain_rank', 'sitemap_url_count', 'google_rating', 'business_name', 'status', 'domain'];
    $sort = in_array($filters['sort'] ?? '', $allowedSort, true) ? $filters['sort'] : 'found_at';
    $dir  = (($filters['dir'] ?? 'desc') === 'asc') ? 'ASC' : 'DESC';
    $sql .= " ORDER BY $sort $dir";

    $stmt = sales_db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** Re-checks one already-saved lead in-request (no background job needed for a single domain). */
function sales_refresh_prospect(int $id): bool
{
    $p = sales_get_prospect($id);
    if (!$p) return false;

    $url = $p['website_url'] !== '' ? $p['website_url'] : ('https://' . $p['domain']);
    $fields = [
        'is_wordpress'      => sales_check_wordpress($url),
        'sitemap_url_count' => sales_count_sitemap_urls($p['domain']),
        'last_checked_at'   => gmdate('c'),
    ];

    if ($p['domain_rank'] !== null) { // only re-spend on a rank lookup if one was ever taken for this lead
        require_once __DIR__ . '/../admin/infra/lib/keywords.php';
        $c = infra_kw_provider('dataforseo');
        if (!empty($c['login'])) {
            $ranks = sales_dataforseo_bulk_rank($c, [$p['domain']]);
            if (array_key_exists($p['domain'], $ranks)) {
                $fields['domain_rank'] = $ranks[$p['domain']];
                $fields['domain_rank_checked_at'] = gmdate('c');
            }
        }
    }

    $sets = []; $params = [':id' => $id];
    foreach ($fields as $k => $v) { $sets[] = "$k = :$k"; $params[":$k"] = $v; }
    return sales_db()->prepare('UPDATE sales_prospects SET ' . implode(', ', $sets) . ' WHERE id = :id')->execute($params);
}

/* -------------------------------------------------------------- job launch */

function sales_cost_estimate(array $states, int $citiesPerState, int $businessesPerCity, bool $domainRankEnabled): array
{
    $citiesTotal   = count(sales_resolve_cities($states, $citiesPerState));
    $businessesEst = $citiesTotal * max(1, $businessesPerCity);
    $costLow       = $citiesTotal * SALES_COST_PER_CITY_SEARCH;
    $costHigh      = $costLow + ($domainRankEnabled ? $businessesEst * SALES_COST_PER_DOMAIN_RANK_CALL : 0);
    return [
        'cities_total'   => $citiesTotal,
        'businesses_est' => $businessesEst,
        'cost_low'       => $costLow,
        'cost_high'      => $costHigh,
    ];
}

/**
 * Launches includes/sales_prospecting_cli.php detached. Reuses the multisite
 * fleet's own proven background-job mechanism (ms_launch_job_now()/
 * ms_with_launch_lock()/ms_active_run()) rather than rebuilding a second
 * detach/liveness mechanism, same reasoning as pluscities_bulk_create_pages_launch().
 * Required lazily so multisite's globals/constants aren't pulled into every
 * ordinary Sales page view, only when a search is actually launched or checked.
 *
 * @return array{run_id:string,started:bool,msg:string}
 */
function sales_launch_job(string $categorySlug, array $states, int $citiesPerState, int $businessesPerCity, bool $domainRankEnabled, int $radiusKm): array
{
    require_once __DIR__ . '/multisite/batch.php';

    $dir = sales_runs_dir();
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        return ['run_id' => '', 'started' => false, 'msg' => 'Could not create the job directory.'];
    }

    return ms_with_launch_lock($dir . '/.lock', function () use ($dir, $categorySlug, $states, $citiesPerState, $businessesPerCity, $domainRankEnabled, $radiusKm) {
        $active = ms_active_run($dir . '/runs');
        if ($active) {
            return ['run_id' => $active['run_id'], 'started' => false, 'msg' => 'A search is already running.'];
        }
        $runId  = gmdate('Ymd-His') . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
        $script = __DIR__ . '/sales_prospecting_cli.php';
        $args = [
            '--run-id=' . $runId,
            '--niche=' . $categorySlug,
            '--states=' . implode(',', $states),
            '--cities-per-state=' . $citiesPerState,
            '--businesses-per-city=' . $businessesPerCity,
            '--domain-rank=' . ($domainRankEnabled ? '1' : '0'),
            '--radius-km=' . $radiusKm,
        ];
        ms_launch_job_now($dir, '__SALES_DONE__', $script, $args);
        return ['run_id' => $runId, 'started' => true, 'msg' => ''];
    });
}

/** Liveness-corrected (ms_read_run() downgrades a dead 'running' to 'stale'). */
function sales_job_status(string $runId): ?array
{
    require_once __DIR__ . '/multisite/batch.php';
    if ($runId === '') return null;
    return ms_read_run(sales_runs_dir() . '/runs/' . $runId . '.json');
}
