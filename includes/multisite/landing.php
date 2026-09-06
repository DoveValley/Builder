<?php
/**
 * Multisite landing-cities parsing.
 *
 * A deploy row's optional `landing_cities` column lists the cities that deploy
 * should get service *landing pages* for (in addition to its single home/core
 * city). Format: semicolon-separated "City, ST" entries. Blank = no landing pages.
 *   e.g.  "Katy, TX; Fulshear, TX; Richmond, TX"
 *
 * ms_parse_landing_cities() turns that string into cities.json rows (the same
 * shape data/cities.json uses), which build_one.php writes into the working dir
 * before running the landing-page generator worker (multisite/generate_landing.php).
 *
 * Requires slugify() (includes/helpers.php, loaded via functions.php).
 */

/** US state abbreviation → full name (for {state} shortcode resolution). */
const MS_STATE_NAMES = [
    'AL' => 'Alabama', 'AK' => 'Alaska', 'AZ' => 'Arizona', 'AR' => 'Arkansas',
    'CA' => 'California', 'CO' => 'Colorado', 'CT' => 'Connecticut', 'DE' => 'Delaware',
    'FL' => 'Florida', 'GA' => 'Georgia', 'HI' => 'Hawaii', 'ID' => 'Idaho',
    'IL' => 'Illinois', 'IN' => 'Indiana', 'IA' => 'Iowa', 'KS' => 'Kansas',
    'KY' => 'Kentucky', 'LA' => 'Louisiana', 'ME' => 'Maine', 'MD' => 'Maryland',
    'MA' => 'Massachusetts', 'MI' => 'Michigan', 'MN' => 'Minnesota', 'MS' => 'Mississippi',
    'MO' => 'Missouri', 'MT' => 'Montana', 'NE' => 'Nebraska', 'NV' => 'Nevada',
    'NH' => 'New Hampshire', 'NJ' => 'New Jersey', 'NM' => 'New Mexico', 'NY' => 'New York',
    'NC' => 'North Carolina', 'ND' => 'North Dakota', 'OH' => 'Ohio', 'OK' => 'Oklahoma',
    'OR' => 'Oregon', 'PA' => 'Pennsylvania', 'RI' => 'Rhode Island', 'SC' => 'South Carolina',
    'SD' => 'South Dakota', 'TN' => 'Tennessee', 'TX' => 'Texas', 'UT' => 'Utah',
    'VT' => 'Vermont', 'VA' => 'Virginia', 'WA' => 'Washington', 'WV' => 'West Virginia',
    'WI' => 'Wisconsin', 'WY' => 'Wyoming', 'DC' => 'District of Columbia',
];

/** Full state name for a 2-letter abbreviation, or the abbreviation itself if unknown. */
function ms_state_name(string $ss): string {
    $ss = strtoupper(trim($ss));
    return MS_STATE_NAMES[$ss] ?? $ss;
}

/**
 * Parse a `landing_cities` cell into cities.json rows.
 * Accepts "City, ST" entries separated by ';' or newlines. Entries without a
 * usable "City, ST" shape are skipped. Duplicate ids are de-duplicated.
 * @return array[] rows: ['id','city','SS','state','city_slug','tags']
 */
function ms_parse_landing_cities(string $raw): array {
    $raw = trim($raw);
    if ($raw === '') return [];

    $slug = function (string $s): string {
        return function_exists('slugify') ? slugify($s) : strtolower(preg_replace('/[^a-z0-9]+/i', '-', trim($s)));
    };

    $rows = [];
    foreach (preg_split('/[;\r\n]+/', $raw) as $entry) {
        $entry = trim($entry);
        if ($entry === '') continue;

        // "City, ST" — split on the last comma so multi-word cities survive.
        $parts = array_map('trim', explode(',', $entry));
        if (count($parts) < 2) continue;                 // need both city and state
        $ss   = strtoupper(array_pop($parts));
        $city = trim(implode(', ', $parts));
        if ($city === '' || !preg_match('/^[A-Z]{2}$/', $ss)) continue;

        $citySlug = $slug($city) . '-' . strtolower($ss);
        $id       = 'city_' . $slug($city) . '_' . strtolower($ss);

        $rows[$id] = [
            'id'        => $id,
            'city'      => $city,
            'SS'        => $ss,
            'state'     => ms_state_name($ss),
            'city_slug' => $citySlug,
            'tags'      => [],
        ];
    }
    return array_values($rows);
}

