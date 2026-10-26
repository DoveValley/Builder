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

// Summaries only (candidate_count, not the full candidates array) — listing
// every run's full multi-MB payload at once is what was exhausting the web
// process's 128M memory_limit. $active below is the one run whose full detail
// this page actually renders, loaded on its own via infra_research_load_run().
$allRuns  = infra_research_list_run_summaries();
$nicheRuns = array_values(array_filter($allRuns, fn($r) => ($r['niche'] ?? '') === $niche));

$runId  = (string) ($_GET['run'] ?? '');
$active = $runId !== '' ? infra_research_load_run($runId) : null;
if ($active && $active['niche'] !== $niche) $active = null;
if (!$active && $nicheRuns && ($nicheRuns[0]['phase'] ?? '') !== 'done') {
    $active = infra_research_load_run($nicheRuns[0]['id']);
}

$kwOn  = infra_kw_configured();
$tpl   = $niches[$niche]['template'] ?? '';
$draft = infra_research_load_draft($niche) ?? [];
?>

<div style="margin-bottom:10px">
  <a class="btn sec" style="padding:4px 12px;font-size:13px" href="index.php?view=citydata">City Data &rarr;</a>
  <span style="font-size:12px;color:#6b7280">Census income/housing lookup — independent of niche, not scoped to any run below.</span>
</div>

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

<?php if ($niche === 'appliance'): ?>
<?php /* Niche-specific reference content — appliance only for now (the target-city matrix
         especially so). Other niches may get their own version later. Inline on the page,
         not a popup: the whole point is that this theory reads BEFORE the operational
         walkthrough below it, not tucked behind a click. */ ?>
