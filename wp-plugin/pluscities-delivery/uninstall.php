<?php
/**
 * uninstall.php — WordPress runs this one file automatically, and only this
 * file, when a user clicks "Delete" on the plugin from the Plugins screen —
 * never on deactivate. Added 2026-11-24; before this, removing the plugin
 * left its settings and per-page cache in the client's database forever,
 * since nothing ever cleaned them up.
 */
if (!defined('WP_UNINSTALL_PLUGIN')) exit;

foreach ([
    'pluscities_api_key',
    'pluscities_base_path',
    'pluscities_delivery_base',
    'pluscities_rewrite_flush_needed',
    'pluscities_site_rev_seen',
    'pluscities_cancelled_redirect',
] as $option) {
    delete_option($option);
}
delete_transient('pluscities_hub_content');

// Dynamic, one row per page this site has ever synced — same
// pluscities_page_url_* pattern the plugin's own reconciliation logic
// already scans elsewhere (see pluscities_delivery_sync_if_needed()), plus
// the matching per-page transient cache entries (both the value row and its
// timeout row — a transient is always stored as two option rows).
global $wpdb;
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'pluscities_page_url_%'");
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_pluscities_page_%' OR option_name LIKE '_transient_timeout_pluscities_page_%'");
