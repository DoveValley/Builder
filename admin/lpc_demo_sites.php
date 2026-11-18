<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

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

const LPC_DEMO_BASE = '/opt/landing-page-clients';
const LPC_DEMO_SHARED_LOGIN = ['user' => 'admin', 'pass' => 'demosite2026'];

// Discover every demo-* site on disk — the filesystem IS the source of
// truth (same reasoning as niche_library_list() elsewhere in this codebase:
// a flat-file registry can't drift from a separate index, because there
// isn't one).
$sites = [];
foreach (glob(LPC_DEMO_BASE . '/demo-*', GLOB_ONLYDIR) ?: [] as $dir) {
    $niche = substr(basename($dir), strlen('demo-'));
    $port  = trim((string) @file_get_contents($dir . '/.port'));
    $theme = trim((string) @file_get_contents($dir . '/.theme')) ?: '—';
    $running = false;
    if ($port !== '') {
        $conn = @fsockopen('127.0.0.1', (int) $port, $errno, $errstr, 0.3);
        if ($conn) { $running = true; fclose($conn); }
    }
    $apiKeySet = false;
    $siteUrl = $port !== '' ? "http://127.0.0.1:{$port}" : '';
    $sites[] = [
        'niche' => $niche, 'dir' => $dir, 'port' => $port, 'theme' => $theme,
        'running' => $running, 'url' => $siteUrl,
    ];
}
usort($sites, fn($a, $b) => strcmp($a['niche'], $b['niche']));
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

    <h1 style="font-size:1.3rem;">Demo Sites</h1>
    <p style="color:#6b7280;font-size:.85rem;">
        Local-only WordPress installs standing in for a real agency's "existing customer site" —
        used to rehearse agency onboarding, plugin pairing, and the full LPC workflow before taking
        on a real paying agency. Never exposed to the internet; reach one with
        <code>ssh -L PORT:localhost:PORT &lt;user&gt;@&lt;this box&gt;</code>, then open
        <code>http://localhost:PORT</code>. Every site shares the same WordPress login —
        <strong>admin / <?= h(LPC_DEMO_SHARED_LOGIN['pass']) ?></strong> — on purpose, so there's
        nothing to look up per site.
    </p>

    <?php foreach ($sites as $s): ?>
        <div class="lpc-row">
            <div>
                <strong><?= h(ucwords(str_replace('-', ' ', $s['niche']))) ?></strong>
                <span class="lpc-status lpc-status-<?= $s['running'] ? 'running' : 'stopped' ?>"><?= $s['running'] ? 'Running' : 'Stopped' ?></span>
                <div class="meta">
                    port <?= h($s['port'] ?: '—') ?> &middot; theme: <?= h($s['theme']) ?>
                </div>
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <?php if ($s['running']): ?>
                <a href="<?= h($s['url']) ?>/wp-admin/" target="_blank" rel="noopener" class="btn-sm-outline">Log In</a>
                <a href="<?= h($s['url']) ?>/" target="_blank" rel="noopener" class="btn-sm-outline">View Site</a>
                <?php endif; ?>
                <form method="post" action="lpc_demo_sites_save.php" style="margin:0;">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                    <input type="hidden" name="action" value="redeploy_plugin">
                    <input type="hidden" name="niche" value="<?= h($s['niche']) ?>">
                    <button type="submit" class="btn-sm-outline">Redeploy Plugin</button>
                </form>
                <form method="post" action="lpc_demo_sites_save.php" style="margin:0;">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                    <input type="hidden" name="action" value="clear_cache">
                    <input type="hidden" name="niche" value="<?= h($s['niche']) ?>">
                    <button type="submit" class="btn-sm-outline">Clear Cache</button>
                </form>
                <form method="post" action="lpc_demo_sites_save.php" style="margin:0;" onsubmit="return confirm('Delete this demo site? This cannot be undone.');">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="niche" value="<?= h($s['niche']) ?>">
                    <button type="submit" class="btn-sm-danger">Delete</button>
                </form>
            </div>
        </div>
    <?php endforeach; ?>
    <?php if (!$sites): ?>
        <p style="color:#6b7280;font-size:.85rem;">No demo sites yet — create one below.</p>
    <?php endif; ?>

    <h3 style="margin-top:28px;">New Demo Site</h3>
    <form method="post" action="lpc_demo_sites_save.php" class="card">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="create">
        <div class="lpc-form-row">
            <div class="form-group"><label>Niche slug</label><input type="text" name="niche" required placeholder="e.g. hvac, electrician" pattern="[a-z0-9\-]+"></div>
            <div class="form-group">
                <label>Theme <span style="font-weight:400;color:#6b7280;">(blank = auto-rotate)</span></label>
                <select name="theme">
                    <option value="">Auto-rotate</option>
                    <option value="astra">Astra</option>
                    <option value="kadence">Kadence</option>
                    <option value="generatepress">GeneratePress</option>
                    <option value="oceanwp">OceanWP</option>
                    <option value="twentytwentyfour">Twenty Twenty-Four (block theme)</option>
                </select>
            </div>
        </div>
        <button type="submit" class="btn-open" style="margin-top:10px;">Create Demo Site</button>
    </form>

</div>
</body>
</html>
