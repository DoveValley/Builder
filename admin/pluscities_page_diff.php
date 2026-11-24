<?php
/**
 * admin/pluscities_page_diff.php — read-only "what changed" view for a past
 * revision, added 2026-11-24 alongside the existing rollback button. Rollback
 * (pluscities_rollback_page(), includes/pluscities_lifecycle.php) already
 * worked and was already wired to a confirm dialog; this screen exists so
 * that confirm dialog isn't a blind guess — compares the old revision's
 * block snapshot against the page's current blocks, position by position,
 * same shape both places (see pluscities_publish_page()'s snapshot vs the
 * live blocks table), so no per-block-type rendering logic is needed: two
 * content_json strings are either identical or they aren't.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/lpc_admin_layout.php';

if (!lpc_actor_is_authenticated() || lpc_current_actor_role() === 'end_client') {
    header('Location: login.php');
    exit;
}

$pageId = $_GET['page_id'] ?? '';
$revisionNumber = (int) ($_GET['revision_number'] ?? 0);
$db = pluscities_db();

$st = $db->prepare('SELECT * FROM pages WHERE page_id = ?');
$st->execute([$pageId]);
$page = $st->fetch(PDO::FETCH_ASSOC);
if ($page && !pluscities_actor_can_access_client($page['client_id'])) {
    $page = null;
}

$revStmt = $db->prepare('SELECT snapshot_json, author, created_at FROM revisions WHERE page_id = ? AND number = ?');
$revStmt->execute([$pageId, $revisionNumber]);
$revision = $revStmt->fetch(PDO::FETCH_ASSOC);

$rows = [];
if ($page && $revision) {
    $oldBlocks = json_decode($revision['snapshot_json'], true)['blocks'] ?? [];
    $curStmt = $db->prepare('SELECT * FROM blocks WHERE page_id = ? ORDER BY position');
    $curStmt->execute([$pageId]);
    $curBlocks = $curStmt->fetchAll(PDO::FETCH_ASSOC);

    $count = max(count($oldBlocks), count($curBlocks));
    for ($i = 0; $i < $count; $i++) {
        $old = $oldBlocks[$i] ?? null;
        $cur = $curBlocks[$i] ?? null;
        if ($old && $cur) {
            $same = $old['block_type'] === $cur['block_type'] && $old['content_json'] === $cur['content_json'];
            $rows[] = ['state' => $same ? 'unchanged' : 'changed', 'position' => $i, 'old' => $old, 'cur' => $cur];
        } elseif ($old && !$cur) {
            $rows[] = ['state' => 'removed', 'position' => $i, 'old' => $old, 'cur' => null];
        } else {
            $rows[] = ['state' => 'added', 'position' => $i, 'old' => null, 'cur' => $cur];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Compare Revision — <?= h(SITE_TITLE) ?></title>
<link rel="stylesheet" href="../assets/css/style.css">
<?php $_adminCssMtime = css_minify_to(__DIR__ . '/../assets/css/admin.src.css', __DIR__ . '/../assets/css/admin.css'); ?>
<link rel="stylesheet" href="../assets/css/admin.css?v=<?= (int) $_adminCssMtime ?>">
<?php lpc_admin_head_assets(); ?>
</head>
<?php
lpc_admin_open('pluscities_pages', 'Compare Revision — ' . h($pageId));
?>
<style>
.pc-card{background:#fff;border:1px solid var(--pc-line,#e2e8f2);border-radius:14px;padding:18px 20px;margin-bottom:12px;}
.pc-diff-unchanged{color:var(--pc-muted,#52607a);font-size:.84rem;}
.pc-diff-label{display:inline-block;font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.02em;padding:2px 8px;border-radius:10px;margin-bottom:8px;}
.pc-diff-label.changed{background:#fef3c7;color:#92400e;}
.pc-diff-label.added{background:#dcfce7;color:#166534;}
.pc-diff-label.removed{background:#fee2e2;color:#991b1b;}
.pc-diff-cols{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
.pc-diff-cols h4{margin:0 0 6px;font-size:.76rem;text-transform:uppercase;letter-spacing:.02em;color:var(--pc-muted,#52607a);}
.pc-diff-cols pre{background:var(--pc-mist,#f4f7fb);border:1px solid var(--pc-line,#e2e8f2);border-radius:8px;padding:10px;font-size:.78rem;white-space:pre-wrap;word-break:break-word;max-height:260px;overflow:auto;}
</style>

<?php if (!$page): ?>
    <div class="lpc-empty"><h2>Page not found</h2></div>
<?php elseif (!$revision): ?>
    <div class="lpc-empty"><h2>Revision not found</h2></div>
<?php else: ?>
    <p><a href="pluscities_page.php?page_id=<?= h($pageId) ?>">&larr; Back to page</a></p>
    <div class="pc-card">
        <strong>Comparing revision #<?= $revisionNumber ?></strong> (by <?= h($revision['author']) ?> at <?= h($revision['created_at']) ?>) to the page's current content.
    </div>
    <?php foreach ($rows as $r): ?>
        <div class="pc-card">
            <?php if ($r['state'] === 'unchanged'): ?>
                <div class="pc-diff-unchanged">Block #<?= $r['position'] + 1 ?> (<?= h($r['cur']['block_type']) ?>) — unchanged</div>
            <?php elseif ($r['state'] === 'changed'): ?>
                <span class="pc-diff-label changed">Changed</span>
                <div class="pc-diff-cols">
                    <div><h4>Revision #<?= $revisionNumber ?> (<?= h($r['old']['block_type']) ?>)</h4><pre><?= h(json_encode(json_decode($r['old']['content_json'], true), JSON_PRETTY_PRINT)) ?></pre></div>
                    <div><h4>Current (<?= h($r['cur']['block_type']) ?>)</h4><pre><?= h(json_encode(json_decode($r['cur']['content_json'], true), JSON_PRETTY_PRINT)) ?></pre></div>
                </div>
            <?php elseif ($r['state'] === 'added'): ?>
                <span class="pc-diff-label added">Added since revision #<?= $revisionNumber ?></span>
                <div><h4 style="margin:0 0 6px;font-size:.76rem;text-transform:uppercase;letter-spacing:.02em;color:var(--pc-muted,#52607a);">Current (<?= h($r['cur']['block_type']) ?>)</h4><pre><?= h(json_encode(json_decode($r['cur']['content_json'], true), JSON_PRETTY_PRINT)) ?></pre></div>
            <?php else: ?>
                <span class="pc-diff-label removed">Removed since revision #<?= $revisionNumber ?></span>
                <div><h4 style="margin:0 0 6px;font-size:.76rem;text-transform:uppercase;letter-spacing:.02em;color:var(--pc-muted,#52607a);">Revision #<?= $revisionNumber ?> (<?= h($r['old']['block_type']) ?>)</h4><pre><?= h(json_encode(json_decode($r['old']['content_json'], true), JSON_PRETTY_PRINT)) ?></pre></div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
<?php endif; ?>
<?php lpc_admin_close(); ?>
