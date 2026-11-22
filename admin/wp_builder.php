<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/wp_builder.php';

if (empty($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}
if (lpc_current_actor_role() !== 'super_admin') {
    header('Location: clients.php?msg=error:WP+Builder+is+managed+by+the+platform+only');
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

$sites = wpb_list_sites();
$profileKeys = array_keys(wpb_profiles());

// Editing an existing site pre-fills the form from its stored registry entry
// and locks domain+profile (neither can change after creation) instead of
// showing the full picker.
$editDomain = isset($_GET['edit']) ? (string) $_GET['edit'] : '';
$editEntry = $editDomain !== '' ? (wpb_registry()[$editDomain] ?? null) : null;
$isEdit = $editEntry !== null;
$editProfileKey = $isEdit ? ($editEntry['profile'] ?? 'local-business') : '';

/** Renders one <input>/<textarea> for a profile field, matching the field's declared type, optionally pre-filled. */
function wpb_admin_render_field(string $name, array $cfg, string $value = ''): string
{
    $label = h($cfg['label'] ?? ucwords(str_replace('_', ' ', $name)));
    $mark = !empty($cfg['required']) ? ' *' : '';
    $hint = isset($cfg['hint']) ? ' <span style="font-weight:400;color:#9ca3af;">(' . h($cfg['hint']) . ')</span>' : '';
    $required = !empty($cfg['required']) ? ' required' : '';
    $placeholder = isset($cfg['placeholder']) ? ' placeholder="' . h($cfg['placeholder']) . '"' : '';
    $maxlength = isset($cfg['maxlength']) ? ' maxlength="' . (int) $cfg['maxlength'] . '"' : '';
    $type = $cfg['type'] ?? 'text';

    if ($type === 'textarea') {
        $rows = (int) ($cfg['rows'] ?? 3);
        $input = '<textarea name="' . h($name) . '" rows="' . $rows . '"' . $placeholder . '>' . h($value) . '</textarea>';
    } elseif ($type === 'color') {
        $v = h($value !== '' ? $value : ($cfg['default'] ?? '#000000'));
        $input = '<input type="color" name="' . h($name) . '" value="' . $v . '">';
    } else {
        $input = '<input type="text" name="' . h($name) . '"' . $required . $placeholder . $maxlength . ' value="' . h($value) . '">';
    }

    return '<div class="form-group wpb-form-row"><label>' . $label . $mark . $hint . '</label>' . $input . '</div>';
}

/** A file input can never be pre-filled (browsers block it) — on edit, show what's already on file and make the upload optional, since leaving it blank keeps the current photo. */
function wpb_admin_render_photo_field(array $cfg, string $currentUrl = '', bool $isEdit = false): string
{
    $label = h($cfg['label'] ?? 'Photo');
    $required = (!$isEdit && !empty($cfg['required_ui'])) ? ' required' : '';
    $mark = $required ? ' *' : '';
    $hintText = $isEdit ? 'leave blank to keep the current photo' : ($cfg['hint'] ?? '');
    $hint = $hintText ? ' <span style="font-weight:400;color:#9ca3af;">(' . h($hintText) . ')</span>' : '';
    $field = h($cfg['field']);
    $current = $currentUrl !== '' ? '<div class="meta" style="margin-bottom:4px;"><a href="' . h($currentUrl) . '" target="_blank" rel="noopener">Current photo &rarr;</a></div>' : '';
    return '<div class="form-group wpb-form-row"><label>' . $label . $mark . $hint . '</label>' . $current . '<input type="file" name="' . $field . '" accept="image/*"' . $required . '></div>';
}

/** Renders one field or photo-field by key, looking it up in whichever bucket of the profile it belongs to. */
function wpb_admin_render_tab_field(array $profile, string $key, array $values = [], bool $isEdit = false): string
{
    if (isset($profile['fields'][$key])) {
        return wpb_admin_render_field($key, $profile['fields'][$key], (string) ($values[$key] ?? ''));
    }
    if (isset($profile['photos'][$key])) {
        $currentUrl = $values[$key . '_photo_url'] ?? '';
        return wpb_admin_render_photo_field($profile['photos'][$key], (string) $currentUrl, $isEdit);
    }
    return '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>WP Builder — <?= h(SITE_TITLE) ?></title>
<link rel="stylesheet" href="../assets/css/style.css">
<?php $_adminCssMtime = css_minify_to(__DIR__ . '/../assets/css/admin.src.css', __DIR__ . '/../assets/css/admin.css'); ?>
<link rel="stylesheet" href="../assets/css/admin.css?v=<?= (int) $_adminCssMtime ?>">
<style>
.wpb-wrap     { max-width: 1000px; margin: 0 auto; padding: 32px 24px 64px; }
.wpb-tabs     { display:flex; gap:4px; border-bottom:2px solid #e5e7eb; margin-bottom:20px; }
.wpb-tab-btn  { padding:10px 18px; border:none; background:none; cursor:pointer; font-size:.9rem; font-weight:600; color:#6b7280; border-bottom:2px solid transparent; margin-bottom:-2px; }
.wpb-tab-btn.active { color:#1e293b; border-bottom-color:#2563eb; }
.wpb-tab-panel { display:none; }
.wpb-tab-panel.active { display:block; }
.wpb-profile-block { display:none; }
.wpb-profile-block.active { display:block; }
.wpb-form-row { margin-bottom:14px; max-width:460px; }
.wpb-form-row label { font-size:.82rem; font-weight:600; color:#374151; display:block; margin-bottom:4px; }
.wpb-form-row input, .wpb-form-row textarea { width:100%; padding:8px 10px; border:1px solid #d1d5db; border-radius:6px; font-size:.85rem; font-family:inherit; box-sizing:border-box; }
.wpb-form-row input[type=color] { padding:2px; height:38px; }
.wpb-profile-select { font-size:.9rem; font-weight:600; padding:8px 10px; border:1px solid #d1d5db; border-radius:6px; margin-bottom:6px; }
.wpb-profile-desc { color:#6b7280; font-size:.85rem; margin:0 0 20px; }
.wpb-row { background:#fff; border:1px solid #e5e7eb; border-radius:8px; padding:12px 16px; margin-bottom:8px; display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; }
.wpb-row .meta { font-size:.8rem; color:#6b7280; }
.wpb-status { font-size:.72rem; font-weight:700; text-transform:uppercase; letter-spacing:.03em; padding:2px 8px; border-radius:10px; }
.wpb-status-running { background:#dcfce7; color:#166534; }
.wpb-status-stopped { background:#fee2e2; color:#991b1b; }
.wpb-profile-tag { font-size:.7rem; font-weight:700; text-transform:uppercase; letter-spacing:.03em; padding:2px 7px; border-radius:8px; background:#e0e7ff; color:#3730a3; }
</style>
</head>
<body class="admin-body">
<div style="background:#1e293b; color:#fff; padding:14px 24px; display:flex; align-items:center; justify-content:space-between;">
    <div style="font-weight:700; font-size:1rem; letter-spacing:.01em;">&#9776; Site Factory</div>
    <a href="pluscities_clients.php" style="color:#94a3b8; font-size:.82rem; text-decoration:none;">&larr; PlusCities</a>
</div>

<div class="wpb-wrap">

    <?php if ($alert): ?>
        <div class="alert alert-<?= h($alert['type']) ?>"><?= h($alert['text']) ?></div>
    <?php endif; ?>

    <h1 style="font-size:1.3rem;"><?= $isEdit ? 'Edit ' . h($editDomain) : 'WP Builder' ?></h1>
    <?php if ($isEdit): ?>
    <p style="color:#6b7280;font-size:.85rem;">
        Editing goes through the same build the site was created with — blank fields keep their
        current value, and photos only change if you upload a new one.
        <a href="wp_builder.php">&larr; Back to all sites</a>
    </p>
    <?php else: ?>
    <p style="color:#6b7280;font-size:.85rem;">
        Builds a real WordPress site on the demo box from a recipe ("profile") — pick one below. No
        page-builder plugin, no imported template, just plain core-block content, so every site stays
        editable in the normal block editor afterward.
    </p>
    <?php endif; ?>

    <form method="post" action="wp_builder_save.php" enctype="multipart/form-data" class="card" style="margin-top:20px;">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="<?= $isEdit ? 'update' : 'create' ?>">

        <div class="wpb-form-row">
            <label>Domain</label>
            <?php if ($isEdit): ?>
                <input type="text" name="domain" value="<?= h($editDomain) ?>" readonly style="background:#f3f4f6;">
            <?php else: ?>
                <input type="text" name="domain" required placeholder="e.g. demo-acme-plumbing.q111.xyz" pattern="[a-z0-9\-\.]+">
            <?php endif; ?>
        </div>

        <div class="wpb-form-row">
            <label>Profile</label>
            <?php if ($isEdit): ?>
                <input type="hidden" name="profile" value="<?= h($editProfileKey) ?>">
                <input type="text" value="<?= h(wpb_profiles()[$editProfileKey] ?? $editProfileKey) ?>" disabled style="background:#f3f4f6;">
            <?php else: ?>
                <select name="profile" id="wpb-profile-select" class="wpb-profile-select">
                    <?php foreach (wpb_profiles() as $key => $label): ?>
                        <option value="<?= h($key) ?>"><?= h($label) ?></option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>
        </div>

        <?php foreach ($profileKeys as $i => $profileKey):
            if ($isEdit && $profileKey !== $editProfileKey) continue;
            $profile = wpb_get_profile($profileKey);
            $values = $isEdit ? $editEntry : [];
            $showActive = $isEdit || $i === 0;
        ?>
        <div class="wpb-profile-block<?= $showActive ? ' active' : '' ?>" data-profile="<?= h($profileKey) ?>">
            <p class="wpb-profile-desc"><?= h($profile['description'] ?? '') ?></p>

            <?php if (!empty($profile['tabs'])): ?>
            <div class="wpb-tabs">
                <?php foreach ($profile['tabs'] as $ti => $tab): ?>
                    <button type="button" class="wpb-tab-btn<?= $ti === 0 ? ' active' : '' ?>" data-tab="<?= h($profileKey . ':' . $ti) ?>"><?= h($tab['label']) ?></button>
                <?php endforeach; ?>
            </div>
            <?php foreach ($profile['tabs'] as $ti => $tab): ?>
                <div class="wpb-tab-panel<?= $ti === 0 ? ' active' : '' ?>" data-panel="<?= h($profileKey . ':' . $ti) ?>">
                    <?php foreach ($tab['fields'] as $fieldKey): ?>
                        <?= wpb_admin_render_tab_field($profile, $fieldKey, $values, $isEdit) ?>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
            <?php else: ?>
                <?php foreach (array_keys($profile['fields']) as $fieldKey): ?>
                    <?= wpb_admin_render_tab_field($profile, $fieldKey, $values, $isEdit) ?>
                <?php endforeach; ?>
                <?php foreach (array_keys($profile['photos'] ?? []) as $photoKey): ?>
                    <?= wpb_admin_render_tab_field($profile, $photoKey, $values, $isEdit) ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>

        <button type="submit" class="btn-open" style="margin-top:14px;"><?= $isEdit ? 'Save Changes' : 'Create Site' ?></button>
    </form>

    <h3 style="margin-top:32px;">Sites Built With WP Builder</h3>
    <?php foreach ($sites as $s): ?>
        <div class="wpb-row">
            <div>
                <strong><?= h($s['domain']) ?></strong>
                <span class="wpb-status wpb-status-<?= $s['running'] ? 'running' : 'stopped' ?>"><?= $s['running'] ? 'Running' : 'Not responding' ?></span>
                <span class="wpb-profile-tag"><?= h(wpb_profiles()[$s['profile'] ?? 'local-business'] ?? ($s['profile'] ?? 'local-business')) ?></span>
                <div class="meta">
                    <?= h($s['business_name'] ?? '') ?> &middot; <?= h($s['niche'] ?? '') ?> &middot; <?= h($s['city'] ?? '') ?><?= !empty($s['state']) ? ', ' . h($s['state']) : '' ?>
                </div>
                <?php $hostsLine = LPC_DEMO_BOX_HOST . ' ' . $s['domain']; ?>
                <div class="meta" style="margin-top:6px;display:flex;align-items:center;gap:6px;">
                    <code id="wpb-hosts-<?= h(md5($s['domain'])) ?>" style="background:#f3f4f6;padding:3px 8px;border-radius:4px;font-size:.78rem;"><?= h($hostsLine) ?></code>
                    <button type="button" class="btn-sm-outline" style="padding:2px 8px;font-size:.75rem;" onclick="wpbCopyLine('wpb-hosts-<?= h(md5($s['domain'])) ?>', this)">Copy</button>
                    <span class="meta">add to your hosts file to view it in a normal browser</span>
                </div>
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <a href="wp_builder.php?edit=<?= rawurlencode($s['domain']) ?>" class="btn-sm-outline">Edit</a>
                <?php if ($s['running']): ?>
                <a href="wp_builder_preview.php?domain=<?= rawurlencode($s['domain']) ?>&path=/" target="_blank" rel="noopener" class="btn-sm-outline">Preview</a>
                <a href="http://<?= h($s['domain']) ?>/wp-admin/" target="_blank" rel="noopener" class="btn-sm-outline">Log In</a>
                <a href="http://<?= h($s['domain']) ?>/" target="_blank" rel="noopener" class="btn-sm-outline">View Site</a>
                <?php endif; ?>
                <form method="post" action="wp_builder_save.php" style="margin:0;" onsubmit="return confirm('Delete this site? This cannot be undone.');">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="domain" value="<?= h($s['domain']) ?>">
                    <button type="submit" class="btn-sm-danger">Delete</button>
                </form>
            </div>
        </div>
    <?php endforeach; ?>
    <?php if (!$sites): ?>
        <p style="color:#6b7280;font-size:.85rem;">No sites built with this tool yet.</p>
    <?php endif; ?>

</div>
<script>
var profileSelect = document.getElementById('wpb-profile-select');
function wpbShowProfile(key) {
    document.querySelectorAll('.wpb-profile-block').forEach(function (b) {
        b.classList.toggle('active', b.dataset.profile === key);
    });
}
if (profileSelect) {
    profileSelect.addEventListener('change', function () { wpbShowProfile(this.value); });
    wpbShowProfile(profileSelect.value);
}

document.querySelectorAll('.wpb-tab-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var block = btn.closest('.wpb-profile-block');
        block.querySelectorAll('.wpb-tab-btn').forEach(function (b) { b.classList.remove('active'); });
        block.querySelectorAll('.wpb-tab-panel').forEach(function (p) { p.classList.remove('active'); });
        btn.classList.add('active');
        block.querySelector('.wpb-tab-panel[data-panel="' + btn.dataset.tab + '"]').classList.add('active');
    });
});

function wpbCopyLine(id, btn) {
    var text = document.getElementById(id).textContent;
    navigator.clipboard.writeText(text).then(function () {
        var original = btn.textContent;
        btn.textContent = 'Copied!';
        setTimeout(function () { btn.textContent = original; }, 1500);
    });
}
</script>
</body>
</html>
