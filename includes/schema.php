<?php
/**
 * Schema (JSON-LD) helpers.
 *
 * FAQ schema is treated as a PROJECTION OF CONTENT: the FAQPage node is derived from
 * the page's current faq_two_col blocks at render time (see site-template.php), so the
 * structured data always matches the visible FAQ — regardless of when, or whether, the
 * AI filled it. FAQPage is therefore never authored by hand and never stored as truth;
 * any FAQPage found in the stored schema is replaced by the freshly-derived one.
 */

/**
 * Build FAQPage Question entities from a page's faq_two_col blocks.
 * Returns [] when there are no answered questions.
 */
function faq_pairs_from_blocks(array $blocks): array {
    $pairs = [];
    foreach ($blocks as $block) {
        if (!is_array($block) || ($block['type'] ?? '') !== 'faq_two_col') continue;
        foreach ($block['fq_items'] ?? [] as $item) {
            $q = trim(html_entity_decode(strip_tags($item['question'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $a = trim(html_entity_decode(strip_tags($item['answer']   ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($q !== '' && $a !== '') {
                $pairs[] = [
                    '@type'          => 'Question',
                    'name'           => $q,
                    'acceptedAnswer' => ['@type' => 'Answer', 'text' => $a],
                ];
            }
        }
    }
    return $pairs;
}

/**
 * Return $schemaJson with its FAQPage node replaced by one derived from $blocks
 * (or removed entirely when the page has no answered FAQ). All non-FAQ schema is
 * preserved. Shortcodes in the Q&A text are left intact for the caller to resolve.
 * Returns '' when the result would be empty (so the caller can skip emitting).
 */
function schema_apply_faqpage(string $schemaJson, array $blocks): string {
    $pairs      = faq_pairs_from_blocks($blocks);
    $schemaJson = trim($schemaJson);
    $decoded    = $schemaJson !== '' ? json_decode($schemaJson, true) : null;

    if (is_array($decoded) && isset($decoded['@graph']) && is_array($decoded['@graph'])) {
        // Existing @graph — drop any FAQPage, keep the rest.
        $wrapper = $decoded;
        $graph   = array_values(array_filter(
            $decoded['@graph'],
            fn($e) => !is_array($e) || ($e['@type'] ?? '') !== 'FAQPage'
        ));
    } elseif (is_array($decoded)) {
        // Single schema object — wrap it in a @graph (dropping it if it was a FAQPage).
        unset($decoded['@context']);
        $wrapper = ['@context' => 'https://schema.org', '@graph' => []];
        $graph   = ($decoded['@type'] ?? '') === 'FAQPage' ? [] : [$decoded];
    } else {
        // Empty or invalid existing schema.
        if (empty($pairs)) return '';
        $wrapper = ['@context' => 'https://schema.org', '@graph' => []];
        $graph   = [];
    }

    if (!empty($pairs)) {
        $graph[] = ['@type' => 'FAQPage', 'mainEntity' => $pairs];
    }
    if (empty($graph)) return '';

    if (!isset($wrapper['@context'])) $wrapper['@context'] = 'https://schema.org';
    $wrapper['@graph'] = $graph;

    return json_encode($wrapper, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
