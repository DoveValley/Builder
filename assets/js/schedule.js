(function () {
    'use strict';

    // Data is always inlined via csmAllData before this script runs.
    // REST_BASE is a fallback only — in practice, csmAllData[id] is always defined.
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
        if (str === null || str === undefined) return '';
        var el = document.createElement('span'); el.textContent = String(str); return el.innerHTML;
    }
    function escA(str) {
        if (!str) return '';
        return String(str).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }

    function renderWidget(wrap, courses) {
        var tbody  = wrap.querySelector('.csm-tbody');
        var mcards = wrap.querySelector('.csm-mcards');
        var filters = { course_type: '', delivery: '', timezone: 'EST' };
        var mobDelivery   = '';
        var mobTZ         = 'EST';
        var mobCourseType = '';

        function showMsg(msg) {
            if (tbody)  tbody.innerHTML  = '<tr><td colspan="7" class="csm-loading">' + msg + '</td></tr>';
            if (mcards) mcards.innerHTML = '<div style="padding:20px;text-align:center;color:#888;font-size:13px">' + msg + '</div>';
        }

        if (!courses || courses.length === 0) { showMsg('No courses scheduled at this time.'); return; }

        function renderTable() {
            if (!tbody) return;
            var tz = filters.timezone || 'EST';
            var filtered = courses.filter(function(c) {
                if (filters.course_type && c.course_type !== filters.course_type) return false;
                if (filters.delivery    && c.delivery    !== filters.delivery)    return false;
                return true;
            });
            if (filtered.length === 0) {
                tbody.innerHTML = '<tr><td colspan="7" class="csm-loading">No courses match your filters.</td></tr>';
                return;
            }
            tbody.innerHTML = filtered.map(function(c) {
                var isSP = !c.time_est || c.time_est.toLowerCase() === 'self-paced';
                var td   = isSP ? c.time_est : convertTime(c.time_est, tz) + ' ' + tz;
                var ph   = (c.old_price > 0 ? '<span class="csm-old-price">' + esc(fmtPrice(c.old_price)) + '</span><br>' : '')
                         + '<span class="csm-price">' + esc(fmtPrice(c.price)) + '</span>';
                var av   = (c.guaranteed ? '<span class="csm-avail-guaranteed">✓ Pass Guarantee</span>' : '')
                         + (c.availability_note ? '<br><span class="csm-avail-note">' + esc(c.availability_note) + '</span>' : '');
                var url  = escA(c.register_url || '#');
                return '<tr>'
                    + '<td><a href="' + url + '" target="_blank" rel="noopener noreferrer" class="csm-course-link">' + esc(c.course_type) + '</a></td>'
                    + '<td>' + esc(c.delivery) + '</td>'
                    + '<td>' + esc(c.dates) + '</td>'
                    + '<td>' + esc(td) + '</td>'
                    + '<td>' + ph + '</td>'
                    + '<td><a href="' + url + '" target="_blank" rel="noopener noreferrer" class="csm-register-btn">REGISTER</a></td>'
                    + '<td>' + av + '</td>'
                    + '</tr>';
            }).join('');
        }

        function renderMobile() {
            if (!mcards) return;
            var tz = mobTZ || 'EST';
            var filtered = courses.filter(function(c) {
                if (mobCourseType && c.course_type !== mobCourseType) return false;
                if (mobDelivery   && c.delivery    !== mobDelivery)   return false;
                return true;
            });
            if (filtered.length === 0) {
                mcards.innerHTML = '<div style="padding:20px;text-align:center;color:#888;font-size:13px">No sessions scheduled at this time.</div>';
                return;
            }
            mcards.innerHTML = filtered.map(function(c) {
                var isSP = !c.time_est || c.time_est.toLowerCase() === 'self-paced' || c.time_est.toLowerCase() === 'anytime';
                var td   = isSP ? c.time_est : convertTime(c.time_est, tz) + ' ' + tz;
                var url  = escA(c.register_url || '#');
                var badges = (c.guaranteed ? '<span class="csm1m-badge-blue">✓ Pass Guarantee</span>' : '')
                           + (c.availability_note ? '<span class="csm1m-badge-gold">★ ' + esc(c.availability_note) + '</span>' : '');
                var prH  = (c.old_price > 0 ? '<span class="csm1m-old-price">' + esc(fmtPrice(c.old_price)) + '</span>' : '')
                         + '<span class="csm1m-price">' + esc(fmtPrice(c.price)) + '</span>';
                return '<div class="csm1m-card">'
                    + '<div class="csm1m-card-top">'
                    +   '<div class="csm1m-card-left">'
                    +     '<p class="csm1m-type">' + esc(c.course_type) + '</p>'
                    +     '<p class="csm1m-dates">' + esc(c.dates) + '</p>'
                    +     '<p class="csm1m-time">' + esc(td) + '</p>'
                    +     (badges ? '<div class="csm1m-badges">' + badges + '</div>' : '')
                    +   '</div>'
                    +   '<div class="csm1m-card-right">'
                    +     prH
                    +     '<a href="' + url + '" target="_blank" rel="noopener noreferrer" class="csm1m-register-btn">REGISTER</a>'
                    +   '</div>'
                    + '</div>'
                    + '</div>';
            }).join('');
        }

        // Desktop filter listeners
        wrap.querySelectorAll('.csm-filter').forEach(function(sel) {
            sel.addEventListener('change', function() {
                var k = this.getAttribute('data-filter');
                if (k) { filters[k] = this.value; renderTable(); }
            });
        });
        var rb = wrap.querySelector('.csm-reset-btn');
        if (rb) {
            rb.addEventListener('click', function() {
                wrap.querySelectorAll('.csm-filter').forEach(function(s) {
                    var k = s.getAttribute('data-filter'); if (!k) return;
                    s.value = k === 'timezone' ? 'EST' : ''; filters[k] = s.value;
                });
                renderTable();
            });
        }

        // Mobile filter listeners
        wrap.querySelectorAll('.csm-mob-filter').forEach(function(sel) {
            sel.addEventListener('change', function() {
                var key = this.getAttribute('data-mob-filter');
                if (key === 'delivery')     mobDelivery   = this.value;
                if (key === 'timezone')     mobTZ         = this.value;
                if (key === 'course_type')  mobCourseType = this.value;
                renderMobile();
            });
        });

        var mobResetBtn = wrap.querySelector('.csm-mob-reset-btn');
        if (mobResetBtn) {
            mobResetBtn.addEventListener('click', function() {
                wrap.querySelectorAll('.csm-mob-filter').forEach(function(s) {
                    var key = s.getAttribute('data-mob-filter');
                    if (key === 'timezone') { s.value = 'EST'; mobTZ = 'EST'; }
                    else { s.value = ''; }
                });
                mobDelivery   = '';
                mobCourseType = '';
                renderMobile();
            });
        }

        renderTable();
        renderMobile();
    }

    function initWidget(wrap) {
        var id      = wrap.getAttribute('id');
        var allData = (typeof csmAllData !== 'undefined') ? csmAllData : null;
        var tbody   = wrap.querySelector('.csm-tbody');
        var mcards  = wrap.querySelector('.csm-mcards');

        function showErr(msg) {
            if (tbody)  tbody.innerHTML  = '<tr><td colspan="7" style="padding:12px;color:#c00">' + msg + '</td></tr>';
            if (mcards) mcards.innerHTML = '<div style="padding:12px;color:#c00;font-size:13px">' + msg + '</div>';
        }

        if (allData && allData[id]) {
            renderWidget(wrap, allData[id].courses || []);
            return;
        }

        var type = wrap.getAttribute('data-type') || 'all';
        fetch(REST_BASE + '?type=' + encodeURIComponent(type))
            .then(function(r) {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.json();
            })
            .then(function(courses) { renderWidget(wrap, Array.isArray(courses) ? courses : []); })
            .catch(function(e) {
                console.error('CSM Widget 1 fetch failed:', e);
                showErr('Failed to load courses. Please refresh the page.');
            });
    }

    function initAll() {
        document.querySelectorAll('.csm-schedule-wrap').forEach(function(w) {
            try { initWidget(w); } catch(e) {
                console.error('CSM Widget 1 init error:', e);
                var t = w.querySelector('.csm-tbody');
                if (t) t.innerHTML = '<tr><td colspan="7" style="color:#c00;padding:12px">Failed to load. Please refresh.</td></tr>';
            }
        });
    }

    document.readyState === 'loading'
        ? document.addEventListener('DOMContentLoaded', initAll)
        : initAll();
})();
