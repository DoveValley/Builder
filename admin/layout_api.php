<?php
// Layout variations API (item 2a) — generate + save a page's alternate block orderings.
// POST only. Admin auth + CSRF. Scope: home | page. JSON responses.

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

if (empty($_SESSION['admin_logged_in'])) { http_response_code(403); echo json_encode(['error' => 'Not authenticated.']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'POST required.']); exit; }
if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) { http_response_code(403); echo json_encode(['error' => 'Invalid request token.']); exit; }

$action = $_POST['action'] ?? '';
$scope  = ($_POST['scope'] ?? 'home') === 'page' ? 'page' : 'home';
$id     = trim($_POST['id'] ?? '');

$data = load_data();

// Bind $ref to the target container (home = whole site; page = one entry in pages[]).
if ($scope === 'page') {
    if ($id === '' || !isset($data['pages'][$id])) { echo json_encode(['error' => 'Page not found.']); exit; }
    $ref = &$data['pages'][$id];
} else {
    $ref = &$data;
}

/** Attach a readable label to each id in an ordering. */
function _lv_label_list(array $ids, array $blocks): array {
    $byId = [];
    foreach ($blocks as $b) { if (!empty($b['id'])) $byId[$b['id']] = $b; }
    $out = [];
    foreach ($ids as $id) { $out[] = ['id' => $id, 'label' => layout_block_label($byId[$id] ?? ['type' => '?'])]; }
    return $out;
}

switch ($action) {

    // Ensure block ids (persist if newly assigned), then produce a fresh randomized set.
    case 'generate':
        $blocks = ensure_block_ids($ref['content_blocks'] ?? []);
        if ($blocks !== ($ref['content_blocks'] ?? [])) { $ref['content_blocks'] = $blocks; save_data($data); }
        if (count($blocks) < 4) { echo json_encode(['error' => 'This page has too few blocks to vary (need 4+).']); break; }
        $variants = layout_generate_variants($blocks, 4, true);
        $naturalIds = array_map(fn($b) => $b['id'], $blocks);
        echo json_encode([
            'natural'  => _lv_label_list($naturalIds, $blocks),
            'variants' => array_map(fn($v) => _lv_label_list($v, $blocks), $variants),
        ]);
        break;

    // Persist the enable flag + chosen orderings (arrays of block ids) for this page.
    case 'save':
        $enabled = !empty($_POST['enabled']);
        $raw = json_decode($_POST['variants'] ?? '[]', true);
        $known = [];
        foreach (($ref['content_blocks'] ?? []) as $b) { if (!empty($b['id'])) $known[$b['id']] = true; }
        $variants = [];
        if (is_array($raw)) {
            foreach ($raw as $v) {
                if (!is_array($v)) continue;
                $clean = [];
                foreach ($v as $bid) { $bid = (string)$bid; if (isset($known[$bid])) $clean[] = $bid; }
                if ($clean) $variants[] = $clean;
            }
        }
        $ref['layout_enabled']  = $enabled;
        $ref['layout_variants'] = $variants;
        save_data($data);
        echo json_encode(['saved' => true, 'count' => count($variants), 'enabled' => $enabled]);
        break;

    // Current stored config for this page.
    case 'load':
        echo json_encode([
            'enabled'  => !empty($ref['layout_enabled']),
            'variants' => array_map(fn($v) => _lv_label_list($v, $ref['content_blocks'] ?? []), $ref['layout_variants'] ?? []),
        ]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action.']);
}
