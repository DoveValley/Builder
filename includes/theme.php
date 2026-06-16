<?php
function theme_css_vars($theme) {
    $map = [
        '--color-header-bg'     => $theme['header_bg']     ?? '#120575',
        '--color-header-top-bg' => $theme['header_top_bg'] ?? '#ffffff',
        '--color-header-text'   => $theme['header_text']   ?? '#ffffff',
        '--color-content-bg'    => $theme['content_bg']    ?? '#ffffff',
        '--color-content-text'  => $theme['content_text']  ?? '#000000',
        '--color-heading'       => $theme['heading_color'] ?? '#000000',
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
