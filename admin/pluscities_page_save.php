<?php
/** admin/pluscities_page_save.php — save handler for admin/pluscities_page.php. */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

if (!lpc_actor_is_authenticated() || lpc_current_actor_role() === 'end_client') {
    header('Location: login.php'); exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: pluscities_page.php'); exit; }

$token = $_POST['csrf_token'] ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
    header('Location: pluscities_page.php?msg=error:Invalid+request+token'); exit;
}

$pageId = trim($_POST['page_id'] ?? '');
$back   = 'pluscities_page.php?page_id=' . urlencode($pageId);
$actor  = lpc_current_actor_role() . ':' . (lpc_current_actor_identity()['name'] ?? 'unknown');
$action = $_POST['action'] ?? '';

// Same authorization choke point as pluscities_page.php — every action here
// carries page_id regardless of which verb it is, so one check up front
// covers block-level actions too.
$pageClientStmt = pluscities_db()->prepare('SELECT client_id FROM pages WHERE page_id = ?');
$pageClientStmt->execute([$pageId]);
$pageClientId = $pageClientStmt->fetchColumn();
if (!$pageClientId || !pluscities_actor_can_access_client($pageClientId)) {
    header('Location: pluscities_pages.php?msg=error:Page+not+found'); exit;
}

if ($action === 'edit') {
    $blockId = (int) ($_POST['block_id'] ?? 0);
    $db = pluscities_db();
    $st = $db->prepare('SELECT content_json FROM blocks WHERE id = ?');
    $st->execute([$blockId]);
    $content = json_decode($st->fetchColumn() ?: '{}', true) ?: [];
    $content['body'] = (string) ($_POST['body'] ?? '');
    pluscities_edit_block($blockId, $content, $actor);
    header('Location: ' . $back . '&msg=success:Block+saved+and+locked'); exit;
}

if ($action === 'edit_seo') {
    // Blank means "fall back to the auto-generated template" — never stored
    // as the literal template text, so a later change to that template
    // (e.g. a copy tweak) still reaches every page that never overrode it.
    pluscities_db()->prepare('UPDATE pages SET seo_title = ?, seo_description = ? WHERE page_id = ?')
        ->execute([trim((string) ($_POST['seo_title'] ?? '')), trim((string) ($_POST['seo_description'] ?? '')), $pageId]);

    // admin/pluscities_sync.php reads these two columns straight off the live
    // `pages` row, not a frozen revision snapshot — so this takes effect on an
    // already-live page immediately, same "settings, not reviewed content"
    // category as hub_content/url_base_path (admin/pluscities_hub_edit_save.php),
    // not a block edit that waits for re-approval/re-publish. Bumping site_rev
    // here (same way that file does) is what actually gets the live WordPress
    // plugin to re-poll and pick it up — without this, the sync endpoint
    // itself would already be correct but the plugin's cached copy never
    // would be.
    $clientFile = PLUSCITIES_CLIENTS_DIR . $pageClientId . '.json';
    $pageClient = json_decode((string) @file_get_contents($clientFile), true);
    if ($pageClient) {
        $pageClient['site_rev'] = (int) ($pageClient['site_rev'] ?? 0) + 1;
        pluscities_atomic_write_json($clientFile, $pageClient);
    }

    pluscities_log_event($actor, 'edit_seo', 'page:' . $pageId, '');
    header('Location: ' . $back . '&msg=success:SEO+fields+saved'); exit;
}

if ($action === 'edit_faq') {
    $blockId = (int) ($_POST['block_id'] ?? 0);
    $db = pluscities_db();
    $st = $db->prepare('SELECT content_json FROM blocks WHERE id = ?');
    $st->execute([$blockId]);
    $content = json_decode($st->fetchColumn() ?: '{}', true) ?: [];
    $questions = $_POST['faq_question'] ?? [];
    $answers   = $_POST['faq_answer'] ?? [];
    $faqs = [];
    foreach ($questions as $i => $q) {
        $faqs[] = ['question' => trim((string) $q), 'answer' => trim((string) ($answers[$i] ?? ''))];
    }
    $content['faqs'] = $faqs;
    pluscities_edit_block($blockId, $content, $actor);
    header('Location: ' . $back . '&msg=success:FAQ+saved+and+locked'); exit;
}

