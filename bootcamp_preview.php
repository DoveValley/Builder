<?php
session_start();
$_SESSION['active_site'] = 'granitepmacademy';
session_write_close();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';

$data  = load_data();
$page  = $data['pages']['page_pmp_boot'] ?? [];

$contentBlocks   = $page['content_blocks'] ?? [];
$seo             = $page['seo'] ?? [];
$pageTitle       = $seo['seo_title'] ?? 'PMP Bootcamp';
$assetPathPrefix = '/';
$homeUrl         = '/';
$slug            = 'pmp-bootcamp';

require __DIR__ . '/includes/site-template.php';
