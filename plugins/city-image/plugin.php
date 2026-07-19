<?php
/**
 * City Image plugin.
 *
 * Auto-sources a representative scenic photo for the site's city from Wikimedia
 * Commons / Wikipedia, self-hosts it as a resized webp, and exposes it as
 * render-time tokens:
 *
 *   {city_image}         — token resolving to the local image path (drop into any
 *                          photo field, e.g. map_info mi_info_photo)
 *   {city_image_alt}     — token, SEO alt text
 *   {city_image_credit}  — token, CC attribution string
 *
 * ── How the value gets populated (two paths, same result) ──────────────────
 *
 *   1. AUTOMATIC (preferred) — the multisite generator calls this plugin's CLI
 *      (cli.php → city_image_fetch_for) once per city during generation and
 *      caches the result. See generate.py → sync_city_images() (Step 1c). The
 *      image is stored per-city in cities.json and mirrored into site_vars, so
 *      {city_image} resolves at render with no manual step. Fetch-once: a city
 *      that already has an image is skipped unless you force a refresh.
 *
 *   2. MANUAL OVERRIDE — the admin panel (panel.php) fetches on demand and
 *      writes city_image* straight into $data['site_vars']. Useful when the
 *      automatic fetch found no good image and you want to re-fetch or swap it.
 *
 * Either way the tokens below read the cached values out of site_vars. The fetch
 * logic itself lives in fetch.php (pure functions, no side effects).
 *
 * NOTE: the old [city_image] block shortcode was removed (0 uses across all
 * sites). Use the {city_image} token in a photo field or Custom HTML instead.
 */

require_once __DIR__ . '/fetch.php';

register_plugin(
    'city_image',
    'City Image',
    'Auto-fetches a scenic photo of your city from Wikimedia during generation and exposes it via the {city_image} token (with SEO alt text + CC credit). Fetched once per city and cached.',
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
