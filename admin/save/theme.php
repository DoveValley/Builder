<?php
        $activeTab = 'genvisual';
        // header_bg (container/dropdowns) now auto-follows the header bar color at render;
        // header_text is driven by the merged "Header bar text" control below — both omitted here.
        $colorKeys = ['header_top_bg','content_bg','footer_bg','footer_text','accent_color','accent2_color','btn_text','border_color'];
        foreach ($colorKeys as $key) {
            $value = trim($_POST[$key] ?? '');
            if (preg_match('/^#[0-9a-fA-F]{6}$/', $value)) $data['theme'][$key] = $value;
        }
        // Header bar color (merged, option A) — 'accent' mode follows the brand, else a custom hex.
        // Lives in data['header']['nav_bg']; the header partials + sticky bars read it, and
        // site-template.php makes --color-header-bg (dropdowns/container) follow it.
        $navMode = ($_POST['nav_bg_mode'] ?? 'accent') === 'custom' ? 'custom' : 'accent';
        if ($navMode === 'custom') {
            $navHex = trim($_POST['nav_bg_custom'] ?? '');
            if (preg_match('/^#[0-9a-fA-F]{6}$/', $navHex)) $data['header']['nav_bg'] = $navHex;
        } else {
            $data['header']['nav_bg'] = 'accent';
        }
        // Header bar text — one control drives both the bar (nav_text) and menu links (theme.header_text).
        $navText = trim($_POST['nav_text'] ?? '');
        if (preg_match('/^#[0-9a-fA-F]{6}$/', $navText)) {
            $data['header']['nav_text']  = $navText;
            $data['theme']['header_text'] = $navText;
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
        foreach (['lead','small','eyebrow'] as $tag) {
            $val = (float)($_POST['font_size_'.$tag] ?? 0);
            if ($val > 0) $data['theme']['font_size_'.$tag] = max(0.5, min(3.0, round($val, 2)));
        }
        // Button radius
        $radius = (int)($_POST['button_radius'] ?? 4);
        $data['theme']['button_radius'] = max(0, min(50, $radius));
        // Skin colors — accent.bg, dark.bg, and light/subtle.heading are all derived from a
        // main brand color at render time (theme_css_vars()), not saved separately, so they
        // can no longer drift from the theme the way they used to (see genvisual.php's tab).
        $skinProps = [
            'light'  => ['bg','text'],
            'subtle' => ['bg','text'],
            'accent' => ['heading','text'],
            'dark'   => ['bg','heading','text'],
        ];
        foreach ($skinProps as $skinKey => $props) {
            foreach ($props as $prop) {
                $fkey = "skin_{$skinKey}_{$prop}";
                $val  = trim($_POST[$fkey] ?? '');
                if (preg_match('/^#[0-9a-fA-F]{6}$/', $val)) {
                    $data['theme']['skins'][$skinKey][$prop] = $val;
                }
            }
        }
        // theme.heading_color used to be synced FROM skins.light.heading here — backwards
        // now that light/subtle.heading are themselves derived FROM heading_color at render
        // time (theme_css_vars()). heading_color has no dedicated admin field of its own (it
        // never did); it's read by the generated wordmark logo and otherwise just needs to
        // stay whatever it was last set to (matches header_bg on every real site currently).
        // Analytics snippets — stored as-is (admin only, trusted input)
        $data['theme']['analytics_head']  = $_POST['analytics_head']  ?? '';
        $data['theme']['facebook_pixel']  = $_POST['facebook_pixel']  ?? '';
        // Same field the multisite batch pipeline writes (ms_gsc_meta(),
        // includes/multisite/differentiate.php) — this was the one tracking field
        // with no manual UI at all; a single site could only get one by hand-editing
        // site.json directly.
        $data['theme']['head_extra']      = $_POST['head_extra']      ?? '';