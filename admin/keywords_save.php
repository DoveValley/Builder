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
    $existing = file_exists($kwFile) ? (json_decode(file_get_contents($kwFile), true) ?: []) : [];

    $names  = $_POST['kw_primary']   ?? [];
    $slugs  = $_POST['kw_slug']      ?? [];
    $tiers  = $_POST['kw_tier']      ?? [];
    $sects  = $_POST['kw_section']   ?? [];
    $seces  = $_POST['kw_secondary'] ?? [];
    if (!is_array($names)) $names = [];

    $slugify = fn(string $s) => trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($s)), '-');
    $validTier    = ['high-1','high-2','high-3','medium-1','medium-2','medium-3','low-1','low-2','low-3',''];
    $validSection = ['home', 'core', 'landing'];

    $services = [];
    $seen = [];
    for ($i = 0; $i < count($names); $i++) {
        $primary = trim((string)($names[$i] ?? ''));
        if ($primary === '') continue;
        $slug = trim((string)($slugs[$i] ?? '')) ?: $slugify($primary);
        $slug = $slugify($slug);
        if ($slug === '' || isset($seen[$slug])) continue;
        $seen[$slug] = true;
        $tier    = in_array($tiers[$i] ?? '', $validTier, true)    ? ($tiers[$i] ?? '') : '';
        $section = in_array($sects[$i] ?? '', $validSection, true) ? ($sects[$i] ?? 'landing') : 'landing';
        // Secondary keywords: one textarea per keyword, comma- or line-separated.
        $secondary = array_values(array_filter(
            array_map('trim', preg_split('/[\r\n,]+/', (string)($seces[$i] ?? ''))),
            fn($x) => $x !== ''
        ));
        $services[] = [
            'primary'   => $primary,
            'slug'      => $slug,
            'section'   => $section,
            'tier'      => $tier,
            'secondary' => $secondary,
        ];
    }

    $map = [
        'niche'      => trim($_POST['niche'] ?? ($existing['niche'] ?? '')),
        'services'   => $services,
        'updated_at' => date('c'),
    ];
    $content = json_encode($map, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $tmp = $kwFile . '.tmp.' . getmypid();
    if (file_put_contents($tmp, $content) === false || !rename($tmp, $kwFile)) {
        header('Location: index.php?tab=keywords&msg=error:Could+not+save+keyword+map'); exit;
    }
    header('Location: index.php?tab=keywords&msg=success:Keyword+map+saved+(' . count($services) . '+keyword' . (count($services) === 1 ? '' : 's') . ').'); exit;
}

header('Location: index.php?tab=keywords');
exit;
