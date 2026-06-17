        $pageId = trim($_POST['page_id'] ?? '');
        $isLandingPage = ($pageId !== '' && isset($data['pages'][$pageId]));
        $postId = trim($_POST['post_id'] ?? '');
        $isPost = (!$isLandingPage && $postId !== '' && isset($data['posts'][$postId]));
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
            'og_image'           => trim($_POST['og_image_existing']   ?? ''),
            'service_name'       => trim($_POST['service_name']       ?? ''),
            'service_type'       => trim($_POST['service_type']       ?? ''),
            'service_area'       => trim($_POST['service_area']       ?? ''),
            'service_description'=> trim($_POST['service_description']?? ''),
            'bc_label'           => trim($_POST['bc_label']           ?? ''),
            'bc_mid_label'       => trim($_POST['bc_mid_label']       ?? ''),
            'bc_mid_url'         => sanitize_url($_POST['bc_mid_url']         ?? ''),
        ];
        $existingSeo = $isLandingPage ? $data['pages'][$pageId]['seo'] : ($isPost ? $data['posts'][$postId]['seo'] : $data['seo']);
        $schema = trim($_POST['schema'] ?? '');
        if ($schema === '') {
            $seoData['schema'] = '';
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
        } elseif ($isPost) {
            $data['posts'][$postId]['content_blocks'] = $blocks;
            $data['posts'][$postId]['seo'] = $seoData;
            $data['posts'][$postId]['title'] = trim($_POST['post_title'] ?? '');
            $requestedSlug = trim($_POST['post_slug'] ?? '') ?: $data['posts'][$postId]['title'];
            $data['posts'][$postId]['slug'] = unique_slug($requestedSlug, $data['posts'], $postId);
            $data['posts'][$postId]['status'] = ($_POST['post_status'] ?? 'draft') === 'published' ? 'published' : 'draft';
            $publishedAt = trim($_POST['post_published_at'] ?? '');
            $data['posts'][$postId]['published_at'] = preg_match('/^\d{4}-\d{2}-\d{2}$/', $publishedAt) ? $publishedAt : date('Y-m-d');
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
        }
