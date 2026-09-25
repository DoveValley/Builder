<?php
/**
 * Dry run for the CTM "nice number" picker (includes/calltrackingmetrics.php).
 * Calls ctm_pool_numbers() -- the exact function the real buy path
 * (ctm_get_number_for_domain()) uses -- and prints the pooled, ranked
 * result. Never calls ctm_buy_number(). Confirms, before any real purchase:
 * how many searches it took to exhaust this area code's inventory, how many
 * unique numbers it found, and which one would actually get bought.
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

echo "Pooling area code {$areaCode} under account {$accountId} (no purchase will be made)...\n";

$pooled = ctm_pool_numbers($accountId, $areaCode);
if (!$pooled['ok']) { fwrite(STDERR, "Pool failed: {$pooled['error']}\n"); exit(1); }

$numbers = $pooled['numbers'];
echo "\n=== Pooled " . count($numbers) . " unique number(s) across {$pooled['attempts']} search(es) ===\n";

if (!$numbers) {
    echo "(none available)\n";
    exit(0);
}

usort($numbers, fn($a, $b) => ctm_number_pattern_score(ctm_dry_run_phone($b) ?? '')
    <=> ctm_number_pattern_score(ctm_dry_run_phone($a) ?? ''));

foreach ($numbers as $i => $n) {
    $phone = ctm_dry_run_phone($n) ?? '(no number field)';
    $score = ctm_number_pattern_score($phone);
    printf("%2d. %-16s score=%d%s\n", $i + 1, $phone, $score, $i === 0 ? '  <- would buy' : '');
}

echo "\nNo numbers were purchased by this script.\n";
