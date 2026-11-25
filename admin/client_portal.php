<?php
/**
 * admin/client_portal.php — the End Client's one screen: read-only reporting
 * on their own pages, nothing else. Rebuilt 2026-11-24 against the current
 * PlusCities data model (pluscities_clients/*.json + pluscities.db) — the
 * previous version of this file (deleted 2026-11-23 in the LPC retirement)
 * was built against the old LPC client record shape and would not have
 * worked here even if restored as-is.
 *
 * Shows every page regardless of status (draft/approved/live/unpublished),
 * added 2026-11-24 — previously only showed 'live' pages, which meant a
 * Client Viewer had nothing to look at before a page went live, defeating
 * the point of a review step. The actual Approve action lives on
 * client_portal_page.php, not here — this list just needs to surface which
 * pages are waiting on this client (approval_stage = pending_client_review,
 * only possible when client.requires_client_approval is on for this
 * client) so they know to click in.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/lpc_admin_layout.php';

if (!lpc_actor_is_authenticated() || lpc_current_actor_role() !== 'end_client') {
    header('Location: login.php');
    exit;
}

$clientId = lpc_current_actor_client_id();
$client = $clientId ? pluscities_client_load($clientId) : null;
if (!$client) {
    // Stale/broken session — the client this session points at no longer
    // exists. Nothing to show; send back to login rather than fatal-erroring
    // on null fields below.
    header('Location: login.php');
    exit;
}

$db = pluscities_db();
$st = $db->prepare("SELECT * FROM pages WHERE client_id = ? ORDER BY approval_stage = 'pending_client_review' DESC, status = 'live' DESC, city_id, service_key");
$st->execute([$clientId]);
$pages = $st->fetchAll(PDO::FETCH_ASSOC);

$statusLabels = ['draft' => 'Draft', 'approved' => 'Approved — not live yet', 'live' => 'Live', 'unpublished' => 'Unpublished'];
$statusPillClass = ['draft' => 'pc-status-pill-draft', 'approved' => 'pc-status-pill-approved', 'live' => 'pc-status-pill-live', 'unpublished' => 'pc-status-pill-unpublished'];

$businessName = $client['identity']['business_name'] ?? $clientId;

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
<title>Reporting — <?= h($businessName) ?></title>
<link rel="stylesheet" href="../assets/css/style.css">
<?php $_adminCssMtime = css_minify_to(__DIR__ . '/../assets/css/admin.src.css', __DIR__ . '/../assets/css/admin.css'); ?>
<link rel="stylesheet" href="../assets/css/admin.css?v=<?= (int) $_adminCssMtime ?>">
<?php lpc_admin_head_assets(); ?>
</head>
<?php lpc_admin_open('reporting', 'Reporting'); ?>
<style>
.pc-card{background:#fff;border:1px solid var(--pc-line,#e2e8f2);border-radius:14px;padding:18px 20px;margin-bottom:16px;}
.pc-report-table{width:100%;border-collapse:collapse;}
.pc-report-table th{text-align:left;font-size:.74rem;color:var(--pc-muted,#52607a);background:#f9fafb;padding:8px 12px;}
.pc-report-table td{padding:8px 12px;font-size:.85rem;border-top:1px solid var(--pc-line,#e2e8f2);}
.pc-status-pill{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.02em;padding:2px 9px;border-radius:10px;display:inline-block;}
.pc-status-pill-draft{background:#f1f5f9;color:#52607a;}
.pc-status-pill-approved{background:#dbeafe;color:#1e40af;}
.pc-status-pill-live{background:#dcfce7;color:#166534;}
.pc-status-pill-unpublished{background:#fee2e2;color:#991b1b;}
.pc-status-pill-needs-approval{background:#fef3c7;color:#92400e;}
.pc-view-link{font-size:.8rem;font-weight:600;color:var(--pc-accent,#1e40af);text-decoration:none;}
.pc-view-link:hover{text-decoration:underline;}
</style>

<?php if ($alert): ?><div class="alert alert-<?= h($alert['type']) ?>"><?= h($alert['text']) ?></div><?php endif; ?>

<div class="pc-card">
    <h3 style="margin-top:0;"><?= h($businessName) ?></h3>
    <p style="color:var(--pc-muted,#52607a);font-size:.84rem;">
        All your pages, read-only, including ones still being worked on. Reach out to your agency for
        anything you'd like changed.
    </p>
    <table class="pc-report-table">
        <tr><th>City</th><th>Service</th><th>Status</th><th></th></tr>
        <?php if (!$pages): ?>
        <tr><td colspan="4" style="color:var(--pc-muted,#52607a);text-align:center;padding:16px 12px;">No pages yet.</td></tr>
        <?php else: foreach ($pages as $p): ?>
        <tr>
            <td><?= h(pluscities_city_label($p['city_id'])) ?></td>
            <td><?= h(ucwords(str_replace('-', ' ', $p['service_key']))) ?></td>
            <?php $needsApproval = $p['approval_stage'] === 'pending_client_review'; ?>
            <td>
                <?php if ($needsApproval): ?>
                    <span class="pc-status-pill pc-status-pill-needs-approval">Needs Your Approval</span>
                <?php else: ?>
                    <span class="pc-status-pill <?= h($statusPillClass[$p['status']] ?? '') ?>"><?= h($statusLabels[$p['status']] ?? ucfirst($p['status'])) ?></span>
                <?php endif; ?>
            </td>
            <td><a class="pc-view-link" href="client_portal_page.php?page_id=<?= h($p['page_id']) ?>"><?= $needsApproval ? 'Review &amp; Approve' : 'View' ?> &rarr;</a></td>
        </tr>
        <?php endforeach; endif; ?>
    </table>
</div>

<?php lpc_admin_close(); ?>
