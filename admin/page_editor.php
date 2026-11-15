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

$client_id = $_GET['client_id'] ?? '';
$page_id   = $_GET['page_id'] ?? '';
$client    = lpc_load_client($client_id);
if (!$client || !lpc_valid_page_id($page_id)) {
    header('Location: clients.php?msg=error:Client+or+page+not+found');
    exit;
}

// A matrix cell can link here before any page file exists yet — the editor
// itself is what creates it, on first Save, rather than requiring a separate
// "generate draft" step before you're allowed to type anything.
$page = lpc_load_page($client_id, $page_id) ?? lpc_default_page_data();
$isNew = !lpc_load_page($client_id, $page_id);

$sectionByType = [];
foreach ($page['sections'] as $s) { $sectionByType[$s['type']] = $s; }
$hero         = $sectionByType['hero'] ?? ['headline' => '', 'subheading' => '', 'image' => '', 'cta_text' => '', 'phone' => ''];
$text         = $sectionByType['text'] ?? ['heading' => '', 'body' => ''];
$servicesList = $sectionByType['services_list'] ?? ['heading' => '', 'items' => []];
$faq          = $sectionByType['faq'] ?? ['heading' => '', 'items' => []];
$cta          = $sectionByType['cta'] ?? ['heading' => '', 'body' => '', 'phone' => '', 'button_text' => ''];
$relatedLinks = $sectionByType['related_links'] ?? ['heading' => '', 'links' => []];

$alert = null;
if (!empty($_GET['msg'])) {
    $raw = $_GET['msg'];
    if (strpos($raw, ':') !== false) {
        [$type, $text_] = explode(':', $raw, 2);
        if (in_array($type, ['success', 'error', 'warning'], true)) {
            $alert = ['type' => $type, 'text' => $text_];
        }
    }
}

$preview = isset($_GET['preview']);

/**
 * Mirrors wp-plugin/landing-page-client/includes/renderer.php's rules
 * exactly (bare semantic HTML, no CSS classes, content starts at H2 — never
 * H1, proven the hard way against a real theme) using plain htmlspecialchars
 * instead of WordPress's esc_* functions, since this runs in the admin panel,
 * not inside WordPress. If that file's rendering rules ever change, this
 * needs the matching update — there is no way to literally share the code
 * across a WP plugin and this non-WP admin panel.
 */
function lpc_preview_render($sections) {
    $out = '';
    foreach ($sections as $s) {
        switch ($s['type'] ?? '') {
            case 'hero':
                $out .= '<section><h2>' . h($s['headline']) . '</h2>';
                if ($s['subheading']) $out .= '<p>' . h($s['subheading']) . '</p>';
                if ($s['phone']) $out .= '<p><a href="tel:">' . h($s['cta_text'] ?: $s['phone']) . '</a></p>';
                $out .= '</section>';
                break;
            case 'text':
                $out .= '<section>' . ($s['heading'] ? '<h2>' . h($s['heading']) . '</h2>' : '') . '<p>' . h($s['body']) . '</p></section>';
                break;
            case 'services_list':
                $out .= '<section>' . ($s['heading'] ? '<h2>' . h($s['heading']) . '</h2>' : '') . '<ul>';
                foreach ($s['items'] as $item) $out .= '<li><strong>' . h($item['title']) . '</strong> — ' . h($item['text']) . '</li>';
                $out .= '</ul></section>';
                break;
            case 'faq':
                $out .= '<section>' . ($s['heading'] ? '<h2>' . h($s['heading']) . '</h2>' : '');
                foreach ($s['items'] as $item) $out .= '<h3>' . h($item['q']) . '</h3><p>' . h($item['a']) . '</p>';
                $out .= '</section>';
                break;
            case 'cta':
                $out .= '<section>' . ($s['heading'] ? '<h2>' . h($s['heading']) . '</h2>' : '') . '<p>' . h($s['body']) . '</p>';
                if ($s['phone']) $out .= '<p><a href="tel:">' . h($s['button_text'] ?: $s['phone']) . '</a></p>';
                $out .= '</section>';
                break;
            case 'related_links':
                if (empty($s['links'])) break;
                $out .= '<section>' . ($s['heading'] ? '<h2>' . h($s['heading']) . '</h2>' : '') . '<ul>';
                foreach ($s['links'] as $link) $out .= '<li><a href="' . h($link['url']) . '">' . h($link['label']) . '</a></li>';
                $out .= '</ul></section>';
                break;
        }
    }
    return $out;
}

