<?php
// Static site generator — SSE endpoint.
// Renders every page via ob_start/ob_get_clean against site-template.php,
// copies assets + uploads, writes sitemap.xml / robots.txt / .htaccess.

define('STATIC_BUILD', true);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

// ── Auth ──────────────────────────────────────────────────────────────────────
if (empty($_SESSION['admin_logged_in'])) {
    http_response_code(403);
    echo "data: " . json_encode(['type' => 'fatal', 'msg' => 'Not authenticated.']) . "\n\n";
    exit;
}
if (!ACTIVE_SITE_ID) {
    http_response_code(400);
    echo "data: " . json_encode(['type' => 'fatal', 'msg' => 'No active site selected.']) . "\n\n";
    exit;
}
// SSE uses GET so the token is passed in the query string; validate before releasing session.
if (!hash_equals($_SESSION['csrf_token'] ?? '', $_GET['token'] ?? '')) {
    http_response_code(403);
    echo "data: " . json_encode(['type' => 'fatal', 'msg' => 'Invalid security token.']) . "\n\n";
    exit;
}

// Release session lock — generation is read-only after this point.
session_write_close();

// ── SSE headers ───────────────────────────────────────────────────────────────
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');

set_time_limit(0);
ini_set('output_buffering', 'off');
@ini_set('zlib.output_compression', '0');

function sse(string $msg, string $type = 'log'): void {
    echo 'data: ' . json_encode(['type' => $type, 'msg' => $msg]) . "\n\n";
    @ob_flush();
    flush();
}

// ── Load deploy config ────────────────────────────────────────────────────────
$deployFile = ACTIVE_SITE_DIR . '/deploy.json';
$deploy = file_exists($deployFile) ? (json_decode(file_get_contents($deployFile), true) ?: []) : [];
$canonicalDomain = rtrim($deploy['canonical_domain'] ?? '', '/');
$GLOBALS['_static_web3forms_key'] = $deploy['web3forms_key'] ?? '';

