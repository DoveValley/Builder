<?php
/**
 * City Map — demo page.
 *
 * Draws the map for every city the masters actually hold real coordinates + towns for, plus a
 * few sample cities, so the claim that matters can be checked rather than taken on trust:
 * DIFFERENT CITY, DIFFERENT PICTURE, with no per-site cost.
 *
 * Read-only. Draws SVG in memory and prints it inline — writes nothing, touches no site. The
 * basemap fetch still hits the network on a cache miss (tiles.php) the same as a real build.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../plugins/image-area-map/draw.php';

if (empty($_SESSION['admin_logged_in'])) { header('Location: login.php'); exit; }

$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES);

// Real cities first — whatever the masters have researched and geocoded.
$cities = [];
foreach (glob(BASE_DIR . '/sites/*/data/cities.json') ?: [] as $f) {
    $master = basename(dirname(dirname($f)));
    $rows = json_decode((string) @file_get_contents($f), true);
    foreach ((is_array($rows) ? $rows : []) as $row) {
        if (!is_array($row) || trim((string) ($row['city'] ?? '')) === '') continue;
        if (!array_filter((array) ($row['nearby_towns'] ?? []))) continue;
        $row['_source'] = $master . ' (real)';
        $cities[] = $row;
    }
}

// Samples, so the spread is visible even before more cities are researched. Coordinates and
// mileages are real, known public facts about these cities — not researched/AI content, so
// the describability rule that governs published site copy doesn't apply here.
foreach ([
    ['city' => 'Overland Park', 'SS' => 'KS', 'lat' => '38.9822', 'lng' => '-94.6708', 'nearby_towns' => [
        ['name' => 'Olathe', 'miles' => 10], ['name' => 'Leawood', 'miles' => 5],
        ['name' => 'Lenexa', 'miles' => 8], ['name' => 'Shawnee', 'miles' => 12], ['name' => 'Kansas City', 'miles' => 15],
    ]],
    ['city' => 'Surprise', 'SS' => 'AZ', 'lat' => '33.6292', 'lng' => '-112.3679', 'nearby_towns' => [
        ['name' => 'Sun City', 'miles' => 5], ['name' => 'Peoria', 'miles' => 8],
        ['name' => 'El Mirage', 'miles' => 4], ['name' => 'Waddell', 'miles' => 10], ['name' => 'Glendale', 'miles' => 15],
    ]],
    ['city' => 'Lancaster', 'SS' => 'CA', 'lat' => '34.6868', 'lng' => '-118.1542', 'nearby_towns' => [
        ['name' => 'Palmdale', 'miles' => 10], ['name' => 'Quartz Hill', 'miles' => 6],
        ['name' => 'Rosamond', 'miles' => 15], ['name' => 'Mojave', 'miles' => 25],
    ]],
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
<h1>City Map &mdash; real-map service-area images</h1>
<p class="sub">One map per city, centred on that city's own verified coordinates in <code>cities.json</code>, with the nearby towns it serves listed below. The point to check is that <strong>no two are alike</strong> &mdash; and that this costs nothing per site, because it is drawn once per city and reused by every site covering it.</p>

<div class="note">
    <strong>A real OpenStreetMap basemap, centred on the city, with a real boundary where OSM has one.</strong>
    The map is centred on the city's own geocoded coordinates &mdash; the same verified lat/lng
    already used for this site's LocalBusiness schema; no marker dot is drawn there. When OSM has
    the city's administrative boundary on record, it's drawn as a bold outline and the map zooms
    to fit it; otherwise the map just stays centred with no outline.
    No other town gets an outline of its own: cities.json has no coordinates for them, and guessing
    would reintroduce the exact problem that got compass bearings cut from this plugin before.
    They stay named, with real driving distances, in the list below the map. Each image also
    writes its own alt text from the same data it drew.
</div>

<div class="grid">
<?php foreach ($cities as $c): ?>
    <div class="card">
        <h3><?= $h(trim(($c['city'] ?? '') . ', ' . ($c['SS'] ?? ''), ', ')) ?></h3>
        <div class="meta"><?= $h($c['_source']) ?> &middot; <?= count(array_filter((array) ($c['nearby_towns'] ?? []))) ?> nearby towns
            <?= empty($c['lat']) || empty($c['lng']) ? ' &middot; <span style="color:#dc2626">no coordinates yet</span>' : '' ?></div>
        <?php $svg = city_map_svg($c); ?>
        <?php if ($svg !== ''): ?>
            <?= $svg ?>
            <div class="alt"><b>alt:</b> <?= $h(city_map_alt($c)) ?></div>
        <?php else: ?>
            <div class="alt" style="color:#dc2626">No image &mdash; missing coordinates, no towns, or the basemap fetch failed.</div>
        <?php endif; ?>
    </div>
<?php endforeach; ?>
</div>
</body>
</html>
