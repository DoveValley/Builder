<?php
/**
 * Per-page "critical CSS" — ship only the CSS a page's actual block types need,
 * instead of the entire shared stylesheet on every page.
 *
 * Fail-open by design: css_critical_for_types() returns null the moment it sees a
 * block type that isn't in CSS_CRITICAL_BLOCK_MAP below, and the caller
 * (site-template.php) falls back to linking the full style.css exactly as it
 * always has — zero regression risk for any block type, niche, or page this
 * hasn't been verified against yet. Only grow CSS_CRITICAL_BLOCK_MAP for a type
 * after checking its real CSS class names against style.src.css by hand (see the
 * other entries' section-name lists for the pattern) — a wrong entry here ships a
 * page with missing CSS, which is a worse failure than just not optimizing it yet.
 *
 * Verified so far: exactly the block types gannmoldremediation.com (mold-site)
 * actually renders across its homepage, templated city pages, and the 4 static
 * pages (about-us, contact-us, privacy-policy, terms) — not all 39 registered
 * block types. A page using ANY unmapped type (gallery, stats, cards, team, any
 * other niche's own mix, a brand new block type) safely falls back to style.css.
 */

/**
 * Section header comment shape in style.src.css:
 *   /​* ================...
 *      TITLE (first line is the real name; a header can have more description
 *      lines before the closing fence, e.g. "FULL-WIDTH BLOCK OVERRIDES\n
 *      Reset content-block flex defaults...")
 *      ================... *​/
 * A line-by-line scan (not one big regex) so a multi-line title/description
 * can't be misread the way a regex quantifier easily gets wrong on this shape.
 *
 * @return array<string,string> section title => its raw CSS text, in file order.
 */
function css_parse_sections(string $srcPath): array {
    static $cache = [];
    $mtime = @filemtime($srcPath);
    if ($mtime === false) return [];
    if (isset($cache[$srcPath]) && $cache[$srcPath]['mtime'] === $mtime) {
        return $cache[$srcPath]['sections'];
    }

    $lines = @file($srcPath);
    if ($lines === false) return [];

    $sections = [];
    $currentName = null;
    $currentBuf  = [];
    $n = count($lines);
    $i = 0;
    while ($i < $n) {
        if (preg_match('/^\/\*[ \t]*=+[ \t]*$/', rtrim($lines[$i]))) {
            // Open fence found (no closing "*/" on this same line — that's the
            // single-line form css_minify()'s comment-stripper already handles
            // fine, this is only about finding where the TITLE is). The closing
            // fence isn't always its own "===*/ " line — some headers run on into
            // several lines of description prose and only close with "*/" buried
            // at the end of a later line. So: title = the first non-blank line
            // that isn't itself a bare "=== " fence; end-of-header = the first
            // line (within a generous cap) that contains "*/" ANYWHERE.
            $j = $i + 1;
            $title = null;
            $sawClose = false;
            $cap = min($n, $i + 20);
            while ($j < $cap) {
                $t = trim($lines[$j]);
                if ($title === null && $t !== '' && !preg_match('/^=+$/', $t)) $title = $t;
                if (strpos($lines[$j], '*/') !== false) { $sawClose = true; break; }
                $j++;
            }
            if ($sawClose && $title !== null) {
                if ($currentName !== null) $sections[$currentName] = implode('', $currentBuf);
                $currentName = $title;
                $currentBuf  = [];
                $i = $j + 1;
                continue;
            }
        }
        $currentBuf[] = $lines[$i];
        $i++;
    }
    if ($currentName !== null) $sections[$currentName] = implode('', $currentBuf);

    $cache[$srcPath] = ['mtime' => $mtime, 'sections' => $sections];
    return $sections;
}

/** Sections every page needs regardless of which blocks it uses: reset, header,
 *  footer, nav (all 3 header/footer layout variants — which one applies is a
 *  theme setting, not a per-page thing), sticky bits, and the handful of
 *  generic content-chrome classes (.content-block, .section-heading, .cta-btn,
 *  breadcrumbs, etc.) too widely reused across block types to safely attribute
 *  to just one. */
function css_base_section_names(): array {
    return [
        'Base / Reset',
        'Public site header',
        'Public site main content / content blocks',
        'Public site footer',
        'NEW BLOCK TYPES',
        'TOP ANNOUNCEMENT BAR',
        'HEADER: city label + hamburger',
        'MOBILE NAV',
        'DROPDOWN NAVIGATION',
        'COMPREHENSIVE RESPONSIVE FIXES (v16 mobile/tablet audit)',
        'TWO-ROW HEADER + STICKY',
        'MOBILE: two-row header',
        'HEADER LAYOUT: Single Row',
        'SMOOTH SCROLL + ANCHOR OFFSET FOR STICKY HEADER',
        'FOOTER DISCLAIMER — between main footer and copyright bar',
        'STICKY BOTTOM BAR',
        'SCROLL TO TOP BUTTON',
        'FOOTER COLUMN TYPES',
        'SIMPLE 3-COLUMN FOOTER',
        'INFO TRIGGER BUTTON (ℹ️ circle)',
        'INFO POPUP',
        'BLOCK SECTION WRAPPER (base skin system — every block renders inside this)',
        'HEADER LIVE-TUNING OVERRIDES (ported from compiled style.css,',
        'MOBILE FOCAL POINT + MOBILE IMAGE VARIANT OVERRIDE',
    ];
}

