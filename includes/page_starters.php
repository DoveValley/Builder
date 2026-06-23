<?php
function starter_categories(): array {
    return [
        'homepage'     => 'Homepage',
        'training'     => 'Training / Course',
        'home_service' => 'Home Services',
        'prof_service' => 'Prof Services',
        'ecommerce'    => 'E-Commerce',
        'universal'    => 'Universal',
    ];
}

function default_starters(): array {
    return [
        // ── Homepage ─────────────────────────────────────────────────────────
        [
            'id'       => 'hp_training',
            'label'    => 'Training / Course',
            'desc'     => 'Hero · Features · Stats · Pricing · Testimonials · CTA',
            'category' => 'homepage',
            'blocks'   => ['hero_split', 'feature_columns', 'stats', 'pricing_cards', 'testimonials', 'cta_banner'],
        ],
        [
            'id'       => 'hp_home_service',
            'label'    => 'Home Services',
            'desc'     => 'Hero · Features · Service cards · Stats · Testimonials · CTA',
            'category' => 'homepage',
            'blocks'   => ['hero_split', 'feature_columns', 'service_cards', 'stats', 'testimonials', 'cta_banner'],
        ],
        [
            'id'       => 'hp_prof_service',
            'label'    => 'Prof Services',
            'desc'     => 'Hero · Feature split · Stats · Team · Testimonials · CTA',
            'category' => 'homepage',
            'blocks'   => ['hero_split', 'feature_split', 'stats', 'team', 'testimonials', 'cta_banner'],
        ],
        [
            'id'       => 'hp_ecommerce',
            'label'    => 'E-Commerce',
            'desc'     => 'Hero · Features · Cards · Logo bar · Testimonials · CTA',
            'category' => 'homepage',
            'blocks'   => ['hero_split', 'feature_columns', 'service_cards', 'logo_bar', 'testimonials', 'cta_banner'],
        ],
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
    $base = ['type' => $type];

    switch ($type) {

        case 'hero':
            return $base + [
                'hero_heading'    => 'Your Main Headline Goes Here',
                'hero_subtext'    => '<p>A short supporting sentence that explains your value proposition.</p>',
                'hero_btn_text'   => 'Get Started',
                'hero_btn_url'    => '#',
                'hero_text_color' => '#ffffff',
                'hero_bg_color'   => '#1e3a5f',
                'hero_bg_image'   => 'assets/img/samples/hero.webp',
            ];

        case 'hero_split':
            return $base + [
                'hs_heading'      => 'Your Main Headline Goes Here',
                'hs_tagline'      => 'Trusted by thousands of professionals',
                'hs_subtext'      => '<p>A short supporting sentence that explains your value proposition and why customers should choose you.</p>',
                'hs_btn_text'     => 'Get Started',
                'hs_btn_url'      => '#',
                'hs_btn2_text'    => 'Learn More',
                'hs_btn2_url'     => '#',
                'hs_caption1'     => '★★★★★ 4.9 rating',
                'hs_caption2'     => '500+ happy customers',
                'hs_photo'        => 'assets/img/samples/hero.webp',
                'hs_photo_alt'    => 'Hero image',
                'hs_bg_photo'     => '',
                'hs_bg_color'     => '#f3f6f7',
                'hs_image_side'   => 'right',
                'hs_mobile_order' => '',
            ];

        case 'hero_grid':
            return $base + [
                'hg_label'        => 'FEATURED',
                'hg_heading'      => 'Your Main Headline Goes Here',
                'hg_body'         => '<p>A short paragraph that introduces your product or service and highlights the key benefit.</p>',
                'hg_btn_text'     => 'Get Started',
                'hg_btn_url'      => '#',
                'hg_photo'        => 'assets/img/samples/hero.webp',
                'hg_photo_alt'    => 'Hero image',
                'hg_color1'       => 'accent',
                'hg_color2'       => 'header',
                'hg_color1_custom'=> '#fd783b',
                'hg_color2_custom'=> '#120575',
                'hg_items'        => [
                    ['icon' => '', 'label' => 'Feature One',   'alt' => ''],
                    ['icon' => '', 'label' => 'Feature Two',   'alt' => ''],
                    ['icon' => '', 'label' => 'Feature Three', 'alt' => ''],
                    ['icon' => '', 'label' => 'Feature Four',  'alt' => ''],
                ],
            ];

        case 'wide_banner':
            return $base + [
                'wb_badge'          => 'ANNOUNCEMENT',
                'wb_heading'        => 'A Bold Statement That Grabs Attention',
                'wb_subtext'        => 'Supporting copy that reinforces the headline and drives action.',
                'wb_btn_text'       => 'Learn More',
                'wb_btn_url'        => '#',
                'wb_btn_style'      => 'filled',
                'wb_centered'       => true,
                'wb_photo'          => 'assets/img/samples/banner.webp',
                'wb_photo_alt'      => 'Banner image',
                'wb_overlay'        => '0.55',
                'wb_bg_color'       => '#1a1a2e',
                'wb_badge_bg'       => 'accent',
                'wb_badge_bg_custom'=> '#fd783b',
            ];

        case 'text':
            return $base + [
                'heading_level' => 'h2',
                'heading_text'  => 'Section Heading',
                'text'          => '<p>This is a text block. Replace this with your content. You can add multiple paragraphs, <strong>bold text</strong>, lists, and links.</p>',
            ];

        case 'image_left':
        case 'image_right':
            return $base + [
                'image_side'     => $type === 'image_right' ? 'right' : 'left',
                'ir_layout'      => 'side',
                'text'           => '<h2>Section Heading</h2><p>This is a text block paired with an image. Replace this with content describing your product or service.</p>',
                'photo'          => 'assets/img/samples/feature.webp',
                'photo_alt'      => 'Feature image',
                'photo_ratio'    => 'landscape',
                'photo_position' => 'center',
            ];

        case 'video':
            return $base + [
                'vid_heading' => 'Watch Our Overview',
                'vid_url'     => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
                'vid_caption' => 'A brief description of what this video covers.',
                'vid_width'   => 'contained',
            ];

        case 'custom_html':
            return $base + [
                'html' => '<p>Custom HTML block. Replace this with your own HTML content.</p>',
            ];

        case 'feature_split':
            return $base + [
                'fs_heading'      => 'Why We Stand Apart',
                'fs_subtext'      => 'We combine years of expertise with a commitment to delivering outstanding results for every client.',
                'fs_photo'        => 'assets/img/samples/feature.webp',
                'fs_photo_alt'    => 'Feature image',
                'fs_star_text'    => '★★★★★ Rated by our clients',
                'fs_bg_color'     => '#f3f6f7',
                'fs_accent'       => '#fd783b',
                'fs_image_side'   => 'right',
                'fs_mobile_order' => '',
                'fs_items'        => [
                    ['icon' => '', 'alt' => '', 'heading' => 'Benefit One',   'text' => 'Short description of this key benefit or feature.'],
                    ['icon' => '', 'alt' => '', 'heading' => 'Benefit Two',   'text' => 'Short description of this key benefit or feature.'],
                    ['icon' => '', 'alt' => '', 'heading' => 'Benefit Three', 'text' => 'Short description of this key benefit or feature.'],
                ],
            ];

        case 'feature_columns':
            return $base + [
                'fc_heading'    => 'Why Choose Us',
                'fc_subheading' => 'Here are the key reasons our customers trust us.',
                'fc_num_cols'   => 3,
                'fc_bg_color'   => '#f3f6f7',
                'columns'       => [
                    ['icon' => '', 'image' => '', 'heading' => 'Benefit One',   'text' => 'Short description of this key benefit or feature.', 'alt' => ''],
                    ['icon' => '', 'image' => '', 'heading' => 'Benefit Two',   'text' => 'Short description of this key benefit or feature.', 'alt' => ''],
                    ['icon' => '', 'image' => '', 'heading' => 'Benefit Three', 'text' => 'Short description of this key benefit or feature.', 'alt' => ''],
                ],
            ];

        case 'service_cards':
            return $base + [
                'sc_badge'            => 'OUR SERVICES',
                'sc_heading'          => 'What We Offer',
                'sc_cols'             => 3,
                'sc_badge_bg'         => 'accent',
                'sc_head_color'       => 'header',
                'sc_badge_bg_custom'  => '#fd783b',
                'sc_head_color_custom'=> '#120575',
                'sc_icon_bg'          => '#fef0e7',
                'sc_items'            => [
                    ['icon' => '', 'alt' => '', 'heading' => 'Service One',   'text' => 'Brief description of what this service includes and who it is for.', 'url' => ''],
                    ['icon' => '', 'alt' => '', 'heading' => 'Service Two',   'text' => 'Brief description of what this service includes and who it is for.', 'url' => ''],
                    ['icon' => '', 'alt' => '', 'heading' => 'Service Three', 'text' => 'Brief description of what this service includes and who it is for.', 'url' => ''],
                ],
            ];

        case 'tab_services':
            return $base + [
                'ts_badge1'           => 'OUR SERVICES',
                'ts_badge2'           => '',
                'ts_heading'          => 'Explore Our Services',
                'ts_active_bg'        => 'header',
                'ts_active_bg_custom' => '#120575',
                'ts_tabs'             => [
                    ['label' => 'Service One',   'icon' => '', 'photo' => '', 'alt' => '', 'desc' => 'Description of service one and what it includes.'],
                    ['label' => 'Service Two',   'icon' => '', 'photo' => '', 'alt' => '', 'desc' => 'Description of service two and what it includes.'],
                    ['label' => 'Service Three', 'icon' => '', 'photo' => '', 'alt' => '', 'desc' => 'Description of service three and what it includes.'],
                ],
            ];

        case 'steps':
            return $base + [
                'steps_heading' => 'How It Works',
                'steps_items'   => [
                    ['image' => '', 'alt' => '', 'heading' => 'Step One',   'text' => 'Describe what happens in this first step of your process.'],
                    ['image' => '', 'alt' => '', 'heading' => 'Step Two',   'text' => 'Describe what happens in this second step of your process.'],
                    ['image' => '', 'alt' => '', 'heading' => 'Step Three', 'text' => 'Describe what happens in this third step of your process.'],
                ],
            ];

        case 'stats':
            return $base + [
                'stats_heading'    => '',
                'stats_bg_color'   => '#1e3a5f',
                'stats_text_color' => '#ffffff',
                'stats_items'      => [
                    ['number' => '500+', 'label' => 'Customers Served'],
                    ['number' => '98%',  'label' => 'Satisfaction Rate'],
                    ['number' => '10+',  'label' => 'Years Experience'],
                    ['number' => '4.9★', 'label' => 'Average Rating'],
                ],
            ];

        case 'image_features':
            return $base + [
                'if_heading'           => 'Everything You Need to Succeed',
                'if_intro'             => 'We provide everything you need to get the results you are looking for.',
                'if_closing'           => 'Ready to get started?',
                'if_phone_label'       => 'Call us',
                'if_phone'             => '{phone}',
                'if_phone_url'         => '{tel}',
                'if_photo'             => 'assets/img/samples/service.webp',
                'if_photo_alt'         => 'Service image',
                'if_bg_color'          => '#f3f6f7',
                'if_check_color'       => 'accent',
                'if_head_color'        => 'header',
                'if_check_color_custom'=> '#fd783b',
                'if_head_color_custom' => '#120575',
                'if_features'          => [
                    'Key benefit or feature one',
                    'Key benefit or feature two',
                    'Key benefit or feature three',
                    'Key benefit or feature four',
                    'Key benefit or feature five',
                ],
            ];

        case 'pricing_cards':
            return $base + [
                'pc_label'      => 'PRICING',
                'pc_heading'    => 'Simple, Transparent Pricing',
                'pc_subheading' => 'Choose the plan that fits your needs.',
                'pc_cols'       => 3,
                'pc_bg'         => '',
                'pc_items'      => [
                    [
                        'name'        => 'Starter',
                        'badge'       => '',
                        'badge_color' => '',
                        'inner_badge' => '',
                        'sublabel'    => '/month',
                        'desc'        => '$99',
                        'features'    => "Feature one\nFeature two\nFeature three",
                        'meta'        => '',
                        'meta2'       => '',
                        'btn_text'    => 'Get Started →',
                        'btn_url'     => '#',
                        'featured'    => false,
                    ],
                    [
                        'name'        => 'Professional',
                        'badge'       => 'Most Popular',
                        'badge_color' => '',
                        'inner_badge' => '',
                        'sublabel'    => '/month',
                        'desc'        => '$199',
                        'features'    => "Everything in Starter\nFeature four\nFeature five\nPriority support",
                        'meta'        => '',
                        'meta2'       => '',
                        'btn_text'    => 'Get Started →',
                        'btn_url'     => '#',
                        'featured'    => true,
                    ],
                    [
                        'name'        => 'Enterprise',
                        'badge'       => '',
                        'badge_color' => '',
                        'inner_badge' => '',
                        'sublabel'    => '/month',
                        'desc'        => '$399',
                        'features'    => "Everything in Professional\nDedicated account manager\nCustom integrations",
                        'meta'        => '',
                        'meta2'       => '',
                        'btn_text'    => 'Contact Sales →',
                        'btn_url'     => '#',
                        'featured'    => false,
                    ],
                ],
            ];

        case 'stage_cards':
            return $base + [
                'sc_section_label' => 'THE PROCESS',
                'sc_heading'       => 'Your Journey to Success',
                'sc_subhead'       => '',
                'sc_subtext'       => '',
                'sc_bg'            => '#f8fafc',
                'sc_cols'          => 4,
                'sc_accent'        => 'accent',
                'sc_accent_custom' => '',
                'sc_stages'        => [
                    ['number' => '01', 'label' => 'Foundation', 'heading' => 'Stage One',   'items' => "First item\nSecond item"],
                    ['number' => '02', 'label' => 'Build',      'heading' => 'Stage Two',   'items' => "First item\nSecond item"],
                    ['number' => '03', 'label' => 'Grow',       'heading' => 'Stage Three', 'items' => "First item\nSecond item"],
                    ['number' => '04', 'label' => 'Scale',      'heading' => 'Stage Four',  'items' => "First item\nSecond item"],
                ],
            ];

        case 'comparison_table':
            return $base + [
                'ct_label'       => 'WHY US',
                'ct_heading'     => 'How We Compare',
                'ct_col1_header' => 'Other Providers',
                'ct_col2_header' => 'Our Company',
                'ct_rows_raw'    => "Feature one|no|yes\nFeature two|no|yes\nFeature three|limited|yes\nDedicated support|no|yes",
                'ct_callout'     => 'Make the smart choice — choose us.',
                'ct_bg'          => '#ffffff',
            ];

        case 'testimonials':
            return $base + [
                'tm_heading'       => 'What Our Customers Say',
                'tm_cols'          => 3,
                'tm_bg_color'      => '#f8fafc',
                'tm_text_color'    => '#374151',
                'tm_accent'        => 'accent',
                'tm_accent_custom' => '#f59e0b',
                'tm_items'         => [
                    ['quote' => 'This was exactly what I needed. Highly recommend to anyone looking for results.', 'name' => 'Jane Smith',       'location' => 'Customer', 'initials' => 'JS', 'avatar_color' => '#2563eb', 'result_badge' => ''],
                    ['quote' => 'Outstanding service from start to finish. The team really knows their stuff.',   'name' => 'Michael Johnson', 'location' => 'Customer', 'initials' => 'MJ', 'avatar_color' => '#7c3aed', 'result_badge' => ''],
                    ['quote' => 'Best decision I made this year. Already seeing great results.',                  'name' => 'Sarah Williams',  'location' => 'Customer', 'initials' => 'SW', 'avatar_color' => '#059669', 'result_badge' => ''],
                ],
            ];

        case 'team':
            return $base + [
                'team_heading'    => 'Meet Our Team',
                'team_subheading' => 'The experts behind our success.',
                'team_cols'       => 3,
                'team_members'    => [
                    ['photo' => 'assets/img/samples/portrait-1.webp', 'photo_alt' => 'Team member', 'name' => 'Team Member Name', 'title' => 'Job Title', 'bio' => 'A short bio about this team member and their expertise.'],
                    ['photo' => 'assets/img/samples/portrait-2.webp', 'photo_alt' => 'Team member', 'name' => 'Team Member Name', 'title' => 'Job Title', 'bio' => 'A short bio about this team member and their expertise.'],
                    ['photo' => 'assets/img/samples/portrait-3.webp', 'photo_alt' => 'Team member', 'name' => 'Team Member Name', 'title' => 'Job Title', 'bio' => 'A short bio about this team member and their expertise.'],
                ],
            ];

        case 'logo_bar':
            return $base + [
                'lb_heading'   => 'As Seen In',
                'lb_bg'        => '#ffffff',
                'lb_height'    => 60,
                'lb_grayscale' => false,
                'lb_items'     => [],
            ];

        case 'cards':
            return $base + [
                'cards_label'                  => '',
                'cards_heading'                => 'Featured Resources',
                'cards_subhead'                => '',
                'cards_subtext'                => '',
                'cards_cols'                   => 3,
                'cards_bg'                     => '',
                'cards_card_bg'                => '',
                'cards_text_color'             => '',
                'cards_head_color'             => 'header',
                'cards_head_color_custom'      => '#1a1a2e',
                'cards_item_head_color'        => 'header',
                'cards_item_head_color_custom' => '#1a1a2e',
                'cards_accent'                 => 'accent',
                'cards_accent_custom'          => '',
                'cards_border'                 => '',
                'cards_badge_accent'           => '',
                'cards_badge_accent_custom'    => '',
                'cards_centered'               => false,
                'cards_items'                  => [
                    ['icon' => '', 'image' => '', 'alt' => '', 'heading' => 'Card Title One',   'text' => 'A short description of this card. Replace with real content.', 'badge' => '', 'link' => '', 'btn_text' => 'Read More'],
                    ['icon' => '', 'image' => '', 'alt' => '', 'heading' => 'Card Title Two',   'text' => 'A short description of this card. Replace with real content.', 'badge' => '', 'link' => '', 'btn_text' => 'Read More'],
                    ['icon' => '', 'image' => '', 'alt' => '', 'heading' => 'Card Title Three', 'text' => 'A short description of this card. Replace with real content.', 'badge' => '', 'link' => '', 'btn_text' => 'Read More'],
                ],
            ];

        case 'gallery':
            return $base + [
                'gallery_heading' => 'Gallery',
                'gallery_cols'    => 3,
                'gallery_images'  => [
                    ['photo' => 'assets/img/samples/hero.webp',      'alt' => 'Gallery image'],
                    ['photo' => 'assets/img/samples/feature.webp',   'alt' => 'Gallery image'],
                    ['photo' => 'assets/img/samples/service.webp',   'alt' => 'Gallery image'],
                    ['photo' => 'assets/img/samples/banner.webp',    'alt' => 'Gallery image'],
                    ['photo' => 'assets/img/samples/portrait-1.webp','alt' => 'Gallery image'],
                    ['photo' => 'assets/img/samples/portrait-2.webp','alt' => 'Gallery image'],
                ],
            ];

        case 'faq_two_col':
            return $base + [
                'fq_heading'           => 'Frequently Asked Questions',
                'fq_bg_color'          => '#ffffff',
                'fq_item_bg'           => '#f0f2f8',
                'fq_head_color'        => 'header',
                'fq_icon_bg'           => 'accent',
                'fq_head_color_custom' => '#120575',
                'fq_icon_bg_custom'    => '#fd783b',
                'fq_items'             => [
                    ['question' => 'What is your most commonly asked question?',   'answer' => 'A clear, helpful answer to this question that addresses the customer concern.'],
                    ['question' => 'How does your process work?',                  'answer' => 'A step-by-step explanation of how you work with clients from start to finish.'],
                    ['question' => 'What makes you different from competitors?',   'answer' => 'Explain your unique value proposition and what sets you apart.'],
                    ['question' => 'What does it cost?',                           'answer' => 'Pricing information or a prompt to contact you for a custom quote.'],
                    ['question' => 'How long does it take?',                       'answer' => 'Timeline expectations and what factors affect the duration.'],
                    ['question' => 'Do you offer a guarantee?',                    'answer' => 'Describe your satisfaction guarantee, refund policy, or commitment to results.'],
                ],
            ];

        case 'links_grid':
            return $base + [
                'lg_heading'      => 'Areas We Serve',
                'lg_subtext'      => 'Find your location below.',
                'lg_sublabel'     => 'View all locations →',
                'lg_photo'        => 'assets/img/samples/banner.webp',
                'lg_photo_alt'    => 'Background image',
                'lg_style'        => 'dark',
                'lg_cols'         => 5,
                'lg_overlay'      => '0.60',
                'lg_bg_color'     => '#ffffff',
                'lg_accent'       => 'accent',
                'lg_accent_custom'=> '#fd783b',
                'lg_links'        => [
                    ['label' => 'Location One',   'url' => '#'],
                    ['label' => 'Location Two',   'url' => '#'],
                    ['label' => 'Location Three', 'url' => '#'],
                    ['label' => 'Location Four',  'url' => '#'],
                    ['label' => 'Location Five',  'url' => '#'],
                ],
            ];

        case 'cta_banner':
            return $base + [
                'cb_text'       => 'Ready to Get Started?',
                'cb_subtext'    => 'Contact us today and take the first step toward your goals.',
                'cb_btn_text'   => 'Contact Us Today',
                'cb_btn_url'    => '#',
                'cb_bg'         => 'accent',
                'cb_bg_custom'  => '#fd783b',
                'cb_text_color' => '#ffffff',
                'cb_padding'    => 'normal',
            ];

        case 'cta_card':
            return $base + [
                'cc_heading'   => 'Ready to Get Started?',
                'cc_text'      => 'Join hundreds of satisfied customers and take the first step today.',
                'cc_checklist' => "Benefit one\nBenefit two\nBenefit three",
                'cc_btn_text'  => 'Get Started Now',
                'cc_btn_url'   => '#',
                'cc_btn_style' => 'filled',
                'cc_align'     => 'split',
                'cc_bg'        => 'accent',
                'cc_bg_custom' => '#fd783b',
                'cc_radius'    => 12,
            ];

        case 'split_cta':
            return $base + [
                'sc_left_heading'    => "Have Questions? Let's Talk.",
                'sc_left_text'       => 'Our team is here to help you find the right solution.',
                'sc_right_label'     => 'Call Us Directly',
                'sc_right_phone'     => '{phone}',
                'sc_right_phone_url' => '{tel}',
                'sc_left_bg'         => 'accent',
                'sc_right_bg'        => 'header',
                'sc_left_bg_custom'  => '#fd783b',
                'sc_right_bg_custom' => '#120575',
            ];

        case 'email_banner':
            return $base + [
                'eb_heading'     => 'Get Your Free Resource',
                'eb_subtext'     => 'Enter your email to receive our free guide instantly.',
                'eb_bg'          => '#2563eb',
                'eb_placeholder' => 'Enter your email',
                'eb_btn_text'    => 'Send Me the Guide →',
                'eb_btn_url'     => '#',
                'eb_form_action' => '',
                'eb_badge_text'  => 'FREE',
                'eb_badge_image' => '',
            ];

        case 'cta_button':
            return $base + [
                'cta_text'    => 'Contact Us Today',
                'cta_url'     => '#',
                'cta_subtext' => 'No commitment required.',
                'cta_align'   => 'center',
            ];

        case 'map_info':
            return $base + [
                'mi_map_heading'       => 'Find Us',
                'mi_info_heading'      => 'Visit Our Office',
                'mi_info_text'         => "123 Main Street\nYour City, ST 00000\n\nPhone: {phone}\nEmail: {email}",
                'mi_info_photo'        => '',
                'mi_info_alt'          => '',
                'mi_map_embed'         => '',
                'mi_head_color'        => 'header',
                'mi_head_color_custom' => '#120575',
            ];

        case 'contact_form':
            return $base + [
                'cf_heading'    => 'Contact Us',
                'cf_subtext'    => 'Fill out the form below and we will get back to you within 24 hours.',
                'cf_btn_text'   => 'Send Message',
                'cf_show_phone' => true,
            ];

        default:
            return $base;
    }
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
