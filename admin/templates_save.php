<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/blocks_from_post.php';

if (empty($_SESSION['admin_logged_in'])) { header('Location: login.php'); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: index.php?tab=templates'); exit; }

$token = $_POST['csrf_token'] ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
    header('Location: index.php?tab=templates&msg=error:Invalid+request+token');
    exit;
}

$action = $_POST['action'] ?? '';

function _tpl_load(): array {
    if (!file_exists(TEMPLATES_FILE)) return [];
    $raw = json_decode(file_get_contents(TEMPLATES_FILE), true);
    return is_array($raw) ? $raw : [];
}

function _tpl_save(array $templates): bool {
    $dir = dirname(TEMPLATES_FILE);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $content = json_encode(array_values($templates), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $tmp = TEMPLATES_FILE . '.tmp.' . getmypid();
    if (file_put_contents($tmp, $content) === false) return false;
    return rename($tmp, TEMPLATES_FILE);
}

function _tpl_cleanup_pages(string $tplId): void {
    if (!defined('PAGES_DIR') || !defined('PAGE_INDEX_FILE')) return;
    $deleted = [];
    foreach (glob(PAGES_DIR . $tplId . '_*.json') ?: [] as $f) {
        @unlink($f);
        if (file_exists($f . '.bak')) @unlink($f . '.bak');
        $deleted[basename($f)] = true;
    }
    if (!empty($deleted) && file_exists(PAGE_INDEX_FILE)) {
        $raw = json_decode(file_get_contents(PAGE_INDEX_FILE), true);
        if (is_array($raw)) {
            $raw = array_filter($raw, fn($fn) => !isset($deleted[$fn]));
            $tmp = PAGE_INDEX_FILE . '.tmp.' . getmypid();
            file_put_contents($tmp, json_encode($raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            rename($tmp, PAGE_INDEX_FILE);
        }
    }
}

function _tpl_make_id(string $title, array $templates): string {
    $base = 'tpl_' . preg_replace('/[^a-z0-9]+/', '_', strtolower(trim($title)));
    $base = trim($base, '_');
    if ($base === 'tpl_') $base = 'tpl_' . substr(uniqid(), -6);
    $id = $base;
    $n  = 2;
    $existing = array_column($templates, 'id');
    while (in_array($id, $existing, true)) { $id = $base . '_' . $n++; }
    return $id;
}

// ── add ───────────────────────────────────────────────────────────────────────
if ($action === 'add') {
    $title       = trim($_POST['title'] ?? '');
    $slugPattern = trim($_POST['slug_pattern'] ?? '');

    if ($title === '') {
        header('Location: index.php?tab=templates&msg=error:Template+title+is+required');
        exit;
    }

    $templates = _tpl_load();
    $id = _tpl_make_id($title, $templates);

    if ($slugPattern === '') {
        $slugPattern = slugify($title) . '-{city_slug}';
    }

    $templates[] = [
        'id'               => $id,
        'title'            => $title,
        'slug_pattern'     => $slugPattern,
        'page_type'        => 'template',
        'generation_steps' => [['step' => 'city_vars']],
        'content_blocks'   => [],
        'seo'              => [
            'seo_title' => '', 'canonical_url' => '', 'meta_description' => '',
            'meta_keywords' => '', 'og_title' => '', 'og_description' => '',
            'og_image' => '', 'service_name' => '', 'service_type' => '',
            'service_area' => '', 'service_description' => '', 'schema' => '',
            'bc_label' => '', 'bc_mid_label' => '', 'bc_mid_url' => '',
        ],
    ];

    if (!_tpl_save($templates)) {
        header('Location: index.php?tab=templates&msg=error:Could+not+save+template');
        exit;
    }
    header('Location: index.php?tab=templates&template=' . urlencode($id) . '&msg=success:Template+created');
    exit;
}

// ── save (block editor + SEO + settings) ─────────────────────────────────────
if ($action === 'save') {
    $id = trim($_POST['template_id'] ?? '');
    $templates = _tpl_load();
    $idx = null;
    foreach ($templates as $k => $t) { if ($t['id'] === $id) { $idx = $k; break; } }

    if ($idx === null) {
        header('Location: index.php?tab=templates&msg=error:Template+not+found');
        exit;
    }

    [$blocks, $uploadError] = parse_blocks_from_post();

    $seoData = [
        'seo_title'          => trim($_POST['seo_title']           ?? ''),
        'canonical_url'      => sanitize_url($_POST['canonical_url']      ?? ''),
        'meta_description'   => trim($_POST['meta_description']    ?? ''),
        'meta_keywords'      => trim($_POST['meta_keywords']       ?? ''),
        'og_title'           => trim($_POST['og_title']            ?? ''),
        'og_description'     => trim($_POST['og_description']      ?? ''),
        'og_image'           => trim($_POST['og_image_existing']   ?? ''),
        'service_name'       => trim($_POST['service_name']        ?? ''),
        'service_type'       => trim($_POST['service_type']        ?? ''),
        'service_area'       => trim($_POST['service_area']        ?? ''),
        'service_description'=> trim($_POST['service_description'] ?? ''),
        'bc_label'           => trim($_POST['bc_label']            ?? ''),
        'bc_mid_label'       => trim($_POST['bc_mid_label']        ?? ''),
        'bc_mid_url'         => sanitize_url($_POST['bc_mid_url']  ?? ''),
    ];
    $schema = trim($_POST['schema'] ?? '');
    $message = '';
    if ($schema === '') {
        $seoData['schema'] = '';
    } elseif (json_decode($schema) !== null || $schema === 'null') {
        $seoData['schema'] = $schema;
    } else {
        $seoData['schema'] = $templates[$idx]['seo']['schema'] ?? '';
        $message = 'error:Schema+markup+must+be+valid+JSON.+Other+changes+were+saved.';
    }

    $slugPattern = trim($_POST['slug_pattern'] ?? '') ?: ($templates[$idx]['slug_pattern'] ?? '');

    // Parse generation steps from JSON textarea
    $rawSteps = trim($_POST['generation_steps'] ?? '');
    $steps = $templates[$idx]['generation_steps'] ?? [['step' => 'city_vars']];
    if ($rawSteps !== '') {
        $parsed = json_decode($rawSteps, true);
        if (is_array($parsed)) {
            $steps = $parsed;
        }
    }

    $templates[$idx]['title']            = trim($_POST['template_title'] ?? '') ?: $templates[$idx]['title'];
    $templates[$idx]['slug_pattern']     = $slugPattern;
    $templates[$idx]['generation_steps'] = $steps;
    $templates[$idx]['content_blocks']   = $blocks;
    $templates[$idx]['seo']              = $seoData;

    if (!_tpl_save($templates)) {
        header('Location: index.php?tab=templates&template=' . urlencode($id) . '&msg=error:Could+not+save+template');
        exit;
    }

    $msg = $message ?: 'success:Template+saved+successfully.';
    if ($uploadError && strpos($msg, 'error') === false) {
        $msg = 'error:One+or+more+image+uploads+failed.';
    }
    header('Location: index.php?tab=templates&template=' . urlencode($id) . '&msg=' . $msg);
    exit;
}

// ── duplicate ─────────────────────────────────────────────────────────────────
if ($action === 'duplicate') {
    $id = trim($_POST['template_id'] ?? '');
    $templates = _tpl_load();
    $source = null;
    foreach ($templates as $t) { if ($t['id'] === $id) { $source = $t; break; } }

    if ($source === null) {
        header('Location: index.php?tab=templates&msg=error:Template+not+found');
        exit;
    }

    $newTitle = $source['title'] . ' (Copy)';
    $newId    = _tpl_make_id($newTitle, $templates);
    $copy = $source;
    $copy['id']    = $newId;
    $copy['title'] = $newTitle;
    $templates[] = $copy;

    if (!_tpl_save($templates)) {
        header('Location: index.php?tab=templates&msg=error:Could+not+duplicate+template');
        exit;
    }
    header('Location: index.php?tab=templates&template=' . urlencode($newId) . '&msg=success:Template+duplicated');
    exit;
}

// ── delete ────────────────────────────────────────────────────────────────────
if ($action === 'delete') {
    $id = trim($_POST['template_id'] ?? '');
    $templates = _tpl_load();
    $templates = array_values(array_filter($templates, fn($t) => $t['id'] !== $id));

    if (!_tpl_save($templates)) {
        header('Location: index.php?tab=templates&msg=error:Could+not+delete+template');
        exit;
    }
    _tpl_cleanup_pages($id);
    header('Location: index.php?tab=templates&msg=success:Template+deleted');
    exit;
}

// ── migrate_from_page — promote a landing page from site.json into templates ──
if ($action === 'migrate_from_page') {
    $pageId      = trim($_POST['page_id']      ?? '');
    $tplTitle    = trim($_POST['tpl_title']    ?? '');
    $slugPattern = trim($_POST['slug_pattern'] ?? '');
    $keepOrig    = !empty($_POST['keep_original']);

    $siteData = load_data();
    $page = $siteData['pages'][$pageId] ?? null;

    if ($page === null || ($page['page_type'] ?? '') !== 'landing') {
        header('Location: index.php?tab=pages&msg=error:Landing+page+not+found');
        exit;
    }

    if ($tplTitle    === '') $tplTitle    = $page['title'];
    if ($slugPattern === '') $slugPattern = $page['slug'];

    $templates = _tpl_load();
    $id = _tpl_make_id($tplTitle, $templates);

    $templates[] = [
        'id'               => $id,
        'title'            => $tplTitle,
        'slug_pattern'     => $slugPattern,
        'page_type'        => 'template',
        'generation_steps' => [['step' => 'city_vars']],
        'content_blocks'   => $page['content_blocks'] ?? [],
        'seo'              => $page['seo']             ?? [],
    ];

    if (!_tpl_save($templates)) {
        header('Location: index.php?tab=pages&msg=error:Could+not+save+template');
        exit;
    }

    if (!$keepOrig) {
        unset($siteData['pages'][$pageId]);
        save_data($siteData);
    }

    $msg = $keepOrig
        ? 'success:Template+created.+Original+page+kept+in+Landing+Pages.'
        : 'success:Page+promoted+to+template.+Original+landing+page+removed.';
    header('Location: index.php?tab=templates&template=' . urlencode($id) . '&msg=' . $msg);
    exit;
}

header('Location: index.php?tab=templates');
exit;
