<?php
/**
 * Multisite params intake (Phase 1).
 *
 * Parse + validate the per-site params table (one row per target site) and run a
 * cheap FTP pre-flight so bad credentials surface before any build. Pure functions,
 * CLI-testable; the admin upload page (and the Phase 3 orchestrator) wrap these.
 *
 * The columns map 1:1 to the row.json build_one.php consumes (master_id is supplied
 * as campaign context, not a column). See docs/multisite-generator-architecture.md §4.
 */

/** Columns that make a row deployable (hard errors when missing/invalid). */
const MS_REQUIRED_COLS = ['domain', 'business'];

/** Columns needed to actually deploy (warning if missing → row builds but won't deploy). */
const MS_FTP_COLS = ['ftp_host', 'ftp_user', 'ftp_pass'];

/** Columns that materially improve AI/identity quality (warning if missing). */
const MS_RECOMMENDED_COLS = ['city', 'state', 'SS', 'phone', 'email'];

/** All recognized columns (anything else is reported as "unknown column"). */
const MS_KNOWN_COLS = [
    'domain', 'business', 'phone', 'tel', 'email', 'address',
    'city', 'state', 'SS', 'zip', 'lat', 'lng', 'logo', 'analytics_id',
    'rating', 'review_count',
    'landing_cities',
    'web3forms_key', 'ftp_host', 'ftp_port', 'ftp_user', 'ftp_pass',
    'ftp_path', 'ftp_passive',
];

/**
 * Parse a params CSV into associative rows keyed by the header line.
 * @return array ['header'=>string[], 'rows'=>array[], 'error'=>?string]
 */
function ms_parse_csv(string $path): array {
    if (!is_file($path)) return ['header' => [], 'rows' => [], 'error' => "File not found: {$path}"];
    $fh = fopen($path, 'r');
    if (!$fh) return ['header' => [], 'rows' => [], 'error' => "Could not open: {$path}"];

    $header = fgetcsv($fh);
    if ($header === false) { fclose($fh); return ['header' => [], 'rows' => [], 'error' => 'Empty CSV']; }
    // Strip UTF-8 BOM from the first header cell, trim all headers.
    if (isset($header[0])) $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
    $header = array_map(fn($h) => trim((string)$h), $header);

    $rows = [];
    $lineNo = 1;
    while (($cells = fgetcsv($fh)) !== false) {
        $lineNo++;
        // Skip fully blank lines.
        if (count($cells) === 1 && trim((string)$cells[0]) === '') continue;
        $row = [];
        foreach ($header as $i => $col) {
            if ($col === '') continue;
            $row[$col] = isset($cells[$i]) ? trim((string)$cells[$i]) : '';
        }
        $row['_line'] = $lineNo;
        $rows[] = $row;
    }
    fclose($fh);
    return ['header' => $header, 'rows' => $rows, 'error' => null];
}

/** True if $d looks like a bare domain (no scheme/path). */
function ms_valid_domain(string $d): bool {
    $d = strtolower(trim($d));
    if ($d === '' || strlen($d) > 253) return false;
    return (bool)preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', $d);
}

/**
 * Validate parsed rows. Returns per-row diagnostics plus a summary.
 * @return array [
 *   'rows'    => [ ['data'=>row, 'line'=>int, 'errors'=>[], 'warnings'=>[]], ... ],
 *   'unknown_columns' => string[],
 *   'ok'      => int,   // rows with no errors
 *   'error'   => int,   // rows with >=1 error
 *   'warn'    => int,   // rows with >=1 warning (and no error)
 * ]
 */