<div class="ic-note" style="margin-bottom:14px">
  <strong style="font-size:15px">What makes a city worth targeting (website-only approach — no links, no GBP)</strong>
  <p style="font-size:13px;line-height:1.5;color:#374151;margin:10px 0 0">
    Income is the top priority, ahead of population — ease of ranking comes right behind it, since this is a
    website-only approach with no links or GBP and smaller, less-competitive cities rank faster without those
    off-page signals. Aim for a median household income of at least <strong>$90k, ideally $110k+</strong>, and
    a high homeownership rate — renters call landlords, not repair companies. A 60,000-person town with $130k
    income will usually beat a 150,000-person city with $55k income, both on income and on how easy it is to
    rank.
  </p>
  <div style="overflow-x:auto">
    <table style="width:100%;border-collapse:collapse;font-size:12.5px">
      <thead>
        <tr style="background:#f9fafb;text-align:left">
          <th style="padding:6px 8px;border-bottom:1px solid #e5e7eb">Rank</th>
          <th style="padding:6px 8px;border-bottom:1px solid #e5e7eb">Population</th>
          <th style="padding:6px 8px;border-bottom:1px solid #e5e7eb">Why</th>
          <th style="padding:6px 8px;border-bottom:1px solid #e5e7eb;white-space:nowrap">Expected calls/mo (established)</th>
          <th style="padding:6px 8px;border-bottom:1px solid #e5e7eb">Examples</th>
        </tr>
      </thead>
      <tbody style="color:#374151">
        <tr>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6;font-weight:600">4</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6;white-space:nowrap">15k–25k</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">Only viable once income clears $90k+ — affluent owners repair rather than replace. Volume is thin alone, so pair with a larger neighbor or lean on brand-specific pages</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6;white-space:nowrap">2–8</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">Mountain Brook AL, Hinsdale IL, Los Altos CA</td>
        </tr>
        <tr>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6;font-weight:600">3</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6;white-space:nowrap">25k–75k</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">Easiest to rank, high-ticket jobs in wealthy towns, but volume is thin; best when you cover several neighboring towns together</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6;white-space:nowrap">3–12 per town</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">Southlake TX, Colleyville TX, Prosper TX, Alpharetta GA</td>
        </tr>
        <tr>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6;font-weight:600">1 (Best)</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6;white-space:nowrap">75k–200k</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">The true sweet spot: enough search volume to matter, competition still beatable without links, and many affluent suburbs fall here</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6;white-space:nowrap">8–25</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">The Woodlands TX, Allen TX, Sugar Land TX, Johns Creek GA</td>
        </tr>
        <tr>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6;font-weight:600">2</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6;white-space:nowrap">200k–400k</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">More volume, moderately harder; affluent ones are excellent targets, but takes longer</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6;white-space:nowrap">10–30</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">Plano TX, Frisco TX, Huntsville AL</td>
        </tr>
        <tr>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6;font-weight:600">5</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6;white-space:nowrap">400k–1M</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">Lots of volume but strong competition; only worth it if you target affluent neighborhoods rather than the whole city</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6;white-space:nowrap">5–25</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">Fort Worth TX, Atlanta GA (city)</td>
        </tr>
        <tr>
          <td style="padding:6px 8px;font-weight:600">6 (Worst)</td>
          <td style="padding:6px 8px;white-space:nowrap">1M+</td>
          <td style="padding:6px 8px">Hardest to rank without links or GBP; map pack and big brands dominate, and the city is too spread out</td>
          <td style="padding:6px 8px;white-space:nowrap">0–20</td>
          <td style="padding:6px 8px">Dallas TX, Houston TX</td>
        </tr>
      </tbody>
    </table>
  </div>
  <p style="font-size:12px;line-height:1.5;color:#6b7280;margin:14px 0 0">
    The calls estimates assume template-quality issues are fixed; if the pages read as heavily templated,
    expect the low end of each range.
  </p>

  <hr style="margin:18px 0;border:none;border-top:1px solid #e5e7eb">

  <p style="font-size:14px;font-weight:600;margin:0 0 10px">
    Target city matrix: website-only appliance repair microsites (no links, no GBP)
  </p>
  <div style="overflow-x:auto">
    <table style="width:100%;border-collapse:collapse;font-size:12.5px">
      <thead>
        <tr style="background:#f9fafb;text-align:left">
          <th style="padding:6px 8px;border-bottom:1px solid #e5e7eb">Target type</th>
          <th style="padding:6px 8px;border-bottom:1px solid #e5e7eb;white-space:nowrap">Small high-income 15k–25k</th>
          <th style="padding:6px 8px;border-bottom:1px solid #e5e7eb;white-space:nowrap">25k–75k</th>
          <th style="padding:6px 8px;border-bottom:1px solid #e5e7eb;white-space:nowrap">75k–200k</th>
          <th style="padding:6px 8px;border-bottom:1px solid #e5e7eb;white-space:nowrap">200k–400k</th>
          <th style="padding:6px 8px;border-bottom:1px solid #e5e7eb;white-space:nowrap">400k–1M</th>
          <th style="padding:6px 8px;border-bottom:1px solid #e5e7eb;white-space:nowrap">1M+</th>
          <th style="padding:6px 8px;border-bottom:1px solid #e5e7eb">Total</th>
        </tr>
      </thead>
      <tbody style="color:#374151">
        <tr>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6;font-weight:600">OK (~$65k–90k income)</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">—</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">~250–350</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">~150–180</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">~30–40</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">~10–15</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">—</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">~440–585</td>
        </tr>
        <tr>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6;font-weight:600">Good ($90k+)</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">~200–250</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">~250–300</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">~90–110</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">~10–15</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">~3–5</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">—</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">~550–680</td>
        </tr>
        <tr>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6;font-weight:600">Great ($110k+, part of good)</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">~100–130</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">~120–150</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">~40–50</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">~5–8</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">1–3</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">—</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">~265–340</td>
        </tr>
        <tr>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6;font-weight:600">Total viable</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">~200–250</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">~500–650</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">~240–290</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">~40–55</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">~13–20</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">—</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">~1,000–1,250</td>
        </tr>
        <tr>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6;font-weight:600">Est. calls/mo (established)</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">2–8</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">3–12</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">8–25</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">10–30</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">5–25</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">0–20</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6"></td>
        </tr>
        <tr>
          <td style="padding:6px 8px;font-weight:600">Rank (best to worst)</td>
          <td style="padding:6px 8px">4</td>
          <td style="padding:6px 8px">3</td>
          <td style="padding:6px 8px">1</td>
          <td style="padding:6px 8px">2</td>
          <td style="padding:6px 8px">5</td>
          <td style="padding:6px 8px">6</td>
          <td style="padding:6px 8px"></td>
        </tr>
      </tbody>
    </table>
  </div>
  <p style="font-size:13px;line-height:1.5;color:#374151;margin:10px 0 0">
    Cities over 1M (Dallas, Houston, etc.) are excluded as the worst fit for this approach. Small
    high-income towns add about 200–250 viable targets (~25% more) and about 100–130 great targets
    (~60% more).
  </p>

  <p style="font-size:13px;line-height:1.5;color:#374151;margin:14px 0 0"><strong>Key notes</strong></p>
  <ul style="font-size:13px;line-height:1.5;color:#374151;margin:6px 0 0;padding-left:20px">
    <li><strong>Income beats population.</strong> Target $90k+ median household income and high
      homeownership; affluent owners repair high-end appliances rather than replace them, so push
      Sub-Zero, Wolf, Thermador, Viking, and Miele pages there.</li>
    <li><strong>Cluster your targets.</strong> The best setup is often a tier 1 or tier 2 city plus two or
      three affluent tier 3 neighbors (e.g., The Woodlands + Spring + Shenandoah, or Allen + Fairview +
      Lucas). You get easy rankings and combined volume.</li>
    <li><strong>Under 25k is usually too small on its own.</strong> For reference, the Harrisburg, NC site
      targets a town of about 18,500, which is why pairing it with Concord and other nearby areas matters.</li>
    <li><strong>Expect a ramp-up.</strong> Smaller towns can produce calls in 2–6 months; larger cities
      often take 6–12 months. Small-town calls are lumpy, so judge them on a 6-month average.</li>
    <li><strong>Fix the template before scaling.</strong> Cut the repeated regional boilerplate (hard
      water, storms, sitewide blocks), add brand-specific detail, make sure FAQ answers are in the HTML,
      and align on-page wording with how calls are actually routed. Repetition across hundreds of sites
      is the biggest ranking risk.</li>
    <li><strong>Count qualified calls, not raw calls.</strong> Pay-per-call buyers often require a minimum
      call length, so only about 50–70% of calls may pay. Make sure each site's qualified calls cover its
      costs.</li>
    <li><strong>Verify before you build.</strong> Census QuickFacts gives median household income and
      owner-occupied rate in a couple of minutes per city; the American Community Survey has the full
      population/income/homeownership table for every place. These matrix counts are estimates and
      exclude unincorporated communities like The Woodlands, so confirm your actual target list against
      real census data.</li>
    <li><strong>Track every page.</strong> Call tracking on your first 5–10 sites will show real per-city
      averages, so you can adjust before scaling.</li>
  </ul>

  <hr style="margin:18px 0;border:none;border-top:1px solid #e5e7eb">

  <strong style="font-size:15px">Proving real demand: the eLocal buyer-coverage check</strong>
  <p style="font-size:13px;line-height:1.5;color:#374151;margin:10px 0 0">
    Once you know what a good city looks like in theory (above), check it against real buyer demand —
    deliberately the second step, not the first. The theory tells you the <em>universe</em> of candidate
    cities; eLocal tells you where there's already proven commercial demand within that universe.
  </p>
  <p style="font-size:13px;line-height:1.5;color:#374151;margin:10px 0 0">
    <strong>What it is:</strong> eLocal's own count of paying companies ("SMB buyers") already buying
    leads/calls in a city — real proof of existing demand, separate from search volume (checked later, via
    Ahrefs/DataForSEO).
  </p>
  <p style="font-size:13px;line-height:1.5;color:#374151;margin:10px 0 0">
    <strong>A real gotcha:</strong> the import only reads <code>city</code>, <code>state</code>,
    <code>buyers</code>, and avg price columns — there is no ZIP-code awareness anywhere in this tool. If
    your eLocal export has multiple rows per city (e.g. broken out by ZIP), they are <strong>not
    summed</strong> — only the last row read for that city survives, the rest are silently discarded. Make
    sure your export is already rolled up to one row per city before uploading.
  </p>
  <p style="font-size:13px;line-height:1.5;color:#374151;margin:10px 0 0">
    <strong>How it's used:</strong> cities under your min-buyers/min-price threshold are rejected
    immediately, before any paid lookup happens — this is why the first "Run" click below is free and
    instant, it's just this filter. Buyers count also feeds each city's score afterward (a damped ~6% of
    the total — enough to matter, not enough to dominate).
  </p>
  <p style="font-size:13px;line-height:1.5;color:#374151;margin:10px 0 0">
    <strong>If eLocal's coverage looks thin:</strong> if a lot of your theoretically-good cities (per the
    tables above) come back with low or zero eLocal buyer data, that's not proof those cities are weak — it
    may just mean eLocal doesn't have coverage there. Worth considering a different buyer-data source before
    writing off those cities.
  </p>
