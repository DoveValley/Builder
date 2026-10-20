<?php
/**
 * Push newly-pinned page-pool slugs into the locked manifests of already-built domains.
 *
 * ms_page_pool_selected_for_domain() locks a domain's selection on its FIRST build and
 * reuses it forever after — deliberately, so editing pool settings never silently
 * rewrites the URL set of a live site. The cost is that flipping a service to 'pinned'
 * in the Keywords tab reaches only domains that have never been built. Every existing
 * domain keeps the manifest it picked on day one.
 *
 * This closes that gap the additive way: for each domain already holding a
 * *.pagepool.json, add any pinned slug the manifest is missing and change nothing else.
 * No slug is ever removed and no slug is reordered, so every URL a domain already
 * publishes still publishes — the only outcome is that pinned pages start appearing.
 *
 * ⚠ The result can exceed the domain's page_pool.counts target. That is correct:
 * 'pinned' means "always built" (admin/tabs/keywords.php labels it exactly that), and
 * counts is the budget for the ROTATE fill — ms_page_pool_select() already subtracts
 * the pinned set before filling, so pinned pages were never inside the target.
 *
 * ⚠ Writing the manifest does NOT build the pages. The domain still needs a build+upload
 * pass afterward for the added slugs to exist as real URLs.
 *
 * --only= restricts the sync to named slugs. Without it this pushes EVERY pinned slug,
 * which on a fleet that has been pinning services for a while is a much larger change
 * than it looks: a domain built before a pinning decision is missing every slug pinned
 * since, and each added page is a real AI-generation + build + upload. Use --only when
 * the intent is one specific decision; use the full sweep deliberately.
 *
 * Usage:
 *   php tools/pagepool_sync_pinned.php --site=appliance-site
 *   php tools/pagepool_sync_pinned.php --site=appliance-site --only=appliance-repair-cost,commercial-appliance-repair
 *   php tools/pagepool_sync_pinned.php --site=appliance-site --apply
 *
 * Defaults to a DRY RUN. Nothing is written without --apply.
 */

$opts   = getopt('', ['site:', 'apply', 'only:']);
$siteId = $opts['site'] ?? '';
$apply  = isset($opts['apply']);
$only   = array_values(array_filter(array_map('trim', explode(',', (string) ($opts['only'] ?? '')))));

if ($siteId === '') {
    fwrite(STDERR, "usage: php tools/pagepool_sync_pinned.php --site=<site-id> [--apply]\n");
    exit(1);
}

define('BASE_DIR', realpath(__DIR__ . '/..'));
require_once BASE_DIR . '/includes/multisite/page_pool.php';

$siteDir = BASE_DIR . '/sites/' . $siteId;
$config  = ms_page_pool_config($siteDir . '/data/keyword_map.json');

if ($config === null) {
    fwrite(STDERR, "page pool not enabled (or no services) for {$siteId} — nothing to do\n");
    exit(1);
}

$pinned = $config['pinned'];
$allPinnedCount = count($pinned);

if ($only) {
    // A slug named in --only that isn't actually pinned is a typo or a half-made
    // decision; syncing it anyway would push a page the config doesn't ask for.
    $notPinned = array_values(array_diff($only, $pinned));
    if ($notPinned) {
        fwrite(STDERR, "not pinned in keyword_map.json: " . implode(', ', $notPinned) . "\n");
        fwrite(STDERR, "set pool=\"pinned\" there first (Keywords tab), then re-run.\n");
        exit(1);
    }
    $pinned = $only;
}

printf("%s  site=%s\n  pinned slugs in config: %d%s\n\n",
    $apply ? 'APPLYING' : 'DRY RUN', $siteId, $allPinnedCount,
    $only ? sprintf("  (syncing only %d: %s)", count($pinned), implode(', ', $pinned)) : '');

$manifests = glob($siteDir . '/multisite/cache/*.pagepool.json') ?: [];
if (!$manifests) {
    print("no page-pool manifests on file — every domain will pick fresh on first build\n");
    exit(0);
}

$touched = 0;
$failed  = [];

foreach ($manifests as $file) {
    $domainSlug = str_replace('.pagepool.json', '', basename($file));
    $saved      = json_decode((string) file_get_contents($file), true);

    if (!is_array($saved) || !is_array($saved['slugs'] ?? null)) {
        $failed[] = "{$domainSlug}: unreadable or malformed manifest — skipped";
        continue;
    }

    $have    = $saved['slugs'];
    $missing = array_values(array_diff($pinned, $have));

    if (!$missing) {
        printf("  %-42s %3d slugs   ✓ all pinned present\n", $domainSlug, count($have));
        continue;
    }

    // Append, never reorder: the existing order is what the first build chose, and a
    // reshuffle here would show up as churn in every downstream diff for no benefit.
    $saved['slugs']      = array_merge($have, $missing);
    $saved['pinned_synced_at'] = date('c');
    $touched++;

    printf("  %-42s %3d → %3d   + %s\n", $domainSlug, count($have), count($saved['slugs']),
        implode(', ', $missing));

    if ($apply) {
        $json = json_encode($saved, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false || file_put_contents($file, $json) === false) {
            $failed[] = "{$domainSlug}: write failed";
        }
    }
}

printf("\nmanifests needing slugs: %d of %d\n", $touched, count($manifests));

if ($failed) {
    print("\n⚠ PROBLEMS:\n");
    foreach ($failed as $f) print("  {$f}\n");
}
if (!$apply) {
    print("\nDRY RUN — nothing written. Re-run with --apply.\n");
} elseif ($touched) {
    print("\nManifests updated. The added pages do NOT exist yet — build + upload each domain.\n");
}
exit($failed ? 1 : 0);
