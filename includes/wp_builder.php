<?php
/**
 * includes/wp_builder.php — the "WP Builder" tool: creates a real, plain,
 * plugin-free (besides landing-page-client) 5-page WordPress site on the LPC
 * demo box from a small set of inputs, for use as an "existing customer site"
 * demo in the LPC product.
 *
 * Deliberately NOT a page-builder import (that's what caused nearly every bug
 * found rehearsing the original 12 demo sites — mismatched plugin versions,
 * leftover placeholder content, dead block types). Every page here is plain
 * core WordPress blocks (heading/paragraph/list/image/group/buttons), so it
 * renders correctly on any theme with zero page-builder plugin dependency,
 * and stays editable in the normal block editor afterward.
 *
 * Reuses lpc_demo_box.php's already-proven vhost+clone+search-replace
 * mechanism by cloning a dedicated blank seed site (demo-wpbuilder-seed,
 * Hello Elementor theme — confirmed to render NO header/footer of its own,
 * see wp-plugin/wp-builder-site) instead of one of the 12 themed niche sites.
 */
require_once __DIR__ . '/lpc_demo_box.php';

const WPB_SEED_NICHE = 'wpbuilder-seed';

/** Every input field this tool collects, with its default-fill-in behavior documented. Single source of truth — add a field here first. */
function wpb_field_defaults(): array
{
    return [
        'business_name' => ['required' => true],
        'niche'         => ['required' => true],
        'phone'         => ['required' => true],
        'city'          => ['required' => true],
        'state'         => ['required' => true],
        'primary_color' => ['required' => true, 'default' => '#1a2b49'],
        'accent_color'  => ['required' => true, 'default' => '#2563eb'],
        'tagline'       => ['required' => false],
        'email'         => ['required' => false],
        'services'      => ['required' => false], // comma-separated string from the form
        'testimonials'  => ['required' => false], // one per line, "Quote | Name | City"
    ];
}

function wpb_registry_file(): string
{
    return BASE_DIR . '/.wp_builder_sites.json';
}

function wpb_registry(): array
{
    return json_decode((string) @file_get_contents(wpb_registry_file()), true) ?: [];
}

function wpb_registry_save(string $domain, array $inputs): void
{
    $all = wpb_registry();
    $all[$domain] = $inputs + ['created_at' => $all[$domain]['created_at'] ?? date('c')];
    @file_put_contents(wpb_registry_file(), json_encode($all, JSON_PRETTY_PRINT));
}

function wpb_registry_delete(string $domain): void
{
    $all = wpb_registry();
    unset($all[$domain]);
    @file_put_contents(wpb_registry_file(), json_encode($all, JSON_PRETTY_PRINT));
}

/** List of sites this tool built, newest first, with a live running-check (same lightweight curl HEAD lpc_demo_list_sites uses). */
function wpb_list_sites(): array
{
    $all = wpb_registry();
    $out = [];
    foreach ($all as $domain => $data) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => 'http://' . LPC_DEMO_BOX_HOST . '/',
            CURLOPT_HTTPHEADER => ['Host: ' . $domain],
            CURLOPT_NOBODY => true,
            CURLOPT_TIMEOUT => 4,
            CURLOPT_RETURNTRANSFER => true,
        ]);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $out[] = $data + ['domain' => $domain, 'running' => in_array($code, [200, 301, 302], true)];
    }
    usort($out, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));
    return $out;
}

/** SSH output is often prefixed with a one-time "Permanently added ... to the list of known hosts" line (UserKnownHostsFile=/dev/null never persists one) — the real result is always the last non-empty line. */
function wpb_last_line(string $output): string
{
    $lines = array_values(array_filter(array_map('trim', explode("\n", $output)), fn($l) => $l !== ''));
    return $lines ? end($lines) : '';
}

function wpb_slug(string $text): string
{
    $s = strtolower(trim($text));
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    return trim($s, '-');
}

