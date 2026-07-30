<?php
/**
 * Directory Network — data cross-tabs (read-only report).
 *
 * Generated 2026-07-29/30 against the production Supabase database for the
 * `recovery` niche. Nothing here writes anything; it renders the CSVs staged in
 * ./_dirnet-data/ and adds the findings that came out of building them.
 *
 * Auth: same session gate as the Test Lab (playground.php).
 */
require_once __DIR__ . '/../config.php';
if (empty($_SESSION['admin_logged_in'])) { header('Location: login.php'); exit; }

$DIR = __DIR__ . '/_dirnet-data';

/** Read a CSV into [header, rows]. */
function csv_load(string $path): array {
    if (!is_readable($path)) return [[], []];
    $rows = [];
    $fh = fopen($path, 'r');
    while (($r = fgetcsv($fh)) !== false) { $rows[] = $r; }
    fclose($fh);
    $head = array_shift($rows) ?: [];
    return [$head, $rows];
}

/** Render one CSV as a table + copy/download controls. */
function panel(string $id, string $title, string $file, string $note = '', int $preview = 0): void {
    global $DIR;
    [$head, $rows] = csv_load("$DIR/$file");
    $total = count($rows);
    $shown = ($preview > 0 && $total > $preview) ? array_slice($rows, 0, $preview) : $rows;
    echo '<section class="panel" id="' . htmlspecialchars($id) . '">';
    echo '<h2>' . htmlspecialchars($title) . '</h2>';
    if ($note) echo '<div class="note">' . $note . '</div>';
    if (!$head) { echo '<p class="err">Missing or unreadable: ' . htmlspecialchars($file) . '</p></section>'; return; }
    echo '<div class="bar">';
    echo '<button type="button" class="btn" onclick="copyTsv(\'' . htmlspecialchars($id) . '\')">Copy as TSV</button> ';
    echo '<a class="btn alt" href="_dirnet-data/' . rawurlencode($file) . '" download>Download CSV</a> ';
    // Show the filename on screen, not only inside the download href — otherwise a file you were told to
    // look for is unfindable on the page.
    echo '<span class="rows"><code>' . htmlspecialchars($file) . '</code> &middot; ' . $total . ' rows'
       . ($total > count($shown) ? ' (showing first ' . count($shown) . ' — copy/download gives all)' : '') . '</span>';
    echo '</div>';
    echo '<div class="scroll"><table><thead><tr>';
    foreach ($head as $h) echo '<th>' . htmlspecialchars($h) . '</th>';
    echo '</tr></thead><tbody>';
    foreach ($shown as $r) {
        echo '<tr>';
        foreach ($r as $i => $c) {
            $num = is_numeric($c);
            echo '<td class="' . ($num ? 'n' : '') . ($i === 0 ? ' k' : '') . '">' . htmlspecialchars($c) . '</td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table></div>';
    // full data for the clipboard, including rows not rendered
    $tsv = implode("\t", $head) . "\n";
    foreach ($rows as $r) $tsv .= implode("\t", $r) . "\n";
    echo '<textarea id="tsv-' . htmlspecialchars($id) . '" class="hidden-tsv">' . htmlspecialchars($tsv) . '</textarea>';
    echo '</section>';
}

/**
 * Render a markdown file as preformatted text, with the same copy/download controls as a CSV panel.
 *
 * Deliberately NOT converted to HTML: this is a QA report meant to be copied verbatim into a doc or a
 * ticket, so the markdown source is the useful artifact. Reuses the copyTsv() clipboard helper, which
 * only needs a textarea with a matching id.
 */
function doc(string $id, string $title, string $file, string $note = ''): void {
    global $DIR;
    $path = "$DIR/$file";
    echo '<section class="panel" id="' . htmlspecialchars($id) . '">';
    echo '<h2>' . htmlspecialchars($title) . '</h2>';
    if ($note) echo '<div class="note">' . $note . '</div>';
    if (!is_readable($path)) { echo '<p class="err">Missing or unreadable: ' . htmlspecialchars($file) . '</p></section>'; return; }
    $md = file_get_contents($path);
    echo '<div class="bar">';
    echo '<button type="button" class="btn" onclick="copyTsv(\'' . htmlspecialchars($id) . '\')">Copy as Markdown</button> ';
    echo '<a class="btn alt" href="_dirnet-data/' . rawurlencode($file) . '" download>Download .md</a> ';
    echo '<span class="rows"><code>' . htmlspecialchars($file) . '</code> &middot; '
       . number_format(substr_count($md, "\n")) . ' lines</span>';
    echo '</div>';
    echo '<div class="scroll" style="max-height:520px"><pre style="margin:0;font-size:12px;line-height:1.5;white-space:pre-wrap">'
       . htmlspecialchars($md) . '</pre></div>';
    echo '<textarea id="tsv-' . htmlspecialchars($id) . '" class="hidden-tsv">' . htmlspecialchars($md) . '</textarea>';
    echo '</section>';
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Directory Network — data cross-tabs</title>
<style>
  :root { --ink:#0f172a; --mut:#64748b; --line:#e2e8f0; --bg:#f8fafc; --acc:#fd783b; --warn:#b45309; --bad:#b91c1c; --ok:#047857; }
  * { box-sizing:border-box; }
  body { margin:0; font:14px/1.55 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif; color:var(--ink); background:var(--bg); }
  header.top { background:#0f172a; color:#fff; padding:14px 22px; display:flex; align-items:center; gap:14px; flex-wrap:wrap; }
  header.top .logo { font-weight:700; font-size:17px; }
  header.top .logo small { color:var(--acc); font-weight:600; }
  header.top .meta { color:#94a3b8; font-size:12px; margin-left:auto; }
  header.top a { color:#cbd5e1; font-size:13px; text-decoration:none; border:1px solid #334155; padding:4px 10px; border-radius:6px; }
  main { max-width:1400px; margin:0 auto; padding:22px; }
  h2 { font-size:16px; margin:0 0 4px; }
  h3 { font-size:14px; margin:20px 0 6px; }
  .panel { background:#fff; border:1px solid var(--line); border-radius:10px; padding:16px; margin:0 0 20px; }
  .note { color:var(--mut); font-size:13px; margin:6px 0 12px; }
  .note code { background:#f1f5f9; padding:1px 5px; border-radius:4px; font-size:12px; }
  .bar { display:flex; align-items:center; gap:8px; margin:10px 0; flex-wrap:wrap; }
  .btn { background:var(--acc); color:#fff; border:0; border-radius:6px; padding:6px 12px; font-size:13px; font-weight:600; cursor:pointer; text-decoration:none; display:inline-block; }
  .btn.alt { background:#475569; }
  .btn:active { transform:translateY(1px); }
  .rows { color:var(--mut); font-size:12px; }
  .scroll { overflow:auto; max-height:520px; border:1px solid var(--line); border-radius:8px; }
  table { border-collapse:collapse; width:100%; font-size:12.5px; }
  th, td { padding:5px 9px; border-bottom:1px solid var(--line); white-space:nowrap; text-align:left; }
  th { position:sticky; top:0; background:#f1f5f9; font-size:11px; text-transform:uppercase; letter-spacing:.03em; color:#475569; z-index:1; }
  td.n { text-align:right; font-variant-numeric:tabular-nums; }
  td.k { font-weight:600; }
  tbody tr:hover td { background:#fff7ed; }
  .hidden-tsv { position:absolute; left:-9999px; width:1px; height:1px; }
  .call { border-left:3px solid var(--acc); background:#fff7ed; padding:12px 14px; border-radius:0 8px 8px 0; margin:12px 0; }
  .call.bad { border-color:var(--bad); background:#fef2f2; }
  .call.ok { border-color:var(--ok); background:#ecfdf5; }
  .call.warn { border-color:var(--warn); background:#fffbeb; }
  .call b { display:block; margin-bottom:4px; }
  ul { margin:8px 0; padding-left:20px; }
  li { margin:3px 0; }
  nav.toc { background:#fff; border:1px solid var(--line); border-radius:10px; padding:12px 16px; margin-bottom:20px; }
  nav.toc a { color:#0f172a; text-decoration:none; font-size:13px; display:inline-block; margin:3px 14px 3px 0; }
  nav.toc a:hover { color:var(--acc); text-decoration:underline; }
  .kpi { display:flex; gap:10px; flex-wrap:wrap; margin:10px 0; }
  .kpi div { background:#f1f5f9; border-radius:8px; padding:8px 14px; min-width:120px; }
  .kpi b { display:block; font-size:19px; }
  .kpi span { color:var(--mut); font-size:11.5px; }
</style>
</head>
<body>
<header class="top">
  <div class="logo">Directory Network <small>data cross-tabs</small></div>
  <a href="playground.php">&larr; Test Lab</a>
  <a href="dirnet-answers.php">Four answers</a>
  <a href="dirnet-sheets.php">Spreadsheets</a>
  <a href="docs.php">Docs</a>
  <div class="meta">recovery niche &middot; production Supabase &middot; generated 2026-07-29</div>
</header>
<main>

<nav class="toc">
  <a href="#summary">Summary &amp; caveats</a>
  <a href="#x1">1. State &times; carrier</a>
  <a href="#x1b">1b. Carrier concentration</a>
  <a href="#x1c">1c. Chain-adjusted carriers</a>
  <a href="#x2">2. City &times; top-8 carriers</a>
  <a href="#x3">3. State &times; level of care</a>
  <a href="#x4">4. Facilities by city</a>
  <a href="#x5">5. Fill rate &amp; provenance</a>
  <a href="#x6">6. Deduplication</a>
  <a href="#x7">7. Density &mdash; 287 cities</a>
  <a href="#x7qa">7b. Density QA report</a>
</nav>

<section class="panel" id="summary">
<h2>Summary &mdash; and the two things that change your read</h2>

<div class="kpi">
  <div><b>17,827</b><span>active facilities</span></div>
  <div><b>~17,798</b><span>distinct buildings</span></div>
  <div><b>5,111</b><span>cities with &ge;1</span></div>
  <div><b>315</b><span>cities with &ge;10</span></div>
  <div><b>101</b><span>cities live</span></div>
  <div><b>28</b><span>carriers with data</span></div>
</div>

<div class="call ok">
<b>Deduplication is a non-issue &mdash; your count is real.</b>
The SAMHSA 3&ndash;4-records-per-facility problem does not apply to this corpus. 17,827 active listings resolve to
<b>17,798 distinct buildings</b>: 27 buildings hold 2 records and 1 holds 3. Measured a second way, only <b>5</b>
records exist beyond the number of distinct sites. No threshold below shifts, and your real count is not 11,000.
<br><br>
What makes it <i>look</i> duplicated: only 10,580 distinct names across 17,827 listings. But 9,617 listings share a
name with another and <b>9,579 of those are at genuinely different addresses</b> &mdash; multi-site chains (Spero Health 70
sites, Compass Health 64, Community Medical Services 60 across 11 states). Chains, not duplicates.
</div>

<div class="call bad">
<b>Your regional-carrier hypothesis is right about the world and wrong about this data &mdash; and the reason is a defect.</b>
CareFirst is <b>28%</b> Maryland, not "nearly all Maryland/DC/Virginia". Highmark is <b>23%</b> Pennsylvania. Tufts <b>29%</b>
Massachusetts. Intermountain <b>25%</b> Utah. California is the top state for 15 of 28 carriers, which is a tell.
<br><br>
Cause, traced: <b>19 of the 20 Michigan CareFirst listings are LifeStance Health</b> &mdash; one national chain whose website
lists every insurer it accepts anywhere, harvested onto all 19 Michigan locations. Across the corpus,
<b>52.7% of crawled carrier rows (12,606 of 23,919) come from websites shared by 5+ listings</b>, and some single
listings claim 25&ndash;30 carriers. This is the same domain-level-fact-vs-location-level-fact error that put 13,000
non-findings in the verification queue, except here it is in the attribute data feeding the pages.
<br><br>
Table 1c isolates it. Use <code>national_ex_chain</code> as the defensible carrier count. The regional opening you
predicted may still be there &mdash; but it cannot be read off table 1 until chain-sourced carrier claims are
attributed per location or excluded.
</div>

<div class="call warn">
<b>Two indexes declared in <code>prisma/postgis.sql</code> are missing from production.</b>
<code>listing_location_gix</code> (GiST on location) and <code>listing_nichedata_gin</code> (GIN, jsonb_path_ops) are both absent
&mdash; confirmed by <code>npm run check:indexes</code>. Every city-page radius query and every browse facet query is
running as a sequential scan. Fix is <code>npm run check:indexes -- --apply</code>. Not applied; it is a write to your
production database and you did not ask for one.
</div>

<div class="call">
<b>Live cities are not aligned with facility density.</b>
Of the 315 cities holding 10+ facilities, <b>only 53 are live</b> &mdash; 262 are dark. Meanwhile 48 of your 101 live
cities hold fewer than 10. The publish list was inherited from the 40-city Site Factory set, not derived from
the data you now have.
</div>

<h3>How each number was measured</h3>
<ul>
  <li><b>State rows</b> use the state page's own predicate: <code>stateSlug</code> + facet, no radius.</li>
  <li><b>City rows</b> use the city page's own predicate: <b>30-mile radius from the city's coordinates, crossing state
      lines</b>, no city-slug filter. This is why <code>within_30mi</code> exceeds <code>assigned_in_city</code> &mdash; and why a New York
      City page sees 711 facilities. Matching the page exactly matters: a count that disagrees with its page is
      the bug class this project has hit repeatedly.</li>
  <li><b>Facets</b> are read from <code>nicheData @&gt; '{"kind":["slug"]}'</code> &mdash; the identical containment operator
      <code>searchListings()</code> uses, so these counts are what the gate and the grid will report.</li>
  <li><b>Carriers</b> exclude Medicaid/Medicare/TRICARE, which are programmes projected into the insurance facet,
      not carriers. All 28 carriers with any data are included rather than a top-16 &mdash; the 12 that a cut would
      drop (GEHA, Tufts, GuideWell, Oscar&hellip;) are precisely the regional ones in question.</li>
  <li>Gate is <code>minListings=3</code>, <code>radiusMiles=30</code>.</li>
</ul>
</section>

<?php
panel('x1', '1. State × carrier — 51 rows × 28 carriers', '01-state-x-carrier.csv',
  'State-page predicate (<code>stateSlug</code> + facet, no radius), so a cell <b>&ge;3</b> means that state×carrier page clears the
   viability gate today. Read alongside 1c: roughly half of every carrier column is chain-site-sourced.');

panel('x1b', '1b. Carrier geographic concentration', '01b-carrier-concentration.csv',
  '<code>pct_of_national</code> is the share of a carrier\'s facilities sitting in its single biggest state. Nothing exceeds
   37%, which is the finding: no carrier in this data is regionally concentrated the way the real insurance market is.
   <code>states_viable_at_gate3</code> counts states where that carrier already clears the gate.');

panel('x1c', '1c. Carrier counts with chain-site inflation isolated', '01c-carrier-chain-adjusted.csv',
  '<code>from_chain_sites</code> = facilities whose carrier list came from a website shared by 5+ listings.
   <b><code>national_ex_chain</code> is the defensible number.</b> Kaiser 544→158 (71% chain), AmeriHealth 434→144, WellCare 466→173.
   Tufts is the cleanest of the regionals at 15.3% chain-sourced.');

panel('x2', '2. City × top-8 carriers — every city with 10+ facilities', '02-city-x-top8-carriers.csv',
  '315 cities. <b>Radius-based (30mi), matching what a city page actually serves</b> — hence <code>within_30mi</code> ≫
   <code>assigned_in_city</code>. A cell ≥3 clears the gate. <code>live</code> is the real <code>CityActivation</code> state.
   <b>Your Fort Worth line:</b> 15 assigned, 70 within 30mi — BCBS 16, Aetna 15, Cigna 13, Ambetter 13, UHC 9, Humana 8,
   Optum 6, Anthem 5. All eight clear the gate, so Fort Worth supports 8 carrier pages. Not live.', 40);

panel('x3', '3. State × level of care', '03-state-x-level-of-care.csv',
  'You were right that this is the strongest tier. Every one of the 12 routable levels clears the gate in most states,
   the facets are genuinely distinct, and none of it depends on the crawled carrier data — it is SAMHSA/findtreatment
   licensing detail at 100% fill. California alone: detox 612, residential 663, IOP 512, PHP 415, outpatient 1,269,
   dual-diagnosis 1,204.');

panel('x4', '4. Facility count by city — full list', '04-facility-count-by-city.csv',
  'All 5,111 cities holding at least one active facility, with band and live state. <b>Bands: 20+ = 109 cities
   (4,521 facilities) · 10–19 = 206 (2,785) · 5–9 = 529 (3,379) · 1–4 = 4,267 (7,142).</b>
   This is your page-count ceiling: <b>109 cities at 20+, 315 at 10+, 844 at 5+, 1,661 at 3+</b> (the gate threshold).', 60);

panel('x5', '5a. Attribute fill rate, provenance and freshness', '05a-attribute-fill-and-provenance.csv',
  'Per attribute kind: distinct listings populated, % of the 17,827 active, value counts, negated counts, and first/last
   observation dates. Source columns are distinct-listing counts per source.
   <b>Above your ~40% anchor line:</b> levels_of_care 100%, clientele 100%, payment_options 99.6%, age_groups 99.4%,
   therapies 99.2%, approaches 98.9%, conditions 94.3%, languages 89.3%, substances 86.0%, policies 79.8%,
   gender_program 68.6%, accreditations 68.3%, insurance 63.5%, amenities 45.7%.
   <b>Below it, cannot anchor a page:</b> year_founded 16.8%, length_of_stay 7.3%, price_band 5.5%, activities 3.1%,
   setting 2.7%, bed_count 2.5%, accessibility 1.8%, dietary 0.4%.
   Note <code>insurance</code> at 63.5% is the one that carries the chain-attribution problem.');

panel('x5b', '5b. Core field fill rate', '05b-core-field-fill.csv',
  'The structural fields. Address/city/coordinates ~100%, phone 99.4%, website 87.2%, email 30.0%, NPI 49.2%.
   <b>Zero descriptions, zero claimed, zero paid tier.</b> 3,574 listings carry photo candidates but they are all
   <code>licensed:false</code>, so displayable photos are effectively zero. 843 verified.');

panel('x6', '6. Records per physical building', '06a-records-per-building.csv',
  'Grouped on normalised street address with suite/unit/floor stripped, within city+state — so co-located <i>programmes</i>
   would collapse together. They do not: 17,770 buildings hold exactly one record.
   Cross-checked against coordinates (17,562 distinct points for 17,823 geocoded; the small overlap is
   interpolated/ZIP-precision geocoding, 2,045 + 200 listings, collapsing nearby distinct addresses).');

panel('x7', '7. Facility density for the 287 candidate cities', 'density_counts.csv',
  'Straight from the SAMHSA 2025 N-SUMHSS directories (both files, 27,563 raw rows &rarr; 18,796 unique facilities).
   287 data rows, ascending by <code>workbook_row</code>, aligned row-for-row with your seed sheet &mdash; paste
   straight in. <b>All 287 rows now carry counts &mdash; <code>geocode_status</code> is <code>OK</code> throughout, and every
   0 in the table is a real 0.</b> Two cities needed a coordinate the Census Places file could not give:
   <b>San Francisco</b>, whose own internal point sits 32.6 miles out in the Pacific because the Farallon Islands are
   inside the city limits, and <b>Hyannis</b>, which has no Places record at all (it is a village of Barnstable). Both
   were derived from the Census ZCTA gazetteer, not typed in &mdash; derivations are in the QA report below.
   <code>otp_25</code> counts opioid treatment
   programs that are <i>not</i> already in <code>strict_25</code>, so the two can be added without double-counting.
   <br><b>43 columns.</b> The original nine keep their names <i>and</i> their positions, so an existing join still
   works; the 34 per-axis columns are appended after <code>geocode_status</code>, each as <code>_25</code> and
   <code>_25_60</code>. Level of care: <code>residential</code> <code>inpatient</code> <code>detox</code>
   <code>php</code> <code>iop</code>. Clientele: <code>adolescent</code> <code>young_adult</code>
   <code>women</code> <code>men</code> <code>pregnant</code> <code>veterans</code> <code>seniors</code>
   <code>criminal_justice</code> <code>eating_disorder</code> <code>tbi</code>
   <code>first_episode_psychosis</code> <code>alzheimers</code>.
   <b>Axes overlap by design and do not sum.</b> There is no <code>lgbtq</code> column because no such code
   exists anywhere in either SAMHSA codebook.
   <br><b>Read the QA report before choosing axes:</b> all 12 clientele axes clear the &ge;10-within-60 density
   gate, so that gate does not discriminate. Six of them sit on 39&ndash;45% of every facility in the country and
   would produce pages nearly identical to their parent; the six selective ones are
   <code>pregnant</code> 25%, <code>adolescent</code> 16%, <code>eating_disorder</code> 13%, <code>tbi</code> 10%,
   <code>first_episode_psychosis</code> 8%, <code>alzheimers</code> 6%.
   <br><b>Showing the first 25 rows &mdash; Copy as TSV and Download give all 287.</b>', 25);

doc('x7qa', '7b. QA report for the density counts', 'qa_report.md',
  'Row counts at every stage, the exact service codes behind STRICT / BROAD / OTP, the city-matching tiers, and
   all four sanity checks with their results. <b>Sanity check 4 does not pass as literally worded</b> &mdash; read
   that section, the reasoning matters more than the number.');
?>

<section class="panel">
<h2>What I would do with this</h2>
<ol>
  <li><b>Do not build the carrier overlay on table 1 as it stands.</b> Half the signal is a chain's national insurer
      list stamped onto every branch. Either attribute per location (the page must name the listing) or restrict to
      <code>national_ex_chain</code>. Same rule that fixed the verification queue.</li>
  <li><b>Lead with state × level of care.</b> 100% fill, licensing-derived, genuinely distinct facets, 12 terms × 51
      states, and it owes nothing to the crawl. This is the tier that is actually ready.</li>
  <li><b>Fix the live-city list before writing content.</b> 262 of your 315 densest cities are dark while 48 live
      cities are thin. That is a publish decision worth more than any new data.</li>
  <li><b>Apply the two missing indexes</b> before serving 315 city pages with radius queries on a sequential scan.</li>
  <li><b>Drop the dedupe item.</b> It is measured and clean; the "3–4 records per facility" risk did not materialise.</li>
</ol>
<p class="note">Raw CSVs also on the box at <code>/root/dirnet-analysis-20260729/</code>.
Every table on this page is the full result set in the clipboard copy and the CSV download, even where the
on-screen table is truncated for length.</p>
</section>

</main>
<script>
function copyTsv(id) {
  var ta = document.getElementById('tsv-' + id);
  if (!ta) return;
  var btn = event.currentTarget;
  var done = function () {
    var old = btn.textContent; btn.textContent = 'Copied ✓';
    setTimeout(function () { btn.textContent = old; }, 1400);
  };
  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(ta.value).then(done, function () { legacy(); });
  } else { legacy(); }
  function legacy() {
    ta.style.position = 'static'; ta.style.width = '1px'; ta.style.height = '1px';
    ta.select(); try { document.execCommand('copy'); done(); } catch (e) { alert('Select the table and copy manually.'); }
    ta.style.position = 'absolute'; ta.style.left = '-9999px';
  }
}
</script>
</body>
</html>
