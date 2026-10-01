<?php
/**
 * lib/research.php — City Research: a standalone tool that answers "which
 * cities are worth building a site in", for any niche, on demand.
 *
 * Deliberately NOT wired into city_niche/selection, and NOT wired into the
 * Batch/Site Factory pipeline. It produces and stores a ranked xlsx per run —
 * what to do with that list is a separate, later, human decision. State lives
 * in its own JSON files under state/research/, never in fleet.db's tables, so
 * this tool can be deleted wholesale without touching anything else.
 *
 * Reuses (does not duplicate) the DataForSEO/Ahrefs clients already in
 * lib/keywords.php and the SERP-openness check in lib/serp.php — same
 * credentials, same rate limits, same money. Duplicating an API client for
 * "separateness" would just create a second copy that drifts from the first.
 */

require_once __DIR__ . '/keywords.php';
require_once __DIR__ . '/serp.php';
require_once __DIR__ . '/cities.php';

const INFRA_RESEARCH_TIME_BUDGET = 90;

// DataForSEO's own docs: live endpoints allow up to 30 simultaneous requests
// and ~2,000/min, but explicitly warn that bursting near that ceiling causes
// MORE errors, not fewer — recommending a steady flow instead. Raised 8->16 on
// 2026-09-29, then 16->24 on 2026-09-30 after a real multi-run night (roofing/
// hvac/plumbing sharing the pool) held failure rates at/below the normal
// ~15-30%/pass baseline at 16 — still comfortably below the documented ceiling,
// not a jump straight to it. If a real run shows failure rates climbing well
// above that baseline after this change, that's the signal to step back down
// rather than push higher.
const INFRA_RESEARCH_SERP_CONCURRENCY = 24;

function infra_research_dir(): string
{
    $dir = infra_base_dir() . '/state/research';
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    return $dir;
}

/* ---------------------------------------------------------------------------
 * Domain classification: DIRECTORY (reuse the existing list) / NATIONAL
 * (franchise brands + gov/edu/wikipedia/reddit/youtube) / LOCAL (everything
 * else — the real competition).
 * ------------------------------------------------------------------------- */

/**
 * Per-niche franchise/national-brand domain lists — deliberately NOT one flat
 * merged list any more. The old shared list conflated real franchises with
 * generic retailers/insurers (Home Depot, State Farm, Amazon) that have
 * nothing to do with any one niche's actual national rivals, and "harmless to
 * keep listed for niches that don't use them" meant every niche's national
 * count was diluted by domains that could never show up for it anyway.
 * Scott-specified list per niche (2026-09-29) — domains verified against each
 * brand's real live site, not guessed:
 *  - Aptive's real domain is aptivepestcontrol.com, not aptive.com (the old
 *    flat list had this wrong — goaptive.com is customer-login-only).
 *  - HomeTeam Pest Defense's real domain is pestdefense.com.
 *  - Rytech's real domain is rytechinc.com, not rytech.com.
 *  - Sears' appliance-repair arm is searshomeservices.com, distinct from the
 *    old list's bare sears.com (a different, much broader retail site).
 *  - "Appliance Doctor" is deliberately NOT included: it isn't one company —
 *    several unrelated independent local shops use this name with different
 *    domains per city (sickappliance.com, myappliancedoctor.com,
 *    appliancedoctorincva.com, ...). Adding any one of those domains would
 *    misclassify that one specific local competitor as "national" everywhere
 *    it happens to rank, which is a real correctness bug, not a no-op. Flag
 *    to Scott before adding anything here.
 *
 * Five niches added 2026-09-29 (Scott-specified brand names, domains verified
 * live same rules as above):
 *  - "Overhead Door" deliberately NOT included for garage door repair, same
 *    reason as Appliance Doctor: Overhead Door Corporation licenses the name
 *    to independent local distributors who each run their own domain
 *    (ohd.com, overheaddoorinc.com, theoverheaddoorco.com, ...) and actively
 *    rank on those, not on overheaddoor.com itself.
 *  - Precision Door Service is precisiondoor.net, not precision-door.com (that
 *    domain belongs to a single Spartanburg SC franchisee, not the network).
 *  - Mister Sparky's consumer domain is mistersparky.com — mistersparkyfranchise.com
 *    is franchise-recruiting only.
 *  - Manufacturer brands (Clopay/Amarr/Wayne Dalton/Chamberlain/LiftMaster for
 *    garage doors; Owens Corning/GAF/CertainTeed for roofing; Trane/Carrier/
 *    Lennox for HVAC) are included per Scott's list — they rarely rank for
 *    local "{service} repair {city}" searches (mostly dealer-locator pages),
 *    so harmless-but-low-yield rather than wrong to include.
 */
const INFRA_RESEARCH_NATIONAL_BRANDS_BY_NICHE = [
    'pest' => [
        'terminix.com', 'orkin.com', 'aptivepestcontrol.com', 'trulynolen.com',
        'arrowexterminators.com', 'westernexterminator.com', 'rentokil.com', 'pestdefense.com',
    ],
    'appliance' => [
        'searshomeservices.com', 'mrappliance.com', 'asurion.com', 'puls.com',
    ],
    'mold' => [
        'servpro.com', 'servicemasterrestore.com', 'servicemaster.com', 'pauldavis.com',
        'rainbowintl.com', '911restoration.com', 'puroclean.com', 'rytechinc.com',
        'belfor.com', 'atirestoration.com', 'firstonsite.com',
    ],
    'restoration' => [
        'servpro.com', 'servicemasterrestore.com', 'servicemaster.com', 'pauldavis.com',
        'rainbowintl.com', '911restoration.com', 'puroclean.com', 'rytechinc.com',
        'belfor.com', 'atirestoration.com', 'firstonsite.com',
    ],
    'garage-door-repair' => [
        'precisiondoor.net', 'a1garage.com', 'clopaydoor.com', 'amarr.com',
        'wayne-dalton.com', 'chamberlain.com', 'liftmaster.com',
    ],
    'electrician' => [
        'mrelectric.com', 'mistersparky.com', 'ars.com', 'serviceexperts.com',
    ],
    'roofing' => [
        'mightydogroofing.com', 'eriehome.com', 'powerhrg.com', 'owenscorning.com',
        'gaf.com', 'certainteed.com',
    ],
    'plumbing' => [
        'rotorooter.com', 'mrrooter.com', 'benjaminfranklinplumbing.com', 'ars.com',
        'onehourheatandair.com',
    ],
    'hvac' => [
        'onehourheatandair.com', 'aireserv.com', 'ars.com', 'serviceexperts.com',
        'trane.com', 'carrier.com', 'lennox.com',
    ],
];

/** Unknown/future niches get an empty list rather than a guessed one — a
 *  niche this table doesn't know about should show more LOCAL competitors,
 *  not silently borrow another niche's brands. */
function infra_research_national_brands(string $niche): array
{
    return INFRA_RESEARCH_NATIONAL_BRANDS_BY_NICHE[$niche] ?? [];
}

function infra_research_national_suffixes(): array { return ['.gov', '.edu']; }
function infra_research_national_extra(): array { return ['wikipedia.org', 'reddit.com', 'quora.com', 'youtube.com']; }

/* ---------------------------------------------------------------------------
 * City-name disambiguation: "Lancaster" alone is ambiguous (SC and PA both
 * have one); "Hamilton" collides with Hamilton, Ontario. A bare {city} keyword
 * silently mixes the wrong place's search demand into the volume number, and
 * sends a SERP check that Google itself may resolve to the wrong place. Fixed
 * by appending the US state abbreviation to the keyword's city name whenever
 * it collides with another US state or a well-known Canadian/UK place —
 * "appliance repair Lancaster SC" instead of "appliance repair Lancaster".
 * ------------------------------------------------------------------------- */

/**
 * Well-known Canadian and UK city/town names that collide with common US city
 * names. Hand-curated, NOT a full database — this codebase has no Canada/UK
 * reference city table, so this is a best-effort safety net covering the
 * largest/most-searched places in both countries, not a guarantee of catching
 * every collision. Expand here if a real run turns up a gap Scott flags.
 */
