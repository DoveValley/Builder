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
 *   - the existing DataForSEO AND Ahrefs account credentials in
 *     admin/infra/config/keywords.json, via admin/infra/lib/keywords.php's
 *     infra_kw_provider()/infra_kw_dfs_post()/infra_kw_dfs_headers()/
 *     infra_kw_ahrefs_headers() — CLAUDE.md's "one module per external API"
 *     rule means a second hand-rolled client here would repeat a mistake this
 *     codebase has already made once, not avoid coupling. DataForSEO does the
 *     business search; Ahrefs does the Domain Rating lookup (swapped from
 *     DataForSEO's own backlinks/bulk_ranks/live score 2026-11-27 — that
 *     endpoint is a raw PageRank-style graph score, not the familiar 0-100 DR
 *     figure, and scored a placeholder domain with zero real content almost as
 *     high as bbc.com; Ahrefs' actual Domain Rating reads the way "domain
 *     rank" is normally meant).
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

const SALES_CATEGORIES_PER_TASK = 10;       // DataForSEO's own cap on categories[] per search task
const SALES_AHREFS_RATE_DELAY_US = 1100000; // ~1.1s between calls — Ahrefs' domain-rating endpoint takes
                                             // one target per request (no batching), stays safely under
                                             // the account's 60-requests/minute cap on a multi-domain run.

// Placeholders — confirm the real per-call prices on your DataForSEO / Ahrefs
// account's own pricing pages and adjust these two constants; shown in the
// cost estimate as a low/high range, not billed from here.
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
    // This build's SQLite doesn't support `ADD COLUMN IF NOT EXISTS` (added upstream in
    // 3.35, not present here) — guard with PRAGMA table_info instead, so a column added
    // after sales.db already had real rows in it doesn't fatal on every request.
    $existingCols = array_column($db->query('PRAGMA table_info(sales_prospects)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (!in_array('office_cities', $existingCols, true)) {
        $db->exec('ALTER TABLE sales_prospects ADD COLUMN office_cities TEXT DEFAULT ""');
    }
    $db->exec('CREATE INDEX IF NOT EXISTS idx_sales_prospects_niche_state ON sales_prospects(niche, state)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_sales_prospects_status ON sales_prospects(status)');
    // A durable ledger of REAL spend, not an estimate — DataForSEO reports its own
    // per-call dollar cost directly (infra_kw_dfs_interpret()'s 'cost'); Ahrefs
    // reports no dollar figure, only API units consumed per call (the
    // x-api-units-cost-total response header). One row per metered action, so
    // "cost of this search" and "cost over time" are both real SUM()s over this
    // table, not a pre-launch guess. run_id is '' for ad-hoc actions not tied to
    // a discovery run (a single Refresh, a filtered bulk-rank lookup).
    $db->exec('CREATE TABLE IF NOT EXISTS sales_cost_log (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        run_id      TEXT DEFAULT "",
        provider    TEXT NOT NULL,
        action      TEXT NOT NULL,
        amount      REAL NOT NULL,
        unit        TEXT NOT NULL,
        recorded_at TEXT NOT NULL
    )');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_sales_cost_log_run_id ON sales_cost_log(run_id)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_sales_cost_log_recorded_at ON sales_cost_log(recorded_at)');
    return $db;
}

