<?php
/**
 * includes/pluscities_writer.php — the writer contract from
 * docs/PLUSCITIES_FEATURE_SPEC.md Section 4: one rule — every specific,
 * checkable claim traces to a fact card, a dataset, or the client's profile.
 * General statements need no reference. Nothing from the model's own
 * knowledge, ever.
 *
 * Fact references are written inline as [[fact_id]] tags during generation,
 * extracted from the actual body text (not a separate, possibly-inconsistent
 * list the model could get out of sync with what it actually cited), then
 * stripped at render — pluscities_render_block() is the one function both the
 * publish step and any preview screen call, so the stripping logic exists
 * exactly once.
 */

/**
 * The standard client-profile facts a spine block may state directly (per
 * spec Section 4's "client profile" allowed source) — one place, so every
 * block that needs them builds the same list the same way.
 */
function pluscities_client_facts_for_writer(array $client): array
{
    $licenses = array_map(fn($l) => ($l['trade'] ?? '') . ' license ' . ($l['number'] ?? '') . ' (' . ($l['state'] ?? '') . ')', $client['licenses'] ?? []);
    $priceRanges = [];
    foreach ($client['price_ranges'] ?? [] as $range) {
        $priceRanges[] = is_array($range) ? implode(' ', $range) : (string) $range;
    }
    return [
        'years_in_business' => $client['years_in_business'] ?? null,
        'licenses'          => $licenses ? implode('; ', $licenses) : null,
        'phone'             => $client['identity']['hubs'][0]['phone'] ?? null,
        // Only a client fact when the client actually supplied one — the
        // generic writer prompt already forbids stating anything outside
        // this list, so cost_factors (the one block type that discusses
        // pricing) naturally stays qualitative-only unless real figures
        // exist here; pluscities_check_invented_prices() fails any other
        // dollar amount regardless.
        'price_ranges'      => $priceRanges ? implode('; ', $priceRanges) : null,
        '_banned_phrases'   => $client['banned_phrases'] ?? [],
    ];
}

/** Pulls every [[fact_id]] tag that's actually present in a body of text. */
function pluscities_extract_fact_ids(string $body): array
{
    preg_match_all('/\[\[([a-z0-9_\-]+)\]\]/i', $body, $m);
    return array_values(array_unique($m[1]));
}

/**
 * A tag citing a fact that was never offered is a model-invented citation —
 * real failure mode, confirmed in testing (the model fabricated a plausible
 * second fact, with a fake id, rather than leaving it out). Silently
 * stripping the bad tag and keeping the surrounding sentence would leave an
 * ungrounded claim in the rendered prose once the tag disappears at render,
 * exactly what the writer contract exists to prevent — so this is checked as
 * a hard failure, not cleaned up quietly.
 *
 * @return string|null null if every cited tag is valid; otherwise the first invalid id found
 */
function pluscities_find_invented_tag(string $body, array $validIds): ?string
{
    foreach (pluscities_extract_fact_ids($body) as $tag) {
        if (!in_array($tag, $validIds, true)) return $tag;
    }
    return null;
}

/**
 * Writes one AI-authored (editable) block, grounded in up to two allowed
 * sources per spec Section 4: fact cards (need a [[fact_id]] tag on every
 * specific claim) and the client's own profile (stated directly, no tag —
 * there's no external source_url to cite, it's the client's own stated fact).
 * General, non-specific statements need neither. $fact_cards may be empty if
 * the block is grounded entirely in client facts (e.g. Hero, which makes no
 * externally-sourced claims at all).
 *
 * @param array $fact_cards   rows from pluscities_fact_card_get_active()
 * @param array $client_facts plain label => value pairs from the client's own
 *              profile (years in business, license, banned phrases to avoid)
 * @param array $context      ['city_label','service_label','business_name']
 * @return array{ok:bool,msg:string,content:?array,fact_ids:array}
 */
