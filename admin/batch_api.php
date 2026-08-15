<?php
/**
 * Batch manager API — create / rename / delete / open a multisite batch.
 * JSON responses. Same session + CSRF pattern as site_api.php.
 *
 * The batch's *contents* (target list, runs, research) are handled by
 * multisite_api.php; this file only manages the batches themselves.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/multisite/batch.php';
require_once __DIR__ . '/../includes/multisite/master_lint.php';

header('Content-Type: application/json');

if (empty($_SESSION['admin_logged_in'])) { http_response_code(403); echo json_encode(['error' => 'Not authenticated.']); exit; }
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
    http_response_code(403); echo json_encode(['error' => 'Invalid security token.']); exit;
}

$action   = $_REQUEST['action'] ?? '';
$masterId = trim((string) ($_REQUEST['master_id'] ?? ''));
$batchId  = trim((string) ($_REQUEST['batch_id']  ?? ''));

/** Shape one batch for the UI: the record plus its live status. */
function batch_row(array $b): array {
    $m = $b['master_id']; $i = $b['id'];
    return [
        'id'         => $i,
        'master_id'  => $m,
        'name'       => $b['name'] ?? $i,
        'created_at' => $b['created_at'] ?? '',
        'updated_at' => $b['updated_at'] ?? '',
        'status'     => ms_batch_status($m, $i),
    ];
}

switch ($action) {

    // Every batch across every master, newest-touched first.
    case 'list':
        echo json_encode(['batches' => array_map('batch_row', ms_all_batches())]);
        break;

    case 'create':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'POST required.']); break; }
        $res = ms_create_batch($masterId, (string) ($_POST['name'] ?? ''));
        if (isset($res['error'])) { echo json_encode($res); break; }
        echo json_encode(['ok' => true, 'id' => $res['id'], 'master_id' => $masterId]);
        break;

    case 'rename':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'POST required.']); break; }
        echo json_encode(ms_rename_batch($masterId, $batchId, (string) ($_POST['name'] ?? '')));
        break;

    // Copy the target list and research into a new batch; the run history stays
    // with the original (see ms_copy_batch).
    case 'copy':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'POST required.']); break; }
        $res = ms_copy_batch($masterId, $batchId, (string) ($_POST['name'] ?? ''));
        if (isset($res['error'])) { echo json_encode($res); break; }
        echo json_encode(['ok' => true, 'id' => $res['id'], 'name' => $res['name'], 'master_id' => $masterId]);
        break;

    case 'delete':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'POST required.']); break; }
        $res = ms_delete_batch($masterId, $batchId);
        // Don't leave a deleted batch open in the session.
        if (!isset($res['error']) && ($_SESSION['active_batch'] ?? '') === $batchId) unset($_SESSION['active_batch']);
        echo json_encode($res);
        break;

    // Open a batch: remember its master (the build needs it) AND the batch itself,
    // then land on the batch page — you no longer have to "become" the master site.
    case 'select':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'POST required.']); break; }
        if (!ms_batch_exists($masterId, $batchId)) { echo json_encode(['error' => 'Batch not found.']); break; }
        $_SESSION['active_site']  = $masterId;
        $_SESSION['active_batch'] = $batchId;
        echo json_encode(['ok' => true, 'redirect' => 'batch.php']);
        break;

    // Close the open batch (used when leaving the batch page for the site admin).
    case 'deselect':
        unset($_SESSION['active_batch']);
        echo json_encode(['ok' => true]);
        break;

    // What would go wrong if this batch were re-pointed at another master? Read-only —
    // the UI shows these, then asks for confirmation before calling set_master.
    case 'swap_master_check':
        $newMaster = trim((string) ($_REQUEST['new_master_id'] ?? ''));
        if (!ms_batch_exists($masterId, $batchId))  { echo json_encode(['error' => 'Batch not found.']); break; }
        if (!ms_valid_master_id($newMaster))        { echo json_encode(['error' => 'Pick a master site.']); break; }
        echo json_encode(['warnings' => ms_swap_master_warnings($masterId, $batchId, $newMaster)]);
        break;

    case 'set_master':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'POST required.']); break; }
        $newMaster = trim((string) ($_POST['new_master_id'] ?? ''));
        $res = ms_set_batch_master($masterId, $batchId, $newMaster);
        if (isset($res['error'])) { echo json_encode($res); break; }
        // The batch physically moved, so re-point the session at its new home.
        $_SESSION['active_site']  = $res['master_id'] ?? $masterId;
        $_SESSION['active_batch'] = $res['id'];
        echo json_encode($res);
        break;

    // Health-check a candidate master from the new-batch box, before a batch exists.
    // Catches the master that would clone 50 identical or un-localized sites.
    case 'lint_master':
        if (!ms_valid_master_id($masterId)) { echo json_encode(['error' => 'Pick a master site.']); break; }
        echo json_encode(ms_lint_master($masterId));
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action.']);
}
