<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/lpc_admin_layout.php';

if (!lpc_actor_is_authenticated()) {
    header('Location: login.php');
    exit;
}
// Approving/rejecting content is a Platform Super Admin or Agency Admin
// action — an End Client never reviews their own pages.
if (lpc_current_actor_role() === 'end_client') {
    header('Location: client_portal.php');
    exit;
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

$alert = null;
if (!empty($_GET['msg'])) {
    $raw = $_GET['msg'];
    if (strpos($raw, ':') !== false) {
        [$type, $text] = explode(':', $raw, 2);
        if (in_array($type, ['success', 'error', 'warning'], true)) {
            $alert = ['type' => $type, 'text' => $text];
        }
    }
}

$isSuperAdmin = lpc_current_actor_role() === 'super_admin';
// Scott sees every agency's queue waiting on HIS review; an agency sees
// only its own queue waiting on ITS review — same scoping split as
// clients.php, just a different stage per role rather than a different
// client list shape.
$stage = $isSuperAdmin ? 'pending_platform_review' : 'pending_agency_review';
$rows  = lpc_list_pages_by_approval_stage($stage, lpc_list_clients_for_current_actor());
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Approvals — <?= h(SITE_TITLE) ?></title>
<link rel="stylesheet" href="../assets/css/style.css">
<?php $_adminCssMtime = css_minify_to(__DIR__ . '/../assets/css/admin.src.css', __DIR__ . '/../assets/css/admin.css'); ?>
<link rel="stylesheet" href="../assets/css/admin.css?v=<?= (int) $_adminCssMtime ?>">
<?php lpc_admin_head_assets(); ?>
<style>
.lpc-wrap { max-width: 900px; }
.lpc-row  { background:#fff; border:1px solid #e5e7eb; border-radius:8px; padding:12px 16px; margin-bottom:8px; display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; }
.lpc-row .meta { font-size:.8rem; color:#6b7280; }
</style>
</head>
<?php lpc_admin_open('approvals', 'Approvals'); ?>

    <?php if ($alert): ?>
        <div class="alert alert-<?= h($alert['type']) ?>"><?= h($alert['text']) ?></div>
    <?php endif; ?>

    <p style="color:#6b7280;font-size:.85rem;">
        <?= $isSuperAdmin
            ? 'Pages submitted by an agency, waiting on the platform\'s review before the agency gets a final say. Applies to every agency, including the platform\'s own.'
            : 'Pages the platform has already reviewed — your approval here is the last step before they go live.' ?>
    </p>

    <?php if (!$rows): ?>
        <div class="sm-empty-sm">Nothing waiting on your review right now.</div>
    <?php endif; ?>

    <?php foreach ($rows as $r): ?>
        <div class="lpc-row">
            <div>
                <strong><?= h($r['page_title']) ?></strong>
                <div class="meta"><?= h($r['business_name']) ?> &middot; <?= h($r['page_id']) ?></div>
            </div>
            <div style="display:flex;gap:8px;">
                <a href="preview_site.php?client_id=<?= h($r['client_id']) ?>&page=<?= h($r['page_id']) ?>" class="btn-sm-outline" target="_blank">Preview</a>
                <form method="post" action="approvals_save.php" style="margin:0;display:inline;">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                    <input type="hidden" name="action" value="approve">
                    <input type="hidden" name="client_id" value="<?= h($r['client_id']) ?>">
                    <input type="hidden" name="page_id" value="<?= h($r['page_id']) ?>">
                    <button type="submit" class="btn-open"><?= $isSuperAdmin ? 'Approve &rarr; Agency' : 'Approve &amp; Publish' ?></button>
                </form>
                <form method="post" action="approvals_save.php" style="margin:0;display:inline;" onsubmit="return reject(this);">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                    <input type="hidden" name="action" value="reject">
                    <input type="hidden" name="client_id" value="<?= h($r['client_id']) ?>">
                    <input type="hidden" name="page_id" value="<?= h($r['page_id']) ?>">
                    <input type="hidden" name="note" value="">
                    <button type="submit" class="btn-sm-danger">Reject</button>
                </form>
            </div>
        </div>
    <?php endforeach; ?>
<script>
function reject(form) {
    var note = prompt('Reason for rejecting (shown to whoever submitted it):', '');
    if (note === null) return false; // cancelled
    form.querySelector('input[name="note"]').value = note;
    return true;
}
</script>
<?php lpc_admin_close(); ?>
