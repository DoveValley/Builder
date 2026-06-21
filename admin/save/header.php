<?php
        $activeTab = 'header';
        $validLayouts = ['standard', 'single_row'];
        $postedLayout = $_POST['header_layout'] ?? 'standard';
        $data['header']['header_layout']   = in_array($postedLayout, $validLayouts) ? $postedLayout : 'standard';
        $data['header']['phone']           = trim($_POST['phone']           ?? '');
        $data['header']['city']            = trim($_POST['city']            ?? '');
        $data['header']['logo_max_height'] = max(32, min(120, (int)($_POST['logo_max_height'] ?? 56)));
        $data['header']['nav_bg']          = trim($_POST['nav_bg']          ?? '#fd783b');
        $data['header']['nav_text']        = trim($_POST['nav_text']        ?? '#ffffff');
        $data['header']['phone_btn_style'] = in_array($_POST['phone_btn_style'] ?? '', ['outline','filled','plain']) ? $_POST['phone_btn_style'] : 'outline';
        $data['header']['phone_label']    = trim($_POST['phone_label']   ?? 'Helpline:');
        $data['header']['show_sponsored'] = !empty($_POST['show_sponsored']);
        $data['header']['cta_text']       = trim($_POST['cta_text']      ?? '');
        $data['header']['cta_url']        = sanitize_url($_POST['cta_url'] ?? '');
        $data['header']['sticky']         = !empty($_POST['sticky']);
        // Info items
        $infoIcons = $_POST['info_icon'] ?? [];
        $infoTexts = $_POST['info_text'] ?? [];
        $infoItems = [];
        foreach ($infoIcons as $ii => $icon) {
            $text = trim($infoTexts[$ii] ?? '');
            $infoItems[] = ['icon' => trim($icon), 'text' => $text];
        }
        $data['header']['info_items'] = $infoItems;
        $data['header']['topbar_text'] = trim($_POST['topbar_text'] ?? '');
        // Only allow safe URL schemes: https, http, tel, mailto — block javascript: etc.
        $data['header']['topbar_link'] = sanitize_url($_POST['topbar_link'] ?? '');
        $labels = $_POST['menu_label'] ?? [];
        $urls   = $_POST['menu_url']   ?? [];
        $menu   = [];
        foreach ($labels as $i => $label) {
            $label = trim($label); $url = sanitize_url($urls[$i] ?? '');
            if ($label === '' && $url === '') continue;
            $childLabels = $_POST['menu_child_label'][$i] ?? [];
            $childUrls   = $_POST['menu_child_url'][$i]   ?? [];
            $children = [];
            foreach ($childLabels as $ci => $cl) {
                $cl = trim($cl); $cu = sanitize_url($childUrls[$ci] ?? '');
                if ($cl === '' && $cu === '') continue;
                $children[] = ['label' => $cl, 'url' => $cu !== '' ? $cu : '#'];
            }
            $menu[] = ['label' => $label, 'url' => $url !== '' ? $url : '#', 'children' => $children];
        }
        $data['header']['menu'] = $menu;
        // Social links
        $socialKeys = ['facebook','instagram','twitter','youtube','linkedin','tiktok','yelp'];
        $socials = [];
        foreach ($socialKeys as $key) {
            $socials[$key] = sanitize_url(trim($_POST['social_' . $key] ?? ''));
        }
        $data['header']['socials'] = $socials;
        $logo = upload_image('logo', 'logo');
        if ($logo === false) $message = 'error:Logo upload failed.';        elseif ($logo !== null) $data['header']['logo'] = $logo;
        if (!empty($_POST['remove_logo'])) $data['header']['logo'] = '';
        // Site variables (shortcodes)
        $data['site_vars']['city']      = trim($_POST['site_vars_city']      ?? '');
        $data['site_vars']['state']     = trim($_POST['site_vars_state']     ?? '');
        $data['site_vars']['SS']        = trim($_POST['site_vars_SS']        ?? '');
        $data['site_vars']['city_slug'] = trim($_POST['site_vars_city_slug'] ?? '');
        $data['site_vars']['business']  = trim($_POST['site_vars_business']  ?? '');
        $data['site_vars']['phone']     = trim($_POST['site_vars_phone']     ?? '');
        $data['site_vars']['tel']       = trim($_POST['site_vars_tel']       ?? '');
        $data['site_vars']['zip']       = trim($_POST['site_vars_zip']       ?? '');
        $data['site_vars']['website']   = sanitize_url(trim($_POST['site_vars_website']   ?? ''));