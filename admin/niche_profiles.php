<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

if (empty($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}
// Niches are global, shared templates every agency's clients draw from —
// Scott-only by decision (not agencies), so one agency can't silently change
// AI output for every OTHER agency's clients in that niche. A real agency
// session is unrestricted here only in the sense that lpc_current_actor_role()
// defaults to 'super_admin' until a login flow actually sets 'agency_admin'.
if (lpc_current_actor_role() !== 'super_admin') {
    header('Location: clients.php?msg=error:Niche+profiles+are+managed+by+the+platform+only');
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
    $editing = lpc_load_niche_profile($_GET['edit']);
}

$niches = [];
foreach (lpc_list_niche_profiles() as $id) {
    $p = lpc_load_niche_profile($id);
    if ($p) $niches[] = $p;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Niche Profiles — <?= h(SITE_TITLE) ?></title>
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

    <h1 style="font-size:1.3rem;">Niche Profiles</h1>
    <p style="color:#6b7280;font-size:.85rem;">
        Reusable AI content guardrails/tone per industry. A new client's industry category is matched
        against these automatically at creation (Content Profile's read-only "Base Profile"). Add a new
        one here any time you take on a client in an industry not covered yet — no code change needed.
    </p>

    <?php foreach ($niches as $p): ?>
        <div class="lpc-row">
            <div>
                <strong><?= h($p['label'] ?: $p['niche_id']) ?></strong>
                <div class="meta">matches: <?= h(implode(', ', $p['match_keywords'])) ?></div>
            </div>
            <div style="display:flex;gap:8px;">
                <a href="?edit=<?= h($p['niche_id']) ?>" class="btn-sm-outline">Edit</a>
                <form method="post" action="niche_profiles_save.php" style="margin:0;" onsubmit="return confirm('Delete this niche profile?');">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="niche_id" value="<?= h($p['niche_id']) ?>">
                    <button type="submit" class="btn-sm-danger">Delete</button>
                </form>
            </div>
        </div>
    <?php endforeach; ?>

    <h3 style="margin-top:28px;"><?= $editing ? 'Edit' : 'Add a' ?> Niche Profile</h3>
    <form method="post" action="niche_profiles_save.php" class="card">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="save">
        <?php if ($editing): ?><input type="hidden" name="existing_niche_id" value="<?= h($editing['niche_id']) ?>"><?php endif; ?>
        <div class="lpc-form-row">
            <div class="form-group"><label>Label</label><input type="text" name="label" required placeholder="e.g. HVAC Repair" value="<?= h($editing['label'] ?? '') ?>"></div>
            <div class="form-group"><label>Match Keywords <span style="font-weight:400;color:#6b7280;">(comma-separated)</span></label><input type="text" name="match_keywords" placeholder="e.g. hvac, heating, air conditioning" value="<?= h(implode(', ', $editing['match_keywords'] ?? [])) ?>"></div>
        </div>
        <div class="lpc-form-row">
            <div class="form-group"><label>Business Descriptor</label><input type="text" name="business_descriptor" placeholder="e.g. a company providing HVAC installation and repair services" value="<?= h($editing['business_descriptor'] ?? '') ?>"></div>
            <div class="form-group"><label>Default Tone</label><input type="text" name="default_tone" placeholder="e.g. practical and reassuring" value="<?= h($editing['default_tone'] ?? 'professional') ?>"></div>
        </div>
        <div class="form-group" style="margin-top:10px;">
            <label>Guardrails <span style="font-weight:400;color:#6b7280;">(industry-specific accuracy/safety rules for the AI — never a referral-network framing, these clients do their own work)</span></label>
            <textarea name="guardrails" style="min-height:90px;"><?= h($editing['guardrails'] ?? '') ?></textarea>
        </div>

        <h4 style="margin-top:18px;margin-bottom:4px;">Content Slots</h4>
        <p style="color:#6b7280;font-size:.8rem;margin:0 0 8px;">
            Every page has the same 6 flexible content slots, but what they're ABOUT is niche-specific —
            a restoration niche's "local relevance" slot is climate/flood-risk; a legal niche's might be
            local court/regulatory relevance instead. Leave blank to use a generic fallback instruction.
        </p>
        <?php $slots = array_merge(lpc_default_content_slots(), $editing['content_slots'] ?? []); ?>
        <div class="form-group" style="margin-top:8px;">
            <label>Feature Columns Local <span style="font-weight:400;color:#6b7280;">(why/how this service matters in this specific city)</span></label>
            <input type="text" name="slot_feature_columns_local" value="<?= h($slots['feature_columns_local']) ?>">
        </div>
        <div class="form-group" style="margin-top:8px;">
            <label>Local Relevance <span style="font-weight:400;color:#6b7280;">(what real local data this niche should reference, and how)</span></label>
            <input type="text" name="slot_local_relevance" value="<?= h($slots['local_relevance']) ?>">
        </div>
        <div class="form-group" style="margin-top:8px;">
            <label>Seasonal Calendar <span style="font-weight:400;color:#6b7280;">(when demand for this service peaks through the year)</span></label>
            <input type="text" name="slot_seasonal_calendar" value="<?= h($slots['seasonal_calendar']) ?>">
        </div>
        <div class="form-group" style="margin-top:8px;">
            <label>Why Choose Us <span style="font-weight:400;color:#6b7280;">(local trust/credibility framing specific to this niche)</span></label>
            <input type="text" name="slot_why_choose_us" value="<?= h($slots['why_choose_us']) ?>">
        </div>
        <div class="form-group" style="margin-top:8px;">
            <label>How It Works <span style="font-weight:400;color:#6b7280;">(the real step-by-step process a customer goes through)</span></label>
            <input type="text" name="slot_how_it_works" value="<?= h($slots['how_it_works']) ?>">
        </div>
        <div class="form-group" style="margin-top:8px;">
            <label>Comparison Block <span style="font-weight:400;color:#6b7280;">(the two-column "this vs that" educational block — what the two columns represent, specific to this niche)</span></label>
            <input type="text" name="slot_comparison" value="<?= h($slots['comparison']) ?>">
        </div>

        <h4 style="margin-top:18px;margin-bottom:4px;">Default Services</h4>
        <p style="color:#6b7280;font-size:.8rem;margin:0 0 8px;">
            A typical service list for this vertical — offered as a checkbox selection in a new
            client's wizard (never auto-added), not forced. Keyword Pattern is optional; leave blank to
            use the standard "{service} in {city}, {state}" default. Supports {service}/{city}/{state}.
        </p>
        <div id="niche-svc-rows">
            <?php foreach ($editing['default_services'] ?? [] as $ds): ?>
            <div class="lpc-repeat-row" style="display:flex;gap:8px;margin-bottom:6px;">
                <input type="text" name="ds_label[]" placeholder="Service label" value="<?= h($ds['label']) ?>" style="flex:1;">
                <input type="text" name="ds_pattern[]" placeholder="Keyword pattern (optional)" value="<?= h($ds['keyword_pattern'] ?? '') ?>" style="flex:1;">
                <button type="button" class="btn-sm-danger" onclick="this.parentElement.remove()">&times;</button>
            </div>
            <?php endforeach; ?>
        </div>
        <button type="button" class="btn-sm-outline" onclick="lpcAddNicheSvcRow()">+ Add Default Service</button>

        <h4 style="margin-top:18px;margin-bottom:4px;">Trust Bar</h4>
        <p style="color:#6b7280;font-size:.8rem;margin:0 0 8px;">
            Short checkmark phrases shown in a bar right under the hero on every page (e.g. "24/7
            Response Line"). One per line. Leave blank to use a generic fallback.
        </p>
        <div class="form-group">
            <textarea name="trust_bar_items" style="min-height:90px;" placeholder="Fast Local Response&#10;24/7 Response Line&#10;Licensed &amp; Insured&#10;No-Cost To Connect"><?= h(implode("\n", $editing['trust_bar_items'] ?? [])) ?></textarea>
        </div>

        <button type="submit" class="btn-open" style="margin-top:10px;display:block;"><?= $editing ? 'Save Changes' : 'Add Niche Profile' ?></button>
        <?php if ($editing): ?><a href="niche_profiles.php" class="btn-sm-outline" style="margin-top:10px;margin-left:8px;">Cancel</a><?php endif; ?>
    </form>

</div>
<script>
function lpcAddNicheSvcRow() {
    var row = document.createElement('div');
    row.className = 'lpc-repeat-row';
    row.style.cssText = 'display:flex;gap:8px;margin-bottom:6px;';
    row.innerHTML = '<input type="text" name="ds_label[]" placeholder="Service label" style="flex:1;">'
        + '<input type="text" name="ds_pattern[]" placeholder="Keyword pattern (optional)" style="flex:1;">'
        + '<button type="button" class="btn-sm-danger" onclick="this.parentElement.remove()">&times;</button>';
    document.getElementById('niche-svc-rows').appendChild(row);
}
</script>
</body>
</html>
