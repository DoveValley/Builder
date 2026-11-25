<?php
/**
 * admin/agency_billing.php — the Billing tab of the agency workspace (see
 * admin/agency_overview.php's doc comment). Spend cap, monthly platform fee,
 * Stripe subscription status, and the "Get Billing Link" action — split out
 * from Overview 2026-11-25 (Scott: "billing" as its own tab) since it's a
 * coherent cluster distinct from identity/login/branding.
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Billing — <?= h($agency['name'] ?? 'PlusCities') ?></title>
<link rel="stylesheet" href="../assets/css/style.css">
<?php $_adminCssMtime = css_minify_to(__DIR__ . '/../assets/css/admin.src.css', __DIR__ . '/../assets/css/admin.css'); ?>
<link rel="stylesheet" href="../assets/css/admin.css?v=<?= (int) $_adminCssMtime ?>">
<?php lpc_admin_head_assets(); ?>
<style>
.pc-card{background:#fff;border:1px solid var(--pc-line,#e2e8f2);border-radius:14px;padding:18px 20px;margin-bottom:16px;}
.pc-form-row{display:flex;gap:12px;flex-wrap:wrap;margin-top:10px;}
.pc-form-row .fg{flex:1;min-width:220px;}
.pc-form-row label{font-size:.8rem;font-weight:600;color:#374151;display:block;margin-bottom:4px;}
.pc-form-row input{width:100%;padding:7px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:.84rem;font-family:inherit;}
</style>
</head>
<?php lpc_admin_open('agencies', 'Billing'); ?>

<?php if ($alert): ?><div class="alert alert-<?= h($alert['type']) ?>"><?= h($alert['text']) ?></div><?php endif; ?>

<?php if (!$agency): ?>
    <div class="lpc-empty"><h2>Agency not found</h2><p>No agency with id <code><?= h($agencyId) ?></code>.</p></div>
<?php else:
    pluscities_agency_subtabs($agencyId, 'billing');
    $fee = (float) ($agency['billing']['monthly_fee_usd'] ?? 0);
    $hasSubscription = !empty($agency['billing']['stripe_subscription_id']);
    $hasPendingLink  = !empty($agency['billing']['last_checkout_url']) && !$hasSubscription;
?>
<div class="pc-card">
    <h3 style="margin-top:0;"><?= h($agency['name'] ?: $agencyId) ?> — Billing</h3>
    <p style="color:var(--pc-muted,#52607a);font-size:.84rem;">
        <?php if ($fee > 0): ?>
            $<?= number_format($fee, 2) ?>/mo &middot; <?= $hasSubscription ? 'active subscription' : 'not yet paid' ?>
        <?php else: ?>
            No monthly fee set
        <?php endif; ?>
    </p>
    <?php if ($hasPendingLink): ?>
    <p style="font-size:.84rem;">
        <a href="<?= h($agency['billing']['last_checkout_url']) ?>" target="_blank" rel="noopener">Billing link ready — click to send/pay</a>
    </p>
    <?php endif; ?>
    <?php if ($fee > 0 && !$hasSubscription): ?>
    <form method="post" action="agencies_save.php" style="margin:0;">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="create_checkout">
        <input type="hidden" name="agency_id" value="<?= h($agencyId) ?>">
        <input type="hidden" name="return_to" value="agency_billing.php">
        <button type="submit" class="btn-sm-outline">Get Billing Link</button>
    </form>
    <?php endif; ?>
</div>

<div class="pc-card">
    <h3 style="margin-top:0;">Billing Settings</h3>
    <form method="post" action="agencies_save.php" class="pc-form-row">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="existing_agency_id" value="<?= h($agencyId) ?>">
        <input type="hidden" name="return_to" value="agency_billing.php">
        <input type="hidden" name="name" value="<?= h($agency['name'] ?? '') ?>">
        <input type="hidden" name="username" value="<?= h($agency['login']['username'] ?? '') ?>">
        <input type="hidden" name="display_name" value="<?= h($agency['branding']['display_name'] ?? '') ?>">
        <input type="hidden" name="logo_url" value="<?= h($agency['branding']['logo_url'] ?? '') ?>">

        <div class="fg">
            <label>Monthly Platform Fee (USD)</label>
            <input type="number" step="0.01" min="0" name="monthly_fee_usd" value="<?= h((string) $fee) ?>">
            <p style="color:var(--pc-muted,#52607a);font-size:.78rem;margin:4px 0 0;">
                What this agency pays you — if they already have an active subscription, changing this
                updates its real price on save; otherwise it's used the next time you create a billing link.
            </p>
        </div>
        <div class="fg">
            <label>Monthly Spend Cap (USD)</label>
            <input type="number" step="0.01" min="0" name="spend_cap_usd" value="<?= h((string) ($agency['billing']['spend_cap_usd'] ?? 0)) ?>">
            <p style="color:var(--pc-muted,#52607a);font-size:.78rem;margin:4px 0 0;">
                Paid API calls — rank checks, keyword lookups, regenerations. 0 = no cap enforced.
            </p>
        </div>

        <div style="flex-basis:100%;">
            <button type="submit" class="btn-open">Save Changes</button>
        </div>
    </form>
</div>
<?php endif; ?>
<?php lpc_admin_close(); ?>
