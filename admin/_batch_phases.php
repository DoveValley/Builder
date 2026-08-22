<?php
/**
 * The six phases of a batch, across the top of the batch page.
 *
 * Numbered to match the six cards below 1-for-1 (Upload target list · Pick servers ·
 * Create host · Generate sites · Upload sites · Go Live) — they used to be five boxes
 * covering six cards, with "Pick servers" folded silently into "Create Host" and every
 * later number one off from the card it described.
 *
 * Each light is READ FROM STATE, not set by hand — a progress strip that has to be
 * kept up to date by a human is a progress strip that lies. Where a phase cannot be
 * known from here it says so rather than showing a colour it has not earned.
 *
 *   grey   not started
 *   green  in process
 *   blue   complete
 *   red    error / stopped
 *
 * Expects: $masterId, $batchId, $status (from ms_batch_status()).
 */
if (!isset($masterId, $batchId)) return;

require_once __DIR__ . '/../includes/multisite/batch.php';
require_once __DIR__ . '/infra/lib/pipeline.php';

const BPH_IDLE = 'idle', BPH_BUSY = 'busy', BPH_DONE = 'done', BPH_FAIL = 'fail';

$bphRun     = ms_batch_latest_run($masterId, $batchId);
$bphState   = $bphRun['state'] ?? '';
$bphRunning = $bphState === 'running';
$bphTargets = (int) ($status['targets'] ?? 0);
$bphOk      = (int) ($status['ok'] ?? 0);
$bphFailed  = (int) ($status['failed'] ?? 0);

/* Phase 3 (Create host) is answered by the target list itself: a row that carries an
   ftp_host and ftp_user has had its host created, because that is where provisioning
   writes the credentials back to. Reading the rows costs nothing; asking every box
   would cost an API call each, on every page load. */
$bphRows = 0; $bphWithHost = 0;
$bphCsv  = ms_batch_dir($masterId, $batchId) . '/params.csv';
if (is_file($bphCsv)) {
    $fh = fopen($bphCsv, 'r');
    $hd = fgetcsv($fh) ?: [];
    $ix = array_flip(array_map('trim', $hd));
    while (($r = fgetcsv($fh)) !== false) {
        if ($r === [null] || $r === false) continue;
        $bphRows++;
        $host = isset($ix['ftp_host']) ? trim((string) ($r[$ix['ftp_host']] ?? '')) : '';
        $user = isset($ix['ftp_user']) ? trim((string) ($r[$ix['ftp_user']] ?? '')) : '';
        if ($host !== '' && $user !== '') $bphWithHost++;
    }
    fclose($fh);
}

/* 1 — Upload target list. Targets exist or they do not. */
$p1 = $bphTargets > 0 ? BPH_DONE : BPH_IDLE;
$p1n = $bphTargets . ' target' . ($bphTargets === 1 ? '' : 's');

/* 2 — Pick servers. A saved plan or none — servers.json is the plan itself. */
$bphPlan = ms_batch_servers($masterId, $batchId);
$p2  = $bphPlan ? BPH_DONE : BPH_IDLE;
$p2n = $bphPlan ? count($bphPlan) . ' server' . (count($bphPlan) === 1 ? '' : 's') . ' picked' : 'no plan yet';

/* 3 — Create host. Partly done is a real and common state, not a failure. */
if ($bphWithHost === 0)              { $p3 = BPH_IDLE; $p3n = 'no hosts yet'; }
elseif ($bphWithHost < $bphRows)     { $p3 = BPH_BUSY; $p3n = $bphWithHost . ' of ' . $bphRows; }
else                                 { $p3 = BPH_DONE; $p3n = 'all ' . $bphRows; }

/* 4 — Generate. Build and deploy happen in one run per row, so both read the same
   run; they differ in what a row having no credentials means (below). */
if (!($status['has_run'] ?? false))  { $p4 = BPH_IDLE; $p4n = 'never run'; }
elseif ($bphRunning)                 { $p4 = BPH_BUSY; $p4n = 'running'; }
elseif ($bphFailed > 0)              { $p4 = BPH_FAIL; $p4n = $bphFailed . ' failed'; }
else                                 { $p4 = BPH_DONE; $p4n = $bphOk . ' built'; }

/* 5 — Upload. A row with no credentials is BUILD ONLY: the run reports ok and
   nothing was ever sent. That must not read as a successful upload. */
if (!($status['has_run'] ?? false))  { $p5 = BPH_IDLE; $p5n = 'nothing sent'; }
elseif ($bphRunning)                 { $p5 = BPH_BUSY; $p5n = 'running'; }
elseif ($bphWithHost === 0)          { $p5 = BPH_IDLE; $p5n = 'build only'; }
elseif ($bphFailed > 0)              { $p5 = BPH_FAIL; $p5n = $bphFailed . ' failed'; }
else                                 { $p5 = BPH_DONE; $p5n = $bphOk . ' uploaded'; }

