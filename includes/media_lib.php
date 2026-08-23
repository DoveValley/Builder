<?php
/**
 * includes/media_lib.php — the media library's storage + image-processing core.
 *
 * Extracted from admin/media_api.php so more than one endpoint can use it without
 * duplicating the paths or the webp encoder. media_api.php and picdrop_api.php both
 * require this; neither redefines any of it.
 *
 * Defines MEDIA_DIR / MEDIA_JSON / MAX_WIDTH (guarded), so requiring this file is
 * enough to have the per-site media paths available.
 */

require_once __DIR__ . '/../config.php';
// ms_convert_bin() — the ImageMagick locator the multisite pipeline already uses. The
// web SAPI's PATH is minimal, so the binary has to be found by absolute path.
require_once __DIR__ . '/multisite/image_overlay.php';

// Per-site paths when a site is active; root-level for single-site installs.
// Guarded so an endpoint that already defined them keeps its own values.
if (!defined('MEDIA_DIR')) {
    $_mediaSiteRoot = (ACTIVE_SITE_DIR !== '' ? ACTIVE_SITE_DIR : BASE_DIR);
    define('MEDIA_DIR',  $_mediaSiteRoot . '/uploads/media/');
    define('MEDIA_JSON', $_mediaSiteRoot . '/data/media.json');
    unset($_mediaSiteRoot);
}
if (!defined('MAX_WIDTH')) define('MAX_WIDTH', 1800);

/**
 * Where full-size originals are kept so a picture can be re-cropped later.
 *
 * DELIBERATELY OUTSIDE uploads/. The static build copies that whole tree to the live
 * site recursively (gen_copy_dir in static_build.php), so an original stored there
 * would be published — megabytes per deploy, full-size files publicly reachable. It
 * would also be deleted: ms_prune_unreferenced_uploads() removes any image no page
 * points at, and an original is unreferenced by definition. Nothing serves this path
 * and nothing copies it.
 */
if (!defined('ORIGINALS_DIR')) {
    define('ORIGINALS_DIR', (ACTIVE_SITE_DIR !== '' ? ACTIVE_SITE_DIR : BASE_DIR) . '/_originals/');
}

/**
 * Create the originals folder with a deny rule inside it.
 *
 * It sits under the webroot, so without this anyone who guessed the path could pull
 * full-size sources for the whole fleet straight out of the browser. The adjuster
 * reads them through picdrop_api.php, which is behind the admin session; nothing
 * needs the raw path.
 */
function media_originals_dir(): ?string {
    if (!is_dir(ORIGINALS_DIR) && !@mkdir(ORIGINALS_DIR, 0775, true)) return null;
    $deny = ORIGINALS_DIR . '.htaccess';
    if (!is_file($deny)) {
        @file_put_contents($deny, "Require all denied\n<IfModule !mod_authz_core.c>\n  Deny from all\n</IfModule>\n");
    }
    return ORIGINALS_DIR;
}

function media_load(): array {
    if (!file_exists(MEDIA_JSON)) return [];
    $d = json_decode(file_get_contents(MEDIA_JSON), true);
    return is_array($d) ? $d : [];
}