/** Logs one real, metered spend event. $unit is 'usd' (DataForSEO) or 'units' (Ahrefs). */
function sales_log_cost(string $runId, string $provider, string $action, float $amount, string $unit): void
{
    if ($amount <= 0) return; // nothing charged — a failed call, or a free metadata endpoint
    sales_db()->prepare('INSERT INTO sales_cost_log (run_id, provider, action, amount, unit, recorded_at) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$runId, $provider, $action, $amount, $unit, gmdate('c')]);
}

/** Real spend for one discovery run, summed from the ledger — not the pre-launch estimate. */
function sales_run_cost(string $runId): array
{
    $stmt = sales_db()->prepare('SELECT provider, unit, SUM(amount) AS total FROM sales_cost_log WHERE run_id = ? GROUP BY provider, unit');
    $stmt->execute([$runId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** Real spend across every run and every ad-hoc action, ever — the "accumulative cost over time" figure. */
function sales_cumulative_cost(): array
{
    return sales_db()->query('SELECT provider, unit, SUM(amount) AS total, COUNT(*) AS calls FROM sales_cost_log GROUP BY provider, unit')
        ->fetchAll(PDO::FETCH_ASSOC);
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

/**
 * Prefix-match search over fleet.db's `cities` table, for the New Search form's
 * search-and-add city picker — Texas alone has 1,884 rows, far too many to ever
 * show as a checkbox grid, so cities are found by typing a few letters instead.
 * Always population DESC (exact-prefix matches first) so the biggest, most likely
 * match is always at the top. $state is an optional `ss` filter; '' searches
 * every state.
 */
function sales_search_cities(string $query, string $state, int $limit = 20): array
{
    $query = trim($query);
    if ($query === '') return [];
    $limit = max(1, min(50, $limit));
    $state = strtoupper(trim($state));

    $where = ['city LIKE :prefix'];
    $params = [':prefix' => $query . '%', ':exact' => $query];
    if ($state !== '' && isset(sales_us_states()[$state])) {
        $where[] = 'ss = :ss';
        $params[':ss'] = $state;
    }
    $sql = 'SELECT city, state, ss, population, lat, lng FROM cities WHERE ' . implode(' AND ', $where)
         . ' ORDER BY (LOWER(city) = LOWER(:exact)) DESC, population DESC LIMIT ' . $limit;
    $stmt = sales_fleet_db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Every niche this city has ever been searched for, with how many leads each
 * found — not scoped to whichever niche is currently typed in the form. Lets
 * the picker answer "have I searched this city, and for what" at a glance, and
 * deliberately does NOT block searching it again (e.g. a different niche, or a
 * wider radius later) — purely informational, same spirit as the overlap warning.
 * @return array<array{niche:string,cnt:int}>
 */
function sales_city_search_history(string $city, string $state): array
{
    $stmt = sales_db()->prepare(
        'SELECT niche, COUNT(*) AS cnt FROM sales_prospects WHERE city = ? AND state = ? GROUP BY niche ORDER BY cnt DESC'
    );
    $stmt->execute([$city, strtoupper(trim($state))]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function sales_haversine_km(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $r = 6371;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

/**
 * The N cities nearest a given point, by real distance — the "pick an anchor city,
 * see what's around it, check some, repeat with a different anchor" workflow. Not
 * scoped to one state (a metro can straddle a state line — Texarkana, Kansas City).
 * Includes the anchor point's own city if it's in the results (distance ~0), so it
 * can be picked like any other result rather than being silently excluded.
 *
 * SQLite has no built-in trig functions to rank by real distance in SQL directly,
 * so this pre-filters with a cheap lat/lng bounding box (~1 degree latitude is
 * ~111km) then computes real Haversine distance in PHP only over that smaller
 * candidate set — full Haversine over all 43,901 rows every call would work too,
 * but the box avoids it. Widens the box and retries if too few candidates turn up
 * (a rural anchor needs a bigger box to find its nearest neighbors at all).
 * @return array<array{city,state,ss,population,lat,lng,distance_km}>
 */
function sales_cities_near(float $lat, float $lng, int $limit = 10): array
{
    $limit = max(1, min(50, $limit));
    $deltaDeg = 2.0; // ≈220km half-width box to start
    $rows = [];
    for ($attempt = 0; $attempt < 5; $attempt++) {
        // `cities.lat`/`lng` are stored as TEXT — without the explicit CAST, SQLite's
        // type-affinity rules make BETWEEN compare them as STRINGS against these
        // numeric bind params ("9.5" > "32.79" lexicographically), silently returning
        // nonsense results instead of an error. Confirmed live: an uncast version of
        // this query returned Louisiana towns 380km away as Dallas's "nearest neighbors."
        $stmt = sales_fleet_db()->prepare(
            'SELECT city, state, ss, population, lat, lng FROM cities
             WHERE CAST(lat AS REAL) BETWEEN :latMin AND :latMax AND CAST(lng AS REAL) BETWEEN :lngMin AND :lngMax'
        );
        $stmt->execute([
            ':latMin' => $lat - $deltaDeg, ':latMax' => $lat + $deltaDeg,
            ':lngMin' => $lng - $deltaDeg, ':lngMax' => $lng + $deltaDeg,
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) >= $limit * 3 || $deltaDeg >= 45) break; // enough candidates to rank confidently, or box already huge
        $deltaDeg *= 2;
    }
    foreach ($rows as &$row) {
        $row['distance_km'] = round(sales_haversine_km($lat, $lng, (float) $row['lat'], (float) $row['lng']), 1);
    }
    unset($row);
    usort($rows, fn($a, $b) => $a['distance_km'] <=> $b['distance_km']);
    return array_slice($rows, 0, $limit);
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
/**
 * $niche is EITHER a real DataForSEO category slug OR free-text to match against
 * a business's own listing name — confirmed live 2026-11-27: `title` is a genuine
 * server-side filter, not just an echoed-back label (a niche like "foundation
 * repair" has no real GMB category at all — Google's own taxonomy doesn't have
 * one either — but `title: "foundation repair"` alone, no category, correctly
 * returned Olshan Foundation Repair, Alpha Foundation Repair, JB Quality
 * Foundation Repair, etc., not generic concrete contractors). $isCategory tells
 * this which field to put it in; the caller decides via sales_category_exists().
 */
function sales_dataforseo_business_search(array $c, string $niche, bool $isCategory, string $lat, string $lng, int $radiusKm, int $limit): array
{
    $radiusKm = max(1, min(100000, $radiusKm));
    $limit    = max(1, min(1000, $limit));
    $task = [
        'location_coordinate' => $lat . ',' . $lng . ',' . $radiusKm,
        'limit'               => $limit,
    ];
    $task[$isCategory ? 'categories' : 'title'] = $isCategory ? [$niche] : $niche;
    $r = infra_kw_dfs_post($c, 'business_data/business_listings/search/live', $task);
    $cost = (float) ($r['cost'] ?? 0);
    if (!$r['ok']) return ['ok' => false, 'msg' => $r['msg'], 'items' => [], 'cost' => $cost];
    $items = $r['result'];
    if (isset($items[0]['items']) && is_array($items[0]['items'])) $items = $items[0]['items'];
    return ['ok' => true, 'msg' => '', 'items' => $items, 'cost' => $cost];
}

/**
 * Ahrefs' real Domain Rating (0-100) — confirmed live 2026-11-27 against the
 * account's real key: h-md.com (a small local med spa) came back 12.0, bbc.com
 * 93.0 — the familiar, immediately-readable scale, unlike DataForSEO's
 * backlinks/bulk_ranks/live (a raw 0-1000 PageRank-style graph score that
 * scored a content-free placeholder domain nearly as high as bbc.com).
 * One domain per request — this endpoint takes a single `target`, no batching —
 * so sales_ahrefs_bulk_rank() below paces calls to stay under the account's
 * rate limit rather than sending them all at once.
 *
 * Ahrefs reports no dollar cost in the response body — it bills in API UNITS
 * against the account's plan, reported only in the `x-api-units-cost-total`
 * response HEADER (confirmed live: a single domain-rating call cost 50 units).
 * Captured via infra_http()'s capture_headers option.
 * @return array{rank:?float,units:int}
 */
function sales_ahrefs_domain_rank(array $c, string $domain): array
{
    require_once __DIR__ . '/../admin/infra/lib/keywords.php';
    $url = 'https://api.ahrefs.com/v3/site-explorer/domain-rating?target=' . urlencode($domain)
         . '&date=' . gmdate('Y-m-d') . '&protocol=https';
    $r = infra_http('GET', $url, ['headers' => infra_kw_ahrefs_headers($c), 'timeout' => 30, 'capture_headers' => true]);
    $units = (int) ($r['headers']['x-api-units-cost-total'] ?? 0);
    if ($r['error'] !== '' || $r['code'] !== 200 || !is_array($r['json'])) return ['rank' => null, 'units' => $units];
    $dr = $r['json']['domain_rating']['domain_rating'] ?? null;
    return ['rank' => $dr !== null ? (float) $dr : null, 'units' => $units];
}

/**
 * @param string[] $domains bare hosts, no scheme/www
 * @param ?callable $onProgress called (doneCount, total, domain) after each lookup —
 *   lets a long paced run (this is N real network calls, not one batch) report live
 *   progress instead of going silent until every domain is done.
 * @return array{ranks:array<string,?float>,units_total:int} ranks only for domains Ahrefs actually answered
 */
function sales_ahrefs_bulk_rank(array $c, array $domains, ?callable $onProgress = null): array
{
    $domains = array_values(array_unique(array_filter($domains)));
    $total = count($domains);
    $ranks = []; $unitsTotal = 0;
    foreach ($domains as $i => $domain) {
        if ($i > 0) usleep(SALES_AHREFS_RATE_DELAY_US);
        $r = sales_ahrefs_domain_rank($c, $domain);
        $unitsTotal += $r['units'];
        if ($r['rank'] !== null) $ranks[$domain] = $r['rank'];
        if ($onProgress) $onProgress($i + 1, $total, $domain);
    }
    return ['ranks' => $ranks, 'units_total' => $unitsTotal];
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

/**
 * One GET of a business's own homepage — shared by sales_check_wordpress() and
 * sales_detect_office_cities() so each business costs ONE fetch, not two; both
 * read from the SAME downloaded body instead of each re-requesting the page.
 */
function sales_fetch_homepage(string $url): array
{
    require_once __DIR__ . '/../admin/infra/lib/http.php';
    return infra_http('GET', $url, ['timeout' => 12, 'verify' => false]);
}

/**
 * Best-effort, not a guarantee — labelled as a heuristic in the results table.
 * $fetched lets a caller that already downloaded the homepage (sales_fetch_homepage())
 * pass that body in rather than triggering a second network request for it.
 */
function sales_check_wordpress(string $url, ?array $fetched = null): string
{
    require_once __DIR__ . '/../admin/infra/lib/http.php';
    $r = $fetched ?? sales_fetch_homepage($url);
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

/** Exact match (case-insensitive) against the real `cities` table — guards the text-pattern fallback below from matching arbitrary capitalized words. */
function sales_city_is_real(string $city, string $ss): bool
{
    static $cache = [];
    $key = strtolower($city) . '|' . strtoupper($ss);
    if (isset($cache[$key])) return $cache[$key];
    $stmt = sales_fleet_db()->prepare('SELECT 1 FROM cities WHERE LOWER(city) = LOWER(?) AND ss = ? LIMIT 1');
    $stmt->execute([$city, strtoupper($ss)]);
    return $cache[$key] = (bool) $stmt->fetchColumn();
}

/** Recursively walks arbitrary JSON-LD data (handles @graph, nested address/department/location blocks) collecting every addressLocality+addressRegion pair found anywhere in the tree. */
function sales_extract_jsonld_addresses($data, array &$found): void
{
    if (!is_array($data)) return;
    if (isset($data['addressLocality'])) {
        $city = trim((string) $data['addressLocality']);
        $region = trim((string) ($data['addressRegion'] ?? ''));
        $ss = strtoupper($region);
        if (strlen($ss) !== 2) { // addressRegion is sometimes a full name ("Texas"), not an abbreviation
            $match = array_search(ucwords(strtolower($region)), sales_us_states(), true);
            $ss = $match !== false ? $match : '';
        }
        if ($city !== '' && isset(sales_us_states()[$ss])) {
            $found[$city . ', ' . $ss] = true;
        }
    }
    foreach ($data as $v) {
        if (is_array($v)) sales_extract_jsonld_addresses($v, $found);
    }
}

/**
 * Best-effort office location(s) from a business's own homepage — free, no paid
 * API, reusing whatever sales_fetch_homepage() already downloaded. JSON-LD
 * structured address data first (the most reliable signal when a site has it —
 * handles multi-location businesses via the recursive @graph/department walk
 * above), falling back to a plain-text "City, ST" pattern validated against the
 * real `cities` table so an arbitrary capitalized phrase can't be mistaken for one.
 * Returns every DISTINCT city found, not just the first — many real businesses
 * list more than one office.
 * @return string[] e.g. ["Dallas, TX", "Fort Worth, TX"]
 */
function sales_detect_office_cities(array $fetched): array
{
    $body = $fetched['raw'] ?? '';
    if ($body === '' || $fetched['error'] !== '') return [];
    $found = [];

    if (preg_match_all('#<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $body, $m)) {
        foreach ($m[1] as $jsonBlock) {
            $data = json_decode(trim($jsonBlock), true);
            if (is_array($data)) sales_extract_jsonld_addresses($data, $found);
        }
    }

    if (!$found) {
        $text = strip_tags($body);
        if (preg_match_all('/\b([A-Z][a-zA-Z.\'-]+(?:\s[A-Z][a-zA-Z.\'-]+){0,3}),\s*([A-Z]{2})\b/', $text, $tm, PREG_SET_ORDER)) {
            foreach ($tm as $match) {
                $cityName = trim($match[1]);
                $ss = strtoupper($match[2]);
                if (!isset(sales_us_states()[$ss]) || !sales_city_is_real($cityName, $ss)) continue;
                $found[$cityName . ', ' . $ss] = true;
            }
        }
    }
    return array_keys($found);
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
         is_wordpress, sitemap_url_count, office_cities, domain_rank, domain_rank_checked_at, status, found_run_id, found_at, last_checked_at)
        VALUES
        (:domain, :website_url, :business_name, :phone, :niche, :city, :state, :google_rating, :google_review_count,
         :is_wordpress, :sitemap_url_count, :office_cities, :domain_rank, :domain_rank_checked_at, \'new\', :found_run_id, :found_at, :last_checked_at)');
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
        ':office_cities'          => implode('; ', $row['office_cities'] ?? []),
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

    $allowedSort = ['found_at', 'domain_rank', 'sitemap_url_count', 'google_rating', 'business_name', 'status', 'domain', 'office_cities'];
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
    $homepage = sales_fetch_homepage($url); // one fetch, shared by the WordPress check and the office-city detector below
    $isWp = sales_check_wordpress($url, $homepage);
    $fields = [
        'is_wordpress'      => $isWp,
        'sitemap_url_count' => sales_count_sitemap_urls($p['domain']),
        'last_checked_at'   => gmdate('c'),
    ];
    // Office-city detection is WordPress-only, by request — skip it for a lead that
    // just came back non-WP (uses this refresh's own fresh check, not the stale stored value).
    if ($isWp === 'yes') {
        $fields['office_cities'] = implode('; ', sales_detect_office_cities($homepage));
    }

    // Refresh is an explicit, one-lead-at-a-time click — always worth a rank
    // lookup too, not just when one already exists (that gate used to mean a
    // lead that never had its rank checked could never get one via Refresh;
    // see sales_bulk_lookup_rank() for the filtered-many-at-once version).
    require_once __DIR__ . '/../admin/infra/lib/keywords.php';
    $c = infra_kw_provider('ahrefs');
    if (!empty($c['api_key'])) {
        $r = sales_ahrefs_domain_rank($c, $p['domain']);
        sales_log_cost('', 'ahrefs', 'domain_rank', (float) $r['units'], 'units');
        if ($r['rank'] !== null) {
            $fields['domain_rank'] = $r['rank'];
            $fields['domain_rank_checked_at'] = gmdate('c');
        }
    }

    $sets = []; $params = [':id' => $id];
    foreach ($fields as $k => $v) { $sets[] = "$k = :$k"; $params[":$k"] = $v; }
    return sales_db()->prepare('UPDATE sales_prospects SET ' . implode(', ', $sets) . ' WHERE id = :id')->execute($params);
}

/**
 * Looks up domain rank for every SAVED lead matching $filters that doesn't
 * already have one — the "found the WordPress ones, now rank them" action:
 * filter the results table (e.g. WordPress = yes), then run this over just
 * that filtered set. Ahrefs has no multi-domain batch endpoint, so this is N
 * paced calls (sales_ahrefs_bulk_rank()'s own rate-limit delay), not one.
 * @return array{ok:bool,msg:string,checked:int,updated:int}
 */
function sales_bulk_lookup_rank(array $filters): array
{
    require_once __DIR__ . '/../admin/infra/lib/keywords.php';
    $c = infra_kw_provider('ahrefs');
    if (empty($c['api_key'])) return ['ok' => false, 'msg' => 'Ahrefs is not configured.', 'checked' => 0, 'updated' => 0];

    $rows = sales_list_prospects($filters);
    $domains = array_values(array_unique(array_column(
        array_filter($rows, fn($r) => $r['domain_rank'] === null), 'domain'
    )));
    if (!$domains) return ['ok' => true, 'msg' => 'Nothing to look up — every matching lead already has a rank.', 'checked' => 0, 'updated' => 0];

    $result = sales_ahrefs_bulk_rank($c, $domains);
    sales_log_cost('', 'ahrefs', 'domain_rank', (float) $result['units_total'], 'units');
    $upd = sales_db()->prepare('UPDATE sales_prospects SET domain_rank = :rank, domain_rank_checked_at = :checked WHERE domain = :domain');
    $checkedAt = gmdate('c'); $updated = 0;
    foreach ($result['ranks'] as $domain => $rank) {
        $upd->execute([':rank' => $rank, ':checked' => $checkedAt, ':domain' => $domain]);
        $updated += $upd->rowCount();
    }
    return ['ok' => true, 'msg' => '', 'checked' => count($domains), 'updated' => $updated];
}

/* -------------------------------------------------------------- job launch */

/** $cities is the exact picked list — [{city,state,ss,lat,lng,radius_km}, ...] — no re-resolution needed. */
function sales_cost_estimate(array $cities, int $businessesPerCity, bool $domainRankEnabled): array
{
    $citiesTotal   = count($cities);
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
 * @param array $cities exact picked list — [{city,state,ss,lat,lng,radius_km}, ...]
 * @return array{run_id:string,started:bool,msg:string}
 */
function sales_launch_job(string $categorySlug, array $cities, int $businessesPerCity, bool $domainRankEnabled): array
{
    require_once __DIR__ . '/multisite/batch.php';

    $dir = sales_runs_dir();
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        return ['run_id' => '', 'started' => false, 'msg' => 'Could not create the job directory.'];
    }

    return ms_with_launch_lock($dir . '/.lock', function () use ($dir, $categorySlug, $cities, $businessesPerCity, $domainRankEnabled) {
        $active = ms_active_run($dir . '/runs');
        if ($active) {
            return ['run_id' => $active['run_id'], 'started' => false, 'msg' => 'A search or backfill is already running.'];
        }
        $runId  = gmdate('Ymd-His') . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
        $script = __DIR__ . '/sales_prospecting_cli.php';
        $args = [
            '--run-id=' . $runId,
            '--niche=' . $categorySlug,
            // Each picked city carries its OWN radius now — a single JSON arg rather than
            // --states=/--cities-per-state=/--radius-km=. Safe as one escapeshellarg()'d
            // string: even a few dozen cities is a couple KB, far under any shell limit.
            '--cities=' . json_encode($cities),
            '--businesses-per-city=' . $businessesPerCity,
            '--domain-rank=' . ($domainRankEnabled ? '1' : '0'),
        ];
        ms_launch_job_now($dir, '__SALES_DONE__', $script, $args);
        return ['run_id' => $runId, 'started' => true, 'msg' => ''];
    });
}

/**
 * Backfills office_cities for every SAVED lead matching $filters that's still
 * blank — "I have 1,373 leads already, the detector didn't exist yet for most of
 * them." Could mean a thousand-plus sequential website fetches, so this is a real
 * background job (includes/sales_detect_offices_cli.php), not a synchronous button
 * like sales_bulk_lookup_rank() — that one is fine in-request because it's a paid
 * API call per domain capped at modest batches; this is free but genuinely slow at
 * this data's actual scale. Shares the SAME launch lock as a discovery search
 * (sales_launch_job()) — one background job of either kind at a time, deliberately,
 * so this doesn't pile a thousand extra concurrent fetches on top of a live search.
 * @return array{run_id:string,started:bool,msg:string}
 */
function sales_launch_office_detect_job(array $filters): array
{
    require_once __DIR__ . '/multisite/batch.php';

    $dir = sales_runs_dir();
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        return ['run_id' => '', 'started' => false, 'msg' => 'Could not create the job directory.'];
    }

    return ms_with_launch_lock($dir . '/.lock', function () use ($dir, $filters) {
        $active = ms_active_run($dir . '/runs');
        if ($active) {
            return ['run_id' => $active['run_id'], 'started' => false, 'msg' => 'A search or backfill is already running.'];
        }
        $runId  = gmdate('Ymd-His') . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
        $script = __DIR__ . '/sales_detect_offices_cli.php';
        $args = ['--run-id=' . $runId, '--filters=' . json_encode($filters)];
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

/**
 * The run_id of whatever search is genuinely still running, or null — launching a
 * detached job has no connection to the browser tab that started it, so refreshing
 * admin/sales.php used to lose the live progress view even though the search itself
 * kept running untouched. This is what lets the page notice an active run on load
 * and resume showing it, instead of silently going back to a blank New Search form.
 */
function sales_active_run_id(): ?string
{
    require_once __DIR__ . '/multisite/batch.php';
    $active = ms_active_run(sales_runs_dir() . '/runs');
    return $active['run_id'] ?? null;
}
