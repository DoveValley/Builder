<?php
/**
 * Error Codes plugin — manufacturer fault-code lookup for the `error_codes` block
 * (includes/blocks.php).
 *
 * WHY. "{Brand} {Appliance} error code {X}" is exactly the long-tail, high-intent
 * search query a repair-lead page should own — someone typing it has a broken
 * appliance right now. The data is built from primary-source research against
 * each manufacturer's own official support documentation, each meaning
 * paraphrased in original wording (never the manufacturer's exact text — see
 * "Writing blog/legal page content" in CLAUDE.md).
 *
 * MODULAR PER PLUGIN, same convention as plugins/image-data-chart (see that
 * plugin's own docblock): everything the data needs lives here, not scattered
 * into site data —
 *   brands/{brand-slug}.json   {appliance_type: {found, source_url, codes[]}}
 * Adding a brand is a file; adding a type to a brand is a key in that file. You
 * go into the plugin once and never again — no code changes, and research data
 * is never left behind when a site is cloned (a per-site data file is NOT
 * copied by the clone path unless someone remembers to add it — see
 * "Clones must carry render-time files" in project memory; this plugin can't
 * hit that bug because its data was never per-site to begin with).
 *
 * NO DATA MEANS NO BLOCK. A brand/type combo researched and found to have no
 * official code list (`"found": false`) — or simply absent from the brand's
 * file — renders nothing, same "disappear rather than show thin/broken" rule as
 * related_links, services_links, and the safe-badges/fake-ratings guards
 * elsewhere. Never falls back to a third-party aggregator's text, because the
 * whole point of this plugin is that every sentence it prints traces to a
 * manufacturer's own page.
 *
 * BRAND/TYPE COME FROM THE SLUG, LIKE EVERYTHING ELSE APPLIANCE-TAXONOMY-SHAPED.
 * A block's ec_brand/ec_type fields default to "auto", which derives both from the
 * CURRENT PAGE's own slug via appliance_derive_slug() (includes/appliance_taxonomy.php)
 * — the same single source of truth services_links and the mega-menu already use,
 * so this can never drift from how the rest of the site classifies a page. A
 * "type hub" page (e.g. refrigerator-repair-{city}, no brand in the slug) has no
 * single manufacturer to cite, so it picks the first brand in a fixed priority
 * order that has real data for that type — deterministic, reproducible, and the
 * render case phrases the heading/intro differently in that case (brand_explicit
 * is returned so the caller knows which case it's in).
 *
 * Isolated like every other plugin here (services_links, related_links): the
 * block's render case in includes/blocks.php calls this softly
 * (function_exists() guard), so a site with no error_codes.json, or missing this
 * plugin entirely, is simply a block that never renders — never a broken one.
 */

register_plugin(
    'error_codes',
    'Error Codes',
    'Manufacturer fault-code lookup for the page\'s own appliance brand/type, sourced only from each manufacturer\'s official support documentation (data/error_codes.json). A brand/type with no researched data renders nothing — never a guess, never a third-party source.',
    '&#9888;',   // ⚠
    __DIR__
);

/**
 * Every brand's research, assembled from brands/*.json — one glob, decoded once
 * per request. A brand with a malformed/unreadable file is simply skipped
 * (never fatal — a typo in one brand's file can't take the whole plugin down),
 * and the brand key comes from the filename itself, so adding brands/lg.json
 * is the entire change needed to add a brand; nothing else references a brand
 * list anywhere.
 */
function error_codes_data(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [];
    foreach (glob(__DIR__ . '/brands/*.json') ?: [] as $file) {
        $brand = basename($file, '.json');
        $decoded = json_decode((string) @file_get_contents($file), true);
        if (is_array($decoded)) $cache[$brand] = $decoded;
    }
    return $cache;
}

/** Trademarked-name casing error_codes.json's slug keys can't carry (e.g. "ge-monogram"). */
function error_codes_brand_label(string $brand): string {
    static $labels = [
        'ge-monogram' => 'GE Monogram', 'sub-zero' => 'Sub-Zero', 'whirlpool' => 'Whirlpool',
        'maytag' => 'Maytag', 'ge' => 'GE', 'kitchenaid' => 'KitchenAid', 'lg' => 'LG',
        'samsung' => 'Samsung', 'frigidaire' => 'Frigidaire', 'bosch' => 'Bosch',
        'kenmore' => 'Kenmore', 'amana' => 'Amana', 'jennair' => 'JennAir', 'dacor' => 'Dacor',
        'wolf' => 'Wolf', 'viking' => 'Viking', 'thermador' => 'Thermador', 'miele' => 'Miele',
    ];
    return $labels[$brand] ?? ucwords(str_replace('-', ' ', $brand));
}

