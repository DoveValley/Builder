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
$elocalLibrary = $niche === 'appliance' ? infra_elocal_library_load($niche) : null;

// Data 3's run picker + floor-suggestion — only loads a completed run's full
// candidates (needed for the floor suggestion) when there's one to suggest from.
$bucketCompletedRuns = array_values(array_filter($nicheRuns, fn($r) => ($r['phase'] ?? '') === 'done'));
$bucketDefaultRunId = $bucketCompletedRuns[0]['id'] ?? null;
$bucketSuggestedFloor = 15000;
if ($niche === 'appliance' && $bucketDefaultRunId) {
    $bucketDefaultRun = infra_research_load_run($bucketDefaultRunId);
    if ($bucketDefaultRun) $bucketSuggestedFloor = infra_city_bucket_suggest_floor($bucketDefaultRun['candidates']);
}
?>

<div style="margin-bottom:10px">
  <a class="btn sec" style="padding:4px 12px;font-size:13px" href="index.php?view=citydata">City Data &rarr;</a>
  <span style="font-size:12px;color:#6b7280">Census income/housing lookup — independent of niche, not scoped to any run below.</span>
</div>

<?php /* Universal — applies to every niche the same way (income/population theory, the
         target-city matrix's viable-count numbers, and the eLocal mechanics are all niche-
         agnostic; only "Expected calls/mo" and the brand-specific push below are actually
         calibrated to one niche's data, and those get their own small addendum inside that
         niche's own form further down). Inline on the page, not a popup: the whole point is
         that this theory reads BEFORE the operational walkthrough below it. */ ?>
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
          <th style="padding:6px 8px;border-bottom:1px solid #e5e7eb;white-space:nowrap">Expected calls/mo (appliance, established)</th>
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
    expect the low end of each range. <strong>These specific numbers are calibrated from appliance's own
    real run data</strong> — another niche's true figures will differ (lower frequency/higher value per
    job for something like water damage restoration vs. appliance's higher frequency), so treat them as a
    rough starting reference until a niche has its own real City Research data to check against.
  </p>

  <hr style="margin:18px 0;border:none;border-top:1px solid #e5e7eb">

  <p style="font-size:14px;font-weight:600;margin:0 0 10px">
    Target city matrix: website-only microsites (no links, no GBP)
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

  <p style="font-size:13px;line-height:1.5;color:#374151;margin:14px 0 0">
    <strong>Bucket prioritization, before any real data exists for this niche</strong> — income tier first,
    population band second within each tier, per the top principle above. Treat this as a starting
    heuristic only, not a conclusion — see why below.
  </p>
  <ol style="font-size:13px;line-height:1.5;color:#374151;margin:6px 0 0;padding-left:20px;columns:2;column-gap:28px">
    <li>Great × 75k–200k</li>
    <li>Great × 200k–400k</li>
    <li>Great × 25k–75k</li>
    <li>Great × 15k–25k</li>
    <li>Great × 400k–1M</li>
    <li>Great × 1M+</li>
    <li>Good × 75k–200k</li>
    <li>Good × 200k–400k</li>
    <li>Good × 25k–75k</li>
    <li>Good × 15k–25k</li>
    <li>Good × 400k–1M</li>
    <li>Good × 1M+</li>
    <li>OK × 75k–200k</li>
    <li>OK × 200k–400k</li>
    <li>OK × 25k–75k</li>
    <li>OK × 15k–25k</li>
    <li>OK × 400k–1M</li>
    <li>OK × 1M+</li>
  </ol>
  <p style="font-size:13px;line-height:1.5;color:#374151;margin:10px 0 0">
    <strong>Once a niche has real merged run data, this heuristic gets overridden — and it already has,
    materially, for both niches checked so far.</strong> Real Score (which already factors in actual
    competition, not just population) shows population/volume matters more than this income-first
    heuristic assumes: for appliance, "Great × 75k–200k" — the heuristic's #1 pick — actually ranks 8th
    in real data, behind several bigger, lower-income-tier buckets (e.g. "OK × 400k–1M" and "OK ×
    200k–400k" both score higher, with 20+ real cities backing each, not a fluke from a tiny sample).
    <strong>The real pattern isn't even the same across niches</strong> — for appliance, "Good × 75k–200k"
    outscores "Great × 75k–200k" in the exact same population band (richer areas there draw more real
    competition, enough to outweigh the extra demand); for plumbing, Great correctly beats Good and OK in
    that same band, the "expected" order. <strong>Always check the real order in that niche's own bucketed
    export's Read Me tab once a run exists</strong> — don't assume either the theoretical order above or
    another niche's real order carries over.
  </p>

  <p style="font-size:13px;line-height:1.5;color:#374151;margin:14px 0 0"><strong>Key notes</strong></p>
  <ul style="font-size:13px;line-height:1.5;color:#374151;margin:6px 0 0;padding-left:20px">
    <li><strong>Income beats population.</strong> Target $90k+ median household income and high
      homeownership; affluent owners repair rather than replace, so push whatever this niche's
      highest-end service/product-line pages are in those markets (see the niche-specific note inside
      the form below, if one exists for this niche).</li>
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
    <li><strong>Verify before you build.</strong> The City Data tab (linked above the niche tabs) already
      has real Census income/homeownership for ~99.8% of cities in this console — look a candidate up
      there before building rather than reasoning from these matrix estimates alone. These matrix counts
      are national theoretical ranges and exclude unincorporated communities like The Woodlands; City
      Data's numbers are the real, current ACS figures for an actual candidate.</li>
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
    <strong>Four limits worth knowing up front:</strong> one run can't cover the full population range — to
    span, say, 8k–1M+, run the tool twice (once each side of a cutoff) and pick between the two downloads;
    there's no automatic merge, ask if you want one combined ranked list. <strong>Scores aren't
    comparable across separate runs</strong> — each run's score is normalized only against its own candidate
    pool, so Run A's "B" grade and Run B's "B" grade don't mean the same thing. A real merge recomputes
    scores across the combined set; two separate downloads do not. <strong>If two runs being merged used
    different providers</strong> (one Ahrefs, one DataForSEO — e.g. because Ahrefs ran out of API units
    mid-niche), merging them as-is skews the result: DataForSEO reports roughly 3× the search volume of
    Ahrefs for the same real searches, so cities from the DataForSEO-sourced run score artificially higher
    on demand, not because they're better. A real merge across mixed providers needs that volume rescaled
    to one consistent basis first, not just combined and re-scored as-is. And <strong>the Score/SEO
    Difficulty only measure competition for this run's own keyword patterns</strong> (typically 2-3 generic
    head terms, e.g. "appliance repair {city}") — they never test the long-tail brand/model-specific pages
    (Sub-Zero refrigerator repair, KitchenAid oven repair, etc.) that make up most of a real site's page
    inventory. That cuts both ways and roughly evenly across city sizes: the generic head term may be
    harder to win in a big metro than Score fully captures (map pack / national brands dominate there more
    than an organic-only check sees), but a national competitor rarely has a dedicated long-tail page
    either, in a small town or a big one — so Score understates long-tail upside everywhere, not
    specifically in big or small cities. Treat it as "can I win the obvious term," not the full picture.
  </p>
