<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';

$data    = load_data();
$baseUrl = rtrim(resolve_shortcodes('{website}'), '/');

header('Content-Type: text/plain; charset=utf-8');
echo "User-agent: *\n";
echo "Allow: /\n";
echo "Disallow: /admin/\n";
echo "Disallow: /data/\n";
echo "\n";
echo "Sitemap: {$baseUrl}/sitemap.xml\n";
