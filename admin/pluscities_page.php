<?php
/**
 * admin/pluscities_page.php — Phase 1's minimal manual-drive screen: enough
 * surface to exercise Edit/Lock/Regenerate/Swap/Validate/Approve/Publish/
 * Rollback by hand on one page, per spec Section 6's "manual edit/lock/
 * regenerate/swap" requirement. NOT Section 11's full table-of-pages UI —
 * that's Phase 2. Gated the same way other edit-capable LPC screens are
 * (any authenticated actor except end_client), matching spec Section 10's
 * capability matrix rather than the niche-library screen's super_admin-only
 * gate, since Agency Admin/Editor both need to drive pages day to day.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/lpc_admin_layout.php';

if (!lpc_actor_is_authenticated() || lpc_current_actor_role() === 'end_client') {
    header('Location: login.php');
    exit;
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

$pageId = $_GET['page_id'] ?? '';
$db = pluscities_db();
$st = $db->prepare('SELECT * FROM pages WHERE page_id = ?');
$st->execute([$pageId]);
$page = $st->fetch(PDO::FETCH_ASSOC);

$alert = null;
if (!empty($_GET['msg'])) {
    $raw = $_GET['msg'];
    if (strpos($raw, ':') !== false) {
        [$type, $text] = explode(':', $raw, 2);
        if (in_array($type, ['success', 'error', 'warning'], true)) $alert = ['type' => $type, 'text' => $text];
    }
}

lpc_admin_open('pluscities_page', 'Page — ' . h($pageId));
?>
<style>
.pc-card{background:#fff;border:1px solid var(--pc-line,#e2e8f2);border-radius:14px;padding:18px 20px;margin-bottom:16px;}
.pc-block-row{border:1px solid var(--pc-line,#e2e8f2);border-radius:10px;padding:12px 14px;margin-bottom:10px;}
.pc-block-head{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;}
.pc-check{display:flex;gap:8px;align-items:center;padding:4px 0;font-size:.84rem;}
.pc-check .pass{color:#15803d;font-weight:700;}
.pc-check .fail{color:#b91c1c;font-weight:700;}
textarea.pc-edit{width:100%;min-height:70px;margin-top:8px;padding:8px;border:1px solid #d1d5db;border-radius:6px;font-family:inherit;font-size:.84rem;}
</style>

<?php if ($alert): ?><div class="alert alert-<?= h($alert['type']) ?>"><?= h($alert['text']) ?></div><?php endif; ?>

<?php if (!$page): ?>
    <div class="lpc-empty"><h2>Page not found</h2><p>No page with id <code><?= h($pageId) ?></code>.</p></div>
<?php else:
    $validation = json_decode($page['validation_json'] ?: '[]', true) ?: [];
    $validationCurrent = ($validation['hash_checked_against'] ?? null) === $page['draft_hash'];
    $blocksStmt = $db->prepare('SELECT * FROM blocks WHERE page_id = ? ORDER BY position');
    $blocksStmt->execute([$pageId]);
    $blocks = $blocksStmt->fetchAll(PDO::FETCH_ASSOC);
    $revStmt = $db->prepare('SELECT number, author, created_at FROM revisions WHERE page_id = ? ORDER BY number DESC');
    $revStmt->execute([$pageId]);
    $revisions = $revStmt->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="pc-card">
    <div class="pc-block-head">
        <div>
            <strong>Status:</strong> <?= h($page['status']) ?> &nbsp;
            <strong>Approval:</strong> <?= h($page['approval_stage']) ?> &nbsp;
            <strong>Validation:</strong> <?= $validationCurrent ? h(pluscities_validation_all_pass($validation) ? 'current, all pass' : 'current, failing') : 'stale — re-run' ?>
        </div>
        <form method="post" action="pluscities_page_save.php" style="display:flex;gap:8px;">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="page_id" value="<?= h($pageId) ?>">
            <button type="submit" name="action" value="validate" class="btn-sm-outline">Validate</button>
            <button type="submit" name="action" value="approve" class="btn-sm-outline">Approve</button>
            <button type="submit" name="action" value="publish" class="btn-open">Publish</button>
            <?php if ($page['status'] === 'live'): ?>
                <button type="submit" name="action" value="unpublish" class="btn-sm-danger">Unpublish</button>
            <?php endif; ?>
        </form>
    </div>
    <?php if ($validation['results'] ?? null): ?>
        <div style="margin-top:12px;">
            <?php foreach ($validation['results'] as $r): ?>
                <div class="pc-check">
                    <span class="<?= h($r['result']) ?>"><?= $r['result'] === 'pass' ? '✓' : '✗' ?></span>
                    <strong><?= h($r['check']) ?></strong>
                    <?php if ($r['detail']): ?><span style="color:var(--pc-muted,#52607a);"> — <?= h($r['detail']) ?></span><?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<div class="pc-card">
    <h3 style="margin-top:0;">Blocks</h3>
    <?php foreach ($blocks as $b):
        $content = json_decode($b['content_json'], true) ?: [];
        $factIds = json_decode($b['fact_ids'], true) ?: [];
    ?>
    <div class="pc-block-row">
        <div class="pc-block-head">
            <div>
                <strong><?= h($b['block_type']) ?></strong>
                <span style="font-size:.78rem;color:var(--pc-muted,#52607a);">
                    (<?= h($b['classification']) ?>, <?= (int) $b['locked'] ? 'locked' : 'unlocked' ?><?= $factIds ? ', ' . count($factIds) . ' fact(s)' : '' ?>)
                </span>
            </div>
            <form method="post" action="pluscities_page_save.php" style="display:flex;gap:6px;">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <input type="hidden" name="page_id" value="<?= h($pageId) ?>">
                <input type="hidden" name="block_id" value="<?= (int) $b['id'] ?>">
                <button type="submit" name="action" value="toggle_lock" class="btn-sm-outline"><?= (int) $b['locked'] ? 'Unlock' : 'Lock' ?></button>
                <button type="submit" name="action" value="regenerate" class="btn-sm-outline" <?= (int) $b['locked'] || $b['classification'] === 'client-supplied' ? 'disabled' : '' ?>>Regenerate</button>
            </form>
        </div>
        <?php if (isset($content['body'])): ?>
            <p style="font-size:.84rem;margin:8px 0 0;"><?= h($content['heading'] ?? '') ?> — <?= h(mb_strimwidth($content['body'], 0, 220, '…')) ?></p>
        <?php elseif (isset($content['intro'])): ?>
            <p style="font-size:.84rem;margin:8px 0 0;"><?= h($content['intro']) ?></p>
        <?php elseif (isset($content['reviews'])): ?>
            <p style="font-size:.84rem;margin:8px 0 0;"><?= count($content['reviews']) ?> client-supplied review(s).</p>
        <?php endif; ?>
        <form method="post" action="pluscities_page_save.php">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="page_id" value="<?= h($pageId) ?>">
            <input type="hidden" name="block_id" value="<?= (int) $b['id'] ?>">
            <input type="hidden" name="action" value="edit">
            <textarea class="pc-edit" name="body"><?= h($content['body'] ?? '') ?></textarea>
            <button type="submit" class="btn-sm-outline" style="margin-top:6px;" <?= isset($content['body']) ? '' : 'disabled' ?>>Save Edit (auto-locks)</button>
        </form>
    </div>
    <?php endforeach; ?>
</div>

<?php if ($revisions): ?>
<div class="pc-card">
    <h3 style="margin-top:0;">Revisions</h3>
    <?php foreach ($revisions as $r): ?>
        <div class="pc-row" style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--pc-line,#e2e8f2);">
            <span>#<?= (int) $r['number'] ?> by <?= h($r['author']) ?> at <?= h($r['created_at']) ?></span>
            <form method="post" action="pluscities_page_save.php" onsubmit="return confirm('Roll back to revision <?= (int) $r['number'] ?>? Still needs to be re-approved and re-published.');">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <input type="hidden" name="page_id" value="<?= h($pageId) ?>">
                <input type="hidden" name="revision_number" value="<?= (int) $r['number'] ?>">
                <button type="submit" name="action" value="rollback" class="btn-sm-outline">Roll back to this</button>
            </form>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php endif; ?>
<?php lpc_admin_close(); ?>
