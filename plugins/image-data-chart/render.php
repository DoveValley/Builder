<?php
/**
 * Writes a chart into the site's uploads and returns its path + alt text.
 *
 * Same shape as the city-map plugin: SVG is the source of truth, a WebP is rasterised beside
 * it and that is what the page uses, because an <img> pointing at a WebP is an ordinary
 * indexable image while an inline SVG mostly is not.
 *
 * Drawn once per city per chart and cached, so ten sites covering one city draw it once.
 */

require_once __DIR__ . '/charts.php';
require_once __DIR__ . '/draw.php';

function city_chart_paths(string $siteDir, array $city, array $def): array
{
    $slug = trim((string) ($city['city_slug'] ?? ''));
    if ($slug === '') {
        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', trim(($city['city'] ?? '') . ' ' . ($city['SS'] ?? ''))));
    }
    $rel = 'uploads/media/chart-' . $def['id'] . '-' . trim($slug, '-');
    return ['svg' => $siteDir . '/' . $rel . '.svg', 'webp' => $siteDir . '/' . $rel . '.webp', 'rel' => $rel];
}

/**
 * @return array{path:string,alt:string,drawn:bool}|null
 *   null when this city has no data for this chart — which is a normal outcome, not an error.
 *   A city without figures gets no chart rather than a chart drawn from defaults.
 */
function city_chart_render(string $siteDir, array $city, array $def, array $theme = [], bool $force = false): ?array
{
    $series = city_chart_series($def, $city);
    if ($series === null) return null;

    $title = city_chart_text((string) ($def['title'] ?? ($def['name'] ?? '')), $city, $def, $series);
    $alt   = city_chart_text((string) ($def['alt'] ?? ($def['title'] ?? '')), $city, $def, $series);

    $p = city_chart_paths($siteDir, $city, $def);
    if (!$force && is_file($p['webp'])) {
        return ['path' => '/' . $p['rel'] . '.webp', 'alt' => $alt, 'drawn' => false];
    }

    $svg = city_chart_svg($series, $def, $title, $theme);
    if ($svg === '') return null;
    if (!is_dir(dirname($p['svg'])) && !@mkdir(dirname($p['svg']), 0775, true) && !is_dir(dirname($p['svg']))) return null;
    if (@file_put_contents($p['svg'], $svg) === false) return null;

    if (!city_chart_rasterise($p['svg'], $p['webp'])) {
        // No converter on this box. The SVG is valid and usable, so ship that rather than
        // nothing — a chart in the wrong format beats no chart.
        return ['path' => '/' . $p['rel'] . '.svg', 'alt' => $alt, 'drawn' => true];
    }
    return ['path' => '/' . $p['rel'] . '.webp', 'alt' => $alt, 'drawn' => true];
}

/** SVG to WebP, using whatever the box has. Mirrors the city-map plugin deliberately. */
function city_chart_rasterise(string $svgPath, string $webpPath): bool
{
    $bin = function_exists('ms_convert_bin') ? ms_convert_bin() : null;
    if ($bin) {
        @exec(escapeshellarg($bin) . ' -background none ' . escapeshellarg($svgPath)
            . ' -quality 88 ' . escapeshellarg($webpPath) . ' 2>/dev/null', $o, $c);
        if ($c === 0 && is_file($webpPath) && filesize($webpPath) > 0) return true;
    }
    $which = trim((string) @shell_exec('command -v rsvg-convert 2>/dev/null'));
    if ($which !== '' && function_exists('imagewebp')) {
        $png = $webpPath . '.png';
        @exec(escapeshellarg($which) . ' -o ' . escapeshellarg($png) . ' ' . escapeshellarg($svgPath) . ' 2>/dev/null', $o2, $c2);
        if ($c2 === 0 && is_file($png)) {
            $im = @imagecreatefrompng($png);
            if ($im) {
                imagepalettetotruecolor($im);
                $ok = @imagewebp($im, $webpPath, 88);
                imagedestroy($im);
                @unlink($png);
                if ($ok) return true;
            }
        }
        @unlink($png);
    }
    return false;
}
