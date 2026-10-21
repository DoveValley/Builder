<?php
/**
 * Stop the feature-columns prompt ASKING for guarantees it then forbids.
 *
 * `feature_columns_local` lists the benefits the model should write about:
 *
 *   "things like experience, responsiveness, guarantees, thorough treatment,
 *    and trained technicians"
 *
 * and the same prompt later says "Never guarantee outcomes." The model resolves
 * that contradiction the way you would expect -- it writes a guarantee-shaped
 * HEADING and hedges in the body underneath. Observed live on a brand-new page:
 *
 *   <h3>Samsung Washer Repair With Real Guarantees</h3>
 *   "The independent providers in our network stand behind their work with
 *    service guarantees."
 *
 * The body is defensible under the referral model. The heading is not, and it sits
 * on a site whose own disclaimer says it makes no warranty on work carried out --
 * the same contradiction removed from pest's copy in 07487c01, arriving fresh from
 * the generator rather than from stored text.
 *
 * Removing the word from the suggestion list is the whole fix. The existing
 * "Never guarantee outcomes." line stays and is now unopposed.
 *
 * ⚠ DELIBERATELY NOT TOUCHED:
 *  - faq_local's "Do not fabricate prices, guarantees, timelines, or credentials"
 *    and homepage_service_area's "without inventing guarantees or credentials" are
 *    BANS. Stripping the word there would delete a guardrail.
 *  - "trained technicians", in the same sentence, is a PERSONNEL claim. Standing
 *    decision (v293) is that personnel wording is Scott's call, so it stays.
 *
 * Usage:
 *   php tools/fix_prompt_guarantee_benefit.php --site=appliance-site
 *   php tools/fix_prompt_guarantee_benefit.php --site=appliance-site --apply
 *
 * Defaults to a DRY RUN. Nothing is written without --apply.
 */

$opts  = getopt('', ['site:', 'apply']);
$site  = $opts['site'] ?? '';
$apply = isset($opts['apply']);

if ($site === '') {
    fwrite(STDERR, "usage: php tools/fix_prompt_guarantee_benefit.php --site=<id> [--apply]\n");
    exit(1);
}

$file = __DIR__ . '/../sites/' . $site . '/data/ai_block_types.json';
if (!is_file($file)) { fwrite(STDERR, "no ai_block_types.json for {$site}\n"); exit(1); }

$raw  = file_get_contents($file);
$data = json_decode($raw, true);
if (!is_array($data)) { fwrite(STDERR, "unreadable ai_block_types.json\n"); exit(1); }

/*
 * Replace rather than delete: the list is a menu of things to write about, and
 * removing an item without putting anything back quietly narrows it. "clear
 * communication" is something the model can say truthfully about a referral
 * service without promising an outcome.
 */
$FROM = 'experience, responsiveness, guarantees, thorough treatment';
$TO   = 'experience, responsiveness, clear communication, thorough treatment';

printf("%s  site=%s\n\n", $apply ? 'APPLYING' : 'DRY RUN', $site);

$hits = 0;
foreach ($data as $key => &$type) {
    $p = $type['ai_prompt'] ?? '';
    if ($p === '' || strpos($p, $FROM) === false) continue;
    $type['ai_prompt'] = str_replace($FROM, $TO, $p);
    $hits++;
    printf("  [%s]\n    - %s\n    + %s\n", $key, $FROM, $TO);
}
unset($type);

// Prove the guardrail survived and no ban lost its word.
$banWords = 0;
foreach ($data as $type) {
    $p = $type['ai_prompt'] ?? '';
    $banWords += preg_match_all('/(?:Do not fabricate[^.]*guarantees|without inventing guarantees|Never guarantee outcomes)/i', $p);
}
printf("\n  guarantee BAN clauses still present: %d\n", $banWords);

if (!$hits) { print("\n  nothing to change\n"); exit(0); }

/*
 * Indent is not uniform: appliance/water/mold round-trip at 4 spaces with no
 * trailing newline, pest at 2 WITH one. Re-encoding at the wrong width reformats
 * every line of a 13-prompt file around a one-word change, so detect and match.
 */
$width  = preg_match('/\n(\x20+)"/', $raw, $m) ? strlen($m[1]) : 4;
$encode = function (array $d) use ($width): string {
    $j = json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($width !== 4) {
        $j = preg_replace_callback('/^(\x20+)/m',
            fn($mm) => str_repeat(' ', intdiv(strlen($mm[1]), 4) * $width), $j);
    }
    return $j;
};
$tail = str_ends_with($raw, "\n") ? "\n" : '';

// Identity check on the untouched structure before trusting the writer.
if ($encode(json_decode($raw, true)) . $tail !== $raw) {
    fwrite(STDERR, "\n⚠ file does not round-trip byte-identically — refusing to rewrite it\n");
    exit(1);
}

if ($apply) {
    $tmp = $file . '.tmp.' . getmypid();
    if (file_put_contents($tmp, $encode($data) . $tail) === false || !rename($tmp, $file)) {
        fwrite(STDERR, "write failed\n"); @unlink($tmp); exit(1);
    }
    print("  written.\n");
} else {
    print("\nDRY RUN — nothing written. Re-run with --apply.\n");
}
