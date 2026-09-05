<?php
/**
 * City Chart — demo page.
 *
 * Shows the engine drawing the same chart definition for different cities, so the claim can be
 * checked rather than taken on trust: different figures, different picture, and the definition
 * itself is a JSON file inside the plugin rather than anything in code.
 *
 * THE NUMBERS BELOW ARE ILLUSTRATIVE. No city has researched figures yet, and the whole point
 * of the engine is that it refuses to draw without them — so a demo needs stand-in data, and
 * it is labelled as such on every card. Nothing here is written to any site.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../plugins/image-data-chart/charts.php';
require_once __DIR__ . '/../plugins/image-data-chart/draw.php';

if (empty($_SESSION['admin_logged_in'])) { header('Location: login.php'); exit; }
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES);

// Stand-in series. Deliberately different shapes so the "every city differs" claim is visible.
$demo = [
    ['city' => 'Lufkin', 'SS' => 'TX', 'niche' => 'water-restoration', 'chart' => 'rainfall',
     'rainfall_monthly' => [4.6, 4.2, 4.1, 3.8, 5.2, 5.0, 3.1, 2.7, 4.0, 5.1, 4.7, 5.0],
     'rainfall_source'  => 'ILLUSTRATIVE — not researched'],
    ['city' => 'Surprise', 'SS' => 'AZ', 'niche' => 'water-restoration', 'chart' => 'rainfall',
     'rainfall_monthly' => [0.9, 0.9, 0.8, 0.3, 0.1, 0.0, 1.0, 1.1, 0.7, 0.6, 0.7, 1.0],
     'rainfall_source'  => 'ILLUSTRATIVE — not researched'],
    ['city' => 'Lancaster', 'SS' => 'CA', 'niche' => 'water-restoration', 'chart' => 'freeze_days',
     'freeze_days_monthly' => [17, 12, 7, 2, 0, 0, 0, 0, 0, 1, 7, 15],
     'freeze_source'       => 'ILLUSTRATIVE — not researched'],
    ['city' => 'Littleton', 'SS' => 'CO', 'niche' => 'water-restoration', 'chart' => 'freeze_days',
     'freeze_days_monthly' => [29, 26, 24, 14, 3, 0, 0, 0, 1, 11, 24, 28],
     'freeze_source'       => 'ILLUSTRATIVE — not researched'],
    ['city' => 'Lufkin', 'SS' => 'TX', 'niche' => 'pest', 'chart' => 'pest_season',
     'pest_activity_monthly' => [2, 2, 4, 6, 8, 9, 10, 10, 8, 6, 3, 2],
     'pest_activity_source'  => 'ILLUSTRATIVE — not researched'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>City Chart — demo</title>
<style>
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#f8fafc;color:#1e293b;margin:0;padding:26px 30px;font-size:15px}
h1{font-size:1.35rem;color:#1e3a5f;margin:0 0 4px}
.sub{color:#64748b;font-size:.9rem;margin:0 0 18px;max-width:940px;line-height:1.55}
.warn{background:#fffbeb;border:1px solid #fde68a;border-left:3px solid #d97706;border-radius:8px;padding:12px 14px;margin-bottom:18px;max-width:940px;font-size:.86rem;line-height:1.55;color:#78350f}
.note{background:#fff;border:1px solid #e2e8f0;border-left:3px solid #2563eb;border-radius:8px;padding:12px 14px;margin-bottom:20px;max-width:940px;font-size:.86rem;line-height:1.55;color:#334155}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(460px,1fr));gap:20px}
.card{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:14px}
.card h3{margin:0 0 2px;font-size:1rem;color:#1e3a5f}
.meta{color:#94a3b8;font-size:.76rem;margin-bottom:10px}
.card svg{width:100%;height:auto;border-radius:8px;border:1px solid #eef2f7}
.alt{margin-top:9px;font-size:.78rem;color:#475569;line-height:1.5}
.alt b{color:#1e3a5f}
code{background:#f1f5f9;padding:1px 5px;border-radius:4px;font-size:.85em}
a.back{color:#1e3a5f;font-size:.85rem}
</style>
</head>
<body>
<a class="back" href="playground.php">&larr; Test Lab</a>
<h1>City Chart &mdash; per-city data charts</h1>
<p class="sub">One engine, many niches. What a niche charts is a JSON file <em>inside the plugin</em>, not code: <code>plugins/image-data-chart/niches/{niche}/{chart}.json</code>. The niche comes from the site's own <code>niche_brief.json</code>, so a water site gets water charts and a pest site gets pest charts with nothing to configure per site.</p>

<div class="warn">
    <strong>The numbers on this page are illustrative, not researched.</strong> No city has real
    figures yet, and the engine refuses to draw without them &mdash; a city with no data gets no
    chart rather than one drawn from estimates. These stand-ins exist only to show the drawing.
    Real figures need one research pass per city.
</div>

<div class="note">
    <strong>Why a chart and not more text.</strong> The city-map diagram taught us that words
    inside an image are invisible to Google. A chart is different: twelve numbers nobody would
    read as prose, whose <em>shape</em> a person reads instantly. The picture is the right medium
    for this content &mdash; and each one is unique per city by construction, so it can never be
    shared between sites the way a stock photo is.
</div>

<div class="grid">
<?php foreach ($demo as $row):
    $defs = city_chart_definitions($row['niche']);
    $def  = $defs[$row['chart']] ?? null;
    if (!$def) continue;
    $series = city_chart_series($def, $row);
    if (!$series) continue;
    $title = city_chart_text((string) $def['title'], $row, $def, $series);
    $alt   = city_chart_text((string) $def['alt'], $row, $def, $series);
?>
    <div class="card">
        <h3><?= $h($row['city'] . ', ' . $row['SS']) ?></h3>
        <div class="meta">niche <code><?= $h($row['niche']) ?></code> &middot; chart <code><?= $h($def['id']) ?></code></div>
        <?= city_chart_svg($series, $def, $title) ?>
        <div class="alt"><b>alt:</b> <?= $h($alt) ?></div>
    </div>
<?php endforeach; ?>
</div>
</body>
</html>
