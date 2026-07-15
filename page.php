<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';

$data = load_data();

// Raw request path (slashes intact), captured BEFORE slugify() flattens it. Nested
// pretty URLs arrive as ?path=a/b/c (see .htaccess); single-segment slugs still
// arrive as ?slug=. Either can be claimed by a route_request plugin.
$rawPath = $_GET['path'] ?? $_GET['slug'] ?? '';
$rawPath = is_string($rawPath) ? trim($rawPath, '/') : '';

$assetPathPrefix = '/';
$homeUrl         = '/';

// ── 0. Plugin routing hook ───────────────────────────────────────────────────
// Let a plugin claim this URL (e.g. the recovery matrix's nested pages). No-op
// unless a plugin registered 'route_request'; first non-null match wins. Rendering
// stays here in core, so plugins reuse the full block library + site-template.
if ($rawPath !== '' && !empty($GLOBALS['_hooks']['route_request'])) {
    $routed = route_hook('route_request', $rawPath, $data);
    if (is_array($routed)) {
        if (!empty($routed['site_vars']) && is_array($routed['site_vars'])) {
            $data['site_vars'] = array_merge($data['site_vars'] ?? [], $routed['site_vars']);
        }
        $contentBlocks = $routed['content_blocks'] ?? [];
        $seo           = $routed['seo'] ?? [];
        $pageTitle     = !empty($routed['seo']['seo_title'])
                            ? $routed['seo']['seo_title']
                            : (($routed['title'] ?? '') !== '' ? $routed['title'] : SITE_TITLE);
        if (!empty($routed['breadcrumbs']) && is_array($routed['breadcrumbs'])) $bcItems = $routed['breadcrumbs'];
        if (!empty($routed['status'])) http_response_code((int) $routed['status']);

        require __DIR__ . '/includes/site-template.php';
        exit;
    }
}

$raw  = $_GET['slug'] ?? $rawPath;
$slug = is_string($raw) ? slugify($raw) : '';

// ── 1. Check generated city pages (page-index.json) ──────────────────────────
// page-index.json maps slug → filename inside PAGES_DIR.
// Generated page files are fully self-contained (content_blocks copied from
// template at generation time). city_vars override site_vars at render time
// so all {city}/{SS}/etc. shortcodes resolve to the correct city.

if (file_exists(PAGE_INDEX_FILE)) {
    $pageIndex = json_decode(file_get_contents(PAGE_INDEX_FILE), true) ?: [];

    if (isset($pageIndex[$slug])) {
        $pageFile     = PAGES_DIR . $pageIndex[$slug];
        $realPagesDir = realpath(PAGES_DIR) ?: PAGES_DIR;
        $realPage     = realpath($pageFile);

        if ($realPage !== false && strncmp($realPage, $realPagesDir . DIRECTORY_SEPARATOR, strlen($realPagesDir) + 1) === 0) {
            $gen = json_decode(file_get_contents($pageFile), true);

            // Merge city vars over site vars — skip blank strings so city rows
            // with empty phone/website/etc. don't wipe out the site-level values.
            $cityVarsRaw = $gen['city_vars'] ?? [];
            $cityVars    = array_filter($cityVarsRaw, fn($v) => is_array($v) || ($v !== '' && $v !== null));
            $data['site_vars'] = array_merge($data['site_vars'] ?? [], $cityVars);

            $contentBlocks = $gen['content_blocks'] ?? [];
            $seo           = $gen['seo']            ?? [];
            // Prefer the SEO title for the <title> tag (consistent with the other
            // page routes); fall back to the page title, then the site title.
            $pageTitle     = !empty($seo['seo_title'])
                                ? $seo['seo_title']
                                : (($gen['title'] ?? '') !== '' ? $gen['title'] : SITE_TITLE);

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

$contentBlocks = $page['content_blocks'] ?? [];
$seo           = $page['seo'] ?? [];
$pageTitle     = !empty($page['seo']['seo_title']) ? $page['seo']['seo_title'] : ($page['title'] !== '' ? $page['title'] : SITE_TITLE);

require __DIR__ . '/includes/site-template.php';