const INFRA_RESEARCH_INTL_COLLISION_CITIES = [
    // Canada
    'london', 'hamilton', 'windsor', 'kingston', 'cambridge', 'waterloo', 'guelph',
    'kitchener', 'barrie', 'oshawa', 'brampton', 'mississauga', 'markham', 'richmond',
    'burlington', 'oakville', 'victoria', 'surrey', 'burnaby', 'regina', 'halifax',
    'sudbury', 'peterborough', 'niagara falls', 'st. catharines', 'st catharines',
    'chatham', 'woodstock', 'stratford', 'brantford', 'cornwall', 'belleville',
    'orillia', 'sarnia', 'owen sound', 'cobourg', 'newmarket', 'aurora', 'ajax',
    'whitby', 'pickering', 'vaughan', 'milton', 'georgetown', 'dundas', 'ancaster',
    'paris', 'ingersoll', 'tillsonburg', 'leamington', 'amherstburg', 'welland',
    'fort erie', 'trenton', 'napanee', 'gananoque', 'brockville', 'perth', 'renfrew',
    'pembroke', 'north bay', 'timmins', 'kenora',
    // UK
    'manchester', 'birmingham', 'liverpool', 'bristol', 'leeds', 'sheffield',
    'newcastle', 'nottingham', 'leicester', 'coventry', 'bradford', 'cardiff',
    'belfast', 'edinburgh', 'glasgow', 'oxford', 'york', 'bath', 'exeter', 'plymouth',
    'southampton', 'portsmouth', 'brighton', 'norwich', 'ipswich', 'chester',
    'lancaster', 'gloucester', 'worcester', 'hereford', 'canterbury', 'winchester',
    'salisbury', 'durham', 'carlisle', 'preston', 'blackpool', 'bolton', 'wigan',
    'derby', 'lincoln', 'reading', 'dover', 'dartmouth', 'ashford', 'maidstone',
    'rochester', 'banbury', 'warwick', 'harrogate', 'scarborough', 'whitby',
    'doncaster', 'wakefield', 'huddersfield', 'barnsley', 'sunderland',
    'middlesbrough', 'hull', 'swansea', 'aberdeen', 'dundee', 'inverness',
    'falmouth', 'truro', 'berwick', 'kettering', 'northampton', 'cheltenham',
    'yeovil', 'taunton', 'newport', 'bangor', 'ely', 'st albans', 'st. albans',
];

/**
 * Every reference city's searchable name mapped to the set of US states (2-
 * letter) it appears under — built once per process (a few thousand rows,
 * cheap), used to detect e.g. "Midlothian" existing in both TX and IL.
 */
function infra_research_us_name_states_map(): array
{
    static $map = null;
    if ($map !== null) return $map;
    $map = [];
    foreach (infra_cities_init()->query('SELECT city, ss FROM cities') as $r) {
        $name = strtolower(infra_kw_city_name($r));
        if ($name === '') continue;
        $map[$name][$r['ss']] = true;
    }
    return $map;
}

function infra_research_city_is_ambiguous(string $searchName): bool
{
    $key = strtolower($searchName);
    if (in_array($key, INFRA_RESEARCH_INTL_COLLISION_CITIES, true)) return true;
    return count(infra_research_us_name_states_map()[$key] ?? []) > 1;
}

/**
 * The city label to put into a keyword phrase's {city} slot: the plain
 * searchable name, or "Name ST" when that name collides with another US state
 * or a well-known Canadian/UK city — "Lancaster SC" (also PA), "Hamilton OH"
 * (also Ontario). Applied to BOTH the volume/Ahrefs lookup and the SERP check
 * (both call sites in infra_research_tick()) — a bare ambiguous name mixes
 * another place's demand into the volume number, not just the SERP result.
 */
function infra_research_city_label(array $c): string
{
    $name = infra_kw_city_name($c);
    $ss = trim((string) ($c['ss'] ?? ''));
    if ($name !== '' && $ss !== '' && infra_research_city_is_ambiguous($name)) {
        return $name . ' ' . $ss;
    }
    return $name;
}

function infra_research_norm_domain(string $d): string
{
    return strtolower(preg_replace('/^(www\.|m\.)/', '', trim($d)));
}

/** DIRECTORY | NATIONAL | LOCAL */
function infra_research_classify(string $domain, string $niche): string
{
    $d = infra_research_norm_domain($domain);
    if (infra_serp_is_directory($d)) return 'DIRECTORY';
    foreach (infra_research_national_brands($niche) as $b) {
        if ($d === $b || substr($d, -strlen('.' . $b)) === '.' . $b) return 'NATIONAL';
    }
    if (in_array($d, infra_research_national_extra(), true)) return 'NATIONAL';
    foreach (infra_research_national_suffixes() as $suf) {
        if (substr($d, -strlen($suf)) === $suf) return 'NATIONAL';
    }
    return 'LOCAL';
}

/* ---------------------------------------------------------------------------
 * eLocal buyer-coverage import: paste or upload, header-aliased, same shape
 * as domains_load.php's dual-input pattern.
 * ------------------------------------------------------------------------- */

const INFRA_RESEARCH_ELOCAL_ALIASES = [
    'city'       => ['city'],
    'state'      => ['state', 'st', 'ss'],
    'buyers'     => ['buyers', 'smb_buyers', 'smb buyers', 'buyer_count', 'local_buyers'],
    'price_avg'  => ['1p_avg', '1p avg $', 'avg_price', 'avg price', 'avg_call_price', 'price_avg', 'first_party_avg', '1st party avg call price'],
    'price_max'  => ['1p_max', '1p max $', 'max_price', 'max price', 'max_call_price', 'price_max', 'first_party_max', '1st party max call price'],
];

// price_max is documented (views/research.php) as "optionally max price" and isn't
// actually read anywhere downstream (score_and_grade, the xlsx writer - neither
// touches it), but the missing-column check below treated it as required same as
// city/state/buyers/price_avg — a CSV genuinely missing only that one optional
// column (e.g. the tool's OWN xlsx output, which has price_avg but no price_max)
// was rejected outright instead of importing with it defaulted.
const INFRA_RESEARCH_ELOCAL_REQUIRED = ['city', 'state', 'buyers', 'price_avg'];

/** Plain text (CSV/TSV) -> rows of cell strings, delimiter sniffed from the first line. */
function infra_research_text_to_rows(string $raw): array
{
    $lines = array_values(array_filter(preg_split('/\r\n|\r|\n/', trim($raw)), fn($l) => trim($l) !== ''));
    if (!$lines) return [];
    $delim = substr_count($lines[0], "\t") > substr_count($lines[0], ',') ? "\t" : ',';
    return array_map(fn($l) => str_getcsv($l, $delim), $lines);
}

/**
 * Reads simple values out of a real .xlsx file's first sheet - just enough to feed
 * the same eLocal import path as a CSV: shared strings resolved, cells reassembled
 * in column order (a row that skips an empty cell in the XML still needs an empty
 * slot here, or every later column silently shifts left). No formulas, no styles,
 * no multi-sheet awareness beyond "first sheet" - eLocal exports are simple
 * single-sheet data dumps, not workbooks.
 *
 * @return array<array<string>>|null rows of cell strings, or null if this isn't
 *   parseable as an xlsx at all (corrupt zip, no worksheet found).
 */
