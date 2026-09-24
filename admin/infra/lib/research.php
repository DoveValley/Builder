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

function infra_research_national_brands(): array
{
    return [
        'servpro.com', 'servicemasterrestore.com', 'puroclean.com', 'pauldavis.com',
        '911restoration.com', 'restorationmasterfinder.com', 'rainbowintl.com',
        'rainbowrestores.com', 'belfor.com', 'steamatic.com', 'ryanrestoration.com',
        'disastercompany.com', 'sernow.com', 'roto-rooter.com', 'rotorooter.com',
        'homedepot.com', 'lowes.com', 'statefarm.com', 'allstate.com', 'usaa.com',
        'amazon.com', '1800waterdamage.com', 'restoration1.com', 'restopros.co',
        'atirestoration.com', 'greenhomesolutions.com', 'drymedic.com',
        // pest/mold/appliance-relevant national franchises, harmless to keep
        // listed for niches that don't use them
        'terminix.com', 'orkin.com', 'rentokil.com', 'aptive.com', 'pestworld.org',
        'moldinspectionandtest.com', 'servicemaster.com', 'mrappliance.com',
        'sears.com', 'geeksquad.com', 'bestbuy.com',
    ];
}

function infra_research_national_suffixes(): array { return ['.gov', '.edu']; }
function infra_research_national_extra(): array { return ['wikipedia.org', 'reddit.com', 'quora.com', 'youtube.com']; }

function infra_research_norm_domain(string $d): string
{
    return strtolower(preg_replace('/^(www\.|m\.)/', '', trim($d)));
}

