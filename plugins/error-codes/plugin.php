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
 * SELF-CLEARABLE FLAG. Every code carries `self_clearable` -- true when the stored
 * meaning names a cause a homeowner can check without tools or parts (blocked
 * vent, door ajar, control lock, a power blip needing only a reset), false when
 * it names a component (board, sensor, pump, relay, compressor, house wiring).
 * It is a judgement about text already researched, NOT new information, and it
 * is stored in the brand files so it can be reviewed and corrected -- never
 * inferred at render time where nobody could audit it. error_codes_intro() uses
 * it to tell a visitor the one thing they actually want to know up front: can I
 * fix this myself, or am I calling someone?
 *
 * Isolated like every other plugin here (services_links, related_links): the
 * block's render case in includes/blocks.php calls this softly
 * (function_exists() guard), so a site with no researched data for its brand/type, or missing this
 * plugin entirely, is simply a block that never renders — never a broken one.
 */

register_plugin(
    'error_codes',
    'Error Codes',
    'Manufacturer fault-code lookup for the page\'s own appliance brand/type, sourced only from each manufacturer\'s official support documentation (plugins/error-codes/brands/*.json). A brand/type with no researched data renders nothing — never a guess, never a third-party source.',
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

/** Trademarked-name casing the brand files' slug keys can't carry (e.g. "ge-monogram"). */
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
    // BRAND-HUB PAGE: a brand but no appliance in the slug (whirlpool-appliance-repair).
    // There is no single appliance to list codes for, so instead of disappearing it
    // shows the most common code on EACH appliance that brand makes -- which is the
    // genuinely useful thing on a hub, and needs no research we don't already have.
    if ($type === '' && $brand !== '') return error_codes_resolve_hub($brand, $max);
    if ($type === '') return null; // no brand either (the master hub) — nothing to anchor on

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

    // Counted from the CAPPED set, not the whole pool, so the intro can never
    // claim a split the visible table doesn't show.
    $diy = 0;
    foreach ($codes as $c) if (!empty($c['self_clearable'])) $diy++;

    return [
        'brand'          => $hit['brand'],
        'brand_label'    => error_codes_brand_label($hit['brand']),
        'type'           => $hit['type'],
        'type_label'     => error_codes_type_label($hit['type']),
        'source_url'     => (string) ($hit['entry']['source_url'] ?? ''),
        'codes'          => $codes,
        'brand_explicit' => $brandExplicit,
        'hub'            => false,
        'diy_count'      => $diy,
        'service_count'  => count($codes) - $diy,
    ];
}

/** Appliance order for a brand-hub table — the ones people search for most, first,
 *  so a capped hub list drops the obscure appliance rather than the refrigerator. */
function error_codes_type_priority(): array {
    return ['refrigerator', 'washer', 'dryer', 'dishwasher', 'oven', 'range', 'cooktop', 'freezer'];
}

/** A hub needs enough rows to be worth a table; below this it would be a stub, so
 *  it disappears instead — the same rule as everywhere else here. Checked against
 *  the final deduped row count, not the appliance count. */
function error_codes_hub_min_types(): int { return 4; }

/**
 * Cross-appliance sampler for a brand-hub page: the FIRST (most common) researched
 * code for each appliance the brand makes, in error_codes_type_priority() order.
 * Each row carries its OWN type_label and source_url, because unlike a leaf page
 * these codes come from several different manufacturer documents and each one has
 * to stay individually traceable.
 */
