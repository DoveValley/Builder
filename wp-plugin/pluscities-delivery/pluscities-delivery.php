<?php
/**
 * Plugin Name: PlusCities Delivery
 * Description: Fetches, caches, and places PlusCities-generated pages inside this site's own theme. Does no rendering of its own — see docs/PLUSCITIES_FEATURE_SPEC.md Section 9 ("thin plugin, server renders").
 * Version: 0.1.0 (Phase 1)
 *
 * Deliberately a NEW plugin, not a patch to the old "Landing Page Client"
 * plugin — Section 9 changes the plugin's fundamental job from renderer (the
 * old plugin's includes/renderer.php) to thin fetch-cache-place client. The
 * old plugin stays installed, deactivated, for whatever still depends on it.
 *
 * Phase 1 simplification, noted so Phase 2 doesn't mistake it for the final
 * design: fetches at request time with a transient cache, rather than
 * WP-cron polling the manifest on a schedule. The contract (poll-based sync,
 * offline-cache fallback) is unchanged; only the trigger timing is simpler
 * for now — see spec Section 9's "internal implementation choices... up to
 * the builder" note.
 */

if (!defined('ABSPATH')) exit;

// A real option, not a hardcoded constant — this plugin now runs on more than
// one install (the local test-wordpress site AND a real remote client site),
// and each needs a different address for the PlusCities server: the test
// site reaches it via localhost, a real remote site needs the platform's own
// real public address. Settable on this plugin's own settings page.
function pluscities_delivery_base() {
    return get_option('pluscities_delivery_base', 'http://localhost:8130/admin/pluscities_sync.php');
}

register_activation_hook(__FILE__, 'pluscities_delivery_activate');
function pluscities_delivery_activate() {
    add_option('pluscities_api_key', '');
    add_option('pluscities_delivery_base', 'http://localhost:8130/admin/pluscities_sync.php');
    add_option('pluscities_site_rev_seen', 0);
    // Per-client, agency-set at onboarding (spec Section 1/15) — same option
    // the old "Landing Page Client" plugin already has as lpc_base_path
    // (default /locations). Configurable here for the same reason: fixing
    // this platform-wide would be a real regression from what agencies can
    // already do today.
    add_option('pluscities_base_path', 'service-area');
    pluscities_delivery_add_rewrite_rules();
    flush_rewrite_rules();
}

register_deactivation_hook(__FILE__, function () { flush_rewrite_rules(); });

add_action('init', 'pluscities_delivery_add_rewrite_rules');
function pluscities_delivery_add_rewrite_rules() {
    $base = trim(get_option('pluscities_base_path', 'service-area'), '/');
    if ($base === '') return;
    add_rewrite_rule(
        '^' . $base . '/([^/]+)/([^/]+)/?$',
        'index.php?pluscities_city=$matches[1]&pluscities_service=$matches[2]',
        'top'
    );
    // Bare base path — the hub/listing page. No pattern overlap with the rule
    // above (this one has zero path segments after $base), so order between
    // them doesn't matter.
    add_rewrite_rule('^' . $base . '/?$', 'index.php?pluscities_hub=1', 'top');
    add_rewrite_rule('^pluscities-sitemap\.xml$', 'index.php?pluscities_sitemap=1', 'top');
}

add_filter('query_vars', function ($vars) {
    $vars[] = 'pluscities_city';
    $vars[] = 'pluscities_service';
    $vars[] = 'pluscities_hub';
    $vars[] = 'pluscities_sitemap';
    return $vars;
});

/**
 * Every currently-live page's absolute URL on this site — reused by the
 * self-served sitemap and by every SEO-plugin registration below, so there's
 * one real source of truth instead of each integration tracking its own
 * stale copy. Always asks the server fresh (site_rev=0 forces the FULL live
 * list, not just what changed since last sync) rather than trusting the
 * locally-accumulated pluscities_page_url_* options, which never get an
 * explicit removal signal today when a page is unpublished.
 */
