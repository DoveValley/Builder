<?php
        $activeTab = 'blog';
        $title     = trim($_POST['title'] ?? '');
        $slugInput = trim($_POST['slug']  ?? '') ?: $title;
        $newId     = 'post_' . uniqid();
        $newPost   = default_post_data();
        $newPost['title'] = $title;
        $newPost['slug']  = unique_slug($slugInput, $data['posts'], null, true);
        $data['posts'][$newId] = $newPost;
        if (!save_data($data)) {
            header('Location: index.php?tab=blog&msg=' . urlencode('error:Could not save — the data file could not be written.'));
            exit;
        }
        header('Location: index.php?tab=blog&post=' . urlencode($newId));
        exit;