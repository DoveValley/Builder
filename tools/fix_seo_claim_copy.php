<?php
/**
 * Normalise the claim clauses in a master's SEO description fields.
 *
 * Commit 35e2a3c2 fixed the ten hardcoded TRUST BARS — the visible badge row — and
 * stated the four claims this business model cannot make: not factory authorized /
 * manufacturer certified, same-day is "often available, never certain", pricing is
 * not characterised at all, and the site's own disclaimer states it makes no warranty
 * or guarantee regarding work carried out.
 *
 * The SEO fields were never swept for the same four. They are STATIC template copy
 * (not AI-generated), so unlike body prose they are NOT rewritten per domain by the
 * prompts — they clone verbatim to every site. That puts the banned claims in three
 * places the trust-bar fix does not reach:
 *
 *   seo.meta_description   → the Google SERP snippet
 *   seo.og_description     → og:description + twitter:description
 *   seo.service_description → the Service node's "description" in the emitted JSON-LD
 *   seo.schema             → the same string, baked into the stored schema blob
 *
 * Verified live on wrenappliancerepair.com after the 2026-10-20 21:00 rebuild: body
 * copy clean (the trust-bar fix landed), metadata still asserting all four.
 *
 * Also fixes brand casing. The bulk-clone that generated these descriptions lowercased
 * the whole service phrase, so 101 templates read "Professional lg dryer repair" /
 * "Professional sub-zero refrigerator repair" — a proper noun lowercased in the live
 * SERP snippet. Appliance-type words stay lowercase (correct mid-sentence); only the
 * brand is restored, and only where it is the brand token for that template.
 *
 * ── WHY RAW STRING REPLACEMENT, NOT JSON RE-ENCODE ───────────────────────────────
 * data/pages/*.json have inconsistent indentation (leaf = 4-space, brand hubs =
 * 2-space) and templates.json is 1.5 MB. A decode/encode round-trip reformats the
 * whole file and buries a 40-field change in a 30k-line diff. These clauses are exact
 * literals containing no quotes or backslashes, so they survive JSON escaping byte for
 * byte — including the copy nested inside the double-encoded seo.schema blob, which a
 * field-level edit would miss. Replacement is on the raw file text; the result is
 * re-decoded and compared to prove only the intended strings moved.
 *
 * Usage:
 *   php tools/fix_seo_claim_copy.php --site=appliance-site
 *   php tools/fix_seo_claim_copy.php --site=appliance-site --apply
 *
 * Defaults to a DRY RUN. Nothing is written without --apply.
 */

$opts   = getopt('', ['site:', 'apply', 'quiet']);
$siteId = $opts['site'] ?? '';
$apply  = isset($opts['apply']);
$quiet  = isset($opts['quiet']);

if ($siteId === '') {
    fwrite(STDERR, "usage: php tools/fix_seo_claim_copy.php --site=<site-id> [--apply]\n");
    exit(1);
}

$dataDir = __DIR__ . '/../sites/' . $siteId . '/data';
if (!is_dir($dataDir)) {
    fwrite(STDERR, "no such site: {$siteId}\n");
    exit(1);
}

/*
 * Clause rewrites, longest-first so a specific variant is consumed before the generic
 * one can match a prefix of it. Each pair is [search, replace]; both sides are plain
 * text exactly as stored.
 *
 * The replacement vocabulary is deliberately lifted from the '@safe_trust_badges'
 * sentinel already in use on 123/123 trust bars ("Fast Local Response", "Local
 * Provider Network") rather than invented here — inventing badge copy is precisely how
 * the hardcoded four arrived in the first place.
 */
$CLAUSES = [
    // --- factory-trained variants ---
    ['Factory-trained technicians, same-day service, upfront pricing, warranty.',
     'Fast local response, often same-day.'],
    ['Factory-trained techs, same-day service, warranty.',
     'Fast local response, often same-day.'],
    ['Factory-trained technicians, upfront pricing, and most repairs done same-day.',
     'Fast local response, often same-day.'],
    ['Our factory-trained technician diagnoses the fault and gives you an upfront, no-surprise quote.',
     'A local provider diagnoses the fault and talks you through what it needs.'],
    // --- same-day / pricing / warranty, no factory claim ---
    ['Responsive local providers, same-day service, upfront pricing, warranty.',
     'Responsive local providers, often same-day.'],
    ['Same-day service, upfront pricing, and warranty options available.',
     'Fast local response, often same-day.'],
    ['— same-day service, upfront pricing, warranty.',
     '— fast local response, often same-day.'],
    // Same four claims, different word order — lives on tpl_master_leaf, the clone
    // source for every new city, so it seeds rather than ships. Fix it or the next
    // city built re-introduces what this tool just removed.
    ['Upfront pricing, same-day service, warranty.',
     'Fast local response, often same-day.'],
    // Four one-off phrasings the bulk patterns above miss. tpl_whirlpool_appliance_repair
    // and tpl_emergency_appliance_repair are LIVE page templates; the two tpl_master_*
    // are clone sources for new cities.
    ['All major brands, same-day service, warranty.',
     'All major brands, fast local response, often same-day.'],
    ['Same-day service, warranty.',
     'Fast local response, often same-day.'],
    ['Same-day service, upfront pricing, warranty.',
     'Fast local response, often same-day.'],
    ['Fast response when an appliance fails, with upfront pricing and a warranty on the work.',
     'Fast response when an appliance fails, connecting you with a local provider.'],
    // --- static hero seed copy ---
    // AI overwrites hs_subtext on every real domain, so these two never reach the
    // fleet — but they are what a page falls back to if generation is skipped or
    // fails, and "upfront pricing" is the pricing characterisation the prompts ban.
    ['<p>Fast, reliable Appliance Repair from {business}. Connect with a local provider for upfront pricing and most repairs done same-day.</p>',
     '<p>Fast, reliable Appliance Repair from {business}. Connect with a local provider — fast response, often same-day.</p>'],
    ['<p>Fast, reliable Appliance Repair connections from {business}. We connect you with responsive local providers for upfront pricing and prompt service.</p>',
     '<p>Fast, reliable Appliance Repair connections from {business}. We connect you with responsive local providers who respond quickly.</p>'],
];

