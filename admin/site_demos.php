<?php
/**
 * admin/site_demos.php — a real link list of every site on the LPC demo box
 * (2026-11-26). Brings back the one genuinely still-needed job the old
 * "Demo Sites" screen (lpc_demo_sites.php) did before its onboarding-wizard
 * half was retired 2026-11-23 (LPC Stage 3) — just the list, not the wizard,
 * since WP Builder (admin/wp_builder.php) is the real site-creation tool now.
 * Read-only: edit/delete a WP-Builder-created site from WP Builder itself;
 * this screen only answers "what's actually out there."
 *
 * Deliberately does NOT call lpc_demo_check_sites_status() by default — that
 * function runs a real SSH + wp-cli + network check against EVERY site on
 * the box (27 of them as of 2026-11-26), ~50 seconds end to end, found the
 * hard way: the first version of this screen called it on every page load,
 * which is exactly the "real resource contention on this 2-vCPU box" that
 * function's OWN docblock already warns the fleet console hit once before.
 * It also slowed pluscities.com itself, since that site is hosted on this
 * same shared box. Status is now opt-in (one button, one explicit request),
 * never automatic.
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

/** The 4 reorder buttons (one step up/down, or jump straight to top/bottom)
 *  shared by domain rows and section-header rows — same move_entry target,
 *  just a different type/id. */
function site_demos_order_buttons(string $type, string $id, bool $isFirst, bool $isLast): void
{
    ?>
    <form method="post" class="pc-demo-order">
        <input type="hidden" name="move_type" value="<?= h($type) ?>">
        <input type="hidden" name="move_id" value="<?= h($id) ?>">
        <button type="submit" name="move_action" value="top" <?= $isFirst ? 'disabled' : '' ?> title="Move to top">&#8607;</button>
        <button type="submit" name="move_action" value="bottom" <?= $isLast ? 'disabled' : '' ?> title="Move to bottom">&#8609;</button>
        <button type="submit" name="move_action" value="up" <?= $isFirst ? 'disabled' : '' ?> title="Move up">&#9650;</button>
        <button type="submit" name="move_action" value="down" <?= $isLast ? 'disabled' : '' ?> title="Move down">&#9660;</button>
    </form>
    <?php
}

$domains = lpc_demo_box_domains();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $moveType = $_POST['move_type'] ?? '';
    $moveId = $_POST['move_id'] ?? '';
    $moveAction = in_array($_POST['move_action'] ?? '', ['up', 'down', 'top', 'bottom'], true) ? $_POST['move_action'] : null;
    if ($moveType === 'domain' && $moveAction && in_array($moveId, $domains, true)) {
        lpc_demo_move_entry($domains, 'domain', $moveId, $moveAction);
        header('Location: site_demos.php');
        exit;
    }
    if ($moveType === 'section' && $moveAction && $moveId !== '') {
        lpc_demo_move_entry($domains, 'section', $moveId, $moveAction);
        header('Location: site_demos.php');
        exit;
    }
    if (!empty($_POST['note_domain']) && in_array($_POST['note_domain'], $domains, true)) {
        lpc_demo_set_note($_POST['note_domain'], trim((string) ($_POST['note'] ?? '')));
        header('Location: site_demos.php');
        exit;
    }
    if (!empty($_POST['add_section_label'])) {
        lpc_demo_add_section(trim((string) $_POST['add_section_label']));
        header('Location: site_demos.php');
        exit;
    }
    if (!empty($_POST['remove_section_id'])) {
        lpc_demo_remove_section($_POST['remove_section_id']);
        header('Location: site_demos.php');
        exit;
    }
}

