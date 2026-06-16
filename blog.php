<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';

$data = load_data();
$slug = isset($_GET['slug']) ? slugify($_GET['slug']) : '';

$assetPathPrefix = '/';
$homeUrl         = '/';

/* ---------------- SINGLE POST ---------------- */
if ($slug !== '') {
    [$postId, $post] = find_post_by_slug($data['posts'], $slug);

    if ($post === null || ($post['status'] ?? 'draft') !== 'published') {
        http_response_code(404);
        $contentBlocks = [
            [
                'type'           => 'text',
                'heading_level'  => 'h1',
                'text'           => "Post not found.\n\nSorry, the blog post you're looking for doesn't exist or has been unpublished.",
                'photo'          => '',
                'photo_ratio'    => 'landscape',
                'photo_position' => 'center',
                'photo_alt'      => '',
            ],
        ];
        $seo       = [];
        $pageTitle = 'Post Not Found';
        require __DIR__ . '/includes/site-template.php';
        exit;
    }

    $metaBlock = [
        'type'               => 'post_meta',
        'title'              => $post['title'] ?? '',
        'author'             => $post['author'] ?? '',
        'published_at'       => $post['published_at'] ?? '',
        'tag'                => $post['tag'] ?? '',
        'featured_image'     => $post['featured_image'] ?? '',
        'featured_image_alt' => $post['featured_image_alt'] ?? '',
    ];

    $contentBlocks = array_merge([$metaBlock], $post['content_blocks'] ?? []);
    $seo           = $post['seo'] ?? [];

    if (empty($seo['bc_mid_label'])) {
        $seo['bc_mid_label'] = 'Blog';
        $seo['bc_mid_url']   = '/blog';
    }

    if (empty($seo['schema'])) {
        $schemaArr = [
            '@context'      => 'https://schema.org',
            '@type'         => 'BlogPosting',
            'headline'      => resolve_shortcodes($post['title'] ?? ''),
            'datePublished' => $post['published_at'] ?? '',
            'dateModified'  => $post['updated_at'] ?? ($post['published_at'] ?? ''),
            'author'        => ['@type' => 'Organization', 'name' => resolve_shortcodes('{business}')],
        ];
        if (!empty($post['featured_image'])) {
            $schemaArr['image'] = rtrim(resolve_shortcodes('{website}'), '/') . '/' . ltrim($post['featured_image'], '/');
        }
        $seo['schema'] = json_encode($schemaArr);
    }

    $pageTitle = $post['title'] !== '' ? $post['title'] : SITE_TITLE;

    require __DIR__ . '/includes/site-template.php';
    exit;
}

/* ---------------- BLOG LISTING ---------------- */
$tag     = isset($_GET['tag']) ? trim($_GET['tag']) : '';
$pageNum = max(1, (int)($_GET['p'] ?? 1));
$perPage = max(1, (int)($data['blog_settings']['posts_per_page'] ?? 9));

$allPosts = array_values(array_filter($data['posts'] ?? [], fn($p) => ($p['status'] ?? 'draft') === 'published'));
usort($allPosts, fn($a, $b) => strcmp($b['published_at'] ?? '', $a['published_at'] ?? ''));

$allTags = [];
foreach ($allPosts as $p) {
    $t = trim($p['tag'] ?? '');
    if ($t !== '' && !in_array($t, $allTags, true)) $allTags[] = $t;
}

$activeTagLabel = '';
if ($tag !== '') {
    $tagSlug = slugify($tag);
    foreach ($allPosts as $p) {
        if (slugify($p['tag'] ?? '') === $tagSlug) { $activeTagLabel = $p['tag']; break; }
    }
    $allPosts = array_values(array_filter($allPosts, fn($p) => slugify($p['tag'] ?? '') === $tagSlug));
}

$totalPosts = count($allPosts);
$totalPages = max(1, (int)ceil($totalPosts / $perPage));
$pageNum    = min($pageNum, $totalPages);
$pagePosts  = array_slice($allPosts, ($pageNum - 1) * $perPage, $perPage);

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

$baseUrl = '/blog' . ($tag !== '' ? '?tag=' . urlencode($tag) : '');

$listBlock = [
    'type'             => 'blog_list',
    'heading'          => $data['blog_settings']['blog_heading'] ?? 'Blog',
    'intro'            => $data['blog_settings']['blog_intro'] ?? '',
    'posts'            => $cardPosts,
    'active_tag'       => $tag,
    'active_tag_label' => $activeTagLabel,
    'all_tags'         => $allTags,
    'pagination'       => ['current' => $pageNum, 'total' => $totalPages, 'base_url' => $baseUrl],
];

$contentBlocks = [$listBlock];
$seo = [
    'meta_description' => $data['blog_settings']['blog_intro'] ?? '',
    'canonical_url'    => '{website}/blog/',
];
$pageTitle = ($data['blog_settings']['blog_heading'] ?? 'Blog') . ($activeTagLabel ? ' — ' . $activeTagLabel : '');
$slug      = 'blog';

require __DIR__ . '/includes/site-template.php';
