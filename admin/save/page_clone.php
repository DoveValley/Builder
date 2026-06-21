<?php
        $activeTab = 'pages';
        $srcId = trim($_POST['page_id'] ?? '');
        $src   = $data['pages'][$srcId] ?? null;

        if ($src === null) {
            $message = 'error:Page not found.';
            break;
        }

        $newId   = 'page_' . uniqid();
        $newPage = $src;
        $newPage['title'] = '(Copy) ' . $src['title'];
        $newPage['slug']  = unique_slug($src['slug'] . '-copy', $data['pages']);

        $data['pages'][$newId] = $newPage;
        if (!save_data($data)) {
            header('Location: index.php?tab=pages&msg=' . urlencode('error:Could not save — check disk space and permissions.'));
            exit;
        }
        header('Location: index.php?tab=pages&page=' . urlencode($newId) . '&msg=success:Page+cloned');
        exit;
