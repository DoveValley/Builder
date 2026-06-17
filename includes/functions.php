<?php
/**
 * Shared helper functions for the homepage builder.
 * This file is a loader — all functions live in the focused files below.
 */

require_once __DIR__ . '/data.php';
require_once __DIR__ . '/shortcodes.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/theme.php';
require_once __DIR__ . '/blocks.php';
require_once __DIR__ . '/editor.php';
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/seo-editor.php';
require_once __DIR__ . '/scripts.php';
require_once __DIR__ . '/hooks.php';
require_once __DIR__ . '/plugins.php';

// Auto-load all plugins. Each plugins/{id}/plugin.php calls register_plugin()
// and add_hook() to attach to the system.
foreach (glob(BASE_DIR . '/plugins/*/plugin.php') as $_pluginFile) {
    require_once $_pluginFile;
}
unset($_pluginFile);
