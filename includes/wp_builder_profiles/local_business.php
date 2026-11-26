<?php
/**
 * includes/wp_builder_profiles/local_business.php — the original WP Builder
 * recipe: a plain 5-page local-service-business site (Home/About/Services/
 * Testimonials/Contact). Extracted unchanged from the pre-profile
 * includes/wp_builder.php so this refactor produces byte-identical sites —
 * every block body below is the same code that was already live.
 */

const WPB_LB = 'local-business';

function wpb_lb_default_services(string $niche): array
{
    $n = ucwords($niche);
    return [
        "General $n Services",
        "Emergency $n Service",
        "Residential $n",
        "Commercial $n",
    ];
}

function wpb_lb_default_testimonials(array $d): array
{
    $biz = $d['business_name'];
    $city = $d['city'];
    return [
        ['quote' => "$biz did great work and explained everything clearly before they started.", 'name' => 'Sample Customer', 'city' => $city],
        ['quote' => "Showed up on time, fair price, no surprises on the invoice. Would call them again.", 'name' => 'Sample Customer', 'city' => $city],
        ['quote' => "Everyone we've dealt with at $biz has been professional and easy to reach.", 'name' => 'Sample Customer', 'city' => $city],
    ];
}

/** The 3 "why us" points shown unless the why_us_points field overrides them — kept as the
 * fallback for any niche that doesn't bother filling that field in, same as default_services/
 * default_testimonials above. */
function wpb_lb_default_why_us(): array
{
    return [
        ['title' => 'Licensed & Insured', 'body' => 'Fully licensed, insured, and background-checked — every job, every time.'],
        ['title' => 'Fast Response', 'body' => "We answer the phone and get back to you quickly, not days later."],
        ['title' => 'Upfront Pricing', 'body' => "You'll know the price before any work starts. No surprises on the invoice."],
    ];
}

/** Parses the inputs collected from the form into the normalized shape every block/seo/site_options callback expects. */
function wpb_lb_normalize(array $post): array
{
    $d = [];
    foreach (['business_name', 'niche', 'phone', 'city', 'state', 'primary_color', 'accent_color', 'tagline', 'email', 'services', 'testimonials', 'why_us_points'] as $key) {
        $d[$key] = trim((string) ($post[$key] ?? ''));
    }
    if ($d['tagline'] === '') {
        $d['tagline'] = 'Serving ' . $d['city'] . ' and the surrounding area';
    }
    if ($d['email'] === '') {
        $d['email'] = 'info@' . wpb_slug($d['business_name']) . '.com';
    }
    // One service per line now (was comma-separated) so a real description can
    // follow a "|", same line-based pattern testimonials already used below —
    // a plain comma list still works fine as long as nobody's description
    // itself contains a "|". A line with no "|" falls back to the generic
    // boilerplate sentence wpb_lb_block_services_grid() always used to
    // generate for every niche; this is purely additive.
    if ($d['services'] !== '') {
        $parsed = [];
        foreach (array_filter(array_map('trim', explode("\n", str_replace(',', "\n", $d['services'])))) as $line) {
            $parts = array_map('trim', explode('|', $line, 2));
            if ($parts[0] ?? '') $parsed[] = ['name' => $parts[0], 'description' => $parts[1] ?? ''];
        }
        $d['services_list'] = $parsed;
    } else {
        $d['services_list'] = array_map(fn($n) => ['name' => $n, 'description' => ''], wpb_lb_default_services($d['niche']));
    }

    if ($d['testimonials'] !== '') {
        $lines = array_filter(array_map('trim', explode("\n", $d['testimonials'])));
        $parsed = [];
        foreach ($lines as $line) {
            $parts = array_map('trim', explode('|', $line));
            if ($parts[0] ?? '') {
                $parsed[] = ['quote' => $parts[0], 'name' => $parts[1] ?? 'Customer', 'city' => $parts[2] ?? $d['city']];
            }
        }
        $d['testimonials_list'] = $parsed ?: wpb_lb_default_testimonials($d);
    } else {
        $d['testimonials_list'] = wpb_lb_default_testimonials($d);
    }

    // "Title | Description" per line, same line-based pattern as above —
    // blank = the 3 generic contractor-flavored points every niche got before
    // this field existed (home-trades-appropriate, but a real mismatch for
    // e.g. a med spa or law firm, found 2026-11-26 trying to build one).
    if ($d['why_us_points'] !== '') {
        $lines = array_filter(array_map('trim', explode("\n", $d['why_us_points'])));
        $parsed = [];
        foreach ($lines as $line) {
            $parts = array_map('trim', explode('|', $line, 2));
            if ($parts[0] ?? '') $parsed[] = ['title' => $parts[0], 'body' => $parts[1] ?? ''];
        }
        $d['why_us_points_list'] = $parsed ?: wpb_lb_default_why_us();
    } else {
        $d['why_us_points_list'] = wpb_lb_default_why_us();
    }

    if ($d['primary_color'] === '') $d['primary_color'] = '#1a2b49';
    if ($d['accent_color'] === '') $d['accent_color'] = '#2563eb';

    return $d;
}

