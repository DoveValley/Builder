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

// Detect legacy site
$hasLegacy = file_exists(BASE_DIR . '/data/site.json')
    && trim((string) file_get_contents(BASE_DIR . '/data/site.json')) !== '';

// Load site list
function get_sites(): array {
    $dir = BASE_DIR . '/sites/';
    if (!is_dir($dir)) return [];
    $sites = [];
    foreach (scandir($dir) as $entry) {
        if ($entry[0] === '.') continue;
        if (!is_dir($dir . $entry)) continue;
        if (!preg_match('/^[a-z0-9][a-z0-9-]{0,59}$/', $entry)) continue;
        $metaFile = $dir . $entry . '/meta.json';
        $meta = file_exists($metaFile)
            ? (json_decode(file_get_contents($metaFile), true) ?? [])
            : [];
        $dataFile = $dir . $entry . '/data/site.json';
        $siteData = [];
        if (file_exists($dataFile)) {
            $siteData = json_decode(file_get_contents($dataFile), true) ?? [];
        }
        $updated = $meta['updated_at'] ?? '';
        if (!$updated && file_exists($dataFile)) $updated = date('c', filemtime($dataFile));
        $sites[] = [
            'id'         => $entry,
            'name'       => $meta['name'] ?? $entry,
            'created_at' => $meta['created_at'] ?? '',
            'updated_at' => $updated,
            'page_count' => count($siteData['pages'] ?? []),
            'post_count' => count($siteData['posts'] ?? []),
            'business'   => $siteData['local_business']['lb_name'] ?? '',
        ];
    }
    usort($sites, fn($a, $b) => strcmp($b['updated_at'], $a['updated_at']));
    return $sites;
}

$sites = get_sites();
$sitesExist = !empty($sites);

