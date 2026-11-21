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

define('PLUSCITIES_DELIVERY_BASE', 'http://localhost:8130/admin/pluscities_sync.php');

register_activation_hook(__FILE__, 'pluscities_delivery_activate');
function pluscities_delivery_activate() {
    add_option('pluscities_api_key', '');
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
}

add_filter('query_vars', function ($vars) {
    $vars[] = 'pluscities_city';
    $vars[] = 'pluscities_service';
    return $vars;
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
        echo '<div class="notice notice-success"><p>Saved.</p></div>';
    }
    if (get_option('pluscities_rewrite_flush_needed')) {
        flush_rewrite_rules();
        delete_option('pluscities_rewrite_flush_needed');
    }
    $key  = get_option('pluscities_api_key', '');
    $base = get_option('pluscities_base_path', 'service-area');
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
    $resp = wp_remote_get(PLUSCITIES_DELIVERY_BASE . '?action=manifest&site_rev=' . $seenRev, [
        'headers' => ['Authorization' => 'Bearer ' . $key],
        'timeout' => 10,
    ]);
    if (is_wp_error($resp)) return; // offline — serve whatever's already cached, per the fallback below
    $manifest = json_decode(wp_remote_retrieve_body($resp), true);
    if (!is_array($manifest) || empty($manifest['changed_pages'])) return;

    foreach ($manifest['changed_pages'] as $cp) {
        $pageResp = wp_remote_get(PLUSCITIES_DELIVERY_BASE . '?action=page&page_id=' . urlencode($cp['page_id']), [
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
        $parts['title'] = $page['head']['title'] ?? $parts['title'];
        return $parts;
    });
    add_action('wp_head', function () use ($page) {
        if (!empty($page['head']['meta_description'])) {
            echo '<meta name="description" content="' . esc_attr($page['head']['meta_description']) . '">' . "\n";
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
