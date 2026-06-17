<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

// Require login
if (empty($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}

// Require an active site to be selected
if (!ACTIVE_SITE_ID) {
    header('Location: sites.php');
    exit;
}

// Ensure CSRF token exists (in case session was started before login.php generated one)
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

// Detect a corrupted data file before load_data() silently falls back to defaults
$dataFileCorrupt = false;
if (file_exists(DATA_FILE)) {
    $rawJson = file_get_contents(DATA_FILE);
    if (trim((string)$rawJson) !== '' && !is_array(json_decode($rawJson, true))) {
        $dataFileCorrupt = true;
    }
}

$data    = load_data();
$theme    = $data['theme'];
$header   = $data['header'];
$siteVars = $data['site_vars'];
$blocks   = $data['content_blocks'];
$footer   = $data['footer'];
$seo      = $data['seo'];
$pages   = $data['pages'];
$posts   = $data['posts'];
$blogSettings = $data['blog_settings'];

// Active tab
$tab = $_GET['tab'] ?? 'header';
if (!in_array($tab, ['header', 'theme', 'content', 'pages', 'templates', 'cities', 'blog', 'footer', 'popups', 'media', 'seo', 'schedule'], true)) {
    $tab = 'header';
}

// If on the Landing Pages tab, are we viewing the list or editing one page?
$editingPageId = null;
$editingPage   = null;
if ($tab === 'pages' && !empty($_GET['page']) && isset($pages[$_GET['page']])) {
    $editingPageId = $_GET['page'];
    $editingPage   = $pages[$editingPageId];
}

// If on the Templates tab, are we viewing the list or editing one template?
$templates          = [];
$editingTemplateId  = null;
$editingTemplate    = null;
if (file_exists(TEMPLATES_FILE)) {
    $raw = json_decode(file_get_contents(TEMPLATES_FILE), true);
    $templates = is_array($raw) ? $raw : [];
}
if ($tab === 'templates' && !empty($_GET['template'])) {
    foreach ($templates as $tpl) {
        if ($tpl['id'] === $_GET['template']) {
            $editingTemplateId = $tpl['id'];
            $editingTemplate   = $tpl;
            break;
        }
    }
}

// Load cities; detect editing state for Cities tab
$cities        = [];
$editingCityId = null;
$editingCity   = null;
if (file_exists(CITIES_FILE)) {
    $raw = json_decode(file_get_contents(CITIES_FILE), true);
    $cities = is_array($raw) ? $raw : [];
}
if ($tab === 'cities' && !empty($_GET['city'])) {
    foreach ($cities as $c) {
        if ($c['id'] === $_GET['city']) {
            $editingCityId = $c['id'];
            $editingCity   = $c;
            break;
        }
    }
}

// If on the Blog tab, are we viewing the list or editing one post?
$editingPostId = null;
$editingPost   = null;
if ($tab === 'blog' && !empty($_GET['post']) && isset($posts[$_GET['post']])) {
    $editingPostId = $_GET['post'];
    $editingPost   = $posts[$editingPostId];
}

// Flash message (format: "success:..." or "error:...")
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

// Work out the next free numeric index for footer columns / links,
// so JS can add new ones without colliding with existing keys.
$nextColumnIndex = 0;
$columnNextLinkIndex = [];
foreach ($footer['columns'] as $ci => $column) {
    $ci = (int) $ci;
    if ($ci >= $nextColumnIndex) {
        $nextColumnIndex = $ci + 1;
    }
    $nextLink = 0;
    foreach (($column['links'] ?? []) as $li => $link) {
        $li = (int) $li;
        if ($li >= $nextLink) {
            $nextLink = $li + 1;
        }
    }
    $columnNextLinkIndex[$ci] = $nextLink;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel - <?= h(SITE_TITLE) ?></title>
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= (int) @filemtime(__DIR__ . '/../assets/css/style.css') ?>">
    <script src="https://cdn.tiny.cloud/1/qeuo7izgoglstixfe9merx5vdkfu7nfuvl1nhyc98p6qej0p/tinymce/6/tinymce.min.js" referrerpolicy="origin"></script>
    <script>
    const TINYMCE_OPTS = {
        menubar: false,
        plugins: 'link lists autolink',
        toolbar: 'bold italic underline | link | bullist numlist | removeformat',
        height: 240,
        branding: false,
        promotion: false,
        statusbar: false,
        link_default_target: '_blank',
        link_assume_external_targets: true,
        skin: 'oxide',
        content_css: false,
        content_style: 'body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; font-size: 14px; color: #1a1a1a; margin: 8px; }',
        setup: function(editor) {
            editor.on('change input', function() { editor.save(); });
        }
    };
    tinymce.init(Object.assign({ selector: '.rich-editor' }, TINYMCE_OPTS));

    function initRichEditors(root) {
        (root || document).querySelectorAll('textarea.rich-editor').forEach(function(ta) {
            if (!ta.id) ta.id = 'mce_' + Date.now() + '_' + Math.random().toString(36).slice(2, 7);
            if (!tinymce.get(ta.id)) {
                tinymce.init(Object.assign({ selector: '#' + ta.id }, TINYMCE_OPTS));
            }
        });
    }
    </script>
    <script>
    // Inject CSRF token into every form on submit
    const CSRF_TOKEN = <?= json_encode($csrfToken) ?>;
    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('form[action="save.php"]').forEach(function(form) {
            form.addEventListener('submit', function() {
                // CSRF token
                var inp = form.querySelector('input[name="csrf_token"]');
                if (!inp) {
                    inp = document.createElement('input');
                    inp.type = 'hidden';
                    inp.name = 'csrf_token';
                    form.appendChild(inp);
                }
                inp.value = CSRF_TOKEN;
            });
        });
    });
    </script>
