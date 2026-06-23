<?php
        $activeTab = 'seo';
        $hex = trim($_POST['bc_hero_bg_color_hex'] ?? $_POST['bc_hero_bg_color'] ?? '');
        if ($hex && !preg_match('/^#[0-9a-fA-F]{3,6}$/', $hex)) $hex = '';
        $data['breadcrumbs'] = [
            'enabled'       => !empty($_POST['bc_enabled']),
            'hero_bg_mode'  => ($_POST['bc_hero_bg_mode'] ?? 'auto') === 'custom' ? 'custom' : 'auto',
            'hero_bg_color' => $hex ?: '#0d1b3e',
        ];
        $message = 'success:Breadcrumb settings saved.';
