<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';

$data = load_data();
$slug = isset($_GET['slug']) ? slugify($_GET['slug']) : '';

$assetPathPrefix = '/';
$homeUrl         = '/';

// ── 1. Check generated city pages (page-index.json) ──────────────────────────
// page-index.json maps slug → filename inside PAGES_DIR.
// Generated page files are fully self-contained (content_blocks copied from
// template at generation time). city_vars override site_vars at render time
// so all {city}/{SS}/etc. shortcodes resolve to the correct city.

if (file_exists(PAGE_INDEX_FILE)) {
    $pageIndex = json_decode(file_get_contents(PAGE_INDEX_FILE), true) ?: [];

    if (isset($pageIndex[$slug])) {
        $pageFile = PAGES_DIR . $pageIndex[$slug];

        if (file_exists($pageFile)) {
            $gen = json_decode(file_get_contents($pageFile), true);

            // Merge city vars over site vars so shortcodes resolve correctly
            $data['site_vars'] = array_merge(
                $data['site_vars'] ?? [],
                $gen['city_vars']  ?? []
            );

            $contentBlocks = $gen['content_blocks'] ?? [];
            $seo           = $gen['seo']            ?? [];
            $pageTitle     = ($gen['title'] ?? '') !== '' ? $gen['title'] : SITE_TITLE;

            require __DIR__ . '/includes/site-template.php';
            exit;
        }
    }
}

// ── 2. Fall back to core pages stored in site.json ───────────────────────────
[$pageId, $page] = find_page_by_slug($data['pages'], $slug);

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
$pageTitle     = !empty($page['seo']['seo_title']) ? $page['seo']['seo_title'] : ($page['title'] !== '' ? $page['title'] : SITE_TITLE);

require __DIR__ . '/includes/site-template.php';
