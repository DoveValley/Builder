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
 *
 * Swap UI added 2026-11-22 — the doc comment above already promised it since
 * this file was first written, but no control for it existed anywhere until
 * now (pluscities_swap_block() itself also had zero pool-membership
 * validation before this same change — see includes/pluscities_lifecycle.php).
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

// Authorization: a page belonging to a client outside this actor's scope
// (wrong agency, or an Agency Editor not assigned to it) doesn't exist as
// far as this screen is concerned — same "zero is an honest answer" rule as
// the table listing, not a 403 that confirms the page_id was valid.
if ($page && !pluscities_actor_can_access_client($page['client_id'])) {
    $page = null;
}

// Resolved once here, not per-block — every block's Swap dropdown needs its
// own pool's real options, and $page carries the one client_id every block
// on this page shares.
$pcSwapPools = ['city' => [], 'service' => []];
if ($page) {
    $pcClient = json_decode((string) @file_get_contents(PLUSCITIES_CLIENTS_DIR . $page['client_id'] . '.json'), true) ?: [];
    $pcNiche  = pluscities_niche_resolved($pcClient['niche_id'] ?? '') ?: [];
    // Both $pcNiche['city_blocks'] and $pcNiche['service_blocks'] already
    // exclude their family's always-on blocks — swapping a block INTO one of
    // those would create a duplicate block_type on the page, since
    // pluscities_create_page() already always-includes them.
    $pcSwapPools = ['city' => $pcNiche['city_blocks'] ?? [], 'service' => $pcNiche['service_blocks'] ?? []];
    $pcCatalog = [];
    foreach (platform_info_city_page_blocks_data() as $pcCatSection) {
        foreach ($pcCatSection['rows'] as $pcCatRow) {
            if (!empty($pcCatRow['slug'])) $pcCatalog[$pcCatRow['slug']] = $pcCatRow;
        }
    }
}

