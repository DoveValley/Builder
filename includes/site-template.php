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

$theme  = $data['theme'];
$header = apply_shortcodes_to_block($data['header']);
$footer = apply_shortcodes_to_block($data['footer']);
// {tel} = E.164 tracking number; fall back to stripping display phone
$telHref = resolve_shortcodes('{tel}') ?: preg_replace('/[^0-9+]/', '', $header['phone'] ?? '');

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
    if ($canonicalUrl): ?>
    <link rel="canonical" href="<?= h($canonicalUrl) ?>">
    <?php endif; ?>
    <?php
    $ogTitle = !empty($seo['og_title']) ? $seo['og_title'] : $pageTitle;
    $ogDesc  = !empty($seo['og_description']) ? $seo['og_description'] : ($seo['meta_description'] ?? '');
    ?>
    <meta property="og:type"  content="website">
    <meta property="og:title" content="<?= h($ogTitle) ?>">
    <?php if ($ogDesc): ?><meta property="og:description" content="<?= h($ogDesc) ?>"><?php endif; ?>
    <?php if (!empty($seo['og_image'])): ?><meta property="og:image" content="<?= h(rtrim(resolve_shortcodes('{website}'), '/') . '/' . ltrim($seo['og_image'], '/')) ?>"><?php endif; ?>
    <link rel="preconnect" href="https://fonts.gstatic.com/" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inclusive+Sans:ital@0;1&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= h($assetPathPrefix ?? '') ?>assets/css/style.css?v=<?= (int) @filemtime(__DIR__ . '/../assets/css/style.css') ?>">
    <style><?= theme_css_vars($theme) ?>
    body { font-family: var(--font-primary, sans-serif); }
    </style>
    <?php
    if (!empty($seo['schema'])) {
        $schemaData = json_decode($seo['schema']);
        if ($schemaData !== null) {
            echo '<script type="application/ld+json">' . json_encode($schemaData, JSON_PRETTY_PRINT) . '</script>' . "\n";
        }
    }
    // Global LocalBusiness schema
    $lb = $data['local_business'] ?? [];
    $lbSchema = generate_local_business_schema($lb);
    if ($lbSchema) echo '<script type="application/ld+json">' . $lbSchema . '</script>' . "\n";
    // Per-page Service schema
    $serviceSchema = generate_service_schema($seo, $lb);
    if ($serviceSchema) echo '<script type="application/ld+json">' . $serviceSchema . '</script>' . "\n";
    // Per-page FAQ schema (reads faq_two_col blocks on this page)
    $faqSchema = generate_faq_schema($contentBlocks ?? []);
    if ($faqSchema) echo '<script type="application/ld+json">' . $faqSchema . '</script>' . "\n";
    // Breadcrumb schema — only on slug pages (not homepage)
    if (!empty($slug)) {
        $lbUrl = rtrim($lb['lb_url'] ?? '', '/');

        // Relative URLs for the on-page breadcrumb nav (always stays on the current host)
        $bcItems = [['name' => 'Home', 'url' => '/']];
        if (!empty($seo['bc_mid_label'])) {
            $midUrlRel = trim($seo['bc_mid_url'] ?? '');
            $bcItems[] = ['name' => resolve_shortcodes($seo['bc_mid_label']), 'url' => $midUrlRel];
        }
        $bcLabel = !empty($seo['bc_label']) ? resolve_shortcodes($seo['bc_label']) : preg_replace('/\s*[|\-–—].*$/', '', $pageTitle);
        $bcItems[] = ['name' => $bcLabel, 'url' => '/' . ltrim($slug, '/')];

        // Absolute URLs for the BreadcrumbList JSON-LD (schema.org requires absolute URLs)
        $bcSchemaItems = [['name' => 'Home', 'url' => $lbUrl ?: '/']];
        if (!empty($seo['bc_mid_label'])) {
            $midUrl = trim($seo['bc_mid_url'] ?? '');
            if ($midUrl && !str_starts_with($midUrl, 'http')) $midUrl = $lbUrl . $midUrl;
            $bcSchemaItems[] = ['name' => resolve_shortcodes($seo['bc_mid_label']), 'url' => $midUrl];
        }
        $bcCurrentUrl = $canonicalUrl ?: ($lbUrl ? $lbUrl . '/' . $slug : '');
        $bcSchemaItems[] = ['name' => $bcLabel, 'url' => $bcCurrentUrl];

        $bcSchema = ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => []];
        foreach ($bcSchemaItems as $pos => $crumb) {
            $entry = ['@type' => 'ListItem', 'position' => $pos + 1, 'name' => $crumb['name']];
            if ($crumb['url']) $entry['item'] = $crumb['url'];
            $bcSchema['itemListElement'][] = $entry;
        }
        echo '<script type="application/ld+json">' . json_encode($bcSchema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>' . "\n";
    }
    // Analytics — output raw (admin-entered, trusted)
    if (!empty($theme['analytics_head'])) echo $theme['analytics_head'] . "\n";
    if (!empty($theme['facebook_pixel'])) echo $theme['facebook_pixel'] . "\n";
    ?>
