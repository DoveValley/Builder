<?php
/**
 * Page pool — decides WHICH of a niche's landing pages a given domain actually builds.
 *
 * Same reasoning as the visual-identity/layout axes (ms_variant(), layout_variations.php):
 * pick per domain from a small set of options, seeded by the domain name, so it looks
 * random across the fleet but a rebuild of the same domain reproduces it. Applied here to
 * PAGE SELECTION instead of appearance — fewer pages per site (doorway-page risk), and a
 * different mix of pages between sites (not an identical inventory fleet-wide).
 *
 * Source of truth for which pages are eligible: keyword_map.json's per-service `pool`
 * field ('pinned' | 'rotate' | 'skip'), edited in the Keywords tab, defaulted from tier
 * when a service has never had a pool value set. `page_pool.counts` there holds the small
 * set of possible page-count totals a domain can land on (never one fixed number — see
 * MS_PAGE_POOL_DEFAULT_COUNTS).
 *
 * A domain's ACTUAL selection is locked in on its first build (see
 * ms_page_pool_selected_for_domain()) and reused on every rebuild after, so editing pool
 * settings later never silently changes which pages already exist on a site that's been
 * built (deployed or not) — only a domain that has never been built yet gets a fresh pick.
 */

const MS_PAGE_POOL_DEFAULT_COUNTS = [10, 12, 14];

/** Default pool bucket for a service that has never had one explicitly set. */
function ms_page_pool_default_for_tier(string $tier): string {
    if ($tier === 'high-1') return 'pinned';
    if (str_starts_with($tier, 'low')) return 'skip';
    return 'rotate';
}

/**
 * Read pool config from a keyword_map.json file. Returns null when the file doesn't
 * exist or has no services (a master that hasn't adopted this yet) — callers must treat
 * that as "nothing to do here", not an error.
 */
function ms_page_pool_config(string $keywordMapFile): ?array {
    if (!is_file($keywordMapFile)) return null;
    $map = json_decode((string) file_get_contents($keywordMapFile), true);
    if (!is_array($map) || !is_array($map['services'] ?? null)) return null;
    // Explicit opt-in, not inferred from tiers being set. A master can have tiered
    // keyword_map services (mold-site, pest-template both already do) without ever
    // having decided to run page pooling — inferring "on" from tier data alone would
    // silently activate this on every master with a filled-in Keywords tab the next
    // time anyone builds it, not just the one this was built and proven against.
    if (($map['page_pool']['enabled'] ?? false) !== true) return null;

    $pinned = [];
    $rotate = [];
    $skip   = [];
    foreach ($map['services'] as $s) {
        if (($s['section'] ?? 'landing') !== 'landing') continue;
        $slug = trim((string) ($s['slug'] ?? ''));
        if ($slug === '') continue;
        $pool = $s['pool'] ?? ms_page_pool_default_for_tier((string) ($s['tier'] ?? ''));
        if ($pool === 'pinned')      $pinned[] = $slug;
        elseif ($pool === 'skip')    $skip[]   = $slug;
        else                         $rotate[] = $slug;
    }
    if (!$pinned && !$rotate) return null;   // nothing eligible — treat as "not adopted"

    $counts = $map['page_pool']['counts'] ?? null;
    if (!is_array($counts) || !$counts) $counts = MS_PAGE_POOL_DEFAULT_COUNTS;
    $counts = array_values(array_unique(array_map('intval', $counts)));
    sort($counts);
    if (!$counts) $counts = MS_PAGE_POOL_DEFAULT_COUNTS;

    return ['pinned' => $pinned, 'rotate' => $rotate, 'skip' => $skip, 'counts' => $counts];
}

/**
 * Deterministic pick of $n slugs out of $pool for $domain: sort the pool by a per-domain
 * hash and take the first $n. Same idea as ms_variant()'s crc32 seed, extended from a
 * single index to a whole subset.
 */