function pluscities_delivery_live_urls() {
    $key = get_option('pluscities_api_key', '');
    if ($key === '') return [];
    $resp = wp_remote_get(pluscities_delivery_base() . '?action=manifest&site_rev=0', [
        'headers' => ['Authorization' => 'Bearer ' . $key],
        'timeout' => 10,
    ]);
    if (is_wp_error($resp)) return [];
    $manifest = json_decode(wp_remote_retrieve_body($resp), true);
    if (!is_array($manifest) || empty($manifest['changed_pages'])) return [];
    return array_map(fn($p) => home_url($p['url']), $manifest['changed_pages']);
}

// Priority 5 — ahead of WordPress's own redirect_canonical() (priority 10 on
// this same hook), which otherwise 301s this file-extension URL to a
// trailing-slash version before our handler ever runs.
add_action('template_redirect', function () {
    if (!get_query_var('pluscities_sitemap')) return;
    header('Content-Type: application/xml; charset=UTF-8');
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    foreach (pluscities_delivery_live_urls() as $url) {
        echo '<url><loc>' . esc_url($url) . '</loc></url>' . "\n";
    }
    echo '</urlset>';
    exit;
}, 5);

/**
 * Sitemap inclusion, spec Section 5 — respect whichever SEO plugin the
 * client is already running rather than fighting it or generating a
 * competing, undiscoverable sitemap of our own. Each plugin's hook was
 * confirmed by reading its actual installed source (not assumed from
 * memory). get_index_links() returning one entry pointing at our own
 * self-served XML above is all either needs — we never need their internal
 * per-type routing to dispatch back to us, so handles_type() and
 * get_sitemap_links() are correctly-shaped no-ops, not partial work.
 *
 * Two near-identical classes, not one shared one: Yoast's own merge loop
 * does `$provider instanceof WPSEO_Sitemap_Provider` — a real interface
 * check, not duck typing — confirmed by reading inc/sitemaps/class-sitemaps.php
 * directly after a plain, non-implementing class silently vanished from its
 * output with no error. Rank Math's equivalent check is only
 * `is_object($provider)` (confirmed the same way), so it never needed this,
 * but giving it the same explicit-interface treatment costs nothing.
 */
function pluscities_delivery_sitemap_index_links() {
    if (!pluscities_delivery_live_urls()) return [];
    return [['loc' => home_url('/pluscities-sitemap.xml'), 'lastmod' => gmdate('c'), 'mod' => gmdate('c')]];
}

// Deferred to plugins_loaded, not run at top-level file-load time — plugins
// load in their active_plugins option order (alphabetical by default), which
// put this plugin BEFORE seo-by-rank-math/wordpress-seo in that list. A
// top-level defined('RANK_MATH_VERSION') check here saw false every time,
// regardless of which was actually active, since Rank Math's own file hadn't
// run yet. plugins_loaded fires once every active plugin has been included,
// and it's also where each conditional provider class below gets declared —
// WPSEO_Sitemap_Provider only exists to implement against once Yoast's own
// file has actually loaded.
add_action('plugins_loaded', function () {
    if (defined('WPSEO_VERSION') && interface_exists('WPSEO_Sitemap_Provider')) {
        class PlusCities_Sitemap_Provider_Yoast implements WPSEO_Sitemap_Provider {
            public function handles_type($type) { return false; }
            public function get_sitemap_links($type, $max_entries, $current_page) { return []; }
            public function get_index_links($max_entries) { return pluscities_delivery_sitemap_index_links(); }
        }
        add_filter('wpseo_sitemaps_providers', function ($providers) {
            $providers[] = new PlusCities_Sitemap_Provider_Yoast();
            return $providers;
        });
    }
    if (defined('RANK_MATH_VERSION')) {
        class PlusCities_Sitemap_Provider_RankMath {
            public function handles_type($type) { return false; }
            public function get_sitemap_links($type, $max_entries, $current_page) { return []; }
            public function get_index_links($max_entries) { return pluscities_delivery_sitemap_index_links(); }
        }
        add_filter('rank_math/sitemap/providers', function ($providers) {
            $providers[] = new PlusCities_Sitemap_Provider_RankMath();
            return $providers;
        });
    }
    if (defined('AIOSEO_VERSION')) {
        // AIOSEO's shape is genuinely different, confirmed by reading
        // app/Common/Sitemap/Root.php directly: this filter runs on entries
        // AIOSEO already transformed to plain ['loc','lastmod'] arrays (not
        // the raw stored additionalPage objects), and folds them into
        // AIOSEO's OWN "additional pages" sub-sitemap rather than linking out
        // to a separate one of ours — so the array shape has to match
        // exactly, not just "some object with a URL on it." Also only runs
        // when the client has their own "Additional Pages" setting turned
        // on — a real constraint of that hook, not something forceable from
        // the outside.
        add_filter('aioseo_sitemap_additional_pages', function ($entries) {
            foreach (pluscities_delivery_live_urls() as $url) {
                $entries[] = ['loc' => $url, 'lastmod' => gmdate('c')];
            }
            return $entries;
        });
    }
});

