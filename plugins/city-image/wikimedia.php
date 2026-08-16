<?php
/**
 * Wikimedia — Wikipedia article images and Commons file metadata.
 *
 * One module for this service, per CLAUDE.md. These two calls used to sit inside the
 * city-image plugin's fetch.php between the download and resize code, so "how do we
 * ask Wikimedia things" and "how do we make a hero image" were the same file.
 *
 * Wikimedia REQUIRES a descriptive User-Agent and throttles anonymous clients — and
 * that failure arrives as an empty response, not as an error that says so. The UA and
 * the retry live here so no caller can forget them.
 */

require_once __DIR__ . '/../../includes/http_get.php';

if (!defined('WIKIMEDIA_UA')) {
    define('WIKIMEDIA_UA', 'HomepageBuilder-CityImage/1.0 (site generator; contact admin)');
}

/** JSON GET against a Wikimedia API. Returns a decoded array, or [] on any failure. */
function wikimedia_api(string $url): array
{
    return http_get_json($url, ['ua' => WIKIMEDIA_UA, 'timeout' => 30, 'tries' => 3]);
}

/**
 * The Wikipedia lead image for "{City}, {State}". Returns ['file'=>'File:..','url'=>original]
 * or null when the article has no page image.
 */
function wikimedia_lead_image(string $city, string $state): ?array {
    // redirects=1 so "San Antonio, Texas" (a redirect) resolves to the real "San Antonio"
    // article and its lead image; without it, redirect-titled cities return no pageimage.
    $wp = wikimedia_api('https://en.wikipedia.org/w/api.php?action=query&format=json&redirects=1'
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
function wikimedia_commons_meta(string $fileTitle): array {
    $res = wikimedia_api('https://commons.wikimedia.org/w/api.php?action=query&format=json'
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
