<?php
// City Image plugin save handler.
// Auth + CSRF already verified by admin/plugin_save.php before this runs.
// Functions (load_data/save_data, city_image_fetch_for) are already loaded.

$base   = 'index.php?tab=plugins&plugin=city_image';
$action = $_POST['action'] ?? 'save';
$data   = load_data();
$v      = $data['site_vars'] ?? [];

// Resolve the active site's media dir (absolute) + the path prefix stored in JSON,
// mirroring how uploaded media paths are recorded ("sites/{id}/uploads/media" in the
// factory; "uploads/media" for a deployed single site).
$outDir      = rtrim(UPLOAD_DIR, '/') . '/media';
$rel         = trim(str_replace(BASE_DIR, '', ACTIVE_SITE_DIR), '/');
$storePrefix = ($rel !== '' ? $rel . '/' : '') . 'uploads/media';

if ($action === 'fetch') {
    $res = city_image_fetch_for([
        'city'         => $v['city']      ?? '',
        'state'        => $v['state']     ?? '',
        'ss'           => $v['SS']        ?? '',
        'city_slug'    => $v['city_slug'] ?? '',
        'out_dir'      => $outDir,
        'store_prefix' => $storePrefix,
    ]);
    if (!$res) {
        header('Location: ' . $base . '&msg=error:No+Wikipedia+image+found+for+' . rawurlencode($v['city'] ?? 'this city'));
        exit;
    }
    $data['site_vars']['city_image']        = $res['path'];
    $data['site_vars']['city_image_alt']    = $res['alt'];
    $data['site_vars']['city_image_credit'] = $res['credit'];
    $data['site_vars']['city_image_source'] = $res['source'];
    save_data($data)
        ? header('Location: ' . $base . '&msg=success:Fetched+' . rawurlencode($res['title']))
        : header('Location: ' . $base . '&msg=error:Could+not+save');
    exit;
}

if ($action === 'clear') {
    unset($data['site_vars']['city_image'], $data['site_vars']['city_image_alt'],
          $data['site_vars']['city_image_credit'], $data['site_vars']['city_image_source']);
    save_data($data);
    header('Location: ' . $base . '&msg=success:City+image+cleared');
    exit;
}

// action=save — manual edits to the SEO alt / credit (image itself unchanged).
$data['site_vars']['city_image_alt']    = trim($_POST['city_image_alt']    ?? ($v['city_image_alt']    ?? ''));
$data['site_vars']['city_image_credit'] = trim($_POST['city_image_credit'] ?? ($v['city_image_credit'] ?? ''));
save_data($data)
    ? header('Location: ' . $base . '&msg=success:Saved')
    : header('Location: ' . $base . '&msg=error:Could+not+save');
exit;
