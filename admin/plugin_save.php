<?php
// Central save dispatcher for plugins.
// Handles auth + CSRF, then hands off to the plugin's own save.php.

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

if (empty($_SESSION['admin_logged_in'])) { header('Location: login.php'); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: index.php?tab=plugins'); exit; }

$token = $_POST['csrf_token'] ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
    header('Location: index.php?tab=plugins&msg=error:Invalid+request+token');
    exit;
}

if (!ACTIVE_SITE_ID) { header('Location: sites.php'); exit; }

$pluginId = preg_replace('/[^a-z0-9_-]/', '', $_POST['plugin_id'] ?? '');
$plugin   = get_plugin($pluginId);

if (!$plugin || !file_exists($plugin['dir'] . '/save.php')) {
    header('Location: index.php?tab=plugins&msg=error:Plugin+not+found');
    exit;
}

require $plugin['dir'] . '/save.php';
