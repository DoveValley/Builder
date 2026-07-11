<?php
function theme_css_vars($theme) {
    $map = [
        '--color-header-bg'     => $theme['header_bg']     ?? '#120575',
        '--color-header-top-bg' => $theme['header_top_bg'] ?? '#ffffff',
        '--color-header-text'   => $theme['header_text']   ?? '#ffffff',
        '--color-content-bg'    => $theme['content_bg']    ?? '#ffffff',
        '--color-content-text'  => 'var(--skin-light-text)',    // cascades from Light skin
        '--color-heading'       => 'var(--skin-light-heading)', // cascades from Light skin
        '--color-section-alt'   => 'var(--skin-subtle-bg)',     // cascades from Subtle skin
        '--color-footer-bg'     => $theme['footer_bg']     ?? '#120575',
        '--color-footer-text'   => $theme['footer_text']   ?? '#ffffff',
        '--color-accent'        => $theme['accent_color']  ?? '#fd783b',
        '--color-highlight'     => $theme['accent2_color'] ?? '#f5a623',
        '--color-btn-text'      => $theme['btn_text']      ?? '#ffffff',
        '--color-border'        => $theme['border_color']  ?? '#e5e7eb',
        // Shared semantic colors (Phase 3): centralize values that blocks used to
        // hardcode, so they theme consistently. Defaults preserve the prior look.
        '--color-success'       => $theme['success_color'] ?? '#16a34a', // checks, "winner" column, result badges
        '--color-media-fallback'=> $theme['media_fallback']?? '#1a1a2e', // bg behind blocks with no image set
        '--color-muted'         => $theme['muted_color']   ?? '#6b7280', // captions, secondary text
        '--btn-radius'          => ($theme['button_radius'] ?? '5') . 'px',
    ];
    $font = $theme['primary_font'] ?? ($theme['font_family'] ?? 'sans-serif');
    $css = ":root {\n";
    foreach ($map as $var => $value) {
        $safe = preg_replace('/[^#a-zA-Z0-9(),.%\s\-_]/', '', $value);
        $css .= "    {$var}: {$safe};\n";
    }
    // Font families
    if (preg_match('/^[a-zA-Z0-9\s,\-]+$/', $font)) {
        $css .= "    --font-primary: {$font};\n";
    } else {
        $css .= "    --font-primary: sans-serif;\n";
    }
    $headingFont = $theme['heading_font'] ?? '';
    if ($headingFont !== '' && preg_match('/^[a-zA-Z0-9\s,\-]+$/', $headingFont)) {
        $css .= "    --font-heading: {$headingFont};\n";
    }
    // Font sizes (rem for headings, px for body)
    $bodyPx = max(12, min(24, (int)($theme['font_size_body'] ?? 16)));
    $css .= "    --font-size-body: {$bodyPx}px;\n";
    foreach (['h1'=>'2.5','h2'=>'2','h3'=>'1.75','h4'=>'1.5'] as $tag => $def) {
        $val = $theme["font_size_{$tag}"] ?? $def;
        $num = preg_replace('/[^0-9.]/', '', (string)$val);
        $num = $num !== '' ? (float)$num : (float)$def;
        $num = max(0.5, min(6.0, $num));
        // Fluid sizing: the theme value is the desktop ceiling; headings scale down on
        // narrow viewports so long words (e.g. "Certification") don't break mid-word on
        // phones. Desktop (>=~1000px) is unchanged — clamp caps at the theme value.
        $floor = round(min($num, max(1.15, $num * 0.55)), 3);
        $vw    = round($num * 1.6, 3);
        $css .= "    --font-size-{$tag}: clamp({$floor}rem, {$vw}vw, {$num}rem);\n";
    }
    // Skin system — 4 named section palettes
    $skinDefaults = [
        'light'  => ['bg' => '#ffffff', 'heading' => '#1a2e5a', 'text' => '#555e6d'],
        'dark'   => ['bg' => '#0d1f3c', 'heading' => '#ffffff',  'text' => '#e2e8f0'],
        'accent' => ['bg' => '#2563eb', 'heading' => '#ffffff',  'text' => '#dbeafe'],
        'subtle' => ['bg' => '#f8fafc', 'heading' => '#1a2e5a',  'text' => '#555e6d'],
    ];
    $skins = $theme['skins'] ?? [];
    foreach ($skinDefaults as $name => $defaults) {
        $s = $skins[$name] ?? [];
        foreach (['bg', 'heading', 'text'] as $prop) {
            // Accent skin background tracks the primary accent color automatically
            if ($name === 'accent' && $prop === 'bg') {
                $css .= "    --skin-accent-bg: var(--color-accent);\n";
                continue;
            }
            $val = $s[$prop] ?? $defaults[$prop];
            $safe = preg_replace('/[^#a-zA-Z0-9(),.%\s\-_]/', '', $val);
            $css .= "    --skin-{$name}-{$prop}: {$safe};\n";
        }
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
    if ($which === 'accent')    return $themeCache['accent_color']  ?? '#fd783b';
    if ($which === 'highlight') return 'var(--color-highlight)';
    if ($which === 'heading')   return 'var(--color-heading)';
    if ($which === 'dark')      return 'var(--skin-dark-bg)';
    if ($which === 'header')   return $themeCache['header_bg']    ?? '#120575';
    if ($which === 'footer')   return $themeCache['footer_bg']    ?? '#120575';
    return $custom ?: '#333333';
}

/**
 * Render a shared color-mode <select> for the block editor.
 *
 * Admin-UI only: emits the standard {accent, header, footer, custom} option set
 * used by ~19 block color pickers. The matching resolve_color() renderer already
 * supports every one of these values, so this does not change how any block renders.
 *
 * @param string $name    The field name (without the trailing "[]", which is added).
 * @param string $current The currently stored value (pass $block['field'] ?? $default).
 * @param string $default Fallback selection when $current is empty/unrecognized.
 */
function color_mode_select(string $name, string $current, string $default = 'accent'): string {
    $modes = ['accent' => 'Accent (global)', 'header' => 'Header (global)', 'footer' => 'Footer (global)', 'custom' => 'Custom'];
    $val = array_key_exists($current, $modes) ? $current : $default;
    $out = '<select name="' . htmlspecialchars($name) . '[]">';
    foreach ($modes as $v => $label) {
        $out .= '<option value="' . $v . '"' . ($val === $v ? ' selected' : '') . '>' . $label . '</option>';
    }
    return $out . '</select>';
}
