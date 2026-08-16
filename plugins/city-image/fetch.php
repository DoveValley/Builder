<?php
/**
 * City Image plugin — fetch logic (Wikipedia-only).
 *
 * Sources a city's photo from the Wikipedia API: the article's lead image
 * (pageimages, piprop=original) is the canonical, geo-correct, freely-licensed
 * representative image of the place. We self-host it as a resized webp, pull the
 * CC license + author from Wikimedia Commons for attribution, and derive SEO alt
 * text from the file title.
 *
 * Pure functions — including this file has no side effects, so it is safe to load
 * from the plugin, an admin action, or the CLI.
 */

require_once __DIR__ . '/wikimedia.php';        // the Wikipedia/Commons client
require_once __DIR__ . '/../../includes/http_get.php';

if (!function_exists('ms_convert_bin') && defined('BASE_DIR')) {
    @require_once BASE_DIR . '/includes/multisite/image_overlay.php';   // ImageMagick locator
}

/* Wikimedia transport and API calls now live in wikimedia.php — this file is
   about turning a found image into a site asset. */

/**
 * Derive SEO alt text from a Commons file title + city context.
 * "File:Downtown Lufkin, TX IMG 3942.JPG" -> "Downtown Lufkin, TX"
 * "File:Angelina County Courthouse, Lufkin, Texas ...jpg" -> "Angelina County Courthouse in Lufkin, TX"
 */
function city_image_alt_text(string $title, string $city, string $ss): string {
    $s = preg_replace('/^File:/i', '', $title);
    $s = preg_replace('/\.(jpe?g|png|webp|tiff?)$/i', '', $s);
    $s = preg_replace('/\s*\([^)]*\)/', '', $s);                 // drop "(36514799203)" etc.
    $s = preg_replace('/[_]+/', ' ', $s);
    $s = preg_replace('/\b(IMG|DSC|LCCN|LOC)[\s_]?\d+\b/i', '', $s);   // camera / archive ids
    $s = preg_replace('/\s+\d{6,}\b/', '', $s);                  // trailing numeric ids
    // Drop boilerplate geo tail segments.
    $parts = array_filter(array_map('trim', explode(',', $s)), function ($p) {
        return $p !== '' && !preg_match('/^\s*(USA|United States|[A-Z][a-z]+ County|Texas|TX)\s*$/i', $p);
    });
    $subject = trim(implode(', ', array_slice($parts, 0, 2)));
    $subject = trim(preg_replace('/\s+/', ' ', $subject), " -–—,");
    $loc = trim($city . ($ss ? ", $ss" : ''), ', ');
    if ($subject === '') return "View of $loc";
    if (stripos($subject, $city) !== false) {
        // Subject already names the city; append the state abbrev for SEO if absent.
        return ($ss && !preg_match('/,\s*' . preg_quote($ss, '/') . '\b/i', $subject)) ? "$subject, $ss" : $subject;
    }
    return "$subject in $loc";
}

/** Build a "Photo: Author, License" attribution string. */
function city_image_credit(array $c): string {
    $artist  = $c['artist']  ?? '';
    $license = $c['license'] ?? '';
    if ($artist && $license) return "Photo: $artist, $license";
    if ($license)            return "Photo: Wikimedia Commons, $license";
    return 'Photo: Wikimedia Commons';
}

/**
 * Download $srcUrl and resize→webp into $outDirAbs/city-scenic-{citySlug}.webp
 * via ImageMagick. Returns the basename on success, '' on failure.
 */
function city_image_download(string $srcUrl, string $outDirAbs, string $citySlug, int $maxW = 1000): string {
    $bin = function_exists('ms_convert_bin') ? ms_convert_bin() : '/usr/bin/convert';
    if (!$bin) return '';
    // Fetching the image file itself, not the API — but the same UA rule applies, and
    // Wikimedia serves media from upload.wikimedia.org with the same expectations.
    $dl    = http_get($srcUrl, ['ua' => WIKIMEDIA_UA, 'timeout' => 30, 'tries' => 3]);
    $bytes = $dl['ok'] ? $dl['body'] : '';
    if ($bytes === '' || strlen($bytes) < 2048) return '';

    if (!is_dir($outDirAbs)) @mkdir($outDirAbs, 0775, true);
    $ext = preg_match('/\.(jpe?g|png|webp|tiff?)$/i', $srcUrl, $m) ? strtolower($m[1]) : 'jpg';
    $tmp = tempnam(sys_get_temp_dir(), 'cityimg_') . '.' . $ext;
    if (file_put_contents($tmp, $bytes) === false) return '';

    $base = 'city-scenic-' . preg_replace('/[^a-z0-9\-]/', '', strtolower($citySlug)) . '.webp';
    $dest = rtrim($outDirAbs, '/') . '/' . $base;
    // -auto-orient MUST come before -strip: it bakes the EXIF orientation into the
    // pixels, then -strip drops the (now-applied) metadata. Without it, images that
    // rely on an EXIF orientation tag (e.g. shot rotated) render sideways/upside-down
    // because WebP doesn't honour the orientation tag the way the source JPEG did.
    $cmd  = implode(' ', array_map('escapeshellarg', [
        $bin, $tmp . '[0]', '-auto-orient', '-resize', $maxW . 'x>', '-strip', '-quality', '82', $dest,
    ]));
    exec($cmd . ' 2>&1', $o, $rc);
    @unlink($tmp);
    return ($rc === 0 && file_exists($dest)) ? $base : '';
}

/**
 * Full pipeline for one city — ALWAYS the Wikipedia lead image. Returns:
 *   ['path','alt','credit','source','title'] on success, or null when Wikipedia
 *   has no usable lead image for the city.
 *
 * $opts: city, state, ss, city_slug, out_dir (absolute media dir),
 *        store_prefix (path prefix stored in JSON, e.g. "sites/x/uploads/media").
 */
function city_image_fetch_for(array $opts): ?array {
    $city = trim($opts['city'] ?? '');
    if ($city === '') return null;
    $state = trim($opts['state'] ?? '');
    $ss    = trim($opts['ss'] ?? '');
    $slug  = trim($opts['city_slug'] ?? '') ?: strtolower(preg_replace('/[^a-z0-9]+/i', '-', "$city-$ss"));

    $lead = wikimedia_lead_image($city, $state);
    if (!$lead) return null;

    $meta = wikimedia_commons_meta($lead['file']);

    $base = city_image_download($lead['url'], $opts['out_dir'] ?? '', $slug);
    if ($base === '') return null;

    $prefix = rtrim($opts['store_prefix'] ?? '', '/');
    return [
        'path'   => ($prefix ? $prefix . '/' : '') . $base,
        'alt'    => city_image_alt_text($meta['title'], $city, $ss),
        'credit' => city_image_credit($meta),
        'source' => $meta['descurl'],
        'title'  => $meta['title'],
    ];
}
