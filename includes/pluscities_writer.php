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

/** Pulls every [[fact_id]] tag that's actually present in a body of text. */
function pluscities_extract_fact_ids(string $body): array
{
    preg_match_all('/\[\[([a-z0-9_\-]+)\]\]/i', $body, $m);
    return array_values(array_unique($m[1]));
}

/**
 * Writes one AI-authored (editable) block from real fact cards. The model is
 * given ONLY the cards' claims, told to tag every specific claim it uses with
 * [[fact_id]], and nothing else — no open-world knowledge, per spec Section 4.
 *
 * @param array $fact_cards  rows from pluscities_fact_card_get_active()
 * @param array $context     ['city_label','service_label','business_name']
 * @return array{ok:bool,msg:string,content:?array,fact_ids:array}
 */
function pluscities_write_editable_block(string $block_type, string $heading_hint, array $fact_cards, array $context): array
{
    if (!$fact_cards) {
        return ['ok' => false, 'msg' => 'No active fact cards for this topic — nothing to write from.', 'content' => null, 'fact_ids' => []];
    }

    $factList = '';
    foreach ($fact_cards as $fc) {
        $factList .= '- [[' . $fc['id'] . ']]: ' . $fc['claim'] . "\n";
    }

    $prompt = "Write one short section (heading + 1-2 short paragraphs) for a local-service landing page. "
        . "Section topic: $heading_hint. City: {$context['city_label']}. Service: {$context['service_label']}. "
        . "Business performing the work: {$context['business_name']}.\n\n"
        . "Facts you may use — EVERY specific claim (a number, a rule, a name, a date, a requirement) you "
        . "state must be immediately followed by its [[fact_id]] tag from this list, with the tag copied "
        . "exactly as shown. Do not state any specific fact that isn't in this list. General, non-specific "
        . "sentences (e.g. \"our technicians can help\") need no tag.\n\n$factList\n"
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

    // Editable blocks: strip [[fact_id]] tags, build the source line from the
    // cards actually cited.
    $body = preg_replace('/\s*\[\[[a-z0-9_\-]+\]\]/i', '', (string) ($content['body'] ?? ''));
    $html = '<section class="lpc-' . htmlspecialchars($block_row['block_type']) . '">'
        . '<h3>' . htmlspecialchars((string) ($content['heading'] ?? '')) . '</h3>'
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
