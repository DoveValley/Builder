<?php
/**
 * admin/pluscities_client_overview.php — the landing page when you click into
 * a client from pluscities_clients.php's list. New 2026-11-25: PlusCities
 * never had a single per-client "workspace" the way the old (deleted)
 * client.php did — every action (Photos, Pages, Service Area Page, etc.) was
 * its own separate full page, so the list row grew 6 content buttons + 4
 * admin-action buttons per client, all inline. This page is that workspace's
 * home tab: status summary (moved from the list row) + admin-only actions
 * (moved from the list row) + the shared sub-tab bar
 * (pluscities_client_subtabs() in lpc_admin_layout.php) that now appears on
 * every client-scoped screen. The list row itself was decluttered down to
 * just a link into here plus a one-line status summary.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/lpc_admin_layout.php';
require_once __DIR__ . '/infra/lib/uptime.php';

if (!lpc_actor_is_authenticated() || lpc_current_actor_role() === 'end_client') {
    header('Location: login.php');
    exit;
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

$clientId = $_GET['client_id'] ?? '';
$clients  = pluscities_client_ids_for_current_actor();
$client   = pluscities_actor_can_access_client($clientId) ? ($clients[$clientId] ?? null) : null;

$alert = null;
if (!empty($_GET['msg'])) {
    $raw = $_GET['msg'];
    if (strpos($raw, ':') !== false) {
        [$type, $text] = explode(':', $raw, 2);
        if (in_array($type, ['success', 'error', 'warning'], true)) $alert = ['type' => $type, 'text' => $text];
    }
}

$graceDays = pluscities_platform_settings()['cancellation_grace_days'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Overview — <?= h($client['identity']['business_name'] ?? 'PlusCities') ?></title>
<link rel="stylesheet" href="../assets/css/style.css">
<?php $_adminCssMtime = css_minify_to(__DIR__ . '/../assets/css/admin.src.css', __DIR__ . '/../assets/css/admin.css'); ?>
<link rel="stylesheet" href="../assets/css/admin.css?v=<?= (int) $_adminCssMtime ?>">
<?php lpc_admin_head_assets(); ?>
</head>
<?php
lpc_admin_open('pluscities_clients', 'Overview');
?>
<style>
.pc-card{background:#fff;border:1px solid var(--pc-line,#e2e8f2);border-radius:14px;padding:18px 20px;margin-bottom:16px;}
.pc-site-badge{display:inline-block;margin-left:8px;font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.02em;padding:2px 8px;border-radius:10px;}
.pc-site-badge-live{background:#dcfce7;color:#166534;}
.pc-site-badge-warn{background:#fef3c7;color:#92400e;}
.pc-site-badge-down{background:#fee2e2;color:#991b1b;}
</style>

<?php if ($alert): ?><div class="alert alert-<?= h($alert['type']) ?>"><?= h($alert['text']) ?></div><?php endif; ?>

<?php if (!$client): ?>
    <div class="lpc-empty"><h2>Client not found</h2><p>No client with id <code><?= h($clientId) ?></code> (or you don't have access to it).</p></div>
<?php else:
    pluscities_client_subtabs($clientId, 'overview');
    $businessName = $client['identity']['business_name'] ?? $clientId;
    $siteCheck = infra_site_check($client['domain'] ?? '');
    if (!empty($siteCheck['up']) && !empty($siteCheck['cert_ok'])) {
        $siteBadgeClass = 'pc-site-badge-live'; $siteBadgeText = 'Site: Live';
    } elseif (!empty($siteCheck['up'])) {
        $siteBadgeClass = 'pc-site-badge-warn'; $siteBadgeText = 'Site: Live (SSL issue)';
    } else {
        $siteBadgeClass = 'pc-site-badge-down'; $siteBadgeText = 'Site: Down';
    }
    $canManage   = in_array(lpc_current_actor_role(), ['super_admin', 'agency_admin'], true);
    $isSuperAdmin = lpc_current_actor_role() === 'super_admin';
    $isCanceled  = ($client['status'] ?? 'active') === 'canceled';
    $isSuspended = !empty($client['site_suspended']);
    $domain      = trim((string) ($client['domain'] ?? ''));
    $hubEnabled  = $client['hub_content']['enabled'] ?? true;
    $urlBasePath = (string) ($client['url_base_path'] ?? 'service-area');
    $serviceAreaUrl = 'https://' . $domain . '/' . trim($urlBasePath, '/') . '/';
?>
<div class="pc-card">
    <h3 style="margin-top:0;">
        <?= h($businessName) ?>
        <span class="pc-site-badge <?= $siteBadgeClass ?>" title="<?= h($siteCheck['error'] ?: ('HTTP ' . $siteCheck['code'])) ?>"><?= h($siteBadgeText) ?></span>
    </h3>
    <div style="font-size:.84rem;color:var(--pc-muted,#52607a);">
        <?php if ($domain !== ''): ?>
            <a href="https://<?= h($domain) ?>" target="_blank" rel="noopener"><?= h($domain) ?></a>
            &middot;
            <?php if ($hubEnabled): ?>
                <a href="<?= h($serviceAreaUrl) ?>" target="_blank" rel="noopener">Service Area Page &#8599;</a>
            <?php else: ?>
                <em>Service Area Page (disabled)</em>
            <?php endif; ?>
        <?php else: ?>
            <em>No domain set</em>
        <?php endif; ?>
        &middot; <?= h($client['niche_id']) ?> &middot; <?= (int) count($client['target_cities'] ?? []) ?> cit(y/ies)
        &middot; Client approval: <?= !empty($client['requires_client_approval']) ? '<strong>On</strong>' : 'Off' ?>
        <?php if ($isCanceled):
            $daysLeft = $graceDays - (int) floor((time() - strtotime($client['canceled_at'])) / 86400);
        ?>
            &middot; <strong style="color:#991b1b;">
                <?= $daysLeft > 0 ? 'Cancelled — ' . $daysLeft . ' day(s) left in grace period' : 'Cancelled — grace period ended, redirecting now' ?>
            </strong>
        <?php endif; ?>
        <?php if ($isSuspended): ?>
            &middot; <strong style="color:#991b1b;">Suspended by platform admin</strong>
        <?php endif; ?>
    </div>
    <?php if (!empty($client['last_seen_at'])):
        $secsAgo = max(0, time() - strtotime($client['last_seen_at']));
        $agoText = $secsAgo < 3600 ? max(1, (int) ($secsAgo / 60)) . 'm ago'
            : ($secsAgo < 86400 ? (int) ($secsAgo / 3600) . 'h ago' : (int) ($secsAgo / 86400) . 'd ago');
    ?>
    <div style="font-size:.8rem;color:var(--pc-muted,#52607a);margin-top:4px;">
        Plugin <?= h($client['plugin_version'] ?: '?') ?> &middot; Theme: <?= h($client['theme'] ?: '?') ?> &middot; SEO: <?= h($client['seo_plugin'] ?: 'none') ?> &middot; last synced <?= h($agoText) ?>
    </div>
    <?php else: ?>
    <div style="font-size:.8rem;color:var(--pc-muted,#52607a);margin-top:4px;">Plugin never synced yet</div>
    <?php endif; ?>
</div>

<div class="pc-card">
    <h3 style="margin-top:0;">Actions</h3>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
        <a href="pluscities_plugin_download.php?client_id=<?= h($clientId) ?>" class="btn-sm-outline">Download Plugin</a>
        <?php if ($canManage): ?>
        <form method="post" action="impersonate.php" style="margin:0;">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="start">
            <input type="hidden" name="type" value="client">
            <input type="hidden" name="client_id" value="<?= h($clientId) ?>">
            <button type="submit" class="btn-sm-outline">Log in as</button>
        </form>
        <form method="post" action="pluscities_clients_save.php" style="margin:0;">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="toggle_client_approval">
            <input type="hidden" name="client_id" value="<?= h($clientId) ?>">
            <button type="submit" class="btn-sm-outline"><?= !empty($client['requires_client_approval']) ? 'Turn Off' : 'Turn On' ?> Client Approval</button>
        </form>
        <form method="post" action="pluscities_clients_save.php" style="margin:0;" onsubmit="return confirm('<?= $isCanceled ? 'Reactivate this client? Their pages will stop redirecting and serve normally again.' : 'Cancel this client? After the ' . $graceDays . '-day grace period, all their pages will redirect to their own homepage instead of serving content.' ?>');">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="<?= $isCanceled ? 'reactivate' : 'cancel' ?>">
            <input type="hidden" name="client_id" value="<?= h($clientId) ?>">
            <button type="submit" class="<?= $isCanceled ? 'btn-sm-outline' : 'btn-sm-danger' ?>"><?= $isCanceled ? 'Reactivate' : 'Cancel Client' ?></button>
        </form>
        <?php endif; ?>
        <?php if ($isSuperAdmin): ?>
        <form method="post" action="pluscities_clients_save.php" style="margin:0;" onsubmit="return confirm('<?= $isSuspended ? 'Un-suspend this site? Pages stay unpublished until manually republished from the Pages screen.' : 'Suspend this site immediately? Every live page for this client is unpublished right away, no grace period.' ?>');">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="<?= $isSuspended ? 'unsuspend_site' : 'suspend_site' ?>">
            <input type="hidden" name="client_id" value="<?= h($clientId) ?>">
            <button type="submit" class="<?= $isSuspended ? 'btn-sm-outline' : 'btn-sm-danger' ?>"><?= $isSuspended ? 'Un-suspend Site' : 'Suspend Site' ?></button>
        </form>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>
<?php lpc_admin_close(); ?>
