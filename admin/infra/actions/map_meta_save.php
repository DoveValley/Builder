<?php
/**
 * infra/actions/map_meta_save.php — save Map-tab-only fields, edited from a dot's
 * popup. Today that's just `label_dir` (which way the leader line points); more
 * will land here later as the same pattern.
 *
 * Writes ONLY to map_meta.json (see lib/map_meta.php) — never to hestia.json.
 * hestia.json holds real Hestia credentials for all 20 servers and is what the
 * Servers tab depends on; a bug or a bad edit in this small, cosmetic feature
 * must never be able to reach it. (It reads hestia.json once, below, only to
 * confirm the id is real — read-only, same as the Map view itself.)
 */
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/hestia_fleet.php';
require_once __DIR__ . '/../lib/map_meta.php';

$back = '../index.php?view=map';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !infra_check_csrf()) {
    infra_set_flash('err', 'Invalid request (bad CSRF token).');
    header('Location: ' . $back); exit;
}
infra_session_release();

$id = trim((string) ($_POST['id'] ?? ''));

const MAP_LABEL_DIRS = ['up', 'down', 'left', 'right', 'upper-left', 'upper-right', 'lower-left', 'lower-right'];

// Each entry: POST field name => [map_meta key, validator]. Add a new map-only
// field by adding one line here (plus its control in the popup form) — nothing
// else about this file's shape needs to change.
$fields = [
    'dir' => ['label_dir', fn($v) => in_array($v, MAP_LABEL_DIRS, true)],
];

$srv = infra_hestia_server($id); // read-only existence check against hestia.json
if (!$srv) {
    infra_set_flash('err', 'That server is not in the list.');
    header('Location: ' . $back); exit;
}

$toSave = [];
foreach ($fields as $postKey => [$metaKey, $valid]) {
    if (!isset($_POST[$postKey])) continue;
    $v = (string) $_POST[$postKey];
    if (!$valid($v)) {
        infra_set_flash('err', 'Invalid value for "' . $postKey . '".');
        header('Location: ' . $back); exit;
    }
    $toSave[$metaKey] = $v;
}

if (!infra_map_meta_save($id, $toSave)) {
    infra_set_flash('err', 'Could not write map_meta.json.');
    header('Location: ' . $back); exit;
}

infra_set_flash('ok', 'Updated "' . ($srv['label'] ?? $id) . '".');
header('Location: ' . $back . '&open=' . rawurlencode($id));
