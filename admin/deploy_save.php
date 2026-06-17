<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

if (empty($_SESSION['admin_logged_in'])) { header('Location: login.php'); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: index.php?tab=deploy'); exit; }
if (!ACTIVE_SITE_ID) { header('Location: sites.php'); exit; }

if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
    header('Location: index.php?tab=deploy&msg=error:Invalid+security+token.');
    exit;
}

$deployFile = ACTIVE_SITE_DIR . '/deploy.json';
$cfg = file_exists($deployFile) ? (json_decode(file_get_contents($deployFile), true) ?: []) : [];

$section = $_POST['section'] ?? 'build';

if ($section === 'build') {
    $cfg['canonical_domain'] = rtrim(trim($_POST['canonical_domain'] ?? ''), '/');
    $cfg['web3forms_key']    = trim($_POST['web3forms_key'] ?? '');
} elseif ($section === 'ftp') {
    $cfg['ftp_host']    = preg_replace('#^ftps?://#i', '', trim($_POST['ftp_host'] ?? ''));
    $cfg['ftp_port']    = max(1, min(65535, (int)($_POST['ftp_port'] ?? 21)));
    $cfg['ftp_user']    = trim($_POST['ftp_user'] ?? '');
    if (trim($_POST['ftp_pass'] ?? '') !== '') {
        $cfg['ftp_pass'] = trim($_POST['ftp_pass']);
    }
    $cfg['ftp_path']    = '/' . ltrim(trim($_POST['ftp_path'] ?? '/'), '/');
    $cfg['ftp_passive'] = !empty($_POST['ftp_passive']);
}

file_put_contents($deployFile, json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

header('Location: index.php?tab=deploy&msg=success:Settings+saved.');
exit;
