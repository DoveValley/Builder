<?php
/**
 * infra/cron/census_backfill.php — one-time (or occasional) Census ACS 5-Year
 * backfill for city_data.
 *
 * NOT scheduled — unlike the other files in this directory, this is run
 * manually, on demand: `php admin/infra/cron/census_backfill.php`. ACS 5-Year
 * estimates only update once a year, so there is nothing for a 2-minute (or
 * even daily) cron tick to usefully re-check. Re-run this by hand whenever a
 * new ACS vintage is worth pulling, or after `cities` gains new rows.
 *
 * Prints a real report when done — matched count, unmatched count, and the
 * actual unmatched city ids (truncated if long) — never a silent partial
 * success.
 */
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(1); }

require_once __DIR__ . '/../lib/census.php';

if (!infra_census_configured()) {
    fwrite(STDERR, "No Census API key found at admin/infra/config/census.json — nothing to do.\n");
    exit(1);
}

echo "Starting Census backfill (one call per state)...\n";
$report = infra_census_backfill(function (string $state, int $matched, int $total) {
    echo sprintf("  %-22s %4d / %4d matched\n", $state, $matched, $total);
});

echo "\n--- Report ---\n";
echo "Matched:   {$report['matched']} / {$report['total_local']}\n";
$unmatchedCount = count($report['unmatched']);
echo "Unmatched: {$unmatchedCount}\n";
if ($unmatchedCount > 0) {
    $show = array_slice($report['unmatched'], 0, 30);
    echo '  ' . implode(', ', $show) . ($unmatchedCount > 30 ? ', ... (' . ($unmatchedCount - 30) . ' more)' : '') . "\n";
}
if ($report['errors']) {
    echo "\nErrors:\n";
    foreach ($report['errors'] as $e) echo "  {$e}\n";
}
