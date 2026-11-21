<?php
/**
 * includes/pluscities_lifecycle.php — the page/content lifecycle from
 * docs/PLUSCITIES_FEATURE_SPEC.md Section 8: one function per verb.
 *
 * status tracks the highest stage ever reached (draft|approved|live|
 * unpublished) and never regresses on its own. approval_stage is the
 * separate, resettable axis — any block mutation invalidates the approval
 * already granted against the old draft_hash, per Section 8's "editing an
 * already-live page follows the exact same rules as a brand-new page" rule,
 * so it resets to 'none' on every edit/regenerate/swap/rollback, requiring
 * Validate -> Approve -> Publish again regardless of how many times before.
 */

function pluscities_compute_draft_hash(string $page_id): string
{
    $db = pluscities_db();
    $st = $db->prepare('SELECT content_json FROM blocks WHERE page_id = ? ORDER BY position');
    $st->execute([$page_id]);
    return hash('sha256', implode('', $st->fetchAll(PDO::FETCH_COLUMN)));
}

/** Recomputes and stores the page's draft_hash, and resets approval_stage —
 *  called by every mutation below, so the invalidation rule lives in one place. */
function pluscities_touch_page(string $page_id): void
{
    $hash = pluscities_compute_draft_hash($page_id);
    pluscities_db()->prepare('UPDATE pages SET draft_hash = ?, approval_stage = "none" WHERE page_id = ?')
        ->execute([$hash, $page_id]);
}

/** Edit: saves content, auto-locks the block (protects a human edit from a
 *  future Regenerate silently overwriting it), origin=edited. */
function pluscities_edit_block(int $block_id, array $content, string $actor): bool
{
    $db = pluscities_db();
    $st = $db->prepare('SELECT page_id FROM blocks WHERE id = ?');
    $st->execute([$block_id]);
    $pageId = $st->fetchColumn();
    if (!$pageId) return false;

    $db->prepare('UPDATE blocks SET content_json = ?, locked = 1, origin = "edited" WHERE id = ?')
       ->execute([json_encode($content), $block_id]);
    pluscities_touch_page($pageId);
    pluscities_log_event($actor, 'edit_block', 'block:' . $block_id, '');
    return true;
}

/** Regenerate: unlocked blocks only, re-runs the writer against the block's
 *  current fact cards/dataset. */
function pluscities_regenerate_block(int $block_id, string $actor): array
{
    $db = pluscities_db();
    $st = $db->prepare('SELECT b.*, p.city_id, p.service_key, p.client_id FROM blocks b JOIN pages p ON p.page_id = b.page_id WHERE b.id = ?');
    $st->execute([$block_id]);
    $block = $st->fetch(PDO::FETCH_ASSOC);
    if (!$block) return ['ok' => false, 'msg' => 'Block not found.'];
    if ((int) $block['locked'] === 1) return ['ok' => false, 'msg' => 'This block is locked — unlock it first.'];
    if ($block['classification'] === 'client-supplied') return ['ok' => false, 'msg' => 'Nothing to regenerate — this block is the client\'s own content.'];

    $client  = json_decode((string) @file_get_contents(PLUSCITIES_CLIENTS_DIR . $block['client_id'] . '.json'), true) ?: [];
    $context = ['city_label' => $block['city_id'], 'service_label' => $block['service_key'], 'business_name' => $client['identity']['business_name'] ?? ''];

    if ($block['classification'] === 'editable') {
        $facts  = pluscities_fact_card_get_active('city', $block['city_id'], $block['block_type']);
        $result = pluscities_write_editable_block($block['block_type'], $block['block_type'], $facts, $context);
        if (!$result['ok']) return ['ok' => false, 'msg' => $result['msg']];
        $db->prepare('UPDATE blocks SET content_json = ?, fact_ids = ?, origin = "generated" WHERE id = ?')
           ->execute([json_encode($result['content']), json_encode($result['fact_ids']), $block_id]);
    } else { // automatic
        $refs    = json_decode($block['dataset_refs'], true) ?: [];
        $dataset = $refs ? pluscities_dataset_get($refs[0]['city_id'], $refs[0]['provider']) : null;
        $result  = pluscities_write_automatic_block($block['block_type'], $dataset ?? [], $context);
        $db->prepare('UPDATE blocks SET content_json = ?, origin = "data" WHERE id = ?')
           ->execute([json_encode($result['content']), $block_id]);
    }
    pluscities_touch_page($block['page_id']);
    pluscities_log_event($actor, 'regenerate_block', 'block:' . $block_id, '');
    return ['ok' => true, 'msg' => ''];
}

