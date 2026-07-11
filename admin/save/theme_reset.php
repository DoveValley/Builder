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
        // The header bar is driven by header.nav_bg / header.nav_text (see save/theme.php),
        // not the theme.* keys above — reset those too or the bar keeps its custom colors.
        $data['header']['nav_bg']   = 'accent';
        $data['header']['nav_text'] = $defaults['header_text'] ?? '#ffffff';
        $message = 'success:Colors reset to default.';