</div>

<?php if ($niche === 'appliance'): ?>
<?php /* Standalone, NOT inside the Data 2 form on purpose (fixed 2026-10-26): the
         per-row Select/Delete buttons each need their own <form> — nested inside
         another <form>, browsers silently drop the inner one and those buttons end
         up submitting to the OUTER form instead (verified against the real parsed
         DOM, not assumed). Living here also means the library stays visible even
         while a run is actively in progress, instead of disappearing along with the
         "Start a new run" form. */ ?>
<div class="ic-card" style="margin-bottom:14px"><div class="body">
  <h2 style="margin-top:0">Data 1 — eLocal</h2>
  <strong style="font-size:13px">File library</strong>
  <div style="font-size:12px;color:#6b7280;margin:4px 0 8px">
    Upload as many eLocal exports as you want, then pick which one is active — Select copies it
    into the slot Save/Run actually reads, so only one is ever "live" at a time.
  </div>
  <?php if ($elocalLibrary['files']): ?>
    <table style="width:100%;border-collapse:collapse;font-size:12.5px;max-width:720px">
      <thead>
        <tr style="background:#f9fafb;text-align:left">
          <th style="padding:6px 8px;border-bottom:1px solid #e5e7eb"></th>
          <th style="padding:6px 8px;border-bottom:1px solid #e5e7eb">File</th>
          <th style="padding:6px 8px;border-bottom:1px solid #e5e7eb;white-space:nowrap">Size</th>
          <th style="padding:6px 8px;border-bottom:1px solid #e5e7eb;white-space:nowrap">Uploaded</th>
          <th style="padding:6px 8px;border-bottom:1px solid #e5e7eb"></th>
        </tr>
      </thead>
      <tbody style="color:#374151">
        <?php foreach ($elocalLibrary['files'] as $f): $isSel = $f['id'] === $elocalLibrary['selected_id']; ?>
          <tr>
            <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">
              <?= $isSel ? '<span class="badge b-ok">selected</span>' : '' ?></td>
            <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6"><?= ih($f['original_name']) ?></td>
            <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6"><?= number_format($f['size'] / 1024, 0) ?> KB</td>
            <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6"><?= ih(substr($f['uploaded_at'], 0, 10)) ?></td>
            <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6;white-space:nowrap">
              <?php if (!$isSel): ?>
                <form method="post" action="actions/elocal_library_save.php" style="display:inline">
                  <input type="hidden" name="csrf" value="<?= ih(infra_csrf()) ?>">
                  <input type="hidden" name="niche" value="<?= ih($niche) ?>">
                  <input type="hidden" name="action" value="select">
                  <input type="hidden" name="id" value="<?= ih($f['id']) ?>">
                  <button class="btn sec" style="padding:2px 8px;font-size:11px" type="submit">Select</button>
                </form>
              <?php endif; ?>
              <a class="btn sec" style="padding:2px 8px;font-size:11px"
                 href="actions/elocal_library_download.php?niche=<?= urlencode($niche) ?>&id=<?= urlencode($f['id']) ?>">Download</a>
              <form method="post" action="actions/elocal_library_save.php" style="display:inline"
                    onsubmit="return confirm('Remove &quot;<?= ih(addslashes($f['original_name'])) ?>&quot; from the library?')">
                <input type="hidden" name="csrf" value="<?= ih(infra_csrf()) ?>">
                <input type="hidden" name="niche" value="<?= ih($niche) ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= ih($f['id']) ?>">
                <button class="btn sec" style="padding:2px 8px;font-size:11px" type="submit">Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php else: ?>
    <p style="font-size:12px;color:#9ca3af">No files in the library yet.</p>
  <?php endif; ?>
  <form method="post" action="actions/elocal_library_save.php" enctype="multipart/form-data" style="margin-top:10px">
    <input type="hidden" name="csrf" value="<?= ih(infra_csrf()) ?>">
    <input type="hidden" name="niche" value="<?= ih($niche) ?>">
    <input type="hidden" name="action" value="upload">
    <input type="file" name="elocal_file" accept=".csv,.tsv,.txt,.xlsx" style="padding:5px 0">
    <button class="btn sec" type="submit">Add to library</button>
  </form>
  <?php if (!empty($draft['elocal_csv_name'])): ?>
    <div style="font-size:12px;color:#065f46;margin-top:8px">
      <strong>Currently held: <?= ih($draft['elocal_csv_name']) ?></strong> — used automatically on Save/Run.
    </div>
  <?php endif; ?>
