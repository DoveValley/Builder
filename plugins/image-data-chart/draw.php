<?php
/**
 * Chart drawing — pure SVG, no side effects.
 *
 * One type for now: labelled bars. That covers the series a niche actually has (rainfall by
 * month, freeze days, seasonal load) and it is the shape where a picture genuinely beats
 * text — twelve numbers nobody would read as prose, but whose SHAPE reads instantly.
 *
 * NUMERIC ENTITIES ONLY. SVG is XML and knows just five named entities; an HTML one like
 * &mdash; renders perfectly in a browser and makes the file invalid, so every real SVG parser
 * refuses it and rasterising silently fails. That cost an hour on the city-map plugin.
 */

/**
 * @param array $series ['values'=>float[], 'labels'=>string[], 'source'=>string]
 * @param array $theme  ['bg','ink','accent','muted']
 */
function city_chart_svg(array $series, array $def, string $title, array $theme = []): string
{
    if (($def['type'] ?? 'bars') === 'compare')  return city_chart_svg_compare($series, $def, $title, $theme);
    if (($def['type'] ?? 'bars') === 'timeline') return city_chart_svg_timeline($series, $def, $title, $theme);

    $vals = $series['values'];
    $labels = $series['labels'];
    if (!$vals) return '';

    $bg     = $theme['bg']     ?? '#f4f7fa';
    $ink    = $theme['ink']    ?? '#12314f';
    $accent = $theme['accent'] ?? '#1f78d1';
    $muted  = $theme['muted']  ?? '#6b7f95';
    $unit   = (string) ($def['unit'] ?? '');

    $W = 900; $H = 520;
    $padL = 62; $padR = 34; $padT = 74; $padB = 76;
    $plotW = $W - $padL - $padR;
    $plotH = $H - $padT - $padB;

    $max = max($vals);
    $min = min(0, min($vals));
    // Round the top up to something a human would choose, so the axis reads sensibly.
    $span = max($max - $min, 0.0001);
    $step = pow(10, floor(log10($span))) / 2;
    $top  = ceil($max / $step) * $step;
    if ($top <= $min) $top = $min + $step;

    $n = count($vals);
    $slot = $plotW / $n;
    $barW = min($slot * 0.62, 58);

    $esc = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    $fmt = fn($x) => rtrim(rtrim(number_format($x, 1), '0'), '.');
    $y = fn($v) => $padT + $plotH - (($v - $min) / ($top - $min)) * $plotH;

    $o = [];
    $o[] = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $W . ' ' . $H . '" width="' . $W . '" height="' . $H . '" role="img">';

    // Gradients only, no filters — rasterisers handle gradients reliably and filter effects
    // patchily, and a chart that renders in a browser but not in the build is worse than a
    // plain one.
    $o[] = '<defs>'
         . '<linearGradient id="cbg" x1="0" y1="0" x2="0" y2="1">'
         . '<stop offset="0" stop-color="#ffffff"/><stop offset="1" stop-color="' . $esc($bg) . '"/></linearGradient>'
         . '<linearGradient id="barg" x1="0" y1="0" x2="0" y2="1">'
         . '<stop offset="0" stop-color="' . $esc($accent) . '" stop-opacity=".95"/>'
         . '<stop offset="1" stop-color="' . $esc($accent) . '" stop-opacity=".45"/></linearGradient>'
         . '<linearGradient id="barp" x1="0" y1="0" x2="0" y2="1">'
         . '<stop offset="0" stop-color="' . $esc($ink) . '"/>'
         . '<stop offset="1" stop-color="' . $esc($accent) . '"/></linearGradient>'
         . '</defs>';
    $o[] = '<rect width="' . $W . '" height="' . $H . '" fill="url(#cbg)"/>';
    $o[] = '<rect x="0" y="0" width="' . $W . '" height="6" fill="' . $esc($accent) . '" fill-opacity=".9"/>';
    $o[] = '<text x="' . $padL . '" y="46" font-family="system-ui,-apple-system,Segoe UI,sans-serif" font-size="25" font-weight="700" fill="'
         . $esc($ink) . '">' . $esc($title) . '</text>';

    // Gridlines + axis labels.
    for ($i = 0; $i <= 4; $i++) {
        $v = $min + ($top - $min) * $i / 4;
        $yy = round($y($v), 1);
        $o[] = '<line x1="' . $padL . '" y1="' . $yy . '" x2="' . ($W - $padR) . '" y2="' . $yy
             . '" stroke="' . $esc($muted) . '" stroke-opacity=".22" stroke-width="1"/>';
        $o[] = '<text x="' . ($padL - 12) . '" y="' . ($yy + 5) . '" text-anchor="end" font-family="system-ui,-apple-system,Segoe UI,sans-serif"'
             . ' font-size="15" fill="' . $esc($muted) . '">' . $esc($fmt($v)) . '</text>';
    }

    $maxI = array_keys($vals, max($vals))[0];
    foreach ($vals as $i => $v) {
        $cx = $padL + $slot * $i + $slot / 2;
        $bx = $cx - $barW / 2;
        $by = $y($v);
        $bh = max($y($min) - $by, 1);
        // The peak is the point of the chart — let it carry the eye.
        $o[] = '<rect x="' . round($bx, 1) . '" y="' . round($by, 1) . '" width="' . round($barW, 1) . '" height="' . round($bh, 1)
             . '" rx="6" fill="url(#' . ($i === $maxI ? 'barp' : 'barg') . ')"/>';
        if ($labels) {
            $o[] = '<text x="' . round($cx, 1) . '" y="' . ($padT + $plotH + 26) . '" text-anchor="middle"'
                 . ' font-family="system-ui,-apple-system,Segoe UI,sans-serif" font-size="15" fill="' . $esc($muted) . '">'
                 . $esc($labels[$i]) . '</text>';
        }
    }

    // Value on the peak only — labelling every bar turns a shape into a table. On a chip, so
    // it reads as a callout rather than floating text.
    // A percent sign attaches to its number ("30%"); every other unit takes a space ("5.2 in").
    $peakTxt = $fmt($vals[$maxI]) . ($unit === '' ? '' : ($unit === '%' ? '%' : ' ' . $unit));
    $chW = 10.2 * mb_strlen($peakTxt) + 22;
    $chX = $padL + $slot * $maxI + $slot / 2 - $chW / 2;
    $chY = $y($vals[$maxI]) - 40;
    // A tall peak leaves no room above the bar and the chip would sit on the title. Drop it
    // INSIDE the bar instead — the peak bar is dark at the top, so white text still reads.
    // Only surfaced once a chart had its maximum in the first month; charts that peak in the
    // middle never hit it.
    if ($chY < $padT + 4) $chY = $y($vals[$maxI]) + 8;
    $o[] = '<rect x="' . round($chX, 1) . '" y="' . round($chY, 1) . '" width="' . round($chW, 1) . '" height="28" rx="14" fill="'
         . $esc($ink) . '"/>';
    $o[] = '<text x="' . round($chX + $chW / 2, 1) . '" y="' . round($chY + 19, 1) . '" text-anchor="middle"'
         . ' font-family="system-ui,-apple-system,Segoe UI,sans-serif" font-size="16" font-weight="700" fill="#ffffff">'
         . $esc($peakTxt) . '</text>';

    $o[] = '<line x1="' . $padL . '" y1="' . round($y($min), 1) . '" x2="' . ($W - $padR) . '" y2="' . round($y($min), 1)
         . '" stroke="' . $esc($ink) . '" stroke-opacity=".45" stroke-width="1.5"/>';

    // The citation belongs ON the picture: a chart travels away from its page.
    $src = trim((string) ($series['source'] ?? ''));
    if ($src !== '') {
        $o[] = '<text x="' . $padL . '" y="' . ($H - 20) . '" font-family="system-ui,-apple-system,Segoe UI,sans-serif"'
             . ' font-size="14" fill="' . $esc($muted) . '">' . $esc('Source: ' . $src) . '</text>';
    }
    // A framing line around the whole picture. Drawn INSIDE the SVG rather than as CSS on the
    // <img>, so it travels with the image everywhere it is used and never restyles the site's
    // other photos. Inset by half the stroke, because a stroke straddles its path and would
    // otherwise be clipped in half at the canvas edge.
    $o[] = '<rect x="2" y="2" width="' . ($W - 4) . '" height="' . ($H - 4) . '" rx="16" fill="none" stroke="'
         . $esc($accent) . '" stroke-opacity=".55" stroke-width="4"/>';
    $o[] = '</svg>';
    return implode("\n", $o);
}