</head>
<body class="admin-body">
<div class="admin-wrapper">

    <div class="admin-header">
        <h1>
            <a href="sites.php" style="color:inherit;text-decoration:none;font-size:0.75em;opacity:0.7;margin-right:10px;" title="All Sites">&#8592; Sites</a>
            <?= h($data['local_business']['lb_name'] ?? SITE_TITLE) ?>
        </h1>
        <div>
            <a href="../index.php" target="_blank" class="preview-link">View site &rarr;</a>
            &nbsp;|&nbsp;
            <a href="logout.php">Log out</a>
        </div>
    </div>

    <?php if ($dataFileCorrupt): ?>
        <div class="alert alert-error">
            &#9888; <strong>Warning:</strong> <?= h(basename(DATA_FILE)) ?> could not be read as valid data and the site is currently showing default placeholder content instead of your saved content. Do not make further changes until this is fixed — check the file manually or restore from a backup.
        </div>
    <?php endif; ?>

    <?php if ($alert): ?>
        <div class="alert alert-<?= h($alert['type']) ?>"><?= h($alert['text']) ?></div>
    <?php endif; ?>

    <!-- Tabs -->
    <div class="tabs">
        <a class="tab-link <?= $tab === 'header' ? 'active' : '' ?>" href="?tab=header">Header</a>
        <a class="tab-link <?= $tab === 'theme' ? 'active' : '' ?>" href="?tab=theme">Theme / Colors</a>
        <a class="tab-link <?= $tab === 'content' ? 'active' : '' ?>" href="?tab=content">Home Page</a>
        <a class="tab-link <?= $tab === 'pages' ? 'active' : '' ?>" href="?tab=pages">Landing Pages</a>
        <a class="tab-link <?= $tab === 'templates' ? 'active' : '' ?>" href="?tab=templates">Templates</a>
        <a class="tab-link <?= $tab === 'cities' ? 'active' : '' ?>" href="?tab=cities">Cities</a>
        <a class="tab-link <?= $tab === 'blog' ? 'active' : '' ?>" href="?tab=blog">Blog</a>
        <a class="tab-link <?= $tab === 'footer' ? 'active' : '' ?>" href="?tab=footer">Footer</a>
        <a class="tab-link <?= $tab === 'popups' ? 'active' : '' ?>" href="?tab=popups">Popups</a>
        <a class="tab-link <?= $tab === 'media' ? 'active' : '' ?>" href="?tab=media">Media Library</a>
        <a class="tab-link <?= $tab === 'seo' ? 'active' : '' ?>" href="?tab=seo">SEO / Schema</a>
        <a class="tab-link <?= $tab === 'schedule' ? 'active' : '' ?>" href="?tab=schedule">Schedule</a>
    </div>

    <!-- ================= HEADER TAB ================= -->
    <?php require __DIR__ . '/tabs/header.php'; ?>

    <!-- ================= THEME TAB ================= -->
    <?php require __DIR__ . '/tabs/theme.php'; ?>

    <!-- ================= CONTENT TAB ================= -->
    <?php require __DIR__ . '/tabs/content.php'; ?>

    <!-- ================= PAGES TAB ================= -->
    <?php require __DIR__ . '/tabs/pages.php'; ?>

    <!-- ================= TEMPLATES TAB ================= -->
    <?php require __DIR__ . '/tabs/templates.php'; ?>

    <!-- ================= CITIES TAB ================= -->
    <?php require __DIR__ . '/tabs/cities.php'; ?>

    <!-- ================= BLOG TAB ================= -->
    <?php require __DIR__ . '/tabs/blog.php'; ?>

    <!-- ================= FOOTER TAB ================= -->
    <?php require __DIR__ . '/tabs/footer.php'; ?>

    <!-- ================= POPUPS TAB ================= -->
    <?php require __DIR__ . '/tabs/popups.php'; ?>

    <!-- ================= MEDIA LIBRARY TAB ================= -->
    <?php require __DIR__ . '/tabs/media.php'; ?>

    <!-- ================= SCHEDULE TAB ================= -->
    <?php require __DIR__ . '/tabs/schedule.php'; ?>

    <!-- ================= SEO / SCHEMA TAB ================= -->
    <?php require __DIR__ . '/tabs/seo.php'; ?>


</div>

?>

<script>
/* ---------------------------------------------------------
   Generic row helpers (menu items, bottom links)
   --------------------------------------------------------- */
/* ── media library helpers ── */
function setBlockPhoto(uid, url, alt) {
    const existing = document.getElementById('existing_' + uid);
    const preview  = document.getElementById('preview_'  + uid);
    if (existing) existing.value = url;
    if (preview)  preview.innerHTML = '<img src="../' + url + '" alt="' + alt + '" style="max-width:100%;max-height:200px;border-radius:4px;">';
    // also fill nearby alt input if empty
    if (alt && preview) {
        const card = preview.closest('.form-group') || preview.parentElement;
        if (card) {
            const altInput = card.querySelector ? card.closest('.block-field-group, .card, form')?.querySelector('input[name="block_photo_alt[]"]') : null;
            if (altInput && !altInput.value) altInput.value = alt;
        }
    }
}

function removeRow(button, containerId) {
    const row = button.closest('.repeat-row');
    const container = containerId ? document.getElementById(containerId) : row.parentElement;
    if (container.children.length > 1) {
        row.remove();
    } else {
        row.querySelectorAll('input').forEach(i => i.value = '');
    }
}

let menuItemCount = <?= count($menu) ?>;

