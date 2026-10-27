<?php
/**
 * "Insert or verify primary area code" — Batch Simple's real, AI-plus-web-search
 * check that each ticked domain's area_code is the true PRIMARY/ORIGINAL code for
 * its city, not an overlay (the same failure class every manual audit in this
 * project has found: see ctm_area_code_for_city()'s own history in
 * calltrackingmetrics.php).
 *
 *   php multisite/check_area_codes.php <master_id> --batch=bN --only=a.com,b.com
 *
 * Two different outcomes depending on what's already on file, same button:
 *   - area_code is BLANK and the row is NOT live: the real primary code is looked
 *     up and WRITTEN onto the row (just that one field — never phone, never any
 *     other field). This is the one and only case this script writes anything.
 *   - area_code already has something on it: read-only. A wrong code is reported,
 *     never auto-changed — same reasoning as every other audit in this project:
 *     a human decides what to do with an existing (possibly live, possibly
 *     already-working) number, this script does not act on its behalf.
 *   - area_code is blank and the row IS live: also read-only — reported as a
 *     suggestion with a pointer to Correct & Regenerate, since a live row's data
 *     only ever changes through that guarded path, never a bulk action.
 *
 * Scoped to --only, same as Research selected — never the whole batch at once.
 * Real, billed Anthropic call per city not already in the fleet-wide cache
 * (ctm_area_code_check_cache_get/_set) — a city checked once, by any batch or
 * niche, is never asked twice. Writes a JSON report the panel polls for.
 */
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "check_area_codes.php is CLI only\n"); exit(2); }

require __DIR__ . '/../config.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/multisite/params.php';
require __DIR__ . '/../includes/multisite/batch.php';
require __DIR__ . '/../includes/calltrackingmetrics.php';
require __DIR__ . '/../admin/infra/lib/state.php';

$args     = array_slice($argv, 1);
$masterId = (string) ($args[0] ?? '');
$batchId  = ''; $only = '';
foreach ($args as $a) {
    if (str_starts_with($a, '--batch=')) $batchId = substr($a, 8);
    if (str_starts_with($a, '--only='))  $only    = substr($a, 7);
}
if (!ms_valid_master_id($masterId) || !ms_batch_exists($masterId, $batchId)) {
    fwrite(STDERR, "usage: check_area_codes.php <master_id> --batch=bN --only=a.com,b.com\n");
    exit(2);
}
$domains = array_values(array_filter(array_map(fn($d) => strtolower(trim($d)), explode(',', $only))));
if (!$domains) { fwrite(STDERR, "No domains given.\n"); exit(2); }

$batchDir = ms_batch_dir($masterId, $batchId);
$csvPath  = $batchDir . '/params.csv';
$parsed   = ms_parse_csv($csvPath);
if ($parsed['error']) { fwrite(STDERR, 'CSV error: ' . $parsed['error'] . "\n"); exit(2); }

$byDomain = [];
foreach ($parsed['rows'] as $r) $byDomain[strtolower(trim((string) ($r['domain'] ?? '')))] = $r;

// PHP buffers stdout when it isn't a tty (exactly the case here — ms_launch_job
// redirects it to a .out file), so without an explicit flush every echo below
// would sit unseen until the whole script exits, defeating the entire point of
// printing progress as each city resolves. Same fix build_one.php/golive_run.php
// already use for the same reason.
function cac_say(string $line): void { echo $line; @flush(); }

$results = [];
$checked = 0; $cachedHits = 0; $mismatches = 0; $inserted = 0; $errors = 0;
// Collected here, written once at the end under one lock — never per-row, so a
// handful of ticked domains needing a fill don't burn through the params_versions/
// rolling cap with one snapshot each.
$toInsert = [];   // domain => primary_code

// ── Pass 1: resolve every domain's city/state, collect what needs a real lookup ──
$rowInfo = [];   // domain => ['city'=>,'ss'=>,'code'=>] | ['error'=>...]
$pairs   = [];
foreach ($domains as $dom) {
    $row = $byDomain[$dom] ?? null;
    if (!$row) { $rowInfo[$dom] = ['error' => "Not in this batch's target list."]; continue; }
    $city = trim((string) ($row['city'] ?? ''));
    $ss   = trim((string) ($row['SS'] ?? ''));
    $code = trim((string) ($row['area_code'] ?? ''));
    if ($city === '' || $ss === '') { $rowInfo[$dom] = ['error' => 'Missing city/state.', 'city' => $city, 'ss' => $ss, 'code' => $code]; continue; }
    $rowInfo[$dom] = ['city' => $city, 'ss' => $ss, 'code' => $code];
    $pairs[] = ['city' => $city, 'ss' => $ss];
}

// ── Pass 2: ONE batched lookup for every city not already cached — concurrent,
// not sequential, so N new cities take as long as the slowest one, not the sum.
// Duplicate cities (several domains, same city) are deduped inside this call too.
// The callback prints the MOMENT each city resolves (cached ones instantly, real
// lookups as each one's own call finishes) — without it, this whole pass would be
// silent for however long the slowest city's real web search takes.
if ($pairs) cac_say('Looking up ' . count($pairs) . " domain(s)' cities (cache + web search where needed)…\n");
$cityResults = ctm_verify_area_codes_many($pairs, function (string $city, string $ss, array $result) {
    if ($result['cached']) {
        cac_say("  [cached] {$city}, {$ss}: {$result['primary_code']}\n");
    } elseif ($result['checked']) {
        cac_say("  ✓ {$city}, {$ss}: {$result['primary_code']}\n");
    } else {
        cac_say("  ✗ {$city}, {$ss}: {$result['error']}\n");
    }
});
cac_say("\n");