function pluscities_write_editable_block(string $block_type, string $heading_hint, array $fact_cards, array $client_facts, array $context): array
{
    if (!$fact_cards && !$client_facts) {
        return ['ok' => false, 'msg' => 'No fact cards or client facts to write from.', 'content' => null, 'fact_ids' => []];
    }

    $factList = '';
    foreach ($fact_cards as $fc) {
        $factList .= '- [[' . $fc['id'] . ']]: ' . $fc['claim'] . "\n";
    }
    $bannedList = $client_facts['_banned_phrases'] ?? [];
    $clientFactList = '';
    foreach ($client_facts as $label => $value) {
        if ($label === '_banned_phrases' || $value === '' || $value === null) continue;
        $clientFactList .= "- $label: $value\n";
    }

    $prompt = "Write one short section (heading + 1-2 short paragraphs) for a local-service landing page. "
        . "Section topic: $heading_hint. City: {$context['city_label']}. Service: {$context['service_label']}. "
        . "Business performing the work: {$context['business_name']}.\n\n"
        . "Two kinds of facts are available, used differently:\n"
        . "1) Externally-sourced facts — EVERY specific claim (a number, a rule, a name, a date, a "
        . "requirement) you state from these must be immediately followed by its [[fact_id]] tag, copied "
        . "exactly as shown:\n$factList\n"
        . "2) The client's own facts about themselves — state these directly, in plain text, NEVER inside "
        . "[[ ]] brackets (that bracket syntax is reserved only for the externally-sourced fact_ids in "
        . "list 1 above, nothing else — do not bracket a license number, a phone number, or any other "
        . "client fact):\n$clientFactList\n"
        . "Do not state any specific fact that isn't in one of these two lists — if something relevant "
        . "isn't in either list, leave it out entirely rather than adding a plausible-sounding fact of "
        . "your own. Only tag ids copied exactly from the list above are ever accepted; a tag naming "
        . "anything else fails this generation outright. General, non-specific sentences (e.g. \"our "
        . "technicians can help\") need no tag."
        . ($bannedList ? ' Never use these exact phrases: ' . implode(', ', $bannedList) . '.' : '') . "\n\n"
        // The [[fact_id]] in this example is a FORMAT illustration, not literal
        // text to copy — confirmed in testing that with an empty fact list the
        // model sometimes echoes the placeholder itself. Show a format with no
        // bracket at all when there's nothing to cite, so there's no
        // placeholder for it to latch onto.
        . ($fact_cards
            ? "Reply as JSON only, no markdown fences: {\"heading\": \"...\", \"body\": \"... [[an id copied from list 1]] ...\"}"
            : "Reply as JSON only, no markdown fences: {\"heading\": \"...\", \"body\": \"...\"} — no [[ ]] brackets at all, there's nothing to cite.");

    $validIds = array_column($fact_cards, 'id');
    // One retry: a model hallucinating a second, plausible-sounding fact is
    // confirmed in testing, not hypothetical — silently stripping the bad tag
    // would leave the surrounding ungrounded sentence in the rendered prose
    // once the tag disappears at render, so this is a hard failure + retry,
    // not a cleanup.
    for ($attempt = 1; $attempt <= 2; $attempt++) {
        $res = anthropic_message($prompt, ['max_tokens' => 500]);
        if (!($res['ok'] ?? false)) {
            return ['ok' => false, 'msg' => $res['error'] ?? 'Writer call failed.', 'content' => null, 'fact_ids' => []];
        }
        $raw  = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim((string) $res['text']));
        $json = json_decode(trim($raw), true);
        if (!is_array($json) || empty($json['body'])) {
            return ['ok' => false, 'msg' => 'Writer did not return usable content.', 'content' => null, 'fact_ids' => []];
        }
        $invented = pluscities_find_invented_tag((string) $json['body'], $validIds);
        if ($invented === null) {
            $factIds = array_values(array_intersect(pluscities_extract_fact_ids((string) $json['body']), $validIds));
            return ['ok' => true, 'msg' => '', 'content' => ['heading' => (string) $json['heading'], 'body' => (string) $json['body']], 'fact_ids' => $factIds];
        }
    }
    return ['ok' => false, 'msg' => 'The writer cited a fact it was never given (' . $invented . ') twice in a row — refusing to accept it rather than silently dropping the claim.', 'content' => null, 'fact_ids' => []];
}