</head>
<body>

<?php if (!empty($header['topbar_text'])): ?>
<div class="site-topbar">
    <?php if (!empty($header['topbar_link'])): ?>
        <a href="<?= h($header['topbar_link']) ?>"><?= h($header['topbar_text']) ?></a>
    <?php else: ?>
        <?= h($header['topbar_text']) ?>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php
$isSticky  = !empty($header['sticky']);
$navBg     = $header['nav_bg']          ?? '#fd783b';
$navText   = $header['nav_text']        ?? '#ffffff';
$btnStyle  = $header['phone_btn_style'] ?? 'outline';
$infoItems = $header['info_items']      ?? [];
?>
<header class="site-header<?= $isSticky ? ' site-header-sticky' : '' ?>">

    <!-- TOP ROW: logo + info items -->
    <div class="header-top-row">
        <div class="container header-top-inner">
            <div class="site-logo">
                <?php
                $logoHeight = (int)($header['logo_max_height'] ?? 56);
                $logoHeight = max(32, min(120, $logoHeight));
                ?>
                <?php if (!empty($header['logo'])): ?>
                    <a href="<?= h($homeUrl ?? '/') ?>"><img src="<?= h(($assetPathPrefix ?? '') . $header['logo']) ?>" alt="Logo" style="max-height:<?= $logoHeight ?>px;width:auto;display:block;"></a>
                <?php else: ?>
                    <a href="<?= h($homeUrl ?? '/') ?>" class="logo-text"><?= h(SITE_TITLE) ?></a>
                <?php endif; ?>
            </div>
            <div class="header-info-items">
                <?php if (!empty($header['city'])): ?>
                    <div class="header-info-item"><span class="info-icon">🌐</span><span><?= h($header['city']) ?></span></div>
                <?php endif; ?>
                <?php foreach ($infoItems as $item): ?>
                    <?php if (empty($item['text'])) continue; ?>
                    <div class="header-info-item">
                        <?php if (!empty($item['icon'])): ?><span class="info-icon"><?= h($item['icon']) ?></span><?php endif; ?>
                        <span><?= h($item['text']) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- BOTTOM ROW: nav bar -->
    <div class="header-nav-row" style="background:<?= h($navBg) ?>;">
        <div class="container header-nav-inner">

            <button class="nav-toggle" id="navToggle" aria-label="Toggle menu" aria-expanded="false">
                <span style="background:<?= h($navText) ?>;"></span>
                <span style="background:<?= h($navText) ?>;"></span>
                <span style="background:<?= h($navText) ?>;"></span>
            </button>

            <nav class="site-nav" id="siteNav">
                <ul>
                    <?php foreach ($header['menu'] as $item): ?>
                        <?php if (empty($item['label'])) continue; ?>
                        <?php $hasChildren = !empty($item['children']); ?>
                        <li class="<?= $hasChildren ? 'has-dropdown' : '' ?>">
                            <a href="<?= h($item['url'] ?: '#') ?>"
                               style="color:<?= h($navText) ?>;"
                               <?= $hasChildren ? 'aria-haspopup="true" aria-expanded="false"' : '' ?>>
                                <?= h($item['label']) ?>
                                <?php if ($hasChildren): ?><span class="dropdown-arrow" aria-hidden="true">&#9662;</span><?php endif; ?>
                            </a>
                            <?php if ($hasChildren): ?>
                            <ul class="dropdown-menu">
                                <?php foreach ($item['children'] as $child): ?>
                                    <?php if (empty($child['label'])) continue; ?>
                                    <li><a href="<?= h($child['url'] ?: '#') ?>"><?= h($child['label']) ?></a></li>
                                <?php endforeach; ?>
                            </ul>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </nav>

            <?php if (!empty($header['phone'])): ?>
                <div class="header-phone-wrap">
                    <div class="header-helpline">
                        <span class="helpline-label" style="color:<?= h($navText) ?>;">Helpline:</span>
                        <span class="helpline-sponsored" style="color:<?= h($navText) ?>;">
                            <?php if (!empty($data['popups']['info']['enabled'])): ?>
                                <button class="info-trigger" onclick="openInfoPopup()" aria-label="Info" style="color:<?= h($navText) ?>;">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2ZM11 7h2v2h-2zm0 4h2v6h-2z"/></svg>
                                </button>
                            <?php else: ?>
                                <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2ZM11 7h2v2h-2zm0 4h2v6h-2z"/></svg>
                            <?php endif; ?>
                            Sponsored
                        </span>
                    </div>
                    <a href="tel:<?= h($telHref) ?>"
                       class="header-phone-btn <?= $btnStyle === 'outline' ? 'header-phone-btn-outline' : 'header-phone-btn-filled' ?>"
                       style="<?= $btnStyle === 'outline'
                           ? 'border-color:'.h($navText).';color:'.h($navText).';'
                           : 'background:'.h($navBg).';color:'.h($navText).';' ?>">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" style="flex-shrink:0;">
                            <path d="M6.62 10.79c1.44 2.83 3.76 5.14 6.59 6.59l2.2-2.2c.27-.27.67-.36 1.02-.24 1.12.37 2.33.57 3.57.57.55 0 1 .45 1 1V20c0 .55-.45 1-1 1C7.61 21 1 14.39 1 6c0-.55.45-1 1-1h3.5c.55 0 1 .45 1 1 0 1.25.2 2.45.57 3.57.11.35.03.74-.25 1.02l-2.2 2.2z"/>
                        </svg>
                        <?= h($header['phone']) ?>
                    </a>
                </div>
            <?php endif; ?>

        </div>
    </div>

