(function() {
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
var scrollBtn = document.getElementById('scrollToTop');
if (scrollBtn) {
window.addEventListener('scroll', function() {
scrollBtn.classList.toggle('visible', window.scrollY > 400);
});
scrollBtn.addEventListener('click', function() {
window.scrollTo({ top: 0, behavior: 'smooth' });
});
}
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
window.toggleFaq = function(id) {
var answer = document.getElementById(id);
if (!answer) return;
var isHidden = answer.hasAttribute('hidden');
answer.toggleAttribute('hidden', !isHidden);
var btn = answer.previousElementSibling;
if (btn) btn.setAttribute('aria-expanded', isHidden ? 'true' : 'false');
};
document.addEventListener('click', function(e) {
var card = e.target.closest('.flip-card');
if (card && card.tagName !== 'A') card.classList.toggle('is-flipped');
});
window.switchTab = function(btn) {
var uid = btn.dataset.uid;
var layout = document.getElementById(uid);
if (!layout) return;
var activeBg = btn.dataset.activeBg || 'var(--color-header-bg,#120575)';
var activeText = btn.dataset.activeText || '#fff';
layout.querySelectorAll('.ts-tab').forEach(function(t) {
t.classList.remove('ts-tab-active');
t.style.background = '';
t.style.color = '';
var icon = t.querySelector('.ts-tab-icon');
if (icon) icon.style.filter = '';
});
layout.querySelectorAll('.ts-panel').forEach(function(p) {
p.setAttribute('hidden', '');
});
btn.classList.add('ts-tab-active');
btn.style.background = activeBg;
btn.style.color = activeText;
var activeIcon = btn.querySelector('.ts-tab-icon');
if (activeIcon) activeIcon.style.filter = (activeText === '#fff' || activeText === '#ffffff') ? '' : 'none';
var panel = layout.querySelector('.ts-panel[data-panel="' + btn.dataset.tab + '"]');
if (panel) panel.removeAttribute('hidden');
};
var toggle = document.getElementById('navToggle');
var nav    = document.getElementById('siteNav');
if (!toggle || !nav) return;
toggle.addEventListener('click', function() {
var open = nav.classList.toggle('is-open');
toggle.classList.toggle('is-open', open);
toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
document.documentElement.style.overflow = open ? 'hidden' : '';
if (open) {
nav.style.marginTop = '0';
nav.style.borderTop = 'none';
nav.querySelectorAll('a[aria-haspopup="true"]').forEach(function(a) {
var li = a.closest('li');
if (li) li.classList.add('open');
a.setAttribute('aria-expanded', 'true');
});
var navRect = nav.getBoundingClientRect();
var stickyBar = document.querySelector('.sticky-bottom-bar');
var stickyBarHeight = stickyBar ? stickyBar.getBoundingClientRect().height : 0;
nav.style.height = (window.innerHeight - navRect.top - stickyBarHeight) + 'px';
nav.style.overflowY = 'auto';
nav.style.background = getComputedStyle(nav.closest('.header-nav-row')).backgroundColor;
} else {
nav.style.height = '';
nav.style.overflowY = '';
nav.style.background = '';
nav.style.marginTop = '';
nav.style.borderTop = '';
}
});
nav.querySelectorAll('a[aria-haspopup="true"]').forEach(function(a) {
a.addEventListener('click', function(e) {
var li = a.closest('li');
if (window.innerWidth <= 768) {
e.preventDefault();
var isOpen = li.classList.toggle('open');
a.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
} else {
if (a.getAttribute('href') === '#') e.preventDefault();
a.blur();
}
});
});
document.addEventListener('click', function(e) {
if (!nav.contains(e.target) && e.target !== toggle && !toggle.contains(e.target)) {
nav.querySelectorAll('a[aria-haspopup="true"]').forEach(function(a) { a.closest('li').classList.remove('open'); });
}
});
document.addEventListener('keydown', function(e) {
if (e.key === 'Escape') {
nav.querySelectorAll('a[aria-haspopup="true"]').forEach(function(a) { a.closest('li').classList.remove('open'); });
}
});
function closeMobileNav() {
nav.classList.remove('is-open');
toggle.classList.remove('is-open');
toggle.setAttribute('aria-expanded', 'false');
document.documentElement.style.overflow = '';
}
nav.querySelectorAll('a:not([aria-haspopup="true"])').forEach(function(a) {
a.addEventListener('click', function() {
if (a.getAttribute('href').charAt(0) === '#') closeMobileNav();
});
});
window.addEventListener('pagehide', closeMobileNav);
})();