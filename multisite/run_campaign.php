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
$only = ''; $limit = 0; $retries = 0; $jobs = 1;
foreach ($args as $a) {
    if (str_starts_with($a, '--only='))    $only    = substr($a, 7);
    if (str_starts_with($a, '--limit='))   $limit   = (int)substr($a, 8);
    if (str_starts_with($a, '--retries=')) $retries = max(0, (int)substr($a, 10));
    if (str_starts_with($a, '--jobs='))    $jobs    = max(1, (int)substr($a, 7));
}
$pos = array_values(array_filter($args, fn($a) => !str_starts_with($a, '--')));
$masterId = $pos[0] ?? '';
if ($masterId === '' || !is_dir(BASE_DIR . '/sites/' . $masterId)) {
    fwrite(STDERR, "usage: run_campaign.php <master_id> [--no-ai --force --jobs=N --retries=N --only=DOMAIN --limit=N --no-preflight --verbose]\n");
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

// Parse one JSON-line of build_one output into a metrics accumulator (mutating).
function ms_parse_line(string $line, array &$m, bool $verbose): void {
    if ($verbose) echo '    ' . rtrim($line) . "\n";
    $ev = json_decode(trim($line), true);
    if (!is_array($ev)) return;
    $msg = $ev['msg'] ?? '';
    if (($ev['type'] ?? '') === 'fatal') { $m['status'] = 'failed'; $m['last'] = $msg; }
    if (($ev['type'] ?? '') === 'done')  { $m['last'] = $msg; }
    if (preg_match('/Deploy complete — (\d+) uploaded/u', $msg, $x)) $m['uploaded'] = (int)$x[1];
    if (preg_match('/Tokens\s*:\s*([\d,]+) in \/ ([\d,]+) out/u', $msg, $x)) {
        $m['tokens_in']  = (int)str_replace(',', '', $x[1]);
        $m['tokens_out'] = (int)str_replace(',', '', $x[2]);
    }
    if (preg_match('/Est\. cost:\s*\$([0-9.]+)/u', $msg, $x)) $m['cost'] = (float)$x[1];
}

/**
 * Run jobs through a bounded process pool. concurrency=1 is plain sequential.
 * Each job: ['domain'=>, 'cmd'=>, 'attempts'=>0]. Failed jobs are re-queued up to
 * $retries times. Returns per-row result rows.
 */
function ms_run_pool(array $queue, int $concurrency, int $retries, bool $verbose): array {
    $fresh   = fn() => ['status' => 'unknown', 'uploaded' => null, 'tokens_in' => 0, 'tokens_out' => 0, 'cost' => 0.0, 'last' => ''];
    $total   = count($queue);
    $running = []; $results = []; $done = 0;

    $launch = function (array $job) use (&$running, $fresh) {
        $proc = proc_open($job['cmd'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            $running[] = ['job' => $job, 'proc' => null, 'pipes' => null, 'buf' => '', 't0' => microtime(true),
                          'm' => ['status' => 'failed', 'uploaded' => null, 'tokens_in' => 0, 'tokens_out' => 0, 'cost' => 0.0, 'last' => 'could not spawn build_one'], 'dead' => true];
            return;
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $running[] = ['job' => $job, 'proc' => $proc, 'pipes' => $pipes, 'buf' => '', 't0' => microtime(true), 'm' => $fresh(), 'dead' => false];
    };

    while (count($running) < $concurrency && $queue) $launch(array_shift($queue));

    while ($running) {
        $read = [];
        foreach ($running as $r) if (!$r['dead']) $read[] = $r['pipes'][1];
        if ($read) { $w = $e = null; @stream_select($read, $w, $e, 1); }

        foreach ($running as $k => &$rp) {
            if (!$rp['dead']) {
                $chunk = fread($rp['pipes'][1], 8192);
                if ($chunk !== false && $chunk !== '') $rp['buf'] .= $chunk;
                while (($p = strpos($rp['buf'], "\n")) !== false) {
                    ms_parse_line(substr($rp['buf'], 0, $p), $rp['m'], $verbose);
                    $rp['buf'] = substr($rp['buf'], $p + 1);
                }
                $st = proc_get_status($rp['proc']);
                if ($st['running']) continue;
                // drain remainder
                $rest = stream_get_contents($rp['pipes'][1]); if ($rest !== false && $rest !== '') $rp['buf'] .= $rest;
                while (($p = strpos($rp['buf'], "\n")) !== false) {
                    ms_parse_line(substr($rp['buf'], 0, $p), $rp['m'], $verbose);
                    $rp['buf'] = substr($rp['buf'], $p + 1);
                }
                if (trim($rp['buf']) !== '') ms_parse_line($rp['buf'], $rp['m'], $verbose);
                fclose($rp['pipes'][1]); @fclose($rp['pipes'][2]);
                $code = $st['exitcode'];
                proc_close($rp['proc']);
                if ($rp['m']['status'] !== 'failed') $rp['m']['status'] = $code === 0 ? 'ok' : 'failed';
            }

            $m   = $rp['m'];
            $job = $rp['job']; $job['attempts']++;
            $dur = (int)round((microtime(true) - $rp['t0']) * 1000);

            if ($m['status'] === 'failed' && $job['attempts'] <= $retries) {
                echo "  ↻ {$job['domain']}: retry {$job['attempts']}/{$retries} — {$m['last']}\n";
                $queue[] = $job;                       // re-enqueue for another attempt
            } else {
                $done++;
                $mark  = $m['status'] === 'ok' ? '✓' : '✗';
                $extra = ($m['uploaded'] !== null ? " ({$m['uploaded']} up)" : '')
                       . ($m['cost'] > 0 ? sprintf(' $%.4f', $m['cost']) : '')
                       . ($job['attempts'] > 1 ? " [{$job['attempts']}x]" : '');
                echo "  [{$done}/{$total}] {$mark} {$job['domain']}: {$m['status']}{$extra}" . ($m['last'] ? " — {$m['last']}" : '') . "\n";
                $results[] = [
                    'domain' => $job['domain'], 'status' => $m['status'], 'attempts' => $job['attempts'],
                    'uploaded' => $m['uploaded'], 'tokens_in' => $m['tokens_in'], 'tokens_out' => $m['tokens_out'],
                    'cost' => $m['cost'], 'duration_ms' => $dur, 'last' => $m['last'],
                ];
            }
            unset($running[$k]);
        }
        unset($rp);
        $running = array_values($running);
        while (count($running) < $concurrency && $queue) $launch(array_shift($queue));
    }
    return $results;
}

// Build the job list (one temp row file + build_one command per row).
$flagsCommon = ' --snapshot=' . escapeshellarg($snapshotDir) . ($noAi ? ' --no-ai' : '') . ($force ? ' --force' : '');
$jobList = []; $rowFiles = [];
foreach ($rows as $r) {
    $domain  = $r['domain'];
    $rowFile = sys_get_temp_dir() . '/ms_row_' . preg_replace('/[^a-z0-9]+/i', '_', strtolower($domain)) . '.json';
    file_put_contents($rowFile, json_encode(array_merge($r, ['master_id' => $masterId])));
    $rowFiles[] = $rowFile;
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/build_one.php')
         . ' ' . escapeshellarg($rowFile) . $flagsCommon;
    $jobList[] = ['domain' => $domain, 'cmd' => $cmd, 'attempts' => 0];
}

echo ($jobs > 1 ? "Running {$jobs} at a time…\n" : "Running sequentially…\n");
$results = ms_run_pool($jobList, $jobs, $retries, $verbose);
foreach ($rowFiles as $rf) @unlink($rf);

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
    'options'     => ['no_ai' => $noAi, 'force' => $force, 'only' => $only, 'limit' => $limit, 'retries' => $retries, 'jobs' => $jobs],
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