/**
 * Enrich the bare `landing_cities` rows with research the master already gathered
 * for those cities (neighborhoods, population, industries, top_employers, market_blurb,
 * salary_note, _researched, …). Without this, scoping the working-dir cities.json to a
 * deploy's landing cities would drop every research field, so generate.py sees bare rows
 * and all research-grounded copy — including the gated neighborhoods — silently degrades
 * to generic. Match is by id, then city_slug, then case-insensitive "city|SS".
 *
 * Structural fields from the landing row win (the deploy's canonical id/slug/state);
 * any extra field the master row carries is added. Cities the master never researched
 * pass through unchanged.
 */
function ms_merge_research_into_landing(array $landingCities, array $masterCities): array {
    $index = [];
    foreach ($masterCities as $m) {
        if (!is_array($m)) continue;
        $keys = [
            (string)($m['id'] ?? ''),
            (string)($m['city_slug'] ?? ''),
            trim(($m['city'] ?? '') . '|' . ($m['SS'] ?? '')),
        ];
        foreach ($keys as $k) {
            $k = strtolower($k);
            if ($k !== '' && $k !== '|') $index[$k] = $m;   // last writer wins; fine for our data
        }
    }

    $out = [];
    foreach ($landingCities as $row) {
        $lookup = [
            strtolower((string)($row['id'] ?? '')),
            strtolower((string)($row['city_slug'] ?? '')),
            strtolower(trim(($row['city'] ?? '') . '|' . ($row['SS'] ?? ''))),
        ];
        $match = null;
        foreach ($lookup as $k) {
            if ($k !== '' && $k !== '|' && isset($index[$k])) { $match = $index[$k]; break; }
        }
        // `$row + $match`: landing row keys win; master-only research keys are added.
        $out[] = $match ? ($row + $match) : $row;
    }
    return $out;
}

/**
 * Delete any landing page file in the working copy whose city isn't in this deploy's
 * current landing_cities — before generate_city_pages() writes the requested ones.
 *
 * A clone starts as a full copy of the master, including the master's OWN landing
 * pages (e.g. water-site's master doubles as a real site for Lufkin, so every clone
 * inherits Lufkin's 26 already-generated pages). generate_city_pages() only ever
 * ADDS pages for the cities it's told about — it has no concept of a city that's no
 * longer wanted — so without this, every deploy accumulates the master's own pages
 * alongside its own, forever: unreachable from nav (nothing links to them), but
 * physically shipped in the output.
 *
 * Matches by each page file's own `city_id` field, not the filename or page-index —
 * page-index.json can already be stale (pointing at files that no longer exist from
 * an earlier template swap), so it's cleaned up here as a side effect rather than
 * trusted as an input.
 *
 * @return int  pages deleted
 */
function ms_prune_stale_landing_pages(string $workingDir, array $keepCityIds): int {
    $pagesDir = rtrim($workingDir, '/') . '/data/pages/';
    if (!is_dir($pagesDir)) return 0;
    $keep = array_flip(array_map('strtolower', $keepCityIds));

    $deleted = 0;
    foreach (glob($pagesDir . '*.json') ?: [] as $pageFile) {
        $page = json_decode((string) @file_get_contents($pageFile), true);
        $cid  = is_array($page) ? strtolower((string)($page['city_id'] ?? '')) : '';
        if ($cid === '' || isset($keep[$cid])) continue;   // no city_id, or a city we're keeping
        if (@unlink($pageFile)) $deleted++;
    }

    // page-index.json maps slug => filename; drop any entry whose file is now gone
    // (this also clears out any pre-existing stale entries left by a past template
    // swap, since those point at files that never existed in this working copy either).
    $indexFile = rtrim($workingDir, '/') . '/data/page-index.json';
    if ($deleted > 0 && is_file($indexFile)) {
        $index = json_decode((string) @file_get_contents($indexFile), true);
        if (is_array($index)) {
            $pruned = array_filter($index, fn($fn) => is_file($pagesDir . $fn));
            if (count($pruned) !== count($index)) {
                file_put_contents($indexFile, json_encode($pruned, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            }
        }
    }

    return $deleted;
}
