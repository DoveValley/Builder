        $activeTab = 'pages';
        $title     = trim($_POST['title'] ?? '');
        $slugInput = trim($_POST['slug']  ?? '') ?: $title;
        $newId     = 'page_' . uniqid();
        $newPage   = default_page_data();
        $newPage['title'] = $title;
        $newPage['slug']  = unique_slug($slugInput, $data['pages']);
        $newPage['page_type'] = ($_POST['page_type'] ?? 'landing') === 'other' ? 'other' : 'landing';
        $data['pages'][$newId] = $newPage;
        if (!save_data($data)) {
            header('Location: index.php?tab=pages&msg=' . urlencode('error:Could not save — the data file could not be written.'));
            exit;
        }
        header('Location: index.php?tab=pages&page=' . urlencode($newId));
        exit;
