<?php
/**
 * A stored theme font value is the admin dropdown's DISPLAY label — for every font
 * except this one, that's also the real CSS family name Google Fonts serves under.
 * "Source Sans Pro" is the exception: Google renamed the family to "Source Sans 3"
 * (site-template.php's $gfMap requests the new name), but the display label was never
 * updated to match. A --font-primary/--font-heading of "Source Sans Pro" doesn't match
 * any @font-face the page actually loads (all registered as "Source Sans 3"), so the
 * browser falls straight through to plain sans-serif — every site configured with this
 * font has been silently rendering in the OS default the whole time, never the brand
 * font, despite correctly downloading it. Confirmed live: baileyrestoration.com's own
 * document.fonts lists only "Source Sans 3" entries, none named "Source Sans Pro".
 */
const THEME_FONT_FAMILY_ALIASES = ['Source Sans Pro' => 'Source Sans 3'];

/**
 * Vertical metrics (ascent/descent/line-gap, as a % of em) for every Google Font this
 * fleet's theme picker offers — extracted directly from each family's own actual woff2
 * (fontTools, OS/2.sTypoAscender/Descender/LineGap where fsSelection's USE_TYPO_METRICS
 * bit is set, else hhea, both ÷ unitsPerEm). Paired with a matching local system fallback
 * in site-template.php's @font-face block, so a page can declare the fallback face with
 * these SAME overrides — matching the browser's line-box height to the real webfont's,
 * so swapping between them (font-display: optional's whole reason to exist) moves zero
 * pixels, regardless of how the timing of that swap plays out on any given network. Every
 * font in $gfMap (site-template.php) should have an entry here; one silently missing just
 * means that family's swap goes back to being timing-sensitive, not broken.
 */
const GF_FONT_METRICS = [
    'Open Sans'         => ['ascentOverride' => 106.88, 'descentOverride' => 29.30, 'lineGapOverride' => 0],
    'Noto Serif'        => ['ascentOverride' => 106.90, 'descentOverride' => 29.30, 'lineGapOverride' => 0],
    'Roboto'            => ['ascentOverride' => 92.77,  'descentOverride' => 24.41, 'lineGapOverride' => 0],
    'Lato'              => ['ascentOverride' => 98.70,  'descentOverride' => 21.30, 'lineGapOverride' => 0],
    'Montserrat'        => ['ascentOverride' => 96.80,  'descentOverride' => 25.10, 'lineGapOverride' => 0],
    'Raleway'           => ['ascentOverride' => 94.00,  'descentOverride' => 23.40, 'lineGapOverride' => 0],
    'Poppins'           => ['ascentOverride' => 105.00, 'descentOverride' => 35.00, 'lineGapOverride' => 10],
    'Nunito'            => ['ascentOverride' => 101.10, 'descentOverride' => 35.30, 'lineGapOverride' => 0],
    'Mulish'            => ['ascentOverride' => 100.50, 'descentOverride' => 25.00, 'lineGapOverride' => 0],
    'Inter'             => ['ascentOverride' => 96.88,  'descentOverride' => 24.12, 'lineGapOverride' => 0],
    'Outfit'            => ['ascentOverride' => 100.00, 'descentOverride' => 26.00, 'lineGapOverride' => 0],
    'Source Sans 3'     => ['ascentOverride' => 102.40, 'descentOverride' => 40.00, 'lineGapOverride' => 0],
    'Inclusive Sans'    => ['ascentOverride' => 95.00,  'descentOverride' => 25.00, 'lineGapOverride' => 0],
    'Playfair Display'  => ['ascentOverride' => 108.20, 'descentOverride' => 25.10, 'lineGapOverride' => 0],
    'Merriweather'      => ['ascentOverride' => 98.40,  'descentOverride' => 27.30, 'lineGapOverride' => 0],
];

/** Serif families in GF_FONT_METRICS — the metric-matched fallback should still be a
 *  system serif (Georgia), not Arial, or the pre-swap flash reads as the wrong typeface
 *  family even though the box height is already correct. */
const GF_SERIF_FAMILIES = ['Noto Serif', 'Playfair Display', 'Merriweather'];

/**
 * A stored font value is a full CSS stack ("Source Sans Pro, sans-serif"), not a bare
 * name — resolves the alias + splices in the metric-matched fallback (see
 * THEME_FONT_FAMILY_ALIASES / GF_FONT_METRICS above) against just its FIRST segment,
 * then re-attaches whatever else was already in the stack.
 */
