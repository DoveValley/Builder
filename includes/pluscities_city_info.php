<?php
/**
 * includes/pluscities_city_info.php — "City Information": a real, CC-licensed
 * photo of the city paired with a short AI-written narrative paragraph
 * (population/neighborhoods, strictly about the place, no sales framing) —
 * directly matching the "City Information Section + photo" every one of the
 * fleet's own reference sites (Wren/Bailey/Gann) carries as its own section,
 * distinct from the Service Area Map.
 *
 * City-level furniture, same discipline as Area Map/Housing Chart/Climate
 * Chart: generated once per city_id and cached; the public sync endpoint and
 * Page Styling preview only ever read the cache.
 *
 * THE PHOTO reuses plugins/city-image/fetch.php's city_image_fetch_for()
 * directly (required here, not plugins/city-image/plugin.php — same reason
 * Climate Chart requires plugins/image-data-chart/charts.php directly rather
 * than that plugin's own plugin.php: avoids re-running register_plugin()/
 * add_hook()). Confirmed safe to require standalone: fetch.php and
 * wikimedia.php touch no ACTIVE_SITE_DIR/fleet.db/session state (the only
 * ACTIVE_SITE_DIR reference in this plugin is save.php, which PlusCities
 * never touches) — the exact same risk class docs/lpc-vs-site-factory-
 * boundary.md §3 already blesses for plugins/image-data-chart/* and
 * plugins/image-area-map/*. Sources a real Wikipedia article lead image, not
 * a stock photo — graceful null when Wikipedia has none for a city (common
 * for smaller places), same "no data means no image" rule as everywhere else.
 *
 * THE NARRATIVE is adapted from the old multisite system's real, proven
 * "City Spotlight" prompt (confirmed via sites/water-site/data/ai_block_
 * types.json's city_spotlight entry, written by generate.py's
 * generate_site_spotlight() — "written once per CITY and shared across that
 * city's pages", the exact furniture discipline already used here) — NOT a
 * one-clause edit of it. Two real changes from the original: the opening
 * frame ("the website of {business}") is rewritten since this is shared
 * furniture with no single business to frame it around, and the "mention
 * {business} at most once" rule is dropped entirely rather than left
 * dangling. Keeps the original's real accuracy discipline unchanged: ground
 * every specific claim only in supplied facts, never invent population
 * figures/distances/landmarks/founding dates, and omit an angle entirely
 * when its input is blank.
 *
 * PlusCities can supply 2 of the original prompt's 5 inputs today —
 * population (infra_city_get()) and neighborhoods (the same 'neighborhoods'
 * fact-card topic the existing neighborhoods editable block already reads).
 * market_blurb/top_employers/industries aren't researched anywhere in
 * PlusCities yet; left blank, relying on the prompt's own "omit this angle"
 * rule. ACCEPTED TRADEOFF: with only 2 of 5 inputs, expect more sameness
 * across cities than the original usually had — not invented, just thinner.
 * A richer per-city economic research pipeline is a natural, separate future
 * enhancement if this proves worth sharpening.
 */

// Already auto-loaded by every PlusCities request via includes/functions.php's
// plugin autoloader (plugins/city-image/plugin.php requires this same file),
// but declared explicitly here too — same reasoning Climate Chart already
// applies to plugins/image-data-chart/charts.php: a real dependency should
// be visible at the top of the file that uses it, not left to incidental
// load order elsewhere.
require_once BASE_DIR . '/plugins/city-image/fetch.php';

const PLUSCITIES_CITY_INFO_NARRATIVE_PROMPT = <<<'PROMPT'
You are writing a short "City Information" panel about {city}, {state} for a
local-service website. This is a factual profile of {city} as a place — NOT a
sales pitch for any business.

Write 1-2 short paragraphs, 90-140 words total, that give a visitor a vivid,
accurate feel for {city}, {state}: its regional setting, what the local
economy is built on, and a little local character. Tone: warm, confident,
professional. Use fully original wording — do NOT copy encyclopedia phrasing
or read like a Wikipedia entry.

GROUND EVERY SPECIFIC CLAIM ONLY IN THIS VERIFIED RESEARCH about {city} (do
not introduce specifics beyond it):
- Approximate population: {population}
- Verified local areas you MAY name (pick at most TWO, woven naturally into a
  sentence — never a "we serve X, Y, Z" list): {neighborhoods}

ACCURACY RULES (critical): Rely only on the verified facts above plus
well-established general knowledge about {city}'s broader region and {state}.
Do NOT invent or state as fact: statistics beyond the population above,
distances or drive times, highway or route numbers, founding dates, named
landmarks, climate figures, local employers, or any detail not supported by
the research above. If a provided value is blank, simply omit that angle. If
unsure about a specific detail for {city}, describe the broader region
instead of inventing specifics. Use the standard two-letter abbreviation for
{state} where it reads naturally.