function wpb_default_services(string $niche): array
{
    $n = ucwords($niche);
    return [
        "General $n Services",
        "Emergency $n Service",
        "Residential $n",
        "Commercial $n",
    ];
}

function wpb_default_testimonials(array $d): array
{
    $biz = $d['business_name'];
    $city = $d['city'];
    return [
        ['quote' => "$biz did great work and explained everything clearly before they started.", 'name' => 'Sample Customer', 'city' => $city],
        ['quote' => "Showed up on time, fair price, no surprises on the invoice. Would call them again.", 'name' => 'Sample Customer', 'city' => $city],
        ['quote' => "Everyone we've dealt with at $biz has been professional and easy to reach.", 'name' => 'Sample Customer', 'city' => $city],
    ];
}

/** Parses the inputs collected from the form into the normalized shape every block generator expects. */
function wpb_normalize_input(array $post): array
{
    $d = [];
    foreach (array_keys(wpb_field_defaults()) as $key) {
        $d[$key] = trim((string) ($post[$key] ?? ''));
    }
    if ($d['tagline'] === '') {
        $d['tagline'] = 'Serving ' . $d['city'] . ' and the surrounding area';
    }
    if ($d['email'] === '') {
        $d['email'] = 'info@' . wpb_slug($d['business_name']) . '.com';
    }
    $d['services_list'] = $d['services'] !== ''
        ? array_values(array_filter(array_map('trim', explode(',', $d['services']))))
        : wpb_default_services($d['niche']);

    if ($d['testimonials'] !== '') {
        $lines = array_filter(array_map('trim', explode("\n", $d['testimonials'])));
        $parsed = [];
        foreach ($lines as $line) {
            $parts = array_map('trim', explode('|', $line));
            if ($parts[0] ?? '') {
                $parsed[] = ['quote' => $parts[0], 'name' => $parts[1] ?? 'Customer', 'city' => $parts[2] ?? $d['city']];
            }
        }
        $d['testimonials_list'] = $parsed ?: wpb_default_testimonials($d);
    } else {
        $d['testimonials_list'] = wpb_default_testimonials($d);
    }

    if ($d['primary_color'] === '') $d['primary_color'] = wpb_field_defaults()['primary_color']['default'];
    if ($d['accent_color'] === '') $d['accent_color'] = wpb_field_defaults()['accent_color']['default'];

    return $d;
}

// ---- Block generators — plain core blocks only, styled via className hooks into wp-builder-site.php's one stylesheet. ----

function wpb_heading(string $text, int $level = 2, string $className = ''): string
{
    $cls = $className ? ' ' . h($className) : '';
    $attrs = json_encode(array_filter(['level' => $level, 'className' => $className ?: null]));
    return "<!-- wp:heading {$attrs} -->\n<h{$level} class=\"wp-block-heading{$cls}\">" . h($text) . "</h{$level}>\n<!-- /wp:heading -->\n\n";
}

function wpb_paragraph(string $text, string $className = ''): string
{
    $cls = $className ? ' class="' . h($className) . '"' : '';
    $attrs = $className ? '{"className":"' . h($className) . '"}' : '';
    return "<!-- wp:paragraph {$attrs} -->\n<p{$cls}>" . h($text) . "</p>\n<!-- /wp:paragraph -->\n\n";
}

function wpb_image(string $url, string $alt, string $className = ''): string
{
    if ($url === '') return '';
    $cls = $className ? ' ' . h($className) : '';
    $attrs = $className ? '{"className":"' . h($className) . '"}' : '';
    return "<!-- wp:image {$attrs} -->\n<figure class=\"wp-block-image{$cls}\"><img src=\"" . h($url) . '" alt="' . h($alt) . "\"/></figure>\n<!-- /wp:image -->\n\n";
}

function wpb_button(string $text, string $url, string $color): string
{
    return '<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button {"style":{"color":{"background":"' . h($color) . '"}}} -->'
        . '<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="' . h($url) . '" style="background-color:' . h($color) . '">'
        . h($text) . "</a></div>\n<!-- /wp:button --></div>\n<!-- /wp:buttons -->\n\n";
}

