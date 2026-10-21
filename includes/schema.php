<?php
/**
 * Schema (JSON-LD) helpers.
 *
 * FAQ schema is treated as a PROJECTION OF CONTENT: the FAQPage node is derived from
 * the page's current faq_two_col blocks AND error_codes blocks at render time (see
 * site-template.php), so the structured data always matches what's visible —
 * regardless of when, or whether, the AI filled it, or which manufacturer codes this
 * page's error_codes block resolved to. FAQPage is therefore never authored by hand
 * and never stored as truth; any FAQPage found in the stored schema is replaced by
 * the freshly-derived one.
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
 * "a" or "an" for a brand name — judged by how the name is SPOKEN, not spelled.
 *
 * "a LG dryer" was appearing in every LG question: L is a consonant letter but the
 * letter's name begins with a vowel sound ("el"), so it takes "an". GE goes the other
 * way — "jee" is a consonant sound, so "a GE oven" is correct. An initialism is
 * detected by being all-caps, which is exactly the set where spelling misleads.
 */
function schema_indefinite_article(string $name): string {
    $name = trim($name);
    if ($name === '') return 'a';
    $first = strtoupper($name[0]);
    // Letter names that start with a vowel sound: F(ef) H(aitch) L(el) M(em) N(en)
    // R(ar) S(ess) X(ex), plus A E I O U themselves.
    $initialism = (bool) preg_match('/^[A-Z][A-Z0-9-]*$/', preg_replace('/\s.*$/', '', $name));
    $vowelSound = $initialism ? 'AEFHILMNORSX' : 'AEIOU';
    return strpos($vowelSound, $first) !== false ? 'an' : 'a';
}

/**
 * Build FAQPage Question entities from a page's error_codes blocks — same
 * "projection of content" rule as faq_pairs_from_blocks(): derived at render
 * time from whatever error_codes_resolve() (plugins/error-codes/plugin.php)
 * actually returns for THIS page, never authored/stored by hand, so the
 * structured data can never list a code the visible block doesn't show.
 * Soft-guarded — a site without the plugin (or with no error_codes.json)
 * simply contributes no pairs here. Returns [] when there's nothing to show.
 */
