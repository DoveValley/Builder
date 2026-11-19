<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/wp_builder.php';

if (empty($_SESSION['admin_logged_in'])) { header('Location: login.php'); exit; }
if (lpc_current_actor_role() !== 'super_admin') {
    header('Location: clients.php?msg=error:WP+Builder+is+managed+by+the+platform+only');
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: wp_builder.php'); exit; }

$token = $_POST['csrf_token'] ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
    header('Location: wp_builder.php?msg=error:Invalid+request+token');
    exit;
}

function wpb_valid_domain(string $d): bool {
    return $d !== '' && preg_match('/^[a-z0-9]([a-z0-9\-\.]*[a-z0-9])?$/', $d) === 1 && strlen($d) <= 253;
}

$action = $_POST['action'] ?? '';

if ($action === 'create') {
    $domain = strtolower(trim($_POST['domain'] ?? ''));
    if (!wpb_valid_domain($domain)) {
        header('Location: wp_builder.php?msg=error:Invalid+domain');
        exit;
    }
    $profile = (string) ($_POST['profile'] ?? '');
    if (!wpb_get_profile($profile)) {
        header('Location: wp_builder.php?msg=error:Unknown+profile');
        exit;
    }

    set_time_limit(180);
    $r = wpb_create_site($profile, $domain, $_POST, $_FILES);
    header('Location: wp_builder.php?msg=' . ($r['ok'] ? 'success' : 'error') . ':' . rawurlencode($r['message']));
    exit;
}

if ($action === 'update') {
    $domain = strtolower(trim($_POST['domain'] ?? ''));
    if (!wpb_valid_domain($domain)) {
        header('Location: wp_builder.php?msg=error:Invalid+domain');
        exit;
    }

    set_time_limit(180);
    $r = wpb_update_site($domain, $_POST, $_FILES);
    $redirect = $r['ok'] ? 'wp_builder.php' : ('wp_builder.php?edit=' . rawurlencode($domain));
    $sep = strpos($redirect, '?') !== false ? '&' : '?';
    header('Location: ' . $redirect . $sep . 'msg=' . ($r['ok'] ? 'success' : 'error') . ':' . rawurlencode($r['message']));
    exit;
}

if ($action === 'delete') {
    $domain = strtolower(trim($_POST['domain'] ?? ''));
    if (!wpb_valid_domain($domain)) {
        header('Location: wp_builder.php?msg=error:Invalid+domain');
        exit;
    }
    $r = wpb_delete_site($domain);
    header('Location: wp_builder.php?msg=' . ($r['ok'] ? 'success' : 'error') . ':' . rawurlencode($r['message']));
    exit;
}

header('Location: wp_builder.php?msg=error:Unknown+action');