/** Verified block-type -> extra section name(s), beyond the base bundle above.
 *  An empty array means the type is verified SAFE with base alone (its CSS lives
 *  entirely in a base section, or it renders with no CSS classes of its own —
 *  e.g. trust_bar is 100% inline-styled). A type NOT present as a key here is
 *  UNVERIFIED, not "assumed safe with base" — see css_critical_for_types(). */
const CSS_CRITICAL_BLOCK_MAP = [
    'hero_split'      => ['HERO SPLIT BLOCK (text left, image right)'],
    'feature_split'   => ['FEATURE SPLIT BLOCK (icon grid left, arched image right)',
                           'SKIN CONTRAST OVERRIDES (feature_split, tab_services, service_cards, faq_two_col, image_features, links_grid)'],
    'split_cta'       => ['SPLIT CTA BLOCK (two colored panels side by side)', 'FULL-WIDTH BLOCK OVERRIDES'],
    'tab_services'    => ['TAB SERVICES BLOCK',
                           'SKIN CONTRAST OVERRIDES (feature_split, tab_services, service_cards, faq_two_col, image_features, links_grid)'],
    'hero_grid'       => ['HERO GRID BLOCK (image left, icon grid right)', 'FULL-WIDTH BLOCK OVERRIDES'],
    'related_links'   => ['RELATED LINKS BLOCK'],
    'wide_banner'      => ['WIDE BANNER BLOCK (full-width bg image, heading left, btn right)', 'FULL-WIDTH BLOCK OVERRIDES'],
    'image_features'  => ['IMAGE FEATURES BLOCK (photo left, checklist + phone right)',
                           'SKIN CONTRAST OVERRIDES (feature_split, tab_services, service_cards, faq_two_col, image_features, links_grid)'],
    'steps'           => ['PROCESS STEPS'],
    'faq_two_col'     => ['FAQ TWO COLUMN BLOCK',
                           'SKIN CONTRAST OVERRIDES (feature_split, tab_services, service_cards, faq_two_col, image_features, links_grid)'],
    'cta_banner'      => ['CTA BANNER BLOCK (solid color, centered text)', 'FULL-WIDTH BLOCK OVERRIDES'],
    'cta_card'        => ['CTA CARD BLOCK (colored box, heading left, phone right)'],
    'map_info'        => ['MAP + INFO BLOCK',
                           'IMAGE CAPTIONS + CHART DATA TABLE (image_left, image_right, image_text, map_info)'],
    'trust_bar'       => [],   // fully inline-styled render, confirmed zero CSS classes of its own
    'image_text'      => ['IMAGE CAPTIONS + CHART DATA TABLE (image_left, image_right, image_text, map_info)'],
    'feature_columns' => [],   // its CSS lives in the base "NEW BLOCK TYPES" section
    'service_cards'   => ['SERVICE CARDS GRID (icon circle + heading + text, centered)',
                           'SKIN CONTRAST OVERRIDES (feature_split, tab_services, service_cards, faq_two_col, image_features, links_grid)'],
    'text'            => [],   // base "Public site main content" covers .content-text/.text-only
    'contact_form'    => ['CONTACT FORM BLOCK'],
    // custom_html deliberately has NO entry — never add one. Its content can be a
    // [shortcode] expanded by any plugin hooked to 'shortcode_content' (confirmed:
    // plugins/services_links/plugin.php renders full .block-links-grid/.lg-*
    // markup into a custom_html block — found live on gannmoldremediation.com's
    // contact-us page, broken exactly this way before this comment was written).
    // The block's declared type can never tell you what CSS its actual rendered
    // content needs, so it can never be "verified safe" — absence here is what
    // correctly forces the fallback to the full stylesheet. The ONE narrow,
    // verified exception is 'custom_html_services_links' below, a pseudo-type
    // css_critical_block_type() only ever returns for a block whose ENTIRE
    // content is nothing but the [services_links] shortcode alone — see that
    // function's docblock for why that specific, narrow case is safe where the
    // general one is not.
    'custom_html_services_links' => ['LINKS GRID BLOCK (bg image, heading, link buttons grid)',
                                      'LINKS GRID — LIGHT STYLE (white bg, gray bordered boxes)'],
    'blog_list'       => ['BLOG'],
    'post_meta'       => ['BLOG'],
];

