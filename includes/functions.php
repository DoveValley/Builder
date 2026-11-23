<?php
/**
 * Shared helper functions for the homepage builder.
 * This file is a loader — all functions live in the focused files below.
 */

require_once __DIR__ . '/progress.php';
require_once __DIR__ . '/static_build.php';
require_once __DIR__ . '/data.php';
require_once __DIR__ . '/page_starters.php';
require_once __DIR__ . '/shortcodes.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/media_lib.php';
require_once __DIR__ . '/css_critical.php';
require_once __DIR__ . '/theme.php';
require_once __DIR__ . '/blocks.php';
require_once __DIR__ . '/layout_variations.php';
require_once __DIR__ . '/editor.php';
require_once __DIR__ . '/models.php';
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/landing_page_clients.php';
require_once __DIR__ . '/agencies.php';
require_once __DIR__ . '/landing_page_clients_publish.php';
require_once __DIR__ . '/landing_page_clients_approvals.php';
require_once __DIR__ . '/landing_page_clients_clicks.php';
require_once __DIR__ . '/stripe_client.php';
require_once __DIR__ . '/landing_page_clients_reporting.php';
require_once __DIR__ . '/landing_page_clients_niches.php';
require_once __DIR__ . '/niche_library.php';
require_once __DIR__ . '/pluscities_niche_library.php';
require_once __DIR__ . '/../admin/infra/lib/pluscities_db.php';
require_once __DIR__ . '/../admin/infra/lib/http.php';
require_once __DIR__ . '/../admin/infra/lib/census.php';
require_once __DIR__ . '/pluscities_color_detect.php';
require_once __DIR__ . '/pluscities_research.php';
require_once __DIR__ . '/pluscities_images.php';
require_once __DIR__ . '/pluscities_writer.php';
require_once __DIR__ . '/pluscities_validator.php';
require_once __DIR__ . '/pluscities_lifecycle.php';
require_once __DIR__ . '/pluscities_users.php';
require_once __DIR__ . '/pluscities_schema.php';
require_once __DIR__ . '/pluscities_block_catalog.php';
require_once __DIR__ . '/pluscities_lpc_bridge.php';
require_once __DIR__ . '/landing_page_clients_ai.php';
require_once __DIR__ . '/landing_page_clients_detect.php';
require_once __DIR__ . '/landing_page_clients_media.php';
require_once __DIR__ . '/landing_page_clients_area_images.php';
require_once __DIR__ . '/landing_page_clients_safety_toolkit.php';
require_once __DIR__ . '/landing_page_clients_area_charts.php';
require_once __DIR__ . '/google_search_console.php';
require_once __DIR__ . '/seo-editor.php';
require_once __DIR__ . '/scripts.php';
require_once __DIR__ . '/hooks.php';
require_once __DIR__ . '/plugins.php';
require_once __DIR__ . '/ai.php';

// Auto-load all plugins. Each plugins/{id}/plugin.php calls register_plugin()
// and add_hook() to attach to the system.
foreach (glob(BASE_DIR . '/plugins/*/plugin.php') as $_pluginFile) {
    require_once $_pluginFile;
}
unset($_pluginFile);