/** DIRECTORY | NATIONAL | LOCAL */
function infra_research_classify(string $domain): string
{
    $d = infra_research_norm_domain($domain);
    if (infra_serp_is_directory($d)) return 'DIRECTORY';
    foreach (infra_research_national_brands() as $b) {
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
    'price_avg'  => ['1p_avg', '1p avg $', 'avg_price', 'avg_call_price', 'price_avg', 'first_party_avg'],
    'price_max'  => ['1p_max', '1p max $', 'max_price', 'max_call_price', 'price_max', 'first_party_max'],
];

/** @return array{rows:array,errors:array} rows keyed nothing — plain list of assoc arrays */
function infra_research_parse_elocal(string $pasted, ?array $file): array
{
    $lines = [];
    if (trim($pasted) !== '') {
        foreach (preg_split('/\r\n|\r|\n/', trim($pasted)) as $l) if (trim($l) !== '') $lines[] = $l;
    }
    $fileLines = [];
    if ($file && !empty($file['tmp_name']) && is_uploaded_file($file['tmp_name'])
        && ($file['error'] ?? UPLOAD_ERR_OK) === UPLOAD_ERR_OK) {
        if (($file['size'] ?? 0) > 4 * 1024 * 1024) {
            return ['rows' => [], 'errors' => ['CSV ignored — larger than 4 MB.']];
        }
        $raw = (string) file_get_contents($file['tmp_name']);
        foreach (preg_split('/\r\n|\r|\n/', trim($raw)) as $l) if (trim($l) !== '') $fileLines[] = $l;
    }
    $allLines = array_merge($lines, $fileLines);
    if (!$allLines) return ['rows' => [], 'errors' => ['Nothing to import — paste rows or choose a file.']];

    // Sniff the delimiter from the header line.
    $delim = substr_count($allLines[0], "\t") > substr_count($allLines[0], ',') ? "\t" : ',';
    $header = str_getcsv($allLines[0], $delim);
    $lower  = array_map(fn($h) => strtolower(trim((string) $h)), $header);
    $colIdx = [];
    foreach (INFRA_RESEARCH_ELOCAL_ALIASES as $field => $aliases) {
        foreach ($lower as $i => $h) {
            if (in_array($h, $aliases, true)) { $colIdx[$field] = $i; break; }
        }
    }
    $missing = array_diff(array_keys(INFRA_RESEARCH_ELOCAL_ALIASES), array_keys($colIdx));
    if ($missing) {
        return ['rows' => [], 'errors' => [
            'Could not find column(s) for: ' . implode(', ', $missing)
            . '. Header seen: ' . implode(', ', $header),
        ]];
    }

    $rows = [];
    for ($i = 1; $i < count($allLines); $i++) {
        $cells = str_getcsv($allLines[$i], $delim);
        $city  = trim((string) ($cells[$colIdx['city']] ?? ''));
        $state = trim((string) ($cells[$colIdx['state']] ?? ''));
        if ($city === '' || $state === '') continue;
        $rows[] = [
            'city'      => $city,
            'state'     => $state,
            'buyers'    => (float) preg_replace('/[^0-9.]/', '', (string) ($cells[$colIdx['buyers']] ?? '0')),
            'price_avg' => (float) preg_replace('/[^0-9.]/', '', (string) ($cells[$colIdx['price_avg']] ?? '0')),
            'price_max' => (float) preg_replace('/[^0-9.]/', '', (string) ($cells[$colIdx['price_max']] ?? '0')),
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
 *   national_brands (counts out of top 10)
 */
function infra_research_serp_fetch(array $c, string $keyword): array
{
    $loc  = (int) ($c['location'] ?? 2840) ?: 2840;
    $lang = trim((string) ($c['language'] ?? 'en')) ?: 'en';
    $r = infra_kw_dfs_post($c, 'serp/google/organic/live/advanced', [
        'keyword' => $keyword, 'location_code' => $loc, 'language_code' => $lang,
        'device' => 'desktop', 'depth' => 10,
    ]);
    if (!$r['ok']) return ['ok' => false, 'msg' => $r['msg'], 'data' => []];

    $items = (array) ($r['result'][0]['items'] ?? []);
    $open = $local = $natl = 0;
    foreach ($items as $it) {
        if ((string) ($it['type'] ?? '') !== 'organic') continue;
        switch (infra_research_classify((string) ($it['domain'] ?? ''))) {
            case 'DIRECTORY': $open++; break;
            case 'NATIONAL':  $natl++; break;
            default:          $local++;
        }
    }
    return ['ok' => true, 'msg' => '', 'data' => [
        'open_slots' => $open, 'local_competitors' => $local, 'national_brands' => $natl,
    ]];
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
    return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

/** One row of the sheet XML. $cells is a list of ['v'=>mixed,'num'=>bool,'bold'=>bool]. */
function infra_research_xlsx_row(int $rowNum, array $cells): string
{
    $out = '<row r="' . $rowNum . '">';
    foreach ($cells as $i => $cell) {
        $col = infra_research_xlsx_col_letter($i) . $rowNum;
        $s = !empty($cell['bold']) ? ' s="1"' : '';
        if (!empty($cell['num'])) {
            $out .= '<c r="' . $col . '"' . $s . '><v>' . (is_numeric($cell['v']) ? $cell['v'] : 0) . '</v></c>';
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
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
         . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
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
 */
function infra_research_write_xlsx_file(string $path, array $sheets): void
{
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

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
        . '<fills count="1"><fill><patternFill patternType="none"/></fill></fills>'
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
    $zip->close();
}

function infra_research_write_xlsx(string $niche, array $rows): string
{
    $fname = $niche . '-city-research-' . date('Y-m-d') . '.xlsx';
    $path  = infra_research_downloads_dir() . '/' . $fname;

    $buildList = [
        ['Build #', 'City', 'State', 'Grade', 'Score', 'Population', 'Volume/mo',
         'Open Slots', 'Local Competitors', 'National Brands', 'Buyers', '1P Avg $', 'Region'],
    ];
    foreach ($rows as $i => $r) {
        $buildList[] = [
            $i + 1, (string) $r['city'], (string) $r['ss'], (string) ($r['grade'] ?? ''), (float) ($r['score'] ?? 0),
            (int) ($r['population'] ?? 0), (int) round($r['volume'] ?? 0),
            round($r['open_slots'] ?? 0, 1), round($r['local_competitors'] ?? 0, 1),
            round($r['national_brands'] ?? 0, 1), (float) ($r['buyers'] ?? 0), (float) ($r['price_avg'] ?? 0),
            infra_research_region($r['ss']),
        ];
    }

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

    infra_research_write_xlsx_file($path, ['Build List' => $buildList, 'Method' => $method]);
    return $fname;
}