// ---- Blocks — plain core blocks only, styled via className hooks into wp-builder-site.php's one stylesheet. ----

function wpb_lb_block_hero(array $d, array $ctx): string
{
    $heroPhotoUrl = $ctx['photos']['hero'] ?? '';
    $out = wpb_group_open('wpb-section');
    $out .= '<!-- wp:columns --><div class="wp-block-columns"><!-- wp:column --><div class="wp-block-column">';
    $out .= wpb_heading($d['business_name'], 1);
    $out .= wpb_paragraph($d['tagline']);
    $out .= wpb_button('Call ' . $d['phone'], 'tel:' . preg_replace('/[^0-9+]/', '', $d['phone']), $d['accent_color']);
    $out .= "</div><!-- /wp:column --><!-- wp:column --><div class=\"wp-block-column\">";
    $out .= wpb_image($heroPhotoUrl, $d['business_name']);
    $out .= "</div><!-- /wp:column --></div><!-- /wp:columns -->\n\n";
    $out .= wpb_group_close();
    return $out;
}

function wpb_lb_block_why_us(array $d, array $ctx): string
{
    $out = wpb_group_open('wpb-section wpb-section-alt');
    $out .= wpb_heading('Why ' . $d['city'] . ' Chooses Us', 2);
    $out .= '<!-- wp:group {"className":"wpb-grid3"} --><div class="wp-block-group wpb-grid3">';
    foreach ($d['why_us_points_list'] as $point) {
        $out .= '<div>' . wpb_heading($point['title'], 3) . wpb_paragraph($point['body']) . '</div>';
    }
    $out .= "</div>\n<!-- /wp:group -->\n\n";
    $out .= wpb_group_close();
    return $out;
}

function wpb_lb_block_cta_band(array $d, array $ctx): string
{
    $out = '<!-- wp:group {"className":"wpb-cta-band"} --><div class="wp-block-group wpb-cta-band">';
    $out .= wpb_heading('Ready to get started?', 2);
    $out .= wpb_paragraph('Call ' . $d['business_name'] . ' now at ' . $d['phone']);
    $out .= "</div>\n<!-- /wp:group -->\n\n";
    return $out;
}

function wpb_lb_block_services_intro(array $d, array $ctx): string
{
    $out = wpb_group_open('wpb-section');
    $out .= wpb_heading('What We Do', 2);
    $out .= wpb_paragraph($d['business_name'] . ' provides ' . strtolower($d['niche']) . ' services for homes and businesses throughout ' . $d['city'] . ', ' . $d['state'] . '. Call ' . $d['phone'] . ' for a clear price before any work begins.');
    $out .= wpb_group_close();
    return $out;
}

function wpb_lb_block_services_grid(array $d, array $ctx): string
{
    $out = wpb_group_open('wpb-section wpb-section-alt');
    $out .= '<!-- wp:group {"className":"wpb-grid3"} --><div class="wp-block-group wpb-grid3">';
    foreach ($d['services_list'] as $service) {
        $body = $service['description'] !== ''
            ? $service['description']
            : 'Call ' . $d['phone'] . ' to schedule ' . strtolower($service['name']) . ' in ' . $d['city'] . '.';
        $out .= '<div class="wpb-card">' . wpb_heading($service['name'], 3) . wpb_paragraph($body) . '</div>';
    }
    $out .= "</div>\n<!-- /wp:group -->\n\n";
    $out .= wpb_group_close();
    return $out;
}

function wpb_lb_block_about(array $d, array $ctx): string
{
    $aboutPhotoUrl = $ctx['photos']['about'] ?? ($ctx['photos']['hero'] ?? '');
    $out = wpb_group_open('wpb-section');
    $out .= '<!-- wp:columns --><div class="wp-block-columns"><!-- wp:column --><div class="wp-block-column">';
    $out .= wpb_heading('About ' . $d['business_name'], 1);
    $out .= wpb_paragraph($d['business_name'] . ' is a locally owned ' . strtolower($d['niche']) . ' business serving ' . $d['city'] . ', ' . $d['state'] . ' and the surrounding area. We show up on time, explain the work in plain language, and stand behind what we do.');
    $out .= wpb_paragraph('Call us at ' . $d['phone'] . ' or email ' . $d['email'] . ' to get started.');
    $out .= "</div><!-- /wp:column --><!-- wp:column --><div class=\"wp-block-column\">";
    $out .= wpb_image($aboutPhotoUrl, $d['business_name']);
    $out .= "</div><!-- /wp:column --></div><!-- /wp:columns -->\n\n";
    $out .= wpb_group_close();
    return $out;
}

