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
.wpb-form-row { display:flex; gap:12px; flex-wrap:wrap; margin-bottom:14px; }
.wpb-form-row .form-group { flex:1; min-width:200px; margin:0; }
.wpb-form-row label { font-size:.82rem; font-weight:600; color:#374151; display:block; margin-bottom:4px; }
.wpb-form-row input, .wpb-form-row textarea { width:100%; padding:8px 10px; border:1px solid #d1d5db; border-radius:6px; font-size:.85rem; font-family:inherit; box-sizing:border-box; }
.wpb-form-row input[type=color] { padding:2px; height:38px; }
.wpb-row { background:#fff; border:1px solid #e5e7eb; border-radius:8px; padding:12px 16px; margin-bottom:8px; display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; }
.wpb-row .meta { font-size:.8rem; color:#6b7280; }
.wpb-status { font-size:.72rem; font-weight:700; text-transform:uppercase; letter-spacing:.03em; padding:2px 8px; border-radius:10px; }
.wpb-status-running { background:#dcfce7; color:#166534; }
.wpb-status-stopped { background:#fee2e2; color:#991b1b; }
</style>
</head>
<body class="admin-body">
<div style="background:#1e293b; color:#fff; padding:14px 24px; display:flex; align-items:center; justify-content:space-between;">
    <div style="font-weight:700; font-size:1rem; letter-spacing:.01em;">&#9776; Site Factory</div>
    <a href="clients.php" style="color:#94a3b8; font-size:.82rem; text-decoration:none;">&larr; Landing Page Clients</a>
</div>

<div class="wpb-wrap">

    <?php if ($alert): ?>
        <div class="alert alert-<?= h($alert['type']) ?>"><?= h($alert['text']) ?></div>
    <?php endif; ?>

    <h1 style="font-size:1.3rem;">WP Builder</h1>
    <p style="color:#6b7280;font-size:.85rem;">
        Builds a plain, 5-page WordPress site (Home, About, Services, Testimonials, Contact) on the
        demo box from the fields below — no page-builder plugin, no imported template, just real
        core-block content with a simple header/footer and basic on-page SEO. Meant as a realistic
        "existing customer site" for rehearsing the LPC plugin-pairing workflow. Edit the content
        afterward in that site's own WordPress admin.
    </p>

    <form method="post" action="wp_builder_save.php" enctype="multipart/form-data" class="card" style="margin-top:20px;">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="create">

        <div class="wpb-form-row">
            <div class="form-group">
                <label>Domain</label>
                <input type="text" name="domain" required placeholder="e.g. demo-acme-plumbing.q111.xyz" pattern="[a-z0-9\-\.]+">
            </div>
        </div>

        <div class="wpb-tabs">
            <button type="button" class="wpb-tab-btn active" data-tab="info">Business Info</button>
            <button type="button" class="wpb-tab-btn" data-tab="design">Design</button>
        </div>

        <div class="wpb-tab-panel active" data-panel="info">
            <div class="wpb-form-row">
                <div class="form-group"><label>Business Name *</label><input type="text" name="business_name" required></div>
                <div class="form-group"><label>Niche / Industry *</label><input type="text" name="niche" required placeholder="e.g. plumbing"></div>
            </div>
            <div class="wpb-form-row">
                <div class="form-group"><label>Phone *</label><input type="text" name="phone" required></div>
                <div class="form-group"><label>City *</label><input type="text" name="city" required></div>
                <div class="form-group"><label>State *</label><input type="text" name="state" required maxlength="2" placeholder="TX"></div>
            </div>
            <div class="wpb-form-row">
                <div class="form-group"><label>Tagline <span style="font-weight:400;color:#9ca3af;">(blank = auto)</span></label><input type="text" name="tagline"></div>
                <div class="form-group"><label>Email <span style="font-weight:400;color:#9ca3af;">(blank = auto)</span></label><input type="text" name="email"></div>
            </div>
            <div class="wpb-form-row">
                <div class="form-group">
                    <label>Services <span style="font-weight:400;color:#9ca3af;">(comma-separated, blank = auto from niche)</span></label>
                    <input type="text" name="services" placeholder="Drain Cleaning, Water Heaters, Gas Lines">
                </div>
            </div>
            <div class="wpb-form-row">
                <div class="form-group">
                    <label>Testimonials <span style="font-weight:400;color:#9ca3af;">(one per line: Quote | Name | City — blank = sample quotes)</span></label>
                    <textarea name="testimonials" rows="3" placeholder="Fixed our water heater same day. | Donald Hunter | Downtown Lufkin"></textarea>
                </div>
            </div>
        </div>

        <div class="wpb-tab-panel" data-panel="design">
            <div class="wpb-form-row">
                <div class="form-group"><label>Primary Color (footer)</label><input type="color" name="primary_color" value="#1a2b49"></div>
                <div class="form-group"><label>Accent Color (buttons/links)</label><input type="color" name="accent_color" value="#2563eb"></div>
            </div>
            <div class="wpb-form-row">
                <div class="form-group"><label>Hero / Home Photo *</label><input type="file" name="hero_photo" accept="image/*" required></div>
                <div class="form-group"><label>About Photo <span style="font-weight:400;color:#9ca3af;">(blank = reuse hero photo)</span></label><input type="file" name="about_photo" accept="image/*"></div>
            </div>
        </div>

        <button type="submit" class="btn-open" style="margin-top:14px;">Create Site</button>
    </form>

    <h3 style="margin-top:32px;">Sites Built With WP Builder</h3>
    <?php foreach ($sites as $s): ?>
        <div class="wpb-row">
            <div>
                <strong><?= h($s['domain']) ?></strong>
                <span class="wpb-status wpb-status-<?= $s['running'] ? 'running' : 'stopped' ?>"><?= $s['running'] ? 'Running' : 'Not responding' ?></span>
                <div class="meta">
                    <?= h($s['business_name'] ?? '') ?> &middot; <?= h($s['niche'] ?? '') ?> &middot; <?= h($s['city'] ?? '') ?>, <?= h($s['state'] ?? '') ?>
                </div>
                <?php $hostsLine = LPC_DEMO_BOX_HOST . ' ' . $s['domain']; ?>
                <div class="meta" style="margin-top:6px;display:flex;align-items:center;gap:6px;">
                    <code id="wpb-hosts-<?= h(md5($s['domain'])) ?>" style="background:#f3f4f6;padding:3px 8px;border-radius:4px;font-size:.78rem;"><?= h($hostsLine) ?></code>
                    <button type="button" class="btn-sm-outline" style="padding:2px 8px;font-size:.75rem;" onclick="wpbCopyLine('wpb-hosts-<?= h(md5($s['domain'])) ?>', this)">Copy</button>
                    <span class="meta">add to your hosts file to view it in a normal browser</span>
                </div>
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
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
document.querySelectorAll('.wpb-tab-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
        document.querySelectorAll('.wpb-tab-btn').forEach(function (b) { b.classList.remove('active'); });
        document.querySelectorAll('.wpb-tab-panel').forEach(function (p) { p.classList.remove('active'); });
        btn.classList.add('active');
        document.querySelector('.wpb-tab-panel[data-panel="' + btn.dataset.tab + '"]').classList.add('active');
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
