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
                <th style="width:34%;">Step</th>
                <th>Is it set up?</th>
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
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <p class="bs-soon" style="margin:14px 0 0;">
        A third column &mdash; what actually executed &mdash; comes next; it needs each build step to tag its
        progress lines so a row can report which step it is on, and which step it failed at.
    </p>
</details>
