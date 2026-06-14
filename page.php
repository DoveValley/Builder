<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';

$data = load_data();
$slug = isset($_GET['slug']) ? slugify($_GET['slug']) : '';

[$pageId, $page] = find_page_by_slug($data['pages'], $slug);

$assetPathPrefix = '/';
$homeUrl         = '/';

if ($page === null) {
    http_response_code(404);
    $contentBlocks = [
        [
            'type'           => 'text',
            'heading_level'  => 'h1',
            'text'           => "Page not found.\n\nSorry, the page you're looking for doesn't exist. Use the menu above to find what you're looking for.",
            'photo'          => '',
            'photo_ratio'    => 'landscape',
            'photo_position' => 'center',
            'photo_alt'      => '',
        ],
    ];
    $seo       = [];
    $pageTitle = 'Page Not Found';

    require __DIR__ . '/includes/site-template.php';
    exit;
}

$contentBlocks = $page['content_blocks'];
$seo           = $page['seo'];
$pageTitle     = $page['title'] !== '' ? $page['title'] : SITE_TITLE;

require __DIR__ . '/includes/site-template.php';
