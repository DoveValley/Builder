<?php
/**
 * admin/platform_settings_save.php — save handler for the "Client
 * Cancellation" card on admin/settings.php. Its own file, not folded into
 * agencies_save.php, since this isn't about agencies at all — platform-wide
 * settings get their own small handler, same "one thing per file" pattern
 * as the rest of this admin.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

if (empty($_SESSION['admin_logged_in']) || lpc_current_actor_role() !== 'super_admin') {
    header('Location: login.php');
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: settings.php'); exit; }

$token = $_POST['csrf_token'] ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
    header('Location: settings.php?msg=error:Invalid+request+token');
    exit;
}

$settings = [
    'cancellation_grace_days' => max(0, (int) ($_POST['cancellation_grace_days'] ?? 14)),
    'plugin_cache_ttl_hours'  => max(1, (int) ($_POST['plugin_cache_ttl_hours'] ?? 24)),
];
file_put_contents(PLUSCITIES_PLATFORM_SETTINGS_FILE, json_encode($settings, JSON_PRETTY_PRINT));

header('Location: settings.php?msg=success:Settings+saved');
exit;