function addInfoItem() {
    const container = document.getElementById('extra-info-items');
    const row = document.createElement('div');
    row.style = 'display:flex;gap:10px;align-items:center;margin-bottom:10px;';
    row.innerHTML = `
        <div class="form-group" style="flex:0 0 80px;margin:0;">
            <label>Icon/emoji</label>
            <input type="text" name="info_icon[]" placeholder="🌐" style="font-size:1.2rem;">
        </div>
        <div class="form-group" style="flex:1;margin:0;">
            <label>Text</label>
            <input type="text" name="info_text[]" placeholder="e.g. Call for Great Service!">
        </div>
        <button type="button" class="remove-row" onclick="this.parentElement.remove()" style="margin-top:20px;">&times;</button>
    `;
    container.appendChild(row);
}

function addMenuRow() {
    const container = document.getElementById('menu-items');
    const mi = menuItemCount++;
    const card = document.createElement('div');
    card.className = 'menu-item-card';
    card.dataset.menuIndex = mi;
    card.innerHTML = `
        <div class="menu-item-top repeat-row">
            <input type="text" name="menu_label[]" placeholder="Label (e.g. Home)">
            <input type="text" name="menu_url[]" placeholder="Link (e.g. / or #about)">
            <button type="button" class="btn btn-secondary btn-small" onclick="toggleDropdown(this)" style="white-space:nowrap;">+ Sub-menu (0)</button>
            <button type="button" class="remove-row" onclick="removeMenuItem(this)">&times;</button>
        </div>
        <div class="menu-dropdown-editor is-hidden">
            <p class="hint" style="margin:6px 0 8px 0;">Sub-menu links — shown in a dropdown under this item.</p>
            <div class="dropdown-links"></div>
            <button type="button" class="btn btn-secondary btn-small" onclick="addDropdownLink(this, ${mi})" style="margin-top:6px;">+ Add sub-link</button>
        </div>
    `;
    container.appendChild(card);
}

function removeMenuItem(btn) {
    const container = document.getElementById('menu-items');
    const card = btn.closest('.menu-item-card');
    if (container.children.length > 1) card.remove();
    else card.querySelectorAll('input').forEach(i => i.value = '');
}

function toggleDropdown(btn) {
    const card = btn.closest('.menu-item-card');
    const editor = card.querySelector('.menu-dropdown-editor');
    editor.classList.toggle('is-hidden');
}

function addDropdownLink(btn, mi) {
    const card = btn.closest('.menu-item-card');
    const linksContainer = card.querySelector('.dropdown-links');
    const row = document.createElement('div');
    row.className = 'repeat-row dropdown-link-row';
    row.innerHTML = `
        <input type="text" name="menu_child_label[${mi}][]" placeholder="Sub-link label">
        <input type="text" name="menu_child_url[${mi}][]" placeholder="Sub-link URL">
        <button type="button" class="remove-row" onclick="removeRow(this, null)">&times;</button>
    `;
    linksContainer.appendChild(row);
    // Update count on toggle button
    const toggleBtn = card.querySelector('.menu-item-top .btn');
    const count = linksContainer.children.length;
    toggleBtn.textContent = `+ Sub-menu (${count})`;
}

function addBottomLinkRow() {
    const container = document.getElementById('bottom-links');
    const row = document.createElement('div');
    row.className = 'repeat-row';
    row.innerHTML = `
        <input type="text" name="bottom_link_label[]" placeholder="Label (e.g. Privacy Policy)">
        <input type="text" name="bottom_link_url[]" placeholder="URL">
        <button type="button" class="remove-row" onclick="removeRow(this, 'bottom-links')">&times;</button>
    `;
    container.appendChild(row);
}

/* ---------------------------------------------------------
   Footer columns & links
   --------------------------------------------------------- */
let nextColumnIndex = <?= $nextColumnIndex ?>;

function switchColType(select) {
    const card = select.closest('.column-card');
    card.querySelectorAll('.col-type-panel').forEach(p => p.classList.add('is-hidden'));
    const panel = card.querySelector('.col-type-' + select.value);
    if (panel) panel.classList.remove('is-hidden');
}

function addContactExtra(btn) {
    const card = btn.closest('.column-card');
    const ci = card.dataset.colIndex;
    const links = card.querySelector('.column-links');
    const li = links ? links.children.length : 0;
    const row = document.createElement('div');
    row.className = 'repeat-row';
    row.innerHTML = `
        <input type="text" name="footer_columns[${ci}][contact_extras][${li}][icon]"  placeholder="Icon/emoji" style="flex:0 0 70px;">
        <input type="text" name="footer_columns[${ci}][contact_extras][${li}][label]" placeholder="Label text">
        <input type="text" name="footer_columns[${ci}][contact_extras][${li}][url]"   placeholder="Link (optional)">
        <button type="button" class="remove-row" onclick="removeRow(this, null)">&times;</button>
    `;
    if (links) links.appendChild(row);
}