function error_codes_resolve_hub(string $brand, int $max = 6): ?array {
    $data = error_codes_data();
    $brandData = $data[$brand] ?? null;
    if (!is_array($brandData)) return null;

    $ordered = error_codes_type_priority();
    foreach (array_keys($brandData) as $t) if (!in_array($t, $ordered, true)) $ordered[] = $t;

    $rows = [];
    $seen = [];
    foreach ($ordered as $t) {
        $entry = $brandData[$t] ?? null;
        if (!is_array($entry) || empty($entry['found']) || empty($entry['codes'])) continue;
        if ($max > 0 && count($rows) >= $max) break;

        // Take the first code NOT already on this table rather than the flat first:
        // brands reuse one code across products (Maytag's F9 E0 is both the oven and
        // the range), and a hub that printed the same row twice would read as padding.
        // Falling through to that appliance's next code keeps it represented instead.
        $pick = null;
        foreach ($entry['codes'] as $c) {
            $key = strtolower(trim((string) ($c['code'] ?? '')));
            if ($key === '' || isset($seen[$key])) continue;
            $pick = $c;
            $seen[$key] = true;
            break;
        }
        if ($pick === null) continue;   // every code this appliance has is already shown

        $rows[] = [
            'code'           => (string) ($pick['code'] ?? ''),
            'meaning'        => (string) ($pick['meaning'] ?? ''),
            'self_clearable' => !empty($pick['self_clearable']),
            'type_label'     => error_codes_type_label($t),
            'source_url'     => (string) ($entry['source_url'] ?? ''),
        ];
    }
    // Floor applies to the FINAL row count, not the number of appliances researched:
    // what matters is whether the rendered table is substantial, and dedupe can shrink it.
    if (count($rows) < error_codes_hub_min_types()) return null;

    $diy = 0;
    foreach ($rows as $r) if ($r['self_clearable']) $diy++;

    return [
        'brand'          => $brand,
        'brand_label'    => error_codes_brand_label($brand),
        'type'           => '',
        'type_label'     => '',
        'source_url'     => '',          // per-row instead; see the render case
        'codes'          => $rows,
        'brand_explicit' => true,
        'hub'            => true,
        'diy_count'      => $diy,
        'service_count'  => count($rows) - $diy,
    ];
}

/** Small cardinals read better than digits in prose; past twelve, digits do. */
function error_codes_num(int $n): string {
    static $w = [0 => 'none', 1 => 'one', 2 => 'two', 3 => 'three', 4 => 'four',
                 5 => 'five', 6 => 'six', 7 => 'seven', 8 => 'eight', 9 => 'nine',
                 10 => 'ten', 11 => 'eleven', 12 => 'twelve'];
    return $w[$n] ?? (string) $n;
}

/**
 * Which intro voice this SITE uses — fixed per domain, so every error-code page
 * on one site reads consistently instead of shuffling page to page, while two
 * sites built from the same factory and the same research data don't open with
 * the identical sentence. Seeded on the domain alone (never the page or city):
 * two city pages for the same brand/appliance must not contradict each other.
 */
function error_codes_lane(string $domain, int $lanes = 4): int {
    $domain = strtolower(trim($domain));
    if ($domain === '' || strpos($domain, '{') !== false) return 0; // unresolved token
    return (int) (crc32($domain) % $lanes);
}

/**
 * The intro sentence, composed from THIS combo's own numbers rather than
 * rotated from a list of rephrasings. The split between "you can probably
 * clear this yourself" and "this needs a technician" is the actual question a
 * visitor with a broken appliance is asking, and it differs per brand/type on
 * its own -- so the variation is earned by the data instead of manufactured.
 * Reads self_clearable, which lives in brands/*.json where it can be reviewed
 * and corrected; nothing here infers it at render time.
 */
