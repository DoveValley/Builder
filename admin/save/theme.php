<?php
        $activeTab = 'theme';
        $colorKeys = ['header_bg','header_top_bg','header_text','content_bg','footer_bg','footer_text','accent_color','accent2_color','btn_text','border_color'];
        foreach ($colorKeys as $key) {
            $value = trim($_POST[$key] ?? '');
            if (preg_match('/^#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$/', $value)) $data['theme'][$key] = $value;
        }
        // Fonts — allow any safe font name (letters, numbers, spaces, commas, hyphens)
        $font = trim($_POST['primary_font'] ?? '');
        if ($font !== '' && preg_match('/^[a-zA-Z0-9\s,\-]+$/', $font)) {
            $data['theme']['primary_font'] = $font;
            $data['theme']['font_family']  = $font; // keep alias in sync
        }
        $headingFont = trim($_POST['heading_font'] ?? '');
        if (preg_match('/^[a-zA-Z0-9\s,\-]*$/', $headingFont)) {
            $data['theme']['heading_font'] = $headingFont;
        }
        // Font sizes
        $data['theme']['font_size_body'] = max(12, min(24, (int)($_POST['font_size_body'] ?? 16)));
        foreach (['h1','h2','h3','h4'] as $tag) {
            $val = (float)($_POST['font_size_'.$tag] ?? 0);
            if ($val > 0) $data['theme']['font_size_'.$tag] = max(0.5, min(6.0, round($val, 2)));
        }
        // Button radius
        $radius = (int)($_POST['button_radius'] ?? 4);
        $data['theme']['button_radius'] = max(0, min(50, $radius));
        // Skin colors — accent bg is derived from primary accent, not saved separately
        $skinProps = [
            'light'  => ['bg','heading','text'],
            'subtle' => ['bg','heading','text'],
            'accent' => ['heading','text'],
            'dark'   => ['bg','heading','text'],
        ];
        foreach ($skinProps as $skinKey => $props) {
            foreach ($props as $prop) {
                $fkey = "skin_{$skinKey}_{$prop}";
                $val  = trim($_POST[$fkey] ?? '');
                if (preg_match('/^#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$/', $val)) {
                    $data['theme']['skins'][$skinKey][$prop] = $val;
                }
            }
        }
        // Analytics snippets — stored as-is (admin only, trusted input)
        $data['theme']['analytics_head']  = $_POST['analytics_head']  ?? '';
        $data['theme']['facebook_pixel']  = $_POST['facebook_pixel']  ?? '';