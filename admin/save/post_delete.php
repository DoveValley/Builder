        $activeTab = 'blog';
        $postIdToDelete = trim($_POST['post_id'] ?? '');
        if (isset($data['posts'][$postIdToDelete])) unset($data['posts'][$postIdToDelete]);
        $msg = save_data($data) ? 'success:Post deleted.' : 'error:Could not save — the data file could not be written.';
        header('Location: index.php?tab=blog&msg=' . urlencode($msg));
        exit;
