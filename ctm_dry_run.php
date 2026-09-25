<?php
/**
 * Dry run for the CTM "nice number" picker (includes/calltrackingmetrics.php).
 * Searches an area code TWICE and prints the pattern-score ranking each time —
 * never calls ctm_buy_number(). Two things this answers before any real
 * purchase happens: (1) does the scoring/sort actually surface good numbers
 * out of CTM's batch, and (2) does calling search again return a fresh batch
 * or the identical list (answers whether "ask for more" is even a real lever
 * on this endpoint).
 *
 * Usage: php ctm_dry_run.php <account_id> <area_code>
 */

require __DIR__ . '/config.php';
require __DIR__ . '/includes/calltrackingmetrics.php';

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

$accountId = $argv[1] ?? '';
$areaCode  = $argv[2] ?? '';

if ($accountId === '' || $areaCode === '') {
    fwrite(STDERR, "Usage: php ctm_dry_run.php <account_id> <area_code>\n");
    exit(1);
}

if (!ctm_configured()) {
    fwrite(STDERR, "CTM_ACCESS_KEY/CTM_SECRET_KEY are not configured in config.php.\n");
    exit(1);
}

function ctm_dry_run_phone(array $n): ?string
{
    return $n['phone_number'] ?? $n['number'] ?? null;
}

function ctm_dry_run_print(array $numbers, string $label): void
{
    echo "\n=== {$label} (" . count($numbers) . " results) ===\n";
    if (!$numbers) { echo "(none)\n"; return; }

    usort($numbers, fn($a, $b) => ctm_number_pattern_score(ctm_dry_run_phone($b) ?? '')
        <=> ctm_number_pattern_score(ctm_dry_run_phone($a) ?? ''));

    foreach ($numbers as $i => $n) {
        $phone = ctm_dry_run_phone($n) ?? '(no number field)';
        $score = ctm_number_pattern_score($phone);
        printf("%2d. %-16s score=%d%s\n", $i + 1, $phone, $score, $i === 0 ? '  <- would buy' : '');
    }
}

echo "Searching area code {$areaCode} under account {$accountId} (no purchase will be made)...\n";

$first = ctm_search_numbers($accountId, $areaCode);
if (!$first['ok']) { fwrite(STDERR, "Search 1 failed: {$first['error']}\n"); exit(1); }
ctm_dry_run_print($first['numbers'], 'Call 1');

sleep(1);

$second = ctm_search_numbers($accountId, $areaCode);
if (!$second['ok']) { fwrite(STDERR, "Search 2 failed: {$second['error']}\n"); exit(1); }
ctm_dry_run_print($second['numbers'], 'Call 2');

$set1 = array_map('ctm_dry_run_phone', $first['numbers']);
$set2 = array_map('ctm_dry_run_phone', $second['numbers']);
$overlap = count(array_intersect($set1, $set2));

echo "\n=== Comparison ===\n";
if ($set1 === $set2) {
    echo "Identical batch both calls — re-querying search.json does not surface new numbers; the first response IS the full available set.\n";
} else {
    echo "Different batch: {$overlap} numbers overlap out of " . count($set1) . " (call 1) / " . count($set2) . " (call 2).\n";
}

echo "\nNo numbers were purchased by this script.\n";