if ($preview) {
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Preview — <?= h($page['seo']['page_title'] ?: $page_id) ?></title>
</head>
<body>
<p><a href="?client_id=<?= h($client_id) ?>&page_id=<?= h($page_id) ?>">&larr; Back to editor</a></p>
<hr>
<p><em>Plain, unstyled preview — mirrors exactly what the WordPress plugin outputs structurally. The client's real theme supplies all visual styling on the live page; this view intentionally has none.</em></p>
<hr>
<main><?= lpc_preview_render($page['sections']) ?></main>
</body>
</html>
<?php
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h($page['seo']['page_title'] ?: $page_id) ?> — <?= h(SITE_TITLE) ?></title>
<link rel="stylesheet" href="../assets/css/style.css">
<?php $_adminCssMtime = css_minify_to(__DIR__ . '/../assets/css/admin.src.css', __DIR__ . '/../assets/css/admin.css'); ?>
<link rel="stylesheet" href="../assets/css/admin.css?v=<?= (int) $_adminCssMtime ?>">
<style>
.lpc-wrap     { max-width: 820px; margin: 0 auto; padding: 32px 24px 64px; }
.lpc-topbar   { display:flex; align-items:center; justify-content:space-between; margin-bottom:8px; flex-wrap:wrap; gap:12px; }
.lpc-topbar h1{ font-size:1.3rem; font-weight:700; margin:0; }
.lpc-badge    { font-size:.72rem; font-weight:700; padding:2px 9px; border-radius:99px; display:inline-block; }
.lpc-badge-connected { background:#dcfce7; color:#166534; }
.lpc-badge-stale      { background:#fef9c3; color:#92400e; }
.lpc-badge-never      { background:#f3f4f6; color:#6b7280; }
.lpc-section  { background:#fff; border:1px solid #e5e7eb; border-radius:8px; padding:16px 20px; margin-bottom:14px; }
.lpc-section h3 { margin:0 0 10px; font-size:.95rem; }
.lpc-field    { margin-bottom:10px; }
.lpc-field label { font-size:.8rem; font-weight:600; color:#374151; display:block; margin-bottom:3px; }
.lpc-field input, .lpc-field textarea { width:100%; padding:7px 10px; border:1px solid #d1d5db; border-radius:6px; font-size:.85rem; font-family:inherit; }
.lpc-field textarea { min-height:70px; }
.lpc-repeat-row { display:flex; gap:8px; margin-bottom:6px; }
.lpc-repeat-row input { flex:1; }
.lpc-actions-bar { display:flex; justify-content:space-between; align-items:center; margin:20px 0; }
</style>
</head>
<body class="admin-body">
<div style="background:#1e293b; color:#fff; padding:14px 24px; display:flex; align-items:center; justify-content:space-between;">
    <div style="font-weight:700; font-size:1rem; letter-spacing:.01em;">&#9776; Site Factory</div>
    <a href="client.php?id=<?= h($client_id) ?>&tab=locations" style="color:#94a3b8; font-size:.82rem; text-decoration:none;">&larr; Locations &amp; Services</a>
</div>

<div class="lpc-wrap">

    <?php if ($alert): ?>
        <div class="alert alert-<?= h($alert['type']) ?>"><?= h($alert['text']) ?></div>
    <?php endif; ?>

    <div class="lpc-topbar">
        <h1><?= h($page_id) ?></h1>
        <?php
        $pageStatus = $isNew ? 'not_created' : $page['status'];
        $badgeClass = $pageStatus === 'published' ? 'connected' : ($pageStatus === 'needs_update' ? 'stale' : 'never');
        $badgeText  = $pageStatus === 'not_created' ? 'Not Saved Yet' : ucfirst(str_replace('_', ' ', $pageStatus));
        ?>
        <span class="lpc-badge lpc-badge-<?= $badgeClass ?>"><?= h($badgeText) ?></span>
    </div>

    <div class="lpc-actions-bar">
        <a href="?client_id=<?= h($client_id) ?>&page_id=<?= h($page_id) ?>&preview=1" class="btn-sm-outline" target="_blank">Preview</a>
        <div style="display:flex;gap:8px;">
            <button type="button" class="btn-sm-outline" disabled title="Requires Phase 3 (AI content pipeline) — not built yet">Regenerate</button>
            <?php if (!$isNew): ?>
            <form method="post" action="client_save.php" style="margin:0;">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <input type="hidden" name="section" value="pages">
                <input type="hidden" name="client_id" value="<?= h($client_id) ?>">
                <input type="hidden" name="page_action" value="publish">
                <input type="hidden" name="page_id" value="<?= h($page_id) ?>">
                <button type="submit" class="btn-open">Publish</button>
            </form>
            <?php endif; ?>
        </div>
    </div>

    <form method="post" action="client_save.php">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
        <input type="hidden" name="section" value="pages">
        <input type="hidden" name="client_id" value="<?= h($client_id) ?>">
        <input type="hidden" name="page_action" value="save_content">
        <input type="hidden" name="page_id" value="<?= h($page_id) ?>">

        <div class="lpc-section">
            <h3>SEO</h3>
            <div class="lpc-field"><label>Page Title</label><input type="text" name="page_title" value="<?= h($page['seo']['page_title']) ?>"></div>
            <div class="lpc-field"><label>Meta Description</label><textarea name="meta_description"><?= h($page['seo']['meta_description']) ?></textarea></div>
            <div class="lpc-field"><label>OG Image URL</label><input type="text" name="og_image" value="<?= h($page['seo']['og_image']) ?>"></div>
        </div>

        <div class="lpc-section">
            <h3>Hero</h3>
            <div class="lpc-field"><label>Headline</label><input type="text" name="hero_headline" value="<?= h($hero['headline']) ?>"></div>
            <div class="lpc-field"><label>Subheading</label><input type="text" name="hero_subheading" value="<?= h($hero['subheading']) ?>"></div>
            <div class="lpc-field"><label>Image URL</label><input type="text" name="hero_image" value="<?= h($hero['image']) ?>"></div>
            <div class="lpc-field"><label>CTA Text</label><input type="text" name="hero_cta_text" value="<?= h($hero['cta_text']) ?>"></div>
            <div class="lpc-field"><label>Phone</label><input type="text" name="hero_phone" value="<?= h($hero['phone']) ?>"></div>
        </div>

        <div class="lpc-section">
            <h3>Text</h3>
            <div class="lpc-field"><label>Heading</label><input type="text" name="text_heading" value="<?= h($text['heading']) ?>"></div>
            <div class="lpc-field"><label>Body</label><textarea name="text_body"><?= h($text['body']) ?></textarea></div>
        </div>

        <div class="lpc-section">
            <h3>Services List</h3>
            <div class="lpc-field"><label>Heading</label><input type="text" name="services_heading" value="<?= h($servicesList['heading']) ?>"></div>
            <div id="svc-rows">
                <?php foreach ($servicesList['items'] as $item): ?>
                <div class="lpc-repeat-row">
                    <input type="text" name="svc_title[]" placeholder="Title" value="<?= h($item['title']) ?>">
                    <input type="text" name="svc_text[]" placeholder="Text" value="<?= h($item['text']) ?>">
                    <button type="button" class="btn-sm-danger" onclick="this.parentElement.remove()">&times;</button>
                </div>
                <?php endforeach; ?>
            </div>
            <button type="button" class="btn-sm-outline" onclick="lpcAddRow('svc-rows','svc_title[]','Title','svc_text[]','Text')">+ Add Item</button>
        </div>

        <div class="lpc-section">
            <h3>FAQ</h3>
            <div class="lpc-field"><label>Heading</label><input type="text" name="faq_heading" value="<?= h($faq['heading']) ?>"></div>
            <div id="faq-rows">
                <?php foreach ($faq['items'] as $item): ?>
                <div class="lpc-repeat-row">
                    <input type="text" name="faq_q[]" placeholder="Question" value="<?= h($item['q']) ?>">
                    <input type="text" name="faq_a[]" placeholder="Answer" value="<?= h($item['a']) ?>">
                    <button type="button" class="btn-sm-danger" onclick="this.parentElement.remove()">&times;</button>
                </div>
                <?php endforeach; ?>
            </div>
            <button type="button" class="btn-sm-outline" onclick="lpcAddRow('faq-rows','faq_q[]','Question','faq_a[]','Answer')">+ Add Item</button>
        </div>

        <div class="lpc-section">
            <h3>CTA</h3>
            <div class="lpc-field"><label>Heading</label><input type="text" name="cta_heading" value="<?= h($cta['heading']) ?>"></div>
            <div class="lpc-field"><label>Body</label><textarea name="cta_body"><?= h($cta['body']) ?></textarea></div>
            <div class="lpc-field"><label>Phone</label><input type="text" name="cta_phone" value="<?= h($cta['phone']) ?>"></div>
            <div class="lpc-field"><label>Button Text</label><input type="text" name="cta_button_text" value="<?= h($cta['button_text']) ?>"></div>
        </div>

        <div class="lpc-section">
            <h3>Related Links <span style="font-weight:400;color:#6b7280;font-size:.78rem;">(read-only — auto-computed at publish time from sibling services at this city)</span></h3>
            <?php if (empty($relatedLinks['links'])): ?>
                <p style="color:#6b7280;font-size:.85rem;margin:0;">None yet — will populate once sibling services at this city are published.</p>
            <?php else: ?>
                <ul style="margin:0;padding-left:20px;">
                    <?php foreach ($relatedLinks['links'] as $link): ?>
                        <li><?= h($link['label']) ?> &rarr; <code><?= h($link['url']) ?></code></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <button type="submit" class="btn-open">Save</button>
    </form>

</div>
<script>
function lpcAddRow(containerId, name1, ph1, name2, ph2) {
    var row = document.createElement('div');
    row.className = 'lpc-repeat-row';
    row.innerHTML = '<input type="text" name="' + name1 + '" placeholder="' + ph1 + '">' +
                    '<input type="text" name="' + name2 + '" placeholder="' + ph2 + '">' +
                    '<button type="button" class="btn-sm-danger" onclick="this.parentElement.remove()">&times;</button>';
    document.getElementById(containerId).appendChild(row);
}
</script>
</body>
</html>
