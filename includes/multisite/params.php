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
    'city', 'state', 'SS', 'zip', 'lat', 'lng', 'logo', 'analytics_id', 'gsc_verification',
    'rating', 'review_count',
    'landing_cities', 'theme_preset',
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
 * Persist an uploaded/validated CSV to the campaign's params location, and keep a
 * rolling snapshot of the last few uploads in params_versions/.
 * @return string the stored path.
 */
function ms_store_params_csv(string $masterId, string $srcCsvPath): string {
    $dir = BASE_DIR . '/sites/' . $masterId . '/multisite';
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    $dest = $dir . '/params.csv';
    copy($srcCsvPath, $dest);
    $ver = ms_snapshot_params_version($dir, $srcCsvPath);
    if ($ver !== '') @file_put_contents($dir . '/params.version', $ver);   // pointer to the current version
    return $dest;
}

/**
 * Sentinel written into the ftp_pass column on export. On re-upload it is swapped
 * back for the stored real password (matched by domain) — so you can download →
 * edit → re-upload without ever exposing (or having to retype) FTP passwords.
 */
const MS_PASS_MASK = '__KEEP__';

/** Write associative rows back out as CSV in the given header's column order. */
function ms_write_csv(string $path, array $header, array $rows): bool {
    $cols = array_values(array_filter(array_map(fn($h) => trim((string)$h), $header), fn($c) => $c !== ''));
    if (!$cols) return false;
    $fh = fopen($path, 'w');
    if (!$fh) return false;
    fputcsv($fh, $cols);
    foreach ($rows as $r) {
        $line = [];
        foreach ($cols as $c) $line[] = (string)($r[$c] ?? '');
        fputcsv($fh, $line);
    }
    fclose($fh);
    return true;
}

/** Return rows with every non-empty ftp_pass replaced by the mask sentinel. */
function ms_mask_ftp_pass(array $rows): array {
    foreach ($rows as &$r) {
        if (($r['ftp_pass'] ?? '') !== '') $r['ftp_pass'] = MS_PASS_MASK;
    }
    unset($r);
    return $rows;
}

/**
 * Swap the mask sentinel back for the real password from the stored table
 * (matched by domain). An unmatched sentinel becomes '' — which fails validation
 * as partial FTP, so a genuinely new row must supply its own password.
 */
function ms_rehydrate_ftp_pass(array $rows, string $storedCsvPath): array {
    $map = [];
    if (is_file($storedCsvPath)) {
        $p = ms_parse_csv($storedCsvPath);
        foreach ($p['rows'] as $sr) {
            $d = strtolower(trim((string)($sr['domain'] ?? '')));
            if ($d !== '' && ($sr['ftp_pass'] ?? '') !== '') $map[$d] = $sr['ftp_pass'];
        }
    }
    foreach ($rows as &$r) {
        if (($r['ftp_pass'] ?? '') === MS_PASS_MASK) {
            $d = strtolower(trim((string)($r['domain'] ?? '')));
            $r['ftp_pass'] = $map[$d] ?? '';
        }
    }
    unset($r);
    return $rows;
}

/**
 * Snapshot the just-uploaded CSV into params_versions/ and prune to the newest $keep.
 * @return string the new version id, or '' on failure.
 */
function ms_snapshot_params_version(string $msDir, string $srcCsvPath, int $keep = 15): string {
    $vdir = $msDir . '/params_versions';
    if (!is_dir($vdir) && !@mkdir($vdir, 0775, true) && !is_dir($vdir)) return '';
    $stamp = gmdate('Ymd-His') . '-' . substr(bin2hex(random_bytes(2)), 0, 4);
    if (!@copy($srcCsvPath, $vdir . '/' . $stamp . '.csv')) return '';
    $files = glob($vdir . '/*.csv') ?: [];
    if (count($files) > $keep) {
        sort($files);   // timestamp-prefixed names sort chronologically
        foreach (array_slice($files, 0, count($files) - $keep) as $old) @unlink($old);
    }
    return $stamp;
}

/** The version id of the currently-stored params.csv (last upload/restore), or ''. */
function ms_current_params_version(string $masterId): string {
    $f = BASE_DIR . '/sites/' . $masterId . '/multisite/params.version';
    if (!is_file($f)) return '';
    $id = trim((string)file_get_contents($f));
    return ms_valid_version_id($id) ? $id : '';
}

/** List stored param versions, newest first: [['id'=>stamp,'rows'=>int,'mtime'=>int], ...]. */
function ms_list_params_versions(string $masterId): array {
    $vdir = BASE_DIR . '/sites/' . $masterId . '/multisite/params_versions';
    $files = glob($vdir . '/*.csv') ?: [];
    rsort($files);
    $out = [];
    foreach ($files as $f) {
        $p = ms_parse_csv($f);
        $out[] = ['id' => basename($f, '.csv'), 'rows' => count($p['rows']), 'mtime' => filemtime($f) ?: 0];
    }
    return $out;
}

/** Validate a version id against path traversal (matches the snapshot stamp format). */
function ms_valid_version_id(string $id): bool {
    return (bool)preg_match('/^\d{8}-\d{6}-[0-9a-f]{4}$/', $id);
}

/** Collapse empty-location artifacts left when {city_state}/{city}/{SS} resolve to ''. */
function ms_clean_title(string $t): string {
    $t = preg_replace('/,\s*\|/u', ' |', $t);      // ", |"  (city present, SS empty via "{city}, {SS}")
    $t = preg_replace('/\|\s*\|/u', '|', $t);       // "| |"  (both empty)
    $t = preg_replace('/\s{2,}/u', ' ', $t);        // runs of spaces
    $t = preg_replace('/^\s*\|\s*|\s*\|\s*$/u', '', $t);   // dangling leading/trailing pipe
    return trim($t, " \t|,");
}

/** Render a generation pattern against one params row + the page's primary keyword. */
function ms_render_pattern(string $pattern, array $row, string $primaryKeyword): string {
    $city = $row['city'] ?? ''; $SS = $row['SS'] ?? ''; $state = $row['state'] ?? '';
    $business = $row['business'] ?? '';
    $city_state = ($city && $SS) ? ($city . ', ' . $SS) : ($city . $SS);
    $t = str_replace(
        ['{primary_keyword}', '{service}', '{business}', '{city_state}', '{city}', '{SS}', '{state}'],
        [$primaryKeyword,     $primaryKeyword, $business, $city_state,   $city,   $SS,   $state],
        $pattern
    );
    return ms_clean_title($t);
}
