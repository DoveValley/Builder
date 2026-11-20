<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/lpc_admin_layout.php';

if (!lpc_actor_is_authenticated()) { header('Location: login.php'); exit; }
// Self-service for the agency that owns this branding only — Scott still
// edits any agency's branding (plus everything else about it) from
// agencies.php; an End Client has no agency-level settings of its own.
if (lpc_current_actor_role() !== 'agency_admin') {
    header('Location: clients.php');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

$agency = lpc_load_agency(lpc_current_actor_agency_id());
if (!$agency) {
    header('Location: clients.php');
    exit;
}

$alert = null;
if (!empty($_GET['msg'])) {
    $raw = $_GET['msg'];
    if (strpos($raw, ':') !== false) {
        [$type, $text] = explode(':', $raw, 2);
        if (in_array($type, ['success', 'error'], true)) {
            $alert = ['type' => $type, 'text' => $text];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Branding — <?= h(SITE_TITLE) ?></title>
<link rel="stylesheet" href="../assets/css/style.css">
<?php $_adminCssMtime = css_minify_to(__DIR__ . '/../assets/css/admin.src.css', __DIR__ . '/../assets/css/admin.css'); ?>
<link rel="stylesheet" href="../assets/css/admin.css?v=<?= (int) $_adminCssMtime ?>">
<?php lpc_admin_head_assets(); ?>
<style>
.lpc-wrap { max-width: 640px; }
.lpc-form-row { display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end; margin-top:14px; }
.lpc-form-row .form-group { margin:0; flex:1; min-width:200px; }
.lpc-form-row label { font-size:.82rem; font-weight:600; color:#374151; display:block; margin-bottom:4px; }
.lpc-form-row input { width:100%; padding:8px 10px; border:1px solid #d1d5db; border-radius:6px; font-size:.85rem; font-family:inherit; }
</style>
</head>
<?php lpc_admin_open('branding', 'My Branding'); ?>

    <?php if ($alert): ?>
        <div class="alert alert-<?= h($alert['type']) ?>"><?= h($alert['text']) ?></div>
    <?php endif; ?>

    <p style="color:#6b7280;font-size:.85rem;">
        Shown in place of &quot;Site Factory&quot; across your own dashboard once set &mdash; visual
        only, no color theming, no custom domain.
    </p>

    <form method="post" action="agency_branding_save.php" class="card" style="margin-top:16px;">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
        <div class="lpc-form-row">
            <div class="form-group">
                <label>Display Name</label>
                <input type="text" name="display_name" placeholder="shown in place of &quot;Site Factory&quot;" value="<?= h($agency['branding']['display_name'] ?? '') ?>">
            </div>
        </div>
        <div class="lpc-form-row">
            <div class="form-group">
                <label>Logo URL</label>
                <input type="text" name="logo_url" placeholder="https://..." value="<?= h($agency['branding']['logo_url'] ?? '') ?>">
            </div>
        </div>
        <?php if (!empty($agency['branding']['logo_url'])): ?>
        <div style="margin-top:14px;">
            <label style="font-size:.82rem;font-weight:600;color:#374151;display:block;margin-bottom:6px;">Current logo</label>
            <img src="<?= h($agency['branding']['logo_url']) ?>" alt="" style="height:32px;width:auto;display:block;">
        </div>
        <?php endif; ?>
        <button type="submit" class="pc-btn pc-btn-lime" style="margin-top:16px;">Save Branding</button>
    </form>
<?php lpc_admin_close(); ?>
