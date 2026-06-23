<?php
session_start();
$_SESSION['active_site'] = 'granitepmacademy';
session_write_close();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';

$data = load_data();
$data['content_blocks'] = blocks_from_starter('hp_training');

$contentBlocks   = $data['content_blocks'];
$seo             = $data['seo'];
$pageTitle       = 'Starter Preview — Training Homepage';
$assetPathPrefix = '/';
$homeUrl         = '/';

require __DIR__ . '/includes/site-template.php';