function infra_research_read_xlsx_rows(string $path): ?array
{
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) return null;

    $shared = [];
    $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($sharedXml !== false) {
        $sx = @simplexml_load_string($sharedXml);
        if ($sx !== false) {
            foreach ($sx->si as $si) {
                $parts = isset($si->t) ? [(string) $si->t] : [];
                foreach ($si->r as $run) if (isset($run->t)) $parts[] = (string) $run->t;
                $shared[] = implode('', $parts);
            }
        }
    }

    $sheetName = $zip->locateName('xl/worksheets/sheet1.xml') !== false ? 'xl/worksheets/sheet1.xml' : null;
    if ($sheetName === null) {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $n = $zip->getNameIndex($i);
            if (preg_match('#^xl/worksheets/sheet\d+\.xml$#', $n)) { $sheetName = $n; break; }
        }
    }
    if ($sheetName === null) { $zip->close(); return null; }
    $sheetXml = $zip->getFromName($sheetName);
    $zip->close();
    if ($sheetXml === false) return null;

    $sx = @simplexml_load_string($sheetXml);
    if ($sx === false || !isset($sx->sheetData)) return null;

    $colToIndex = function (string $ref): int {
        preg_match('/^([A-Z]+)/', $ref, $m);
        $letters = $m[1] ?? 'A';
        $idx = 0;
        for ($i = 0; $i < strlen($letters); $i++) $idx = $idx * 26 + (ord($letters[$i]) - 64);
        return $idx - 1;
    };

    $rows = [];
    foreach ($sx->sheetData->row as $rowEl) {
        if (!count($rowEl->c)) continue;
        $cells = []; $maxCol = -1; $next = 0;
        foreach ($rowEl->c as $c) {
            $ref = (string) ($c['r'] ?? '');
            $col = $ref !== '' ? $colToIndex($ref) : $next;
            $type = (string) ($c['t'] ?? '');
            if ($type === 's')             $val = $shared[(int) $c->v] ?? '';
            elseif ($type === 'inlineStr') $val = isset($c->is->t) ? (string) $c->is->t : '';
            else                           $val = isset($c->v) ? (string) $c->v : '';
            $cells[$col] = $val;
            if ($col > $maxCol) $maxCol = $col;
            $next = $col + 1;
        }
        $row = [];
        for ($i = 0; $i <= $maxCol; $i++) $row[] = $cells[$i] ?? '';
        $rows[] = $row;
    }
    return $rows;
}

/**
 * @param string $fallbackPath if $file isn't a genuine new upload this request, read
 *   from this path instead (the niche's previously-saved eLocal file, if any) — so a
 *   file chosen once stays in effect until a different one replaces it, instead of
 *   needing to be re-attached on every save/run.
 * @return array{rows:array,errors:array} rows keyed nothing — plain list of assoc arrays
 */
function infra_research_parse_elocal(string $pasted, ?array $file, ?string $fallbackPath = null): array
{
    $pasteRows = trim($pasted) !== '' ? infra_research_text_to_rows($pasted) : [];

    $isNewUpload = $file && !empty($file['tmp_name']) && is_uploaded_file($file['tmp_name'])
        && ($file['error'] ?? UPLOAD_ERR_OK) === UPLOAD_ERR_OK;
    $srcPath = null;
    if ($isNewUpload) {
        if (($file['size'] ?? 0) > 4 * 1024 * 1024) {
            return ['rows' => [], 'errors' => ['File ignored — larger than 4 MB.']];
        }
        $srcPath = $file['tmp_name'];
    } elseif ($fallbackPath !== null && is_file($fallbackPath)) {
        $srcPath = $fallbackPath;
    }

    $fileRows = [];
    if ($srcPath !== null) {
        // Sniffed from real bytes, not the filename - a held file always gets saved
        // with a .csv extension (infra_research_draft_elocal_path()) regardless of
        // what was actually uploaded, so trusting the extension here would silently
        // misread every re-used xlsx as text.
        $head = (string) @file_get_contents($srcPath, false, null, 0, 4);
        if (substr($head, 0, 2) === 'PK') {
            $xlsxRows = infra_research_read_xlsx_rows($srcPath);
            if ($xlsxRows === null) {
                return ['rows' => [], 'errors' => ['Could not read that .xlsx file as a spreadsheet — is it a real Excel file?']];
            }
            $fileRows = $xlsxRows;
        } elseif (substr($head, 0, 4) === "\xD0\xCF\x11\xE0") {
            return ['rows' => [], 'errors' => ['That looks like an old .xls file (pre-2007 Excel format) — re-save it as .xlsx or .csv and try again.']];
        } else {
            $fileRows = infra_research_text_to_rows((string) @file_get_contents($srcPath));
        }
    }

    $allRows = array_merge($pasteRows, $fileRows);
    if (!$allRows) return ['rows' => [], 'errors' => ['Nothing to import — paste rows or choose a file.']];

    $header = $allRows[0];
    $lower  = array_map(fn($h) => strtolower(trim((string) $h)), $header);
    $colIdx = [];
    foreach (INFRA_RESEARCH_ELOCAL_ALIASES as $field => $aliases) {
        foreach ($lower as $i => $h) {
            if (in_array($h, $aliases, true)) { $colIdx[$field] = $i; break; }
        }
    }
    $missing = array_diff(INFRA_RESEARCH_ELOCAL_REQUIRED, array_keys($colIdx));
    if ($missing) {
        return ['rows' => [], 'errors' => [
            'Could not find column(s) for: ' . implode(', ', $missing)
            . '. Header seen: ' . implode(', ', $header),
        ]];
    }

    $rows = [];
    for ($i = 1; $i < count($allRows); $i++) {
        $cells = $allRows[$i];
        $city  = trim((string) ($cells[$colIdx['city']] ?? ''));
        $state = trim((string) ($cells[$colIdx['state']] ?? ''));
        if ($city === '' || $state === '') continue;
        $rows[] = [
            'city'      => $city,
            'state'     => $state,
            'buyers'    => (float) preg_replace('/[^0-9.]/', '', (string) ($cells[$colIdx['buyers']] ?? '0')),
            'price_avg' => (float) preg_replace('/[^0-9.]/', '', (string) ($cells[$colIdx['price_avg']] ?? '0')),
            'price_max' => (float) preg_replace('/[^0-9.]/', '', (string) (
                isset($colIdx['price_max']) ? ($cells[$colIdx['price_max']] ?? '0') : '0'
            )),
        ];
    }
    return ['rows' => $rows, 'errors' => []];
}