function error_code_pairs_from_blocks(array $blocks): array {
    if (!function_exists('error_codes_resolve')) return [];
    $pairs = [];
    $seen  = [];
    foreach ($blocks as $block) {
        if (!is_array($block) || ($block['type'] ?? '') !== 'error_codes') continue;
        $max = (int) ($block['ec_max'] ?? 12) ?: 12;
        $ec = error_codes_resolve($block['ec_brand'] ?? 'auto', $block['ec_type'] ?? 'auto', $max);
        if (!$ec) continue;
        $brandLabel = $ec['brand_label'];
        $typeLabel  = strtolower($ec['type_label']);
        foreach ($ec['codes'] as $row) {
            $code    = trim((string) ($row['code'] ?? ''));
            $meaning = trim((string) ($row['meaning'] ?? ''));
            if ($code === '' || $meaning === '') continue;
            // Brand-hub rows each belong to a DIFFERENT appliance, so the question has
            // to name that row's own appliance, not the block's (which is empty here).
            $rowType = strtolower(trim((string) ($row['type_label'] ?? '')));
            if ($rowType !== '') $typeLabel = $rowType;

            /*
             * ONE QUESTION PER DISPLAY CODE, NOT PER STORED ENTRY.
             *
             * A manufacturer shows one fault under several codes depending on model and
             * panel generation, so an entry's code is often a slash-joined set —
             * "4C / 4E / nF", "E16 / E17", "d75 / d80 / d90 / d95". Asking "What does
             * error code 4C / 4E / nF mean on a Samsung washer?" matches nothing anyone
             * types, while the three codes behind it are searched 5,400, 1,300 and 320
             * times a month respectively. 71 of 538 stored entries are compound, so this
             * turns ~92 unsearchable questions into exact-match ones.
             *
             * This does NOT break the projection rule. The visible table prints the whole
             * "4C / 4E / nF" string, so every code named here is one the block shows.
             */
            $display = preg_split('#\s*/\s*#', $code) ?: [$code];
            $detail  = is_array($row['detail'] ?? null) ? $row['detail'] : [];
            foreach ($display as $one) {
                $one = trim($one);
                if ($one === '') continue;
                $name = 'What does error code ' . $one . ' mean on '
                      . schema_indefinite_article($brandLabel) . ' ' . $brandLabel . ' ' . $typeLabel . '?';
                // Same code can appear under two entries (a brand reuses a code across
                // appliances); a repeated Question is invalid, so first one wins.
                $key = strtolower($name);
                if (isset($seen[$key])) continue;
                $seen[$key] = true;
                // Prefer the written answer for THIS code when one exists — it is
                // specific to the code rather than shared across the compound set, and
                // it is what the visible page says a few hundred pixels above.
                $answer = trim((string) ($detail[$one]['what_it_means'] ?? '')) ?: $meaning;
                $pairs[] = [
                    '@type'          => 'Question',
                    'name'           => $name,
                    'acceptedAnswer' => ['@type' => 'Answer', 'text' => $answer],
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
    $pairs      = array_merge(faq_pairs_from_blocks($blocks), error_code_pairs_from_blocks($blocks));
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

/**
 * Return $schemaJson with the LocalBusiness node's sameAs set from $socials —
 * same "projection of content" approach as schema_apply_faqpage: sameAs is
 * never authored by hand, it is always derived from the site's real social
 * links (Footer tab) at render time, so it can never go stale and never gets
 * fabricated when no profiles are set. Only the node whose @id ends in
 * "#localbusiness" (the convention every schema prompt in this codebase
 * follows) is touched; everything else in $schemaJson is untouched.
 */
function schema_apply_sameas(string $schemaJson, array $socials): string {
    $schemaJson = trim($schemaJson);
    if ($schemaJson === '') return $schemaJson;
    $decoded = json_decode($schemaJson, true);
    if (!is_array($decoded)) return $schemaJson;

    $urls = [];
    foreach ($socials as $url) {
        $url = trim((string) $url);
        if ($url !== '' && preg_match('#^https?://#i', $url)) $urls[] = $url;
    }

    $hasGraph = isset($decoded['@graph']) && is_array($decoded['@graph']);
    $nodes    = $hasGraph ? $decoded['@graph'] : [$decoded];
    $touched  = false;

    foreach ($nodes as &$node) {
        if (!is_array($node)) continue;
        if (!str_ends_with((string) ($node['@id'] ?? ''), '#localbusiness')) continue;
        if ($urls) { $node['sameAs'] = $urls; } else { unset($node['sameAs']); }
        $touched = true;
    }
    unset($node);

    if (!$touched) return $schemaJson; // no business node in this schema — leave untouched

    if ($hasGraph) { $decoded['@graph'] = $nodes; } else { $decoded = $nodes[0]; }
    return json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

/**
 * Build a BlogPosting node from a page's post_meta block.
 *
 * Same projection rule as FAQPage: blog posts carry no stored schema at all (blog.php
 * and static_build.php both synthesise $seo from $post['seo'], which has no 'schema'
 * key), and the byline the visitor reads — date, author, tag — is already sitting in
 * the post_meta pseudo-block that those two prepend as element 0. Deriving the node
 * from that block means the markup can never disagree with the visible byline, and
 * no post has to be re-authored to gain structured data.
 *
 * Returns null when the page is not a blog post, or when the post has no usable
 * headline — a BlogPosting without one is invalid, and an invalid node is worse than
 * no node. Shortcodes ({business} in the author name, {website} in the publisher @id)
 * are left intact for the caller to resolve, matching schema_apply_faqpage.
 */
function blogposting_from_blocks(array $blocks, array $seo = [], string $imageUrl = ''): ?array {
    $meta = null;
    foreach ($blocks as $block) {
        if (is_array($block) && ($block['type'] ?? '') === 'post_meta') { $meta = $block; break; }
    }
    if ($meta === null) return null;

    $headline = trim(html_entity_decode(strip_tags($meta['title'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($headline === '') return null;

    $node = [
        '@type'    => 'BlogPosting',
        'headline' => $headline,
        'inLanguage' => 'en',
    ];

    // mainEntityOfPage is what ties the node to this URL. canonical_url is the only
    // absolute, per-post URL available this early in the render.
    $canonical = trim((string) ($seo['canonical_url'] ?? ''));
    if ($canonical !== '') {
        $node['@id']              = $canonical . '#blogposting';
        $node['mainEntityOfPage'] = ['@type' => 'WebPage', '@id' => $canonical];
    }

    $description = trim(html_entity_decode(strip_tags(
        ($meta['excerpt'] ?? '') !== '' ? $meta['excerpt'] : ($seo['meta_description'] ?? '')
    ), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($description !== '') $node['description'] = $description;

    // Dates are stored as plain Y-m-d. Emit only what validates as a date, so a blank
    // or half-filled field never produces a malformed datePublished.
    $published = trim((string) ($meta['published_at'] ?? ''));
    $updated   = trim((string) ($meta['updated_at']   ?? ''));
    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $published)) $node['datePublished'] = $published;
    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $updated))   $node['dateModified']  = $updated;
    elseif (isset($node['datePublished']))              $node['dateModified']  = $node['datePublished'];

    // The byline is "{business} Team" — an organisation, not a named person. Emitting
    // Person here would invent an individual who does not exist.
    $author = trim((string) ($meta['author'] ?? ''));
    if ($author !== '') $node['author'] = ['@type' => 'Organization', 'name' => $author];

    $tag = trim((string) ($meta['tag'] ?? ''));
    if ($tag !== '') $node['articleSection'] = $tag;

    if ($imageUrl !== '') $node['image'] = $imageUrl;

    // Point at the business node the homepage defines rather than restating it; every
    // schema in this codebase uses the "{website}/#localbusiness" @id convention.
    $node['publisher'] = ['@id' => '{website}/#localbusiness'];

    return $node;
}

/**
 * Return $schemaJson with its BlogPosting node replaced by one derived from the page's
 * post_meta block. Non-blog pages are returned untouched, including the '' that a page
 * with no schema at all passes in.
 */
function schema_apply_blogposting(string $schemaJson, array $blocks, array $seo = [], string $imageUrl = ''): string {
    $node       = blogposting_from_blocks($blocks, $seo, $imageUrl);
    $schemaJson = trim($schemaJson);
    if ($node === null) return $schemaJson;

    $decoded = $schemaJson !== '' ? json_decode($schemaJson, true) : null;

    if (is_array($decoded) && isset($decoded['@graph']) && is_array($decoded['@graph'])) {
        $wrapper = $decoded;
        $graph   = array_values(array_filter(
            $decoded['@graph'],
            fn($e) => !is_array($e) || ($e['@type'] ?? '') !== 'BlogPosting'
        ));
    } elseif (is_array($decoded)) {
        unset($decoded['@context']);
        $wrapper = ['@context' => 'https://schema.org', '@graph' => []];
        $graph   = ($decoded['@type'] ?? '') === 'BlogPosting' ? [] : [$decoded];
    } else {
        $wrapper = ['@context' => 'https://schema.org', '@graph' => []];
        $graph   = [];
    }

    $graph[]           = $node;
    $wrapper['@context'] = $wrapper['@context'] ?? 'https://schema.org';
    $wrapper['@graph'] = $graph;

    return json_encode($wrapper, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