function wpb_lb_block_contact(array $d, array $ctx): string
{
    $mapQuery = rawurlencode($d['city'] . ', ' . $d['state']);
    $out = wpb_group_open('wpb-section');
    $out .= wpb_heading('Contact ' . $d['business_name'], 1);
    $out .= '<!-- wp:columns --><div class="wp-block-columns"><!-- wp:column --><div class="wp-block-column">';
    $out .= wpb_paragraph('Phone: ' . $d['phone']);
    $out .= wpb_paragraph('Email: ' . $d['email']);
    $out .= wpb_paragraph('Serving ' . $d['city'] . ', ' . $d['state']);
    $out .= "</div><!-- /wp:column --><!-- wp:column --><div class=\"wp-block-column\">";
    $out .= '<!-- wp:html --><iframe src="https://www.google.com/maps?q=' . $mapQuery . '&output=embed" width="100%" height="320" style="border:0;border-radius:8px;" loading="lazy"></iframe><!-- /wp:html -->' . "\n\n";
    $out .= "</div><!-- /wp:column --></div><!-- /wp:columns -->\n\n";
    $out .= wpb_group_close();
    return $out;
}

function wpb_lb_block_testimonials(array $d, array $ctx): string
{
    $out = wpb_group_open('wpb-section wpb-section-alt');
    $out .= wpb_heading('What Our Customers Say', 1);
    $out .= '<!-- wp:group {"className":"wpb-grid3"} --><div class="wp-block-group wpb-grid3">';
    foreach ($d['testimonials_list'] as $t) {
        $initials = strtoupper(substr($t['name'], 0, 1) . substr(strrchr(' ' . $t['name'], ' '), 1, 1));
        $out .= '<div class="wpb-card">'
            . wpb_paragraph('"' . $t['quote'] . '"')
            . '<div style="display:flex;align-items:center;gap:10px;margin-top:12px;">'
            . '<div class="wpb-avatar">' . h($initials) . '</div>'
            . '<div><strong>' . h($t['name']) . '</strong><br><span style="font-size:.85rem;color:#6b7280;">' . h($t['city']) . '</span></div>'
            . '</div></div>';
    }
    $out .= "</div>\n<!-- /wp:group -->\n\n";
    $out .= wpb_group_close();
    return $out;
}

/** Per-page title + meta description — no SEO plugin needed, read by wp-builder-site.php's wp_head hook. */
function wpb_lb_seo(array $d, string $page): array
{
    $biz = $d['business_name'];
    $loc = $d['city'] . ', ' . $d['state'];
    $niche = $d['niche'];
    switch ($page) {
        case 'home':
            return [$biz . ' | ' . ucwords($niche) . ' in ' . $loc, "$biz provides $niche services in $loc. Call $d[phone] for honest pricing and fast response."];
        case 'about':
            return ['About Us | ' . $biz, "Learn about $biz, a locally owned $niche business serving $loc."];
        case 'services':
            return ['Services | ' . $biz, "$biz offers " . implode(', ', array_slice(array_column($d['services_list'], 'name'), 0, 3)) . " and more in $loc."];
        case 'contact':
            return ['Contact Us | ' . $biz, "Contact $biz at $d[phone] or $d[email]. Serving $loc."];
        case 'testimonials':
            return ['Testimonials | ' . $biz, "See what customers in $loc say about $biz."];
    }
    return [$biz, ''];
}

function wpb_lb_site_options(array $d, array $photos = []): array
{
    return [
        'wpb_business_name' => $d['business_name'],
        'wpb_niche'         => $d['niche'],
        'wpb_tagline'       => $d['tagline'],
        'wpb_phone'         => $d['phone'],
        'wpb_email'         => $d['email'],
        'wpb_city'          => $d['city'],
        'wpb_state'         => $d['state'],
        'wpb_primary_color' => $d['primary_color'],
        'wpb_accent_color'  => $d['accent_color'],
        'wpb_created_year'  => date('Y'),
        'wpb_services'      => $d['services_list'],
        'wpb_schema_type'   => 'LocalBusiness',
        // Fallback social-share image for any page without its own featured image.
        'wpb_og_image'      => $photos['hero'] ?? '',
    ];
}