/** Match an eLocal row to the reference `cities` table by city+state (2-letter or full name). */
function infra_research_match_city(string $city, string $state): ?array
{
    $ss = strtoupper(trim($state));
    if (strlen($ss) !== 2) $ss = '';   // not a 2-letter code — match on full state name instead
    $db = infra_cities_init();
    if ($ss !== '') {
        $st = $db->prepare('SELECT * FROM cities WHERE lower(city) = lower(?) AND ss = ? LIMIT 1');
        $st->execute([$city, $ss]);
    } else {
        $st = $db->prepare('SELECT * FROM cities WHERE lower(city) = lower(?) AND lower(state) = lower(?) LIMIT 1');
        $st->execute([$city, $state]);
    }
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * A real Google organic SERP, classified 3 ways instead of serp.php's
 * directory-or-not. Reuses the same shared HTTP primitive `infra_kw_dfs_post()`
 * that infra_serp_fetch() itself calls — the classification loop is new, the
 * network/auth code underneath it is not duplicated.
 *
 * @return array{ok:bool,msg:string,data:array} data: open_slots/local_competitors/
 *   national_brands (counts out of top 10) + domains (the up-to-10 ranking
 *   domains themselves, in rank order — kept alongside the counts so a caller
 *   that wants the "All Scored" sheet's per-pattern breakdown doesn't need a
 *   second SERP call to get what this one call already saw).
 */
/** The part of a SERP reply both the one-at-a-time and concurrent paths share:
 *  classify every organic result and keep the ranked domain list. */
function infra_research_classify_serp_result(array $result, string $niche): array
{
    $items = (array) ($result[0]['items'] ?? []);
    $open = $local = $natl = 0;
    $domains = [];
    foreach ($items as $it) {
        if ((string) ($it['type'] ?? '') !== 'organic') continue;
        $domain = (string) ($it['domain'] ?? '');
        if (count($domains) < 10) $domains[] = $domain;
        switch (infra_research_classify($domain, $niche)) {
            case 'DIRECTORY': $open++; break;
            case 'NATIONAL':  $natl++; break;
            default:          $local++;
        }
    }
    return ['open_slots' => $open, 'local_competitors' => $local, 'national_brands' => $natl, 'domains' => $domains];
}

/**
 * Builds ONE task's location field: a per-city GPS pin (location_coordinate)
 * when the candidate has real coordinates, falling back to the account's
 * fixed nationwide location_code when it doesn't (a handful of reference
 * cities have no lat/lng). location_coordinate's format is fixed by
 * DataForSEO's own spec: "latitude,longitude,radius", max 7 decimal digits,
 * radius 199-199999 — units are documented as millimeters, which doesn't map
 * to a real-world search radius, so this uses their own docs' example radius
 * (200) verbatim rather than inventing a "meaningful" number in a unit that
 * isn't actually meaningful.
 */
function infra_research_serp_location(array $c, ?float $lat, ?float $lng): array
{
    if ($lat !== null && $lng !== null) {
        return ['location_coordinate' => number_format($lat, 7, '.', '') . ',' . number_format($lng, 7, '.', '') . ',200'];
    }
    return ['location_code' => (int) ($c['location'] ?? 2840) ?: 2840];
}

/* ---------------------------------------------------------------------------
 * Distance — same math as build_cities.py's haversine(), so a mile here means
 * the same thing it means there.
 * ------------------------------------------------------------------------- */

function infra_research_haversine_mi(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $r = 3958.8;
    $p1 = deg2rad($lat1); $p2 = deg2rad($lat2);
    $dp = deg2rad($lat2 - $lat1); $dl = deg2rad($lng2 - $lng1);
    $a = sin($dp / 2) ** 2 + cos($p1) * cos($p2) * sin($dl / 2) ** 2;
    return 2 * $r * asin(min(1, sqrt($a)));
}

const INFRA_RESEARCH_REGIONS = [
    'Northeast' => ['CT','ME','MA','NH','RI','VT','NJ','NY','PA'],
    'Midwest'   => ['IL','IN','MI','OH','WI','IA','KS','MN','MO','NE','ND','SD'],
    'South'     => ['DE','FL','GA','MD','NC','SC','VA','DC','WV','AL','KY','MS','TN','AR','LA','OK','TX'],
    'West'      => ['AZ','CO','ID','MT','NV','NM','UT','WY','AK','CA','HI','OR','WA'],
];

function infra_research_region(string $ss): string
{
    foreach (INFRA_RESEARCH_REGIONS as $region => $states) {
        if (in_array(strtoupper($ss), $states, true)) return $region;
    }
    return '?';
}

/* ---------------------------------------------------------------------------
 * Score: 0.45*competition + 0.35*demand + 0.20*money, all z-scored across the
 * run's own candidate pool. Same weights as the hand-built list — not exposed
 * as editable, per "don't rebalance without asking."
 * ------------------------------------------------------------------------- */

function infra_research_zstats(array $vals): array
{
    $vals = array_values(array_filter($vals, fn($v) => $v !== null));
    if (!$vals) return [0.0, 1.0];
    $mean = array_sum($vals) / count($vals);
    $var  = array_sum(array_map(fn($v) => ($v - $mean) ** 2, $vals)) / count($vals);
    $sd   = sqrt($var) ?: 1.0;
    return [$mean, $sd];
}

function infra_research_logsafe(float $v): float { return log(max($v, 1)); }

/**
 * Note: tonight's hand-built list also penalized "population within 30 miles"
 * inside the score itself. That number needs a precomputed isolation metric
 * this tool doesn't have (no `pop_30mi` column exists on the reference `cities`
 * table — computing it live would mean comparing every candidate against all
 * 10,000 reference cities). Skipped for simplicity: the mile-separation +
 * state-cap diversification pass below already prevents picking two cities in
 * the same metro, which is the same problem this score term would have caught.
 */
function infra_research_score_and_grade(array &$candidates): void
{
    $openVals   = array_map(fn($c) => $c['open_slots'] ?? 0.0, $candidates);
    $localVals  = array_map(fn($c) => $c['local_competitors'] ?? 0.0, $candidates);
    $volVals    = array_map(fn($c) => isset($c['volume']) ? infra_research_logsafe($c['volume']) : null, $candidates);
    $priceVals  = array_map(fn($c) => isset($c['price_avg']) && $c['price_avg'] > 0 ? infra_research_logsafe($c['price_avg']) : null, $candidates);
    $buyerVals  = array_map(fn($c) => isset($c['buyers']) ? sqrt(max(0, $c['buyers'])) : null, $candidates);

    [$mOpen, $sdOpen]     = infra_research_zstats($openVals);
    [$mLocal, $sdLocal]   = infra_research_zstats($localVals);
    [$mVol, $sdVol]       = infra_research_zstats(array_filter($volVals, fn($v) => $v !== null));
    [$mPrice, $sdPrice]   = infra_research_zstats(array_filter($priceVals, fn($v) => $v !== null));
    [$mBuyers, $sdBuyers] = infra_research_zstats(array_filter($buyerVals, fn($v) => $v !== null));

    foreach ($candidates as $id => &$c) {
        $zOpen   = (($c['open_slots'] ?? 0.0) - $mOpen) / $sdOpen;
        $zLocal  = (($c['local_competitors'] ?? 0.0) - $mLocal) / $sdLocal;
        $zVol    = isset($c['volume']) ? (infra_research_logsafe($c['volume']) - $mVol) / $sdVol : 0.0;
        $zPrice  = isset($c['price_avg']) && $c['price_avg'] > 0 ? (infra_research_logsafe($c['price_avg']) - $mPrice) / $sdPrice : 0.0;
        $zBuyers = isset($c['buyers']) ? (sqrt(max(0, $c['buyers'])) - $mBuyers) / $sdBuyers : 0.0;

        $competition = 0.55 * $zOpen - 0.30 * $zLocal;
        $demand      = $zVol;
        $money       = 0.70 * $zPrice + 0.30 * $zBuyers;
        $c['score']  = round(0.45 * $competition + 0.35 * $demand + 0.20 * $money, 3);
    }
    unset($c);

    $order = $candidates;
    usort($order, fn($a, $b) => $b['score'] <=> $a['score']);
    $n = count($order);
    foreach ($order as $i => $c) {
        $pct = $n > 1 ? $i / ($n - 1) * 100 : 0;
        $grade = $pct < 15 ? 'A' : ($pct < 40 ? 'B' : ($pct < 70 ? 'C' : ($pct < 90 ? 'D' : 'F')));
        $candidates[$c['_id']]['grade'] = $grade;
    }
}

/**
 * Walk score-ranked candidates, drop anything within $sepMi of an already-
 * picked city, cap each state at $stateCapFrac of the pool. No target count —
 * take whatever clears the bar.
 */
function infra_research_diversify(array $candidates, float $sepMi, float $stateCapFrac): array
{
    $order = array_values($candidates);
    usort($order, fn($a, $b) => $b['score'] <=> $a['score']);
    $stateCap = max(1, (int) (count($order) * $stateCapFrac));
    $stateCounts = [];
    $picked = [];
    foreach ($order as $c) {
        $ss = $c['ss'];
        if (($stateCounts[$ss] ?? 0) >= $stateCap) continue;
        if ($c['lat'] !== null && $c['lng'] !== null) {
            $tooClose = false;
            foreach ($picked as $p) {
                if ($p['lat'] === null) continue;
                if (infra_research_haversine_mi($c['lat'], $c['lng'], $p['lat'], $p['lng']) < $sepMi) { $tooClose = true; break; }
            }
            if ($tooClose) continue;
        }
        $picked[] = $c;
        $stateCounts[$ss] = ($stateCounts[$ss] ?? 0) + 1;
    }
    return $picked;
}

/* ---------------------------------------------------------------------------
 * Run state — one JSON file per run, its own storage, never fleet.db.
 * ------------------------------------------------------------------------- */

function infra_research_run_path(string $runId): string
{
    return infra_research_dir() . '/' . preg_replace('/[^a-z0-9_-]/i', '', $runId) . '.json';
}

function infra_research_load_run(string $runId): ?array
{
    $p = infra_research_run_path($runId);
    if (!is_file($p)) return null;
    $d = json_decode((string) file_get_contents($p), true);
    return is_array($d) ? $d : null;
}

function infra_research_save_run(array $run): void
{
    file_put_contents(infra_research_run_path($run['id']), json_encode($run, JSON_PRETTY_PRINT));
}

function infra_research_new_run_id(string $niche): string
{
    return $niche . '-' . date('Ymd-His');
}

/**
 * One time-boxed tick of a run: volume phase, then SERP phase, then the final
 * score/diversify/write once SERP is done. Shared by the web Continue action
 * and cron/research_tick.php — a run's progress must not depend on a browser
 * tab staying open. A backgrounded tab can have its JS timer silently throttled
 * or frozen by the browser (observed twice: long stalls, then a catch-up burst
 * the moment the tab regains focus); a closed tab obviously can't run any JS
 * at all. The cron tick makes forward progress possible either way.
 *
 * Mutates $run in place. Does NOT save or redirect - the caller owns that, so
 * this one function works from both a web request (redirects after) and a
 * CLI loop (just keeps ticking).
 *
 * @return array{level:string,msg:string,stop:bool} stop=true means don't
 *   immediately retry in a loop (bad creds, or a whole-request API failure).
 */
/**
 * Once every candidate/pattern pair in a run has a SERP result: average the
 * per-pattern sums, score+grade, apply the max-rivals filter, diversify, and
 * write the xlsx. Split out of the tick loop so both the single-run path and
 * the pooled multi-run path below call the exact same finishing logic.
 *
 * @return array{level:string,msg:string}
 */
function infra_research_finalize_serp(array &$run): array
{
    foreach ($run['candidates'] as $id => &$c) {
        $n = max(1, $c['serp_patterns_done']);
        $c['open_slots']        = $c['serp_open_sum'] / $n;
        $c['local_competitors'] = $c['serp_local_sum'] / $n;
        $c['national_brands']   = $c['serp_natl_sum'] / $n;
    }
    unset($c);
    infra_research_score_and_grade($run['candidates']);

    $maxRivals = $run['filters']['max_rivals'] ?? null;
    $rivalsDropped = 0;
    $scoredPool = $run['candidates'];
    if ($maxRivals !== null) {
        $before = count($scoredPool);
        $scoredPool = array_filter($scoredPool, fn($c) =>
            (($c['local_competitors'] ?? 0) + ($c['national_brands'] ?? 0)) <= $maxRivals);
        $rivalsDropped = $before - count($scoredPool);
    }

    $picked = infra_research_diversify($scoredPool, $run['filters']['sep_mi'], $run['filters']['state_cap_pct']);
    // $run['candidates'] here is passed BEFORE the max-rivals/diversify drops
    // above — "All Scored" must hold every city that reached the SERP phase,
    // not just the final list, and in its own natural (unsorted) order.
    $resultFile = infra_research_write_xlsx($run['niche'], $picked, $run['candidates'], $run['patterns']);
    $rivalsNote = $rivalsDropped > 0 ? " ({$rivalsDropped} dropped for exceeding the max-rivals cap)" : '';
    if ($resultFile === null) {
        return ['level' => 'err', 'msg' =>
            count($picked) . ' cities scored, but writing the xlsx failed '
            . '(disk full or uploads/downloads not writable?) - press Continue to retry the write.'];
    }
    $run['result_file'] = $resultFile;
    $run['result_count'] = count($picked);
    $run['phase'] = 'done';
    return ['level' => 'ok', 'msg' =>
        count($picked) . " cities in the final list{$rivalsNote}. Saved to Downloads (Test Lab) as {$resultFile}."];
}

/**
 * Ticks the SERP phase for one or more runs at once, sharing ONE time budget
 * and ONE concurrency pool across every niche passed in, instead of each
 * niche getting its own sequential turn. Before this, N active niches in the
 * same cron invocation could take up to N * INFRA_RESEARCH_TIME_BUDGET
 * seconds combined — easily longer than the 2-minute cron interval, which
 * caused the NEXT invocation to be skipped outright by cron/research_tick.
 * php's self-lock (a whole cron cycle thrown away, not just a slow one).
 * Pooling bounds one invocation's SERP work to ONE budget window regardless
 * of how many niches are active. Real numbers from 2026-09-29: 3 niches
 * running concurrently (pre-pooling) totaled about the same combined
 * throughput as 1 niche running alone — evidence the real bottleneck was
 * never "each niche needs its own dedicated time", so sharing one budget/pool
 * loses nothing real and stops the wasted skips.
 *
 * The raw HTTP fetch (infra_kw_dfs_post_many()) is niche-agnostic — only
 * classifying a result's domains into DIRECTORY/NATIONAL/LOCAL depends on
 * which niche a job belongs to, so that happens per-job AFTER the shared
 * fetch (a wave can and often will mix jobs from several different niches).
 *
 * @param array $runs run_id => &$run, BY REFERENCE — candidates/phase are
 *   mutated in place. Entries whose phase isn't 'serp' are left untouched.
 * @return array run_id => {level:string,msg:string}, one entry for every run
 *   that WAS in the serp phase (a run that wasn't is simply absent from the
 *   return, not an error).
 */
function infra_research_tick_serp_pool(array &$runs): array
{
    $out = [];
    $serpRunIds = [];
    foreach ($runs as $rid => $run) {
        if (($run['phase'] ?? '') === 'serp') $serpRunIds[] = $rid;
    }
    if (!$serpRunIds) return $out;

    if (!infra_kw_has_creds('dataforseo')) {
        foreach ($serpRunIds as $rid) {
            $out[$rid] = ['level' => 'err', 'msg' =>
                'The SERP check needs DataForSEO credentials — add them on the Cities/Niche tab first.'];
        }
        return $out;
    }
    $cfg = infra_kw_provider('dataforseo');
    $lang = trim((string) ($cfg['language'] ?? 'en')) ?: 'en';

    // NOT batched within one request: DataForSEO's serp/google/organic/live/advanced
    // flatly rejects more than one task per request body ("You can set only one task
    // at a time"), confirmed against the real API. That's a different constraint than
    // CONCURRENT separate requests, which DataForSEO's own docs explicitly describe as
    // the intended way to use their per-minute throughput.
    //
    // One combined backlog across EVERY run in the serp phase, tagged with which run
    // each job belongs to, then drained in shared waves of INFRA_RESEARCH_SERP_
    // CONCURRENCY regardless of which niche each job in a wave happens to be for.
    //
    // Built per-run first, then ROUND-ROBIN interleaved rather than concatenated —
    // concatenating would let whichever run happens to be enumerated first (e.g. the
    // one with the biggest remaining backlog) consume the ENTIRE shared time budget
    // before a later run's jobs are ever reached, some invocations making zero
    // progress on it. Confirmed live: with a 4,000+ job niche listed before a
    // smaller one, the smaller one got 0 checks for a full pass. Round-robin means
    // every active run gets a proportional share of every invocation's budget.
    $perRunTodo = [];
    foreach ($serpRunIds as $rid) {
        $run =& $runs[$rid];
        $numPatterns = count($run['patterns']);
        $jobs = [];
        foreach ($run['candidates'] as $id => $c) {
            if ($c['serp_patterns_done'] >= $numPatterns) continue;
            $patIdx = $c['serp_patterns_done'];
            $pat = $run['patterns'][$patIdx];
            $phrase = infra_kw_phrase($pat, ['city' => infra_research_city_label($c), 'state' => $c['state'], 'ss' => $c['ss']]);
            if ($phrase === '') { $run['candidates'][$id]['serp_patterns_done']++; continue; }
            $jobs[] = ['rid' => $rid, 'niche' => $run['niche'], 'id' => $id, 'patIdx' => $patIdx,
                       'phrase' => $phrase, 'lat' => $c['lat'] ?? null, 'lng' => $c['lng'] ?? null];
        }
        $perRunTodo[$rid] = $jobs;
        unset($run);
    }

    $todo = [];
    $cursors = array_fill_keys(array_keys($perRunTodo), 0);
    $remaining = array_sum(array_map('count', $perRunTodo));
    while ($remaining > 0) {
        foreach ($perRunTodo as $rid => $jobs) {
            $cur = $cursors[$rid];
            if ($cur >= count($jobs)) continue;
            $todo[] = $jobs[$cur];
            $cursors[$rid] = $cur + 1;
            $remaining--;
        }
    }

    $doneCounts = []; $failCounts = [];
    $started = time(); $i = 0; $n = count($todo);
    while ($i < $n && time() - $started <= INFRA_RESEARCH_TIME_BUDGET) {
        $wave = array_slice($todo, $i, INFRA_RESEARCH_SERP_CONCURRENCY);
        $i += count($wave);
        $tasks = array_map(fn($job) => array_merge([
            'keyword' => $job['phrase'], 'language_code' => $lang, 'device' => 'desktop', 'depth' => 10,
        ], infra_research_serp_location($cfg, $job['lat'], $job['lng'])), $wave);
        $raw = infra_kw_dfs_post_many($cfg, 'serp/google/organic/live/advanced', $tasks, INFRA_RESEARCH_SERP_CONCURRENCY);
        foreach ($wave as $j => $job) {
            $r = $raw[$j];
            $rid = $job['rid'];
            if (!$r['ok']) { $failCounts[$rid] = ($failCounts[$rid] ?? 0) + 1; continue; }
            $data = infra_research_classify_serp_result($r['result'], $job['niche']);
            $run =& $runs[$rid];
            $id = $job['id']; $patIdx = $job['patIdx'];
            $run['candidates'][$id]['serp_open_sum']  += $data['open_slots'];
            $run['candidates'][$id]['serp_local_sum'] += $data['local_competitors'];
            $run['candidates'][$id]['serp_natl_sum']  += $data['national_brands'];
            $run['candidates'][$id]['serp_by_pattern'][$patIdx] = [
                'local' => $data['local_competitors'], 'national' => $data['national_brands'],
                'directory' => $data['open_slots'], 'domains' => $data['domains'],
            ];
            $run['candidates'][$id]['serp_patterns_done']++;
            $doneCounts[$rid] = ($doneCounts[$rid] ?? 0) + 1;
            unset($run);
        }
    }

    foreach ($serpRunIds as $rid) {
        $run =& $runs[$rid];
        $numPatterns = count($run['patterns']);
        $left = 0;
        foreach ($run['candidates'] as $c) $left += max(0, $numPatterns - $c['serp_patterns_done']);
        $done = $doneCounts[$rid] ?? 0;
        $failed = $failCounts[$rid] ?? 0;
        if ($left === 0) {
            $out[$rid] = infra_research_finalize_serp($run);
        } else {
            $failNote = $failed > 0 ? " ({$failed} failed this pass, will retry)" : '';
            $out[$rid] = ['level' => 'ok', 'msg' => "{$done} SERP checks this pass{$failNote}, {$left} still to go — press Continue."];
        }
        unset($run);
    }
    return $out;
}

function infra_research_tick(array &$run): array
{
    if ($run['phase'] === 'volume') {
        if (!infra_kw_has_creds($run['provider'])) {
            return ['level' => 'err', 'stop' => true,
                'msg' => 'No API key stored for ' . $run['provider'] . ' — add one on the Cities/Niche tab first.'];
        }
        $todo = array_filter($run['candidates'], fn($c) => $c['volume'] === null);
        $numPatterns = count($run['patterns']);
        $chunkCities = max(1, (int) floor(infra_kw_batch_size($run['provider']) / max(1, $numPatterns)));

        $started = time(); $done = 0;
        $ids = array_keys($todo);
        foreach (array_chunk($ids, $chunkCities) as $chunk) {
            if (time() - $started > INFRA_RESEARCH_TIME_BUDGET) break;
            $phrases = []; $byPhrase = [];
            foreach ($chunk as $id) {
                $c = $run['candidates'][$id];
                foreach ($run['patterns'] as $patIdx => $pat) {
                    $p = infra_kw_phrase($pat, ['city' => infra_research_city_label($c), 'state' => $c['state'], 'ss' => $c['ss']]);
                    if ($p === '') continue;
                    $k = strtolower($p);
                    if (!isset($byPhrase[$k])) { $phrases[] = $p; $byPhrase[$k] = []; }
                    $byPhrase[$k][] = [$id, $patIdx];
                }
            }
            if (!$phrases) continue;
            $r = infra_kw_fetch($run['provider'], $phrases);
            if (!$r['ok']) return ['level' => 'err', 'stop' => true, 'msg' => 'Stopped: ' . $r['msg']];
            $sums = []; $byPattern = [];
            foreach ($byPhrase as $phrase => $pairs) {
                $vol = (float) ($r['rows'][$phrase]['volume'] ?? 0);
                foreach ($pairs as [$id, $patIdx]) {
                    $sums[$id] = ($sums[$id] ?? 0) + $vol;
                    $byPattern[$id][$patIdx] = $vol;
                }
            }
            foreach ($chunk as $id) {
                $run['candidates'][$id]['volume'] = $sums[$id] ?? 0.0;
                // Per-pattern breakdown, kept alongside the total for the "All Scored"
                // sheet — the total alone (the only thing tracked before) can't be
                // split back into its per-pattern parts after the fact.
                $run['candidates'][$id]['volume_by_pattern'] = $byPattern[$id] ?? [];
                $done++;
            }
        }
        $left = count(array_filter($run['candidates'], fn($c) => $c['volume'] === null));
        if ($left === 0) {
            $before = count($run['candidates']);
            $run['candidates'] = array_filter($run['candidates'], fn($c) => $c['volume'] >= $run['filters']['min_volume']);
            $dropped = $before - count($run['candidates']);
            $run['phase'] = 'serp';
            return ['level' => 'ok', 'stop' => false, 'msg' =>
                "Volume done — {$dropped} cities dropped under {$run['filters']['min_volume']}/mo, "
                . count($run['candidates']) . ' remain. Press Continue to run real SERP checks (costs money — DataForSEO, ~$0.002/keyword).'];
        }
        return ['level' => 'ok', 'stop' => false, 'msg' => "{$done} fetched this pass, {$left} still to go — press Continue."];
    }

    if ($run['phase'] === 'serp') {
        // Single-run entrypoint delegates to the multi-run pool below with a
        // one-element pool — see that function's doc comment for why pooling
        // exists at all (the cron path is the real reason; a lone run here
        // behaves exactly as it did before this existed).
        $runs = [];
        $runs['_single'] =& $run;
        $out = infra_research_tick_serp_pool($runs);
        $r = $out['_single'] ?? ['level' => 'warn', 'msg' => 'This run is already done.'];
        return $r + ['stop' => $run['phase'] === 'done' || $r['level'] === 'err'];
    }

    return ['level' => 'warn', 'stop' => true, 'msg' => 'This run is already done.'];
}

/* ---------------------------------------------------------------------------
 * Form drafts — save the current field values (patterns, eLocal data,
 * filters) per niche WITHOUT starting a real run or spending any money. Kept
 * in their own subdirectory, not the run directory itself: infra_research_
 * list_runs() globs every *.json in state/research/ with no shape check, so
 * a draft sitting there would silently show up in the "past runs" table.
 * ------------------------------------------------------------------------- */

function infra_research_draft_path(string $niche): string
{
    $dir = infra_research_dir() . '/drafts';
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    return $dir . '/' . preg_replace('/[^a-z0-9_-]/i', '', $niche) . '.json';
}

function infra_research_load_draft(string $niche): ?array
{
    $p = infra_research_draft_path($niche);
    if (!is_file($p)) return null;
    $d = json_decode((string) file_get_contents($p), true);
    return is_array($d) ? $d : null;
}

function infra_research_save_draft(string $niche, array $fields): void
{
    file_put_contents(infra_research_draft_path($niche), json_encode($fields, JSON_PRETTY_PRINT));
}

/** Where a niche's held eLocal upload lives — fixed name, so a new upload always
 *  replaces the previous one rather than accumulating files. */
function infra_research_draft_elocal_path(string $niche): string
{
    return infra_research_dir() . '/drafts/' . preg_replace('/[^a-z0-9_-]/i', '', $niche) . '-elocal.csv';
}

/**
 * If $file is a genuine new upload, copies it into this niche's held-file slot
 * (replacing whatever was there) and returns its original name to record. Returns
 * null when there's no new upload — callers must leave whatever was already saved
 * alone in that case, not delete it.
 */
function infra_research_persist_elocal_upload(string $niche, ?array $file): ?string
{
    if (!$file || empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])
        || ($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK || ($file['size'] ?? 0) > 4 * 1024 * 1024) {
        return null;
    }
    $dir = dirname(infra_research_draft_elocal_path($niche));
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    if (!@move_uploaded_file($file['tmp_name'], infra_research_draft_elocal_path($niche))) return null;
    return (string) ($file['name'] ?? 'uploaded.csv');
}

