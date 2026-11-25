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
    $html = '<div class="lpc-trust-bar lpc-fullbleed"><div class="lpc-band-inner"><ul>';
    foreach ($items as $item) {
        $html .= '<li>&#10003; ' . htmlspecialchars($item) . '</li>';
    }
    return $html . '</ul></div></div>';
}

/** Mid-page CTA band — solid dark band, bold headline, pill-shaped call
 *  button. Fixed position (right before the FAQ block) rather than a rhythm
 *  engine deciding placement — matches the fleet's own reference sites. */
function pluscities_render_cta_band(array $client): string
{
    $business = htmlspecialchars((string) ($client['identity']['business_name'] ?? ''));
    $phone    = $client['identity']['hubs'][0]['phone'] ?? '';
    if ($phone === '') return '';
    $telHref  = preg_replace('/[^0-9+]/', '', $phone);
    return '<section class="lpc-cta-band lpc-fullbleed"><div class="lpc-band-inner"><h2>Need ' . $business . '? Get fast, professional help.</h2>'
        . '<a class="lpc-cta-band-button" href="tel:' . htmlspecialchars($telHref) . '">Call Now &mdash; ' . htmlspecialchars($phone) . '</a></div></section>';
}

/**
 * Mirrors what WordPress's wpautop()/wp_kses_post() did for the old hub page's
 * Top/Bottom text fields, without needing WordPress: a blank line marks a real
 * paragraph break, a single newline becomes <br>, and a line containing only
 * "&nbsp;" is a deliberate spacer (real visible height) rather than collapsing
 * to nothing the way a genuinely blank line does. Runs through
 * sanitize_rich_html() first since this is admin-typed HTML, same trust level
 * as the Custom HTML city format.
 */
function pluscities_hub_format_text(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') return '';
    $clean = sanitize_rich_html($raw);

    $html = '';
    foreach (preg_split('/\n\s*\n/', $clean) as $chunk) {
        $chunk = trim($chunk);
        if ($chunk === '') continue;
        if (strcasecmp($chunk, '&nbsp;') === 0) {
            $html .= '<p>&nbsp;</p>';
        } elseif (preg_match('/^<(h2|h3|ul|ol|blockquote)\b/i', $chunk)) {
            // Already a block-level element the admin typed on purpose —
            // don't nest it inside a <p>.
            $html .= $chunk;
        } else {
            $html .= '<p>' . nl2br($chunk) . '</p>';
        }
    }
    return $html;
}

/**
 * 10 real spacer lines, blank-line-separated so each becomes its own
 * <p>&nbsp;</p> under pluscities_hub_format_text(). A genuinely blank footer
 * has no visible height on the live page, so this is the runtime default for
 * every client that hasn't typed footer text of its own — not just a
 * one-time prefill at client creation.
 */
function pluscities_hub_default_footer_text(): string
{
    return implode("\n\n", array_fill(0, 10, '&nbsp;'));
}

/**
 * <h2>Service Area</h2> is this page's one and only heading — there's no
 * separate page-level <h1> (admin/pluscities_sync.php deliberately doesn't
 * print one). A spacer line above and below gives it real breathing room on
 * the live page without the admin having to add that themselves. Same
 * runtime-default pattern as pluscities_hub_default_footer_text() — applies
 * to every client with no top text of its own, pre-filled into the editable
 * textarea rather than just an invisible fallback, so it's a real starting
 * point to edit, not a hidden assumption.
 */
function pluscities_hub_default_top_text(): string
{
    return implode("\n\n", [
        '&nbsp;',
        '<h2>Service Area</h2>',
        '{{business_name}} proudly serves the following areas:',
        '&nbsp;',
    ]);
}

/**
 * Renders the hub/listing page body: every one of this client's LIVE pages,
 * grouped by city, linking out to each. This is a client-level aggregate (no
 * page_id, no blocks table row of its own) — see project_lpc_to_pluscities_
 * migration in memory for why this page didn't exist in PlusCities until now.
 * A client with zero live pages yet (freshly onboarded) gets a "coming soon"
 * state rather than an empty or broken page.
 */
