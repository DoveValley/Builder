<?php
// Keywords tab save handler. Map-first: writes data/keyword_map.json.
// Auth + CSRF verified here (public POST endpoint pattern, same as other admin saves).

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

if (empty($_SESSION['admin_logged_in'])) { header('Location: login.php'); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: index.php?tab=keywords'); exit; }
if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
    header('Location: index.php?tab=keywords&msg=error:Invalid+request+token'); exit;
}
if (!ACTIVE_SITE_ID) { header('Location: sites.php'); exit; }

$kwFile = dirname(TEMPLATES_FILE) . '/keyword_map.json';
$action = $_POST['action'] ?? '';

if ($action === 'save_primaries') {
    // Carry over any existing secondary keywords for services that already exist (match by slug).
    $existing = file_exists($kwFile) ? (json_decode(file_get_contents($kwFile), true) ?: []) : [];
    $prevBySlug = [];
    foreach (($existing['services'] ?? []) as $s) {
        if (!empty($s['slug'])) $prevBySlug[$s['slug']] = $s['secondary'] ?? [];
    }

    $names  = $_POST['kw_primary'] ?? [];
    $slugs  = $_POST['kw_slug']    ?? [];
    $tiers  = $_POST['kw_tier']    ?? [];
    $stats  = $_POST['kw_status']  ?? [];
    $vols   = $_POST['kw_volume']  ?? [];
    $kds    = $_POST['kw_kd']       ?? [];
    if (!is_array($names)) $names = [];

    $slugify = fn(string $s) => trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($s)), '-');
    $validTier   = ['high', 'medium', 'low', ''];
    $validStatus = ['primary', 'fold', 'cut'];

    $services = [];
    $seen = [];
    for ($i = 0; $i < count($names); $i++) {
        $primary = trim((string)($names[$i] ?? ''));
        if ($primary === '') continue;
        $slug = trim((string)($slugs[$i] ?? '')) ?: $slugify($primary);
        $slug = $slugify($slug);
        if ($slug === '' || isset($seen[$slug])) continue;
        $seen[$slug] = true;
        $tier   = in_array($tiers[$i] ?? '', $validTier, true)   ? ($tiers[$i] ?? '') : '';
        $status = in_array($stats[$i] ?? '', $validStatus, true) ? ($stats[$i] ?? 'primary') : 'primary';
        $services[] = [
            'primary'   => $primary,
            'slug'      => $slug,
            'tier'      => $tier,
            'status'    => $status,
            'volume'    => trim((string)($vols[$i] ?? '')),
            'kd'        => trim((string)($kds[$i] ?? '')),
            'secondary' => $prevBySlug[$slug] ?? [],
        ];
    }

    // Keep the pasted Ahrefs data (capped) so it persists + powers Stage 2.
    $ahrefs = (string)($_POST['ahrefs_data'] ?? ($existing['ahrefs_data'] ?? ''));
    if (strlen($ahrefs) > 200000) $ahrefs = substr($ahrefs, 0, 200000);

    $map = [
        'niche'         => trim($_POST['niche'] ?? ($existing['niche'] ?? '')),
        'ahrefs_data'   => $ahrefs,
        'services'      => $services,
        'stage'         => 'secondary',   // primaries solidified → Stage 2 unlocked
        'updated_at'    => date('c'),
    ];
    $content = json_encode($map, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $tmp = $kwFile . '.tmp.' . getmypid();
    if (file_put_contents($tmp, $content) === false || !rename($tmp, $kwFile)) {
        header('Location: index.php?tab=keywords&msg=error:Could+not+save+keyword+map'); exit;
    }
    $kept = count(array_filter($services, fn($s) => $s['status'] === 'primary'));
    header('Location: index.php?tab=keywords&msg=success:Primaries+solidified+(' . $kept . '+primary+of+' . count($services) . ').'); exit;
}

header('Location: index.php?tab=keywords');
exit;