function ms_validate_rows(array $rows, array $header = []): array {
    $unknown = array_values(array_diff(array_filter($header), MS_KNOWN_COLS));

    // Pre-scan for duplicate domains.
    $seen = [];
    foreach ($rows as $r) {
        $d = strtolower(trim($r['domain'] ?? ''));
        if ($d !== '') $seen[$d] = ($seen[$d] ?? 0) + 1;
    }

    $out = ['rows' => [], 'unknown_columns' => $unknown, 'ok' => 0, 'error' => 0, 'warn' => 0];

    foreach ($rows as $r) {
        $errors = [];
        $warnings = [];

        // Required.
        foreach (MS_REQUIRED_COLS as $c) {
            if (($r[$c] ?? '') === '') $errors[] = "missing required '{$c}'";
        }
        // Domain format + uniqueness.
        $d = strtolower(trim($r['domain'] ?? ''));
        if ($d !== '') {
            if (!ms_valid_domain($d)) $errors[] = "invalid domain '{$r['domain']}'";
            elseif (($seen[$d] ?? 0) > 1) $errors[] = "duplicate domain '{$d}'";
        }
        // FTP completeness (warning — row can still build without deploying).
        $ftpPresent = array_filter(MS_FTP_COLS, fn($c) => ($r[$c] ?? '') !== '');
        if (count($ftpPresent) > 0 && count($ftpPresent) < count(MS_FTP_COLS)) {
            $missing = array_diff(MS_FTP_COLS, $ftpPresent);
            $errors[] = 'partial FTP credentials (missing ' . implode(', ', $missing) . ')';
        } elseif (count($ftpPresent) === 0) {
            $warnings[] = 'no FTP credentials — will build but not deploy';
        }
        // Recommended.
        foreach (MS_RECOMMENDED_COLS as $c) {
            if (($r[$c] ?? '') === '') $warnings[] = "missing recommended '{$c}'";
        }
        // lat/lng sanity if provided.
        foreach (['lat', 'lng'] as $c) {
            if (($r[$c] ?? '') !== '' && !is_numeric($r[$c])) $errors[] = "non-numeric '{$c}'";
        }
        // rating / review_count: must be real + paired (both or neither) — never invent one.
        $hasRating = ($r['rating'] ?? '') !== '';
        $hasCount  = ($r['review_count'] ?? '') !== '';
        if ($hasRating !== $hasCount) {
            $errors[] = 'rating and review_count must be provided together';
        } elseif ($hasRating) {
            if (!is_numeric($r['rating']) || $r['rating'] < 0 || $r['rating'] > 5) $errors[] = "rating must be 0–5";
            if (!ctype_digit((string)$r['review_count']) || (int)$r['review_count'] < 1) $errors[] = "review_count must be a positive integer";
        }

        if ($errors)      $out['error']++;
        elseif ($warnings) $out['warn']++;
        else               $out['ok']++;

        $out['rows'][] = [
            'data'     => $r,
            'line'     => $r['_line'] ?? 0,
            'domain'   => $r['domain'] ?? '',
            'errors'   => $errors,
            'warnings' => $warnings,
        ];
    }
    return $out;
}

/**
 * Cheap FTP reachability check for one row: connect + login, no upload.
 * @return array ['ok'=>bool, 'msg'=>string]
 */
function ms_ftp_preflight(array $row, int $timeout = 15): array {
    if (!function_exists('ftp_connect')) return ['ok' => false, 'msg' => 'PHP FTP extension not available'];
    $host = preg_replace('#^ftps?://#i', '', trim($row['ftp_host'] ?? ''));
    $port = (int)($row['ftp_port'] ?? 21);
    $user = $row['ftp_user'] ?? '';
    $pass = $row['ftp_pass'] ?? '';
    if ($host === '' || $user === '' || $pass === '') return ['ok' => false, 'msg' => 'incomplete FTP credentials'];

    $conn = @ftp_connect($host, $port, $timeout);
    if (!$conn) return ['ok' => false, 'msg' => "cannot connect to {$host}:{$port}"];
    if (!@ftp_login($conn, $user, $pass)) { @ftp_close($conn); return ['ok' => false, 'msg' => 'login failed']; }
    if (!empty($row['ftp_passive'])) @ftp_pasv($conn, true);
    @ftp_close($conn);
    return ['ok' => true, 'msg' => 'ok'];
}

/**
 * Persist an uploaded/validated CSV to the campaign's params location.
 * @return string the stored path.
 */
function ms_store_params_csv(string $masterId, string $srcCsvPath): string {
    $dir = BASE_DIR . '/sites/' . $masterId . '/multisite';
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    $dest = $dir . '/params.csv';
    copy($srcCsvPath, $dest);
    return $dest;
}
