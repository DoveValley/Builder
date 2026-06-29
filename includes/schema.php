<?php
/* ============================================================
   SCHEMA: Generate LocalBusiness JSON-LD
   ============================================================ */
function generate_faq_schema(array $contentBlocks): string {
    $pairs = [];
    foreach ($contentBlocks as $block) {
        if (($block['type'] ?? '') !== 'faq_two_col') continue;
        foreach ($block['fq_items'] ?? [] as $item) {
            $q = html_entity_decode(resolve_shortcodes(trim($item['question'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $a = html_entity_decode(resolve_shortcodes(trim(strip_tags($item['answer'] ?? ''))), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($q && $a) $pairs[] = [
                '@type'          => 'Question',
                'name'           => $q,
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $a],
            ];
        }
    }
    if (empty($pairs)) return '';
    return json_encode([
        '@context'   => 'https://schema.org',
        '@type'      => 'FAQPage',
        'mainEntity' => $pairs,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
}

function generate_local_business_schema(array $lb): string {
    if (empty($lb['lb_name'])) return '';
    $schema = [
        '@context' => 'https://schema.org',
        '@type'    => $lb['lb_type'] ?: 'LocalBusiness',
        'name'     => $lb['lb_name'],
    ];
    if (!empty($lb['lb_url']))         $schema['url']         = $lb['lb_url'];
    if (!empty($lb['lb_phone']))       $schema['telephone']   = resolve_shortcodes($lb['lb_phone']);
    if (!empty($lb['lb_price_range'])) $schema['priceRange']  = $lb['lb_price_range'];
    if (!empty($lb['lb_description'])) $schema['description'] = resolve_shortcodes($lb['lb_description']);
    if (!empty($lb['lb_logo']))        $schema['logo']        = $lb['lb_logo'];
    if (!empty($lb['lb_hours']))       $schema['openingHours'] = $lb['lb_hours'];

    $addr = [];
    if (!empty($lb['lb_street']))  $addr['streetAddress']   = $lb['lb_street'];
    if (!empty($lb['lb_city']))    $addr['addressLocality'] = $lb['lb_city'];
    if (!empty($lb['lb_state']))   $addr['addressRegion']   = $lb['lb_state'];
    if (!empty($lb['lb_zip']))     $addr['postalCode']      = $lb['lb_zip'];
    if (!empty($lb['lb_country'])) $addr['addressCountry']  = $lb['lb_country'];
    if ($addr) {
        $addr['@type'] = 'PostalAddress';
        $schema['address'] = $addr;
    }
    if (!empty($lb['lb_lat']) && !empty($lb['lb_lng'])) {
        $schema['geo'] = [
            '@type'     => 'GeoCoordinates',
            'latitude'  => $lb['lb_lat'],
            'longitude' => $lb['lb_lng'],
        ];
    }
    // areaServed signals to Google this is a service-area business (no streetAddress is intentional)
    if (!empty($lb['lb_city'])) {
        $cityName = $lb['lb_city'];
        if (!empty($lb['lb_state'])) $cityName .= ', ' . $lb['lb_state'];
        $schema['areaServed'] = ['@type' => 'City', 'name' => $cityName];
    }
    if (!empty($lb['lb_rating']) && !empty($lb['lb_review_count'])) {
        $schema['aggregateRating'] = [
            '@type'       => 'AggregateRating',
            'ratingValue' => $lb['lb_rating'],
            'reviewCount' => $lb['lb_review_count'],
            'bestRating'  => '5',
            'worstRating' => '1',
        ];
    }
    return json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
}

/* ============================================================
   SCHEMA: Skeleton generator for the panel Schema Section
   ============================================================ */
function get_schema_skeleton(string $type, array $seo = [], array $lb = []): string {
    $website  = rtrim($lb['lb_url'] ?? resolve_shortcodes('{website}'), '/');
    $orgId    = $website . '/#organization';
    $siteId   = $website . '/#website';
    $canonical = rtrim(resolve_shortcodes($seo['canonical_url'] ?? ''), '/') ?: $website;
    $canonical = rtrim($canonical, '/') . '/';
    $pageTitle = $seo['seo_title'] ?? '';

    $skeletons = [
        'EducationalOrganization' => [
            '@type'     => 'EducationalOrganization',
            '@id'       => $orgId,
            'name'      => $lb['lb_name'] ?? '',
            'url'       => $website . '/',
            'logo'      => $lb['lb_logo'] ?? '',
            'telephone' => $lb['lb_phone'] ?? '',
            'sameAs'    => [],
        ],
        'WebSite' => [
            '@type'     => 'WebSite',
            '@id'       => $siteId,
            'url'       => $website . '/',
            'name'      => $lb['lb_name'] ?? '',
            'publisher' => ['@id' => $orgId],
        ],
        'WebPage' => [
            '@type'    => 'WebPage',
            '@id'      => $canonical . '#webpage',
            'url'      => $canonical,
            'name'     => $pageTitle,
            'isPartOf' => ['@id' => $siteId],
            'about'    => ['@id' => $orgId],
        ],
        'ItemList' => [
            '@type'           => 'ItemList',
            'name'            => 'PMI Certification Courses',
            'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => '', 'url' => ''],
                ['@type' => 'ListItem', 'position' => 2, 'name' => '', 'url' => ''],
                ['@type' => 'ListItem', 'position' => 3, 'name' => '', 'url' => ''],
            ],
        ],
        'Course' => [
            '@type'       => 'Course',
            'name'        => '',
            'description' => '',
            'provider'    => ['@id' => $orgId],
            'url'         => $canonical,
            'offers'      => [
                [
                    '@type'        => 'Offer',
                    'name'         => 'Live Virtual',
                    'price'        => '',
                    'priceCurrency'=> 'USD',
                    'availability' => 'https://schema.org/InStock',
                    'url'          => $canonical,
                ],
            ],
            'aggregateRating' => [
                '@type'       => 'AggregateRating',
                'ratingValue' => '',
                'reviewCount' => '',
                'bestRating'  => '5',
                'worstRating' => '1',
            ],
        ],
        'Event' => [
            '@type'               => 'Event',
            'name'                => '',
            'startDate'           => '',
            'endDate'             => '',
            'eventAttendanceMode' => 'https://schema.org/OnlineEventAttendanceMode',
            'location'  => ['@type' => 'VirtualLocation', 'url' => $canonical],
            'organizer' => ['@id' => $orgId],
            'offers'    => [
                '@type'        => 'Offer',
                'price'        => '',
                'priceCurrency'=> 'USD',
                'availability' => 'https://schema.org/InStock',
                'url'          => $canonical,
            ],
        ],
        'AboutPage' => [
            '@type'    => 'AboutPage',
            'url'      => $canonical,
            'name'     => $pageTitle,
            'isPartOf' => ['@id' => $siteId],
        ],
        'ContactPage' => [
            '@type'    => 'ContactPage',
            'url'      => $canonical,
            'name'     => $pageTitle,
            'isPartOf' => ['@id' => $siteId],
        ],
        'EducationalOccupationalCredential' => [
            '@type'              => 'EducationalOccupationalCredential',
            'name'               => '',
            'description'        => '',
            'credentialCategory' => 'Professional Certification',
            'recognizedBy'       => ['@type' => 'Organization', 'name' => ''],
            'url'                => $canonical,
        ],
    ];

    if (!isset($skeletons[$type])) return '';
    return json_encode($skeletons[$type], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

/* ============================================================
   SCHEMA: Generate WebSite JSON-LD (global)
   ============================================================ */
function generate_website_schema(array $lb): string {
    $url = rtrim($lb['lb_url'] ?? '', '/');
    if (empty($url)) return '';
    $schema = [
        '@context' => 'https://schema.org',
        '@type'    => 'WebSite',
        'name'     => $lb['lb_name'] ?? '',
        'url'      => $url . '/',
    ];
    return json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
}

/* ============================================================
   SCHEMA: Generate Organization JSON-LD (global, with sameAs)
   ============================================================ */
function generate_organization_schema(array $lb, array $footer): string {
    if (empty($lb['lb_name'])) return '';
    $schema = [
        '@context' => 'https://schema.org',
        '@type'    => 'Organization',
        'name'     => $lb['lb_name'],
    ];
    if (!empty($lb['lb_url']))  $schema['url']  = $lb['lb_url'];
    if (!empty($lb['lb_logo'])) $schema['logo'] = $lb['lb_logo'];
    $sameAs = [];
    foreach ($footer['social_links'] ?? [] as $url) {
        $url = trim((string)$url);
        if ($url && filter_var($url, FILTER_VALIDATE_URL)) $sameAs[] = $url;
    }
    if ($sameAs) $schema['sameAs'] = array_values($sameAs);
    return json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
}

/* ============================================================
   SCHEMA: Generate Service JSON-LD (per-page)
   ============================================================ */
function generate_service_schema(array $seo, array $lb): string {
    $name = resolve_shortcodes($seo['service_name'] ?? '');
    if (empty($name)) return '';
    $schema = [
        '@context'    => 'https://schema.org',
        '@type'       => 'Service',
        'name'        => $name,
        'serviceType' => resolve_shortcodes($seo['service_type'] ?? $name),
    ];
    if (!empty($seo['service_description'])) $schema['description'] = resolve_shortcodes($seo['service_description']);
    if (!empty($seo['canonical_url']))        $schema['url']         = $seo['canonical_url'];
    if (!empty($seo['service_area'])) {
        $schema['areaServed'] = ['@type' => 'City', 'name' => resolve_shortcodes($seo['service_area'])];
    }
    if (!empty($lb['lb_name'])) {
        $provider = ['@type' => 'LocalBusiness', 'name' => $lb['lb_name']];
        if (!empty($lb['lb_url']))   $provider['url']       = $lb['lb_url'];
        if (!empty($lb['lb_phone'])) $provider['telephone'] = resolve_shortcodes($lb['lb_phone']);
        $schema['provider'] = $provider;
    }
    return json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
}
