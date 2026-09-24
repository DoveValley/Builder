<?php
/* ============================= CITY RESEARCH =============================
 * Standalone tool: which cities are worth building a site in, for any niche.
 * Own state (state/research/*.json), own output (uploads/downloads/*.xlsx).
 * Does not read or write city_niche, does not touch Batch/Site Factory —
 * see lib/research.php's header for why.
 */
require_once __DIR__ . '/../lib/cities.php';
require_once __DIR__ . '/../lib/keywords.php';
require_once __DIR__ . '/../lib/research.php';
infra_header('research');

$niches = infra_niches();
$niche  = infra_niche_slug($_GET['niche'] ?? '');
if ($niche === '' || !isset($niches[$niche])) $niche = (string) array_key_first($niches);

$allRuns  = infra_research_list_runs();
$nicheRuns = array_values(array_filter($allRuns, fn($r) => ($r['niche'] ?? '') === $niche));

$runId  = (string) ($_GET['run'] ?? '');
$active = $runId !== '' ? infra_research_load_run($runId) : null;
if ($active && $active['niche'] !== $niche) $active = null;
if (!$active && $nicheRuns && ($nicheRuns[0]['phase'] ?? '') !== 'done') $active = $nicheRuns[0];

$kwOn = infra_kw_configured();
$tpl  = $niches[$niche]['template'] ?? '';
?>

<div class="ic-card" style="margin-bottom:14px"><div class="body" style="padding:10px 14px">
  <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">
    <?php foreach ($niches as $s => $n):
        $c = count(array_filter($allRuns, fn($r) => ($r['niche'] ?? '') === $s)); ?>
      <a href="index.php?view=research&niche=<?= urlencode($s) ?>"
         class="btn <?= $s === $niche ? '' : 'sec' ?>" style="padding:4px 12px;font-size:13px">
        <?= ih($n['label']) ?>
        <span style="opacity:.7;font-size:11px"><?= $c ?>&nbsp;run<?= $c === 1 ? '' : 's' ?></span>
      </a>
    <?php endforeach; ?>
  </div>
</div></div>

<div class="ic-note">
  Produces and stores a ranked city list for <strong><?= ih($niches[$niche]['label']) ?></strong> — a real
  eLocal buyer-coverage filter, real search volume, a real Google SERP check per city. Nothing here
  selects a city or touches Batch; the output is an xlsx file in <strong>Downloads (Test Lab)</strong> and the
  decision what to build stays a separate, later step.
  <?php if (!$kwOn): ?><br><strong>No keyword provider connected</strong> — add DataForSEO/Ahrefs credentials
    on the <a href="index.php?view=cities">Cities/Niche</a> tab first (same credentials, reused here).<?php endif; ?>
</div>

<?php if ($active): $phase = $active['phase']; ?>
  <div class="ic-card" style="margin-bottom:14px"><div class="body">
    <h2>Run in progress — <?= ih(substr($active['created_at'], 0, 16)) ?></h2>
    <p>
      <?= count($active['candidates']) ?> candidate cities ·
      phase: <span class="badge <?= $phase === 'done' ? 'b-ok' : 'b-warn' ?>"><?= ih($phase) ?></span>
      <?php if (($active['stats']['unmatched'] ?? 0) > 0): ?>
        · <?= (int) $active['stats']['unmatched'] ?> eLocal rows could not be matched to a known city
      <?php endif; ?>
    </p>
    <?php if ($phase === 'done'): ?>
      <p><strong><?= (int) ($active['result_count'] ?? 0) ?> cities</strong> in the final list.
        <a class="btn" href="/uploads/downloads/<?= rawurlencode($active['result_file']) ?>" download>Download xlsx</a>
        <a class="btn sec" href="../playground.php#downloads-scott">Open in Test Lab</a>
      </p>
    <?php else: ?>
      <form method="post" action="actions/research_save.php">
        <input type="hidden" name="csrf" value="<?= ih(infra_csrf()) ?>">
        <input type="hidden" name="action" value="run">
        <input type="hidden" name="run_id" value="<?= ih($active['id']) ?>">
        <button class="btn" type="submit">Continue</button>
        <span style="font-size:12px;color:#6b7280">
          Each click works for up to <?= INFRA_RESEARCH_TIME_BUDGET ?> seconds, then stops and says what's left —
          safe to navigate away and come back.
        </span>
      </form>
    <?php endif; ?>
    <form method="post" action="actions/research_save.php" style="margin-top:10px"
          onsubmit="return confirm('Delete this run? The xlsx already saved to Downloads is not affected.')">
      <input type="hidden" name="csrf" value="<?= ih(infra_csrf()) ?>">
      <input type="hidden" name="action" value="delete">
      <input type="hidden" name="run_id" value="<?= ih($active['id']) ?>">
      <button class="btn sec" type="submit">Delete this run</button>
    </form>
  </div></div>