// Universal fallback regardless of which SEO plugin (if any) is active —
// spec Section 5's "the plugin's own sitemap as a fallback when the client
// has none of those installed," plus a harmless, additive courtesy line
// even when one is: a Sitemap: directive is additive, not exclusive, so
// listing ours alongside an active plugin's own line doesn't conflict with it.
add_filter('robots_txt', function ($output) {
    return $output . "Sitemap: " . home_url('/pluscities-sitemap.xml') . "\n";
});

/** Settings page — just the API key and delivery base, same minimal shape as
 *  the old plugin's one-setting admin page. */
add_action('admin_menu', function () {
    add_options_page('PlusCities Delivery', 'PlusCities Delivery', 'manage_options', 'pluscities-delivery', 'pluscities_delivery_settings_page');
});
function pluscities_delivery_settings_page() {
    if (!empty($_POST['pluscities_api_key']) && check_admin_referer('pluscities_delivery_save')) {
        update_option('pluscities_api_key', sanitize_text_field($_POST['pluscities_api_key']));
        update_option('pluscities_site_rev_seen', 0); // force a full re-sync after a key change
        $newBase = trim(sanitize_text_field($_POST['pluscities_base_path'] ?? 'service-area'), '/');
        if ($newBase !== '' && $newBase !== get_option('pluscities_base_path')) {
            update_option('pluscities_base_path', $newBase);
            update_option('pluscities_rewrite_flush_needed', 1); // same pattern as the old plugin's lpc_rewrite_flush_needed
        }
        $newDeliveryBase = esc_url_raw(trim((string) ($_POST['pluscities_delivery_base'] ?? '')));
        if ($newDeliveryBase !== '') update_option('pluscities_delivery_base', $newDeliveryBase);
        echo '<div class="notice notice-success"><p>Saved.</p></div>';
    }
    if (get_option('pluscities_rewrite_flush_needed')) {
        flush_rewrite_rules();
        delete_option('pluscities_rewrite_flush_needed');
    }
    $key  = get_option('pluscities_api_key', '');
    $base = get_option('pluscities_base_path', 'service-area');
    $deliveryBase = pluscities_delivery_base();
    ?>
    <div class="wrap"><h1>PlusCities Delivery</h1>
    <form method="post">
        <?php wp_nonce_field('pluscities_delivery_save'); ?>
        <table class="form-table">
        <tr><th>API Key</th>
            <td><input type="text" name="pluscities_api_key" value="<?php echo esc_attr($key); ?>" style="width:400px;"></td>
        </tr>
        <tr><th>URL Base Path</th>
            <td><input type="text" name="pluscities_base_path" value="<?php echo esc_attr($base); ?>" style="width:200px;">
            <p class="description">e.g. "service-area" or "locations" — set once per client at onboarding, matching the agency's choice.</p></td>
        </tr>
        <tr><th>Delivery Server Address</th>
            <td><input type="text" name="pluscities_delivery_base" value="<?php echo esc_attr($deliveryBase); ?>" style="width:400px;">
            <p class="description">The PlusCities platform's own sync endpoint — different per install (a local test site reaches it via localhost; a real remote site needs the platform's real public address).</p></td>
        </tr>
        </table>
        <?php submit_button(); ?>
    </form>
    </div>
    <?php
}

