<?php
/**
 * Shared site template.
 *
 * Expects the following variables to be set by the including script:
 *   $data          - full site data array (from load_data())
 *   $contentBlocks - array of content blocks for THIS page
 *   $seo           - SEO data for THIS page (meta_description, meta_keywords, schema)
 *   $pageTitle     - <title> text for THIS page (falls back to SITE_TITLE if empty)
 *
 * The header, footer, and theme colors are shared (global) across every page.
 */

/**
 * Never let a browser cache a dynamically rendered page.
 *
 * This is the factory's own view of a site and must always show what the data says
 * right now. Apache sends no Cache-Control for these responses — only ETag and
 * Last-Modified — so a browser applies its own heuristic and can serve the page from
 * disk without ever asking whether it changed. Edits then look like they did not save
 * and fixes look like they did not work, with nothing on screen saying the page is
 * old. That has already cost real debugging time.
 *
 * It also matches what the generated production .htaccess does for the deployed static
 * site (ExpiresByType text/html "access plus 0 seconds"), so preview and production
 * agree instead of differing in a way only one of them reveals.
 *
 * Here rather than in index.php / page.php / blog.php because those three reach this
 * template through seven separate require sites — one would have been missed. Skipped
 * during a static build, where there is no response to send, and guarded on
 * headers_sent() so an already-streaming endpoint cannot warn.
 */
if (!defined('STATIC_BUILD') && PHP_SAPI !== 'cli' && !headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
}

$theme   = $data['theme'];
$header  = apply_shortcodes_to_block($data['header']);
$footer  = apply_shortcodes_to_block($data['footer']);
// Header bar color (nav_bg: 'accent' mode or a hex), resolved once. The visible nav row +
// sticky bars use $navBg; the header container/dropdowns (--color-header-bg) follow it too (option A).
$navBgRaw    = $header['nav_bg'] ?? 'accent';
$navBgIsMode = in_array($navBgRaw, ['accent','header','footer','highlight'], true);
$navBg       = $navBgIsMode ? resolve_color($navBgRaw) : $navBgRaw;
// Only let the header container/dropdowns follow the bar when the bar tracks a theme
// color ("Match brand accent"). An explicit custom hex leaves header_bg untouched, so
// existing sites that set header_bg + a hex nav_bg (e.g. Granite) render unchanged.
if ($navBgIsMode) $theme['header_bg'] = $navBg;
$_hLayout = preg_replace('/[^a-z0-9_]/', '', $header['header_layout'] ?? 'standard');
// Topbar is position:fixed — out of document flow, adds to total fixed area.
$_hHasTopbar     = !empty($header['topbar_text']);
$_hTopbarEst     = $_hHasTopbar ? 36 : 0;
$_hSrBarHeight   = max(48, min(120, (int)($header['sr_bar_height'] ?? 64)));
$_hInitialHeight = match($_hLayout) {
    'single_row' => ($_hSrBarHeight + $_hTopbarEst) . 'px',
    'standard'   => (120 + $_hTopbarEst) . 'px',
    default      => (90 + $_hTopbarEst) . 'px',
};
// {tel} = E.164 tracking number; fall back to stripping display phone
$telHref = resolve_shortcodes('{tel}') ?: preg_replace('/[^0-9+]/', '', $header['phone'] ?? '');

