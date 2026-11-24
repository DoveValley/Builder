<?php
/**
 * admin/client_portal.php — the End Client's one screen: read-only reporting
 * on their own pages, nothing else. Rebuilt 2026-11-24 against the current
 * PlusCities data model (pluscities_clients/*.json + pluscities.db) — the
 * previous version of this file (deleted 2026-11-23 in the LPC retirement)
 * was built against the old LPC client record shape and would not have
 * worked here even if restored as-is.
 *
 * Only shows pages with status 'live' — an End Client has no approval role
 * today (see the Workflow section of the Product Roadmap), so a draft or
 * pending page isn't theirs to see yet.
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
$st = $db->prepare("SELECT * FROM pages WHERE client_id = ? AND status = 'live' ORDER BY city_id, service_key");
$st->execute([$clientId]);
$pages = $st->fetchAll(PDO::FETCH_ASSOC);

$businessName = $client['identity']['business_name'] ?? $clientId;
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
.pc-status-pill{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.02em;padding:2px 9px;border-radius:10px;background:#dcfce7;color:#166534;display:inline-block;}
</style>

<div class="pc-card">
    <h3 style="margin-top:0;"><?= h($businessName) ?></h3>
    <p style="color:var(--pc-muted,#52607a);font-size:.84rem;">
        Your live pages, read-only. Reach out to your agency for anything you'd like changed.
    </p>
    <table class="pc-report-table">
        <tr><th>City</th><th>Service</th><th>Status</th></tr>
        <?php if (!$pages): ?>
        <tr><td colspan="3" style="color:var(--pc-muted,#52607a);text-align:center;padding:16px 12px;">No live pages yet.</td></tr>
        <?php else: foreach ($pages as $p): ?>
        <tr>
            <td><?= h(pluscities_city_label($p['city_id'])) ?></td>
            <td><?= h(ucwords(str_replace('-', ' ', $p['service_key']))) ?></td>
            <td><span class="pc-status-pill">Live</span></td>
        </tr>
        <?php endforeach; endif; ?>
    </table>
</div>

<?php lpc_admin_close(); ?>
