<?php
/**
 * Multisite campaign orchestrator (Phase 3).
 *
 * Runs the whole params table for a master, sequentially. Each row is processed by
 * build_one.php (a self-contained process_row) in its own subprocess — the master
 * is snapshotted ONCE up front and shared across every row via --snapshot (§5), so
 * the original is frozen for the run and never re-read per row.
 *
 * Usage:
 *   php multisite/run_campaign.php <master_id> [options]
 *     --no-ai         skip AI generation (identity + build + deploy only)
 *     --force         force AI refresh + full FTP re-upload
 *     --only=DOMAIN   process just one row
 *     --limit=N       process at most N rows
 *     --no-preflight  skip the FTP reachability pre-check
 *     --verbose       stream each row's raw progress
 *
 * Reads sites/{master}/multisite/params.csv (store it first with params_check.php).
 * Writes a run log to sites/{master}/multisite/runs/{run_id}.json.
 * Exit 0 if every processed row succeeded, 1 otherwise.
 */
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "run_campaign.php is CLI only\n"); exit(2); }

require __DIR__ . '/../config.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/multisite/params.php';
require __DIR__ . '/../includes/multisite/clone.php';

// ── Args ──────────────────────────────────────────────────────────────────────
$args      = array_slice($argv, 1);
$noAi      = in_array('--no-ai', $args, true);
$force     = in_array('--force', $args, true);
$noPre     = in_array('--no-preflight', $args, true);
$verbose   = in_array('--verbose', $args, true);
$only = ''; $limit = 0; $retries = 0;
foreach ($args as $a) {
    if (str_starts_with($a, '--only='))    $only    = substr($a, 7);
    if (str_starts_with($a, '--limit='))   $limit   = (int)substr($a, 8);
    if (str_starts_with($a, '--retries=')) $retries = max(0, (int)substr($a, 10));
}
$pos = array_values(array_filter($args, fn($a) => !str_starts_with($a, '--')));
$masterId = $pos[0] ?? '';
if ($masterId === '' || !is_dir(BASE_DIR . '/sites/' . $masterId)) {
    fwrite(STDERR, "usage: run_campaign.php <master_id> [--no-ai --force --only=DOMAIN --limit=N --no-preflight --verbose]\n");
    exit(2);
}

$msDir     = BASE_DIR . '/sites/' . $masterId . '/multisite';
$csvPath   = $msDir . '/params.csv';
if (!is_file($csvPath)) { fwrite(STDERR, "No params.csv at {$csvPath} — run params_check.php first.\n"); exit(2); }

// ── Load + validate rows ────────────────────────────────────────────────────
$parsed = ms_parse_csv($csvPath);
if ($parsed['error']) { fwrite(STDERR, 'CSV error: ' . $parsed['error'] . "\n"); exit(2); }
$v = ms_validate_rows($parsed['rows'], $parsed['header']);

$rows = [];
foreach ($v['rows'] as $r) {
    if ($r['errors']) { echo "SKIP (invalid) line {$r['line']} {$r['domain']}: " . implode('; ', $r['errors']) . "\n"; continue; }
    if ($only !== '' && strtolower($r['domain']) !== strtolower($only)) continue;
    $rows[] = $r['data'];
}
if ($limit > 0) $rows = array_slice($rows, 0, $limit);

$n = count($rows);
echo "Campaign: {$masterId}  |  rows to process: {$n}"
   . ($noAi ? '  [--no-ai]' : '') . ($force ? '  [--force]' : '') . "\n";
if ($n === 0) { echo "Nothing to do.\n"; exit(0); }

// ── FTP pre-flight (§5 R0) ────────────────────────────────────────────────────
if (!$noPre) {
    echo "Pre-flight FTP check…\n";
    foreach ($rows as $r) {
        if (($r['ftp_host'] ?? '') === '') { echo "  · {$r['domain']}: no FTP (build only)\n"; continue; }
        $pf = ms_ftp_preflight($r);
        echo '  ' . ($pf['ok'] ? '✓' : '✗') . " {$r['domain']}: {$pf['msg']}\n";
    }
}

// ── Snapshot master ONCE (shared by every row) ───────────────────────────────
$snapshotDir = sys_get_temp_dir() . '/ms_campaign_snap_' . $masterId;
echo "Snapshotting master…\n";
try { snapshot_master($masterId, $snapshotDir); }
catch (Throwable $e) { fwrite(STDERR, 'Snapshot failed: ' . $e->getMessage() . "\n"); exit(1); }

