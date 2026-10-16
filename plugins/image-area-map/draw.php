<?php
/**
 * City service-area image — the drawing half. Pure functions except for the basemap fetch
 * (tiles.php), which hits the network the first time any given map tile is needed.
 *
 * ONE THING ONLY: a real map centred on the city, pinned once at the city's own (geocoded,
 * verified) coordinates — and the SURROUNDING TOWNS it serves, in the list below with their
 * driving distances.
 *
 * It used to draw neighbourhoods on a schematic ring. They were being INVENTED. Four sites
 * covering Lufkin returned lists that barely overlapped, "Fredonia Hill Historic District"
 * belongs to Nacogdoches, and OpenStreetMap records no named areas in Lufkin at all. Towns are
 * incorporated municipalities: real, checkable, and the research got them right.
 *
 * It then switched the ring itself from a geography-free schematic to true compass bearings,
 * and cut THAT too — research returned a direction wrong for four of eight towns around Lufkin,
 * Pollok coming back "S" when it is NW, off by 132 degrees. That failure was specific to guessing
 * a TOWN's bearing from an LLM; it does not apply to the CITY's own position, which already comes
 * from a verified geocode (includes/multisite/geocode.php, Nominatim) and already powers this
 * site's LocalBusiness schema elsewhere. So: the city gets a real pin on a real map. The towns
 * still do NOT get pins — geocoding a whole list of same-named small towns correctly is the same
 * class of risk that broke the bearings, multiplied by however many towns are listed, for a
 * payoff (an unlabelled dot) smaller than what it already has in the list below. Do not add town
 * pins without fixing that risk first.
 *
 * The mileage IS kept, because "Diboll, 10 mi" is the one fact a customer wants. Note it is
 * pixels here: the real value of these town names is in the page copy, where Google can read
 * them and "we serve Diboll" is a keyword of its own.
 *
 * Map tiles are OpenStreetMap's free raster server (plugins/image-area-map/tiles.php) — no API
 * key, no licence fee, cached per-tile on disk forever. No coordinates means no image, same as
 * every other data-gated plugin in this codebase.
 *
 * When OSM has a recorded administrative boundary for the city, it's drawn as a bold outline —
 * also real, also from Nominatim, also cached (tiles.php's city_map_fetch_boundary()), never
 * drawn for any other town. Its mileage-sized extent is usually tiny next to a 10-70 mile
 * "reach" zoom, so the zoom is chosen to fit the BOUNDARY when one exists (city_map_boundary_
 * radius()) rather than the farthest listed town — the farthest town simply won't always appear
 * on the map image itself any more, though it stays correct in the list below either way. No
 * boundary on record falls back to the old reach-based zoom, unchanged.
 *
 * Output is SVG (a real raster basemap embedded as a base64 PNG, plus vector pin/boundary/text
 * on top). render.php rasterises it to WebP for the <img> so it stays an ordinary indexable image.
 */

require_once __DIR__ . '/tiles.php';