function addColumn() {
    const container = document.getElementById('footer-columns');
    const colIndex = nextColumnIndex++;
    const card = document.createElement('div');
    card.className = 'column-card';
    card.dataset.colIndex = colIndex;
    card.dataset.nextLinkIndex = '1';
    card.innerHTML = `
        <div class="column-card-header" style="gap:8px;">
            <input type="text" name="footer_columns[${colIndex}][title]" placeholder="Column heading">
            <select name="footer_columns[${colIndex}][type]" onchange="switchColType(this)"
                    style="flex:0 0 140px;padding:8px 10px;border:1px solid #e5e7eb;border-radius:6px;font-size:0.88rem;">
                <option value="links">Links column</option>
                <option value="text">Text column</option>
                <option value="contact">Contact column</option>
            </select>
            <button type="button" class="icon-btn remove-row" onclick="removeColumn(this)">Remove</button>
        </div>
        <div class="col-type-panel col-type-links">
            <div class="column-links">
                <div class="repeat-row">
                    <input type="text" name="footer_columns[${colIndex}][links][0][label]" placeholder="Link text">
                    <input type="text" name="footer_columns[${colIndex}][links][0][url]"   placeholder="URL">
                    <button type="button" class="remove-row" onclick="removeRow(this, null)">&times;</button>
                </div>
            </div>
            <button type="button" class="btn btn-secondary btn-small" onclick="addLink(this)">+ Add link</button>
        </div>
        <div class="col-type-panel col-type-text is-hidden">
            <div class="form-group" style="margin-top:10px;">
                <textarea name="footer_columns[${colIndex}][text]" rows="5" placeholder="Column text..."></textarea>
            </div>
        </div>
        <div class="col-type-panel col-type-contact is-hidden">
            <p class="hint" style="margin:8px 0;">Phone and city shown automatically. Add extra items below.</p>
            <div class="column-links"></div>
            <button type="button" class="btn btn-secondary btn-small" onclick="addContactExtra(this)">+ Add item</button>
        </div>
    `;
    container.appendChild(card);
}

function removeColumn(button) {
    const container = document.getElementById('footer-columns');
    const card = button.closest('.column-card');
    card.remove();
    if (container.children.length === 0) {
        nextColumnIndex = 0;
    }
}

function addLink(button) {
    const card = button.closest('.column-card');
    const colIndex = card.dataset.colIndex;
    const linkIndex = parseInt(card.dataset.nextLinkIndex || '0', 10);
    card.dataset.nextLinkIndex = String(linkIndex + 1);

    const linksContainer = card.querySelector('.column-links');
    const row = document.createElement('div');
    row.className = 'repeat-row';
    row.innerHTML = `
        <input type="text" name="footer_columns[${colIndex}][links][${linkIndex}][label]" placeholder="Link text">
        <input type="text" name="footer_columns[${colIndex}][links][${linkIndex}][url]" placeholder="URL (e.g. /about or #faq)">
        <button type="button" class="remove-row" onclick="removeRow(this, null)">&times;</button>
    `;
    linksContainer.appendChild(row);
}
</script>

<?php content_editor_scripts(); ?>

