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
    return [
        'years_in_business' => $client['years_in_business'] ?? null,
        'licenses'          => $licenses ? implode('; ', $licenses) : null,
        'phone'             => $client['identity']['hubs'][0]['phone'] ?? null,
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
        . "2) The client's own facts about themselves — state these directly, no tag needed (they're not "
        . "an external source to cite):\n$clientFactList\n"
        . "Do not state any specific fact that isn't in one of these two lists. General, non-specific "
        . "sentences (e.g. \"our technicians can help\") need no tag."
        . ($bannedList ? ' Never use these exact phrases: ' . implode(', ', $bannedList) . '.' : '') . "\n\n"
        . "Reply as JSON only, no markdown fences: {\"heading\": \"...\", \"body\": \"... [[fact_id]] ...\"}";

    $res = anthropic_message($prompt, ['max_tokens' => 500]);
    if (!($res['ok'] ?? false)) {
        return ['ok' => false, 'msg' => $res['error'] ?? 'Writer call failed.', 'content' => null, 'fact_ids' => []];
    }
    $raw  = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim((string) $res['text']));
    $json = json_decode(trim($raw), true);
    if (!is_array($json) || empty($json['body'])) {
        return ['ok' => false, 'msg' => 'Writer did not return usable content.', 'content' => null, 'fact_ids' => []];
    }

    $factIds = pluscities_extract_fact_ids((string) $json['body']);
    // Only facts actually offered can be cited — a tag the model invented
    // (wrong id, or one not in this call's fact list) is not trusted as a
    // real citation, matching the "nothing from the model's own knowledge"
    // rule applied to the tags themselves, not just the prose.
    $validIds = array_column($fact_cards, 'id');
    $factIds  = array_values(array_intersect($factIds, $validIds));

    return ['ok' => true, 'msg' => '', 'content' => ['heading' => (string) $json['heading'], 'body' => (string) $json['body']], 'fact_ids' => $factIds];
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
        . "The client's own facts, stated directly, no tag needed:\n$clientFactList\n"
        . "The rest may be general service questions needing no specific fact at all. Never state a "
        . "specific fact that isn't in one of the two lists above."
        . ($bannedList ? ' Never use these exact phrases: ' . implode(', ', $bannedList) . '.' : '') . "\n\n"
        . "Reply as JSON only, no markdown fences: {\"faqs\": [{\"question\": \"...\", \"answer\": \"... [[fact_id]] ...\"}]}";

    $res = anthropic_message($prompt, ['max_tokens' => 600]);
    if (!($res['ok'] ?? false)) return ['ok' => false, 'msg' => $res['error'] ?? 'Writer call failed.', 'content' => null, 'fact_ids' => []];
    $raw  = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim((string) $res['text']));
    $json = json_decode(trim($raw), true);
    if (!is_array($json) || empty($json['faqs'])) return ['ok' => false, 'msg' => 'Writer did not return usable FAQ content.', 'content' => null, 'fact_ids' => []];

    $validIds = array_column($fact_cards, 'id');
    $allFactIds = [];
    foreach ($json['faqs'] as &$faq) {
        $used = array_values(array_intersect(pluscities_extract_fact_ids((string) ($faq['answer'] ?? '')), $validIds));
        $allFactIds = array_merge($allFactIds, $used);
    }
    unset($faq);
    return ['ok' => true, 'msg' => '', 'content' => ['faqs' => $json['faqs']], 'fact_ids' => array_values(array_unique($allFactIds))];
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
 * Writes one Automatic (data-built) block. The "writer" here is a template
 * filling in real dataset values plus one short, non-specific AI intro
 * sentence — not a citation-heavy block, since there's no claim to cite, just
 * numbers from the dataset itself (the provider IS the source, spec Section 3).
 */
function pluscities_write_automatic_block(string $block_type, array $dataset, array $context): array
{
    $prompt = "Write ONE short, general sentence (no specific numbers, no specific claims) introducing a "
        . "local climate/housing data section for {$context['city_label']}, for a " . $context['service_label']
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
function pluscities_render_block(array $block_row): string
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

    if ($block_row['block_type'] === 'climate_data') {
        $html = '<section class="lpc-climate-data"><h3>Local Climate</h3>';
        if (!empty($content['intro'])) $html .= '<p>' . htmlspecialchars($content['intro']) . '</p>';
        $html .= '<ul>';
        foreach ($content['dataset_values'] ?? [] as $k => $v) {
            if ($v === null) continue;
            $html .= '<li>' . htmlspecialchars($k) . ': ' . htmlspecialchars((string) $v) . '</li>';
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

    // Editable blocks: strip [[fact_id]] tags, build the source line from the
    // cards actually cited. Hero's heading is the page's real H1 — every
    // other editable block is a sub-section (h3).
    $body = preg_replace('/\s*\[\[[a-z0-9_\-]+\]\]/i', '', (string) ($content['body'] ?? ''));
    $headingTag = $block_row['block_type'] === 'hero' ? 'h1' : 'h3';
    $html = '<section class="lpc-' . htmlspecialchars($block_row['block_type']) . '">'
        . '<' . $headingTag . '>' . htmlspecialchars((string) ($content['heading'] ?? '')) . '</' . $headingTag . '>'
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
