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

$kwOn  = infra_kw_configured();
$tpl   = $niches[$niche]['template'] ?? '';
$draft = infra_research_load_draft($niche) ?? [];
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
  <strong>Getting a list of best cities — what you do vs. what happens on its own:</strong>
  <ol style="margin:6px 0 0;padding-left:20px">
    <li><strong>You:</strong> pick a niche tab above.</li>
    <li><strong>You:</strong> upload or paste <em>that niche's own</em> eLocal buyer-coverage export below —
      not a different niche's data. A file you choose here is held onto for next time; you only need to
      re-attach one if you want to replace it.</li>
    <li><strong>You:</strong> set the population / buyer / price / volume / separation / state-cap
      thresholds. There's no direct "give me N cities" field — the final count falls out of these, so
      hitting a target size (e.g. 400) usually takes a run, a look at the result count, then a second run
      with the thresholds loosened or tightened. <strong>Save</strong> remembers these fields with no run and
      no money spent, if you want to stop and come back later.</li>
    <li><strong>You: click Run.</strong> This step alone is free and fast — it only filters your uploaded
      eLocal rows against the thresholds above and matches them to known cities. No API is called yet,
      which is why it finishes almost instantly and just shows how many cities survived.</li>
    <li>From here it switches to a "Run in progress" card and mostly runs itself:
      <ol type="a" style="margin:4px 0 0;padding-left:20px">
        <li><strong>The page auto-continues on its own</strong> — a "Continue" click fires automatically
          every few seconds until the run is done, so <u>usually there is nothing more for you to click</u>.
          Leave the tab open, or close it and come back anytime — progress is saved after every pass and it
          picks up exactly where it left off. Only use the <strong>stop</strong> link next to "Auto-continuing…"
          if you want to pause and look at something before letting it keep going; after that, Continue
          becomes a manual button again.</li>
        <li><strong>Volume phase (real money: Ahrefs/DataForSEO search volume).</strong> Fully automatic
          from here — each pass looks up every surviving city's monthly search volume, several cities per
          request. When it finishes it reports how many cities got dropped for falling under the minimum
          monthly volume, then moves on by itself.</li>
        <li><strong>SERP phase (real money: DataForSEO, ~$0.002/check).</strong> Also automatic — each pass
          runs real Google searches to see who's actually ranking, in batches of up to
          <?= INFRA_RESEARCH_SERP_BATCH_SIZE ?> cities per request (not one city per request), so this phase
          finishes in far fewer passes than it used to. The progress line on the card below shows exactly
          how many keyword-checks are done vs. still to go.</li>
        <li><strong>Scoring, diversifying, writing the file.</strong> Fully automatic the instant SERP
          finishes — no click of any kind needed. It scores every city, assigns a grade (A–F), picks a
          diversified final list (mile-separation + state-cap), and writes the xlsx. The phase badge changes
          to "done" and a download link appears.</li>
      </ol>
    </li>
    <li><strong>You:</strong> download the xlsx and check the count. Off-target? Start a new run with
      adjusted thresholds — past runs stay in the history below, nothing is lost by iterating.</li>
  </ol>
  <p style="margin:10px 0 0">
    Produces and stores a ranked city list for <strong><?= ih($niches[$niche]['label']) ?></strong> — a real
    eLocal buyer-coverage filter, real search volume, a real Google SERP check per city. Nothing here
    selects a city or touches Batch; the output is an xlsx file in <strong>Downloads (Test Lab)</strong> and the
    decision what to build stays a separate, later step.
    <?php if (!$kwOn): ?><br><strong>No keyword provider connected</strong> — add DataForSEO/Ahrefs credentials
      on the <a href="index.php?view=cities">Cities/Niche</a> tab first (same credentials, reused here).<?php endif; ?>
  </p>
</div>

