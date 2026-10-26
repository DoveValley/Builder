<?php
/**
 * infra/actions/elocal_library_save.php — eLocal file library writes (CSRF, PRG).
 * action = upload | select | delete
 *
 * See lib/research.php's "eLocal file LIBRARY" section header for why this is a
 * thin layer on top of the single held-file slot, not a replacement for it.
 */
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/research.php';

$niche = infra_niche_slug((string) ($_POST['niche'] ?? ''));
$back = '../index.php?view=research' . ($niche !== '' ? '&niche=' . urlencode($niche) : '');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !infra_check_csrf()) {
    infra_set_flash('err', 'Invalid request (bad CSRF token).');
    header('Location: ' . $back); exit;
}
infra_session_release();

if ($niche === '' || !isset(infra_niches()[$niche])) {
    infra_set_flash('err', 'Pick a niche first.');
    header('Location: ' . $back); exit;
}

$action = (string) ($_POST['action'] ?? '');

if ($action === 'upload') {
    $entry = infra_elocal_library_add($niche, $_FILES['elocal_file'] ?? null);
    if ($entry === null) {
        infra_set_flash('err', 'Upload failed — choose a file under 4 MB.');
    } else {
        infra_set_flash('ok', 'Added "' . $entry['original_name'] . '" to the library. Select it to use it for a run.');
    }
    header('Location: ' . $back); exit;
}

if ($action === 'select') {
    $id = (string) ($_POST['id'] ?? '');
    if (infra_elocal_library_select($niche, $id)) {
        infra_set_flash('ok', 'Selected — this file will be used for the next Save/Run.');
    } else {
        infra_set_flash('err', 'Could not select that file — it may have been deleted.');
    }
    header('Location: ' . $back); exit;
}

if ($action === 'delete') {
    $id = (string) ($_POST['id'] ?? '');
    if (infra_elocal_library_delete($niche, $id)) {
        infra_set_flash('ok', 'Removed from the library.');
    } else {
        infra_set_flash('err', 'File not found.');
    }
    header('Location: ' . $back); exit;
}

infra_set_flash('err', 'Unknown action.');
header('Location: ' . $back);
