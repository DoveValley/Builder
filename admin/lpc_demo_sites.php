<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/lpc_demo_box.php';

if (empty($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}
// Internal testing tooling only — not something an agency manages, same gate
// as agencies.php/niche_profiles.php.
if (lpc_current_actor_role() !== 'super_admin') {
    header('Location: clients.php?msg=error:Demo+sites+are+managed+by+the+platform+only');
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

const LPC_DEMO_SHARED_LOGIN = ['user' => 'admin', 'pass' => 'demosite2026'];

// Every site, live from the box's own domain list (fast — no theme/running
// check here). Those are fetched on demand via the "Check Status" button,
// which calls lpc_demo_sites_status.php; see lpc_demo_check_sites_status().
$sites = lpc_demo_list_sites();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Demo Sites — <?= h(SITE_TITLE) ?></title>
<link rel="stylesheet" href="../assets/css/style.css">
<?php $_adminCssMtime = css_minify_to(__DIR__ . '/../assets/css/admin.src.css', __DIR__ . '/../assets/css/admin.css'); ?>
<link rel="stylesheet" href="../assets/css/admin.css?v=<?= (int) $_adminCssMtime ?>">
<style>
.lpc-wrap     { max-width: 1000px; margin: 0 auto; padding: 32px 24px 64px; }
.lpc-form-row { display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end; margin-top:14px; }
.lpc-form-row .form-group { margin:0; flex:1; min-width:150px; }
.lpc-form-row label { font-size:.82rem; font-weight:600; color:#374151; display:block; margin-bottom:4px; }
.lpc-form-row input, .lpc-form-row select { width:100%; padding:8px 10px; border:1px solid #d1d5db; border-radius:6px; font-size:.85rem; font-family:inherit; }
.lpc-row { background:#fff; border:1px solid #e5e7eb; border-radius:8px; padding:12px 16px; margin-bottom:8px; display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; }
.lpc-row .meta { font-size:.8rem; color:#6b7280; }
.lpc-status { font-size:.72rem; font-weight:700; text-transform:uppercase; letter-spacing:.03em; padding:2px 8px; border-radius:10px; }
.lpc-status-running  { background:#dcfce7; color:#166534; }
.lpc-status-stopped  { background:#fee2e2; color:#991b1b; }
.lpc-status-unknown  { background:#f1f5f9; color:#64748b; }
</style>
</head>
<body class="admin-body">
<div style="background:#1e293b; color:#fff; padding:14px 24px; display:flex; align-items:center; justify-content:space-between;">
    <div style="font-weight:700; font-size:1rem; letter-spacing:.01em;">&#9776; Site Factory</div>
    <a href="clients.php" style="color:#94a3b8; font-size:.82rem; text-decoration:none;">&larr; Landing Page Clients</a>
</div>

<div class="lpc-wrap">

    <?php if ($alert): ?>
        <div class="alert alert-<?= h($alert['type']) ?>"><?= h($alert['text']) ?></div>
    <?php endif; ?>

    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
        <h1 style="font-size:1.3rem;margin:0;">Demo Sites</h1>
        <button type="button" id="lpc-check-status-btn" class="btn-sm-outline" onclick="lpcCheckStatus(this)">Check Status</button>
    </div>
    <p style="color:#6b7280;font-size:.85rem;">
        Real WordPress installs on their own dedicated box (<?= h(LPC_DEMO_BOX_HOST) ?>), standing in
        for a real agency's "existing customer site" — used to rehearse agency onboarding, plugin
        pairing, and the full LPC workflow before taking on a real paying agency. Current domains are
        placeholders (<code>demo-*.q111.xyz</code>) pending real domains per site — until then, add the
        hosts-file line shown per row to your own machine to view a site in a normal browser.
        Every site shares the same WordPress login —
        <strong>admin / <?= h(LPC_DEMO_SHARED_LOGIN['pass']) ?></strong> — on purpose, so there's
        nothing to look up per site.
    </p>

    <?php foreach ($sites as $s): ?>
        <?php $hostsLine = LPC_DEMO_BOX_HOST . ' ' . $s['domain']; $hash = md5($s['domain']); ?>
        <div class="lpc-row" data-domain="<?= h($s['domain']) ?>">
            <div>
                <strong><?= h($s['domain']) ?></strong>
                <span class="lpc-status lpc-status-unknown" id="status-<?= h($hash) ?>">Not checked</span>
                <div class="meta">
                    niche: <?= h(ucwords(str_replace('-', ' ', $s['niche']))) ?> &middot; theme: <span id="theme-<?= h($hash) ?>">&mdash;</span>
                </div>
                <div class="meta" style="margin-top:6px;display:flex;align-items:center;gap:6px;">
                    <code id="hosts-<?= h($hash) ?>" style="background:#f3f4f6;padding:3px 8px;border-radius:4px;font-size:.78rem;"><?= h($hostsLine) ?></code>
                    <button type="button" class="btn-sm-outline" style="padding:2px 8px;font-size:.75rem;" onclick="lpcCopyLine('hosts-<?= h($hash) ?>', this)">Copy</button>
                    <span class="meta">add to your hosts file, then open http://<?= h($s['domain']) ?>/</span>
                </div>
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <a href="http://<?= h($s['domain']) ?>/wp-admin/" target="_blank" rel="noopener" class="btn-sm-outline lpc-running-only" id="login-<?= h($hash) ?>" style="display:none;">Log In</a>
                <a href="http://<?= h($s['domain']) ?>/" target="_blank" rel="noopener" class="btn-sm-outline lpc-running-only" id="view-<?= h($hash) ?>" style="display:none;">View Site</a>
                <form method="post" action="lpc_demo_sites_save.php" style="margin:0;">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                    <input type="hidden" name="action" value="redeploy_plugin">
                    <input type="hidden" name="domain" value="<?= h($s['domain']) ?>">
                    <button type="submit" class="btn-sm-outline">Redeploy Plugin</button>
                </form>
                <form method="post" action="lpc_demo_sites_save.php" style="margin:0;">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                    <input type="hidden" name="action" value="clear_cache">
                    <input type="hidden" name="domain" value="<?= h($s['domain']) ?>">
                    <button type="submit" class="btn-sm-outline">Clear Cache</button>
                </form>
                <form method="post" action="lpc_demo_sites_save.php" style="margin:0;" onsubmit="return confirm('Delete this demo site? This cannot be undone.');">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="domain" value="<?= h($s['domain']) ?>">
                    <button type="submit" class="btn-sm-danger">Delete</button>
                </form>
            </div>
        </div>
    <?php endforeach; ?>
    <?php if (!$sites): ?>
        <p style="color:#6b7280;font-size:.85rem;">No demo sites yet, or the demo box isn't reachable right now.</p>
    <?php endif; ?>

    <h3 style="margin-top:28px;">New Demo Site</h3>
    <form method="post" action="lpc_demo_sites_save.php" class="card">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="create">
        <div class="lpc-form-row">
            <div class="form-group">
                <label>Domain</label>
                <input type="text" name="domain" required placeholder="e.g. demo-landscaping.q111.xyz, or a real domain" pattern="[a-z0-9\-\.]+">
            </div>
            <div class="form-group">
                <label>Clone content from</label>
                <select name="source_niche" required>
                    <?php foreach ($sites as $s): ?>
                        <option value="<?= h($s['niche']) ?>"><?= h(ucwords(str_replace('-', ' ', $s['niche']))) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Theme <span style="font-weight:400;color:#6b7280;">(blank = keep cloned theme)</span></label>
                <select name="theme">
                    <option value="">Keep as cloned</option>
                    <option value="astra">Astra</option>
                    <option value="kadence">Kadence</option>
                    <option value="generatepress">GeneratePress</option>
                    <option value="oceanwp">OceanWP</option>
                    <option value="twentytwentyfour">Twenty Twenty-Four (block theme)</option>
                </select>
            </div>
        </div>
        <p class="meta" style="margin-top:8px;">
            New sites are cloned from an existing site's real content (not a blank install) — pick
            whichever niche is the closest starting point, then edit afterward through the WordPress
            admin like any other demo site.
        </p>
        <button type="submit" class="btn-open" style="margin-top:10px;">Create Demo Site</button>
    </form>

    <h3 id="template-review" style="margin-top:36px;">Premium Template Review</h3>
    <p class="meta" style="margin-bottom:12px;">
        Candidate replacements for the sites that came back "not great." Click a template name to view
        its live preview, then mark Yes/No — nothing gets rebuilt until you approve one.
    </p>
    <?php $verdicts = lpc_kadence_template_verdicts(); ?>
    <?php foreach (lpc_kadence_template_candidates() as $group => $templates): ?>
        <div class="card" style="margin-bottom:14px;">
            <strong><?= h($group) ?></strong>
            <?php foreach ($templates as $t): ?>
                <?php $v = $verdicts[$t['slug']] ?? null; ?>
                <div class="lpc-row" style="margin-top:8px;">
                    <div>
                        <a href="https://startertemplatecloud.com/<?= h($t['slug']) ?>/" target="_blank" rel="noopener"><?= h($t['name']) ?></a>
                        <span class="meta">&middot; <?= $t['pro'] ? 'Kadence Pro' : 'Free' ?> &middot; <?= (int) $t['pages'] ?> pages</span>
                        <?php if ($v): ?>
                            <span class="lpc-status lpc-status-<?= $v === 'yes' ? 'running' : 'stopped' ?>"><?= $v === 'yes' ? 'Approved' : 'Rejected' ?></span>
                        <?php endif; ?>
                    </div>
                    <div style="display:flex;gap:8px;">
                        <form method="post" action="lpc_demo_sites_save.php" style="margin:0;">
                            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                            <input type="hidden" name="action" value="template_verdict">
                            <input type="hidden" name="slug" value="<?= h($t['slug']) ?>">
                            <input type="hidden" name="verdict" value="yes">
                            <button type="submit" class="btn-sm-outline">Yes</button>
                        </form>
                        <form method="post" action="lpc_demo_sites_save.php" style="margin:0;">
                            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                            <input type="hidden" name="action" value="template_verdict">
                            <input type="hidden" name="slug" value="<?= h($t['slug']) ?>">
                            <input type="hidden" name="verdict" value="no">
                            <button type="submit" class="btn-sm-outline">No</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>

</div>
<script>
function lpcCopyLine(id, btn) {
    var text = document.getElementById(id).textContent;
    navigator.clipboard.writeText(text).then(function () {
        var original = btn.textContent;
        btn.textContent = 'Copied!';
        setTimeout(function () { btn.textContent = original; }, 1500);
    });
}

function lpcCheckStatus(btn) {
    var original = btn.textContent;
    btn.disabled = true;
    btn.textContent = 'Checking…';
    var body = new URLSearchParams();
    body.set('csrf_token', <?= json_encode($csrf) ?>);
    fetch('lpc_demo_sites_status.php', { method: 'POST', body: body })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (!data.ok) { alert(data.error || 'Check failed.'); return; }
            document.querySelectorAll('.lpc-row[data-domain]').forEach(function (row) {
                var domain = row.getAttribute('data-domain');
                var info = data.sites[domain];
                var hash = md5ForDomain(domain);
                if (!info) return;
                var statusEl = document.getElementById('status-' + hash);
                var themeEl = document.getElementById('theme-' + hash);
                var loginEl = document.getElementById('login-' + hash);
                var viewEl = document.getElementById('view-' + hash);
                if (statusEl) {
                    statusEl.textContent = info.running ? 'Running' : 'Not responding';
                    statusEl.className = 'lpc-status ' + (info.running ? 'lpc-status-running' : 'lpc-status-stopped');
                }
                if (themeEl) themeEl.textContent = info.theme || '—';
                if (loginEl) loginEl.style.display = info.running ? '' : 'none';
                if (viewEl) viewEl.style.display = info.running ? '' : 'none';
            });
        })
        .catch(function () { alert('Check failed — see console.'); })
        .finally(function () {
            btn.disabled = false;
            btn.textContent = original;
        });
}

// Matches PHP's md5() on the domain — used to key the per-row element ids
// rendered server-side, so results from the status check land on the right row.
var LPC_DOMAIN_HASHES = <?= json_encode(array_combine(array_column($sites, 'domain'), array_map(fn($s) => md5($s['domain']), $sites))) ?>;
function md5ForDomain(domain) {
    return LPC_DOMAIN_HASHES[domain] || '';
}
</script>
</body>
</html>
