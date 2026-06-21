<?php
function starter_categories(): array {
    return [
        'training'     => 'Training / Course',
        'home_service' => 'Home Services',
        'prof_service' => 'Prof Services',
        'ecommerce'    => 'E-Commerce',
        'universal'    => 'Universal',
    ];
}

function default_starters(): array {
    return [
        // ── Training / Course ────────────────────────────────────────────────
        [
            'id'       => 'training_course_service',
            'label'    => 'Course service page',
            'desc'     => 'Hero · Features · Pricing · FAQ · CTA',
            'category' => 'training',
            'blocks'   => ['hero_split', 'feature_columns', 'pricing_cards', 'faq_two_col', 'cta_banner'],
        ],
        [
            'id'       => 'training_about',
            'label'    => 'About / Instructors',
            'desc'     => 'Hero · Stats · Team · Testimonials · CTA',
            'category' => 'training',
            'blocks'   => ['hero', 'stats', 'team', 'testimonials', 'cta_banner'],
        ],
        [
            'id'       => 'training_schedule',
            'label'    => 'Schedule page',
            'desc'     => 'Hero · Schedule widget · CTA',
            'category' => 'training',
            'blocks'   => ['hero', 'custom_html', 'cta_banner'],
        ],
        [
            'id'       => 'training_corporate',
            'label'    => 'Corporate training',
            'desc'     => 'Hero · Feature split · Pricing · CTA',
            'category' => 'training',
            'blocks'   => ['hero_split', 'feature_split', 'pricing_cards', 'cta_banner'],
        ],
        // ── Home Services ────────────────────────────────────────────────────
        [
            'id'       => 'hs_service',
            'label'    => 'Service page',
            'desc'     => 'Hero · Features · Cards · Testimonials · CTA',
            'category' => 'home_service',
            'blocks'   => ['hero_split', 'feature_columns', 'service_cards', 'testimonials', 'cta_banner'],
        ],
        [
            'id'       => 'hs_city',
            'label'    => 'City landing page',
            'desc'     => 'Hero · Features · Links grid · CTA',
            'category' => 'home_service',
            'blocks'   => ['hero_split', 'feature_columns', 'links_grid', 'cta_banner'],
        ],
        [
            'id'       => 'hs_about',
            'label'    => 'About page',
            'desc'     => 'Hero · Stats · Team · Testimonials · CTA',
            'category' => 'home_service',
            'blocks'   => ['hero', 'stats', 'team', 'testimonials', 'cta_banner'],
        ],
        // ── Prof Services ────────────────────────────────────────────────────
        [
            'id'       => 'ps_service',
            'label'    => 'Service page',
            'desc'     => 'Hero · Feature split · Team · FAQ · CTA',
            'category' => 'prof_service',
            'blocks'   => ['hero_split', 'feature_split', 'team', 'faq_two_col', 'cta_banner'],
        ],
        [
            'id'       => 'ps_practice',
            'label'    => 'Practice area',
            'desc'     => 'Hero · Features · FAQ · CTA card',
            'category' => 'prof_service',
            'blocks'   => ['hero_split', 'feature_columns', 'faq_two_col', 'cta_card'],
        ],
        [
            'id'       => 'ps_about',
            'label'    => 'About page',
            'desc'     => 'Hero · Stats · Team · Testimonials · CTA',
            'category' => 'prof_service',
            'blocks'   => ['hero', 'stats', 'team', 'testimonials', 'cta_banner'],
        ],
        // ── E-Commerce ───────────────────────────────────────────────────────
        [
            'id'       => 'ec_product',
            'label'    => 'Product page',
            'desc'     => 'Hero · Image features · Pricing · Testimonials · CTA',
            'category' => 'ecommerce',
            'blocks'   => ['hero_split', 'image_features', 'pricing_cards', 'testimonials', 'cta_banner'],
        ],
        [
            'id'       => 'ec_category',
            'label'    => 'Category page',
            'desc'     => 'Hero · Service cards · CTA',
            'category' => 'ecommerce',
            'blocks'   => ['hero', 'service_cards', 'cta_banner'],
        ],
        [
            'id'       => 'ec_about',
            'label'    => 'About / Brand page',
            'desc'     => 'Hero · Stats · Testimonials · CTA',
            'category' => 'ecommerce',
            'blocks'   => ['hero', 'stats', 'testimonials', 'cta_banner'],
        ],
        // ── Universal ────────────────────────────────────────────────────────
        [
            'id'       => 'contact',
            'label'    => 'Contact page',
            'desc'     => 'Hero · Map & info · Contact form',
            'category' => 'universal',
            'blocks'   => ['hero', 'map_info', 'contact_form'],
        ],
        [
            'id'       => 'landing',
            'label'    => 'Generic landing page',
            'desc'     => 'Hero · Feature split · Service cards · CTA',
            'category' => 'universal',
            'blocks'   => ['hero_split', 'feature_split', 'service_cards', 'cta_banner'],
        ],
        [
            'id'       => 'legal',
            'label'    => 'Legal / policy page',
            'desc'     => 'Text only',
            'category' => 'universal',
            'blocks'   => ['text'],
        ],
        [
            'id'       => 'blank',
            'label'    => 'Blank page',
            'desc'     => 'Start with no blocks',
            'category' => 'universal',
            'blocks'   => [],
        ],
    ];
}

function starters_load(): array {
    if (defined('STARTERS_FILE') && file_exists(STARTERS_FILE)) {
        $raw = json_decode(file_get_contents(STARTERS_FILE), true);
        if (is_array($raw) && !empty($raw)) return $raw;
    }
    return default_starters();
}

function starters_save(array $starters): bool {
    if (!defined('STARTERS_FILE')) return false;
    $dir = dirname(STARTERS_FILE);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $content = json_encode(array_values($starters), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $tmp = STARTERS_FILE . '.tmp.' . getmypid();
    if (file_put_contents($tmp, $content) === false) return false;
    return rename($tmp, STARTERS_FILE);
}

function default_block_data(string $type): array {
    return ['type' => $type];
}

function blocks_from_starter(string $starterId): array {
    $starters = starters_load();
    foreach ($starters as $s) {
        if ($s['id'] === $starterId) {
            return array_map('default_block_data', $s['blocks'] ?? []);
        }
    }
    return [];
}