function wpb_group_open(string $className): string
{
    return '<!-- wp:group {"className":"' . h($className) . '"} --><div class="wp-block-group ' . h($className) . "\">\n";
}

function wpb_group_close(): string
{
    return "</div>\n<!-- /wp:group -->\n\n";
}

/** Home block 1 — hero: text+CTA one side, photo the other. */
function wpb_block_hero(array $d, string $heroPhotoUrl): string
{
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

/** Home block 2 — 3 short trust points. */
function wpb_block_why_us(array $d): string
{
    $points = [
        ['Licensed & Insured', 'Fully licensed, insured, and background-checked — every job, every time.'],
        ['Fast Response', "We answer the phone and get back to you quickly, not days later."],
        ['Upfront Pricing', "You'll know the price before any work starts. No surprises on the invoice."],
    ];
    $out = wpb_group_open('wpb-section wpb-section-alt');
    $out .= wpb_heading('Why ' . $d['city'] . ' Chooses Us', 2);
    $out .= '<!-- wp:group {"className":"wpb-grid3"} --><div class="wp-block-group wpb-grid3">';
    foreach ($points as [$title, $body]) {
        $out .= '<div>' . wpb_heading($title, 3) . wpb_paragraph($body) . '</div>';
    }
    $out .= "</div>\n<!-- /wp:group -->\n\n";
    $out .= wpb_group_close();
    return $out;
}

/** Reusable full-width CTA band — used on Home and Services. */
function wpb_block_cta_band(array $d): string
{
    $out = '<!-- wp:group {"className":"wpb-cta-band"} --><div class="wp-block-group wpb-cta-band">';
    $out .= wpb_heading('Ready to get started?', 2);
    $out .= wpb_paragraph('Call ' . $d['business_name'] . ' now at ' . $d['phone']);
    $out .= "</div>\n<!-- /wp:group -->\n\n";
    return $out;
}

function wpb_block_services_intro(array $d): string
{
    $out = wpb_group_open('wpb-section');
    $out .= wpb_heading('What We Do', 2);
    $out .= wpb_paragraph($d['business_name'] . ' provides ' . strtolower($d['niche']) . ' services for homes and businesses throughout ' . $d['city'] . ', ' . $d['state'] . '. Call ' . $d['phone'] . ' for a clear price before any work begins.');
    $out .= wpb_group_close();
    return $out;
}

function wpb_block_services_grid(array $d): string
{
    $out = wpb_group_open('wpb-section wpb-section-alt');
    $out .= '<!-- wp:group {"className":"wpb-grid3"} --><div class="wp-block-group wpb-grid3">';
    foreach ($d['services_list'] as $service) {
        $out .= '<div class="wpb-card">' . wpb_heading($service, 3)
            . wpb_paragraph('Call ' . $d['phone'] . ' to schedule ' . strtolower($service) . ' in ' . $d['city'] . '.')
            . '</div>';
    }
    $out .= "</div>\n<!-- /wp:group -->\n\n";
    $out .= wpb_group_close();
    return $out;
}

function wpb_block_about(array $d, string $aboutPhotoUrl): string
{
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

function wpb_block_contact(array $d): string
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

function wpb_block_testimonials(array $d): string
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
function wpb_page_seo(array $d, string $page): array
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
            return ['Services | ' . $biz, "$biz offers " . implode(', ', array_slice($d['services_list'], 0, 3)) . " and more in $loc."];
        case 'contact':
            return ['Contact Us | ' . $biz, "Contact $biz at $d[phone] or $d[email]. Serving $loc."];
        case 'testimonials':
            return ['Testimonials | ' . $biz, "See what customers in $loc say about $biz."];
    }
    return [$biz, ''];
}

