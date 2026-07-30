<?php
/**
 * Directory Network — answers to four questions asked 2026-07-30.
 *
 * Read-only. Every number here was measured against the production Supabase
 * database for the `recovery` niche, or read out of the directory-network repo,
 * on 2026-07-30. Nothing on this page writes anything.
 *
 * Each answer is authored ONCE as markdown and rendered from that source, so the
 * "Copy answer" button hands you exactly what is on the screen — no second copy
 * to drift out of step.
 *
 * Auth: same session gate as the Test Lab (playground.php).
 */
require_once __DIR__ . '/../config.php';
if (empty($_SESSION['admin_logged_in'])) { header('Location: login.php'); exit; }

/** Inline markdown: **bold**, `code`. Escapes first. */
function inl(string $t): string {
    $t = htmlspecialchars($t, ENT_QUOTES);
    $t = preg_replace('/\*\*(.+?)\*\*/s', '<b>$1</b>', $t);
    $t = preg_replace('/`([^`]+)`/', '<code>$1</code>', $t);
    return $t;
}

/** Minimal markdown → HTML: headings, paragraphs, ul/ol, pipe tables. */
function md(string $src): string {
    $html = ''; $mode = null; $buf = [];

    $flush = function () use (&$html, &$mode, &$buf) {
        if (!$buf) { $mode = null; return; }
        if ($mode === 'p')  $html .= '<p>' . inl(implode(' ', $buf)) . '</p>';
        if ($mode === 'ul') { $html .= '<ul>'; foreach ($buf as $b) $html .= '<li>' . inl($b) . '</li>'; $html .= '</ul>'; }
        if ($mode === 'ol') { $html .= '<ol>'; foreach ($buf as $b) $html .= '<li>' . inl($b) . '</li>'; $html .= '</ol>'; }
        if ($mode === 'table') {
            $rows = [];
            foreach ($buf as $r) { if (!preg_match('/^[\s|:\-]+$/', $r)) $rows[] = $r; }
            $html .= '<div class="scroll"><table>';
            foreach ($rows as $i => $r) {
                $cells = array_map('trim', explode('|', trim($r, "| \t")));
                $tag = $i === 0 ? 'th' : 'td';
                $html .= '<tr>';
                foreach ($cells as $j => $c) {
                    $cls = '';
                    if ($tag === 'td') {
                        if ($j === 0) $cls = ' class="k"';
                        elseif (is_numeric(str_replace([',', '%', '~'], '', $c))) $cls = ' class="n"';
                    }
                    $html .= "<$tag$cls>" . inl($c) . "</$tag>";
                }
                $html .= '</tr>';
            }
            $html .= '</table></div>';
        }
        $buf = []; $mode = null;
    };

    foreach (preg_split('/\n/', $src) as $ln) {
        $t = rtrim($ln);
        if ($t === '') { $flush(); continue; }
        if (preg_match('/^### (.+)$/', $t, $m)) { $flush(); $html .= '<h3>' . inl($m[1]) . '</h3>'; continue; }
        if (preg_match('/^## (.+)$/', $t, $m))  { $flush(); $html .= '<h2>' . inl($m[1]) . '</h2>'; continue; }
        if (str_starts_with($t, '|'))           { if ($mode !== 'table') { $flush(); $mode = 'table'; } $buf[] = $t; continue; }
        if (preg_match('/^[-*] (.+)$/', $t, $m)){ if ($mode !== 'ul') { $flush(); $mode = 'ul'; } $buf[] = $m[1]; continue; }
        if (preg_match('/^\d+\. (.+)$/', $t, $m)){ if ($mode !== 'ol') { $flush(); $mode = 'ol'; } $buf[] = $m[1]; continue; }
        if ($mode !== 'p') { $flush(); $mode = 'p'; }
        $buf[] = trim($t);
    }
    $flush();
    return $html;
}

$ANSWERS = [];

/** Register one Q&A block. */
function answer(string $id, string $q, string $verdict, string $vclass, string $body, string $extra = ''): void {
    global $ANSWERS;
    $ANSWERS[] = compact('id', 'q', 'verdict', 'vclass', 'body', 'extra');
}

