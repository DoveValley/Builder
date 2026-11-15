<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

if (empty($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

$client_id = $_GET['id'] ?? '';
$client = lpc_load_client($client_id);
if (!$client) {
    header('Location: clients.php?msg=error:Client+not+found');
    exit;
}

$tab = $_GET['tab'] ?? 'overview';
if (!in_array($tab, ['overview', 'locations', 'branding', 'content_profile', 'delivery', 'reporting'], true)) {
    $tab = 'overview';
}

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

$pagesLive = 0;
$pagesTotal = 0;
foreach (lpc_list_pages($client_id) as $page_id) {
    $page = lpc_load_page($client_id, $page_id);
    if (!$page) continue;
    $pagesTotal++;
    if ($page['status'] === 'published') $pagesLive++;
}

$status = lpc_plugin_status($client_id);
$locations = count($client['target_cities'] ?? []);
$mrr = $locations * (float) ($client['billing']['monthly_fee_per_location'] ?? 0);
$apiKey = lpc_get_client_api_key($client_id);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h($client['identity']['business_name']) ?> — <?= h(SITE_TITLE) ?></title>
<link rel="stylesheet" href="../assets/css/style.css">
<?php $_adminCssMtime = css_minify_to(__DIR__ . '/../assets/css/admin.src.css', __DIR__ . '/../assets/css/admin.css'); ?>
<link rel="stylesheet" href="../assets/css/admin.css?v=<?= (int) $_adminCssMtime ?>">
<style>
.lpc-wrap     { max-width: 1000px; margin: 0 auto; padding: 32px 24px 64px; }
.lpc-topbar   { display:flex; align-items:center; justify-content:space-between; margin-bottom:8px; flex-wrap:wrap; gap:12px; }
.lpc-topbar h1{ font-size:1.4rem; font-weight:700; margin:0; }
.lpc-sub      { color:#6b7280; font-size:.85rem; margin:0 0 20px; }
.lpc-stats    { display:flex; gap:28px; background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:16px 22px; margin-bottom:24px; flex-wrap:wrap; }
.lpc-stats div strong { display:block; font-size:1.25rem; color:#111; }
.lpc-stats div span   { font-size:.78rem; color:#6b7280; }
.lpc-badge    { font-size:.72rem; font-weight:700; padding:2px 9px; border-radius:99px; display:inline-block; }
.lpc-badge-connected { background:#dcfce7; color:#166534; }
.lpc-badge-stale      { background:#fef9c3; color:#92400e; }
.lpc-badge-never      { background:#f3f4f6; color:#6b7280; }
.lpc-hub-row  { background:#fff; border:1px solid #e5e7eb; border-radius:8px; padding:14px 18px; margin-bottom:10px; display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; }
.lpc-hub-row .meta { font-size:.82rem; color:#6b7280; }
.lpc-placeholder { background:#f8fafc; border:2px dashed #cbd5e1; border-radius:10px; padding:40px 24px; text-align:center; color:#6b7280; }
.lpc-form-row { display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end; margin-top:14px; }
.lpc-form-row .form-group { margin:0; flex:1; min-width:140px; }
.lpc-form-row label { font-size:.82rem; font-weight:600; color:#374151; display:block; margin-bottom:4px; }
.lpc-form-row input { width:100%; padding:8px 10px; border:1px solid #d1d5db; border-radius:6px; font-size:.85rem; }
</style>
</head>
<body class="admin-body">
<div style="background:#1e293b; color:#fff; padding:14px 24px; display:flex; align-items:center; justify-content:space-between;">
    <div style="font-weight:700; font-size:1rem; letter-spacing:.01em;">&#9776; Site Factory</div>
    <div style="display:flex; align-items:center; gap:16px;">
        <a href="clients.php" style="color:#94a3b8; font-size:.82rem; text-decoration:none;">&larr; Landing Page Clients</a>
        <a href="logout.php" style="color:#94a3b8; font-size:.82rem; text-decoration:none;">Log out</a>
    </div>
</div>

<div class="lpc-wrap">

    <?php if ($alert): ?>
        <div class="alert alert-<?= h($alert['type']) ?>"><?= h($alert['text']) ?></div>
    <?php endif; ?>

    <div class="lpc-topbar">
        <h1><?= h($client['identity']['business_name']) ?></h1>
        <?php
        $badgeClass = $status === 'Connected' ? 'connected' : ($status === 'Stale' ? 'stale' : 'never');
        ?>
        <span class="lpc-badge lpc-badge-<?= $badgeClass ?>"><?= h($status) ?></span>
    </div>
    <p class="lpc-sub"><?= h($client['identity']['domain']) ?: 'No domain set' ?></p>

    <div class="lpc-stats">
        <div><strong><?= $pagesLive ?> / <?= $pagesTotal ?></strong><span>Pages Live</span></div>
        <div><strong><?= $locations ?></strong><span>Target Locations</span></div>
        <div><strong>$<?= number_format($mrr, 0) ?>/mo</strong><span>MRR</span></div>
    </div>

    <div class="tabs">
        <a class="tab-link <?= $tab === 'overview' ? 'active' : '' ?>" href="?id=<?= h($client_id) ?>&tab=overview">Overview</a>
        <a class="tab-link <?= $tab === 'locations' ? 'active' : '' ?>" href="?id=<?= h($client_id) ?>&tab=locations">Locations &amp; Services</a>
        <a class="tab-link <?= $tab === 'branding' ? 'active' : '' ?>" href="?id=<?= h($client_id) ?>&tab=branding">Branding</a>
        <a class="tab-link <?= $tab === 'content_profile' ? 'active' : '' ?>" href="?id=<?= h($client_id) ?>&tab=content_profile">Content Profile</a>
        <a class="tab-link <?= $tab === 'delivery' ? 'active' : '' ?>" href="?id=<?= h($client_id) ?>&tab=delivery">Delivery</a>
        <a class="tab-link <?= $tab === 'reporting' ? 'active' : '' ?>" href="?id=<?= h($client_id) ?>&tab=reporting">Reporting</a>
    </div>

    <?php if ($tab === 'overview'): ?>

        <h3 style="margin-top:28px;">Hub Offices</h3>
        <?php if (empty($client['hubs'])): ?>
            <p style="color:#6b7280;font-size:.88rem;">No hub offices yet — add the business's real office locations below.</p>
        <?php else: foreach ($client['hubs'] as $hub): ?>
            <div class="lpc-hub-row">
                <div>
                    <strong><?= h($hub['name']) ?></strong>
                    <div class="meta"><?= h($hub['address']['city'] ?? '') ?>, <?= h($hub['address']['state'] ?? '') ?> &middot; <?= h($hub['phone'] ?? '') ?></div>
                </div>
                <form method="post" action="client_save.php" onsubmit="return confirm('Delete this hub?');">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                    <input type="hidden" name="section" value="hubs">
                    <input type="hidden" name="client_id" value="<?= h($client_id) ?>">
                    <input type="hidden" name="hub_action" value="delete">
                    <input type="hidden" name="hub_id" value="<?= h($hub['hub_id']) ?>">
                    <button type="submit" class="btn-sm-danger">Delete</button>
                </form>
            </div>
        <?php endforeach; endif; ?>

        <form method="post" action="client_save.php" class="card" style="margin-top:14px;">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="section" value="hubs">
            <input type="hidden" name="client_id" value="<?= h($client_id) ?>">
            <input type="hidden" name="hub_action" value="add">
            <strong style="font-size:.88rem;">Add a hub office</strong>
            <div class="lpc-form-row">
                <div class="form-group"><label>Name</label><input type="text" name="name" required placeholder="e.g. Dallas Office"></div>
                <div class="form-group"><label>Street</label><input type="text" name="street"></div>
                <div class="form-group"><label>City</label><input type="text" name="city" required></div>
                <div class="form-group"><label>State</label><input type="text" name="state" required></div>
                <div class="form-group"><label>Zip</label><input type="text" name="zip"></div>
                <div class="form-group"><label>Phone</label><input type="text" name="phone"></div>
                <button type="submit" class="btn-open">Add Hub</button>
            </div>
        </form>

        <h3 style="margin-top:32px;">Billing</h3>
        <form method="post" action="client_save.php" class="card">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="section" value="billing">
            <input type="hidden" name="client_id" value="<?= h($client_id) ?>">
            <div class="lpc-form-row">
                <div class="form-group"><label>Setup Fee / Location</label><input type="number" step="0.01" name="setup_fee_per_location" value="<?= h($client['billing']['setup_fee_per_location']) ?>"></div>
                <div class="form-group"><label>Monthly Fee / Location</label><input type="number" step="0.01" name="monthly_fee_per_location" value="<?= h($client['billing']['monthly_fee_per_location']) ?>"></div>
                <div class="form-group"><label>Start Date</label><input type="date" name="start_date" value="<?= h($client['billing']['start_date']) ?>"></div>
                <button type="submit" class="btn-open">Save Billing</button>
            </div>
            <p style="color:#6b7280;font-size:.8rem;margin:10px 0 0;"><?= $locations ?> location<?= $locations === 1 ? '' : 's' ?> &times; $<?= number_format((float)$client['billing']['monthly_fee_per_location'], 2) ?>/mo = $<?= number_format($mrr, 2) ?>/mo</p>
        </form>

    <?php elseif ($tab === 'delivery'): ?>

        <div class="card" style="margin-top:20px;">
            <strong style="font-size:.88rem;">API Key</strong>
            <p style="font-family:monospace;background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;padding:10px 14px;margin:8px 0;word-break:break-all;"><?= h($apiKey) ?></p>
            <p style="color:#6b7280;font-size:.82rem;margin:0;">Paste this into the client's WordPress install under Settings &rarr; Landing Page Client. That is the only setup step on their end.</p>
        </div>
        <div class="card" style="margin-top:14px;">
            <strong style="font-size:.88rem;">Plugin connection</strong>
            <p style="margin:8px 0 0;"><span class="lpc-badge lpc-badge-<?= $badgeClass ?>"><?= h($status) ?></span></p>
        </div>

    <?php else: ?>

        <div class="lpc-placeholder" style="margin-top:24px;">
            <p>This tab is not built yet.</p>
        </div>

    <?php endif; ?>

</div>
</body>
</html>