/** Full post_content per page, assembled from the blocks above. */
function wpb_page_content(array $d, string $page, array $photos): string
{
    switch ($page) {
        case 'home':
            return wpb_block_hero($d, $photos['hero'] ?? '') . wpb_block_why_us($d) . wpb_block_cta_band($d);
        case 'services':
            return wpb_block_services_intro($d) . wpb_block_services_grid($d) . wpb_block_cta_band($d);
        case 'about':
            return wpb_block_about($d, $photos['about'] ?? $photos['hero'] ?? '');
        case 'contact':
            return wpb_block_contact($d);
        case 'testimonials':
            return wpb_block_testimonials($d);
    }
    return '';
}

/** Stage a local file onto the box and import it into that site's media library. Returns the attachment URL, or '' on failure. */
function wpb_upload_photo(string $domain, string $localTmpPath, string $origName): string
{
    if ($localTmpPath === '' || !is_file($localTmpPath)) return '';
    $siteUser = LPC_DEMO_BOX_SITE_USER;
    $dir = '/home/' . $siteUser . '/web/' . $domain . '/public_html';
    $remoteStage = '/tmp/wpb_photo_' . bin2hex(random_bytes(6)) . '_' . preg_replace('/[^a-zA-Z0-9.\-]/', '', $origName);

    $scp = sprintf(
        'scp -i %s -o UserKnownHostsFile=/dev/null -o StrictHostKeyChecking=no %s %s@%s:%s',
        escapeshellarg(LPC_DEMO_BOX_SSH_KEY),
        escapeshellarg($localTmpPath),
        escapeshellarg(LPC_DEMO_BOX_SSH_USER),
        escapeshellarg(LPC_DEMO_BOX_HOST),
        escapeshellarg($remoteStage)
    );
    shell_exec($scp . ' 2>&1');

    // media import (run as the site user) leaves new files owned demo:demo —
    // unlike the clone step's files, which are explicitly chowned demo:www-data,
    // so without this nginx (www-data) can't read them and every uploaded photo
    // 403s on the front end despite a perfectly valid <img> tag pointing at it.
    $cmd = 'sudo -u ' . escapeshellarg($siteUser) . ' php ' . escapeshellarg($dir . '/wp-cli.phar')
         . ' media import ' . escapeshellarg($remoteStage) . ' --path=' . escapeshellarg($dir) . ' --porcelain 2>&1'
         . ' ; rm -f ' . escapeshellarg($remoteStage)
         . ' ; chown -R ' . escapeshellarg($siteUser . ':www-data') . ' ' . escapeshellarg($dir . '/wp-content/uploads')
         . ' ; find ' . escapeshellarg($dir . '/wp-content/uploads') . ' -type f -exec chmod 640 {} \;'
         . ' ; find ' . escapeshellarg($dir . '/wp-content/uploads') . ' -type d -exec chmod 750 {} \;';
    $r = lpc_demo_box_ssh($cmd);
    $id = wpb_last_line($r['output']);
    if (!ctype_digit($id)) return '';

    $urlCmd = 'sudo -u ' . escapeshellarg($siteUser) . ' php ' . escapeshellarg($dir . '/wp-cli.phar')
            . ' post get ' . escapeshellarg($id) . ' --field=guid --path=' . escapeshellarg($dir) . ' 2>&1';
    $urlR = lpc_demo_box_ssh($urlCmd);
    return wpb_last_line($urlR['output']);
}

/**
 * The main create flow: clone the blank seed (reusing lpc_demo_create_site's
 * proven vhost+clone+search-replace+back-link steps), deploy this tool's own
 * chrome mu-plugin, upload photos, set every wp_option, then write the 5 pages.
 */
