<?php
// Keywords tab save handler. Map-first: writes data/keyword_map.json.
// Auth + CSRF verified here (public POST endpoint pattern, same as other admin saves).

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/multisite/page_pool.php';

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
    $pools  = $_POST['kw_pool']      ?? [];
    if (!is_array($names)) $names = [];

    $slugify = fn(string $s) => trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($s)), '-');
    $validTier    = ['high-1','high-2','high-3','medium-1','medium-2','medium-3','low-1','low-2','low-3',''];
    $validSection = ['home', 'core', 'landing'];
    $validPool    = ['pinned', 'rotate', 'skip'];

    $services = [];
    $seen = [];
    $droppedDupes = [];
    for ($i = 0; $i < count($names); $i++) {
        $primary = trim((string)($names[$i] ?? ''));
        if ($primary === '') continue;
        $slug = trim((string)($slugs[$i] ?? '')) ?: $slugify($primary);
        $slug = $slugify($slug);
        if ($slug === '') continue;
        if (isset($seen[$slug])) {
            // Two different-looking primaries can slugify identically (e.g. "AC Repair"
            // vs "A/C Repair") — silently keeping only the first with no feedback meant
            // there was no way to tell a row had been dropped, or which one.
            $droppedDupes[] = $primary;
            continue;
        }
        $seen[$slug] = true;
        $tier    = in_array($tiers[$i] ?? '', $validTier, true)    ? ($tiers[$i] ?? '') : '';
        $section = in_array($sects[$i] ?? '', $validSection, true) ? ($sects[$i] ?? 'landing') : 'landing';
        // Secondary keywords: one textarea per keyword, comma- or line-separated.
        $secondary = array_values(array_filter(
            array_map('trim', preg_split('/[\r\n,]+/', (string)($seces[$i] ?? ''))),
            fn($x) => $x !== ''
        ));
        // Page pool (landing rows only — see includes/multisite/page_pool.php). Always
        // written explicitly, defaulted from tier when the form didn't send a valid one
        // (a brand-new row, or a niche saved before this field existed), so the stored
        // map never depends on tier at read time — pool is its own deliberate setting.
        $pool = in_array($pools[$i] ?? '', $validPool, true)
            ? $pools[$i]
            : ms_page_pool_default_for_tier($tier);
        $services[] = [
            'primary'   => $primary,
            'slug'      => $slug,
            'section'   => $section,
            'tier'      => $tier,
            'secondary' => $secondary,
            'pool'      => $pool,
        ];
    }

    // Page-count options: a HANDFUL of possible totals a domain can land on (picked per
    // domain, seeded by name — see ms_page_pool_select()), never one fixed number, same
    // reasoning as the palette/font pools. Blank/invalid entries dropped; falls back to
    // the module's own default if nothing usable was submitted.
    $counts = array_values(array_unique(array_filter(
        array_map('intval', (array) ($_POST['pp_counts'] ?? [])),
        fn($n) => $n > 0
    )));
    sort($counts);
    if (!$counts) $counts = MS_PAGE_POOL_DEFAULT_COUNTS;

    // 'enabled' is preserved from whatever was already on file, never set true here —
    // page pooling is an explicit, deliberate opt-in per master (see
    // ms_page_pool_config()), not something a routine keyword edit should switch on.
    $map = [
        'niche'      => trim($_POST['niche'] ?? ($existing['niche'] ?? '')),
        'services'   => $services,
        'page_pool'  => ['enabled' => (bool) ($existing['page_pool']['enabled'] ?? false), 'counts' => $counts],
        'updated_at' => date('c'),
    ];
    $content = json_encode($map, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $tmp = $kwFile . '.tmp.' . getmypid();
    if (file_put_contents($tmp, $content) === false || !rename($tmp, $kwFile)) {
        header('Location: index.php?tab=keywords&msg=error:Could+not+save+keyword+map'); exit;
    }
    $msg = 'Keyword map saved (' . count($services) . '+keyword' . (count($services) === 1 ? '' : 's') . ').';
    if (!empty($droppedDupes)) {
        $msg = 'warning:' . $msg . ' Dropped ' . count($droppedDupes) . ' duplicate-slug row(s): '
             . implode(', ', array_slice($droppedDupes, 0, 10))
             . (count($droppedDupes) > 10 ? ', ...' : '') . '.';
    } else {
        $msg = 'success:' . $msg;
    }
    header('Location: index.php?tab=keywords&msg=' . urlencode($msg)); exit;
}

header('Location: index.php?tab=keywords');
exit;
