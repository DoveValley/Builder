<?php
        $activeTab = 'seo';
        // MERGE, never replace. This form (admin/tabs/seo.php) posts only the four
        // fields below, but local_business also carries lb_type, lb_phone, lb_street,
        // lb_city, lb_state, lb_zip, lb_country, lb_lat, lb_lng, lb_price_range,
        // lb_hours, lb_description and lb_logo — written by the multisite differentiate
        // step (ms_differentiate_working_dir), not by anything on this screen.
        // Assigning a fresh four-key array over the top silently dropped all thirteen.
        // That is not hypothetical: granitepmacademy has had 4 fields since commit
        // 4a6585cb where every other master has 17, so its address, geo, phone, hours,
        // description and logo were lost on a live site by someone opening this tab and
        // pressing Save. Each field is still assigned unconditionally, so clearing an
        // input in the form still clears the stored value.
        $lb = $data['local_business'] ?? [];
        $lb['lb_name']         = trim($_POST['lb_name']         ?? '');
        $lb['lb_url']          = sanitize_url($_POST['lb_url']  ?? '');
        $lb['lb_rating']       = trim($_POST['lb_rating']       ?? '');
        $lb['lb_review_count'] = trim($_POST['lb_review_count'] ?? '');
        $data['local_business'] = $lb;
        $message = 'success:Local business info saved.';