foreach ([
    'hero'            => 'wpb_lb_block_hero',
    'why_us'          => 'wpb_lb_block_why_us',
    'cta_band'        => 'wpb_lb_block_cta_band',
    'services_intro'  => 'wpb_lb_block_services_intro',
    'services_grid'   => 'wpb_lb_block_services_grid',
    'about'           => 'wpb_lb_block_about',
    'contact'         => 'wpb_lb_block_contact',
    'testimonials'    => 'wpb_lb_block_testimonials',
] as $blockKey => $fn) {
    wpb_register_block(WPB_LB, $blockKey, $fn);
}

wpb_register_profile(WPB_LB, [
    'label' => 'Local Business (5-page)',
    'description' => 'Builds a plain, 5-page WordPress site (Home, About, Services, Testimonials, '
        . 'Contact) on the demo box from the fields below — no page-builder plugin, no imported '
        . 'template, just real core-block content with a simple header/footer and basic on-page SEO. '
        . 'Meant as a realistic "existing customer site" for rehearsing the LPC plugin-pairing '
        . 'workflow. Edit the content afterward in that site\'s own WordPress admin.',
    'fields' => [
        'business_name' => ['required' => true, 'label' => 'Business Name'],
        'niche'         => ['required' => true, 'label' => 'Niche / Industry', 'placeholder' => 'e.g. plumbing'],
        'phone'         => ['required' => true, 'label' => 'Phone'],
        'city'          => ['required' => true, 'label' => 'City'],
        'state'         => ['required' => true, 'label' => 'State', 'maxlength' => 2, 'placeholder' => 'TX'],
        'tagline'       => ['required' => false, 'label' => 'Tagline', 'hint' => 'blank = auto'],
        'email'         => ['required' => false, 'label' => 'Email', 'hint' => 'blank = auto'],
        'services'      => ['required' => false, 'label' => 'Services', 'type' => 'textarea', 'rows' => 4, 'placeholder' => "Drain Cleaning | Fast response for clogged or slow drains...\nWater Heaters", 'hint' => 'one per line: Name, or Name | real description — blank line description = generic boilerplate, blank field = auto from niche'],
        'testimonials'  => ['required' => false, 'label' => 'Testimonials', 'type' => 'textarea', 'rows' => 3, 'placeholder' => 'Fixed our water heater same day. | Donald Hunter | Downtown Lufkin', 'hint' => 'one per line: Quote | Name | City — blank = sample quotes'],
        'why_us_points' => ['required' => false, 'label' => '"Why Us" Points', 'type' => 'textarea', 'rows' => 3, 'placeholder' => "Licensed Medical Staff | Every treatment is performed or supervised by a licensed provider.", 'hint' => 'one per line: Title | Description — blank = generic contractor-style points (Licensed & Insured, Fast Response, Upfront Pricing)'],
        'primary_color' => ['required' => true, 'default' => '#1a2b49', 'label' => 'Primary Color (footer)', 'type' => 'color'],
        'accent_color'  => ['required' => true, 'default' => '#2563eb', 'label' => 'Accent Color (buttons/links)', 'type' => 'color'],
    ],
    'photos' => [
        'hero'  => ['field' => 'hero_photo', 'label' => 'Hero / Home Photo', 'required_ui' => true],
        'about' => ['field' => 'about_photo', 'label' => 'About Photo', 'required_ui' => false, 'hint' => 'blank = reuse hero photo'],
    ],
    'tabs' => [
        ['label' => 'Business Info', 'fields' => ['business_name', 'niche', 'phone', 'city', 'state', 'tagline', 'email', 'services', 'why_us_points', 'testimonials']],
        ['label' => 'Design', 'fields' => ['primary_color', 'accent_color', 'hero', 'about']],
    ],
    'normalize' => 'wpb_lb_normalize',
    'site_title' => fn(array $d): string => $d['business_name'],
    'home_page' => 'home',
    'page_types' => [
        'home'         => ['slug' => 'home', 'title' => 'Home', 'blocks' => ['hero', 'why_us', 'cta_band'], 'seo' => fn(array $d) => wpb_lb_seo($d, 'home')],
        'about'        => ['slug' => 'about', 'title' => 'About', 'blocks' => ['about'], 'seo' => fn(array $d) => wpb_lb_seo($d, 'about')],
        'services'     => ['slug' => 'services', 'title' => 'Services', 'blocks' => ['services_intro', 'services_grid', 'cta_band'], 'seo' => fn(array $d) => wpb_lb_seo($d, 'services')],
        'contact'      => ['slug' => 'contact', 'title' => 'Contact', 'blocks' => ['contact'], 'seo' => fn(array $d) => wpb_lb_seo($d, 'contact')],
        'testimonials' => ['slug' => 'testimonials', 'title' => 'Testimonials', 'blocks' => ['testimonials'], 'seo' => fn(array $d) => wpb_lb_seo($d, 'testimonials')],
    ],
    'site_options' => 'wpb_lb_site_options',
    'chrome_dir' => null,
]);
