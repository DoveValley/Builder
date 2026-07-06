<?php
/**
 * City Image plugin.
 *
 * Auto-sources a representative scenic photo for the site's city from Wikimedia
 * Commons / Wikipedia, self-hosts it, and exposes it two ways:
 *
 *   {city_image}         — token resolving to the local image path (drop into any
 *                          photo field, e.g. map_info mi_info_photo)
 *   {city_image_alt}     — token, SEO alt text
 *   {city_image_credit}  — token, CC attribution string
 *   [city_image]         — block shortcode: renders <figure> image + credit caption,
 *                          for use inside a Custom HTML block
 *
 * The value comes entirely from this plugin: the fetch step (admin panel /
 * CLI / multisite generator) writes city_image / city_image_alt /
 * city_image_credit / city_image_source into $data['site_vars'].
 */

require_once __DIR__ . '/fetch.php';

register_plugin(
    'city_image',
    'City Image',
    'Auto-fetches a scenic photo of your city from Wikimedia and exposes it via the {city_image} token and [city_image] shortcode (with SEO alt text + CC credit).',
    '&#127957;',   // 🏝️
    __DIR__
);

// ── Register {city_image}* tokens (read from site_vars, populated by the fetch step) ──
add_hook('shortcode_tokens', function (array $map): array {
    global $data;
    $v = $data['site_vars'] ?? [];
    $map['{city_image}']        = $v['city_image']        ?? '';
    $map['{city_image_alt}']    = $v['city_image_alt']    ?? '';
    $map['{city_image_credit}'] = $v['city_image_credit'] ?? '';
    return $map;
});

// ── [city_image] block shortcode → <figure> with image + credit caption ──
add_hook('shortcode_content', function (string $html, string $pathPrefix = ''): string {
    if (strpos($html, '[city_image]') === false) return $html;
    return str_replace('[city_image]', _city_image_render($pathPrefix), $html);
});

function _city_image_render(string $pathPrefix = ''): string {
    global $data;
    $v      = $data['site_vars'] ?? [];
    $path   = $v['city_image']        ?? '';
    $alt    = $v['city_image_alt']    ?? '';
    $credit = $v['city_image_credit'] ?? '';
    if ($path === '') return '';   // nothing fetched yet — render nothing

    $src = (str_starts_with($path, 'http') || str_starts_with($path, '//'))
        ? $path : $pathPrefix . $path;

    $out  = '<figure class="city-image-figure" style="margin:0;">';
    $out .= '<img src="' . h($src) . '" alt="' . h($alt) . '" class="city-image-photo" loading="lazy"'
          . ' style="width:100%;height:auto;border-radius:12px;display:block;">';
    if ($credit !== '') {
        $out .= '<figcaption class="city-image-credit" style="font-size:12px;color:#888;margin-top:6px;">'
              . h($credit) . '</figcaption>';
    }
    $out .= '</figure>';
    return $out;
}