/**
 * A COMPARISON: this city against its benchmarks, drawn as horizontal bars.
 *
 * Horizontal on purpose. Comparison labels are phrases — "Texas average", "US average" — and
 * vertical bars would either clip them or turn them sideways. Laid on their side, the label
 * sits beside its bar and the relative lengths are the whole message.
 *
 * The city's bar is first and carries the accent; the benchmarks are muted. That ordering is
 * the argument: this place, measured against the usual.
 */
function city_chart_svg_compare(array $series, array $def, string $title, array $theme = []): string
{
    $vals = $series['values'];
    $labels = $series['labels'];
    if (count($vals) < 2) return '';

    $bg     = $theme['bg']     ?? '#f4f7fa';
    $ink    = $theme['ink']    ?? '#12314f';
    $accent = $theme['accent'] ?? '#1f78d1';
    $muted  = $theme['muted']  ?? '#6b7f95';
    $unit   = (string) ($def['unit'] ?? '');
    $hl     = (int) ($series['highlight'] ?? 0);

    $n = count($vals);
    $rowH = 62;
    $W = 900;
    $padL = 34; $padR = 34; $padT = 96;
    $H = $padT + $n * $rowH + 62;
    $labelW = 250;
    $trackX = $padL + $labelW;
    $trackW = $W - $trackX - $padR - 110;         // room for the value at the end of the bar
    $max = max(max($vals), 0.0001);

    $esc = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    $fmt = fn($x) => rtrim(rtrim(number_format($x, 1), '0'), '.');

    $o = [];
    $o[] = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $W . ' ' . $H . '" width="' . $W . '" height="' . $H . '" role="img">';
    $o[] = '<defs>'
         . '<linearGradient id="cbg2" x1="0" y1="0" x2="0" y2="1">'
         . '<stop offset="0" stop-color="#ffffff"/><stop offset="1" stop-color="' . $esc($bg) . '"/></linearGradient>'
         . '<linearGradient id="hbar" x1="0" y1="0" x2="1" y2="0">'
         . '<stop offset="0" stop-color="' . $esc($ink) . '"/><stop offset="1" stop-color="' . $esc($accent) . '"/></linearGradient>'
         . '</defs>';
    $o[] = '<rect width="' . $W . '" height="' . $H . '" fill="url(#cbg2)"/>';
    $o[] = '<rect x="0" y="0" width="' . $W . '" height="6" fill="' . $esc($accent) . '" fill-opacity=".9"/>';
    $o[] = '<text x="' . $padL . '" y="46" font-family="system-ui,-apple-system,Segoe UI,sans-serif" font-size="25" font-weight="700" fill="'
         . $esc($ink) . '">' . $esc($title) . '</text>';

    $summary = city_chart_compare_summary($series, $def);
    if ($summary !== '') {
        $o[] = '<text x="' . $padL . '" y="74" font-family="system-ui,-apple-system,Segoe UI,sans-serif" font-size="18" font-weight="600" fill="'
             . $esc($accent) . '">' . $esc($summary) . '</text>';
    }

    foreach ($vals as $i => $v) {
        $y = $padT + $i * $rowH;
        $bw = max(($v / $max) * $trackW, 3);
        $isSelf = $i === $hl;

        $o[] = '<text x="' . ($trackX - 16) . '" y="' . ($y + 30) . '" text-anchor="end"'
             . ' font-family="system-ui,-apple-system,Segoe UI,sans-serif" font-size="18" font-weight="'
             . ($isSelf ? '700' : '500') . '" fill="' . $esc($isSelf ? $ink : $muted) . '">'
             . $esc($labels[$i] ?? '') . '</text>';

        // Full-width track, so a short bar still reads as "less of the same thing".
        $o[] = '<rect x="' . $trackX . '" y="' . ($y + 10) . '" width="' . $trackW . '" height="30" rx="15" fill="'
             . $esc($muted) . '" fill-opacity=".10"/>';
        $o[] = '<rect x="' . $trackX . '" y="' . ($y + 10) . '" width="' . round($bw, 1) . '" height="30" rx="15" fill="'
             . ($isSelf ? 'url(#hbar)' : $esc($muted)) . '"' . ($isSelf ? '' : ' fill-opacity=".42"') . '/>';
        $o[] = '<text x="' . round($trackX + $bw + 14, 1) . '" y="' . ($y + 31) . '"'
             . ' font-family="system-ui,-apple-system,Segoe UI,sans-serif" font-size="19" font-weight="700" fill="'
             . $esc($isSelf ? $ink : $muted) . '">' . $esc($fmt($v) . ($unit !== '' ? ' ' . $unit : '')) . '</text>';
    }

    $src = trim((string) ($series['source'] ?? ''));
    if ($src !== '') {
        $o[] = '<text x="' . $padL . '" y="' . ($H - 22) . '" font-family="system-ui,-apple-system,Segoe UI,sans-serif"'
             . ' font-size="14" fill="' . $esc($muted) . '">' . $esc('Source: ' . $src) . '</text>';
    }
    // A framing line around the whole picture. Drawn INSIDE the SVG rather than as CSS on the
    // <img>, so it travels with the image everywhere it is used and never restyles the site's
    // other photos. Inset by half the stroke, because a stroke straddles its path and would
    // otherwise be clipped in half at the canvas edge.
    $o[] = '<rect x="2" y="2" width="' . ($W - 4) . '" height="' . ($H - 4) . '" rx="16" fill="none" stroke="'
         . $esc($accent) . '" stroke-opacity=".55" stroke-width="4"/>';
    $o[] = '</svg>';
    return implode("\n", $o);
}