/* 6 — Go live. Real now: create_hosts.php tags each domain into the Infra console's
   own registry under "master/batch", so infra_pipeline_rows() can find exactly this
   batch's domains and report the same zone/golive/dns/live cells card 6 shows. A
   domain only appears here once its host has been created at least once — before
   that there is nothing tagged yet, which is IDLE, not a lie about zero being fine. */
$bphGoLive    = infra_pipeline_rows($masterId . '/' . $batchId);
$bphGLTotal   = count($bphGoLive);
$bphGLLive    = 0; $bphGLFail = false;
foreach ($bphGoLive as $r) {
    if (($r['cells']['live']['state'] ?? '') === INFRA_STEP_OK) $bphGLLive++;
    if ($r['worst'] === INFRA_STEP_FAIL) $bphGLFail = true;
}
if ($bphGLTotal === 0)                { $p6 = BPH_IDLE; $p6n = 'not started'; }
elseif ($bphGLLive === $bphGLTotal)   { $p6 = BPH_DONE; $p6n = 'all ' . $bphGLTotal . ' live'; }
elseif ($bphGLFail)                   { $p6 = BPH_FAIL; $p6n = $bphGLLive . ' of ' . $bphGLTotal . ' live'; }
else                                   { $p6 = BPH_BUSY; $p6n = $bphGLLive . ' of ' . $bphGLTotal . ' live'; }

$bphPhases = [
    ['n' => 1, 'label' => 'Upload Target List', 'st' => $p1, 'note' => $p1n],
    ['n' => 2, 'label' => 'Pick Servers',        'st' => $p2, 'note' => $p2n],
    ['n' => 3, 'label' => 'Create Host',         'st' => $p3, 'note' => $p3n],
    ['n' => 4, 'label' => 'Generate Sites',      'st' => $p4, 'note' => $p4n],
    ['n' => 5, 'label' => 'Upload Sites',        'st' => $p5, 'note' => $p5n],
    ['n' => 6, 'label' => 'Go Live (DNS)',       'st' => $p6, 'note' => $p6n],
];

$bphStyle = [
    BPH_IDLE => ['#94a3b8', '#f1f5f9', '#e2e8f0', 'not started'],
    BPH_BUSY => ['#15803d', '#dcfce7', '#86efac', 'in process'],
    BPH_DONE => ['#1d4ed8', '#dbeafe', '#93c5fd', 'complete'],
    BPH_FAIL => ['#b91c1c', '#fee2e2', '#fca5a5', 'stopped'],
];
?>
<style>
.bph        { display:grid; grid-template-columns:repeat(6,1fr); gap:10px; margin:0 0 22px; }
.bph-item   { background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:14px 12px; text-align:center; }
.bph-name   { font-size:.84rem; font-weight:700; color:#0f172a; line-height:1.3; }
.bph-num    { color:#94a3b8; font-weight:800; margin-right:4px; }
.bph-dot    { width:30px; height:30px; border-radius:50%; margin:10px auto 6px; display:flex;
              align-items:center; justify-content:center; font-size:.9rem; font-weight:800; }
.bph-state  { font-size:.68rem; text-transform:uppercase; letter-spacing:.05em; font-weight:700; }
.bph-note   { font-size:.74rem; color:#64748b; margin-top:3px; }
.bph-arrow  { display:none; }
@media (max-width:900px){ .bph{ grid-template-columns:repeat(3,1fr); } }
@media (max-width:560px){ .bph{ grid-template-columns:repeat(2,1fr); } }
</style>

<div class="bph">
    <?php foreach ($bphPhases as $p):
        [$fg, $bg, $bd, $word] = $bphStyle[$p['st']];
        // A tick for complete, a spinner-ish dot for running, a cross for stopped —
        // so the strip still reads correctly in greyscale or to a colourblind eye.
        $glyph = $p['st'] === BPH_DONE ? '&#10003;' : ($p['st'] === BPH_FAIL ? '&#10005;'
               : ($p['st'] === BPH_BUSY ? '&#9679;' : '&#9675;'));
    ?>
    <div class="bph-item" style="border-color:<?= $bd ?>">
        <div class="bph-name"><span class="bph-num"><?= (int) $p['n'] ?>.</span><?= h($p['label']) ?></div>
        <div class="bph-dot" style="background:<?= $bg ?>;color:<?= $fg ?>;border:1px solid <?= $bd ?>"><?= $glyph ?></div>
        <div class="bph-state" style="color:<?= $fg ?>"><?= h($word) ?></div>
        <div class="bph-note"><?= h($p['note']) ?></div>
    </div>
    <?php endforeach; ?>
</div>