<?php if ($active): $phase = $active['phase'];
    $numPatterns = count($active['patterns']);
    $totalCities = count($active['candidates']);
    $volDone = $serpDone = $serpTotal = 0;
    foreach ($active['candidates'] as $c) {
        if (($c['volume'] ?? null) !== null) $volDone++;
        $serpDone += min($numPatterns, (int) ($c['serp_patterns_done'] ?? 0));
    }
    $serpTotal = $totalCities * $numPatterns;
?>
  <div class="ic-card" style="margin-bottom:14px"><div class="body">
    <h2>Run in progress — <?= ih(substr($active['created_at'], 0, 16)) ?></h2>
    <p>
      <?= count($active['candidates']) ?> candidate cities ·
      phase: <span class="badge <?= $phase === 'done' ? 'b-ok' : 'b-warn' ?>"><?= ih($phase) ?></span>
      <?php if ($phase === 'volume'): ?>
        · volume looked up: <strong><?= $volDone ?>/<?= $totalCities ?></strong> cities
      <?php elseif ($phase === 'serp'): ?>
        · SERP checks done: <strong><?= $serpDone ?>/<?= $serpTotal ?></strong>
        <?php if ($serpTotal > 0): ?>(<?= round($serpDone / $serpTotal * 100) ?>%)<?php endif; ?>
      <?php endif; ?>
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
      <form method="post" action="actions/research_save.php" id="continueForm" data-show-working="Working — fetching data, up to <?= INFRA_RESEARCH_TIME_BUDGET ?>s…">
        <input type="hidden" name="csrf" value="<?= ih(infra_csrf()) ?>">
        <input type="hidden" name="action" value="run">
        <input type="hidden" name="run_id" value="<?= ih($active['id']) ?>">
        <button class="btn" type="submit">Continue</button>
        <span id="autoContinueNote" style="font-size:12px;color:#6b7280">
          Auto-continuing every few seconds until done —
          <a href="javascript:void(0)" onclick="window.__stopAutoContinue=true;this.parentNode.textContent='Auto-continue stopped — click Continue manually.';">stop</a>
        </span>
      </form>
      <script>
        setTimeout(function () {
          if (window.__stopAutoContinue) return;
          var f = document.getElementById('continueForm');
          if (f) f.requestSubmit();
        }, 2500);
      </script>
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

  <form method="post" action="actions/research_save.php" enctype="multipart/form-data" data-show-working="Working…">
    <input type="hidden" name="csrf" value="<?= ih(infra_csrf()) ?>">
    <input type="hidden" name="niche" value="<?= ih($niche) ?>">
    <div class="ic-card"><div class="body" style="display:flex;flex-direction:column;gap:14px">

      <label style="font-size:12px">Keyword patterns (one per line, <code>{city}</code> required)<br>
        <textarea name="patterns" rows="3" style="width:100%;max-width:520px;padding:6px 8px;font-family:monospace"><?=
          ih($draft['patterns'] ?? ($tpl !== '' ? $tpl : '')) ?></textarea></label>

      <div>
        <label style="font-size:12px">eLocal buyer-coverage export<br>
          <input type="file" name="elocal_csv" accept=".csv,.tsv,.txt" style="padding:5px 0"></label>
        <?php if (!empty($draft['elocal_csv_name'])): ?>
          <div style="font-size:12px;color:#065f46;margin-top:4px">
            <strong>Currently held: <?= ih($draft['elocal_csv_name']) ?></strong> — used automatically on Save/Run
            until you choose a different file above.
          </div>
        <?php endif; ?>
        <div style="font-size:12px;color:#6b7280;margin-top:4px">or paste rows below — needs columns for
          city, state, buyer count, avg call price (and optionally max price); header names are
          matched loosely (e.g. "SMB Buyers", "1P Avg $").
          <?php if (($draft['elocal_paste'] ?? '') !== ''): ?><br><strong>Saved paste loaded below.</strong><?php endif; ?>
        </div>
        <textarea name="elocal_paste" rows="4" style="width:100%;max-width:640px;padding:6px 8px;font-family:monospace;margin-top:6px"
                  placeholder="city,state,buyers,price_avg,price_max"><?= ih($draft['elocal_paste'] ?? '') ?></textarea>
      </div>

      <div style="display:flex;gap:16px;flex-wrap:wrap">
        <label style="font-size:12px">Population min<br><input name="pop_min" type="number" value="<?= ih($draft['pop_min'] ?? '30000') ?>" style="width:100px;padding:5px 8px"></label>
        <label style="font-size:12px">Population max<br><input name="pop_max" type="number" value="<?= ih($draft['pop_max'] ?? '400000') ?>" style="width:100px;padding:5px 8px"></label>
        <label style="font-size:12px">Min buyers<br><input name="min_buyers" type="number" value="<?= ih($draft['min_buyers'] ?? '2') ?>" style="width:70px;padding:5px 8px"></label>
        <label style="font-size:12px">Min avg call price $<br><input name="min_price" type="number" value="<?= ih($draft['min_price'] ?? '250') ?>" style="width:90px;padding:5px 8px"></label>
        <label style="font-size:12px">Min monthly volume<br><input name="min_volume" type="number" value="<?= ih($draft['min_volume'] ?? '100') ?>" style="width:90px;padding:5px 8px"></label>
      </div>
      <div style="display:flex;gap:16px;flex-wrap:wrap">
        <label style="font-size:12px">Separation (miles)<br><input name="sep_mi" type="number" value="<?= ih($draft['sep_mi'] ?? '10') ?>" style="width:70px;padding:5px 8px"></label>
        <label style="font-size:12px">State cap %<br><input name="state_cap_pct" type="number" value="<?= ih($draft['state_cap_pct'] ?? '8') ?>" style="width:70px;padding:5px 8px"></label>
        <?php if (count($kwOn) > 1): ?>
          <label style="font-size:12px">Volume source<br>
            <select name="provider" style="padding:5px 8px">
              <?php foreach ($kwOn as $t => $m): ?><option value="<?= ih($t) ?>" <?= ($draft['provider'] ?? '') === $t ? 'selected' : '' ?>><?= ih($m['label']) ?></option><?php endforeach; ?>
            </select></label>
        <?php else: ?>
          <input type="hidden" name="provider" value="<?= ih((string) array_key_first($kwOn ?: ['ahrefs' => true])) ?>">
        <?php endif; ?>
      </div>

      <div>
        <button class="btn" type="submit" name="action" value="start" <?= $kwOn ? '' : 'disabled title="Connect a keyword provider first"' ?>>Run</button>
        <button class="btn sec" type="submit" name="action" value="save_draft" formnovalidate>Save</button>
        <span style="font-size:12px;color:#6b7280">
          <strong>Save</strong> just remembers these fields for next time — no money spent, nothing run.
          <strong>Run</strong> spends real money: volume lookups (<?= ih(implode('/', array_map(fn($m) => $m['label'], $kwOn ?: []))) ?: 'no provider connected' ?>)
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

<script>
// Every step here is a real page submit (POST + redirect), not AJAX, so there's a
// real wait with no visual change unless something says so - this is that something.
// Deliberately does NOT disable the button synchronously in the submit handler: some
// browsers drop a disabled control's name/value from the request it's still in the
// middle of building, which would silently break the action="save_draft" vs "start"
// routing on the form with two submit buttons. setTimeout(...,0) defers the disable
// to after the browser has already read the clicked button's value.
document.querySelectorAll('form[data-show-working]').forEach(function (f) {
  f.addEventListener('submit', function (e) {
    var btn = e.submitter || f.querySelector('button[type=submit]');
    if (!btn || btn.disabled) return;
    var msg = f.getAttribute('data-show-working') || 'Working…';
    setTimeout(function () {
      btn.textContent = msg;
      btn.disabled = true;
      btn.style.opacity = '0.65';
    }, 0);
  });
});
</script>

<?php infra_footer(); exit;