/**
 * All runs, newest first, WITH their full `candidates` payload intact.
 *
 * Despite the name, this is the mutate-in-place form: cron/research_tick.php
 * calls this (not infra_research_load_run()) to get every in-progress run's
 * real candidate array so it can tick and save it. CLI has no memory_limit,
 * so decoding every run file's full contents at once is fine there.
 *
 * The web console does NOT have that headroom (128M under mod_php) — a run
 * file now holds thousands of scored candidates and has grown to several MB
 * each; with ~19 accumulated runs that's enough to exhaust 128M just to
 * render a list of niches and counts. Use
 * infra_research_list_run_summaries() for anything that only needs to list/
 * display runs rather than mutate them.
 */
function infra_research_list_runs(): array
{
    $out = [];
    foreach (glob(infra_research_dir() . '/*.json') ?: [] as $f) {
        $d = json_decode((string) file_get_contents($f), true);
        if (is_array($d)) $out[] = $d;
    }
    usort($out, fn($a, $b) => strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));
    return $out;
}

/**
 * Same runs, newest first, but with the heavy `candidates` array replaced by
 * a cheap `candidate_count` — for the web UI's niche tabs and "Past runs"
 * table, which only ever display a count, never the candidates themselves.
 * See infra_research_list_runs() for why this split exists: loading all of a
 * niche's run history in full is what was exhausting the web process's 128M
 * memory_limit once real runs grew to multi-MB each.
 */
