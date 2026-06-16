<?php
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
    $html .= '<img src="' . h($pathPrefix . $photo) . '" alt="' . $altAttr . '" loading="lazy" style="object-position:' . h($position) . ';">';
    $html .= '</div>';
    return $html;
}

function get_focal_point(string $url): string {
    static $index = null;
    if ($index === null) {
        $path  = defined('BASE_DIR') ? BASE_DIR . '/data/media.json' : '';
        $items = ($path && file_exists($path)) ? (json_decode(file_get_contents($path), true) ?? []) : [];
        $index = [];
        foreach ($items as $item) {
            if (!empty($item['url'])) $index[$item['url']] = $item;
        }
    }
    $item = $index[$url] ?? null;
    if (!$item || !isset($item['focal_x'])) return '50% 50%';
    return round($item['focal_x'], 1) . '% ' . round($item['focal_y'], 1) . '%';
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
        'hero_split'      => 'Hero Split (default image right)',
        'feature_split'   => 'Feature Split (default image left, icon grid right)',
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
    $block = apply_shortcodes_to_block($block);
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
            $layout   = ($type === 'image_left') ? 'image-left' : 'image-right';
            $irLayout = $block['ir_layout'] ?? 'side';
            $extraClass = ($irLayout === 'stacked') ? ' layout-stacked' : '';
            echo '<div class="content-block ' . $layout . $extraClass . '"' . $anchorAttr . '>';
            if ($type === 'image_left' && $photo && $irLayout !== 'stacked') echo render_content_photo($photo, $ratio, $position, $alt, $pathPrefix);
            echo '<div class="content-text">' . text_to_html($text) . '</div>';
            if ($photo && ($type === 'image_right' || $irLayout === 'stacked')) echo render_content_photo($photo, $ratio, $position, $alt, $pathPrefix);
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
            if ($bgImage) $style .= 'background-image:url(' . h($pathPrefix . $bgImage) . ');background-size:cover;background-position:'.h(get_focal_point($bgImage)).';';
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
            $bgPhoto    = $block['hs_bg_photo']    ?? '';
            $imgSide      = $block['hs_image_side']    ?? 'right';
            $mobileOrder  = $block['hs_mobile_order']  ?? '';
            $anchorOut    = $anchorAttr;
            if ($bgPhoto) {
                $bgPhotoSrc = (str_starts_with($bgPhoto, 'http') || str_starts_with($bgPhoto, '//'))
                    ? $bgPhoto : $pathPrefix . $bgPhoto;
                $bgStyle = 'background:'.h($bgColor).';background-image:url('.h($bgPhotoSrc).');background-size:cover;background-position:'.h(get_focal_point($bgPhoto)).';';
            } else {
                $bgStyle = 'background:'.h($bgColor).';';
            }
            $hsExtraClass = $mobileOrder === 'img_first' ? ' hs-mobile-img-first' : ($mobileOrder === 'text_first' ? ' hs-mobile-text-first' : '');
            echo '<div class="content-block block-hero-split"'.$anchorOut.' style="'.$bgStyle.'">';
            echo '<div class="container hs-inner'.$hsExtraClass.'">';
            // Build image column HTML
            $hsImgCol = '';
            if ($photo) {
                $photoSrc = (str_starts_with($photo, 'http') || str_starts_with($photo, '//'))
                    ? $photo : $pathPrefix . $photo;
                $hsImgCol .= '<div class="hs-image-wrap">';
                $hsImgCol .= '<img src="'.h($photoSrc).'" alt="'.h(resolve_shortcodes($photoAlt)).'" class="hs-image" style="object-position:'.h(get_focal_point($photo)).';">';
                if ($caption1 || $caption2) {
                    $hsImgCol .= '<div class="hs-caption">';
                    if ($caption1) $hsImgCol .= '<div class="hs-caption-title">'.h($caption1).'</div>';
                    if ($caption2) $hsImgCol .= '<div class="hs-caption-sub">'.h($caption2).'</div>';
                    $hsImgCol .= '</div>';
                }
                $hsImgCol .= '</div>';
            }
            if ($imgSide === 'left') echo $hsImgCol;
            // Text column
            echo '<div class="hs-text">';
            if ($heading) echo '<h1 class="hs-heading">'.h($heading).'</h1>';
            if ($subtext) echo '<div class="hs-subtext">'.resolve_shortcodes($subtext).'</div>';
            if ($btnText) echo '<a href="'.h($btnUrl).'" class="hs-btn">'.h($btnText).'</a>';
            echo '</div>';
            if ($imgSide !== 'left') echo $hsImgCol;
            echo '</div></div>';
            break;

        /* ---- FEATURE SPLIT (icon grid left, arched image right) ---- */
        case 'feature_split':
            $heading     = $block['fs_heading']    ?? '';
            $subtext     = $block['fs_subtext']    ?? '';
            $photo       = $block['fs_photo']      ?? '';
            $photoAlt    = $block['fs_photo_alt']  ?? '';
            $starText    = $block['fs_star_text']  ?? '';
            $bgColor     = $block['fs_bg_color']   ?? '#f3f6f7';
            $accentColor = $block['fs_accent']     ?? '#fd783b';
            $items       = $block['fs_items']      ?? [];
            $imgSide     = $block['fs_image_side']   ?? 'right';
            $fsMobOrder  = $block['fs_mobile_order'] ?? '';
            $photoSrc    = '';
            if ($photo) {
                $photoSrc = (str_starts_with($photo, 'http') || str_starts_with($photo, '//'))
                    ? $photo : $pathPrefix . $photo;
            }
            $hasIcons = !empty(array_filter($items, fn($i) => !empty($i['icon'])));

            $imgCol = '<div class="fs-right">';
            if ($photoSrc) $imgCol .= '<div class="fs-arch-wrap"><img src="'.h($photoSrc).'" alt="'.h($photoAlt).'" class="fs-arch-img" loading="lazy" style="object-position:'.h(get_focal_point($photo)).';"></div>';
            if ($starText) $imgCol .= '<div class="fs-star-badge"><span class="fs-stars">★★★★★</span><span class="fs-star-text">'.h($starText).'</span></div>';
            $imgCol .= '</div>';

            $fsMobClass = $fsMobOrder === 'img_first' ? ' fs-mobile-img-first' : ($fsMobOrder === 'text_first' ? ' fs-mobile-text-first' : '');
            $wrapClass = ($imgSide === 'left' ? 'fs-inner fs-img-left' : 'fs-inner') . $fsMobClass;
            echo '<div class="content-block block-feature-split"'.$anchorAttr.' style="background:'.h($bgColor).';">';
            echo '<div class="container '.$wrapClass.'">';

            if ($imgSide === 'left') echo $imgCol;

            // Content column
            echo '<div class="fs-left">';
            if ($heading) echo '<h2 class="fs-heading block-heading">'.h($heading).'</h2>';
            if ($subtext) echo '<p class="fs-subtext">'.h($subtext).'</p>';

            if ($hasIcons) {
                // Icon card grid
                echo '<div class="fs-grid">';
                foreach ($items as $item) {
                    $iIcon = $item['icon'] ?? ''; $iHead = $item['heading'] ?? '';
                    $iText = $item['text'] ?? ''; $iAlt  = $item['alt'] ?? '';
                    $iIconSrc = $iIcon ? ((str_starts_with($iIcon,'http')||str_starts_with($iIcon,'//')) ? $iIcon : $pathPrefix.$iIcon) : '';
                    echo '<div class="fs-item">';
                    if ($iIconSrc) echo '<img class="fs-item-icon" src="'.h($iIconSrc).'" alt="'.h($iAlt).'" loading="lazy">';
                    echo '<div class="fs-item-body">';
                    if ($iHead) echo '<h3 class="fs-item-heading" style="color:'.h($accentColor).';">'.h($iHead).'</h3>';
                    if ($iText) echo '<p class="fs-item-text">'.h($iText).'</p>';
                    echo '</div></div>';
                }
                echo '</div>';
            } else {
                // Bullet list (no icons)
                echo '<ul class="fs-bullet-list">';
                foreach ($items as $item) {
                    $iHead = $item['heading'] ?? ''; $iText = $item['text'] ?? '';
                    echo '<li>';
                    if ($iHead) echo '<strong>'.h($iHead).'</strong>';
                    if ($iHead && $iText) echo ' – ';
                    if ($iText) echo h($iText);
                    echo '</li>';
                }
                echo '</ul>';
            }


            echo '</div>';

            if ($imgSide !== 'left') echo $imgCol;

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
                if ($colImg) echo '<img class="feature-icon" src="' . h($pathPrefix . $colImg) . '" alt="' . h($colAlt) . '" loading="lazy">';
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
            if ($infoPhotoSrc) echo '<img src="'.h($infoPhotoSrc).'" alt="'.h($infoAlt).'" class="mi-photo" loading="lazy">';
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
            echo '<div class="container">';
            if ($text)    echo '<p class="cb-text" style="color:'.h($textColor).';">'.h($text).'</p>';
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
                echo '<img src="'.h($photoSrc).'" alt="'.h(resolve_shortcodes($photoAlt)).'" class="if-photo" loading="lazy" style="object-position:'.h(get_focal_point($photo)).';">';
                echo '</div>';
            }

            // RIGHT: content
            echo '<div class="if-content">';
            if ($heading) echo '<h2 class="if-heading" style="color:'.$headStyle.';">'.h($heading).'</h2>';
            if ($intro)   echo '<div class="if-intro">'.resolve_shortcodes($intro).'</div>';

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

            if ($closing) echo '<p class="if-closing">'.h(resolve_shortcodes($closing)).'</p>';

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
            $subtext    = $block['wb_subtext']    ?? '';
            $btnText    = $block['wb_btn_text']   ?? '';
            $btnUrl     = $block['wb_btn_url']    ?? '#';
            $photo      = $block['wb_photo']      ?? '';
            $photoAlt   = $block['wb_photo_alt']  ?? '';
            $overlayOpacity = $block['wb_overlay'] ?? '0.55';
            $badgeBg    = $block['wb_badge_bg']   ?? 'accent';
            $badgeBgC   = $block['wb_badge_bg_custom'] ?? '#fd783b';
            $btnStyle   = $block['wb_btn_style']  ?? 'filled';

            $badgeBgStyle = resolve_color($badgeBg, $badgeBgC);

            $bgColor    = $block['wb_bg_color'] ?? '';

            $photoSrc = '';
            if ($photo) {
                $photoSrc = (str_starts_with($photo,'http') || str_starts_with($photo,'//'))
                    ? $photo : $pathPrefix.$photo;
            }

            if ($photoSrc) {
                $bgStyle = 'background-image:url('.h($photoSrc).');background-size:cover;background-position:'.h(get_focal_point($photo)).';';
                $overlayStyle = 'background:rgba(0,0,0,'.h($overlayOpacity).');';
            } elseif ($bgColor) {
                $bgStyle = 'background:'.h($bgColor).';';
                $overlayStyle = '';
            } else {
                $bgStyle = 'background:#1a1a2e;';
                $overlayStyle = 'background:rgba(0,0,0,'.h($overlayOpacity).');';
            }

            $wbCentered = ($block['wb_centered'] ?? false) || !$btnText;
            echo '<div class="content-block block-wide-banner"'.$anchorAttr.' style="'.$bgStyle.'">';
            echo '<div class="wb-overlay"'.($overlayStyle ? ' style="'.$overlayStyle.'"' : '').'>';
            echo '<div class="container wb-inner'.($wbCentered ? ' wb-centered' : '').'">';

            // Left: badge + heading + subtext
            echo '<div class="wb-left">';
            if ($badge)   echo '<span class="wb-badge" style="background:'.$badgeBgStyle.';color:#fff;">'.h($badge).'</span>';
            if ($heading) echo '<h2 class="wb-heading">'.h($heading).'</h2>';
            if ($subtext) echo '<p class="wb-subtext">'.h($subtext).'</p>';
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
                $iIcon  = $item['icon']    ?? '';
                $iAlt   = $item['alt']     ?? '';
                $iHead  = $item['heading'] ?? '';
                $iText  = $item['text']    ?? '';
                $iUrl   = $item['url']     ?? '';
                $iIconSrc = '';
                if ($iIcon) $iIconSrc = (str_starts_with($iIcon,'http') || str_starts_with($iIcon,'//')) ? $iIcon : $pathPrefix.$iIcon;
                $tag    = $iUrl ? 'a' : 'div';
                $tagAttr = $iUrl ? ' href="'.h($iUrl).'"' : '';
                echo '<'.$tag.' class="svc-card"'.$tagAttr.'>';
                if ($iIconSrc) {
                    echo '<div class="svc-icon-wrap" style="background:'.h($iconBg).';"><img src="'.h($iIconSrc).'" alt="'.h($iAlt).'" class="svc-icon" loading="lazy"></div>';
                }
                if ($iHead) echo '<h3 class="svc-card-heading">'.h($iHead).'</h3>';
                if ($iText) echo '<p class="svc-card-text">'.h($iText).'</p>';
                echo '</'.$tag.'>';
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
            echo '<div class="hg-left" style="'.($photoSrc ? 'background-image:url('.h($photoSrc).');background-size:cover;background-position:'.h(get_focal_point($photo)).';' : 'background:#1a1a2e;').'">';
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
                    echo '<img src="'.h($iIconSrc).'" alt="'.h($iAlt).'" class="hg-tile-icon" loading="lazy">';
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
                if ($iconSrc) echo '<img src="'.h($iconSrc).'" class="ts-tab-icon" alt="'.h($label).'" loading="lazy">';
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
            // HowTo schema
            if ($heading && !empty($items)) {
                $howToSteps = [];
                foreach ($items as $n => $step) {
                    $sHead = trim($step['heading'] ?? '');
                    $sText = trim($step['text']    ?? '');
                    if ($sHead || $sText) {
                        $howToSteps[] = ['@type' => 'HowToStep', 'name' => $sHead, 'text' => $sText, 'position' => $n + 1];
                    }
                }
                if ($howToSteps) {
                    $howTo = ['@context' => 'https://schema.org', '@type' => 'HowTo', 'name' => resolve_shortcodes($heading), 'step' => $howToSteps];
                    echo '<script type="application/ld+json">' . json_encode($howTo, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>' . "\n";
                }
            }
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
                    echo '<img class="step-icon" src="' . h($pathPrefix . $stepImg) . '" alt="' . h($stepAlt) . '" loading="lazy">';
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
            $heading      = $block['cards_heading']               ?? '';
            $cols         = max(2, min(4, (int)($block['cards_cols'] ?? 3)));
            $items        = $block['cards_items']                 ?? [];
            $bgColor      = $block['cards_bg']                    ?? '';
            $headColor    = $block['cards_head_color']            ?? '';
            $headColorC   = $block['cards_head_color_custom']     ?? '#1a1a2e';
            $cardBg       = $block['cards_card_bg']               ?? '';
            $itemHeadC    = $block['cards_item_head_color']       ?? '';
            $itemHeadCC   = $block['cards_item_head_color_custom'] ?? '#1a1a2e';
            $textColor    = $block['cards_text_color']            ?? '';

            $blockStyle    = $bgColor    ? ' style="background:'.h($bgColor).';"' : '';
            $headStyle     = $headColor  ? ' style="color:'.resolve_color($headColor, $headColorC).';"' : '';
            $itemHeadStyle = $itemHeadC  ? ' style="color:'.resolve_color($itemHeadC, $itemHeadCC).';"' : '';
            $textStyle     = $textColor  ? ' style="color:'.h($textColor).';"' : '';
            $cardBgStyle   = $cardBg     ? ' style="background:'.h($cardBg).';"' : '';

            echo '<div class="content-block block-cards"' . $anchorAttr . $blockStyle . '>';
            if ($heading) echo '<h2 class="section-heading"' . $headStyle . '>' . h($heading) . '</h2>';
            echo '<div class="cards-grid cards-grid-' . $cols . '">';
            foreach ($items as $card) {
                $cardImg  = $card['image']    ?? '';
                $cardAlt  = $card['alt']      ?? '';
                $cardHead = $card['heading']  ?? '';
                $cardText = $card['text']     ?? '';
                $cardLink = $card['link']     ?? '';
                $cardBtn  = $card['btn_text'] ?? 'Read More';
                echo '<div class="card-item"' . $cardBgStyle . '>';
                if ($cardImg) echo '<img class="card-image" src="' . h($pathPrefix . $cardImg) . '" alt="' . h($cardAlt) . '" loading="lazy">';
                echo '<div class="card-body">';
                if ($cardHead) echo '<h3 class="card-heading"' . $itemHeadStyle . '>' . h($cardHead) . '</h3>';
                if ($cardText) echo '<p class="card-text"' . $textStyle . '>' . h($cardText) . '</p>';
                if ($cardLink) echo '<a href="' . h($cardLink) . '" class="card-link">' . h($cardBtn) . '</a>';
                echo '</div></div>';
            }
            echo '</div></div>';
            break;

        /* ---- POST META (byline / date / tag / featured image — blog posts only) ---- */
        case 'post_meta':
            $postHeading = $block['title']      ?? '';
            $author      = $block['author']     ?? '';
            $publishedAt = $block['published_at'] ?? '';
            $tag         = $block['tag']        ?? '';
            $featImg     = $block['featured_image'] ?? '';
            $featAlt     = $block['featured_image_alt'] ?? '';
            echo '<div class="content-block block-post-meta">';
            if ($postHeading) echo '<h1 class="post-meta-heading">' . h($postHeading) . '</h1>';
            echo '<div class="post-meta-row">';
            if ($publishedAt) echo '<span class="post-meta-date">' . h($publishedAt) . '</span>';
            if ($author)      echo '<span class="post-meta-author">By ' . h($author) . '</span>';
            if ($tag)         echo '<a class="post-meta-tag" href="/blog?tag=' . h(slugify($tag)) . '">' . h($tag) . '</a>';
            echo '</div>';
            if ($featImg) {
                $featSrc = (str_starts_with($featImg,'http') || str_starts_with($featImg,'//')) ? $featImg : $pathPrefix . $featImg;
                echo '<img class="post-meta-image" src="' . h($featSrc) . '" alt="' . h($featAlt) . '" loading="lazy">';
            }
            echo '</div>';
            break;

        /* ---- BLOG LIST (post cards grid + tag filter + pagination) ---- */
        case 'blog_list':
            $blHeading  = $block['heading']  ?? '';
            $blIntro    = $block['intro']    ?? '';
            $blPosts    = $block['posts']    ?? [];
            $activeTag      = $block['active_tag']       ?? '';
            $activeTagLabel = $block['active_tag_label'] ?? '';
            $allTags        = $block['all_tags']          ?? [];
            $pagination     = $block['pagination']        ?? null;
            echo '<div class="content-block block-blog-list"><div class="container">';
            if ($blHeading) echo '<h1 class="blog-list-heading">' . h($blHeading) . '</h1>';
            if ($blIntro)   echo '<p class="blog-list-intro">' . h($blIntro) . '</p>';
            if (!empty($allTags)) {
                echo '<div class="blog-tags-bar">';
                echo '<a class="blog-tag-pill' . ($activeTag === '' ? ' is-active' : '') . '" href="/blog">All</a>';
                foreach ($allTags as $t) {
                    $tSlug = slugify($t);
                    $isActive = ($activeTag !== '' && slugify($activeTag) === $tSlug);
                    echo '<a class="blog-tag-pill' . ($isActive ? ' is-active' : '') . '" href="/blog?tag=' . h($tSlug) . '">' . h($t) . '</a>';
                }
                echo '</div>';
            }
            if (empty($blPosts)) {
                echo '<p class="blog-empty">No posts yet.</p>';
            } else {
                echo '<div class="blog-card-grid">';
                foreach ($blPosts as $bp) {
                    $bpImg = $bp['featured_image'] ?? '';
                    echo '<a class="blog-card" href="/blog/' . h($bp['slug'] ?? '') . '">';
                    if ($bpImg) {
                        $bpSrc = (str_starts_with($bpImg,'http') || str_starts_with($bpImg,'//')) ? $bpImg : $pathPrefix . $bpImg;
                        echo '<img class="blog-card-image" src="' . h($bpSrc) . '" alt="' . h($bp['featured_image_alt'] ?? '') . '" loading="lazy">';
                    }
                    echo '<div class="blog-card-body">';
                    if (!empty($bp['published_at']) || !empty($bp['tag'])) {
                        echo '<div class="blog-card-meta-row">';
                        if (!empty($bp['published_at'])) echo '<span class="blog-card-date">' . h($bp['published_at']) . '</span>';
                        if (!empty($bp['tag']))          echo '<span class="blog-card-tag">' . h($bp['tag']) . '</span>';
                        echo '</div>';
                    }
                    echo '<h2 class="blog-card-heading">' . h($bp['title'] ?? '') . '</h2>';
                    if (!empty($bp['excerpt'])) echo '<p class="blog-card-excerpt">' . h($bp['excerpt']) . '</p>';
                    echo '</div></a>';
                }
                echo '</div>';
            }
            if ($pagination && (int)$pagination['total'] > 1) {
                echo '<div class="blog-pagination">';
                for ($pg = 1; $pg <= (int)$pagination['total']; $pg++) {
                    $sep = str_contains($pagination['base_url'], '?') ? '&' : '?';
                    $url = $pg > 1 ? $pagination['base_url'] . $sep . 'p=' . $pg : $pagination['base_url'];
                    $cls = $pg === (int)$pagination['current'] ? ' blog-page-active' : '';
                    echo '<a class="blog-page-link' . $cls . '" href="' . h($url) . '">' . $pg . '</a>';
                }
                echo '</div>';
            }
            echo '</div></div>';
            break;

        default:
            break;
    }
}