/**
 * TIMELINE — the years something happened, along a time axis.
 *
 * The bar charts answer "how much". This answers "when, and how recently", which for flood
 * history is the fact a homeowner actually reacts to: "the last major flood here was 2016"
 * lands harder than any average.
 *
 * The most recent event is called out, because recency is the whole point. The axis runs a
 * little past the last event to today, so a long quiet stretch reads as a long quiet stretch
 * rather than the chart simply ending.
 */
function city_chart_svg_timeline(array $series, array $def, string $title, array $theme = []): string
{
    $years = array_map('intval', $series['values']);
    if (!$years) return '';
    sort($years);

    $bg     = $theme['bg']     ?? '#f4f7fa';
    $ink    = $theme['ink']    ?? '#12314f';
    $accent = $theme['accent'] ?? '#1f78d1';
    $muted  = $theme['muted']  ?? '#6b7f95';

    $W = 900; $H = 300;
    $padL = 62; $padR = 62;
    $axisY = 176;

    // Pad the span so the first and last markers are not jammed against the ends, and always
    // run to the current year so the gap since the last event is visible.
    $first = min($years);
    $last  = max((int) date('Y'), max($years));
    $span  = max($last - $first, 1);
    $first -= max(1, (int) round($span * 0.06));
    $span   = max($last - $first, 1);
    $x = fn($yr) => $padL + (($yr - $first) / $span) * ($W - $padL - $padR);

    $esc = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    $o = [];
    $o[] = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $W . ' ' . $H . '" width="' . $W . '" height="' . $H . '" role="img">';
    $o[] = '<defs>'
         . '<linearGradient id="tbg" x1="0" y1="0" x2="0" y2="1">'
         . '<stop offset="0" stop-color="#ffffff"/><stop offset="1" stop-color="' . $esc($bg) . '"/></linearGradient>'
         . '<linearGradient id="tdot" x1="0" y1="0" x2="0" y2="1">'
         . '<stop offset="0" stop-color="' . $esc($accent) . '"/><stop offset="1" stop-color="' . $esc($ink) . '"/></linearGradient>'
         . '</defs>';
    $o[] = '<rect width="' . $W . '" height="' . $H . '" fill="url(#tbg)"/>';
    $o[] = '<rect x="0" y="0" width="' . $W . '" height="6" fill="' . $esc($accent) . '" fill-opacity=".9"/>';

    $o[] = '<text x="' . $padL . '" y="52" font-family="system-ui,-apple-system,Segoe UI,sans-serif"'
         . ' font-size="25" font-weight="700" fill="' . $esc($ink) . '">' . $esc($title) . '</text>';

    $mostRecent = max($years);
    $o[] = '<text x="' . $padL . '" y="78" font-family="system-ui,-apple-system,Segoe UI,sans-serif"'
         . ' font-size="17" font-weight="700" fill="' . $esc($accent) . '">Most recent: ' . $mostRecent . '</text>';

    // The axis, with the two ends dated so the span is readable at a glance.
    $o[] = '<line x1="' . $padL . '" y1="' . $axisY . '" x2="' . ($W - $padR) . '" y2="' . $axisY
         . '" stroke="' . $esc($muted) . '" stroke-opacity=".35" stroke-width="2"/>';
    foreach ([$first, $last] as $endYr) {
        $o[] = '<text x="' . round($x($endYr), 1) . '" y="' . ($axisY + 30) . '" text-anchor="middle"'
             . ' font-family="system-ui,-apple-system,Segoe UI,sans-serif" font-size="14" fill="'
             . $esc($muted) . '">' . $endYr . '</text>';
    }

    // Markers. Labels alternate above and below the line so close years stay readable.
    foreach ($years as $i => $yr) {
        $px  = $x($yr);
        $isLast = ($yr === $mostRecent);
        $up  = ($i % 2 === 0);
        $ly  = $up ? $axisY - 22 : $axisY + 52;
        $o[] = '<line x1="' . round($px, 1) . '" y1="' . ($axisY - ($up ? 14 : 0)) . '" x2="' . round($px, 1)
             . '" y2="' . ($axisY + ($up ? 0 : 14)) . '" stroke="' . $esc($accent) . '" stroke-opacity=".45" stroke-width="1.5"/>';
        $o[] = '<circle cx="' . round($px, 1) . '" cy="' . $axisY . '" r="' . ($isLast ? 9 : 6.5) . '" fill="url(#tdot)"/>';
        if ($isLast) {
            $o[] = '<circle cx="' . round($px, 1) . '" cy="' . $axisY . '" r="15" fill="none" stroke="'
                 . $esc($accent) . '" stroke-opacity=".45" stroke-width="2"/>';
        }
        $o[] = '<text x="' . round($px, 1) . '" y="' . $ly . '" text-anchor="middle"'
             . ' font-family="system-ui,-apple-system,Segoe UI,sans-serif" font-size="' . ($isLast ? 18 : 16)
             . '" font-weight="' . ($isLast ? 700 : 600) . '" fill="' . $esc($ink) . '">' . $yr . '</text>';
    }

    if (trim($series['source']) !== '') {
        $o[] = '<text x="' . $padL . '" y="' . ($H - 18) . '" font-family="system-ui,-apple-system,Segoe UI,sans-serif"'
             . ' font-size="14" fill="' . $esc($muted) . '">Source: ' . $esc($series['source']) . '</text>';
    }

    // Same framing line as the other chart types. NUMERIC entities only — SVG is XML.
    $o[] = '<rect x="2" y="2" width="' . ($W - 4) . '" height="' . ($H - 4) . '" rx="16" fill="none" stroke="'
         . $esc($accent) . '" stroke-opacity=".55" stroke-width="4"/>';
    $o[] = '</svg>';
    return implode("\n", $o);
}
