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

if (!ACTIVE_SITE_ID) { header('Location: sites.php'); exit; }

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
            'primary_keyword' => '', 'secondary_keywords' => '',
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
    $blocks = ensure_block_ids($blocks);   // stable ids for layout variations (2a)

    // Guard against silent max_input_vars truncation: if JS reported more blocks
    // than PHP received, the POST was cut short — refuse to overwrite blocks.
    $submittedCount = (int)($_POST['block_count_submitted'] ?? -1);
    if ($submittedCount > 0 && count($blocks) < $submittedCount) {
        header('Location: index.php?tab=templates&template=' . urlencode($id)
            . '&msg=error:Save+aborted+%E2%80%94+only+' . count($blocks) . '+of+' . $submittedCount
            . '+blocks+received+%28PHP+max_input_vars+limit%29.+Blocks+not+changed.');
        exit;
    }

    $seoData = [
        'primary_keyword'    => trim($_POST['primary_keyword']     ?? ''),
        'secondary_keywords' => trim($_POST['secondary_keywords']  ?? ''),
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
        'og_type'            => in_array($_POST['og_type'] ?? '', ['website','article']) ? $_POST['og_type'] : 'website',
        'robots_noindex'     => !empty($_POST['robots_noindex']),
    ];
    $schema = trim($_POST['schema'] ?? '');
    $message = '';
    if ($schema === '') {
        // Empty textarea — preserve whatever was already saved rather than wiping it
        $seoData['schema'] = $templates[$idx]['seo']['schema'] ?? '';
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

// ── set_base — flag/unflag a template as an "Master Template" (the master a batch clones from) ──
if ($action === 'set_base') {
    $id  = trim($_POST['template_id'] ?? '');
    $on  = !empty($_POST['on']);
    $templates = _tpl_load();
    $found = false;
    foreach ($templates as &$t) {
        if (($t['id'] ?? '') === $id) {
            if ($on) { $t['base'] = true; } else { unset($t['base']); }
            $found = true;
            break;
        }
    }
    unset($t);

    if (!$found) {
        header('Location: index.php?tab=templates&msg=error:Template+not+found');
        exit;
    }
    if (!_tpl_save($templates)) {
        header('Location: index.php?tab=templates&msg=error:Could+not+update+template');
        exit;
    }
    $msg = $on ? 'success:Moved+to+Master+Template' : 'success:Moved+to+Templates';
    header('Location: index.php?tab=templates&msg=' . $msg);
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

// ── registry_add ─────────────────────────────────────────────────────────────
if ($action === 'registry_add') {
    $rid   = preg_replace('/[^a-z0-9_]/', '', strtolower(trim($_POST['registry_id'] ?? '')));
    $label = trim($_POST['reg_label'] ?? '');

    if (!ai_valid_type_id($rid)) {
        header('Location: index.php?tab=templates&msg=error:Invalid+type+ID+(lowercase+letters%2C+numbers%2C+underscores+only)');
        exit;
    }
    if ($label === '') {
        header('Location: index.php?tab=templates&msg=error:Label+is+required');
        exit;
    }

    $registry = ai_load_registry();
    if (isset($registry[$rid])) {
        header('Location: index.php?tab=templates&msg=error:A+registry+entry+with+that+ID+already+exists');
        exit;
    }

    $registry[$rid] = [
        'label'             => $label,
        'description'       => '',
        'ai_mode'           => 'standalone',
        'ai_render_as'      => 'text',
        'ai_model'          => 'claude-haiku-4-5-20251001',
        'ai_inject_target'  => null,
        'ai_inject_field'   => null,
        'ai_inject_mode'    => null,
        'ai_prompt'         => '',
        'ai_output_schema'  => ['heading_text' => 'string', 'text' => 'html_string'],
        'default_fields'    => [],
    ];

    if (!ai_save_registry($registry)) {
        header('Location: index.php?tab=templates&msg=error:Could+not+save+registry');
        exit;
    }
    header('Location: index.php?tab=templates&registry=' . urlencode($rid) . '&msg=success:Registry+entry+created');
    exit;
}

// ── registry_save ─────────────────────────────────────────────────────────────
if ($action === 'registry_save') {
    $rid = preg_replace('/[^a-z0-9_]/', '', strtolower(trim($_POST['registry_id'] ?? '')));
    if (!ai_valid_type_id($rid)) {
        header('Location: index.php?tab=templates&msg=error:Invalid+registry+ID');
        exit;
    }

    $registry = ai_load_registry();
    if (!isset($registry[$rid])) {
        header('Location: index.php?tab=templates&msg=error:Registry+entry+not+found');
        exit;
    }

    $aiModeRaw = trim($_POST['reg_ai_mode'] ?? 'standalone');
    $aiMode    = in_array($aiModeRaw, ['standalone', 'inject']) ? $aiModeRaw : 'standalone';

    $aiModel = model_or_default($_POST['reg_ai_model'] ?? '');

    $aiInjectTargetRaw = trim($_POST['reg_ai_inject_target'] ?? '');
    $aiInjectTarget    = in_array($aiInjectTargetRaw, ['previous', 'next']) ? $aiInjectTargetRaw : null;
    $aiInjectModeRaw   = trim($_POST['reg_ai_inject_mode'] ?? '');
    $aiInjectMode      = in_array($aiInjectModeRaw, ['replace', 'append', 'prepend']) ? $aiInjectModeRaw : null;

    $rawSchema = trim($_POST['reg_ai_output_schema'] ?? '{}');
    $schema = json_decode($rawSchema, true);
    if (!is_array($schema)) {
        header('Location: index.php?tab=templates&registry=' . urlencode($rid) . '&msg=error:Output+schema+must+be+valid+JSON');
        exit;
    }

    $rawDefaults = trim($_POST['reg_default_fields'] ?? '{}');
    $defaults = json_decode($rawDefaults, true);
    if (!is_array($defaults)) {
        header('Location: index.php?tab=templates&registry=' . urlencode($rid) . '&msg=error:Default+fields+must+be+valid+JSON');
        exit;
    }

    $registry[$rid] = [
        'label'             => trim($_POST['reg_label'] ?? $registry[$rid]['label']),
        'description'       => trim($_POST['reg_description'] ?? ''),
        'ai_mode'           => $aiMode,
        'ai_render_as'      => $aiMode === 'standalone' ? trim($_POST['reg_ai_render_as'] ?? 'text') : null,
        'ai_model'          => $aiModel,
        'ai_inject_target'  => $aiMode === 'inject' ? $aiInjectTarget : null,
        'ai_inject_field'   => $aiMode === 'inject' ? trim($_POST['reg_ai_inject_field'] ?? '') : null,
        'ai_inject_mode'    => $aiMode === 'inject' ? $aiInjectMode : null,
        'ai_prompt'         => trim($_POST['reg_ai_prompt'] ?? ''),
        'ai_output_schema'  => $schema,
        'default_fields'    => $defaults,
    ];

    if (!ai_save_registry($registry)) {
        header('Location: index.php?tab=templates&registry=' . urlencode($rid) . '&msg=error:Could+not+save+registry');
        exit;
    }
    header('Location: index.php?tab=templates&registry=' . urlencode($rid) . '&msg=success:Registry+entry+saved');
    exit;
}

// ── registry_delete ───────────────────────────────────────────────────────────
if ($action === 'registry_delete') {
    $rid = preg_replace('/[^a-z0-9_]/', '', strtolower(trim($_POST['registry_id'] ?? '')));
    $registry = ai_load_registry();
    if (isset($registry[$rid])) {
        unset($registry[$rid]);
        ai_save_registry($registry);
    }
    header('Location: index.php?tab=templates&msg=success:Registry+entry+deleted');
    exit;
}

// ── bulk_generate — clone a base template into many, via find/replace + images ──
// Niche-agnostic: nothing here is pest-specific. It clones the chosen base
// template, applies per-row case-aware find/replace over its blocks + seo body,
// swaps the 3 image slots (hero + first two image_text blocks), and overrides the
// clean metadata (id/title/slug/service + seo service fields). Reusable for any
// niche — pick that niche's base template and paste that niche's rows.
if ($action === 'bulk_generate') {
    $mode    = (($_POST['mode'] ?? 'preview') === 'commit') ? 'commit' : 'preview';
    $baseId  = trim($_POST['base_id'] ?? '');
    $rawRows = (string)($_POST['rows'] ?? '');
    $projRoot = dirname(__DIR__);

    $templates = _tpl_load();
    $base = null;
    foreach ($templates as $t) { if (($t['id'] ?? '') === $baseId) { $base = $t; break; } }
    if ($base === null) {
        header('Location: index.php?tab=templates&msg=error:Pick+a+valid+base+template'); exit;
    }

    // Case-preserving replace of a whole word (word-boundaried so "roach" never
    // eats "approach"). "Cockroach"→ucfirst, "COCKROACH"→upper, else lowercase.
    $caseReplace = function (string $s, string $find, string $repl): string {
        if ($find === '') return $s;
        return preg_replace_callback('/\b' . preg_quote($find, '/') . '\b/i', function ($m) use ($repl) {
            $o = $m[0];
            $letters = preg_replace('/[^a-zA-Z]/', '', $o);
            if ($letters !== '' && ctype_upper($letters)) return strtoupper($repl);
            if (isset($o[0]) && ctype_upper($o[0]))       return ucfirst($repl);
            return $repl;
        }, $s);
    };
    $applyReplace = function (&$node, array $pairs) use (&$applyReplace, $caseReplace) {
        if (is_array($node)) {
            foreach ($node as &$v) $applyReplace($v, $pairs);
            unset($v);
        } elseif (is_string($node)) {
            foreach ($pairs as [$f, $r]) $node = $caseReplace($node, $f, $r);
        }
    };
    // Assign hero → first hero block's main (non-bg) image field; intro/local →
    // it_photo of the 1st/2nd image_text block, in document order.
    $assignImages = function (array &$blocks, string $hero, string $intro, string $local): array {
        $done = []; $itN = 0;
        foreach ($blocks as &$b) {
            if (!is_array($b)) continue;
            $type = $b['type'] ?? '';
            if ($hero !== '' && strncmp($type, 'hero', 4) === 0) {
                foreach ($b as $k => $v) {
                    if (is_string($k) && isset($k[0]) && $k[0] === '_') continue;
                    if (is_string($v) && preg_match('/\.(jpe?g|png|webp)$/i', $v)
                        && stripos($v, 'uploads') !== false && stripos($k, 'bg') === false) {
                        $b[$k] = $hero; $done['hero'] = $k; $hero = ''; break;
                    }
                }
            }
            if (($b['ai_render_as'] ?? '') === 'image_text' || $type === 'image_text') {
                $img = $itN === 0 ? $intro : ($itN === 1 ? $local : '');
                if ($img !== '') { $b['it_photo'] = $img; $done['it' . $itN] = $img; }
                $itN++;
            }
        }
        unset($b);
        return $done;
    };
    $mediaPath = function (string $file): string {
        $file = trim($file);
        if ($file === '') return '';
        if (strpos($file, '/') !== false) return $file;                 // already a path
        return 'sites/' . ACTIVE_SITE_ID . '/uploads/media/' . $file;
    };

    $report = [];
    foreach (preg_split('/\r\n|\r|\n/', $rawRows) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        $c = array_map('trim', explode('|', $line));
        $service = $c[0] ?? '';
        if ($service === '') continue;

        $slugBase = ($c[1] ?? '') !== '' ? $c[1] : slugify($service);
        $keyword  = ($c[2] ?? '') !== '' ? $c[2] : $service;
        $pairs = [];
        foreach (explode(';', $c[3] ?? '') as $p) {
            $p = trim($p); if ($p === '') continue;
            $kv = explode('=', $p, 2);
            if (count($kv) === 2 && trim($kv[0]) !== '') $pairs[] = [trim($kv[0]), trim($kv[1])];
        }
        $heroImg  = $mediaPath($c[4] ?? '');
        $introImg = $mediaPath($c[5] ?? '');
        $localImg = $mediaPath($c[6] ?? '');
        $title    = ($c[7] ?? '') !== '' ? $c[7] : ($service . ' in {city_state} | {business}');

        $tpl = json_decode(json_encode($base), true);           // deep clone
        if (!isset($tpl['content_blocks']) || !is_array($tpl['content_blocks'])) $tpl['content_blocks'] = [];
        if (!isset($tpl['seo'])            || !is_array($tpl['seo']))            $tpl['seo'] = [];
        $applyReplace($tpl['content_blocks'], $pairs);
        $applyReplace($tpl['seo'], $pairs);
        $imgDone = $assignImages($tpl['content_blocks'], $heroImg, $introImg, $localImg);

        $newId = _tpl_make_id($service, $templates);
        $tpl['id']            = $newId;
        $tpl['title']         = $title;
        $tpl['slug_pattern']  = $slugBase . '-{city_slug}';
        $tpl['service']       = $service;
        $tpl['seo']['service_name']    = $service;
        $tpl['seo']['service_type']    = $service;
        $tpl['seo']['seo_title']       = $title;
        $tpl['seo']['primary_keyword'] = $keyword;
        // Hero H1 = keyword + city, not the word-swapped base phrasing (e.g. avoid
        // "Pest Exterminator" on a "Commercial Pest Control" page).
        foreach ($tpl['content_blocks'] as &$hb) {
            if (is_array($hb) && strncmp($hb['type'] ?? '', 'hero', 4) === 0 && isset($hb['hs_heading'])) {
                $hb['hs_heading'] = $service . ' in {city_state}';
                break;
            }
        }
        unset($hb);

        // leftover check: any find-word still present after replace = a stray base subject
        $blob = json_encode($tpl);
        $leftover = 0;
        foreach ($pairs as [$f, $r]) $leftover += (int)preg_match_all('/\b' . preg_quote($f, '/') . '\b/i', $blob);
        $imgMissing = [];
        foreach (['hero' => $heroImg, 'intro' => $introImg, 'local' => $localImg] as $slot => $pth) {
            if ($pth !== '' && !is_file($projRoot . '/' . $pth)) $imgMissing[] = $slot;
        }

        $report[] = [
            'id' => $newId, 'title' => $title, 'slug' => $tpl['slug_pattern'], 'service' => $service,
            'images' => ['hero' => $heroImg, 'intro' => $introImg, 'local' => $localImg],
            'img_slots' => $imgDone, 'img_missing' => $imgMissing, 'leftover' => $leftover,
        ];
        $templates[] = $tpl;    // keeps _tpl_make_id unique across the batch
    }

    if (empty($report)) {
        header('Location: index.php?tab=templates&msg=error:No+valid+rows+parsed'); exit;
    }

    if ($mode === 'preview') {
        $_SESSION['tpl_bulk'] = ['base' => $baseId, 'rows' => $rawRows, 'report' => $report];
        header('Location: index.php?tab=templates#bulkgen'); exit;
    }

    // commit — back up first, then save (built rows already appended to $templates)
    @copy(TEMPLATES_FILE, TEMPLATES_FILE . '.bak');
    if (!_tpl_save($templates)) {
        header('Location: index.php?tab=templates&msg=error:Could+not+save+templates'); exit;
    }
    unset($_SESSION['tpl_bulk']);
    $n = count($report);
    header('Location: index.php?tab=templates&msg=success:' . $n . '+template(s)+generated+(backup+saved+to+templates.json.bak)'); exit;
}

header('Location: index.php?tab=templates');
exit;
