<?php
        $pageId = trim($_POST['page_id'] ?? '');
        $isLandingPage = ($pageId !== '' && isset($data['pages'][$pageId]));
        $postId = trim($_POST['post_id'] ?? '');
        $isPost = (!$isLandingPage && $postId !== '' && isset($data['posts'][$postId]));
        // Guard: a non-empty post_id that doesn't resolve must not fall through to the
        // main site content update — that would silently overwrite the homepage blocks.
        if (!$isLandingPage && !$isPost && $postId !== '') {
            header('Location: index.php?tab=blog&msg=' . urlencode('error:Post not found — it may have been deleted. Please refresh and try again.'));
            exit;
        }
        $activeTab = $isLandingPage ? 'pages' : ($isPost ? 'blog' : 'content');

        require_once BASE_DIR . '/includes/blocks_from_post.php';
        [$blocks, $uploadError] = parse_blocks_from_post();


        if ($uploadError && $message === '') $message = 'error:One or more image uploads failed.';

        // SEO
        $seoData = [
            'seo_title'          => trim($_POST['seo_title']          ?? ''),
            'canonical_url'      => sanitize_url($_POST['canonical_url']      ?? ''),
            'meta_description'   => trim($_POST['meta_description']   ?? ''),
            'meta_keywords'      => trim($_POST['meta_keywords']      ?? ''),
            'og_title'           => trim($_POST['og_title']           ?? ''),
            'og_description'     => trim($_POST['og_description']     ?? ''),
            'og_image'           => sanitize_url($_POST['og_image_existing'] ?? ''),
            'og_image_alt'       => trim($_POST['og_image_alt']       ?? ''),
            'bc_hide'            => !empty($_POST['bc_hide']),
            'bc_label'           => trim($_POST['bc_label']           ?? ''),
            'bc_mid_label'       => trim($_POST['bc_mid_label']       ?? ''),
            'bc_mid_url'         => sanitize_url($_POST['bc_mid_url'] ?? ''),
            'og_site_name'       => trim($_POST['og_site_name']       ?? ''),
            'og_locale'          => trim($_POST['og_locale']          ?? ''),
            'twitter_card'       => in_array($_POST['twitter_card'] ?? '', ['summary_large_image','summary']) ? $_POST['twitter_card'] : '',
            'twitter_handle'     => trim($_POST['twitter_handle']     ?? ''),
            'og_type'            => in_array($_POST['og_type'] ?? '', ['website','article']) ? $_POST['og_type'] : 'website',
            'robots_noindex'     => !empty($_POST['robots_noindex']),
        ];
        $existingSeo = $isLandingPage ? $data['pages'][$pageId]['seo'] : ($isPost ? $data['posts'][$postId]['seo'] : $data['seo']);

        $schema = trim($_POST['schema'] ?? '');
        if ($schema === '') {
            // Empty textarea — preserve existing rather than wiping the schema
            $seoData['schema'] = $existingSeo['schema'] ?? '';
        } elseif (json_decode($schema) !== null || $schema === 'null') {
            $seoData['schema'] = $schema;
        } else {
            $seoData['schema'] = $existingSeo['schema'] ?? '';
            if ($message === '') $message = 'error:Schema markup must be valid JSON. Other changes were saved.';
        }

        if ($isLandingPage) {
            $data['pages'][$pageId]['content_blocks'] = $blocks;
            $data['pages'][$pageId]['seo'] = $seoData;
            $data['pages'][$pageId]['title'] = trim($_POST['page_title'] ?? '');
            $requestedSlug = trim($_POST['page_slug'] ?? '') ?: $data['pages'][$pageId]['title'];
            $data['pages'][$pageId]['slug'] = unique_slug($requestedSlug, $data['pages'], $pageId);
            $data['pages'][$pageId]['last_modified'] = date('Y-m-d');
        } elseif ($isPost) {
            $data['posts'][$postId]['content_blocks'] = $blocks;
            $data['posts'][$postId]['seo'] = $seoData;
            $data['posts'][$postId]['title'] = trim($_POST['post_title'] ?? '');
            $requestedSlug = trim($_POST['post_slug'] ?? '') ?: $data['posts'][$postId]['title'];
            $data['posts'][$postId]['slug'] = unique_slug($requestedSlug, $data['posts'], $postId, true);
            $data['posts'][$postId]['status'] = ($_POST['post_status'] ?? 'draft') === 'published' ? 'published' : 'draft';
            $publishedAt = trim($_POST['post_published_at'] ?? '');
            $data['posts'][$postId]['published_at'] = preg_match('/^\d{4}-\d{2}-\d{2}$/', $publishedAt) ? $publishedAt : ($data['posts'][$postId]['published_at'] ?? date('Y-m-d'));
            $data['posts'][$postId]['updated_at'] = date('Y-m-d');
            $data['posts'][$postId]['author'] = trim($_POST['post_author'] ?? '') ?: '{business} Team';
            $data['posts'][$postId]['tag'] = trim($_POST['post_tag'] ?? '');
            $data['posts'][$postId]['excerpt'] = trim($_POST['post_excerpt'] ?? '');
            $data['posts'][$postId]['featured_image'] = trim($_POST['post_featured_image_existing'] ?? '');
            $data['posts'][$postId]['featured_image_alt'] = trim($_POST['post_featured_image_alt'] ?? '');
            if (!empty($_POST['post_remove_featured_image'])) $data['posts'][$postId]['featured_image'] = '';
            $up = upload_image('post_featured_image', 'post_featured');
            if ($up === false) { $uploadError = true; $message = 'error:Featured image upload failed.'; }
            elseif ($up !== null) $data['posts'][$postId]['featured_image'] = $up;
        } else {
            $data['content_blocks'] = $blocks;
            $data['seo'] = $seoData;
            $data['last_modified'] = date('Y-m-d');
        }