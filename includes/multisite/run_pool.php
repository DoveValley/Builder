<?php
/**
 * Bounded process pool for running many independent CLI jobs concurrently (one real
 * OS process per job, via proc_open) — e.g. one build_one.php or upload_one.php
 * invocation per domain. Extracted from run_campaign.php (its original and still only
 * caller until upload_sites.php) so a second orchestrator can reuse the exact same,
 * already-proven scheduler instead of a second copy that would inevitably drift from
 * it — see this repo's CLAUDE.md "duplicated clients do not stay duplicates" note,
 * which applies just as much to internal mechanisms as to external API clients.
 */

if (!function_exists('ms_parse_line')) {

// Parse one JSON-line of a worker's output into a metrics accumulator (mutating).
function ms_parse_line(string $line, array &$m, bool $verbose): void {
    $ev  = json_decode(trim($line), true);
    $msg = is_array($ev) ? (string) ($ev['msg'] ?? '') : '';
    // Surfaced even outside --verbose: a handful of lines per domain, not the
    // per-line firehose --verbose turns on, but exactly the detail that explains
    // *why* a photo slot was skipped (warn/error/fatal) or what a forced photo
    // regen actually did (the Images: cost/count summary) — both otherwise
    // invisible in the .out file the Simple page's log tail reads from.
    $evType = is_array($ev) ? (string) ($ev['type'] ?? '') : '';
    if ($verbose || in_array($evType, ['warn', 'error', 'fatal'], true) || str_starts_with($msg, 'Images:')) {
        echo '    ' . rtrim($line) . "\n";
    }
    if (!is_array($ev)) return;
    // A step marker: remember where the row is, and that it got this far. Kept rather
    // than discarded so a running row can say what it is doing and a failed row can say
    // where it died — see includes/multisite/steps.php.
    if (($ev['type'] ?? '') === 'step' && ($ev['step'] ?? '') !== '') {
        $m['step'] = $ev['step'];
        if (!in_array($ev['step'], $m['steps'], true)) $m['steps'][] = $ev['step'];
    }
    // Every message updates 'last', not just fatal/done — so a row still IN PROGRESS
    // can say what it is doing right now (which AI block, which landing page, …)
    // instead of showing nothing until it finishes or dies. BUT once a row has died
    // (a 'fatal' already set status to failed), stop letting later lines overwrite
    // 'last' — build_one.php always logs a routine "Cleaned up temp dirs." right
    // after every fatal exit, which was silently replacing the real error with that
    // cleanup message by the time the row finished. The actual reason was always
    // being captured correctly one level down (see build_one.php's fatal messages);
    // it was being erased here, one line later.
    if ($msg !== '' && $m['status'] !== 'failed') $m['last'] = $msg;
    if (($ev['type'] ?? '') === 'fatal') { $m['status'] = 'failed'; if ($msg !== '') $m['last'] = $msg; }
    if (preg_match('/Deploy complete — (\d+) uploaded/u', $msg, $x)) $m['uploaded'] = (int)$x[1];
    if (preg_match('/Tokens\s*:\s*([\d,]+) in \/ ([\d,]+) out/u', $msg, $x)) {
        $m['tokens_in']  = (int)str_replace(',', '', $x[1]);
        $m['tokens_out'] = (int)str_replace(',', '', $x[2]);
    }
    if (preg_match('/Est\. cost:\s*\$([0-9.]+)/u', $msg, $x)) $m['cost'] = (float)$x[1];
    // Real OpenAI image spend — a SEPARATE field from 'cost' above (Anthropic text
    // generation), never summed together: different service, different per-unit price,
    // and a row can have one without the other (e.g. no new photo this run) or both.
    if (preg_match('/Images: AI photo cost:\s*\$([0-9.]+)/u', $msg, $x)) $m['image_cost'] = (float)$x[1];
    // Upload-specific result, emitted once by upload_one.php as a single structured
    // event (not regex-sniffed from prose, unlike the fields above) — carries exactly
    // what upload_sites.php needs to reconstruct its legacy per-domain log line and
    // its post-run fleet.db note, without re-deriving them from deploy_site() itself.
    if (($ev['type'] ?? '') === 'upload_result') {
        $m['files']          = (int) ($ev['files'] ?? 0);
        $m['failed']         = (int) ($ev['failed'] ?? 0);
        $m['verify_missing'] = $ev['verify_missing'] ?? [];
        if (!empty($ev['uploaded'])) $m['uploaded'] = (int) $ev['uploaded'];
    }
}

/**
 * Run jobs through a bounded process pool. concurrency=1 is plain sequential.
 * Each job: ['domain'=>, 'cmd'=>, 'attempts'=>0]. Failed jobs are re-queued up to
 * $retries times. Returns per-row result rows.
 *
 * $onComplete, if given, formats the one line printed when a job finishes (ok or
 * retries exhausted) — receives ($job, $m, $done, $total, $durationMs) and returns
 * the string to echo (including its own trailing newline). Omitted, the original
 * "[done/total] mark domain: status (…) — last" format is used, unchanged — every
 * existing caller of this function keeps today's exact output.
 */
function ms_run_pool(array $queue, int $concurrency, int $retries, bool $verbose, ?callable $onProgress = null, ?callable $onComplete = null): array {
    // 'step' = the stage this row is in right now (or died in); 'steps' = the stages it
    // got through. Both come from build_one's step events — see ms_step_begin().
    $fresh   = fn() => ['status' => 'unknown', 'uploaded' => null, 'tokens_in' => 0, 'tokens_out' => 0, 'cost' => 0.0, 'image_cost' => 0.0, 'last' => '', 'step' => '', 'steps' => []];
    $total   = count($queue);
    $running = []; $results = []; $done = 0;

    $launch = function (array $job) use (&$running, $fresh) {
        $proc = proc_open($job['cmd'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            $running[] = ['job' => $job, 'proc' => null, 'pipes' => null, 'buf' => '', 't0' => microtime(true),
                          'm' => ['status' => 'failed', 'uploaded' => null, 'tokens_in' => 0, 'tokens_out' => 0, 'cost' => 0.0, 'image_cost' => 0.0, 'last' => 'could not spawn worker', 'step' => '', 'steps' => []], 'dead' => true];
            return;
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $running[] = ['job' => $job, 'proc' => $proc, 'pipes' => $pipes, 'buf' => '', 't0' => microtime(true), 'm' => $fresh(), 'dead' => false];
    };

    while (count($running) < $concurrency && $queue) $launch(array_shift($queue));

    while ($running) {
        $read = [];
        foreach ($running as $r) if (!$r['dead']) { $read[] = $r['pipes'][1]; $read[] = $r['pipes'][2]; }
        if ($read) { $w = $e = null; @stream_select($read, $w, $e, 1); }

        foreach ($running as $k => &$rp) {
            if (!$rp['dead']) {
                // Always drain stderr too, or a child writing >64KB to it blocks and hangs the pool.
                do { $e = fread($rp['pipes'][2], 8192); } while ($e !== false && $e !== '');
                $chunk = fread($rp['pipes'][1], 8192);
                if ($chunk !== false && $chunk !== '') $rp['buf'] .= $chunk;
                while (($p = strpos($rp['buf'], "\n")) !== false) {
                    ms_parse_line(substr($rp['buf'], 0, $p), $rp['m'], $verbose);
                    $rp['buf'] = substr($rp['buf'], $p + 1);
                }
                $st = proc_get_status($rp['proc']);
                if ($st['running']) continue;
                // drain remainder
                @stream_get_contents($rp['pipes'][2]);   // drain any remaining stderr
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
                if ($onComplete) {
                    echo $onComplete($job, $m, $done, $total, $dur);
                } else {
                    $mark  = $m['status'] === 'ok' ? '✓' : '✗';
                    // 'cost' (Anthropic text) and 'image_cost' (OpenAI photos) are two
                    // different services at two different per-unit prices — shown as two
                    // separate figures, never added into one number, same as they're kept
                    // apart everywhere else in this pipeline (ms_parse_line() above,
                    // $results below, and the totals rollup).
                    $extra = ($m['uploaded'] !== null ? " ({$m['uploaded']} up)" : '')
                           . ($m['cost'] > 0 ? sprintf(' $%.4f', $m['cost']) : '')
                           . ($m['image_cost'] > 0 ? sprintf(' + img $%.4f', $m['image_cost']) : '')
                           . ($job['attempts'] > 1 ? " [{$job['attempts']}x]" : '');
                    echo "  [{$done}/{$total}] {$mark} {$job['domain']}: {$m['status']}{$extra}" . ($m['last'] ? " — {$m['last']}" : '') . "\n";
                }
                $results[] = [
                    'domain' => $job['domain'], 'status' => $m['status'], 'attempts' => $job['attempts'],
                    'uploaded' => $m['uploaded'], 'tokens_in' => $m['tokens_in'], 'tokens_out' => $m['tokens_out'],
                    'cost' => $m['cost'], 'image_cost' => $m['image_cost'], 'duration_ms' => $dur, 'last' => $m['last'],
                    'step' => $m['step'], 'steps' => $m['steps'],
                    'files' => $m['files'] ?? null, 'failed' => $m['failed'] ?? null, 'verify_missing' => $m['verify_missing'] ?? [],
                ];
                // Reported below, once per tick, alongside whatever's still in flight.
            }
            unset($running[$k]);
        }
        unset($rp);
        $running = array_values($running);

        // Report live progress every tick, not just when a row finishes — a row still
        // IN PROGRESS is exactly the case with nothing to show otherwise (see 'last'
        // fix in ms_parse_line above). Same shape as a finished row, status='running'.
        if ($onProgress) {
            $inFlight = array_map(function (array $rp): array {
                $m = $rp['m'];
                return [
                    'domain' => $rp['job']['domain'], 'status' => 'running', 'attempts' => $rp['job']['attempts'] + 1,
                    'uploaded' => $m['uploaded'], 'tokens_in' => $m['tokens_in'], 'tokens_out' => $m['tokens_out'],
                    'cost' => $m['cost'], 'duration_ms' => (int) round((microtime(true) - $rp['t0']) * 1000),
                    'last' => $m['last'], 'step' => $m['step'], 'steps' => $m['steps'],
                ];
            }, $running);
            $onProgress($results, $total, $inFlight);
        }
        while (count($running) < $concurrency && $queue) $launch(array_shift($queue));
    }
    return $results;
}

}
