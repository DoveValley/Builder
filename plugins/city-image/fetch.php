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

/** Every US state + DC, full name and postal abbreviation, for geo-tail stripping. */
function city_image_state_words(): array {
    static $w = null;
    if ($w !== null) return $w;
    $states = [
        'Alabama' => 'AL', 'Alaska' => 'AK', 'Arizona' => 'AZ', 'Arkansas' => 'AR',
        'California' => 'CA', 'Colorado' => 'CO', 'Connecticut' => 'CT', 'Delaware' => 'DE',
        'Florida' => 'FL', 'Georgia' => 'GA', 'Hawaii' => 'HI', 'Idaho' => 'ID',
        'Illinois' => 'IL', 'Indiana' => 'IN', 'Iowa' => 'IA', 'Kansas' => 'KS',
        'Kentucky' => 'KY', 'Louisiana' => 'LA', 'Maine' => 'ME', 'Maryland' => 'MD',
        'Massachusetts' => 'MA', 'Michigan' => 'MI', 'Minnesota' => 'MN', 'Mississippi' => 'MS',
        'Missouri' => 'MO', 'Montana' => 'MT', 'Nebraska' => 'NE', 'Nevada' => 'NV',
        'New Hampshire' => 'NH', 'New Jersey' => 'NJ', 'New Mexico' => 'NM', 'New York' => 'NY',
        'North Carolina' => 'NC', 'North Dakota' => 'ND', 'Ohio' => 'OH', 'Oklahoma' => 'OK',
        'Oregon' => 'OR', 'Pennsylvania' => 'PA', 'Rhode Island' => 'RI', 'South Carolina' => 'SC',
        'South Dakota' => 'SD', 'Tennessee' => 'TN', 'Texas' => 'TX', 'Utah' => 'UT',
        'Vermont' => 'VT', 'Virginia' => 'VA', 'Washington' => 'WA', 'West Virginia' => 'WV',
        'Wisconsin' => 'WI', 'Wyoming' => 'WY', 'District of Columbia' => 'DC',
    ];
    // Longest first so "West Virginia" is matched before "Virginia".
    $names = array_keys($states);
    usort($names, fn($a, $b) => strlen($b) <=> strlen($a));
    return $w = ['names' => $names, 'abbrs' => array_values($states)];
}

/**
 * Derive SEO alt text from a Commons file title + city context.
 * "File:Downtown Lufkin, TX IMG 3942.JPG" -> "Downtown Lufkin, TX"
 * "File:Angelina County Courthouse, Lufkin, Texas ...jpg" -> "Angelina County Courthouse in Lufkin, TX"
 *
 * Commons filenames are not captions: they carry upload dates, photographer credits and
 * full state names that read as noise in an alt attribute. All four of these shipped on
 * real fleet domains before the rules below existed —
 *   "2024-4-12-Falls Park Waterfall Greenville South Carolina by Yousef AbdulHusain, SC"
 *   "Lewisville August 2019 13, TX"
 *   "Visalia Tranist Center 2011, CA"
 *   "Harrisburg nc, NC"
 * The photographer belongs in city_image_credit(), which already renders it separately.
 *
 * Re-derived on every build — _fetch_city_image()'s fetch-once cache keys on city_image,
 * which is only ever populated in a throwaway working clone — so improving this repairs
 * every live domain on its next rebuild, with no stored values to migrate.
 */
function city_image_alt_text(string $title, string $city, string $ss): string {
    $states = city_image_state_words();
    $nameRe = implode('|', array_map(fn($n) => preg_quote($n, '/'), $states['names']));

    $s = preg_replace('/^File:/i', '', $title);
    $s = preg_replace('/\.(jpe?g|png|webp|tiff?)$/i', '', $s);
    $s = preg_replace('/\s*\([^)]*\)/', '', $s);                 // drop "(36514799203)" etc.
    $s = preg_replace('/[_]+/', ' ', $s);
    $s = preg_replace('/\b(IMG|DSC|LCCN|LOC)[\s_]?\d+\b/i', '', $s);   // camera / archive ids
    $s = preg_replace('/\s+\d{6,}\b/', '', $s);                  // trailing numeric ids

    // Upload-date prefix: "2024-4-12-Falls Park…", "2019.07.04 Something".
    $s = preg_replace('/^\s*\d{4}[-.\/]\d{1,2}[-.\/]\d{1,2}\s*[-–—]?\s*/', '', $s);
    // Photographer credit: "… by Yousef AbdulHusain". Anchored to the END, and only
    // applied when at least two words of subject survive — "Painting by Numbers Museum"
    // would otherwise be stripped to the bare word "Painting". A real credit always
    // follows a subject; a title that is almost entirely "by …" is not one.
    $deCredited = preg_replace('/\s+by\s+\p{Lu}[\p{L}.\'-]*(?:\s+\p{Lu}[\p{L}.\'-]*)*\s*$/u', '', $s);
    if ($deCredited !== $s && count(preg_split('/\s+/', trim($deCredited))) >= 2) $s = $deCredited;
    // Dates anywhere: "August 2019 13", "Sept. 2011", bare years.
    $months = 'January|February|March|April|May|June|July|August|September|October|November|December'
            . '|Jan|Feb|Mar|Apr|Jun|Jul|Aug|Sept|Sep|Oct|Nov|Dec';
    $s = preg_replace('/\b(?:' . $months . ')\.?(?:\s+\d{1,4})*\b/i', ' ', $s);
    $s = preg_replace('/\b(?:19|20)\d{2}\b/', ' ', $s);

    // Drop boilerplate geo tail segments — every state now, not just Texas.
    $geo = '/^\s*(USA|United States|U\.S\.A?\.?|[A-Z][a-z]+ County|' . $nameRe . '|'
         . implode('|', $states['abbrs']) . ')\s*$/i';
    $parts = array_filter(array_map('trim', explode(',', $s)),
        fn($p) => $p !== '' && !preg_match($geo, $p));
    $subject = trim(implode(', ', array_slice($parts, 0, 2)));

    // A state can also trail without a comma: "Falls Park Waterfall Greenville South Carolina".
    $subject = preg_replace('/\s+(' . $nameRe . ')\s*$/i', '', $subject);
    $subject = trim(preg_replace('/\s+/', ' ', $subject), " -–—,");

    $loc = trim($city . ($ss ? ", $ss" : ''), ', ');
    if ($subject === '') return "View of $loc";

    if (stripos($subject, $city) !== false) {
        // Subject already names the city. A filename-derived subject often ends with a
        // lowercase abbrev ("Harrisburg nc") — normalise that rather than appending a
        // second copy and shipping "Harrisburg nc, NC".
        if ($ss !== '') {
            $fixed = 0;
            $subject = preg_replace_callback(
                '/[\s,]+' . preg_quote($ss, '/') . '\s*$/i',
                fn($m) => ', ' . strtoupper($ss),
                $subject, 1, $fixed
            );
            if (!$fixed) $subject .= ', ' . strtoupper($ss);
        }
        return $subject;
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
