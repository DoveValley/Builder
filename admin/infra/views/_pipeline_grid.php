<?php
/**
 * The go-live grid — one row per domain, one column per step.
 *
 * Included at the top of the Bulk tab. Renders STORED state only and never goes to the
 * network: fifty rows × eight steps is four hundred cells, and checking them on page
 * load would be four hundred outbound calls before the page appeared. The per-column
 * Refresh (stage 2) is what goes and looks.
 *
 * Stage 1: read-only. The date box, the buttons and the refresh arrive in stages 2–4;
 * the columns and the state they read are what is worth correcting first.
 *
 * Expects nothing. Reads ?batch= from the query string.
 */
require_once __DIR__ . '/../lib/pipeline.php';

$pgBatch   = (string) ($_GET['batch'] ?? '');
$pgBatches = infra_pipeline_batches();
$pgSteps   = infra_pipeline_steps();
$pgRows    = infra_pipeline_rows($pgBatch);
$pgSum     = infra_pipeline_summary($pgRows);
$pgAges    = infra_pipeline_column_ages($pgRows);

/** "3m" / "2h" / "5d" — how old the last sweep of a column is. */
function pg_age(int $ts): string
{
    $s = max(0, time() - $ts);
    if ($s < 90)    return $s . 's';
    if ($s < 5400)  return round($s / 60) . 'm';
    if ($s < 172800) return round($s / 3600) . 'h';
    return round($s / 86400) . 'd';
}

// id => label, so the Box column can say "BOX 7" rather than "hst-beaa53".
$pgBoxes = [];
foreach (infra_hestia_servers() as $s) $pgBoxes[(string) ($s['id'] ?? '')] = (string) ($s['label'] ?? $s['id'] ?? '');