function pluscities_render_hub_blocks(array $client, ?array $niche): string
{
    $hub = $client['hub_content'] ?? [];
    $businessName = (string) ($client['identity']['business_name'] ?? '');
    $topTextRaw = trim((string) ($hub['top_text'] ?? ''));
    if ($topTextRaw === '') $topTextRaw = pluscities_hub_default_top_text();
    $topText    = pluscities_hub_format_text(str_replace('{{business_name}}', $businessName, $topTextRaw));
    $footerTextRaw = trim((string) ($hub['footer_text'] ?? ''));
    if ($footerTextRaw === '') $footerTextRaw = pluscities_hub_default_footer_text();
    $footerText = pluscities_hub_format_text(str_replace('{{business_name}}', $businessName, $footerTextRaw));
    $cityFormat = (string) ($hub['hub_city_format'] ?? 'simple');
    if (!in_array($cityFormat, ['simple', 'compact', 'detailed', 'custom'], true)) $cityFormat = 'simple';
    $cityFormatCustom = (string) ($hub['hub_city_format_custom'] ?? '');
    // Custom with nothing typed falls back to Simple List rather than
    // rendering an empty per-city block.
    if ($cityFormat === 'custom' && trim($cityFormatCustom) === '') $cityFormat = 'simple';

    $stmt = pluscities_db()->prepare(
        'SELECT * FROM pages WHERE client_id = ? AND status = "live" ORDER BY city_id, service_key'
    );
    $stmt->execute([$client['client_id']]);
    $pages = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $serviceLabels = [];
    foreach (($niche['services'] ?? []) as $svc) {
        $serviceLabels[$svc['slug']] = $svc['label'] ?? $svc['slug'];
    }

    $byCity = [];
    foreach ($pages as $p) {
        $byCity[$p['city_id']][] = $p;
    }

    $html = '<section class="lpc-hub">';
    if ($topText !== '') $html .= '<div class="lpc-hub-intro">' . $topText . '</div>';

    if (!$byCity) {
        $html .= '<p class="lpc-hub-empty">Service areas coming soon.</p>';
    } else {
        $html .= $cityFormat === 'compact' ? '<div class="lpc-hub-compact-list">' : '<div class="lpc-hub-grid">';
        foreach ($byCity as $cityId => $cityPages) {
            $cityLabel = htmlspecialchars(pluscities_city_label($cityId));
            $links = [];
            foreach ($cityPages as $p) {
                $label = $serviceLabels[$p['service_key']] ?? $p['service_key'];
                $links[] = '<a href="' . htmlspecialchars($p['slug']) . '">' . htmlspecialchars($label) . '</a>';
            }
            $servicesList = '<ul><li>' . implode('</li><li>', $links) . '</li></ul>';

            switch ($cityFormat) {
                case 'compact':
                    $html .= '<div class="lpc-hub-compact-row"><span class="lpc-hub-compact-city">' . $cityLabel
                        . '</span><span class="lpc-hub-compact-services">' . implode(' &nbsp;&middot;&nbsp; ', $links) . '</span></div>';
                    break;
                case 'detailed':
                    $count = count($cityPages);
                    $html .= '<div class="lpc-hub-card"><h3>' . $cityLabel . '</h3>'
                        . '<p class="lpc-hub-card-count">' . $count . ' service' . ($count === 1 ? '' : 's') . ' available</p>'
                        . $servicesList . '</div>';
                    break;
                case 'custom':
                    $filled = str_replace(['{{city}}', '{{services}}'], [$cityLabel, $servicesList], $cityFormatCustom);
                    $html .= '<div class="lpc-hub-custom">' . $filled . '</div>';
                    break;
                default: // simple
                    $html .= '<div class="lpc-hub-card"><h3>' . $cityLabel . '</h3>' . $servicesList . '</div>';
            }
        }
        $html .= '</div>';
    }

    if ($footerText !== '') $html .= '<div class="lpc-hub-footer">' . $footerText . '</div>';
    return $html . '</section>';
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
 * The full page stylesheet, factored out of admin/pluscities_sync.php's
 * action=page handler (2026-11-23) so the real delivery endpoint and any
 * admin-side preview screen (Platform Information's Page Styling tab) render
 * from the exact same CSS — the same "one function, not a drifting second
 * copy" rule pluscities_render_block()'s own doc comment already states for
 * HTML. Resolved colors come from pluscities_client_colors() either way
 * (manual override > detected from the client's live site > fixed fallback).
 */
function pluscities_page_style_css(array $client): string
{
    $resolvedColors = pluscities_client_colors($client);
    $brandColor = $resolvedColors['accent'];
    $darkColor  = $resolvedColors['dark'];
    // Hex -> "r,g,b" for the full-bleed Hero's overlay gradient, which needs
    // alpha stops (rgba) built from the client's own dark color rather than a
    // fixed black, so the overlay still reads as "this client's brand" even
    // on a photo-heavy hero.
    $darkRgb = sscanf(ltrim($darkColor, '#'), '%02x%02x%02x') ?: [31, 31, 37];
    $darkRgbCsv = implode(',', array_map('intval', $darkRgb));
    // Same conversion, for the alternating-band tint below — a light wash of
    // the client's own accent color, not a fixed gray, so the banding still
    // reads as "this client's brand" (same reasoning as the Hero overlay).
    $brandRgb = sscanf(ltrim($brandColor, '#'), '%02x%02x%02x') ?: [20, 32, 178];
    $brandRgbCsv = implode(',', array_map('intval', $brandRgb));
    return '<style>'
        // Safety net for the full-bleed bands below: breaking an element out
        // to 100vw can overflow by a few pixels on some browsers (100vw
        // includes the scrollbar's own width, a well-known quirk) — hidden
        // horizontal overflow at the body level is harmless and standard
        // practice, whereas overflow-x:hidden on .pluscities-page itself
        // would clip the intentional breakout and defeat the whole feature.
        . 'body{overflow-x:hidden;}'
        . '.lpc-' . htmlspecialchars($client['class_prefix']) . '{color:' . htmlspecialchars($darkColor) . ';}'
        . '.lpc-breadcrumbs{font-size:.82rem;color:#52607a;margin:0 0 14px;}'
        . '.lpc-breadcrumbs a{color:#52607a;}'
        . '.lpc-hero-photo{width:100%;max-width:100%;height:auto;border-radius:10px;display:block;}'
        . '.pluscities-page{max-width:1000px;margin:0 auto;}'
        . '.pluscities-page section[class^="lpc-"]{background:#fff;border:1px solid #9ca3af;border-radius:12px;padding:24px 28px;margin:0 0 20px;box-shadow:0 1px 3px rgba(0,0,0,.04);}'
        . '.pluscities-page section.lpc-hero{background:transparent;border:none;box-shadow:none;padding:0 0 8px;}'
        . '.pluscities-page section.lpc-hero-fullbleed{position:relative;background-size:cover;background-position:center;background-repeat:no-repeat;border-radius:14px;overflow:hidden;min-height:420px;display:flex;align-items:center;padding:0;}'
        // A directional gradient alone depends on each photo happening to be
        // dark where the text sits — real fleet photos won't reliably
        // cooperate (confirmed live: with this client's actual photo, body
        // text drifted into a light area and became unreadable). A solid
        // card behind the text guarantees contrast regardless of what's in
        // the photo; the light overall wash keeps the rest of the photo
        // still readable as a photo instead of fully darkened.
        . '.lpc-hero-overlay{position:absolute;inset:0;background:rgba(' . $darkRgbCsv . ',.25);}'
        . '.lpc-hero-fb-text{position:relative;z-index:1;margin:32px;padding:32px 36px;max-width:520px;background:rgba(' . $darkRgbCsv . ',.74);border-radius:14px;}'
        // Matches BOTH classes the real element carries (class="lpc-hero
        // lpc-hero-fullbleed"), not just one, so this is strictly MORE
        // specific than the generic ".pluscities-page section[class^="lpc-"]
        // p" paragraph-color rule below (tied specificity + later source
        // order was silently winning and reverting this text back to dark
        // gray on a dark card — found by pixel-sampling a live render, not
        // visible from the markup alone).
        . '.pluscities-page section.lpc-hero.lpc-hero-fullbleed h1{color:#fff;}'
        . '.pluscities-page section.lpc-hero.lpc-hero-fullbleed p{color:#e2e8f2;}'
        . '.lpc-hero-row{display:flex;flex-wrap:wrap;gap:36px;align-items:center;}'
        . '.lpc-hero-text{flex:1 1 360px;}'
        . '.lpc-hero-media{flex:1 1 360px;}'
        . '.lpc-cta-button{display:inline-block;background:' . htmlspecialchars($brandColor) . ';color:#fff;font-weight:700;text-decoration:none;padding:14px 28px;border-radius:8px;margin-top:8px;}'
        . '.pluscities-page h1{font-size:2.1rem;font-weight:800;color:' . htmlspecialchars($darkColor) . ';line-height:1.2;margin:0 0 14px;}'
        // Bleeds edge-to-edge (Scott's call, 2026-11-25 — matches the fleet's
        // own reference sites, where every band-style section runs full
        // browser width, not just text blocks) via the classic breakout:
        // left:50%/margin-left:-50vw re-centers a 100vw-wide box regardless
        // of how deep it sits inside the client's real WP theme markup,
        // without needing to know that theme's container width. padding/
        // border-radius are forced to 0 with !important so this utility
        // always wins regardless of how a specific band's own rule orders
        // relative to it — the lesson from the Hero specificity bug earlier
        // this session, applied up front instead of after finding the bug
        // live. The actual visual padding/max-width lives on the inner
        // ".lpc-band-inner" wrapper instead, one level in.
        // Confirmed broken on the real live theme (not just caught in
        // preview this time): "position:relative;left:50%;margin-left:-50vw"
        // mixes two different reference frames — "left" resolves against the
        // PARENT's width, "-50vw" against the true viewport width. Those only
        // agree when the parent happens to BE the viewport's full width;
        // Meade's real theme content column is narrower, so the band rendered
        // shifted right with text overflowing off-screen. Margin-only
        // (calc(50% - 50vw) on both sides, no position/left/right at all)
        // keeps every value in ONE consistent frame — percentages here and
        // vw there are combined inside the same calc() per side instead of
        // fighting each other as two separate properties.
        . '.pluscities-page .lpc-fullbleed{width:100vw;margin-left:calc(50% - 50vw);margin-right:calc(50% - 50vw);border-radius:0 !important;padding:0 !important;}'
        . '.lpc-band-inner{max-width:1000px;margin:0 auto;}'
        . '.lpc-trust-bar .lpc-band-inner{padding:18px 24px;}'
        . '.lpc-trust-bar{background:' . htmlspecialchars($darkColor) . ';margin:0 0 20px;}'
        . '.lpc-trust-bar ul{list-style:none;margin:0;padding:0;display:flex;flex-wrap:wrap;gap:12px 32px;justify-content:center;}'
        . '.lpc-trust-bar li{color:#fff;font-weight:600;font-size:.92rem;white-space:nowrap;}'
        . '.pluscities-page section[class^="lpc-"] h2,.pluscities-page section[class^="lpc-"] h3{font-size:1.25rem;font-weight:700;color:' . htmlspecialchars($darkColor) . ';margin:0 0 14px;padding-bottom:10px;border-bottom:2px solid ' . htmlspecialchars($brandColor) . ';display:inline-block;}'
        . '.pluscities-page section[class^="lpc-"] p{line-height:1.65;color:#334155;margin:0 0 12px;}'
        . '.pluscities-page section[class^="lpc-"] ul{padding-left:20px;line-height:1.7;color:#334155;}'
        // Found while reading this function: the citation line had no
        // dedicated rule at all, so it rendered identical to normal body
        // text — selector matches the generic paragraph rule's own
        // specificity plus one class so it reliably wins (the lesson from
        // the Hero specificity bug earlier this session: a plain ".lpc-
        // source" rule here would lose to the rule above on specificity,
        // regardless of source order).
        . '.pluscities-page section[class^="lpc-"] p.lpc-source{font-size:.82rem;font-style:italic;color:#64748b;margin-top:-4px;}'
        // Every block that falls through to the generic card look (reviews,
        // housing stock, the niche data table, and the ~16 plain editable
        // block types) reads as a wall of identical bordered boxes once a
        // real page stacks 8-10 of them — confirmed against this fleet's own
        // reference sites (wrenappliancerepair.com, baileyrestoration.com,
        // gannmoldremediation.com), none of which box a single block of text
        // at all. Alternating bands instead, no border/shadow — Hero/Trust
        // bar/FAQ/CTA band/Service area already have their own more-specific
        // rules above and are untouched by this.
        . '.pluscities-page section.lpc-alt-0{background:transparent;border:none;box-shadow:none;}'
        . '.pluscities-page section.lpc-alt-1{background:rgba(' . $brandRgbCsv . ',.06);border:none;box-shadow:none;}'
        . '.lpc-alt-0 .lpc-band-inner,.lpc-alt-1 .lpc-band-inner{padding:24px 28px;}'
        . '.lpc-faq-grid{display:grid;grid-template-columns:1fr;gap:12px;}'
        . '@media(min-width:720px){.lpc-faq-grid{grid-template-columns:1fr 1fr;}}'
        . '.pluscities-page .lpc-faq-item{background:#f1f5f9;border-radius:10px;padding:16px 18px;margin:0;}'
        . '.pluscities-page .lpc-faq-item summary{font-size:1.02rem;font-weight:700;color:' . htmlspecialchars($darkColor) . ';cursor:pointer;list-style:none;}'
        . '.pluscities-page .lpc-faq-item summary::-webkit-details-marker{display:none;}'
        . '.pluscities-page .lpc-faq-item summary::before{content:"+";display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;border-radius:50%;background:' . htmlspecialchars($brandColor) . ';color:#fff;font-weight:700;margin-right:10px;}'
        . '.pluscities-page .lpc-faq-item[open] summary::before{content:"\\2212";}'
        . '.pluscities-page .lpc-faq-item p{margin:10px 0 0 32px;}'
        . '.pluscities-page section.lpc-service-area{background:' . htmlspecialchars($brandColor) . ';text-align:center;}'
        . '.lpc-service-area .lpc-band-inner{padding:24px 28px;}'
        . '.pluscities-page section.lpc-service-area h2{color:#fff;border-bottom:none;padding-bottom:0;}'
        . '.pluscities-page section.lpc-service-area p{color:#fff;opacity:.9;}'
        . '.lpc-service-area-links{display:flex;flex-wrap:wrap;gap:10px;justify-content:center;margin-top:12px;}'
        . '.lpc-service-area-links a{background:#fff;color:' . htmlspecialchars($brandColor) . ';font-weight:700;text-decoration:none;padding:10px 20px;border-radius:999px;font-size:.9rem;}'
        . '.pluscities-page section.lpc-cta-band{background:' . htmlspecialchars($darkColor) . ';text-align:center;}'
        . '.lpc-cta-band .lpc-band-inner{padding:36px 28px;}'
        . '.pluscities-page section.lpc-cta-band h2{color:#fff;border-bottom:none;padding-bottom:0;font-size:1.5rem;}'
        . '.lpc-cta-band-button{display:inline-block;background:transparent;border:2px solid #fff;color:#fff;font-weight:700;text-decoration:none;padding:12px 28px;border-radius:999px;margin-top:8px;}'
        . '.pluscities-page table{width:100%;border-collapse:collapse;margin-top:4px;}'
        . '.pluscities-page table th,.pluscities-page table td{padding:10px 14px;border:1px solid #e5e7eb;text-align:left;font-size:.92rem;}'
        . '.pluscities-page table th{background:#f8fafc;font-weight:700;color:' . htmlspecialchars($darkColor) . ';}'
        . '.pluscities-page table tbody tr:nth-child(even){background:#fafbfc;}'
        . '</style>';
}

/**
 * Renders one stored block row to display HTML — fact-id tags stripped, a
 * source line built from the cards actually cited. The ONE function both
 * publish and any preview screen call (spec Section 4's "fact references are
 * removed at render" rule, kept in one place).
 */
function pluscities_render_block(array $block_row, ?array $client = null, ?array $niche = null, int $altIndex = 0): string
{
    // Which of 2 background bands this block gets (see pluscities_page_
    // style_css()'s ".lpc-alt-0"/".lpc-alt-1" rules) — a caller rendering a
    // real page in sequence passes its own running position; any caller that
    // doesn't care (single-block previews, diffs) gets the default 0, same
    // look every time, no regression.
    $altClass = ' lpc-alt-' . ($altIndex % 2) . ' lpc-fullbleed';
    $content = json_decode($block_row['content_json'], true) ?: [];
    $factIds = json_decode($block_row['fact_ids'], true) ?: [];

    if ($block_row['block_type'] === 'reviews_from_city') {
        $html = '<section class="lpc-reviews' . $altClass . '"><div class="lpc-band-inner"><h3>What Clients Are Saying</h3>';
        foreach ($content['reviews'] ?? [] as $rev) {
            $html .= '<blockquote>"' . htmlspecialchars($rev['text']) . '" &mdash; '
                . htmlspecialchars($rev['author']) . ' (' . (int) $rev['rating'] . '/5)</blockquote>';
        }
        return $html . '</div></section>';
    }

    if ($block_row['block_type'] === 'niche_data_table') {
        $html = '<section class="lpc-niche-data-table' . $altClass . '"><div class="lpc-band-inner">';
        if (!empty($content['title'])) $html .= '<h3>' . htmlspecialchars($content['title']) . '</h3>';
        $html .= '<table><thead><tr>';
        foreach ($content['columns'] ?? [] as $col) $html .= '<th>' . htmlspecialchars($col) . '</th>';
        $html .= '</tr></thead><tbody>';
        foreach ($content['rows'] ?? [] as $row) {
            $html .= '<tr>';
            foreach ($row as $cell) $html .= '<td>' . htmlspecialchars($cell) . '</td>';
            $html .= '</tr>';
        }
        return $html . '</tbody></table></div></section>';
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
        $html = '<section class="lpc-housing-stock' . $altClass . '"><div class="lpc-band-inner"><h3>Local Housing Stock</h3>';
        if (!empty($content['intro'])) $html .= '<p>' . htmlspecialchars($content['intro']) . '</p>';
        $html .= '<ul>';
        foreach ($content['dataset_values'] ?? [] as $k => $v) {
            if ($v === null) continue;
            $label = $labels[$k] ?? $k;
            $display = $k === 'income_median' || $k === 'home_value_median' ? '$' . number_format((float) $v)
                : ($k === 'homeownership_pct' ? number_format((float) $v, 1) . '%' : (string) $v);
            $html .= '<li>' . htmlspecialchars($label) . ': ' . htmlspecialchars($display) . '</li>';
        }
        return $html . '</ul><p class="lpc-source">Source: US Census ACS 5-Year Estimates</p></div></section>';
    }

    if ($block_row['block_type'] === 'faq') {
        // <details>/<summary> — no JS required (design doc rule #5), answer
        // text stays in the DOM even while collapsed so it's still present
        // for schema/crawlers either way. First item open by default, same
        // convention the design doc calls for. Two-column grid, matching the
        // fleet's own reference sites (Bailey) rather than a single stacked
        // list — reads as a distinct, designed section instead of more
        // generic heading+paragraph blocks.
        $html = '<section class="lpc-faq"><h2>Frequently Asked Questions</h2><div class="lpc-faq-grid">';
        $i = 0;
        foreach ($content['faqs'] ?? [] as $faq) {
            $answer = preg_replace('/\s*\[\[[a-z0-9_\-]+\]\]/i', '', (string) ($faq['answer'] ?? ''));
            $open = $i === 0 ? ' open' : '';
            $html .= '<details class="lpc-faq-item"' . $open . '><summary>' . htmlspecialchars((string) ($faq['question'] ?? '')) . '</summary>'
                . '<p>' . htmlspecialchars($answer) . '</p></details>';
            $i++;
        }
        return $html . '</div></section>';
    }

    if ($block_row['block_type'] === 'service_area') {
        // Full-width accent-colored band with pill-shaped white links —
        // matches the fleet's own reference sites' "Related Services" band,
        // not a plain bulleted list. Links that have nothing to point to
        // (e.g. other_services_this_city with an empty label/url) are
        // skipped rather than rendered as a blank pill.
        $links = [];
        if (!empty($content['main_service_url'])) {
            $links[] = ['url' => $content['main_service_url'], 'label' => 'More About This Service'];
        }
        foreach ($content['other_services_this_city'] ?? [] as $link) {
            if (empty($link['url']) || empty($link['label'])) continue;
            $links[] = ['url' => $link['url'], 'label' => $link['label'] . ' in This City'];
        }
        // Whether this link shows at all, its URL, and its label are all
        // decided fresh here from the client's LIVE settings — not from
        // $content['service_area_url'], which was only ever a snapshot of
        // url_base_path at generation time. Doing it this way means turning
        // the Service Area Page on/off, changing its slug, or editing the
        // pill text all take effect on every already-live page immediately,
        // with no regenerate/republish step needed.
        if (!empty($content['service_area_url']) && $client) {
            $hub = $client['hub_content'] ?? [];
            $hubEnabled  = $hub['enabled'] ?? true;
            $pillEnabled = $hub['pill_enabled'] ?? true;
            if ($hubEnabled && $pillEnabled) {
                $pillText = trim((string) ($hub['pill_text'] ?? ''));
                $livePath = '/' . trim($client['url_base_path'] ?? 'service-area', '/') . '/';
                $links[] = ['url' => $livePath, 'label' => $pillText !== '' ? $pillText : 'All Service Areas'];
            }
        } elseif (!empty($content['service_area_url'])) {
            // No $client in scope (shouldn't happen on a real render — kept
            // as a safe fallback for any future caller that omits it).
            $links[] = ['url' => $content['service_area_url'], 'label' => 'All Service Areas'];
        }

        $html = '<section class="lpc-service-area lpc-fullbleed"><div class="lpc-band-inner"><h2>Related Services</h2>';
        if (!empty($content['hub_address'])) {
            $html .= '<p>Serving this area' . (!empty($content['drive_minutes']) ? ' — about ' . (int) $content['drive_minutes'] . ' min from our ' . htmlspecialchars($content['hub_address']) . ' location' : '') . '.</p>';
        }
        if ($links) {
            $html .= '<div class="lpc-service-area-links">';
            foreach ($links as $link) {
                $html .= '<a href="' . htmlspecialchars($link['url']) . '">' . htmlspecialchars($link['label']) . '</a>';
            }
            $html .= '</div>';
        }
        return $html . '</div></section>';
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
        $ctaHtml = '';
        if ($phone) {
            $telHref = preg_replace('/[^0-9+]/', '', $phone);
            $ctaHtml = '<a class="lpc-cta-button" href="tel:' . htmlspecialchars($telHref) . '">Call ' . htmlspecialchars($phone) . '</a>';
        }
        // The hero's photo — resolved per spec (client's own upload > their
        // branded niche default > the plain niche default), same function
        // the Photos screen itself shows, so what the client picks there is
        // exactly what renders here. $client/$niche are optional because
        // most callers (e.g. the admin block-list preview) only need text.
        $heroImg = ($client && $niche) ? pluscities_resolve_image_url($client, $niche, 'hero') : null;

        // One of two Hero styles (Page Styling screen, 2026-11-25) — a
        // CLIENT-level setting, not a per-page one: nothing about a block's
        // STYLE varies by city or service in this engine, only its content
        // does (same reasoning as color/logo). Falls back to the two-column
        // layout if full_bleed is picked but no photo actually resolved — a
        // full-bleed hero with no photo is just an empty dark box.
        $heroStyle = 'two_column';
        if (is_array($client) && !empty($client['block_styles']['hero'])) {
            $heroStyle = $client['block_styles']['hero'];
        }
        if ($heroStyle === 'full_bleed' && $heroImg) {
            // Absolute — a client's WordPress site is a different origin
            // than this platform, so the root-relative path
            // pluscities_resolve_image_url() returns (correct for
            // same-origin admin screens) would 404 there.
            return '<section class="lpc-hero lpc-hero-fullbleed" style="background-image:url(\''
                . htmlspecialchars(PLUSCITIES_PUBLIC_BASE . $heroImg) . '\')">'
                . '<div class="lpc-hero-overlay"></div>'
                . '<div class="lpc-hero-fb-text"><h1>' . $heading . '</h1><p>' . $body . '</p>' . $ctaHtml . '</div>'
                . '</section>';
        }

        $html = '<section class="lpc-hero"><div class="lpc-hero-row"><div class="lpc-hero-text">'
            . '<h1>' . $heading . '</h1><p>' . $body . '</p>' . $ctaHtml . '</div>';
        if ($heroImg) {
            $html .= '<div class="lpc-hero-media"><img class="lpc-hero-photo" src="'
                . htmlspecialchars(PLUSCITIES_PUBLIC_BASE . $heroImg) . '" alt="' . $heading . '"></div>';
        }
        return $html . '</div></section>';
    }

    // Editable blocks: strip [[fact_id]] tags, build the source line from the
    // cards actually cited.
    $body = preg_replace('/\s*\[\[[a-z0-9_\-]+\]\]/i', '', (string) ($content['body'] ?? ''));
    $html = '<section class="lpc-' . htmlspecialchars($block_row['block_type']) . $altClass . '"><div class="lpc-band-inner">';
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
    return $html . '</div></section>';
}
