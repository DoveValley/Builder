<?php
/**
 * The go-live grid — one row per domain, one column per step.
 *
 * Included at the top of the Bulk tab. Renders STORED state and never goes to the
 * network: sixty-five rows × eight steps is five hundred cells, and checking them on
 * page load would be five hundred outbound calls before the page appeared. The ↻ in a
 * column heading is the only thing that goes and looks, and it does a whole column in
 * one pass.
 *
 * ONE FORM, for the whole card. Nested forms are invalid HTML and the grid needs a
 * checkbox, a date box and a button on every row as well as a Refresh on every column
 * heading — so the column Refreshes carry formaction to reach their own endpoint, and
 * every other button posts to actions/pipeline_golive.php. A per-row button carries
 * its domain as its own value, which is how one form holds sixty-five of them: only
 * the button actually pressed is submitted.
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
$pgToday   = infra_today();

// The scheduler's footprint. A scheduler that has silently stopped running is worse
// than no scheduler, so the page says when it last ticked rather than implying it did.
$pgTick = @json_decode((string) @file_get_contents(dirname(__DIR__) . '/state/golive_tick.json'), true);

// id => label, so the Box column can say "BOX 7" rather than "hst-beaa53".
$pgBoxes = [];
foreach (infra_hestia_servers() as $s) $pgBoxes[(string) ($s['id'] ?? '')] = (string) ($s['label'] ?? $s['id'] ?? '');

/** "3m" / "2h" / "5d" — how old the last sweep of a column is. */
function pg_age(int $ts): string
{
    $s = max(0, time() - $ts);
    if ($s < 90)     return $s . 's';
    if ($s < 5400)   return round($s / 60) . 'm';
    if ($s < 172800) return round($s / 3600) . 'h';
    return round($s / 86400) . 'd';
}

