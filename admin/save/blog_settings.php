        $activeTab = 'blog';
        $data['blog_settings']['blog_heading']   = trim($_POST['blog_heading']  ?? '');
        $data['blog_settings']['blog_intro']     = trim($_POST['blog_intro']    ?? '');
        $data['blog_settings']['posts_per_page'] = max(1, min(50, (int)($_POST['posts_per_page'] ?? 9)));
        $message = 'success:Blog settings saved.';
