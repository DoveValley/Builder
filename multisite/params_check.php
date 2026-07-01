<?php
/**
 * Multisite params intake CLI (Phase 1).
 *
 * Parse + validate a params CSV for a campaign, optionally FTP pre-flight each row,
 * print a report, and (unless --dry-run) store it at
 * sites/{master}/multisite/params.csv.
 *
 * Usage:
 *   php multisite/params_check.php <master_id> <params.csv> [--preflight] [--dry-run]
 *
 * Exit: 0 if every row is error-free, 1 if any row has errors, 2 on usage/parse error.
 */
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "params_check.php is CLI only\n"); exit(2); }

require __DIR__ . '/../config.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/multisite/params.php';

$args = array_slice($argv, 1);
$preflight = in_array('--preflight', $args, true);
$dryRun    = in_array('--dry-run', $args, true);
$pos = array_values(array_filter($args, fn($a) => !str_starts_with($a, '--')));
$masterId = $pos[0] ?? '';
$csvPath  = $pos[1] ?? '';

if ($masterId === '' || $csvPath === '') {
    fwrite(STDERR, "usage: params_check.php <master_id> <params.csv> [--preflight] [--dry-run]\n");
    exit(2);
}
if (!is_dir(BASE_DIR . '/sites/' . $masterId)) {
    fwrite(STDERR, "master site not found: {$masterId}\n");
    exit(2);
}

$parsed = ms_parse_csv($csvPath);
if ($parsed['error']) { fwrite(STDERR, 'CSV error: ' . $parsed['error'] . "\n"); exit(2); }

$v = ms_validate_rows($parsed['rows'], $parsed['header']);
$n = count($v['rows']);

echo "Campaign master : {$masterId}\n";
echo "CSV             : {$csvPath}\n";
echo "Rows            : {$n}  (ok={$v['ok']}  warn={$v['warn']}  error={$v['error']})\n";
if ($v['unknown_columns']) echo 'Unknown columns : ' . implode(', ', $v['unknown_columns']) . "\n";
echo str_repeat('─', 72) . "\n";

foreach ($v['rows'] as $r) {
    $status = $r['errors'] ? 'ERROR' : ($r['warnings'] ? 'warn ' : 'ok   ');
    $domain = $r['domain'] !== '' ? $r['domain'] : '(no domain)';
    printf("[%s] line %-3d %s\n", $status, $r['line'], $domain);
    foreach ($r['errors']   as $e) echo "         ✗ {$e}\n";
    foreach ($r['warnings'] as $w) echo "         · {$w}\n";

    if ($preflight && !$r['errors'] && ($r['data']['ftp_host'] ?? '') !== '') {
        $pf = ms_ftp_preflight($r['data']);
        echo '         FTP: ' . ($pf['ok'] ? "✓ {$pf['msg']}" : "✗ {$pf['msg']}") . "\n";
    }
}
echo str_repeat('─', 72) . "\n";

if (!$dryRun) {
    if ($v['error'] > 0) {
        echo "Not stored — fix {$v['error']} row error(s) first (or re-run with a corrected CSV).\n";
    } else {
        $dest = ms_store_params_csv($masterId, $csvPath);
        echo "Stored → {$dest}\n";
    }
} else {
    echo "(--dry-run: not stored)\n";
}

exit($v['error'] > 0 ? 1 : 0);