// ── Pass 3: apply the per-domain decision now that every city's answer is in hand.
foreach ($domains as $dom) {
    $info = $rowInfo[$dom];
    if (isset($info['error']) && !isset($info['city'])) {
        cac_say("  {$dom}: not in this batch's target list — skipped\n");
        $results[] = ['domain' => $dom, 'action' => 'error', 'error' => $info['error']];
        continue;
    }
    $city = $info['city']; $ss = $info['ss']; $code = $info['code'];
    if (isset($info['error'])) {
        cac_say("  {$dom}: missing city/state on file — skipped\n");
        $results[] = ['domain' => $dom, 'city' => $city, 'ss' => $ss, 'on_file' => $code, 'action' => 'error', 'error' => $info['error']];
        continue;
    }

    $key = strtolower($city) . '|' . strtolower($ss);
    $r = $cityResults[$key] ?? ['checked' => false, 'error' => 'Internal error — no result for this city.'];
    if (!$r['checked']) {
        $errors++;
        cac_say("  {$dom} ({$city}, {$ss}): check failed — {$r['error']}\n");
        $results[] = ['domain' => $dom, 'city' => $city, 'ss' => $ss, 'on_file' => $code, 'action' => 'error', 'error' => $r['error']];
        continue;
    }
    $checked++;
    if ($r['cached']) $cachedHits++;
    $primary = $r['primary_code'];
    $tag = $r['cached'] ? ' [cached]' : '';

    if ($code === '') {
        $rec  = infra_state_get_domain($dom);
        $live = $rec && ($rec['status'] ?? '') === 'live';
        if ($live) {
            cac_say("  {$dom} ({$city}, {$ss}): blank, LIVE — suggested {$primary}, use Correct & Regenerate{$tag}\n");
            $results[] = ['domain' => $dom, 'city' => $city, 'ss' => $ss, 'on_file' => '', 'action' => 'suggested',
                           'correct_code' => $primary, 'evidence' => $r['evidence'], 'error' => ''];
        } else {
            $toInsert[$dom] = $primary;
            $inserted++;
            cac_say("  {$dom} ({$city}, {$ss}): blank — INSERTED {$primary}{$tag}\n");
            $results[] = ['domain' => $dom, 'city' => $city, 'ss' => $ss, 'on_file' => '', 'action' => 'inserted',
                           'correct_code' => $primary, 'evidence' => $r['evidence'], 'error' => ''];
        }
    } elseif ($code === $primary) {
        cac_say("  {$dom} ({$city}, {$ss}): on file {$code} — CORRECT{$tag}\n");
        $results[] = ['domain' => $dom, 'city' => $city, 'ss' => $ss, 'on_file' => $code, 'action' => 'correct',
                       'correct_code' => $primary, 'evidence' => $r['evidence'], 'error' => ''];
    } else {
        $mismatches++;
        cac_say("  {$dom} ({$city}, {$ss}): on file {$code} — WRONG, should be {$primary}{$tag}\n");
        $results[] = ['domain' => $dom, 'city' => $city, 'ss' => $ss, 'on_file' => $code, 'action' => 'wrong',
                       'correct_code' => $primary, 'evidence' => $r['evidence'], 'error' => ''];
    }
}

// Commit any inserts — re-read fresh under the lock so a concurrent panel edit to
// an unrelated row in this same params.csv can't be clobbered by the row set this
// script loaded minutes ago at the top.
if ($toInsert) {
    ms_with_launch_lock($csvPath . '.lock', function () use ($csvPath, $batchDir, $toInsert) {
        $fresh = ms_parse_csv($csvPath);
        if ($fresh['error']) { fwrite(STDERR, "Could not re-read params.csv to save inserts: {$fresh['error']}\n"); return; }
        $changed = false;
        foreach ($fresh['rows'] as &$fr) {
            $d = strtolower(trim((string) ($fr['domain'] ?? '')));
            // Still blank now — don't overwrite a value someone else just typed in
            // the moments between this script's first read and this lock.
            if (isset($toInsert[$d]) && trim((string) ($fr['area_code'] ?? '')) === '') {
                $fr['area_code'] = $toInsert[$d];
                $changed = true;
            }
        }
        unset($fr);
        if (!$changed) return;
        $tmp = $csvPath . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (ms_write_csv($tmp, $fresh['header'], $fresh['rows'])) {
            ms_store_params_csv($batchDir, $tmp);
        }
        @unlink($tmp);
    });
}

cac_say("\n{$checked} checked ({$cachedHits} already cached), {$inserted} inserted, {$mismatches} mismatch(es) found, {$errors} error(s).\n");

$report = [
    'checked_at' => gmdate('c'),
    'master_id'  => $masterId,
    'batch_id'   => $batchId,
    'results'    => $results,
];
file_put_contents($batchDir . '/area_code_check_report.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

exit($errors > 0 && $checked === 0 ? 1 : 0);
