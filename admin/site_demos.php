<?php
/**
 * admin/site_demos.php — a real link list of every site on the LPC demo box
 * (2026-11-26). Brings back the one genuinely still-needed job the old
 * "Demo Sites" screen (lpc_demo_sites.php) did before its onboarding-wizard
 * half was retired 2026-11-23 (LPC Stage 3) — just the list, not the wizard,
 * since WP Builder (admin/wp_builder.php) is the real site-creation tool now.
 * Read-only: edit/delete a WP-Builder-created site from WP Builder itself;
 * this screen only answers "what's actually out there and is it up."
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/lpc_demo_box.php';
require_once __DIR__ . '/../includes/wp_builder.php';
require_once __DIR__ . '/lpc_admin_layout.php';

if (empty($_SESSION['admin_logged_in'])) { header('Location: login.php'); exit; }
if (lpc_current_actor_role() !== 'super_admin') {
    header('Location: pluscities_clients.php?msg=error:Demo+sites+are+managed+by+the+platform+only');
    exit;
}

$status = lpc_demo_check_sites_status();
$registry = wpb_registry();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Site Demos — PlusCities</title>
<link rel="stylesheet" href="../assets/css/style.css">
<?php $_adminCssMtime = css_minify_to(__DIR__ . '/../assets/css/admin.src.css', __DIR__ . '/../assets/css/admin.css'); ?>
<link rel="stylesheet" href="../assets/css/admin.css?v=<?= (int) $_adminCssMtime ?>">
<?php lpc_admin_head_assets(); ?>
</head>
<?php lpc_admin_open('site_demos', 'Site Demos'); ?>
<style>
.pc-card{background:#fff;border:1px solid var(--pc-line,#e2e8f2);border-radius:14px;padding:18px 20px;margin-bottom:16px;}
.pc-demo-row{display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid #f1f5f9;font-size:.86rem;}
.pc-demo-row:last-child{border-bottom:none;}
.pc-demo-badge{font-size:.68rem;font-weight:700;text-transform:uppercase;padding:2px 8px;border-radius:10px;}
.pc-demo-up{background:#dcfce7;color:#166534;}
.pc-demo-down{background:#fee2e2;color:#991b1b;}
</style>

<div class="pc-card">
    <h3 style="margin-top:0;">Every site on the demo box (<?= count($status) ?>)</h3>
    <p style="font-size:.84rem;color:#6b7280;margin:0 0 10px;">
        Built via <a href="wp_builder.php">WP Builder</a> or an older rehearsal site that predates it — whatever's
        actually live on the box, real status (theme + reachability) checked just now, not cached.
    </p>
    <?php foreach ($status as $domain => $info):
        $profile = $registry[$domain]['profile'] ?? null;
    ?>
        <div class="pc-demo-row">
            <div>
                <a href="http://<?= h($domain) ?>/" target="_blank" rel="noopener"><strong><?= h($domain) ?></strong></a>
                <?php if ($profile): ?><span style="color:#6b7280;"> — <?= h(wpb_profiles()[$profile] ?? $profile) ?></span><?php endif; ?>
                <div style="font-size:.76rem;color:#6b7280;">theme: <?= h($info['theme']) ?></div>
            </div>
            <span class="pc-demo-badge <?= $info['running'] ? 'pc-demo-up' : 'pc-demo-down' ?>">
                <?= $info['running'] ? 'Up' : 'Down' ?>
            </span>
        </div>
    <?php endforeach; ?>
    <?php if (!$status): ?><p style="color:#6b7280;font-size:.84rem;">No sites on the box yet.</p><?php endif; ?>
</div>
<?php lpc_admin_close(); ?>
