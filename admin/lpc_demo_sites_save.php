<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/lpc_demo_box.php';

if (empty($_SESSION['admin_logged_in'])) { header('Location: login.php'); exit; }
if (lpc_current_actor_role() !== 'super_admin') {
    header('Location: clients.php?msg=error:Demo+sites+are+managed+by+the+platform+only');
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: lpc_demo_sites.php'); exit; }

$token = $_POST['csrf_token'] ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
    header('Location: lpc_demo_sites.php?msg=error:Invalid+request+token');
    exit;
}

$action = $_POST['action'] ?? '';

// Same validation shape used fleet-wide for anything that feeds a shell command
// or a Hestia domain argument — letters, digits, hyphens, dots only.
function lpc_demo_valid_domain(string $d): bool {
    return $d !== '' && preg_match('/^[a-z0-9]([a-z0-9\-\.]*[a-z0-9])?$/', $d) === 1 && strlen($d) <= 253;
}

if ($action === 'create') {
    $domain = strtolower(trim($_POST['domain'] ?? ''));
    $sourceNiche = trim($_POST['source_niche'] ?? '');
    $theme = trim($_POST['theme'] ?? '');

    if (!lpc_demo_valid_domain($domain)) {
        header('Location: lpc_demo_sites.php?msg=error:Invalid+domain');
        exit;
    }
    if (!preg_match('/^[a-z0-9\-]+$/', $sourceNiche)) {
        header('Location: lpc_demo_sites.php?msg=error:Invalid+source+niche');
        exit;
    }
    if ($theme !== '' && !preg_match('/^[a-z0-9\-]+$/', $theme)) {
        header('Location: lpc_demo_sites.php?msg=error:Invalid+theme+slug');
        exit;
    }

    set_time_limit(120);
    $r = lpc_demo_create_site($domain, $sourceNiche, $theme);
    header('Location: lpc_demo_sites.php?msg=' . ($r['ok'] ? 'success' : 'error') . ':' . rawurlencode($r['message']));
    exit;
}

$domain = strtolower(trim($_POST['domain'] ?? ''));
if (!lpc_demo_valid_domain($domain)) {
    header('Location: lpc_demo_sites.php?msg=error:Invalid+domain');
    exit;
}

if ($action === 'redeploy_plugin') {
    $r = lpc_demo_redeploy_plugin($domain);
    header('Location: lpc_demo_sites.php?msg=' . ($r['ok'] ? 'success' : 'error') . ':' . rawurlencode($r['message']));
    exit;
}

if ($action === 'clear_cache') {
    $r = lpc_demo_clear_cache($domain);
    header('Location: lpc_demo_sites.php?msg=' . ($r['ok'] ? 'success' : 'error') . ':' . rawurlencode($r['message']));
    exit;
}

if ($action === 'delete') {
    $r = lpc_demo_delete_site($domain);
    header('Location: lpc_demo_sites.php?msg=' . ($r['ok'] ? 'success' : 'error') . ':' . rawurlencode($r['message']));
    exit;
}

header('Location: lpc_demo_sites.php?msg=error:Unknown+action');
