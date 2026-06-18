<?php
        $activeTab = 'footer';
        $data['footer']['phone']                = trim($_POST['footer_phone']    ?? '');
        $data['footer']['copyright']            = trim($_POST['copyright']       ?? '');
        $data['footer']['disclaimer']           = trim($_POST['disclaimer']      ?? '');
        $data['footer']['sticky_bar_text']      = trim($_POST['sticky_bar_text'] ?? '');
        $data['footer']['sticky_bar_info']      = trim($_POST['sticky_bar_info'] ?? '');
        $data['footer']['logo_in_copyright_bar']= !empty($_POST['logo_in_copyright_bar']);
        $data['footer']['col_count'] = max(2, min(4, (int)($_POST['footer_col_count'] ?? 3)));
        $socialKeys = ['facebook','instagram','linkedin','youtube','twitter'];
        foreach ($socialKeys as $sk) {
            $data['footer']['socials'][$sk] = sanitize_url(trim($_POST['social_' . $sk] ?? ''));
        }
        $fl = upload_image('footer_logo','footerlogo');
        if ($fl === false) $message = 'error:Footer logo upload failed.';
        elseif ($fl !== null) $data['footer']['logo'] = $fl;
        if (!empty($_POST['remove_footer_logo'])) $data['footer']['logo'] = '';
        $columnsInput = $_POST['footer_columns'] ?? [];
        $columns = [];
        foreach ($columnsInput as $col) {
            $title   = trim($col['title'] ?? '');
            $colType = in_array($col['type'] ?? '', ['text','links','contact']) ? $col['type'] : 'links';

            if ($colType === 'links') {
                $links = [];
                foreach (($col['links'] ?? []) as $link) {
                    $label = trim($link['label'] ?? ''); $url = trim($link['url'] ?? '');
                    if ($label === '' && $url === '') continue;
                    $links[] = ['label' => $label, 'url' => sanitize_url($url) ?: '#'];
                }
                if ($title === '' && empty($links)) continue;
                $columns[] = ['type' => 'links', 'title' => $title, 'links' => $links];

            } elseif ($colType === 'text') {
                $text = trim($col['text'] ?? '');
                if ($title === '' && $text === '') continue;
                $columns[] = ['type' => 'text', 'title' => $title, 'text' => $text];

            } elseif ($colType === 'contact') {
                $extras = [];
                foreach (($col['contact_extras'] ?? []) as $extra) {
                    $label = trim($extra['label'] ?? '');
                    if ($label === '') continue;
                    $extras[] = [
                        'icon'  => trim($extra['icon']  ?? ''),
                        'label' => $label,
                        'url'   => trim($extra['url']   ?? ''),
                    ];
                }
                if ($title === '') continue;
                $columns[] = ['type' => 'contact', 'title' => $title, 'contact_extras' => $extras];
            }
        }
        $data['footer']['columns'] = $columns;
        $bottomLabels = $_POST['bottom_link_label'] ?? [];
        $bottomUrls   = $_POST['bottom_link_url']   ?? [];
        $bottomLinks  = [];
        foreach ($bottomLabels as $i => $label) {
            $label = trim($label); $url = sanitize_url($bottomUrls[$i] ?? '');
            if ($label === '' && $url === '') continue;
            $bottomLinks[] = ['label' => $label, 'url' => $url ?: '#'];
        }
        $data['footer']['bottom_links'] = $bottomLinks;