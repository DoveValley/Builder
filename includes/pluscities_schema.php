<?php
/**
 * includes/pluscities_schema.php — real JSON-LD assembly for a published
 * page, spec Section 5: "LocalBusiness or niche subtype, Service
 * (areaServed), BreadcrumbList, FAQPage" plus AggregateRating where the
 * client has real reviews.
 *
 * CRITICAL, per this codebase's own documented past bug (CLAUDE.md
 * "Breadcrumbs" section — includes/site-template.php's $bcItems vs
 * $bcSchemaItems): the VISIBLE breadcrumb nav (relative URLs) and the
 * BreadcrumbList schema (absolute URLs) are built as two SEPARATE arrays
 * here, never one reused for the other. Reusing an absolute-URL array for
 * the visible nav once sent real clicks off-site in the old engine; this
 * file exists specifically so that mistake doesn't happen a second time in
 * the new one.
 */

/** Full state name -> 2-letter abbreviation, for "Frisco, TX" style labels.
 *  No existing helper in this codebase did this — confirmed before adding. */
const PLUSCITIES_STATE_ABBR = [
    'Alabama' => 'AL', 'Alaska' => 'AK', 'Arizona' => 'AZ', 'Arkansas' => 'AR',
    'California' => 'CA', 'Colorado' => 'CO', 'Connecticut' => 'CT', 'Delaware' => 'DE',
    'District of Columbia' => 'DC', 'Florida' => 'FL', 'Georgia' => 'GA', 'Hawaii' => 'HI',
    'Idaho' => 'ID', 'Illinois' => 'IL', 'Indiana' => 'IN', 'Iowa' => 'IA', 'Kansas' => 'KS',
    'Kentucky' => 'KY', 'Louisiana' => 'LA', 'Maine' => 'ME', 'Maryland' => 'MD',
    'Massachusetts' => 'MA', 'Michigan' => 'MI', 'Minnesota' => 'MN', 'Mississippi' => 'MS',
    'Missouri' => 'MO', 'Montana' => 'MT', 'Nebraska' => 'NE', 'Nevada' => 'NV',
    'New Hampshire' => 'NH', 'New Jersey' => 'NJ', 'New Mexico' => 'NM', 'New York' => 'NY',
    'North Carolina' => 'NC', 'North Dakota' => 'ND', 'Ohio' => 'OH', 'Oklahoma' => 'OK',
    'Oregon' => 'OR', 'Pennsylvania' => 'PA', 'Rhode Island' => 'RI', 'South Carolina' => 'SC',
    'South Dakota' => 'SD', 'Tennessee' => 'TN', 'Texas' => 'TX', 'Utah' => 'UT',
    'Vermont' => 'VT', 'Virginia' => 'VA', 'Washington' => 'WA', 'West Virginia' => 'WV',
    'Wisconsin' => 'WI', 'Wyoming' => 'WY',
];

/** Real "Frisco, TX" style label — city_id is an internal slug, never shown
 *  to anyone; this is what title/H1/schema/breadcrumbs actually display. */
function pluscities_city_label(string $city_id): string
{
    if (!function_exists('infra_city_get')) return $city_id; // caller forgot to require cities.php
    $city = infra_city_get($city_id);
    if (!$city) return $city_id;
    $abbr = PLUSCITIES_STATE_ABBR[$city['state']] ?? $city['state'];
    return $city['city'] . ', ' . $abbr;
}

/**
 * Assembles everything schema.org-facing for one page. $blocks is the full
 * ordered block row set — FAQPage is read directly from the faq block's own
 * stored content, never a second copy, so schema can never drift from what's
 * actually visible on the page.
 */