/** $city's own coordinates, as floats — or null if missing/invalid. Never guessed. */
function city_map_latlng(array $city): ?array
{
    $lat = $city['lat'] ?? null;
    $lng = $city['lng'] ?? null;
    if (!is_numeric($lat) || !is_numeric($lng)) return null;
    $lat = (float) $lat; $lng = (float) $lng;
    if ($lat === 0.0 && $lng === 0.0) return null;
    if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) return null;
    return ['lat' => $lat, 'lng' => $lng];
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

    // No verified coordinates, no map — same rule every other data-gated plugin in this
    // codebase follows (NO DATA MEANS NO CHART). We will not estimate these.
    $latlng = city_map_latlng($city);
    if ($latlng === null) return '';

    $allTowns = city_map_towns($city);
    $towns    = array_slice($allTowns, 0, 12);
    $dropped  = count($allTowns) - count($towns);

    $bg     = $theme['bg']     ?? '#f4f7fa';
    $ink    = $theme['ink']    ?? '#12314f';
    $accent = $theme['accent'] ?? '#1f78d1';
    $muted  = $theme['muted']  ?? '#6b7f95';

    $maxMi = 0.0;
    foreach ($towns as $t) if ($t['miles'] !== null) $maxMi = max($maxMi, $t['miles']);

    // The real city-limits boundary, from the SAME OSM family as the coordinates (Nominatim's
    // polygon_geojson), not a guess. Fetched BEFORE the zoom is chosen: when a boundary exists,
    // it drives the zoom (city-first, clear outline) rather than the farthest listed town (which
    // left a typical 5-10 mile boundary as a few illegible pixels — see tiles.php's
    // city_map_boundary_radius() for why). No boundary available falls back to the old
    // reach-based framing, unchanged.
    $boundaryRings = city_map_fetch_boundary($name, $ss);
    $boundaryRadius = $boundaryRings ? city_map_boundary_radius($boundaryRings) : 0.0;
    // No mileage data at all (rare: towns present but every distance null) — fall back to a
    // sensible local-area view rather than refusing the whole image over one missing field.
    $radiusMiles = $boundaryRadius > 0 ? $boundaryRadius : ($maxMi > 0 ? $maxMi : 15.0);

    // The town list sits BELOW the map, full width, numbered — unchanged from the schematic.
    $W = 900;
    $hasList = (bool) $towns;
    $rowH    = 31;
    $mapTop  = 60;
    $mapH    = 520;
    $cx = $W / 2;
    $cy = $mapTop + $mapH / 2;
    $diagramBottom = $mapTop + $mapH;
    $listX   = 60;
    $listW   = $W - $listX * 2;
    $listTop = $diagramBottom + 40;
    $listBottom = $hasList ? $listTop + 22 + count($towns) * $rowH + ($dropped > 0 ? 26 : 0) : $diagramBottom;
    $H = (int) round($listBottom + 46);

    // The one network call in this plugin. Cached per-tile forever (tiles.php), so a city only
    // ever pays this cost the first time ANY site needs it. No converter, no network, or every
    // tile failing all mean the same thing: no image this build, same as missing coordinates —
    // never fall back to the old schematic, which would silently reintroduce guessed geometry.
    $mapPng = city_map_fetch_basemap($latlng['lat'], $latlng['lng'], $radiusMiles, $W, $mapH);
    if ($mapPng === null) return '';
    $mapB64 = base64_encode($mapPng);

    $esc = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    $o = [];
    $o[] = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"'
         . ' viewBox="0 0 ' . $W . ' ' . $H . '" width="' . $W . '" height="' . $H . '" role="img">';

    // Gradients only — no SVG filters. rsvg-convert and ImageMagick handle gradients reliably;
    // filter effects are patchy across rasterisers, and a diagram that renders in a browser but
    // not in the build is the exact trap the &mdash; entity already cost us once.
    $o[] = '<defs>'
         . '<linearGradient id="bgg" x1="0" y1="0" x2="0" y2="1">'
         . '<stop offset="0" stop-color="#ffffff"/><stop offset="1" stop-color="' . $esc($bg) . '"/></linearGradient>'
         . '<linearGradient id="dotg" x1="0" y1="0" x2="0" y2="1">'
         . '<stop offset="0" stop-color="' . $esc($accent) . '"/>'
         . '<stop offset="1" stop-color="' . $esc($ink) . '"/></linearGradient>'
         . '<clipPath id="mapclip"><rect x="0" y="' . $mapTop . '" width="' . $W . '" height="' . $mapH . '" rx="12"/></clipPath>'
         . '</defs>';
    $o[] = '<rect width="' . $W . '" height="' . $H . '" fill="url(#bgg)"/>';

    // The real basemap, embedded as a raster image — not vector geometry, so there is nothing
    // here that can be "wrong" the way a guessed bearing or a seeded angle could be.
    $o[] = '<image x="0" y="' . $mapTop . '" width="' . $W . '" height="' . $mapH
         . '" clip-path="url(#mapclip)" preserveAspectRatio="xMidYMid slice"'
         . ' href="data:image/png;base64,' . $mapB64 . '" xlink:href="data:image/png;base64,' . $mapB64 . '"/>';

    // The boundary fetched above (it already drove the zoom, if one was found) is drawn here,
    // on top of the map. Purely decorative on an already-correct map — a city OSM has no
    // recorded administrative boundary for simply draws without one, never gating the image the
    // way missing coordinates do.
    // The boundary's own pixel footprint, tracked across every ring — used below to push the
    // city label outside it rather than letting the outline run straight through the text.
    $boundaryPxBBox = null;
    if ($boundaryRings) {
        $proj = city_map_projection($latlng['lat'], $latlng['lng'], $radiusMiles, $W, $mapH);
        foreach ($boundaryRings as $ring) {
            if (count($ring) < 3) continue;
            $pts = [];
            // GeoJSON ring points are [lng, lat], not [lat, lng].
            foreach ($ring as $pt) {
                if (!isset($pt[0], $pt[1])) continue;
                [$px, $py] = city_map_project_point((float) $pt[1], (float) $pt[0], $proj);
                $py += $mapTop;
                $pts[] = round($px, 1) . ',' . round($py, 1);
                if ($boundaryPxBBox === null) {
                    $boundaryPxBBox = ['minX' => $px, 'maxX' => $px, 'minY' => $py, 'maxY' => $py];
                } else {
                    $boundaryPxBBox['minX'] = min($boundaryPxBBox['minX'], $px);
                    $boundaryPxBBox['maxX'] = max($boundaryPxBBox['maxX'], $px);
                    $boundaryPxBBox['minY'] = min($boundaryPxBBox['minY'], $py);
                    $boundaryPxBBox['maxY'] = max($boundaryPxBBox['maxY'], $py);
                }
            }
            if (count($pts) < 3) continue;
            $pointsAttr = $esc(implode(' ', $pts));
            // A light halo first so the dark line stays readable over dark roads/water/forest,
            // then the bold dark line itself on top.
            $o[] = '<polygon points="' . $pointsAttr . '" fill="none" stroke="#ffffff" stroke-opacity=".9"'
                 . ' stroke-width="4" stroke-linejoin="round" clip-path="url(#mapclip)"/>';
            $o[] = '<polygon points="' . $pointsAttr . '" fill="none" stroke="' . $esc($ink) . '" stroke-width="2"'
                 . ' stroke-linejoin="round" clip-path="url(#mapclip)"/>';
        }
    }

    // OSM's tile usage policy requires visible attribution; it travels WITH the image since an
    // inline SVG or a cropped screenshot can end up anywhere a page byline would not.
    $o[] = '<rect x="' . ($W - 222) . '" y="' . ($mapTop + $mapH - 26) . '" width="206" height="20" rx="4" fill="#ffffff" fill-opacity=".78"/>';
    $o[] = '<text x="' . ($W - 16) . '" y="' . ($mapTop + $mapH - 12) . '" text-anchor="end"'
         . ' font-family="system-ui,-apple-system,Segoe UI,sans-serif" font-size="11" fill="#333333">'
         . '&#169; OpenStreetMap contributors</text>';

    // No marker dot at the city's own point any more — the map is already centred there, and a
    // boundary (when one is drawn) already shows it. $cx/$cy stay as the label's anchor below.
    $label = $ss !== '' ? "$name, $ss" : $name;

    // Label sits on its own chip, not bare text over the map, so it stays legible over whatever
    // colours the real tiles happen to have underneath. With a boundary drawn, the chip is
    // pushed clear of it (right, then left, then below) rather than sitting on top of the
    // outline it would otherwise straddle; with no boundary it stays centred under the map's
    // own centre point, unchanged from before.
    $labelW = 20 + strlen($label) * 10.5;
    $gap = 14; $chipH = 34; $margin = 20;
    $labelCx = $cx; $labelTop = $cy + 18;
    if ($boundaryPxBBox !== null) {
        $midY = max($mapTop + $margin + $chipH / 2, min($mapTop + $mapH - $margin - $chipH / 2, $cy));
        if ($boundaryPxBBox['maxX'] + $gap + $labelW <= $W - $margin) {
            $labelCx = $boundaryPxBBox['maxX'] + $gap + $labelW / 2;
            $labelTop = $midY - $chipH / 2;
        } elseif ($boundaryPxBBox['minX'] - $gap - $labelW >= $margin) {
            $labelCx = $boundaryPxBBox['minX'] - $gap - $labelW / 2;
            $labelTop = $midY - $chipH / 2;
        } elseif ($boundaryPxBBox['maxY'] + $gap + $chipH <= $mapTop + $mapH - $margin) {
            $labelCx = max($margin + $labelW / 2, min($W - $margin - $labelW / 2, $cx));
            $labelTop = $boundaryPxBBox['maxY'] + $gap;
        }
        // None fit (a boundary that fills the whole frame) — falls through to the centred
        // default set above, same placement a city with no boundary already gets.
    }
    $o[] = '<rect x="' . round($labelCx - $labelW / 2, 1) . '" y="' . round($labelTop, 1) . '" width="' . round($labelW, 1)
         . '" height="' . $chipH . '" rx="8" fill="#ffffff" fill-opacity=".92"/>';
    $o[] = '<text x="' . round($labelCx, 1) . '" y="' . round($labelTop + 23, 1) . '" text-anchor="middle" font-family="system-ui,-apple-system,Segoe UI,sans-serif"'
         . ' font-size="19" font-weight="700" fill="' . $esc($ink) . '">' . $esc($label) . '</text>';

    // "AROUND", not "IN" — these are separate towns, not districts.
    if ($towns) {
        $o[] = '<rect x="18" y="14" width="' . (34 + strlen('AROUND ' . strtoupper($name)) * 8.4) . '" height="30" rx="6" fill="#ffffff" fill-opacity=".85"/>';
        $o[] = '<circle cx="33" cy="29" r="5.5" fill="url(#dotg)"/>';
        $o[] = '<text x="47" y="34" font-family="system-ui,-apple-system,Segoe UI,sans-serif" font-size="15"'
             . ' font-weight="700" letter-spacing="0.6" fill="' . $esc($ink) . '" fill-opacity=".8">AROUND '
             . $esc(strtoupper($name)) . '</text>';
    }

    // ── The town list, set BELOW the diagram (not beside it) — full width, numbered to match
    // the badges on the ring above. A name of any length fits here with room to spare, which is
    // the whole reason it moved: a floating chip on the ring had nowhere near this much width to
    // work with, and long names were clipping or colliding against each other and the old side
    // column. The mileages still line up in their own column so they can be compared at a
    // glance, and the list still makes no claim about where any town lies — which is right,
    // because we do not know.
    if ($hasList) {
        $ly = $listTop;

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
            // Same badge as the ring — same shape, same fill, same number — so the two read as
            // one legend rather than two different visual languages.
            $o[] = '<circle cx="' . ($listX + 12) . '" cy="' . round($ly - 6, 1) . '" r="12" fill="url(#dotg)"/>';
            $o[] = '<text x="' . ($listX + 12) . '" y="' . round($ly - 1.5, 1) . '" text-anchor="middle"'
                 . ' font-family="system-ui,-apple-system,Segoe UI,sans-serif" font-size="12" font-weight="700" fill="#ffffff">'
                 . ($i + 1) . '</text>';
            $o[] = '<text x="' . ($listX + 32) . '" y="' . round($ly, 1) . '"'
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
            $o[] = '<text x="' . ($listX + 32) . '" y="' . round($ly + 26, 1) . '"'
                 . ' font-family="system-ui,-apple-system,Segoe UI,sans-serif" font-size="15" font-style="italic" fill="'
                 . $esc($muted) . '">and ' . $dropped . ' more</text>';
        }
    }

    // It IS a real map now, so this no longer disclaims scale. It says what IS drawn: a real
    // outline when OSM has one on record for this city, otherwise just that the map is centred
    // there. No marker dot any more — see the label-placement comment above.
    // NUMERIC entity, not &mdash;. SVG is XML and knows only the five XML entities; an HTML one
    // makes the file invalid. Browsers forgive it — which is the trap, because it renders
    // perfectly on screen while every real SVG parser refuses the file, so rasterising to WebP
    // silently fails and the page ships raw SVG.
    $footer = $boundaryRings
        ? 'Outline marks ' . $esc($name) . '&#8217;s official city limits'
        : 'Map centred on ' . $esc($name) . ' &#8212; approximate coverage area, not an exact boundary';
    $o[] = '<text x="' . $cx . '" y="' . ($H - 22) . '" text-anchor="middle" font-family="system-ui,-apple-system,Segoe UI,sans-serif"'
         . ' font-size="16" fill="' . $esc($muted) . '">' . $footer . '</text>';
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
    if (!$parts) return "Map of the {$where} area.";
    return "Map of the {$where} area, " . implode(' ', $parts) . '.';
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
        "Based in {$where}, with {$n} nearby communities inside the service area — near enough to reach quickly, not just listed on a map.",
        "{$where} is the centre of the service area, which extends to {$n} surrounding towns. The map shows how far that reaches.",
        "Serving {$where} and {$n} communities around it. Local knowledge of how each area is built shapes how the work is handled there.",
    ];
    // ms_variant() is the project's existing per-domain primitive, and it measures evenly here.
    $pick = function_exists('ms_variant') ? ms_variant($domain, count($opts), 'map_caption') : 0;
    return $opts[$pick] ?? $opts[0];
}

