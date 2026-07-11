<?php
/* ============================================================
   HEADER LAYOUT: Standard (2-row)
   Row 1 — white: logo + info badges
   Row 2 — colored: nav + phone
   Variables provided by site-template.php before include:
   $isSticky, $navBg, $navText, $btnStyle, $infoItems,
   $logoHeight, $phoneLabel, $showSponsored, $ctaText, $ctaUrl
   ============================================================ */
?>
<?php if (!empty($header['topbar_text'])): ?>
<div class="site-topbar">
    <?php if (!empty($header['topbar_link'])): ?>
        <a href="<?= h($header['topbar_link']) ?>"><?= h($header['topbar_text']) ?></a>
    <?php else: ?>
        <?= h($header['topbar_text']) ?>
    <?php endif; ?>
</div>
<?php endif; ?>

<header class="site-header<?= $isSticky ? ' site-header-sticky' : '' ?>">

    <!-- TOP ROW: logo + info items -->
    <div class="header-top-row">
        <div class="container header-top-inner">
            <div class="site-logo">
                <?php if (!empty($header['logo'])): ?>
                    <a href="<?= h($homeUrl ?? '/') ?>"><img src="<?= h(admin_upload_url_v($header['logo'])) ?>" alt="Logo" <?= img_dim_attrs($header['logo'], (int) $logoHeight) ?>style="max-height:<?= $logoHeight ?>px;height:auto;width:auto;max-width:100%;display:block;"></a>
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
            <?php $headerSocials = array_filter($header['socials'] ?? []); ?>
            <?php if (!empty($headerSocials)): ?>
            <div class="header-socials">
                <?php $socialLabels = ['facebook'=>'Facebook','instagram'=>'Instagram','twitter'=>'X','youtube'=>'YouTube','linkedin'=>'LinkedIn','tiktok'=>'TikTok','yelp'=>'Yelp']; ?>
                <?php foreach ($headerSocials as $platform => $url): ?>
                    <a href="<?= h($url) ?>" class="header-social-link" target="_blank" rel="noopener noreferrer"><?= h($socialLabels[$platform] ?? ucfirst($platform)) ?></a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
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
                    <?php if ($phoneLabel || $showSponsored): ?>
                    <div class="header-helpline">
                        <?php if ($phoneLabel): ?>
                            <span class="helpline-label" style="color:<?= h($navText) ?>;"><?= h($phoneLabel) ?></span>
                        <?php endif; ?>
                        <?php if ($showSponsored): ?>
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
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
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
            <?php if ($ctaText): ?>
                <a href="<?= h($ctaUrl) ?>" class="header-cta-btn" style="border-color:<?= h($navText) ?>;color:<?= h($navText) ?>;"><?= h($ctaText) ?></a>
            <?php endif; ?>

        </div>
    </div>

</header>
