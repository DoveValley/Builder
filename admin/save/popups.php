        $activeTab = 'popups';
        if (!isset($data['popups'])) $data['popups'] = [];

        $existing = trim($_POST['popup_info_image_existing'] ?? '');
        $imgPath  = $existing;
        if (!empty($_POST['popup_info_remove_image'])) $imgPath = '';
        $up = upload_image('popup_info_image', 'popup');
        if ($up === false) $message = 'error:Popup image upload failed.';
        elseif ($up !== null) $imgPath = $up;

        // Simple bold: **text** → <strong>text</strong>
        $body = trim($_POST['popup_info_body'] ?? '');

        $data['popups']['info'] = [
            'enabled' => !empty($_POST['popup_info_enabled']),
            'heading' => trim($_POST['popup_info_heading'] ?? 'How Your Calls Are Handled'),
            'image'   => $imgPath,
            'body'    => $body,
        ];