if ($action === 'toggle_lock') {
    $blockId = (int) ($_POST['block_id'] ?? 0);
    $st = pluscities_db()->prepare('SELECT locked FROM blocks WHERE id = ?');
    $st->execute([$blockId]);
    $currentlyLocked = (bool) $st->fetchColumn();
    pluscities_set_block_lock($blockId, !$currentlyLocked, $actor);
    header('Location: ' . $back . '&msg=success:Lock+toggled'); exit;
}

if ($action === 'lock_all' || $action === 'unlock_all') {
    // A convenience wrapper, not new locking architecture — added 2026-11-24.
    // Block-level lock already existed and worked; whole-page lock was
    // missing entirely, so this just loops the same proven per-block
    // function instead of clicking Lock on every block one at a time.
    $lock = $action === 'lock_all';
    $blockIds = pluscities_db()->prepare('SELECT id FROM blocks WHERE page_id = ?');
    $blockIds->execute([$pageId]);
    foreach ($blockIds->fetchAll(PDO::FETCH_COLUMN) as $blockId) {
        pluscities_set_block_lock((int) $blockId, $lock, $actor);
    }
    header('Location: ' . $back . '&msg=success:' . ($lock ? 'All+blocks+locked' : 'All+blocks+unlocked')); exit;
}

if ($action === 'regenerate') {
    $result = pluscities_regenerate_block((int) ($_POST['block_id'] ?? 0), $actor);
    header('Location: ' . $back . '&msg=' . ($result['ok'] ? 'success:Regenerated' : 'error:' . urlencode($result['msg']))); exit;
}

if ($action === 'swap') {
    $result = pluscities_swap_block((int) ($_POST['block_id'] ?? 0), (string) ($_POST['new_block_type'] ?? ''), $actor);
    header('Location: ' . $back . '&msg=' . ($result['ok'] ? 'success:Swapped' : 'error:' . urlencode($result['msg']))); exit;
}

if ($action === 'validate') {
    pluscities_run_validator($pageId);
    header('Location: ' . $back . '&msg=success:Validation+run'); exit;
}

if ($action === 'override') {
    $checkName = (string) ($_POST['check_name'] ?? '');
    $reason    = (string) ($_POST['reason'] ?? '');
    $result    = pluscities_override_validator_check($pageId, $checkName, $reason, lpc_current_actor_role(), $actor);
    header('Location: ' . $back . '&msg=' . ($result['ok'] ? 'success:Override+recorded' : 'error:' . urlencode($result['msg']))); exit;
}

if ($action === 'approve') {
    $result = pluscities_approve_page($pageId, $actor, lpc_current_actor_role());
    header('Location: ' . $back . '&msg=' . ($result['ok'] ? 'success:Approved' : 'error:' . urlencode($result['msg']))); exit;
}

if ($action === 'publish') {
    $result = pluscities_publish_page($pageId, $actor, lpc_current_actor_role());
    header('Location: ' . $back . '&msg=' . ($result['ok'] ? 'success:Published' : 'error:' . urlencode($result['msg']))); exit;
}

if ($action === 'unpublish') {
    pluscities_unpublish_page($pageId, $actor);
    header('Location: ' . $back . '&msg=success:Unpublished'); exit;
}

if ($action === 'rollback') {
    $result = pluscities_rollback_page($pageId, (int) ($_POST['revision_number'] ?? 0), $actor);
    header('Location: ' . $back . '&msg=' . ($result['ok'] ? 'success:Rolled+back+to+draft' : 'error:' . urlencode($result['msg']))); exit;
}

header('Location: ' . $back . '&msg=error:Unknown+action');