/** One cell. Four states plus "never checked", which is the absence of the other four. */
function pg_cell(array $c): string
{
    $state = (string) $c['state'];
    $tips  = [];
    if ($c['note'] !== '')                     $tips[] = $c['note'];
    if (!empty($c['attempts']))                $tips[] = $c['attempts'] . ' attempt' . ($c['attempts'] === 1 ? '' : 's');
    if (!empty($c['at']))                      $tips[] = 'checked ' . date('j M H:i', (int) $c['at']);
    // Said on every inferred cell, because "we worked this out from the fleet table"
    // and "somebody went and looked" must never look like the same claim.
    if (!empty($c['derived']) && $state !== '') $tips[] = 'derived from fleet state — not verified';

    [$glyph, $colour] = match ($state) {
        INFRA_STEP_OK      => ['&#10003;', '#166534'],
        INFRA_STEP_FAIL    => ['&#10007;', '#991b1b'],
        INFRA_STEP_RUNNING => ['&#8230;',  '#92400e'],
        INFRA_STEP_TODO    => ['&middot;', '#cbd5e1'],
        default            => ['&#9675;',  '#cbd5e1'],   // never checked
    };

    return '<span class="pg-c' . (!empty($c['derived']) && $state !== '' ? ' pg-d' : '') . '"'
         . ' style="color:' . $colour . '"'
         . ($tips ? ' title="' . ih(implode(' · ', $tips)) . '"' : '')
         . '>' . $glyph . '</span>';
}
?>
<style>
.pg-wrap    { overflow-x:auto }
.pg         { width:100%; border-collapse:collapse; font-size:13px }
.pg th      { text-align:left; padding:7px 8px; border-bottom:1px solid #e5e7eb; font-size:11px;
              text-transform:uppercase; letter-spacing:.03em; color:#6b7280; font-weight:700; white-space:nowrap }
.pg td      { padding:6px 8px; border-bottom:1px solid #f3f4f6; white-space:nowrap }
.pg tr:hover td { background:#f9fafb }
.pg .num    { text-align:center; width:74px }
.pg-c       { font-size:15px; font-weight:700 }
.pg-d       { opacity:.45; border-bottom:1px dotted currentColor }   /* inferred, not checked */
.pg-dom     { font-weight:600; color:#111827 }
.pg-sub     { display:block; font-size:11px; color:#9ca3af; font-weight:400 }
.pg-tabs a  { display:inline-block; padding:3px 10px; border:1px solid #d1d5db; border-radius:999px;
              font-size:12px; text-decoration:none; color:#374151; margin:0 6px 6px 0 }
.pg-tabs a.on { background:#111827; border-color:#111827; color:#fff }
.pg-r       { border:1px solid #d1d5db; background:#fff; border-radius:5px; cursor:pointer;
              font-size:12px; line-height:1; padding:3px 7px; color:#374151 }
.pg-r:hover { background:#111827; border-color:#111827; color:#fff }
.pg-age     { display:block; margin-top:3px; font-size:10px; font-weight:400; color:#cbd5e1;
              text-transform:none; letter-spacing:0 }
</style>

<div class="ic-card">
  <h2>Go-live grid
    <span style="font-weight:400;font-size:13px;color:#6b7280">
      &mdash; one row per domain, one column per step
    </span>
  </h2>
  <div class="body">

    <?php if ($pgBatches): ?>
    <div class="pg-tabs">
      <a href="index.php?view=bulk" class="<?= $pgBatch === '' ? 'on' : '' ?>">All in flight</a>
      <?php foreach ($pgBatches as $b => $c): ?>
        <a href="index.php?view=bulk&amp;batch=<?= urlencode($b) ?>" class="<?= $pgBatch === $b ? 'on' : '' ?>"><?= ih($b) ?> (<?= $c ?>)</a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (!$pgRows): ?>
      <div class="ic-empty">
        <?= $pgBatch !== '' ? 'No domains tagged &ldquo;' . ih($pgBatch) . '&rdquo;.'
                            : 'No domain has been assigned to a box yet — nothing is in flight.' ?>
      </div>
    <?php else: ?>

      <?php
      // The one line worth reading. Four hundred cells is not something anybody reads;
      // "12 next at Upload" is. Counted off each row's FIRST unfinished step, so a row
      // is only ever blamed on the thing actually blocking it.
      $pgBits = ['<strong>' . $pgSum['total'] . '</strong> domain' . ($pgSum['total'] === 1 ? '' : 's')];
      if ($pgSum['live'])   $pgBits[] = '<strong style="color:#166534">' . $pgSum['live'] . '</strong> live';
      if ($pgSum['failed']) $pgBits[] = '<strong style="color:#991b1b">' . $pgSum['failed'] . '</strong> failed';
      foreach (array_slice($pgSum['stuck'], 0, 3, true) as $k => $c) {
          $lbl = '';
          foreach ($pgSteps as $s) if ($s['key'] === $k) $lbl = $s['label'];
          $pgBits[] = $c . ' next at <strong>' . ih($lbl) . '</strong>';
      }
      ?>
      <div class="ic-note" style="margin-bottom:12px"><?= implode(' &middot; ', $pgBits) ?></div>

      <div class="pg-wrap">
      <table class="pg">
        <thead>
          <tr>
            <th>Domain</th>
            <th>
              Box
              <?php // The Box column IS the `assign` step — it just shows the box's name
                    // rather than a tick. Without this it would be the one step with no
                    // way to re-check it, and its state decides what every row's "next"
                    // is. Costs nothing: assign reads stored state and never calls out. ?>
              <form method="post" action="actions/pipeline_refresh.php" style="display:inline;margin-left:6px">
                <input type="hidden" name="csrf" value="<?= ih(infra_csrf()) ?>">
                <input type="hidden" name="step" value="assign">
                <input type="hidden" name="batch" value="<?= ih($pgBatch) ?>">
                <button class="pg-r" type="submit" title="Re-check which box each domain is assigned to">&#8635;</button>
              </form>
            </th>
            <?php foreach ($pgSteps as $s): if ($s['key'] === 'assign') continue; ?>
              <th class="num" title="<?= ih($s['does']) ?>">
                <?= ih($s['label']) ?>
                <?php // One Refresh per column, because that is the shape the APIs have:
                      // host and upload answer one box at a time, zone and DNS one
                      // Cloudflare account at a time. Only Live is one call per row. ?>
                <form method="post" action="actions/pipeline_refresh.php" style="margin:3px 0 0">
                  <input type="hidden" name="csrf" value="<?= ih(infra_csrf()) ?>">
                  <input type="hidden" name="step" value="<?= ih($s['key']) ?>">
                  <input type="hidden" name="batch" value="<?= ih($pgBatch) ?>">
                  <button class="pg-r" type="submit"
                          title="Go and check this column for all <?= count($pgRows) ?> rows">&#8635;</button>
                </form>
                <span class="pg-age"><?= isset($pgAges[$s['key']]) ? ih(pg_age($pgAges[$s['key']])) . ' ago' : 'never' ?></span>
              </th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($pgRows as $r):
            $rec  = $r['rec'];
            $dom  = $r['domain'];
            $isUp = ($r['cells']['live']['state'] ?? '') === INFRA_STEP_OK; ?>
          <tr>
            <td>
              <?php if ($isUp): ?>
                <a class="pg-dom" href="https://<?= ih($dom) ?>" target="_blank" rel="noopener"><?= ih($dom) ?> &#8599;</a>
              <?php else: ?>
                <span class="pg-dom"><?= ih($dom) ?></span>
              <?php endif; ?>
              <span class="pg-sub"><?= ih($rec['niche'] ?: '—') ?><?= $rec['registrar'] ? ' · ' . ih($rec['registrar']) : '' ?></span>
            </td>
            <td>
              <?php $sid = (string) ($rec['server_id'] ?? ''); ?>
              <?php if ($sid !== ''): ?>
                <?= ih($pgBoxes[$sid] ?? $sid) ?>
                <?php // The FTP login is the closest thing to a per-domain receipt for
                      // the host area; the vhost itself is just the domain again. ?>
                <span class="pg-sub"><?= ih($rec['ftp_user'] ?: 'no ftp login') ?></span>
              <?php else: ?>
                <span style="color:#cbd5e1">&mdash;</span>
              <?php endif; ?>
            </td>
            <?php foreach ($pgSteps as $s): if ($s['key'] === 'assign') continue; ?>
              <td class="num"><?= pg_cell($r['cells'][$s['key']]) ?></td>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>

      <div style="margin-top:10px;font-size:12px;color:#6b7280">
        <span style="color:#166534;font-weight:700">&#10003;</span> done &nbsp;
        <span style="color:#cbd5e1;font-weight:700">&middot;</span> not yet &nbsp;
        <span style="color:#991b1b;font-weight:700">&#10007;</span> failed &nbsp;
        <span style="color:#cbd5e1;font-weight:700">&#9675;</span> never checked &nbsp;
        <span class="pg-d" style="opacity:.45">faded</span> = worked out from fleet state, not verified.
        Nothing renders from the network &mdash; <strong>&#8635;</strong> in a column heading is the only thing
        that goes and looks, and it does the whole column in one pass.
      </div>

    <?php endif; ?>
  </div>
</div>
