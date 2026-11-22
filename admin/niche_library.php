<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

if (empty($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}
// Same reasoning as niche_profiles.php's gate: a shared library both the
// fleet and LPC pull facts from is a platform-level decision, not something
// one agency manages for everyone else.
if (lpc_current_actor_role() !== 'super_admin') {
    header('Location: clients.php?msg=error:The+niche+library+is+managed+by+the+platform+only');
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

$editing = null;
if (!empty($_GET['edit'])) {
    $editing = niche_library_load($_GET['edit']);
}
$creatingNew = isset($_GET['new']);

$niches = [];
foreach (niche_library_list() as $id) {
    $n = niche_library_load($id);
    if ($n) $niches[] = $n;
}

$form = $editing ?: ($creatingNew ? niche_library_blank('') : null);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Niche Library — <?= h(SITE_TITLE) ?></title>
<link rel="stylesheet" href="../assets/css/style.css">
<?php $_adminCssMtime = css_minify_to(__DIR__ . '/../assets/css/admin.src.css', __DIR__ . '/../assets/css/admin.css'); ?>
<link rel="stylesheet" href="../assets/css/admin.css?v=<?= (int) $_adminCssMtime ?>">
<style>
.lpc-wrap     { max-width: 900px; margin: 0 auto; padding: 32px 24px 64px; }
.lpc-form-row { display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end; margin-top:14px; }
.lpc-form-row .form-group { margin:0; flex:1; min-width:150px; }
.lpc-form-row label { font-size:.82rem; font-weight:600; color:#374151; display:block; margin-bottom:4px; }
.lpc-form-row input, .lpc-form-row textarea { width:100%; padding:8px 10px; border:1px solid #d1d5db; border-radius:6px; font-size:.85rem; font-family:inherit; }
.lpc-row { background:#fff; border:1px solid #e5e7eb; border-radius:8px; padding:12px 16px; margin-bottom:8px; display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; }
.lpc-row .meta { font-size:.8rem; color:#6b7280; }
.nl-repeat-row { display:flex; gap:8px; margin-bottom:6px; }
.nl-repeat-row input { flex:1; }
</style>
</head>
<body class="admin-body">
<div style="background:#1e293b; color:#fff; padding:14px 24px; display:flex; align-items:center; justify-content:space-between;">
    <div style="font-weight:700; font-size:1rem; letter-spacing:.01em;">&#9776; Site Factory</div>
    <div>
        <a href="niche_profiles.php" style="color:#94a3b8; font-size:.82rem; text-decoration:none; margin-right:16px;">LPC Niche Profiles</a>
        <a href="pluscities_clients.php" style="color:#94a3b8; font-size:.82rem; text-decoration:none;">&larr; PlusCities</a>
    </div>
</div>

<div class="lpc-wrap">

    <?php if ($alert): ?>
        <div class="alert alert-<?= h($alert['type']) ?>"><?= h($alert['text']) ?></div>
    <?php endif; ?>

    <h1 style="font-size:1.3rem;">Niche Library</h1>
    <p style="color:#6b7280;font-size:.85rem;">
        The facts about a niche that are true no matter who's running the business — what pages/services
        it has, each one's natural keyword, what's locally unique about it, blog ideas, and what extra
        research to gather per city. Write a niche here once; pull its facts into the fleet's Niche Brief
        and/or LPC's Niche Profile from there — each still adds its own tone/guardrails on top.
        See <code>docs/lpc-vs-site-factory-boundary.md</code> for why these stay separate.
    </p>

    <?php if (!$form): ?>
        <?php foreach ($niches as $n): ?>
            <div class="lpc-row">
                <div>
                    <strong><?= h($n['label'] ?: $n['niche_id']) ?></strong>
                    <div class="meta"><?= count($n['services']) ?> service(s) &middot; <?= count($n['blog_topics']) ?> blog idea(s)</div>
                </div>
                <div style="display:flex;gap:8px;">
                    <a href="?edit=<?= h($n['niche_id']) ?>" class="btn-sm-outline">Edit</a>
                    <form method="post" action="niche_library_save.php" style="margin:0;" onsubmit="return confirm('Delete this niche from the library? Niches already pulled into the fleet or LPC are unaffected.');">
                        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="niche_id" value="<?= h($n['niche_id']) ?>">
                        <button type="submit" class="btn-sm-danger">Delete</button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
        <a href="?new=1" class="btn-open" style="margin-top:18px;display:inline-block;">+ Add a Niche</a>

    <?php else: ?>

        <?php if (!$editing): ?>
        <div class="card" style="margin-top:18px;background:#f8fafc;">
            <h3 style="margin:0 0 6px;font-size:1rem;">Draft with AI</h3>
            <p style="color:#6b7280;font-size:.82rem;margin:0 0 8px;">
                Describe the niche in plain English — what it is, what makes a city's version of it
                different, any services or angles you already know you want. The draft below is a
                starting point to review and correct, not a final answer.
            </p>
            <textarea id="nl-draft-input" style="width:100%;min-height:90px;padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:.85rem;font-family:inherit;" placeholder="e.g. HVAC repair and installation — heating and air conditioning. What's unique per city is how brutal the summer/winter extremes are, which drives both failure rates and seasonal demand..."></textarea>
            <button type="button" class="btn-sm-outline" style="margin-top:8px;" onclick="nlDraft()">Draft with AI</button>
            <span id="nl-draft-status" style="font-size:.8rem;color:#6b7280;margin-left:8px;"></span>
        </div>
        <?php endif; ?>

        <h3 style="margin-top:28px;"><?= $editing ? 'Edit' : 'Add a' ?> Niche</h3>
        <form method="post" action="niche_library_save.php" class="card" id="nl-form">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="save">
            <?php if ($editing): ?><input type="hidden" name="existing_niche_id" value="<?= h($editing['niche_id']) ?>"><?php endif; ?>

            <div class="lpc-form-row">
                <div class="form-group"><label>Niche ID <span style="font-weight:400;color:#6b7280;">(slug, can't change after creating)</span></label>
                    <input type="text" name="niche_id" id="nl-niche-id" required placeholder="e.g. hvac-repair" value="<?= h($form['niche_id']) ?>" <?= $editing ? 'readonly style="background:#f3f4f6;"' : '' ?>></div>
                <div class="form-group"><label>Label</label><input type="text" name="label" id="nl-label" required placeholder="e.g. HVAC Repair &amp; Installation" value="<?= h($form['label']) ?>"></div>
            </div>
            <div class="lpc-form-row">
                <div class="form-group"><label>Service Noun</label><input type="text" name="service_noun" id="nl-service-noun" placeholder="e.g. HVAC repair" value="<?= h($form['service_noun']) ?>"></div>
                <div class="form-group"><label>Customer Noun</label><input type="text" name="customer_noun" id="nl-customer-noun" value="<?= h($form['customer_noun']) ?>"></div>
                <div class="form-group"><label>Tone</label><input type="text" name="tone" id="nl-tone" value="<?= h($form['tone']) ?>"></div>
            </div>
            <div class="form-group" style="margin-top:10px;">
                <label>Local Angle <span style="font-weight:400;color:#6b7280;">(what's locally unique about this niche — climate, housing stock, regulation, whatever actually varies by city)</span></label>
                <textarea name="local_angle" id="nl-local-angle" style="min-height:60px;"><?= h($form['local_angle']) ?></textarea>
            </div>
            <div class="form-group" style="margin-top:10px;">
                <label>Seasonal Calendar <span style="font-weight:400;color:#6b7280;">(when demand/failure patterns peak through the year)</span></label>
                <textarea name="seasonal_calendar" id="nl-seasonal-calendar" style="min-height:50px;"><?= h($form['seasonal_calendar']) ?></textarea>
            </div>

            <h4 style="margin-top:18px;margin-bottom:4px;">Services / Pages</h4>
            <p style="color:#6b7280;font-size:.8rem;margin:0 0 8px;">
                The starting catalog for this niche. Keyword Pattern is optional — blank uses the
                standard "{service} in {city}, {state}". Real researched search volume/competition is
                deliberately NOT here — that's fleet-only, fetched later, per domain.
            </p>
            <div id="nl-svc-rows">
                <?php foreach ($form['services'] as $s): ?>
                <div class="nl-repeat-row">
                    <input type="text" name="svc_label[]" placeholder="Service label" value="<?= h($s['label']) ?>">
                    <input type="text" name="svc_slug[]" placeholder="slug" value="<?= h($s['slug']) ?>">
                    <input type="text" name="svc_pattern[]" placeholder="Keyword pattern (optional)" value="<?= h($s['keyword_pattern'] ?? '') ?>">
                    <button type="button" class="btn-sm-danger" onclick="this.parentElement.remove()">&times;</button>
                </div>
                <?php endforeach; ?>
            </div>
            <button type="button" class="btn-sm-outline" onclick="nlAddRow('nl-svc-rows','<input type=\'text\' name=\'svc_label[]\' placeholder=\'Service label\'><input type=\'text\' name=\'svc_slug[]\' placeholder=\'slug\'><input type=\'text\' name=\'svc_pattern[]\' placeholder=\'Keyword pattern (optional)\'>')">+ Add Service</button>

            <h4 style="margin-top:18px;margin-bottom:4px;">Trust Bar Phrases <span style="font-weight:400;color:#6b7280;font-size:.78rem;">(one per line)</span></h4>
            <div class="form-group">
                <textarea name="trust_bar_items" id="nl-trust-bar" style="min-height:70px;"><?= h(implode("\n", $form['trust_bar_items'])) ?></textarea>
            </div>

            <h4 style="margin-top:18px;margin-bottom:4px;">Match Keywords <span style="font-weight:400;color:#6b7280;font-size:.78rem;">(comma-separated — LPC uses these to auto-match a new client's industry text; the fleet ignores this)</span></h4>
            <div class="form-group">
                <input type="text" name="match_keywords" id="nl-match-keywords" value="<?= h(implode(', ', $form['match_keywords'])) ?>">
            </div>

            <h4 style="margin-top:18px;margin-bottom:4px;">Extra Per-City Research <span style="font-weight:400;color:#6b7280;font-size:.78rem;">(beyond the standard population/industries/neighborhoods facts every niche already gets)</span></h4>
            <div id="nl-research-rows">
                <?php foreach ($form['custom_research_fields'] as $f): ?>
                <div class="nl-repeat-row">
                    <input type="text" name="research_key[]" placeholder="key (e.g. climate_zone)" value="<?= h($f['key']) ?>">
                    <input type="text" name="research_ask[]" placeholder="what to ask for" value="<?= h($f['ask']) ?>">
                    <button type="button" class="btn-sm-danger" onclick="this.parentElement.remove()">&times;</button>
                </div>
                <?php endforeach; ?>
            </div>
            <button type="button" class="btn-sm-outline" onclick="nlAddRow('nl-research-rows','<input type=\'text\' name=\'research_key[]\' placeholder=\'key (e.g. climate_zone)\'><input type=\'text\' name=\'research_ask[]\' placeholder=\'what to ask for\'>')">+ Add Research Field</button>

            <h4 style="margin-top:18px;margin-bottom:4px;">Blog Topic Ideas</h4>
            <p style="color:#6b7280;font-size:.8rem;margin:0 0 8px;">
                Related Topics references OTHER blog topics by their slug, never a URL — each side turns
                that into its own real link using its own existing "never link to a page that doesn't
                exist" rule.
            </p>
            <div id="nl-blog-rows">
                <?php foreach ($form['blog_topics'] as $b): ?>
                <div class="nl-repeat-row">
                    <input type="text" name="blog_title[]" placeholder="Title" value="<?= h($b['title']) ?>">
                    <input type="text" name="blog_slug[]" placeholder="slug" value="<?= h($b['slug']) ?>">
                    <input type="text" name="blog_keyword[]" placeholder="Focus keyword" value="<?= h($b['focus_keyword'] ?? '') ?>">
                    <input type="text" name="blog_tag[]" placeholder="Tag" value="<?= h($b['tag'] ?? '') ?>">
                    <input type="text" name="blog_related[]" placeholder="Related slugs, comma-separated" value="<?= h(implode(', ', $b['related_topics'] ?? [])) ?>">
                    <button type="button" class="btn-sm-danger" onclick="this.parentElement.remove()">&times;</button>
                </div>
                <?php endforeach; ?>
            </div>
            <button type="button" class="btn-sm-outline" onclick="nlAddRow('nl-blog-rows','<input type=\'text\' name=\'blog_title[]\' placeholder=\'Title\'><input type=\'text\' name=\'blog_slug[]\' placeholder=\'slug\'><input type=\'text\' name=\'blog_keyword[]\' placeholder=\'Focus keyword\'><input type=\'text\' name=\'blog_tag[]\' placeholder=\'Tag\'><input type=\'text\' name=\'blog_related[]\' placeholder=\'Related slugs, comma-separated\'>')">+ Add Blog Topic</button>

            <div style="margin-top:18px;">
                <button type="submit" class="btn-open"><?= $editing ? 'Save Changes' : 'Add Niche' ?></button>
                <a href="niche_library.php" class="btn-sm-outline" style="margin-left:8px;">Cancel</a>
            </div>
        </form>

    <?php endif; ?>

</div>
<script>
function nlAddRow(containerId, innerHtml) {
    var row = document.createElement('div');
    row.className = 'nl-repeat-row';
    row.innerHTML = innerHtml + '<button type="button" class="btn-sm-danger" onclick="this.parentElement.remove()">&times;</button>';
    document.getElementById(containerId).appendChild(row);
}

var NL_CSRF = <?= json_encode($csrf) ?>;

function nlAddSvcRow(s) {
    nlAddRow('nl-svc-rows', '');
    var rows = document.querySelectorAll('#nl-svc-rows .nl-repeat-row');
    var row = rows[rows.length - 1];
    row.innerHTML = '<input type="text" name="svc_label[]" placeholder="Service label" value="' + (s.label || '').replace(/"/g, '&quot;') + '">'
        + '<input type="text" name="svc_slug[]" placeholder="slug" value="' + (s.slug || '').replace(/"/g, '&quot;') + '">'
        + '<input type="text" name="svc_pattern[]" placeholder="Keyword pattern (optional)" value="' + (s.keyword_pattern || '').replace(/"/g, '&quot;') + '">'
        + '<button type="button" class="btn-sm-danger" onclick="this.parentElement.remove()">&times;</button>';
}
function nlAddResearchRow(f) {
    nlAddRow('nl-research-rows', '');
    var rows = document.querySelectorAll('#nl-research-rows .nl-repeat-row');
    var row = rows[rows.length - 1];
    row.innerHTML = '<input type="text" name="research_key[]" value="' + (f.key || '').replace(/"/g, '&quot;') + '">'
        + '<input type="text" name="research_ask[]" value="' + (f.ask || '').replace(/"/g, '&quot;') + '">'
        + '<button type="button" class="btn-sm-danger" onclick="this.parentElement.remove()">&times;</button>';
}
function nlAddBlogRow(b) {
    nlAddRow('nl-blog-rows', '');
    var rows = document.querySelectorAll('#nl-blog-rows .nl-repeat-row');
    var row = rows[rows.length - 1];
    row.innerHTML = '<input type="text" name="blog_title[]" value="' + (b.title || '').replace(/"/g, '&quot;') + '">'
        + '<input type="text" name="blog_slug[]" value="' + (b.slug || '').replace(/"/g, '&quot;') + '">'
        + '<input type="text" name="blog_keyword[]" value="' + (b.focus_keyword || '').replace(/"/g, '&quot;') + '">'
        + '<input type="text" name="blog_tag[]" value="' + (b.tag || '').replace(/"/g, '&quot;') + '">'
        + '<input type="text" name="blog_related[]" value="' + ((b.related_topics || []).join(', ')).replace(/"/g, '&quot;') + '">'
        + '<button type="button" class="btn-sm-danger" onclick="this.parentElement.remove()">&times;</button>';
}

function nlDraft() {
    var desc = document.getElementById('nl-draft-input').value.trim();
    if (!desc) { alert('Describe the niche first.'); return; }
    var status = document.getElementById('nl-draft-status');
    status.textContent = 'Drafting…';
    var fd = new FormData();
    fd.append('csrf_token', NL_CSRF);
    fd.append('description', desc);
    fetch('niche_library_draft.php', {method: 'POST', body: fd})
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (d.error) { status.textContent = ''; alert(d.error); return; }
            status.textContent = 'Drafted — review every field below before saving.';
            var n = d.niche;
            if (!document.getElementById('nl-niche-id').readOnly) document.getElementById('nl-niche-id').value = n.niche_id || '';
            document.getElementById('nl-label').value = n.label || '';
            document.getElementById('nl-service-noun').value = n.service_noun || '';
            document.getElementById('nl-customer-noun').value = n.customer_noun || '';
            document.getElementById('nl-tone').value = n.tone || '';
            document.getElementById('nl-local-angle').value = n.local_angle || '';
            document.getElementById('nl-seasonal-calendar').value = n.seasonal_calendar || '';
            document.getElementById('nl-trust-bar').value = (n.trust_bar_items || []).join('\n');
            document.getElementById('nl-match-keywords').value = (n.match_keywords || []).join(', ');
            document.getElementById('nl-svc-rows').innerHTML = '';
            (n.services || []).forEach(nlAddSvcRow);
            document.getElementById('nl-research-rows').innerHTML = '';
            (n.custom_research_fields || []).forEach(nlAddResearchRow);
            document.getElementById('nl-blog-rows').innerHTML = '';
            (n.blog_topics || []).forEach(nlAddBlogRow);
        })
        .catch(function (e) { status.textContent = ''; alert('Could not draft: ' + e); });
}
</script>
</body>
</html>
