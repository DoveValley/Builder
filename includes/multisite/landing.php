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