function ms_page_pool_pick(array $pool, int $n, string $domain, string $salt): array {
    if ($n <= 0 || !$pool) return [];
    $n = min($n, count($pool));
    $keyed = [];
    foreach ($pool as $slug) {
        $keyed[] = [crc32($salt . '|' . strtolower(trim($domain)) . '|' . $slug), $slug];
    }
    usort($keyed, fn($a, $b) => $a[0] <=> $b[0]);
    return array_map(fn($k) => $k[1], array_slice($keyed, 0, $n));
}

/** Full fresh selection for one domain: which landing-page slugs it should keep. */
function ms_page_pool_select(array $config, string $domain): array {
    $counts   = $config['counts'];
    $countIdx = ms_variant($domain, count($counts), 'pagepool_count');
    $target   = $counts[$countIdx];

    $pinned    = $config['pinned'];
    $remaining = max(0, $target - count($pinned));
    $filled    = ms_page_pool_pick($config['rotate'], $remaining, $domain, 'pagepool_fill');

    return array_values(array_unique(array_merge($pinned, $filled)));
}

function ms_page_pool_manifest_file(string $masterId, string $domainSlug): string {
    return BASE_DIR . '/sites/' . $masterId . '/multisite/cache/' . $domainSlug . '.pagepool.json';
}

/**
 * The domain's real selection: reused from a prior build if one is on file, otherwise
 * computed fresh and persisted. This is what makes the selection LOCK IN — a routine
 * rebuild always reuses whatever was picked the first time, even if pool config (tiers,
 * pinned/rotate/skip, counts) changes afterward.
 */
