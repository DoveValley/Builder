<?php
/**
 * Fill the per-domain `business_short` column in a batch's params.csv.
 *
 * {business_short} exists precisely for space-constrained fields — includes/shortcodes.php
 * calls it "a shorter brand form for space-constrained fields like <title>, where the full
 * business name plus a keyword phrase often runs past what search engines display" — and
 * falls back to the full {business} when it is blank.
 *
 * It is blank on EVERY row of EVERY niche (checked: appliance 0/100, water 0/100,
 * mold 0/100, pest 0/100), so every title in the fleet has been rendering at full length.
 * On appliance that put 45 of 123 titles past 65 characters and 93 past 60 — Google
 * truncates around 60, so the keyword phrase was being cut for the sake of a brand name
 * the reader has never heard of.
 *
 * The templates are already correct; only the data was missing. So this fills the column
 * rather than editing 123 title strings, which is both smaller and the mechanism's
 * intended use.
 *
 * ── How the short form is derived ────────────────────────────────────────────────
 * Every business in a niche batch shares a trailing phrase ("Wren Appliance Repair",
 * "Beck Appliance Repair", …). That common tail is the niche descriptor, not the brand,
 * so it is what the <title> can afford to lose — the keyword phrase earlier in the title
 * already says what the business does. The tail is DETECTED from the data rather than
 * passed in, so this works unchanged on water/mold/pest, and --strip overrides it when
 * the detection is wrong.
 *
 * Refuses to write a row whose short form would be empty or unchanged-but-still-long,
 * and never overwrites a short form someone has already set by hand.
 *
 * ⚠ Edits params.csv in place and deliberately does NOT touch params.version or write a
 * params_versions/ entry — those record UPLOADS (see includes/multisite/batch.php), and
 * this is an in-place correction, not a new upload.
 *
 * Usage:
 *   php tools/fill_business_short.php --site=appliance-site --batch=b3
 *   php tools/fill_business_short.php --site=appliance-site --batch=b3 --apply
 *   php tools/fill_business_short.php --site=water-site --batch=b1 --strip="Water Damage Restoration"
 *
 * Defaults to a DRY RUN. Nothing is written without --apply.
 */

$opts  = getopt('', ['site:', 'batch:', 'strip:', 'apply']);
$site  = $opts['site']  ?? '';
$batch = $opts['batch'] ?? '';
$strip = trim($opts['strip'] ?? '');
$apply = isset($opts['apply']);

if ($site === '' || $batch === '') {
    fwrite(STDERR, "usage: php tools/fill_business_short.php --site=<id> --batch=<id> [--strip=\"...\"] [--apply]\n");
    exit(1);
}

$csv = __DIR__ . "/../sites/{$site}/batches/{$batch}/params.csv";
if (!is_file($csv)) { fwrite(STDERR, "no params.csv at {$csv}\n"); exit(1); }

$rows = [];
$fh = fopen($csv, 'r');
$hdr = fgetcsv($fh);
if (!$hdr || !in_array('business', $hdr, true) || !in_array('business_short', $hdr, true)) {
    fwrite(STDERR, "params.csv has no business/business_short column\n"); exit(1);
}
while (($r = fgetcsv($fh)) !== false) {
    if (count($r) === 1 && trim((string)$r[0]) === '') continue;   // blank line
    $rows[] = array_pad(array_slice($r, 0, count($hdr)), count($hdr), '');
}
fclose($fh);

$iBiz   = array_search('business', $hdr, true);
$iShort = array_search('business_short', $hdr, true);

/** Longest trailing word-sequence shared by every non-empty business name. */
function common_tail(array $names): string {
    $names = array_values(array_filter(array_map('trim', $names), fn($n) => $n !== ''));
    if (count($names) < 2) return '';
    $split = array_map(fn($n) => preg_split('/\s+/', $n), $names);
    $tail = [];
    for ($i = 1; ; $i++) {
        $word = null;
        foreach ($split as $w) {
            if (count($w) <= $i) return implode(' ', array_reverse($tail));   // would consume a whole name
            $cur = $w[count($w) - $i];
            if ($word === null) { $word = $cur; continue; }
            if (strcasecmp($word, $cur) !== 0) return implode(' ', array_reverse($tail));
        }
        $tail[] = $word;
    }
}

$allBiz = array_map(fn($r) => $r[$iBiz], $rows);
$tail   = $strip !== '' ? $strip : common_tail($allBiz);

printf("%s  site=%s batch=%s  rows=%d\n", $apply ? 'APPLYING' : 'DRY RUN', $site, $batch, count($rows));
printf("  common tail %s: \"%s\"\n\n", $strip !== '' ? '(from --strip)' : '(detected)', $tail);

if ($tail === '') { fwrite(STDERR, "could not detect a common tail — pass --strip=\"...\"\n"); exit(1); }

$changed = 0; $skipped = 0; $shown = 0;
foreach ($rows as &$r) {
    $biz = trim($r[$iBiz]);
    if ($biz === '') { $skipped++; continue; }
    if (trim($r[$iShort]) !== '') { $skipped++; continue; }   // never overwrite a hand-set value

    $short = trim(preg_replace('/\s*' . preg_quote($tail, '/') . '\s*$/i', '', $biz));
    if ($short === '' || strcasecmp($short, $biz) === 0) { $skipped++; continue; }

    $r[$iShort] = $short;
    $changed++;
    if ($shown < 15) { printf("  %-34s %-32s → %s\n", $r[0], '"' . $biz . '"', '"' . $short . '"'); $shown++; }
}
unset($r);
if ($changed > $shown) printf("  … and %d more\n", $changed - $shown);

printf("\nfilled: %d   skipped (blank / already set / no change): %d\n", $changed, $skipped);

if (!$apply) { print("\nDRY RUN — nothing written. Re-run with --apply.\n"); exit(0); }
if (!$changed) { print("nothing to write.\n"); exit(0); }

$tmp = $csv . '.tmp.' . getmypid();
$out = fopen($tmp, 'w');
fputcsv($out, $hdr);
foreach ($rows as $r) fputcsv($out, $r);
fclose($out);
if (!rename($tmp, $csv)) { fwrite(STDERR, "write failed\n"); @unlink($tmp); exit(1); }
print("written.\n");