function infra_research_list_run_summaries(): array
{
    $out = [];
    foreach (glob(infra_research_dir() . '/*.json') ?: [] as $f) {
        $d = json_decode((string) file_get_contents($f), true);
        if (!is_array($d)) continue;
        $d['candidate_count'] = count($d['candidates'] ?? []);
        unset($d['candidates']);
        $out[] = $d;
    }
    usort($out, fn($a, $b) => strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));
    return $out;
}

/* ---------------------------------------------------------------------------
 * Output: a real .xlsx into uploads/downloads/ — Test Lab's existing
 * "Downloads for Scott" panel picks it up automatically, no new delivery UI.
 *
 * No spreadsheet library exists in this codebase (no PhpSpreadsheet/composer
 * package), so this hand-writes the minimal OOXML a workbook needs: one zip,
 * inline-string cells (skips needing a sharedStrings table), one bold-header
 * style. Deliberately not a general-purpose writer — just enough for this
 * tool's own two-sheet output.
 * ------------------------------------------------------------------------- */

function infra_research_downloads_dir(): string
{
    $dir = infra_base_dir() . '/../../uploads/downloads';
    return realpath($dir) ?: $dir;
}

function infra_research_xml_text(string $s): string
{
    // Strip XML-illegal control characters (everything except tab/LF/CR) first — a
    // stray byte from a Windows-1252 eLocal export makes the worksheet part
    // non-well-formed XML, which is a real "unreadable content" corruption, not a
    // cosmetic one. ENT_SUBSTITUTE keeps htmlspecialchars() from returning '' outright
    // on any remaining invalid UTF-8 (its default behavior with none of the ENT_*
    // substitute flags set) — better a replacement character than a silently blanked
    // cell.
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s);
    return htmlspecialchars($s, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
}