/**
 * Resolve the real CSS-relevant type for a content block — an ai_block is a
 * proxy that renders as whatever ai_render_as says (see the 'ai_block' case in
 * render_content_block(), includes/blocks.php), so its CSS needs are that type's,
 * not "ai_block" itself (which isn't a real type and carries no CSS).
 *
 * custom_html gets one narrow, verified special case instead of the blanket
 * "always unmapped" rule: a block whose ENTIRE trimmed content is nothing but
 * the [services_links] shortcode and nothing else. Unlike arbitrary admin-typed
 * custom_html, this is a first-party plugin (plugins/services_links/plugin.php)
 * with a small, fixed set of CSS classes under our own control — found live on
 * wrenappliancerepair.com's homepage (same shortcode-in-custom_html pattern that
 * broke gannmoldremediation.com's contact-us page, just here rendering ALONE
 * with nothing else mixed in, so its real CSS needs are fully knowable). Any
 * OTHER custom_html content — raw HTML, multiple shortcodes, anything mixed
 * with this one — still returns the plain 'custom_html' type, which has no
 * entry in CSS_CRITICAL_BLOCK_MAP and so still falls back to the full
 * stylesheet, exactly as before.
 */
function css_critical_block_type(array $block): string {
    $type = $block['type'] ?? '';
    if ($type === 'ai_block') {
        return (string) ($block['ai_render_as'] ?? '');
    }
    if ($type === 'custom_html') {
        $html = trim((string) ($block['html'] ?? ''));
        if (preg_match('/^\[services_links(?:\s[^\]]*)?\]$/', $html)) {
            return 'custom_html_services_links';
        }
    }
    return (string) $type;
}

/**
 * Build (or reuse, mtime-cached) a trimmed+minified CSS file covering exactly
 * the base bundle plus every type in $types. Returns null — "not safe to trim,
 * fall back to the full stylesheet" — the instant any type isn't a verified key
 * in CSS_CRITICAL_BLOCK_MAP, INCLUDING an ai_block whose ai_render_as resolved
 * to '' or something unmapped.
 *
 * Cache key is the sorted unique type set, not the page — many pages share the
 * identical block-type combination (e.g. every templated city page), so this
 * avoids re-minifying the same subset over and over.
 *
 * @return array{path:string, mtime:int}|null
 */
function css_critical_for_types(array $types, string $srcPath, string $cacheDir): ?array {
    // TEMPORARILY DISABLED — three separate real bugs traced to this system in one
    // session (silent permission failures leaving stale per-page CSS, a cache file
    // shared across niches with identical block-type lists, and a page's rendered
    // HTML referencing a hash that didn't match what the current block list
    // actually needs). The function's own fail-open design (returning null falls
    // every caller back to the always-correct full style.css) makes this the safe
    // kill switch rather than ripping the feature out — re-enable once the exact
    // mechanism behind the hash/filename mismatch is understood, not before.
    return null;

    $unique = array_values(array_unique(array_filter($types, fn($t) => $t !== '')));
    foreach ($unique as $t) {
        if (!array_key_exists($t, CSS_CRITICAL_BLOCK_MAP)) return null;
    }

    $sections = css_parse_sections($srcPath);
    if (!$sections) return null;

    $needed = css_base_section_names();
    foreach ($unique as $t) {
        foreach (CSS_CRITICAL_BLOCK_MAP[$t] as $extra) $needed[] = $extra;
    }
    $needed = array_unique($needed);

    sort($unique);
    $hash = substr(md5(implode(',', $unique)), 0, 12);
    $outPath = rtrim($cacheDir, '/') . '/' . $hash . '.css';

    // Always recompute and overwrite, rather than trusting outPath's mtime against
    // srcPath's — this file is a build artifact of a batch process, not a per-request
    // hot path, so the cost of always rewriting it is trivial. The old "skip if
    // outPath looks newer than srcPath" check was found silently serving stale
    // content across regens for reasons never fully pinned down (observed: a build
    // that should have produced current CSS instead produced an older cached
    // version, byte-for-byte, with no code or source-file change in between) —
    // unconditional overwrite removes the whole class of bug instead of chasing
    // the exact mechanism.
    $css = '';
    foreach ($sections as $name => $text) {
        if (in_array($name, $needed, true)) $css .= $text;
    }
    $minified = css_minify($css);
    if ($minified === '') return null;
    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);
    if (@file_put_contents($outPath, $minified) === false) return null;
    $outMtime = @filemtime($outPath);
    return $outMtime !== false ? ['path' => $outPath, 'mtime' => $outMtime] : null;
}
