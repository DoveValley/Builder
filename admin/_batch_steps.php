<?php
/**
 * "What a run does" — the documented steps of a batch run, and whether each is set up.
 *
 * Replaces (and upgrades) the old MultiSite tab's static "How a multisite run works"
 * explainer: same list, but each step now reports a fact read from the master and this
 * batch's target list.
 *
 * Warn only — nothing here blocks a run. A grey "skipped" row is usually deliberate
 * (building for review without deploying is a real workflow), so it is not styled as
 * a problem.
 *
 * Expects: $masterId, $batchId
 */
if (!isset($masterId, $batchId)) return;

require_once __DIR__ . '/../includes/multisite/steps.php';

$bsSteps = ms_run_steps();
$bsReady = ms_step_readiness($masterId, $batchId);
$bsRun   = ms_batch_latest_run($masterId, $batchId);
$bsRan   = ms_step_execution($bsRun);
$bsHasRun = $bsRun !== null && !empty($bsRun['results']);

$bsWarnCount = count(array_filter($bsReady, fn($c) => $c['state'] === MS_STEP_WARN));
$bsSkipCount = count(array_filter($bsReady, fn($c) => $c['state'] === MS_STEP_OFF));

$bsStyle = [
    MS_STEP_OK   => ['#166534', '#dcfce7', '&#10003;'],
    MS_STEP_WARN => ['#92400e', '#fef3c7', '&#9888;'],
    MS_STEP_OFF  => ['#64748b', '#f1f5f9', '&ndash;'],
];
?>
<style>
.bs-table       { width:100%; border-collapse:collapse; font-size:.88rem; }
.bs-table th    { text-align:left; padding:7px 10px; border-bottom:1px solid #e2e8f0; font-size:.76rem; text-transform:uppercase; letter-spacing:.04em; color:#64748b; font-weight:700; }
.bs-table td    { padding:11px 10px; border-bottom:1px solid #f1f5f9; vertical-align:top; }
.bs-step        { font-weight:700; color:#0f172a; }
.bs-does        { color:#475569; font-size:.83rem; margin-top:2px; }
.bs-where       { color:#94a3b8; font-size:.78rem; margin-top:3px; }
.bs-pill        { display:inline-block; font-size:.72rem; font-weight:700; padding:2px 8px; border-radius:4px; margin-right:8px; white-space:nowrap; }
.bs-fact        { color:#0f172a; }
.bs-note        { color:#64748b; font-size:.8rem; margin-top:3px; }
.bs-soon        { color:#cbd5e1; font-size:.8rem; font-style:italic; }
.bs-items       { margin:9px 0 0; border-collapse:collapse; width:100%; }
.bs-items td    { padding:2px 0; border:0; font-size:.79rem; vertical-align:top; }
.bs-i-col       { width:92px; white-space:nowrap; }
.bs-i-col code  { font-size:.76rem; background:#f1f5f9; padding:1px 5px; border-radius:3px; color:#334155; }
.bs-i-opt       { color:#cbd5e1; font-size:.68rem; }
.bs-i-drives    { color:#64748b; padding-right:10px !important; }
.bs-i-n         { width:56px; text-align:right; white-space:nowrap; font-weight:600; }
</style>

<details class="card" open style="background:#f8fafc;border-left:3px solid #2563eb;">
    <summary style="cursor:pointer;font-weight:700;font-size:1.02rem;color:#1e3a5f;">
        What a run does &mdash; and what's set up
        <?php if ($bsWarnCount): ?>
        <span class="bs-pill" style="color:#92400e;background:#fef3c7;margin-left:8px;"><?= $bsWarnCount ?> to look at</span>
        <?php endif; ?>
        <?php if ($bsSkipCount): ?>
        <span class="bs-pill" style="color:#64748b;background:#f1f5f9;"><?= $bsSkipCount ?> will be skipped</span>
        <?php endif; ?>
    </summary>

    <p class="hint" style="margin:12px 0 4px;">
        Every site in this batch goes through these steps, in this order. The right-hand column is read from
        the master and this batch's target list <strong>before anything runs</strong> &mdash; so you can see what
        will be skipped without building 50 sites first.
    </p>
    <p class="hint" style="margin:0 0 14px;">
        Nothing here stops a run. A grey row is usually deliberate &mdash; building without deploying is a normal
        thing to do. And a green tick only means the thing <em>exists</em>; it can't tell you the thing is good.
    </p>

    <table class="bs-table">
        <thead>
            <tr>
                <th style="width:30%;">Step</th>
                <th style="width:40%;">Is it set up?</th>
                <th>
                    Did it run?
                    <?php if ($bsHasRun): ?>
                    <span style="font-weight:400;text-transform:none;letter-spacing:0;">&mdash; last run <?= h($bsRun['state'] ?? '') ?></span>
                    <?php endif; ?>
                </th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($bsSteps as $i => $s):
            $c = $bsReady[$s['key']] ?? ms_step_cell(MS_STEP_OFF, '&mdash;');
            [$fg, $bg, $icon] = $bsStyle[$c['state']] ?? $bsStyle[MS_STEP_OFF];
        ?>
            <tr>
                <td>
                    <div class="bs-step"><?= $i + 1 ?>. <?= h($s['label']) ?></div>
                    <div class="bs-does"><?= h($s['does']) ?></div>
                    <div class="bs-where">&rarr; <?= h($s['where']) ?></div>
                </td>
                <td>
                    <span class="bs-pill" style="color:<?= $fg ?>;background:<?= $bg ?>;"><?= $icon ?></span>
                    <span class="bs-fact"><?= h($c['fact']) ?></span>
                    <?php if ($c['note']): ?>
                    <div class="bs-note"><?= h($c['note']) ?></div>
                    <?php endif; ?>
                    <?php if (!empty($c['items'])): ?>
                    <table class="bs-items">
                        <?php foreach ($c['items'] as $it):
                            $short  = $it['filled'] < $it['total'];
                            $isBad  = $short && $it['required'];
                        ?>
                        <tr>
                            <td class="bs-i-col"><code><?= h($it['label']) ?></code><?= $it['required'] ? '' : ' <span class="bs-i-opt">optional</span>' ?></td>
                            <td class="bs-i-drives"><?= h($it['drives']) ?></td>
                            <td class="bs-i-n" style="color:<?= $isBad ? '#991b1b' : ($short ? '#92400e' : '#166534') ?>;">
                                <?= (int) $it['filled'] ?> of <?= (int) $it['total'] ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </table>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if (!$bsHasRun): ?>
                    <span class="bs-soon">not run yet</span>
                    <?php else:
                        $e = $bsRan[$s['key']];
                        if ($e['ran'] === 0): ?>
                        <span style="color:#64748b;">skipped &mdash; no row reached this step</span>
                    <?php else: ?>
                        <span class="bs-fact"><strong><?= $e['ran'] ?></strong> of <?= $e['total'] ?> rows</span>
                        <?php if ($e['failed'] > 0): ?>
                        <div class="bs-note" style="color:#991b1b;font-weight:600;">
                            <?= $e['failed'] ?> row<?= $e['failed'] === 1 ? '' : 's' ?> failed here
                        </div>
                        <?php endif; ?>
                    <?php endif; endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <?php
    // Where the failures cluster. This one sentence is the difference between
    // "something broke" and "fix the FTP credentials, the AI is fine."
    $bsFails = [];
    foreach ($bsSteps as $s) {
        $f = $bsRan[$s['key']]['failed'] ?? 0;
        if ($f > 0) $bsFails[] = $f . ' at ' . $s['label'];
    }
    $bsFailTotal = array_sum(array_column($bsRan, 'failed'));
    ?>
    <?php if ($bsHasRun && $bsFailTotal > 0): ?>
    <p style="margin:14px 0 0;padding:10px 13px;background:#fef2f2;border:1px solid #fecaca;border-radius:7px;font-size:.86rem;color:#991b1b;">
        <strong><?= $bsFailTotal ?> row<?= $bsFailTotal === 1 ? '' : 's' ?> failed</strong> &mdash; <?= h(implode(' · ', $bsFails)) ?>.
        Fix the step with the most failures first.
    </p>
    <?php elseif ($bsHasRun): ?>
    <p style="margin:14px 0 0;font-size:.86rem;color:#166534;">
        &#10003; No row failed in the last run.
    </p>
    <?php endif; ?>
</details>