function media_save(array $items): void {
    $json = json_encode(array_values($items), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    $tmp  = MEDIA_JSON . '.tmp.' . getmypid();
    if (file_put_contents($tmp, $json) !== false) {
        rename($tmp, MEDIA_JSON);
    } else {
        @unlink($tmp);
    }
}

/* Append one item to the library index. Returns the item as stored. */
function media_register(array $item): array {
    $items   = media_load();
    $items[] = $item;
    media_save($items);
    return $item;
}

/* Decode any supported upload into a GD resource. Returns false on failure. */
function media_imagecreate(string $path, string $mime) {
    return match ($mime) {
        'image/jpeg' => @imagecreatefromjpeg($path),
        'image/png'  => @imagecreatefrompng($path),
        'image/webp' => @imagecreatefromwebp($path),
        'image/gif'  => @imagecreatefromgif($path),
        default      => false,
    };
}

/**
 * Run an ImageMagick conversion. Returns true only if a non-empty file came out.
 *
 * GD IS NOT INSTALLED ON THIS BOX — there is no gd.so for any SAPI. Every function
 * here that reached for GD was silently falling through to a plain copy(), which
 * meant the Media tab's "auto-optimized to WebP on save" wrote the original JPEG or
 * PNG bytes under a .webp filename. Existing library files are all genuine WebP only
 * because they came from the multisite pipeline, which has always shelled out to
 * ImageMagick. So does this now.
 */
function media_magick(string $src, string $dest, array $ops): bool {
    $bin = ms_convert_bin();
    if ($bin === null) return false;

    $cmd = escapeshellarg($bin) . ' ' . escapeshellarg($src . '[0]') . ' '
         . implode(' ', $ops) . ' -quality 82 ' . escapeshellarg($dest) . ' 2>&1';
    @shell_exec($cmd);

    if (!is_file($dest) || filesize($dest) === 0) { @unlink($dest); return false; }
    return true;
}

/* Downscale to MAX_WIDTH if wider, then write webp. Aspect ratio is preserved. */
function img_optimize(string $tmp, string $dest, string $mime): bool {
    if (!extension_loaded('gd')) {
        // "1800x>" only ever shrinks — a narrower source is left at its own size.
        if (media_magick($tmp, $dest, ['-resize', escapeshellarg(MAX_WIDTH . 'x>')])) return true;
        return copy($tmp, $dest);
    }
    $src = media_imagecreate($tmp, $mime);
    if (!$src) return copy($tmp, $dest);

    $ow = imagesx($src); $oh = imagesy($src);
    if ($ow > MAX_WIDTH) {
        $nw = MAX_WIDTH;
        $nh = (int) round($oh * MAX_WIDTH / $ow);
        $dst = imagecreatetruecolor($nw, $nh);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $ow, $oh);
        imagedestroy($src);
        $src = $dst;
    }
    $ok = imagewebp($src, $dest, 82);
    imagedestroy($src);
    return $ok;
}

/**
 * Fit an image to exact target dimensions and write it as webp.
 *
 * Scales so the source COVERS the target, then centre-crops the overflow — the
 * result always fills the slot with no letterboxing and no distortion. When the
 * two aspect ratios are within $tolerance the crop is skipped and it is a plain
 * resize, so a same-shape replacement never loses edge pixels to rounding.
 *
 * Returns an [ok, note] pair; the note describes what happened, for the UI.
 */
function img_fit_to(string $tmp, string $dest, string $mime, int $tw, int $th, float $tolerance = 0.02): array {
    if ($tw < 1 || $th < 1) return [img_optimize($tmp, $dest, $mime), 'no target size — optimised only'];

    // getimagesize() is core, not GD, so the source dimensions are available either way.
    [$ow, $oh] = @getimagesize($tmp) ?: [0, 0];
    if ($ow < 1 || $oh < 1) return [copy($tmp, $dest), 'could not read source size — copied as-is'];

    $srcAspect = $ow / $oh;
    $tgtAspect = $tw / $th;
    $cropped   = abs($srcAspect - $tgtAspect) / $tgtAspect > $tolerance;

    $note = $cropped
        ? sprintf('%d×%d → %d×%d, centre-cropped to match the slot', $ow, $oh, $tw, $th)
        : sprintf('%d×%d → %d×%d, resized (same shape)', $ow, $oh, $tw, $th);

    if (!extension_loaded('gd')) {
        // "WxH^" scales so the image COVERS the box; -extent with centre gravity then
        // trims the overflow. Same result as the GD branch below.
        $ok = media_magick($tmp, $dest, [
            '-resize', escapeshellarg($tw . 'x' . $th . '^'),
            '-gravity', 'center',
            '-extent', escapeshellarg($tw . 'x' . $th),
        ]);
        if ($ok) return [true, $note];
        return [copy($tmp, $dest), 'could not resize — copied at its own size'];
    }

    $src = media_imagecreate($tmp, $mime);
    if (!$src) return [copy($tmp, $dest), 'could not decode — copied as-is'];

    // Source rectangle to sample from: the whole image, or the centred cover-crop.
    $sx = 0; $sy = 0; $sw = $ow; $sh = $oh;
    if ($cropped) {
        if ($srcAspect > $tgtAspect) {          // too wide — trim left/right
            $sw = (int) round($oh * $tgtAspect);
            $sx = (int) round(($ow - $sw) / 2);
        } else {                                 // too tall — trim top/bottom
            $sh = (int) round($ow / $tgtAspect);
            $sy = (int) round(($oh - $sh) / 2);
        }
    }

    $dst = imagecreatetruecolor($tw, $th);
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
    imagecopyresampled($dst, $src, 0, 0, $sx, $sy, $tw, $th, $sw, $sh);
    imagedestroy($src);

    $ok = imagewebp($dst, $dest, 82);
    imagedestroy($dst);

    return [$ok, $note];
}

