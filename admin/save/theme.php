        $activeTab = 'theme';
        $colorKeys = ['header_bg','header_top_bg','header_text','content_bg','content_text','heading_color','footer_bg','footer_text','accent_color'];
        foreach ($colorKeys as $key) {
            $value = trim($_POST[$key] ?? '');
            if (preg_match('/^#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$/', $value)) $data['theme'][$key] = $value;
        }
        // Font — allow any safe font name (letters, numbers, spaces, commas, hyphens)
        $font = trim($_POST['primary_font'] ?? '');
        if ($font !== '' && preg_match('/^[a-zA-Z0-9\s,\-]+$/', $font)) {
            $data['theme']['primary_font'] = $font;
            $data['theme']['font_family']  = $font; // keep alias in sync
        }
        // Button radius
        $radius = (int)($_POST['button_radius'] ?? 4);
        $data['theme']['button_radius'] = max(0, min(50, $radius));
        // Analytics snippets — stored as-is (admin only, trusted input)
        $data['theme']['analytics_head']  = $_POST['analytics_head']  ?? '';
        $data['theme']['facebook_pixel']  = $_POST['facebook_pixel']  ?? '';
