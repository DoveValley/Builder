<?php
/**
 * City service-area diagram — the drawing half. Pure functions, no side effects.
 *
 * ONE THING ONLY: the city at the centre, and the SURROUNDING TOWNS it serves — on the ring as
 * a picture, and in the column beside it with their driving distances.
 *
 * It used to draw neighbourhoods on that ring. They were being INVENTED. Four sites covering
 * Lufkin returned lists that barely overlapped, "Fredonia Hill Historic District" belongs to
 * Nacogdoches, and OpenStreetMap records no named areas in Lufkin at all — a city of 34,500
 * simply has none, so there was nothing to recall. Towns are incorporated municipalities: real,
 * checkable, and the research got them right. One accurate tier beats two when one is fiction.
 *
 * DELIBERATELY A SCHEMATIC, NOT A MAP. Ring positions carry no geography: they are seeded from
 * the city name so every city's picture differs, and the footer says it is not to scale.
 *
 * We DID try placing towns on their true compass bearing, and cut it. Two reasons, in order of
 * importance. First it bought nothing: the diagram was already unique per city, and someone in
 * Diboll is looking for the word "Diboll", not for where the dot sits. Second the data would
 * not support it — research returned a direction that was wrong for four of eight towns around
 * Lufkin, Pollok coming back "S" when it is NW, off by 132 degrees. A bearing is glaringly
 * wrong to a local reader in a way this schematic never can be, so the claim cost credibility
 * and returned nothing. Do not re-add it without a reason the picture actually needs.
 *
 * The mileage IS kept, because "Diboll, 10 mi" is the one fact a customer wants. But note it is
 * pixels here: the real value of these town names is in the page copy, where Google can read
 * them and "we serve Diboll" is a keyword of its own.
 *
 * No map tiles, no API key, no licence, and every city differs by construction.
 *
 * Output is SVG. render.php rasterises it to WebP for the <img> so it stays an ordinary
 * indexable image.
 */

/** Deterministic 0..1 from a seed — same city, same layout, every rebuild. */
function city_map_rand(string $seed, int $i): float
{
    return (hexdec(substr(md5($seed . '|' . $i), 0, 8)) % 100000) / 100000;
}

/** The within-city areas, cleaned. */
function city_map_areas(array $city): array
{
    $out = [];
    foreach ((array) ($city['neighborhoods'] ?? []) as $n) {
        $n = trim((string) $n);
        if ($n !== '') $out[] = $n;
    }
    return $out;
}

/**
 * Nearby towns, normalised to ['name','miles'].
 *
 * Permissive about shape because research output is not ours to dictate: an entry may be a
 * bare string, or an object using any of a few obvious key names.
 */
function city_map_towns(array $city): array
{
    $out = [];
    foreach ((array) ($city['nearby_towns'] ?? []) as $t) {
        if (is_string($t)) {
            $name = trim($t);
            if ($name !== '') $out[] = ['name' => $name, 'miles' => null];
            continue;
        }
        if (!is_array($t)) continue;
        $name = trim((string) ($t['name'] ?? $t['town'] ?? $t['city'] ?? ''));
        if ($name === '') continue;
        $mi = $t['miles'] ?? $t['distance'] ?? $t['distance_miles'] ?? null;
        $out[] = ['name' => $name, 'miles' => (is_numeric($mi) && $mi > 0) ? (float) $mi : null];
    }
    return $out;
}

/** Trim a float for display: 10.0 -> "10", 10.5 -> "10.5". */
function city_map_num(float $n): string
{
    return rtrim(rtrim(number_format($n, 1, '.', ''), '0'), '.');
}

/**
 * @param array $city  a cities.json row: city, SS, neighborhoods[], nearby_towns[]
 * @param array $theme ['bg','ink','accent','muted'] hex colours
 */