$alert = null;
if (!empty($_GET['msg'])) {
    $raw = $_GET['msg'];
    if (strpos($raw, ':') !== false) {
        [$type, $text] = explode(':', $raw, 2);
        if (in_array($type, ['success', 'error', 'warning'], true)) $alert = ['type' => $type, 'text' => $text];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Page — <?= h($pcClient['identity']['business_name'] ?? 'PlusCities') ?></title>
<link rel="stylesheet" href="../assets/css/style.css">
<?php $_adminCssMtime = css_minify_to(__DIR__ . '/../assets/css/admin.src.css', __DIR__ . '/../assets/css/admin.css'); ?>
<link rel="stylesheet" href="../assets/css/admin.css?v=<?= (int) $_adminCssMtime ?>">
<?php lpc_admin_head_assets(); ?>
</head>
<?php
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

    // Same formula admin/pluscities_sync.php falls back to when no override
    // is set — shown as placeholder text so the editor sees exactly what a
    // visitor/Google gets today, not a blank field with no context.
    $pcServiceLabel = $page['service_key'];
    foreach (($pcNiche['services'] ?? []) as $svc) {
        if ($svc['slug'] === $page['service_key']) { $pcServiceLabel = $svc['label']; break; }
    }
    $pcCityLabel = pluscities_city_label($page['city_id']);
    $pcBusinessName = $pcClient['identity']['business_name'] ?? '';
    $pcDefaultTitle = $pcServiceLabel . ' in ' . $pcCityLabel . ' | ' . $pcBusinessName;
    $pcDefaultDesc  = 'Professional ' . $pcServiceLabel . ' in ' . $pcCityLabel . ' from ' . $pcBusinessName . '.';
?>
<div class="pc-card">
    <h3 style="margin-top:0;">URL Slug</h3>
    <p style="font-size:.78rem;color:var(--pc-muted,#52607a);margin:0 0 8px;">
        <?= $page['status'] === 'live'
            ? 'This page is live — changing this changes its real URL immediately, with no redirect from the old one.'
            : 'Still a draft, so this is safe to change freely — nothing is indexed yet.' ?>
    </p>
    <form method="post" action="pluscities_page_save.php" id="pc-slug-form" style="display:flex;gap:8px;align-items:center;">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
        <input type="hidden" name="page_id" value="<?= h($pageId) ?>">
        <input type="hidden" name="action" value="edit_slug">
        <input type="hidden" name="confirm_live_slug_change" id="pc-slug-confirm" value="0">
        <input type="text" name="slug" id="pc-slug-input" value="<?= h($page['slug']) ?>" data-original="<?= h($page['slug']) ?>" data-live="<?= $page['status'] === 'live' ? '1' : '0' ?>" style="flex:1;padding:7px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:.84rem;font-family:monospace;">
        <button type="submit" class="btn-sm-outline">Save Slug</button>
    </form>
    <script>
    document.getElementById('pc-slug-form').addEventListener('submit', function (e) {
        var input = document.getElementById('pc-slug-input');
        if (input.value === input.dataset.original || input.dataset.live !== '1') return;
        if (!confirm('This page is already live at ' + input.dataset.original + ' — changing this breaks that exact URL, with no redirect. Change it anyway?')) {
            e.preventDefault();
            return;
        }
        document.getElementById('pc-slug-confirm').value = '1';
    });
    </script>
</div>

<div class="pc-card">
    <h3 style="margin-top:0;">SEO Title &amp; Meta Description</h3>
    <p style="font-size:.78rem;color:var(--pc-muted,#52607a);margin:0 0 8px;">
        Blank uses the auto-generated default shown as placeholder text below — nothing is lost by
        leaving either field empty.
    </p>
    <form method="post" action="pluscities_page_save.php" class="pc-form-row" style="display:flex;flex-direction:column;gap:8px;">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
        <input type="hidden" name="page_id" value="<?= h($pageId) ?>">
        <input type="hidden" name="action" value="edit_seo">
        <div><label style="font-size:.8rem;font-weight:600;color:#374151;display:block;margin-bottom:4px;">Page Title</label>
            <input type="text" name="seo_title" value="<?= h($page['seo_title'] ?? '') ?>" placeholder="<?= h($pcDefaultTitle) ?>" style="width:100%;padding:7px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:.84rem;"></div>
        <div><label style="font-size:.8rem;font-weight:600;color:#374151;display:block;margin-bottom:4px;">Meta Description</label>
            <textarea name="seo_description" placeholder="<?= h($pcDefaultDesc) ?>" style="width:100%;min-height:50px;padding:7px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:.84rem;font-family:inherit;"><?= h($page['seo_description'] ?? '') ?></textarea></div>
        <div><button type="submit" class="btn-sm-outline">Save SEO Fields</button></div>
    </form>
</div>

<div class="pc-card">
    <div class="pc-block-head">
        <div>
            <strong>Status:</strong> <?= h($page['status']) ?> &nbsp;
            <strong>Approval:</strong> <?= h(['none' => 'none', 'pending_client_review' => 'waiting on client', 'approved' => 'approved'][$page['approval_stage']] ?? $page['approval_stage']) ?> &nbsp;
            <strong>Validation:</strong> <?= $validationCurrent ? h(pluscities_validation_all_pass($validation) ? 'current, all pass' : 'current, failing') : 'stale — re-run' ?>
        </div>
        <form method="post" action="pluscities_page_save.php" style="display:flex;gap:8px;">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="page_id" value="<?= h($pageId) ?>">
            <button type="submit" name="action" value="validate" class="btn-sm-outline">Validate</button>
            <button type="submit" name="action" value="approve" class="btn-sm-outline">Approve</button>
            <button type="submit" name="action" value="publish" class="btn-open">Publish</button>
            <?php if ($page['status'] === 'live'): ?>
                <button type="submit" name="action" value="unpublish" class="btn-sm-danger" onclick="return confirm('Unpublish this page? It will stop showing on the client\'s live site immediately. You can republish it later.');">Unpublish</button>
            <?php endif; ?>
            <button type="submit" name="action" value="lock_all" class="btn-sm-outline" onclick="return confirm('Lock every block on this page? Locked blocks are skipped by Regenerate and future batch rewrites.');">Lock All</button>
            <button type="submit" name="action" value="unlock_all" class="btn-sm-outline" onclick="return confirm('Unlock every block on this page?');">Unlock All</button>
        </form>
    </div>
    <?php if ($validation['results'] ?? null): ?>
        <div style="margin-top:12px;">
            <?php foreach ($validation['results'] as $r): ?>
                <div class="pc-check">
                    <span class="<?= !empty($r['overridden']) ? 'pass' : h($r['result']) ?>">
                        <?= $r['result'] === 'pass' || !empty($r['overridden']) ? '✓' : '✗' ?>
                    </span>
                    <strong><?= h($r['check']) ?></strong>
                    <?php if ($r['detail']): ?><span style="color:var(--pc-muted,#52607a);"> — <?= h($r['detail']) ?></span><?php endif; ?>
                    <?php if (!empty($r['overridden'])): ?>
                        <span style="color:#92400e;"> (overridden by <?= h($r['override_by']) ?>: <?= h($r['override_reason']) ?>)</span>
                    <?php elseif ($r['result'] === 'fail' && ($r['overridable'] ?? true) && in_array(lpc_current_actor_role(), ['agency_admin', 'super_admin'], true)): ?>
                        <form method="post" action="pluscities_page_save.php" style="display:inline-flex;gap:6px;margin-left:8px;" onsubmit="return this.reason.value.trim() !== '';">
                            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                            <input type="hidden" name="page_id" value="<?= h($pageId) ?>">
                            <input type="hidden" name="action" value="override">
                            <input type="hidden" name="check_name" value="<?= h($r['check']) ?>">
                            <input type="text" name="reason" placeholder="Reason (required)" style="font-size:.78rem;padding:3px 6px;width:220px;">
                            <button type="submit" class="btn-sm-outline">Override</button>
                        </form>
                    <?php elseif ($r['result'] === 'fail' && !($r['overridable'] ?? true)): ?>
                        <span style="color:var(--pc-muted,#52607a);font-size:.78rem;"> (cannot be overridden — fix required)</span>
                    <?php endif; ?>
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
            <form method="post" action="pluscities_page_save.php" style="display:flex;gap:6px;align-items:center;">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <input type="hidden" name="page_id" value="<?= h($pageId) ?>">
                <input type="hidden" name="block_id" value="<?= (int) $b['id'] ?>">
                <button type="submit" name="action" value="toggle_lock" class="btn-sm-outline"><?= (int) $b['locked'] ? 'Unlock' : 'Lock' ?></button>
                <button type="submit" name="action" value="regenerate" class="btn-sm-outline" <?= (int) $b['locked'] || $b['classification'] === 'client-supplied' ? 'disabled' : '' ?>>Regenerate</button>
                <?php
                // Options come from the block's OWN pool only (city-pool
                // blocks can only swap to another city-pool type, same for
                // service) — pluscities_swap_block() enforces this
                // server-side too, this just keeps the dropdown honest
                // rather than offering choices it would reject anyway.
                // Spine blocks ($b['pool'] === 'spine') have no entry in
                // $pcSwapPools at all — always present, never swapped, per
                // the matrix's own design — so no control renders for them.
                $pcSwapOptions = $pcSwapPools[$b['pool']] ?? [];
                ?>
                <?php if ($pcSwapOptions): ?>
                    <select name="new_block_type" style="font-size:.78rem;padding:4px 6px;" <?= (int) $b['locked'] || $b['classification'] === 'client-supplied' ? 'disabled' : '' ?>>
                        <?php foreach ($pcSwapOptions as $pcOptSlug): ?>
                            <option value="<?= h($pcOptSlug) ?>" <?= $pcOptSlug === $b['block_type'] ? 'selected' : '' ?>><?= h($pcCatalog[$pcOptSlug]['block'] ?? $pcOptSlug) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" name="action" value="swap" class="btn-sm-outline" <?= (int) $b['locked'] || $b['classification'] === 'client-supplied' ? 'disabled' : '' ?>>Swap</button>
                <?php endif; ?>
            </form>
        </div>
        <?php if (isset($content['body'])): ?>
            <p style="font-size:.84rem;margin:8px 0 0;"><?= h($content['heading'] ?? '') ?> — <?= h(mb_strimwidth($content['body'], 0, 220, '…')) ?></p>
        <?php elseif (isset($content['intro'])): ?>
            <p style="font-size:.84rem;margin:8px 0 0;"><?= h($content['intro']) ?></p>
        <?php elseif (isset($content['reviews'])): ?>
            <p style="font-size:.84rem;margin:8px 0 0;"><?= count($content['reviews']) ?> client-supplied review(s).</p>
        <?php elseif ($b['block_type'] === 'niche_data_table' && !empty($content['rows'])): ?>
            <!-- Shared niche-wide content (same table on every page in this
                 niche, every service, every city) — editable only one level
                 up, in the Niche Library, never per-page. Previously this
                 block showed nothing at all here: no preview, no indication
                 of what it even contains — found during the panel-coverage
                 walkthrough, 2026-11-26. -->
            <p style="font-size:.78rem;color:var(--pc-muted,#52607a);margin:8px 0 4px;">
                Shared reference table for every page in this niche — not editable per-page.
                <a href="pluscities_niches.php?edit_niche=<?= h($pcClient['niche_id'] ?? '') ?>">Edit in the Niche Library →</a>
            </p>
            <table style="width:100%;border-collapse:collapse;font-size:.78rem;">
                <?php if (!empty($content['title'])): ?><caption style="text-align:left;font-weight:600;margin-bottom:4px;"><?= h($content['title']) ?></caption><?php endif; ?>
                <thead><tr><?php foreach ($content['columns'] ?? [] as $col): ?><th style="text-align:left;padding:3px 8px 3px 0;border-bottom:1px solid var(--pc-line,#e2e8f2);"><?= h($col) ?></th><?php endforeach; ?></tr></thead>
                <tbody>
                <?php foreach (array_slice($content['rows'], 0, 4) as $row): ?>
                    <tr><?php foreach ($row as $cell): ?><td style="padding:3px 8px 3px 0;color:var(--pc-muted,#52607a);"><?= h($cell) ?></td><?php endforeach; ?></tr>
                <?php endforeach; ?>
                <?php if (count($content['rows']) > 4): ?><tr><td colspan="<?= count($content['columns'] ?? []) ?>" style="padding:3px 0;color:var(--pc-muted,#52607a);">&hellip; and <?= count($content['rows']) - 4 ?> more row(s)</td></tr><?php endif; ?>
                </tbody>
            </table>
        <?php elseif ($b['block_type'] === 'service_area'): ?>
            <p style="font-size:.78rem;color:var(--pc-muted,#52607a);margin:8px 0 0;">
                Auto-generated from this client/niche's own data (drive time, hub address, related
                service links) — nothing here to manually write or edit.
            </p>
        <?php endif; ?>
        <?php if (isset($content['faqs'])): ?>
        <form method="post" action="pluscities_page_save.php">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="page_id" value="<?= h($pageId) ?>">
            <input type="hidden" name="block_id" value="<?= (int) $b['id'] ?>">
            <input type="hidden" name="action" value="edit_faq">
            <?php foreach ($content['faqs'] as $faq): ?>
                <div style="margin:8px 0;padding:8px;border:1px solid var(--pc-line,#e2e8f2);border-radius:6px;">
                    <input type="text" name="faq_question[]" value="<?= h($faq['question'] ?? '') ?>" placeholder="Question" style="width:100%;margin-bottom:6px;font-size:.84rem;padding:4px 6px;">
                    <textarea name="faq_answer[]" class="pc-edit" placeholder="Answer"><?= h($faq['answer'] ?? '') ?></textarea>
                </div>
            <?php endforeach; ?>
            <button type="submit" class="btn-sm-outline" style="margin-top:6px;">Save FAQ Edits (auto-locks)</button>
        </form>
        <?php elseif (isset($content['body'])): ?>
        <form method="post" action="pluscities_page_save.php">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="page_id" value="<?= h($pageId) ?>">
            <input type="hidden" name="block_id" value="<?= (int) $b['id'] ?>">
            <input type="hidden" name="action" value="edit">
            <textarea class="pc-edit" name="body"><?= h($content['body'] ?? '') ?></textarea>
            <button type="submit" class="btn-sm-outline" style="margin-top:6px;">Save Edit (auto-locks)</button>
        </form>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>

<?php if ($revisions): ?>
<div class="pc-card">
    <h3 style="margin-top:0;">Revisions</h3>
    <?php foreach ($revisions as $r): ?>
        <div class="pc-row" style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--pc-line,#e2e8f2);">
            <span>#<?= (int) $r['number'] ?> by <?= h($r['author']) ?> at <?= h($r['created_at']) ?></span>
            <div style="display:flex;gap:8px;">
                <a class="btn-sm-outline" href="pluscities_page_diff.php?page_id=<?= h($pageId) ?>&revision_number=<?= (int) $r['number'] ?>">Compare to current</a>
                <form method="post" action="pluscities_page_save.php" onsubmit="return confirm('Roll back to revision <?= (int) $r['number'] ?>? Still needs to be re-approved and re-published.');">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                    <input type="hidden" name="page_id" value="<?= h($pageId) ?>">
                    <input type="hidden" name="revision_number" value="<?= (int) $r['number'] ?>">
                    <button type="submit" name="action" value="rollback" class="btn-sm-outline">Roll back to this</button>
                </form>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php endif; ?>
<?php lpc_admin_close(); ?>
