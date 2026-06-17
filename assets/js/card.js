(function () {
    'use strict';

    // Data is always inlined via csm2AllData before this script runs.
    // REST_BASE is a fallback only — in practice, csm2AllData[id] is always defined.
    var REST_BASE = '/schedule-data';

    var tzOffsets = { EST: 0, CST: -1, MST: -2, PST: -3 };

    function convertTime(t, tz) {
        if (!t) return '';
        var l = t.toLowerCase().trim();
        if (l === 'self-paced' || l === 'anytime') return t;
        var d = tzOffsets[tz] !== undefined ? tzOffsets[tz] : 0;
        return t.replace(/(\d{1,2}):(\d{2})\s*(am|pm)/gi, function (m, h, mn, ap) {
            var hr = parseInt(h, 10);
            ap = ap.toLowerCase();
            if (ap === 'pm' && hr !== 12) hr += 12;
            if (ap === 'am' && hr === 12) hr = 0;
            hr += d; if (hr < 0) hr += 24; if (hr >= 24) hr -= 24;
            return (hr % 12 || 12) + ':' + mn + (hr < 12 ? 'am' : 'pm');
        });
    }

    function fmtPrice(val) {
        var n = parseFloat(val);
        return (!isNaN(n) && n > 0) ? '$' + n.toLocaleString('en-US', {minimumFractionDigits:0,maximumFractionDigits:0}) : '';
    }
    function esc(str) {
        if (!str && str !== 0) return '';
        var el = document.createElement('span'); el.textContent = String(str); return el.innerHTML;
    }
    function escA(str) {
        if (!str) return '';
        return String(str).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }

    function renderWidget(wrap, courses) {
        var startTab = wrap.getAttribute('data-start-tab');
        var delivery = (startTab === '2') ? 'On-Demand' : 'Live-Virtual';
        var tz       = 'EST';
        var cardsEl  = wrap.querySelector('.csm2-cards');
        var labelEl  = wrap.querySelector('.csm2-section-label');
        var tzSelect = wrap.querySelector('.csm2-tz-select');

        function render() {
            var filtered = courses.filter(function(c) { return c.delivery === delivery; });

            if (labelEl) { labelEl.style.display = 'none'; }

            var tzRow = wrap.querySelector('.csm2-tz-row');
            if (tzRow) tzRow.style.display = delivery === 'On-Demand' ? 'none' : 'block';
            if (!cardsEl) return;

            if (filtered.length === 0) {
                cardsEl.innerHTML = '<div class="csm2-empty">No sessions scheduled at this time.</div>';
                return;
            }

            cardsEl.innerHTML = filtered.map(function(c) {
                var isSP = !c.time_est || c.time_est.toLowerCase() === 'self-paced' || c.time_est.toLowerCase() === 'anytime';
                var td   = isSP ? c.time_est : convertTime(c.time_est, tz) + ' ' + tz;
                var url  = escA(c.register_url || '#');
                var badges = (c.guaranteed ? '<span class="csm2-badge-blue">✓ Pass Guarantee</span>' : '')
                           + (c.availability_note ? '<span class="csm2-badge-gold">★ ' + esc(c.availability_note) + '</span>' : '');
                var prH  = (c.old_price > 0 ? '<span class="csm2-old-price">' + esc(fmtPrice(c.old_price)) + '</span>' : '')
                         + '<span class="csm2-price">' + esc(fmtPrice(c.price)) + '</span>';
                return '<div class="csm2-card">'
                    + '<div class="csm2-card-top"><div class="csm2-card-left">'
                    + '<p class="csm2-dates">' + esc(c.dates) + '</p>'
                    + '<p class="csm2-time">' + esc(td) + '</p>'
                    + (badges ? '<div class="csm2-badges">' + badges + '</div>' : '')
                    + '</div><div class="csm2-card-right">' + prH
                    + '<a href="' + url + '" target="_blank" rel="noopener noreferrer" class="csm2-register-btn">REGISTER</a>'
                    + '</div></div>'
                    + '</div>';
            }).join('');
        }

        wrap.querySelectorAll('.csm2-tab').forEach(function(tab) {
            tab.addEventListener('click', function() {
                wrap.querySelectorAll('.csm2-tab').forEach(function(t) { t.classList.remove('csm2-active'); });
                this.classList.add('csm2-active');
                delivery = this.getAttribute('data-delivery') || 'Live-Virtual';
                render();
            });
        });
        if (tzSelect) { tzSelect.addEventListener('change', function() { tz = this.value; render(); }); }
        render();
    }

    function initWidget(wrap) {
        var id      = wrap.getAttribute('id');
        var allData = (typeof csm2AllData !== 'undefined') ? csm2AllData : null;

        if (allData && allData[id]) {
            renderWidget(wrap, allData[id].courses || []);
            return;
        }

        var type    = wrap.getAttribute('data-type') || 'all';
        var cardsEl = wrap.querySelector('.csm2-cards');
        if (cardsEl) cardsEl.innerHTML = '<div class="csm2-empty">Loading&#8230;</div>';

        fetch(REST_BASE + '?type=' + encodeURIComponent(type))
            .then(function(r) {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.json();
            })
            .then(function(courses) { renderWidget(wrap, Array.isArray(courses) ? courses : []); })
            .catch(function(e) {
                console.error('CSM Widget 2 fetch failed:', e);
                if (cardsEl) cardsEl.innerHTML = '<div class="csm2-empty" style="color:#c00">Failed to load. Please refresh.</div>';
            });
    }

    function initAll() {
        document.querySelectorAll('.csm2-wrap').forEach(function(w) {
            try { initWidget(w); } catch(e) {
                console.error('CSM Widget 2 init error:', e);
                var c = w.querySelector('.csm2-cards');
                if (c) c.innerHTML = '<div class="csm2-empty" style="color:#c00">Failed to load. Please refresh.</div>';
            }
        });
    }

    document.readyState === 'loading'
        ? document.addEventListener('DOMContentLoaded', initAll)
        : initAll();
})();