function city_map_svg(array $city, array $theme = []): string
{
    $name = trim((string) ($city['city'] ?? ''));
    $ss   = trim((string) ($city['SS'] ?? ''));
    if ($name === '') return '';

    // More than 8 labels on the ring stops being readable at the size these render on a page.
    // The list is cheaper, so it holds more before it has to say how many it left out.
    $allTowns = city_map_towns($city);
    $towns    = array_slice($allTowns, 0, 8);
    $dropped  = count($allTowns) - count($towns);

    $bg     = $theme['bg']     ?? '#f4f7fa';
    $ink    = $theme['ink']    ?? '#12314f';
    $accent = $theme['accent'] ?? '#1f78d1';
    $muted  = $theme['muted']  ?? '#6b7f95';

    // With a town list beside it the diagram moves left and tightens up; on its own it takes
    // the full width and stays centred.
    // Height follows whichever column is taller. The ring is a squashed ellipse and uses less
    // vertical room than its radius suggests, so a fixed height left a wide band of dead space
    // above and below it.
    $W = 900;
    $hasList = (bool) $towns;
    $listX   = 596;
    $rowH    = 31;
    $R  = $hasList ? 200 : 250;                  // outer extent of the ring
    $cx = $hasList ? 306 : $W / 2;
    $cy = 60 + $R * 0.78 + 20;
    $diagramBottom = $cy + $R * 0.78 + 20;
    $listBottom    = $hasList ? 56 + count($towns) * $rowH + ($dropped > 0 ? 26 : 0) : 0;
    $H  = (int) round(max($diagramBottom, $listBottom) + 46);
    $chipMaxX = $hasList ? $listX - 20 : $W - 14;
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
    $o[] = '<circle cx="' . $cx . '" cy="' . $cy . '" r="' . round($R * 0.98) . '" fill="url(#halo)"/>';

    // Rings are scenery, not scale — they give the diagram depth without implying a distance.
    foreach ([[0.52, .20], [0.76, .13], [1.0, .10]] as [$f, $op]) {
        $o[] = '<circle cx="' . $cx . '" cy="' . $cy . '" r="' . round($R * $f, 1) . '" fill="none" stroke="' . $esc($muted)
             . '" stroke-opacity="' . $op . '" stroke-width="1.4" stroke-dasharray="4 8"/>';
    }

    // Nodes are COLLECTED before anything is drawn, because the labels have to be laid out
    // against each other.
    $nodes = [];

    // The ring carries the SURROUNDING TOWNS. It used to carry neighbourhoods, and those were
    // being invented: four sites covering Lufkin returned lists that barely overlapped, and
    // OpenStreetMap records no named areas there at all. Towns are incorporated municipalities
    // — real, checkable, and the research got them right. Accuracy beats having a second tier.
    //
    // Radius is ordered by distance where we have it, so nearer towns sit nearer the middle.
    // Angles are seeded, not geographic; the footer says as much.
    $maxMi = 0.0;
    foreach ($towns as $t) if ($t['miles'] !== null) $maxMi = max($maxMi, $t['miles']);

    $n = max(count($towns), 1);
    foreach ($towns as $i => $t) {
        $ang = (2 * M_PI * $i / $n) - M_PI / 2 + (city_map_rand($seed, $i) - 0.5) * 0.28;
        $rad = ($maxMi > 0 && $t['miles'] !== null)
            ? $R * (0.52 + ($t['miles'] / $maxMi) * 0.42)
            : $R * 0.76;
        // No mileage on the chip — the column beside the diagram carries it, and printing it
        // twice only crowds the picture.
        $nodes[] = ['x' => $cx + cos($ang) * $rad, 'y' => $cy + sin($ang) * $rad * 0.78,
                    'label' => $t['name'], 'sub' => '', 'strong' => true];
    }

    // Chip geometry. SVG gives no text metrics, so widths are estimated from character count;
    // over-estimating is safe, a chip too narrow clips the name.
    $chH = 30;
    foreach ($nodes as &$nd) {
        $subW = $nd['sub'] !== '' ? 8.6 * mb_strlen($nd['sub']) + 16 : 0;
        $nd['w'] = 11.2 * mb_strlen($nd['label']) + 30 + $subW;
        $nd['right'] = $nd['x'] >= $cx;               // labels flip side past the centre line
        $nd['cy'] = $nd['y'];                         // chip centre, moved by the pass below
        // A long name on a far dot would otherwise run off the canvas and be clipped by the
        // rasteriser. Clamp the chip into frame; the dot keeps its position.
        $nd['chX'] = $nd['right'] ? $nd['x'] + 15 : $nd['x'] - 15 - $nd['w'];
        $nd['chX'] = max(14, min($nd['chX'], $chipMaxX - $nd['w']));
    }
    unset($nd);

    // Collision pass. Chips on the same side that overlap vertically get pushed apart; the
    // DOT never moves, only its label, so a nudge costs nothing.
    for ($pass = 0; $pass < 24; $pass++) {
        $moved = false;
        foreach ([true, false] as $side) {
            $idx = [];
            foreach ($nodes as $i => $nd) if ($nd['right'] === $side) $idx[] = $i;
            usort($idx, fn($a, $b) => $nodes[$a]['cy'] <=> $nodes[$b]['cy']);
            for ($k = 1; $k < count($idx); $k++) {
                $a = $nodes[$idx[$k - 1]];
                $b = $nodes[$idx[$k]];
                // Only chips that overlap horizontally can collide. The margin covers the dot
                // and leader that sit just outside a chip — without it, two merely ADJACENT
                // chips pass this test and the second one's dot lands on the first one's text.
                $pad = 16;
                if ($a['chX'] + $a['w'] + $pad < $b['chX'] || $b['chX'] + $b['w'] + $pad < $a['chX']) continue;
                $gap = $b['cy'] - $a['cy'];
                $need = $chH + 5;
                if ($gap >= $need) continue;
                $push = ($need - $gap) / 2;
                $nodes[$idx[$k - 1]]['cy'] -= $push;
                $nodes[$idx[$k]]['cy']     += $push;
                $moved = true;
            }
        }
        // The city's own name is an obstacle too — with only a few areas the ring is sparse and
        // a chip can land right on top of it. Push clear of it, away from the centre.
        $lblW = 12.0 * mb_strlen($ss !== '' ? "$name, $ss" : $name) + 24;
        $lblL = $cx - $lblW / 2; $lblR = $cx + $lblW / 2;
        $lblT = $cy + 36;        $lblB = $cy + 74;
        foreach ($nodes as $i => $nd) {
            if ($nd['chX'] + $nd['w'] < $lblL || $nd['chX'] > $lblR) continue;
            if ($nd['cy'] + 15 < $lblT || $nd['cy'] - 15 > $lblB) continue;
            $nodes[$i]['cy'] += ($nd['cy'] < ($lblT + $lblB) / 2) ? -($nd['cy'] + 15 - $lblT) - 2
                                                                 : ($lblB - $nd['cy'] + 15) + 2;
            $moved = true;
        }
        if (!$moved) break;
    }
    // Keep every chip on the canvas, below the caption and above the footer.
    foreach ($nodes as &$nd) $nd['cy'] = max(58, min($H - 54, $nd['cy']));
    unset($nd);

    foreach ($nodes as $nd) {
        $x = $nd['x']; $y = $nd['y']; $strong = $nd['strong'];
        $o[] = '<line x1="' . round($cx, 1) . '" y1="' . round($cy, 1) . '" x2="' . round($x, 1)
             . '" y2="' . round($y, 1) . '" stroke="' . $esc($accent) . '" stroke-opacity="'
             . ($strong ? '.30' : '.22') . '" stroke-width="' . ($strong ? '1.6' : '1.3')
             . '"' . ($strong ? '' : ' stroke-dasharray="5 5"') . '/>';
        $o[] = '<circle cx="' . round($x, 1) . '" cy="' . round($y, 1) . '" r="9" fill="' . $esc($accent) . '" fill-opacity=".18"/>';
        $o[] = '<circle cx="' . round($x, 1) . '" cy="' . round($y, 1) . '" r="' . ($strong ? '5.5' : '5')
             . '" fill="' . ($strong ? 'url(#dotg)' : $esc($accent)) . '"/>';

        $chX = $nd['chX'];
        $chY = $nd['cy'];
        // When the pass has moved a chip clear of its dot, a short leader keeps the two
        // visibly tied together.
        if (abs($chY - $y) > 3) {
            $tie = $nd['right'] ? $chX : $chX + $nd['w'];
            $o[] = '<line x1="' . round($x, 1) . '" y1="' . round($y, 1) . '" x2="' . round($tie, 1)
                 . '" y2="' . round($chY, 1) . '" stroke="' . $esc($muted) . '" stroke-opacity=".38" stroke-width="1"/>';
        }
        $o[] = '<rect x="' . round($chX, 1) . '" y="' . round($chY - $chH / 2, 1) . '" width="' . round($nd['w'], 1)
             . '" height="' . $chH . '" rx="15" fill="#ffffff" fill-opacity=".92" stroke="' . $esc($accent)
             . '" stroke-opacity="' . ($strong ? '.22' : '.30') . '" stroke-width="1"/>';
        $o[] = '<text x="' . round($chX + 15, 1) . '" y="' . round($chY + 5.5, 1) . '"'
             . ' font-family="system-ui,-apple-system,Segoe UI,sans-serif" font-size="17" font-weight="600" fill="'
             . $esc($ink) . '">' . $esc($nd['label']) . '</text>';
        if ($nd['sub'] !== '') {
            $o[] = '<text x="' . round($chX + $nd['w'] - 13, 1) . '" y="' . round($chY + 5, 1) . '" text-anchor="end"'
                 . ' font-family="system-ui,-apple-system,Segoe UI,sans-serif" font-size="14" font-weight="600" fill="'
                 . $esc($muted) . '">' . $esc($nd['sub']) . '</text>';
        }
    }

    // The city itself, last so it sits above the spokes.
    $label = $ss !== '' ? "$name, $ss" : $name;
    $o[] = '<circle cx="' . $cx . '" cy="' . $cy . '" r="30" fill="#ffffff" fill-opacity=".85"/>';
    $o[] = '<circle cx="' . $cx . '" cy="' . $cy . '" r="23" fill="none" stroke="' . $esc($accent) . '" stroke-opacity=".45" stroke-width="2"/>';
    $o[] = '<circle cx="' . $cx . '" cy="' . $cy . '" r="14" fill="url(#dotg)"/>';
    $o[] = '<circle cx="' . ($cx - 4) . '" cy="' . ($cy - 5) . '" r="4" fill="#ffffff" fill-opacity=".35"/>';
    $o[] = '<text x="' . $cx . '" y="' . ($cy + 58) . '" text-anchor="middle" font-family="system-ui,-apple-system,Segoe UI,sans-serif"'
         . ' font-size="28" font-weight="700" fill="' . $esc($ink) . '">' . $esc($label) . '</text>';

    // Caption for the ring. "AROUND", not "IN" — these are separate towns, not districts.
    if ($towns) {
        $o[] = '<circle cx="33" cy="29" r="5.5" fill="url(#dotg)"/>';
        $o[] = '<text x="47" y="34" font-family="system-ui,-apple-system,Segoe UI,sans-serif" font-size="15"'
             . ' font-weight="700" letter-spacing="0.6" fill="' . $esc($ink) . '" fill-opacity=".65">AROUND '
             . $esc(strtoupper($name)) . '</text>';
    }

    // ── The town list, set beside the diagram.
    //
    // A column rather than more chips: the mileages line up so they can be compared at a
    // glance, and a list makes no claim about where any town lies — which is right, because we
    // do not know.
    if ($hasList) {
        $listW = $W - 24 - $listX;
        // Top-aligned with the ring's caption, so the two tiers read as two columns.
        $ly = 34;

        $o[] = '<text x="' . $listX . '" y="' . $ly . '" font-family="system-ui,-apple-system,Segoe UI,sans-serif"'
             . ' font-size="15" font-weight="700" letter-spacing="0.6" fill="' . $esc($ink)
             . '" fill-opacity=".65">NEARBY TOWNS SERVED</text>';
        $ly += 22;

        foreach ($towns as $i => $t) {
            $ly += $rowH;
            if ($i > 0) {
                $o[] = '<line x1="' . $listX . '" y1="' . round($ly - $rowH + 9, 1) . '" x2="' . ($listX + $listW)
                     . '" y2="' . round($ly - $rowH + 9, 1) . '" stroke="' . $esc($muted) . '" stroke-opacity=".16" stroke-width="1"/>';
            }
            $o[] = '<circle cx="' . ($listX + 5) . '" cy="' . round($ly - 5, 1) . '" r="4" fill="' . $esc($accent) . '" fill-opacity=".55"/>';
            $o[] = '<text x="' . ($listX + 18) . '" y="' . round($ly, 1) . '"'
                 . ' font-family="system-ui,-apple-system,Segoe UI,sans-serif" font-size="17" font-weight="600" fill="'
                 . $esc($ink) . '">' . $esc($t['name']) . '</text>';
            if ($t['miles'] !== null) {
                $o[] = '<text x="' . ($listX + $listW) . '" y="' . round($ly, 1) . '" text-anchor="end"'
                     . ' font-family="system-ui,-apple-system,Segoe UI,sans-serif" font-size="15" font-weight="600" fill="'
                     . $esc($muted) . '">' . $esc(city_map_num($t['miles'])) . ' mi</text>';
            }
        }

        // Never truncate silently — a shortened list would read as "this is everywhere we go".
        if ($dropped > 0) {
            $o[] = '<text x="' . ($listX + 18) . '" y="' . round($ly + 26, 1) . '"'
                 . ' font-family="system-ui,-apple-system,Segoe UI,sans-serif" font-size="15" font-style="italic" fill="'
                 . $esc($muted) . '">and ' . $dropped . ' more</text>';
        }
    }

    // Says what it is. A coverage diagram that looked like a map without saying so would be
    // making a geographic claim it cannot back.
    // NUMERIC entity, not &mdash;. SVG is XML and knows only the five XML entities; an HTML one
    // makes the file invalid. Browsers forgive it — which is the trap, because it renders
    // perfectly on screen while every real SVG parser refuses the file, so rasterising to WebP
    // silently fails and the page ships raw SVG.
    $o[] = '<text x="' . $cx . '" y="' . ($H - 22) . '" text-anchor="middle" font-family="system-ui,-apple-system,Segoe UI,sans-serif"'
         . ' font-size="16" fill="' . $esc($muted) . '">Areas served &#8212; coverage diagram, not to scale</text>';
    // A framing line around the whole picture. Drawn INSIDE the SVG rather than as CSS on the
    // <img>, so it travels with the image everywhere it is used and never restyles the site's
    // other photos. Inset by half the stroke, because a stroke straddles its path and would
    // otherwise be clipped in half at the canvas edge.
    $o[] = '<rect x="2" y="2" width="' . ($W - 4) . '" height="' . ($H - 4) . '" rx="16" fill="none" stroke="'
         . $esc($accent) . '" stroke-opacity=".55" stroke-width="4"/>';
    $o[] = '</svg>';

    return implode("\n", $o);
}

