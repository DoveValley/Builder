<?php
        $activeTab = 'blog';
        $postIdToDelete = trim($_POST['post_id'] ?? '');
        if (isset($data['posts'][$postIdToDelete])) {
            $deletedSlug = $data['posts'][$postIdToDelete]['_blog_topic_slug'] ?? '';
            unset($data['posts'][$postIdToDelete]);
            // Record the topic so a rebuild's cache-read (ms_blog_inject_from_cache()) can't
            // resurrect this exact post and generate_blog_posts() won't re-pick the same
            // topic — both read this field straight off site.json, no cache-file lookup.
            if ($deletedSlug !== '') {
                if (!isset($data['blog_deleted_slugs']) || !is_array($data['blog_deleted_slugs'])) {
                    $data['blog_deleted_slugs'] = [];
                }
                if (!in_array($deletedSlug, $data['blog_deleted_slugs'], true)) {
                    $data['blog_deleted_slugs'][] = $deletedSlug;
                }
            }
        }
        $msg = save_data($data) ? 'success:Post deleted.' : 'error:Could not save — the data file could not be written.';
        header('Location: index.php?tab=blog&msg=' . urlencode($msg));
        exit;