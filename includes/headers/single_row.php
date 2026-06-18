<?php
/* ============================================================
   HEADER LAYOUT: Single Row
   One colored bar: logo left · nav center · phone right
   No top info-items row. Topbar announcement still supported.
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

<header class="site-header site-header-single-row<?= $isSticky ? ' site-header-sticky' : '' ?>">
    <div class="header-sr-bar" style="background:<?= h($navBg) ?>;">
        <div class="container header-sr-inner">

            <!-- Logo -->
            <div class="site-logo site-logo-sr">
                <?php if (!empty($header['logo'])): ?>
                    <a href="<?= h($homeUrl ?? '/') ?>"><img src="<?= h(($assetPathPrefix ?? '') . $header['logo']) ?>" alt="Logo" style="max-height:<?= min($logoHeight, 52) ?>px;width:auto;display:block;"></a>
                <?php else: ?>
                    <a href="<?= h($homeUrl ?? '/') ?>" class="logo-text" style="color:<?= h($navText) ?>;"><?= h(SITE_TITLE) ?></a>
                <?php endif; ?>
            </div>

            <!-- Hamburger (mobile only) -->
            <button class="nav-toggle" id="navToggle" aria-label="Toggle menu" aria-expanded="false">
                <span style="background:<?= h($navText) ?>;"></span>
                <span style="background:<?= h($navText) ?>;"></span>
                <span style="background:<?= h($navText) ?>;"></span>
            </button>

            <!-- Nav -->
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

            <!-- Phone button -->
            <?php if (!empty($header['phone'])): ?>
                <div class="header-phone-wrap">
                    <a href="tel:<?= h($telHref) ?>"
                       class="header-phone-btn <?= $btnStyle === 'outline' ? 'header-phone-btn-outline' : 'header-phone-btn-filled' ?>"
                       style="<?= $btnStyle === 'outline'
                           ? 'border-color:'.h($navText).';color:'.h($navText).';'
                           : 'background:var(--color-accent,'.h($navText).');color:#fff;' ?>">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" style="flex-shrink:0;">
                            <path d="M6.62 10.79c1.44 2.83 3.76 5.14 6.59 6.59l2.2-2.2c.27-.27.67-.36 1.02-.24 1.12.37 2.33.57 3.57.57.55 0 1 .45 1 1V20c0 .55-.45 1-1 1C7.61 21 1 14.39 1 6c0-.55.45-1 1-1h3.5c.55 0 1 .45 1 1 0 1.25.2 2.45.57 3.57.11.35.03.74-.25 1.02l-2.2 2.2z"/>
                        </svg>
                        <?= h($header['phone']) ?>
                    </a>
                </div>
            <?php endif; ?>

            <!-- Optional CTA button -->
            <?php if ($ctaText): ?>
                <a href="<?= h($ctaUrl) ?>" class="header-cta-btn" style="border-color:<?= h($navText) ?>;color:<?= h($navText) ?>;"><?= h($ctaText) ?></a>
            <?php endif; ?>

        </div>
    </div>
</header>