/**
 * Writes the FAQ spine block — a list of {question, answer, fact_ids}, not
 * heading+body, since its schema (FAQPage JSON-LD) needs discrete Q&A pairs.
 * Spec's own spine description: answers in HTML, match schema, at least half
 * city-specific — so at least one question must be grounded in a real,
 * city-scoped fact card, not just generic service questions.
 */
function pluscities_write_faq_block(array $fact_cards, array $client_facts, array $context): array
{
    $factList = '';
    foreach ($fact_cards as $fc) $factList .= '- [[' . $fc['id'] . ']]: ' . $fc['claim'] . "\n";
    $clientFactList = '';
    foreach ($client_facts as $label => $value) {
        if ($label === '_banned_phrases' || $value === '' || $value === null) continue;
        $clientFactList .= "- $label: $value\n";
    }
    $bannedList = $client_facts['_banned_phrases'] ?? [];

    $prompt = "Write 3 short FAQ question/answer pairs for a local-service landing page. "
        . "City: {$context['city_label']}. Service: {$context['service_label']}. Business: {$context['business_name']}.\n\n"
        . "At least one question must be answered using one of these city-specific facts (tag the claim "
        . "with its [[fact_id]], copied exactly):\n$factList\n"
        . "The client's own facts, stated directly in plain text, NEVER inside [[ ]] brackets (that "
        . "bracket syntax is reserved only for the externally-sourced fact_ids in the list above, nothing "
        . "else — do not bracket a license number, a phone number, a client fact's label, or any other "
        . "client fact):\n$clientFactList\n"
        . "The rest may be general service questions needing no specific fact at all. Never state a "
        . "specific fact that isn't in one of the two lists above — if nothing relevant is available, leave "
        . "it out rather than adding a plausible-sounding fact of your own. Only tag ids copied exactly "
        . "from the list above are ever accepted; any other tag fails this generation outright."
        . ($bannedList ? ' Never use these exact phrases: ' . implode(', ', $bannedList) . '.' : '') . "\n\n"
        . "Reply as JSON only, no markdown fences: {\"faqs\": [{\"question\": \"...\", \"answer\": \"... [[fact_id]] ...\"}]}";

    $validIds = array_column($fact_cards, 'id');
    for ($attempt = 1; $attempt <= 2; $attempt++) {
        $res = anthropic_message($prompt, ['max_tokens' => 600]);
        if (!($res['ok'] ?? false)) return ['ok' => false, 'msg' => $res['error'] ?? 'Writer call failed.', 'content' => null, 'fact_ids' => []];
        $raw  = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim((string) $res['text']));
        $json = json_decode(trim($raw), true);
        if (!is_array($json) || empty($json['faqs'])) return ['ok' => false, 'msg' => 'Writer did not return usable FAQ content.', 'content' => null, 'fact_ids' => []];

        $invented = null;
        foreach ($json['faqs'] as $faq) {
            $invented = pluscities_find_invented_tag((string) ($faq['answer'] ?? ''), $validIds);
            if ($invented !== null) break;
        }
        if ($invented === null) {
            $allFactIds = [];
            foreach ($json['faqs'] as $faq) {
                $allFactIds = array_merge($allFactIds, array_intersect(pluscities_extract_fact_ids((string) ($faq['answer'] ?? '')), $validIds));
            }
            return ['ok' => true, 'msg' => '', 'content' => ['faqs' => $json['faqs']], 'fact_ids' => array_values(array_unique($allFactIds))];
        }
    }
    return ['ok' => false, 'msg' => 'The writer cited a fact it was never given (' . $invented . ') twice in a row — refusing to accept it rather than silently dropping the claim.', 'content' => null, 'fact_ids' => []];
}

/**
 * Service area + links — Automatic, not AI-written at all: it's purely
 * structural data (drive time, internal links), with no specific claim that
 * isn't already a client-profile/page-record fact, so there's nothing for a
 * model to add. Per spec Section 5's spine description.
 */