function pluscities_assemble_schema(array $page, array $client, ?array $niche, array $blocks, string $pageUrl): array
{
    $cityLabel    = pluscities_city_label($page['city_id']);
    $businessName = $client['identity']['business_name'] ?? '';
    $hub          = $client['identity']['hubs'][0] ?? [];
    $schemaType   = $niche['schema_subtype'] ?: 'LocalBusiness';

    $serviceLabel = $page['service_key'];
    foreach (($niche['services'] ?? []) as $svc) {
        if ($svc['slug'] === $page['service_key']) { $serviceLabel = $svc['label']; break; }
    }

    $business = [
        '@type' => $schemaType,
        'name'  => $businessName,
    ];
    if (!empty($hub['phone'])) $business['telephone'] = $hub['phone'];
    if (!empty($hub['address'])) $business['address'] = ['@type' => 'PostalAddress', 'streetAddress' => $hub['address']];
    $business['areaServed'] = ['@type' => 'City', 'name' => $cityLabel];

    // AggregateRating — spec Section 5: built directly from reviews_by_city,
    // never invented, since structured rating data is what actually earns a
    // star-rating snippet in search results.
    $reviews = $client['reviews_by_city'][$page['city_id']] ?? [];
    if ($reviews) {
        $ratings = array_column($reviews, 'rating');
        $business['aggregateRating'] = [
            '@type'       => 'AggregateRating',
            'ratingValue' => round(array_sum($ratings) / count($ratings), 1),
            'reviewCount' => count($reviews),
        ];
    }

    $service = [
        '@type'      => 'Service',
        'serviceType' => $serviceLabel,
        'provider'   => ['@type' => $schemaType, 'name' => $businessName],
        'areaServed' => ['@type' => 'City', 'name' => $cityLabel],
        'url'        => $pageUrl,
    ];

    $schema = [
        array_merge(['@context' => 'https://schema.org'], $business),
        array_merge(['@context' => 'https://schema.org'], $service),
    ];

    // FAQPage — read straight from the faq block's own content, same source
    // the renderer uses, so this can never say something the visible page
    // doesn't (a real Google FAQPage rich-result requirement, not just good
    // practice).
    foreach ($blocks as $b) {
        if ($b['block_type'] !== 'faq') continue;
        $content = json_decode($b['content_json'], true) ?: [];
        $mainEntity = [];
        foreach ($content['faqs'] ?? [] as $faq) {
            $answer = preg_replace('/\s*\[\[[a-z0-9_\-]+\]\]/i', '', (string) ($faq['answer'] ?? ''));
            $mainEntity[] = [
                '@type' => 'Question',
                'name'  => (string) ($faq['question'] ?? ''),
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $answer],
            ];
        }
        if ($mainEntity) {
            $schema[] = ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => $mainEntity];
        }
        break;
    }

    // BreadcrumbList — ABSOLUTE urls only, for schema. The visible nav
    // (relative urls) is built separately by pluscities_breadcrumb_nav_html()
    // — never derived from this array. See file docblock.
    $basePath = '/' . trim($client['url_base_path'] ?? 'service-area', '/');
    $domain   = rtrim((string) ($client['domain'] ?? ''), '/');
    $schema[] = [
        '@context' => 'https://schema.org', '@type' => 'BreadcrumbList',
        'itemListElement' => [
            ['@type' => 'ListItem', 'position' => 1, 'name' => 'Service Area', 'item' => 'https://' . $domain . $basePath . '/'],
            ['@type' => 'ListItem', 'position' => 2, 'name' => $cityLabel, 'item' => 'https://' . $domain . $basePath . '/' . $page['city_id'] . '/'],
            ['@type' => 'ListItem', 'position' => 3, 'name' => $serviceLabel, 'item' => 'https://' . $domain . $pageUrl],
        ],
    ];

    return $schema;
}

/**
 * The VISIBLE breadcrumb nav — relative urls, built independently of the
 * schema array above (see file docblock for why this separation is
 * non-negotiable, not a style preference).
 */
function pluscities_breadcrumb_nav_html(array $page, array $client, string $cityLabel, string $serviceLabel): string
{
    $basePath = '/' . trim($client['url_base_path'] ?? 'service-area', '/');
    return '<nav class="lpc-breadcrumbs">'
        . '<a href="' . htmlspecialchars($basePath . '/') . '">Service Area</a> &rsaquo; '
        . '<a href="' . htmlspecialchars($basePath . '/' . $page['city_id'] . '/') . '">' . htmlspecialchars($cityLabel) . '</a> &rsaquo; '
        . '<span>' . htmlspecialchars($serviceLabel) . '</span>'
        . '</nav>';
}
