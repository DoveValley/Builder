<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

if (empty($_SESSION['admin_logged_in'])) { header('Location: login.php'); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: index.php?tab=starters'); exit; }

$token = $_POST['csrf_token'] ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
    header('Location: index.php?tab=starters&msg=error:Invalid+request+token');
    exit;
}

$action = $_POST['action'] ?? '';

function _str_make_id(string $label, array $starters): string {
    $base = preg_replace('/[^a-z0-9]+/', '_', strtolower(trim($label)));
    $base = trim($base, '_') ?: 'starter';
    $id = $base; $n = 2;
    $existing = array_column($starters, 'id');
    while (in_array($id, $existing, true)) { $id = $base . '_' . $n++; }
    return $id;
}

// ── add ───────────────────────────────────────────────────────────────────────
if ($action === 'add') {
    $label    = trim($_POST['label']    ?? '');
    $desc     = trim($_POST['desc']     ?? '');
    $category = trim($_POST['category'] ?? 'universal');
    if ($label === '') {
        header('Location: index.php?tab=starters&msg=error:Starter+name+is+required');
        exit;
    }
    $starters = starters_load();
    $id = _str_make_id($label, $starters);
    $starters[] = ['id' => $id, 'label' => $label, 'desc' => $desc, 'category' => $category, 'blocks' => []];
    if (!starters_save($starters)) {
        header('Location: index.php?tab=starters&msg=error:Could+not+save');
        exit;
    }
    header('Location: index.php?tab=starters&starter=' . urlencode($id) . '&cat=' . urlencode($category) . '&msg=success:Starter+created');
    exit;
}

// ── save ──────────────────────────────────────────────────────────────────────
if ($action === 'save') {
    $id        = trim($_POST['starter_id'] ?? '');
    $label     = trim($_POST['label']      ?? '');
    $desc      = trim($_POST['desc']       ?? '');
    $category  = trim($_POST['category']   ?? 'universal');
    $returnCat = trim($_POST['return_cat'] ?? $category);
    $rawBlocks = $_POST['starter_blocks'] ?? [];
    $blocks    = array_values(array_filter(array_map('trim', (array)$rawBlocks)));

    $starters = starters_load();
    $found = false;
    foreach ($starters as &$s) {
        if ($s['id'] === $id) {
            if ($label !== '') $s['label'] = $label;
            $s['desc']     = $desc;
            $s['category'] = $category;
            $s['blocks']   = $blocks;
            $found = true;
            break;
        }
    }
    unset($s);

    if (!$found) {
        header('Location: index.php?tab=starters&msg=error:Starter+not+found');
        exit;
    }
    if (!starters_save($starters)) {
        header('Location: index.php?tab=starters&starter=' . urlencode($id) . '&cat=' . urlencode($returnCat) . '&msg=error:Could+not+save');
        exit;
    }
    header('Location: index.php?tab=starters&starter=' . urlencode($id) . '&cat=' . urlencode($category) . '&msg=success:Starter+saved');
    exit;
}

// ── duplicate ─────────────────────────────────────────────────────────────────
if ($action === 'duplicate') {
    $id        = trim($_POST['starter_id'] ?? '');
    $returnCat = trim($_POST['return_cat'] ?? 'training');
    $starters  = starters_load();
    $source    = null;
    foreach ($starters as $s) { if ($s['id'] === $id) { $source = $s; break; } }
    if (!$source) {
        header('Location: index.php?tab=starters&cat=' . urlencode($returnCat) . '&msg=error:Starter+not+found');
        exit;
    }
    $newLabel = $source['label'] . ' (Copy)';
    $newId    = _str_make_id($newLabel, $starters);
    $copy = $source;
    $copy['id'] = $newId; $copy['label'] = $newLabel;
    $starters[] = $copy;
    if (!starters_save($starters)) {
        header('Location: index.php?tab=starters&cat=' . urlencode($returnCat) . '&msg=error:Could+not+duplicate');
        exit;
    }
    header('Location: index.php?tab=starters&starter=' . urlencode($newId) . '&cat=' . urlencode($returnCat) . '&msg=success:Starter+duplicated');
    exit;
}

// ── delete ────────────────────────────────────────────────────────────────────
if ($action === 'delete') {
    $id        = trim($_POST['starter_id'] ?? '');
    $returnCat = trim($_POST['return_cat'] ?? 'training');
    $starters  = starters_load();
    $starters  = array_values(array_filter($starters, fn($s) => $s['id'] !== $id));
    if (!starters_save($starters)) {
        header('Location: index.php?tab=starters&cat=' . urlencode($returnCat) . '&msg=error:Could+not+delete');
        exit;
    }
    header('Location: index.php?tab=starters&cat=' . urlencode($returnCat) . '&msg=success:Starter+deleted');
    exit;
}

header('Location: index.php?tab=starters');
exit;
