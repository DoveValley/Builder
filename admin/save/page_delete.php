        $activeTab = 'pages';
        $pageId = trim($_POST['page_id'] ?? '');
        if (isset($data['pages'][$pageId])) unset($data['pages'][$pageId]);
        $msg = save_data($data) ? 'success:Page deleted.' : 'error:Could not save — the data file could not be written.';
        header('Location: index.php?tab=pages&msg=' . urlencode($msg));
        exit;