/** Fetches the manifest + any changed pages, caching each page's rendered
 *  bundle as a transient keyed by page_id. Real poll-based contract (spec
 *  Section 9) — Phase 1 just triggers it at request time instead of WP-cron. */
function pluscities_delivery_sync_if_needed() {
    $key = get_option('pluscities_api_key', '');
    if ($key === '') return;

    $seenRev = (int) get_option('pluscities_site_rev_seen', 0);
    $resp = wp_remote_get(pluscities_delivery_base() . '?action=manifest&site_rev=' . $seenRev, [
        'headers' => ['Authorization' => 'Bearer ' . $key],
        'timeout' => 10,
    ]);
    if (is_wp_error($resp)) return; // offline — serve whatever's already cached, per the fallback below
    $manifest = json_decode(wp_remote_retrieve_body($resp), true);
    if (!is_array($manifest) || !isset($manifest['site_rev'])) return;
    // Gate on site_rev advancing, not on changed_pages being non-empty —
    // if a client unpublishes their LAST remaining live page, changed_pages
    // comes back empty even though site_rev genuinely moved; gating on
    // changed_pages would skip updating site_rev_seen forever AND skip the
    // reconciliation below, leaking that page's cache indefinitely.
    if ((int) $manifest['site_rev'] <= $seenRev) return;

    foreach ($manifest['changed_pages'] ?? [] as $cp) {
        $pageResp = wp_remote_get(pluscities_delivery_base() . '?action=page&page_id=' . urlencode($cp['page_id']), [
            'headers' => ['Authorization' => 'Bearer ' . $key],
            'timeout' => 10,
        ]);
        if (is_wp_error($pageResp)) continue;
        $page = json_decode(wp_remote_retrieve_body($pageResp), true);
        if (!is_array($page) || empty($page['html'])) continue;
        // No expiry — this transient IS the offline-cache fallback (spec
        // Section 9): it keeps serving the last-known-good content
        // indefinitely if the server later becomes unreachable.
        set_transient('pluscities_page_' . $cp['page_id'], $page, 0);
        update_option('pluscities_page_url_' . md5($cp['url']), $cp['page_id']);
    }

    // Reconciliation — delete anything cached earlier that's no longer in the
    // manifest's live set (e.g. a page that got unpublished). live_page_ids is
    // the full current truth, not just what changed, so anything cached that
    // ISN'T in it is stale and safe to remove. The no-expiry transient above
    // is deliberate for still-live pages (the offline fallback); without this
    // step an unpublished page would otherwise keep serving forever, since
    // nothing else in this protocol ever signals a removal.
    global $wpdb;
    $liveSet = array_flip($manifest['live_page_ids'] ?? []);
    $cachedUrlOptions = $wpdb->get_results(
        "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'pluscities_page_url_%'"
    );
    foreach ($cachedUrlOptions as $opt) {
        $cachedPageId = $opt->option_value;
        if (!isset($liveSet[$cachedPageId])) {
            delete_option($opt->option_name);
            delete_transient('pluscities_page_' . $cachedPageId);
        }
    }

    // The hub/listing page is a client-level aggregate, not one of the pages
    // in changed_pages above — refetched once per sync pass (same trigger:
    // only runs when the manifest shows the client's site_rev moved), rather
    // than on a separate polling schedule of its own.
    $hubResp = wp_remote_get(pluscities_delivery_base() . '?action=hub', [
        'headers' => ['Authorization' => 'Bearer ' . $key],
        'timeout' => 10,
    ]);
    if (!is_wp_error($hubResp)) {
        $hub = json_decode(wp_remote_retrieve_body($hubResp), true);
        if (is_array($hub) && !empty($hub['html'])) {
            set_transient('pluscities_hub_content', $hub, 0);
        }
    }

    update_option('pluscities_site_rev_seen', (int) $manifest['site_rev']);
}

