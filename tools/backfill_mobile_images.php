<?php
/**
 * One-time backfill: write a "-mobile.webp" sibling for every EXISTING photo in each
 * site's upload tree, fleet-wide. Going forward, img_write_mobile_variant()
 * (includes/media_lib.php) does this automatically on every new upload/crop/
 * AI-generate — this script exists only so existing photos don't have to wait for a
 * re-upload to get the same benefit (smaller file served to mobile via img_srcset()
 * in includes/blocks.php).
 *
 * Scans uploads/media/ directly rather than media.json: most sites in this fleet
 * (multisite-generated ones especially) have their real photos written straight into
 * site.json/pages/*.json by the batch/AI-photo pipeline and never registered in the
 * media library index at all — media.json was empty or missing for 4 of 5 real sites
 * checked while building this script, so keying off it would have silently skipped
 * nearly everything.
 *
 * Only ever WRITES a new "-mobile.webp" file next to an existing one — never touches
 * site.json/templates.json/pages or the original file, so there's nothing to break if
 * this is run twice or aborted partway.
 *
 * Usage:  php tools/backfill_mobile_images.php            (every site under sites/)
 *         php tools/backfill_mobile_images.php water-site  (one site)
 */

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(2); }

define('BASE', dirname(__DIR__));
require BASE . '/config.php';
require BASE . '/includes/media_lib.php'; // img_write_mobile_variant(), MOBILE_IMG_WIDTH

$onlySite = $argv[1] ?? '';
$sites = $onlySite !== ''
    ? [$onlySite]
    : array_values(array_filter(array_map('basename', glob(BASE . '/sites/*')), fn($s) => is_dir(BASE . "/sites/$s/uploads/media")));

if (!$sites) { fwrite(STDERR, "No site(s) with an uploads/media directory found.\n"); exit(2); }

$totals = ['seen' => 0, 'written' => 0, 'already' => 0, 'too_small' => 0];

foreach ($sites as $site) {
    $files = glob(BASE . "/sites/$site/uploads/media/*.webp");
    $siteWritten = 0;
    foreach ($files as $fs) {
        if (str_ends_with($fs, '-mobile.webp')) continue; // a variant, not a source
        $totals['seen']++;

        $mobileFs = preg_replace('/\.webp$/i', '-mobile.webp', $fs);
        if ($mobileFs === null || $mobileFs === $fs) continue;
        if (is_file($mobileFs)) { $totals['already']++; continue; }

        [$w, $h] = @getimagesize($fs) ?: [0, 0];
        if ($w <= MOBILE_IMG_WIDTH + 120) { $totals['too_small']++; continue; }

        img_write_mobile_variant($fs);
        if (is_file($mobileFs)) { $totals['written']++; $siteWritten++; }
    }
    echo "$site: $siteWritten mobile variant(s) written (of " . count($files) . " files scanned)\n";
}

echo "\nTotals across " . count($sites) . " site(s), " . $totals['seen'] . " source file(s) scanned:\n";
echo "  written:              {$totals['written']}\n";
echo "  already had one:      {$totals['already']}\n";
echo "  too narrow to bother: {$totals['too_small']}\n";
exit(0);