function pluscities_write_service_area_block(array $client, array $page, array $niche): array
{
    $target = null;
    foreach ($client['target_cities'] ?? [] as $tc) {
        if (($tc['city_id'] ?? '') === $page['city_id']) { $target = $tc; break; }
    }
    $hub = $client['identity']['hubs'][0] ?? [];
    $mainServiceUrl = 'https://' . $client['domain'] . '/' . $page['service_key'] . '/';
    // Per-client, configurable — never hardcoded, per spec Section 5/15 (the
    // existing WP plugin already has this as lpc_base_path; fixing it
    // platform-wide here would be a real regression from what agencies can
    // already do today).
    $basePath = '/' . trim($client['url_base_path'] ?? 'service-area', '/') . '/';

    $otherServiceLinks = [];
    foreach ($client['services'] ?? [] as $svcSlug) {
        if ($svcSlug === $page['service_key']) continue;
        $otherServiceLinks[] = ['label' => $svcSlug, 'url' => $basePath . $page['city_id'] . '/' . $svcSlug . '/'];
    }

    return ['ok' => true, 'msg' => '', 'content' => [
        'drive_minutes'   => $target['drive_minutes'] ?? null,
        'hub_address'     => $hub['address'] ?? '',
        'main_service_url' => $mainServiceUrl,
        'other_services_this_city' => $otherServiceLinks,
        'service_area_url' => $basePath,
    ], 'fact_ids' => []];
}

/**
 * Writes the niche data table block — Automatic, and unlike every other
 * automatic block, not per-city at all: the niche blueprint's own
 * niche_table (editorially authored once, shared by every client/city in the
 * niche, same "research once, reuse everywhere" pattern as image_slots) IS
 * the content. No AI call — there's no per-page variation to write, just the
 * niche's current table.
 */
function pluscities_write_niche_data_table_block(array $niche): array
{
    $table = $niche['niche_table'] ?? null;
    if (!$table || empty($table['rows'])) {
        return ['ok' => false, 'msg' => 'No reference table is set up for this niche yet.', 'content' => null, 'fact_ids' => []];
    }
    return ['ok' => true, 'msg' => '', 'content' => [
        'title'   => $table['title'] ?? '',
        'columns' => $table['columns'] ?? [],
        'rows'    => $table['rows'],
    ], 'fact_ids' => []];
}

/**
 * Renders the trust bar strip, spec-adjacent polish matching the fleet's own
 * reference sites' hero-adjacent checkmark row. Niche-level content
 * (generically true for any real client in the niche, e.g. "Licensed &
 * Insured") — never a specific number like years-in-business or a review
 * count, since those vary per client and this one bar is shared by every
 * client in the niche.
 */
function pluscities_render_trust_bar(array $items): string
{
    $html = '<div class="lpc-trust-bar"><ul>';
    foreach ($items as $item) {
        $html .= '<li>&#10003; ' . htmlspecialchars($item) . '</li>';
    }
    return $html . '</ul></div>';
}

/**
 * Writes one Automatic (data-built) block. The "writer" here is a template
 * filling in real dataset values plus one short, non-specific AI intro
 * sentence — not a citation-heavy block, since there's no claim to cite, just
 * numbers from the dataset itself (the provider IS the source, spec Section 3).
 */
function pluscities_write_automatic_block(string $block_type, array $dataset, array $context): array
{
    $prompt = "Write ONE short, general sentence (no specific numbers, no specific claims) introducing a "
        . "local housing-stock data section for {$context['city_label']}, for a " . $context['service_label']
        . " business's landing page. Reply as plain text, one sentence only, no JSON, no quotes.";
    $res = anthropic_message($prompt, ['max_tokens' => 80]);
    $intro = ($res['ok'] ?? false) ? trim((string) $res['text']) : '';

    return ['ok' => true, 'msg' => '', 'content' => [
        'intro' => $intro,
        'dataset_values' => $dataset['values'] ?? [],
    ], 'fact_ids' => []];
}

