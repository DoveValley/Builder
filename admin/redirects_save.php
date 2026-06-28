<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

if (empty($_SESSION['admin_logged_in'])) { header('Location: login.php'); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: index.php?tab=seo'); exit; }
if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
    header('Location: index.php?tab=seo&msg=error:Invalid+token');
    exit;
}

$froms = array_values($_POST['redir_from'] ?? []);
$tos   = array_values($_POST['redir_to']   ?? []);

$redirects = [];
foreach ($froms as $i => $from) {
    $from = trim($from);
    $to   = trim($tos[$i] ?? '');
    if ($from === '' || $to === '') continue;

    // Ensure from starts with /
    if ($from[0] !== '/') $from = '/' . $from;
    // Strip query strings and fragments from 'from'
    $from = strtok($from, '?#');

    // Ensure to starts with / (unless it's a full URL)
    if (!str_starts_with($to, 'http') && $to[0] !== '/') $to = '/' . $to;

    $redirects[] = ['from' => $from, 'to' => $to];
}

$json = json_encode($redirects, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$tmp  = REDIRECTS_FILE . '.tmp.' . getmypid();
if (file_put_contents($tmp, $json) !== false && rename($tmp, REDIRECTS_FILE)) {
    header('Location: index.php?tab=seo&msg=success:Redirects+saved');
} else {
    @unlink($tmp);
    header('Location: index.php?tab=seo&msg=error:Could+not+save+redirects');
}
exit;
