<?php
/**
 * admin/agency_overview.php — the landing page when you click into an agency
 * from agencies.php's list. New 2026-11-25, same pattern/reasoning as
 * admin/pluscities_client_overview.php: agencies.php's row had grown an Edit
 * link + Log in as/Get Billing Link/Suspend buttons all inline; this page is
 * that workspace's home tab (status + the edit form's identity/branding
 * fields + Log in as/Suspend). Billing fields (spend cap, monthly fee,
 * subscription status, Get Billing Link) live on agency_billing.php instead
 * — see pluscities_agency_subtabs() in lpc_admin_layout.php for the tab bar.
 *
 * Deliberately no per-agency special-casing in this UI (not even for
 * scotts-agency) — every agency gets the same tabs/buttons. The one
 * remaining scotts-agency guard is server-side in agencies_save.php's
 * toggle_status handler (refuses to actually suspend it, to prevent Scott
 * locking himself out of his own direct-sale clients) — a real safety net,
 * left in place even though the button itself is no longer hidden here.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/lpc_admin_layout.php';

if (empty($_SESSION['admin_logged_in']) || lpc_current_actor_role() !== 'super_admin') {
    header('Location: clients.php?msg=error:Agencies+are+managed+by+the+platform+only');
    exit;
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

$agencyId = trim((string) ($_GET['agency_id'] ?? ''));
$agency   = $agencyId !== '' ? lpc_load_agency($agencyId) : null;

$alert = null;
if (!empty($_GET['msg'])) {
    $raw = $_GET['msg'];
    if (strpos($raw, ':') !== false) {
        [$type, $text] = explode(':', $raw, 2);
        if (in_array($type, ['success', 'error', 'warning'], true)) $alert = ['type' => $type, 'text' => $text];
    }
}

$clientCount = 0;
if ($agency) {
    foreach (lpc_list_clients() as $cid) {
        $c = lpc_load_client($cid);
        if ($c && ($c['agency_id'] ?? '') === $agencyId) $clientCount++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Overview — <?= h($agency['name'] ?? 'PlusCities') ?></title>
<link rel="stylesheet" href="../assets/css/style.css">
<?php $_adminCssMtime = css_minify_to(__DIR__ . '/../assets/css/admin.src.css', __DIR__ . '/../assets/css/admin.css'); ?>
<link rel="stylesheet" href="../assets/css/admin.css?v=<?= (int) $_adminCssMtime ?>">
<?php lpc_admin_head_assets(); ?>
<style>
.pc-card{background:#fff;border:1px solid var(--pc-line,#e2e8f2);border-radius:14px;padding:18px 20px;margin-bottom:16px;}
.pc-form-row{display:flex;gap:12px;flex-wrap:wrap;margin-top:10px;}
.pc-form-row .fg{flex:1;min-width:180px;}
.pc-form-row label{font-size:.8rem;font-weight:600;color:#374151;display:block;margin-bottom:4px;}
.pc-form-row input{width:100%;padding:7px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:.84rem;font-family:inherit;}
.lpc-status{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.03em;padding:2px 8px;border-radius:10px;}
.lpc-status-active{background:var(--pc-lime-soft,#eef6da);color:var(--pc-lime-ink,#3d6b0e);}
.lpc-status-suspended{background:#fee2e2;color:#991b1b;}
.lpc-status-pending_approval{background:#fef3c7;color:#92400e;}
</style>
</head>
<?php lpc_admin_open('agencies', 'Overview'); ?>

<?php if ($alert): ?><div class="alert alert-<?= h($alert['type']) ?>"><?= h($alert['text']) ?></div><?php endif; ?>

<?php if (!$agency): ?>
    <div class="lpc-empty"><h2>Agency not found</h2><p>No agency with id <code><?= h($agencyId) ?></code>.</p></div>
<?php else:
    pluscities_agency_subtabs($agencyId, 'overview');
    $isSuspended = $agency['status'] === 'suspended';
?>
<div class="pc-card">
    <h3 style="margin-top:0;">
        <?= h($agency['name'] ?: $agencyId) ?>
        <span class="lpc-status lpc-status-<?= h($agency['status']) ?>"><?= h(str_replace('_', ' ', $agency['status'])) ?></span>
    </h3>
    <p style="color:var(--pc-muted,#52607a);font-size:.84rem;">
        username: <?= h($agency['login']['username'] ?: '—') ?>
        &middot; <a href="pluscities_clients.php?agency_id=<?= h($agencyId) ?>"><?= $clientCount ?> client(s)</a>
    </p>
</div>

<div class="pc-card">
    <h3 style="margin-top:0;">Actions</h3>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
        <form method="post" action="impersonate.php" style="margin:0;">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="start">
            <input type="hidden" name="type" value="agency">
            <input type="hidden" name="agency_id" value="<?= h($agencyId) ?>">
            <button type="submit" class="btn-sm-outline">Log in as</button>
        </form>
        <form method="post" action="agencies_save.php" style="margin:0;">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="toggle_status">
            <input type="hidden" name="agency_id" value="<?= h($agencyId) ?>">
            <input type="hidden" name="return_to" value="agency_overview.php">
            <?php if ($isSuspended): ?>
            <button type="submit" class="btn-sm-outline">Reactivate</button>
            <?php else: ?>
            <button type="submit" class="btn-sm-danger" onclick="return confirm('Suspend this agency? Its login is blocked immediately; its clients enter the grace-period/pause flow.');">Suspend</button>
            <?php endif; ?>
        </form>
    </div>
</div>

<div class="pc-card">
    <h3 style="margin-top:0;">Agency Details</h3>
    <form method="post" action="agencies_save.php" class="pc-form-row">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="existing_agency_id" value="<?= h($agencyId) ?>">
        <input type="hidden" name="return_to" value="agency_overview.php">
        <input type="hidden" name="spend_cap_usd" value="<?= h((string) ($agency['billing']['spend_cap_usd'] ?? 0)) ?>">
        <input type="hidden" name="monthly_fee_usd" value="<?= h((string) ($agency['billing']['monthly_fee_usd'] ?? 0)) ?>">

        <div class="fg"><label>Agency Name</label><input type="text" name="name" required value="<?= h($agency['name'] ?? '') ?>"></div>
        <div class="fg"><label>Login Username</label><input type="text" name="username" required autocomplete="off" value="<?= h($agency['login']['username'] ?? '') ?>"></div>
        <div class="fg"><label>Password <span style="font-weight:400;color:#6b7280;">(leave blank to keep the current one)</span></label>
            <input type="password" name="password" autocomplete="new-password"></div>

        <div class="pc-section-label" style="flex-basis:100%;margin-top:10px;font-size:.72rem;text-transform:uppercase;letter-spacing:.04em;color:var(--pc-muted,#52607a);">White-Labeling</div>
        <p style="color:var(--pc-muted,#52607a);font-size:.8rem;margin:0 0 4px;flex-basis:100%;">
            Visual only — swapped into the shared admin header in place of the platform's own branding when
            this agency is logged in. No color theming, no custom domain.
        </p>
        <div class="fg"><label>Display Name</label><input type="text" name="display_name" placeholder="shown in place of &quot;PlusCities&quot;" value="<?= h($agency['branding']['display_name'] ?? '') ?>"></div>
        <div class="fg"><label>Logo URL</label><input type="text" name="logo_url" value="<?= h($agency['branding']['logo_url'] ?? '') ?>"></div>

        <div style="flex-basis:100%;">
            <button type="submit" class="btn-open">Save Changes</button>
        </div>
    </form>
</div>
<?php endif; ?>
<?php lpc_admin_close(); ?>