function fmt_date(string $iso): string {
    if (!$iso) return '—';
    $ts = strtotime($iso);
    return $ts ? date('M j, Y', $ts) : '—';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Site Manager — <?= h(SITE_TITLE) ?></title>
<link rel="stylesheet" href="../assets/css/style.css">
<style>
.sm-wrap        { max-width: 1100px; margin: 0 auto; padding: 32px 24px 64px; }
.sm-topbar      { display:flex; align-items:center; justify-content:space-between; margin-bottom:32px; flex-wrap:wrap; gap:12px; }
.sm-topbar h1   { font-size:1.4rem; font-weight:700; margin:0; }
.sm-grid        { display:grid; grid-template-columns:repeat(auto-fill,minmax(300px,1fr)); gap:20px; }
.site-card      { background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:22px 22px 18px; display:flex; flex-direction:column; gap:12px; transition:box-shadow .15s; }
.site-card:hover{ box-shadow:0 4px 18px rgba(0,0,0,.09); }
.site-card-name { font-size:1.1rem; font-weight:700; color:#111; margin:0; line-height:1.3; }
.site-card-biz  { font-size:.82rem; color:#6b7280; margin:0; }
.site-card-meta { display:flex; gap:16px; font-size:.78rem; color:#9ca3af; }
.site-card-meta span strong { color:#374151; }
.site-card-actions { display:flex; gap:8px; flex-wrap:wrap; margin-top:4px; }
.btn-open       { background:var(--color-accent,#fd783b); color:#fff; border:none; padding:7px 16px; border-radius:6px; font-size:.82rem; font-weight:600; cursor:pointer; text-decoration:none; display:inline-block; }
.btn-open:hover { opacity:.88; }
.btn-sm-outline { background:#fff; border:1px solid #d1d5db; color:#374151; padding:6px 12px; border-radius:6px; font-size:.8rem; font-weight:500; cursor:pointer; text-decoration:none; display:inline-block; }
.btn-sm-outline:hover { border-color:#9ca3af; background:#f9fafb; }
.btn-sm-danger  { background:#fff; border:1px solid #fca5a5; color:#dc2626; padding:6px 12px; border-radius:6px; font-size:.8rem; font-weight:500; cursor:pointer; }
.btn-sm-danger:hover { background:#fef2f2; }
.sm-empty       { text-align:center; padding:64px 24px; background:#fff; border:2px dashed #e5e7eb; border-radius:12px; }
.sm-empty h2    { font-size:1.3rem; margin:0 0 8px; color:#111; }
.sm-empty p     { color:#6b7280; margin:0 0 24px; }
/* New site form card */
.new-site-card  { background:#f8fafc; border:2px dashed #cbd5e1; border-radius:10px; padding:22px; display:none; }
.new-site-card.open { display:block; }
.new-site-card h3 { margin:0 0 16px; font-size:1rem; font-weight:700; }
.sm-form-row    { display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end; }
.sm-form-row .form-group { margin:0; flex:1; min-width:180px; }
.sm-form-row label { font-size:.82rem; font-weight:600; color:#374151; display:block; margin-bottom:4px; }
.sm-form-row input, .sm-form-row select { width:100%; padding:8px 10px; border:1px solid #d1d5db; border-radius:6px; font-size:.85rem; }
/* Legacy import banner */
.legacy-banner  { background:#fffbeb; border:1px solid #fbbf24; border-radius:8px; padding:14px 18px; display:flex; align-items:center; gap:16px; margin-bottom:24px; flex-wrap:wrap; }
.legacy-banner p { margin:0; font-size:.87rem; color:#92400e; flex:1; }
/* Rename inline */
.rename-form    { display:none; align-items:center; gap:8px; }
.rename-form.open { display:flex; }
.rename-form input { padding:5px 8px; border:1px solid #d1d5db; border-radius:5px; font-size:.85rem; width:160px; }
</style>
</head>
<body class="admin-body">
<div style="background:#1e293b; color:#fff; padding:14px 24px; display:flex; align-items:center; justify-content:space-between;">
    <div style="font-weight:700; font-size:1rem; letter-spacing:.01em;">&#9776; Homepage Builder</div>
    <a href="logout.php" style="color:#94a3b8; font-size:.82rem; text-decoration:none;">Log out</a>
</div>

<div class="sm-wrap">

    <?php if ($hasLegacy && !$sitesExist): ?>
    <div class="legacy-banner">
        <p><strong>Existing site found.</strong> You have a site in the legacy location. Import it to manage it here.</p>
        <button class="btn-open" onclick="showImport()">Import existing site &rarr;</button>
    </div>
    <div id="import-form" class="new-site-card" style="margin-bottom:24px;">
        <h3>Import existing site</h3>
        <div class="sm-form-row">
            <div class="form-group">
                <label>Site Name</label>
                <input type="text" id="import-name" value="My Site" placeholder="e.g. Katy Pest Pros">
            </div>
            <button class="btn-open" onclick="doImport()">Import</button>
            <button class="btn-sm-outline" onclick="cancelImport()">Cancel</button>
        </div>
    </div>
    <?php endif; ?>

    <div class="sm-topbar">
        <h1>Your Sites</h1>
        <button class="btn-open" onclick="showNewSite()">+ New Site</button>
    </div>

    <!-- New site form -->
    <div id="new-site-card" class="new-site-card" style="margin-bottom:24px;">
        <h3>New Site</h3>
        <div class="sm-form-row">
            <div class="form-group">
                <label>Site Name</label>
                <input type="text" id="new-name" placeholder="e.g. Sugar Land Pest Pros" oninput="clearNewErr()">
            </div>
            <?php if ($sitesExist): ?>
            <div class="form-group">
                <label>Start from</label>
                <select id="new-clone">
                    <option value="">Blank site</option>
                    <?php foreach ($sites as $s): ?>
                    <option value="<?= h($s['id']) ?>"><?= h($s['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <button class="btn-open" onclick="doCreate()">Create</button>
            <button class="btn-sm-outline" onclick="cancelNewSite()">Cancel</button>
        </div>
        <div id="new-err" style="color:#dc2626;font-size:.82rem;margin-top:8px;display:none;"></div>
    </div>

    <?php if (empty($sites)): ?>
    <div class="sm-empty">
        <h2>No sites yet</h2>
        <p>Create your first site to get started.</p>
        <button class="btn-open" onclick="showNewSite()">Create your first site</button>
    </div>
    <?php else: ?>
    <div class="sm-grid" id="sites-grid">
        <?php foreach ($sites as $s): ?>
        <div class="site-card" id="card-<?= h($s['id']) ?>">
            <div>
                <div style="display:flex; align-items:flex-start; justify-content:space-between; gap:8px;">
                    <p class="site-card-name" id="name-label-<?= h($s['id']) ?>"><?= h($s['name']) ?></p>
                    <button class="btn-sm-outline" style="flex-shrink:0;font-size:.75rem;padding:3px 8px;" onclick="startRename('<?= h($s['id']) ?>', '<?= h(addslashes($s['name'])) ?>')">Rename</button>
                </div>
                <div class="rename-form" id="rename-form-<?= h($s['id']) ?>">
                    <input type="text" id="rename-input-<?= h($s['id']) ?>" value="<?= h($s['name']) ?>">
                    <button class="btn-open" style="padding:5px 10px;font-size:.8rem;" onclick="doRename('<?= h($s['id']) ?>')">Save</button>
                    <button class="btn-sm-outline" style="padding:5px 8px;font-size:.8rem;" onclick="cancelRename('<?= h($s['id']) ?>')">Cancel</button>
                </div>
                <?php if ($s['business'] && $s['business'] !== $s['name']): ?>
                <p class="site-card-biz"><?= h($s['business']) ?></p>
                <?php endif; ?>
            </div>
            <div class="site-card-meta">
                <span><strong><?= $s['page_count'] ?></strong> pages</span>
                <?php if ($s['post_count']): ?><span><strong><?= $s['post_count'] ?></strong> posts</span><?php endif; ?>
                <span>Updated <?= h(fmt_date($s['updated_at'])) ?></span>
            </div>
            <div class="site-card-actions">
                <button class="btn-open" onclick="openSite('<?= h($s['id']) ?>')">Open &rarr;</button>
                <form method="post" action="site_api.php" style="display:contents;">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                    <input type="hidden" name="action" value="export">
                    <input type="hidden" name="site_id" value="<?= h($s['id']) ?>">
                    <button type="submit" class="btn-sm-outline">Export</button>
                </form>
                <button class="btn-sm-outline" onclick="cloneSite('<?= h($s['id']) ?>', '<?= h(addslashes($s['name'])) ?>')">Clone</button>
                <button class="btn-sm-danger" onclick="deleteSite('<?= h($s['id']) ?>', '<?= h(addslashes($s['name'])) ?>')">Delete</button>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

</div>

<script>
const CSRF = <?= json_encode($csrf) ?>;

async function post(data) {
    const fd = new FormData();
    fd.append('csrf_token', CSRF);
    for (const [k,v] of Object.entries(data)) fd.append(k, v);
    const r = await fetch('site_api.php', {method:'POST', body:fd});
    return r.json();
}

function showNewSite() {
    document.getElementById('new-site-card').classList.add('open');
    document.getElementById('new-name').focus();
}
function cancelNewSite() { document.getElementById('new-site-card').classList.remove('open'); }
function clearNewErr()   { document.getElementById('new-err').style.display='none'; }

async function doCreate() {
    const name = document.getElementById('new-name').value.trim();
    if (!name) { showErr('Site name is required'); return; }
    const cloneFrom = document.getElementById('new-clone')?.value ?? '';
    const res = await post({action:'create', name, clone_from: cloneFrom});
    if (res.error) { showErr(res.error); return; }
    window.location.reload();
}

function showErr(msg) {
    const el = document.getElementById('new-err');
    el.textContent = msg;
    el.style.display = 'block';
}

async function openSite(id) {
    const res = await post({action:'select', site_id: id});
    if (res.redirect) window.location.href = res.redirect;
}

async function cloneSite(id, name) {
    const newName = prompt('Name for the cloned site:', 'Copy of ' + name);
    if (!newName) return;
    const res = await post({action:'create', name: newName, clone_from: id});
    if (res.error) { alert('Error: ' + res.error); return; }
    window.location.reload();
}

async function deleteSite(id, name) {
    if (!confirm('Delete "' + name + '"?\n\nThis removes all pages, posts and uploaded images for this site. This cannot be undone.')) return;
    const res = await post({action:'delete', site_id: id});
    if (res.error) { alert('Error: ' + res.error); return; }
    document.getElementById('card-' + id)?.remove();
    // Show empty state if grid is now empty
    if (!document.querySelector('.site-card')) window.location.reload();
}

function startRename(id, currentName) {
    document.getElementById('name-label-' + id).style.display = 'none';
    document.getElementById('rename-form-' + id).classList.add('open');
    document.getElementById('rename-input-' + id).focus();
}
function cancelRename(id) {
    document.getElementById('name-label-' + id).style.display = '';
    document.getElementById('rename-form-' + id).classList.remove('open');
}
async function doRename(id) {
    const name = document.getElementById('rename-input-' + id).value.trim();
    if (!name) return;
    const res = await post({action:'rename', site_id: id, name});
    if (res.error) { alert('Error: ' + res.error); return; }
    document.getElementById('name-label-' + id).textContent = name;
    cancelRename(id);
}

function showImport() { document.getElementById('import-form').classList.add('open'); }
function cancelImport() { document.getElementById('import-form').classList.remove('open'); }
async function doImport() {
    const name = document.getElementById('import-name').value.trim() || 'My Site';
    const res = await post({action:'import', name});
    if (res.error) { alert('Error: ' + res.error); return; }
    window.location.reload();
}
</script>
</body>
</html>
