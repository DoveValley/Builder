<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/lpc_admin_layout.php';

if (empty($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}
// Platform-wide switches, not something an agency sets for itself — same
// gate as agencies.php/niche_profiles.php.
if (lpc_current_actor_role() !== 'super_admin') {
    header('Location: clients.php?msg=error:Settings+are+managed+by+the+platform+only');
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

$agencies = [];
foreach (lpc_list_agencies() as $id) {
    $a = lpc_load_agency($id);
    if ($a) $agencies[] = $a;
}
$platformSettings = pluscities_platform_settings();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Settings — <?= h(SITE_TITLE) ?></title>
<link rel="stylesheet" href="../assets/css/style.css">
<?php $_adminCssMtime = css_minify_to(__DIR__ . '/../assets/css/admin.src.css', __DIR__ . '/../assets/css/admin.css'); ?>
<link rel="stylesheet" href="../assets/css/admin.css?v=<?= (int) $_adminCssMtime ?>">
<?php lpc_admin_head_assets(); ?>
<style>
.lpc-wrap { max-width: 900px; }
.lpc-row  { background:#fff; border:1px solid #e5e7eb; border-radius:8px; padding:12px 16px; margin-bottom:8px; display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; }
.lpc-row .meta { font-size:.8rem; color:#6b7280; }
.lpc-status { font-size:.72rem; font-weight:700; text-transform:uppercase; letter-spacing:.03em; padding:2px 8px; border-radius:10px; }
.lpc-status-on  { background:var(--pc-lime-soft); color:var(--pc-lime-ink); }
.lpc-status-off { background:#f1f5f9; color:#64748b; }
</style>
</head>
<?php lpc_admin_open('settings', 'Settings'); ?>

    <?php if ($alert): ?>
        <div class="alert alert-<?= h($alert['type']) ?>"><?= h($alert['text']) ?></div>
    <?php endif; ?>

    <h3 style="margin-top:0;">Approval Gate</h3>
    <p style="color:#6b7280;font-size:.85rem;">
        Whether an Agency Editor can approve their own pages, or only an Agency Admin can. Defaults
        on for every agency — this is the Platform Admin deciding how much oversight a given
        agency needs, not something an agency can set for itself.
    </p>

    <?php foreach ($agencies as $a): $gated = !empty($a['requires_approval_gate']); ?>
        <div class="lpc-row">
            <div>
                <strong><?= h($a['name'] ?: $a['agency_id']) ?></strong>
                <span class="lpc-status <?= $gated ? 'lpc-status-on' : 'lpc-status-off' ?>">
                    <?= $gated ? 'Gate On' : 'Gate Off' ?>
                </span>
                <?php if ($a['agency_id'] === 'scotts-agency'): ?>
                <div class="meta">Scott's own direct-sale agency</div>
                <?php endif; ?>
            </div>
            <form method="post" action="agencies_save.php" style="margin:0;" onsubmit="return confirm('<?= $gated ? 'Turn OFF the approval gate for this agency? Its pages will publish immediately, no review.' : 'Turn ON the approval gate for this agency? Its pages will need Platform + agency review before publishing.' ?>');">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <input type="hidden" name="action" value="toggle_approval_gate">
                <input type="hidden" name="agency_id" value="<?= h($a['agency_id']) ?>">
                <button type="submit" class="btn-sm-outline"><?= $gated ? 'Turn Off' : 'Turn On' ?></button>
            </form>
        </div>
    <?php endforeach; ?>
    <?php if (!$agencies): ?>
        <p class="meta">No agencies yet.</p>
    <?php endif; ?>

    <h3 style="margin-top:28px;">Client Cancellation</h3>
    <p style="color:#6b7280;font-size:.85rem;">
        Platform-wide, not per-agency — one predictable policy for every cancelled client.
    </p>
    <form method="post" action="platform_settings_save.php" class="lpc-row" style="flex-direction:column;align-items:stretch;">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
        <div style="display:flex;gap:20px;flex-wrap:wrap;">
            <div class="fg">
                <label style="font-size:.8rem;font-weight:600;display:block;margin-bottom:4px;">Grace period (days)</label>
                <input type="number" name="cancellation_grace_days" min="0" value="<?= (int) $platformSettings['cancellation_grace_days'] ?>" style="width:100px;padding:6px 8px;border:1px solid #d1d5db;border-radius:6px;">
                <div class="meta">How long a cancelled client's pages keep working before they redirect to the client's own homepage.</div>
            </div>
            <div class="fg">
                <label style="font-size:.8rem;font-weight:600;display:block;margin-bottom:4px;">Plugin cache lifetime (hours)</label>
                <input type="number" name="plugin_cache_ttl_hours" min="1" value="<?= (int) $platformSettings['plugin_cache_ttl_hours'] ?>" style="width:100px;padding:6px 8px;border:1px solid #d1d5db;border-radius:6px;">
                <div class="meta">How long the WordPress plugin keeps showing a page without checking in. Previously never expired at all — a shorter value here is what actually makes cancellation able to take effect.</div>
            </div>
        </div>
        <div style="margin-top:12px;">
            <button type="submit" class="btn-open">Save</button>
        </div>
    </form>

<?php lpc_admin_close(); ?>
