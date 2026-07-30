<?php
/**
 * Directory Network — the two spreadsheets, and an honest account of their provenance.
 *
 * Read-only. The CSVs are produced by `npm run export:sheets` in the directory-network repo
 * (scripts/export-owner-sheets.ts), which writes straight into ./_dirnet-data. Re-run that to refresh.
 *
 * Auth: same session gate as the Test Lab (playground.php).
 */
require_once __DIR__ . '/../config.php';
if (empty($_SESSION['admin_logged_in'])) { header('Location: login.php'); exit; }

$DIR = __DIR__ . '/_dirnet-data';

function fsize(string $f): string {
    $p = __DIR__ . '/_dirnet-data/' . $f;
    if (!is_readable($p)) return 'missing';
    $b = filesize($p);
    return $b > 1048576 ? round($b / 1048576, 1) . ' MB' : round($b / 1024) . ' KB';
}

/** Download button, or a visible failure if the export has not been run. */
function dlbtn(string $f, string $label): string {
    $ok = is_readable(__DIR__ . '/_dirnet-data/' . $f);
    if (!$ok) return '<span class="missing">MISSING: ' . htmlspecialchars($f) . ' — run <code>npm run export:sheets</code></span> ';
    return '<a class="btn" href="_dirnet-data/' . rawurlencode($f) . '" download>' . htmlspecialchars($label)
         . ' <small>' . fsize($f) . '</small></a> ';
}

function csv_load(string $path): array {
    if (!is_readable($path)) return [[], []];
    $rows = []; $fh = fopen($path, 'r');
    while (($r = fgetcsv($fh)) !== false) $rows[] = $r;
    fclose($fh);
    $head = array_shift($rows) ?: [];
    return [$head, $rows];
}

[$lgHead, $lgRows] = csv_load("$DIR/sheet3-legend.csv");

// Column dictionaries, authored here so the page explains the file without opening it.
$SHEET1 = [
  ['facility_id', 'Our internal id. Present so sheet 2 joins back to this sheet — you did not ask for it, but without it the second file is unusable.'],
  ['name', 'Facility name as we hold it. 100% filled.'],
  ['address', 'Street address. 100% filled, 17,586 of which are usable street addresses rather than PO boxes.'],
  ['city', '100% filled.'],
  ['state', 'Full state name, not the abbreviation. 100% filled.'],
  ['zip', '99.9% filled — 13 blanks.'],
  ['phone', '99.4% filled — 102 blanks. This is the ONE number we publish; the database holds every number found on a site separately.'],
  ['website', '87.2% filled — 2,283 facilities have no website we know of. Included because it is what you would click to check an insurance claim in sheet 2.'],
];

