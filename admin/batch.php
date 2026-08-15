<?php
/**
 * Batch page — one multisite batch: its target list, its runs, its history.
 *
 * This is deliberately its own page rather than a tab inside a site. Running a batch
 * used to mean "become the master site, then act"; now you just open the batch. The
 * master is still remembered in the session (the build needs its niche brief, theme
 * presets and icons) — it just isn't where you stand.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/multisite/batch.php';

if (empty($_SESSION['admin_logged_in'])) { header('Location: login.php'); exit; }
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrfToken = $_SESSION['csrf_token'];

// Opening by URL (batch.php?site=pest-template&id=b2): record it, then redirect to the
// clean URL so ACTIVE_SITE_DIR is rebuilt from the session on the next request.
$qSite  = trim((string) ($_GET['site'] ?? ''));
$qBatch = trim((string) ($_GET['id']   ?? ''));
if ($qSite !== '' && $qBatch !== '') {
    if (ms_batch_exists($qSite, $qBatch)) {
        $_SESSION['active_site']  = $qSite;
        $_SESSION['active_batch'] = $qBatch;
    }
    header('Location: batch.php');
    exit;
}

$active = ms_active_batch();
if (!$active) { header('Location: sites.php'); exit; }

$masterId = $active['master_id'];
$batchId  = $active['batch_id'];
$batch    = ms_batch_meta($masterId, $batchId) ?: ['name' => $batchId];
$status   = ms_batch_status($masterId, $batchId);

$masterMeta = @json_decode((string) @file_get_contents(BASE_DIR . '/sites/' . $masterId . '/meta.json'), true) ?: [];
$masterName = $masterMeta['name'] ?? $masterId;

// Research panel only shows when the master's niche brief asks for it.
$nicheBrief = @json_decode((string) @file_get_contents(ms_master_dir($masterId) . '/niche_brief.json'), true) ?: [];
$researchOn = !empty($nicheBrief['uses_research_fields']);

$allMasters = [];
foreach (glob(BASE_DIR . '/sites/*', GLOB_ONLYDIR) ?: [] as $d) {
    $id = basename($d);
    if (!ms_valid_master_id($id)) continue;
    $m = @json_decode((string) @file_get_contents($d . '/meta.json'), true) ?: [];
    $allMasters[$id] = $m['name'] ?? $id;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h($batch['name']) ?> — Batch — <?= h(SITE_TITLE) ?></title>
<link rel="stylesheet" href="../assets/css/style.css">
<style>
.bp-master   { display:flex; align-items:center; gap:10px; flex-wrap:wrap; font-size:.85rem; color:#475569; margin:0 0 20px; }
.bp-chip     { background:#eef2ff; color:#3730a3; border-radius:5px; padding:3px 9px; font-weight:600; font-size:.8rem; }
.bp-stat     { display:flex; gap:22px; flex-wrap:wrap; margin:0 0 20px; font-size:.86rem; color:#475569; }
.bp-stat b   { color:#0f172a; font-size:1.05rem; display:block; }
</style>
</head>
<body class="admin-body">
<div class="admin-wrapper">

    <div class="admin-header">
        <h1>
            <a href="sites.php" style="color:inherit;text-decoration:none;font-size:0.75em;opacity:0.7;margin-right:10px;" title="Site Factory">&#8592; Factory</a>
            <?= h($batch['name']) ?>
        </h1>
        <div>
            <a href="docs.php?doc=multisite" target="_blank" class="preview-link">MultiSite docs &rarr;</a>
            &nbsp;|&nbsp;
            <a href="logout.php">Log out</a>
        </div>
    </div>

    <p class="bp-master">
        <span class="bp-chip">Batch</span>
        Copies from
        <strong><?= h($masterName) ?></strong>
        <code style="font-size:.78rem;"><?= h($masterId) ?></code>
        <a href="index.php?tab=content">edit this master &rarr;</a>
        <span style="color:#cbd5e1;">|</span>
        <a href="#" onclick="bpChangeMaster();return false;">change master</a>
    </p>

    <?php include __DIR__ . '/_batch_phases.php'; ?>

    <div class="bp-stat">
        <span><b><?= (int) $status['targets'] ?></b> targets</span>
        <?php if ($status['has_run']): ?>
        <span><b><?= (int) $status['ok'] ?></b> built</span>
        <span><b><?= (int) $status['failed'] ?></b> failed</span>
        <span><b>$<?= number_format((float) $status['cost'], 2) ?></b> spent</span>
        <span><b><?= h($status['state']) ?></b> last run</span>
        <?php else: ?>
        <span style="color:#94a3b8;">Never run</span>
        <?php endif; ?>
    </div>

    <?php include __DIR__ . '/_batch_steps.php'; ?>

    <?php include __DIR__ . '/_batch_panels.php'; ?>

</div>

<script>
const BP_CSRF     = <?= json_encode($csrfToken) ?>;
const BP_MASTER   = <?= json_encode($masterId) ?>;
const BP_BATCH    = <?= json_encode($batchId) ?>;
const BP_MASTERS  = <?= json_encode($allMasters) ?>;

async function bpPost(data) {
    const fd = new FormData();
    fd.append('csrf_token', BP_CSRF);
    for (const [k, v] of Object.entries(data)) fd.append(k, v);
    const r = await fetch('batch_api.php', { method: 'POST', body: fd });
    return r.json();
}

// Re-point this batch at a different master. Guards live in batch_api.php — this only
// collects the choice and surfaces whatever warnings come back.
async function bpChangeMaster() {
    const ids = Object.keys(BP_MASTERS).filter(id => id !== BP_MASTER);
    if (!ids.length) { alert('There is no other site to use as a master.'); return; }
    const list = ids.map((id, i) => (i + 1) + '. ' + BP_MASTERS[id] + '  (' + id + ')').join('\n');
    const pick = prompt('Change this batch\'s master.\n\n' + list + '\n\nType the number:');
    if (!pick) return;
    const target = ids[parseInt(pick, 10) - 1];
    if (!target) { alert('No master picked.'); return; }

    const chk = await bpPost({ action: 'swap_master_check', master_id: BP_MASTER, batch_id: BP_BATCH, new_master_id: target });
    if (chk.error) { alert(chk.error); return; }
    if ((chk.warnings || []).length && !confirm(chk.warnings.join('\n\n') + '\n\nChange the master anyway?')) return;

    const res = await bpPost({ action: 'set_master', master_id: BP_MASTER, batch_id: BP_BATCH, new_master_id: target });
    if (res.error) { alert(res.error); return; }
    window.location.href = 'batch.php?site=' + encodeURIComponent(res.master_id) + '&id=' + encodeURIComponent(res.id);
}
</script>
</body>
</html>
