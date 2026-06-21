<?php
        $activeTab = 'theme';
        $defaults = default_data()['theme'];
        // Merge defaults into current theme, preserving skins and font settings.
        // Only reset the base color tokens back to their default values.
        $resetKeys = ['header_bg','header_top_bg','header_text','content_bg','footer_bg',
                      'footer_text','accent_color','accent2_color','btn_text','border_color'];
        foreach ($resetKeys as $k) {
            if (isset($defaults[$k])) $data['theme'][$k] = $defaults[$k];
        }
        $message = 'success:Colors reset to default.';