        $pageId = trim($_POST['page_id'] ?? '');
        $isLandingPage = ($pageId !== '' && isset($data['pages'][$pageId]));
        $postId = trim($_POST['post_id'] ?? '');
        $isPost = (!$isLandingPage && $postId !== '' && isset($data['posts'][$postId]));
        $activeTab = $isLandingPage ? 'pages' : ($isPost ? 'blog' : 'content');

        $types = $_POST['block_type'] ?? [];
        $blocks = [];
        $uploadError = false;

        foreach ($types as $i => $type) {
            if (!array_key_exists($type, allowed_block_types())) $type = 'text';
            $block = ['type' => $type];

            // Anchor ID — sanitize to safe slug characters only
            $rawAnchor = trim($_POST['block_anchor'][$i] ?? '');
            if ($rawAnchor !== '') {
                $block['anchor'] = preg_replace('/[^a-zA-Z0-9_-]/', '', $rawAnchor);
            }

            switch ($type) {

                case 'text':
                    $block['heading_level'] = in_array($_POST['block_heading_level'][$i] ?? '', array_keys(heading_level_options()))
                        ? $_POST['block_heading_level'][$i] : 'h2';
                    $block['text'] = trim($_POST['block_text'][$i] ?? '');
                    if ($block['text'] === '') continue 2;
                    break;

                case 'image_left':
                case 'image_right':
                    $block['ir_layout'] = ($_POST['ir_layout'][$i] ?? 'side') === 'stacked' ? 'stacked' : 'side';
                    $block['text']  = trim($_POST['block_text'][$i] ?? '');
                    $block['photo'] = trim($_POST['block_existing_photo'][$i] ?? '');
                    $block['photo_alt'] = trim($_POST['block_photo_alt'][$i] ?? '');
                    if (!empty($_POST['block_remove_photo'][$i])) $block['photo'] = '';
                    $up = upload_image_indexed('block_photo', $i, 'block');
                    if ($up === false) $uploadError = true;
                    elseif ($up !== null) $block['photo'] = $up;
                    $ratio = $_POST['block_photo_ratio'][$i] ?? 'landscape';
                    $block['photo_ratio']    = array_key_exists($ratio, photo_ratio_options()) ? $ratio : 'landscape';
                    $pos = $_POST['block_photo_position'][$i] ?? 'center';
                    $block['photo_position'] = array_key_exists($pos, photo_position_options()) ? $pos : 'center';
                    if ($block['text'] === '' && $block['photo'] === '') continue 2;
                    break;

                case 'hero':
                    $block['hero_heading']    = trim($_POST['hero_heading'][$i]    ?? '');
                    $block['hero_subtext']    = trim($_POST['hero_subtext'][$i]    ?? '');
                    $block['hero_btn_text']   = trim($_POST['hero_btn_text'][$i]   ?? '');
                    $block['hero_btn_url']    = sanitize_url($_POST['hero_btn_url'][$i]    ?? '');
                    $block['hero_text_color'] = trim($_POST['hero_text_color'][$i] ?? '#ffffff');
                    $bgColor = trim($_POST['hero_bg_color'][$i] ?? '');
                    $block['hero_bg_color'] = preg_match('/^#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$/', $bgColor) ? $bgColor : '#1e3a5f';
                    $block['hero_bg_image'] = trim($_POST['hero_bg_image_existing'][$i] ?? '');
                    $up = upload_image_indexed('hero_bg_image', $i, 'hero_bg');
                    if ($up === false) $uploadError = true;
                    elseif ($up !== null) $block['hero_bg_image'] = $up;
                    if ($block['hero_heading'] === '' && $block['hero_subtext'] === '') continue 2;
                    break;

                case 'hero_split':
                    $block['hs_heading']   = trim($_POST['hs_heading'][$i]   ?? '');
                    $block['hs_subtext']   = trim($_POST['hs_subtext'][$i]   ?? '');
                    $block['hs_btn_text']  = trim($_POST['hs_btn_text'][$i]  ?? '');
                    $block['hs_btn_url']   = sanitize_url($_POST['hs_btn_url'][$i]   ?? '');
                    $block['hs_caption1']  = trim($_POST['hs_caption1'][$i]  ?? '');
                    $block['hs_caption2']  = trim($_POST['hs_caption2'][$i]  ?? '');
                    $block['hs_photo_alt']   = trim($_POST['hs_photo_alt'][$i]   ?? '');
                    $imgSideVal = trim($_POST['hs_image_side'][$i] ?? 'right');
                    $block['hs_image_side'] = in_array($imgSideVal, ['left','right']) ? $imgSideVal : 'right';
                    $bgColor = trim($_POST['hs_bg_color'][$i] ?? '#f3f6f7');
                    $block['hs_bg_color'] = preg_match('/^#[0-9a-fA-F]{3,6}$/', $bgColor) ? $bgColor : '#f3f6f7';
                    $block['hs_photo'] = trim($_POST['hs_photo_existing'][$i] ?? '');
                    $up = upload_image_indexed('hs_photo', $i, 'hs_photo');
                    if ($up === false) $uploadError = true;
                    elseif ($up !== null) $block['hs_photo'] = $up;
                    $block['hs_bg_photo'] = trim($_POST['hs_bg_photo_existing'][$i] ?? '');
                    $mob = trim($_POST['hs_mobile_order'][$i] ?? '');
                    $block['hs_mobile_order'] = in_array($mob, ['img_first','text_first']) ? $mob : '';
                    if ($block['hs_heading'] === '' && $block['hs_photo'] === '') continue 2;
                    break;

                case 'feature_split':
                    $block['fs_heading']   = trim($_POST['fs_heading'][$i]   ?? '');
                    $block['fs_subtext']   = trim($_POST['fs_subtext'][$i]   ?? '');
                    $block['fs_photo_alt'] = trim($_POST['fs_photo_alt'][$i] ?? '');
                    $block['fs_star_text'] = trim($_POST['fs_star_text'][$i] ?? '');
                    $bgc = trim($_POST['fs_bg_color'][$i] ?? '#f3f6f7');
                    $block['fs_bg_color']  = preg_match('/^#[0-9a-fA-F]{3,6}$/', $bgc) ? $bgc : '#f3f6f7';
                    $acc = trim($_POST['fs_accent'][$i] ?? '#fd783b');
                    $block['fs_accent']    = preg_match('/^#[0-9a-fA-F]{3,6}$/', $acc) ? $acc : '#fd783b';
                    // Main photo
                    $block['fs_image_side'] = ($_POST['fs_image_side'][$i] ?? 'right') === 'left' ? 'left' : 'right';
                    $fsMob = trim($_POST['fs_mobile_order'][$i] ?? '');
                    $block['fs_mobile_order'] = in_array($fsMob, ['img_first','text_first']) ? $fsMob : '';
                    $block['fs_photo'] = trim($_POST['fs_photo_existing'][$i] ?? '');
                    $up = upload_image_indexed('fs_photo', $i, 'fs_photo');
                    if ($up === false) $uploadError = true;
                    elseif ($up !== null) $block['fs_photo'] = $up;
                    // Grid items
                    $itemHeadings = $_POST['fs_item_heading'][$i] ?? [];
                    $itemTexts    = $_POST['fs_item_text'][$i]    ?? [];
                    $itemAlts     = $_POST['fs_item_alt'][$i]     ?? [];
                    $itemExisting = $_POST['fs_item_icon_existing'][$i] ?? [];
                    $fsItems = [];
                    foreach ($itemHeadings as $fi => $ih) {
                        $iconPath = trim($itemExisting[$fi] ?? '');
                        if (isset($_FILES['fs_item_icon']['error'][$i][$fi]) &&
                            $_FILES['fs_item_icon']['error'][$i][$fi] === UPLOAD_ERR_OK) {
                            $up2 = save_uploaded_file($_FILES['fs_item_icon']['tmp_name'][$i][$fi], 'fs_icon');
                            if ($up2) $iconPath = $up2;
                            elseif ($up2 === false) $uploadError = true;
                        }
                        $ih = trim($ih); $it = trim($itemTexts[$fi] ?? '');
                        if ($ih === '' && $it === '' && !$iconPath) continue;
                        $fsItems[] = ['icon' => $iconPath, 'alt' => trim($itemAlts[$fi] ?? ''), 'heading' => $ih, 'text' => $it];
                    }
                    $block['fs_items'] = $fsItems;
                    if ($block['fs_heading'] === '' && empty($fsItems)) continue 2;
                    break;

                case 'feature_columns':
                    $block['fc_heading']  = trim($_POST['fc_heading'][$i]  ?? '');
                    $block['fc_num_cols'] = max(2, min(4, (int)($_POST['fc_num_cols'][$i] ?? 3)));
                    $headings  = $_POST['fc_col_heading'][$i]         ?? [];
                    $texts     = $_POST['fc_col_text'][$i]            ?? [];
                    $alts      = $_POST['fc_col_alt'][$i]             ?? [];
                    $existing  = $_POST['fc_col_image_existing'][$i]  ?? [];
                    $cols = [];
                    foreach ($headings as $ci => $ch) {
                        $colImg = trim($existing[$ci] ?? '');
                        // Handle file upload for fc col images
                        if (isset($_FILES['fc_col_image']['error'][$i][$ci]) &&
                            $_FILES['fc_col_image']['error'][$i][$ci] === UPLOAD_ERR_OK) {
                            $up = save_uploaded_file($_FILES['fc_col_image']['tmp_name'][$i][$ci], 'fc_icon');
                            if ($up) $colImg = $up;
                        }
                        $colHead = trim($ch);
                        $colText = trim($texts[$ci] ?? '');
                        $colAlt  = trim($alts[$ci] ?? '');
                        if ($colHead === '' && $colText === '' && $colImg === '') continue;
                        $cols[] = ['image' => $colImg, 'heading' => $colHead, 'text' => $colText, 'alt' => $colAlt];
                    }
                    $block['columns'] = $cols;
                    if (empty($cols) && $block['fc_heading'] === '') continue 2;
                    break;

                case 'split_cta':
                    $block['sc_left_heading']   = trim($_POST['sc_left_heading'][$i]   ?? '');
                    $block['sc_left_text']      = trim($_POST['sc_left_text'][$i]      ?? '');
                    $block['sc_right_label']    = trim($_POST['sc_right_label'][$i]    ?? '');
                    $block['sc_right_phone']    = trim($_POST['sc_right_phone'][$i]    ?? '');
                    $block['sc_right_phone_url']= sanitize_url($_POST['sc_right_phone_url'][$i]?? '');
                    $scLeftBg  = in_array($_POST['sc_left_bg'][$i]  ?? '', ['accent','header','custom']) ? $_POST['sc_left_bg'][$i]  : 'accent';
                    $scRightBg = in_array($_POST['sc_right_bg'][$i] ?? '', ['accent','header','custom']) ? $_POST['sc_right_bg'][$i] : 'header';
                    $block['sc_left_bg']        = $scLeftBg;
                    $block['sc_right_bg']       = $scRightBg;
                    $lc = trim($_POST['sc_left_bg_custom'][$i]  ?? '#fd783b');
                    $block['sc_left_bg_custom'] = preg_match('/^#[0-9a-fA-F]{3,6}$/', $lc) ? $lc : '#fd783b';
                    $rc = trim($_POST['sc_right_bg_custom'][$i] ?? '#120575');
                    $block['sc_right_bg_custom']= preg_match('/^#[0-9a-fA-F]{3,6}$/', $rc) ? $rc : '#120575';
                    if ($block['sc_left_heading'] === '' && $block['sc_right_phone'] === '') continue 2;
                    break;

                case 'cta_button':
                    $block['cta_text']    = trim($_POST['cta_text'][$i]    ?? 'Contact Us');
                    $block['cta_url']     = sanitize_url($_POST['cta_url'][$i]     ?? '') ?: '#';
                    $block['cta_subtext'] = trim($_POST['cta_subtext'][$i] ?? '');
                    $block['cta_align']   = in_array($_POST['cta_align'][$i] ?? '', ['left','center','right'])
                        ? $_POST['cta_align'][$i] : 'center';
                    if ($block['cta_text'] === '') continue 2;
                    break;

                case 'image_text':
                    $block['it_image_side']    = ($_POST['it_image_side'][$i] ?? 'left') === 'right' ? 'right' : 'left';
                    $block['it_heading_level'] = in_array($_POST['it_heading_level'][$i] ?? '', array_keys(heading_level_options()))
                        ? $_POST['it_heading_level'][$i] : 'h2';
                    $block['it_heading'] = trim($_POST['it_heading'][$i] ?? '');
                    $block['it_text']    = trim($_POST['it_text'][$i]    ?? '');
                    $block['it_btn_text']= trim($_POST['it_btn_text'][$i]?? '');
                    $block['it_btn_url'] = sanitize_url($_POST['it_btn_url'][$i] ?? '');
                    $block['it_alt']     = trim($_POST['it_alt'][$i]     ?? '');
                    $block['it_photo']   = trim($_POST['it_photo_existing'][$i] ?? '');
                    $up = upload_image_indexed('it_photo', $i, 'it_photo');
                    if ($up === false) $uploadError = true;
                    elseif ($up !== null) $block['it_photo'] = $up;
                    $block['it_ratio']    = array_key_exists($_POST['it_ratio'][$i]    ?? '', photo_ratio_options())    ? $_POST['it_ratio'][$i]    : 'landscape';
                    $block['it_position'] = array_key_exists($_POST['it_position'][$i] ?? '', photo_position_options()) ? $_POST['it_position'][$i] : 'center';
                    if ($block['it_heading'] === '' && $block['it_text'] === '' && $block['it_photo'] === '') continue 2;
                    break;

                case 'faq':
                    $block['faq_heading'] = trim($_POST['faq_heading'][$i] ?? '');
                    $questions = $_POST['faq_question'][$i] ?? [];
                    $answers   = $_POST['faq_answer'][$i]   ?? [];
                    $items = [];
                    foreach ($questions as $fi => $q) {
                        $q = trim($q); $a = trim($answers[$fi] ?? '');
                        if ($q === '' && $a === '') continue;
                        $items[] = ['question' => $q, 'answer' => $a];
                    }
                    $block['faq_items'] = $items;
                    if (empty($items) && $block['faq_heading'] === '') continue 2;
                    break;

                case 'custom_html':
                    $block['html'] = $_POST['custom_html'][$i] ?? '';
                    if ($block['html'] === '') continue 2;
                    break;

                case 'cta_card':
                    $block['cc_heading']  = trim($_POST['cc_heading'][$i]  ?? '');
                    $block['cc_text']     = trim($_POST['cc_text'][$i]     ?? '');
                    $block['cc_btn_text'] = trim($_POST['cc_btn_text'][$i] ?? '');
                    $block['cc_btn_url']  = sanitize_url($_POST['cc_btn_url'][$i]  ?? '');
                    $block['cc_btn_style']= ($_POST['cc_btn_style'][$i] ?? 'outline') === 'filled' ? 'filled' : 'outline';
                    $block['cc_align']    = ($_POST['cc_align'][$i] ?? 'split') === 'center' ? 'center' : 'split';
                    $ccBg = in_array($_POST['cc_bg'][$i] ?? '', ['accent','header','custom']) ? $_POST['cc_bg'][$i] : 'accent';
                    $block['cc_bg'] = $ccBg;
                    $cbc = trim($_POST['cc_bg_custom'][$i] ?? '#fd783b');
                    $block['cc_bg_custom'] = preg_match('/^#[0-9a-fA-F]{3,6}$/', $cbc) ? $cbc : '#fd783b';
                    $block['cc_radius']   = max(0, min(40, (int)($_POST['cc_radius'][$i] ?? 12)));
                    if ($block['cc_heading'] === '' && $block['cc_text'] === '') continue 2;
                    break;

                case 'map_info':
                    $block['mi_map_heading']  = trim($_POST['mi_map_heading'][$i]  ?? '');
                    $block['mi_info_heading'] = trim($_POST['mi_info_heading'][$i] ?? '');
                    $block['mi_info_text']    = trim($_POST['mi_info_text'][$i]    ?? '');
                    $block['mi_info_alt']     = trim($_POST['mi_info_alt'][$i]     ?? '');
                    // Sanitize map embed — only allow iframe tag, strip event attributes
                    $rawEmbed = trim($_POST['mi_map_embed'][$i] ?? '');
                    $rawEmbed = preg_replace('/\s+on\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]*)/i', '', $rawEmbed);
                    $block['mi_map_embed'] = preg_match('/<iframe[^>]*>.*<\/iframe>/is', $rawEmbed) ? $rawEmbed : '';
                    $mhc = in_array($_POST['mi_head_color'][$i] ?? '', ['accent','header','custom']) ? $_POST['mi_head_color'][$i] : 'header';
                    $block['mi_head_color'] = $mhc;
                    $mcc = trim($_POST['mi_head_color_custom'][$i] ?? '#120575');
                    $block['mi_head_color_custom'] = preg_match('/^#[0-9a-fA-F]{3,6}$/', $mcc) ? $mcc : '#120575';
                    // Photo
                    $block['mi_info_photo'] = trim($_POST['mi_info_photo_existing'][$i] ?? '');
                    $up = upload_image_indexed('mi_info_photo', $i, 'mi_photo');
                    if ($up === false) $uploadError = true;
                    elseif ($up !== null) $block['mi_info_photo'] = $up;
                    if ($block['mi_map_heading'] === '' && $block['mi_map_embed'] === '' && $block['mi_info_heading'] === '') continue 2;
                    break;

                case 'links_grid':
                    $block['lg_heading']  = trim($_POST['lg_heading'][$i]  ?? '');
                    $block['lg_subtext']  = trim($_POST['lg_subtext'][$i]  ?? '');
                    $block['lg_sublabel'] = trim($_POST['lg_sublabel'][$i] ?? '');
                    $block['lg_photo_alt']= trim($_POST['lg_photo_alt'][$i]?? '');
                    $block['lg_style']    = ($_POST['lg_style'][$i] ?? 'dark') === 'light' ? 'light' : 'dark';
                    $block['lg_cols']     = max(2, min(6, (int)($_POST['lg_cols'][$i] ?? 5)));
                    $block['lg_overlay']  = number_format(max(0, min(0.9, (float)($_POST['lg_overlay'][$i] ?? 0.6))), 2);
                    $lgbc = trim($_POST['lg_bg_color'][$i] ?? '#ffffff');
                    $block['lg_bg_color'] = preg_match('/^#[0-9a-fA-F]{3,6}$/', $lgbc) ? $lgbc : '#ffffff';
                    $lgac = in_array($_POST['lg_accent'][$i] ?? '', ['accent','header','custom']) ? $_POST['lg_accent'][$i] : 'accent';
                    $block['lg_accent'] = $lgac;
                    $lgacc = trim($_POST['lg_accent_custom'][$i] ?? '#fd783b');
                    $block['lg_accent_custom'] = preg_match('/^#[0-9a-fA-F]{3,6}$/', $lgacc) ? $lgacc : '#fd783b';
                    $block['lg_photo']    = trim($_POST['lg_photo_existing'][$i] ?? '');
                    $up = upload_image_indexed('lg_photo', $i, 'lg_photo');
                    if ($up === false) $uploadError = true;
                    elseif ($up !== null) $block['lg_photo'] = $up;
                    // Links
                    $lgLabels = $_POST['lg_link_label'][$i] ?? [];
                    $lgUrls   = $_POST['lg_link_url'][$i]   ?? [];
                    $lgLinks  = [];
                    foreach ($lgLabels as $li => $ll) {
                        $ll = trim($ll);
                        if ($ll === '') continue;
                        $lgLinks[] = ['label' => $ll, 'url' => sanitize_url($lgUrls[$li] ?? '#') ?: '#'];
                    }
                    $block['lg_links'] = $lgLinks;
                    if (empty($lgLinks) && $block['lg_heading'] === '') continue 2;
                    break;

                case 'cta_banner':
                    $block['cb_text']    = trim($_POST['cb_text'][$i]    ?? '');
                    $block['cb_subtext'] = trim($_POST['cb_subtext'][$i] ?? '');
                    $block['cb_btn_text']= trim($_POST['cb_btn_text'][$i]?? '');
                    $block['cb_btn_url'] = sanitize_url($_POST['cb_btn_url'][$i] ?? '');
                    $cbBg = in_array($_POST['cb_bg'][$i] ?? '', ['accent','header','custom']) ? $_POST['cb_bg'][$i] : 'accent';
                    $block['cb_bg'] = $cbBg;
                    $cbc = trim($_POST['cb_bg_custom'][$i] ?? '#fd783b');
                    $block['cb_bg_custom'] = preg_match('/^#[0-9a-fA-F]{3,6}$/', $cbc) ? $cbc : '#fd783b';
                    $ctc = trim($_POST['cb_text_color'][$i] ?? '#ffffff');
                    $block['cb_text_color'] = preg_match('/^#[0-9a-fA-F]{3,6}$/', $ctc) ? $ctc : '#ffffff';
                    $cbp = $_POST['cb_padding'][$i] ?? 'normal';
                    $block['cb_padding'] = in_array($cbp, ['compact','normal','large']) ? $cbp : 'normal';
                    if ($block['cb_text'] === '') continue 2;
                    break;

                case 'faq_two_col':
                    $block['fq_heading']  = trim($_POST['fq_heading'][$i]  ?? '');
                    $bgc = trim($_POST['fq_bg_color'][$i]   ?? '#ffffff');
                    $block['fq_bg_color'] = preg_match('/^#[0-9a-fA-F]{3,6}$/', $bgc) ? $bgc : '#ffffff';
                    $ibc = trim($_POST['fq_item_bg'][$i]    ?? '#f0f2f8');
                    $block['fq_item_bg']  = preg_match('/^#[0-9a-fA-F]{3,6}$/', $ibc) ? $ibc : '#f0f2f8';
                    foreach (['fq_head_color','fq_icon_bg'] as $ck) {
                        $cv = in_array($_POST[$ck][$i] ?? '', ['accent','header','custom']) ? $_POST[$ck][$i] : ($ck === 'fq_head_color' ? 'header' : 'accent');
                        $block[$ck] = $cv;
                    }
                    $hcc = trim($_POST['fq_head_color_custom'][$i] ?? '#120575');
                    $block['fq_head_color_custom'] = preg_match('/^#[0-9a-fA-F]{3,6}$/', $hcc) ? $hcc : '#120575';
                    $icc = trim($_POST['fq_icon_bg_custom'][$i]    ?? '#fd783b');
                    $block['fq_icon_bg_custom']    = preg_match('/^#[0-9a-fA-F]{3,6}$/', $icc) ? $icc : '#fd783b';
                    // Q&A items
                    $fqQs = $_POST['fq_question'][$i] ?? [];
                    $fqAs = $_POST['fq_answer'][$i]   ?? [];
                    $fqItems = [];
                    foreach ($fqQs as $fi => $fq) {
                        $fq = trim($fq); $fa = trim($fqAs[$fi] ?? '');
                        if ($fq === '') continue;
                        $fqItems[] = ['question' => $fq, 'answer' => $fa];
                    }
                    $block['fq_items'] = $fqItems;
                    if (empty($fqItems) && $block['fq_heading'] === '') continue 2;
                    break;

                case 'image_features':
                    $block['if_heading']     = trim($_POST['if_heading'][$i]     ?? '');
                    $block['if_intro']       = trim($_POST['if_intro'][$i]       ?? '');
                    $block['if_closing']     = trim($_POST['if_closing'][$i]     ?? '');
                    $block['if_phone_label'] = trim($_POST['if_phone_label'][$i] ?? '');
                    $block['if_phone']       = trim($_POST['if_phone'][$i]       ?? '');
                    $block['if_phone_url']   = sanitize_url($_POST['if_phone_url'][$i]   ?? '');
                    $block['if_photo_alt']   = trim($_POST['if_photo_alt'][$i]   ?? '');
                    $ibc = trim($_POST['if_bg_color'][$i] ?? '#f3f6f7');
                    $block['if_bg_color'] = preg_match('/^#[0-9a-fA-F]{3,6}$/', $ibc) ? $ibc : '#f3f6f7';
                    foreach (['if_check_color','if_head_color'] as $ck) {
                        $default = $ck === 'if_head_color' ? 'header' : 'accent';
                        $cv = in_array($_POST[$ck][$i] ?? '', ['accent','header','custom']) ? $_POST[$ck][$i] : $default;
                        $block[$ck] = $cv;
                    }
                    $cc = trim($_POST['if_check_color_custom'][$i] ?? '#fd783b');
                    $block['if_check_color_custom'] = preg_match('/^#[0-9a-fA-F]{3,6}$/', $cc) ? $cc : '#fd783b';
                    $hc = trim($_POST['if_head_color_custom'][$i]  ?? '#120575');
                    $block['if_head_color_custom']  = preg_match('/^#[0-9a-fA-F]{3,6}$/', $hc) ? $hc : '#120575';
                    // Photo
                    $block['if_photo'] = trim($_POST['if_photo_existing'][$i] ?? '');
                    $up = upload_image_indexed('if_photo', $i, 'if_photo');
                    if ($up === false) $uploadError = true;
                    elseif ($up !== null) $block['if_photo'] = $up;
                    // Features list
                    $feats = $_POST['if_features'][$i] ?? [];
                    $block['if_features'] = array_values(array_filter(array_map('trim', $feats)));
                    if ($block['if_heading'] === '' && empty($block['if_features'])) continue 2;
                    break;

                case 'wide_banner':
                    $block['wb_badge']     = trim($_POST['wb_badge'][$i]     ?? '');
                    $block['wb_heading']   = trim($_POST['wb_heading'][$i]   ?? '');
                    $block['wb_subtext']   = trim($_POST['wb_subtext'][$i]   ?? '');
                    $block['wb_btn_text']  = trim($_POST['wb_btn_text'][$i]  ?? '');
                    $block['wb_btn_url']   = sanitize_url($_POST['wb_btn_url'][$i]   ?? '');
                    $block['wb_btn_style'] = ($_POST['wb_btn_style'][$i] ?? 'filled') === 'outline' ? 'outline' : 'filled';
                    $block['wb_centered']  = !empty($_POST['wb_centered'][$i]);
                    $block['wb_photo_alt'] = trim($_POST['wb_photo_alt'][$i] ?? '');
                    $block['wb_overlay']   = number_format(max(0, min(0.9, (float)($_POST['wb_overlay'][$i] ?? 0.55))), 2);
                    $wbBgCol = trim($_POST['wb_bg_color'][$i] ?? '#1a1a2e');
                    $block['wb_bg_color']  = preg_match('/^#[0-9a-fA-F]{3,6}$/', $wbBgCol) ? $wbBgCol : '#1a1a2e';
                    $wbBg = in_array($_POST['wb_badge_bg'][$i] ?? '', ['accent','header','custom']) ? $_POST['wb_badge_bg'][$i] : 'accent';
                    $block['wb_badge_bg'] = $wbBg;
                    $wbc = trim($_POST['wb_badge_bg_custom'][$i] ?? '#fd783b');
                    $block['wb_badge_bg_custom'] = preg_match('/^#[0-9a-fA-F]{3,6}$/', $wbc) ? $wbc : '#fd783b';
                    $block['wb_photo'] = trim($_POST['wb_photo_existing'][$i] ?? '');
                    $up = upload_image_indexed('wb_photo', $i, 'wb_photo');
                    if ($up === false) $uploadError = true;
                    elseif ($up !== null) $block['wb_photo'] = $up;
                    if ($block['wb_heading'] === '' && $block['wb_badge'] === '') continue 2;
                    break;

                case 'service_cards':
                    $block['sc_badge']   = trim($_POST['sc_badge'][$i]   ?? '');
                    $block['sc_heading'] = trim($_POST['sc_heading'][$i] ?? '');
                    $block['sc_cols']    = max(2, min(4, (int)($_POST['sc_cols'][$i] ?? 3)));
                    foreach (['sc_badge_bg','sc_head_color'] as $ck) {
                        $cv = in_array($_POST[$ck][$i] ?? '', ['accent','header','custom']) ? $_POST[$ck][$i] : 'accent';
                        $block[$ck] = $cv;
                    }
                    $bbc = trim($_POST['sc_badge_bg_custom'][$i]   ?? '#fd783b');
                    $block['sc_badge_bg_custom']    = preg_match('/^#[0-9a-fA-F]{3,6}$/', $bbc) ? $bbc : '#fd783b';
                    $hcc = trim($_POST['sc_head_color_custom'][$i] ?? '#120575');
                    $block['sc_head_color_custom']  = preg_match('/^#[0-9a-fA-F]{3,6}$/', $hcc) ? $hcc : '#120575';
                    $ibc = trim($_POST['sc_icon_bg'][$i] ?? '#fef0e7');
                    $block['sc_icon_bg'] = preg_match('/^#[0-9a-fA-F]{3,6}$/', $ibc) ? $ibc : '#fef0e7';
                    // Items
                    $scHeadings = $_POST['sc_item_heading'][$i]       ?? [];
                    $scTexts    = $_POST['sc_item_text'][$i]          ?? [];
                    $scAlts     = $_POST['sc_item_alt'][$i]           ?? [];
                    $scUrls     = $_POST['sc_item_url'][$i]           ?? [];
                    $scExisting = $_POST['sc_item_icon_existing'][$i] ?? [];
                    $scItems = [];
                    foreach ($scHeadings as $si => $sh) {
                        $iconPath = trim($scExisting[$si] ?? '');
                        if (isset($_FILES['sc_item_icon']['error'][$i][$si]) &&
                            $_FILES['sc_item_icon']['error'][$i][$si] === UPLOAD_ERR_OK) {
                            $up = save_uploaded_file($_FILES['sc_item_icon']['tmp_name'][$i][$si], 'sc_icon');
                            if ($up) $iconPath = $up; elseif ($up === false) $uploadError = true;
                        }
                        $sh = trim($sh); $st = trim($scTexts[$si] ?? '');
                        if ($sh === '' && $st === '' && !$iconPath) continue;
                        $scItems[] = ['icon' => $iconPath, 'alt' => trim($scAlts[$si] ?? ''), 'heading' => $sh, 'text' => $st, 'url' => sanitize_url($scUrls[$si] ?? '')];
                    }
                    $block['sc_items'] = $scItems;
                    if (empty($scItems) && $block['sc_heading'] === '') continue 2;
                    break;

                case 'hero_grid':
                    $block['hg_label']     = trim($_POST['hg_label'][$i]     ?? '');
                    $block['hg_heading']   = trim($_POST['hg_heading'][$i]   ?? '');
                    $block['hg_body']      = trim($_POST['hg_body'][$i]      ?? '');
                    $block['hg_btn_text']  = trim($_POST['hg_btn_text'][$i]  ?? '');
                    $block['hg_btn_url']   = sanitize_url($_POST['hg_btn_url'][$i]   ?? '');
                    $block['hg_photo_alt'] = trim($_POST['hg_photo_alt'][$i] ?? '');
                    $block['hg_photo'] = trim($_POST['hg_photo_existing'][$i] ?? '');
                    $up = upload_image_indexed('hg_photo', $i, 'hg_photo');
                    if ($up === false) $uploadError = true;
                    elseif ($up !== null) $block['hg_photo'] = $up;
                    // Colors
                    foreach (['hg_color1','hg_color2'] as $ck) {
                        $cv = in_array($_POST[$ck][$i] ?? '', ['accent','header','custom']) ? $_POST[$ck][$i] : 'accent';
                        $block[$ck] = $cv;
                    }
                    $c1c = trim($_POST['hg_color1_custom'][$i] ?? '#fd783b');
                    $block['hg_color1_custom'] = preg_match('/^#[0-9a-fA-F]{3,6}$/', $c1c) ? $c1c : '#fd783b';
                    $c2c = trim($_POST['hg_color2_custom'][$i] ?? '#120575');
                    $block['hg_color2_custom'] = preg_match('/^#[0-9a-fA-F]{3,6}$/', $c2c) ? $c2c : '#120575';
                    // Grid items
                    $hgLabels   = $_POST['hg_item_label'][$i]         ?? [];
                    $hgAlts     = $_POST['hg_item_alt'][$i]           ?? [];
                    $hgExisting = $_POST['hg_item_icon_existing'][$i] ?? [];
                    $hgItems = [];
                    foreach ($hgLabels as $gi => $gl) {
                        $iconPath = trim($hgExisting[$gi] ?? '');
                        if (isset($_FILES['hg_item_icon']['error'][$i][$gi]) &&
                            $_FILES['hg_item_icon']['error'][$i][$gi] === UPLOAD_ERR_OK) {
                            $up2 = save_uploaded_file($_FILES['hg_item_icon']['tmp_name'][$i][$gi], 'hg_icon');
                            if ($up2) $iconPath = $up2; elseif ($up2 === false) $uploadError = true;
                        }
                        $gl = trim($gl);
                        if ($gl === '' && !$iconPath) continue;
                        $hgItems[] = ['icon' => $iconPath, 'label' => $gl, 'alt' => trim($hgAlts[$gi] ?? '')];
                    }
                    $block['hg_items'] = $hgItems;
                    if ($block['hg_heading'] === '' && empty($hgItems)) continue 2;
                    break;

                case 'tab_services':
                    $block['ts_badge1']   = trim($_POST['ts_badge1'][$i]  ?? '');
                    $block['ts_badge2']   = trim($_POST['ts_badge2'][$i]  ?? '');
                    $block['ts_heading']  = trim($_POST['ts_heading'][$i] ?? '');
                    $tsActiveBg = in_array($_POST['ts_active_bg'][$i] ?? '', ['header','accent','custom'])
                        ? $_POST['ts_active_bg'][$i] : 'header';
                    $block['ts_active_bg'] = $tsActiveBg;
                    $tac = trim($_POST['ts_active_bg_custom'][$i] ?? '#120575');
                    $block['ts_active_bg_custom'] = preg_match('/^#[0-9a-fA-F]{3,6}$/', $tac) ? $tac : '#120575';
                    // Tabs
                    $tabLabels  = $_POST['ts_tab_label'][$i]          ?? [];
                    $tabDescs   = $_POST['ts_tab_desc'][$i]            ?? [];
                    $tabAlts    = $_POST['ts_tab_alt'][$i]             ?? [];
                    $tabIcons   = $_POST['ts_tab_icon_existing'][$i]  ?? [];
                    $tabPhotos  = $_POST['ts_tab_photo_existing'][$i] ?? [];
                    $tsTabs = [];
                    foreach ($tabLabels as $ti => $tl) {
                        $iconPath  = trim($tabIcons[$ti]  ?? '');
                        $photoPath = trim($tabPhotos[$ti] ?? '');
                        // Icon upload
                        if (isset($_FILES['ts_tab_icon']['error'][$i][$ti]) &&
                            $_FILES['ts_tab_icon']['error'][$i][$ti] === UPLOAD_ERR_OK) {
                            $up = save_uploaded_file($_FILES['ts_tab_icon']['tmp_name'][$i][$ti], 'ts_icon');
                            if ($up) $iconPath = $up; elseif ($up === false) $uploadError = true;
                        }
                        // Photo upload
                        if (isset($_FILES['ts_tab_photo']['error'][$i][$ti]) &&
                            $_FILES['ts_tab_photo']['error'][$i][$ti] === UPLOAD_ERR_OK) {
                            $up = save_uploaded_file($_FILES['ts_tab_photo']['tmp_name'][$i][$ti], 'ts_photo');
                            if ($up) $photoPath = $up; elseif ($up === false) $uploadError = true;
                        }
                        $tl = trim($tl);
                        if ($tl === '' && !$iconPath && !$photoPath) continue;
                        $tsTabs[] = [
                            'label' => $tl,
                            'icon'  => $iconPath,
                            'photo' => $photoPath,
                            'alt'   => trim($tabAlts[$ti] ?? ''),
                            'desc'  => trim($tabDescs[$ti] ?? ''),
                        ];
                    }
                    $block['ts_tabs'] = $tsTabs;
                    if (empty($tsTabs) && $block['ts_heading'] === '') continue 2;
                    break;

                case 'gallery':
                    $block['gallery_heading'] = trim($_POST['gallery_heading'][$i] ?? '');
                    $block['gallery_cols']    = max(2, min(4, (int)($_POST['gallery_cols'][$i] ?? 3)));
                    $existingGallery = $_POST['gallery_photo_existing'][$i] ?? [];
                    $galleryAlts     = $_POST['gallery_alt'][$i]            ?? [];
                    $images = [];
                    foreach ($existingGallery as $gi => $existingPath) {
                        $imgPath = trim($existingPath);
                        if (isset($_FILES['gallery_photo']['error'][$i][$gi]) &&
                            $_FILES['gallery_photo']['error'][$i][$gi] === UPLOAD_ERR_OK) {
                            $up = save_uploaded_file($_FILES['gallery_photo']['tmp_name'][$i][$gi], 'gallery');
                            if ($up) $imgPath = $up;
                            elseif ($up === false) $uploadError = true;
                        }
                        $alt = trim($galleryAlts[$gi] ?? '');
                        if ($imgPath) $images[] = ['photo' => $imgPath, 'alt' => $alt];
                    }
                    $block['gallery_images'] = $images;
                    if (empty($images) && $block['gallery_heading'] === '') continue 2;
                    break;

                case 'steps':
                    $block['steps_heading'] = trim($_POST['steps_heading'][$i] ?? '');
                    $stepHeadings  = $_POST['steps_heading_item'][$i] ?? [];
                    $stepTexts     = $_POST['steps_text'][$i]          ?? [];
                    $stepAlts      = $_POST['steps_alt'][$i]           ?? [];
                    $stepExisting  = $_POST['steps_image_existing'][$i]?? [];
                    $stepItems = [];
                    foreach ($stepHeadings as $si => $sh) {
                        $imgPath = trim($stepExisting[$si] ?? '');
                        if (isset($_FILES['steps_image']['error'][$i][$si]) &&
                            $_FILES['steps_image']['error'][$i][$si] === UPLOAD_ERR_OK) {
                            $up = save_uploaded_file($_FILES['steps_image']['tmp_name'][$i][$si], 'step');
                            if ($up) $imgPath = $up;
                            elseif ($up === false) $uploadError = true;
                        }
                        $sh = trim($sh); $st = trim($stepTexts[$si] ?? '');
                        if ($sh === '' && $st === '' && !$imgPath) continue;
                        $stepItems[] = ['image' => $imgPath, 'alt' => trim($stepAlts[$si] ?? ''), 'heading' => $sh, 'text' => $st];
                    }
                    $block['steps_items'] = $stepItems;
                    if (empty($stepItems) && $block['steps_heading'] === '') continue 2;
                    break;

                case 'stats':
                    $block['stats_heading']    = trim($_POST['stats_heading'][$i]    ?? '');
                    $sbc = trim($_POST['stats_bg_color'][$i]   ?? '#1e3a5f');
                    $stc = trim($_POST['stats_text_color'][$i] ?? '#ffffff');
                    $block['stats_bg_color']   = preg_match('/^#[0-9a-fA-F]{3,6}$/', $sbc) ? $sbc : '#1e3a5f';
                    $block['stats_text_color'] = preg_match('/^#[0-9a-fA-F]{3,6}$/', $stc) ? $stc : '#ffffff';
                    $numbers = $_POST['stats_number'][$i] ?? [];
                    $labels  = $_POST['stats_label'][$i]  ?? [];
                    $statItems = [];
                    foreach ($numbers as $si => $num) {
                        $num = trim($num); $lbl = trim($labels[$si] ?? '');
                        if ($num === '' && $lbl === '') continue;
                        $statItems[] = ['number' => $num, 'label' => $lbl];
                    }
                    $block['stats_items'] = $statItems;
                    if (empty($statItems) && $block['stats_heading'] === '') continue 2;
                    break;

                case 'cards':
                    $block['cards_heading'] = trim($_POST['cards_heading'][$i] ?? '');
                    $block['cards_cols']    = max(2, min(4, (int)($_POST['cards_cols'][$i] ?? 3)));
                    // Colors — read directly from color picker inputs
                    $cbg = trim($_POST['cards_bg'][$i] ?? '');
                    $block['cards_bg'] = preg_match('/^#[0-9a-fA-F]{3,6}$/', $cbg) ? $cbg : '';
                    $ccbg = trim($_POST['cards_card_bg'][$i] ?? '');
                    $block['cards_card_bg'] = preg_match('/^#[0-9a-fA-F]{3,6}$/', $ccbg) ? $ccbg : '';
                    $ctc = trim($_POST['cards_text_color'][$i] ?? '');
                    $block['cards_text_color'] = preg_match('/^#[0-9a-fA-F]{3,6}$/', $ctc) ? $ctc : '';
                    $chc = in_array($_POST['cards_head_color'][$i] ?? '', ['accent','header','custom']) ? $_POST['cards_head_color'][$i] : 'header';
                    $block['cards_head_color'] = $chc;
                    $chcc = trim($_POST['cards_head_color_custom'][$i] ?? '#1a1a2e');
                    $block['cards_head_color_custom'] = preg_match('/^#[0-9a-fA-F]{3,6}$/', $chcc) ? $chcc : '#1a1a2e';
                    $cihc = in_array($_POST['cards_item_head_color'][$i] ?? '', ['accent','header','custom']) ? $_POST['cards_item_head_color'][$i] : 'header';
                    $block['cards_item_head_color'] = $cihc;
                    $cihcc = trim($_POST['cards_item_head_color_custom'][$i] ?? '#1a1a2e');
                    $block['cards_item_head_color_custom'] = preg_match('/^#[0-9a-fA-F]{3,6}$/', $cihcc) ? $cihcc : '#1a1a2e';
                    $cardHeadings  = $_POST['cards_heading_item'][$i] ?? [];
                    $cardTexts     = $_POST['cards_text'][$i]         ?? [];
                    $cardLinks     = $_POST['cards_link'][$i]         ?? [];
                    $cardBtns      = $_POST['cards_btn'][$i]          ?? [];
                    $cardAlts      = $_POST['cards_alt'][$i]          ?? [];
                    $cardExisting  = $_POST['cards_image_existing'][$i]?? [];
                    $cardItems = [];
                    foreach ($cardHeadings as $ci => $ch) {
                        $imgPath = trim($cardExisting[$ci] ?? '');
                        if (isset($_FILES['cards_image']['error'][$i][$ci]) &&
                            $_FILES['cards_image']['error'][$i][$ci] === UPLOAD_ERR_OK) {
                            $up = save_uploaded_file($_FILES['cards_image']['tmp_name'][$i][$ci], 'card');
                            if ($up) $imgPath = $up;
                            elseif ($up === false) $uploadError = true;
                        }
                        $ch = trim($ch); $ct = trim($cardTexts[$ci] ?? '');
                        if ($ch === '' && $ct === '' && !$imgPath) continue;
                        $cardItems[] = [
                            'image'    => $imgPath,
                            'alt'      => trim($cardAlts[$ci] ?? ''),
                            'heading'  => $ch,
                            'text'     => $ct,
                            'link'     => sanitize_url($cardLinks[$ci] ?? ''),
                            'btn_text' => trim($cardBtns[$ci] ?? 'Read More') ?: 'Read More',
                        ];
                    }
                    $block['cards_items'] = $cardItems;
                    if (empty($cardItems) && $block['cards_heading'] === '') continue 2;
                    break;
            }

            $blocks[] = $block;
        }

        if ($uploadError && $message === '') $message = 'error:One or more image uploads failed.';

        // SEO
        $seoData = [
            'seo_title'          => trim($_POST['seo_title']          ?? ''),
            'canonical_url'      => sanitize_url($_POST['canonical_url']      ?? ''),
            'meta_description'   => trim($_POST['meta_description']   ?? ''),
            'meta_keywords'      => trim($_POST['meta_keywords']      ?? ''),
            'og_title'           => trim($_POST['og_title']           ?? ''),
            'og_description'     => trim($_POST['og_description']     ?? ''),
            'og_image'           => trim($_POST['og_image_existing']   ?? ''),
            'service_name'       => trim($_POST['service_name']       ?? ''),
            'service_type'       => trim($_POST['service_type']       ?? ''),
            'service_area'       => trim($_POST['service_area']       ?? ''),
            'service_description'=> trim($_POST['service_description']?? ''),
            'bc_label'           => trim($_POST['bc_label']           ?? ''),
            'bc_mid_label'       => trim($_POST['bc_mid_label']       ?? ''),
            'bc_mid_url'         => sanitize_url($_POST['bc_mid_url']         ?? ''),
        ];
        $existingSeo = $isLandingPage ? $data['pages'][$pageId]['seo'] : ($isPost ? $data['posts'][$postId]['seo'] : $data['seo']);
        $schema = trim($_POST['schema'] ?? '');
        if ($schema === '') {
            $seoData['schema'] = '';
        } elseif (json_decode($schema) !== null || $schema === 'null') {
            $seoData['schema'] = $schema;
        } else {
            $seoData['schema'] = $existingSeo['schema'] ?? '';
            if ($message === '') $message = 'error:Schema markup must be valid JSON. Other changes were saved.';
        }

        if ($isLandingPage) {
            $data['pages'][$pageId]['content_blocks'] = $blocks;
            $data['pages'][$pageId]['seo'] = $seoData;
            $data['pages'][$pageId]['title'] = trim($_POST['page_title'] ?? '');
            $requestedSlug = trim($_POST['page_slug'] ?? '') ?: $data['pages'][$pageId]['title'];
            $data['pages'][$pageId]['slug'] = unique_slug($requestedSlug, $data['pages'], $pageId);
        } elseif ($isPost) {
            $data['posts'][$postId]['content_blocks'] = $blocks;
            $data['posts'][$postId]['seo'] = $seoData;
            $data['posts'][$postId]['title'] = trim($_POST['post_title'] ?? '');
            $requestedSlug = trim($_POST['post_slug'] ?? '') ?: $data['posts'][$postId]['title'];
            $data['posts'][$postId]['slug'] = unique_slug($requestedSlug, $data['posts'], $postId);
            $data['posts'][$postId]['status'] = ($_POST['post_status'] ?? 'draft') === 'published' ? 'published' : 'draft';
            $publishedAt = trim($_POST['post_published_at'] ?? '');
            $data['posts'][$postId]['published_at'] = preg_match('/^\d{4}-\d{2}-\d{2}$/', $publishedAt) ? $publishedAt : date('Y-m-d');
            $data['posts'][$postId]['updated_at'] = date('Y-m-d');
            $data['posts'][$postId]['author'] = trim($_POST['post_author'] ?? '') ?: '{business} Team';
            $data['posts'][$postId]['tag'] = trim($_POST['post_tag'] ?? '');
            $data['posts'][$postId]['excerpt'] = trim($_POST['post_excerpt'] ?? '');
            $data['posts'][$postId]['featured_image'] = trim($_POST['post_featured_image_existing'] ?? '');
            $data['posts'][$postId]['featured_image_alt'] = trim($_POST['post_featured_image_alt'] ?? '');
            if (!empty($_POST['post_remove_featured_image'])) $data['posts'][$postId]['featured_image'] = '';
            $up = upload_image('post_featured_image', 'post_featured');
            if ($up === false) { $uploadError = true; $message = 'error:Featured image upload failed.'; }
            elseif ($up !== null) $data['posts'][$postId]['featured_image'] = $up;
        } else {
            $data['content_blocks'] = $blocks;
            $data['seo'] = $seoData;
        }