Return JSON only, no markdown fences: {"text": "the paragraph text; separate
two paragraphs with a single blank line; plain text, NO HTML tags"}
PROMPT;

/**
 * Only 'json'/'dir' are fixed paths — the photo's own filename
 * ("city-scenic-{slug}.webp") is decided by city_image_download()
 * (plugins/city-image/fetch.php) and read back from city_image_fetch_for()'s
 * own return value, not predicted here.
 */
function pluscities_city_info_paths(string $city_id): array
{
    return [
        'json' => PLUSCITIES_UPLOADS_DIR . 'city_photos/' . $city_id . '.json',
        'dir'  => PLUSCITIES_UPLOADS_DIR . 'city_photos/',
    ];
}

/**
 * Writes the city narrative via anthropic_message() — same primitive every
 * other writer function in this engine uses. Returns '' on any failure
 * (no fallback text, no invented paragraph).
 */
function pluscities_city_info_write_narrative(string $city, string $state, string $population, string $neighborhoods): string
{
    $prompt = strtr(PLUSCITIES_CITY_INFO_NARRATIVE_PROMPT, [
        '{city}' => $city, '{state}' => $state,
        '{population}' => $population !== '' ? $population : '(not available — omit this angle)',
        '{neighborhoods}' => $neighborhoods !== '' ? $neighborhoods : '(none verified — omit this angle)',
    ]);
    $res = anthropic_message($prompt, ['max_tokens' => 400]);
    if (!($res['ok'] ?? false)) return '';
    $raw = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim((string) $res['text']));
    $json = json_decode(trim($raw), true);
    return is_array($json) ? trim((string) ($json['text'] ?? '')) : '';
}

/**
 * Generates (or refreshes) one city's City Info (photo + narrative) and
 * caches it. Called from pluscities_create_page() and a one-time backfill —
 * NEVER from the public sync endpoint. Both halves are independent: a city
 * with no Wikipedia photo can still get a narrative, and vice versa — never
 * block one on the other.
 *
 * @return array{ok:bool,drawn:bool}  ok=false only when NEITHER half
 *   produced anything (nothing to cache at all).
 */
function pluscities_ensure_city_info(string $city_id, bool $force = false): array
{
    $city = infra_city_get($city_id);
    if (!$city) return ['ok' => false, 'drawn' => false];

    $p = pluscities_city_info_paths($city_id);
    if (!$force && is_file($p['json'])) {
        return ['ok' => true, 'drawn' => false];
    }

    $photoPath = null; $alt = ''; $credit = ''; $sourceUrl = '';
    if (!is_dir($p['dir'])) @mkdir($p['dir'], 0775, true);
    $fetched = city_image_fetch_for([
        'city'         => $city['city'],
        'state'        => $city['state'],
        'ss'           => $city['ss'],
        'city_slug'    => $city_id,
        'out_dir'      => rtrim($p['dir'], '/'),
        'store_prefix' => trim(PLUSCITIES_UPLOADS_URL, '/') . '/city_photos',
    ]);
    if ($fetched) {
        $photoPath = '/' . ltrim($fetched['path'], '/');
        $alt = $fetched['alt'];
        $credit = $fetched['credit'];
        $sourceUrl = $fetched['source'];
    }

    $neighborhoods = '';
    $facts = pluscities_fact_card_get_active('city', $city_id, 'neighborhoods');
    if ($facts) $neighborhoods = implode('; ', array_column($facts, 'claim'));
    $narrative = pluscities_city_info_write_narrative(
        $city['city'], $city['state'], (string) ($city['population'] ?? ''), $neighborhoods
    );

    if ($photoPath === null && $narrative === '') return ['ok' => false, 'drawn' => false];

    @file_put_contents($p['json'], json_encode([
        'photo_path' => $photoPath, 'alt' => $alt, 'credit' => $credit, 'source_url' => $sourceUrl,
        'narrative' => $narrative, 'updated_at' => gmdate('c'),
    ]));
    return ['ok' => true, 'drawn' => true];
}

/**
 * Renders the City Info section — a cache READ only, never triggers
 * generation. Returns '' if nothing has been generated for this city yet.
 */
function pluscities_render_city_info(string $city_id): string
{
    if ($city_id === '') return '';
    $p = pluscities_city_info_paths($city_id);
    if (!is_file($p['json'])) return '';
    $meta = json_decode((string) @file_get_contents($p['json']), true);
    if (!is_array($meta)) return '';
    $photoPath = $meta['photo_path'] ?? null;
    $narrative = trim((string) ($meta['narrative'] ?? ''));
    if (!$photoPath && $narrative === '') return '';

    $cityLabel = function_exists('pluscities_city_label') ? pluscities_city_label($city_id) : $city_id;

    $html = '<section class="lpc-city-info lpc-fullbleed"><div class="lpc-band-inner">'
        . '<h3>' . htmlspecialchars($cityLabel) . ' Information</h3><div class="lpc-city-info-grid">';
    if ($photoPath) {
        $imgUrl = PLUSCITIES_PUBLIC_BASE . $photoPath;
        $html .= '<figure class="lpc-city-info-figure"><img class="lpc-city-info-img" src="' . htmlspecialchars($imgUrl)
            . '" alt="' . htmlspecialchars((string) ($meta['alt'] ?? '')) . '" loading="lazy">';
        if (!empty($meta['credit'])) {
            $html .= '<figcaption class="lpc-city-info-credit">' . htmlspecialchars((string) $meta['credit']) . '</figcaption>';
        }
        $html .= '</figure>';
    }
    if ($narrative !== '') {
        $html .= '<div class="lpc-city-info-text">';
        foreach (preg_split('/\n\s*\n/', $narrative) as $para) {
            $para = trim($para);
            if ($para !== '') $html .= '<p>' . htmlspecialchars($para) . '</p>';
        }
        $html .= '</div>';
    }
    return $html . '</div></div></section>';
}