/**
 * Place a picture into a slot at a given zoom and offset, and write it as webp.
 *
 * ONE model for both directions, because the split version could only ever crop:
 *
 *   zoom 1        "cover" — fills the slot, overflow trimmed. Exactly what a plain
 *                 drop produces, so opening the adjuster changes nothing.
 *   zoom > 1      tighter crop.
 *   zoom < 1      the picture shrinks inside the slot and the remainder is filled,
 *                 down to img_zoom_min() where the WHOLE picture is visible.
 *
 * The old version clamped zoom at 1, which meant a tall photo could never be shown
 * whole in a wide slot — there was no way to say "show all of it and pad the sides".
 *
 * ImageMagick does both with one -resize plus one -extent: a viewport bigger than the
 * scaled image pads it, a smaller one crops it. No branch, so the two cases cannot
 * drift apart.
 *
 * @return array{0:bool,1:string} ok, and a note for the UI
 */
function img_place_geometry(int $ow, int $oh, int $tw, int $th, float $zoom, float $fx, float $fy): array {
    $zoom = max(img_zoom_min($ow, $oh, $tw, $th), min(8.0, $zoom));
    $fx   = max(0.0, min(1.0, $fx));
    $fy   = max(0.0, min(1.0, $fy));

    $scale = max($tw / $ow, $th / $oh) * $zoom;      // zoom 1 == cover
    $nw    = max(1, (int) round($ow * $scale));
    $nh    = max(1, (int) round($oh * $scale));

    // One formula for pan and pad: negative when the picture overflows the slot,
    // positive when it sits inside it.
    return [$nw, $nh, (int) round($fx * ($tw - $nw)), (int) round($fy * ($th - $nh))];
}

/** The zoom at which the whole picture is visible — contain, relative to cover. */
function img_zoom_min(int $ow, int $oh, int $tw, int $th): float {
    if ($ow < 1 || $oh < 1 || $tw < 1 || $th < 1) return 1.0;
    $cover   = max($tw / $ow, $th / $oh);
    $contain = min($tw / $ow, $th / $oh);
    return $cover > 0 ? round($contain / $cover, 4) : 1.0;
}

function img_place_to(string $src, string $dest, int $tw, int $th,
                      float $zoom, float $fx, float $fy, string $fill = 'white'): array {
    [$ow, $oh] = @getimagesize($src) ?: [0, 0];
    if ($ow < 1 || $oh < 1) return [false, 'could not read the source image'];
    if ($tw < 1 || $th < 1) return [false, 'the slot has no size to fit'];

    // Only ever a name we chose. This reaches a shell argument.
    $fills = ['white' => 'white', 'black' => 'black', 'none' => 'none'];
    $bg    = $fills[$fill] ?? 'white';

    [$nw, $nh, $px, $py] = img_place_geometry($ow, $oh, $tw, $th, $zoom, $fx, $fy);

    $ok = media_magick($src, $dest, [
        '-resize',    escapeshellarg($nw . 'x' . $nh . '!'),
        '-background', escapeshellarg($bg),
        '-gravity',   'none',
        // A viewport offset by -px/-py: bigger than the image pads, smaller crops.
        '-extent',    escapeshellarg(sprintf('%dx%d%+d%+d', $tw, $th, -$px, -$py)),
    ]);
    if (!$ok) return [false, 'the image could not be written'];

    $note = ($nw < $tw || $nh < $th)
        ? sprintf('whole picture shown at %.2f×, padded %s', $zoom, $bg === 'none' ? 'transparent' : $bg)
        : sprintf('%d×%d source at %.2f× → %d×%d', $ow, $oh, $zoom, $tw, $th);
    return [true, $note];
}