</header>
<main class="site-main">
    <?php if (!empty($slug) && isset($bcItems)): ?>
    <nav class="breadcrumb-bar" aria-label="Breadcrumb">
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
    foreach ($contentBlocks as $block):
        $btype = $block['type'] ?? '';
        $isFullWidth = in_array($btype, ['split_cta','cta_banner','wide_banner','links_grid','hero_grid','cta_card','map_info','hero_split','feature_split','faq_two_col','image_features','service_cards','tab_services','blog_list']);
    ?>
        <?php if (!$isFullWidth): ?>
        <div class="container">
        <?php endif; ?>
            <?php render_content_block($block, $assetPathPrefix ?? ''); ?>
        <?php if (!$isFullWidth): ?>
        </div>
        <?php endif; ?>
    <?php endforeach; ?>
</main>

<footer class="site-footer">

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
                <img class="footer-bottom-logo" src="<?= h(($assetPathPrefix ?? '') . $footer['logo']) ?>" alt="Logo">
            <?php endif; ?>
            <div class="footer-copyright"><?= h($footer['copyright']) ?></div>
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
<div class="sticky-bottom-bar" style="background:<?= h($header['nav_bg'] ?? '#fd783b') ?>;">
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
        style="background:<?= h($header['nav_bg'] ?? '#fd783b') ?>;color:<?= h($header['nav_text'] ?? '#ffffff') ?>;">
    ⬆
