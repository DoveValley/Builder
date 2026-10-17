<?php
/**
 * Safety Toolkit — the "verify your provider before you sign" trust block.
 *
 * Renders as a `cards` content block (includes/blocks.php's existing card-grid render case,
 * not a new block type — it already has everything this needs: icon, heading, text, a link).
 * A template's `cards_items` field can be the literal sentinel string
 * '@safety_toolkit_default' instead of a real item array, same pattern as trust_bar's
 * '@safe_trust_badges' (includes/blocks.php). The intro copy (cards_label/cards_heading/
 * cards_subtext) is NOT sentineled — it's ordinary per-template text, same as every other
 * block, and may use the normal {city_state} shortcode for page-level relevance.
 *
 * Content lives one JSON file per niche, keyed by the niche slug already established
 * fleet-wide (sites/{master}/multisite/niche_brief.json's `niche` field, slugified) — same
 * convention as plugins/image-data-chart/niches/{slug}/*.json. Drop a new niche's file in and
 * it works there too; nothing here names a niche in code.
 *
 *   plugins/safety_toolkit/niches/{niche-slug}/items.json
 *   { "items": [ {id, icon, heading, text, btn_text, link}, ... 5 of them ... ] }
 *
 * Every domain shows only 3 of a niche's 5 organizations, not all 5 — so domains on the same
 * niche master don't render an identical trust row. Which 3 (and their order) is picked
 * deterministically from the domain name via crc32, the same seeding idiom already used for
 * page-pool counts, schema shape, and color jitter (includes/multisite/page_pool.php,
 * schema_shape.php, visual.php) — stable across rebuilds, not reshuffled on every regenerate.
 *
 * All 5 organizations per niche are national/independent bodies, true regardless of which
 * city the domain targets, so picking a random 3 never drops a city-specific fact.
 */

register_plugin(
    'safety_toolkit',
    'Safety Toolkit',
    'Shows a "verify your provider before you hire" trust section — 3 independent national organizations '
    . '(e.g. IICRC, EPA, BBB for water damage) picked per domain from a niche\'s own set of 5, so two domains '
    . 'on the same niche don\'t show the identical three. Which 3 appear is fixed per domain, not reshuffled '
    . 'on every rebuild. Place <code>cards_items: "@safety_toolkit_default"</code> on a Cards content block '
    . '(already wired into every niche\'s homepage and landing-page templates). Each niche\'s 5 organizations, '
    . 'descriptions and real outbound links live in <code>plugins/safety_toolkit/niches/{niche-slug}/items.json</code> '
    . '— one file per niche, matched automatically to the site\'s own Niche Brief. A niche with no file yet '
    . 'renders nothing, never a guess.',
    '&#128737;',
    __DIR__
);

/** This site's niche, slugified — '' when the brief is missing/empty. Self-contained copy of
 *  the same read-and-slugify logic plugins/image-data-chart/charts.php uses (city_chart_niche),
 *  duplicated rather than shared across plugin boundaries on purpose: this plugin's data and
 *  logic travel together as one removable unit. */
function safety_toolkit_niche_slug(string $siteDir): string {
    $b = json_decode((string) @file_get_contents($siteDir . '/multisite/niche_brief.json'), true);
    $niche = is_array($b) ? trim((string) ($b['niche'] ?? '')) : '';
    if ($niche === '') return '';
    return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($niche)), '-');
}

/** All 5 declared organizations for a niche slug, or [] if that niche has no data file yet —
 *  a niche with nothing here just shows no toolkit, the same "no data means nothing renders"
 *  rule the chart plugin uses, rather than a guessed/empty-looking block. */
function safety_toolkit_niche_items(string $slug): array {
    if ($slug === '') return [];
    $file = __DIR__ . '/niches/' . basename($slug) . '/items.json';
    if (!is_file($file)) return [];
    $doc = json_decode((string) @file_get_contents($file), true);
    return is_array($doc['items'] ?? null) ? $doc['items'] : [];
}

/** Deterministically pick and order N of a niche's items, seeded on the domain — stable
 *  across rebuilds/regenerates, different per domain. */
function safety_toolkit_pick(array $items, string $domain, int $n = 3): array {
    $domain = strtolower(trim($domain));
    usort($items, function ($a, $b) use ($domain) {
        $ka = crc32('safety-toolkit|' . $domain . '|' . ($a['id'] ?? $a['heading'] ?? ''));
        $kb = crc32('safety-toolkit|' . $domain . '|' . ($b['id'] ?? $b['heading'] ?? ''));
        return $ka <=> $kb;
    });
    return array_slice($items, 0, max(0, $n));
}

/**
 * Entry point the `cards` render case calls when it sees the '@safety_toolkit_default'
 * sentinel. Resolves this domain's niche from ACTIVE_SITE_DIR, loads that niche's 5
 * organizations, and returns 3 of them shaped as cards_items rows. Returns [] when there's
 * no niche brief or no data file for it yet (renders as an empty grid, same as any other
 * cards block with no items — never a literal sentinel string shown on the page).
 */
function safety_toolkit_default_items(): array {
    global $data;
    $siteDir = (defined('ACTIVE_SITE_DIR') && ACTIVE_SITE_DIR !== '') ? ACTIVE_SITE_DIR : (defined('BASE_DIR') ? BASE_DIR : '');
    $slug    = safety_toolkit_niche_slug($siteDir);
    $items   = safety_toolkit_niche_items($slug);
    if (empty($items)) return [];
    $website = $data['site_vars']['website'] ?? '';
    $domain  = parse_url($website, PHP_URL_HOST) ?: $website;
    $picked  = safety_toolkit_pick($items, (string) $domain, 3);
    // Shape to exactly what the cards render case reads (includes/blocks.php's 'cards' case).
    return array_map(static function (array $org): array {
        return [
            'icon'     => $org['icon']     ?? '',
            'heading'  => $org['heading']  ?? '',
            'text'     => $org['text']     ?? '',
            'btn_text' => $org['btn_text'] ?? 'Learn More',
            'link'     => $org['link']     ?? '',
        ];
    }, $picked);
}
