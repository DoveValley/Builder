<?php
/**
 * infra/lib/census.php — Census Bureau ACS 5-Year income/housing data, one row
 * per city already in `cities`.
 *
 * Its own table (city_data), never columns bolted onto `cities` itself — same
 * convention as file_date_refresh's own independent table (lib/state.php:
 * "this module is a different, separate feature and must stay fully
 * independent"). Deleting this file and dropping city_data removes the
 * feature completely; nothing else's schema or code changes.
 *
 * Self-contained: only this file talks to api.census.gov. Credentials live in
 * gitignored config/census.json, 0600, same convention as every other
 * service module.
 */

require_once __DIR__ . '/http.php';
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/state.php';   // infra_state_db(), infra_now()
require_once __DIR__ . '/cities.php';  // infra_cities_init() — `cities` table itself

/** ACS 5-Year Data Profile/Detail variables this module pulls, one state at a
 *  time (for=place:*&in=state:FIPS returns every place in one call — 51 calls
 *  back-fills every city this console knows about, not one call per city). */
const INFRA_CENSUS_VARS = [
    'income_median'       => 'B19013_001E', // median household income
    'housing_units_total' => 'B25001_001E', // total housing units
    'home_value_median'   => 'B25077_001E', // median home value (owner-occupied)
    'year_built_median'   => 'B25035_001E', // median year structure built
    'occ_total'           => 'B25003_001E', // total occupied housing units
    'occ_owner'           => 'B25003_002E', // owner-occupied units — homeownership_pct = owner/total
];

/** 2-digit state FIPS codes. Fixed, public, does not change. */
function infra_census_state_fips(): array
{
    return [
        'Alabama' => '01', 'Alaska' => '02', 'Arizona' => '04', 'Arkansas' => '05',
        'California' => '06', 'Colorado' => '08', 'Connecticut' => '09', 'Delaware' => '10',
        'District of Columbia' => '11', 'Florida' => '12', 'Georgia' => '13', 'Hawaii' => '15',
        'Idaho' => '16', 'Illinois' => '17', 'Indiana' => '18', 'Iowa' => '19', 'Kansas' => '20',
        'Kentucky' => '21', 'Louisiana' => '22', 'Maine' => '23', 'Maryland' => '24',
        'Massachusetts' => '25', 'Michigan' => '26', 'Minnesota' => '27', 'Mississippi' => '28',
        'Missouri' => '29', 'Montana' => '30', 'Nebraska' => '31', 'Nevada' => '32',
        'New Hampshire' => '33', 'New Jersey' => '34', 'New Mexico' => '35', 'New York' => '36',
        'North Carolina' => '37', 'North Dakota' => '38', 'Ohio' => '39', 'Oklahoma' => '40',
        'Oregon' => '41', 'Pennsylvania' => '42', 'Rhode Island' => '44', 'South Carolina' => '45',
        'South Dakota' => '46', 'Tennessee' => '47', 'Texas' => '48', 'Utah' => '49',
        'Vermont' => '50', 'Virginia' => '51', 'Washington' => '53', 'West Virginia' => '54',
        'Wisconsin' => '55', 'Wyoming' => '56',
    ];
}

function infra_census_config(): array { return infra_load_json(infra_config_path('census.json'), []); }
function infra_census_configured(): bool { return trim((string) (infra_census_config()['key'] ?? '')) !== ''; }

/** Idempotent: creates city_data if missing, same lazy-init convention as infra_cities_init(). */
function infra_census_init(): PDO
{
    static $done = false;
    $db = infra_state_db();
    if ($done) return $db;
    $db->exec('CREATE TABLE IF NOT EXISTS city_data (
        city_id             TEXT PRIMARY KEY,
        income_median       INTEGER,
        homeownership_pct   REAL,
        home_value_median   INTEGER,
        year_built_median   INTEGER,
        housing_units_total INTEGER,
        census_place_name   TEXT DEFAULT "",
        updated_at          TEXT DEFAULT ""
    )');
    $done = true;
    return $db;
}

