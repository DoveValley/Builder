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

    // Gradients only — no SVG filters. rsvg-convert and ImageMagick handle gradients reliably;
    // filter effects are patchy across rasterisers, and a diagram that renders in a browser but
    // not in the build is the exact trap the &mdash; entity already cost us once.
    $o[] = '<defs>'
         . '<linearGradient id="bgg" x1="0" y1="0" x2="0" y2="1">'
         . '<stop offset="0" stop-color="#ffffff"/><stop offset="1" stop-color="' . $esc($bg) . '"/></linearGradient>'
         . '<radialGradient id="halo" cx="50%" cy="50%" r="50%">'
         . '<stop offset="0" stop-color="' . $esc($accent) . '" stop-opacity=".20"/>'
         . '<stop offset="1" stop-color="' . $esc($accent) . '" stop-opacity="0"/></radialGradient>'
         . '<linearGradient id="dotg" x1="0" y1="0" x2="0" y2="1">'
         . '<stop offset="0" stop-color="' . $esc($accent) . '"/>'
         . '<stop offset="1" stop-color="' . $esc($ink) . '"/></linearGradient>'
         . '</defs>';
    $o[] = '<rect width="' . $W . '" height="' . $H . '" fill="url(#bgg)"/>';

    // A soft halo behind the centre gives the diagram depth without implying any real area.
    $o[] = '<circle cx="' . $cx . '" cy="' . $cy . '" r="255" fill="url(#halo)"/>';

    // Coverage rings. Radii are fixed; only the labels and dots move per city, so the diagram
    // never implies a distance it cannot support.
    foreach ([250, 175, 100] as $i => $r) {
        $o[] = '<circle cx="' . $cx . '" cy="' . $cy . '" r="' . $r . '" fill="none" stroke="' . $esc($muted)
             . '" stroke-opacity="' . (0.14 + $i * 0.05) . '" stroke-width="1.4" stroke-dasharray="4 8"/>';
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
             . '" y2="' . round($y, 1) . '" stroke="' . $esc($accent) . '" stroke-opacity=".30" stroke-width="1.6"/>';
        $o[] = '<circle cx="' . round($x, 1) . '" cy="' . round($y, 1) . '" r="9" fill="' . $esc($accent) . '" fill-opacity=".18"/>';
        $o[] = '<circle cx="' . round($x, 1) . '" cy="' . round($y, 1) . '" r="5.5" fill="url(#dotg)"/>';

        // Labels flip side past the centre line so they never run off the canvas, and sit on a
        // rounded chip so they read against the rings instead of tangling with them.
        $right  = $x >= $cx;
        $chW    = 10.6 * mb_strlen($area) + 24;      // approximate: no text metrics in SVG
        $chH    = 30;
        $chX    = $right ? $x + 15 : $x - 15 - $chW;
        $chY    = $y - $chH / 2;
        $o[] = '<rect x="' . round($chX, 1) . '" y="' . round($chY, 1) . '" width="' . round($chW, 1) . '" height="' . $chH
             . '" rx="15" fill="#ffffff" fill-opacity=".92" stroke="' . $esc($accent) . '" stroke-opacity=".22" stroke-width="1"/>';
        $o[] = '<text x="' . round($chX + $chW / 2, 1) . '" y="' . round($y + 5.5, 1) . '" text-anchor="middle"'
             . ' font-family="system-ui,-apple-system,Segoe UI,sans-serif" font-size="17" font-weight="600" fill="'
             . $esc($ink) . '">' . $esc($area) . '</text>';
    }

    // The city itself, last so it sits above the spokes.
    $label = $ss !== '' ? "$name, $ss" : $name;
    $o[] = '<circle cx="' . $cx . '" cy="' . $cy . '" r="30" fill="#ffffff" fill-opacity=".85"/>';
    $o[] = '<circle cx="' . $cx . '" cy="' . $cy . '" r="23" fill="none" stroke="' . $esc($accent) . '" stroke-opacity=".45" stroke-width="2"/>';
    $o[] = '<circle cx="' . $cx . '" cy="' . $cy . '" r="14" fill="url(#dotg)"/>';
    $o[] = '<circle cx="' . ($cx - 4) . '" cy="' . ($cy - 5) . '" r="4" fill="#ffffff" fill-opacity=".35"/>';
    $o[] = '<text x="' . $cx . '" y="' . ($cy + 58) . '" text-anchor="middle" font-family="system-ui,-apple-system,Segoe UI,sans-serif"'
         . ' font-size="28" font-weight="700" fill="' . $esc($ink) . '">' . $esc($label) . '</text>';

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