// ---------------------------------------------------------------- Q1
answer('q1',
  'Of the 17,827 facilities, how many did you actually manage to find insurance info for by crawling their websites?',
  'About 1,300 usable — roughly 7%. Not the 5,450 the raw count suggests.',
  'bad',
<<<'MD'
The headline number looks better than the real one, so here is the whole ladder.

| Rung | Listings | % of 17,827 active |
| Any insurance attribute, any source | 11,362 | 63.7% |
| ...from the website crawl (`facility-site`) | 5,450 | 30.6% |
| ...crawl, **named carriers only** (excl. medicaid/medicare/tricare) | 2,445 | 13.7% |
| ......of those, on a domain serving **1 listing** (clean) | 665 | 3.7% |
| ......on a domain serving 2-4 listings | 655 | 3.7% |
| ......on a domain serving **5+ listings** (chain risk) | 1,125 | 6.3% |

So: the crawl produced **location-attributable named-carrier data for roughly 1,300 facilities — about 7%** of the directory. The 2,445 figure is the optimistic reading, and **46% of it sits on chain domains**, which is exactly the contamination the cross-tabs found: 19 of the 20 Michigan CareFirst listings are one company, LifeStance Health, whose site lists every insurer it accepts anywhere.

The 63.7% "insurance coverage" figure that appears elsewhere in the docs is mostly SAMHSA payment-type tags aliased into the insurance facet — medicaid, medicare, tricare. Those are programmes, not carriers.

Named carriers were what recovery.com supplied, and that source is gone. **This is why the 1,040 city x carrier cutover URLs are still blocked:** we hold carrier data for about 7% of facilities, and the live site's entire indexed inventory is built on it.

The chain share is remarkably even across carriers — 45-57% for every one of the top eight — which is itself evidence that it is a systematic collection defect and not a property of any particular insurer.
MD,
  'csv:07-insurance-crawl-yield.csv|07b-crawled-carrier-by-name.csv'
);

// ---------------------------------------------------------------- Q2
answer('q2',
  'Is Search Console set up, and how long has it been collecting?',
  'No. It has collected nothing — zero days.',
  'bad',
<<<'MD'
Not set up. No property, no API integration, and **no `google-site-verification` tag anywhere in the codebase** (checked). Zero days of data.

This was a deliberate route-around rather than an oversight. Locked cutover decision #4 was *"no Search Console access, therefore crawl"*, so the redirect audit was built from crawling recoverydawn.com instead. The limitation was stated at the time and still stands:

- **A crawl only finds what is linked.** Orphaned-but-indexed pages are invisible to it — and those are precisely the ones that 404 silently after cutover.
- The live sitemap was useless for this: 16 URLs, and the browse pages are not in it.
- So the 1,355-URL inventory I am working from is a floor, not a census.

**The one thing worth acting on:** recoverydawn.com is your live site. If you already hold a Search Console property on it, that data is the single highest-value input this project does not have. It would settle the 1,040-URL question with real impressions and clicks instead of my crawl inventory — I would know which of those pages actually earn traffic and which are dead weight that can be 301'd without further thought. Right now I am inferring that.

Submitting our own sitemap in Search Console is already on the build list (item 5) and has not been done.
MD
);

// ---------------------------------------------------------------- Q3
answer('q3',
  'How many pages can you realistically build and check each month?',
  '1,000-2,000 generated and machine-checked. Around 150 genuinely read.',
  'warn',
<<<'MD'
Two very different rates, and conflating them would mislead you.

### Generation and automated checks — not the constraint

About **$0.02-0.03 per page** on Sonnet at low effort; 426 pages ran in roughly 40 minutes. The automated gates are real and do run:

- `pageViability` — counts listings using the **same query the page itself runs**, so a count cannot disagree with its page
- `audit:pages` — finds published pages with empty grids
- a shortcode-leak grep, which catches template tokens rendering literally
- `check:niche` — 11 structural checks per directory

Comfortably **1,000-2,000 pages a month for tens of dollars**, and it could be more if that were the limit.

### Editorial checking — this is the real ceiling

There is **no per-page review step in this project.** No reviewer, no sampling protocol, no sign-off. The realistic rate at which pages get genuinely read is maybe **150 a month**, done by me inside working sessions.

My track record on that is worth knowing before you rely on it: in one session most of the "defects" I reported turned out to be **bad measurements, not bugs**, and I have been confidently wrong from too-small samples on five separate occasions — each caught by you, not by a process.

### What that means against the actual backlog

| Page type | Published | Draft |
| carrier | 78 | 2,992 |
| carrier_state | 8 | 986 |
| category | 83 | 1,249 |
| city | 40 | 61 |
| state | 0 | 33 |
| carrier_national | 3 | 28 |
| **Total** | **217** | **5,349** |

At the machine rate that backlog is 3-5 months. **I would not publish it at that rate with the insurance data in its current state** — a carrier page whose grid was assembled from chain-site noise is a page that asserts something we cannot support.
MD
);

