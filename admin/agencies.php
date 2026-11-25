<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/lpc_admin_layout.php';

if (empty($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}
// Same reasoning as niche_profiles.php's gate: agencies are the platform's
// own tenants, not something an agency manages about itself or anyone else.
if (lpc_current_actor_role() !== 'super_admin') {
    header('Location: clients.php?msg=error:Agencies+are+managed+by+the+platform+only');
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

// Client counts per agency — same cost class as clients.php's own per-client
// page-count loop; fine at this scale, no index needed.
$clientCounts = [];
foreach (lpc_list_clients() as $cid) {
    $c = lpc_load_client($cid);
    if (!$c) continue;
    $aid = $c['agency_id'] ?? '';
    $clientCounts[$aid] = ($clientCounts[$aid] ?? 0) + 1;
}

$agencies = [];
foreach (lpc_list_agencies() as $id) {
    $a = lpc_load_agency($id);
    if ($a) $agencies[] = $a;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Agencies — PlusCities</title>
<link rel="stylesheet" href="../assets/css/style.css">
<?php $_adminCssMtime = css_minify_to(__DIR__ . '/../assets/css/admin.src.css', __DIR__ . '/../assets/css/admin.css'); ?>
<link rel="stylesheet" href="../assets/css/admin.css?v=<?= (int) $_adminCssMtime ?>">
<?php lpc_admin_head_assets(); ?>
<style>
.lpc-wrap     { max-width: 900px; }
.lpc-form-row { display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end; margin-top:14px; }
.lpc-form-row .form-group { margin:0; flex:1; min-width:150px; }
.lpc-form-row label { font-size:.82rem; font-weight:600; color:#374151; display:block; margin-bottom:4px; }
.lpc-form-row input, .lpc-form-row textarea { width:100%; padding:8px 10px; border:1px solid #d1d5db; border-radius:6px; font-size:.85rem; font-family:inherit; }
.lpc-row { background:#fff; border:1px solid #e5e7eb; border-radius:8px; padding:12px 16px; margin-bottom:8px; display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; }
.lpc-row .meta { font-size:.8rem; color:#6b7280; }
.lpc-status { font-size:.72rem; font-weight:700; text-transform:uppercase; letter-spacing:.03em; padding:2px 8px; border-radius:10px; }
.lpc-status-active   { background:var(--pc-lime-soft); color:var(--pc-lime-ink); }
.lpc-status-suspended{ background:#fee2e2; color:#991b1b; }
.lpc-status-pending_approval { background:#fef3c7; color:#92400e; }
.pc-client-name{font-weight:700;color:var(--pc-ink,#1c2b45);text-decoration:none;font-size:.95rem;}
.pc-client-name:hover{color:var(--pc-lime-ink,#3d6b0e);text-decoration:underline;}
</style>
</head>
<?php lpc_admin_open('agencies', 'Agencies'); ?>

    <?php if ($alert): ?>
        <div class="alert alert-<?= h($alert['type']) ?>"><?= h($alert['text']) ?></div>
    <?php endif; ?>

    <p style="color:#6b7280;font-size:.85rem;">
        Each agency resells PlusCities to its own customers, scoped to only the clients
        it created — creating one here IS the approval, there's no separate signup to review.
        Suspending an agency blocks its login AND its clients go into the grace-period/pause flow,
        not an immediate takedown.
    </p>

    <?php foreach ($agencies as $a): ?>
        <div class="lpc-row">
            <div>
                <a href="agency_overview.php?agency_id=<?= h($a['agency_id']) ?>" class="pc-client-name"><?= h($a['name'] ?: $a['agency_id']) ?></a>
                <span class="lpc-status lpc-status-<?= h($a['status']) ?>"><?= h(str_replace('_', ' ', $a['status'])) ?></span>
                <div class="meta">
                    username: <?= h($a['login']['username'] ?: '—') ?>
                    &middot; <?= (int) ($clientCounts[$a['agency_id']] ?? 0) ?> client(s)
                    <?php if ((float) ($a['billing']['monthly_fee_usd'] ?? 0) > 0): ?>
                        &middot; $<?= number_format((float) $a['billing']['monthly_fee_usd'], 2) ?>/mo
                        <?= $a['billing']['stripe_subscription_id'] ? '(active subscription)' : '(not yet paid)' ?>
                    <?php endif; ?>
                </div>
            </div>
            <div>
                <a href="agency_overview.php?agency_id=<?= h($a['agency_id']) ?>" class="btn-open">Open &rarr;</a>
            </div>
        </div>
    <?php endforeach; ?>

    <h3 style="margin-top:28px;">Add an Agency</h3>
    <form method="post" action="agencies_save.php" class="card">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="save">
        <div class="lpc-form-row">
            <div class="form-group"><label>Agency Name</label><input type="text" name="name" required placeholder="e.g. Acme Marketing"></div>
            <div class="form-group"><label>Login Username</label><input type="text" name="username" required autocomplete="off"></div>
        </div>
        <div class="lpc-form-row">
            <div class="form-group"><label>Password</label><input type="password" name="password" autocomplete="new-password" required></div>
            <div class="form-group"><label>Monthly Spend Cap (USD) <span style="font-weight:400;color:#6b7280;">(paid API calls — rank checks, keyword lookups, regenerations; 0 = no cap enforced)</span></label><input type="number" step="0.01" min="0" name="spend_cap_usd" value="0"></div>
        </div>
        <div class="lpc-form-row">
            <div class="form-group"><label>Monthly Platform Fee (USD) <span style="font-weight:400;color:#6b7280;">(what this agency pays you)</span></label><input type="number" step="0.01" min="0" name="monthly_fee_usd" value="0"></div>
        </div>

        <h4 style="margin-top:18px;margin-bottom:4px;">White-Labeling</h4>
        <p style="color:#6b7280;font-size:.8rem;margin:0 0 8px;">
            Visual only — swapped into the shared admin header in place of the platform's own
            branding when this agency is logged in. No color theming, no custom domain.
        </p>
        <div class="lpc-form-row">
            <div class="form-group"><label>Display Name</label><input type="text" name="display_name" placeholder="shown in place of &quot;PlusCities&quot;"></div>
            <div class="form-group"><label>Logo URL</label><input type="text" name="logo_url"></div>
        </div>

        <button type="submit" class="pc-btn pc-btn-lime" style="margin-top:10px;">Create Agency</button>
    </form>
<?php lpc_admin_close(); ?>
