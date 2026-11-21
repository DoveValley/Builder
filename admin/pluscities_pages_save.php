<?php
/**
 * admin/pluscities_pages_save.php — bulk action handler for
 * admin/pluscities_pages.php. Each selected page gets the same lifecycle
 * function (includes/pluscities_lifecycle.php) a single-page action would
 * use — bulk is just "loop and call the same verb," per spec Section 11's
 * "bulk actions use the same checkbox-row pattern throughout."
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

if (!lpc_actor_is_authenticated() || lpc_current_actor_role() === 'end_client') {
    header('Location: login.php'); exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: pluscities_pages.php'); exit; }

$token = $_POST['csrf_token'] ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
    header('Location: pluscities_pages.php?msg=error:Invalid+request+token'); exit;
}

$pageIds = $_POST['page_ids'] ?? [];
$action  = $_POST['action'] ?? '';
$actor   = lpc_current_actor_role() . ':' . (lpc_current_actor_identity()['name'] ?? 'unknown');

if (!$pageIds) {
    header('Location: pluscities_pages.php?msg=error:No+pages+selected'); exit;
}

$db = pluscities_db();
$okCount = 0;
$failMsgs = [];

foreach ($pageIds as $pageId) {
    $pageId = (string) $pageId;
    $st = $db->prepare('SELECT client_id FROM pages WHERE page_id = ?');
    $st->execute([$pageId]);
    $clientId = $st->fetchColumn();
    // Same authorization check as pluscities_page_save.php — agency scope
    // AND, for an Agency Editor, their own assigned-clients restriction, not
    // just agency-level scope. A page outside either silently doesn't
    // participate in the bulk action, same as it wouldn't appear as a row.
    if (!$clientId || !pluscities_actor_can_access_client($clientId)) continue;

    if ($action === 'approve') {
        $r = pluscities_approve_page($pageId, $actor);
        if ($r['ok']) $okCount++; else $failMsgs[] = "$pageId: {$r['msg']}";
    } elseif ($action === 'publish') {
        $r = pluscities_publish_page($pageId, $actor, lpc_current_actor_role());
        if ($r['ok']) $okCount++; else $failMsgs[] = "$pageId: {$r['msg']}";
    } elseif ($action === 'regenerate_unlocked') {
        $blocksStmt = $db->prepare('SELECT id FROM blocks WHERE page_id = ? AND locked = 0');
        $blocksStmt->execute([$pageId]);
        $anyFail = false;
        foreach ($blocksStmt->fetchAll(PDO::FETCH_COLUMN) as $blockId) {
            $r = pluscities_regenerate_block((int) $blockId, $actor);
            if (!$r['ok']) { $anyFail = true; $failMsgs[] = "$pageId block $blockId: {$r['msg']}"; }
        }
        if (!$anyFail) $okCount++;
    }
}

$msg = $okCount . ' page(s) updated.';
if ($failMsgs) $msg .= ' Issues: ' . implode('; ', array_slice($failMsgs, 0, 3));
header('Location: pluscities_pages.php?msg=' . ($failMsgs ? 'warning' : 'success') . ':' . urlencode($msg));