/** One cell. Four states plus "never checked", which is the absence of the other four. */
function pg_cell(array $c): string
{
    $state = (string) $c['state'];
    $tips  = [];
    if ($c['note'] !== '')                      $tips[] = $c['note'];
    if (!empty($c['attempts']))                 $tips[] = $c['attempts'] . ' attempt' . ($c['attempts'] === 1 ? '' : 's');
    if (!empty($c['at']))                       $tips[] = 'checked ' . date('j M H:i', (int) $c['at']);
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
.pg-gl      { width:172px; text-align:left }
.pg-date    { width:118px; padding:2px 5px; border:1px solid #d1d5db; border-radius:5px; font-size:11.5px }
.pg-go      { border:1px solid #b91c1c; background:#fff; color:#b91c1c; border-radius:5px; cursor:pointer;
              font-size:11px; padding:2px 7px; margin-top:3px }
.pg-go:hover{ background:#b91c1c; color:#fff }
.pg-ov      { font-size:10px; color:#9ca3af; margin-left:5px }
.pg-late    { color:#991b1b; font-weight:700 }
.pg-bar     { display:flex; gap:16px; flex-wrap:wrap; align-items:flex-end;
              margin-top:14px; padding-top:14px; border-top:1px solid #e5e7eb }
.pg-bar label { display:block; font-size:11px; color:#6b7280; margin-bottom:3px }
.pg-bar input[type=text], .pg-bar input[type=date], .pg-bar input[type=number]
            { padding:5px 8px; border:1px solid #d1d5db; border-radius:6px; font-size:13px }
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
                            : 'Nothing is in flight — no domain has a box or a Cloudflare zone yet.' ?>
      </div>
    <?php else: ?>

      <?php
      // The one line worth reading. Five hundred cells is not something anybody reads;
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

      <form method="post" action="actions/pipeline_golive.php">
      <input type="hidden" name="csrf" value="<?= ih(infra_csrf()) ?>">
      <input type="hidden" name="batch" value="<?= ih($pgBatch) ?>">

      <div class="pg-wrap">
      <table class="pg">
        <thead>
          <tr>
            <th style="width:24px"><input type="checkbox" onclick="this.closest('table').querySelectorAll('.pg-sel').forEach(b=>b.checked=this.checked)" title="Select all"></th>
            <th>Domain</th>
            <th>
              Box
              <?php // The Box column IS the `assign` step — it just shows the box's name
                    // rather than a tick. Without this it would be the one step with no
                    // way to re-check it, and its state decides every row's "next". ?>
              <button class="pg-r" type="submit" formaction="actions/pipeline_refresh.php"
                      name="step" value="assign" title="Re-check which box each domain is assigned to">&#8635;</button>
            </th>
            <?php foreach ($pgSteps as $s):
                if ($s['key'] === 'assign') continue;
                $isGl = $s['key'] === 'golive'; ?>
              <th class="<?= $isGl ? 'pg-gl' : 'num' ?>" title="<?= ih($s['does']) ?>">
                <?= ih($s['label']) ?>
                <?php // One Refresh per column, because that is the shape the APIs have:
                      // host and upload answer one box at a time, zone and DNS one
                      // Cloudflare account at a time. Only Live is one call per row. ?>
                <button class="pg-r" type="submit" formaction="actions/pipeline_refresh.php"
                        name="step" value="<?= ih($s['key']) ?>"
                        title="Go and check this column for all <?= count($pgRows) ?> rows">&#8635;</button>
                <span class="pg-age"><?= isset($pgAges[$s['key']]) ? ih(pg_age($pgAges[$s['key']])) . ' ago' : 'never' ?></span>
              </th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($pgRows as $r):
            $rec  = $r['rec'];
            $dom  = $r['domain'];
            $isUp = ($r['cells']['live']['state'] ?? '') === INFRA_STEP_OK;
            $when = trim((string) ($rec['go_live_at'] ?? ''));
            $gone = ($r['cells']['golive']['state'] ?? '') === INFRA_STEP_OK;   // already released
            $late = !$gone && $when !== '' && $when < $pgToday;
            // Green Upload is the gate. It is stated on the row rather than discovered
            // when the button is pressed — see infra_golive_gate().
            $ready = ($r['cells']['upload']['state'] ?? '') === INFRA_STEP_OK; ?>
          <tr>
            <td><input type="checkbox" class="pg-sel" name="sel[]" value="<?= ih($dom) ?>"></td>
            <td>
              <?php if ($isUp): ?>
                <a class="pg-dom" href="https://<?= ih($dom) ?>" target="_blank" rel="noopener"><?= ih($dom) ?> &#8599;</a>
              <?php else: ?>
                <span class="pg-dom"><?= ih($dom) ?></span>
              <?php endif; ?>
              <span class="pg-sub"><?= ih($rec['niche'] ?: '—') ?><?= $rec['registrar'] ? ' · ' . ih($rec['registrar']) : '' ?><?= $rec['batch'] ? ' · ' . ih($rec['batch']) : '' ?></span>
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
            <?php foreach ($pgSteps as $s):
                if ($s['key'] === 'assign') continue;
                if ($s['key'] !== 'golive'): ?>
                  <td class="num"><?= pg_cell($r['cells'][$s['key']]) ?></td>
                <?php continue; endif; ?>
              <td class="pg-gl">
                <?php if ($gone): ?>
                  <?= pg_cell($r['cells']['golive']) ?>
                  <span style="font-size:11px;color:#166534">released<?= $when !== '' ? ' ' . ih($when) : '' ?></span>
                <?php else: ?>
                  <input class="pg-date<?= $late ? ' pg-late' : '' ?>" type="date"
                         name="date[<?= ih($dom) ?>]" value="<?= ih($when) ?>"
                         title="<?= $late ? 'This date has passed and the domain has not been released' : 'Release date — blank means unscheduled' ?>">
                  <?php if ($late): ?><span class="pg-late" style="font-size:10px"> overdue</span><?php endif; ?>
                  <div>
                    <?php // The one outward-facing action on this page. Confirmed, because
                          // switching nameservers is the moment the world starts resolving
                          // the domain — and refused outright unless a site is up there. ?>
                    <button class="pg-go" type="submit" name="release" value="<?= ih($dom) ?>"
                            onclick="return confirm('Switch nameservers for <?= ih($dom) ?> now?\n\nThe world starts resolving it as soon as the registrar applies this.')">Go live now</button>
                    <?php if (!$ready): ?>
                      <label class="pg-ov" title="Upload is not green for this domain — releasing now points DNS at an empty site">
                        <input type="checkbox" name="force[<?= ih($dom) ?>]"> override
                      </label>
                    <?php endif; ?>
                  </div>
                <?php endif; ?>
              </td>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>

      <div class="pg-bar">
        <div>
          <label>Tag the ticked rows as a batch</label>
          <input type="text" name="batch_tag" value="<?= ih($pgBatch) ?>" placeholder="e.g. pest-wave-1" style="width:190px">
          <button class="btn sec" type="submit" name="action" value="tag">Tag</button>
        </div>
        <div>
          <label>Release dates you have typed above</label>
          <button class="btn sec" type="submit" name="action" value="save_dates">Save dates</button>
        </div>
        <div>
          <label>Or schedule <?= $pgBatch !== '' ? 'this batch' : 'everything in flight' ?> automatically</label>
          <input type="number" name="per_day" value="10" min="1" max="200" style="width:64px" title="domains per day">
          <span style="font-size:12px;color:#6b7280">per day from</span>
          <input type="date" name="start_date" value="<?= ih($pgToday) ?>">
          <button class="btn sec" type="submit" name="action" value="schedule">Schedule</button>
        </div>
      </div>
      </form>

      <div style="margin-top:12px;font-size:12px;color:#6b7280">
        <span style="color:#166534;font-weight:700">&#10003;</span> done &nbsp;
        <span style="color:#cbd5e1;font-weight:700">&middot;</span> not yet &nbsp;
        <span style="color:#991b1b;font-weight:700">&#10007;</span> failed &nbsp;
        <span style="color:#cbd5e1;font-weight:700">&#9675;</span> never checked &nbsp;
        <span class="pg-d" style="opacity:.45">faded</span> = worked out from fleet state, not verified.
        Nothing renders from the network &mdash; <strong>&#8635;</strong> in a column heading is the only thing
        that goes and looks, and it does the whole column in one pass.
        <br>
        <strong>A domain will not be released until its Upload cell is green</strong>, by the button or by the
        nightly run &mdash; pointing DNS at an empty folder is the one mistake here that the outside world sees.
        <br>
        <?php // Whether the scheduler is actually running is a fact, and an absent
              // scheduler must not look like a quiet one. ?>
        Nightly release run:
        <?php if (is_array($pgTick) && !empty($pgTick['at'])): ?>
          last ran <strong><?= ih(date('j M H:i', (int) $pgTick['at'])) ?></strong>
          &mdash; <?= (int) $pgTick['released'] ?> released<?= !empty($pgTick['held']) ? ', ' . (int) $pgTick['held'] . ' held' : '' ?>
          of <?= (int) $pgTick['due'] ?> due.
        <?php else: ?>
          <span style="color:#92400e"><strong>has never run.</strong> Dates set here will not fire until
          <code>cron/golive_tick.php</code> is in crontab.</span>
        <?php endif; ?>
      </div>

    <?php endif; ?>
  </div>
</div>
