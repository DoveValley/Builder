<?php
/**
 * PHP built-in server router — simulates .htaccess mod_rewrite locally.
 * Usage: php -S localhost:8080 router.php
 *
 * Without this, pretty URLs (/some-slug) fall back to index.php silently.
 */

$uri  = $_SERVER['REQUEST_URI'];
$path = parse_url($uri, PHP_URL_PATH);

// 1. Serve real files (assets, uploads, PHP scripts) as-is
if ($path !== '/' && file_exists(__DIR__ . $path)) {
    return false;
}

// 2. Strip leading slash to get the candidate slug
$slug = ltrim($path, '/');

// 3. Known PHP entry points — pass through directly
$phpFiles = ['index.php', 'page.php', 'blog.php', 'admin', 'sitemap.xml'];
foreach ($phpFiles as $f) {
    if ($slug === $f || str_starts_with($slug, $f . '/') || str_starts_with($slug, $f . '?')) {
        return false;
    }
}

// 4. /blog or /blog/some-post → blog.php
if ($slug === 'blog' || str_starts_with($slug, 'blog/')) {
    $post = substr($slug, 5); // strip "blog/"
    $_GET['slug'] = ltrim($post, '/');
    $_SERVER['SCRIPT_NAME'] = '/blog.php';
    require __DIR__ . '/blog.php';
    return true;
}

// 5. Everything else is a page slug → page.php
if ($slug !== '') {
    $_GET['slug'] = $slug;
    $_SERVER['SCRIPT_NAME'] = '/page.php';
    require __DIR__ . '/page.php';
    return true;
}

// 6. Root → homepage
require __DIR__ . '/index.php';
return true;