// Per-page keyword for {primary_keyword}/{service} — set before any resolve_shortcodes() below.
$GLOBALS['_page_primary_keyword'] = $seo['primary_keyword'] ?? '';
$pageTitle = resolve_shortcodes(!empty($pageTitle) ? $pageTitle : site_default_title($data));
if (isset($seo['meta_description'])) $seo['meta_description'] = resolve_shortcodes($seo['meta_description']);
if (isset($seo['meta_keywords']))    $seo['meta_keywords']    = resolve_shortcodes($seo['meta_keywords']);
if (isset($seo['og_title']))         $seo['og_title']         = resolve_shortcodes($seo['og_title']);
if (isset($seo['og_description']))   $seo['og_description']   = resolve_shortcodes($seo['og_description']);
// OG image fallback: hero block photo → global site og_image
// $contentBlocks here is still pre-shortcode-resolution (apply_shortcodes_to_block() runs
// later, per-block, in the main render loop below) — a literal uploaded path has no braces
// so this was never visibly wrong before, but a bare-token photo value (e.g. hs_photo set to
// "{city_image}", which the Contact Us page's hero_split uses) was captured unresolved and
// never touched again, shipping the literal "{city_image}" in <meta property="og:image">.
if (empty($seo['og_image'])) {
    foreach ($contentBlocks as $_b) {
        $_t = $_b['type'] ?? '';
        if ($_t === 'hero_split' && !empty($_b['hs_photo']))      { $seo['og_image'] = resolve_shortcodes($_b['hs_photo']);      break; }
        if ($_t === 'hero'       && !empty($_b['hero_bg_image'])) { $seo['og_image'] = resolve_shortcodes($_b['hero_bg_image']); break; }
        if ($_t === 'hero_grid'  && !empty($_b['hg_photo']))      { $seo['og_image'] = resolve_shortcodes($_b['hg_photo']);      break; }
        if ($_t === 'post_meta'  && !empty($_b['featured_image'])){ $seo['og_image'] = resolve_shortcodes($_b['featured_image']); break; }
    }
    if (empty($seo['og_image'])) $seo['og_image'] = $data['seo']['og_image'] ?? '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php
    // Requested as early as possible in <head> — before every other preload/preconnect/meta
    // tag below — so the browser's preload scanner discovers and starts fetching this
    // render-blocking stylesheet first, instead of queued behind them. Same file, same
    // blocking behavior; only the request's position in the document moved.
    //
    // Deliberately NOT async-deferred the way Google Fonts is below, despite that trick
    // removing this same audit's flag there: a font swap only affects text metrics (and
    // even then only with a matched fallback — see GF_FONT_METRICS), but style.css carries
    // this template's actual LAYOUT rules (flex/grid/section structure). Deferring it would
    // paint an unstyled, vertically-stacked page first and then visibly snap into its real
    // layout once it loads — trading an estimated ~150ms "render-blocking" audit flag for a
    // guaranteed, large, real layout shift on every single load. Almost shipped this;
    // reverted before deploying. See css_minify_to() in includes/helpers.php: style.css is
    // regenerated from assets/css/style.src.css automatically whenever the source is newer.
    $mainCssPath  = __DIR__ . '/../assets/css/style.css';
    $mainCssMtime = css_minify_to(__DIR__ . '/../assets/css/style.src.css', $mainCssPath);
    $mainCssHref  = h($assetPathPrefix ?? '') . 'assets/css/style.css?v=' . $mainCssMtime;
    // Per-page critical CSS (includes/css_critical.php) — only a verified subset of block
    // types is eligible; css_critical_for_types() returns null the instant it sees anything
    // outside that subset, and this falls straight back to linking the full $mainCssHref
    // below with no other change needed. See that file's docblock before adding a new type.
    //
    // Linked, NOT inlined — tried inlining this (same trimmed CSS, as a <style> block) and
    // measured it live on gannmoldremediation.com: FCP never moved (2.9s both ways — the
    // round-trip theory didn't pan out in practice), while LCP got WORSE (3.5s -> 3.8s) and
    // Speed Index nearly doubled (2.9s -> 4.4s), reproduced across two separate PSI captures.
    // Likely cause: the hero's background-image is applied lazily by site.js
    // (IntersectionObserver on [data-bg-lazy]), and 63KB of inline CSS text measurably delays
    // the parser reaching that script/getting to a style recalc, costing more than the
    // request round-trip it saved. Reverted — net regression, not worth it. Performance
    // score: 86 linked vs 81 inlined on the same page. Don't re-try this without a real fix
    // for that lazy-background timing first.
    $_criticalTypes = [];
    foreach ($contentBlocks as $_cb) { $_criticalTypes[] = css_critical_block_type($_cb); }
    $_critical = css_critical_for_types(
        $_criticalTypes,
        __DIR__ . '/../assets/css/style.src.css',
        __DIR__ . '/../assets/css/pages'
    );
    if ($_critical !== null) {
        $mainCssHref = h($assetPathPrefix ?? '') . 'assets/css/pages/' . basename($_critical['path']) . '?v=' . $_critical['mtime'];
    }
    ?>
    <link rel="stylesheet" href="<?= $mainCssHref ?>">
    <?php
    // Same pre-shortcode-resolution gap as the og:image fallback below: $contentBlocks here
    // hasn't been through apply_shortcodes_to_block() yet, so a bare-token photo value (e.g.
    // "{city_image}") must be resolved explicitly or it ships literally in the preload href.
    // hero_split can paint a full-section background photo (hs_bg_photo) behind its own
    // text+image layout — a real LCP case found live (gannmoldremediation.com's city pages):
    // the background covers more viewport than the column <img>, so it's the one that's
    // actually the LCP element whenever both are set, confirmed in every PSI capture on that
    // domain. ONLY the background gets preloaded in that case — found live, comparing against
    // baileyrestoration.com (water-site, same shared template, no bg photo, only ONE
    // fetchpriority=high preload): under PSI's slow-4G bandwidth cap, two same-priority
    // "highest" preloads split the one constrained pipe and both arrive slower, directly
    // delaying the one that's actually being measured. hs_photo (the smaller column image)
    // still renders — it just loads at normal discovery priority instead of competing for
    // the top slot its own LCP element needs.
    $heroPreloadSrcs = [];
    foreach ($contentBlocks as $_b) {
        $_t = $_b['type'] ?? '';
        if ($_t === 'hero' && !empty($_b['hero_bg_image'])) { $heroPreloadSrcs[] = resolve_shortcodes($_b['hero_bg_image']); break; }
        if ($_t === 'hero_split') {
            if (!empty($_b['hs_bg_photo']))      { $heroPreloadSrcs[] = resolve_shortcodes($_b['hs_bg_photo']); }
            elseif (!empty($_b['hs_photo']))     { $heroPreloadSrcs[] = resolve_shortcodes($_b['hs_photo']); }
            if ($heroPreloadSrcs) break;
        }
        if ($_t === 'hero_grid' && !empty($_b['hg_photo'])) { $heroPreloadSrcs[] = resolve_shortcodes($_b['hg_photo']); break; }
        // blog.php's synthetic lead block (see "Blog system" in CLAUDE.md) — a post's
        // featured_image renders immediately under the H1 (post-meta-image, blocks.php)
        // and is the LCP element on every blog post page, same class of miss as
        // hero_split's background photo above.
        if ($_t === 'post_meta' && !empty($_b['featured_image'])) { $heroPreloadSrcs[] = resolve_shortcodes($_b['featured_image']); break; }
    }
    foreach ($heroPreloadSrcs as &$_heroSrc) {
        if (!str_starts_with($_heroSrc, 'http') && !str_starts_with($_heroSrc, '//')) {
            $_heroSrc = ($assetPathPrefix ?? '/') . $_heroSrc;
        }
    }
    unset($_heroSrc);
    ?>
    <?php foreach ($heroPreloadSrcs as $_heroSrc): ?>
    <link rel="preload" as="image" href="<?= h($_heroSrc) ?>" fetchpriority="high">
    <?php endforeach; ?>
    <title><?= h($pageTitle) ?></title>
    <?php $favicon = $data['header']['favicon'] ?? ''; if ($favicon !== ''): $faviconUrl = h(admin_upload_url_v($favicon)); ?>
    <link rel="icon" type="image/x-icon" href="<?= $faviconUrl ?>">
    <link rel="icon" type="image/png" sizes="32x32" href="<?= $faviconUrl ?>">
    <link rel="apple-touch-icon" href="<?= $faviconUrl ?>">
    <?php endif; ?>
    <?php if (!empty($seo['meta_description'])): ?>
    <meta name="description" content="<?= h($seo['meta_description']) ?>">
    <?php endif; ?>
    <?php
    $canonicalUrl = resolve_shortcodes($seo['canonical_url'] ?? '');
    // A noindexed page (e.g. 404.html) has no canonical: a bare slug ('') falling through
    // this fallback used to resolve to the site's own homepage URL — pointing Google at a
    // completely different page while ALSO telling it not to index this one, a contradictory
    // pair of signals on a 404. Any noindexed page skips the fallback for the same reason.
    if (empty($canonicalUrl) && empty($seo['robots_noindex'])) {
        $lbUrl = rtrim(resolve_shortcodes($data['local_business']['lb_url'] ?? ''), '/');
        if ($lbUrl && isset($slug)) {
            $canonicalUrl = $slug ? $lbUrl . '/' . $slug : $lbUrl . '/';
        }
    }
    // Force a trailing slash on the canonical's path (before any ?/#) so it points at the
    // served directory form, never at a DirectorySlash 301. Covers stored and generated
    // values; og:url reuses $canonicalUrl below. Skips file URLs (last segment has a dot).
    if ($canonicalUrl) {
        $cut  = strcspn($canonicalUrl, '?#');
        $cpath = substr($canonicalUrl, 0, $cut);
        $crest = substr($canonicalUrl, $cut);
        $cseg  = substr($cpath, strrpos($cpath, '/') + 1);
        if ($cseg !== '' && strpos($cseg, '.') === false && substr($cpath, -1) !== '/') {
            $canonicalUrl = $cpath . '/' . $crest;
        }
    }
    if ($canonicalUrl): ?>
    <link rel="canonical" href="<?= h($canonicalUrl) ?>">
    <?php endif; ?>
    <?php
    $ogTitle = !empty($seo['og_title']) ? $seo['og_title'] : $pageTitle;
    $ogDesc  = !empty($seo['og_description']) ? $seo['og_description'] : ($seo['meta_description'] ?? '');
    ?>
    <?php
    $ogSiteName  = !empty($seo['og_site_name'])   ? $seo['og_site_name']   : ($data['seo']['og_site_name']   ?? '');
    // og_locale is stored as a literal '' (present, not unset) once a page has ever been
    // saved through the SEO tab, so `?? 'en_US'` never fires — '??' only catches null/unset,
    // not an empty string — and every page shipped an empty og:locale forever. !empty()
    // treats '' the same as absent, which null-coalescing does not.
    $ogLocale    = !empty($seo['og_locale']) ? $seo['og_locale'] : (!empty($data['seo']['og_locale']) ? $data['seo']['og_locale'] : 'en_US');
    $ogImageAlt  = !empty($seo['og_image_alt'])   ? $seo['og_image_alt']   : ($data['seo']['og_image_alt']   ?? '');
    $twCard      = !empty($seo['twitter_card'])    ? $seo['twitter_card']    : ($data['seo']['twitter_card']    ?? 'summary_large_image');
    $twHandle    = !empty($seo['twitter_handle'])  ? $seo['twitter_handle']  : ($data['seo']['twitter_handle']  ?? '');
    $ogImageAbsUrl = !empty($seo['og_image']) ? rtrim(resolve_shortcodes('{website}'), '/') . '/' . ltrim($seo['og_image'], '/') : '';
    $ogImageDims   = [];
    if (!empty($seo['og_image'])) {
        $ogImgFile = BASE_DIR . '/' . ltrim($seo['og_image'], '/');
        if (file_exists($ogImgFile)) { $sz = @getimagesize($ogImgFile); if ($sz) $ogImageDims = [$sz[0], $sz[1]]; }
    }
    ?>
    <?php if (!empty($seo['robots_noindex'])): ?><meta name="robots" content="noindex"><?php endif; ?>
    <?php $ogType = !empty($seo['og_type']) ? $seo['og_type'] : 'website'; ?>
    <meta property="og:type"        content="<?= h($ogType) ?>">
    <meta property="og:title"       content="<?= h($ogTitle) ?>">
    <?php if ($ogDesc): ?><meta property="og:description" content="<?= h($ogDesc) ?>"><?php endif; ?>
    <?php if ($canonicalUrl): ?><meta property="og:url"  content="<?= h($canonicalUrl) ?>"><?php endif; ?>
    <?php if ($ogSiteName): ?><meta property="og:site_name" content="<?= h($ogSiteName) ?>"><?php endif; ?>
    <meta property="og:locale"      content="<?= h($ogLocale) ?>">
    <?php if ($ogImageAbsUrl): ?><meta property="og:image" content="<?= h($ogImageAbsUrl) ?>"><?php endif; ?>
    <?php if ($ogImageDims): ?>
    <meta property="og:image:width"  content="<?= (int)$ogImageDims[0] ?>">
    <meta property="og:image:height" content="<?= (int)$ogImageDims[1] ?>">
    <?php endif; ?>
    <?php if ($ogImageAlt): ?><meta property="og:image:alt" content="<?= h($ogImageAlt) ?>"><?php endif; ?>
    <meta name="twitter:card"        content="<?= h($twCard) ?>">
    <meta name="twitter:title"       content="<?= h($ogTitle) ?>">
    <?php if ($ogDesc): ?><meta name="twitter:description" content="<?= h($ogDesc) ?>"><?php endif; ?>
    <?php if ($ogImageAbsUrl): ?><meta name="twitter:image" content="<?= h($ogImageAbsUrl) ?>"><?php endif; ?>
    <?php if ($twHandle): ?><meta name="twitter:site"  content="<?= h($twHandle) ?>"><?php endif; ?>
    <?php
    // Google Fonts loader — only requests fonts that need network loading
    $gfSystemFonts = ['sans-serif','serif','monospace','Arial, sans-serif','Helvetica, sans-serif','Verdana, sans-serif','Trebuchet MS, sans-serif','Georgia, serif'];
    $gfMap = [
        // Weight lists include 800/900 because block headings use font-weight 800–900;
        // without them the browser fakes bold. Each request verified to return 200 from
        // css2 (a weight the font lacks would 400 and break ALL font loading).
        'Open Sans'    => 'Open+Sans:wght@400;600;700;800',
        'Noto Serif'   => 'Noto+Serif:wght@400;700;800;900',
        'Roboto'       => 'Roboto:wght@400;500;700;900',
        'Lato'         => 'Lato:wght@400;700;900',
        'Montserrat'   => 'Montserrat:wght@400;600;700;800;900',
        'Raleway'      => 'Raleway:wght@400;600;700;800;900',
        'Poppins'      => 'Poppins:wght@400;600;700;800;900',
        'Nunito'       => 'Nunito:wght@400;600;700;800;900',
        'Mulish'       => 'Mulish:wght@400;600;700;800;900',
        'Inter'        => 'Inter:wght@400;500;600;700;800;900',
        'Outfit'       => 'Outfit:wght@400;500;600;700;800;900',
        'Source Sans Pro' => 'Source+Sans+3:wght@400;600;700;800;900',
        'Inclusive Sans'  => 'Inclusive+Sans:ital@0;1',
        'Playfair Display'=> 'Playfair+Display:wght@400;700;800;900',
        'Merriweather' => 'Merriweather:wght@400;700;900',
    ];
    $gfFamilies = [];
    // Real (post-alias) family names in use this page that have known metrics — each
    // gets a "Fallback" @font-face below, matched to theme_css_vars()'s font-family
    // stack (includes/theme.php), which is what actually asks for "<Family> Fallback".
    $gfMetricFamilies = [];
    foreach ([$theme['primary_font'] ?? '', $theme['heading_font'] ?? ''] as $fontStr) {
        if ($fontStr === '' || in_array($fontStr, $gfSystemFonts)) continue;
        $baseName = trim(explode(',', $fontStr)[0], " '\"");
        if (isset($gfMap[$baseName]) && !in_array($gfMap[$baseName], $gfFamilies)) {
            $gfFamilies[] = $gfMap[$baseName];
        }
        $realName = THEME_FONT_FAMILY_ALIASES[$baseName] ?? $baseName;
        if (isset(GF_FONT_METRICS[$realName]) && !isset($gfMetricFamilies[$realName])) {
            $gfMetricFamilies[$realName] = GF_FONT_METRICS[$realName];
        }
    }
    if ($gfFamilies):
        $gfHref = 'https://fonts.googleapis.com/css2?' . implode('&', array_map(fn($f) => 'family=' . $f, $gfFamilies)) . '&display=optional';
    ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <?php /* Load font CSS async so it never blocks first paint. display=optional: the
             browser only uses the web font if it's already available within its first
             ~100ms decision window, otherwise it keeps the fallback for the whole view
             and just caches the font for next time. swap painted the fallback then
             ALWAYS swapped to the web font once it arrived — a real, measured CLS
             source (0.26-0.29, "poor" on PageSpeed) confirmed on a real page. optional
             alone still measurably shifted layout (confirmed: same 0.287 page, dropped
             to exactly 0 with Google Fonts blocked in a controlled test) — it narrows
             the window a swap can happen in, it doesn't remove it. Preloading the
             actual font FILE below (not just this CSS) is what closes that window: the
             bytes start downloading immediately instead of only after this stylesheet
             resolves and the browser discovers the @font-face, so the font is far more
             likely to already be ready when optional's decision point arrives. */
    foreach (gf_preload_urls($gfHref) as $gfFontUrl): ?>
    <link rel="preload" as="font" type="font/woff2" href="<?= h($gfFontUrl) ?>" crossorigin fetchpriority="high">
    <?php endforeach; ?>
    <link rel="preload" as="style" href="<?= h($gfHref) ?>">
    <link rel="stylesheet" href="<?= h($gfHref) ?>" media="print" onload="this.media='all'">
    <noscript><link rel="stylesheet" href="<?= h($gfHref) ?>"></noscript>
    <?php if ($gfMetricFamilies): ?>
    <style>
    <?php foreach ($gfMetricFamilies as $realName => $m):
        $localFace = in_array($realName, GF_SERIF_FAMILIES, true) ? 'Georgia' : 'Arial';
    ?>
    @font-face {
        font-family: '<?= h($realName) ?> Fallback';
        src: local('<?= h($localFace) ?>');
        ascent-override: <?= $m['ascentOverride'] ?>%;
        descent-override: <?= $m['descentOverride'] ?>%;
        line-gap-override: <?= $m['lineGapOverride'] ?>%;
    }
    <?php endforeach; ?>
    </style>
    <?php endif; ?>
    <?php endif; ?>
    <?php
    // Flag whether this page actually uses a course shortcode, so plugins that add
    // render-blocking <head> CSS (schedule plugin) can skip it on pages that don't.
    $GLOBALS['_page_has_course_sc'] = page_uses_course_shortcodes($contentBlocks ?? []);
    run_hook('head_styles', $assetPathPrefix ?? '');
    ?>
    <style><?= theme_css_vars($theme) ?>
    body { font-family: var(--font-primary, sans-serif); }
    h1,h2,h3,h4,h5,h6 { font-family: var(--font-heading, var(--font-primary, sans-serif)); }
    :root { --fixed-header-height: <?= $_hInitialHeight ?>; }
    </style>
    <?php
    // Schema markup — stored JSON-LD, with the FAQPage node derived from THIS page's
    // current FAQ blocks and the LocalBusiness node's sameAs derived from the site's
    // real social links, both at render time (never stale; see schema_apply_faqpage
    // and schema_apply_sameas in includes/schema.php). Shortcodes resolve after the
    // merge so Q&A tokens fill too.
    $schemaJson = schema_apply_faqpage($seo['schema'] ?? '', $contentBlocks ?? []);
    $schemaJson = schema_apply_sameas($schemaJson, $data['footer']['socials'] ?? []);
    if ($schemaJson !== '') {
        $schemaData = json_decode(resolve_shortcodes($schemaJson));
        if ($schemaData !== null) {
            echo '<script type="application/ld+json">' . json_encode($schemaData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) . '</script>' . "\n";
        }
    }
    // Breadcrumb HTML nav — only on slug pages (not homepage)
    if (!empty($slug) && !isset($bcItems)) {   // skip if a caller (e.g. a route plugin) pre-set $bcItems
        $bcItems = [['name' => 'Home', 'url' => '/']];
        if (!empty($seo['bc_mid_label'])) {
            $midUrlRel = trim($seo['bc_mid_url'] ?? '');
            $bcItems[] = ['name' => resolve_shortcodes($seo['bc_mid_label']), 'url' => $midUrlRel];
        }
        $bcLabel = !empty($seo['bc_label']) ? resolve_shortcodes($seo['bc_label']) : preg_replace('/\s*[|\-–—].*$/', '', $pageTitle);
        $bcItems[] = ['name' => $bcLabel, 'url' => '/' . ltrim($slug, '/')];
    }
    // Analytics — output raw (admin-entered, trusted)
    if (!empty($theme['analytics_head'])) echo $theme['analytics_head'] . "\n";
    if (!empty($theme['facebook_pixel'])) echo $theme['facebook_pixel'] . "\n";
    if (!empty($theme['gsc_meta']))       echo $theme['gsc_meta'] . "\n";     // multisite: per-site Search Console verification meta tag
    if (!empty($theme['head_extra']))     echo $theme['head_extra'] . "\n";   // free-text "Custom head code" — admin-authored CSS/HTML
    ?>
</head>
<body>

<?php
// ── Shared header variables (available to all header partials) ────────────
$isSticky      = !empty($header['sticky']);
// $navBg resolved near the top of this file (header bar color, option A).
$navText       = $header['nav_text']        ?? '#ffffff';
$btnStyle      = $header['phone_btn_style'] ?? 'outline';
$infoItems     = $header['info_items']      ?? [];
$logoHeight    = max(32, min(120, (int)($header['logo_max_height'] ?? 56)));
$phoneLabel    = trim($header['phone_label']   ?? 'Helpline:');
$showSponsored = !empty($header['show_sponsored']);
$sponsoredText = trim($header['sponsored_text'] ?? 'Sponsored');
$ctaText       = trim($header['cta_text']      ?? '');
$ctaUrl        = trim($header['cta_url']       ?? '#');

// ── Dispatch to the correct header layout partial ─────────────────────────
$_hFile = __DIR__ . '/headers/' . $_hLayout . '.php';
include file_exists($_hFile) ? $_hFile : __DIR__ . '/headers/standard.php';
?>
<?php
$lastBlockType  = end($contentBlocks)['type'] ?? '';
$firstBlockType = ($contentBlocks[0]['type'] ?? '');
$lastBlockNoGap = in_array($lastBlockType,  ['custom_html','cta_banner','wide_banner','stats','hero','hero_split','hero_grid','links_grid','cta_card','map_info']);
$firstBlockHero = in_array($firstBlockType, ['hero','hero_split','hero_grid','hero_video']);
$bcSettings     = $data['breadcrumbs'] ?? [];
$bcEnabled      = $bcSettings['enabled'] ?? true;
$bcPageHide     = !empty($seo['bc_hide'] ?? false);
$showBreadcrumb = $bcEnabled && !$bcPageHide && !empty($bcItems);
$bcHeroBgMode   = $bcSettings['hero_bg_mode']  ?? 'auto';
$bcHeroBgColor  = $bcSettings['hero_bg_color'] ?? '';

// Read first hero block's actual background color for seamless header→hero transition
$firstHeroBg = '';
if ($firstBlockHero) {
    $fb = $contentBlocks[0];
    $firstHeroBg = $fb['hs_bg_color'] ?? $fb['hero_bg_color'] ?? $fb['hg_bg_color'] ?? '#0d1b3e';
    if ($firstHeroBg && !str_starts_with($firstHeroBg, '#')) $firstHeroBg = '#0d1b3e';
}
$mainStyle = [];
if ($lastBlockNoGap) $mainStyle[] = 'padding-bottom:0';
if ($firstHeroBg) {
    // Color only the padding-top gap (header offset area) with the hero color.
    // A solid gradient sized to --fixed-header-height avoids coloring the entire <main>.
    $mainStyle[] = 'background-image:linear-gradient(' . h($firstHeroBg) . ',' . h($firstHeroBg) . ')';
    $mainStyle[] = 'background-size:100% var(--fixed-header-height,' . $_hInitialHeight . ')';
    $mainStyle[] = 'background-repeat:no-repeat';
}
$mainStyleAttr = $mainStyle ? ' style="' . implode(';', $mainStyle) . '"' : '';

// Override breadcrumb bg with actual hero color when in custom mode, else use hero color directly
$bcHeroInlineStyle = '';
if ($firstBlockHero) {
    $bcBg = ($bcHeroBgMode === 'custom' && $bcHeroBgColor) ? $bcHeroBgColor : $firstHeroBg;
    if ($bcBg) {
        $bcStyle = 'background:' . h($bcBg) . ';border-bottom-color:rgba(255,255,255,0.12);';
        // .breadcrumb-bar--hero hardcodes white text, correct when the hero is dark (the
        // common case) — but a hero can be light (e.g. a light-blue hero_split background),
        // which makes that white text invisible. Perceived-luminance check (same formula as
        // ms_is_light_color() in includes/multisite/visual.php) picks dark text instead when
        // the hero itself is light, via CSS vars the stylesheet already falls back from.
        $bcHex = ltrim($bcBg, '#');
        if (strlen($bcHex) === 3) $bcHex = $bcHex[0].$bcHex[0].$bcHex[1].$bcHex[1].$bcHex[2].$bcHex[2];
        if (strlen($bcHex) === 6 && ctype_xdigit($bcHex)) {
            $r = hexdec(substr($bcHex, 0, 2)); $g = hexdec(substr($bcHex, 2, 2)); $bl = hexdec(substr($bcHex, 4, 2));
            if (0.299 * $r + 0.587 * $g + 0.114 * $bl > 150) {
                $bcStyle .= '--bc-text-color:#475569;--bc-link-color:var(--color-accent,#1a2e5a);'
                          . '--bc-link-hover-color:var(--color-heading,#1a2e5a);--bc-current-color:#1a2e5a;--bc-sep-color:#94a3b8;';
            }
        }
        $bcHeroInlineStyle = ' style="' . $bcStyle . '"';
    }
}
?>
<main class="site-main"<?= $mainStyleAttr ?>>
    <?php if ($showBreadcrumb): ?>
    <nav class="breadcrumb-bar<?= $firstBlockHero ? ' breadcrumb-bar--hero' : '' ?>"<?= $bcHeroInlineStyle ?> aria-label="Breadcrumb">
        <div class="container">
            <ol class="breadcrumb-list" itemscope itemtype="https://schema.org/BreadcrumbList">
                <?php foreach ($bcItems as $pos => $crumb):
                    $isLast = $pos === count($bcItems) - 1; ?>
                <li itemprop="itemListElement" itemscope itemtype="https://schema.org/ListItem">
                    <?php if (!$isLast && $crumb['url']): ?>
                        <a itemprop="item" href="<?= h($crumb['url']) ?>"><span itemprop="name"><?= h($crumb['name']) ?></span></a>
                    <?php else: ?>
                        <span itemprop="name" aria-current="page"><?= h($crumb['name']) ?></span>
                    <?php endif; ?>
                    <meta itemprop="position" content="<?= $pos + 1 ?>">
                    <?php if (!$isLast): ?><span class="breadcrumb-sep" aria-hidden="true">›</span><?php endif; ?>
                </li>
                <?php endforeach; ?>
            </ol>
        </div>
    </nav>
    <?php endif; ?>
    <?php
    // These block types need full-width rendering (no container wrapper)
    // Fix 4: gate debug overlay on admin session so public visitors can't trigger it
    $showBlocks = !empty($_GET['show_blocks']) && !empty($_SESSION['admin_logged_in']);
    $blockIdx = 0;
    // blog.php always prepends a 'post_meta' pseudo-block as element 0 for a single
    // post (never for the blog listing or any other page) — reusing it as the
    // signal costs nothing new to track and can't drift out of sync with it.
    $isBlogPost = ($contentBlocks[0]['type'] ?? '') === 'post_meta';
    foreach ($contentBlocks as $block):
        $btype = $block['type'] ?? '';
        // Blocks that manage their own .container must be full-width here to avoid double-wrapping
        $isFullWidth = in_array($btype, ['split_cta','cta_banner','wide_banner','links_grid','hero_grid','cta_card','map_info','hero_split','feature_split','faq_two_col','image_features','service_cards','tab_services','blog_list','stats','email_banner','cards','custom_html','comparison_table','testimonials','stage_cards','logo_bar','trust_bar','video','contact_form','buttons_grid','related_links']);
        // These stay width-constrained by .container (they need its max-width/
        // centering for a multi-column grid, unlike the isFullWidth types above),
        // but each already has its own top/bottom padding — .container's own
        // 56px block-section padding on top of that was a second, redundant
        // layer of the same "blank space at the start/end of blocks" bug the
        // isFullWidth list exists to prevent, just via the width path instead
        // of the padding path. container-tight-v keeps the width/centering,
        // drops only the vertical padding.
        $noContainerVPad = in_array($btype, ['hero','feature_columns','cta_button','faq','html_two_col','gallery','steps','pricing_cards','team']);
        $blockIdx++;
    ?>
        <?php
        $bskin = $block['skin'] ?? '';
        $skinClass = in_array($bskin, ['light','dark','accent','subtle']) ? " skin-{$bskin}" : '';
        $pbVal = (int)($block['padding_bottom'] ?? 0);
        $sectionStyle = $pbVal > 0 ? ' style="padding-bottom:' . $pbVal . 'px"' : '';
        ?>
        <?php if ($showBlocks): ?>
        <div style="outline:2px dashed #e11d48;">
        <div style="background:#e11d48;color:#fff;font-size:11px;font-weight:700;padding:2px 8px;font-family:monospace;display:inline-block;"><?= $blockIdx ?>: <?= h($btype) ?><?= $skinClass ? " [{$bskin}]" : '' ?></div>
        <?php endif; ?>
        <section class="block-section<?= $skinClass ?>"<?= $sectionStyle ?>>
        <?php if (!$isFullWidth): ?>
        <div class="container<?= $noContainerVPad ? ' container-tight-v' : '' ?>">
        <?php endif; ?>
            <?php render_content_block($block, $assetPathPrefix ?? '', $isBlogPost); ?>
        <?php if (!$isFullWidth): ?>
        </div>
        <?php endif; ?>
        </section>
        <?php if ($showBlocks): ?></div><?php endif; ?>
    <?php endforeach; ?>
</main>

<footer class="site-footer"<?= $lastBlockNoGap ? ' style="margin-top:0"' : '' ?>>

    <!-- FOOTER COLUMNS -->
    <div class="footer-main">
        <?php $footerColCount = max(2, min(4, (int)($footer['col_count'] ?? 3))); ?>
        <div class="container footer-cols-<?= $footerColCount ?>">
            <?php foreach ($footer['columns'] as $column):
                $colType = $column['type'] ?? 'links';
            ?>
            <div class="footer-col<?= $colType === 'logo' ? ' footer-col-logo-only' : '' ?>">
                <?php if (!empty($column['title'])): ?>
                    <h3 class="footer-col-title"><?= h($column['title']) ?></h3>
                    <div class="footer-col-divider"></div>
                <?php endif; ?>

                <?php if ($colType === 'text'): ?>
                    <div class="footer-col-text"><?= text_to_html($column['text'] ?? '') ?></div>

                <?php elseif ($colType === 'links'): ?>
                    <ul class="footer-col-links">
                        <?php foreach (($column['links'] ?? []) as $link): ?>
                            <?php if (empty($link['label'])) continue; ?>
                            <li><a href="<?= h($link['url'] ?: '#') ?>"><?= h($link['label']) ?></a></li>
                        <?php endforeach; ?>
                    </ul>

                <?php elseif ($colType === 'contact'): ?>
                    <ul class="footer-contact-list">
                        <?php
                        // Plain currentColor SVGs, not emoji — an emoji carries its own fixed
                        // colors no CSS can touch, so phone/pin/envelope each looked like a
                        // different, unthemed color next to each other. These pick up the
                        // site's own accent color from .contact-icon, like every other icon
                        // badge on the page (e.g. the sticky bar's phone icon, same path below).
                        $svgPhone = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M6.62 10.79c1.44 2.83 3.76 5.14 6.59 6.59l2.2-2.2c.27-.27.67-.36 1.02-.24 1.12.37 2.33.57 3.57.57.55 0 1 .45 1 1V20c0 .55-.45 1-1 1C7.61 21 1 14.39 1 6c0-.55.45-1 1-1h3.5c.55 0 1 .45 1 1 0 1.25.2 2.45.57 3.57.11.35.03.74-.25 1.02l-2.2 2.2z"/></svg>';
                        $svgPin   = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2C8.14 2 5 5.14 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.86-3.14-7-7-7zm0 9.5A2.5 2.5 0 1 1 12 6.5a2.5 2.5 0 0 1 0 5z"/></svg>';
                        $svgMail  = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M20 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4-8 5-8-5V6l8 5 8-5v2z"/></svg>';
                        ?>
                        <?php
                        // City for the contact column comes from site_vars (the real source).
                        // Previously read header.city, but that key is now migrated/cleared into
                        // the header info items, so it's always empty here.
                        $footerCity = !empty($data['site_vars']['city']) ? trim(resolve_shortcodes('{city_state}')) : '';
                        $footerNeighborhoods = trim(resolve_shortcodes('{neighborhoods}'));
                        ?>
                        <?php if ($footerCity !== '' && strpos($footerCity, '{') === false): ?>
                            <li class="footer-contact-item footer-contact-service-area">
                                <span class="contact-icon contact-icon-city"><?= $svgPin ?></span>
                                <span>Serving <?= h($footerCity) ?><?= $footerNeighborhoods !== '' ? ' including ' . h($footerNeighborhoods) . ' and nearby neighborhoods.' : '.' ?></span>
                            </li>
                        <?php endif; ?>
                        <?php if (!empty($footer['phone'])): ?>
                            <li class="footer-contact-item">
                                <span class="contact-icon contact-icon-phone"><?= $svgPhone ?></span>
                                <a href="tel:<?= h($telHref) ?>"><?= h($footer['phone']) ?></a>
                            </li>
                        <?php endif; ?>
                        <?php $footerEmail = trim($data['site_vars']['email'] ?? ''); ?>
                        <?php if ($footerEmail !== '' && strpos($footerEmail, '{') === false): ?>
                            <li class="footer-contact-item">
                                <span class="contact-icon contact-icon-email"><?= $svgMail ?></span>
                                <a href="mailto:<?= h($footerEmail) ?>"><?= h($footerEmail) ?></a>
                            </li>
                        <?php endif; ?>
                        <?php foreach (($column['contact_extras'] ?? []) as $extra): ?>
                            <?php if (empty($extra['label'])) continue; ?>
                            <li class="footer-contact-item">
                                <?php if (!empty($extra['icon'])): ?><span class="contact-icon"><?= h($extra['icon']) ?></span><?php endif; ?>
                                <?php if (!empty($extra['url'])): ?>
                                    <a href="<?= h($extra['url']) ?>"><?= h($extra['label']) ?></a>
                                <?php else: ?>
                                    <span><?= h($extra['label']) ?></span>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php if (!empty($footer['logo']) && !empty($footer['logo_in_contact_column'])): ?>
                        <?php $__footLogoPath = img_logo_variant($footer['logo'], 40); ?>
                        <div class="footer-col-logo-box">
                            <img class="footer-col-logo" src="<?= h(admin_upload_url_v($__footLogoPath)) ?>" alt="<?= h(($__footColLogoAlt = trim(resolve_shortcodes((string)($header['site_name'] ?? '')))) !== '' ? $__footColLogoAlt : SITE_TITLE) ?>" <?= img_dim_attrs($__footLogoPath, 40) ?>>
                        </div>
                    <?php endif; ?>

                <?php elseif ($colType === 'logo'): ?>
                    <?php if (!empty($footer['logo'])): ?>
                        <?php $__footLogoPath = img_logo_variant($footer['logo'], (int) $logoHeight); ?>
                        <div class="footer-col-logo-box">
                            <img class="footer-col-logo" src="<?= h(admin_upload_url_v($__footLogoPath)) ?>" alt="<?= h(($__footColLogoAlt = trim(resolve_shortcodes((string)($header['site_name'] ?? '')))) !== '' ? $__footColLogoAlt : SITE_TITLE) ?>" <?= img_dim_attrs($__footLogoPath, (int) $logoHeight) ?>style="<?= img_fixed_size_css($__footLogoPath, (int) $logoHeight) ?>display:block;">
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($footer['tagline'])): ?>
                        <p class="footer-col-tagline"><?= h(resolve_shortcodes($footer['tagline'])) ?></p>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- WHITE DIVIDER + DISCLAIMER — sits between main footer and copyright bar -->
    <?php if (!empty($footer['disclaimer'])): ?>
    <div class="footer-disclaimer-section">
        <div class="footer-disclaimer-divider"></div>
        <div class="container footer-disclaimer">
            <?= text_to_html($footer['disclaimer']) ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- FOOTER BOTTOM: copyright + links -->
    <div class="footer-bottom">
        <div class="container footer-bottom-inner">
            <?php if (!empty($footer['logo']) && !empty($footer['logo_in_copyright_bar'])): ?>
                <img class="footer-bottom-logo" src="<?= h(admin_upload_url_v($footer['logo'])) ?>" alt="<?= h(($__footLogoAlt = trim(resolve_shortcodes((string)($header['site_name'] ?? '')))) !== '' ? $__footLogoAlt : SITE_TITLE) ?>" <?= img_dim_attrs($footer['logo'], 48) ?>>
            <?php endif; ?>
            <div class="footer-copyright"><?= h(str_replace('{year}', date('Y'), resolve_shortcodes($footer['copyright'] ?? ''))) ?></div>
            <?php $footerSocials = array_filter($footer['socials'] ?? []); ?>
            <?php if (!empty($footerSocials)): ?>
                <div class="footer-socials" style="margin-top:0;">
                    <?php
                    $socialLabels = ['facebook'=>'Facebook','instagram'=>'Instagram','linkedin'=>'LinkedIn','youtube'=>'YouTube','twitter'=>'X / Twitter'];
                    foreach ($footerSocials as $platform => $url): ?>
                        <a href="<?= h($url) ?>" class="social-link" target="_blank" rel="noopener noreferrer"><?= h($socialLabels[$platform] ?? ucfirst($platform)) ?></a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php if (!empty($footer['bottom_links'])): ?>
                <div class="footer-bottom-links">
                    <?php $first = true;
                    foreach ($footer['bottom_links'] as $link):
                        if (empty($link['label'])) continue;
                        if (!$first) echo ' <span class="sep">|</span> ';
                        $first = false; ?>
                        <a href="<?= h($link['url'] ?: '#') ?>"><?= h($link['label']) ?></a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