/** Swap: unlocked blocks only, changes block_type within the same pool.
 *  Phase 1 only checks the pool matches (the full pool-membership list is
 *  Phase 2 scope); regenerates content for the new type immediately so the
 *  block is never left showing stale content for a type it no longer is. */
function pluscities_swap_block(int $block_id, string $new_block_type, string $actor): array
{
    $db = pluscities_db();
    $st = $db->prepare('SELECT * FROM blocks WHERE id = ?');
    $st->execute([$block_id]);
    $block = $st->fetch(PDO::FETCH_ASSOC);
    if (!$block) return ['ok' => false, 'msg' => 'Block not found.'];
    if ((int) $block['locked'] === 1) return ['ok' => false, 'msg' => 'This block is locked — unlock it first.'];

    $db->prepare('UPDATE blocks SET block_type = ? WHERE id = ?')->execute([$new_block_type, $block_id]);
    pluscities_log_event($actor, 'swap_block', 'block:' . $block_id, 'to ' . $new_block_type);
    return pluscities_regenerate_block($block_id, $actor);
}

/** Lock / unlock — independent of edit, since the UI exposes this as its own
 *  action per spec Section 6's action matrix. */
function pluscities_set_block_lock(int $block_id, bool $locked, string $actor): void
{
    pluscities_db()->prepare('UPDATE blocks SET locked = ? WHERE id = ?')->execute([$locked ? 1 : 0, $block_id]);
    pluscities_log_event($actor, $locked ? 'lock_block' : 'unlock_block', 'block:' . $block_id, '');
}

/**
 * Approve: requires validation current (checked against the page's current
 * draft_hash) and all checks passing. Phase 1 has no agency/approval-gate
 * record yet, so it always takes the no-gate path (approval_stage -> approved
 * directly) — the pending_platform_review/pending_agency_review sequence is
 * Phase 2 scope once a real agency record exists to read the gate from.
 */
function pluscities_approve_page(string $page_id, string $actor): array
{
    $db = pluscities_db();
    $st = $db->prepare('SELECT * FROM pages WHERE page_id = ?');
    $st->execute([$page_id]);
    $page = $st->fetch(PDO::FETCH_ASSOC);
    if (!$page) return ['ok' => false, 'msg' => 'Page not found.'];

    $validation = json_decode($page['validation_json'] ?: '[]', true) ?: [];
    if (($validation['hash_checked_against'] ?? null) !== $page['draft_hash']) {
        return ['ok' => false, 'msg' => 'Validation is stale — re-run it before approving.'];
    }
    if (!pluscities_validation_all_pass($validation)) {
        return ['ok' => false, 'msg' => 'One or more checks are still failing.'];
    }

    $db->prepare('UPDATE pages SET status = CASE WHEN status = "draft" THEN "approved" ELSE status END, approval_stage = "approved" WHERE page_id = ?')
       ->execute([$page_id]);
    pluscities_log_event($actor, 'approve_page', 'page:' . $page_id, '');
    return ['ok' => true, 'msg' => ''];
}

/**
 * Publish: only from an approved page. Snapshots the current blocks into a
 * new immutable revision, updates live_revision_id, bumps the client's
 * site_rev (for plugin sync), status -> live.
 */
