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
 * Clause rewrites, PER MASTER. Each niche's copy was authored separately, so the
 * phrasings do not overlap — keeping them in one table keyed by site keeps a single
 * place to look while making it impossible for one niche's rule to fire on another's
 * text. Longest-first within a set, so a specific variant is consumed before a generic
 * one can match a prefix of it. Each pair is [search, replace], plain text exactly as
 * stored.
 *
 * The replacement vocabulary is deliberately lifted from the '@safe_trust_badges'
 * sentinel already in use on every trust bar in the fleet ("Fast Local Response",
 * "Local Provider Network") rather than invented here — inventing badge copy is
 * precisely how the hardcoded claims arrived in the first place.
 */
$CLAUSE_SETS = [];

$CLAUSE_SETS['appliance-site'] = [
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
 * ── pest-template ────────────────────────────────────────────────────────────────
 * Pest pages promise a re-treat guarantee in the <title>, og:description, the Service
 * schema and twice in the body, then disclaim it in the footer on the same page:
 * "offers no warranty or guarantee regarding any work they carry out". Live on 30
 * domains, 22 of 32 templates, 1,150 of 1,158 built pages.
 *
 * ⚠ SCOPE: this removes only the SITE's first-person promise — "our re-treat
 * guarantee" and "Fast, Guaranteed X Control". Whether a PROVIDER-attributed version
 * goes back in ("providers who offer a re-treat guarantee") is a separate, open
 * question that depends on whether the operators actually re-treat for free; that is
 * Scott's fact to supply, and nothing here pre-empts it. Removing the site's promise
 * is correct under either answer, because the site does not perform the work.
 *
 * Deliberately NOT touched: "Never guarantee outcomes." and "Never guarantee total or
 * permanent eradication." are PROMPT BANS in ai_prompt_override. None of the rules
 * below can match them — checked.
 */
$CLAUSE_SETS['pest-template'] = [
    // service_description → the Service node's description in the emitted JSON-LD
    ['for homes and businesses with fast response and a re-treat guarantee.',
     'for homes and businesses, with fast local response.'],
    // cb_text → the body CTA. "our" is the site promising; this is the clearest case.
    ['Get fast, targeted treatment backed by our re-treat guarantee.',
     'Get fast, targeted treatment from a local provider.'],
    // hs_subtext → hero paragraph. The provider framing before the comma is accurate
    // and stays; only the trailing promise goes.
    ['inside, treat the source, and back the work with a re-treat guarantee.',
     'inside, and treat the source.'],
    ['the problem at the source and back the work with a re-treat guarantee.',
     'the problem at the source.'],
    // og_description variants
    ['treatment for homes and businesses, backed by a re-treat guarantee.',
     'treatment for homes and businesses, from a local provider.'],
    ['targeted treatment, and a re-treat guarantee.',
     'and targeted treatment.'],
];

/*
 * Brand casing. Only brands that actually appear lowercased in a description are
 * listed; the match is anchored to "Professional <brand> " so it cannot touch prose
 * elsewhere in the file, and the replacement restores the canonical casing used by
 * seo.brand / the mega-menu taxonomy. Appliance-only — no other niche has a brand axis.
 */
$BRAND_SETS = [];
$BRAND_SETS['appliance-site'] = [
    'ge monogram' => 'GE Monogram', 'sub-zero' => 'Sub-Zero', 'kitchenaid' => 'KitchenAid',
    'jennair' => 'JennAir', 'whirlpool' => 'Whirlpool', 'maytag' => 'Maytag',
    'frigidaire' => 'Frigidaire', 'kenmore' => 'Kenmore', 'samsung' => 'Samsung',
    'thermador' => 'Thermador', 'amana' => 'Amana', 'bosch' => 'Bosch',
    'dacor' => 'Dacor', 'viking' => 'Viking', 'miele' => 'Miele',
    'wolf' => 'Wolf', 'ge' => 'GE', 'lg' => 'LG',
];

/*
 * Pattern rewrites, for claims that vary by a noun and so cannot be listed as literals.
 * Pest titles read "<Pest> Exterminator in {city_state} | Fast, Guaranteed <Pest>
 * Control" across 15 pests — the promise is in the <title>, i.e. the blue headline in
 * Google, which is the single highest-value string on the page.
 *
 * "Targeted" replaces "Guaranteed": it is the word the surrounding copy already uses
 * ("targeted treatment", "Targeted roach control"), it keeps the headline the same
 * length and rhythm, and it describes the method rather than promising an outcome.
 */
$PATTERN_SETS = [];
$PATTERN_SETS['pest-template'] = [
    ['/\| Fast, Guaranteed (.+?) Control/', '| Fast, Targeted $1 Control',
     'title: "| Fast, Guaranteed X Control" → "| Fast, Targeted X Control"'],
];

/** Apply pattern rewrites to a raw file body, counting hits per rule. */
function sweep_patterns(string $raw, array $patterns, array &$tally): string {
    foreach ($patterns as [$re, $to, $label]) {
        $n = preg_match_all($re, $raw);
        if (!$n) continue;
        $tally[$label] = ($tally[$label] ?? 0) + $n;
        $raw = preg_replace($re, $to, $raw);
    }
    return $raw;
}

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

$CLAUSES  = $CLAUSE_SETS[$siteId]  ?? [];
$PATTERNS = $PATTERN_SETS[$siteId] ?? [];
$BRANDS   = $BRAND_SETS[$siteId]   ?? [];

if (!$CLAUSES && !$PATTERNS && !$BRANDS) {
    fwrite(STDERR, "no rule set defined for '{$siteId}'.\n");
    fwrite(STDERR, "Known: " . implode(', ', array_unique(array_merge(
        array_keys($CLAUSE_SETS), array_keys($PATTERN_SETS), array_keys($BRAND_SETS)))) . "\n");
    fwrite(STDERR, "Each niche's copy was authored separately — add its own rules rather than\n");
    fwrite(STDERR, "reusing another's, or a rule will fire on text it was never written for.\n");
    exit(1);
}

$targets = array_merge(
    [$dataDir . '/templates.json'],
    array_values(array_filter(glob($dataDir . '/pages/*.json') ?: [],
        fn($f) => !str_ends_with($f, '.bak')))
);

printf("%s  site=%s  files=%d  rules=%d clause/%d pattern/%d brand\n\n",
    $apply ? 'APPLYING' : 'DRY RUN', $siteId, count($targets),
    count($CLAUSES), count($PATTERNS), count($BRANDS));

$tally = [];
$brandCount = 0;
$changedFiles = 0;
$failed = [];

foreach ($targets as $path) {
    $raw = file_get_contents($path);
    if ($raw === false) { $failed[] = "unreadable: {$path}"; continue; }

    $before = $raw;
    $raw = sweep_clauses($raw, $CLAUSES, $tally);
    $raw = sweep_patterns($raw, $PATTERNS, $tally);
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
$anyClause = false;
foreach ($CLAUSES as [$from, $to]) {
    if (empty($tally[$from])) continue;
    $anyClause = true;
    printf("  x%-5d %s\n           → %s\n", $tally[$from], $from, $to);
}
if (!$anyClause) print("  (none found — already clean)\n");

if ($PATTERNS) {
    print("\n── pattern rewrites ─────────────────────────────────────────\n");
    $anyPat = false;
    foreach ($PATTERNS as [$re, $to, $label]) {
        if (empty($tally[$label])) continue;
        $anyPat = true;
        printf("  x%-5d %s\n", $tally[$label], $label);
    }
    if (!$anyPat) print("  (none found — already clean)\n");
}

if ($BRANDS) {
    printf("\n── brand casing ─────────────────────────────────────────────\n  x%d restored after \"Professional \"\n", $brandCount);
}
printf("\nfiles changed: %d of %d\n", $changedFiles, count($targets));

if ($failed) {
    print("\n⚠ PROBLEMS:\n");
    foreach ($failed as $f) print("  {$f}\n");
}
if (!$apply) print("\nDRY RUN — nothing written. Re-run with --apply.\n");
exit($failed ? 1 : 0);