<?php else: ?>

  <form method="post" action="actions/research_save.php" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= ih(infra_csrf()) ?>">
    <input type="hidden" name="action" value="start">
    <input type="hidden" name="niche" value="<?= ih($niche) ?>">
    <div class="ic-card"><div class="body" style="display:flex;flex-direction:column;gap:14px">

      <label style="font-size:12px">Keyword patterns (one per line, <code>{city}</code> required)<br>
        <textarea name="patterns" rows="3" style="width:100%;max-width:520px;padding:6px 8px;font-family:monospace"><?=
          ih($tpl !== '' ? $tpl : '') ?></textarea></label>

      <div>
        <label style="font-size:12px">eLocal buyer-coverage export<br>
          <input type="file" name="elocal_csv" accept=".csv,.tsv,.txt" style="padding:5px 0"></label>
        <div style="font-size:12px;color:#6b7280;margin-top:4px">or paste rows below — needs columns for
          city, state, buyer count, avg call price (and optionally max price); header names are
          matched loosely (e.g. "SMB Buyers", "1P Avg $").</div>
        <textarea name="elocal_paste" rows="4" style="width:100%;max-width:640px;padding:6px 8px;font-family:monospace;margin-top:6px"
                  placeholder="city,state,buyers,price_avg,price_max"></textarea>
      </div>

      <div style="display:flex;gap:16px;flex-wrap:wrap">
        <label style="font-size:12px">Population min<br><input name="pop_min" type="number" value="30000" style="width:100px;padding:5px 8px"></label>
        <label style="font-size:12px">Population max<br><input name="pop_max" type="number" value="400000" style="width:100px;padding:5px 8px"></label>
        <label style="font-size:12px">Min buyers<br><input name="min_buyers" type="number" value="2" style="width:70px;padding:5px 8px"></label>
        <label style="font-size:12px">Min avg call price $<br><input name="min_price" type="number" value="250" style="width:90px;padding:5px 8px"></label>
        <label style="font-size:12px">Min monthly volume<br><input name="min_volume" type="number" value="100" style="width:90px;padding:5px 8px"></label>
      </div>
      <div style="display:flex;gap:16px;flex-wrap:wrap">
        <label style="font-size:12px">Separation (miles)<br><input name="sep_mi" type="number" value="10" style="width:70px;padding:5px 8px"></label>
        <label style="font-size:12px">State cap %<br><input name="state_cap_pct" type="number" value="8" style="width:70px;padding:5px 8px"></label>
        <?php if (count($kwOn) > 1): ?>
          <label style="font-size:12px">Volume source<br>
            <select name="provider" style="padding:5px 8px">
              <?php foreach ($kwOn as $t => $m): ?><option value="<?= ih($t) ?>"><?= ih($m['label']) ?></option><?php endforeach; ?>
            </select></label>
        <?php else: ?>
          <input type="hidden" name="provider" value="<?= ih((string) array_key_first($kwOn ?: ['ahrefs' => true])) ?>">
        <?php endif; ?>
      </div>

      <div>
        <button class="btn" type="submit" <?= $kwOn ? '' : 'disabled title="Connect a keyword provider first"' ?>>Run</button>
        <span style="font-size:12px;color:#6b7280">
          Spends real money: volume lookups (<?= ih(implode('/', array_map(fn($m) => $m['label'], $kwOn ?: []))) ?: 'no provider connected' ?>)
          plus one real Google SERP check per city per keyword pattern (~$0.002 each via DataForSEO).
        </span>
      </div>
    </div></div>
  </form>
<?php endif; ?>

<?php if ($nicheRuns): ?>
  <div class="ic-card" style="margin-top:14px"><div class="body">
    <h2>Past runs — <?= ih($niches[$niche]['label']) ?></h2>
    <table><thead><tr><th>When</th><th>Phase</th><th>Cities in</th><th>Final list</th><th>File</th><th></th></tr></thead><tbody>
      <?php foreach ($nicheRuns as $r): ?>
        <tr>
          <td><?= ih(substr($r['created_at'], 0, 16)) ?></td>
          <td><span class="badge <?= $r['phase'] === 'done' ? 'b-ok' : 'b-warn' ?>"><?= ih($r['phase']) ?></span></td>
          <td><?= count($r['candidates']) ?></td>
          <td><?= isset($r['result_count']) ? (int) $r['result_count'] : '<span style="color:#d1d5db">—</span>' ?></td>
          <td><?php if (!empty($r['result_file'])): ?>
                <a href="/uploads/downloads/<?= rawurlencode($r['result_file']) ?>" download><?= ih($r['result_file']) ?></a>
              <?php else: ?><span style="color:#d1d5db">—</span><?php endif; ?></td>
          <td><a class="btn sec" style="padding:2px 8px;font-size:11px"
                 href="index.php?view=research&niche=<?= urlencode($niche) ?>&run=<?= urlencode($r['id']) ?>">Open</a></td>
        </tr>
      <?php endforeach; ?>
    </tbody></table>
  </div></div>
<?php endif; ?>

<?php infra_footer(); exit;
