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

// Regenerate needs a real confirmed target city + service to build a prompt
// from (lpc_generate_page_content() requires both) — a page_id that doesn't
// match one isn't reachable through normal navigation, but could exist from
// a hand-typed URL, so check rather than assume.
[$_ctid, $_svcid] = array_pad(explode('__', $page_id, 2), 2, '');
$canRegenerate = lpc_find_by_id($client['target_cities'] ?? [], 'city_id', $_ctid)
    && lpc_find_by_id($client['services'] ?? [], 'service_id', $_svcid);

// Keyed by 'key' when a section has one (the 5 niche-injected slots share
// 'type' with each other, so 'type' alone can't tell them apart), falling
// back to 'type' for the singular sections — same matching rule
// lpc_apply_ai_sections() uses.
$sectionByKey = [];
foreach ($page['sections'] as $s) { $sectionByKey[$s['key'] ?? $s['type']] = $s; }
$hero            = $sectionByKey['hero'] ?? ['headline' => '', 'subheading' => '', 'image' => '', 'image_alt' => '', 'cta_text' => '', 'phone' => ''];
$cityIntro       = $sectionByKey['city_intro'] ?? ['heading' => '', 'body' => ''];
$comparison      = $sectionByKey['comparison'] ?? ['heading' => '', 'intro' => '', 'label_a' => '', 'items_a' => [], 'label_b' => '', 'items_b' => []];
$featureLocal    = $sectionByKey['feature_columns_local'] ?? ['heading' => '', 'items' => []];
$localRelevance  = $sectionByKey['local_relevance'] ?? ['heading' => '', 'body' => ''];
$howItWorks      = $sectionByKey['how_it_works'] ?? ['heading' => '', 'items' => []];
$seasonal        = $sectionByKey['seasonal_calendar'] ?? ['heading' => '', 'items' => []];
$whyChooseUs     = $sectionByKey['why_choose_us'] ?? ['heading' => '', 'body' => ''];
$servicesList = $sectionByKey['services_list'] ?? ['heading' => '', 'items' => []];
$faq          = $sectionByKey['faq'] ?? ['heading' => '', 'items' => []];
$cta          = $sectionByKey['cta'] ?? ['heading' => '', 'body' => '', 'phone' => '', 'button_text' => ''];
$relatedLinks = $sectionByKey['related_links'] ?? ['heading' => '', 'links' => []];

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
        $locked = !empty($page['locked']);
        ?>
        <span class="lpc-badge lpc-badge-<?= $badgeClass ?>"><?= h($badgeText) ?></span>
        <?php if ($locked): ?>
        <span class="lpc-badge lpc-badge-stale">&#128274; Locked</span>
        <?php endif; ?>
    </div>

    <div class="lpc-actions-bar">
        <a href="?client_id=<?= h($client_id) ?>&page_id=<?= h($page_id) ?>&preview=1" class="btn-sm-outline" target="_blank">Preview</a>
        <div style="display:flex;gap:8px;">
            <?php if (!$isNew): ?>
            <form method="post" action="client_save.php" style="margin:0;" onsubmit="return confirm('<?= $locked ? 'Unlock this page? Regenerate will be available again.' : 'Lock this page? Regenerate will be blocked until you unlock it.' ?>');">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <input type="hidden" name="section" value="pages">
                <input type="hidden" name="client_id" value="<?= h($client_id) ?>">
                <input type="hidden" name="page_action" value="toggle_lock">
                <input type="hidden" name="page_id" value="<?= h($page_id) ?>">
                <button type="submit" class="btn-sm-outline"><?= $locked ? '&#128275; Unlock' : '&#128274; Lock' ?></button>
            </form>
            <?php endif; ?>
            <?php if ($canRegenerate && !$locked): ?>
            <form method="post" action="client_save.php" style="margin:0;" onsubmit="return confirm('Regenerate this whole page with AI? Real API cost, replaces all current section content (not related_links).');">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <input type="hidden" name="section" value="pages">
                <input type="hidden" name="client_id" value="<?= h($client_id) ?>">
                <input type="hidden" name="page_action" value="regenerate_ai">
                <input type="hidden" name="page_id" value="<?= h($page_id) ?>">
                <button type="submit" class="btn-sm-outline">&#128176; Regenerate</button>
            </form>
            <?php elseif ($locked): ?>
            <button type="button" class="btn-sm-outline" disabled title="This page is locked — unlock it first">&#128176; Regenerate</button>
            <?php else: ?>
            <button type="button" class="btn-sm-outline" disabled title="Not a confirmed target city/service — nothing to generate from">Regenerate</button>
            <?php endif; ?>
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
            <div class="lpc-field"><label>Primary Keyword <span style="font-weight:400;color:#6b7280;font-size:.78rem;">(this page's one target keyword — stays central in Regenerate, leads the title tag)</span></label><input type="text" name="primary_keyword" value="<?= h($page['seo']['primary_keyword']) ?>"></div>
            <div class="lpc-field"><label>Secondary Keywords <span style="font-weight:400;color:#6b7280;font-size:.78rem;">(comma-separated, woven in lightly — never at the primary keyword's expense)</span></label><input type="text" name="secondary_keywords" value="<?= h(implode(', ', $page['seo']['secondary_keywords'] ?? [])) ?>"></div>
            <div class="lpc-field"><label>Page Title</label><input type="text" name="page_title" value="<?= h($page['seo']['page_title']) ?>"></div>
            <div class="lpc-field"><label>Meta Description</label><textarea name="meta_description"><?= h($page['seo']['meta_description']) ?></textarea></div>
            <div class="lpc-field"><label>OG Image URL</label><input type="text" name="og_image" value="<?= h($page['seo']['og_image']) ?>"></div>
        </div>

        <div class="lpc-section">
            <h3>Hero</h3>
            <div class="lpc-field"><label>Headline</label><input type="text" name="hero_headline" value="<?= h($hero['headline']) ?>"></div>
            <div class="lpc-field"><label>Subheading</label><input type="text" name="hero_subheading" value="<?= h($hero['subheading']) ?>"></div>
            <div class="lpc-field"><label>Image URL</label><input type="text" name="hero_image" value="<?= h($hero['image']) ?>"></div>
            <div class="lpc-field"><label>Image Alt Text <span style="font-weight:400;color:#6b7280;font-size:.78rem;">(image SEO — defaults to the primary keyword)</span></label><input type="text" name="hero_image_alt" value="<?= h($hero['image_alt'] ?? '') ?>"></div>
            <div class="lpc-field"><label>CTA Text</label><input type="text" name="hero_cta_text" value="<?= h($hero['cta_text']) ?>"></div>
            <div class="lpc-field"><label>Phone</label><input type="text" name="hero_phone" value="<?= h($hero['phone']) ?>"></div>
        </div>

        <div class="lpc-section">
            <h3>City Intro <span style="font-weight:400;color:#6b7280;font-size:.78rem;">(real per-city research — population, housing data)</span></h3>
            <div class="lpc-field"><label>Heading</label><input type="text" name="city_intro_heading" value="<?= h($cityIntro['heading']) ?>"></div>
            <div class="lpc-field"><label>Body</label><textarea name="city_intro_body"><?= h($cityIntro['body']) ?></textarea></div>
        </div>

        <div class="lpc-section">
            <h3>Comparison Block <span style="font-weight:400;color:#6b7280;font-size:.78rem;">(two-column "this vs that" — one short phrase per line in each column)</span></h3>
            <div class="lpc-field"><label>Heading</label><input type="text" name="comparison_heading" value="<?= h($comparison['heading']) ?>"></div>
            <div class="lpc-field"><label>Intro (optional)</label><input type="text" name="comparison_intro" value="<?= h($comparison['intro']) ?>"></div>
            <div class="lpc-field-row" style="display:flex;gap:16px;">
                <div class="lpc-field" style="flex:1;">
                    <label>Column A Label</label><input type="text" name="comparison_label_a" value="<?= h($comparison['label_a']) ?>">
                    <label style="margin-top:8px;display:block;">Column A Items</label>
                    <textarea name="comparison_items_a" placeholder="One short phrase per line"><?= h(implode("\n", $comparison['items_a'])) ?></textarea>
                </div>
                <div class="lpc-field" style="flex:1;">
                    <label>Column B Label</label><input type="text" name="comparison_label_b" value="<?= h($comparison['label_b']) ?>">
                    <label style="margin-top:8px;display:block;">Column B Items</label>
                    <textarea name="comparison_items_b" placeholder="One short phrase per line"><?= h(implode("\n", $comparison['items_b'])) ?></textarea>
                </div>
            </div>
        </div>

        <div class="lpc-section">
            <h3>Local Highlights <span style="font-weight:400;color:#6b7280;font-size:.78rem;">(why/how this service matters in this city)</span></h3>
            <div class="lpc-field"><label>Heading</label><input type="text" name="highlights_heading" value="<?= h($featureLocal['heading']) ?>"></div>
            <div id="highlights-rows">
                <?php foreach ($featureLocal['items'] as $item): ?>
                <div class="lpc-repeat-row">
                    <input type="text" name="highlights_title[]" placeholder="Title" value="<?= h($item['title']) ?>">
                    <input type="text" name="highlights_text[]" placeholder="Text" value="<?= h($item['text']) ?>">
                    <button type="button" class="btn-sm-danger" onclick="this.parentElement.remove()">&times;</button>
                </div>
                <?php endforeach; ?>
            </div>
            <button type="button" class="btn-sm-outline" onclick="lpcAddRow('highlights-rows','highlights_title[]','Title','highlights_text[]','Text')">+ Add Item</button>
        </div>

        <div class="lpc-section">
            <h3>Local Relevance <span style="font-weight:400;color:#6b7280;font-size:.78rem;">(real local data — home age, value, housing stock)</span></h3>
            <div class="lpc-field"><label>Heading</label><input type="text" name="local_relevance_heading" value="<?= h($localRelevance['heading']) ?>"></div>
            <div class="lpc-field"><label>Body</label><textarea name="local_relevance_body"><?= h($localRelevance['body']) ?></textarea></div>
        </div>

        <div class="lpc-section">
            <h3>How It Works <span style="font-weight:400;color:#6b7280;font-size:.78rem;">(the real step-by-step process)</span></h3>
            <div class="lpc-field"><label>Heading</label><input type="text" name="steps_heading" value="<?= h($howItWorks['heading']) ?>"></div>
            <div id="steps-rows">
                <?php foreach ($howItWorks['items'] as $item): ?>
                <div class="lpc-repeat-row">
                    <input type="text" name="steps_title[]" placeholder="Step title" value="<?= h($item['title']) ?>">
                    <input type="text" name="steps_text[]" placeholder="Text" value="<?= h($item['text']) ?>">
                    <button type="button" class="btn-sm-danger" onclick="this.parentElement.remove()">&times;</button>
                </div>
                <?php endforeach; ?>
            </div>
            <button type="button" class="btn-sm-outline" onclick="lpcAddRow('steps-rows','steps_title[]','Step title','steps_text[]','Text')">+ Add Step</button>
        </div>

        <div class="lpc-section">
            <h3>Seasonal Calendar <span style="font-weight:400;color:#6b7280;font-size:.78rem;">(4 season cards — when demand for this service peaks)</span></h3>
            <div class="lpc-field"><label>Heading</label><input type="text" name="seasonal_heading" value="<?= h($seasonal['heading']) ?>"></div>
            <div id="seasonal-rows">
                <?php foreach ($seasonal['items'] as $item): ?>
                <div class="lpc-repeat-row">
                    <input type="text" name="seasonal_title[]" placeholder="Season" value="<?= h($item['title']) ?>">
                    <input type="text" name="seasonal_text[]" placeholder="Text" value="<?= h($item['text']) ?>">
                    <button type="button" class="btn-sm-danger" onclick="this.parentElement.remove()">&times;</button>
                </div>
                <?php endforeach; ?>
            </div>
            <button type="button" class="btn-sm-outline" onclick="lpcAddRow('seasonal-rows','seasonal_title[]','Season','seasonal_text[]','Text')">+ Add Season</button>
        </div>

        <div class="lpc-section">
            <h3>Why Choose Us</h3>
            <div class="lpc-field"><label>Heading</label><input type="text" name="why_choose_us_heading" value="<?= h($whyChooseUs['heading']) ?>"></div>
            <div class="lpc-field"><label>Body</label><textarea name="why_choose_us_body"><?= h($whyChooseUs['body']) ?></textarea></div>
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