/** Join a list the way a sentence does. */
function city_map_join(array $items): string
{
    if (count($items) > 1) return implode(', ', array_slice($items, 0, -1)) . ' and ' . end($items);
    return $items[0] ?? '';
}

/**
 * Alt text written from the same data the picture was drawn from — including the distances,
 * so someone who cannot see the image gets what the labels carry rather than a bare list.
 */
function city_map_alt(array $city): string
{
    $name  = trim((string) ($city['city'] ?? ''));
    $ss    = trim((string) ($city['SS'] ?? ''));
    $where = $ss !== '' ? "$name, $ss" : $name;
    $towns = array_slice(city_map_towns($city), 0, 8);

    $parts = [];
    if ($towns) {
        $desc = [];
        foreach ($towns as $t) {
            $desc[] = $t['name'] . ($t['miles'] !== null ? ' ' . city_map_num($t['miles']) . ' miles away' : '');
        }
        $parts[] = 'covering ' . city_map_join($desc);
    }
    if (!$parts) return "Service area diagram for {$where}.";
    return "Service area diagram for {$where}, " . implode(' ', $parts) . '.';
}

/**
 * The caption under the diagram — the coverage claim as TEXT, since every name inside the
 * picture is pixels a crawler cannot read.
 *
 * THE CITY IS THE SUBJECT, and the surrounding towns are deliberately NOT listed here. Eight
 * town names repeated under every page is boilerplate and a well-known low-quality local-SEO
 * pattern; it dilutes the single entity the site is built around, and on a one-site-per-city
 * strategy it competes with a future site for those towns. They stay inside the diagram, where
 * being unreadable to a crawler is a FEATURE — a person still gets their answer at a glance.
 *
 * It no longer names neighbourhoods either, because those were being invented. Four sites
 * covering Lufkin returned barely-overlapping lists and OpenStreetMap records no named areas
 * there at all, yet those names reached the built pages 54 times each.
 *
 * Phrasing varies by domain, so sites off one master do not all carry the same sentence.
 */
function city_map_caption(array $city, string $domain = ''): string
{
    $name = trim((string) ($city['city'] ?? ''));
    if ($name === '') return '';
    $ss    = trim((string) ($city['SS'] ?? ''));
    $where = $ss !== '' ? "$name, $ss" : $name;
    $towns = city_map_towns($city);
    if (!$towns) return '';
    $n = count($towns);

    $opts = [
        "Coverage centres on {$where} and reaches {$n} surrounding communities. Response time is what matters most in the hours after a problem is found, so knowing the ground is part of the job.",
        "Based in {$where}, with {$n} nearby communities inside the service area — near enough to reach quickly rather than a list of places on a map.",
        "{$where} is the centre of the service area, which extends to {$n} surrounding towns. The diagram shows how far that reaches.",
        "Serving {$where} and {$n} communities around it. Local knowledge of how each area is built shapes how the work is handled there.",
    ];
    // ms_variant() is the project's existing per-domain primitive, and it measures evenly here.
    $pick = function_exists('ms_variant') ? ms_variant($domain, count($opts), 'map_caption') : 0;
    return $opts[$pick] ?? $opts[0];
}