$SHEET2 = [
  ['facility_id, facility_name, state, city', 'Joins to sheet 1. Name and place repeated so the file is readable on its own.'],
  ['fact_type', 'One of: insurance, levels_of_care, conditions, substances.'],
  ['value / label', 'Our taxonomy slug, and its human-readable label.'],
  ['says', '<b>yes</b> = the source asserts it. <b>no</b> = the source explicitly DENIES it (387 insurance rows). A denial is kept rather than dropped, because "we do not take Medicaid" is stronger information than silence.'],
  ['how_code', 'Short provenance code — pivot on this. Full meanings in the legend below and in sheet 3.'],
  ['how_we_learned', 'The same thing in a few words, so the file reads without the legend.'],
  ['inferred_not_stated', '<b>yes</b> means we worked it out rather than read it. 439 rows, all one rule (see below).'],
  ['evidence_phrase', 'The text the fact was read from. For levels of care, conditions and substances this is usually a real sentence. <b>For insurance it is only the matched carrier name</b> — see the limitation below.'],
  ['source', 'Raw source identifier, kept so a claim can always name its origin.'],
  ['source_url', 'Where it came from.'],
  ['url_points_to', '<b>site root</b> or <b>exact page</b>. Every crawl-sourced insurance row says "site root" — that is the limitation below, made visible per row rather than buried.'],
  ['date_observed', 'The date we read it. Crawl rows are 2026-07-28/29; SAMHSA 2026-07-26.'],
  ['domain_listings', 'How many of our facilities share that website.'],
  ['chain_risk', '<b>yes</b> when a crawl-sourced fact came from a site serving 5+ facilities. <b>This is the column to filter on.</b>'],
];
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Directory Network — two spreadsheets</title>
<style>
  :root { --ink:#0f172a; --mut:#64748b; --line:#e2e8f0; --bg:#f8fafc; --acc:#fd783b; --warn:#b45309; --bad:#b91c1c; --ok:#047857; }
  * { box-sizing:border-box; }
  body { margin:0; font:14px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif; color:var(--ink); background:var(--bg); }
  header.top { background:#0f172a; color:#fff; padding:14px 22px; display:flex; align-items:center; gap:14px; flex-wrap:wrap; }
  header.top .logo { font-weight:700; font-size:17px; }
  header.top .logo small { color:var(--acc); font-weight:600; }
  header.top .meta { color:#94a3b8; font-size:12px; margin-left:auto; }
  header.top a { color:#cbd5e1; font-size:13px; text-decoration:none; border:1px solid #334155; padding:4px 10px; border-radius:6px; }
  main { max-width:1100px; margin:0 auto; padding:22px; }
  h2 { font-size:16px; margin:0 0 6px; }
  h3 { font-size:13px; margin:20px 0 6px; color:#334155; text-transform:uppercase; letter-spacing:.04em; }
  p { margin:9px 0; }
  .panel { background:#fff; border:1px solid var(--line); border-radius:10px; padding:18px 20px; margin:0 0 20px; }
  .btn { background:var(--acc); color:#fff; border:0; border-radius:6px; padding:8px 14px; font-size:13.5px; font-weight:600; cursor:pointer; text-decoration:none; display:inline-block; margin:0 4px 6px 0; }
  .btn small { font-weight:400; opacity:.85; }
  .btn.alt { background:#475569; }
  .btn:active { transform:translateY(1px); }
  .missing { color:var(--bad); font-weight:600; font-size:13px; }
  code { background:#f1f5f9; padding:1px 5px; border-radius:4px; font-size:12.5px; }
  .scroll { overflow:auto; max-height:560px; margin:12px 0; border:1px solid var(--line); border-radius:8px; }
  table { border-collapse:collapse; width:100%; font-size:12.5px; }
  th, td { padding:6px 10px; border-bottom:1px solid var(--line); text-align:left; vertical-align:top; }
  th { position:sticky; top:0; background:#f1f5f9; font-size:11px; text-transform:uppercase; letter-spacing:.03em; color:#475569; z-index:1; }
  td.n { text-align:right; font-variant-numeric:tabular-nums; white-space:nowrap; }
  td.k { font-weight:600; white-space:nowrap; }
  tr:hover td { background:#fff7ed; }
  ul, ol { margin:9px 0; padding-left:22px; }
  li { margin:5px 0; }
  .call { border-left:3px solid var(--acc); background:#fff7ed; padding:12px 15px; border-radius:0 8px 8px 0; margin:14px 0; }
  .call.bad { border-color:var(--bad); background:#fef2f2; }
  .call.ok { border-color:var(--ok); background:#ecfdf5; }
  .call.warn { border-color:var(--warn); background:#fffbeb; }
  .call b.h { display:block; margin-bottom:5px; }
  .kpi { display:flex; gap:10px; flex-wrap:wrap; margin:12px 0; }
  .kpi div { background:#f1f5f9; border-radius:8px; padding:9px 15px; min-width:118px; }
  .kpi b { display:block; font-size:19px; }
  .kpi span { color:var(--mut); font-size:11.5px; }
  .note { color:var(--mut); font-size:12.5px; }
  nav.toc { background:#fff; border:1px solid var(--line); border-radius:10px; padding:12px 18px; margin-bottom:20px; }
  nav.toc a { color:#0f172a; text-decoration:none; font-size:13px; display:inline-block; margin:4px 16px 4px 0; }
  nav.toc a:hover { color:var(--acc); text-decoration:underline; }
</style>
</head>
<body>
<header class="top">
  <div class="logo">Directory Network <small>two spreadsheets</small></div>
  <a href="dirnet-answers.php">&larr; Four answers</a>
  <a href="dirnet-data.php">Data cross-tabs</a>
  <a href="playground.php">Test Lab</a>
  <div class="meta">exported 2026-07-30 &middot; recovery niche &middot; production Supabase</div>
</header>
<main>

<nav class="toc">
  <a href="#get">Download</a>
  <a href="#prov">How each insurance fact was learned</a>
  <a href="#legend">Legend</a>
  <a href="#dict">Column dictionary</a>
  <a href="#use">How I would read it</a>
</nav>

<section class="panel" id="get">
<h2>The two files</h2>
<div class="kpi">
  <div><b>17,827</b><span>rows in sheet 1</span></div>
  <div><b>372,586</b><span>rows in sheet 2</span></div>
  <div><b>36,689</b><span>insurance facts</span></div>
  <div><b>439</b><span>inferred, not stated</span></div>
  <div><b>0</b><span>from a logo</span></div>
</div>

<h3>Sheet 1 — one row per facility</h3>
<p>Name, address, city, state, zip, phone — plus <code>facility_id</code> so sheet 2 joins, and
<code>website</code> because it is what you would click to check a claim. Nothing else added.</p>
<p><?= dlbtn('sheet1-facilities.csv', 'sheet1-facilities.csv') ?><?= dlbtn('sheet1-facilities.csv.gz', 'gzipped') ?></p>

<h3>Sheet 2 — one row per fact per facility</h3>
<p>Insurance, levels of care, and what it treats (conditions and substances), each with how we learned it,
the URL and the date.</p>
<p><?= dlbtn('sheet2-facts.csv.gz', 'sheet2-facts.csv.gz — start here') ?><?= dlbtn('sheet2-facts.csv', 'uncompressed') ?><?= dlbtn('sheet3-legend.csv', 'sheet3-legend.csv') ?></p>
<div class="call warn">
<b class="h">Take the gzip.</b> The raw CSV is 142 MB. It is inside Excel's row limit (372,586 of 1,048,576)
and inside Google Sheets' cell limit (6.7M of 10M), but both will labour. If you only care about insurance,
filter <code>fact_type = insurance</code> and you are down to 36,689 rows.
</div>
</section>

<section class="panel" id="prov">
<h2>You asked how I learned each insurance fact. Three parts of that I can answer, one I cannot.</h2>

<div class="call ok">
<b class="h">"Or just show a logo?" &mdash; never. Zero rows in this dataset came from a logo.</b>
Carrier matching runs only over the facility's own body prose. Before matching, the extractor strips
<code>&lt;nav&gt;</code>, <code>&lt;header&gt;</code>, <code>&lt;footer&gt;</code>, <code>&lt;aside&gt;</code>, forms,
and <b>the text of every link</b> — and images are never matched for carriers at all. A logo carousel in a
footer contributes nothing.
<br><br>
This was not a lucky accident; it was built after measurement. Matching the whole page made
<code>luxury</code> appear on 847 of 847 calibration pages and produced <b>~18 spurious carriers per page</b>,
every one from navigation, a filter sidebar or a footer. The rule that came out of it: <b>a claim is prose,
not a link.</b>
</div>

<div class="call bad">
<b class="h">"Did the website say 'in-network with Humana'?" &mdash; I can prove the carrier's NAME was in the
facility's own body copy. I cannot show you the sentence, because we did not keep it.</b>
<code>evidence_phrase</code> holds the matched carrier name only — <code>aetna</code>,
<code>amerihealth caritas</code> — not the prose around it. So for the 23,485 rows coded
<code>site_states</code> I can tell you the facility wrote that carrier's name in its own copy, and I cannot
distinguish "in-network with Humana" from "we can help you understand your Humana benefits".
<br><br>
The one thing that <i>was</i> evaluated at extraction time and is preserved: <b>negation</b>. 387 rows carry
<code>says = no</code>, meaning the site was read as denying that carrier.
<br><br>
For the other three fact types this limitation mostly does not apply — <code>evidence_phrase</code> on
levels of care, conditions and substances usually holds a real sentence, e.g.
<i>"Prevent, identify, treat and manage depression, dementia and delirium"</i>.
</div>

<div class="call bad">
<b class="h">"And the web address you got it from" &mdash; it is the site's front door, not the page.</b>
Carrier matching runs over the JOIN of every page crawled for a domain, and every resulting row was written
with the domain root as its URL. Verified: <b>24,313 of 24,313</b> crawl-sourced insurance rows point at a bare
root. The <code>url_points_to</code> column says <code>site root</code> on every one of them rather than
hiding it.
<br><br>
The annoying part: the crawler <i>does</i> keep per-page text, with a comment explaining that a fact should be
attributable to the page it was found on so a reviewer can open it. That was wired up for phone numbers and
never for carriers. It is a fixable defect, not a fundamental limit — a re-run over the existing cache could
attribute each carrier to its page without a single new request.
</div>

<div class="call warn">
<b class="h">"Or did you infer it?" &mdash; yes, 439 times, all from one rule, and every one is flagged.</b>
Filter <code>inferred_not_stated = yes</code>. Blue Cross Blue Shield is not a carrier; it is an association of
~33 independent licensees trading under their own names. When a site named a licensee, we also recorded
"Blue Cross Blue Shield" so that someone searching that phrase finds the facility. That is true about the
world, but it is not something the page said.
<br><br>
Anthem 327 &middot; Highmark 84 &middot; CareFirst 20 &middot; GuideWell (Florida Blue) 8. The licensee that triggered
each row is in <code>evidence_phrase</code>.
</div>

<div class="call">
<b class="h">The thing you did not ask about, which matters more than any of the above.</b>
<code>chain_risk = yes</code> marks a crawl-sourced fact that came from a website serving <b>5 or more</b> of our
facilities — a chain's national insurer list harvested onto every branch. 19 of the 20 Michigan CareFirst
listings are one company, LifeStance Health. Across crawled carrier rows this is <b>52.7%</b> of the volume.
A <code>chain_risk = yes</code> row is evidence about a <i>company</i>, not about that <i>building</i>.
Also worth knowing: the strongest insurance evidence in the file is the 1,835 rows coded
<code>insurer_network_*</code>, because those come from the insurer's own published network file — the party
doing the paying — rather than from the facility's marketing copy.
</div>
</section>

<section class="panel" id="legend">
<h2>Legend &mdash; every <code>how_code</code> in sheet 2</h2>
<p class="note">Also shipped as sheet 3 so the workbook explains itself. The count column is insurance rows only.</p>
<?php if (!$lgHead): ?>
  <p class="missing">sheet3-legend.csv missing — run <code>npm run export:sheets</code>.</p>
<?php else: ?>
<div class="scroll"><table>
<thead><tr><?php foreach ($lgHead as $h) echo '<th>' . htmlspecialchars(str_replace('_', ' ', $h)) . '</th>'; ?></tr></thead>
<tbody>
<?php foreach ($lgRows as $r): ?>
  <tr>
    <td class="k"><code><?= htmlspecialchars($r[0] ?? '') ?></code></td>
    <td><?= htmlspecialchars($r[1] ?? '') ?></td>
    <td><?= htmlspecialchars($r[2] ?? '') ?></td>
    <td class="n"><?= htmlspecialchars(number_format((int)($r[3] ?? 0))) ?></td>
  </tr>
<?php endforeach; ?>
</tbody></table></div>
<?php endif; ?>
</section>

<section class="panel" id="dict">
<h2>Column dictionary</h2>
<h3>Sheet 1 — facilities</h3>
<div class="scroll" style="max-height:none"><table><tbody>
<?php foreach ($SHEET1 as [$c, $d]): ?>
  <tr><td class="k"><code><?= htmlspecialchars($c) ?></code></td><td><?= $d ?></td></tr>
<?php endforeach; ?>
</tbody></table></div>

<h3>Sheet 2 — facts</h3>
<div class="scroll" style="max-height:none"><table><tbody>
<?php foreach ($SHEET2 as [$c, $d]): ?>
  <tr><td class="k"><code><?= htmlspecialchars($c) ?></code></td><td><?= $d ?></td></tr>
<?php endforeach; ?>
</tbody></table></div>

<h3>What is in sheet 2, by fact type</h3>
<div class="scroll" style="max-height:none"><table>
<thead><tr><th>fact type</th><th class="n">rows</th><th>where it comes from, and how much to trust it</th></tr></thead>
<tbody>
<tr><td class="k">levels_of_care</td><td class="n">179,434</td><td>Overwhelmingly SAMHSA licensing. <b>The strongest tier in the dataset</b> — 100% fill, regulator-collected, and it owes nothing to the crawl.</td></tr>
<tr><td class="k">conditions</td><td class="n">87,891</td><td>Mixed licensing and crawl. 94.3% fill.</td></tr>
<tr><td class="k">substances</td><td class="n">68,572</td><td>Mixed licensing and crawl. 86.0% fill.</td></tr>
<tr><td class="k">insurance</td><td class="n">36,689</td><td><b>The weakest tier, and the one you asked about.</b> 23,485 from facility websites, 8,333 SAMHSA payment types (never a named carrier), 2,210 imported from recoveryexcellence.com, 1,835 from insurers' own network files, 439 inferred, 387 site denials.</td></tr>
</tbody></table></div>
</section>

<section class="panel" id="use">
<h2>How I would read it</h2>
<ol>
  <li><b>For anything you intend to publish about insurance, filter out <code>chain_risk = yes</code> first.</b>
      It removes about half the crawled carrier volume and it is the half that describes a company rather than
      a building.</li>
  <li><b>Trust the <code>insurer_network_*</code> rows most</b> (1,835). The insurer named the facility. That is
      a different quality of evidence from a facility naming the insurer.</li>
  <li><b>Treat <code>samhsa_licensing</code> insurance rows as payment types, not carriers.</b> All 8,333 are
      Medicaid / Medicare / TRICARE / private-insurance. They are reliable and they cannot support a
      city&times;carrier page.</li>
  <li><b>Lead with <code>levels_of_care</code> if you want to build pages from this.</b> It is the only tier with
      100% fill and regulator provenance.</li>
  <li><b>The 387 <code>says = no</code> rows are worth more than their count suggests</b> — an explicit denial is
      the only place this dataset can prove a negative.</li>
</ol>
<p class="note">Regenerate both files with <code>npm run export:sheets</code> in the directory-network repo
(<code>scripts/export-owner-sheets.ts</code>, read-only, writes nothing to the database). It re-derives
<code>inferred_not_stated</code> from <code>lib/sources/carrier-aliases.ts</code> rather than from a copy, so the
flag cannot drift from the extractor's own rules.</p>
</section>

</main>
</body>
</html>
