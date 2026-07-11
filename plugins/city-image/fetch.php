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

if (!function_exists('ms_convert_bin') && defined('BASE_DIR')) {
    @require_once BASE_DIR . '/includes/multisite/image_overlay.php';   // ImageMagick locator
}

// Wikimedia asks every client to send a descriptive User-Agent.
if (!defined('CITY_IMAGE_UA')) define('CITY_IMAGE_UA', 'HomepageBuilder-CityImage/1.0 (site generator; contact admin)');

/**
 * GET a URL with the Wikimedia-required UA. Returns body string, or '' on failure.
 * Retries with backoff on transient failures / 429 throttling.
 */
function city_image_http(string $url, int $tries = 3): string {
    for ($i = 0; $i < $tries; $i++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 12,
            CURLOPT_USERAGENT      => CITY_IMAGE_UA,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body !== false && $code >= 200 && $code < 300) return (string)$body;
        if ($i < $tries - 1) usleep((int)(300000 * ($i + 1)));   // 0.3s, 0.6s backoff
    }
    return '';
}

/** JSON GET helper — returns decoded array or []. */
function city_image_api(string $url): array {
    $raw = city_image_http($url);
    if ($raw === '') return [];
    $d = json_decode($raw, true);
    return is_array($d) ? $d : [];
}

/**
 * The Wikipedia lead image for "{City}, {State}". Returns ['file'=>'File:..','url'=>original]
 * or null when the article has no page image.
 */
function city_image_wikipedia_lead(string $city, string $state): ?array {
    // redirects=1 so "San Antonio, Texas" (a redirect) resolves to the real "San Antonio"
    // article and its lead image; without it, redirect-titled cities return no pageimage.
    $wp = city_image_api('https://en.wikipedia.org/w/api.php?action=query&format=json&redirects=1'
        . '&prop=pageimages&piprop=original|name&titles=' . rawurlencode("$city, $state"));
    foreach (($wp['query']['pages'] ?? []) as $pg) {
        $src = $pg['original']['source'] ?? '';
        if (!empty($pg['pageimage']) && $src !== '') {
            return ['file' => 'File:' . $pg['pageimage'], 'url' => $src];
        }
    }
    return null;
}

/** License + author + dimensions for one Commons File: title (for attribution). */
function city_image_commons_meta(string $fileTitle): array {
    $res = city_image_api('https://commons.wikimedia.org/w/api.php?action=query&format=json'
        . '&prop=imageinfo&iiprop=' . rawurlencode('url|size|extmetadata')
        . '&titles=' . rawurlencode($fileTitle));
    foreach (($res['query']['pages'] ?? []) as $pg) {
        $ii = $pg['imageinfo'][0] ?? null;
        if (!$ii) continue;
        $md = $ii['extmetadata'] ?? [];
        $strip = fn($h) => trim(preg_replace('/\s+/', ' ', strip_tags($h ?? '')));
        return [
            'title'      => $pg['title'] ?? $fileTitle,
            'width'      => (int)($ii['width'] ?? 0),
            'height'     => (int)($ii['height'] ?? 0),
            'descurl'    => $ii['descriptionurl'] ?? '',
            'artist'     => $strip($md['Artist']['value'] ?? ''),
            'license'    => $strip($md['LicenseShortName']['value'] ?? ''),
        ];
    }
    return ['title' => $fileTitle, 'width' => 0, 'height' => 0, 'descurl' => '', 'artist' => '', 'license' => ''];
}

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
    $bytes = city_image_http($srcUrl);
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

    $lead = city_image_wikipedia_lead($city, $state);
    if (!$lead) return null;

    $meta = city_image_commons_meta($lead['file']);

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