/**
 * Renders one stored block row to display HTML — fact-id tags stripped, a
 * source line built from the cards actually cited. The ONE function both
 * publish and any preview screen call (spec Section 4's "fact references are
 * removed at render" rule, kept in one place).
 */
function pluscities_render_block(array $block_row, ?array $client = null, ?array $niche = null): string
{
    $content = json_decode($block_row['content_json'], true) ?: [];
    $factIds = json_decode($block_row['fact_ids'], true) ?: [];

    if ($block_row['block_type'] === 'reviews_from_city') {
        $html = '<section class="lpc-reviews"><h3>What Clients Are Saying</h3>';
        foreach ($content['reviews'] ?? [] as $rev) {
            $html .= '<blockquote>"' . htmlspecialchars($rev['text']) . '" &mdash; '
                . htmlspecialchars($rev['author']) . ' (' . (int) $rev['rating'] . '/5)</blockquote>';
        }
        return $html . '</section>';
    }

    if ($block_row['block_type'] === 'niche_data_table') {
        $html = '<section class="lpc-niche-data-table">';
        if (!empty($content['title'])) $html .= '<h3>' . htmlspecialchars($content['title']) . '</h3>';
        $html .= '<table><thead><tr>';
        foreach ($content['columns'] ?? [] as $col) $html .= '<th>' . htmlspecialchars($col) . '</th>';
        $html .= '</tr></thead><tbody>';
        foreach ($content['rows'] ?? [] as $row) {
            $html .= '<tr>';
            foreach ($row as $cell) $html .= '<td>' . htmlspecialchars($cell) . '</td>';
            $html .= '</tr>';
        }
        return $html . '</tbody></table></section>';
    }

    if ($block_row['block_type'] === 'housing_stock') {
        // Human-readable labels, not raw field names — "income_median: 146158"
        // reads as a debug dump, not content; real SEO value comes from
        // genuine local specificity read as a sentence, not a key/value list.
        $labels = [
            'income_median'     => 'Median household income',
            'home_value_median' => 'Median home value',
            'year_built_median' => 'Median year homes were built',
            'homeownership_pct' => 'Homeownership rate',
        ];
        $html = '<section class="lpc-housing-stock"><h3>Local Housing Stock</h3>';
        if (!empty($content['intro'])) $html .= '<p>' . htmlspecialchars($content['intro']) . '</p>';
        $html .= '<ul>';
        foreach ($content['dataset_values'] ?? [] as $k => $v) {
            if ($v === null) continue;
            $label = $labels[$k] ?? $k;
            $display = $k === 'income_median' || $k === 'home_value_median' ? '$' . number_format((float) $v)
                : ($k === 'homeownership_pct' ? number_format((float) $v, 1) . '%' : (string) $v);
            $html .= '<li>' . htmlspecialchars($label) . ': ' . htmlspecialchars($display) . '</li>';
        }
        return $html . '</ul><p class="lpc-source">Source: US Census ACS 5-Year Estimates</p></section>';
    }

    if ($block_row['block_type'] === 'faq') {
        $html = '<section class="lpc-faq"><h2>Frequently Asked Questions</h2>';
        foreach ($content['faqs'] ?? [] as $faq) {
            $answer = preg_replace('/\s*\[\[[a-z0-9_\-]+\]\]/i', '', (string) ($faq['answer'] ?? ''));
            $html .= '<div class="lpc-faq-item"><h3>' . htmlspecialchars((string) ($faq['question'] ?? '')) . '</h3>'
                . '<p>' . htmlspecialchars($answer) . '</p></div>';
        }
        return $html . '</section>';
    }

    if ($block_row['block_type'] === 'service_area') {
        $html = '<section class="lpc-service-area"><h3>Service Area</h3>';
        if (!empty($content['hub_address'])) {
            $html .= '<p>Serving this area' . (!empty($content['drive_minutes']) ? ' — about ' . (int) $content['drive_minutes'] . ' min from our ' . htmlspecialchars($content['hub_address']) . ' location' : '') . '.</p>';
        }
        $html .= '<ul>';
        if (!empty($content['main_service_url'])) {
            $html .= '<li><a href="' . htmlspecialchars($content['main_service_url']) . '">More about this service</a></li>';
        }
        foreach ($content['other_services_this_city'] ?? [] as $link) {
            $html .= '<li><a href="' . htmlspecialchars($link['url']) . '">' . htmlspecialchars($link['label']) . ' in this city</a></li>';
        }
        if (!empty($content['service_area_url'])) {
            $html .= '<li><a href="' . htmlspecialchars($content['service_area_url']) . '">All service areas</a></li>';
        }
        return $html . '</ul></section>';
    }

    // Hero — a real two-column layout (text left, photo right), matching
    // the fleet's own proven reference sites (Bailey/Wren/Gann), not the
    // generic stacked heading+paragraph+image every other editable block
    // uses. Text comes first in the DOM (H1 before the image — better for
    // accessibility/SEO) with CSS putting it visually on the left.
    if ($block_row['block_type'] === 'hero') {
        $heading = htmlspecialchars((string) ($content['heading'] ?? ''));
        $body    = htmlspecialchars(preg_replace('/\s*\[\[[a-z0-9_\-]+\]\]/i', '', (string) ($content['body'] ?? '')));
        $phone   = $client['identity']['hubs'][0]['phone'] ?? '';
        $html = '<section class="lpc-hero"><div class="lpc-hero-row"><div class="lpc-hero-text">'
            . '<h1>' . $heading . '</h1><p>' . $body . '</p>';
        if ($phone) {
            $telHref = preg_replace('/[^0-9+]/', '', $phone);
            $html .= '<a class="lpc-cta-button" href="tel:' . htmlspecialchars($telHref) . '">Call ' . htmlspecialchars($phone) . '</a>';
        }
        $html .= '</div>';
        // The hero's photo — resolved per spec (client's own upload > their
        // branded niche default > the plain niche default), same function
        // the Photos screen itself shows, so what the client picks there is
        // exactly what renders here. $client/$niche are optional because
        // most callers (e.g. the admin block-list preview) only need text.
        if ($client && $niche) {
            $heroImg = pluscities_resolve_image_url($client, $niche, 'hero');
            if ($heroImg) {
                // Absolute — a client's WordPress site is a different origin
                // than this platform, so the root-relative path
                // pluscities_resolve_image_url() returns (correct for
                // same-origin admin screens) would 404 there.
                $html .= '<div class="lpc-hero-media"><img class="lpc-hero-photo" src="'
                    . htmlspecialchars(PLUSCITIES_PUBLIC_BASE . $heroImg) . '" alt="' . $heading . '"></div>';
            }
        }
        return $html . '</div></section>';
    }

    // Editable blocks: strip [[fact_id]] tags, build the source line from the
    // cards actually cited.
    $body = preg_replace('/\s*\[\[[a-z0-9_\-]+\]\]/i', '', (string) ($content['body'] ?? ''));
    $html = '<section class="lpc-' . htmlspecialchars($block_row['block_type']) . '">';
    $html .= '<h3>' . htmlspecialchars((string) ($content['heading'] ?? '')) . '</h3>'
        . '<p>' . htmlspecialchars($body) . '</p>';
    if ($factIds) {
        $sources = [];
        $st = pluscities_db()->prepare('SELECT source_title, source_url FROM fact_cards WHERE id = ?');
        foreach ($factIds as $fid) {
            $st->execute([$fid]);
            if ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                $key = $row['source_title'] . '|' . $row['source_url'];
                $sources[$key] = $row; // de-duped by source, not by fact
            }
        }
        if ($sources) {
            $html .= '<p class="lpc-source">Source: ';
            $parts = [];
            foreach ($sources as $s) {
                $parts[] = '<a href="' . htmlspecialchars($s['source_url']) . '">' . htmlspecialchars($s['source_title']) . '</a>';
            }
            $html .= implode(', ', $parts) . '</p>';
        }
    }
    return $html . '</section>';
}
