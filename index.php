<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';

$data = load_data();

$contentBlocks  = $data['content_blocks'];
$seo            = $data['seo'];
$pageTitle      = SITE_TITLE;
$assetPathPrefix = '/';
$homeUrl        = '/';

require __DIR__ . '/includes/site-template.php';