</button>

<?php
$infoPopup = $data['popups']['info'] ?? [];
if (!empty($infoPopup['enabled']) && (!empty($infoPopup['heading']) || !empty($infoPopup['body']))):
    function renderPopupBody($text) {
        $text = trim($text);
        if ($text === '') return '';
        // If rich editor HTML, output directly
        if (preg_match('/<[a-z][\s\S]*>/i', $text)) {
            return $text;
        }
        // Plain text — convert **bold**, auto-link URLs and phones, wrap paragraphs
        $safe = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        $safe = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $safe);
        // Auto-link https?:// URLs
        $safe = preg_replace(
            '/\bhttps?:\/\/[^\s<>"]+/i',
            '<a href="$0" target="_blank" rel="noopener">$0</a>',
            $safe
        );
        // Auto-link bare domains like katypestpros.com
        $safe = preg_replace(
            '/\b(?<!href=["\'])(?<!\/\/)([a-z0-9][a-z0-9\-]*\.[a-z]{2,}(?:\/[^\s<"]*)?)\b/i',
            '<a href="https://$1" target="_blank" rel="noopener">$1</a>',
            $safe
        );
        // Auto-link phone numbers
        $safe = preg_replace(
            '/\((\d{3})\)\s*(\d{3})-(\d{4})/',
            '<a href="tel:+1$1$2$3">($1) $2-$3</a>',
            $safe
        );
        $paras = preg_split('/\n\s*\n/', trim($safe));
        $html = '';
        foreach ($paras as $p) { $p = trim($p); if ($p !== '') $html .= '<p>' . nl2br($p) . '</p>'; }
        return $html;
    }
?>
<div class="info-popup-overlay" id="infoPopupOverlay" onclick="if(event.target===this)closeInfoPopup()">
    <div class="info-popup-box" role="dialog" aria-modal="true">
        <button class="info-popup-close" onclick="closeInfoPopup()" aria-label="Close">&times;</button>
        <?php if (!empty($infoPopup['image'])): ?>
            <img class="info-popup-image" src="<?= h(($assetPathPrefix ?? '') . $infoPopup['image']) ?>" alt="<?= h(resolve_shortcodes($infoPopup['heading'] ?? '')) ?>">
        <?php endif; ?>
        <div class="info-popup-content">
            <h2 class="info-popup-heading"><?= h(resolve_shortcodes($infoPopup['heading'] ?? '')) ?></h2>
            <div class="info-popup-body"><?= renderPopupBody(resolve_shortcodes($infoPopup['body'] ?? '')) ?></div>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
(function() {
    // Popup functions
    window.openInfoPopup = function() {
        var overlay = document.getElementById('infoPopupOverlay');
        if (overlay) {
            overlay.classList.add('is-open');
            document.body.style.overflow = 'hidden';
        }
    };
    window.closeInfoPopup = function() {
        var overlay = document.getElementById('infoPopupOverlay');
        if (overlay) {
            overlay.classList.remove('is-open');
            document.body.style.overflow = '';
        }
    };
    // Close on Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeInfoPopup();
    });
})();
</script>

<script>
(function() {
    // Fixed header offset — push content down by exact header height
    var stickyHeader = document.querySelector('.site-header-sticky');
    if (stickyHeader) {
        function setOffset() {
            var h = stickyHeader.offsetHeight;
            document.documentElement.style.setProperty('--fixed-header-height', h + 'px');
            var main = document.querySelector('main');
            if (main) main.style.marginTop = h + 'px';
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

    // Mobile dropdown toggles
    nav.querySelectorAll('li.has-dropdown > a').forEach(function(a) {
        a.addEventListener('click', function(e) {
            if (window.innerWidth <= 768) {
                e.preventDefault();
                var li = a.closest('li.has-dropdown');
                li.classList.toggle('open');
                a.setAttribute('aria-expanded', li.classList.contains('open') ? 'true' : 'false');
            }
        });
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
</body>
</html>