</div>
<?php endif; ?>

<div class="ic-note">
  <strong>Getting a list of best cities — what you do vs. what happens on its own:</strong>
  <ol style="margin:6px 0 0;padding-left:20px">
    <li><strong>You:</strong> pick a niche tab above.</li>
    <li><strong>You:</strong> check the keyword patterns (one per line, <code>{city}</code> required) — they
      default from the niche's own template. <strong>A city whose name collides with another US state or a
      well-known Canadian/UK city gets the state appended automatically</strong> — "Lancaster" becomes
      "Lancaster SC" (also exists in PA), "Hamilton" becomes "Hamilton OH" (also Ontario) — in both the
      volume lookup and the SERP check, so you don't need to write that yourself.</li>
    <li><strong>You:</strong> upload or paste <em>that niche's own</em> eLocal buyer-coverage export below —
      not a different niche's data. A file you choose here is held onto for next time; you only need to
      re-attach one if you want to replace it.</li>
    <li><strong>You:</strong> set the population / buyer / price / volume / max-rivals / separation /
      state-cap thresholds. There's no direct "give me N cities" field — the final count falls out of
      these, so hitting a target size (e.g. 400) usually takes a run, a look at the result count, then a
      second run with the thresholds loosened or tightened. <strong>Population size is not a reliable
      stand-in for competition</strong> — a completed run's Local/National Brands columns show real
      competitor counts that don't track city size; use <strong>Max rivals</strong> if the goal is
      specifically low competition ("SEO ease"), not just smaller cities. <strong>Save</strong> remembers
      these fields with no run and no money spent, if you want to stop and come back later.</li>
    <li><strong>You: click Run.</strong> This step alone is free and fast — it only filters your uploaded
      eLocal rows against the thresholds above and matches them to known cities. No API is called yet,
      which is why it finishes almost instantly and just shows how many cities survived.</li>
    <li>From here it switches to a "Run in progress" card and runs itself — <strong>a real server-side cron
      job ticks every in-progress run every 2 minutes, browser or no browser.</strong> Close the tab, close
      your laptop, come back tomorrow — the page's own "Continue" auto-click is just a convenience for
      watching it live, not what's actually keeping it moving:
      <ol type="a" style="margin:4px 0 0;padding-left:20px">
        <li><strong>Running more than one niche at once is efficient, not wasteful.</strong> Every niche
          currently checking SERP results shares one combined queue rather than taking turns — 2-3 at once
          is a reasonable pace; running them all simultaneously mostly just means each one finishes a bit
          later rather than any capacity going to waste. The one rough edge is the very start of a run
          (the volume-lookup phase, before real per-city checks begin) — that part isn't shared the same
          way, so starting many niches at the exact same moment can briefly slow that first step down until
          each clears it on its own.</li>
        <li><strong>Volume phase (real money: Ahrefs/DataForSEO search volume).</strong> Each pass looks up
          every surviving city's monthly search volume, several cities per request. When it finishes it
          reports how many cities got dropped for falling under the minimum monthly volume, then moves on
          by itself.</li>
        <li><strong>SERP phase (real money: DataForSEO, ~$0.002/check).</strong> Each check is a real Google
          search <strong>geotargeted to that exact city's own coordinates</strong> (not a generic nationwide
          search), so the top-10 domains reflect who actually ranks there — DataForSEO's live endpoint only
          accepts one city per request, confirmed against the real API, so more cities/patterns means more
          passes. The progress line on the card below shows exactly how many keyword-checks are done vs.
          still to go.</li>
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
    eLocal buyer-coverage filter, real search volume, a real Google SERP check per city geotargeted to that
    city's own location. Nothing here selects a city or touches Batch; the output is an xlsx file in
    <strong>Downloads (Test Lab)</strong> and the decision what to build stays a separate, later step.
    <strong>If the "still to go" count stops moving for several minutes,</strong> check your provider
    balance on the <a href="index.php?view=cities">Cities/Niche</a> tab. A DataForSEO balance of
    <strong>$0 fails every check silently</strong> and retries forever on its own 2-minute schedule rather
    than stopping with a visible error. An exhausted <strong>Ahrefs API unit cap fails loudly</strong> (a
    visible 403 error on the run) but will also just keep retrying the same failing batch every cron tick
    until units are added — switching that run's provider isn't exposed in the UI once it's started, so it
    currently needs a direct fix to the run's saved state.
    <?php if (!$kwOn): ?><br><strong>No keyword provider connected</strong> — add DataForSEO/Ahrefs credentials
      on the <a href="index.php?view=cities">Cities/Niche</a> tab first (same credentials, reused here).<?php endif; ?>
  </p>
  <p style="margin:10px 0 0">
    <strong>Two limits worth knowing up front:</strong> one run can't cover the full population range — to
    span, say, 8k–1M+, run the tool twice (once each side of a cutoff) and pick between the two downloads;
    there's no automatic merge, ask if you want one combined ranked list. And <strong>scores aren't
    comparable across separate runs</strong> — each run's score is normalized only against its own candidate
    pool, so Run A's "B" grade and Run B's "B" grade don't mean the same thing. A real merge recomputes
    scores across the combined set; two separate downloads do not.
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
    <h2><?= $phase === 'done' ? 'Last run — ' : 'Run in progress — ' ?><?= ih(substr($active['created_at'], 0, 16)) ?></h2>
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
<?php endif; ?>