function error_codes_intro(array $ec, string $domain = ''): string {
    $n = count($ec['codes']);
    if ($n === 0) return '';
    $d = (int) ($ec['diy_count'] ?? 0);
    $v = (int) ($ec['service_count'] ?? 0);
    $brand = $ec['brand_label'];
    $type  = strtolower($ec['type_label']);
    $N = error_codes_num($n);
    $D = error_codes_num($d);
    $V = error_codes_num($v);
    $codeWord = $n === 1 ? 'code' : 'codes';

    // A type-hub page cites one representative manufacturer, so the caveat that
    // codes differ by brand has to come FIRST -- it is the honest framing, and
    // it matters more than any voice variation.
    if (empty($ec['brand_explicit'])) {
        $lead = 'Error codes vary by manufacturer — below is what the most common ones mean on '
              . $brand . ' units. A different brand will use different codes, but the fix usually '
              . 'starts the same way: note the exact code, then check it against your own model\'s manual.';
        if ($d && $v) {
            $lead .= ' Of the ' . $N . ' here, ' . $D . ' are usually a check you can make yourself and '
                   . $V . ' need a technician.';
        }
        return $lead;
    }

    // A hub lists one code per appliance, so the framing is "which of your
    // appliances is this" rather than "which code on this appliance".
    if (!empty($ec['hub'])) {
        // "a common one", not "the most common one": the dedupe above can surface an
        // appliance's second code when its first is already on the table.
        $lead = $brand . ' uses a different family of codes on each appliance it makes. Below is a '
              . 'common one for each — ';
        if ($d === 0)      return $lead . 'all ' . $N . ' point to a part inside the machine that needs a technician.';
        if ($v === 0)      return $lead . 'all ' . $N . ' are usually something you can check yourself first.';
        return $lead . $D . ' of the ' . $N . ' are usually something you can check yourself, and ' . $V
             . ' point to a part that needs a technician.';
    }

    // A combo with exactly one researched code can't take any of the counting
    // sentences without reading as broken English ("all one of the codes below").
    if ($n === 1) {
        return $d === 1
            ? $brand . ' documents a single ' . $type . ' code, and it is usually something you can '
                . 'check yourself before calling anyone. Confirm the exact code first.'
            : $brand . ' documents a single ' . $type . ' code, and it points at a component inside the '
                . 'machine — generally a technician\'s job rather than a do-it-yourself fix.';
    }

    // Degenerate splits first -- a lane sentence that says "the other none" is worse
    // than no variation at all. Sub-Zero, for instance, is service-only end to end.
    if ($d === 0) {
        return 'Every one of the ' . $N . ' ' . $brand . ' ' . $type . ' ' . $codeWord . ' below points at a '
             . 'component inside the machine, so these generally need a technician rather than a '
             . 'do-it-yourself fix. Note the exact code first — it tells the tech what to bring.';
    }
    if ($v === 0) {
        return 'Good news: all ' . $N . ' of the ' . $brand . ' ' . $type . ' ' . $codeWord . ' below usually come '
             . 'down to something you can check yourself before calling anyone — a blockage, a latch, '
             . 'or a setting. Note the exact code, then work through it.';
    }

    switch (error_codes_lane($domain)) {
        case 1:
            return 'Write the exact code down before anything else — ' . $brand . ' reuses similar codes '
                 . 'across models, and the precise characters are what narrow it down. ' . ucfirst($D)
                 . ' of the ' . $N . ' below are usually a quick check you can make yourself; the other '
                 . $V . ' need a technician.';
        case 2:
            return 'Not every code means a repair bill. ' . ucfirst($D) . ' of these ' . $N . ' ' . $brand . ' '
                 . $type . ' ' . $codeWord . ' are usually something you can clear yourself; the remaining '
                 . $V . ' involve a part inside the unit.';
        case 3:
            return $brand . ' documents these ' . $N . ' ' . $codeWord . ' most often on ' . $type . 's. ' . ucfirst($D)
                 . ' typically trace to something simple — a blockage, a latch, a setting — and ' . $V
                 . ' to a component that needs replacing.';
        default:
            return 'Of the ' . $N . ' ' . $codeWord . ' below, ' . $D . ' usually come down to something you can '
                 . 'check yourself — a blocked filter, a kinked hose, a door that isn\'t latching. The other '
                 . $V . ' point to a part inside the machine that needs a technician.';
    }
}

/** Default for the "your code isn't listed" line. Absent key => this text;
 *  present but empty => the editor turned it off. See the render case. */
function error_codes_miss_default(): string {
    return 'Don\'t see your code? Call {phone} — we\'ll help you track it down.';
}
