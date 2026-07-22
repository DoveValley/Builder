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
    // Canonical must be an absolute http(s) URL — it feeds sitemap.xml / robots.txt.
    // sanitize_url() also permits tel:/mailto:/relative, so validate the scheme here.
    $canon = rtrim(sanitize_url(trim($_POST['canonical_domain'] ?? '')), '/');
    if ($canon !== '' && !preg_match('#^https?://#i', $canon)) {
        header('Location: index.php?tab=deploy&msg=error:Canonical+domain+must+start+with+http://+or+https://');
        exit;
    }
    $cfg['canonical_domain'] = $canon;
    $cfg['web3forms_key']    = trim($_POST['web3forms_key'] ?? '');
} elseif ($section === 'ftp') {
    $cfg['ftp_protocol'] = (($_POST['ftp_protocol'] ?? 'ftp') === 'sftp') ? 'sftp' : 'ftp';
    $cfg['ftp_host']    = preg_replace('#^s?ftps?://#i', '', trim($_POST['ftp_host'] ?? ''));
    $cfg['ftp_port']    = max(1, min(65535, (int)($_POST['ftp_port'] ?? ($cfg['ftp_protocol'] === 'sftp' ? 22 : 21))));
    $cfg['ftp_user']    = trim($_POST['ftp_user'] ?? '');
    if (trim($_POST['ftp_pass'] ?? '') !== '') {
        $cfg['ftp_pass'] = trim($_POST['ftp_pass']);
    }
    $cfg['ftp_path']    = '/' . ltrim(trim($_POST['ftp_path'] ?? '/'), '/');
    $cfg['ftp_passive'] = !empty($_POST['ftp_passive']);
}

$deployJson = json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
$deployTmp  = $deployFile . '.tmp.' . getmypid();
if (file_put_contents($deployTmp, $deployJson) === false || !rename($deployTmp, $deployFile)) {
    @unlink($deployTmp);
    header('Location: index.php?tab=deploy&msg=error:Could+not+save+settings+-+check+file+permissions.');
    exit;
}

header('Location: index.php?tab=deploy&msg=success:Settings+saved.');
exit;
