<?php
/**
 * admin/pluscities_team.php — the "Team" page from
 * docs/PLUSCITIES_FEATURE_SPEC.md Section 10: Agency-Admin-only management
 * of real per-person Agency Editor accounts (includes/pluscities_users.php),
 * replacing the one-shared-login model for that role. Also where the
 * Agency-Admin-only "can Editors publish" toggle lives (spec's own
 * conservative default — off until the Admin opts in).
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/lpc_admin_layout.php';

if (!lpc_actor_is_authenticated() || lpc_current_actor_role() !== 'agency_admin') {
    header('Location: clients.php?msg=error:Team+management+is+for+Agency+Admins+only');
    exit;
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];
$agencyId = lpc_current_actor_agency_id();
$agency = lpc_load_agency($agencyId);

$alert = null;
if (!empty($_GET['msg'])) {
    $raw = $_GET['msg'];
    if (strpos($raw, ':') !== false) {
        [$type, $text] = explode(':', $raw, 2);
        if (in_array($type, ['success', 'error', 'warning'], true)) $alert = ['type' => $type, 'text' => $text];
    }
}

$editing = !empty($_GET['edit']) ? pluscities_user_load($_GET['edit']) : null;
$users = pluscities_users_for_agency($agencyId);
$clients = pluscities_client_ids_for_current_actor(); // agency_admin => every one of the agency's own clients
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Team — <?= h(SITE_TITLE) ?></title>
<link rel="stylesheet" href="../assets/css/style.css">
<?php $_adminCssMtime = css_minify_to(__DIR__ . '/../assets/css/admin.src.css', __DIR__ . '/../assets/css/admin.css'); ?>
<link rel="stylesheet" href="../assets/css/admin.css?v=<?= (int) $_adminCssMtime ?>">
<?php lpc_admin_head_assets(); ?>
</head>
<?php
lpc_admin_open('pluscities_team', 'Team');
?>
<style>
.pc-card{background:#fff;border:1px solid var(--pc-line,#e2e8f2);border-radius:14px;padding:18px 20px;margin-bottom:16px;}
.pc-form-row{display:flex;gap:12px;flex-wrap:wrap;margin-top:10px;}
.pc-form-row .fg{flex:1;min-width:160px;}
.pc-form-row label{font-size:.8rem;font-weight:600;color:#374151;display:block;margin-bottom:4px;}
.pc-form-row input,.pc-form-row select{width:100%;padding:7px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:.84rem;font-family:inherit;}
.pc-row{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:10px 0;border-bottom:1px solid var(--pc-line,#e2e8f2);}
.pc-row:last-child{border-bottom:none;}
.pc-checklist label{display:block;font-size:.82rem;font-weight:400;margin:3px 0;}
</style>

<?php if ($alert): ?><div class="alert alert-<?= h($alert['type']) ?>"><?= h($alert['text']) ?></div><?php endif; ?>

<div class="pc-card">
    <h3 style="margin-top:0;">Publishing</h3>
    <p style="color:var(--pc-muted,#52607a);font-size:.84rem;">
        Agency Editors can always edit, lock, regenerate, swap, and approve. Publishing a page live is a
        separate, conservative default (off) — turn it on here once you trust your team with it.
    </p>
    <form method="post" action="pluscities_team_save.php" style="display:flex;align-items:center;gap:10px;">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="toggle_editor_publish">
        <input type="checkbox" name="editor_can_publish" value="1" <?= !empty($agency['editor_can_publish']) ? 'checked' : '' ?>
            onchange="this.form.submit()" id="editor-publish-toggle">
        <label for="editor-publish-toggle" style="font-size:.86rem;">Agency Editors can publish pages live</label>
    </form>
</div>

<div class="pc-card">
    <h3 style="margin-top:0;">Team Members</h3>
    <?php foreach ($users as $u): ?>
        <div class="pc-row">
            <div>
                <strong><?= h($u['name'] ?: $u['username']) ?></strong>
                <div style="font-size:.78rem;color:var(--pc-muted,#52607a);">
                    <?= h($u['username']) ?> · <?= h($u['email']) ?> ·
                    <?= $u['assigned_client_ids'] ? count($u['assigned_client_ids']) . ' assigned client(s)' : 'all clients' ?>
                </div>
            </div>
            <div style="display:flex;gap:8px;">
                <a href="?edit=<?= h($u['user_id']) ?>" class="btn-sm-outline">Edit</a>
                <form method="post" action="pluscities_team_save.php" onsubmit="return confirm('Remove this team member?');">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="user_id" value="<?= h($u['user_id']) ?>">
                    <button type="submit" class="btn-sm-danger">Remove</button>
                </form>
            </div>
        </div>
    <?php endforeach; ?>
    <?php if (!$users): ?><p style="color:var(--pc-muted,#52607a);font-size:.84rem;">No team members yet.</p><?php endif; ?>

    <h4 style="margin-top:18px;"><?= $editing ? 'Edit' : 'Add a' ?> Team Member</h4>
    <form method="post" action="pluscities_team_save.php" class="pc-form-row">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="save">
        <?php if ($editing): ?><input type="hidden" name="existing_user_id" value="<?= h($editing['user_id']) ?>"><?php endif; ?>
        <div class="fg"><label>Name</label><input type="text" name="name" required value="<?= h($editing['name'] ?? '') ?>"></div>
        <div class="fg"><label>Email</label><input type="email" name="email" value="<?= h($editing['email'] ?? '') ?>"></div>
        <div class="fg"><label>Username</label><input type="text" name="username" required value="<?= h($editing['username'] ?? '') ?>"></div>
        <div class="fg"><label>Password <?= $editing ? '(leave blank to keep current)' : '' ?></label>
            <input type="password" name="password" <?= $editing ? '' : 'required' ?> autocomplete="new-password"></div>

        <div class="fg" style="flex-basis:100%;">
            <label>Assigned clients (none checked = sees all of the agency's clients)</label>
            <div class="pc-checklist">
                <?php foreach ($clients as $cid => $c): ?>
                    <label>
                        <input type="checkbox" name="assigned_client_ids[]" value="<?= h($cid) ?>"
                            <?= in_array($cid, $editing['assigned_client_ids'] ?? [], true) ? 'checked' : '' ?>>
                        <?= h($c['identity']['business_name'] ?? $cid) ?>
                    </label>
                <?php endforeach; ?>
                <?php if (!$clients): ?><span style="color:var(--pc-muted,#52607a);font-size:.8rem;">No clients yet.</span><?php endif; ?>
            </div>
        </div>
        <div style="flex-basis:100%;">
            <button type="submit" class="btn-open"><?= $editing ? 'Save Changes' : 'Add Team Member' ?></button>
            <?php if ($editing): ?><a href="pluscities_team.php" class="btn-sm-outline">Cancel</a><?php endif; ?>
        </div>
    </form>
</div>

<?php lpc_admin_close(); ?>