function wpb_create_site(string $domain, array $rawInput, array $files): array
{
    foreach (wpb_field_defaults() as $key => $cfg) {
        if (!empty($cfg['required']) && trim((string) ($rawInput[$key] ?? '')) === '' && empty($cfg['default'])) {
            return ['ok' => false, 'message' => "Missing required field: $key"];
        }
    }

    $create = lpc_demo_create_site($domain, WPB_SEED_NICHE, '');
    if (!$create['ok']) {
        return $create;
    }

    $d = wpb_normalize_input($rawInput);
    $siteUser = LPC_DEMO_BOX_SITE_USER;
    $dir = '/home/' . $siteUser . '/web/' . $domain . '/public_html';

    // Chrome mu-plugin (header/footer/colors/SEO) — same deploy pattern as the back-link badge.
    $chromeSrc = rtrim(__DIR__ . '/../wp-plugin/wp-builder-site', '/');
    $chromeDest = $dir . '/wp-content/mu-plugins';
    $stage = sprintf(
        'rsync -az -e %s %s %s@%s:/tmp/wpb_chrome_stage/',
        escapeshellarg('ssh -i ' . LPC_DEMO_BOX_SSH_KEY . ' -o UserKnownHostsFile=/dev/null -o StrictHostKeyChecking=no'),
        escapeshellarg($chromeSrc . '/'),
        escapeshellarg(LPC_DEMO_BOX_SSH_USER),
        escapeshellarg(LPC_DEMO_BOX_HOST)
    );
    shell_exec($stage . ' 2>&1');
    lpc_demo_box_ssh('mkdir -p ' . escapeshellarg($chromeDest) . ' && rsync -a /tmp/wpb_chrome_stage/ ' . escapeshellarg($chromeDest . '/') . ' && chown -R ' . escapeshellarg($siteUser . ':www-data') . ' ' . escapeshellarg($chromeDest));

    // Photos.
    $photos = [
        'hero'  => wpb_upload_photo($domain, $files['hero_photo']['tmp_name'] ?? '', $files['hero_photo']['name'] ?? ''),
        'about' => wpb_upload_photo($domain, $files['about_photo']['tmp_name'] ?? '', $files['about_photo']['name'] ?? ''),
    ];

    // Site options the chrome mu-plugin reads.
    $opts = [
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
    ];
    $cmds = ['sudo -u ' . escapeshellarg($siteUser) . ' php ' . escapeshellarg($dir . '/wp-cli.phar') . ' option update blogname ' . escapeshellarg($d['business_name']) . ' --path=' . escapeshellarg($dir)];
    foreach ($opts as $k => $v) {
        $cmds[] = 'sudo -u ' . escapeshellarg($siteUser) . ' php ' . escapeshellarg($dir . '/wp-cli.phar') . ' option update ' . escapeshellarg($k) . ' ' . escapeshellarg((string) $v) . ' --path=' . escapeshellarg($dir);
    }
    $cmds[] = 'sudo -u ' . escapeshellarg($siteUser) . ' php ' . escapeshellarg($dir . '/wp-cli.phar') . ' option update wpb_services --format=json ' . escapeshellarg(json_encode($d['services_list'])) . ' --path=' . escapeshellarg($dir);
    lpc_demo_box_ssh(implode(' && ', $cmds));

    // Wipe the seed's own leftover pages (there shouldn't be any, but a prior
    // create on this same domain could have left some — safe no-op otherwise).
    lpc_demo_box_ssh('sudo -u ' . escapeshellarg($siteUser) . ' php ' . escapeshellarg($dir . '/wp-cli.phar') . ' post delete $(sudo -u ' . escapeshellarg($siteUser) . ' php ' . escapeshellarg($dir . '/wp-cli.phar') . ' post list --post_type=page --format=ids --path=' . escapeshellarg($dir) . ') --force --path=' . escapeshellarg($dir) . ' 2>&1');

    // Stage the page-creation helper once — it does the actual wp_insert_post()
    // in PHP so generated HTML (full of quotes/attributes) never has to survive
    // a shell command line.
    $helperSrc = __DIR__ . '/wp_builder_create_page.php';
    $helperRemote = '/tmp/wpb_create_page_' . bin2hex(random_bytes(6)) . '.php';
    $scpHelper = sprintf(
        'scp -i %s -o UserKnownHostsFile=/dev/null -o StrictHostKeyChecking=no %s %s@%s:%s',
        escapeshellarg(LPC_DEMO_BOX_SSH_KEY), escapeshellarg($helperSrc),
        escapeshellarg(LPC_DEMO_BOX_SSH_USER), escapeshellarg(LPC_DEMO_BOX_HOST), escapeshellarg($helperRemote)
    );
    shell_exec($scpHelper . ' 2>&1');

    // Write the 5 pages.
    $pageIds = [];
    foreach (['home', 'about', 'services', 'contact', 'testimonials'] as $page) {
        [$title, $metaDesc] = wpb_page_seo($d, $page);
        $slug = $page === 'home' ? 'home' : $page;
        $content = wpb_page_content($d, $page, $photos);
        $tmpFile = sys_get_temp_dir() . '/wpb_content_' . bin2hex(random_bytes(6)) . '.html';
        file_put_contents($tmpFile, $content);
        $remoteTmp = '/tmp/' . basename($tmpFile);
        $scp = sprintf(
            'scp -i %s -o UserKnownHostsFile=/dev/null -o StrictHostKeyChecking=no %s %s@%s:%s',
            escapeshellarg(LPC_DEMO_BOX_SSH_KEY), escapeshellarg($tmpFile),
            escapeshellarg(LPC_DEMO_BOX_SSH_USER), escapeshellarg(LPC_DEMO_BOX_HOST), escapeshellarg($remoteTmp)
        );
        shell_exec($scp . ' 2>&1');
        @unlink($tmpFile);

        $pageTitle = $page === 'home' ? 'Home' : ucfirst($page);
        $createCmd = 'sudo -u ' . escapeshellarg($siteUser) . ' php ' . escapeshellarg($dir . '/wp-cli.phar')
            . ' eval-file ' . escapeshellarg($helperRemote)
            . ' ' . escapeshellarg($slug) . ' ' . escapeshellarg($pageTitle) . ' ' . escapeshellarg($remoteTmp) . ' ' . escapeshellarg($metaDesc) . ' ' . escapeshellarg($title)
            . ' --path=' . escapeshellarg($dir) . ' 2>&1'
            . ' ; rm -f ' . escapeshellarg($remoteTmp);
        $r = lpc_demo_box_ssh($createCmd);
        $id = wpb_last_line($r['output']);
        if (ctype_digit($id)) {
            $pageIds[$page] = (int) $id;
        } else {
            error_log('wp_builder: page create failed for ' . $page . ' on ' . $domain . ': ' . $r['output']);
        }
    }

    lpc_demo_box_ssh('rm -f ' . escapeshellarg($helperRemote));

    if (!empty($pageIds['home'])) {
        lpc_demo_box_ssh('sudo -u ' . escapeshellarg($siteUser) . ' php ' . escapeshellarg($dir . '/wp-cli.phar') . ' option update show_on_front page --path=' . escapeshellarg($dir)
            . ' && sudo -u ' . escapeshellarg($siteUser) . ' php ' . escapeshellarg($dir . '/wp-cli.phar') . ' option update page_on_front ' . (int) $pageIds['home'] . ' --path=' . escapeshellarg($dir));
    }

    $registryEntry = $rawInput;
    $registryEntry['business_name'] = $d['business_name'];
    $registryEntry['hero_photo_url'] = $photos['hero'];
    $registryEntry['about_photo_url'] = $photos['about'];
    wpb_registry_save($domain, $registryEntry);

    return ['ok' => true, 'message' => 'Created ' . $domain . ' with ' . count($pageIds) . ' of 5 pages'];
}

function wpb_delete_site(string $domain): array
{
    $r = lpc_demo_delete_site($domain);
    if ($r['ok']) {
        wpb_registry_delete($domain);
    }
    return $r;
}
