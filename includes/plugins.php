<?php
// Plugin registry — register_plugin, get_plugins, get_plugin.
// Plugins call register_plugin() from their plugin.php file,
// which is auto-loaded by functions.php after all core includes.

$GLOBALS['_plugins'] = [];

function register_plugin(
    string $id,
    string $name,
    string $description,
    string $icon,
    string $dir
): void {
    $GLOBALS['_plugins'][$id] = compact('id', 'name', 'description', 'icon', 'dir');
}

function get_plugins(): array {
    return $GLOBALS['_plugins'];
}

function get_plugin(string $id): ?array {
    return $GLOBALS['_plugins'][$id] ?? null;
}