// ── Process each row via build_one.php ───────────────────────────────────────
$runId = gmdate('Ymd-His') . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
// Run build_one once; parse status + metrics (uploaded, tokens, cost) from its JSON-lines.
function ms_run_build_one(string $cmd, bool $verbose): array {
    $r = ['status' => 'unknown', 'uploaded' => null, 'tokens_in' => 0, 'tokens_out' => 0, 'cost' => 0.0, 'last' => ''];
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($proc)) { $r['status'] = 'failed'; $r['last'] = 'could not spawn build_one'; return $r; }
    while (($line = fgets($pipes[1])) !== false) {
        if ($verbose) echo '    ' . rtrim($line) . "\n";
        $ev = json_decode(trim($line), true);
        if (!is_array($ev)) continue;
        $msg = $ev['msg'] ?? '';
        if (($ev['type'] ?? '') === 'fatal') { $r['status'] = 'failed'; $r['last'] = $msg; }
        if (($ev['type'] ?? '') === 'done')  { $r['last'] = $msg; }
        if (preg_match('/Deploy complete — (\d+) uploaded/u', $msg, $m)) $r['uploaded'] = (int)$m[1];
        if (preg_match('/Tokens\s*:\s*([\d,]+) in \/ ([\d,]+) out/u', $msg, $m)) {
            $r['tokens_in']  = (int)str_replace(',', '', $m[1]);
            $r['tokens_out'] = (int)str_replace(',', '', $m[2]);
        }
        if (preg_match('/Est\. cost:\s*\$([0-9.]+)/u', $msg, $m)) $r['cost'] = (float)$m[1];
    }
    fclose($pipes[1]);
    stream_get_contents($pipes[2]); fclose($pipes[2]);
    $code = proc_close($proc);
    if ($r['status'] !== 'failed') $r['status'] = $code === 0 ? 'ok' : 'failed';
    return $r;
}

$results = [];
$i = 0;
foreach ($rows as $r) {
    $i++;
    $domain = $r['domain'];
    $rowFile = sys_get_temp_dir() . '/ms_row_' . preg_replace('/[^a-z0-9]+/i', '_', strtolower($domain)) . '.json';
    file_put_contents($rowFile, json_encode(array_merge($r, ['master_id' => $masterId])));

    $flags = ' --snapshot=' . escapeshellarg($snapshotDir);
    if ($noAi)  $flags .= ' --no-ai';
    if ($force) $flags .= ' --force';
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/build_one.php')
         . ' ' . escapeshellarg($rowFile) . $flags;

    echo "\n[{$i}/{$n}] {$domain} …\n";
    $t0 = microtime(true);
    $attempt = 0;
    while (true) {
        $attempt++;
        $res = ms_run_build_one($cmd, $verbose);
        if ($res['status'] === 'ok' || $attempt > $retries) break;
        echo "  ↻ retry {$attempt}/{$retries} after failure — {$res['last']}\n";
    }
    $durationMs = (int)round((microtime(true) - $t0) * 1000);
    @unlink($rowFile);

    $mark = $res['status'] === 'ok' ? '✓' : '✗';
    $extra = ($res['uploaded'] !== null ? " ({$res['uploaded']} uploaded)" : '')
           . ($res['cost'] > 0 ? sprintf(' $%.4f', $res['cost']) : '')
           . ($attempt > 1 ? " [{$attempt} attempts]" : '');
    echo "  {$mark} {$domain}: {$res['status']}{$extra}" . ($res['last'] ? " — {$res['last']}" : '') . "\n";
    $results[] = [
        'domain'      => $domain,
        'status'      => $res['status'],
        'attempts'    => $attempt,
        'uploaded'    => $res['uploaded'],
        'tokens_in'   => $res['tokens_in'],
        'tokens_out'  => $res['tokens_out'],
        'cost'        => $res['cost'],
        'duration_ms' => $durationMs,
        'last'        => $res['last'],
    ];
}

// ── Teardown + run log ───────────────────────────────────────────────────────
ms_delete_dir($snapshotDir);

$ok        = count(array_filter($results, fn($r) => $r['status'] === 'ok'));
$fail      = $n - $ok;
$totalCost = array_sum(array_column($results, 'cost'));
$totalIn   = array_sum(array_column($results, 'tokens_in'));
$totalOut  = array_sum(array_column($results, 'tokens_out'));
$uploaded  = array_sum(array_map(fn($r) => (int)($r['uploaded'] ?? 0), $results));

$runsDir = $msDir . '/runs';
if (!is_dir($runsDir)) mkdir($runsDir, 0775, true);
file_put_contents($runsDir . '/' . $runId . '.json', json_encode([
    'run_id'      => $runId,
    'master_id'   => $masterId,
    'finished_at' => gmdate('c'),
    'options'     => ['no_ai' => $noAi, 'force' => $force, 'only' => $only, 'limit' => $limit, 'retries' => $retries],
    'total'       => $n, 'ok' => $ok, 'failed' => $fail,
    'totals'      => [
        'files_uploaded' => $uploaded,
        'tokens_in'      => $totalIn,
        'tokens_out'     => $totalOut,
        'cost_usd'       => round($totalCost, 4),
    ],
    'results'     => $results,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

echo "\n" . str_repeat('═', 54) . "\n";
echo "Campaign done — {$ok}/{$n} ok" . ($fail ? ", {$fail} failed" : '') . ".\n";
echo sprintf("  Uploaded: %d files  |  Tokens: %s in / %s out  |  Est. cost: \$%.4f\n",
    $uploaded, number_format($totalIn), number_format($totalOut), $totalCost);
echo "  Run log: runs/{$runId}.json\n";
exit($fail > 0 ? 1 : 0);