function theme_resolve_font_stack(string $stack): string {
    $parts = array_map(fn($p) => trim($p, " '\""), explode(',', $stack));
    $baseName = $parts[0] ?? '';
    $realName = THEME_FONT_FAMILY_ALIASES[$baseName] ?? $baseName;
    $rest = array_slice($parts, 1);
    if (isset(GF_FONT_METRICS[$realName])) {
        array_unshift($rest, "{$realName} Fallback");
    }
    return implode(', ', array_merge([$realName], $rest));
}

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
    // Font families. A recognized Google Font gets its metric-matched fallback name
    // spliced in right after the real name (see GF_FONT_METRICS) — the corresponding
    // @font-face lives in site-template.php, which is the piece that actually knows
    // which Google fonts this page requested. The stored value is a full CSS stack
    // ("Source Sans Pro, sans-serif"), not a bare name — alias/metrics lookups must
    // match on just its first segment, not the whole string.
    if (preg_match('/^[a-zA-Z0-9\s,\-]+$/', $font)) {
        $fontOut = theme_resolve_font_stack($font);
        $css .= "    --font-primary: {$fontOut};\n";
    } else {
        $css .= "    --font-primary: sans-serif;\n";
    }
    $headingFont = $theme['heading_font'] ?? '';
    if ($headingFont !== '' && preg_match('/^[a-zA-Z0-9\s,\-]+$/', $headingFont)) {
        $headingFontOut = theme_resolve_font_stack($headingFont);
        $css .= "    --font-heading: {$headingFontOut};\n";
    }
    $headingWeight = (string)($theme['heading_weight'] ?? '700');
    $headingWeight = in_array($headingWeight, ['400','500','600','700','800','900'], true) ? $headingWeight : '700';
    $css .= "    --font-weight-heading: {$headingWeight};\n";
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
    // Supporting-text sizes (rem, non-fluid): lead = subtitles / prominent secondary,
    // small = captions / descriptions / secondary text, eyebrow = badges / micro-labels.
    // These let every block size be a Theme-tab setting (no hardcoded rem in block CSS).
    foreach (['lead'=>'1.15','small'=>'0.9','eyebrow'=>'0.75'] as $tag => $def) {
        $val = $theme["font_size_{$tag}"] ?? $def;
        $num = preg_replace('/[^0-9.]/', '', (string)$val);
        $num = $num !== '' ? (float)$num : (float)$def;
        $num = max(0.5, min(3.0, $num));
        $css .= "    --font-size-{$tag}: {$num}rem;\n";
    }
    // Skin system — 4 named section palettes
    $skinDefaults = [
        'light'  => ['bg' => '#ffffff', 'heading' => '#1a2e5a', 'text' => '#555e6d'],
        'dark'   => ['bg' => '#0d1f3c', 'heading' => '#ffffff',  'text' => '#e2e8f0'],
        'accent' => ['bg' => '#2563eb', 'heading' => '#ffffff',  'text' => '#dbeafe'],
        'subtle' => ['bg' => '#f8fafc', 'heading' => '#1a2e5a',  'text' => '#555e6d'],
    ];
    $skins = $theme['skins'] ?? [];
    // Which skin properties track a main brand color automatically, same as
    // accent.bg already did, instead of sitting in the JSON as an independent value
    // nothing keeps in sync. Real, live bug: pest/water/appliance all rebranded
    // heading_color at some point but skins.light/subtle.heading were never
    // updated, so whole sections kept showing an old, unrelated heading color next
    // to the current brand color. Auto-deriving these closes the gap by
    // construction — there is no second copy of the color left to forget to update.
    //
    // dark.bg deliberately does NOT auto-track header_bg the way this reasoning
    // would suggest: the $theme passed in here may already have header_bg
    // OVERWRITTEN to follow the header bar's own nav_bg color (see
    // site-template.php's "header-bg follows nav-bg" logic, itself intentional)
    // — when nav_bg tracks the accent color, that would make the Dark skin
    // (meant to be a dramatic, usually-dark section) equal the Accent skin.
    // Confirmed live: pest-template's dark.bg came out amber, identical to
    // accent.bg, the instant this was tried. Left as an independently-set value.
    $autoTrack = [
        'accent' => ['bg' => $theme['accent_color']  ?? '#fd783b'],
        'light'  => ['heading' => $theme['heading_color'] ?? '#1a2e5a'],
        'subtle' => ['heading' => $theme['heading_color'] ?? '#1a2e5a'],
    ];
    foreach ($skinDefaults as $name => $defaults) {
        $s = $skins[$name] ?? [];
        foreach (['bg', 'heading', 'text'] as $prop) {
            if (isset($autoTrack[$name][$prop])) {
                $safe = preg_replace('/[^#a-zA-Z0-9(),.%\s\-_]/', '', $autoTrack[$name][$prop]);
                $css .= "    --skin-{$name}-{$prop}: {$safe};\n";
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
    // Read $data['theme'] fresh on every call — do NOT cache it in a `static` across calls.
    // A multisite build (run_campaign.php/build_one.php/render_site.php) renders many pages,
    // and sometimes many different sites, in ONE continuous PHP process; a `static` cache
    // freezes whatever $data['theme'] was at the very FIRST call for the rest of that
    // process's lifetime. This was a real, live bug: pest-template's hero_grid tiles kept
    // rendering the hardcoded '#120575' fallback (an old, pre-rebrand navy) site-wide even
    // though the theme's real header_bg was green — some earlier resolve_color() call in the
    // same build had cached an empty/stale theme before this page's $data was in scope.
    global $data;
    $theme = $data['theme'] ?? [];
    if (empty($theme)) {
        // DATA_FILE, not a hardcoded legacy path — this must resolve to whatever site is
        // actually active (worker clone, session-selected multisite, or true single-site),
        // the same reasoning the big comment above already established for not caching
        // $data['theme'] in a static. A hardcoded path here would silently read the wrong
        // site's theme in exactly the same way, just on the cold-fallback branch instead
        // of on every call.
        $file = defined('DATA_FILE') ? DATA_FILE : __DIR__ . '/../data/site.json';
        if (file_exists($file)) {
            $d = json_decode(file_get_contents($file), true);
            $theme = $d['theme'] ?? [];
        }
    }
    if ($which === 'accent')    return $theme['accent_color']  ?? '#fd783b';
    if ($which === 'highlight') return 'var(--color-highlight)';
    if ($which === 'heading')   return 'var(--color-heading)';
    if ($which === 'dark')      return 'var(--skin-dark-bg)';
    if ($which === 'header')   return $theme['header_bg']    ?? '#120575';
    if ($which === 'footer')   return $theme['footer_bg']    ?? '#120575';
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
