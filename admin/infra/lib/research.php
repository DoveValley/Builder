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
// MORE errors, not fewer — recommending a steady flow instead. 8 is
// comfortably inside that "steady flow" zone, not the hard ceiling; tune here
// if a real test run shows headroom to go higher without more errors.
const INFRA_RESEARCH_SERP_CONCURRENCY = 8;

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

function infra_research_serp_fetch(array $c, string $keyword, string $niche): array
{
    $loc  = (int) ($c['location'] ?? 2840) ?: 2840;
    $lang = trim((string) ($c['language'] ?? 'en')) ?: 'en';
    $r = infra_kw_dfs_post($c, 'serp/google/organic/live/advanced', [
        'keyword' => $keyword, 'location_code' => $loc, 'language_code' => $lang,
        'device' => 'desktop', 'depth' => 10,
    ]);
    if (!$r['ok']) return ['ok' => false, 'msg' => $r['msg'], 'data' => []];
    return ['ok' => true, 'msg' => '', 'data' => infra_research_classify_serp_result($r['result'], $niche)];
}

/**
 * Many SERP checks at once, overlapped via infra_kw_dfs_post_many() (bounded
 * curl_multi, not a single bigger request — DataForSEO's live endpoint still
 * only accepts one task per request body). Each keyword's result is
 * independent — unlike the one-at-a-time tick loop, one failure here does not
 * prevent the others in the same batch from succeeding.
 *
 * @param array $keywords list of resolved keyword phrases, one per job
 * @return array same length/order as $keywords, each {ok:bool,msg:string,data:array}
 */
function infra_research_serp_fetch_many(array $c, array $keywords, string $niche, int $concurrency): array
{
    $loc  = (int) ($c['location'] ?? 2840) ?: 2840;
    $lang = trim((string) ($c['language'] ?? 'en')) ?: 'en';
    $tasks = array_map(fn($kw) => [
        'keyword' => $kw, 'location_code' => $loc, 'language_code' => $lang,
        'device' => 'desktop', 'depth' => 10,
    ], $keywords);
    $raw = infra_kw_dfs_post_many($c, 'serp/google/organic/live/advanced', $tasks, $concurrency);
    return array_map(function ($r) use ($niche) {
        if (!$r['ok']) return ['ok' => false, 'msg' => $r['msg'], 'data' => []];
        return ['ok' => true, 'msg' => '', 'data' => infra_research_classify_serp_result($r['result'], $niche)];
    }, $raw);
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
function infra_research_tick(array &$run): array
{
    $niche = $run['niche'];

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
                    $p = infra_kw_phrase($pat, ['city' => $c['city'], 'state' => $c['state'], 'ss' => $c['ss']]);
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
        if (!infra_kw_has_creds('dataforseo')) {
            return ['level' => 'err', 'stop' => true,
                'msg' => 'The SERP check needs DataForSEO credentials — add them on the Cities/Niche tab first.'];
        }
        $cfg = infra_kw_provider('dataforseo');
        $numPatterns = count($run['patterns']);

        // NOT batched within one request: DataForSEO's serp/google/organic/live/advanced
        // flatly rejects more than one task per request body ("You can set only one
        // task at a time"), confirmed against the real API - a past attempt at batching
        // sent 20 tasks/request and had 19/20 rejected every time. That's a different
        // constraint than CONCURRENT separate requests, which DataForSEO's own docs
        // explicitly describe as the intended way to use their per-minute throughput —
        // see infra_research_serp_fetch_many()/infra_http_multi() for the bounded
        // sliding-window concurrency this uses instead of one call at a time.
        //
        // Build the backlog first (every candidate/pattern pair not yet done), then
        // drain it in waves of INFRA_RESEARCH_SERP_CONCURRENCY. Each job's result is
        // independent - one failed check no longer aborts the whole pass the way a
        // single failure used to; it's just left in the backlog and retried next tick.
        $todo = [];
        foreach ($run['candidates'] as $id => $c) {
            if ($c['serp_patterns_done'] >= $numPatterns) continue;
            $patIdx = $c['serp_patterns_done'];
            $pat = $run['patterns'][$patIdx];
            $phrase = infra_kw_phrase($pat, ['city' => $c['city'], 'state' => $c['state'], 'ss' => $c['ss']]);
            if ($phrase === '') { $run['candidates'][$id]['serp_patterns_done']++; continue; }
            $todo[] = ['id' => $id, 'patIdx' => $patIdx, 'phrase' => $phrase];
        }

        $started = time(); $done = 0; $failed = 0;
        $i = 0; $n = count($todo);
        while ($i < $n && time() - $started <= INFRA_RESEARCH_TIME_BUDGET) {
            $wave = array_slice($todo, $i, INFRA_RESEARCH_SERP_CONCURRENCY);
            $i += count($wave);
            $results = infra_research_serp_fetch_many(
                $cfg, array_column($wave, 'phrase'), $niche, INFRA_RESEARCH_SERP_CONCURRENCY);
            foreach ($wave as $j => $job) {
                $r = $results[$j];
                if (!$r['ok']) { $failed++; continue; }
                $id = $job['id']; $patIdx = $job['patIdx'];
                $run['candidates'][$id]['serp_open_sum']  += $r['data']['open_slots'];
                $run['candidates'][$id]['serp_local_sum'] += $r['data']['local_competitors'];
                $run['candidates'][$id]['serp_natl_sum']  += $r['data']['national_brands'];
                // Per-pattern breakdown, kept alongside the running sums above — the sums
                // feed the existing averaged score inputs unchanged; this is purely
                // additive, for the "All Scored" sheet's per-pattern columns.
                $run['candidates'][$id]['serp_by_pattern'][$patIdx] = [
                    'local'     => $r['data']['local_competitors'],
                    'national'  => $r['data']['national_brands'],
                    'directory' => $r['data']['open_slots'],
                    'domains'   => $r['data']['domains'],
                ];
                $run['candidates'][$id]['serp_patterns_done']++;
                $done++;
            }
        }
        $left = 0;
        foreach ($run['candidates'] as $c) $left += max(0, $numPatterns - $c['serp_patterns_done']);
        if ($left === 0) {
            foreach ($run['candidates'] as $id => &$c) {
                $n = max(1, $c['serp_patterns_done']);
                $c['open_slots']         = $c['serp_open_sum'] / $n;
                $c['local_competitors']  = $c['serp_local_sum'] / $n;
                $c['national_brands']    = $c['serp_natl_sum'] / $n;
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
            $resultFile = infra_research_write_xlsx($niche, $picked, $run['candidates'], $run['patterns']);
            $rivalsNote = $rivalsDropped > 0 ? " ({$rivalsDropped} dropped for exceeding the max-rivals cap)" : '';
            if ($resultFile === null) {
                return ['level' => 'err', 'stop' => true, 'msg' =>
                    count($picked) . ' cities scored, but writing the xlsx failed '
                    . '(disk full or uploads/downloads not writable?) - press Continue to retry the write.'];
            }
            $run['result_file'] = $resultFile;
            $run['result_count'] = count($picked);
            $run['phase'] = 'done';
            return ['level' => 'ok', 'stop' => true, 'msg' =>
                count($picked) . " cities in the final list{$rivalsNote}. Saved to Downloads (Test Lab) as {$resultFile}."];
        }
        $failNote = $failed > 0 ? " ({$failed} failed this pass, will retry)" : '';
        return ['level' => 'ok', 'stop' => false, 'msg' => "{$done} SERP checks this pass{$failNote}, {$left} still to go — press Continue."];
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

/** All runs, newest first, for the "past runs" list. */
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
