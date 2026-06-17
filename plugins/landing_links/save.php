<?php
// Landing Links: Multi-City save handler.
// Auth + CSRF already verified by admin/plugin_save.php before this runs.

$base   = 'index.php?tab=plugins&plugin=landing_links';
$action = $_POST['action'] ?? 'save';

if ($action !== 'save') {
    header('Location: ' . $base);
    exit;
}

$data = load_data();

$cols = max(2, min(4, (int)($_POST['cols'] ?? 3)));

$data['landing_links'] = [
    'format'          => in_array($_POST['format'] ?? '', ['by_state', 'columns', 'list'], true)
                            ? $_POST['format'] : 'by_state',
    'cols'            => $cols,
    'link_text'       => trim($_POST['link_text']        ?? '{template_title} in {city}, {SS}'),
    'template_filter' => trim($_POST['template_filter']  ?? ''),
];

$saved = save_data($data);
if (!$saved) {
    header('Location: ' . $base . '&msg=error:Could+not+save+—+check+file+permissions.');
    exit;
}
header('Location: ' . $base . '&msg=success:Landing+Links+settings+saved.');
exit;