</div></div>
<?php endif; ?>

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

      <?php if ($niche === 'appliance'): ?>
        <h2 style="margin:0">Data 2 — Volume Run</h2>
        <div style="font-size:12px;color:#6b7280">
          eLocal file management (upload / select / delete) lives in Data 1, above the niche tabs —
          whatever's marked "selected" there is what Save/Run below actually reads.
          <?php if (!empty($draft['elocal_csv_name'])): ?>
            <br><strong style="color:#065f46">Currently held: <?= ih($draft['elocal_csv_name']) ?></strong>
          <?php endif; ?>
        </div>
        <label style="font-size:12px">Or paste eLocal rows directly (optional, supplements the selected file)<br>
          <textarea name="elocal_paste" rows="3" style="width:100%;max-width:640px;padding:6px 8px;font-family:monospace"
                    placeholder="city,state,buyers,price_avg,price_max"><?= ih($draft['elocal_paste'] ?? '') ?></textarea></label>
        <label style="font-size:12px">Keyword patterns (one per line, <code>{city}</code> required)<br>
          <textarea name="patterns" rows="3" style="width:100%;max-width:520px;padding:6px 8px;font-family:monospace"><?=
            ih($draft['patterns'] ?? ($tpl !== '' ? $tpl : '')) ?></textarea></label>
      <?php else: ?>
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
      <?php endif; ?>

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

