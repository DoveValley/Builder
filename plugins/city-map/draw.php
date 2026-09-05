<?php
/**
 * City service-area diagram — the drawing half. Pure functions, no side effects.
 *
 * Deliberately a SCHEMATIC, not a map. The neighbourhood names in cities.json have no
 * coordinates, and inventing positions on a real map would be a false geographic claim on a
 * page telling people who serves their area. This draws a coverage diagram instead: the city
 * at the centre, its real named areas arranged around it, labelled as coverage. Honest about
 * what it is, and it needs no map tiles, no API key and no licence.
 *
 * Every city has different area names, so every diagram differs by construction — which is the
 * point: an image that is unique per city at zero cost per site.
 *
 * Output is SVG. It scales, it is a few KB rather than a few hundred, and the labels stay
 * crisp at any size. render.php rasterises it to WebP for the <img> so it is still an
 * ordinary indexable image.
 */

/** Deterministic 0..1 from a seed — same city, same layout, every rebuild. */
function city_map_rand(string $seed, int $i): float
{
    return (hexdec(substr(md5($seed . '|' . $i), 0, 8)) % 100000) / 100000;
}

/**
 * @param array $city  a cities.json row: city, SS, neighborhoods[], population
 * @param array $theme ['bg','ink','accent','muted'] hex colours
 */
function city_map_svg(array $city, array $theme = []): string
{
    $name  = trim((string) ($city['city'] ?? ''));
    $ss    = trim((string) ($city['SS'] ?? ''));
    $areas = array_values(array_filter(array_map('trim', (array) ($city['neighborhoods'] ?? []))));
    if ($name === '') return '';

    // More than 8 labels stops being readable at the size these render on a page.
    $areas = array_slice($areas, 0, 8);

    $bg     = $theme['bg']     ?? '#f4f7fa';
    $ink    = $theme['ink']    ?? '#12314f';
    $accent = $theme['accent'] ?? '#1f78d1';
    $muted  = $theme['muted']  ?? '#6b7f95';

    $W = 900; $H = 640;
    $cx = $W / 2; $cy = $H / 2 + 6;
    $seed = strtolower($name . '|' . $ss);

    $esc = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    $o = [];
    $o[] = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $W . ' ' . $H . '" width="' . $W . '" height="' . $H . '" role="img">';
    $o[] = '<rect width="' . $W . '" height="' . $H . '" fill="' . $esc($bg) . '"/>';

    // Coverage rings. Radii are fixed; only the labels and dots move per city, so the diagram
    // never implies a distance it cannot support.
    foreach ([250, 175, 100] as $i => $r) {
        $o[] = '<circle cx="' . $cx . '" cy="' . $cy . '" r="' . $r . '" fill="none" stroke="' . $esc($muted)
             . '" stroke-opacity="' . (0.16 + $i * 0.06) . '" stroke-width="1.5" stroke-dasharray="5 6"/>';
    }

    // The named areas, spread evenly with a small deterministic jitter so two cities with the
    // same number of areas don't produce the same picture.
    $n = max(count($areas), 1);
    foreach ($areas as $i => $area) {
        $ang = (2 * M_PI * $i / $n) - M_PI / 2 + (city_map_rand($seed, $i) - 0.5) * 0.34;
        $rad = 150 + city_map_rand($seed, $i + 50) * 105;
        $x = $cx + cos($ang) * $rad;
        $y = $cy + sin($ang) * $rad * 0.78;          // squashed: reads as ground, not a clock face

        $o[] = '<line x1="' . round($cx, 1) . '" y1="' . round($cy, 1) . '" x2="' . round($x, 1)
             . '" y2="' . round($y, 1) . '" stroke="' . $esc($accent) . '" stroke-opacity=".28" stroke-width="1.5"/>';
        $o[] = '<circle cx="' . round($x, 1) . '" cy="' . round($y, 1) . '" r="6.5" fill="' . $esc($accent) . '"/>';

        // Labels flip side past the centre line so they never run off the canvas.
        $right  = $x >= $cx;
        $tx     = $x + ($right ? 14 : -14);
        $anchor = $right ? 'start' : 'end';
        $o[] = '<text x="' . round($tx, 1) . '" y="' . round($y + 4.5, 1) . '" text-anchor="' . $anchor
             . '" font-family="system-ui,-apple-system,Segoe UI,sans-serif" font-size="19" font-weight="600" fill="'
             . $esc($ink) . '">' . $esc($area) . '</text>';
    }

    // The city itself, last so it sits above the spokes.
    $label = $ss !== '' ? "$name, $ss" : $name;
    $o[] = '<circle cx="' . $cx . '" cy="' . $cy . '" r="13" fill="' . $esc($ink) . '"/>';
    $o[] = '<circle cx="' . $cx . '" cy="' . $cy . '" r="23" fill="none" stroke="' . $esc($ink) . '" stroke-opacity=".35" stroke-width="2"/>';
    $o[] = '<text x="' . $cx . '" y="' . ($cy + 52) . '" text-anchor="middle" font-family="system-ui,-apple-system,Segoe UI,sans-serif"'
         . ' font-size="27" font-weight="700" fill="' . $esc($ink) . '">' . $esc($label) . '</text>';

    // Says what it is. A coverage diagram that looks like a map without saying so would be
    // making a geographic claim it cannot back.
    // NUMERIC entity, not &mdash;. SVG is XML and knows only the five XML entities; an HTML
    // one makes the file invalid. Browsers forgive it — which is the trap, because it renders
    // perfectly on screen while every real SVG parser refuses the file, so rasterising to WebP
    // silently fails and the page falls back to shipping raw SVG.
    $o[] = '<text x="' . $cx . '" y="' . ($H - 22) . '" text-anchor="middle" font-family="system-ui,-apple-system,Segoe UI,sans-serif"'
         . ' font-size="16" fill="' . $esc($muted) . '">Areas served &#8212; coverage diagram, not to scale</text>';
    $o[] = '</svg>';

    return implode("\n", $o);
}

/** Alt text written from the same data the picture was drawn from. */
function city_map_alt(array $city): string
{
    $name  = trim((string) ($city['city'] ?? ''));
    $ss    = trim((string) ($city['SS'] ?? ''));
    $areas = array_slice(array_values(array_filter((array) ($city['neighborhoods'] ?? []))), 0, 8);
    $where = $ss !== '' ? "$name, $ss" : $name;
    if (!$areas) return "Service area diagram for {$where}.";
    $list = count($areas) > 1
        ? implode(', ', array_slice($areas, 0, -1)) . ' and ' . end($areas)
        : $areas[0];
    return "Service area diagram for {$where}, covering {$list}.";
}