/**
 * Census place names carry a legal/statistical qualifier appended after the
 * real name — "Allen city, Texas", "The Woodlands CDP, Texas" — including
 * when the real name already ends in that same word: Census's own NAME field
 * for Daly City is literally "Daly City city, California" (confirmed against
 * the live API, not assumed), doubling it rather than omitting the generic
 * suffix because one's already present. Stripping exactly one trailing
 * designator is therefore correct for the CENSUS side, but wrong for the
 * `cities` table's bare local names — those never carry the extra designator,
 * so running the same strip on "Lake Havasu City" would wrongly cut it down
 * to "Lake Havasu". Local names must only be lowercased/trimmed
 * (infra_census_normalize_local()), never suffix-stripped.
 */
function infra_census_normalize_place(string $name): string
{
    $n = trim($name);
    $n = preg_replace('/,\s*[A-Za-z .]+$/', '', $n);               // drop trailing ", State"
    $n = preg_replace('/\s*\(balance\)$/i', '', $n);
    $n = preg_replace(
        '/\s+(city|town|village|CDP|municipality|borough|corporation|'
        . 'metropolitan government|charter township|township|'
        . 'consolidated government|unified government)$/i',
        '',
        $n
    );
    return strtolower(trim($n));
}

/** `cities`'s bare local name — lowercase/trim only, never suffix-stripped (see
 *  infra_census_normalize_place()'s docblock for why that strip would be wrong here). */
function infra_census_normalize_local(string $name): string
{
    return strtolower(trim($name));
}

/** Census uses sentinel negatives (e.g. -666666666) for "not available" — never store those. */
function infra_census_numeric($v): ?int
{
    if ($v === null || $v === '') return null;
    $n = (int) $v;
    return $n < 0 ? null : $n;
}

/** One state's worth of places, in a single API call. */
function infra_census_fetch_state(string $stateFips, int $year = 2023): array
{
    $key = (string) (infra_census_config()['key'] ?? '');
    $vars = implode(',', array_values(INFRA_CENSUS_VARS));
    $url = 'https://api.census.gov/data/' . $year . '/acs/acs5'
         . '?get=NAME,' . $vars . '&for=place:*&in=state:' . $stateFips
         . '&key=' . urlencode($key);
    $r = infra_http('GET', $url, ['timeout' => 30]);
    if ($r['code'] !== 200 || !is_array($r['json'])) {
        return ['ok' => false, 'msg' => 'Census returned HTTP ' . $r['code'] . ($r['error'] ? ': ' . $r['error'] : ''), 'rows' => []];
    }
    $rows = $r['json'];
    $header = array_shift($rows) ?? [];
    $idx = array_flip($header);

    $out = [];
    foreach ($rows as $row) {
        $occTotal = (int) ($row[$idx[INFRA_CENSUS_VARS['occ_total']]] ?? 0);
        $occOwner = (int) ($row[$idx[INFRA_CENSUS_VARS['occ_owner']]] ?? 0);
        $out[] = [
            'name'                => $row[$idx['NAME']] ?? '',
            'income_median'       => infra_census_numeric($row[$idx[INFRA_CENSUS_VARS['income_median']]] ?? null),
            'housing_units_total' => infra_census_numeric($row[$idx[INFRA_CENSUS_VARS['housing_units_total']]] ?? null),
            'home_value_median'   => infra_census_numeric($row[$idx[INFRA_CENSUS_VARS['home_value_median']]] ?? null),
            'year_built_median'   => infra_census_numeric($row[$idx[INFRA_CENSUS_VARS['year_built_median']]] ?? null),
            'homeownership_pct'   => $occTotal > 0 ? round($occOwner / $occTotal * 100, 1) : null,
        ];
    }
    return ['ok' => true, 'msg' => '', 'rows' => $out];
}