</footer>

<!-- STICKY BOTTOM BAR -->
<?php if (!empty($footer['sticky_bar_text']) || !empty($footer['phone'])): ?>
<div class="sticky-bottom-bar" style="background:<?= h($navBg) ?>;">
    <div class="sticky-bar-inner">
        <span class="sticky-bar-text" style="color:<?= h($header['nav_text'] ?? '#ffffff') ?>;">
            <?= h($footer['sticky_bar_text'] ?? '24/7 Support Line - Call Now') ?>
            <?php if (($footer['show_sticky_info_icon'] ?? true) && (!empty($footer['sticky_bar_info']) || !empty($data['popups']['info']['enabled']))): ?>
                <button class="info-trigger sticky-info-trigger"
                        onclick="openInfoPopup()"
                        title="<?= h($footer['sticky_bar_info'] ?? '') ?>"
                        style="color:<?= h($header['nav_text'] ?? '#ffffff') ?>;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="36" height="36" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2ZM11 7h2v2h-2zm0 4h2v6h-2z"/></svg>
                </button>
            <?php endif; ?>
        </span>
        <?php if (!empty($footer['phone'])): ?>
        <a href="tel:<?= h($telHref) ?>"
           class="sticky-bar-phone" style="color:<?= h($header['nav_text'] ?? '#ffffff') ?>;">
            <span class="sticky-phone-icon">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="white" aria-hidden="true">
                    <path d="M6.62 10.79c1.44 2.83 3.76 5.14 6.59 6.59l2.2-2.2c.27-.27.67-.36 1.02-.24 1.12.37 2.33.57 3.57.57.55 0 1 .45 1 1V20c0 .55-.45 1-1 1C7.61 21 1 14.39 1 6c0-.55.45-1 1-1h3.5c.55 0 1 .45 1 1 0 1.25.2 2.45.57 3.57.11.35.03.74-.25 1.02l-2.2 2.2z"/>
                </svg>
            </span>
            <span class="sticky-phone-number"><?= h($footer['phone']) ?></span>
        </a>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- SCROLL TO TOP BUTTON -->
<button class="scroll-to-top" id="scrollToTop" aria-label="Scroll to top"
        style="background:<?= h($navBg) ?>;color:<?= h($navText) ?>;">
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 19V5M5 12l7-7 7 7"/></svg>
</button>

<script>
<?php
$mainJsPath = __DIR__ . "/../assets/js/site.js";
js_minify_to(__DIR__ . "/../assets/js/site.src.js", $mainJsPath);
echo file_get_contents($mainJsPath);
?>
</script>
<?php run_hook('body_scripts', $data, $assetPathPrefix ?? ''); ?>
<?php run_hook('body_end',     $data, $assetPathPrefix ?? ''); ?>
</body>
</html>
