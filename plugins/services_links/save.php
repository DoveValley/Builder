<?php
// Services Links plugin save handler.
// Auth + CSRF already verified by admin/plugin_save.php before this runs.
// Two actions:
//   save — persist the edited {name,url} rows + layout/text settings
//   sync — merge in any landing template not already listed (never clobbers edits)

$base   = 'index.php?tab=plugins&plugin=services_links';
$action = $_POST['action'] ?? 'save';
if (!in_array($action, ['save', 'sync'], true)) { header('Location: ' . $base); exit; }

$data = load_data();

// Parse the editable rows: parallel svc_name[] / svc_url[] arrays. Skip blank
// names; dedupe by url (or name when url is blank).
$names = $_POST['svc_name'] ?? [];
$urls  = $_POST['svc_url']  ?? [];
if (!is_array($names)) $names = [];
if (!is_array($urls))  $urls  = [];

$services = [];
$seen     = [];
$n = count($names);
for ($i = 0; $i < $n; $i++) {
    $name = trim((string)($names[$i] ?? ''));
    if ($name === '') continue;
    $url = sanitize_url(trim((string)($urls[$i] ?? '')));
    $key = $url !== '' ? $url : strtolower($name);
    if (isset($seen[$key])) continue;
    $seen[$key] = true;
    $services[] = ['name' => $name, 'url' => $url];
}

// Sync: append landing templates that aren't already in the list. Uses each
// template's real slug_pattern (authoritative link) and seo.service_name.
$syncMsg = '';
if ($action === 'sync') {
    $templates = (defined('TEMPLATES_FILE') && file_exists(TEMPLATES_FILE))
        ? (json_decode(file_get_contents(TEMPLATES_FILE), true) ?: []) : [];
    $added = 0;
    foreach ($templates as $t) {
        if (!is_array($t)) continue;
        $slug = trim($t['slug_pattern'] ?? '');
        if ($slug === '') continue;
        $url = sanitize_url('/' . ltrim($slug, '/'));
        if ($url === '' || isset($seen[$url])) continue;
        $name = trim($t['seo']['service_name'] ?? '');
        if ($name === '') {
            $name = trim(preg_replace('/\s*\|.*$/', '', (string)($t['title'] ?? '')));
            $name = trim(str_replace(['in {city_state}', '{city_state}'], '', $name));
        }
        if ($name === '') continue;
        $seen[$url] = true;
        $services[] = ['name' => $name, 'url' => $url];
        $added++;
    }
    $syncMsg = $added > 0 ? ($added . '+service(s)+added+from+templates.') : 'Already+in+sync+—+no+new+templates.';
}

// Background photo upload
$existing = trim($_POST['bg_photo_existing'] ?? '');
$bgPhoto  = $existing;
if (!empty($_POST['bg_photo_remove'])) $bgPhoto = '';
$up = upload_image('bg_photo_upload', 'services');
if ($up === false) {
    header('Location: ' . $base . '&msg=error:Background+photo+upload+failed.');
    exit;
}
if ($up !== null) $bgPhoto = $up;

// Numeric fields — clamp to safe ranges
$cols    = max(2, min(6, (int)($_POST['cols']    ?? 5)));
$overlay = max(0.0, min(1.0, (float)($_POST['overlay'] ?? 0.20)));

$data['services_links'] = [
    'services'      => $services,
    'url_pattern'   => sanitize_url(trim($_POST['url_pattern'] ?? '/{service_slug}-{city_slug}')),
    'heading'       => trim($_POST['heading']        ?? ''),
    'sublabel'      => trim($_POST['sublabel']       ?? ''),
    'subtext'       => trim($_POST['subtext']        ?? ''),
    'style'         => in_array($_POST['style'] ?? '', ['dark', 'light'], true) ? $_POST['style'] : 'dark',
    'cols'          => $cols,
    'bg_photo'      => $bgPhoto,
    'overlay'       => number_format($overlay, 2, '.', ''),
    'bg_color'      => trim($_POST['bg_color']       ?? '#ffffff'),
    'accent'        => trim($_POST['accent']         ?? 'accent'),
    'accent_custom' => trim($_POST['accent_custom']  ?? '#fd783b'),
    'anchor'        => trim($_POST['anchor']         ?? ''),
];

$saved = save_data($data);
if (!$saved) {
    header('Location: ' . $base . '&msg=error:Could+not+save+—+check+file+permissions.');
    exit;
}
$msg = $action === 'sync' ? ('success:' . $syncMsg) : 'success:Services+Links+saved.';
header('Location: ' . $base . '&msg=' . $msg);
exit;
