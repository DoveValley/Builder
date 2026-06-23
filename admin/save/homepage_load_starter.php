<?php
        $activeTab = 'content';
        $starterId = trim($_POST['starter_id'] ?? '');
        $newBlocks = blocks_from_starter($starterId);

        if (!empty($newBlocks) || $starterId === 'blank') {
            $data['content_blocks'] = $newBlocks;
        } else {
            $message = 'error:Starter not found.';
        }