/** Display label for an appliance_type key. */
function error_codes_type_label(string $type): string {
    static $labels = [
        'ice-maker' => 'Ice Maker', 'ice-machine' => 'Ice Machine',
        'wine-cooler' => 'Wine Cooler', 'garbage-disposal' => 'Garbage Disposal',
        'trash-compactor' => 'Trash Compactor',
    ];
    return $labels[$type] ?? ucwords(str_replace('-', ' ', $type));
}

/** Brand priority for a type-hub page with no brand of its own in the slug —
 *  fixed and deterministic, so the same type always picks the same brand. */
function error_codes_brand_priority(): array {
    return [
        'whirlpool', 'ge', 'samsung', 'lg', 'kitchenaid', 'maytag', 'frigidaire',
        'bosch', 'kenmore', 'amana', 'miele', 'jennair', 'viking', 'sub-zero',
        'thermador', 'wolf', 'dacor', 'ge-monogram',
    ];
}

/** A small, HONEST synonym fallback — only ever folds wording for the identical
 *  appliance (never a different one). "Stove" is colloquial for range/oven;
 *  nothing else gets a synonym, so a type with genuinely no data (ice maker,
 *  wine cooler, garbage disposal, trash compactor) correctly finds nothing. */
function error_codes_type_synonyms(string $type): array {
    $map = ['stove' => ['stove', 'range', 'oven']];
    return $map[$type] ?? [$type];
}

/** The current page's own slug with its trailing city segment stripped, the same
 *  `global $slug` + city-slug-strip convention plugins/services_links/plugin.php
 *  already uses for this exact "classify this page's own slug" purpose — without
 *  stripping the city, "whirlpool-refrigerator-repair-lufkin-tx" never matches
 *  appliance_derive_slug()'s "-repair" suffix check and classification silently
 *  fails on every real (city-suffixed) page. */
function error_codes_current_slug(): string {
    global $slug, $data;
    $base = (string) ($slug ?? '');
    $citySlug = $data['site_vars']['city_slug'] ?? '';
    if ($citySlug !== '') $base = preg_replace('/-' . preg_quote($citySlug, '/') . '$/', '', $base);
    return $base;
}

/**
 * Resolve the error-code block's content for the current (or given) page.
 *
 * @param string $brandOverride 'auto'/'' to derive from the slug, else a brand key.
 * @param string $typeOverride  'auto'/'' to derive from the slug, else an appliance_type key.
 * @param int    $max           Cap on codes returned (0 = no cap).
 * @param string $slugOverride  Slug to derive from; '' uses the current page's own slug.
 * @return array{brand:string,brand_label:string,type:string,type_label:string,
 *               source_url:string,codes:array,brand_explicit:bool}|null
 *         null when there is genuinely nothing researched to show.
 */
function error_codes_resolve(
    string $brandOverride = '',
    string $typeOverride = '',
    int $max = 6,
    string $slugOverride = ''
): ?array {
    $data = error_codes_data();
    if (!$data) return null;

    $brand = $brandOverride === 'auto' ? '' : trim($brandOverride);
    $type  = $typeOverride  === 'auto' ? '' : trim($typeOverride);
    $brandExplicit = $brand !== '';

    if ($brand === '' || $type === '') {
        $slug = $slugOverride !== '' ? $slugOverride : error_codes_current_slug();
        $info = function_exists('appliance_derive_slug')
            ? appliance_derive_slug($slug)
            : ['role' => '', 'brand' => '', 'appliance_type' => ''];
        if ($brand === '') { $brand = $info['brand']; $brandExplicit = $brand !== ''; }
        if ($type === '')  $type  = $info['appliance_type'];
    }
    if ($type === '') return null; // nothing to anchor the lookup on — e.g. a brand-hub page

    $tryTypes = error_codes_type_synonyms($type);

    $lookup = function (string $b) use ($data, $tryTypes): ?array {
        $brandData = $data[$b] ?? null;
        if (!is_array($brandData)) return null;
        foreach ($tryTypes as $t) {
            $entry = $brandData[$t] ?? null;
            if (is_array($entry) && !empty($entry['found']) && !empty($entry['codes'])) {
                return ['brand' => $b, 'type' => $t, 'entry' => $entry];
            }
        }
        return null;
    };

    $hit = null;
    if ($brand !== '') {
        $hit = $lookup($brand);
    } else {
        foreach (error_codes_brand_priority() as $b) {
            $hit = $lookup($b);
            if ($hit) break;
        }
    }
    if (!$hit) return null;

    $codes = array_values($hit['entry']['codes']);
    if ($max > 0 && count($codes) > $max) $codes = array_slice($codes, 0, $max);
    if (!$codes) return null;

    return [
        'brand'          => $hit['brand'],
        'brand_label'    => error_codes_brand_label($hit['brand']),
        'type'           => $hit['type'],
        'type_label'     => error_codes_type_label($hit['type']),
        'source_url'     => (string) ($hit['entry']['source_url'] ?? ''),
        'codes'          => $codes,
        'brand_explicit' => $brandExplicit,
    ];
}