$layout = lpc_demo_layout($domains);
$notes = lpc_demo_display_data()['notes'];
$registry = wpb_registry();
$checkStatus = !empty($_GET['check_status']);
$status = $checkStatus ? lpc_demo_check_sites_status() : [];
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
.pc-demo-order{display:grid;grid-template-columns:1fr 1fr;gap:1px;margin-right:10px;}
.pc-demo-order button{border:none;background:none;cursor:pointer;color:#6b7280;font-size:.72rem;line-height:1.6;padding:1px 4px;}
.pc-demo-order button:disabled{opacity:.25;cursor:default;}
.pc-demo-note{border:1px solid var(--pc-line,#e2e8f2);border-radius:6px;font-size:.8rem;padding:3px 7px;width:180px;}
.pc-demo-section{display:flex;justify-content:space-between;align-items:center;padding:8px 0 6px;margin-top:6px;border-bottom:2px solid var(--pc-line,#e2e8f2);}
.pc-demo-section:first-child{margin-top:0;}
.pc-demo-section h4{margin:0;font-size:.78rem;text-transform:uppercase;letter-spacing:.04em;color:#6b7280;}
</style>

<div class="pc-card">
    <h3 style="margin-top:0;">Every site on the demo box (<?= count($domains) ?>)</h3>
    <p style="font-size:.84rem;color:#6b7280;margin:0 0 10px;">
        Built via <a href="wp_builder.php">WP Builder</a> or an older rehearsal site that predates it.
        <?php if (!$checkStatus): ?>
            Live theme + up/down status is a real SSH check against every site, ~1 minute — click only if you need it, not loaded automatically (it shares this box's resources with every real site on it, including pluscities.com itself).
            <a href="?check_status=1" class="btn-sm-outline" style="margin-left:6px;">Check live status (~1 min)</a>
        <?php else: ?>
            Live status checked just now. <a href="site_demos.php">Hide status</a>
        <?php endif; ?>
    </p>
    <form method="post" style="display:flex;gap:6px;align-items:center;margin-bottom:14px;">
        <input type="text" name="add_section_label" class="pc-demo-note" placeholder="New section name…">
        <button type="submit" class="btn-sm-outline">+ Add section</button>
    </form>
    <?php foreach ($layout as $i => $entry):
        $isFirst = $i === 0;
        $isLast = $i === count($layout) - 1;
        if (is_array($entry)): // section header
    ?>
        <div class="pc-demo-section">
            <h4><?= h($entry['section']) ?></h4>
            <div style="display:flex;align-items:center;gap:8px;">
                <?php site_demos_order_buttons('section', $entry['id'], $isFirst, $isLast) ?>
                <form method="post" onsubmit="return confirm('Remove this section header? Sites under it are kept.');">
                    <input type="hidden" name="remove_section_id" value="<?= h($entry['id']) ?>">
                    <button type="submit" class="btn-sm-danger">Remove</button>
                </form>
            </div>
        </div>
    <?php else:
        $domain = $entry;
        $profile = $registry[$domain]['profile'] ?? null;
        $info = $status[$domain] ?? null;
    ?>
        <div class="pc-demo-row">
            <?php site_demos_order_buttons('domain', $domain, $isFirst, $isLast) ?>
            <form method="post" style="display:flex;gap:6px;align-items:center;margin-right:10px;">
                <input type="hidden" name="note_domain" value="<?= h($domain) ?>">
                <input type="text" name="note" class="pc-demo-note" placeholder="Note…" value="<?= h($notes[$domain] ?? '') ?>">
                <button type="submit" class="btn-sm-outline">Save</button>
            </form>
            <div style="flex:1;">
                <a href="http://<?= h($domain) ?>/" target="_blank" rel="noopener"><strong><?= h($domain) ?></strong></a>
                <?php if ($profile): ?><span style="color:#6b7280;"> — <?= h(wpb_profiles()[$profile] ?? $profile) ?></span><?php endif; ?>
                <?php if ($info): ?><div style="font-size:.76rem;color:#6b7280;">theme: <?= h($info['theme']) ?></div><?php endif; ?>
            </div>
            <?php if ($info): ?>
                <span class="pc-demo-badge <?= $info['running'] ? 'pc-demo-up' : 'pc-demo-down' ?>">
                    <?= $info['running'] ? 'Up' : 'Down' ?>
                </span>
            <?php endif; ?>
        </div>
    <?php endif; endforeach; ?>
    <?php if (!$layout): ?><p style="color:#6b7280;font-size:.84rem;">No sites on the box yet.</p><?php endif; ?>
</div>
<?php lpc_admin_close(); ?>
