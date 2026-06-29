<?php
        $activeTab = 'seo';
        $lb = [
            'lb_name'         => trim($_POST['lb_name']         ?? ''),
            'lb_url'          => sanitize_url($_POST['lb_url']  ?? ''),
            'lb_rating'       => trim($_POST['lb_rating']       ?? ''),
            'lb_review_count' => trim($_POST['lb_review_count'] ?? ''),
        ];
        $data['local_business'] = $lb;
        $message = 'success:Local business info saved.';