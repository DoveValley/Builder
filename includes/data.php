<?php
function default_data() {
    return [
        'theme' => [
            'header_bg'      => '#ffffff',
            'header_top_bg'  => '#ffffff',
            'header_text'    => '#000000',
            'content_bg'     => '#ffffff',
            'content_text'   => '#000000',
            'heading_color'  => '#000000',
            'footer_bg'      => '#ffffff',
            'footer_text'    => '#000000',
            'accent_color'   => '#2563eb',
            'accent2_color'  => '#f5a623',
            'btn_text'       => '#ffffff',
            'border_color'   => '#e5e7eb',
            'primary_font'   => 'sans-serif',
            'heading_font'   => '',
            'font_size_body' => '16',
            'font_size_h1'   => '2.5',
            'font_size_h2'   => '2',
            'font_size_h3'   => '1.75',
            'font_size_h4'   => '1.5',
            'button_radius'  => '4',
            'analytics_head' => '',
            'facebook_pixel' => '',
            'skins' => [
                'light'  => ['bg' => '#ffffff', 'heading' => '#1a2e5a', 'text' => '#555e6d'],
                'dark'   => ['bg' => '#0d1f3c', 'heading' => '#ffffff',  'text' => '#e2e8f0'],
                'accent' => ['bg' => '#2563eb', 'heading' => '#ffffff',  'text' => '#dbeafe'],
                'subtle' => ['bg' => '#f8fafc', 'heading' => '#1a2e5a',  'text' => '#555e6d'],
            ],
        ],
        'header' => [
            'logo'            => '',
            'logo_max_height' => '56',
            'favicon'         => '',
            'phone'           => '+1 (555) 123-4567',
            'city'            => '',
            'nav_bg'          => '#fd783b',
            'nav_text'        => '#ffffff',
            'phone_btn_style' => 'outline',
            'phone_label'     => 'Helpline:',
            'show_sponsored'  => true,
            'cta_text'        => '',
            'cta_url'         => '',
            'sticky'          => true,
            'info_items'      => [
                ['icon' => '🌐', 'text' => ''],
                ['icon' => '🇺🇸', 'text' => 'Proudly American'],
                ['icon' => '',   'text' => 'Call for Great Service!'],
            ],
            'topbar_text'     => '',
            'topbar_link'     => '',
            'menu'            => [
                ['label' => 'Home',    'url' => '/',       'children' => []],
                ['label' => 'About',   'url' => '#about',  'children' => []],
                ['label' => 'Contact', 'url' => '#contact','children' => []],
            ],
            'socials' => [
                'facebook' => '', 'instagram' => '', 'twitter' => '',
                'youtube'  => '', 'linkedin'  => '', 'tiktok'  => '', 'yelp' => '',
            ],
        ],
        'content_blocks' => [
            [
                'type' => 'text',
                'heading_level' => 'h2',
                'text'  => "Welcome to our website!\n\nThis is your homepage content.",
                'photo' => '',
                'photo_ratio'    => 'landscape',
                'photo_position' => 'center',
                'photo_alt'      => '',
            ],
        ],
        'seo' => [
            'meta_keywords'    => '',
            'meta_description' => '',
            'og_title'         => '',
            'og_description'   => '',
            'og_image'         => '',
            'schema'           => '',
        ],
        'breadcrumbs' => [
            'enabled'      => true,
            'hero_bg_mode' => 'auto',
            'hero_bg_color'=> '',
        ],
        'local_business' => [
            'lb_name'         => '',
            'lb_url'          => '',
            'lb_rating'       => '',
            'lb_review_count' => '',
        ],
        'site_vars' => [
            'business'  => 'Your Business Name',
            'phone'     => '(555) 123-4567',
            'tel'       => 'tel:+15551234567',
            'email'     => 'contact@yourbusiness.com',
            'website'   => '',
            'city'      => 'Your City',
            'state'     => 'Your State',
            'SS'        => 'ST',
            'city_slug' => 'your-city-st',
            'zip'       => '00000',
        ],
        'footer' => [
            'logo'                  => '',
            'logo_in_copyright_bar' => false,
            'phone'           => '+1 (555) 123-4567',
            'col_count'       => 3,
            'disclaimer'      => '',
            'sticky_bar_text' => '24/7 Support Line - Call Now',
            'sticky_bar_info' => '',
            'socials'         => [
                'facebook'  => '',
                'instagram' => '',
                'linkedin'  => '',
                'youtube'   => '',
                'twitter'   => '',
            ],
            'columns'         => [
                [
                    'title' => 'Company',
                    'links' => [
                        ['label' => 'About us',   'url' => '#'],
                        ['label' => 'Contact us', 'url' => '#'],
                    ],
                ],
            ],
            'copyright'   => '© ' . date('Y') . ' My Company. All rights reserved.',
            'bottom_links' => [
                ['label' => 'Privacy Policy',    'url' => '#'],
                ['label' => 'Terms of Service',  'url' => '#'],
            ],
        ],
        'pages'  => [],
        'posts'  => [],
        'blog_settings' => [
            'blog_heading'    => 'Pest Control Tips & News for {city}',
            'blog_intro'      => '',
            'posts_per_page'  => 9,
        ],
        'popups' => [
            'info' => [
                'enabled' => false,
                'heading' => 'How Your Calls Are Handled',
                'image'   => '',
                'body'    => '',
            ],
        ],
    ];
}

