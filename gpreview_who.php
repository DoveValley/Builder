<?php
session_start();
if (empty($_SESSION['admin_logged_in'])) { header('Location: admin/login.php'); exit; }
$_SESSION['active_site'] = 'granitepmacademy';
session_write_close();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';
$data = load_data();
$block = null;
foreach ($data['content_blocks'] as $b) {
    if ($b['type'] === 'cards' && strpos($b['cards_label'] ?? '', 'WHO') !== false) {
        $block = $b;
        break;
    }
}
echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><link rel="stylesheet" href="/assets/css/style.css"></head><body style="margin:0;padding:40px;background:#f0f4f8;">';
if ($block) {
    require_once __DIR__ . '/includes/blocks.php';
    $assetPathPrefix = '/';
    render_content_block($block, $assetPathPrefix);
}
echo '</body></html>';