function ms_page_pool_selected_for_domain(string $masterId, string $domainSlug, array $config, string $domain): array {
    $file = ms_page_pool_manifest_file($masterId, $domainSlug);
    if (is_file($file)) {
        $saved = json_decode((string) file_get_contents($file), true);
        if (is_array($saved) && is_array($saved['slugs'] ?? null) && $saved['slugs']) {
            return ['slugs' => $saved['slugs'], 'reused' => true];
        }
    }
    $slugs = ms_page_pool_select($config, $domain);
    @mkdir(dirname($file), 0775, true);
    file_put_contents(
        $file,
        json_encode(['slugs' => $slugs, 'selected_at' => date('c')], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );
    return ['slugs' => $slugs, 'reused' => false];
}

/**
 * Prune every generated landing page in $workingDir whose template_id isn't in
 * $keepTemplateIds. Same shape as ms_prune_stale_landing_pages() (includes/multisite/landing.php,
 * which prunes by city instead of by page) — including the page-index.json cleanup, so the
 * nav's Services dropdown (services_links_resolve(), plugins/services_links/plugin.php)
 * never offers a link to a page pruned here; it already skips anything not in the index.
 */
function ms_prune_pages_outside_pool(string $workingDir, array $keepTemplateIds): int {
    $pagesDir = rtrim($workingDir, '/') . '/data/pages/';
    if (!is_dir($pagesDir)) return 0;
    $keep = array_flip($keepTemplateIds);

    $deleted = 0;
    foreach (glob($pagesDir . '*.json') ?: [] as $pageFile) {
        $page = json_decode((string) @file_get_contents($pageFile), true);
        $tid  = is_array($page) ? (string) ($page['template_id'] ?? '') : '';
        if ($tid === '' || isset($keep[$tid])) continue;   // no template_id, or a page we're keeping
        if (@unlink($pageFile)) $deleted++;
    }

    $indexFile = rtrim($workingDir, '/') . '/data/page-index.json';
    if ($deleted > 0 && is_file($indexFile)) {
        $index = json_decode((string) @file_get_contents($indexFile), true);
        if (is_array($index)) {
            $pruned = array_filter($index, fn($fn) => is_file($pagesDir . $fn));
            if (count($pruned) !== count($index)) {
                file_put_contents($indexFile, json_encode($pruned, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            }
        }
    }
    return $deleted;
}

/**
 * The nav's Services dropdown already checks page-index.json before linking anywhere
 * (services_links_resolve(), plugins/services_links/plugin.php) — but the footer's own
 * "Our Services" column (site.json's footer.columns[], type 'links') is a SEPARATE,
 * hand-curated static list that does not go through that check at all. Found by
 * actually looking at a rendered pruned page, not by reasoning about the code: a
 * footer link to a page that page-pool had just pruned rendered anyway. Prunes any
 * footer link whose URL names a pool slug that isn't in $keepSlugs; a link that
 * doesn't name any pool slug at all (About Us, Privacy Policy, …) is always kept.
 */
function ms_prune_footer_service_links(string $workingDir, array $poolSlugs, array $keepSlugs): int {
    $siteFile = rtrim($workingDir, '/') . '/data/site.json';
    $site = json_decode((string) @file_get_contents($siteFile), true);
    if (!is_array($site) || !is_array($site['footer']['columns'] ?? null)) return 0;

    $removed = 0;
    foreach ($site['footer']['columns'] as &$col) {
        if (($col['type'] ?? '') !== 'links' || !is_array($col['links'] ?? null)) continue;
        $col['links'] = array_values(array_filter($col['links'], function ($link) use ($poolSlugs, $keepSlugs, &$removed) {
            $url = trim((string) ($link['url'] ?? ''), '/');
            foreach ($poolSlugs as $slug) {
                if ($url === $slug . '-{city_slug}') {
                    if (isset($keepSlugs[$slug])) return true;
                    $removed++;
                    return false;
                }
            }
            return true;   // doesn't name any pool slug — not this feature's concern
        }));
    }
    unset($col);

    if ($removed > 0) {
        file_put_contents($siteFile, json_encode($site, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
    return $removed;
}

/**
 * Orchestrates the whole step for one domain's already-generated working dir: load
 * config, get (or lock in) this domain's selection, translate slugs to template ids via
 * templates.json's slug_pattern, and prune every generated page (plus the footer's own
 * services column) outside that set.
 *
 * Templates with no keyword_map 'landing' row at all are always kept — fail open, so a
 * template nobody has made a pool decision about never silently disappears.
 */
function ms_page_pool_apply_to_working_dir(string $workingDir, string $masterId, string $domainSlug, string $domain): array {
    $config = ms_page_pool_config($workingDir . '/data/keyword_map.json');
    if ($config === null) return ['applied' => false];

    $picked    = ms_page_pool_selected_for_domain($masterId, $domainSlug, $config, $domain);
    $keepSlugs = array_flip($picked['slugs']);

    $templates = json_decode((string) @file_get_contents($workingDir . '/data/templates.json'), true);
    $templates = is_array($templates) ? array_values($templates) : [];

    $poolSlugs = array_merge($config['pinned'], $config['rotate'], $config['skip']);
    $keepIds   = [];
    $available = 0;
    foreach ($templates as $tpl) {
        $tid     = (string) ($tpl['id'] ?? '');
        $pattern = (string) ($tpl['slug_pattern'] ?? '');
        if ($tid === '') continue;
        $matched = null;
        foreach ($poolSlugs as $slug) {
            if (str_starts_with($pattern, $slug . '-')) { $matched = $slug; break; }
        }
        if ($matched === null) { $keepIds[] = $tid; continue; }   // no pool opinion — always keep
        $available++;
        if (isset($keepSlugs[$matched])) $keepIds[] = $tid;
    }

    $deleted     = ms_prune_pages_outside_pool($workingDir, $keepIds);
    $footerFixed = ms_prune_footer_service_links($workingDir, $poolSlugs, $keepSlugs);
    return [
        'applied'      => true,
        'available'    => $available,
        'kept'         => count($picked['slugs']),
        'pruned'       => $deleted,
        'footer_fixed' => $footerFixed,
        'reused'       => $picked['reused'],
    ];
}
