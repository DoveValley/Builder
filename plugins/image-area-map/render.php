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
    // 'rel' is where the file LIVES (always uploads/ inside the site dir). 'url' is how a page
    // ASKS for it, which differs by context: the panel serves the site from sites/{id}/uploads/,
    // a built site from /uploads/. UPLOAD_URL already knows which — hardcoding '/uploads/' made
    // the image work in the build and 404 in the panel.
    $url = (defined('UPLOAD_URL') ? UPLOAD_URL : 'uploads/') . 'media/' . basename($rel);
    return ['svg' => $siteDir . '/' . $rel . '.svg', 'webp' => $siteDir . '/' . $rel . '.webp',
            'rel' => $rel, 'url' => $url];
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
    // The diagram is built from the surrounding towns now, so that is the only requirement.
    if (!city_map_towns($city)) return null;

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
        return ['path' => $p['url'] . '.webp', 'alt' => $alt, 'drawn' => false];
    }
    if (!is_dir(dirname($p['svg'])) && !@mkdir(dirname($p['svg']), 0775, true) && !is_dir(dirname($p['svg']))) return null;
    if (@file_put_contents($p['svg'], $svg) === false) return null;

    if (!city_map_rasterise($p['svg'], $p['webp'])) {
        // Rasterising failed (no converter on this box). The SVG is still valid and usable,
        // so return that rather than nothing — a diagram in the wrong format beats no diagram.
        return ['path' => $p['url'] . '.svg', 'alt' => $alt, 'drawn' => true];
    }
    return ['path' => $p['url'] . '.webp', 'alt' => $alt, 'drawn' => true];
}

/**
 * PNG to WebP. ImageMagick first, GD second.
 *
 * Deliberately duplicated from the chart plugin rather than shared: a plugin is the whole unit,
 * and this one must not stop working because the other was removed.
 *
 * Not just GD: imagecreatefrompng()+imagewebp() on rsvg's output produced an all-BLACK image —
 * a perfect PNG and a WebP-shaped void on the page. ImageMagick is reliable on PNG; only its
 * SVG parsing is weak, and that job belongs to rsvg.
 */
function city_map_png_to_webp(string $png, string $webpPath): bool
{
    $bin = function_exists('ms_convert_bin') ? ms_convert_bin() : null;
    if ($bin) {
        @exec(escapeshellarg($bin) . ' ' . escapeshellarg($png) . ' -background white -flatten'
            . ' -quality 88 ' . escapeshellarg($webpPath) . ' 2>/dev/null', $o, $c);
        if ($c === 0 && is_file($webpPath) && filesize($webpPath) > 0) return true;
    }
    if (function_exists('imagewebp')) {
        $im = @imagecreatefrompng($png);
        if ($im) {
            // Flatten onto white before encoding — a transparent background became black.
            $flat = imagecreatetruecolor(imagesx($im), imagesy($im));
            imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
            imagecopy($flat, $im, 0, 0, 0, 0, imagesx($im), imagesy($im));
            $ok = @imagewebp($flat, $webpPath, 88);
            imagedestroy($im);
            imagedestroy($flat);
            if ($ok && is_file($webpPath) && filesize($webpPath) > 0) return true;
        }
    }
    return false;
}

/** SVG → WebP. Uses whichever converter the box has; returns false if none does. */
function city_map_rasterise(string $svgPath, string $webpPath): bool
{
    // rsvg-convert FIRST, because it is a real SVG renderer. ImageMagick's built-in MSVG
    // delegate silently drops gradient fills: the diagram rasterised with a TRANSPARENT
    // background, so on a dark section the dark ink text sat on dark and was unreadable. The
    // picture was there, correct, and invisible. ImageMagick stays as the fallback because it
    // is the one binary the build is guaranteed to have.
    foreach (['rsvg-convert', 'inkscape'] as $alt) {
        $which = trim((string) @shell_exec('command -v ' . escapeshellarg($alt) . ' 2>/dev/null'));
        if ($which === '') continue;
        $png = $webpPath . '.png';
        $cmd = $alt === 'rsvg-convert'
            ? escapeshellarg($which) . ' -o ' . escapeshellarg($png) . ' ' . escapeshellarg($svgPath)
            : escapeshellarg($which) . ' --export-type=png --export-filename=' . escapeshellarg($png) . ' ' . escapeshellarg($svgPath);
        @exec($cmd . ' 2>/dev/null', $o2, $c2);
        // The encode is deliberately NOT GD's: imagewebp() on rsvg's output produced an
        // all-black image, so the diagram was perfect as a PNG and a void as a WebP.
        if ($c2 === 0 && is_file($png) && city_map_png_to_webp($png, $webpPath)) {
            @unlink($png);
            return true;
        }
        @unlink($png);
    }
    // ms_convert_bin() is the multisite build's own ImageMagick locator — reuse it rather than
    // guessing at a path, so this works wherever the image pipeline already works. Last resort:
    // it loses gradients, but a flat-looking diagram beats shipping raw SVG.
    $bin = function_exists('ms_convert_bin') ? ms_convert_bin() : null;
    if ($bin) {
        $cmd = escapeshellarg($bin) . ' -background white -flatten ' . escapeshellarg($svgPath)
             . ' -quality 88 ' . escapeshellarg($webpPath) . ' 2>/dev/null';
        @exec($cmd, $out, $code);
        if ($code === 0 && is_file($webpPath) && filesize($webpPath) > 0) return true;
    }
    return false;
}
