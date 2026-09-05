<?php
/**
 * City Map — demo page.
 *
 * Draws the diagram for every city the masters actually hold, plus a few sample cities, so the
 * claim that matters can be checked rather than taken on trust: DIFFERENT CITY, DIFFERENT
 * PICTURE, with no per-site cost.
 *
 * Read-only. Draws SVG in memory and prints it inline — writes nothing, touches no site.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../plugins/city-map/draw.php';

if (empty($_SESSION['admin_logged_in'])) { header('Location: login.php'); exit; }

$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES);

// Real cities first — whatever the masters have researched.
$cities = [];
foreach (glob(BASE_DIR . '/sites/*/data/cities.json') ?: [] as $f) {
    $master = basename(dirname(dirname($f)));
    $rows = json_decode((string) @file_get_contents($f), true);
    foreach ((is_array($rows) ? $rows : []) as $row) {
        if (!is_array($row) || trim((string) ($row['city'] ?? '')) === '') continue;
        if (!array_filter((array) ($row['neighborhoods'] ?? []))) continue;
        $row['_source'] = $master . ' (real)';
        $cities[] = $row;
    }
}

// Samples, so the spread is visible even before more cities are researched.
foreach ([
    ['city' => 'Overland Park', 'SS' => 'KS', 'neighborhoods' => ['Downtown OP', 'Deer Creek', 'Nottingham', 'Blue Valley', 'Corporate Woods']],
    ['city' => 'Surprise',      'SS' => 'AZ', 'neighborhoods' => ['Marley Park', 'Sarah Ann Ranch', 'Litchfield Manor', 'Rancho Gabriela', 'Greer Ranch', 'Asante', 'Sierra Montana']],
    ['city' => 'Lancaster',     'SS' => 'CA', 'neighborhoods' => ['Quartz Hill', 'West Lancaster', 'Antelope Acres', 'Lancaster Park']],
] as $s) { $s['_source'] = 'sample'; $cities[] = $s; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>City Map — demo</title>
<style>
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#f8fafc;color:#1e293b;margin:0;padding:26px 30px;font-size:15px}
h1{font-size:1.35rem;color:#1e3a5f;margin:0 0 4px}
.sub{color:#64748b;font-size:.9rem;margin:0 0 20px;max-width:900px;line-height:1.55}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(430px,1fr));gap:20px}
.card{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:14px}
.card h3{margin:0 0 2px;font-size:1rem;color:#1e3a5f}
.meta{color:#94a3b8;font-size:.76rem;margin-bottom:10px}
.card svg{width:100%;height:auto;border-radius:8px;border:1px solid #eef2f7}
.alt{margin-top:9px;font-size:.78rem;color:#475569;line-height:1.5}
.alt b{color:#1e3a5f}
a.back{color:#1e3a5f;font-size:.85rem}
.note{background:#fff;border:1px solid #e2e8f0;border-left:3px solid #2563eb;border-radius:8px;padding:12px 14px;margin-bottom:20px;max-width:900px;font-size:.86rem;line-height:1.55;color:#334155}
</style>
</head>
<body>
<a class="back" href="playground.php">&larr; Test Lab</a>
<h1>City Map &mdash; service-area diagrams</h1>
<p class="sub">One diagram per city, drawn from that city's own named areas in <code>cities.json</code>. The point to check is that <strong>no two are alike</strong> &mdash; and that this costs nothing per site, because it is drawn once per city and reused by every site covering it.</p>

<div class="note">
    <strong>It is a diagram, not a map.</strong> The area names have no coordinates, so placing
    them on real geography would be a claim the data cannot support. The image says so on its
    face. Each one also writes its own alt text from the same data it drew &mdash; which is the
    other half of the value, since every site currently ships near-identical alt text.
</div>

<div class="grid">
<?php foreach ($cities as $c): ?>
    <div class="card">
        <h3><?= $h(trim(($c['city'] ?? '') . ', ' . ($c['SS'] ?? ''), ', ')) ?></h3>
        <div class="meta"><?= $h($c['_source']) ?> &middot; <?= count(array_filter((array) ($c['neighborhoods'] ?? []))) ?> named areas</div>
        <?= city_map_svg($c) ?>
        <div class="alt"><b>alt:</b> <?= $h(city_map_alt($c)) ?></div>
    </div>
<?php endforeach; ?>
</div>
</body>
</html>
