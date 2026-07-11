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
$pageTitle = resolve_shortcodes(!empty($pageTitle) ? $pageTitle : SITE_TITLE);
if (isset($seo['meta_description'])) $seo['meta_description'] = resolve_shortcodes($seo['meta_description']);
if (isset($seo['meta_keywords']))    $seo['meta_keywords']    = resolve_shortcodes($seo['meta_keywords']);
if (isset($seo['og_title']))         $seo['og_title']         = resolve_shortcodes($seo['og_title']);
if (isset($seo['og_description']))   $seo['og_description']   = resolve_shortcodes($seo['og_description']);
// OG image fallback: hero block photo → global site og_image
if (empty($seo['og_image'])) {
    foreach ($contentBlocks as $_b) {
        $_t = $_b['type'] ?? '';
        if ($_t === 'hero_split' && !empty($_b['hs_photo']))      { $seo['og_image'] = $_b['hs_photo'];      break; }
        if ($_t === 'hero'       && !empty($_b['hero_bg_image'])) { $seo['og_image'] = $_b['hero_bg_image']; break; }
        if ($_t === 'hero_grid'  && !empty($_b['hg_photo']))      { $seo['og_image'] = $_b['hg_photo'];      break; }
        if ($_t === 'post_meta'  && !empty($_b['featured_image'])){ $seo['og_image'] = $_b['featured_image']; break; }
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
    $heroPreloadSrc = null;
    foreach ($contentBlocks as $_b) {
        $_t = $_b['type'] ?? '';
        if ($_t === 'hero'       && !empty($_b['hero_bg_image'])) { $heroPreloadSrc = $_b['hero_bg_image']; break; }
        if ($_t === 'hero_split' && !empty($_b['hs_photo']))      { $heroPreloadSrc = $_b['hs_photo'];      break; }
        if ($_t === 'hero_grid'  && !empty($_b['hg_photo']))      { $heroPreloadSrc = $_b['hg_photo'];      break; }
    }
    if ($heroPreloadSrc && !str_starts_with($heroPreloadSrc, 'http') && !str_starts_with($heroPreloadSrc, '//')) {
        $heroPreloadSrc = ($assetPathPrefix ?? '/') . $heroPreloadSrc;
    }
    ?>
    <?php if ($heroPreloadSrc): ?>
    <link rel="preload" as="image" href="<?= h($heroPreloadSrc) ?>" fetchpriority="high">
    <?php endif; ?>
    <title><?= h($pageTitle) ?></title>
    <?php $favicon = $data['header']['favicon'] ?? ''; if ($favicon !== ''): $faviconUrl = h(admin_upload_url($favicon)); ?>
    <link rel="icon" type="image/x-icon" href="<?= $faviconUrl ?>">
    <link rel="icon" type="image/png" sizes="32x32" href="<?= $faviconUrl ?>">
    <link rel="apple-touch-icon" href="<?= $faviconUrl ?>">
    <?php endif; ?>
    <?php if (!empty($seo['meta_description'])): ?>
    <meta name="description" content="<?= h($seo['meta_description']) ?>">
    <?php endif; ?>
    <?php if (!empty($seo['meta_keywords'])): ?>
    <meta name="keywords" content="<?= h($seo['meta_keywords']) ?>">
    <?php endif; ?>
    <?php
    $canonicalUrl = resolve_shortcodes($seo['canonical_url'] ?? '');
    if (empty($canonicalUrl)) {
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
    $ogLocale    = !empty($seo['og_locale'])       ? $seo['og_locale']       : ($data['seo']['og_locale']       ?? 'en_US');
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
        'Source Sans Pro' => 'Source+Sans+3:wght@400;600;700;800;900',
        'Inclusive Sans'  => 'Inclusive+Sans:ital@0;1',
        'Playfair Display'=> 'Playfair+Display:wght@400;700;800;900',
        'Merriweather' => 'Merriweather:wght@400;700;900',
    ];
    $gfFamilies = [];
    foreach ([$theme['primary_font'] ?? '', $theme['heading_font'] ?? ''] as $fontStr) {
        if ($fontStr === '' || in_array($fontStr, $gfSystemFonts)) continue;
        $baseName = trim(explode(',', $fontStr)[0], " '\"");
        if (isset($gfMap[$baseName]) && !in_array($gfMap[$baseName], $gfFamilies)) {
            $gfFamilies[] = $gfMap[$baseName];
        }
    }
    if ($gfFamilies):
        $gfHref = 'https://fonts.googleapis.com/css2?' . implode('&', array_map(fn($f) => 'family=' . $f, $gfFamilies)) . '&display=swap';
    ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <?php /* Load font CSS async so it never blocks first paint — display=swap already
             paints text in the fallback, then swaps to the web font when it arrives. */ ?>
    <link rel="preload" as="style" href="<?= h($gfHref) ?>">
    <link rel="stylesheet" href="<?= h($gfHref) ?>" media="print" onload="this.media='all'">
    <noscript><link rel="stylesheet" href="<?= h($gfHref) ?>"></noscript>
    <?php endif; ?>
    <link rel="stylesheet" href="<?= h($assetPathPrefix ?? '') ?>assets/css/style.css?v=<?= (int) @filemtime(__DIR__ . '/../assets/css/style.css') ?>">
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
    // current FAQ blocks at render time (never stale; see schema_apply_faqpage in
    // includes/schema.php). Shortcodes resolve after the merge so Q&A tokens fill too.
    $schemaJson = schema_apply_faqpage($seo['schema'] ?? '', $contentBlocks ?? []);
    if ($schemaJson !== '') {
        $schemaData = json_decode(resolve_shortcodes($schemaJson));
        if ($schemaData !== null) {
            echo '<script type="application/ld+json">' . json_encode($schemaData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) . '</script>' . "\n";
        }
    }
    // Breadcrumb HTML nav — only on slug pages (not homepage)
    if (!empty($slug)) {
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
    if (!empty($theme['head_extra']))     echo $theme['head_extra'] . "\n";   // e.g. per-site Search Console verification
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
$ctaText       = trim($header['cta_text']      ?? '');
$ctaUrl        = trim($header['cta_url']       ?? '#');

// ── Dispatch to the correct header layout partial ─────────────────────────
$_hFile = __DIR__ . '/headers/' . $_hLayout . '.php';
include file_exists($_hFile) ? $_hFile : __DIR__ . '/headers/standard.php';
?>
<?php
$lastBlockType  = end($contentBlocks)['type'] ?? '';
$firstBlockType = ($contentBlocks[0]['type'] ?? '');
$lastBlockNoGap = in_array($lastBlockType,  ['custom_html','cta_banner','wide_banner','stats','hero','hero_split','hero_grid']);
$firstBlockHero = in_array($firstBlockType, ['hero','hero_split','hero_grid','hero_video']);
$bcSettings     = $data['breadcrumbs'] ?? [];
$bcEnabled      = $bcSettings['enabled'] ?? true;
$bcPageHide     = !empty($seo['bc_hide'] ?? false);
$showBreadcrumb = $bcEnabled && !$bcPageHide && !empty($slug) && isset($bcItems);
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
    if ($bcBg) $bcHeroInlineStyle = ' style="background:' . h($bcBg) . ';border-bottom-color:rgba(255,255,255,0.12);"';
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
    foreach ($contentBlocks as $block):
        $btype = $block['type'] ?? '';
        // Blocks that manage their own .container must be full-width here to avoid double-wrapping
        $isFullWidth = in_array($btype, ['split_cta','cta_banner','wide_banner','links_grid','hero_grid','cta_card','map_info','hero_split','feature_split','faq_two_col','image_features','service_cards','tab_services','blog_list','stats','email_banner','cards','custom_html','comparison_table','testimonials','stage_cards','logo_bar','video','contact_form','buttons_grid']);
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
        <div class="container">
        <?php endif; ?>
            <?php render_content_block($block, $assetPathPrefix ?? ''); ?>
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
            <div class="footer-col">
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
                        <?php if (!empty($footer['phone'])): ?>
                            <li class="footer-contact-item">
                                <span class="contact-icon">📞</span>
                                <a href="tel:<?= h($telHref) ?>"><?= h($footer['phone']) ?></a>
                            </li>
                        <?php endif; ?>
                        <?php if (!empty($header['city'])): ?>
                            <li class="footer-contact-item">
                                <span class="contact-icon">🌐</span>
                                <span><?= h($header['city']) ?></span>
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
                <img class="footer-bottom-logo" src="<?= h(admin_upload_url($footer['logo'])) ?>" alt="Logo">
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
            <?php if (!empty($footer['sticky_bar_info']) || !empty($data['popups']['info']['enabled'])): ?>
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
    ⬆
</button>

<script>
(function() {
    // Fixed header offset — topbar (fixed) + header (fixed below topbar)
    var stickyHeader = document.querySelector('.site-header-sticky');
    if (stickyHeader) {
        function setOffset() {
            var topbar = document.querySelector('.site-topbar');
            var topbarH = topbar ? topbar.offsetHeight : 0;
            stickyHeader.style.top = topbarH + 'px';
            var totalH = topbarH + stickyHeader.offsetHeight;
            document.documentElement.style.setProperty('--fixed-header-height', totalH + 'px');
            var main = document.querySelector('main');
            if (main) main.style.paddingTop = totalH + 'px';
        }
        setOffset();
        window.addEventListener('resize', setOffset);
    }

    // Scroll to top button
    var scrollBtn = document.getElementById('scrollToTop');
    if (scrollBtn) {
        window.addEventListener('scroll', function() {
            scrollBtn.classList.toggle('visible', window.scrollY > 400);
        });
        scrollBtn.addEventListener('click', function() {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });
    }

    // ---- FAQ Two Column toggle ----
    window.toggleFq = function(id) {
        var answer = document.getElementById(id);
        var btn = answer ? answer.previousElementSibling : null;
        if (!answer) return;
        var isHidden = answer.hasAttribute('hidden');
        answer.toggleAttribute('hidden', !isHidden);
        if (btn) {
            btn.setAttribute('aria-expanded', isHidden ? 'true' : 'false');
            var icon = btn.querySelector('.fq-icon');
            if (icon) icon.textContent = isHidden ? '\u2212' : '+';
        }
    };

    // ---- Standard FAQ accordion toggle ----
    window.toggleFaq = function(id) {
        var answer = document.getElementById(id);
        if (!answer) return;
        var isHidden = answer.hasAttribute('hidden');
        answer.toggleAttribute('hidden', !isHidden);
        var btn = answer.previousElementSibling;
        if (btn) btn.setAttribute('aria-expanded', isHidden ? 'true' : 'false');
    };

    // ---- Tab Services switcher ----
    window.switchTab = function(btn) {
        var uid = btn.dataset.uid;
        var layout = document.getElementById(uid);
        if (!layout) return;
        var activeBg = btn.dataset.activeBg || 'var(--color-header-bg,#120575)';
        layout.querySelectorAll('.ts-tab').forEach(function(t) {
            t.classList.remove('ts-tab-active');
            t.style.background = '';
            t.style.color = '';
        });
        layout.querySelectorAll('.ts-panel').forEach(function(p) {
            p.setAttribute('hidden', '');
        });
        btn.classList.add('ts-tab-active');
        btn.style.background = activeBg;
        btn.style.color = '#fff';
        var panel = layout.querySelector('.ts-panel[data-panel="' + btn.dataset.tab + '"]');
        if (panel) panel.removeAttribute('hidden');
    };

    // Hamburger nav toggle
    var toggle = document.getElementById('navToggle');
    var nav    = document.getElementById('siteNav');
    if (!toggle || !nav) return;

    toggle.addEventListener('click', function() {
        var open = nav.classList.toggle('is-open');
        toggle.classList.toggle('is-open', open);
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    });

    // Dropdown toggle: mobile = click accordion; desktop = click closes others, Esc closes
    nav.querySelectorAll('li.has-dropdown > a').forEach(function(a) {
        a.addEventListener('click', function(e) {
            var li = a.closest('li.has-dropdown');
            if (window.innerWidth <= 768) {
                e.preventDefault();
                var isOpen = li.classList.toggle('open');
                a.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            } else {
                // On desktop: blur so hover takes over; prevent the # href jump
                if (a.getAttribute('href') === '#') e.preventDefault();
                a.blur();
            }
        });
    });

    // Close all dropdowns when clicking outside the nav
    document.addEventListener('click', function(e) {
        if (!nav.contains(e.target)) {
            nav.querySelectorAll('li.has-dropdown').forEach(function(li) { li.classList.remove('open'); });
        }
    });

    // Esc key closes all dropdowns
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            nav.querySelectorAll('li.has-dropdown').forEach(function(li) { li.classList.remove('open'); });
        }
    });

    // Close everything when a leaf link is clicked
    nav.querySelectorAll('a:not(.has-dropdown > a)').forEach(function(a) {
        a.addEventListener('click', function() {
            nav.classList.remove('is-open');
            toggle.classList.remove('is-open');
            toggle.setAttribute('aria-expanded', 'false');
        });
    });
})();
</script>
<?php run_hook('body_scripts', $data, $assetPathPrefix ?? ''); ?>
<?php run_hook('body_end',     $data, $assetPathPrefix ?? ''); ?>
</body>
</html>
