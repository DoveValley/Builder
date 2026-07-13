<?php
/**
 * Recovery plugin — per-INTERSECTION AI enrichment (CLI, background-friendly).
 *
 *   MULTISITE_SITE_BASE=/var/www/homepage-builder-new/sites/recovery-site \
 *   php plugins/recovery/enrich_intersections_cli.php [--state=california] [--refresh]
 *
 * Generates a UNIQUE bundle for each state×carrier and city×carrier page, so the bulk
 * of the matrix (the 1,160 city×company pages) stops being stitched from shared
 * carrier + city fragments. Stored in recovery/ai_intersections.json keyed by
 * "{state}/{company}" and "{state}/{city}/{company}". recovery_ai_sources() prefers
 * these when present and falls back to composed fragments otherwise.
 *
 * --state limits to one state slug (pilot). Omit to run all four. Incremental save per
 * bundle, 429 backoff, paced, idempotent (skips existing unless --refresh).
 */
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(2); }
require __DIR__ . '/../../config.php';
require __DIR__ . '/../../includes/functions.php';
if (ACTIVE_SITE_ID !== 'recovery-site') { fwrite(STDERR, "Set MULTISITE_SITE_BASE to the recovery-site dir.\n"); exit(2); }
require_once __DIR__ . '/enrich.php';

$refresh   = in_array('--refresh', $argv, true);
$onlyState = '';
$conc      = 6;   // concurrent requests (curl_multi); --parallel=1 forces sequential
$outName   = 'ai_intersections.json';   // --out= lets each parallel PROCESS write its own shard file (no races)
foreach ($argv as $a) {
    if (strpos($a, '--state=') === 0)    $onlyState = substr($a, 8);
    if (strpos($a, '--parallel=') === 0) $conc = max(1, (int) substr($a, 11));
    if (strpos($a, '--out=') === 0)      $outName = preg_replace('/[^a-z0-9._-]/i', '', substr($a, 6)) ?: $outName;
}

$dir = ACTIVE_SITE_DIR . '/data/recovery/';
$log = fopen('/tmp/enrich_' . pathinfo($outName, PATHINFO_FILENAME) . '.log', 'w');

$states   = json_decode(file_get_contents($dir . 'states.json'), true) ?: [];
$cities   = json_decode(file_get_contents($dir . 'cities.json'), true) ?: [];
$carriers = json_decode(file_get_contents($dir . 'carriers.json'), true) ?: [];
$ai = is_file($dir . $outName) ? (json_decode(file_get_contents($dir . $outName), true) ?: []) : [];

$ss = []; foreach ($states as $s) $ss[$s['slug']] = ['name' => $s['name'], 'ss' => $s['ss'] ?? ''];

function _geni($carrier, $city, $ssAbbr, $stateName, $log) {
    for ($t = 0; $t < 4; $t++) {
        $r = recovery_ai_generate_intersection($carrier, $city, $ssAbbr, $stateName);
        if (is_array($r) && !empty($r['__rate__'])) { fwrite($log, "  rate-limited; backoff\n"); fflush($log); sleep(20 * ($t + 1)); continue; }
        return $r;
    }
    return null;
}

// Build the work list: state×carrier first (fewer), then city×carrier.
$jobs = [];
foreach ($states as $s) {
    $st = $s['slug'];
    if ($onlyState !== '' && $st !== $onlyState) continue;
    foreach ($carriers as $c) {
        $jobs[] = ['key' => "$st/{$c['slug']}", 'carrier' => $c['name'], 'city' => '', 'ss' => $s['ss'] ?? '', 'state' => $s['name']];
    }
}
foreach ($cities as $ci) {
    $st = $ci['state'] ?? '';
    if ($onlyState !== '' && $st !== $onlyState) continue;
    if (!isset($ss[$st])) continue;
    foreach ($carriers as $c) {
        $jobs[] = ['key' => "$st/{$ci['slug']}/{$c['slug']}", 'carrier' => $c['name'], 'city' => $ci['name'], 'ss' => $ss[$st]['ss'], 'state' => $ss[$st]['name']];
    }
}

// Pending = jobs without an existing bundle (unless --refresh).
$pending = [];
foreach ($jobs as $j) {
    if (!$refresh && !empty($ai[$j['key']]['intro_html'])) continue;
    $pending[$j['key']] = $j;
}
$total = count($jobs); $todo = count($pending); $ok = 0; $skip = $total - $todo;
$save = function () use (&$ai, $dir, $outName) {
    file_put_contents($dir . $outName, json_encode($ai, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
};
fwrite($log, "START jobs=$total todo=$todo state=" . ($onlyState ?: 'ALL') . " parallel=$conc\n"); fflush($log);

if ($conc <= 1) {
    // ── Sequential fallback ──
    $n = 0;
    foreach ($pending as $key => $j) {
        $n++;
        $b = _geni($j['carrier'], $j['city'], $j['ss'], $j['state'], $log);
        if ($b && !empty($b['intro_html'])) { $ai[$key] = $b; $ok++; $save(); fwrite($log, "OK   [$n/$todo] $key\n"); }
        else fwrite($log, "FAIL [$n/$todo] $key\n");
        fflush($log); usleep(400000);
    }
} else {
    // ── Parallel (curl_multi), chunked with a save after every chunk (crash-resilient),
    //    then up to 4 retry passes for rate-limited/failed items. ──
    $CHUNK = max($conc, 24);
    $remaining = $pending;   // key => job
    for ($pass = 1; $pass <= 4 && $remaining; $pass++) {
        fwrite($log, "PASS $pass — " . count($remaining) . " requests (conc=$conc, chunk=$CHUNK)\n"); fflush($log);
        $next = []; $rate = 0; $keys = array_keys($remaining);
        foreach (array_chunk($keys, $CHUNK) as $ci => $chunkKeys) {
            $prompts = [];
            foreach ($chunkKeys as $key) { $j = $remaining[$key]; $prompts[$key] = recovery_ai_prompt_intersection($j['carrier'], $j['city'], $j['ss'], $j['state']); }
            $parsed = recovery_ai_parse_intersections(recovery_ai_call_many($prompts, $conc));
            foreach ($parsed as $key => $b) {
                if (is_array($b) && !empty($b['intro_html'])) { $ai[$key] = $b; $ok++; }
                elseif ($b === '__RATE__') { $next[$key] = $remaining[$key]; $rate++; }
                else { $next[$key] = $remaining[$key]; }   // transient/null → retry next pass
            }
            $save();   // incremental — survives a mid-run crash
            fwrite($log, "  chunk " . ($ci + 1) . ": ok_total=$ok\n"); fflush($log);
        }
        fwrite($log, "  pass $pass done: ok_total=$ok remaining=" . count($next) . " (rate-limited=$rate)\n"); fflush($log);
        $remaining = $next;
        if ($remaining && $rate) sleep(15 * $pass);   // back off only if we actually got throttled
    }
    if ($remaining) foreach ($remaining as $key => $j) fwrite($log, "FAIL (after retries) $key\n");
}
exec('chown -R www-data:www-data ' . escapeshellarg(rtrim($dir, '/')));
fwrite($log, "\nDONE ok=$ok skip=$skip fail=" . ($total - $ok - $skip) . " total=$total\n");
fclose($log);