/** One row of the sheet XML. $cells is a list of ['v'=>mixed,'num'=>bool,'bold'=>bool]. */
function infra_research_xlsx_row(int $rowNum, array $cells): string
{
    $out = '<row r="' . $rowNum . '">';
    foreach ($cells as $i => $cell) {
        $col = infra_research_xlsx_col_letter($i) . $rowNum;
        $s = !empty($cell['bold']) ? ' s="1"' : '';
        if (!empty($cell['num'])) {
            // is_numeric() alone lets NAN/INF through (both are floats) - either would
            // be written as the literal word "NAN"/"INF" inside <v>, which is not a
            // valid OOXML number and corrupts the whole sheet for Excel, not just the
            // one cell. Last-resort boundary check regardless of what upstream scoring
            // produced.
            $numOk = is_numeric($cell['v']) && is_finite((float) $cell['v']);
            $out .= '<c r="' . $col . '"' . $s . '><v>' . ($numOk ? $cell['v'] : 0) . '</v></c>';
        } else {
            $out .= '<c r="' . $col . '"' . $s . ' t="inlineStr"><is><t xml:space="preserve">'
                  . infra_research_xml_text((string) $cell['v']) . '</t></is></c>';
        }
    }
    return $out . '</row>';
}

function infra_research_xlsx_col_letter(int $i): string
{
    $s = '';
    $i++;
    while ($i > 0) {
        $m = ($i - 1) % 26;
        $s = chr(65 + $m) . $s;
        $i = intdiv($i - 1, 26);
    }
    return $s;
}

/** @param array $rows list of plain rows (each a list of scalars); row 0 is treated as the bold header */
function infra_research_xlsx_sheet_xml(array $rows): string
{
    $numCols = $rows ? max(array_map('count', $rows)) : 1;
    $lastCol = infra_research_xlsx_col_letter(max(0, $numCols - 1));
    $lastRow = max(1, count($rows));
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
         . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
         . '<dimension ref="A1:' . $lastCol . $lastRow . '"/><sheetData>';
    foreach ($rows as $r => $row) {
        $cells = [];
        foreach ($row as $v) {
            $cells[] = ['v' => $v, 'num' => is_int($v) || is_float($v), 'bold' => $r === 0];
        }
        $xml .= infra_research_xlsx_row($r + 1, $cells);
    }
    return $xml . '</sheetData></worksheet>';
}

/**
 * @param string $path full filesystem path to write
 * @param array $sheets ['Sheet Name' => [ [row0 cells], [row1 cells], ... ], ...]
 * @return bool false if the zip couldn't be opened or written - callers must not link
 *   the file when this fails, or the browser downloads whatever partial/absent file
 *   is on disk and names it .xlsx regardless.
 */
function infra_research_write_xlsx_file(string $path, array $sheets): bool
{
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) return false;

    $zip->addFromString('[Content_Types].xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . implode('', array_map(fn($i) => '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" '
            . 'ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>', range(1, count($sheets))))
        . '</Types>');

    $zip->addFromString('_rels/.rels',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>');

    $sheetNames = array_keys($sheets);
    $zip->addFromString('xl/workbook.xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
        . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>'
        . implode('', array_map(fn($i, $name) => '<sheet name="' . infra_research_xml_text($name)
            . '" sheetId="' . ($i + 1) . '" r:id="rId' . ($i + 1) . '"/>', array_keys($sheetNames), $sheetNames))
        . '</sheets></workbook>');

    $zip->addFromString('xl/_rels/workbook.xml.rels',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . implode('', array_map(fn($i) => '<Relationship Id="rId' . ($i + 1) . '" '
            . 'Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" '
            . 'Target="worksheets/sheet' . ($i + 1) . '.xml"/>', range(0, count($sheets) - 1)))
        . '</Relationships>');

    $zip->addFromString('xl/styles.xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font>'
        . '<font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
        // Excel hard-requires index 0 = none and index 1 = gray125 in the fills table -
        // every real writer emits both, even when nothing uses the second one. A
        // single-fill table is a known trigger for Excel's "Removed Records: Style
        // from /xl/styles.xml" repair prompt.
        . '<fills count="2"><fill><patternFill patternType="none"/></fill>'
        . '<fill><patternFill patternType="gray125"/></fill></fills>'
        . '<borders count="1"><border/></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="2">'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
        . '</cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
        . '</styleSheet>');

    $i = 1;
    foreach ($sheets as $rows) {
        $zip->addFromString('xl/worksheets/sheet' . $i . '.xml', infra_research_xlsx_sheet_xml($rows));
        $i++;
    }
    return $zip->close();
}

