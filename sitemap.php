<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';

$data  = load_data();
$pages = $data['pages'] ?? [];

$baseUrl = rtrim(resolve_shortcodes('{website}'), '/');
$today   = date('Y-m-d');

header('Content-Type: application/xml; charset=utf-8');
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

// Homepage
echo "  <url>\n";
echo "    <loc>" . htmlspecialchars($baseUrl . '/') . "</loc>\n";
echo "    <changefreq>weekly</changefreq>\n";
echo "    <priority>1.0</priority>\n";
echo "    <lastmod>{$today}</lastmod>\n";
echo "  </url>\n";

// Landing pages — deduplicate by resolved URL
$seen = [];
foreach ($pages as $page) {
    if (!is_array($page)) continue;

    $slug = $page['slug'] ?? '';
    if (!$slug) continue;

    // Use canonical_url (supports shortcodes like {website}/{city_slug})
    // Fall back to building from base URL + slug
    $canonical = trim($page['seo']['canonical_url'] ?? '');
    if ($canonical) {
        $loc = rtrim(resolve_shortcodes($canonical), '/') . '/';
    } else {
        $loc = $baseUrl . '/' . $slug . '/';
    }

    if (isset($seen[$loc])) continue;
    $seen[$loc] = true;

    echo "  <url>\n";
    echo "    <loc>" . htmlspecialchars($loc) . "</loc>\n";
    echo "    <changefreq>monthly</changefreq>\n";
    echo "    <priority>0.8</priority>\n";
    echo "    <lastmod>{$today}</lastmod>\n";
    echo "  </url>\n";
}

// Blog index + published posts
$posts = $data['posts'] ?? [];
if (!empty($posts)) {
    $blogLoc = $baseUrl . '/blog/';
    if (!isset($seen[$blogLoc])) {
        $seen[$blogLoc] = true;
        echo "  <url>\n";
        echo "    <loc>" . htmlspecialchars($blogLoc) . "</loc>\n";
        echo "    <changefreq>weekly</changefreq>\n";
        echo "    <priority>0.6</priority>\n";
        echo "    <lastmod>{$today}</lastmod>\n";
        echo "  </url>\n";
    }
    foreach ($posts as $post) {
        if (!is_array($post) || ($post['status'] ?? 'draft') !== 'published') continue;
        $slug = $post['slug'] ?? '';
        if (!$slug) continue;
        $loc = $blogLoc . $slug . '/';
        if (isset($seen[$loc])) continue;
        $seen[$loc] = true;
        $lastmod = $post['updated_at'] ?? ($post['published_at'] ?? $today);
        echo "  <url>\n";
        echo "    <loc>" . htmlspecialchars($loc) . "</loc>\n";
        echo "    <changefreq>monthly</changefreq>\n";
        echo "    <priority>0.5</priority>\n";
        echo "    <lastmod>" . htmlspecialchars($lastmod) . "</lastmod>\n";
        echo "  </url>\n";
    }
}

echo '</urlset>';