add_action('template_redirect', function () {
    $city    = get_query_var('pluscities_city');
    $service = get_query_var('pluscities_service');
    if (!$city || !$service) return;

    pluscities_delivery_sync_if_needed();

    $base   = trim(get_option('pluscities_base_path', 'service-area'), '/');
    $url    = '/' . $base . '/' . $city . '/' . $service . '/';
    $pageId = get_option('pluscities_page_url_' . md5($url));
    $page   = $pageId ? get_transient('pluscities_page_' . $pageId) : false;

    if (!$page) {
        status_header(404);
        get_header();
        echo '<p>Page not found.</p>';
        get_footer();
        exit;
    }

    // Thin placement only — no rendering decisions happen here, the HTML
    // arrived already finished. Wrapped in the theme's own header/footer so
    // it inherits the client's real site chrome, per spec Section 9.
    add_filter('document_title_parts', function ($parts) use ($page) {
        // Our own title is already a complete, self-contained title (service +
        // city + business name) — WordPress's default behavior still appends
        // " – {site name}" on top of it, which reads as a real duplicate
        // whenever a client's blogname happens to be their own business name
        // (not a coincidence specific to one client — any client could hit
        // this). Clearing 'site'/'tagline' here, not just setting 'title'.
        $parts = ['title' => $page['head']['title'] ?? $parts['title']];
        return $parts;
    });
    add_action('wp_head', function () use ($page) {
        if (!empty($page['head']['meta_description'])) {
            echo '<meta name="description" content="' . esc_attr($page['head']['meta_description']) . '">' . "\n";
        }
        if (!empty($page['head']['canonical'])) {
            echo '<link rel="canonical" href="' . esc_url($page['head']['canonical']) . '">' . "\n";
        }
        if (!empty($page['style'])) echo $page['style'] . "\n";
        // Schema arrived already-assembled from the server (spec Section 5) —
        // the plugin's only job is placing it, same "thin plugin" rule as the
        // HTML itself. One <script> tag per schema object, not one array,
        // since that's the format Google's own rich-results tooling expects.
        foreach ($page['head']['schema'] ?? [] as $schemaObject) {
            echo '<script type="application/ld+json">' . wp_json_encode($schemaObject) . '</script>' . "\n";
        }
    });

    get_header();
    echo '<div class="pluscities-page">' . $page['html'] . '</div>';
    get_footer();
    exit;
});

// Separate closure, not folded into the one above — different query var, and
// the existing closure's own early-return (`if (!$city || !$service) return;`)
// would otherwise need restructuring, which risks the already-working page
// route for no benefit.
add_action('template_redirect', function () {
    if (!get_query_var('pluscities_hub')) return;

    pluscities_delivery_sync_if_needed();

    $page = get_transient('pluscities_hub_content');

    if (!$page) {
        status_header(404);
        get_header();
        echo '<p>Page not found.</p>';
        get_footer();
        exit;
    }

    add_filter('document_title_parts', function ($parts) use ($page) {
        $parts = ['title' => $page['head']['title'] ?? $parts['title']];
        return $parts;
    });
    add_action('wp_head', function () use ($page) {
        if (!empty($page['head']['meta_description'])) {
            echo '<meta name="description" content="' . esc_attr($page['head']['meta_description']) . '">' . "\n";
        }
        if (!empty($page['head']['canonical'])) {
            echo '<link rel="canonical" href="' . esc_url($page['head']['canonical']) . '">' . "\n";
        }
        if (!empty($page['style'])) echo $page['style'] . "\n";
        foreach ($page['head']['schema'] ?? [] as $schemaObject) {
            echo '<script type="application/ld+json">' . wp_json_encode($schemaObject) . '</script>' . "\n";
        }
    });

    get_header();
    echo '<div class="pluscities-page">' . $page['html'] . '</div>';
    get_footer();
    exit;
});
