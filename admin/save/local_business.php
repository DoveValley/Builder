<?php
        $activeTab = 'seo';
        $lb = [
            'lb_name'        => trim($_POST['lb_name']        ?? ''),
            'lb_url'         => sanitize_url($_POST['lb_url']         ?? ''),
            'lb_phone'       => trim($_POST['lb_phone']       ?? ''),
            'lb_street'      => trim($_POST['lb_street']      ?? ''),
            'lb_city'        => trim($_POST['lb_city']        ?? ''),
            'lb_state'       => trim($_POST['lb_state']       ?? ''),
            'lb_zip'         => trim($_POST['lb_zip']         ?? ''),
            'lb_country'     => trim($_POST['lb_country']     ?? 'US'),
            'lb_lat'         => trim($_POST['lb_lat']         ?? ''),
            'lb_lng'         => trim($_POST['lb_lng']         ?? ''),
            'lb_price_range' => trim($_POST['lb_price_range'] ?? '$$'),
            'lb_hours'       => trim($_POST['lb_hours']       ?? ''),
            'lb_description'  => trim($_POST['lb_description']  ?? ''),
            'lb_logo'         => sanitize_url(trim($_POST['lb_logo'] ?? '')),
            'lb_type'         => trim($_POST['lb_type']         ?? 'LocalBusiness'),
            'lb_rating'       => trim($_POST['lb_rating']       ?? ''),
            'lb_review_count' => trim($_POST['lb_review_count'] ?? ''),
        ];
        $data['local_business'] = $lb;
        $message = 'success:Local business info saved.';