/**
 * Every reference city with population > 100,000 and real coordinates — the
 * candidate pool for "nearest big city", queried once per write rather than
 * once per row. `cities` is read-only reference data (lib/cities.php), so this
 * is safe to cache for the lifetime of one xlsx write.
 */
function infra_research_major_cities(): array
{
    return infra_cities_init()
        ->query("SELECT id, city, ss, lat, lng FROM cities WHERE population > 100000 AND lat <> '' AND lng <> ''")
        ->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * @return array{0:string,1:?float} [nearest city label ("City, SS") or '' if
 *   nothing qualifies, distance in miles or null]. Excludes the candidate's
 *   own reference-city row so a candidate that is itself >100k population
 *   doesn't trivially report itself at 0 miles.
 */
function infra_research_nearest_major(array $c, array $majors): array
{
    if (($c['lat'] ?? null) === null || ($c['lng'] ?? null) === null) return ['', null];
    $bestLabel = ''; $bestDist = null;
    foreach ($majors as $m) {
        if (($m['id'] ?? null) === ($c['_id'] ?? null)) continue;
        $d = infra_research_haversine_mi((float) $c['lat'], (float) $c['lng'], (float) $m['lat'], (float) $m['lng']);
        if ($bestDist === null || $d < $bestDist) { $bestDist = $d; $bestLabel = $m['city'] . ', ' . $m['ss']; }
    }
    return [$bestLabel, $bestDist];
}

/**
 * The "All Scored" sheet: every city that reached the SERP phase, in the
 * candidates array's own natural order — deliberately NOT re-sorted, filtered,
 * or deduplicated against the diversified final list. One row per candidate,
 * one repeating group of columns per keyword pattern (a run with 3 patterns
 * gets 3 sets of local/national/directory/domain columns, in pattern order).
 */
function infra_research_all_scored_sheet(array $allScored, array $patterns): array
{
    $majors = infra_research_major_cities();
    $numPatterns = count($patterns);

    $header = ['City', 'State', 'Population', 'SMB Buyers', 'Avg Call Price $', 'Max Call Price $'];
    foreach ($patterns as $pat) $header[] = 'Vol: ' . $pat;
    $header[] = 'Total Volume/mo';
    foreach ($patterns as $pat) {
        $header[] = $pat . ' — Local Competitors';
        $header[] = $pat . ' — National Brands';
        $header[] = $pat . ' — Directories';
        for ($k = 1; $k <= 10; $k++) $header[] = $pat . " — Rank {$k} Domain";
    }
    $header[] = 'Latitude'; $header[] = 'Longitude';
    $header[] = 'Nearest 100k+ City'; $header[] = 'Distance to Nearest 100k+ City (mi)';
    $header[] = 'Score'; $header[] = 'Grade';

    $sheet = [$header];
    foreach ($allScored as $c) {
        $row = [
            (string) ($c['city'] ?? ''), (string) ($c['ss'] ?? ''), (int) ($c['population'] ?? 0),
            (float) ($c['buyers'] ?? 0), (float) ($c['price_avg'] ?? 0), (float) ($c['price_max'] ?? 0),
        ];
        for ($p = 0; $p < $numPatterns; $p++) {
            $row[] = isset($c['volume_by_pattern'][$p]) ? round($c['volume_by_pattern'][$p]) : '';
        }
        $row[] = isset($c['volume']) ? round($c['volume']) : '';
        for ($p = 0; $p < $numPatterns; $p++) {
            $pd = $c['serp_by_pattern'][$p] ?? null;
            $row[] = $pd['local'] ?? '';
            $row[] = $pd['national'] ?? '';
            $row[] = $pd['directory'] ?? '';
            $doms = $pd['domains'] ?? [];
            for ($k = 0; $k < 10; $k++) $row[] = $doms[$k] ?? '';
        }
        $row[] = $c['lat'] ?? '';
        $row[] = $c['lng'] ?? '';
        [$nearLabel, $nearDist] = infra_research_nearest_major($c, $majors);
        $row[] = $nearLabel;
        $row[] = $nearDist !== null ? round($nearDist, 1) : '';
        $row[] = (float) ($c['score'] ?? 0);
        $row[] = (string) ($c['grade'] ?? '');
        $sheet[] = $row;
    }
    return $sheet;
}

/**
 * @param array $picked the diversified final list (unchanged shape/contents from before)
 * @param array $allScored every candidate that reached the SERP phase — pre-maxRivals,
 *   pre-diversify, in its own natural order (see infra_research_all_scored_sheet())
 * @param array $patterns this run's keyword patterns, in order — drives the "All
 *   Scored" sheet's repeating per-pattern column groups
 * @return string|null the filename on success, null if the write failed - callers must
 *  not link/store a filename on null, or a browser ends up downloading whatever
 *  partial or missing file is on disk and naming it .xlsx regardless. */
function infra_research_write_xlsx(string $niche, array $picked, array $allScored = [], array $patterns = []): ?string
{
    $fname = $niche . '-city-research-' . date('Y-m-d') . '.xlsx';
    $path  = infra_research_downloads_dir() . '/' . $fname;

    $buildList = [
        ['Build #', 'City', 'State', 'Grade', 'Score', 'Population', 'Volume/mo',
         'Open Slots', 'Local Competitors', 'National Brands', 'Buyers', '1P Avg $', 'Region'],
    ];
    foreach ($picked as $i => $r) {
        $buildList[] = [
            $i + 1, (string) $r['city'], (string) $r['ss'], (string) ($r['grade'] ?? ''), (float) ($r['score'] ?? 0),
            (int) ($r['population'] ?? 0), (int) round($r['volume'] ?? 0),
            round($r['open_slots'] ?? 0, 1), round($r['local_competitors'] ?? 0, 1),
            round($r['national_brands'] ?? 0, 1), (float) ($r['buyers'] ?? 0), (float) ($r['price_avg'] ?? 0),
            infra_research_region($r['ss']),
        ];
    }

    $allScoredSheet = infra_research_all_scored_sheet($allScored, $patterns);

    $method = [['City Research — method, in brief'], [
        "SCORE = 0.45*COMPETITION + 0.35*DEMAND + 0.20*MONEY, z-scored across this run's own candidate pool.",
    ], [
        'COMPETITION = 0.55*z(open slots) - 0.30*z(local competitors). Weighted highest on purpose:',
    ], [
        "rank with less volume beats volume you can't rank for.",
    ], [''], [
        'Open Slots = directories (Yelp, Angi, HomeAdvisor, etc.) in the top 10 - a well-built page can outrank these.',
    ], [
        'Local Competitors = real independent companies - the actual competition.',
    ], [
        "National Brands = franchises (ServPro, PuroClean, etc.) - can't displace, not counted against the city.",
    ], [''], [
        'Grade = percentile band of SCORE within this run: A=top 15%, B=next 25%, C=next 30%, D=next 20%, F=bottom 10%.',
    ], [
        'Diversification: no two picked cities within the chosen mile-separation, no state over the chosen % of the list.',
    ]];

    $ok = infra_research_write_xlsx_file($path, [
        'Build List' => $buildList,
        'All Scored' => $allScoredSheet,
        'Method' => $method,
    ]);
    return $ok ? $fname : null;
}
