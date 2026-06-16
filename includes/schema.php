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
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
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
    return json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
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
    return json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
