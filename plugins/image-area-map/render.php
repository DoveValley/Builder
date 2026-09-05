<?php
/**
 * Writes the diagram into the site's uploads and returns its path.
 *
 * SVG is the source of truth; a WebP is rasterised beside it and that is what the page uses.
 * Reason: an <img> pointing at a WebP is an ordinary indexable image that can earn traffic
 * from Google Images, while an inline SVG mostly is not. The SVG is kept because it is tiny,
 * scales, and can be re-rasterised at any size later without redrawing.
 *
 * Idempotent: a city whose diagram already exists is skipped unless $force. Ten sites covering
 * the same city therefore draw it once.
 */

require_once __DIR__ . '/draw.php';

/** Where a city's diagram lives inside a site. */
function city_map_paths(string $siteDir, array $city): array
{
    $slug = trim((string) ($city['city_slug'] ?? ''));
    if ($slug === '') {
        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-',
            trim(($city['city'] ?? '') . ' ' . ($city['SS'] ?? ''))));
    }
    $rel = 'uploads/media/service-area-map-' . trim($slug, '-');
    return ['svg' => $siteDir . '/' . $rel . '.svg', 'webp' => $siteDir . '/' . $rel . '.webp', 'rel' => $rel];
}

/**
 * @return array{path:string,alt:string,drawn:bool}|null  null = not enough data to draw
 */
function city_map_render(string $siteDir, array $city, array $theme = [], bool $force = false): ?array
{
    if (trim((string) ($city['city'] ?? '')) === '') return null;
    // Nothing to show around the city means the diagram would be a lone dot — worse than no
    // image. EITHER tier is enough: a city with no named areas but a list of towns it serves
    // still makes a useful picture, and gating on neighbourhoods alone silently suppressed it.
    if (!array_filter((array) ($city['neighborhoods'] ?? [])) && !city_map_towns($city)) return null;

    $p = city_map_paths($siteDir, $city);
    $alt = city_map_alt($city);

    $svg = city_map_svg($city, $theme);
    if ($svg === '') return null;

    // Cache on the DRAWING, not merely on the file existing. Checking is_file() alone meant a
    // city that gained new data kept its old picture forever, because the path is keyed on the
    // city slug and never changes. Comparing the SVG is cheap — it is pure string building —
    // and it still skips the expensive rasterise whenever nothing has actually changed, so two
    // sites covering the same city draw it once.
    if (!$force && is_file($p['webp']) && is_file($p['svg']) && @file_get_contents($p['svg']) === $svg) {
        return ['path' => '/' . $p['rel'] . '.webp', 'alt' => $alt, 'drawn' => false];
    }
    if (!is_dir(dirname($p['svg'])) && !@mkdir(dirname($p['svg']), 0775, true) && !is_dir(dirname($p['svg']))) return null;
    if (@file_put_contents($p['svg'], $svg) === false) return null;

    if (!city_map_rasterise($p['svg'], $p['webp'])) {
        // Rasterising failed (no converter on this box). The SVG is still valid and usable,
        // so return that rather than nothing — a diagram in the wrong format beats no diagram.
        return ['path' => '/' . $p['rel'] . '.svg', 'alt' => $alt, 'drawn' => true];
    }
    return ['path' => '/' . $p['rel'] . '.webp', 'alt' => $alt, 'drawn' => true];
}

/** SVG → WebP. Uses whichever converter the box has; returns false if none does. */
function city_map_rasterise(string $svgPath, string $webpPath): bool
{
    // ms_convert_bin() is the multisite build's own ImageMagick locator — reuse it rather
    // than guessing at a path, so this works wherever the image pipeline already works.
    $bin = function_exists('ms_convert_bin') ? ms_convert_bin() : null;
    if ($bin) {
        $cmd = escapeshellarg($bin) . ' -background none ' . escapeshellarg($svgPath)
             . ' -quality 88 ' . escapeshellarg($webpPath) . ' 2>/dev/null';
        @exec($cmd, $out, $code);
        if ($code === 0 && is_file($webpPath) && filesize($webpPath) > 0) return true;
    }
    foreach (['rsvg-convert', 'inkscape'] as $alt) {
        $which = trim((string) @shell_exec('command -v ' . escapeshellarg($alt) . ' 2>/dev/null'));
        if ($which === '') continue;
        $png = $webpPath . '.png';
        $cmd = $alt === 'rsvg-convert'
            ? escapeshellarg($which) . ' -o ' . escapeshellarg($png) . ' ' . escapeshellarg($svgPath)
            : escapeshellarg($which) . ' --export-type=png --export-filename=' . escapeshellarg($png) . ' ' . escapeshellarg($svgPath);
        @exec($cmd . ' 2>/dev/null', $o2, $c2);
        if ($c2 === 0 && is_file($png) && function_exists('imagewebp')) {
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