<!-- ===================== MEDIA LIBRARY TAB ===================== -->
<?php if ($tab === 'media'): ?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/cropperjs@1.6.2/dist/cropper.min.css">
<script src="https://cdn.jsdelivr.net/npm/cropperjs@1.6.2/dist/cropper.min.js"></script>
<style>
.ml-usage { font-size:.76rem; margin:5px 0 3px; line-height:1.3; }
.ml-unused { color:#9ca3af; }
.ml-in-use { color:#15803d; cursor:pointer; user-select:none; position:relative; }
.ml-in-use:hover { color:#166534; }
.ml-usage-list { display:none; margin:3px 0 0; padding:0; list-style:none; background:#f0fdf4; border:1px solid #bbf7d0; border-radius:4px; overflow:hidden; }
.ml-in-use.ml-open .ml-usage-list { display:block; }
.ml-usage-list li { padding:3px 8px; font-size:.73rem; color:#166534; border-bottom:1px solid #dcfce7; }
.ml-usage-list li:last-child { border-bottom:none; }
</style>
<script>
(function() {
    const api = 'media_api.php';

    /* ── state ── */
    let allMedia    = [];
    let searchQ     = '';
    let mediaUsage  = {};
    let usageLoaded = false;
    let dupeGroups  = [];

    /* ── elements ── */
    const grid     = document.getElementById('media-grid');
    const searchEl = document.getElementById('media-search');
    const countEl  = document.getElementById('media-count');
    const dropzone = document.getElementById('media-dropzone');
    const fileInp  = document.getElementById('media-file-input');

    /* ── load ── */
    async function loadMedia() {
        const res  = await fetch(api + '?action=list');
        allMedia   = await res.json();
        renderGrid();
    }

    async function loadUsage() {
        const res  = await fetch(api + '?action=usage');
        mediaUsage = await res.json();
        usageLoaded = true;
        renderGrid();
    }

    function usageBadge(url) {
        if (!usageLoaded) return '';
        const list = mediaUsage[url] || [];
        if (list.length === 0) return '<div class="ml-usage ml-unused">Unused</div>';
        const items = list.map(u => `<li>${escHtml(u)}</li>`).join('');
        return `<div class="ml-usage ml-in-use" onclick="this.classList.toggle('ml-open')">`
             + `${list.length} place${list.length > 1 ? 's' : ''} &#9662;`
             + `<ul class="ml-usage-list">${items}</ul></div>`;
    }

    function renderGrid() {
        const q    = searchQ.toLowerCase();
        const items = allMedia.filter(m =>
            !q || m.filename.toLowerCase().includes(q) || (m.alt||'').toLowerCase().includes(q)
        );
        countEl.textContent = items.length + ' image' + (items.length !== 1 ? 's' : '');
        grid.innerHTML = items.map(m => `
            <div class="ml-card" data-fn="${escHtml(m.filename)}">
                <div class="ml-thumb" onclick="copyUrl('${escHtml(m.url)}')">
                    <img src="../${escHtml(m.url)}" alt="${escHtml(m.alt||'')}">
                    <div class="ml-thumb-overlay">Click to copy URL</div>
                </div>
                <div class="ml-info">
                    <div class="ml-name" title="${escHtml(m.filename)}">${escHtml(m.filename.replace('.webp',''))}</div>
                    <div class="ml-dims">${m.width}×${m.height} &nbsp;·&nbsp; ${fmtSize(m.size)}</div>
                    <input class="ml-alt-input" type="text" value="${escHtml(m.alt||'')}" placeholder="Alt text…" onchange="updateAlt('${escHtml(m.filename)}', this.value)">
                    ${usageBadge(m.url)}
                    <div class="ml-actions">
                        <button class="btn btn-small btn-secondary" onclick="copyUrl('${escHtml(m.url)}')">Copy URL</button>
                        <button class="btn btn-small btn-secondary" onclick="openCropper('${escHtml(m.filename)}','../${escHtml(m.url)}')">&#9986; Crop</button>
                        <button class="btn btn-small btn-secondary" onclick="openFocal('${escHtml(m.filename)}','../${escHtml(m.url)}',${m.focal_x!=null?m.focal_x:50},${m.focal_y!=null?m.focal_y:50})">&#10753; Focal</button>
                        <button class="btn btn-small btn-danger" onclick="deleteMedia('${escHtml(m.filename)}')">Delete</button>
                    </div>
                </div>
            </div>
        `).join('');
    }

    function escHtml(s) { return String(s).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;'); }
    function fmtSize(b) { return b > 1048576 ? (b/1048576).toFixed(1)+' MB' : Math.round(b/1024)+' KB'; }

    window.copyUrl = function(url) {
        navigator.clipboard.writeText(url).then(() => {
            showToast('URL copied: ' + url);
        });
    };

    window.deleteMedia = async function(filename) {
        const item   = allMedia.find(m => m.filename === filename);
        const used   = item ? (mediaUsage[item.url] || []) : [];
        let msg = 'Delete ' + filename + '?';
        if (used.length > 0) {
            msg = '⚠ This image is used in ' + used.length + ' place(s):\n'
                + used.join('\n')
                + '\n\nDeleting it will break those pages. Continue?';
        }
        if (!confirm(msg)) return;
        const fd = new FormData();
        fd.append('action', 'delete');
        fd.append('filename', filename);
        fd.append('csrf_token', CSRF_TOKEN);
        await fetch(api, { method:'POST', body: fd });
        if (item) delete mediaUsage[item.url];
        allMedia = allMedia.filter(m => m.filename !== filename);
        renderGrid();
    };

    window.updateAlt = async function(filename, alt) {
        const fd = new FormData();
        fd.append('action', 'update');
        fd.append('filename', filename);
        fd.append('alt', alt);
        fd.append('csrf_token', CSRF_TOKEN);
        await fetch(api, { method:'POST', body: fd });
    };

    /* ── upload ── */
    async function uploadFiles(files) {
        for (const file of files) {
            const fd = new FormData();
            fd.append('action', 'upload');
            fd.append('file', file);
            fd.append('csrf_token', CSRF_TOKEN);
            const res  = await fetch(api, { method:'POST', body: fd });
            const data = await res.json();
            if (data.item) {
                allMedia.unshift(data.item);
                showToast('Uploaded: ' + data.item.filename);
            } else {
                showToast('Error: ' + (data.error || 'unknown'));
            }
        }
        renderGrid();
    }

    dropzone.addEventListener('click', () => fileInp.click());
    fileInp.addEventListener('change', () => { uploadFiles(fileInp.files); fileInp.value = ''; });
    dropzone.addEventListener('dragover', e => { e.preventDefault(); dropzone.classList.add('drag-over'); });
    dropzone.addEventListener('dragleave', () => dropzone.classList.remove('drag-over'));
    dropzone.addEventListener('drop', e => {
        e.preventDefault();
        dropzone.classList.remove('drag-over');
        uploadFiles(e.dataTransfer.files);
    });

    searchEl.addEventListener('input', () => { searchQ = searchEl.value; renderGrid(); });

    function showToast(msg) {
        const t = document.createElement('div');
        t.className = 'ml-toast';
        t.textContent = msg;
        document.body.appendChild(t);
        setTimeout(() => t.remove(), 3000);
    }

    /* ── image variation ── */
    window.applyVariation = async function() {
        const seed = parseInt(document.getElementById('var-seed').value, 10);
        if (!seed || seed < 1 || seed > 9999) {
            alert('Enter a seed number between 1 and 9999.\n\nUse a different number for each city site.');
            return;
        }
        const result = document.getElementById('var-result');
        const btn    = document.getElementById('var-apply-btn');
        const n      = allMedia.filter(m => !m.varied_seed || m.varied_seed !== seed).length;
        if (!confirm(
            'Apply variation seed ' + seed + ' to ' + n + ' image' + (n !== 1 ? 's' : '') + '?\n\n' +
            'This permanently modifies the image files. Use a different seed number for each city site.\n\n' +
            'Already-varied images with this seed are skipped automatically.'
        )) return;

        btn.disabled = true;
        btn.textContent = 'Applying…';
        result.innerHTML = '';

        const fd = new FormData();
        fd.append('action', 'vary_batch');
        fd.append('seed',   seed);
        fd.append('csrf_token', CSRF_TOKEN);
        const res  = await fetch(api, { method: 'POST', body: fd });
        const data = await res.json();

        btn.disabled = false;
        btn.textContent = 'Apply to All Images';

        if (data.success) {
            result.innerHTML = `<span style="color:#166534;">&#10003; ${data.varied} image${data.varied !== 1 ? 's' : ''} varied`
                + (data.skipped ? ` &nbsp;&middot;&nbsp; ${data.skipped} already done` : '')
                + (data.failed  ? ` &nbsp;&middot;&nbsp; <span style="color:#dc2626;">${data.failed} failed</span>` : '')
                + '</span>';
            await loadMedia(); // refresh dimensions/sizes
        } else {
            result.innerHTML = `<span style="color:#dc2626;">Error: ${escHtml(data.error || 'unknown')}</span>`;
        }
    };

    /* ── duplicate detector ── */
    window.findDuplicates = async function() {
        const btn = document.getElementById('dupe-btn');
        btn.disabled = true;
        btn.textContent = 'Scanning…';

        // Backfill hashes for any new images
        const hfd = new FormData(); hfd.append('action', 'hash_all'); hfd.append('csrf_token', CSRF_TOKEN);
        await fetch(api, { method: 'POST', body: hfd });

        // Get duplicate groups
        const res = await fetch(api + '?action=dupes');
        dupeGroups = await res.json();

        btn.disabled = false;
        btn.textContent = 'Find Duplicates';
        renderDupePanel();
    };

    window.closeDupePanel = function() {
        document.getElementById('dupe-panel').style.display = 'none';
        dupeGroups = [];
    };

    window.dupeDelete = async function(filename, gi) {
        const item = allMedia.find(m => m.filename === filename);
        const used = item ? (mediaUsage[item.url] || []) : [];
        let msg = 'Delete ' + filename + '?';
        if (used.length) msg = '⚠ Used in ' + used.length + ' place(s):\n' + used.join('\n') + '\n\nDelete anyway?';
        if (!confirm(msg)) return;

        const fd = new FormData();
        fd.append('action', 'delete');
        fd.append('filename', filename);
        fd.append('csrf_token', CSRF_TOKEN);
        await fetch(api, { method: 'POST', body: fd });

        if (item) delete mediaUsage[item.url];
        allMedia     = allMedia.filter(m => m.filename !== filename);
        dupeGroups[gi] = dupeGroups[gi].filter(i => i.filename !== filename);
        if (dupeGroups[gi].length <= 1) dupeGroups.splice(gi, 1);

        renderGrid();
        renderDupePanel();
    };

    function renderDupePanel() {
        const panel = document.getElementById('dupe-panel');

        if (dupeGroups.length === 0) {
            panel.innerHTML = `<div style="display:flex;justify-content:space-between;align-items:center;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:6px;padding:12px 16px;margin-bottom:16px;">
                <span style="color:#166534;font-weight:500;">&#10003; No duplicate images found</span>
                <button onclick="closeDupePanel()" style="background:none;border:none;cursor:pointer;color:#6b7280;font-size:1.1rem;line-height:1;">&#10005;</button>
            </div>`;
            panel.style.display = 'block';
            return;
        }

        const groupsHtml = dupeGroups.map((group, gi) => {
            // Sort: most-used first, then largest file
            const sorted = [...group].sort((a, b) => {
                const ua = (mediaUsage[a.url] || []).length;
                const ub = (mediaUsage[b.url] || []).length;
                return ub - ua || b.size - a.size;
            });

            const cards = sorted.map((img, ii) => {
                const usages  = mediaUsage[img.url] || [];
                const uLabel  = usages.length ? usages.length + ' place' + (usages.length > 1 ? 's' : '') : 'Unused';
                const uColor  = usages.length ? '#15803d' : '#9ca3af';
                const action  = ii === 0
                    ? `<div style="font-size:.71rem;color:#1d4ed8;font-weight:600;margin-top:4px;">&#9733; Keep</div>`
                    : `<button class="btn btn-small btn-danger" style="margin-top:5px;width:100%;font-size:.72rem;" onclick="dupeDelete('${escHtml(img.filename)}',${gi})">Delete</button>`;
                return `<div style="min-width:110px;max-width:150px;">
                    <img src="../${escHtml(img.url)}" style="width:100%;aspect-ratio:4/3;object-fit:cover;border-radius:4px;display:block;border:${ii===0?'2px solid #1d4ed8':'1px solid #e5e7eb'};">
                    <div style="font-size:.71rem;color:#374151;margin-top:3px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${escHtml(img.filename)}">${escHtml(img.filename.replace('.webp','').replace('.svg','').replace('.gif',''))}</div>
                    <div style="font-size:.69rem;color:#6b7280;">${img.width}&#215;${img.height} &middot; ${fmtSize(img.size)}</div>
                    <div style="font-size:.69rem;color:${uColor};">${uLabel}</div>
                    ${action}
                </div>`;
            }).join('');

            return `<div style="margin-bottom:12px;padding:12px;background:#fffbeb;border:1px solid #fde68a;border-radius:6px;">
                <div style="font-size:.8rem;font-weight:600;color:#92400e;margin-bottom:8px;">${group.length} similar images</div>
                <div style="display:flex;gap:10px;flex-wrap:wrap;">${cards}</div>
            </div>`;
        }).join('');

        panel.innerHTML = `<div style="background:#fff7ed;border:1px solid #f59e0b;border-radius:6px;padding:14px 16px;margin-bottom:16px;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
                <span style="font-weight:600;color:#92400e;">&#9888; ${dupeGroups.length} duplicate group${dupeGroups.length > 1 ? 's' : ''} found &mdash; keep one, delete the rest</span>
                <button onclick="closeDupePanel()" style="background:none;border:none;cursor:pointer;color:#6b7280;font-size:1.1rem;line-height:1;">&#10005;</button>
            </div>
            ${groupsHtml}
        </div>`;
        panel.style.display = 'block';
    }

    /* ── focal point tool ── */
    let focalFilename = '';
    let focalX = 50, focalY = 50;

    window.openFocal = function(filename, url, fx, fy) {
        focalFilename = filename;
        focalX = fx != null ? fx : 50;
        focalY = fy != null ? fy : 50;
        const modal = document.getElementById('focal-modal');
        const img   = document.getElementById('focal-image');
        const dot   = document.getElementById('focal-dot');
        const info  = document.getElementById('focal-info');
        img.src = '';
        modal.style.display = 'block';
        img.onload = function() {
            dot.style.left = focalX + '%';
            dot.style.top  = focalY + '%';
            info.textContent = 'Left: ' + Math.round(focalX) + '%, Top: ' + Math.round(focalY) + '%';
        };
        img.src = url;
    };

    window.closeFocal = function() {
        document.getElementById('focal-modal').style.display = 'none';
        document.getElementById('focal-image').src = '';
        focalFilename = '';
    };

    window.clickFocal = async function(e) {
        const img  = document.getElementById('focal-image');
        const rect = img.getBoundingClientRect();
        focalX = Math.min(100, Math.max(0, ((e.clientX - rect.left) / rect.width)  * 100));
        focalY = Math.min(100, Math.max(0, ((e.clientY - rect.top)  / rect.height) * 100));
        const dot  = document.getElementById('focal-dot');
        const info = document.getElementById('focal-info');
        dot.style.left = focalX + '%';
        dot.style.top  = focalY + '%';
        info.textContent = 'Left: ' + Math.round(focalX) + '%, Top: ' + Math.round(focalY) + '%';
        const fd = new FormData();
        fd.append('action',   'focal');
        fd.append('filename', focalFilename);
        fd.append('focal_x',  focalX.toFixed(1));
        fd.append('focal_y',  focalY.toFixed(1));
        fd.append('csrf_token', CSRF_TOKEN);
        const res  = await fetch(api, { method:'POST', body: fd });
        const data = await res.json();
        if (data.success) {
            const idx = allMedia.findIndex(m => m.filename === focalFilename);
            if (idx !== -1) { allMedia[idx].focal_x = data.focal_x; allMedia[idx].focal_y = data.focal_y; }
            showToast('Focal point saved');
        } else {
            showToast('Error: ' + (data.error || 'save failed'));
        }
    };

    /* ── crop tool ── */
    let cropperInstance = null;
    let cropFilename    = '';

    window.openCropper = function(filename, url) {
        cropFilename = filename;
        const modal = document.getElementById('crop-modal');
        const img   = document.getElementById('crop-image');
        if (cropperInstance) { cropperInstance.destroy(); cropperInstance = null; }
        img.src = '';
        modal.style.display = 'block';
        img.onload = function() {
            cropperInstance = new Cropper(img, {
                viewMode: 1,
                autoCropArea: 1,
                responsive: true,
                checkCrossOrigin: false,
            });
        };
        img.src = url;
    };

    window.closeCropper = function() {
        document.getElementById('crop-modal').style.display = 'none';
        if (cropperInstance) { cropperInstance.destroy(); cropperInstance = null; }
        document.getElementById('crop-image').src = '';
        cropFilename = '';
    };

    window.setCropRatio = function(ratio) {
        if (cropperInstance) cropperInstance.setAspectRatio(ratio);
    };

    window.applyCrop = async function() {
        if (!cropperInstance || !cropFilename) return;
        const d  = cropperInstance.getData(true);
        const fd = new FormData();
        fd.append('action',   'crop');
        fd.append('filename', cropFilename);
        fd.append('x',        d.x);
        fd.append('y',        d.y);
        fd.append('width',    d.width);
        fd.append('height',   d.height);
        fd.append('csrf_token', CSRF_TOKEN);
        const btn = document.getElementById('crop-apply-btn');
        btn.disabled = true;
        btn.textContent = 'Saving…';
        const res  = await fetch(api, { method:'POST', body: fd });
        const data = await res.json();
        btn.disabled = false;
        btn.textContent = 'Apply Crop';
        if (data.success) {
            const idx = allMedia.findIndex(m => m.filename === cropFilename);
            if (idx !== -1) {
                allMedia[idx].width  = data.width;
                allMedia[idx].height = data.height;
                allMedia[idx].size   = data.size;
            }
            window.closeCropper();
            renderGrid();
            showToast('Cropped and saved');
        } else {
            showToast('Error: ' + (data.error || 'crop failed'));
        }
    };

    loadMedia();
    loadUsage();
})();
</script>

<!-- ===================== FOCAL POINT MODAL ===================== -->
<div id="focal-modal" style="display:none;position:fixed;inset:0;z-index:10001;background:rgba(0,0,0,0.85);overflow-y:auto;">
    <div style="background:#fff;max-width:860px;margin:40px auto;border-radius:8px;overflow:hidden;box-shadow:0 8px 40px rgba(0,0,0,0.4);">
        <div style="display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid #e5e7eb;">
            <h2 style="margin:0;font-size:1.1rem;">Set Focal Point</h2>
            <button onclick="closeFocal()" style="background:none;border:none;font-size:1.5rem;cursor:pointer;line-height:1;">&times;</button>
        </div>
        <div style="padding:16px 20px;">
            <p style="margin:0 0 10px;font-size:.85rem;color:#6b7280;">Click the most important part of the image — the crop will stay centered on that point.</p>
            <div style="border-radius:4px;overflow:hidden;background:#111;max-height:58vh;overflow-y:auto;">
                <div id="focal-img-wrap" style="position:relative;cursor:crosshair;line-height:0;" onclick="clickFocal(event)">
                    <img id="focal-image" src="" style="width:100%;height:auto;display:block;">
                    <div id="focal-dot" style="position:absolute;left:50%;top:50%;width:22px;height:22px;border-radius:50%;border:3px solid #fff;box-shadow:0 0 0 2px #fd783b,0 2px 8px rgba(0,0,0,0.6);transform:translate(-50%,-50%);pointer-events:none;transition:left .08s,top .08s;"></div>
                </div>
            </div>
            <div id="focal-info" style="margin-top:8px;font-size:.82rem;color:#6b7280;text-align:center;">Click image to set focal point</div>
        </div>
        <div style="padding:16px 20px;border-top:1px solid #e5e7eb;display:flex;gap:10px;justify-content:flex-end;">
            <button class="btn" onclick="closeFocal()">Done</button>
        </div>
    </div>
</div>

<!-- ===================== CROP MODAL ===================== -->
<div id="crop-modal" style="display:none;position:fixed;inset:0;z-index:10000;background:rgba(0,0,0,0.85);overflow-y:auto;">
    <div style="background:#fff;max-width:920px;margin:40px auto;border-radius:8px;overflow:hidden;box-shadow:0 8px 40px rgba(0,0,0,0.4);">
        <div style="display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid #e5e7eb;">
            <h2 style="margin:0;font-size:1.1rem;">Crop Image</h2>
            <button onclick="closeCropper()" style="background:none;border:none;font-size:1.5rem;cursor:pointer;line-height:1;">&times;</button>
        </div>
        <div style="padding:16px 20px;">
            <div style="display:flex;gap:8px;margin-bottom:12px;flex-wrap:wrap;align-items:center;">
                <span style="font-size:.85rem;color:#6b7280;margin-right:4px;">Aspect ratio:</span>
                <button class="btn btn-small btn-secondary" onclick="setCropRatio(NaN)">Free</button>
                <button class="btn btn-small btn-secondary" onclick="setCropRatio(16/9)">16:9</button>
                <button class="btn btn-small btn-secondary" onclick="setCropRatio(4/3)">4:3</button>
                <button class="btn btn-small btn-secondary" onclick="setCropRatio(3/2)">3:2</button>
                <button class="btn btn-small btn-secondary" onclick="setCropRatio(1)">1:1</button>
                <button class="btn btn-small btn-secondary" onclick="setCropRatio(9/16)">9:16</button>
            </div>
            <div style="max-height:520px;overflow:hidden;background:#111;border-radius:4px;">
                <img id="crop-image" src="" style="max-width:100%;display:block;">
            </div>
        </div>
        <div style="padding:16px 20px;border-top:1px solid #e5e7eb;display:flex;gap:10px;justify-content:flex-end;">
            <button class="btn btn-secondary" onclick="closeCropper()">Cancel</button>
            <button id="crop-apply-btn" class="btn" onclick="applyCrop()">Apply Crop</button>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ===================== IMAGE PICKER MODAL ===================== -->
<div id="img-picker-modal" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,0.6);overflow-y:auto;">
    <div style="background:#fff;max-width:960px;margin:40px auto;border-radius:8px;overflow:hidden;box-shadow:0 8px 40px rgba(0,0,0,0.3);">
        <div style="display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid #e5e7eb;">
            <h2 style="margin:0;font-size:1.1rem;">Pick from Media Library</h2>
            <button onclick="closeImgPicker()" style="background:none;border:none;font-size:1.5rem;cursor:pointer;line-height:1;">&times;</button>
        </div>
        <div style="padding:16px 20px;">
            <div style="display:flex;gap:12px;align-items:center;margin-bottom:16px;">
                <input id="picker-search" type="text" placeholder="Search images…" style="flex:1;padding:8px 12px;border:1px solid #d1d5db;border-radius:6px;">
                <span id="picker-count" style="font-size:.85rem;color:#6b7280;white-space:nowrap;"></span>
            </div>
            <div id="picker-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:10px;max-height:500px;overflow-y:auto;"></div>
        </div>
    </div>
</div>

<script>
(function() {
    let pickerCallback = null;
    let pickerMedia    = [];

    window.openImgPicker = function(callback) {
        pickerCallback = callback;
        document.getElementById('img-picker-modal').style.display = 'block';
        loadPickerMedia();
    };

    window.closeImgPicker = function() {
        document.getElementById('img-picker-modal').style.display = 'none';
        pickerCallback = null;
    };

    document.getElementById('img-picker-modal').addEventListener('click', function(e) {
        if (e.target === this) closeImgPicker();
    });

    document.getElementById('picker-search').addEventListener('input', function() {
        renderPickerGrid(this.value.toLowerCase());
    });

    async function loadPickerMedia() {
        const res   = await fetch('media_api.php?action=list');
        pickerMedia = await res.json();
        renderPickerGrid('');
    }

    function renderPickerGrid(q) {
        const items = pickerMedia.filter(m =>
            !q || m.filename.toLowerCase().includes(q) || (m.alt||'').toLowerCase().includes(q)
        );
        document.getElementById('picker-count').textContent = items.length + ' images';
        document.getElementById('picker-grid').innerHTML = items.map(m => `
            <div onclick="pickImage('${esc(m.url)}','${esc(m.alt||'')}')" style="cursor:pointer;border:2px solid transparent;border-radius:6px;overflow:hidden;background:#f9fafb;transition:border-color .15s;" onmouseover="this.style.borderColor='#fd783b'" onmouseout="this.style.borderColor='transparent'">
                <img src="../${esc(m.url)}" alt="${esc(m.alt||m.filename)}" style="width:100%;aspect-ratio:1;object-fit:cover;display:block;">
                <div style="padding:4px 6px;font-size:.7rem;color:#4b5563;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${esc(m.filename.replace('.webp',''))}</div>
            </div>
        `).join('');
    }

    window.pickImage = function(url, alt) {
        if (pickerCallback) pickerCallback(url, alt);
        closeImgPicker();
    };

    function esc(s) { return String(s).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;'); }
})();
</script>

</body>
</html>
