<?php
session_start();
$_SESSION['active_site'] = 'granitepmacademy';
session_write_close();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';

$data = load_data();

$contentBlocks = [[
    "type" => "wide_banner",
    "wb_heading" => "The Granite PM Bootcamp Pass Guarantee",
    "wb_badge" => "Risk-Free Enrollment",
    "wb_subtext" => "Complete the bootcamp, follow our 30-day study plan, sit for your exam — if you don't pass, attend the next session free. No exceptions beyond those two steps. We stand behind every seat we sell.",
    "wb_btn_text" => "View Bootcamp Schedules",
    "wb_btn_url" => "/schedule",
    "wb_bg_color" => "#0d1b3e",
    "wb_centered" => true,
    "wb_btn_style" => "outline"
]];

$seo = [];
$pageTitle = 'Guarantee Test';
$assetPathPrefix = '/';
$homeUrl = '/';

require __DIR__ . '/includes/site-template.php';