<?php if (!$active || $phase === 'done'): ?>
  <?php if ($active): ?><h2 style="margin-top:0">Start a new run</h2><?php endif; ?>
  <form method="post" action="actions/research_save.php" enctype="multipart/form-data" data-show-working="Working…">
    <input type="hidden" name="csrf" value="<?= ih(infra_csrf()) ?>">
    <input type="hidden" name="niche" value="<?= ih($niche) ?>">
    <div class="ic-card"><div class="body" style="display:flex;flex-direction:column;gap:14px">

      <label style="font-size:12px">Keyword patterns (one per line, <code>{city}</code> required)<br>
        <textarea name="patterns" rows="3" style="width:100%;max-width:520px;padding:6px 8px;font-family:monospace"><?=
          ih($draft['patterns'] ?? ($tpl !== '' ? $tpl : '')) ?></textarea></label>

      <div>
        <label style="font-size:12px">eLocal buyer-coverage export<br>
          <input type="file" name="elocal_csv" accept=".csv,.tsv,.txt,.xlsx" style="padding:5px 0"></label>
        <?php if (!empty($draft['elocal_csv_name'])): ?>
          <div style="font-size:12px;color:#065f46;margin-top:4px">
            <strong>Currently held: <?= ih($draft['elocal_csv_name']) ?></strong> — used automatically on Save/Run
            until you choose a different file above.
          </div>
        <?php endif; ?>
        <div style="font-size:12px;color:#6b7280;margin-top:4px">.csv, .tsv, .txt, or a real .xlsx file
          (first sheet only) — or paste rows below. Needs columns for city, state, buyer count, avg call
          price (and optionally max price); header names are matched loosely (e.g. "SMB Buyers", "1P Avg $").
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
        <label style="font-size:12px">Max rivals (SEO ease)<br><input name="max_rivals" type="number" min="0"
          value="<?= ih($draft['max_rivals'] ?? '') ?>" placeholder="no limit" style="width:90px;padding:5px 8px"></label>
      </div>
      <div style="font-size:12px;color:#6b7280;margin-top:-8px">
        <strong>Max rivals</strong> is checked <em>after</em> the SERP phase, on real competitor counts (not
        population) — a city is dropped if <code>local competitors + national brands</code> in the top 10
        results exceeds this number. Leave blank for no cap; the score already weights competition, this
        adds a hard floor for "must be easy to rank in" on top of that. Bigger population does not reliably
        mean more competition — check a completed run's Local/National Brands columns before assuming so.
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
          <td><?= (int) ($r['candidate_count'] ?? 0) ?></td>
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