function pluscities_publish_page(string $page_id, string $actor): array
{
    $db = pluscities_db();
    $st = $db->prepare('SELECT * FROM pages WHERE page_id = ?');
    $st->execute([$page_id]);
    $page = $st->fetch(PDO::FETCH_ASSOC);
    if (!$page) return ['ok' => false, 'msg' => 'Page not found.'];
    if ($page['approval_stage'] !== 'approved') return ['ok' => false, 'msg' => 'This page hasn\'t been approved yet.'];

    $blocksStmt = $db->prepare('SELECT * FROM blocks WHERE page_id = ? ORDER BY position');
    $blocksStmt->execute([$page_id]);
    $blocks = $blocksStmt->fetchAll(PDO::FETCH_ASSOC);

    $numStmt = $db->prepare('SELECT COALESCE(MAX(number), 0) FROM revisions WHERE page_id = ?');
    $numStmt->execute([$page_id]);
    $number = (int) $numStmt->fetchColumn() + 1;

    $snapshot = ['blocks' => $blocks, 'slug' => $page['slug']];
    $db->prepare('INSERT INTO revisions (page_id, number, snapshot_json, hash, author, created_at) VALUES (?,?,?,?,?,?)')
       ->execute([$page_id, $number, json_encode($snapshot), $page['draft_hash'], $actor, gmdate('c')]);

    $db->prepare('UPDATE pages SET live_revision_id = ?, status = "live" WHERE page_id = ?')
       ->execute([$number, $page_id]);

    // Bump the client's site_rev — the plugin's manifest check compares against this.
    $clientFile = PLUSCITIES_CLIENTS_DIR . $page['client_id'] . '.json';
    $client = json_decode((string) @file_get_contents($clientFile), true);
    if ($client) {
        $client['site_rev'] = (int) ($client['site_rev'] ?? 0) + 1;
        pluscities_atomic_write_json($clientFile, $client);
    }

    pluscities_log_event($actor, 'publish_page', 'page:' . $page_id, 'revision ' . $number);
    return ['ok' => true, 'msg' => '', 'revision' => $number];
}

/**
 * Roll back: copies an old revision's blocks into the draft. Never an instant
 * revert — the restored draft still has to go through Validate -> Approve ->
 * Publish again, same as any other edit, so there's no path to live that
 * skips validation, not even through history.
 */
function pluscities_rollback_page(string $page_id, int $revision_number, string $actor): array
{
    $db = pluscities_db();
    $st = $db->prepare('SELECT snapshot_json FROM revisions WHERE page_id = ? AND number = ?');
    $st->execute([$page_id, $revision_number]);
    $snapshotJson = $st->fetchColumn();
    if (!$snapshotJson) return ['ok' => false, 'msg' => 'Revision not found.'];

    $snapshot = json_decode($snapshotJson, true);
    $db->prepare('DELETE FROM blocks WHERE page_id = ?')->execute([$page_id]);
    $ins = $db->prepare('INSERT INTO blocks (page_id, block_type, position, pool, classification, content_json, fact_ids, dataset_refs, locked, origin) VALUES (?,?,?,?,?,?,?,?,?,?)');
    foreach ($snapshot['blocks'] as $b) {
        $ins->execute([$page_id, $b['block_type'], $b['position'], $b['pool'], $b['classification'],
            $b['content_json'], $b['fact_ids'], $b['dataset_refs'], $b['locked'], 'edited']);
    }
    pluscities_touch_page($page_id);
    pluscities_log_event($actor, 'rollback_page', 'page:' . $page_id, 'to revision ' . $revision_number);
    return ['ok' => true, 'msg' => ''];
}

/** Unpublish: status -> unpublished, site_rev bumped so the plugin's next
 *  sync stops serving it. Nothing is deleted. */
function pluscities_unpublish_page(string $page_id, string $actor): bool
{
    $db = pluscities_db();
    $st = $db->prepare('SELECT client_id FROM pages WHERE page_id = ?');
    $st->execute([$page_id]);
    $clientId = $st->fetchColumn();
    if (!$clientId) return false;

    $db->prepare('UPDATE pages SET status = "unpublished" WHERE page_id = ?')->execute([$page_id]);
    $clientFile = PLUSCITIES_CLIENTS_DIR . $clientId . '.json';
    $client = json_decode((string) @file_get_contents($clientFile), true);
    if ($client) {
        $client['site_rev'] = (int) ($client['site_rev'] ?? 0) + 1;
        pluscities_atomic_write_json($clientFile, $client);
    }
    pluscities_log_event($actor, 'unpublish_page', 'page:' . $page_id, '');
    return true;
}
