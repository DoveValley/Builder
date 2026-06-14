<?php
/**
 * Shared helper functions for the homepage builder.
 */

function default_data() {
    return [
        'theme' => [
            'header_bg'      => '#ffffff',
            'header_text'    => '#000000',
            'content_bg'     => '#ffffff',
            'content_text'   => '#000000',
            'footer_bg'      => '#ffffff',
            'footer_text'    => '#000000',
            'accent_color'   => '#2563eb',
            'primary_font'   => 'sans-serif',
            'button_radius'  => '4',
            'analytics_head' => '',
            'facebook_pixel' => '',
        ],
        'header' => [
            'logo'            => '',
            'logo_max_height' => '56',
            'phone'           => '+1 (555) 123-4567',
            'city'            => '',
            'nav_bg'          => '#fd783b',
            'nav_text'        => '#ffffff',
            'phone_btn_style' => 'outline',
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
        'footer' => [
            'logo'                  => '',
            'logo_in_copyright_bar' => false,
            'tagline'               => 'Tell visitors what makes you different.',
            'highlight'       => '',
            'phone'           => '+1 (555) 123-4567',
            'email'           => 'info@example.com',
            'disclaimer'      => '',
            'sticky_bar_text' => '24/7 Support Line - Call Now',
            'sticky_bar_info' => '',
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
    if (!is_array($data)) return $defaults;
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
    return $data;
}

function default_page_data() {
    return [
        'title' => '',
        'slug'  => '',
        'content_blocks' => [
            ['type' => 'text', 'heading_level' => 'h2', 'text' => '',
             'photo' => '', 'photo_ratio' => 'landscape', 'photo_position' => 'center', 'photo_alt' => ''],
        ],
        'seo' => [
            'meta_keywords' => '', 'meta_description' => '',
            'og_title' => '', 'og_description' => '', 'og_image' => '', 'schema' => '',
        ],
    ];
}

function slugify($text) {
    $text = strtolower((string) $text);
    $text = preg_replace('/[^a-z0-9]+/', '-', $text);
    return trim($text, '-');
}

function reserved_slugs() {
    return ['', 'admin', 'assets', 'data', 'includes', 'uploads', 'index', 'page', 'home'];
}

function unique_slug($slug, $pages, $excludeId = null) {
    $slug = slugify($slug);
    if ($slug === '' || in_array($slug, reserved_slugs(), true)) $slug = 'page';
    $base = $slug; $i = 2;
    while (true) {
        $collision = false;
        foreach ($pages as $pid => $page) {
            if ($excludeId !== null && $pid === $excludeId) continue;
            if (($page['slug'] ?? '') === $slug) { $collision = true; break; }
        }
        if (!$collision) return $slug;
        $slug = $base . '-' . $i; $i++;
    }
}

function find_page_by_slug($pages, $slug) {
    foreach ($pages as $pid => $page) {
        if (($page['slug'] ?? '') === $slug) return [$pid, $page];
    }
    return [null, null];
}

function save_data($data) {
    if (!is_dir(dirname(DATA_FILE))) mkdir(dirname(DATA_FILE), 0775, true);
    file_put_contents(DATA_FILE, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

function upload_image($fieldName, $prefix) {
    if (!isset($_FILES[$fieldName]) || $_FILES[$fieldName]['error'] === UPLOAD_ERR_NO_FILE) return null;
    if ($_FILES[$fieldName]['error'] !== UPLOAD_ERR_OK) return false;
    return save_uploaded_file($_FILES[$fieldName]['tmp_name'], $prefix);
}

function upload_image_indexed($fieldName, $index, $prefix) {
    if (!isset($_FILES[$fieldName]['error'][$index]) || $_FILES[$fieldName]['error'][$index] === UPLOAD_ERR_NO_FILE) return null;
    if ($_FILES[$fieldName]['error'][$index] !== UPLOAD_ERR_OK) return false;
    return save_uploaded_file($_FILES[$fieldName]['tmp_name'][$index], $prefix);
}

function save_uploaded_file($tmpPath, $prefix) {
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];

    // Max 8MB per upload
    $maxBytes = 8 * 1024 * 1024;
    if (filesize($tmpPath) > $maxBytes) return false;

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $tmpPath);
    finfo_close($finfo);
    if (!isset($allowed[$mime])) return false;
    if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0775, true);
    $ext = $allowed[$mime];
    $filename = $prefix . '_' . time() . '_' . substr(md5(mt_rand()), 0, 6) . '.' . $ext;
    $destination = UPLOAD_DIR . $filename;
    return move_uploaded_file($tmpPath, $destination) ? UPLOAD_URL . $filename : false;
}

function h($string) {
    return htmlspecialchars((string) $string, ENT_QUOTES, 'UTF-8');
}

function text_to_html($text) {
    $text = trim((string) $text);
    if ($text === '') return '';
    // If it already contains HTML tags, output as-is (rich editor content)
    if (preg_match('/<[a-z][\s\S]*>/i', $text)) {
        return $text;
    }
    // Plain text — wrap paragraphs
    $blocks = preg_split('/\n\s*\n/', $text);
    $html = '';
    foreach ($blocks as $block) {
        $block = trim($block);
        if ($block === '') continue;
        $html .= '<p>' . nl2br(h($block)) . "</p>\n";
    }
    return $html;
}

function theme_css_vars($theme) {
    $map = [
        '--color-header-bg'     => $theme['header_bg']     ?? '#120575',
        '--color-header-top-bg' => $theme['header_top_bg'] ?? '#ffffff',
        '--color-header-text'   => $theme['header_text']   ?? '#ffffff',
        '--color-content-bg'    => $theme['content_bg']    ?? '#ffffff',
        '--color-content-text'  => $theme['content_text']  ?? '#000000',
        '--color-footer-bg'     => $theme['footer_bg']     ?? '#120575',
        '--color-footer-text'   => $theme['footer_text']   ?? '#ffffff',
        '--color-accent'        => $theme['accent_color']  ?? '#fd783b',
        '--btn-radius'          => ($theme['button_radius'] ?? '5') . 'px',
    ];
    $font = $theme['primary_font'] ?? ($theme['font_family'] ?? 'sans-serif');
    $css = ":root {\n";
    foreach ($map as $var => $value) {
        $safe = preg_replace('/[^#a-zA-Z0-9(),.%\s\-_]/', '', $value);
        $css .= "    {$var}: {$safe};\n";
    }
    // Font — allow Google Fonts or safe system fonts
    if (preg_match('/^[a-zA-Z0-9\s,\-]+$/', $font)) {
        $css .= "    --font-primary: {$font};\n";
    } else {
        $css .= "    --font-primary: sans-serif;\n";
    }
    $css .= "}\n";
    return $css;
}

/* Resolve a color setting ('accent'|'header'|'custom') to a concrete hex value */
function resolve_color($which, $custom = '#333333') {
    static $themeCache = null;
    if ($themeCache === null) {
        // Try to load theme from the global $data variable or data file
        global $data;
        $themeCache = $data['theme'] ?? [];
        if (empty($themeCache)) {
            $file = __DIR__ . '/../data/site.json';
            if (file_exists($file)) {
                $d = json_decode(file_get_contents($file), true);
                $themeCache = $d['theme'] ?? [];
            }
        }
    }
    if ($which === 'accent') return $themeCache['accent_color'] ?? '#fd783b';
    if ($which === 'header') return $themeCache['header_bg']    ?? '#120575';
    if ($which === 'footer') return $themeCache['footer_bg']    ?? '#120575';
    return $custom ?: '#333333';
}

function photo_ratio_options() {
    return ['landscape' => 'Horizontal rectangle', 'square' => 'Square', 'portrait' => 'Vertical rectangle', 'auto' => 'Original size (no cropping)'];
}

function photo_ratio_to_padding($ratio) {
    if ($ratio === 'auto')      return null;
    if ($ratio === 'square')    return 100;
    if ($ratio === 'portrait')  return 133;
    if ($ratio === 'landscape') return 50;
    if (is_numeric($ratio))     return (float) $ratio;
    return 50;
}

function photo_position_options() {
    return ['center' => 'Center', 'top' => 'Top', 'bottom' => 'Bottom', 'left' => 'Left', 'right' => 'Right'];
}

function render_content_photo($photo, $ratio, $position, $alt = '', $pathPrefix = '') {
    $position = array_key_exists((string) $position, photo_position_options()) ? (string) $position : 'center';
    $padding  = photo_ratio_to_padding((string) $ratio);
    $class = 'content-photo';
    $style = '';
    if ($padding === null) {
        $class .= ' content-photo-auto';
    } else {
        $style = 'padding-top:' . $padding . '%;';
    }
    $altAttr = h($alt ?: '');
    $html  = '<div class="' . $class . '"' . ($style !== '' ? ' style="' . h($style) . '"' : '') . '>';
    $html .= '<img src="' . h($pathPrefix . $photo) . '" alt="' . $altAttr . '" style="object-position:' . h($position) . ';">';
    $html .= '</div>';
    return $html;
}

/* ============================================================
   BLOCK TYPES
   ============================================================ */
function allowed_block_types() {
    return [
        'text'            => 'Text only',
        'image_left'      => 'Image left, text right',
        'image_right'     => 'Text left, image right',
        'hero'            => 'Hero / Banner (full width)',
        'hero_split'      => 'Hero Split (text left, image right)',
        'feature_split'   => 'Feature Split (icon grid left, image right)',
        'split_cta'       => 'Split CTA (colored left panel + phone right panel)',
        'tab_services'    => 'Tab Services (vertical tabs left, image right)',
        'hero_grid'       => 'Hero Grid (image left, icon grid right)',
        'service_cards'   => 'Service Cards Grid (icon + heading + text, centered)',
        'wide_banner'     => 'Wide Banner (full-width bg image, heading left, button right)',
        'image_features'  => 'Image + Feature List (photo left, checklist + phone right)',
        'faq_two_col'     => 'FAQ Two Column (2-col accordion with icon, heading)',
        'cta_banner'      => 'CTA Banner (solid color, centered text)',
        'links_grid'      => 'Links Grid (bg image, heading, link buttons grid)',
        'cta_card'        => 'CTA Card (colored box, heading left, phone button right)',
        'map_info'        => 'Map + Info (Google map left, text + photo right)',
        'image_text'      => 'Image & Text (side by side)',
        'faq'             => 'FAQ / Accordion',
        'feature_columns' => 'Feature Columns (icon + heading + text)',
        'custom_html'     => 'Custom HTML',
        'steps'           => 'Process Steps (Step 1, 2, 3…)',
        'stats'           => 'Stats / Counters',
        'cards'           => 'Cards Grid (image + heading + text + link)',
        'gallery'         => 'Photo Gallery / Image Grid',
        'cta_button'      => 'CTA Button',
    ];
}

function heading_level_options() {
    return ['h1' => 'H1 (Page title — use once)', 'h2' => 'H2 (Section heading)', 'h3' => 'H3 (Sub-section)', 'p' => 'Paragraph (no heading)'];
}

/* ============================================================
   FRONTEND: render a single block
   ============================================================ */
function render_content_block($block, $pathPrefix = '') {
    $type  = $block['type'] ?? 'text';
    $text  = $block['text'] ?? '';
    $photo = $block['photo'] ?? '';
    $ratio    = $block['photo_ratio']    ?? 'landscape';
    $position = $block['photo_position'] ?? 'center';
    $alt      = $block['photo_alt']      ?? '';
    $headingLevel = $block['heading_level'] ?? 'h2';
    if (!array_key_exists($headingLevel, heading_level_options())) $headingLevel = 'h2';

    // Anchor ID — output as id attribute if set, with scroll offset for sticky header
    $anchorAttr = '';
    if (!empty($block['anchor'])) {
        $anchorAttr = ' id="' . h($block['anchor']) . '"';
    }

    switch ($type) {

        /* ---- TEXT ONLY ---- */
        case 'text':
            echo '<div class="content-block text-only"' . $anchorAttr . '>';
            echo '<div class="content-text">';
            if ($headingLevel !== 'p' && $text !== '') {
                $lines = explode("\n", trim($text));
                $heading = array_shift($lines);
                $rest = implode("\n", $lines);
                echo '<' . h($headingLevel) . ' class="block-heading">' . h($heading) . '</' . h($headingLevel) . '>';
                if (trim($rest) !== '') echo text_to_html($rest);
            } else {
                echo text_to_html($text);
            }
            echo '</div></div>';
            break;

        /* ---- IMAGE LEFT / RIGHT (legacy) ---- */
        case 'image_left':
        case 'image_right':
            $layout = ($type === 'image_left') ? 'image-left' : 'image-right';
            echo '<div class="content-block ' . $layout . '"' . $anchorAttr . '>';
            if ($type === 'image_left' && $photo) echo render_content_photo($photo, $ratio, $position, $alt, $pathPrefix);
            echo '<div class="content-text">' . text_to_html($text) . '</div>';
            if ($type === 'image_right' && $photo) echo render_content_photo($photo, $ratio, $position, $alt, $pathPrefix);
            echo '</div>';
            break;

        /* ---- HERO / BANNER ---- */
        case 'hero':
            $heading    = $block['hero_heading']    ?? '';
            $subtext    = $block['hero_subtext']    ?? '';
            $btnText    = $block['hero_btn_text']   ?? '';
            $btnUrl     = $block['hero_btn_url']    ?? '';
            $bgImage    = $block['hero_bg_image']   ?? '';
            $bgColor    = $block['hero_bg_color']   ?? '';
            $textColor  = $block['hero_text_color'] ?? '#ffffff';
            $style = '';
            if ($bgImage) $style .= 'background-image:url(' . h($pathPrefix . $bgImage) . ');background-size:cover;background-position:center;';
            if ($bgColor) $style .= 'background-color:' . h($bgColor) . ';';
            echo '<div class="content-block block-hero" style="' . $style . 'color:' . h($textColor) . ';"' . $anchorAttr . '>';
            echo '<div class="hero-inner">';
            if ($heading) echo '<h1 class="hero-heading">' . h($heading) . '</h1>';
            if ($subtext) echo '<p class="hero-subtext">' . h($subtext) . '</p>';
            if ($btnText) echo '<a href="' . h($btnUrl ?: '#') . '" class="hero-btn">' . h($btnText) . '</a>';
            echo '</div></div>';
            break;

        /* ---- HERO SPLIT (text left, image right with caption) ---- */
        case 'hero_split':
            $heading    = $block['hs_heading']     ?? '';
            $subtext    = $block['hs_subtext']     ?? '';
            $btnText    = $block['hs_btn_text']    ?? '';
            $btnUrl     = $block['hs_btn_url']     ?? '#';
            $photo      = $block['hs_photo']       ?? '';
            $photoAlt   = $block['hs_photo_alt']   ?? '';
            $caption1   = $block['hs_caption1']    ?? '';
            $caption2   = $block['hs_caption2']    ?? '';
            $bgColor    = $block['hs_bg_color']    ?? '#f3f6f7';
            $anchorOut  = $anchorAttr;
            echo '<div class="content-block block-hero-split"'.$anchorOut.' style="background:'.h($bgColor).';">';
            echo '<div class="container hs-inner">';
            // Left: text
            echo '<div class="hs-text">';
            if ($heading) echo '<h1 class="hs-heading">'.h($heading).'</h1>';
            if ($subtext) echo '<p class="hs-subtext">'.h($subtext).'</p>';
            if ($btnText) echo '<a href="'.h($btnUrl).'" class="hs-btn">'.h($btnText).'</a>';
            echo '</div>';
            // Right: image with caption overlay
            if ($photo) {
                $photoSrc = (str_starts_with($photo, 'http') || str_starts_with($photo, '//'))
                    ? $photo
                    : $pathPrefix . $photo;
                echo '<div class="hs-image-wrap">';
                echo '<img src="'.h($photoSrc).'" alt="'.h($photoAlt).'" class="hs-image">';
                if ($caption1 || $caption2) {
                    echo '<div class="hs-caption">';
                    if ($caption1) echo '<div class="hs-caption-title">'.h($caption1).'</div>';
                    if ($caption2) echo '<div class="hs-caption-sub">'.h($caption2).'</div>';
                    echo '</div>';
                }
                echo '</div>';
            }
            echo '</div></div>';
            break;

        /* ---- FEATURE SPLIT (icon grid left, arched image right) ---- */
        case 'feature_split':
            $heading     = $block['fs_heading']   ?? '';
            $subtext     = $block['fs_subtext']   ?? '';
            $photo       = $block['fs_photo']     ?? '';
            $photoAlt    = $block['fs_photo_alt'] ?? '';
            $starText    = $block['fs_star_text'] ?? '';
            $bgColor     = $block['fs_bg_color']  ?? '#f3f6f7';
            $accentColor = $block['fs_accent']    ?? '#fd783b';
            $items       = $block['fs_items']     ?? [];
            $photoSrc    = '';
            if ($photo) {
                $photoSrc = (str_starts_with($photo, 'http') || str_starts_with($photo, '//'))
                    ? $photo : $pathPrefix . $photo;
            }
            echo '<div class="content-block block-feature-split"'.$anchorAttr.' style="background:'.h($bgColor).';">';
            echo '<div class="container fs-inner">';
            // LEFT: heading + intro + icon grid
            echo '<div class="fs-left">';
            if ($heading) echo '<h2 class="fs-heading">'.h($heading).'</h2>';
            if ($subtext) echo '<p class="fs-subtext">'.h($subtext).'</p>';
            echo '<div class="fs-grid">';
            foreach ($items as $item) {
                $iIcon = $item['icon'] ?? '';
                $iHead = $item['heading'] ?? '';
                $iText = $item['text']    ?? '';
                $iAlt  = $item['alt']     ?? '';
                $iIconSrc = '';
                if ($iIcon) {
                    $iIconSrc = (str_starts_with($iIcon, 'http') || str_starts_with($iIcon, '//'))
                        ? $iIcon : $pathPrefix . $iIcon;
                }
                echo '<div class="fs-item">';
                if ($iIconSrc) echo '<img class="fs-item-icon" src="'.h($iIconSrc).'" alt="'.h($iAlt).'">';
                echo '<div class="fs-item-body">';
                if ($iHead) echo '<h3 class="fs-item-heading" style="color:'.h($accentColor).';">'.h($iHead).'</h3>';
                if ($iText) echo '<p class="fs-item-text">'.h($iText).'</p>';
                echo '</div></div>';
            }
            echo '</div></div>';
            // RIGHT: arched image + star badge
            echo '<div class="fs-right">';
            if ($photoSrc) {
                echo '<div class="fs-arch-wrap"><img src="'.h($photoSrc).'" alt="'.h($photoAlt).'" class="fs-arch-img"></div>';
            }
            if ($starText) {
                echo '<div class="fs-star-badge"><span class="fs-stars">★★★★★</span><span class="fs-star-text">'.h($starText).'</span></div>';
            }
            echo '</div>';
            echo '</div></div>';
            break;

        case 'feature_columns':
            $heading = $block['fc_heading'] ?? '';
            $cols    = $block['columns']    ?? [];
            $numCols = max(2, min(4, (int)($block['fc_num_cols'] ?? 3)));
            echo '<div class="content-block block-feature-columns"' . $anchorAttr . '>';
            if ($heading) echo '<h2 class="section-heading">' . h($heading) . '</h2>';
            echo '<div class="feature-grid feature-grid-' . $numCols . '">';
            foreach ($cols as $col) {
                $colImg  = $col['image']   ?? '';
                $colHead = $col['heading'] ?? '';
                $colText = $col['text']    ?? '';
                $colAlt  = $col['alt']     ?? '';
                echo '<div class="feature-col">';
                if ($colImg) echo '<img class="feature-icon" src="' . h($pathPrefix . $colImg) . '" alt="' . h($colAlt) . '">';
                if ($colHead) echo '<h3 class="feature-col-heading">' . h($colHead) . '</h3>';
                if ($colText) echo '<p class="feature-col-text">' . h($colText) . '</p>';
                echo '</div>';
            }
            echo '</div></div>';
            break;

        /* ---- SPLIT CTA (two panels side by side) ---- */
        case 'split_cta':
            $leftHeading  = $block['sc_left_heading']  ?? '';
            $leftText     = $block['sc_left_text']     ?? '';
            $leftBg       = $block['sc_left_bg']       ?? 'accent';   // 'accent' | 'header' | 'custom'
            $leftBgCustom = $block['sc_left_bg_custom']?? '#fd783b';
            $rightLabel   = $block['sc_right_label']   ?? '';
            $rightPhone   = $block['sc_right_phone']   ?? '';
            $rightPhoneUrl= $block['sc_right_phone_url']?? '';
            $rightBg      = $block['sc_right_bg']      ?? 'header';
            $rightBgCustom= $block['sc_right_bg_custom']?? '#120575';

            // Resolve background colors — use concrete hex values
            $leftStyle  = 'background:' . resolve_color($leftBg,  $leftBgCustom)  . ';';
            $rightStyle = 'background:' . resolve_color($rightBg, $rightBgCustom) . ';';

            echo '<div class="content-block block-split-cta"'.$anchorAttr.'>';
            // Left panel
            echo '<div class="sc-panel sc-left" style="'.$leftStyle.'">';
            if ($leftHeading) echo '<h2 class="sc-heading">'.h($leftHeading).'</h2>';
            if ($leftText)    echo '<p class="sc-text">'.h($leftText).'</p>';
            echo '</div>';
            // Right panel
            echo '<div class="sc-panel sc-right" style="'.$rightStyle.'">';
            if ($rightLabel) echo '<div class="sc-right-label">'.h($rightLabel).'</div>';
            if ($rightPhone) {
                $tel = $rightPhoneUrl ?: 'tel:'.preg_replace('/[^0-9+]/', '', $rightPhone);
                echo '<a href="'.h($tel).'" class="sc-phone">'.h($rightPhone).'</a>';
            }
            echo '</div>';
            echo '</div>';
            break;

        /* ---- CTA BUTTON ---- */
        case 'cta_button':
            $btnText    = $block['cta_text']       ?? 'Contact Us';
            $btnUrl     = $block['cta_url']        ?? '#';
            $subtext    = $block['cta_subtext']    ?? '';
            $align      = $block['cta_align']      ?? 'center';
            echo '<div class="content-block block-cta" style="text-align:' . h($align) . ';"' . $anchorAttr . '>';
            if ($subtext) echo '<p class="cta-subtext">' . h($subtext) . '</p>';
            echo '<a href="' . h($btnUrl) . '" class="cta-btn">' . h($btnText) . '</a>';
            echo '</div>';
            break;

        /* ---- IMAGE & TEXT SIDE BY SIDE ---- */
        case 'image_text':
            $imgSide  = $block['it_image_side'] ?? 'left';
            $itPhoto  = $block['it_photo']      ?? '';
            $itAlt    = $block['it_alt']        ?? '';
            $itRatio  = $block['it_ratio']      ?? 'landscape';
            $itPos    = $block['it_position']   ?? 'center';
            $itHead   = $block['it_heading']    ?? '';
            $itLevel  = $block['it_heading_level'] ?? 'h2';
            $itText   = $block['it_text']       ?? '';
            $itBtn    = $block['it_btn_text']   ?? '';
            $itBtnUrl = $block['it_btn_url']    ?? '';
            $layout   = ($imgSide === 'right') ? 'image-right' : 'image-left';
            echo '<div class="content-block ' . $layout . '"' . $anchorAttr . '>';
            if ($imgSide === 'left' && $itPhoto)  echo render_content_photo($itPhoto, $itRatio, $itPos, $itAlt, $pathPrefix);
            echo '<div class="content-text">';
            if ($itHead) echo '<' . h($itLevel) . ' class="block-heading">' . h($itHead) . '</' . h($itLevel) . '>';
            if ($itText) echo text_to_html($itText);
            if ($itBtn)  echo '<a href="' . h($itBtnUrl ?: '#') . '" class="cta-btn cta-btn-sm">' . h($itBtn) . '</a>';
            echo '</div>';
            if ($imgSide === 'right' && $itPhoto) echo render_content_photo($itPhoto, $itRatio, $itPos, $itAlt, $pathPrefix);
            echo '</div>';
            break;

        /* ---- FAQ / ACCORDION ---- */
        case 'faq':
            $heading = $block['faq_heading'] ?? '';
            $items   = $block['faq_items']   ?? [];
            $faqId   = 'faq_' . substr(md5(serialize($block)), 0, 6);
            echo '<div class="content-block block-faq"' . $anchorAttr . '>';
            if ($heading) echo '<h2 class="section-heading">' . h($heading) . '</h2>';
            echo '<div class="faq-list" id="' . $faqId . '">';
            foreach ($items as $idx => $item) {
                $q = $item['question'] ?? '';
                $a = $item['answer']   ?? '';
                if ($q === '' && $a === '') continue;
                $uid = $faqId . '_' . $idx;
                echo '<div class="faq-item">';
                echo '<button class="faq-question" onclick="toggleFaq(\'' . $uid . '\')" aria-expanded="false">';
                echo h($q) . '<span class="faq-icon">+</span></button>';
                echo '<div class="faq-answer" id="' . $uid . '" hidden>' . text_to_html($a) . '</div>';
                echo '</div>';
            }
            echo '</div></div>';
            break;

        /* ---- CUSTOM HTML ---- */
        case 'custom_html':
            $html = $block['html'] ?? '';
            if ($html !== '') {
                echo '<div class="content-block block-custom-html"' . $anchorAttr . '>';
                echo $html;
                echo '</div>';
            }
            break;

        /* ---- CTA CARD ---- */
        case 'cta_card':
            $heading   = $block['cc_heading']   ?? '';
            $text      = $block['cc_text']      ?? '';
            $btnText   = $block['cc_btn_text']  ?? '';
            $btnUrl    = $block['cc_btn_url']   ?? '#';
            $btnStyle  = $block['cc_btn_style'] ?? 'outline';
            $bg        = $block['cc_bg']        ?? 'accent';
            $bgCustom  = $block['cc_bg_custom'] ?? '#fd783b';
            $radius    = $block['cc_radius']    ?? '12';
            $align     = $block['cc_align']     ?? 'split'; // split | center

            $bgStyle = resolve_color($bg, $bgCustom);

            if ($align === 'center') {
                echo '<div class="content-block block-cta-card"'.$anchorAttr.'>';
                echo '<div class="cc-box cc-centered" style="background:'.$bgStyle.';border-radius:'.h($radius).'px;text-align:center;">';
                if ($heading) echo '<h2 class="cc-heading">'.h($heading).'</h2>';
                if ($text)    echo '<p class="cc-text" style="max-width:860px;margin:0 auto;">'.h($text).'</p>';
                if ($btnText) echo '<a href="'.h($btnUrl).'" class="cc-btn cc-btn-'.h($btnStyle).'" style="margin-top:20px;">'.h($btnText).'</a>';
                echo '</div></div>';
            } else {
                echo '<div class="content-block block-cta-card"'.$anchorAttr.'>';
                echo '<div class="container">';
                echo '<div class="cc-box" style="background:'.$bgStyle.';border-radius:'.h($radius).'px;">';
                echo '<div class="cc-left">';
                if ($heading) echo '<h2 class="cc-heading">'.h($heading).'</h2>';
                if ($text)    echo '<p class="cc-text">'.h($text).'</p>';
                echo '</div>';
                if ($btnText) {
                    $btnClass = $btnStyle === 'filled' ? 'cc-btn cc-btn-filled' : 'cc-btn cc-btn-outline';
                    echo '<div class="cc-right"><a href="'.h($btnUrl).'" class="'.$btnClass.'">'.h($btnText).'</a></div>';
                }
                echo '</div></div></div>';
            }
            break;

        /* ---- MAP + INFO ---- */
        case 'map_info':
            $mapHeading  = $block['mi_map_heading']  ?? '';
            $mapEmbed    = $block['mi_map_embed']     ?? '';
            $infoHeading = $block['mi_info_heading']  ?? '';
            $infoText    = $block['mi_info_text']     ?? '';
            $infoPhoto   = $block['mi_info_photo']    ?? '';
            $infoAlt     = $block['mi_info_alt']      ?? '';
            $headColor   = $block['mi_head_color']    ?? 'header';
            $headColorC  = $block['mi_head_color_custom'] ?? '#120575';

            $headStyle = resolve_color($headColor, $headColorC);

            $infoPhotoSrc = '';
            if ($infoPhoto) {
                $infoPhotoSrc = (str_starts_with($infoPhoto,'http') || str_starts_with($infoPhoto,'//'))
                    ? $infoPhoto : $pathPrefix.$infoPhoto;
            }

            echo '<div class="content-block block-map-info"'.$anchorAttr.'>';
            echo '<div class="container mi-grid">';

            // LEFT: map
            echo '<div class="mi-panel mi-map-panel">';
            if ($mapHeading) echo '<h2 class="mi-heading" style="color:'.$headStyle.';">'.h($mapHeading).'</h2>';
            if ($mapEmbed)   echo '<div class="mi-map-wrap">'.$mapEmbed.'</div>';
            echo '</div>';

            // RIGHT: info
            echo '<div class="mi-panel mi-info-panel">';
            if ($infoHeading) echo '<h2 class="mi-heading" style="color:'.$headStyle.';">'.h($infoHeading).'</h2>';
            if ($infoText)    echo '<p class="mi-text">'.h($infoText).'</p>';
            if ($infoPhotoSrc) echo '<img src="'.h($infoPhotoSrc).'" alt="'.h($infoAlt).'" class="mi-photo">';
            echo '</div>';

            echo '</div></div>';
            break;

        /* ---- LINKS GRID ---- */
        case 'links_grid':
            $heading  = $block['lg_heading']  ?? '';
            $subtext  = $block['lg_subtext']  ?? '';
            $subLabel = $block['lg_sublabel'] ?? '';
            $photo    = $block['lg_photo']    ?? '';
            $photoAlt = $block['lg_photo_alt']?? '';
            $cols     = max(2, min(6, (int)($block['lg_cols'] ?? 5)));
            $overlay  = $block['lg_overlay']  ?? '0.6';
            $links    = $block['lg_links']    ?? [];
            $style    = $block['lg_style']    ?? 'dark'; // dark | light
            $bgColor  = $block['lg_bg_color'] ?? '#ffffff';
            $accentC  = $block['lg_accent']   ?? 'accent';
            $accentCC = $block['lg_accent_custom'] ?? '#fd783b';

            $accentStyle = resolve_color($accentC, $accentCC);

            $photoSrc = '';
            if ($photo) {
                $photoSrc = (str_starts_with($photo,'http') || str_starts_with($photo,'//'))
                    ? $photo : $pathPrefix.$photo;
            }

            if ($style === 'light') {
                // Light style: white bg, dark text, gray bordered boxes
                echo '<div class="content-block block-links-grid block-links-light"'.$anchorAttr.' style="background:'.h($bgColor).';">';
                echo '<div class="container">';
                if ($subLabel || $heading) {
                    echo '<div class="lg-light-header">';
                    if ($subLabel) echo '<div class="lg-sublabel" style="color:'.$accentStyle.';">'.h($subLabel).'</div>';
                    if ($heading)  echo '<h2 class="lg-light-heading">'.h($heading).'</h2>';
                    echo '</div>';
                }
                echo '<div class="lg-grid lg-light-grid lg-cols-'.$cols.'">';
                foreach ($links as $link) {
                    $label = $link['label'] ?? ''; $url = $link['url'] ?? '#';
                    if (!$label) continue;
                    echo '<a href="'.h($url).'" class="lg-light-link">'.h($label).'</a>';
                }
                echo '</div></div></div>';
            } else {
                // Dark style: bg image with overlay
                $bgStyle = $photoSrc
                    ? 'background-image:url('.h($photoSrc).');background-size:cover;background-position:center;'
                    : 'background:#1a1a2e;';
                echo '<div class="content-block block-links-grid"'.$anchorAttr.' style="'.$bgStyle.'">';
                echo '<div class="lg-overlay" style="background:rgba(0,0,0,'.h($overlay).');">';
                if ($heading || $subtext) {
                    echo '<div class="lg-header container">';
                    if ($heading) echo '<h2 class="lg-heading">'.h($heading).'</h2>';
                    if ($subtext) echo '<p class="lg-subtext">'.h($subtext).'</p>';
                    echo '</div>';
                }
                echo '<div class="lg-grid-wrap container">';
                echo '<div class="lg-grid lg-cols-'.$cols.'">';
                foreach ($links as $link) {
                    $label = $link['label'] ?? ''; $url = $link['url'] ?? '#';
                    if (!$label) continue;
                    echo '<a href="'.h($url).'" class="lg-link">'.h($label).'</a>';
                }
                echo '</div></div>';
                echo '</div></div>';
            }
            break;

        /* ---- CTA BANNER ---- */
        case 'cta_banner':
            $text      = $block['cb_text']       ?? '';
            $subtext   = $block['cb_subtext']    ?? '';
            $btnText   = $block['cb_btn_text']   ?? '';
            $btnUrl    = $block['cb_btn_url']    ?? '#';
            $bg        = $block['cb_bg']         ?? 'accent';
            $bgCustom  = $block['cb_bg_custom']  ?? '#fd783b';
            $textColor = $block['cb_text_color'] ?? '#ffffff';
            $padding   = $block['cb_padding']    ?? 'normal'; // compact | normal | large

            $bgStyle = resolve_color($bg, $bgCustom);

            $paddingMap = ['compact' => '20px 0', 'normal' => '32px 0', 'large' => '56px 0'];
            $paddingStyle = $paddingMap[$padding] ?? '32px 0';

            echo '<div class="content-block block-cta-banner"'.$anchorAttr
                .' style="background:'.$bgStyle.';padding:'.$paddingStyle.';text-align:center;">';
            if ($text)    echo '<div class="container"><p class="cb-text" style="color:'.h($textColor).';">'.h($text).'</p>';
            if ($subtext) echo '<p class="cb-subtext" style="color:'.h($textColor).';">'.h($subtext).'</p>';
            if ($btnText) echo '<a href="'.h($btnUrl).'" class="cb-btn" style="color:'.h($bgStyle).';background:'.h($textColor).';">'.h($btnText).'</a>';
            echo '</div></div>';
            break;

        /* ---- FAQ TWO COLUMN ---- */
        case 'faq_two_col':
            $heading     = $block['fq_heading']    ?? '';
            $items       = $block['fq_items']      ?? [];
            $bgColor     = $block['fq_bg_color']   ?? '#ffffff';
            $headColor   = $block['fq_head_color'] ?? 'header';
            $headColorC  = $block['fq_head_color_custom'] ?? '#120575';
            $iconBg      = $block['fq_icon_bg']    ?? 'accent';
            $iconBgC     = $block['fq_icon_bg_custom'] ?? '#fd783b';
            $itemBg      = $block['fq_item_bg']    ?? '#f0f2f8';

            $headStyle = resolve_color($headColor, $headColorC);
            $iconStyle = resolve_color($iconBg, $iconBgC);

            $uid = 'fq_'.substr(md5(serialize($block)),0,6);

            echo '<div class="content-block block-faq-two-col"'.$anchorAttr.' style="background:'.h($bgColor).';">';
            echo '<div class="container">';
            if ($heading) echo '<h2 class="fq-heading" style="color:'.$headStyle.';">'.h($heading).'</h2>';
            echo '<div class="fq-grid" id="'.$uid.'">';
            foreach ($items as $qi => $item) {
                $q = $item['question'] ?? '';
                $a = $item['answer']   ?? '';
                if (!$q) continue;
                $iid = $uid.'_'.$qi;
                echo '<div class="fq-item" style="background:'.h($itemBg).';">';
                echo '<button class="fq-btn" onclick="toggleFq(\''.$iid.'\')" aria-expanded="false">';
                echo '<span class="fq-icon" style="background:'.$iconStyle.';">+</span>';
                echo '<span class="fq-question">'.h($q).'</span>';
                echo '</button>';
                echo '<div class="fq-answer" id="'.$iid.'" hidden>'.text_to_html($a).'</div>';
                echo '</div>';
            }
            echo '</div></div></div>';
            break;

        /* ---- IMAGE FEATURES ---- */
        case 'image_features':
            $heading     = $block['if_heading']     ?? '';
            $intro       = $block['if_intro']       ?? '';
            $closing     = $block['if_closing']     ?? '';
            $photo       = $block['if_photo']       ?? '';
            $photoAlt    = $block['if_photo_alt']   ?? '';
            $phoneLabel  = $block['if_phone_label'] ?? '';
            $phone       = $block['if_phone']       ?? '';
            $phoneUrl    = $block['if_phone_url']   ?? '';
            $features    = $block['if_features']    ?? [];
            $bgColor     = $block['if_bg_color']    ?? '#f3f6f7';
            $checkColor  = $block['if_check_color'] ?? 'accent';
            $checkColorC = $block['if_check_color_custom'] ?? '#fd783b';
            $headColor   = $block['if_head_color']  ?? 'header';
            $headColorC  = $block['if_head_color_custom']  ?? '#120575';

            $checkBg   = resolve_color($checkColor, $checkColorC);
            $headStyle = resolve_color($headColor,  $headColorC);

            $photoSrc = '';
            if ($photo) {
                $photoSrc = (str_starts_with($photo,'http') || str_starts_with($photo,'//'))
                    ? $photo : $pathPrefix.$photo;
            }
            $tel = $phoneUrl ?: ('tel:'.preg_replace('/[^0-9+]/', '', $phone));

            echo '<div class="content-block block-image-features"'.$anchorAttr.' style="background:'.h($bgColor).';">';
            echo '<div class="container if-inner">';

            // LEFT: photo
            if ($photoSrc) {
                echo '<div class="if-photo-wrap">';
                echo '<img src="'.h($photoSrc).'" alt="'.h($photoAlt).'" class="if-photo">';
                echo '</div>';
            }

            // RIGHT: content
            echo '<div class="if-content">';
            if ($heading) echo '<h2 class="if-heading" style="color:'.$headStyle.';">'.h($heading).'</h2>';
            if ($intro)   echo '<p class="if-intro">'.h($intro).'</p>';

            // 2×2 feature grid
            if (!empty($features)) {
                echo '<div class="if-features-grid">';
                foreach ($features as $feat) {
                    echo '<div class="if-feature-box">';
                    echo '<span class="if-check" style="background:'.$checkBg.';">✓</span>';
                    echo '<span class="if-feat-text" style="color:'.$headStyle.';">'.h($feat).'</span>';
                    echo '</div>';
                }
                echo '</div>';
            }

            if ($closing) echo '<p class="if-closing">'.h($closing).'</p>';

            // Phone CTA row
            if ($phone) {
                echo '<div class="if-phone-row">';
                echo '<a href="'.h($tel).'" class="if-phone-icon" style="background:'.$checkBg.';">📞</a>';
                echo '<div class="if-phone-info">';
                if ($phoneLabel) echo '<div class="if-phone-label">'.h($phoneLabel).'</div>';
                echo '<a href="'.h($tel).'" class="if-phone-number" style="color:'.$headStyle.';">'.h($phone).'</a>';
                echo '</div></div>';
            }

            echo '</div></div></div>';
            break;

        /* ---- WIDE BANNER ---- */
        case 'wide_banner':
            $badge      = $block['wb_badge']      ?? '';
            $heading    = $block['wb_heading']    ?? '';
            $btnText    = $block['wb_btn_text']   ?? '';
            $btnUrl     = $block['wb_btn_url']    ?? '#';
            $photo      = $block['wb_photo']      ?? '';
            $photoAlt   = $block['wb_photo_alt']  ?? '';
            $overlayOpacity = $block['wb_overlay'] ?? '0.55';
            $badgeBg    = $block['wb_badge_bg']   ?? 'accent';
            $badgeBgC   = $block['wb_badge_bg_custom'] ?? '#fd783b';
            $btnStyle   = $block['wb_btn_style']  ?? 'filled';

            $badgeBgStyle = resolve_color($badgeBg, $badgeBgC);

            $photoSrc = '';
            if ($photo) {
                $photoSrc = (str_starts_with($photo,'http') || str_starts_with($photo,'//'))
                    ? $photo : $pathPrefix.$photo;
            }

            $bgStyle = $photoSrc
                ? 'background-image:url('.h($photoSrc).');background-size:cover;background-position:center;'
                : 'background:#1a1a2e;';

            echo '<div class="content-block block-wide-banner"'.$anchorAttr.' style="'.$bgStyle.'">';
            echo '<div class="wb-overlay" style="background:rgba(0,0,0,'.h($overlayOpacity).');">';
            echo '<div class="container wb-inner">';

            // Left: badge + heading
            echo '<div class="wb-left">';
            if ($badge)   echo '<span class="wb-badge" style="background:'.$badgeBgStyle.';color:#fff;">'.h($badge).'</span>';
            if ($heading) echo '<h2 class="wb-heading">'.h($heading).'</h2>';
            echo '</div>';

            // Right: button
            if ($btnText) {
                $btnClass = $btnStyle === 'outline' ? 'wb-btn wb-btn-outline' : 'wb-btn wb-btn-filled';
                echo '<div class="wb-right">';
                echo '<a href="'.h($btnUrl).'" class="'.$btnClass.'" style="'.($btnStyle === 'filled' ? 'background:'.h($badgeBgStyle).';' : 'border-color:'.h($badgeBgStyle).';color:'.h($badgeBgStyle).';').'">'.h($btnText).'</a>';
                echo '</div>';
            }

            echo '</div></div></div>';
            break;

        /* ---- SERVICE CARDS GRID ---- */
        case 'service_cards':
            $badge      = $block['sc_badge']      ?? '';
            $heading    = $block['sc_heading']    ?? '';
            $cols       = max(2, min(4, (int)($block['sc_cols'] ?? 3)));
            $items      = $block['sc_items']      ?? [];
            $badgeBg    = $block['sc_badge_bg']   ?? 'accent';
            $badgeBgC   = $block['sc_badge_bg_custom'] ?? '#fd783b';
            $headColor  = $block['sc_head_color'] ?? 'header';
            $headColorC = $block['sc_head_color_custom'] ?? '#120575';
            $iconBg     = $block['sc_icon_bg']    ?? '#fef0e7';

            $badgeBgStyle   = resolve_color($badgeBg,   $badgeBgC);
            $headColorStyle = resolve_color($headColor, $headColorC);

            echo '<div class="content-block block-service-cards"'.$anchorAttr.'>';
            echo '<div class="container">';
            if ($badge)   echo '<div class="svc-badge-wrap"><span class="svc-badge" style="background:'.$badgeBgStyle.';color:#fff;">'.h($badge).'</span></div>';
            if ($heading) echo '<h2 class="svc-heading" style="color:'.$headColorStyle.';">'.h($heading).'</h2>';
            echo '<div class="svc-grid svc-grid-'.$cols.'">';
            foreach ($items as $item) {
                $iIcon  = $item['icon']       ?? '';
                $iEmoji = $item['icon_emoji'] ?? '';
                $iAlt   = $item['alt']        ?? '';
                $iHead  = $item['heading']    ?? '';
                $iText  = $item['text']       ?? '';
                $iIconSrc = '';
                if ($iIcon) $iIconSrc = (str_starts_with($iIcon,'http') || str_starts_with($iIcon,'//')) ? $iIcon : $pathPrefix.$iIcon;
                echo '<div class="svc-card">';
                if ($iIconSrc) {
                    echo '<div class="svc-icon-wrap" style="background:'.h($iconBg).';"><img src="'.h($iIconSrc).'" alt="'.h($iAlt).'" class="svc-icon"></div>';
                } elseif ($iEmoji) {
                    echo '<div class="svc-icon-wrap" style="background:'.h($iconBg).';font-size:2rem;line-height:1;"><span>'.h($iEmoji).'</span></div>';
                }
                if ($iHead) echo '<h3 class="svc-card-heading">'.h($iHead).'</h3>';
                if ($iText) echo '<p class="svc-card-text">'.h($iText).'</p>';
                echo '</div>';
            }
            echo '</div></div></div>';
            break;

        /* ---- HERO GRID (image left with overlay, icon grid right) ---- */
        case 'hero_grid':
            $label      = $block['hg_label']      ?? '';
            $heading    = $block['hg_heading']    ?? '';
            $body       = $block['hg_body']       ?? '';
            $btnText    = $block['hg_btn_text']   ?? '';
            $btnUrl     = $block['hg_btn_url']    ?? '#';
            $photo      = $block['hg_photo']      ?? '';
            $photoAlt   = $block['hg_photo_alt']  ?? '';
            $gridItems  = $block['hg_items']      ?? [];
            $color1     = $block['hg_color1']     ?? 'accent';   // odd tiles
            $color1c    = $block['hg_color1_custom'] ?? '#fd783b';
            $color2     = $block['hg_color2']     ?? 'header';   // even tiles
            $color2c    = $block['hg_color2_custom'] ?? '#120575';

            $resolveColor = function($which, $custom) {
                if ($which === 'accent')  return 'var(--color-accent,#fd783b)';
                if ($which === 'header')  return 'var(--color-header-bg,#120575)';
                return h($custom);
            };
            $c1 = $resolveColor($color1, $color1c);
            $c2 = $resolveColor($color2, $color2c);

            $photoSrc = '';
            if ($photo) {
                $photoSrc = (str_starts_with($photo,'http') || str_starts_with($photo,'//'))
                    ? $photo : $pathPrefix.$photo;
            }

            echo '<div class="content-block block-hero-grid"'.$anchorAttr.'>';

            // LEFT: image with overlay + text
            echo '<div class="hg-left" style="'.($photoSrc ? 'background-image:url('.h($photoSrc).');' : 'background:#1a1a2e;').'">';
            echo '<div class="hg-overlay">';
            if ($label)   echo '<div class="hg-label">'.h($label).'</div>';
            if ($heading) echo '<h2 class="hg-heading">'.h($heading).'</h2>';
            if ($body)    echo '<div class="hg-body">'.text_to_html($body).'</div>';
            if ($btnText) echo '<a href="'.h($btnUrl).'" class="hg-btn">'.h($btnText).'</a>';
            echo '</div></div>';

            // RIGHT: 3×2 icon grid
            echo '<div class="hg-grid">';
            foreach ($gridItems as $gi => $item) {
                $iIcon    = $item['icon']    ?? '';
                $iLabel   = $item['label']   ?? '';
                $iAlt     = $item['alt']     ?? $iLabel;
                $iIconSrc = '';
                if ($iIcon) {
                    $iIconSrc = (str_starts_with($iIcon,'http') || str_starts_with($iIcon,'//'))
                        ? $iIcon : $pathPrefix.$iIcon;
                }
                $tileBg = ($gi % 2 === 0) ? $c1 : $c2;
                echo '<div class="hg-tile" style="background:'.$tileBg.';">';
                if ($iIconSrc) {
                    echo '<img src="'.h($iIconSrc).'" alt="'.h($iAlt).'" class="hg-tile-icon">';
                }
                if ($iLabel) echo '<span class="hg-tile-label">'.h($iLabel).'</span>';
                echo '</div>';
            }
            echo '</div>';

            echo '</div>';
            break;

        /* ---- TAB SERVICES ---- */
        case 'tab_services':
            $badge1      = $block['ts_badge1']      ?? '';
            $badge2      = $block['ts_badge2']      ?? '';
            $heading     = $block['ts_heading']     ?? '';
            $tabs        = $block['ts_tabs']        ?? [];
            $activeBg    = $block['ts_active_bg']   ?? 'header'; // header | accent | custom
            $activeBgCustom = $block['ts_active_bg_custom'] ?? '#120575';
            $uid = 'ts_' . substr(md5(serialize($block)), 0, 6);

            $activeBgStyle = resolve_color($activeBg, $activeBgCustom);

            echo '<div class="content-block block-tab-services"'.$anchorAttr.'>';
            echo '<div class="container">';
            // Badges + heading
            if ($badge1 || $badge2) {
                echo '<div class="ts-badges">';
                if ($badge1) echo '<span class="ts-badge ts-badge-filled" style="background:'.$activeBgStyle.';color:#fff;">'.h($badge1).'</span>';
                if ($badge2) echo '<span class="ts-badge ts-badge-outline" style="border-color:'.$activeBgStyle.';color:'.$activeBgStyle.';">'.h($badge2).'</span>';
                echo '</div>';
            }
            if ($heading) echo '<h2 class="ts-heading">'.h($heading).'</h2>';

            echo '<div class="ts-layout" id="'.$uid.'">';
            // Left: tab list
            echo '<div class="ts-tabs">';
            foreach ($tabs as $ti => $tab) {
                $label   = $tab['label']   ?? '';
                $icon    = $tab['icon']    ?? '';
                $iconSrc = '';
                if ($icon) {
                    $iconSrc = (str_starts_with($icon,'http') || str_starts_with($icon,'//')) ? $icon : $pathPrefix.$icon;
                }
                $isActive = $ti === 0 ? 'ts-tab-active' : '';
                $activeInlineStyle = $ti === 0 ? 'background:'.$activeBgStyle.';color:#fff;' : '';
                echo '<button class="ts-tab '.$isActive.'" data-tab="'.$ti.'" data-uid="'.$uid.'"'
                     .' onclick="switchTab(this)"'
                     .($activeInlineStyle ? ' style="'.$activeInlineStyle.'"' : '')
                     .' data-active-bg="'.$activeBgStyle.'">';
                if ($iconSrc) echo '<img src="'.h($iconSrc).'" class="ts-tab-icon" alt="'.h($label).'">';
                echo '<span>'.h($label).'</span>';
                echo '</button>';
            }
            echo '</div>';
            // Right: panels
            echo '<div class="ts-panels">';
            foreach ($tabs as $ti => $tab) {
                $photo   = $tab['photo'] ?? '';
                $alt     = $tab['alt']   ?? ($tab['label'] ?? '');
                $desc    = $tab['desc']  ?? '';
                $photoSrc = '';
                if ($photo) {
                    $photoSrc = (str_starts_with($photo,'http') || str_starts_with($photo,'//')) ? $photo : $pathPrefix.$photo;
                }
                $hidden = $ti === 0 ? '' : ' hidden';
                echo '<div class="ts-panel" data-panel="'.$ti.'"'.$hidden.'>';
                if ($photoSrc) echo '<img src="'.h($photoSrc).'" alt="'.h($alt).'" class="ts-photo" loading="lazy">';
                if ($desc)     echo '<div class="ts-desc">'.h($desc).'</div>';
                echo '</div>';
            }
            echo '</div>';
            echo '</div></div></div>';
            break;

        /* ---- PHOTO GALLERY ---- */
        case 'gallery':
            $heading  = $block['gallery_heading'] ?? '';
            $cols     = max(2, min(4, (int)($block['gallery_cols'] ?? 3)));
            $images   = $block['gallery_images'] ?? [];
            echo '<div class="content-block block-gallery"' . $anchorAttr . '>';
            if ($heading) echo '<h2 class="section-heading">' . h($heading) . '</h2>';
            echo '<div class="gallery-grid gallery-grid-' . $cols . '">';
            foreach ($images as $img) {
                $src = $img['photo'] ?? '';
                $alt = $img['alt']   ?? '';
                if (!$src) continue;
                echo '<div class="gallery-item">';
                echo '<img src="' . h($pathPrefix . $src) . '" alt="' . h($alt) . '" loading="lazy">';
                echo '</div>';
            }
            echo '</div></div>';
            break;

        /* ---- PROCESS STEPS ---- */
        case 'steps':
            $heading = $block['steps_heading'] ?? '';
            $items   = $block['steps_items']   ?? [];
            echo '<div class="content-block block-steps"' . $anchorAttr . '>';
            if ($heading) echo '<h2 class="section-heading">' . h($heading) . '</h2>';
            echo '<div class="steps-grid">';
            foreach ($items as $n => $step) {
                $stepImg  = $step['image']   ?? '';
                $stepHead = $step['heading'] ?? '';
                $stepText = $step['text']    ?? '';
                $stepAlt  = $step['alt']     ?? '';
                echo '<div class="step-item">';
                if ($stepImg) {
                    echo '<img class="step-icon" src="' . h($pathPrefix . $stepImg) . '" alt="' . h($stepAlt) . '">';
                } else {
                    echo '<div class="step-number">' . ($n + 1) . '</div>';
                }
                if ($stepHead) echo '<h3 class="step-heading">' . h($stepHead) . '</h3>';
                if ($stepText) echo '<p class="step-text">' . h($stepText) . '</p>';
                echo '</div>';
            }
            echo '</div></div>';
            break;

        /* ---- STATS / COUNTERS ---- */
        case 'stats':
            $heading = $block['stats_heading'] ?? '';
            $items   = $block['stats_items']   ?? [];
            $bgColor = $block['stats_bg_color'] ?? '';
            $textColor = $block['stats_text_color'] ?? '';
            $style = '';
            if ($bgColor)   $style .= 'background-color:' . h($bgColor) . ';';
            if ($textColor) $style .= 'color:' . h($textColor) . ';';
            echo '<div class="content-block block-stats"' . ($style ? ' style="' . $style . '"' : '') . $anchorAttr . '>';
            if ($heading) echo '<h2 class="section-heading">' . h($heading) . '</h2>';
            echo '<div class="stats-grid">';
            foreach ($items as $stat) {
                $number = $stat['number'] ?? '';
                $label  = $stat['label']  ?? '';
                if (!$number && !$label) continue;
                echo '<div class="stat-item">';
                if ($number) echo '<div class="stat-number">' . h($number) . '</div>';
                if ($label)  echo '<div class="stat-label">'  . h($label)  . '</div>';
                echo '</div>';
            }
            echo '</div></div>';
            break;

        /* ---- CARDS GRID (image + heading + text + link) ---- */
        case 'cards':
            $heading  = $block['cards_heading']  ?? '';
            $cols     = max(2, min(4, (int)($block['cards_cols'] ?? 3)));
            $items    = $block['cards_items']    ?? [];
            echo '<div class="content-block block-cards"' . $anchorAttr . '>';
            if ($heading) echo '<h2 class="section-heading">' . h($heading) . '</h2>';
            echo '<div class="cards-grid cards-grid-' . $cols . '">';
            foreach ($items as $card) {
                $cardImg  = $card['image']    ?? '';
                $cardAlt  = $card['alt']      ?? '';
                $cardHead = $card['heading']  ?? '';
                $cardText = $card['text']     ?? '';
                $cardLink = $card['link']     ?? '';
                $cardBtn  = $card['btn_text'] ?? 'Read More';
                echo '<div class="card-item">';
                if ($cardImg) echo '<img class="card-image" src="' . h($pathPrefix . $cardImg) . '" alt="' . h($cardAlt) . '" loading="lazy">';
                echo '<div class="card-body">';
                if ($cardHead) echo '<h3 class="card-heading">' . h($cardHead) . '</h3>';
                if ($cardText) echo '<p class="card-text">' . h($cardText) . '</p>';
                if ($cardLink) echo '<a href="' . h($cardLink) . '" class="card-link">' . h($cardBtn) . '</a>';
                echo '</div></div>';
            }
            echo '</div></div>';
            break;

        default:
            echo '<div class="content-block text-only"><div class="content-text">' . text_to_html($text) . '</div></div>';
            break;
    }
}

/* ============================================================
   ADMIN: photo field helpers
   ============================================================ */
function photo_ratio_options_html($selected = 'landscape') {
    $html = '';
    foreach (photo_ratio_options() as $key => $label) {
        $sel   = ($key === $selected) ? ' selected' : '';
        $html .= '<option value="' . h($key) . '"' . $sel . '>' . h($label) . '</option>';
    }
    return $html;
}

function photo_position_options_html($selected = 'center') {
    $html = '';
    foreach (photo_position_options() as $key => $label) {
        $sel   = ($key === $selected) ? ' selected' : '';
        $html .= '<option value="' . h($key) . '"' . $sel . '>' . h($label) . '</option>';
    }
    return $html;
}

function heading_level_options_html($selected = 'h2') {
    $html = '';
    foreach (heading_level_options() as $key => $label) {
        $sel   = ($key === $selected) ? ' selected' : '';
        $html .= '<option value="' . h($key) . '"' . $sel . '>' . h($label) . '</option>';
    }
    return $html;
}

/* ============================================================
   ADMIN: render the full content blocks editor
   ============================================================ */
function render_content_blocks_editor($blocks) {
    $blockList = $blocks ?: [['type' => 'text', 'heading_level' => 'h2', 'text' => '', 'photo' => '']];
    $blockTypeOptions = '';
    foreach (allowed_block_types() as $key => $label) {
        $blockTypeOptions .= '<option value="' . h($key) . '">' . h($label) . '</option>';
    }
    ?>
    <div class="card">
        <h2>Content Blocks</h2>
        <p class="hint" style="margin-bottom:18px;">
            Build this page from any number of blocks. Choose a block type and fill in the fields.
        </p>
        <div id="content-blocks">
            <?php foreach ($blockList as $i => $block):
                $type = $block['type'] ?? 'text';
                if (!array_key_exists($type, allowed_block_types())) $type = 'text';
            ?>
            <div class="block-card" data-block-type="<?= h($type) ?>">
                <div class="block-card-header">
                    <span class="block-label">Block <?= $i + 1 ?></span>
                    <select name="block_type[]" class="block-type-select" onchange="switchBlockType(this)">
                        <?php foreach (allowed_block_types() as $key => $label): ?>
                            <option value="<?= h($key) ?>" <?= $key === $type ? 'selected' : '' ?>><?= h($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" name="block_anchor[]"
                           value="<?= h($block['anchor'] ?? '') ?>"
                           placeholder="Section ID (e.g. pest_services)"
                           title="Anchor ID — use in menu links as #pest_services"
                           style="flex:1 1 160px;max-width:200px;font-size:0.82rem;padding:6px 10px;">
                    <div class="block-actions">
                        <button type="button" class="icon-btn" onclick="moveBlock(this,-1)" title="Move up">&uarr;</button>
                        <button type="button" class="icon-btn" onclick="moveBlock(this,1)"  title="Move down">&darr;</button>
                        <button type="button" class="icon-btn remove-row" onclick="removeBlock(this)" title="Remove">Remove</button>
                    </div>
                </div>

                <?php /* ---- TEXT ONLY FIELDS ---- */ ?>
                <div class="block-fields block-fields-text <?= $type !== 'text' ? 'is-hidden' : '' ?>">
                    <input type="hidden" name="block_photo_alt[]" value="">
                    <input type="hidden" name="block_existing_photo[]" value="">
                    <input type="hidden" name="block_photo_ratio[]" value="landscape">
                    <input type="hidden" name="block_photo_position[]" value="center">
                    <input type="checkbox" name="block_remove_photo[]" value="1" style="display:none;">
                    <div class="form-group">
                        <label>Heading level</label>
                        <select name="block_heading_level[]">
                            <?= heading_level_options_html($block['heading_level'] ?? 'h2') ?>
                        </select>
                        <span class="hint">The first line of the text below will become this heading.</span>
                    </div>
                    <div class="form-group">
                        <label>Text</label>
                        <textarea name="block_text[]" rows="5" class="rich-editor"><?= h($block['text'] ?? '') ?></textarea>
                        <span class="hint">First line = heading (if heading level chosen). Leave a blank line between paragraphs.</span>
                    </div>
                </div>

                <?php /* ---- IMAGE LEFT / RIGHT FIELDS (legacy) ---- */ ?>
                <div class="block-fields block-fields-image_left block-fields-image_right <?= !in_array($type, ['image_left','image_right']) ? 'is-hidden' : '' ?>">
                    <div class="form-group">
                        <label>Text</label>
                        <textarea name="block_text[]" rows="4"><?= h($block['text'] ?? '') ?></textarea>
                    </div>
                    <?php render_photo_upload_fields('block_photo', $block['photo'] ?? '', $block['photo_ratio'] ?? 'landscape', $block['photo_position'] ?? 'center', $block['photo_alt'] ?? '', $i); ?>
                </div>

                <?php /* ---- HERO FIELDS ---- */ ?>
                <div class="block-fields block-fields-hero <?= $type !== 'hero' ? 'is-hidden' : '' ?>">
                    <div class="form-group">
                        <label>Main headline (H1)</label>
                        <input type="text" name="hero_heading[]" value="<?= h($block['hero_heading'] ?? '') ?>" placeholder="e.g. Trusted Local Pest Control in Katy, TX">
                    </div>
                    <div class="form-group">
                        <label>Subtext</label>
                        <textarea name="hero_subtext[]" rows="2" class="rich-editor"><?= h($block['hero_subtext'] ?? '') ?></textarea>
                    </div>
                    <div style="display:flex;gap:12px;flex-wrap:wrap;">
                        <div class="form-group" style="flex:1 1 180px;">
                            <label>Button text</label>
                            <input type="text" name="hero_btn_text[]" value="<?= h($block['hero_btn_text'] ?? '') ?>" placeholder="e.g. Call Now">
                        </div>
                        <div class="form-group" style="flex:1 1 180px;">
                            <label>Button link</label>
                            <input type="text" name="hero_btn_url[]" value="<?= h($block['hero_btn_url'] ?? '') ?>" placeholder="e.g. tel:+15551234567">
                        </div>
                    </div>
                    <div style="display:flex;gap:12px;flex-wrap:wrap;">
                        <div class="form-group" style="flex:1 1 180px;">
                            <label>Background color</label>
                            <input type="color" name="hero_bg_color[]" value="<?= h($block['hero_bg_color'] ?? '#1e3a5f') ?>">
                        </div>
                        <div class="form-group" style="flex:1 1 180px;">
                            <label>Text color</label>
                            <input type="color" name="hero_text_color[]" value="<?= h($block['hero_text_color'] ?? '#ffffff') ?>">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Background image (optional — overrides background color)</label>
                        <?php if (!empty($block['hero_bg_image'])): ?>
                            <img src="/<?= h($block['hero_bg_image']) ?>" style="max-height:80px;border-radius:4px;margin-bottom:6px;display:block;" onerror="this.style.display='none'">
                        <?php endif; ?>
                        <input type="file" name="hero_bg_image[]" accept="image/png,image/jpeg,image/gif,image/webp">
                        <input type="hidden" name="hero_bg_image_existing[]" value="<?= h($block['hero_bg_image'] ?? '') ?>">
                    </div>
                </div>

                <?php /* ---- HERO SPLIT FIELDS ---- */ ?>
                <div class="block-fields block-fields-hero_split <?= $type !== 'hero_split' ? 'is-hidden' : '' ?>">
                    <div class="form-group">
                        <label>H1 Headline</label>
                        <input type="text" name="hs_heading[]" value="<?= h($block['hs_heading'] ?? '') ?>" placeholder="e.g. Trusted Local Pest Control in Katy, TX">
                    </div>
                    <div class="form-group">
                        <label>Paragraph text</label>
                        <textarea name="hs_subtext[]" rows="3" class="rich-editor"><?= h($block['hs_subtext'] ?? '') ?></textarea>
                    </div>
                    <div style="display:flex;gap:12px;flex-wrap:wrap;">
                        <div class="form-group" style="flex:1 1 180px;">
                            <label>Button text</label>
                            <input type="text" name="hs_btn_text[]" value="<?= h($block['hs_btn_text'] ?? '') ?>" placeholder="e.g. (281) 215-0160">
                        </div>
                        <div class="form-group" style="flex:1 1 180px;">
                            <label>Button link</label>
                            <input type="text" name="hs_btn_url[]" value="<?= h($block['hs_btn_url'] ?? '') ?>" placeholder="e.g. tel:+12812150160">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Background color</label>
                        <input type="color" name="hs_bg_color[]" value="<?= h($block['hs_bg_color'] ?? '#f3f6f7') ?>">
                        <span class="hint">Light gray (#f3f6f7) matches katypestpros.com</span>
                    </div>
                    <div class="form-group">
                        <label>Right-side image</label>
                        <?php if (!empty($block['hs_photo'])): ?>
                            <img src="/<?= h($block['hs_photo']) ?>" style="max-height:80px;border-radius:4px;margin-bottom:6px;display:block;" onerror="this.style.display='none'">
                        <?php endif; ?>
                        <input type="file" name="hs_photo[]" accept="image/png,image/jpeg,image/gif,image/webp">
                        <input type="hidden" name="hs_photo_existing[]" value="<?= h($block['hs_photo'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label>Image alt text (SEO)</label>
                        <input type="text" name="hs_photo_alt[]" value="<?= h($block['hs_photo_alt'] ?? '') ?>" placeholder="e.g. Pest control services in Katy TX">
                    </div>
                    <div style="display:flex;gap:12px;flex-wrap:wrap;">
                        <div class="form-group" style="flex:1 1 180px;">
                            <label>Image caption line 1</label>
                            <input type="text" name="hs_caption1[]" value="<?= h($block['hs_caption1'] ?? '') ?>" placeholder="e.g. Pest Control">
                        </div>
                        <div class="form-group" style="flex:1 1 180px;">
                            <label>Image caption line 2</label>
                            <input type="text" name="hs_caption2[]" value="<?= h($block['hs_caption2'] ?? '') ?>" placeholder="e.g. Katy, TX">
                        </div>
                    </div>
                </div>

                <?php /* ---- FEATURE SPLIT FIELDS ---- */ ?>
                <div class="block-fields block-fields-feature_split <?= $type !== 'feature_split' ? 'is-hidden' : '' ?>">
                    <div class="form-group">
                        <label>Section heading (H2)</label>
                        <input type="text" name="fs_heading[]" value="<?= h($block['fs_heading'] ?? '') ?>" placeholder="e.g. Full-Service Pest Management">
                    </div>
                    <div class="form-group">
                        <label>Intro paragraph</label>
                        <textarea name="fs_subtext[]" rows="2"><?= h($block['fs_subtext'] ?? '') ?></textarea>
                    </div>
                    <div style="display:flex;gap:12px;flex-wrap:wrap;">
                        <div class="form-group" style="flex:1 1 160px;">
                            <label>Background color</label>
                            <input type="color" name="fs_bg_color[]" value="<?= h($block['fs_bg_color'] ?? '#f3f6f7') ?>">
                        </div>
                        <div class="form-group" style="flex:1 1 160px;">
                            <label>Item heading color</label>
                            <input type="color" name="fs_accent[]" value="<?= h($block['fs_accent'] ?? '#fd783b') ?>">
                        </div>
                    </div>

                    <h4 style="margin:16px 0 8px;font-size:0.95rem;">Icon Grid Items (2 columns)</h4>
                    <div class="fs-items-editor" id="fs_items_<?= $i ?>">
                        <?php foreach (($block['fs_items'] ?? []) as $fi => $fitem): ?>
                        <div class="fs-item-row" style="border:1px solid #e5e7eb;border-radius:6px;padding:12px;margin-bottom:8px;background:#f9fafb;">
                            <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-start;">
                                <div style="flex:0 0 90px;">
                                    <div class="form-group">
                                        <label>Icon image</label>
                                        <?php if (!empty($fitem['icon'])): ?>
                                            <img src="<?= str_starts_with($fitem['icon'], 'http') ? h($fitem['icon']) : '/'.h($fitem['icon']) ?>" style="max-height:40px;display:block;margin-bottom:4px;" onerror="this.style.display='none'">
                                        <?php endif; ?>
                                        <input type="file" name="fs_item_icon[<?= $i ?>][]" accept="image/png,image/jpeg,image/gif,image/webp,image/svg+xml" style="font-size:0.75rem;">
                                        <input type="hidden" name="fs_item_icon_existing[<?= $i ?>][]" value="<?= h($fitem['icon'] ?? '') ?>">
                                    </div>
                                    <div class="form-group">
                                        <label>Alt text</label>
                                        <input type="text" name="fs_item_alt[<?= $i ?>][]" value="<?= h($fitem['alt'] ?? '') ?>" placeholder="Icon description" style="font-size:0.8rem;">
                                    </div>
                                </div>
                                <div style="flex:1 1 200px;">
                                    <div class="form-group">
                                        <label>Heading</label>
                                        <input type="text" name="fs_item_heading[<?= $i ?>][]" value="<?= h($fitem['heading'] ?? '') ?>" placeholder="e.g. Ants">
                                    </div>
                                    <div class="form-group">
                                        <label>Description</label>
                                        <textarea name="fs_item_text[<?= $i ?>][]" rows="2"><?= h($fitem['text'] ?? '') ?></textarea>
                                    </div>
                                </div>
                                <button type="button" class="remove-row" onclick="removeFsItem(this)" style="align-self:flex-start;margin-top:22px;">&times;</button>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="btn btn-secondary btn-small" onclick="addFsItem(this, <?= $i ?>)">+ Add item</button>

                    <h4 style="margin:20px 0 8px;font-size:0.95rem;">Right Side Image</h4>
                    <div class="form-group">
                        <label>Image (shown in arched frame on right)</label>
                        <?php if (!empty($block['fs_photo'])): ?>
                            <img src="<?= str_starts_with($block['fs_photo'], 'http') ? h($block['fs_photo']) : '/'.h($block['fs_photo']) ?>" style="max-height:80px;border-radius:6px;margin-bottom:6px;display:block;" onerror="this.style.display='none'">
                        <?php endif; ?>
                        <input type="file" name="fs_photo[]" accept="image/png,image/jpeg,image/gif,image/webp">
                        <input type="hidden" name="fs_photo_existing[]" value="<?= h($block['fs_photo'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label>Image alt text</label>
                        <input type="text" name="fs_photo_alt[]" value="<?= h($block['fs_photo_alt'] ?? '') ?>" placeholder="e.g. Pest control technician in Katy TX">
                    </div>
                    <div class="form-group">
                        <label>Star badge text (shown below image)</label>
                        <input type="text" name="fs_star_text[]" value="<?= h($block['fs_star_text'] ?? '') ?>" placeholder="e.g. 5 Star Services">
                        <span class="hint">★★★★★ stars are shown automatically. Leave blank to hide the badge.</span>
                    </div>
                </div>

                <?php /* ---- FEATURE COLUMNS FIELDS ---- */ ?>
                <div class="block-fields block-fields-feature_columns <?= $type !== 'feature_columns' ? 'is-hidden' : '' ?>">
                    <div class="form-group">
                        <label>Section heading (H2)</label>
                        <input type="text" name="fc_heading[]" value="<?= h($block['fc_heading'] ?? '') ?>" placeholder="e.g. Our Pest Control Services">
                    </div>
                    <div class="form-group">
                        <label>Number of columns</label>
                        <select name="fc_num_cols[]">
                            <?php foreach ([2,3,4] as $n): ?>
                                <option value="<?= $n ?>" <?= ($block['fc_num_cols'] ?? 3) == $n ? 'selected' : '' ?>><?= $n ?> columns</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="fc-columns-editor" id="fc_cols_<?= $i ?>">
                        <?php $cols = $block['columns'] ?? [['image'=>'','heading'=>'','text'=>'','alt'=>'']]; ?>
                        <?php foreach ($cols as $ci => $col): ?>
                        <div class="fc-col-row">
                            <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-start;">
                                <div class="form-group" style="flex:0 0 80px;">
                                    <label>Icon/image</label>
                                    <?php if (!empty($col['image'])): ?>
                                        <img src="../<?= h($col['image']) ?>" style="max-height:48px;display:block;margin-bottom:4px;">
                                    <?php endif; ?>
                                    <input type="file" name="fc_col_image[<?= $i ?>][]" accept="image/png,image/jpeg,image/gif,image/webp" style="font-size:0.78rem;">
                                    <input type="hidden" name="fc_col_image_existing[<?= $i ?>][]" value="<?= h($col['image'] ?? '') ?>">
                                </div>
                                <div style="flex:1 1 160px;">
                                    <div class="form-group">
                                        <label>Heading (H3)</label>
                                        <input type="text" name="fc_col_heading[<?= $i ?>][]" value="<?= h($col['heading'] ?? '') ?>" placeholder="e.g. Ants">
                                    </div>
                                    <div class="form-group">
                                        <label>Alt text</label>
                                        <input type="text" name="fc_col_alt[<?= $i ?>][]" value="<?= h($col['alt'] ?? '') ?>" placeholder="Image description for SEO">
                                    </div>
                                </div>
                                <div style="flex:2 1 200px;">
                                    <div class="form-group">
                                        <label>Description</label>
                                        <textarea name="fc_col_text[<?= $i ?>][]" rows="2"><?= h($col['text'] ?? '') ?></textarea>
                                    </div>
                                </div>
                                <button type="button" class="remove-row" onclick="removeFcCol(this)" style="align-self:flex-start;margin-top:24px;">&times;</button>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="btn btn-secondary btn-small" onclick="addFcCol(this, <?= $i ?>)">+ Add column item</button>
                </div>

                <?php /* ---- SPLIT CTA FIELDS ---- */ ?>
                <div class="block-fields block-fields-split_cta <?= $type !== 'split_cta' ? 'is-hidden' : '' ?>">
                    <p class="hint" style="margin-bottom:14px;">Two equal panels side by side — left is content, right is phone CTA. Colors pull from your global theme by default.</p>

                    <h4 style="margin:0 0 10px;font-size:0.95rem;border-bottom:1px solid #e5e7eb;padding-bottom:6px;">Left Panel</h4>
                    <div class="form-group">
                        <label>Heading</label>
                        <input type="text" name="sc_left_heading[]" value="<?= h($block['sc_left_heading'] ?? '') ?>" placeholder="e.g. Serving the Greater Katy, TX Area">
                    </div>
                    <div class="form-group">
                        <label>Paragraph text</label>
                        <textarea name="sc_left_text[]" rows="3"><?= h($block['sc_left_text'] ?? '') ?></textarea>
                    </div>
                    <div class="form-group">
                        <label>Left panel background</label>
                        <select name="sc_left_bg[]">
                            <option value="accent" <?= ($block['sc_left_bg'] ?? 'accent') === 'accent' ? 'selected' : '' ?>>Accent color (global theme)</option>
                            <option value="header" <?= ($block['sc_left_bg'] ?? '') === 'header' ? 'selected' : '' ?>>Header/nav color (global theme)</option>
                            <option value="custom" <?= ($block['sc_left_bg'] ?? '') === 'custom' ? 'selected' : '' ?>>Custom color</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Custom left color (only if Custom selected above)</label>
                        <input type="color" name="sc_left_bg_custom[]" value="<?= h($block['sc_left_bg_custom'] ?? '#fd783b') ?>">
                    </div>

                    <h4 style="margin:16px 0 10px;font-size:0.95rem;border-bottom:1px solid #e5e7eb;padding-bottom:6px;">Right Panel</h4>
                    <div class="form-group">
                        <label>Label text (above phone)</label>
                        <input type="text" name="sc_right_label[]" value="<?= h($block['sc_right_label'] ?? '') ?>" placeholder="e.g. Call The Katy Pest Pros Team">
                    </div>
                    <div class="form-group">
                        <label>Phone number (displayed)</label>
                        <input type="text" name="sc_right_phone[]" value="<?= h($block['sc_right_phone'] ?? '') ?>" placeholder="e.g. (281) 215-0160">
                    </div>
                    <div class="form-group">
                        <label>Phone link (optional — auto-generated if blank)</label>
                        <input type="text" name="sc_right_phone_url[]" value="<?= h($block['sc_right_phone_url'] ?? '') ?>" placeholder="e.g. tel:+12812150160">
                    </div>
                    <div class="form-group">
                        <label>Right panel background</label>
                        <select name="sc_right_bg[]">
                            <option value="header" <?= ($block['sc_right_bg'] ?? 'header') === 'header' ? 'selected' : '' ?>>Header/nav color (global theme)</option>
                            <option value="accent" <?= ($block['sc_right_bg'] ?? '') === 'accent' ? 'selected' : '' ?>>Accent color (global theme)</option>
                            <option value="custom" <?= ($block['sc_right_bg'] ?? '') === 'custom' ? 'selected' : '' ?>>Custom color</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Custom right color (only if Custom selected above)</label>
                        <input type="color" name="sc_right_bg_custom[]" value="<?= h($block['sc_right_bg_custom'] ?? '#120575') ?>">
                    </div>
                </div>

                <?php /* ---- CTA BUTTON FIELDS ---- */ ?>
                <div class="block-fields block-fields-cta_button <?= $type !== 'cta_button' ? 'is-hidden' : '' ?>">
                    <div style="display:flex;gap:12px;flex-wrap:wrap;">
                        <div class="form-group" style="flex:1 1 180px;">
                            <label>Button text</label>
                            <input type="text" name="cta_text[]" value="<?= h($block['cta_text'] ?? 'Contact Us') ?>" placeholder="e.g. Call Now">
                        </div>
                        <div class="form-group" style="flex:1 1 180px;">
                            <label>Button link</label>
                            <input type="text" name="cta_url[]" value="<?= h($block['cta_url'] ?? '#') ?>" placeholder="e.g. tel:+15551234567 or /contact">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Optional text above button</label>
                        <input type="text" name="cta_subtext[]" value="<?= h($block['cta_subtext'] ?? '') ?>" placeholder="e.g. Ready to get started?">
                    </div>
                    <div class="form-group">
                        <label>Alignment</label>
                        <select name="cta_align[]">
                            <?php foreach (['left'=>'Left','center'=>'Center','right'=>'Right'] as $v => $l): ?>
                                <option value="<?= $v ?>" <?= ($block['cta_align'] ?? 'center') === $v ? 'selected' : '' ?>><?= $l ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <?php /* ---- IMAGE & TEXT SIDE BY SIDE FIELDS ---- */ ?>
                <div class="block-fields block-fields-image_text <?= $type !== 'image_text' ? 'is-hidden' : '' ?>">
                    <div class="form-group">
                        <label>Image side</label>
                        <select name="it_image_side[]">
                            <option value="left"  <?= ($block['it_image_side'] ?? 'left') === 'left'  ? 'selected' : '' ?>>Image on left, text on right</option>
                            <option value="right" <?= ($block['it_image_side'] ?? 'left') === 'right' ? 'selected' : '' ?>>Text on left, image on right</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Heading level</label>
                        <select name="it_heading_level[]"><?= heading_level_options_html($block['it_heading_level'] ?? 'h2') ?></select>
                    </div>
                    <div class="form-group">
                        <label>Heading</label>
                        <input type="text" name="it_heading[]" value="<?= h($block['it_heading'] ?? '') ?>" placeholder="Section heading">
                    </div>
                    <div class="form-group">
                        <label>Text</label>
                        <textarea name="it_text[]" rows="4" class="rich-editor"><?= h($block['it_text'] ?? '') ?></textarea>
                    </div>
                    <div style="display:flex;gap:12px;flex-wrap:wrap;">
                        <div class="form-group" style="flex:1 1 160px;">
                            <label>Button text (optional)</label>
                            <input type="text" name="it_btn_text[]" value="<?= h($block['it_btn_text'] ?? '') ?>" placeholder="e.g. Call Now">
                        </div>
                        <div class="form-group" style="flex:1 1 160px;">
                            <label>Button link</label>
                            <input type="text" name="it_btn_url[]" value="<?= h($block['it_btn_url'] ?? '') ?>" placeholder="e.g. tel:+15551234567">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Image</label>
                        <?php if (!empty($block['it_photo'])): ?>
                            <img src="../<?= h($block['it_photo']) ?>" style="max-height:80px;border-radius:4px;margin-bottom:6px;display:block;">
                        <?php endif; ?>
                        <input type="file" name="it_photo[]" accept="image/png,image/jpeg,image/gif,image/webp">
                        <input type="hidden" name="it_photo_existing[]" value="<?= h($block['it_photo'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label>Image alt text (SEO)</label>
                        <input type="text" name="it_alt[]" value="<?= h($block['it_alt'] ?? '') ?>" placeholder="Describe the image for search engines">
                    </div>
                    <div style="display:flex;gap:12px;flex-wrap:wrap;">
                        <div style="flex:1 1 160px;">
                            <label>Picture shape</label>
                            <select name="it_ratio[]"><?= photo_ratio_options_html($block['it_ratio'] ?? 'landscape') ?></select>
                        </div>
                        <div style="flex:1 1 160px;">
                            <label>Crop focus</label>
                            <select name="it_position[]"><?= photo_position_options_html($block['it_position'] ?? 'center') ?></select>
                        </div>
                    </div>
                </div>

                <?php /* ---- FAQ FIELDS ---- */ ?>
                <div class="block-fields block-fields-faq <?= $type !== 'faq' ? 'is-hidden' : '' ?>">
                    <div class="form-group">
                        <label>Section heading (H2)</label>
                        <input type="text" name="faq_heading[]" value="<?= h($block['faq_heading'] ?? '') ?>" placeholder="e.g. Frequently Asked Questions">
                    </div>
                    <div class="faq-items-editor" id="faq_items_<?= $i ?>">
                        <?php $faqItems = $block['faq_items'] ?? [['question'=>'','answer'=>'']]; ?>
                        <?php foreach ($faqItems as $fi => $fitem): ?>
                        <div class="faq-item-row">
                            <div class="form-group">
                                <label>Question</label>
                                <input type="text" name="faq_question[<?= $i ?>][]" value="<?= h($fitem['question'] ?? '') ?>" placeholder="e.g. How much does pest control cost?">
                            </div>
                            <div class="form-group">
                                <label>Answer</label>
                                <textarea name="faq_answer[<?= $i ?>][]" rows="2" class="rich-editor"><?= h($fitem['answer'] ?? '') ?></textarea>
                            </div>
                            <button type="button" class="remove-row btn-secondary btn-small" onclick="removeFaqItem(this)" style="margin-bottom:12px;">Remove Q&amp;A</button>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="btn btn-secondary btn-small" onclick="addFaqItem(this, <?= $i ?>)">+ Add Q&amp;A</button>
                </div>

                <?php /* ---- CUSTOM HTML FIELDS ---- */ ?>
                <div class="block-fields block-fields-custom_html <?= $type !== 'custom_html' ? 'is-hidden' : '' ?>">
                    <div class="form-group">
                        <label>Custom HTML / Embed code</label>
                        <textarea name="custom_html[]" rows="6" style="font-family:monospace;font-size:0.83rem;"><?= h($block['html'] ?? '') ?></textarea>
                        <span class="hint">Paste any raw HTML here — Google Maps embeds, review widgets, booking scripts, etc. Output is not escaped.</span>
                    </div>
                </div>

                <?php /* ---- CTA CARD FIELDS ---- */ ?>
                <div class="block-fields block-fields-cta_card <?= $type !== 'cta_card' ? 'is-hidden' : '' ?>">
                    <div class="form-group">
                        <label>Heading</label>
                        <input type="text" name="cc_heading[]" value="<?= h($block['cc_heading'] ?? '') ?>" placeholder="e.g. Contact Katy's Top Pest Control Company Today">
                    </div>
                    <div class="form-group">
                        <label>Paragraph text</label>
                        <textarea name="cc_text[]" rows="3"><?= h($block['cc_text'] ?? '') ?></textarea>
                    </div>
                    <div style="display:flex;gap:12px;flex-wrap:wrap;">
                        <div class="form-group" style="flex:1 1 160px;">
                            <label>Button text</label>
                            <input type="text" name="cc_btn_text[]" value="<?= h($block['cc_btn_text'] ?? '') ?>" placeholder="e.g. (281) 215-0160">
                        </div>
                        <div class="form-group" style="flex:1 1 160px;">
                            <label>Button link</label>
                            <input type="text" name="cc_btn_url[]" value="<?= h($block['cc_btn_url'] ?? '') ?>" placeholder="tel:+12812150160">
                        </div>
                        <div class="form-group" style="flex:1 1 120px;">
                            <label>Button style</label>
                            <select name="cc_btn_style[]">
                                <option value="outline" <?= ($block['cc_btn_style'] ?? 'outline') === 'outline' ? 'selected' : '' ?>>Outline (white border)</option>
                                <option value="filled"  <?= ($block['cc_btn_style'] ?? '') === 'filled' ? 'selected' : '' ?>>Filled (white bg)</option>
                            </select>
                        </div>
                    </div>
                    <div style="display:flex;gap:12px;flex-wrap:wrap;">
                        <div class="form-group" style="flex:1 1 160px;">
                            <label>Card background</label>
                            <select name="cc_bg[]">
                                <option value="accent" <?= ($block['cc_bg'] ?? 'accent') === 'accent' ? 'selected' : '' ?>>Accent (global)</option>
                                <option value="header" <?= ($block['cc_bg'] ?? '') === 'header' ? 'selected' : '' ?>>Header (global)</option>
                                <option value="custom" <?= ($block['cc_bg'] ?? '') === 'custom' ? 'selected' : '' ?>>Custom</option>
                            </select>
                            <input type="color" name="cc_bg_custom[]" value="<?= h($block['cc_bg_custom'] ?? '#fd783b') ?>" style="margin-top:4px;">
                        </div>
                        <div class="form-group" style="flex:1 1 120px;">
                            <label>Card border radius (px)</label>
                            <input type="number" name="cc_radius[]" value="<?= h($block['cc_radius'] ?? '12') ?>" min="0" max="40" placeholder="12">
                        </div>
                        <div class="form-group" style="flex:1 1 120px;">
                            <label>Layout</label>
                            <select name="cc_align[]">
                                <option value="split"  <?= ($block['cc_align'] ?? 'split') === 'split'  ? 'selected' : '' ?>>Split (text left, button right)</option>
                                <option value="center" <?= ($block['cc_align'] ?? 'split') === 'center' ? 'selected' : '' ?>>Centered (full width text)</option>
                            </select>
                        </div>
                    </div>
                </div>

                <?php /* ---- MAP + INFO FIELDS ---- */ ?>
                <div class="block-fields block-fields-map_info <?= $type !== 'map_info' ? 'is-hidden' : '' ?>">
                    <div class="form-group">
                        <label>Heading color</label>
                        <select name="mi_head_color[]">
                            <option value="header" <?= ($block['mi_head_color'] ?? 'header') === 'header' ? 'selected' : '' ?>>Header (global)</option>
                            <option value="accent" <?= ($block['mi_head_color'] ?? '') === 'accent' ? 'selected' : '' ?>>Accent (global)</option>
                            <option value="custom" <?= ($block['mi_head_color'] ?? '') === 'custom' ? 'selected' : '' ?>>Custom</option>
                        </select>
                        <input type="color" name="mi_head_color_custom[]" value="<?= h($block['mi_head_color_custom'] ?? '#120575') ?>" style="margin-top:4px;">
                    </div>

                    <h4 style="margin:14px 0 8px;font-size:0.95rem;border-bottom:1px solid #e5e7eb;padding-bottom:6px;">Left Panel — Map</h4>
                    <div class="form-group">
                        <label>Map panel heading</label>
                        <input type="text" name="mi_map_heading[]" value="<?= h($block['mi_map_heading'] ?? '') ?>" placeholder="e.g. Katy, Texas Map">
                    </div>
                    <div class="form-group">
                        <label>Google Maps embed code</label>
                        <textarea name="mi_map_embed[]" rows="4" placeholder='Paste your Google Maps <iframe ...> embed code here'><?= h($block['mi_map_embed'] ?? '') ?></textarea>
                        <span class="hint">Go to Google Maps → Share → Embed a map → copy the &lt;iframe&gt; code.</span>
                    </div>

                    <h4 style="margin:14px 0 8px;font-size:0.95rem;border-bottom:1px solid #e5e7eb;padding-bottom:6px;">Right Panel — Info</h4>
                    <div class="form-group">
                        <label>Info panel heading</label>
                        <input type="text" name="mi_info_heading[]" value="<?= h($block['mi_info_heading'] ?? '') ?>" placeholder="e.g. Katy, TX Information">
                    </div>
                    <div class="form-group">
                        <label>Info text</label>
                        <textarea name="mi_info_text[]" rows="4" class="rich-editor"><?= h($block['mi_info_text'] ?? '') ?></textarea>
                    </div>
                    <div class="form-group">
                        <label>Info photo (optional)</label>
                        <?php if (!empty($block['mi_info_photo'])): ?>
                            <img src="<?= str_starts_with($block['mi_info_photo'],'http') ? h($block['mi_info_photo']) : '/'.h($block['mi_info_photo']) ?>" style="max-height:80px;border-radius:4px;margin-bottom:6px;display:block;" onerror="this.style.display='none'">
                        <?php endif; ?>
                        <input type="file" name="mi_info_photo[]" accept="image/png,image/jpeg,image/gif,image/webp">
                        <input type="hidden" name="mi_info_photo_existing[]" value="<?= h($block['mi_info_photo'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label>Photo alt text</label>
                        <input type="text" name="mi_info_alt[]" value="<?= h($block['mi_info_alt'] ?? '') ?>" placeholder="e.g. Katy TX shopping center">
                    </div>
                </div>

                <?php /* ---- LINKS GRID FIELDS ---- */ ?>
                <div class="block-fields block-fields-links_grid <?= $type !== 'links_grid' ? 'is-hidden' : '' ?>">
                    <div class="form-group">
                        <label>Style</label>
                        <select name="lg_style[]" onchange="this.closest('.block-fields').querySelectorAll('.lg-dark-only,.lg-light-only').forEach(el=>el.style.display=this.value==='dark'?'':'none');this.closest('.block-fields').querySelectorAll('.lg-light-only').forEach(el=>el.style.display=this.value==='light'?'':'none')">
                            <option value="dark"  <?= ($block['lg_style'] ?? 'dark') === 'dark'  ? 'selected' : '' ?>>Dark (background image + overlay)</option>
                            <option value="light" <?= ($block['lg_style'] ?? 'dark') === 'light' ? 'selected' : '' ?>>Light (white/colored bg, gray bordered boxes)</option>
                        </select>
                    </div>

                    <!-- Light style fields -->
                    <div class="lg-light-only" <?= ($block['lg_style'] ?? 'dark') !== 'light' ? 'style="display:none;"' : '' ?>>
                        <div class="form-group">
                            <label>Small label text above heading (accent color)</label>
                            <input type="text" name="lg_sublabel[]" value="<?= h($block['lg_sublabel'] ?? '') ?>" placeholder="e.g. Top Rated Katy, TX Pest Experts">
                        </div>
                        <div class="form-group">
                            <label>Background color</label>
                            <input type="color" name="lg_bg_color[]" value="<?= h($block['lg_bg_color'] ?? '#ffffff') ?>">
                        </div>
                        <div class="form-group">
                            <label>Label / accent color</label>
                            <select name="lg_accent[]">
                                <option value="accent" <?= ($block['lg_accent'] ?? 'accent') === 'accent' ? 'selected' : '' ?>>Accent (global)</option>
                                <option value="header" <?= ($block['lg_accent'] ?? '') === 'header' ? 'selected' : '' ?>>Header (global)</option>
                                <option value="custom" <?= ($block['lg_accent'] ?? '') === 'custom' ? 'selected' : '' ?>>Custom</option>
                            </select>
                            <input type="color" name="lg_accent_custom[]" value="<?= h($block['lg_accent_custom'] ?? '#fd783b') ?>" style="margin-top:4px;">
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Section heading</label>
                        <input type="text" name="lg_heading[]" value="<?= h($block['lg_heading'] ?? '') ?>" placeholder="e.g. Our Pest Control Services in Katy, TX">
                    </div>

                    <!-- Dark style only fields -->
                    <div class="lg-dark-only" <?= ($block['lg_style'] ?? 'dark') !== 'dark' ? 'style="display:none;"' : '' ?>>
                        <div class="form-group">
                            <label>Subtext paragraph</label>
                            <textarea name="lg_subtext[]" rows="2"><?= h($block['lg_subtext'] ?? '') ?></textarea>
                        </div>
                    </div>

                    <div style="display:flex;gap:12px;flex-wrap:wrap;">
                        <div class="form-group" style="flex:1 1 180px;">
                            <label>Number of columns</label>
                            <select name="lg_cols[]">
                                <?php foreach ([2,3,4,5,6] as $n): ?>
                                    <option value="<?= $n ?>" <?= ($block['lg_cols'] ?? 5) == $n ? 'selected' : '' ?>><?= $n ?> columns</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group" style="flex:1 1 180px;">
                            <label>Dark overlay opacity: <strong id="lg_ov_val_<?= $i ?>"><?= h($block['lg_overlay'] ?? '0.6') ?></strong></label>
                            <input type="range" name="lg_overlay[]" min="0" max="0.9" step="0.05"
                                   value="<?= h($block['lg_overlay'] ?? '0.6') ?>"
                                   oninput="document.getElementById('lg_ov_val_<?= $i ?>').textContent=this.value"
                                   style="width:100%;accent-color:var(--color-accent,#2563eb);">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Background image</label>
                        <?php if (!empty($block['lg_photo'])): ?>
                            <img src="<?= str_starts_with($block['lg_photo'],'http') ? h($block['lg_photo']) : '/'.h($block['lg_photo']) ?>" style="max-height:60px;border-radius:4px;margin-bottom:6px;display:block;" onerror="this.style.display='none'">
                        <?php endif; ?>
                        <input type="file" name="lg_photo[]" accept="image/png,image/jpeg,image/gif,image/webp">
                        <input type="hidden" name="lg_photo_existing[]" value="<?= h($block['lg_photo'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label>Image alt text</label>
                        <input type="text" name="lg_photo_alt[]" value="<?= h($block['lg_photo_alt'] ?? '') ?>" placeholder="e.g. Pest control services Katy TX">
                    </div>

                    <h4 style="margin:14px 0 8px;font-size:0.95rem;">Links (label + URL)</h4>
                    <p class="hint" style="margin-bottom:10px;">Each link becomes a bordered button. Typically used for internal SEO links to service pages.</p>
                    <div class="lg-links-editor" id="lg_links_<?= $i ?>">
                        <?php foreach (($block['lg_links'] ?? []) as $li => $lnk): ?>
                        <div class="lg-link-row" style="display:flex;gap:8px;margin-bottom:6px;align-items:center;">
                            <input type="text" name="lg_link_label[<?= $i ?>][]" value="<?= h($lnk['label'] ?? '') ?>" placeholder="Link text" style="flex:1;">
                            <input type="text" name="lg_link_url[<?= $i ?>][]"   value="<?= h($lnk['url']   ?? '') ?>" placeholder="URL e.g. /cockroach-exterminator" style="flex:1;">
                            <button type="button" class="remove-row" onclick="removeLgLink(this)">&times;</button>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="btn btn-secondary btn-small" onclick="addLgLink(this, <?= $i ?>)">+ Add link</button>
                    <p class="hint" style="margin-top:8px;">Tip: Add all links first, then use "Bulk add" to paste a list.</p>
                    <div class="form-group" style="margin-top:10px;">
                        <label>Bulk add (one link label per line — no URLs, all link to #)</label>
                        <textarea id="lg_bulk_<?= $i ?>" rows="4" placeholder="Cockroach Exterminator&#10;Termite Treatment&#10;Mosquito Control"></textarea>
                        <button type="button" class="btn btn-secondary btn-small" style="margin-top:6px;" onclick="bulkAddLgLinks(<?= $i ?>)">Add all as links</button>
                    </div>
                </div>

                <?php /* ---- CTA BANNER FIELDS ---- */ ?>
                <div class="block-fields block-fields-cta_banner <?= $type !== 'cta_banner' ? 'is-hidden' : '' ?>">
                    <div class="form-group">
                        <label>Banner text (centered, bold)</label>
                        <input type="text" name="cb_text[]" value="<?= h($block['cb_text'] ?? '') ?>"
                               placeholder="e.g. 24/7 Pest Control Services in Katy, TX">
                    </div>
                    <div class="form-group">
                        <label>Subtext (optional, smaller below main text)</label>
                        <input type="text" name="cb_subtext[]" value="<?= h($block['cb_subtext'] ?? '') ?>"
                               placeholder="Optional tagline or second line">
                    </div>
                    <div style="display:flex;gap:12px;flex-wrap:wrap;">
                        <div class="form-group" style="flex:1 1 160px;">
                            <label>Button text (optional)</label>
                            <input type="text" name="cb_btn_text[]" value="<?= h($block['cb_btn_text'] ?? '') ?>" placeholder="e.g. Call Now">
                        </div>
                        <div class="form-group" style="flex:1 1 160px;">
                            <label>Button link</label>
                            <input type="text" name="cb_btn_url[]" value="<?= h($block['cb_btn_url'] ?? '') ?>" placeholder="tel:+12812150160">
                        </div>
                    </div>
                    <div style="display:flex;gap:12px;flex-wrap:wrap;">
                        <div class="form-group" style="flex:1 1 160px;">
                            <label>Background color</label>
                            <select name="cb_bg[]">
                                <option value="accent" <?= ($block['cb_bg'] ?? 'accent') === 'accent' ? 'selected' : '' ?>>Accent (global)</option>
                                <option value="header" <?= ($block['cb_bg'] ?? '') === 'header' ? 'selected' : '' ?>>Header (global)</option>
                                <option value="custom" <?= ($block['cb_bg'] ?? '') === 'custom' ? 'selected' : '' ?>>Custom</option>
                            </select>
                            <input type="color" name="cb_bg_custom[]" value="<?= h($block['cb_bg_custom'] ?? '#fd783b') ?>" style="margin-top:4px;">
                        </div>
                        <div class="form-group" style="flex:1 1 120px;">
                            <label>Text color</label>
                            <input type="color" name="cb_text_color[]" value="<?= h($block['cb_text_color'] ?? '#ffffff') ?>">
                        </div>
                        <div class="form-group" style="flex:1 1 120px;">
                            <label>Padding / height</label>
                            <select name="cb_padding[]">
                                <option value="compact" <?= ($block['cb_padding'] ?? 'normal') === 'compact' ? 'selected' : '' ?>>Compact (thin strip)</option>
                                <option value="normal"  <?= ($block['cb_padding'] ?? 'normal') === 'normal'  ? 'selected' : '' ?>>Normal</option>
                                <option value="large"   <?= ($block['cb_padding'] ?? 'normal') === 'large'   ? 'selected' : '' ?>>Large</option>
                            </select>
                        </div>
                    </div>
                </div>

                <?php /* ---- FAQ TWO COLUMN FIELDS ---- */ ?>
                <div class="block-fields block-fields-faq_two_col <?= $type !== 'faq_two_col' ? 'is-hidden' : '' ?>">
                    <div class="form-group">
                        <label>Section heading</label>
                        <input type="text" name="fq_heading[]" value="<?= h($block['fq_heading'] ?? '') ?>" placeholder="e.g. FAQs – Pest Control in Katy">
                    </div>
                    <div style="display:flex;gap:12px;flex-wrap:wrap;">
                        <div class="form-group" style="flex:1 1 140px;">
                            <label>Background color</label>
                            <input type="color" name="fq_bg_color[]" value="<?= h($block['fq_bg_color'] ?? '#ffffff') ?>">
                        </div>
                        <div class="form-group" style="flex:1 1 140px;">
                            <label>Item box color</label>
                            <input type="color" name="fq_item_bg[]" value="<?= h($block['fq_item_bg'] ?? '#f0f2f8') ?>">
                            <span class="hint">Light blue-gray (#f0f2f8)</span>
                        </div>
                    </div>
                    <div style="display:flex;gap:12px;flex-wrap:wrap;">
                        <div class="form-group" style="flex:1 1 160px;">
                            <label>Heading color</label>
                            <select name="fq_head_color[]">
                                <option value="header" <?= ($block['fq_head_color'] ?? 'header') === 'header' ? 'selected' : '' ?>>Header (global)</option>
                                <option value="accent" <?= ($block['fq_head_color'] ?? '') === 'accent' ? 'selected' : '' ?>>Accent (global)</option>
                                <option value="custom" <?= ($block['fq_head_color'] ?? '') === 'custom' ? 'selected' : '' ?>>Custom</option>
                            </select>
                            <input type="color" name="fq_head_color_custom[]" value="<?= h($block['fq_head_color_custom'] ?? '#120575') ?>" style="margin-top:4px;">
                        </div>
                        <div class="form-group" style="flex:1 1 160px;">
                            <label>+ Icon background</label>
                            <select name="fq_icon_bg[]">
                                <option value="accent" <?= ($block['fq_icon_bg'] ?? 'accent') === 'accent' ? 'selected' : '' ?>>Accent (global)</option>
                                <option value="header" <?= ($block['fq_icon_bg'] ?? '') === 'header' ? 'selected' : '' ?>>Header (global)</option>
                                <option value="custom" <?= ($block['fq_icon_bg'] ?? '') === 'custom' ? 'selected' : '' ?>>Custom</option>
                            </select>
                            <input type="color" name="fq_icon_bg_custom[]" value="<?= h($block['fq_icon_bg_custom'] ?? '#fd783b') ?>" style="margin-top:4px;">
                        </div>
                    </div>

                    <h4 style="margin:14px 0 8px;font-size:0.95rem;">Q&amp;A Items</h4>
                    <div class="fq-items-editor" id="fq_items_<?= $i ?>">
                        <?php foreach (($block['fq_items'] ?? []) as $fi => $fitem): ?>
                        <div class="fq-item-row" style="border:1px solid #e5e7eb;border-radius:6px;padding:12px;margin-bottom:8px;background:#f9fafb;">
                            <div class="form-group">
                                <label>Question</label>
                                <input type="text" name="fq_question[<?= $i ?>][]" value="<?= h($fitem['question'] ?? '') ?>" placeholder="e.g. What types of pests do you treat?">
                            </div>
                            <div class="form-group">
                                <label>Answer</label>
                                <textarea name="fq_answer[<?= $i ?>][]" rows="2" class="rich-editor"><?= h($fitem['answer'] ?? '') ?></textarea>
                            </div>
                            <button type="button" class="remove-row btn-secondary btn-small" onclick="removeFqItem(this)" style="margin-bottom:4px;">Remove Q&amp;A</button>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="btn btn-secondary btn-small" onclick="addFqItem(this, <?= $i ?>)">+ Add Q&amp;A</button>
                </div>

                <?php /* ---- IMAGE FEATURES FIELDS ---- */ ?>
                <div class="block-fields block-fields-image_features <?= $type !== 'image_features' ? 'is-hidden' : '' ?>">
                    <div class="form-group">
                        <label>Background color</label>
                        <input type="color" name="if_bg_color[]" value="<?= h($block['if_bg_color'] ?? '#f3f6f7') ?>">
                    </div>
                    <div style="display:flex;gap:12px;flex-wrap:wrap;">
                        <div class="form-group" style="flex:1 1 160px;">
                            <label>Checkmark / accent color</label>
                            <select name="if_check_color[]">
                                <option value="accent" <?= ($block['if_check_color'] ?? 'accent') === 'accent' ? 'selected' : '' ?>>Accent (global)</option>
                                <option value="header" <?= ($block['if_check_color'] ?? '') === 'header' ? 'selected' : '' ?>>Header (global)</option>
                                <option value="custom" <?= ($block['if_check_color'] ?? '') === 'custom' ? 'selected' : '' ?>>Custom</option>
                            </select>
                            <input type="color" name="if_check_color_custom[]" value="<?= h($block['if_check_color_custom'] ?? '#fd783b') ?>" style="margin-top:4px;">
                        </div>
                        <div class="form-group" style="flex:1 1 160px;">
                            <label>Heading / text color</label>
                            <select name="if_head_color[]">
                                <option value="header" <?= ($block['if_head_color'] ?? 'header') === 'header' ? 'selected' : '' ?>>Header (global)</option>
                                <option value="accent" <?= ($block['if_head_color'] ?? '') === 'accent' ? 'selected' : '' ?>>Accent (global)</option>
                                <option value="custom" <?= ($block['if_head_color'] ?? '') === 'custom' ? 'selected' : '' ?>>Custom</option>
                            </select>
                            <input type="color" name="if_head_color_custom[]" value="<?= h($block['if_head_color_custom'] ?? '#120575') ?>" style="margin-top:4px;">
                        </div>
                    </div>

                    <h4 style="margin:12px 0 8px;font-size:0.95rem;border-bottom:1px solid #e5e7eb;padding-bottom:6px;">Left Photo</h4>
                    <div class="form-group">
                        <label>Photo</label>
                        <?php if (!empty($block['if_photo'])): ?>
                            <img src="<?= str_starts_with($block['if_photo'],'http') ? h($block['if_photo']) : '/'.h($block['if_photo']) ?>" style="max-height:80px;border-radius:4px;margin-bottom:6px;display:block;" onerror="this.style.display='none'">
                        <?php endif; ?>
                        <input type="file" name="if_photo[]" accept="image/png,image/jpeg,image/gif,image/webp">
                        <input type="hidden" name="if_photo_existing[]" value="<?= h($block['if_photo'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label>Photo alt text</label>
                        <input type="text" name="if_photo_alt[]" value="<?= h($block['if_photo_alt'] ?? '') ?>" placeholder="e.g. Pest control technician Katy TX">
                    </div>

                    <h4 style="margin:12px 0 8px;font-size:0.95rem;border-bottom:1px solid #e5e7eb;padding-bottom:6px;">Right Content</h4>
                    <div class="form-group">
                        <label>Heading</label>
                        <input type="text" name="if_heading[]" value="<?= h($block['if_heading'] ?? '') ?>" placeholder="e.g. Quality Pest Prevention">
                    </div>
                    <div class="form-group">
                        <label>Intro paragraph</label>
                        <textarea name="if_intro[]" rows="3" class="rich-editor"><?= h($block['if_intro'] ?? '') ?></textarea>
                    </div>

                    <h4 style="margin:12px 0 8px;font-size:0.95rem;">Feature checkboxes (2 per row)</h4>
                    <div class="if-feats-editor" id="if_feats_<?= $i ?>">
                        <?php foreach (($block['if_features'] ?? []) as $fi => $feat): ?>
                        <div class="if-feat-row" style="display:flex;gap:8px;margin-bottom:6px;">
                            <input type="text" name="if_features[<?= $i ?>][]" value="<?= h($feat) ?>" placeholder="e.g. Exterior treatments" style="flex:1;">
                            <button type="button" class="remove-row" onclick="removeIfFeat(this)">&times;</button>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="btn btn-secondary btn-small" onclick="addIfFeat(this, <?= $i ?>)">+ Add feature</button>

                    <div class="form-group" style="margin-top:14px;">
                        <label>Closing paragraph (below features)</label>
                        <textarea name="if_closing[]" rows="2"><?= h($block['if_closing'] ?? '') ?></textarea>
                    </div>

                    <h4 style="margin:12px 0 8px;font-size:0.95rem;border-bottom:1px solid #e5e7eb;padding-bottom:6px;">Phone CTA Row</h4>
                    <div style="display:flex;gap:12px;flex-wrap:wrap;">
                        <div class="form-group" style="flex:1 1 160px;">
                            <label>Label (above phone)</label>
                            <input type="text" name="if_phone_label[]" value="<?= h($block['if_phone_label'] ?? '') ?>" placeholder="e.g. Call Us 24/7">
                        </div>
                        <div class="form-group" style="flex:1 1 160px;">
                            <label>Phone number</label>
                            <input type="text" name="if_phone[]" value="<?= h($block['if_phone'] ?? '') ?>" placeholder="e.g. (281) 215-0160">
                        </div>
                        <div class="form-group" style="flex:1 1 160px;">
                            <label>Phone link (optional)</label>
                            <input type="text" name="if_phone_url[]" value="<?= h($block['if_phone_url'] ?? '') ?>" placeholder="tel:+12812150160">
                        </div>
                    </div>
                </div>

                <?php /* ---- WIDE BANNER FIELDS ---- */ ?>
                <div class="block-fields block-fields-wide_banner <?= $type !== 'wide_banner' ? 'is-hidden' : '' ?>">
                    <div class="form-group">
                        <label>Badge text (small pill, optional)</label>
                        <input type="text" name="wb_badge[]" value="<?= h($block['wb_badge'] ?? '') ?>" placeholder="e.g. KATY, TEXAS'S SPECIALISTS">
                    </div>
                    <div class="form-group">
                        <label>Heading (H2)</label>
                        <input type="text" name="wb_heading[]" value="<?= h($block['wb_heading'] ?? '') ?>" placeholder="e.g. Your First Choice For Katy Pest Pros in Katy, TX">
                    </div>
                    <div style="display:flex;gap:12px;flex-wrap:wrap;">
                        <div class="form-group" style="flex:1 1 160px;">
                            <label>Button text</label>
                            <input type="text" name="wb_btn_text[]" value="<?= h($block['wb_btn_text'] ?? '') ?>" placeholder="e.g. Call Us">
                        </div>
                        <div class="form-group" style="flex:1 1 160px;">
                            <label>Button link</label>
                            <input type="text" name="wb_btn_url[]" value="<?= h($block['wb_btn_url'] ?? '') ?>" placeholder="tel:+12812150160">
                        </div>
                        <div class="form-group" style="flex:1 1 120px;">
                            <label>Button style</label>
                            <select name="wb_btn_style[]">
                                <option value="filled"  <?= ($block['wb_btn_style'] ?? 'filled') === 'filled'  ? 'selected' : '' ?>>Filled</option>
                                <option value="outline" <?= ($block['wb_btn_style'] ?? '')        === 'outline' ? 'selected' : '' ?>>Outline</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Badge / button color</label>
                        <select name="wb_badge_bg[]">
                            <option value="accent" <?= ($block['wb_badge_bg'] ?? 'accent') === 'accent' ? 'selected' : '' ?>>Accent (global)</option>
                            <option value="header" <?= ($block['wb_badge_bg'] ?? '') === 'header' ? 'selected' : '' ?>>Header (global)</option>
                            <option value="custom" <?= ($block['wb_badge_bg'] ?? '') === 'custom' ? 'selected' : '' ?>>Custom</option>
                        </select>
                        <input type="color" name="wb_badge_bg_custom[]" value="<?= h($block['wb_badge_bg_custom'] ?? '#fd783b') ?>" style="margin-top:4px;">
                    </div>
                    <div class="form-group">
                        <label>Background image</label>
                        <?php if (!empty($block['wb_photo'])): ?>
                            <img src="<?= str_starts_with($block['wb_photo'],'http') ? h($block['wb_photo']) : '/'.h($block['wb_photo']) ?>" style="max-height:60px;border-radius:4px;margin-bottom:6px;display:block;" onerror="this.style.display='none'">
                        <?php endif; ?>
                        <input type="file" name="wb_photo[]" accept="image/png,image/jpeg,image/gif,image/webp">
                        <input type="hidden" name="wb_photo_existing[]" value="<?= h($block['wb_photo'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label>Image alt text</label>
                        <input type="text" name="wb_photo_alt[]" value="<?= h($block['wb_photo_alt'] ?? '') ?>" placeholder="e.g. Pest control technician Katy TX">
                    </div>
                    <div class="form-group">
                        <label>Dark overlay opacity: <strong id="wb_overlay_val_<?= $i ?>"><?= h($block['wb_overlay'] ?? '0.55') ?></strong></label>
                        <input type="range" name="wb_overlay[]" min="0" max="0.9" step="0.05"
                               value="<?= h($block['wb_overlay'] ?? '0.55') ?>"
                               oninput="document.getElementById('wb_overlay_val_<?= $i ?>').textContent=this.value"
                               style="width:100%;accent-color:var(--color-accent,#2563eb);">
                        <div style="display:flex;justify-content:space-between;font-size:0.78rem;color:#888;"><span>0 (no overlay)</span><span>0.9 (very dark)</span></div>
                    </div>
                </div>

                <?php /* ---- SERVICE CARDS FIELDS ---- */ ?>
                <div class="block-fields block-fields-service_cards <?= $type !== 'service_cards' ? 'is-hidden' : '' ?>">
                    <div class="form-group">
                        <label>Badge text (orange pill above heading)</label>
                        <input type="text" name="sc_badge[]" value="<?= h($block['sc_badge'] ?? '') ?>" placeholder="e.g. PROFESSIONAL KATY, TX COMPANY">
                    </div>
                    <div class="form-group">
                        <label>Section heading</label>
                        <input type="text" name="sc_heading[]" value="<?= h($block['sc_heading'] ?? '') ?>" placeholder="e.g. Local Experts in Katy, TX">
                    </div>
                    <div class="form-group">
                        <label>Number of columns</label>
                        <select name="sc_cols[]">
                            <?php foreach ([2,3,4] as $n): ?>
                                <option value="<?= $n ?>" <?= ($block['sc_cols'] ?? 3) == $n ? 'selected' : '' ?>><?= $n ?> columns</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div style="display:flex;gap:12px;flex-wrap:wrap;">
                        <div class="form-group" style="flex:1 1 160px;">
                            <label>Badge background</label>
                            <select name="sc_badge_bg[]">
                                <option value="accent" <?= ($block['sc_badge_bg'] ?? 'accent') === 'accent' ? 'selected' : '' ?>>Accent (global)</option>
                                <option value="header" <?= ($block['sc_badge_bg'] ?? '') === 'header' ? 'selected' : '' ?>>Header (global)</option>
                                <option value="custom" <?= ($block['sc_badge_bg'] ?? '') === 'custom' ? 'selected' : '' ?>>Custom</option>
                            </select>
                            <input type="color" name="sc_badge_bg_custom[]" value="<?= h($block['sc_badge_bg_custom'] ?? '#fd783b') ?>" style="margin-top:4px;">
                        </div>
                        <div class="form-group" style="flex:1 1 160px;">
                            <label>Heading color</label>
                            <select name="sc_head_color[]">
                                <option value="header" <?= ($block['sc_head_color'] ?? 'header') === 'header' ? 'selected' : '' ?>>Header (global)</option>
                                <option value="accent" <?= ($block['sc_head_color'] ?? '') === 'accent' ? 'selected' : '' ?>>Accent (global)</option>
                                <option value="custom" <?= ($block['sc_head_color'] ?? '') === 'custom' ? 'selected' : '' ?>>Custom</option>
                            </select>
                            <input type="color" name="sc_head_color_custom[]" value="<?= h($block['sc_head_color_custom'] ?? '#120575') ?>" style="margin-top:4px;">
                        </div>
                        <div class="form-group" style="flex:1 1 160px;">
                            <label>Icon circle background color</label>
                            <input type="color" name="sc_icon_bg[]" value="<?= h($block['sc_icon_bg'] ?? '#fef0e7') ?>">
                            <span class="hint">Light peach (#fef0e7) matches katypestpros.com</span>
                        </div>
                    </div>

                    <h4 style="margin:16px 0 8px;font-size:0.95rem;">Cards</h4>
                    <div class="sc-items-editor" id="sc_items_<?= $i ?>">
                        <?php foreach (($block['sc_items'] ?? []) as $si => $sitem): ?>
                        <div class="sc-item-row" style="border:1px solid #e5e7eb;border-radius:6px;padding:12px;margin-bottom:8px;background:#f9fafb;">
                            <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-start;">
                                <div style="flex:0 0 100px;">
                                    <div class="form-group">
                                        <label>Icon image</label>
                                        <?php if (!empty($sitem['icon'])): ?>
                                            <img src="<?= str_starts_with($sitem['icon'],'http') ? h($sitem['icon']) : '/'.h($sitem['icon']) ?>" style="max-height:40px;display:block;margin-bottom:4px;" onerror="this.style.display='none'">
                                        <?php endif; ?>
                                        <input type="file" name="sc_item_icon[<?= $i ?>][]" accept="image/png,image/jpeg,image/gif,image/webp,image/svg+xml" style="font-size:0.75rem;">
                                        <input type="hidden" name="sc_item_icon_existing[<?= $i ?>][]" value="<?= h($sitem['icon'] ?? '') ?>">
                                    </div>
                                    <div class="form-group">
                                        <label>Alt text</label>
                                        <input type="text" name="sc_item_alt[<?= $i ?>][]" value="<?= h($sitem['alt'] ?? '') ?>" style="font-size:0.8rem;">
                                    </div>
                                </div>
                                <div style="flex:1 1 220px;">
                                    <div class="form-group">
                                        <label>Card heading</label>
                                        <input type="text" name="sc_item_heading[<?= $i ?>][]" value="<?= h($sitem['heading'] ?? '') ?>" placeholder="e.g. Roach Control & Extermination">
                                    </div>
                                    <div class="form-group">
                                        <label>Description</label>
                                        <textarea name="sc_item_text[<?= $i ?>][]" rows="2"><?= h($sitem['text'] ?? '') ?></textarea>
                                    </div>
                                </div>
                                <button type="button" class="remove-row" onclick="removeScItem(this)" style="align-self:flex-start;margin-top:22px;">&times;</button>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="btn btn-secondary btn-small" onclick="addScItem(this, <?= $i ?>)">+ Add card</button>
                </div>

                <?php /* ---- HERO GRID FIELDS ---- */ ?>
                <div class="block-fields block-fields-hero_grid <?= $type !== 'hero_grid' ? 'is-hidden' : '' ?>">
                    <p class="hint" style="margin-bottom:12px;">Left side: background image + text overlay. Right side: 3×2 icon grid with alternating colors.</p>

                    <h4 style="margin:0 0 10px;font-size:0.95rem;border-bottom:1px solid #e5e7eb;padding-bottom:6px;">Left Panel</h4>
                    <div class="form-group">
                        <label>Small label (above heading)</label>
                        <input type="text" name="hg_label[]" value="<?= h($block['hg_label'] ?? '') ?>" placeholder="e.g. Katy Pest Pros">
                    </div>
                    <div class="form-group">
                        <label>Heading (H2)</label>
                        <input type="text" name="hg_heading[]" value="<?= h($block['hg_heading'] ?? '') ?>" placeholder="e.g. Top-Notch Katy Pest Pros in Katy, TX">
                    </div>
                    <div class="form-group">
                        <label>Body text (leave blank line between paragraphs)</label>
                        <textarea name="hg_body[]" rows="4" class="rich-editor"><?= h($block['hg_body'] ?? '') ?></textarea>
                    </div>
                    <div style="display:flex;gap:12px;flex-wrap:wrap;">
                        <div class="form-group" style="flex:1 1 160px;">
                            <label>Button text</label>
                            <input type="text" name="hg_btn_text[]" value="<?= h($block['hg_btn_text'] ?? '') ?>" placeholder="e.g. Call Us">
                        </div>
                        <div class="form-group" style="flex:1 1 160px;">
                            <label>Button link</label>
                            <input type="text" name="hg_btn_url[]" value="<?= h($block['hg_btn_url'] ?? '') ?>" placeholder="tel:+12812150160">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Background image</label>
                        <?php if (!empty($block['hg_photo'])): ?>
                            <img src="<?= str_starts_with($block['hg_photo'],'http') ? h($block['hg_photo']) : '/'.h($block['hg_photo']) ?>" style="max-height:60px;border-radius:4px;margin-bottom:6px;display:block;" onerror="this.style.display='none'">
                        <?php endif; ?>
                        <input type="file" name="hg_photo[]" accept="image/png,image/jpeg,image/gif,image/webp">
                        <input type="hidden" name="hg_photo_existing[]" value="<?= h($block['hg_photo'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label>Image alt text</label>
                        <input type="text" name="hg_photo_alt[]" value="<?= h($block['hg_photo_alt'] ?? '') ?>" placeholder="e.g. Pest control technician Katy TX">
                    </div>

                    <h4 style="margin:16px 0 10px;font-size:0.95rem;border-bottom:1px solid #e5e7eb;padding-bottom:6px;">Right Grid — Tile Colors (alternating)</h4>
                    <div style="display:flex;gap:12px;flex-wrap:wrap;">
                        <div class="form-group" style="flex:1 1 180px;">
                            <label>Odd tiles (1st, 3rd, 5th…)</label>
                            <select name="hg_color1[]">
                                <option value="accent" <?= ($block['hg_color1'] ?? 'accent') === 'accent' ? 'selected' : '' ?>>Accent (global)</option>
                                <option value="header" <?= ($block['hg_color1'] ?? '') === 'header' ? 'selected' : '' ?>>Header color (global)</option>
                                <option value="custom" <?= ($block['hg_color1'] ?? '') === 'custom' ? 'selected' : '' ?>>Custom</option>
                            </select>
                            <input type="color" name="hg_color1_custom[]" value="<?= h($block['hg_color1_custom'] ?? '#fd783b') ?>" style="margin-top:6px;">
                        </div>
                        <div class="form-group" style="flex:1 1 180px;">
                            <label>Even tiles (2nd, 4th, 6th…)</label>
                            <select name="hg_color2[]">
                                <option value="header" <?= ($block['hg_color2'] ?? 'header') === 'header' ? 'selected' : '' ?>>Header color (global)</option>
                                <option value="accent" <?= ($block['hg_color2'] ?? '') === 'accent' ? 'selected' : '' ?>>Accent (global)</option>
                                <option value="custom" <?= ($block['hg_color2'] ?? '') === 'custom' ? 'selected' : '' ?>>Custom</option>
                            </select>
                            <input type="color" name="hg_color2_custom[]" value="<?= h($block['hg_color2_custom'] ?? '#120575') ?>" style="margin-top:6px;">
                        </div>
                    </div>

                    <h4 style="margin:16px 0 10px;font-size:0.95rem;border-bottom:1px solid #e5e7eb;padding-bottom:6px;">Grid Items (icon + label, 3 per row)</h4>
                    <div class="hg-items-editor" id="hg_items_<?= $i ?>">
                        <?php foreach (($block['hg_items'] ?? []) as $gi => $gitem): ?>
                        <div class="hg-item-row" style="display:flex;gap:10px;align-items:center;margin-bottom:8px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;padding:10px;">
                            <div style="flex:0 0 90px;">
                                <label style="font-size:0.8rem;font-weight:600;">Icon</label>
                                <?php if (!empty($gitem['icon'])): ?>
                                    <img src="<?= str_starts_with($gitem['icon'],'http') ? h($gitem['icon']) : '/'.h($gitem['icon']) ?>" style="max-height:32px;display:block;margin:4px 0;" onerror="this.style.display='none'">
                                <?php endif; ?>
                                <input type="file" name="hg_item_icon[<?= $i ?>][]" accept="image/png,image/jpeg,image/gif,image/webp,image/svg+xml" style="font-size:0.72rem;">
                                <input type="hidden" name="hg_item_icon_existing[<?= $i ?>][]" value="<?= h($gitem['icon'] ?? '') ?>">
                            </div>
                            <div style="flex:1 1 160px;">
                                <label style="font-size:0.8rem;font-weight:600;">Label</label>
                                <input type="text" name="hg_item_label[<?= $i ?>][]" value="<?= h($gitem['label'] ?? '') ?>" placeholder="e.g. Carpenter Ants">
                                <label style="font-size:0.8rem;font-weight:600;margin-top:4px;display:block;">Alt text</label>
                                <input type="text" name="hg_item_alt[<?= $i ?>][]" value="<?= h($gitem['alt'] ?? '') ?>" placeholder="Icon alt text" style="font-size:0.8rem;">
                            </div>
                            <button type="button" class="remove-row" onclick="removeHgItem(this)">&times;</button>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="btn btn-secondary btn-small" onclick="addHgItem(this, <?= $i ?>)">+ Add grid item</button>
                </div>

                <?php /* ---- TAB SERVICES FIELDS ---- */ ?>
                <div class="block-fields block-fields-tab_services <?= $type !== 'tab_services' ? 'is-hidden' : '' ?>">
                    <div style="display:flex;gap:12px;flex-wrap:wrap;">
                        <div class="form-group" style="flex:1 1 200px;">
                            <label>Badge 1 text (filled pill)</label>
                            <input type="text" name="ts_badge1[]" value="<?= h($block['ts_badge1'] ?? '') ?>" placeholder="e.g. KATY PEST PROS">
                        </div>
                        <div class="form-group" style="flex:1 1 200px;">
                            <label>Badge 2 text (outline pill)</label>
                            <input type="text" name="ts_badge2[]" value="<?= h($block['ts_badge2'] ?? '') ?>" placeholder="e.g. SERVICES KATY, TX">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Section heading</label>
                        <input type="text" name="ts_heading[]" value="<?= h($block['ts_heading'] ?? '') ?>" placeholder="e.g. Professional Katy Pest Pros Team in Katy, TX">
                    </div>
                    <div class="form-group">
                        <label>Active tab background color</label>
                        <select name="ts_active_bg[]">
                            <option value="header" <?= ($block['ts_active_bg'] ?? 'header') === 'header' ? 'selected' : '' ?>>Header/nav color (global)</option>
                            <option value="accent" <?= ($block['ts_active_bg'] ?? '') === 'accent' ? 'selected' : '' ?>>Accent color (global)</option>
                            <option value="custom" <?= ($block['ts_active_bg'] ?? '') === 'custom' ? 'selected' : '' ?>>Custom</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Custom active color (if Custom above)</label>
                        <input type="color" name="ts_active_bg_custom[]" value="<?= h($block['ts_active_bg_custom'] ?? '#120575') ?>">
                    </div>

                    <h4 style="margin:16px 0 8px;font-size:0.95rem;">Tabs (each has icon, label, photo, description)</h4>
                    <div class="ts-tabs-editor" id="ts_tabs_<?= $i ?>">
                        <?php foreach (($block['ts_tabs'] ?? []) as $ti => $tab): ?>
                        <div class="ts-tab-row" style="border:1px solid #e5e7eb;border-radius:6px;padding:12px;margin-bottom:10px;background:#f9fafb;">
                            <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-start;">
                                <div style="flex:0 0 110px;">
                                    <div class="form-group">
                                        <label>Tab icon</label>
                                        <?php if (!empty($tab['icon'])): ?>
                                            <img src="<?= str_starts_with($tab['icon'],'http') ? h($tab['icon']) : '/'.h($tab['icon']) ?>" style="max-height:36px;display:block;margin-bottom:4px;" onerror="this.style.display='none'">
                                        <?php endif; ?>
                                        <input type="file" name="ts_tab_icon[<?= $i ?>][]" accept="image/png,image/jpeg,image/gif,image/webp,image/svg+xml" style="font-size:0.75rem;">
                                        <input type="hidden" name="ts_tab_icon_existing[<?= $i ?>][]" value="<?= h($tab['icon'] ?? '') ?>">
                                    </div>
                                </div>
                                <div style="flex:1 1 200px;">
                                    <div class="form-group">
                                        <label>Tab label</label>
                                        <input type="text" name="ts_tab_label[<?= $i ?>][]" value="<?= h($tab['label'] ?? '') ?>" placeholder="e.g. Fleas">
                                    </div>
                                    <div class="form-group">
                                        <label>Description (shown below photo)</label>
                                        <textarea name="ts_tab_desc[<?= $i ?>][]" rows="2"><?= h($tab['desc'] ?? '') ?></textarea>
                                    </div>
                                </div>
                                <div style="flex:0 0 130px;">
                                    <div class="form-group">
                                        <label>Tab photo</label>
                                        <?php if (!empty($tab['photo'])): ?>
                                            <img src="<?= str_starts_with($tab['photo'],'http') ? h($tab['photo']) : '/'.h($tab['photo']) ?>" style="max-height:60px;display:block;margin-bottom:4px;border-radius:4px;" onerror="this.style.display='none'">
                                        <?php endif; ?>
                                        <input type="file" name="ts_tab_photo[<?= $i ?>][]" accept="image/png,image/jpeg,image/gif,image/webp" style="font-size:0.75rem;">
                                        <input type="hidden" name="ts_tab_photo_existing[<?= $i ?>][]" value="<?= h($tab['photo'] ?? '') ?>">
                                    </div>
                                    <div class="form-group">
                                        <label>Photo alt text</label>
                                        <input type="text" name="ts_tab_alt[<?= $i ?>][]" value="<?= h($tab['alt'] ?? '') ?>" placeholder="Alt text" style="font-size:0.8rem;">
                                    </div>
                                </div>
                                <button type="button" class="remove-row" onclick="removeTsTab(this)" style="align-self:flex-start;margin-top:22px;">&times;</button>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="btn btn-secondary btn-small" onclick="addTsTab(this, <?= $i ?>)">+ Add tab</button>
                </div>

                <?php /* ---- GALLERY FIELDS ---- */ ?>
                <div class="block-fields block-fields-gallery <?= $type !== 'gallery' ? 'is-hidden' : '' ?>">
                    <div class="form-group">
                        <label>Section heading (optional)</label>
                        <input type="text" name="gallery_heading[]" value="<?= h($block['gallery_heading'] ?? '') ?>" placeholder="e.g. Gallery of Restoration Projects">
                    </div>
                    <div class="form-group">
                        <label>Number of columns</label>
                        <select name="gallery_cols[]">
                            <?php foreach ([2,3,4] as $n): ?>
                                <option value="<?= $n ?>" <?= ($block['gallery_cols'] ?? 3) == $n ? 'selected' : '' ?>><?= $n ?> columns</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="gallery-images-editor" id="gallery_imgs_<?= $i ?>">
                        <?php foreach (($block['gallery_images'] ?? []) as $gi => $gimg): ?>
                        <div class="gallery-img-row">
                            <div style="display:flex;gap:10px;align-items:flex-start;flex-wrap:wrap;">
                                <?php if (!empty($gimg['photo'])): ?>
                                    <img src="/<?= h($gimg['photo']) ?>" style="max-height:60px;border-radius:4px;" onerror="this.style.display='none'">
                                <?php endif; ?>
                                <div style="flex:1 1 200px;">
                                    <div class="form-group">
                                        <label>Image</label>
                                        <input type="file" name="gallery_photo[<?= $i ?>][]" accept="image/png,image/jpeg,image/gif,image/webp">
                                        <input type="hidden" name="gallery_photo_existing[<?= $i ?>][]" value="<?= h($gimg['photo'] ?? '') ?>">
                                    </div>
                                    <div class="form-group">
                                        <label>Alt text</label>
                                        <input type="text" name="gallery_alt[<?= $i ?>][]" value="<?= h($gimg['alt'] ?? '') ?>" placeholder="Describe the photo for SEO">
                                    </div>
                                </div>
                                <button type="button" class="remove-row" onclick="removeGalleryImg(this)" style="margin-top:24px;">&times;</button>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="btn btn-secondary btn-small" onclick="addGalleryImg(this, <?= $i ?>)">+ Add image</button>
                </div>

                <?php /* ---- STEPS FIELDS ---- */ ?>
                <div class="block-fields block-fields-steps <?= $type !== 'steps' ? 'is-hidden' : '' ?>">
                    <div class="form-group">
                        <label>Section heading (optional)</label>
                        <input type="text" name="steps_heading[]" value="<?= h($block['steps_heading'] ?? '') ?>" placeholder="e.g. Our Recovery Process">
                    </div>
                    <span class="hint" style="display:block;margin-bottom:10px;">Leave the image blank to show an auto-numbered circle instead.</span>
                    <div class="steps-items-editor" id="steps_items_<?= $i ?>">
                        <?php foreach (($block['steps_items'] ?? []) as $si => $step): ?>
                        <div class="step-item-row">
                            <div style="display:flex;gap:10px;align-items:flex-start;flex-wrap:wrap;">
                                <div style="flex:0 0 100px;">
                                    <div class="form-group">
                                        <label>Icon/image (optional)</label>
                                        <?php if (!empty($step['image'])): ?>
                                            <img src="/<?= h($step['image']) ?>" style="max-height:48px;display:block;margin-bottom:4px;" onerror="this.style.display='none'">
                                        <?php endif; ?>
                                        <input type="file" name="steps_image[<?= $i ?>][]" accept="image/png,image/jpeg,image/gif,image/webp" style="font-size:0.78rem;">
                                        <input type="hidden" name="steps_image_existing[<?= $i ?>][]" value="<?= h($step['image'] ?? '') ?>">
                                    </div>
                                    <div class="form-group">
                                        <label>Alt text</label>
                                        <input type="text" name="steps_alt[<?= $i ?>][]" value="<?= h($step['alt'] ?? '') ?>" placeholder="Step icon description" style="font-size:0.82rem;">
                                    </div>
                                </div>
                                <div style="flex:1 1 200px;">
                                    <div class="form-group">
                                        <label>Step heading</label>
                                        <input type="text" name="steps_heading_item[<?= $i ?>][]" value="<?= h($step['heading'] ?? '') ?>" placeholder="e.g. Call Us">
                                    </div>
                                    <div class="form-group">
                                        <label>Description</label>
                                        <textarea name="steps_text[<?= $i ?>][]" rows="2"><?= h($step['text'] ?? '') ?></textarea>
                                    </div>
                                </div>
                                <button type="button" class="remove-row" onclick="removeStepItem(this)" style="margin-top:24px;">&times;</button>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="btn btn-secondary btn-small" onclick="addStepItem(this, <?= $i ?>)">+ Add step</button>
                </div>

                <?php /* ---- STATS FIELDS ---- */ ?>
                <div class="block-fields block-fields-stats <?= $type !== 'stats' ? 'is-hidden' : '' ?>">
                    <div class="form-group">
                        <label>Section heading (optional)</label>
                        <input type="text" name="stats_heading[]" value="<?= h($block['stats_heading'] ?? '') ?>" placeholder="e.g. Why Choose Us">
                    </div>
                    <div style="display:flex;gap:12px;flex-wrap:wrap;">
                        <div class="form-group" style="flex:1 1 160px;">
                            <label>Background color</label>
                            <input type="color" name="stats_bg_color[]" value="<?= h($block['stats_bg_color'] ?? '#1e3a5f') ?>">
                        </div>
                        <div class="form-group" style="flex:1 1 160px;">
                            <label>Text color</label>
                            <input type="color" name="stats_text_color[]" value="<?= h($block['stats_text_color'] ?? '#ffffff') ?>">
                        </div>
                    </div>
                    <div class="stats-items-editor" id="stats_items_<?= $i ?>">
                        <?php foreach (($block['stats_items'] ?? []) as $stat): ?>
                        <div class="stat-item-row">
                            <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                                <div class="form-group" style="flex:1 1 120px;">
                                    <label>Number / value</label>
                                    <input type="text" name="stats_number[<?= $i ?>][]" value="<?= h($stat['number'] ?? '') ?>" placeholder="e.g. 5,200+">
                                </div>
                                <div class="form-group" style="flex:2 1 200px;">
                                    <label>Label</label>
                                    <input type="text" name="stats_label[<?= $i ?>][]" value="<?= h($stat['label'] ?? '') ?>" placeholder="e.g. Jobs Completed">
                                </div>
                                <button type="button" class="remove-row" onclick="removeStatItem(this)">&times;</button>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="btn btn-secondary btn-small" onclick="addStatItem(this, <?= $i ?>)">+ Add stat</button>
                </div>

                <?php /* ---- CARDS GRID FIELDS ---- */ ?>
                <div class="block-fields block-fields-cards <?= $type !== 'cards' ? 'is-hidden' : '' ?>">
                    <div class="form-group">
                        <label>Section heading (optional)</label>
                        <input type="text" name="cards_heading[]" value="<?= h($block['cards_heading'] ?? '') ?>" placeholder="e.g. Our Services">
                    </div>
                    <div class="form-group">
                        <label>Number of columns</label>
                        <select name="cards_cols[]">
                            <?php foreach ([2,3,4] as $n): ?>
                                <option value="<?= $n ?>" <?= ($block['cards_cols'] ?? 3) == $n ? 'selected' : '' ?>><?= $n ?> columns</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="cards-items-editor" id="cards_items_<?= $i ?>">
                        <?php foreach (($block['cards_items'] ?? []) as $card): ?>
                        <div class="card-item-row">
                            <div style="display:flex;gap:10px;align-items:flex-start;flex-wrap:wrap;border:1px solid #e5e7eb;border-radius:6px;padding:12px;margin-bottom:8px;background:#f9fafb;">
                                <div style="flex:0 0 100px;">
                                    <div class="form-group">
                                        <label>Image</label>
                                        <?php if (!empty($card['image'])): ?>
                                            <img src="/<?= h($card['image']) ?>" style="max-height:60px;display:block;margin-bottom:4px;" onerror="this.style.display='none'">
                                        <?php endif; ?>
                                        <input type="file" name="cards_image[<?= $i ?>][]" accept="image/png,image/jpeg,image/gif,image/webp" style="font-size:0.78rem;">
                                        <input type="hidden" name="cards_image_existing[<?= $i ?>][]" value="<?= h($card['image'] ?? '') ?>">
                                    </div>
                                    <div class="form-group">
                                        <label>Alt text</label>
                                        <input type="text" name="cards_alt[<?= $i ?>][]" value="<?= h($card['alt'] ?? '') ?>" placeholder="Image description" style="font-size:0.82rem;">
                                    </div>
                                </div>
                                <div style="flex:1 1 200px;">
                                    <div class="form-group">
                                        <label>Heading</label>
                                        <input type="text" name="cards_heading_item[<?= $i ?>][]" value="<?= h($card['heading'] ?? '') ?>" placeholder="e.g. Water Damage Repair">
                                    </div>
                                    <div class="form-group">
                                        <label>Description</label>
                                        <textarea name="cards_text[<?= $i ?>][]" rows="2"><?= h($card['text'] ?? '') ?></textarea>
                                    </div>
                                    <div style="display:flex;gap:8px;flex-wrap:wrap;">
                                        <div class="form-group" style="flex:1 1 140px;">
                                            <label>Link URL</label>
                                            <input type="text" name="cards_link[<?= $i ?>][]" value="<?= h($card['link'] ?? '') ?>" placeholder="/service-page">
                                        </div>
                                        <div class="form-group" style="flex:1 1 100px;">
                                            <label>Button text</label>
                                            <input type="text" name="cards_btn[<?= $i ?>][]" value="<?= h($card['btn_text'] ?? 'Read More') ?>" placeholder="Read More">
                                        </div>
                                    </div>
                                </div>
                                <button type="button" class="remove-row" onclick="removeCardItem(this)" style="align-self:flex-start;">&times;</button>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="btn btn-secondary btn-small" onclick="addCardItem(this, <?= $i ?>)">+ Add card</button>
                </div>

            </div><!-- .block-card -->
            <?php endforeach; ?>
        </div>
        <button type="button" class="btn btn-secondary btn-small" onclick="addBlock()">+ Add content block</button>
    </div>
    <?php
}

/* helper: photo upload sub-form (used inside image_left/right fields) */
function render_photo_upload_fields($fieldBaseName, $existingPhoto, $ratio, $position, $alt, $index) {
    ?>
    <div class="form-group">
        <label>Image alt text (SEO)</label>
        <input type="text" name="block_photo_alt[]" value="<?= h($alt) ?>" placeholder="Describe the image">
    </div>
    <div class="current-image">
        <?php if (!empty($existingPhoto)): ?>
            <img src="../<?= h($existingPhoto) ?>" alt="Block image">
        <?php else: ?>
            <span class="none">No image uploaded yet.</span>
        <?php endif; ?>
    </div>
    <label>Upload image</label>
    <input type="file" name="<?= $fieldBaseName ?>[]" accept="image/png,image/jpeg,image/gif,image/webp">
    <input type="hidden" name="block_existing_photo[]" value="<?= h($existingPhoto) ?>">
    <?php if (!empty($existingPhoto)): ?>
        <label style="margin-top:8px;font-weight:400;">
            <input type="checkbox" name="block_remove_photo[]" value="1"> Remove current image
        </label>
    <?php else: ?>
        <input type="checkbox" name="block_remove_photo[]" value="1" style="display:none;">
    <?php endif; ?>
    <div style="display:flex;gap:12px;margin-top:12px;flex-wrap:wrap;">
        <div style="flex:1 1 160px;">
            <label>Picture shape</label>
            <select name="block_photo_ratio[]"><?= photo_ratio_options_html($ratio) ?></select>
        </div>
        <div style="flex:1 1 160px;">
            <label>Crop focus</label>
            <select name="block_photo_position[]"><?= photo_position_options_html($position) ?></select>
        </div>
    </div>
    <?php
}

/* ============================================================
   ADMIN: SEO editor
   ============================================================ */
function render_seo_editor($seo) {
    ?>
    <div class="card">
        <h2>SEO &amp; Metadata</h2>
        <p class="hint" style="margin-bottom:18px;">These fields help search engines and social media understand your page.</p>

        <div class="form-group">
            <label for="meta_description">Meta description</label>
            <textarea id="meta_description" name="meta_description" rows="3"><?= h($seo['meta_description'] ?? '') ?></textarea>
            <span class="hint">1–2 sentences shown in search results. Aim for 120–160 characters.</span>
        </div>
        <div class="form-group">
            <label for="meta_keywords">Meta keywords</label>
            <input type="text" id="meta_keywords" name="meta_keywords" value="<?= h($seo['meta_keywords'] ?? '') ?>" placeholder="e.g. pest control, Katy TX, exterminator">
        </div>
        <div class="form-group">
            <label for="og_title">Social share title (og:title)</label>
            <input type="text" id="og_title" name="og_title" value="<?= h($seo['og_title'] ?? '') ?>" placeholder="Leave blank to use the page title">
        </div>
        <div class="form-group">
            <label for="og_description">Social share description (og:description)</label>
            <textarea id="og_description" name="og_description" rows="2"><?= h($seo['og_description'] ?? '') ?></textarea>
            <span class="hint">Shown when someone shares this page on social media.</span>
        </div>
        <div class="form-group">
            <label for="schema">Schema markup (JSON-LD)</label>
            <textarea id="schema" name="schema" rows="8" style="font-family:monospace;font-size:0.85rem;"><?= h($seo['schema'] ?? '') ?></textarea>
            <span class="hint">Optional structured data from <a href="https://schema.org" target="_blank" rel="noopener">schema.org</a>. Must be valid JSON.</span>
        </div>
    </div>
    <?php
}

/* ============================================================
   ADMIN: JS for the block editor
   ============================================================ */
function content_editor_scripts() {
    $blockTypes = json_encode(array_keys(allowed_block_types()));
    ?>
    <script>
    const BLOCK_TYPES = <?= $blockTypes ?>;

    /* Show only the fields panel matching the selected block type */
    function switchBlockType(select) {
        const card = select.closest('.block-card');
        BLOCK_TYPES.forEach(t => {
            const panel = card.querySelector('.block-fields-' + t);
            if (panel) panel.classList.toggle('is-hidden', t !== select.value);
        });
        card.dataset.blockType = select.value;
    }

    /* Legacy alias */
    function toggleBlockImage(select) { switchBlockType(select); }

    /* Move block up/down */
    function moveBlock(btn, dir) {
        const card = btn.closest('.block-card');
        const container = card.parentElement;
        if (dir < 0) { const prev = card.previousElementSibling; if (prev) container.insertBefore(card, prev); }
        else         { const next = card.nextElementSibling;     if (next) container.insertBefore(next, card); }
    }

    /* Remove a block */
    function removeBlock(btn) {
        const container = document.getElementById('content-blocks');
        const card = btn.closest('.block-card');
        if (container.children.length > 1) card.remove();
    }

    /* Add a new blank block */
    function addBlock() {
        const container = document.getElementById('content-blocks');
        const idx = container.children.length;
        const card = document.createElement('div');
        card.className = 'block-card';
        card.dataset.blockType = 'text';

        let typeOptions = '';
        <?php foreach (allowed_block_types() as $k => $l): ?>
        typeOptions += `<option value="<?= h($k) ?>"><?= h($l) ?></option>`;
        <?php endforeach; ?>

        card.innerHTML = `
            <div class="block-card-header">
                <span class="block-label">New block</span>
                <select name="block_type[]" class="block-type-select" onchange="switchBlockType(this)">
                    ${typeOptions}
                </select>
                <input type="text" name="block_anchor[]"
                       placeholder="Section ID (e.g. about)"
                       title="Anchor ID — use in menu links as #about"
                       style="flex:1 1 160px;max-width:200px;font-size:0.82rem;padding:6px 10px;">
                <div class="block-actions">
                    <button type="button" class="icon-btn" onclick="moveBlock(this,-1)">&uarr;</button>
                    <button type="button" class="icon-btn" onclick="moveBlock(this,1)">&darr;</button>
                    <button type="button" class="icon-btn remove-row" onclick="removeBlock(this)">Remove</button>
                </div>
            </div>
            <div class="block-fields block-fields-text">
                <input type="hidden" name="block_photo_alt[]" value="">
                <input type="hidden" name="block_existing_photo[]" value="">
                <input type="hidden" name="block_photo_ratio[]" value="landscape">
                <input type="hidden" name="block_photo_position[]" value="center">
                <input type="checkbox" name="block_remove_photo[]" value="1" style="display:none;">
                <div class="form-group">
                    <label>Heading level</label>
                    <select name="block_heading_level[]">
                        <option value="h1">H1 (Page title)</option>
                        <option value="h2" selected>H2 (Section heading)</option>
                        <option value="h3">H3 (Sub-section)</option>
                        <option value="p">Paragraph (no heading)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Text</label>
                    <textarea name="block_text[]" rows="5" placeholder="Write the text for this block..."></textarea>
                    <span class="hint">First line = heading. Leave a blank line between paragraphs.</span>
                </div>
            </div>
            <div class="block-fields block-fields-image_left block-fields-image_right is-hidden">
                <div class="form-group"><label>Text</label><textarea name="block_text[]" rows="4"></textarea></div>
                <div class="form-group"><label>Image alt text</label><input type="text" name="block_photo_alt[]" placeholder="Describe the image"></div>
                <div class="current-image"><span class="none">No image uploaded yet.</span></div>
                <label>Upload image</label>
                <input type="file" name="block_photo[]" accept="image/png,image/jpeg,image/gif,image/webp">
                <input type="hidden" name="block_existing_photo[]" value="">
                <input type="checkbox" name="block_remove_photo[]" value="1" style="display:none;">
                <div style="display:flex;gap:12px;margin-top:12px;flex-wrap:wrap;">
                    <div style="flex:1 1 160px;"><label>Picture shape</label>
                        <select name="block_photo_ratio[]">
                            <option value="landscape" selected>Horizontal rectangle</option>
                            <option value="square">Square</option>
                            <option value="portrait">Vertical rectangle</option>
                            <option value="auto">Original size</option>
                        </select>
                    </div>
                    <div style="flex:1 1 160px;"><label>Crop focus</label>
                        <select name="block_photo_position[]">
                            <option value="center" selected>Center</option>
                            <option value="top">Top</option>
                            <option value="bottom">Bottom</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="block-fields block-fields-hero is-hidden">
                <div class="form-group"><label>Headline (H1)</label><input type="text" name="hero_heading[]" placeholder="Main page headline"></div>
                <div class="form-group"><label>Subtext</label><textarea name="hero_subtext[]" rows="2"></textarea></div>
                <div style="display:flex;gap:12px;flex-wrap:wrap;">
                    <div class="form-group" style="flex:1 1 160px;"><label>Button text</label><input type="text" name="hero_btn_text[]"></div>
                    <div class="form-group" style="flex:1 1 160px;"><label>Button link</label><input type="text" name="hero_btn_url[]"></div>
                </div>
                <div style="display:flex;gap:12px;flex-wrap:wrap;">
                    <div class="form-group" style="flex:1 1 160px;"><label>Background color</label><input type="color" name="hero_bg_color[]" value="#1e3a5f"></div>
                    <div class="form-group" style="flex:1 1 160px;"><label>Text color</label><input type="color" name="hero_text_color[]" value="#ffffff"></div>
                </div>
                <div class="form-group"><label>Background image</label>
                    <input type="file" name="hero_bg_image[]" accept="image/png,image/jpeg,image/gif,image/webp">
                    <input type="hidden" name="hero_bg_image_existing[]" value="">
                </div>
            </div>
            <div class="block-fields block-fields-hero_split is-hidden">
                <div class="form-group"><label>H1 Headline</label><input type="text" name="hs_heading[]" placeholder="e.g. Trusted Local Pest Control in Katy, TX"></div>
                <div class="form-group"><label>Paragraph text</label><textarea name="hs_subtext[]" rows="3"></textarea></div>
                <div style="display:flex;gap:12px;flex-wrap:wrap;">
                    <div class="form-group" style="flex:1 1 180px;"><label>Button text</label><input type="text" name="hs_btn_text[]"></div>
                    <div class="form-group" style="flex:1 1 180px;"><label>Button link</label><input type="text" name="hs_btn_url[]" placeholder="tel:+1..."></div>
                </div>
                <div class="form-group"><label>Background color</label><input type="color" name="hs_bg_color[]" value="#f3f6f7"></div>
                <div class="form-group"><label>Right-side image</label>
                    <input type="file" name="hs_photo[]" accept="image/png,image/jpeg,image/gif,image/webp">
                    <input type="hidden" name="hs_photo_existing[]" value="">
                </div>
                <div class="form-group"><label>Image alt text</label><input type="text" name="hs_photo_alt[]"></div>
                <div style="display:flex;gap:12px;flex-wrap:wrap;">
                    <div class="form-group" style="flex:1 1 180px;"><label>Caption line 1</label><input type="text" name="hs_caption1[]" placeholder="e.g. Pest Control"></div>
                    <div class="form-group" style="flex:1 1 180px;"><label>Caption line 2</label><input type="text" name="hs_caption2[]" placeholder="e.g. Katy, TX"></div>
                </div>
            </div>
            <div class="block-fields block-fields-feature_split is-hidden">
                <div class="form-group"><label>Section heading</label><input type="text" name="fs_heading[]" placeholder="e.g. Full-Service Pest Management"></div>
                <div class="form-group"><label>Intro paragraph</label><textarea name="fs_subtext[]" rows="2"></textarea></div>
                <div style="display:flex;gap:12px;flex-wrap:wrap;">
                    <div class="form-group" style="flex:1 1 160px;"><label>Background color</label><input type="color" name="fs_bg_color[]" value="#f3f6f7"></div>
                    <div class="form-group" style="flex:1 1 160px;"><label>Item heading color</label><input type="color" name="fs_accent[]" value="#fd783b"></div>
                </div>
                <div class="fs-items-editor" id="fs_items_new_${idx}"></div>
                <button type="button" class="btn btn-secondary btn-small" onclick="addFsItem(this, 'new_${idx}')">+ Add item</button>
                <div class="form-group" style="margin-top:16px;"><label>Right-side image</label>
                    <input type="file" name="fs_photo[]" accept="image/png,image/jpeg,image/gif,image/webp">
                    <input type="hidden" name="fs_photo_existing[]" value="">
                </div>
                <div class="form-group"><label>Image alt text</label><input type="text" name="fs_photo_alt[]"></div>
                <div class="form-group"><label>Star badge text</label><input type="text" name="fs_star_text[]" placeholder="e.g. 5 Star Services"></div>
            </div>
            <div class="block-fields block-fields-feature_columns is-hidden">
                <div class="form-group"><label>Section heading</label><input type="text" name="fc_heading[]" placeholder="e.g. Our Services\"></div>
                <div class="form-group"><label>Number of columns</label>
                    <select name="fc_num_cols[]"><option value="2">2</option><option value="3" selected>3</option><option value="4">4</option></select>
                </div>
                <div class="fc-columns-editor" id="fc_cols_new_${idx}"></div>
                <button type="button" class="btn btn-secondary btn-small" onclick="addFcCol(this, 'new_${idx}')">+ Add column item</button>
            </div>
            <div class="block-fields block-fields-split_cta is-hidden">
                <div class="form-group"><label>Left heading</label><input type="text" name="sc_left_heading[]" placeholder="e.g. Serving the Greater Katy, TX Area"></div>
                <div class="form-group"><label>Left text</label><textarea name="sc_left_text[]" rows="3"></textarea></div>
                <div class="form-group"><label>Left background</label>
                    <select name="sc_left_bg[]"><option value="accent" selected>Accent (global)</option><option value="header">Header color (global)</option><option value="custom">Custom</option></select>
                </div>
                <div class="form-group"><label>Custom left color</label><input type="color" name="sc_left_bg_custom[]" value="#fd783b"></div>
                <div class="form-group"><label>Right label text</label><input type="text" name="sc_right_label[]" placeholder="e.g. Call The Katy Pest Pros Team"></div>
                <div class="form-group"><label>Right phone number</label><input type="text" name="sc_right_phone[]" placeholder="e.g. (281) 215-0160"></div>
                <div class="form-group"><label>Right phone link</label><input type="text" name="sc_right_phone_url[]" placeholder="tel:+12812150160"></div>
                <div class="form-group"><label>Right background</label>
                    <select name="sc_right_bg[]"><option value="header" selected>Header color (global)</option><option value="accent">Accent (global)</option><option value="custom">Custom</option></select>
                </div>
                <div class="form-group"><label>Custom right color</label><input type="color" name="sc_right_bg_custom[]" value="#120575"></div>
            </div>
            <div class="block-fields block-fields-cta_button is-hidden">
                <div style="display:flex;gap:12px;flex-wrap:wrap;">
                    <div class="form-group" style="flex:1 1 160px;"><label>Button text</label><input type="text" name="cta_text[]" value="Contact Us"></div>
                    <div class="form-group" style="flex:1 1 160px;"><label>Button link</label><input type="text" name="cta_url[]" value="#"></div>
                </div>
                <div class="form-group"><label>Optional text above button</label><input type="text" name="cta_subtext[]"></div>
                <div class="form-group"><label>Alignment</label>
                    <select name="cta_align[]"><option value="left">Left</option><option value="center" selected>Center</option><option value="right">Right</option></select>
                </div>
            </div>
            <div class="block-fields block-fields-image_text is-hidden">
                <div class="form-group"><label>Image side</label>
                    <select name="it_image_side[]"><option value="left" selected>Image left, text right</option><option value="right">Text left, image right</option></select>
                </div>
                <div class="form-group"><label>Heading level</label>
                    <select name="it_heading_level[]"><option value="h2" selected>H2</option><option value="h3">H3</option><option value="p">Paragraph</option></select>
                </div>
                <div class="form-group"><label>Heading</label><input type="text" name="it_heading[]"></div>
                <div class="form-group"><label>Text</label><textarea name="it_text[]" rows="4"></textarea></div>
                <div style="display:flex;gap:12px;flex-wrap:wrap;">
                    <div class="form-group" style="flex:1 1 160px;"><label>Button text</label><input type="text" name="it_btn_text[]"></div>
                    <div class="form-group" style="flex:1 1 160px;"><label>Button link</label><input type="text" name="it_btn_url[]"></div>
                </div>
                <div class="form-group"><label>Image</label>
                    <input type="file" name="it_photo[]" accept="image/png,image/jpeg,image/gif,image/webp">
                    <input type="hidden" name="it_photo_existing[]" value="">
                </div>
                <div class="form-group"><label>Image alt text</label><input type="text" name="it_alt[]"></div>
                <div style="display:flex;gap:12px;flex-wrap:wrap;">
                    <div style="flex:1 1 160px;"><label>Picture shape</label>
                        <select name="it_ratio[]"><option value="landscape" selected>Horizontal</option><option value="square">Square</option><option value="portrait">Vertical</option><option value="auto">Original</option></select>
                    </div>
                    <div style="flex:1 1 160px;"><label>Crop focus</label>
                        <select name="it_position[]"><option value="center" selected>Center</option><option value="top">Top</option><option value="bottom">Bottom</option></select>
                    </div>
                </div>
            </div>
            <div class="block-fields block-fields-faq is-hidden">
                <div class="form-group"><label>Section heading</label><input type="text" name="faq_heading[]" placeholder="e.g. Frequently Asked Questions"></div>
                <div class="faq-items-editor" id="faq_items_new_${idx}"></div>
                <button type="button" class="btn btn-secondary btn-small" onclick="addFaqItem(this, 'new_${idx}')">+ Add Q&amp;A</button>
            </div>
            <div class="block-fields block-fields-custom_html is-hidden">
                <div class="form-group"><label>Custom HTML / Embed code</label>
                    <textarea name="custom_html[]" rows="6" style="font-family:monospace;font-size:0.83rem;"></textarea>
                    <span class="hint">Paste maps, widgets, scripts, etc.</span>
                </div>
            </div>
            <div class="block-fields block-fields-cta_card is-hidden">
                <div class="form-group"><label>Heading</label><input type="text" name="cc_heading[]" placeholder="e.g. Contact Katy's Top Pest Control Company Today"></div>
                <div class="form-group"><label>Text</label><textarea name="cc_text[]" rows="3"></textarea></div>
                <div style="display:flex;gap:12px;flex-wrap:wrap;">
                    <div class="form-group" style="flex:1 1 140px;"><label>Button text</label><input type="text" name="cc_btn_text[]"></div>
                    <div class="form-group" style="flex:1 1 140px;"><label>Button link</label><input type="text" name="cc_btn_url[]" placeholder="tel:+1..."></div>
                    <div class="form-group" style="flex:1 1 100px;"><label>Style</label>
                        <select name="cc_btn_style[]"><option value="outline" selected>Outline</option><option value="filled">Filled</option></select>
                    </div>
                </div>
                <div style="display:flex;gap:12px;flex-wrap:wrap;">
                    <div class="form-group" style="flex:1 1 140px;"><label>Background</label>
                        <select name="cc_bg[]"><option value="accent" selected>Accent</option><option value="header">Header</option><option value="custom">Custom</option></select>
                        <input type="color" name="cc_bg_custom[]" value="#fd783b" style="margin-top:4px;">
                    </div>
                    <div class="form-group" style="flex:1 1 100px;"><label>Border radius (px)</label><input type="number" name="cc_radius[]" value="12" min="0" max="40"></div>
                </div>
            </div>
            <div class="block-fields block-fields-map_info is-hidden">
                <div class="form-group"><label>Heading color</label>
                    <select name="mi_head_color[]"><option value="header" selected>Header</option><option value="accent">Accent</option><option value="custom">Custom</option></select>
                    <input type="color" name="mi_head_color_custom[]" value="#120575" style="margin-top:4px;">
                </div>
                <div class="form-group"><label>Map panel heading</label><input type="text" name="mi_map_heading[]" placeholder="e.g. Katy, Texas Map"></div>
                <div class="form-group"><label>Google Maps embed code</label>
                    <textarea name="mi_map_embed[]" rows="3" placeholder="Paste &lt;iframe&gt; embed code here"></textarea>
                </div>
                <div class="form-group"><label>Info panel heading</label><input type="text" name="mi_info_heading[]" placeholder="e.g. Katy, TX Information"></div>
                <div class="form-group"><label>Info text</label><textarea name="mi_info_text[]" rows="3"></textarea></div>
                <div class="form-group"><label>Info photo</label>
                    <input type="file" name="mi_info_photo[]" accept="image/png,image/jpeg,image/gif,image/webp">
                    <input type="hidden" name="mi_info_photo_existing[]" value="">
                </div>
                <div class="form-group"><label>Photo alt text</label><input type="text" name="mi_info_alt[]"></div>
            </div>
            <div class="block-fields block-fields-links_grid is-hidden">
                <div class="form-group"><label>Heading</label><input type="text" name="lg_heading[]" placeholder="Our Pest Control Services in Katy, TX"></div>
                <div class="form-group"><label>Subtext</label><textarea name="lg_subtext[]" rows="2"></textarea></div>
                <div style="display:flex;gap:12px;flex-wrap:wrap;">
                    <div class="form-group" style="flex:1 1 140px;"><label>Columns</label>
                        <select name="lg_cols[]"><option value="2">2</option><option value="3">3</option><option value="4">4</option><option value="5" selected>5</option><option value="6">6</option></select>
                    </div>
                    <div class="form-group" style="flex:1 1 140px;"><label>Overlay opacity</label>
                        <input type="range" name="lg_overlay[]" min="0" max="0.9" step="0.05" value="0.6" style="width:100%;">
                    </div>
                </div>
                <div class="form-group"><label>Background image</label>
                    <input type="file" name="lg_photo[]" accept="image/png,image/jpeg,image/gif,image/webp">
                    <input type="hidden" name="lg_photo_existing[]" value="">
                </div>
                <div class="form-group"><label>Image alt text</label><input type="text" name="lg_photo_alt[]"></div>
                <div class="lg-links-editor" id="lg_links_new_${idx}"></div>
                <button type="button" class="btn btn-secondary btn-small" onclick="addLgLink(this, 'new_${idx}')">+ Add link</button>
                <div class="form-group" style="margin-top:10px;">
                    <label>Bulk add (one per line)</label>
                    <textarea id="lg_bulk_new_${idx}" rows="3" placeholder="Service 1&#10;Service 2"></textarea>
                    <button type="button" class="btn btn-secondary btn-small" style="margin-top:4px;" onclick="bulkAddLgLinks('new_${idx}')">Add all</button>
                </div>
            </div>
            <div class="block-fields block-fields-cta_banner is-hidden">
                <div class="form-group"><label>Banner text</label><input type="text" name="cb_text[]" placeholder="e.g. 24/7 Pest Control Services in Katy, TX"></div>
                <div class="form-group"><label>Subtext (optional)</label><input type="text" name="cb_subtext[]"></div>
                <div style="display:flex;gap:12px;flex-wrap:wrap;">
                    <div class="form-group" style="flex:1 1 140px;"><label>Button text</label><input type="text" name="cb_btn_text[]"></div>
                    <div class="form-group" style="flex:1 1 140px;"><label>Button link</label><input type="text" name="cb_btn_url[]"></div>
                </div>
                <div style="display:flex;gap:12px;flex-wrap:wrap;">
                    <div class="form-group" style="flex:1 1 140px;"><label>Background</label>
                        <select name="cb_bg[]"><option value="accent" selected>Accent</option><option value="header">Header</option><option value="custom">Custom</option></select>
                        <input type="color" name="cb_bg_custom[]" value="#fd783b" style="margin-top:4px;">
                    </div>
                    <div class="form-group" style="flex:1 1 100px;"><label>Text color</label><input type="color" name="cb_text_color[]" value="#ffffff"></div>
                    <div class="form-group" style="flex:1 1 100px;"><label>Padding</label>
                        <select name="cb_padding[]"><option value="compact">Compact</option><option value="normal" selected>Normal</option><option value="large">Large</option></select>
                    </div>
                </div>
            </div>
            <div class="block-fields block-fields-faq_two_col is-hidden">
                <div class="form-group"><label>Heading</label><input type="text" name="fq_heading[]" placeholder="e.g. FAQs – Pest Control in Katy"></div>
                <div style="display:flex;gap:12px;flex-wrap:wrap;">
                    <div class="form-group" style="flex:1 1 120px;"><label>Background</label><input type="color" name="fq_bg_color[]" value="#ffffff"></div>
                    <div class="form-group" style="flex:1 1 120px;"><label>Item box color</label><input type="color" name="fq_item_bg[]" value="#f0f2f8"></div>
                </div>
                <div style="display:flex;gap:12px;flex-wrap:wrap;">
                    <div class="form-group" style="flex:1 1 140px;"><label>Heading color</label>
                        <select name="fq_head_color[]"><option value="header" selected>Header</option><option value="accent">Accent</option><option value="custom">Custom</option></select>
                        <input type="color" name="fq_head_color_custom[]" value="#120575" style="margin-top:4px;">
                    </div>
                    <div class="form-group" style="flex:1 1 140px;"><label>Icon color</label>
                        <select name="fq_icon_bg[]"><option value="accent" selected>Accent</option><option value="header">Header</option><option value="custom">Custom</option></select>
                        <input type="color" name="fq_icon_bg_custom[]" value="#fd783b" style="margin-top:4px;">
                    </div>
                </div>
                <div class="fq-items-editor" id="fq_items_new_${idx}"></div>
                <button type="button" class="btn btn-secondary btn-small" onclick="addFqItem(this, 'new_${idx}')">+ Add Q&amp;A</button>
            </div>
            <div class="block-fields block-fields-image_features is-hidden">
                <div class="form-group"><label>Background color</label><input type="color" name="if_bg_color[]" value="#f3f6f7"></div>
                <div style="display:flex;gap:12px;flex-wrap:wrap;">
                    <div class="form-group" style="flex:1 1 140px;"><label>Check color</label>
                        <select name="if_check_color[]"><option value="accent" selected>Accent</option><option value="header">Header</option><option value="custom">Custom</option></select>
                        <input type="color" name="if_check_color_custom[]" value="#fd783b" style="margin-top:4px;">
                    </div>
                    <div class="form-group" style="flex:1 1 140px;"><label>Heading color</label>
                        <select name="if_head_color[]"><option value="header" selected>Header</option><option value="accent">Accent</option><option value="custom">Custom</option></select>
                        <input type="color" name="if_head_color_custom[]" value="#120575" style="margin-top:4px;">
                    </div>
                </div>
                <div class="form-group"><label>Photo</label>
                    <input type="file" name="if_photo[]" accept="image/png,image/jpeg,image/gif,image/webp">
                    <input type="hidden" name="if_photo_existing[]" value="">
                </div>
                <div class="form-group"><label>Photo alt text</label><input type="text" name="if_photo_alt[]"></div>
                <div class="form-group"><label>Heading</label><input type="text" name="if_heading[]" placeholder="e.g. Quality Pest Prevention"></div>
                <div class="form-group"><label>Intro paragraph</label><textarea name="if_intro[]" rows="3"></textarea></div>
                <div class="if-feats-editor" id="if_feats_new_${idx}"></div>
                <button type="button" class="btn btn-secondary btn-small" onclick="addIfFeat(this, 'new_${idx}')">+ Add feature</button>
                <div class="form-group" style="margin-top:10px;"><label>Closing paragraph</label><textarea name="if_closing[]" rows="2"></textarea></div>
                <div style="display:flex;gap:12px;flex-wrap:wrap;">
                    <div class="form-group" style="flex:1 1 140px;"><label>Phone label</label><input type="text" name="if_phone_label[]" placeholder="Call Us 24/7"></div>
                    <div class="form-group" style="flex:1 1 140px;"><label>Phone number</label><input type="text" name="if_phone[]" placeholder="(281) 215-0160"></div>
                    <div class="form-group" style="flex:1 1 140px;"><label>Phone link</label><input type="text" name="if_phone_url[]" placeholder="tel:+1..."></div>
                </div>
            </div>
            <div class="block-fields block-fields-wide_banner is-hidden">
                <div class="form-group"><label>Badge text</label><input type="text" name="wb_badge[]" placeholder="e.g. KATY, TEXAS'S SPECIALISTS"></div>
                <div class="form-group"><label>Heading</label><input type="text" name="wb_heading[]" placeholder="Your First Choice For Katy Pest Pros in Katy, TX"></div>
                <div style="display:flex;gap:12px;flex-wrap:wrap;">
                    <div class="form-group" style="flex:1 1 160px;"><label>Button text</label><input type="text" name="wb_btn_text[]" placeholder="e.g. Call Us"></div>
                    <div class="form-group" style="flex:1 1 160px;"><label>Button link</label><input type="text" name="wb_btn_url[]" placeholder="tel:+1..."></div>
                    <div class="form-group" style="flex:1 1 100px;"><label>Button style</label>
                        <select name="wb_btn_style[]"><option value="filled" selected>Filled</option><option value="outline">Outline</option></select>
                    </div>
                </div>
                <div class="form-group"><label>Badge / button color</label>
                    <select name="wb_badge_bg[]"><option value="accent" selected>Accent (global)</option><option value="header">Header (global)</option><option value="custom">Custom</option></select>
                    <input type="color" name="wb_badge_bg_custom[]" value="#fd783b" style="margin-top:4px;">
                </div>
                <div class="form-group"><label>Background image</label>
                    <input type="file" name="wb_photo[]" accept="image/png,image/jpeg,image/gif,image/webp">
                    <input type="hidden" name="wb_photo_existing[]" value="">
                </div>
                <div class="form-group"><label>Image alt text</label><input type="text" name="wb_photo_alt[]"></div>
                <div class="form-group"><label>Overlay opacity (0=none, 0.9=very dark)</label>
                    <input type="range" name="wb_overlay[]" min="0" max="0.9" step="0.05" value="0.55" style="width:100%;">
                </div>
            </div>
            <div class="block-fields block-fields-service_cards is-hidden">
                <div class="form-group"><label>Badge text</label><input type="text" name="sc_badge[]" placeholder="e.g. PROFESSIONAL KATY, TX COMPANY"></div>
                <div class="form-group"><label>Heading</label><input type="text" name="sc_heading[]" placeholder="e.g. Local Experts in Katy, TX"></div>
                <div class="form-group"><label>Columns</label>
                    <select name="sc_cols[]"><option value="2">2</option><option value="3" selected>3</option><option value="4">4</option></select>
                </div>
                <div style="display:flex;gap:12px;flex-wrap:wrap;">
                    <div class="form-group" style="flex:1 1 140px;"><label>Badge color</label>
                        <select name="sc_badge_bg[]"><option value="accent" selected>Accent</option><option value="header">Header</option><option value="custom">Custom</option></select>
                        <input type="color" name="sc_badge_bg_custom[]" value="#fd783b" style="margin-top:4px;">
                    </div>
                    <div class="form-group" style="flex:1 1 140px;"><label>Heading color</label>
                        <select name="sc_head_color[]"><option value="header" selected>Header</option><option value="accent">Accent</option><option value="custom">Custom</option></select>
                        <input type="color" name="sc_head_color_custom[]" value="#120575" style="margin-top:4px;">
                    </div>
                    <div class="form-group" style="flex:1 1 140px;"><label>Icon circle bg</label>
                        <input type="color" name="sc_icon_bg[]" value="#fef0e7">
                    </div>
                </div>
                <div class="sc-items-editor" id="sc_items_new_${idx}"></div>
                <button type="button" class="btn btn-secondary btn-small" onclick="addScItem(this, 'new_${idx}')">+ Add card</button>
            </div>
            <div class="block-fields block-fields-hero_grid is-hidden">
                <div class="form-group"><label>Small label</label><input type="text" name="hg_label[]" placeholder="e.g. Katy Pest Pros"></div>
                <div class="form-group"><label>Heading</label><input type="text" name="hg_heading[]" placeholder="Section heading"></div>
                <div class="form-group"><label>Body text</label><textarea name="hg_body[]" rows="3"></textarea></div>
                <div style="display:flex;gap:12px;flex-wrap:wrap;">
                    <div class="form-group" style="flex:1 1 160px;"><label>Button text</label><input type="text" name="hg_btn_text[]"></div>
                    <div class="form-group" style="flex:1 1 160px;"><label>Button link</label><input type="text" name="hg_btn_url[]" placeholder="tel:+1..."></div>
                </div>
                <div class="form-group"><label>Background image</label>
                    <input type="file" name="hg_photo[]" accept="image/png,image/jpeg,image/gif,image/webp">
                    <input type="hidden" name="hg_photo_existing[]" value="">
                </div>
                <div class="form-group"><label>Odd tile color</label>
                    <select name="hg_color1[]"><option value="accent" selected>Accent (global)</option><option value="header">Header (global)</option><option value="custom">Custom</option></select>
                    <input type="color" name="hg_color1_custom[]" value="#fd783b" style="margin-top:4px;">
                </div>
                <div class="form-group"><label>Even tile color</label>
                    <select name="hg_color2[]"><option value="header" selected>Header (global)</option><option value="accent">Accent (global)</option><option value="custom">Custom</option></select>
                    <input type="color" name="hg_color2_custom[]" value="#120575" style="margin-top:4px;">
                </div>
                <div class="hg-items-editor" id="hg_items_new_${idx}"></div>
                <button type="button" class="btn btn-secondary btn-small" onclick="addHgItem(this, 'new_${idx}')">+ Add grid item</button>
            </div>
            <div class="block-fields block-fields-tab_services is-hidden">
                <div style="display:flex;gap:12px;flex-wrap:wrap;">
                    <div class="form-group" style="flex:1 1 180px;"><label>Badge 1</label><input type="text" name="ts_badge1[]" placeholder="KATY PEST PROS"></div>
                    <div class="form-group" style="flex:1 1 180px;"><label>Badge 2</label><input type="text" name="ts_badge2[]" placeholder="SERVICES KATY, TX"></div>
                </div>
                <div class="form-group"><label>Heading</label><input type="text" name="ts_heading[]" placeholder="Section heading"></div>
                <div class="form-group"><label>Active tab background</label>
                    <select name="ts_active_bg[]"><option value="header" selected>Header color (global)</option><option value="accent">Accent (global)</option><option value="custom">Custom</option></select>
                </div>
                <div class="form-group"><label>Custom active color</label><input type="color" name="ts_active_bg_custom[]" value="#120575"></div>
                <div class="ts-tabs-editor" id="ts_tabs_new_${idx}"></div>
                <button type="button" class="btn btn-secondary btn-small" onclick="addTsTab(this, 'new_${idx}')">+ Add tab</button>
            </div>
            <div class="block-fields block-fields-gallery is-hidden">
                <div class="form-group"><label>Section heading (optional)</label>
                    <input type="text" name="gallery_heading[]" placeholder="e.g. Gallery of Projects">
                </div>
                <div class="form-group"><label>Number of columns</label>
                    <select name="gallery_cols[]"><option value="2">2</option><option value="3" selected>3</option><option value="4">4</option></select>
                </div>
                <div class="gallery-images-editor" id="gallery_imgs_new_${idx}"></div>
                <button type="button" class="btn btn-secondary btn-small" onclick="addGalleryImg(this, 'new_${idx}')">+ Add image</button>
            </div>
            <div class="block-fields block-fields-steps is-hidden">
                <div class="form-group"><label>Section heading (optional)</label>
                    <input type="text" name="steps_heading[]" placeholder="e.g. Our Recovery Process">
                </div>
                <div class="steps-items-editor" id="steps_items_new_${idx}"></div>
                <button type="button" class="btn btn-secondary btn-small" onclick="addStepItem(this, 'new_${idx}')">+ Add step</button>
            </div>
            <div class="block-fields block-fields-stats is-hidden">
                <div class="form-group"><label>Section heading (optional)</label>
                    <input type="text" name="stats_heading[]" placeholder="e.g. Why Choose Us">
                </div>
                <div style="display:flex;gap:12px;flex-wrap:wrap;">
                    <div class="form-group" style="flex:1 1 160px;"><label>Background color</label>
                        <input type="color" name="stats_bg_color[]" value="#1e3a5f">
                    </div>
                    <div class="form-group" style="flex:1 1 160px;"><label>Text color</label>
                        <input type="color" name="stats_text_color[]" value="#ffffff">
                    </div>
                </div>
                <div class="stats-items-editor" id="stats_items_new_${idx}"></div>
                <button type="button" class="btn btn-secondary btn-small" onclick="addStatItem(this, 'new_${idx}')">+ Add stat</button>
            </div>
            <div class="block-fields block-fields-cards is-hidden">
                <div class="form-group"><label>Section heading (optional)</label>
                    <input type="text" name="cards_heading[]" placeholder="e.g. Our Services">
                </div>
                <div class="form-group"><label>Number of columns</label>
                    <select name="cards_cols[]"><option value="2">2</option><option value="3" selected>3</option><option value="4">4</option></select>
                </div>
                <div class="cards-items-editor" id="cards_items_new_${idx}"></div>
                <button type="button" class="btn btn-secondary btn-small" onclick="addCardItem(this, 'new_${idx}')">+ Add card</button>
            </div>
        `;
        container.appendChild(card);
        // Init TinyMCE on any new rich-editor textareas in this card
        if (typeof tinymce !== 'undefined') {
            card.querySelectorAll('.rich-editor').forEach(function(ta) {
                if (!tinymce.get(ta.id)) {
                    tinymce.init({
                        target: ta,
                        menubar: false,
                        plugins: 'link lists autolink',
                        toolbar: 'bold italic underline | link | bullist numlist | removeformat',
                        height: 200,
                        branding: false,
                        promotion: false,
                        statusbar: false,
                        skin: 'oxide',
                        content_css: false,
                        content_style: 'body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; font-size: 14px; color: #1a1a1a; margin: 8px; }',
                        setup: function(editor) { editor.on('change input', function() { editor.save(); }); }
                    });
                }
            });
        }
    }

    /* ---- Feature Columns helpers ---- */
    function addFcCol(btn, blockIdx) {
        const editor = document.getElementById('fc_cols_' + blockIdx);
        const row = document.createElement('div');
        row.className = 'fc-col-row';
        row.innerHTML = `
            <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-start;">
                <div class="form-group" style="flex:0 0 80px;">
                    <label>Icon/image</label>
                    <input type="file" name="fc_col_image[${blockIdx}][]" accept="image/png,image/jpeg,image/gif,image/webp" style="font-size:0.78rem;">
                    <input type="hidden" name="fc_col_image_existing[${blockIdx}][]" value="">
                </div>
                <div style="flex:1 1 160px;">
                    <div class="form-group"><label>Heading (H3)</label><input type="text" name="fc_col_heading[${blockIdx}][]" placeholder="e.g. Ants"></div>
                    <div class="form-group"><label>Alt text</label><input type="text" name="fc_col_alt[${blockIdx}][]" placeholder="Image description"></div>
                </div>
                <div style="flex:2 1 200px;">
                    <div class="form-group"><label>Description</label><textarea name="fc_col_text[${blockIdx}][]" rows="2"></textarea></div>
                </div>
                <button type="button" class="remove-row" onclick="removeFcCol(this)" style="align-self:flex-start;margin-top:24px;">&times;</button>
            </div>
        `;
        editor.appendChild(row);
    }
    function removeFcCol(btn) {
        btn.closest('.fc-col-row').remove();
    }

    /* ---- Feature Split helpers ---- */
    function addFsItem(btn, blockIdx) {
        const editor = document.getElementById('fs_items_' + blockIdx);
        const row = document.createElement('div');
        row.className = 'fs-item-row';
        row.style.cssText = 'border:1px solid #e5e7eb;border-radius:6px;padding:12px;margin-bottom:8px;background:#f9fafb;';
        row.innerHTML = `
            <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-start;">
                <div style="flex:0 0 90px;">
                    <div class="form-group"><label>Icon image</label>
                        <input type="file" name="fs_item_icon[${blockIdx}][]" accept="image/png,image/jpeg,image/gif,image/webp,image/svg+xml" style="font-size:0.75rem;">
                        <input type="hidden" name="fs_item_icon_existing[${blockIdx}][]" value="">
                    </div>
                    <div class="form-group"><label>Alt text</label>
                        <input type="text" name="fs_item_alt[${blockIdx}][]" placeholder="Icon description" style="font-size:0.8rem;">
                    </div>
                </div>
                <div style="flex:1 1 200px;">
                    <div class="form-group"><label>Heading</label>
                        <input type="text" name="fs_item_heading[${blockIdx}][]" placeholder="e.g. Ants">
                    </div>
                    <div class="form-group"><label>Description</label>
                        <textarea name="fs_item_text[${blockIdx}][]" rows="2"></textarea>
                    </div>
                </div>
                <button type="button" class="remove-row" onclick="removeFsItem(this)" style="align-self:flex-start;margin-top:22px;">&times;</button>
            </div>`;
        editor.appendChild(row);
    }
    function removeFsItem(btn) { btn.closest('.fs-item-row').remove(); }

    /* ---- Tab Services helpers ---- */
    function addTsTab(btn, blockIdx) {
        const editor = document.getElementById('ts_tabs_' + blockIdx);
        const row = document.createElement('div');
        row.className = 'ts-tab-row';
        row.style.cssText = 'border:1px solid #e5e7eb;border-radius:6px;padding:12px;margin-bottom:10px;background:#f9fafb;';
        row.innerHTML = `
            <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-start;">
                <div style="flex:0 0 110px;">
                    <div class="form-group"><label>Tab icon</label>
                        <input type="file" name="ts_tab_icon[${blockIdx}][]" accept="image/png,image/jpeg,image/gif,image/webp,image/svg+xml" style="font-size:0.75rem;">
                        <input type="hidden" name="ts_tab_icon_existing[${blockIdx}][]" value="">
                    </div>
                </div>
                <div style="flex:1 1 200px;">
                    <div class="form-group"><label>Tab label</label>
                        <input type="text" name="ts_tab_label[${blockIdx}][]" placeholder="e.g. Fleas">
                    </div>
                    <div class="form-group"><label>Description</label>
                        <textarea name="ts_tab_desc[${blockIdx}][]" rows="2"></textarea>
                    </div>
                </div>
                <div style="flex:0 0 130px;">
                    <div class="form-group"><label>Tab photo</label>
                        <input type="file" name="ts_tab_photo[${blockIdx}][]" accept="image/png,image/jpeg,image/gif,image/webp" style="font-size:0.75rem;">
                        <input type="hidden" name="ts_tab_photo_existing[${blockIdx}][]" value="">
                    </div>
                    <div class="form-group"><label>Photo alt text</label>
                        <input type="text" name="ts_tab_alt[${blockIdx}][]" placeholder="Alt text" style="font-size:0.8rem;">
                    </div>
                </div>
                <button type="button" class="remove-row" onclick="removeTsTab(this)" style="align-self:flex-start;margin-top:22px;">&times;</button>
            </div>`;
        editor.appendChild(row);
    }
    function removeTsTab(btn) { btn.closest('.ts-tab-row').remove(); }

    /* ---- Hero Grid helpers ---- */
    function addHgItem(btn, blockIdx) {
        const editor = document.getElementById('hg_items_' + blockIdx);
        const row = document.createElement('div');
        row.className = 'hg-item-row';
        row.style.cssText = 'display:flex;gap:10px;align-items:center;margin-bottom:8px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;padding:10px;';
        row.innerHTML = `
            <div style="flex:0 0 90px;">
                <label style="font-size:0.8rem;font-weight:600;">Icon</label>
                <input type="file" name="hg_item_icon[${blockIdx}][]" accept="image/png,image/jpeg,image/gif,image/webp,image/svg+xml" style="font-size:0.72rem;">
                <input type="hidden" name="hg_item_icon_existing[${blockIdx}][]" value="">
            </div>
            <div style="flex:1 1 160px;">
                <label style="font-size:0.8rem;font-weight:600;">Label</label>
                <input type="text" name="hg_item_label[${blockIdx}][]" placeholder="e.g. Carpenter Ants">
                <label style="font-size:0.8rem;font-weight:600;margin-top:4px;display:block;">Alt text</label>
                <input type="text" name="hg_item_alt[${blockIdx}][]" placeholder="Icon alt text" style="font-size:0.8rem;">
            </div>
            <button type="button" class="remove-row" onclick="removeHgItem(this)">&times;</button>`;
        editor.appendChild(row);
    }
    function removeHgItem(btn) { btn.closest('.hg-item-row').remove(); }

    /* ---- Service Cards helpers ---- */
    function addScItem(btn, blockIdx) {
        const editor = document.getElementById('sc_items_' + blockIdx);
        const row = document.createElement('div');
        row.className = 'sc-item-row';
        row.style.cssText = 'border:1px solid #e5e7eb;border-radius:6px;padding:12px;margin-bottom:8px;background:#f9fafb;';
        row.innerHTML = `
            <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-start;">
                <div style="flex:0 0 100px;">
                    <div class="form-group"><label>Icon image</label>
                        <input type="file" name="sc_item_icon[${blockIdx}][]" accept="image/png,image/jpeg,image/gif,image/webp,image/svg+xml" style="font-size:0.75rem;">
                        <input type="hidden" name="sc_item_icon_existing[${blockIdx}][]" value="">
                    </div>
                    <div class="form-group"><label>Alt text</label>
                        <input type="text" name="sc_item_alt[${blockIdx}][]" style="font-size:0.8rem;">
                    </div>
                </div>
                <div style="flex:1 1 220px;">
                    <div class="form-group"><label>Card heading</label>
                        <input type="text" name="sc_item_heading[${blockIdx}][]" placeholder="e.g. Roach Control & Extermination">
                    </div>
                    <div class="form-group"><label>Description</label>
                        <textarea name="sc_item_text[${blockIdx}][]" rows="2"></textarea>
                    </div>
                </div>
                <button type="button" class="remove-row" onclick="removeScItem(this)" style="align-self:flex-start;margin-top:22px;">&times;</button>
            </div>`;
        editor.appendChild(row);
    }
    function removeScItem(btn) { btn.closest('.sc-item-row').remove(); }

    /* ---- Image Features helpers ---- */
    function addIfFeat(btn, blockIdx) {
        const editor = document.getElementById('if_feats_' + blockIdx);
        const row = document.createElement('div');
        row.className = 'if-feat-row';
        row.style.cssText = 'display:flex;gap:8px;margin-bottom:6px;';
        row.innerHTML = `
            <input type="text" name="if_features[${blockIdx}][]" placeholder="e.g. Exterior treatments" style="flex:1;">
            <button type="button" class="remove-row" onclick="removeIfFeat(this)">&times;</button>`;
        editor.appendChild(row);
    }
    function removeIfFeat(btn) { btn.closest('.if-feat-row').remove(); }

    /* ---- FAQ Two Col item helpers ---- */
    function addFqItem(btn, blockIdx) {
        const editor = document.getElementById('fq_items_' + blockIdx);
        const row = document.createElement('div');
        row.className = 'fq-item-row';
        row.style.cssText = 'border:1px solid #e5e7eb;border-radius:6px;padding:12px;margin-bottom:8px;background:#f9fafb;';
        row.innerHTML = `
            <div class="form-group"><label>Question</label>
                <input type="text" name="fq_question[${blockIdx}][]" placeholder="e.g. What types of pests do you treat?">
            </div>
            <div class="form-group"><label>Answer</label>
                <textarea name="fq_answer[${blockIdx}][]" rows="2"></textarea>
            </div>
            <button type="button" class="remove-row btn-secondary btn-small" onclick="removeFqItem(this)" style="margin-bottom:4px;">Remove Q&A</button>`;
        editor.appendChild(row);
    }
    function removeFqItem(btn) { btn.closest('.fq-item-row').remove(); }

    /* ---- Links Grid helpers ---- */
    function addLgLink(btn, blockIdx) {
        const editor = document.getElementById('lg_links_' + blockIdx);
        const row = document.createElement('div');
        row.className = 'lg-link-row';
        row.style.cssText = 'display:flex;gap:8px;margin-bottom:6px;align-items:center;';
        row.innerHTML = `
            <input type="text" name="lg_link_label[${blockIdx}][]" placeholder="Link text" style="flex:1;">
            <input type="text" name="lg_link_url[${blockIdx}][]"   placeholder="URL e.g. /service-page" style="flex:1;">
            <button type="button" class="remove-row" onclick="removeLgLink(this)">&times;</button>`;
        editor.appendChild(row);
    }
    function removeLgLink(btn) { btn.closest('.lg-link-row').remove(); }
    function bulkAddLgLinks(blockIdx) {
        const textarea = document.getElementById('lg_bulk_' + blockIdx);
        const editor   = document.getElementById('lg_links_' + blockIdx);
        if (!textarea || !editor) return;
        const lines = textarea.value.split('\n').map(l => l.trim()).filter(Boolean);
        lines.forEach(function(label) {
            const row = document.createElement('div');
            row.className = 'lg-link-row';
            row.style.cssText = 'display:flex;gap:8px;margin-bottom:6px;align-items:center;';
            row.innerHTML = `
                <input type="text" name="lg_link_label[${blockIdx}][]" value="${label.replace(/"/g,'&quot;')}" style="flex:1;">
                <input type="text" name="lg_link_url[${blockIdx}][]" placeholder="/url" style="flex:1;">
                <button type="button" class="remove-row" onclick="removeLgLink(this)">&times;</button>`;
            editor.appendChild(row);
        });
        textarea.value = '';
    }

    /* ---- Tab Services frontend switcher ---- */
    function switchTab(btn) {
        const uid = btn.dataset.uid;
        const layout = document.getElementById(uid);
        if (!layout) return;
        const activeBg = btn.dataset.activeBg || 'var(--color-header-bg,#120575)';
        // Reset all tabs
        layout.querySelectorAll('.ts-tab').forEach(function(t) {
            t.classList.remove('ts-tab-active');
            t.style.background = '';
            t.style.color = '';
        });
        // Reset all panels
        layout.querySelectorAll('.ts-panel').forEach(function(p) { p.setAttribute('hidden',''); });
        // Activate clicked tab
        btn.classList.add('ts-tab-active');
        btn.style.background = activeBg;
        btn.style.color = '#fff';
        const panel = layout.querySelector('.ts-panel[data-panel="' + btn.dataset.tab + '"]');
        if (panel) panel.removeAttribute('hidden');
    }

    /* ---- FAQ helpers ---- */
    function addFaqItem(btn, blockIdx) {
        const editor = document.getElementById('faq_items_' + blockIdx);
        const row = document.createElement('div');
        row.className = 'faq-item-row';
        row.innerHTML = `
            <div class="form-group"><label>Question</label><input type="text" name="faq_question[${blockIdx}][]" placeholder="e.g. How much does it cost?"></div>
            <div class="form-group"><label>Answer</label><textarea name="faq_answer[${blockIdx}][]" rows="2"></textarea></div>
            <button type="button" class="remove-row btn-secondary btn-small" onclick="removeFaqItem(this)" style="margin-bottom:12px;">Remove Q&amp;A</button>
        `;
        editor.appendChild(row);
    }
    function removeFaqItem(btn) {
        btn.closest('.faq-item-row').remove();
    }

    /* ---- Gallery helpers ---- */
    function addGalleryImg(btn, blockIdx) {
        const editor = document.getElementById('gallery_imgs_' + blockIdx);
        const row = document.createElement('div');
        row.className = 'gallery-img-row';
        row.innerHTML = `
            <div style="display:flex;gap:10px;align-items:flex-start;flex-wrap:wrap;">
                <div style="flex:1 1 200px;">
                    <div class="form-group"><label>Image</label>
                        <input type="file" name="gallery_photo[${blockIdx}][]" accept="image/png,image/jpeg,image/gif,image/webp">
                        <input type="hidden" name="gallery_photo_existing[${blockIdx}][]" value="">
                    </div>
                    <div class="form-group"><label>Alt text</label>
                        <input type="text" name="gallery_alt[${blockIdx}][]" placeholder="Describe the photo for SEO">
                    </div>
                </div>
                <button type="button" class="remove-row" onclick="removeGalleryImg(this)" style="margin-top:24px;">&times;</button>
            </div>`;
        editor.appendChild(row);
    }
    function removeGalleryImg(btn) { btn.closest('.gallery-img-row').remove(); }

    /* ---- Steps helpers ---- */
    function addStepItem(btn, blockIdx) {
        const editor = document.getElementById('steps_items_' + blockIdx);
        const row = document.createElement('div');
        row.className = 'step-item-row';
        row.innerHTML = `
            <div style="display:flex;gap:10px;align-items:flex-start;flex-wrap:wrap;">
                <div style="flex:0 0 100px;">
                    <div class="form-group"><label>Icon/image (optional)</label>
                        <input type="file" name="steps_image[${blockIdx}][]" accept="image/png,image/jpeg,image/gif,image/webp" style="font-size:0.78rem;">
                        <input type="hidden" name="steps_image_existing[${blockIdx}][]" value="">
                    </div>
                    <div class="form-group"><label>Alt text</label>
                        <input type="text" name="steps_alt[${blockIdx}][]" placeholder="Step icon description" style="font-size:0.82rem;">
                    </div>
                </div>
                <div style="flex:1 1 200px;">
                    <div class="form-group"><label>Step heading</label>
                        <input type="text" name="steps_heading_item[${blockIdx}][]" placeholder="e.g. Call Us">
                    </div>
                    <div class="form-group"><label>Description</label>
                        <textarea name="steps_text[${blockIdx}][]" rows="2"></textarea>
                    </div>
                </div>
                <button type="button" class="remove-row" onclick="removeStepItem(this)" style="margin-top:24px;">&times;</button>
            </div>`;
        editor.appendChild(row);
    }
    function removeStepItem(btn) { btn.closest('.step-item-row').remove(); }

    /* ---- Stats helpers ---- */
    function addStatItem(btn, blockIdx) {
        const editor = document.getElementById('stats_items_' + blockIdx);
        const row = document.createElement('div');
        row.className = 'stat-item-row';
        row.innerHTML = `
            <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                <div class="form-group" style="flex:1 1 120px;"><label>Number / value</label>
                    <input type="text" name="stats_number[${blockIdx}][]" placeholder="e.g. 5,200+">
                </div>
                <div class="form-group" style="flex:2 1 200px;"><label>Label</label>
                    <input type="text" name="stats_label[${blockIdx}][]" placeholder="e.g. Jobs Completed">
                </div>
                <button type="button" class="remove-row" onclick="removeStatItem(this)">&times;</button>
            </div>`;
        editor.appendChild(row);
    }
    function removeStatItem(btn) { btn.closest('.stat-item-row').remove(); }

    /* ---- Cards helpers ---- */
    function addCardItem(btn, blockIdx) {
        const editor = document.getElementById('cards_items_' + blockIdx);
        const row = document.createElement('div');
        row.className = 'card-item-row';
        row.innerHTML = `
            <div style="display:flex;gap:10px;align-items:flex-start;flex-wrap:wrap;border:1px solid #e5e7eb;border-radius:6px;padding:12px;margin-bottom:8px;background:#f9fafb;">
                <div style="flex:0 0 100px;">
                    <div class="form-group"><label>Image</label>
                        <input type="file" name="cards_image[${blockIdx}][]" accept="image/png,image/jpeg,image/gif,image/webp" style="font-size:0.78rem;">
                        <input type="hidden" name="cards_image_existing[${blockIdx}][]" value="">
                    </div>
                    <div class="form-group"><label>Alt text</label>
                        <input type="text" name="cards_alt[${blockIdx}][]" placeholder="Image description" style="font-size:0.82rem;">
                    </div>
                </div>
                <div style="flex:1 1 200px;">
                    <div class="form-group"><label>Heading</label>
                        <input type="text" name="cards_heading_item[${blockIdx}][]" placeholder="e.g. Water Damage Repair">
                    </div>
                    <div class="form-group"><label>Description</label>
                        <textarea name="cards_text[${blockIdx}][]" rows="2"></textarea>
                    </div>
                    <div style="display:flex;gap:8px;flex-wrap:wrap;">
                        <div class="form-group" style="flex:1 1 140px;"><label>Link URL</label>
                            <input type="text" name="cards_link[${blockIdx}][]" placeholder="/service-page">
                        </div>
                        <div class="form-group" style="flex:1 1 100px;"><label>Button text</label>
                            <input type="text" name="cards_btn[${blockIdx}][]" placeholder="Read More" value="Read More">
                        </div>
                    </div>
                </div>
                <button type="button" class="remove-row" onclick="removeCardItem(this)" style="align-self:flex-start;">&times;</button>
            </div>`;
        editor.appendChild(row);
    }
    function removeCardItem(btn) { btn.closest('.card-item-row').remove(); }

    /* ---- FAQ Two Col frontend toggle ---- */
    function toggleFq(id) {
        var answer = document.getElementById(id);
        var btn = answer ? answer.previousElementSibling : null;
        if (!answer) return;
        var isHidden = answer.hasAttribute('hidden');
        answer.toggleAttribute('hidden', !isHidden);
        if (btn) {
            btn.setAttribute('aria-expanded', isHidden ? 'true' : 'false');
            var icon = btn.querySelector('.fq-icon');
            if (icon) icon.textContent = isHidden ? '−' : '+';
        }
    }

    /* ---- FAQ frontend toggle ---- */
    function toggleFaq(id) {
        const answer = document.getElementById(id);
        const btn = answer ? answer.previousElementSibling : null;
        if (!answer) return;
        const isHidden = answer.hasAttribute('hidden');
        answer.toggleAttribute('hidden', !isHidden);
        if (btn) {
            btn.setAttribute('aria-expanded', isHidden ? 'true' : 'false');
            const icon = btn.querySelector('.faq-icon');
            if (icon) icon.textContent = isHidden ? '−' : '+';
        }
    }
    </script>
    <?php
}
