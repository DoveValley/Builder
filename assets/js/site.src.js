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

    // The nav/phone row stays pinned to the top on scroll via real CSS
    // position:sticky (.header-nav-row.nav-row-sticky-enabled in
    // style.src.css) — the browser's compositor handles it natively, so
    // there's no scroll listener, threshold math, or spacer element here at
    // all. An earlier version faked this in JS (position:fixed toggled from
    // a 'scroll' event) because of an old assumption that this site's
    // overflow-x:hidden/clip on html/body broke native sticky everywhere;
    // that's only true for Safari below 16 falling back to plain
    // overflow-x:hidden (see the comment on that rule) — negligible today,
    // and not worth carrying this much JS to work around.

    // Deferred backgrounds (wide_banner, a non-lead hero_grid — see includes/blocks.php's
    // data-bg-lazy comment). --bg/--bgm are already DECLARED on these elements as custom
    // properties, which fetches nothing by itself; setting background-image here is what
    // actually triggers the download, at the moment we choose rather than at first paint.
    // var(--bgm, var(--bg)) mirrors style.src.css's own mobile fallback chain.
    var lazyBgEls = document.querySelectorAll('[data-bg-lazy]');
    if (lazyBgEls.length) {
        if ('IntersectionObserver' in window) {
            var bgObserver = new IntersectionObserver(function(entries) {
                entries.forEach(function(entry) {
                    if (!entry.isIntersecting) return;
                    entry.target.style.backgroundImage = 'var(--bgm, var(--bg))';
                    bgObserver.unobserve(entry.target);
                });
            }, { rootMargin: '200px' });
            lazyBgEls.forEach(function(el) { bgObserver.observe(el); });
        } else {
            lazyBgEls.forEach(function(el) { el.style.backgroundImage = 'var(--bgm, var(--bg))'; });
        }
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

    // ---- Flip cards: tap to flip on touch (hover handles desktop) ----
    document.addEventListener('click', function(e) {
        var card = e.target.closest('.flip-card');
        if (card && card.tagName !== 'A') card.classList.toggle('is-flipped');
    });

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
        // Lock page scroll while the menu is open. The panel above is capped to
        // its own internal scroll, but nothing was stopping the PAGE underneath
        // from scrolling too — on a real phone a finger usually lands on the
        // panel itself so this went unnoticed, but any input that isn't a touch
        // literally on the panel (a mouse wheel, or the touch simulation used by
        // a browser's device-emulation mode) scrolled the page behind it, which
        // showed through in the gap around the panel. One line, fixes it outright
        // instead of relying on the panel's footprint to happen to cover input.
        document.documentElement.style.overflow = open ? 'hidden' : '';
        // The open panel is always position:absolute off .header-nav-row (see
        // .header-nav-row .site-nav.is-open in style.src.css), top:100% — so it's
        // always flush below the nav bar with no position math needed here, whether
        // that bar is currently sticky-pinned or sitting in normal flow. Only the
        // height still needs JS: how much room is available below the bar depends
        // on the live viewport height, which CSS alone can't see.
        if (open) {
            // A bare ".site-nav" rule elsewhere (written for a different header
            // layout, where this element is a divider sitting in normal flow) adds
            // an 8px margin-top + hairline border-top. Harmless there; here it left
            // an 8px gap between the bar and the panel that let the page peek
            // through. Must happen BEFORE the rect measurement below — clearing it
            // AFTER measuring meant the measured top still included the 8px that
            // was about to be removed, so every height computed from it came out
            // 8px short once the margin actually disappeared.
            nav.style.marginTop = '0';
            nav.style.borderTop = 'none';
            // Services (and any other accordion item) starts expanded on mobile —
            // no second tap needed after opening the hamburger menu. Reuses the
            // same 'open' class/aria-expanded wiring the accordion toggle below
            // already uses, so this can't drift out of sync with it.
            nav.querySelectorAll('a[aria-haspopup="true"]').forEach(function(a) {
                var li = a.closest('li');
                if (li) li.classList.add('open');
                a.setAttribute('aria-expanded', 'true');
            });
            var navRect = nav.getBoundingClientRect();
            // The mobile sticky call bar is also position:fixed at the bottom of the
            // viewport, on top of the nav (higher z-index) — reserve its height so the
            // panel stops above it instead of running underneath it out of sight.
            var stickyBar = document.querySelector('.sticky-bottom-bar');
            var stickyBarHeight = stickyBar ? stickyBar.getBoundingClientRect().height : 0;
            // height, not max-height: a short menu (few top-level links, nothing
            // expanded) should still fill all the way down to the sticky bottom bar,
            // not shrink-wrap to its own content and leave a gap where the page
            // shows through. overflow-y:auto still kicks in if an accordion expands
            // past this height — the fixed height becomes the scroll viewport, it
            // doesn't clip content the way max-height plus real content would.
            nav.style.height = (window.innerHeight - navRect.top - stickyBarHeight) + 'px';
            nav.style.overflowY = 'auto';
            // Solid backing so the page underneath a fixed, always-on-top panel
            // doesn't show through a translucent one — was fine when this only ever
            // sat in normal document flow (nothing to show through), became a visible
            // double-exposure once it started floating over the page for every open.
            nav.style.background = getComputedStyle(nav.closest('.header-nav-row')).backgroundColor;
        } else {
            nav.style.height = '';
            nav.style.overflowY = '';
            nav.style.background = '';
            nav.style.marginTop = '';
            nav.style.borderTop = '';
        }
    });

    // Dropdown toggle: mobile = click accordion; desktop = click closes others, Esc closes.
    // Selected by [aria-haspopup="true"], NOT a "has-dropdown" class — the anti-fingerprint
    // class-vocabulary rename pass (includes/multisite/class_vocab.php) only spares a class
    // name if it detects the class referenced from JS, via specific regex shapes ('.class',
    // classList.x('class'), className===). "li.has-dropdown > a" doesn't match any of those
    // (the dot isn't at the very start of the quoted string), so on every real deployed
    // domain the pass silently renamed the class in the HTML while this selector kept
    // looking for the old name — the mobile accordion opened for nobody. aria-haspopup is a
    // plain attribute, never touched by that pass, so this can't regress the same way.
    nav.querySelectorAll('a[aria-haspopup="true"]').forEach(function(a) {
        a.addEventListener('click', function(e) {
            var li = a.closest('li');
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

    // Close all dropdowns when clicking outside the nav.
    // #navToggle is a SIBLING of #siteNav, not a descendant, so its own click
    // bubbles to this document listener same as any outside click — it was
    // stripping the 'open' class the hamburger handler above had just added,
    // in the same click event, which is why auto-expanding Services on open
    // silently did nothing. Ignoring clicks on the toggle itself fixes it
    // without weakening this handler's real job (closing dropdowns for an
    // ACTUAL outside click, e.g. tapping the page behind the menu).
    document.addEventListener('click', function(e) {
        if (!nav.contains(e.target) && e.target !== toggle && !toggle.contains(e.target)) {
            nav.querySelectorAll('a[aria-haspopup="true"]').forEach(function(a) { a.closest('li').classList.remove('open'); });
        }
    });

    // Esc key closes all dropdowns
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            nav.querySelectorAll('a[aria-haspopup="true"]').forEach(function(a) { a.closest('li').classList.remove('open'); });
        }
    });

    // Close everything when a leaf link is clicked.
    function closeMobileNav() {
        nav.classList.remove('is-open');
        toggle.classList.remove('is-open');
        toggle.setAttribute('aria-expanded', 'false');
        document.documentElement.style.overflow = '';
    }
    nav.querySelectorAll('a:not([aria-haspopup="true"])').forEach(function(a) {
        a.addEventListener('click', function() {
            // A real page link is about to navigate away, so closing the menu
            // here — unlocking scroll, hiding the panel — forces a reflow of
            // the CURRENT page a moment before the browser actually swaps in
            // the destination. That reflow gets painted (briefly showing the
            // old page, now unscrolled-and-unlocked) right before navigation
            // completes, reading as "the menu closes, shows a page, then
            // flickers to a second page" — the flicker is real, it's just the
            // old page's new layout, not an actual double navigation. Deferred
            // to 'pagehide' below instead, which fires as the browser is
            // already tearing the page down, so nothing from it gets painted.
            // An in-page anchor (href="#...") is the one case that's exempt —
            // it doesn't navigate away at all, so nothing will ever fire
            // pagehide, and skipping the close here would leave the menu
            // open on the same page with no page-hide to save it.
            if (a.getAttribute('href').charAt(0) === '#') closeMobileNav();
        });
    });
    // Catches real navigations (including the back/forward button), so a
    // page restored from the browser's cache never comes back with the menu
    // still open or scroll still locked from when it was left.
    window.addEventListener('pagehide', closeMobileNav);
})();