/**
 * Back-fills city_data for every city already in `cities`, one Census API
 * call per state. Returns a real report — matched/unmatched counts, plus the
 * actual unmatched city ids — because a silent partial match is worse than a
 * visible gap (see feedback_a_filename_outliving_its_file: zero-finding scans
 * must print their denominator — the same principle applies to partial ones).
 *
 * @param ?callable $onState fn(string $stateName, int $matched, int $total) — progress callback
 */
function infra_census_backfill(?callable $onState = null): array
{
    infra_census_init();
    $db = infra_state_db();

    $report = ['matched' => 0, 'total_local' => 0, 'unmatched' => [], 'errors' => []];
    foreach (infra_census_state_fips() as $stateName => $fips) {
        $localStmt = $db->prepare('SELECT id, city FROM cities WHERE state = ?');
        $localStmt->execute([$stateName]);
        // Keyed by array of rows, not one row — `cities` has a handful of genuine
        // duplicate entries (e.g. reno-tx / reno-tx-5829, both "Reno" in Texas).
        // A single-row dict here silently dropped whichever id lost the last-write,
        // before it was ever even attempted against Census — invisible in both the
        // matched AND unmatched counts. Every id sharing a normalized name now gets
        // the same Census row (they're the same real place) or the same unmatched
        // outcome — nothing is silently discarded.
        $localByNorm = [];
        foreach ($localStmt as $row) {
            $localByNorm[infra_census_normalize_local($row['city'])][] = $row;
        }
        if (!$localByNorm) continue; // no cities from this state in our table at all

        $fetch = infra_census_fetch_state($fips);
        if (!$fetch['ok']) {
            $report['errors'][] = $stateName . ': ' . $fetch['msg'];
            continue;
        }
        $censusByNorm = [];
        foreach ($fetch['rows'] as $row) {
            $censusByNorm[infra_census_normalize_place($row['name'])] = $row;
        }

        $stMatched = 0;
        $upsert = $db->prepare(
            'INSERT INTO city_data (city_id, income_median, homeownership_pct, home_value_median,
                                     year_built_median, housing_units_total, census_place_name, updated_at)
             VALUES (?,?,?,?,?,?,?,?)
             ON CONFLICT(city_id) DO UPDATE SET
                 income_median = excluded.income_median, homeownership_pct = excluded.homeownership_pct,
                 home_value_median = excluded.home_value_median, year_built_median = excluded.year_built_median,
                 housing_units_total = excluded.housing_units_total, census_place_name = excluded.census_place_name,
                 updated_at = excluded.updated_at'
        );
        $stTotal = 0;
        foreach ($localByNorm as $norm => $cityRows) {
            $stTotal += count($cityRows);
            if (!isset($censusByNorm[$norm])) {
                foreach ($cityRows as $cityRow) $report['unmatched'][] = $cityRow['id'];
                continue;
            }
            $d = $censusByNorm[$norm];
            foreach ($cityRows as $cityRow) {
                $upsert->execute([
                    $cityRow['id'], $d['income_median'], $d['homeownership_pct'], $d['home_value_median'],
                    $d['year_built_median'], $d['housing_units_total'], $d['name'], infra_now(),
                ]);
                $stMatched++;
            }
        }
        $report['matched']     += $stMatched;
        $report['total_local'] += $stTotal;
        if ($onState) $onState($stateName, $stMatched, $stTotal);
    }
    return $report;
}

/** One city's Census data, or null if not backfilled yet. */
function infra_census_lookup(string $cityId): ?array
{
    infra_census_init();
    $st = infra_state_db()->prepare('SELECT * FROM city_data WHERE city_id = ?');
    $st->execute([$cityId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/** How many of `cities` currently have a city_data row — for the browse view's header count. */
function infra_census_coverage(): array
{
    infra_census_init();
    $db = infra_state_db();
    return [
        'have'  => (int) $db->query('SELECT COUNT(*) FROM city_data')->fetchColumn(),
        'total' => (int) $db->query('SELECT COUNT(*) FROM cities')->fetchColumn(),
    ];
}