// ── Output directory ──────────────────────────────────────────────────────────
$outputBase = BASE_DIR . '/output/' . ACTIVE_SITE_ID . '/';
if (!is_dir($outputBase)) {
    mkdir($outputBase, 0755, true);
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function gen_write(string $filePath, string $html): bool {
    $dir = dirname($filePath);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    // Rewrite multi-site upload URLs to flat /uploads/ so the static output
    // doesn't expose the internal sites/{id}/ path structure.
    if (UPLOAD_URL !== 'uploads/') {
        $html = str_replace('/' . UPLOAD_URL, '/uploads/', $html);
    }
    return file_put_contents($filePath, $html) !== false;
}

function gen_reset_shortcode_globals(): void {
    $GLOBALS['_csm_w1_data'] = [];
    $GLOBALS['_csm_w2_data'] = [];
}

function gen_copy_dir(string $src, string $dst): array {
    $copied = 0; $failed = 0;
    if (!is_dir($src)) return [$copied, $failed];
    if (!is_dir($dst)) mkdir($dst, 0755, true);
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($items as $item) {
        $rel  = substr($item->getPathname(), strlen($src));
        $dest = $dst . $rel;
        if ($item->isDir()) {
            if (!is_dir($dest)) mkdir($dest, 0755, true);
        } elseif (copy($item->getPathname(), $dest)) {
            $copied++;
        } else {
            $failed++;
        }
    }
    return [$copied, $failed];
}

// Shared render variables — set before each require of site-template.php
$assetPathPrefix = '/';
$homeUrl         = '/';
$slug            = '';
$_SERVER['REQUEST_URI'] = '/';

$siteUrls = []; // Collected for sitemap

// Load site data once — reused for every page render; city pages merge city_vars per-iteration.
$siteData = load_data();

// ── 1. Homepage ───────────────────────────────────────────────────────────────
sse('Generating homepage…');
gen_reset_shortcode_globals();
$data = $siteData;
$contentBlocks  = $data['content_blocks'];
$seo            = $data['seo'];
$pageTitle      = !empty($data['seo']['seo_title']) ? $data['seo']['seo_title'] : SITE_TITLE;
$assetPathPrefix = '/';
$homeUrl         = '/';
$slug            = '';

ob_start();
require BASE_DIR . '/includes/site-template.php';
$html = ob_get_clean();
gen_write($outputBase . 'index.html', $html);
$siteUrls[] = ['loc' => '/', 'priority' => '1.0'];

// ── 2. Landing pages ──────────────────────────────────────────────────────────
$pages = $siteData['pages'] ?? [];
$pageCount = 0;
$writtenSlugs = []; // track every slug written — used to prune stale dirs

foreach ($pages as $pageId => $page) {
    $pageSlug = $page['slug'] ?? '';
    if ($pageSlug === '' || !preg_match('/^[a-z0-9][a-z0-9-]*$/', $pageSlug)) continue;

    gen_reset_shortcode_globals();
    $data = $siteData;
    $contentBlocks   = $page['content_blocks'] ?? [];
    $seo             = $page['seo'] ?? [];
    $pageTitle       = ($page['title'] ?? '') !== '' ? $page['title'] : SITE_TITLE;
    $assetPathPrefix = '/';
    $homeUrl         = '/';
    $slug            = $pageSlug;
    $_SERVER['REQUEST_URI'] = '/' . $pageSlug;

    ob_start();
    require BASE_DIR . '/includes/site-template.php';
    $html = ob_get_clean();

    gen_write($outputBase . $pageSlug . '/index.html', $html);
    $siteUrls[] = ['loc' => '/' . $pageSlug . '/', 'priority' => '0.8'];
    $writtenSlugs[$pageSlug] = true;
    $pageCount++;
}
sse("Landing pages: {$pageCount} generated.");

// ── 3. City pages ─────────────────────────────────────────────────────────────
$cityCount = 0;
if (file_exists(PAGE_INDEX_FILE)) {
    $pageIndex = json_decode(file_get_contents(PAGE_INDEX_FILE), true) ?: [];
    foreach ($pageIndex as $citySlug => $filename) {
        if (!preg_match('/^[a-z0-9][a-z0-9-]*$/', (string)$citySlug)) continue;
        $pageFile = PAGES_DIR . $filename;
        if (!file_exists($pageFile)) continue;

        $gen = json_decode(file_get_contents($pageFile), true);
        if (!is_array($gen)) continue;

        gen_reset_shortcode_globals();
        $data = $siteData;
        $data['site_vars'] = array_merge($data['site_vars'] ?? [], $gen['city_vars'] ?? []);
        $contentBlocks   = $gen['content_blocks'] ?? [];
        $seo             = $gen['seo'] ?? [];
        $pageTitle       = ($gen['title'] ?? '') !== '' ? $gen['title'] : SITE_TITLE;
        $assetPathPrefix = '/';
        $homeUrl         = '/';
        $slug            = $citySlug;
        $_SERVER['REQUEST_URI'] = '/' . $citySlug;

        ob_start();
        require BASE_DIR . '/includes/site-template.php';
        $html = ob_get_clean();

        gen_write($outputBase . $citySlug . '/index.html', $html);
        $siteUrls[] = ['loc' => '/' . $citySlug . '/', 'priority' => '0.7'];
        $writtenSlugs[$citySlug] = true;
        $cityCount++;
    }
}
sse("City pages: {$cityCount} generated.");

// ── Prune stale slug directories ──────────────────────────────────────────────
// Directories in output/ that aren't from this run are leftovers from deleted pages/cities.
$reserved = ['blog', 'assets', 'uploads', 'blog'];
$pruned = 0;
foreach (glob($outputBase . '*', GLOB_ONLYDIR) ?: [] as $dir) {
    $slug = basename($dir);
    if (in_array($slug, $reserved, true)) continue;
    if (!isset($writtenSlugs[$slug])) {
        // Recursively remove stale directory
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
        $pruned++;
    }
}
if ($pruned > 0) sse("Pruned {$pruned} stale page director" . ($pruned === 1 ? 'y' : 'ies') . ' from output.', 'warn');

// ── 4. Blog pages ─────────────────────────────────────────────────────────────
$posts = $siteData['posts'] ?? [];
$blogSettings = $siteData['blog_settings'] ?? [];
$perPage = max(1, (int)($blogSettings['posts_per_page'] ?? 9));

$allPosts = array_values(array_filter($posts, fn($p) => ($p['status'] ?? 'draft') === 'published'));
usort($allPosts, fn($a, $b) => strcmp($b['published_at'] ?? '', $a['published_at'] ?? ''));

// Blog listing (page 1)
$postCount  = 0;
$totalPages = 0;
if (!empty($allPosts)) {
    $totalPages = max(1, (int)ceil(count($allPosts) / $perPage));

    for ($pageNum = 1; $pageNum <= $totalPages; $pageNum++) {
        gen_reset_shortcode_globals();
        $data = $siteData;
        $pagePosts = array_slice($allPosts, ($pageNum - 1) * $perPage, $perPage);
        $cardPosts = [];
        foreach ($pagePosts as $p) {
            $cardPosts[] = [
                'slug'               => $p['slug'] ?? '',
                'title'              => $p['title'] ?? '',
                'excerpt'            => $p['excerpt'] ?? '',
                'featured_image'     => $p['featured_image'] ?? '',
                'featured_image_alt' => $p['featured_image_alt'] ?? '',
                'published_at'       => $p['published_at'] ?? '',
                'tag'                => $p['tag'] ?? '',
            ];
        }
        $allTags = [];
        foreach ($allPosts as $p) {
            $t = trim($p['tag'] ?? '');
            if ($t !== '' && !in_array($t, $allTags, true)) $allTags[] = $t;
        }
        $listBlock = [
            'type'             => 'blog_list',
            'heading'          => $blogSettings['blog_heading'] ?? 'Blog',
            'intro'            => $blogSettings['blog_intro'] ?? '',
            'posts'            => $cardPosts,
            'active_tag'       => '',
            'active_tag_label' => '',
            'all_tags'         => $allTags,
            'pagination'       => ['current' => $pageNum, 'total' => $totalPages, 'base_url' => '/blog'],
        ];
        $contentBlocks   = [$listBlock];
        $seo             = ['meta_description' => $blogSettings['blog_intro'] ?? '', 'canonical_url' => '{website}/blog/'];
        $pageTitle       = $blogSettings['blog_heading'] ?? 'Blog';
        $assetPathPrefix = '/';
        $homeUrl         = '/';
        $slug            = 'blog';
        $_SERVER['REQUEST_URI'] = '/blog';

        ob_start();
        require BASE_DIR . '/includes/site-template.php';
        $html = ob_get_clean();

        if ($pageNum === 1) {
            gen_write($outputBase . 'blog/index.html', $html);
            $siteUrls[] = ['loc' => '/blog/', 'priority' => '0.6'];
        } else {
            gen_write($outputBase . 'blog/page/' . $pageNum . '/index.html', $html);
        }
    }

    // Individual blog posts
    $postCount = 0;
    foreach ($allPosts as $post) {
        $postSlug = $post['slug'] ?? '';
        if ($postSlug === '' || !preg_match('/^[a-z0-9][a-z0-9-]*$/', $postSlug)) continue;

        gen_reset_shortcode_globals();
        $data = $siteData;

        $metaBlock = [
            'type'               => 'post_meta',
            'title'              => $post['title'] ?? '',
            'author'             => $post['author'] ?? '',
            'published_at'       => $post['published_at'] ?? '',
            'tag'                => $post['tag'] ?? '',
            'featured_image'     => $post['featured_image'] ?? '',
            'featured_image_alt' => $post['featured_image_alt'] ?? '',
        ];
        $contentBlocks   = array_merge([$metaBlock], $post['content_blocks'] ?? []);
        $seo             = $post['seo'] ?? [];
        if (empty($seo['bc_mid_label'])) {
            $seo['bc_mid_label'] = 'Blog';
            $seo['bc_mid_url']   = '/blog';
        }
        $pageTitle       = !empty($seo['seo_title']) ? $seo['seo_title'] : (($post['title'] ?? '') !== '' ? $post['title'] : SITE_TITLE);
        if (empty($seo['canonical_url'])) {
            $seo['canonical_url'] = '{website}/blog/' . $postSlug;
        }
        $assetPathPrefix = '/';
        $homeUrl         = '/';
        $slug            = 'blog/' . $postSlug;
        $_SERVER['REQUEST_URI'] = '/blog/' . $postSlug;

        ob_start();
        require BASE_DIR . '/includes/site-template.php';
        $html = ob_get_clean();

        gen_write($outputBase . 'blog/' . $postSlug . '/index.html', $html);
        $siteUrls[] = ['loc' => '/blog/' . $postSlug . '/', 'priority' => '0.6'];
        $postCount++;
    }
    sse("Blog: listing ({$totalPages} page" . ($totalPages > 1 ? 's' : '') . ") + {$postCount} post" . ($postCount !== 1 ? 's' : '') . " generated.");
} else {
    sse('Blog: no published posts, skipping.');
}

// ── 5. 404 page ───────────────────────────────────────────────────────────────
gen_reset_shortcode_globals();
$data = $siteData;
$contentBlocks = [[
    'type'           => 'text',
    'heading_level'  => 'h1',
    'text'           => "Page not found.\n\nSorry, the page you're looking for doesn't exist. Use the menu above to find what you're looking for.",
    'photo'          => '',
    'photo_ratio'    => 'landscape',
    'photo_position' => 'center',
    'photo_alt'      => '',
]];
$seo             = [];
$pageTitle       = 'Page Not Found';
$assetPathPrefix = '/';
$homeUrl         = '/';
$slug            = '';
$_SERVER['REQUEST_URI'] = '/404';

ob_start();
require BASE_DIR . '/includes/site-template.php';
$html = ob_get_clean();
gen_write($outputBase . '404.html', $html);

// ── 6. Copy assets ────────────────────────────────────────────────────────────
sse('Copying assets…');
[$assetCount, $assetFailed] = gen_copy_dir(BASE_DIR . '/assets', $outputBase . 'assets');
sse("Assets: {$assetCount} files copied." . ($assetFailed ? " ({$assetFailed} failed)" : ''), $assetFailed ? 'warn' : 'log');

// ── 7. Copy uploads ───────────────────────────────────────────────────────────
sse('Copying uploads…');
[$uploadCount, $uploadFailed] = gen_copy_dir(UPLOAD_DIR, $outputBase . 'uploads/');
sse("Uploads: {$uploadCount} files copied." . ($uploadFailed ? " ({$uploadFailed} failed)" : ''));

// ── 8. sitemap.xml ────────────────────────────────────────────────────────────
if ($canonicalDomain !== '') {
    $now = date('Y-m-d');
    $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    foreach ($siteUrls as $u) {
        $xml .= "  <url>\n";
        $xml .= '    <loc>' . htmlspecialchars($canonicalDomain . $u['loc'], ENT_XML1) . "</loc>\n";
        $xml .= "    <lastmod>{$now}</lastmod>\n";
        $xml .= '    <priority>' . $u['priority'] . "</priority>\n";
        $xml .= "  </url>\n";
    }
    $xml .= '</urlset>';
    gen_write($outputBase . 'sitemap.xml', $xml);
    sse('Generated: sitemap.xml (' . count($siteUrls) . ' URLs).');
} else {
    sse('Skipping sitemap.xml — canonical domain not set.', 'warn');
}

// ── 9. robots.txt ─────────────────────────────────────────────────────────────
$robotsTxt = "User-agent: *\nAllow: /\n";
if ($canonicalDomain !== '') {
    $robotsTxt .= "\nSitemap: {$canonicalDomain}/sitemap.xml\n";
}
gen_write($outputBase . 'robots.txt', $robotsTxt);
sse('Generated: robots.txt.');

// ── 10. .htaccess ─────────────────────────────────────────────────────────────
$htaccess = <<<'HTACCESS'
Options -Indexes
DirectoryIndex index.html
ErrorDocument 404 /404.html

<IfModule mod_expires.c>
    ExpiresActive On
    ExpiresByType text/html "access plus 0 seconds"
    ExpiresByType text/css "access plus 1 year"
    ExpiresByType application/javascript "access plus 1 year"
    ExpiresByType image/webp "access plus 1 year"
    ExpiresByType image/jpeg "access plus 1 year"
    ExpiresByType image/png "access plus 1 year"
    ExpiresByType image/svg+xml "access plus 1 year"
    ExpiresByType image/gif "access plus 1 year"
</IfModule>

<IfModule mod_deflate.c>
    AddOutputFilterByType DEFLATE text/html text/css application/javascript
</IfModule>
HTACCESS;

// Append 301 redirects
if (defined('REDIRECTS_FILE') && file_exists(REDIRECTS_FILE)) {
    $redirsRaw = json_decode(file_get_contents(REDIRECTS_FILE), true) ?: [];
    if (!empty($redirsRaw)) {
        $htaccess .= "\n\n# 301 Redirects\n";
        foreach ($redirsRaw as $r) {
            $from = preg_replace('/[^\x20-\x7E]/', '', $r['from'] ?? '');
            $to   = preg_replace('/[^\x20-\x7E]/', '', $r['to']   ?? '');
            if ($from === '' || $to === '') continue;
            $htaccess .= 'Redirect 301 ' . $from . ' ' . $to . "\n";
        }
        sse(count($redirsRaw) . ' redirect(s) added to .htaccess.');
    }
}

gen_write($outputBase . '.htaccess', $htaccess);
sse('Generated: .htaccess.');

// ── Done ──────────────────────────────────────────────────────────────────────
$total = 1 + $pageCount + $cityCount + $postCount + 1; // +1 home +1 404
sse("Build complete — {$total} pages written to output/" . ACTIVE_SITE_ID . "/", 'done');