<?php if ($niche === 'appliance'): ?>
<div class="ic-card" style="margin-top:14px"><div class="body">
  <h2 style="margin-top:0">Data 3 — City Matrix &amp; Build Order</h2>

  <?php if ($bucketCompletedRuns): ?>
  <form method="post" action="actions/city_bucket_run.php" data-show-working="Working…"
        style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:12px;margin-bottom:12px">
    <input type="hidden" name="csrf" value="<?= ih(infra_csrf()) ?>">
    <input type="hidden" name="niche" value="<?= ih($niche) ?>">
    <label style="font-size:12px">Run<br>
      <select name="run_id" style="padding:5px 8px;max-width:360px">
        <?php foreach ($bucketCompletedRuns as $r): ?>
          <option value="<?= ih($r['id']) ?>" <?= $r['id'] === $bucketDefaultRunId ? 'selected' : '' ?>>
            <?= ih(substr($r['created_at'], 0, 16)) ?> — <?= (int) ($r['candidate_count'] ?? 0) ?> cities
            (<?= ih($r['id']) ?>)</option>
        <?php endforeach; ?>
      </select></label>
    <label style="font-size:12px">Population floor<br>
      <input name="pop_floor" type="number" step="1000" value="<?= ih((string) $bucketSuggestedFloor) ?>"
             style="width:100px;padding:5px 8px"></label>
    <div>
      <button class="btn" type="submit">Run Data 3</button>
      <button type="button" onclick="document.getElementById('data3Info').showModal()"
              style="border:1px solid #d1d5db;background:#fff;border-radius:50%;width:22px;height:22px;
                     line-height:20px;text-align:center;cursor:pointer;font-size:12px;color:#6b7280;padding:0"
              title="What does this do?">i</button>
    </div>
  </form>
  <dialog id="data3Info" style="max-width:520px;width:92%;border:1px solid #d1d5db;border-radius:10px;padding:0">
    <div style="padding:18px 20px">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
        <strong style="font-size:14px">What "Run Data 3" does</strong>
        <button type="button" onclick="this.closest('dialog').close()"
                style="border:none;background:none;font-size:18px;line-height:1;cursor:pointer;color:#6b7280">&times;</button>
      </div>
      <p style="font-size:13px;line-height:1.5;color:#374151;margin:0 0 8px">
        Takes the selected completed Data 2 run and joins every one of its candidate cities against
        Census City Data (median income, homeownership) — a local database lookup, no API cost. Buckets
        each city by population band × income tier, computes a real Score-ranked Build Order per bucket
        (not the theoretical income-first order), and writes a downloadable xlsx with a Read Me tab.
      </p>
      <p style="font-size:13px;line-height:1.5;color:#374151;margin:0 0 8px">
        <strong>Population floor</strong> excludes cities below it entirely — some niches (water damage
        restoration, mold, roofing) show near-zero real search volume below 75k population, so their
        floor should be 75000, not 15000. The value shown is auto-suggested from this run's own real
        volume data (checks the 15k–25k band specifically), but it's a judgment call, not a hard rule —
        change it if you know better for this run.
      </p>
      <p style="font-size:13px;line-height:1.5;color:#374151;margin:0">
        Safe to run as many times as you want — it never changes the underlying run, just reads it and
        writes a new dated xlsx each time.
      </p>
    </div>
  </dialog>
  <?php else: ?>
  <p style="font-size:13px;color:#9ca3af">No completed Data 2 run yet to bucket.</p>
  <?php endif; ?>

  <p style="font-size:13px;line-height:1.5;color:#374151">
    The real output of Data 1 + Data 2, once a run is joined against City Data: every candidate city
    bucketed by population band × income tier, with a Build Order number so the whole list sorts into
    build-priority order with one click.
  </p>

  <?php $bucketHistory = infra_city_bucket_history_load($niche); ?>
  <?php if ($bucketHistory): ?>
    <h2 style="margin:14px 0 8px;font-size:15px">Past bucket runs — <?= ih($niches[$niche]['label']) ?></h2>
    <table><thead><tr>
        <th>When</th><th>Source run</th><th>Floor</th><th>Cities</th><th>File</th><th></th>
      </tr></thead><tbody>
      <?php foreach ($bucketHistory as $h): ?>
        <tr>
          <td><?= ih(substr($h['created_at'], 0, 16)) ?></td>
          <td style="font-size:11px;color:#6b7280"><?= ih($h['source_run_id']) ?></td>
          <td><?= number_format((int) $h['pop_floor']) ?></td>
          <td><?= (int) $h['count'] ?></td>
          <td><a href="/uploads/downloads/<?= rawurlencode($h['file']) ?>" download><?= ih($h['file']) ?></a></td>
          <td><a class="btn sec" style="padding:2px 8px;font-size:11px" target="_blank"
                 href="/uploads/downloads/<?= rawurlencode($h['file']) ?>">Open</a></td>
        </tr>
      <?php endforeach; ?>
    </tbody></table>
  <?php else: ?>
    <p style="font-size:13px;color:#9ca3af">No bucket runs yet — use "Run Data 3" above.</p>
  <?php endif; ?>
  <p style="font-size:13px;line-height:1.5;color:#374151;margin-top:10px">
    <strong>Brand push:</strong> affluent owners repair rather than replace — Sub-Zero, Wolf, Thermador,
    Viking, and Miele pages are worth extra effort in this niche's high-income markets specifically.
  </p>
  <p style="font-size:13px;line-height:1.5;color:#374151;margin-top:10px">
    <strong>Tiny-sample standouts from the real merged run</strong> — the 5 buckets too thin to be a
    repeatable strategy (1–7 cities each), but each one is a real, named, individually-checkable city,
    not a statistical pattern. Score already factors in real competition.
  </p>
  <div style="overflow-x:auto">
    <table style="width:100%;border-collapse:collapse;font-size:12.5px">
      <thead>
        <tr style="background:#f9fafb;text-align:left">
          <th style="padding:6px 8px;border-bottom:1px solid #e5e7eb">Bucket</th>
          <th style="padding:6px 8px;border-bottom:1px solid #e5e7eb">City</th>
          <th style="padding:6px 8px;border-bottom:1px solid #e5e7eb;white-space:nowrap">Population</th>
          <th style="padding:6px 8px;border-bottom:1px solid #e5e7eb;white-space:nowrap">Income</th>
          <th style="padding:6px 8px;border-bottom:1px solid #e5e7eb;white-space:nowrap">Score</th>
          <th style="padding:6px 8px;border-bottom:1px solid #e5e7eb;white-space:nowrap">Volume/mo</th>
        </tr>
      </thead>
      <tbody style="color:#374151">
        <tr><td style="padding:6px 8px;border-bottom:1px solid #f3f4f6" rowspan="1">Good × 1M+</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">San Diego, CA</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">1,404,452</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">$104,321</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">1.068</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">2,220</td></tr>
        <tr><td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">Great × 400k–1M</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">San Jose, CA</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">997,368</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">$141,565</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">1.079</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">1,930</td></tr>
        <tr><td style="padding:6px 8px;border-bottom:1px solid #f3f4f6"></td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">San Francisco, CA</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">827,526</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">$141,446</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">0.875</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">870</td></tr>
        <tr><td style="padding:6px 8px;border-bottom:1px solid #f3f4f6"></td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">Seattle, WA</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">780,995</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">$121,984</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">0.725</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">920</td></tr>
        <tr><td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">Great × 200k–400k</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">Frisco, TX</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">235,208</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">$146,158</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">1.16</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">770</td></tr>
        <tr><td style="padding:6px 8px;border-bottom:1px solid #f3f4f6"></td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">Irvine, CA</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">318,683</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">$129,647</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">1.094</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">190</td></tr>
        <tr><td style="padding:6px 8px;border-bottom:1px solid #f3f4f6"></td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">Fremont, CA</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">228,192</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">$176,350</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">0.972</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">390</td></tr>
        <tr><td style="padding:6px 8px;border-bottom:1px solid #f3f4f6"></td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">Gilbert, AZ</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">288,790</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">$121,351</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">0.656</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">690</td></tr>
        <tr><td style="padding:6px 8px;border-bottom:1px solid #f3f4f6"></td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">McKinney, TX</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">227,526</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">$120,273</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">0.513</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">1,250</td></tr>
        <tr><td style="padding:6px 8px;border-bottom:1px solid #f3f4f6"></td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">Santa Clarita, CA</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">229,159</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">$119,926</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">0.38</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">640</td></tr>
        <tr><td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">OK × 1M+</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">Phoenix, AZ</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">1,673,164</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">$77,041</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6"><strong>1.215</strong></td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">2,060</td></tr>
        <tr><td style="padding:6px 8px;border-bottom:1px solid #f3f4f6"></td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">Los Angeles, CA</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">3,878,704</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">$80,366</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">1.17</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">1,730</td></tr>
        <tr><td style="padding:6px 8px;border-bottom:1px solid #f3f4f6"></td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">Chicago, IL</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">2,721,308</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">$75,134</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">0.898</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6"><strong>4,190</strong></td></tr>
        <tr><td style="padding:6px 8px;border-bottom:1px solid #f3f4f6"></td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">Dallas, TX</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">1,326,087</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">$67,760</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">0.759</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">1,830</td></tr>
        <tr><td style="padding:6px 8px;border-bottom:1px solid #f3f4f6"></td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">New York, NY</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">8,478,072</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">$79,713</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">0.6</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">730</td></tr>
        <tr><td style="padding:6px 8px;border-bottom:1px solid #f3f4f6"></td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">Fort Worth, TX</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">1,008,106</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">$76,602</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">0.552</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">2,210</td></tr>
        <tr><td style="padding:6px 8px;border-bottom:1px solid #f3f4f6"></td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">Jacksonville, FL</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">1,009,833</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">$66,981</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">0.485</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">2,810</td></tr>
        <tr><td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">Good × 400k–1M</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">Virginia Beach, VA</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">454,808</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">$90,685</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">0.946</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">1,270</td></tr>
        <tr><td style="padding:6px 8px;border-bottom:1px solid #f3f4f6"></td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">Austin, TX</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">993,588</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">$91,461</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">0.89</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">2,890</td></tr>
        <tr><td style="padding:6px 8px;border-bottom:1px solid #f3f4f6"></td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">Boston, MA</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">673,458</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">$94,755</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">0.758</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">690</td></tr>
        <tr><td style="padding:6px 8px;border-bottom:1px solid #f3f4f6"></td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">Denver, CO</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">729,019</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">$91,681</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">0.643</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">2,750</td></tr>
        <tr><td style="padding:6px 8px;border-bottom:1px solid #f3f4f6"></td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">Oakland, CA</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">443,554</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">$97,369</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">0.642</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">400</td></tr>
        <tr><td style="padding:6px 8px;border-bottom:1px solid #f3f4f6"></td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">Washington, DC</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">702,250</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">$106,287</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">0.358</td>
          <td style="padding:6px 8px;border-bottom:1px solid #f3f4f6">390</td></tr>
      </tbody>
    </table>
  </div>
  <p style="font-size:12px;line-height:1.5;color:#6b7280;margin:8px 0 0">
    Phoenix (score 1.215) is the single highest-scoring city across all 2,185 scored appliance
    candidates — in the "OK" income tier, not "Great," reinforcing that income tier alone
    undersells it. Pulled from run <code>appliance-20261026-152705-merged</code>.
  </p>
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