function load_data() {
    $defaults = default_data();
    if (!file_exists(DATA_FILE)) return $defaults;
    $json = file_get_contents(DATA_FILE);
    $data = json_decode($json, true);
    if (!is_array($data)) {
        if (trim((string)$json) !== '') {
            error_log('homepage-builder: ' . DATA_FILE . ' failed to decode as JSON (' . json_last_error_msg() . ') — falling back to default content. The file may be corrupted; check it manually.');
        }
        return $defaults;
    }
    foreach ($defaults as $section => $values) {
        if (!isset($data[$section])) { $data[$section] = $values; continue; }
        if (is_array($values) && is_array($data[$section])) {
            foreach ($values as $key => $val) {
                if (!array_key_exists($key, $data[$section])) {
                    $data[$section][$key] = $val;
                }
            }
        }
    }
    if (!empty($data['pages']) && is_array($data['pages'])) {
        $pageDefaults = default_page_data();
        foreach ($data['pages'] as $pid => $page) {
            if (!is_array($page)) $page = [];
            foreach ($pageDefaults as $key => $val) {
                if (!array_key_exists($key, $page)) {
                    $page[$key] = $val;
                } elseif ($key === 'seo' && is_array($val) && is_array($page[$key])) {
                    foreach ($val as $sk => $sv) {
                        if (!array_key_exists($sk, $page[$key])) $page[$key][$sk] = $sv;
                    }
                }
            }
            $data['pages'][$pid] = $page;
        }
    }
    if (!empty($data['posts']) && is_array($data['posts'])) {
        $postDefaults = default_post_data();
        foreach ($data['posts'] as $pid => $post) {
            if (!is_array($post)) $post = [];
            foreach ($postDefaults as $key => $val) {
                if (!array_key_exists($key, $post)) {
                    $post[$key] = $val;
                } elseif ($key === 'seo' && is_array($val) && is_array($post[$key])) {
                    foreach ($val as $sk => $sv) {
                        if (!array_key_exists($sk, $post[$key])) $post[$key][$sk] = $sv;
                    }
                }
            }
            $data['posts'][$pid] = $post;
        }
    }
    return $data;
}

function default_page_data() {
    return [
        'title' => '',
        'slug'  => '',
        'page_type' => 'landing',
        'content_blocks' => [
            ['type' => 'text', 'heading_level' => 'h2', 'text' => '',
             'photo' => '', 'photo_ratio' => 'landscape', 'photo_position' => 'center', 'photo_alt' => ''],
        ],
        'seo' => [
            'meta_keywords' => '', 'meta_description' => '',
            'og_title' => '', 'og_description' => '', 'og_image' => '', 'schema' => '',
            'canonical_url' => '', 'service_name' => '', 'service_type' => '',
            'service_area' => '', 'service_description' => '',
            'bc_label' => '', 'bc_mid_label' => '', 'bc_mid_url' => '',
        ],
    ];
}

function default_post_data() {
    return [
        'title'              => '',
        'slug'               => '',
        'status'             => 'draft',
        'published_at'       => date('Y-m-d'),
        'updated_at'         => date('Y-m-d'),
        'author'             => '{business} Team',
        'tag'                => '',
        'excerpt'            => '',
        'featured_image'     => '',
        'featured_image_alt' => '',
        'content_blocks' => [
            ['type' => 'text', 'heading_level' => 'h2', 'text' => '',
             'photo' => '', 'photo_ratio' => 'landscape', 'photo_position' => 'center', 'photo_alt' => ''],
        ],
        'seo' => [
            'meta_keywords' => '', 'meta_description' => '',
            'og_title' => '', 'og_description' => '', 'og_image' => '', 'schema' => '',
            'canonical_url' => '', 'service_name' => '', 'service_type' => '',
            'service_area' => '', 'service_description' => '',
            'bc_label' => '', 'bc_mid_label' => 'Blog', 'bc_mid_url' => '/blog',
        ],
    ];
}

function save_data($data): bool {
    $dir = dirname(DATA_FILE);
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $tmp  = DATA_FILE . '.tmp.' . getmypid();
    if (file_put_contents($tmp, $json, LOCK_EX) === false) {
        error_log('homepage-builder: failed to write ' . $tmp . ' — check disk space and permissions on ' . $dir);
        return false;
    }
    if (!rename($tmp, DATA_FILE)) {
        error_log('homepage-builder: failed to rename ' . $tmp . ' to ' . DATA_FILE);
        return false;
    }
    return true;
}