/*
 * Brand casing. Only brands that actually appear lowercased in a description are
 * listed; the match is anchored to "Professional <brand> " so it cannot touch prose
 * elsewhere in the file, and the replacement restores the canonical casing used by
 * seo.brand / the mega-menu taxonomy.
 */
$BRANDS = [
    'ge monogram' => 'GE Monogram', 'sub-zero' => 'Sub-Zero', 'kitchenaid' => 'KitchenAid',
    'jennair' => 'JennAir', 'whirlpool' => 'Whirlpool', 'maytag' => 'Maytag',
    'frigidaire' => 'Frigidaire', 'kenmore' => 'Kenmore', 'samsung' => 'Samsung',
    'thermador' => 'Thermador', 'amana' => 'Amana', 'bosch' => 'Bosch',
    'dacor' => 'Dacor', 'viking' => 'Viking', 'miele' => 'Miele',
    'wolf' => 'Wolf', 'ge' => 'GE', 'lg' => 'LG',
];

/** Apply every clause rewrite to a raw file body, counting hits per rule. */
function sweep_clauses(string $raw, array $clauses, array &$tally): string {
    foreach ($clauses as [$from, $to]) {
        $n = substr_count($raw, $from);
        if ($n === 0) continue;
        $tally[$from] = ($tally[$from] ?? 0) + $n;
        $raw = str_replace($from, $to, $raw);
    }
    return $raw;
}

/**
 * Restore brand casing after "Professional ". Longest brand first so "ge monogram"
 * is not shadowed by "ge"; \b on the trailing edge keeps "ge" from eating "general".
 */
function sweep_brands(string $raw, array $brands, int &$count): string {
    uksort($brands, fn($a, $b) => strlen($b) <=> strlen($a));
    foreach ($brands as $lower => $proper) {
        $re  = '/(Professional )' . preg_quote($lower, '/') . '\b/';
        $raw = preg_replace_callback($re, function ($m) use ($proper, &$count) {
            $count++;
            return $m[1] . $proper;
        }, $raw);
    }
    return $raw;
}

$targets = array_merge(
    [$dataDir . '/templates.json'],
    array_values(array_filter(glob($dataDir . '/pages/*.json') ?: [],
        fn($f) => !str_ends_with($f, '.bak')))
);

printf("%s  site=%s  files=%d\n\n", $apply ? 'APPLYING' : 'DRY RUN', $siteId, count($targets));

$tally = [];
$brandCount = 0;
$changedFiles = 0;
$failed = [];

foreach ($targets as $path) {
    $raw = file_get_contents($path);
    if ($raw === false) { $failed[] = "unreadable: {$path}"; continue; }

    $before = $raw;
    $raw = sweep_clauses($raw, $CLAUSES, $tally);
    $raw = sweep_brands($raw, $BRANDS, $brandCount);
    if ($raw === $before) continue;

    // Never write a file we just broke: the result must still parse, and must differ
    // from the original in string VALUES only — same key set, same structure.
    $decoded = json_decode($raw, true);
    if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
        $failed[] = sprintf("%s: replacement produced invalid JSON (%s) — skipped",
            basename($path), json_last_error_msg());
        continue;
    }
    $origDecoded = json_decode($before, true);
    if (json_shape($origDecoded) !== json_shape($decoded)) {
        $failed[] = sprintf("%s: structure changed — skipped", basename($path));
        continue;
    }

    $changedFiles++;
    if (!$quiet) printf("  %s\n", basename($path));
    if ($apply) {
        if (file_put_contents($path, $raw) === false) $failed[] = "write failed: {$path}";
    }
}

/** Key/type skeleton of a decoded structure — ignores scalar string values. */
function json_shape($o): string {
    if (is_array($o)) {
        $parts = [];
        foreach ($o as $k => $v) $parts[] = $k . ':' . json_shape($v);
        return '{' . implode(',', $parts) . '}';
    }
    return is_string($o) ? 's' : gettype($o);
}

print("\n── clause rewrites ──────────────────────────────────────────\n");
if (!$tally) {
    print("  (none found — already clean)\n");
} else {
    foreach ($CLAUSES as [$from, $to]) {
        if (empty($tally[$from])) continue;
        printf("  x%-5d %s\n           → %s\n", $tally[$from], $from, $to);
    }
}
printf("\n── brand casing ─────────────────────────────────────────────\n  x%d restored after \"Professional \"\n", $brandCount);
printf("\nfiles changed: %d of %d\n", $changedFiles, count($targets));

if ($failed) {
    print("\n⚠ PROBLEMS:\n");
    foreach ($failed as $f) print("  {$f}\n");
}
if (!$apply) print("\nDRY RUN — nothing written. Re-run with --apply.\n");
exit($failed ? 1 : 0);