// ---------------------------------------------------------------- Q4
answer('q4',
  'Are you paying anyone with clinical credentials to review the content?',
  'No. $0. Not one page has been clinically reviewed.',
  'bad',
<<<'MD'
Nobody with clinical credentials has reviewed any content on this site. Not one page, and no budget is allocated to it.

Published content is AI-generated from SAMHSA licensing data and crawled facility websites. The only medical safeguards in place are:

- a disclaimer adapted from recoveryexcellence.com (its `/disclaimer/` was the one genuinely good document there — clean medical and no-endorsement clauses)
- crisis resources in `SiteConfig`: 988, 911, and the SAMHSA helpline 1-800-662-4357

Stating the exposure plainly rather than dressing it up:

- **Addiction treatment is the sharp end of YMYL.** Google weights demonstrated expertise heavily for health topics, so the absence is a ranking factor as well as a liability question.
- **The 843 listings marked `verified` are verified in the tier sense, not clinically vetted.** A visitor will not make that distinction on their own, and the badge wording is ours to choose.
- There are **zero facility descriptions** in the database, so every word of prose on a treatment page is generated rather than sourced from the provider.

How to handle it is entirely your call. I would rather you make it with the number in front of you than discover it during cutover week.
MD
);
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Directory Network — four answers (2026-07-30)</title>
<style>
  :root { --ink:#0f172a; --mut:#64748b; --line:#e2e8f0; --bg:#f8fafc; --acc:#fd783b; --warn:#b45309; --bad:#b91c1c; --ok:#047857; }
  * { box-sizing:border-box; }
  body { margin:0; font:14px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif; color:var(--ink); background:var(--bg); }
  header.top { background:#0f172a; color:#fff; padding:14px 22px; display:flex; align-items:center; gap:14px; flex-wrap:wrap; }
  header.top .logo { font-weight:700; font-size:17px; }
  header.top .logo small { color:var(--acc); font-weight:600; }
  header.top .meta { color:#94a3b8; font-size:12px; margin-left:auto; }
  header.top a { color:#cbd5e1; font-size:13px; text-decoration:none; border:1px solid #334155; padding:4px 10px; border-radius:6px; }
  main { max-width:1000px; margin:0 auto; padding:22px; }
  h2 { font-size:15px; margin:18px 0 4px; }
  h3 { font-size:13.5px; margin:18px 0 6px; color:#334155; text-transform:uppercase; letter-spacing:.04em; }
  p { margin:9px 0; }
  .panel { background:#fff; border:1px solid var(--line); border-radius:10px; padding:18px 20px; margin:0 0 20px; }
  .q { font-size:16px; font-weight:700; line-height:1.4; margin:0; padding-right:110px; }
  .qnum { color:var(--acc); font-weight:800; margin-right:7px; }
  .verdict { font-size:14px; font-weight:700; padding:10px 14px; border-radius:8px; margin:12px 0 4px; border-left:3px solid; }
  .verdict.bad { background:#fef2f2; border-color:var(--bad); color:#7f1d1d; }
  .verdict.warn { background:#fffbeb; border-color:var(--warn); color:#78350f; }
  .verdict.ok { background:#ecfdf5; border-color:var(--ok); color:#065f46; }
  .head { position:relative; }
  .bar { display:flex; align-items:center; gap:8px; margin:14px 0 0; flex-wrap:wrap; border-top:1px solid var(--line); padding-top:12px; }
  .btn { background:var(--acc); color:#fff; border:0; border-radius:6px; padding:6px 12px; font-size:13px; font-weight:600; cursor:pointer; text-decoration:none; display:inline-block; }
  .btn.alt { background:#475569; }
  .btn.big { padding:9px 18px; font-size:14px; }
  .btn:active { transform:translateY(1px); }
  code { background:#f1f5f9; padding:1px 5px; border-radius:4px; font-size:12.5px; }
  .scroll { overflow:auto; margin:12px 0; border:1px solid var(--line); border-radius:8px; }
  table { border-collapse:collapse; width:100%; font-size:12.5px; }
  th, td { padding:6px 10px; border-bottom:1px solid var(--line); text-align:left; }
  th { background:#f1f5f9; font-size:11px; text-transform:uppercase; letter-spacing:.03em; color:#475569; }
  td.n { text-align:right; font-variant-numeric:tabular-nums; white-space:nowrap; }
  td.k { font-weight:600; }
  tbody tr:hover td, tr:hover td { background:#fff7ed; }
  ul, ol { margin:9px 0; padding-left:22px; }
  li { margin:4px 0; }
  .hidden-tsv { position:absolute; left:-9999px; width:1px; height:1px; }
  nav.toc { background:#fff; border:1px solid var(--line); border-radius:10px; padding:14px 18px; margin-bottom:20px; }
  nav.toc a { color:#0f172a; text-decoration:none; font-size:13px; display:block; margin:5px 0; }
  nav.toc a:hover { color:var(--acc); text-decoration:underline; }
  nav.toc .v { color:var(--mut); }
  .note { color:var(--mut); font-size:12.5px; }
</style>
</head>
<body>
<header class="top">
  <div class="logo">Directory Network <small>four answers</small></div>
  <a href="dirnet-data.php">&larr; Data cross-tabs</a>
  <a href="dirnet-sheets.php">Spreadsheets</a>
  <a href="playground.php">Test Lab</a>
  <div class="meta">asked &amp; measured 2026-07-30 &middot; recovery niche &middot; production Supabase</div>
</header>
<main>

<nav class="toc">
<?php foreach ($ANSWERS as $i => $a): ?>
  <a href="#<?= $a['id'] ?>"><b><?= $i + 1 ?>.</b> <?= htmlspecialchars($a['q']) ?><br><span class="v"><?= htmlspecialchars($a['verdict']) ?></span></a>
<?php endforeach; ?>
  <div class="bar" style="border-top:1px solid var(--line);">
    <button type="button" class="btn big" onclick="copyId('all')">Copy all four answers</button>
    <span class="note">as markdown, ready to paste</span>
  </div>
</nav>

<?php
$allMd = "# Directory Network — four answers (measured 2026-07-30)\n\n";
foreach ($ANSWERS as $i => $a):
    $n = $i + 1;
    $raw = "## Q{$n}. {$a['q']}\n\n**{$a['verdict']}**\n\n" . trim($a['body']) . "\n\n";
    $allMd .= $raw;
?>
<section class="panel" id="<?= $a['id'] ?>">
  <div class="head">
    <p class="q"><span class="qnum"><?= $n ?>.</span><?= htmlspecialchars($a['q']) ?></p>
  </div>
  <div class="verdict <?= $a['vclass'] ?>"><?= htmlspecialchars($a['verdict']) ?></div>
  <?= md($a['body']) ?>
  <div class="bar">
    <button type="button" class="btn" onclick="copyId('<?= $a['id'] ?>')">Copy answer</button>
<?php
    if (str_starts_with($a['extra'], 'csv:')) {
        foreach (explode('|', substr($a['extra'], 4)) as $f) {
            $ok = is_readable(__DIR__ . '/_dirnet-data/' . $f);
            echo '<a class="btn alt" href="_dirnet-data/' . rawurlencode($f) . '" download>'
               . ($ok ? 'Download ' : 'MISSING: ') . htmlspecialchars($f) . '</a> ';
        }
    }
?>
  </div>
  <textarea id="tsv-<?= $a['id'] ?>" class="hidden-tsv"><?= htmlspecialchars($raw) ?></textarea>
</section>
<?php endforeach; ?>

<section class="panel">
<h2>Where these numbers came from</h2>
<ul>
  <li><b>Q1</b> — live queries against the production Supabase <code>ListingAttribute</code> table, grouped by
      <code>source</code> and by how many listings share the website's hostname. Both CSVs on this page are the full
      result sets. The 5+-listings-per-domain threshold is the same one used in
      <code>01c-carrier-chain-adjusted.csv</code> on the cross-tabs page, so the two agree by construction.</li>
  <li><b>Q2</b> — a repo-wide grep for <code>google-site-verification</code>, Search Console references and API
      integrations across <code>.ts</code>, <code>.tsx</code>, <code>.json</code> and the handbook. Only two hits, both
      prose: a comment in <code>scripts/crawl-live-site.ts</code> explaining why the crawl exists, and build-list item 5
      saying to submit the sitemap.</li>
  <li><b>Q3</b> — measured generation cost and runtime from prior real runs; the draft/published table is a live
      <code>ContentPage</code> count by <code>pageType</code> and <code>status</code>.</li>
  <li><b>Q4</b> — the absence of a thing, so there is no query to show. Verified there is no reviewer role, review
      queue, or sign-off field in the schema; the disclaimer and crisis-resource wording is in <code>SiteConfig</code>.</li>
</ul>
<p class="note">Read-only page. Companion to <a href="dirnet-data.php">the data cross-tabs</a>.
Raw CSVs also on the box at <code>/root/dirnet-analysis-20260729/</code> and
<code>/var/www/homepage-builder-new/admin/_dirnet-data/</code>.</p>
</section>

<textarea id="tsv-all" class="hidden-tsv"><?= htmlspecialchars($allMd) ?></textarea>

</main>
<script>
function copyId(id) {
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
    ta.select(); try { document.execCommand('copy'); done(); } catch (e) { alert('Select the text and copy manually.'); }
    ta.style.position = 'absolute'; ta.style.left = '-9999px';
  }
}
</script>
</body>
</html>
