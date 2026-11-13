// batch-detailed.js, part 1/4 — Batch Detailed: CSV upload, test-server deploy,
// saved target-list versions, pre-flight FTP check.
//
// Split 2026-11-13 from one 2064-line batch-detailed.js (itself split out of
// admin/_batch_panels.php the same day — see that file's own history). Plain
// top-level declarations here, NOT wrapped in an IIFE: classic <script> tags on
// one page share a single global lexical scope, so `const`/`let`/`function`
// declared here are visible to the other 3 files loaded after it (02-generate,
// 03-rows, 04-ctm), same as they were all one script before this split. Load
// order matters — this file (and its window.MSB-derived constants: csrfToken,
// bpMasterId, bpBatchId, msBuyRegistrars, msBuyRegistrarLabels, msConfirmLabels)
// must load FIRST, since every other part references these.
    // Per-request/server-computed values — set by the small inline <script> that
    // runs just before this file loads (see admin/_batch_panels.php). msBuyRegistrars/
    // msBuyRegistrarLabels are the same list+labels the Buy Domains card's registrar
    // dropdown uses, reused here so a single row's registrar can be set/corrected
    // inline without re-running the fleet-wide spread assignment.
    const { csrfToken, bpMasterId, bpBatchId, msBuyRegistrars, msBuyRegistrarLabels, msConfirmLabels } = window.MSB;
    let msPollTimer = null;

    // esc() is window.esc from admin/_batch_servers.php's IIFE (included above, still
    // the same page) — no local declaration needed, the scope chain resolves the
    // plain `esc` calls throughout this script to it.

    // Click-to-open replacement for a native title="" tooltip, which vanishes the
    // instant the mouse moves toward it — can't be read in full or clicked into.
    // Exposed on window so _batch_golive.php's own separate <script> block (included
    // above, included = still the same page) can open the same shared popup.
    // \\n (two chars, a literal backslash+n some of these tooltips were written with
    // outside of a JS string) and a real \n (from ones built inside a JS template
    // literal, where \n is already a real newline by the time this runs) are both
    // normalized to real newlines before splitting into paragraphs.
    window.bpShowInfo = function (el) {
        const raw = (el.getAttribute('data-info') || '').replace(/\\n/g, '\n');
        document.getElementById('bp-info-body').innerHTML =
            raw.split(/\n\n+/).map(p => '<p>' + esc(p).replace(/\n/g, '<br>') + '</p>').join('');
        document.getElementById('bp-info-bg').style.display = 'flex';
    };
    function bpInfoClose() { document.getElementById('bp-info-bg').style.display = 'none'; }
    document.addEventListener('click', e => {
        if (e.target.id === 'bp-info-close' || e.target.id === 'bp-info-bg') bpInfoClose();
    });
    document.addEventListener('keydown', e => { if (e.key === 'Escape') bpInfoClose(); });

    function badge(status) {
        const map = { ok: ['#166534', '#dcfce7', 'ok'], warn: ['#92400e', '#fef3c7', 'warn'], error: ['#991b1b', '#fee2e2', 'error'] };
        const [fg, bg, label] = map[status] || map.error;
        return '<span style="font-size:0.72rem;font-weight:700;padding:2px 8px;border-radius:4px;color:' + fg + ';background:' + bg + ';">' + label + '</span>';
    }

    function render(data) {
        const card = document.getElementById('ms-results-card');
        if (!data || (!data.rows && !data.error)) { card.style.display = 'none'; return; }
        card.style.display = '';

        if (data.error) {
            document.getElementById('ms-summary').innerHTML = '<span style="color:#991b1b;font-weight:600;">' + esc(data.error) + '</span>';
            document.querySelector('#ms-table tbody').innerHTML = '';
            document.getElementById('ms-unknown').innerHTML = '';
            return;
        }

        const s = data.summary || { total: 0, ok: 0, warn: 0, error: 0 };
        const storedNote = data.stored ? ' &nbsp;·&nbsp; <strong style="color:#166534;">stored ✓</strong>'
                                       : (s.error > 0 ? ' &nbsp;·&nbsp; <strong style="color:#991b1b;">not stored — fix errors</strong>' : '');
        document.getElementById('ms-summary').innerHTML =
            '<strong>' + s.total + '</strong> rows &nbsp; ' + badge('ok') + ' ' + s.ok + ' &nbsp; ' +
            badge('warn') + ' ' + s.warn + ' &nbsp; ' + badge('error') + ' ' + s.error + storedNote;

        document.getElementById('ms-unknown').innerHTML =
            (data.unknown_columns && data.unknown_columns.length)
                ? 'Unknown columns (ignored): <code>' + data.unknown_columns.map(esc).join('</code> <code>') + '</code>' : '';

        const tb = document.querySelector('#ms-table tbody');
        tb.innerHTML = (data.rows || []).map(r => {
            const issues = (r.errors || []).map(e => '<div style="color:#991b1b;">✗ ' + esc(e) + '</div>').join('')
                         + (r.warnings || []).map(w => '<div style="color:#92400e;">· ' + esc(w) + '</div>').join('');
            return '<tr data-domain="' + esc(r.domain) + '">' +
                '<td>' + esc(r.line) + '</td>' +
                '<td>' + badge(r.status) + '</td>' +
                '<td>' + esc(r.domain) + '</td>' +
                '<td>' + esc(r.business) + '</td>' +
                '<td>' + esc(r.city) + '</td>' +
                '<td class="ms-ftp-cell">' + (r.box ? esc(r.box) : '—') + '</td>' +
                '<td>' + (issues || '<span class="hint">—</span>') + '</td>' +
                '</tr>';
        }).join('');
    }

    window.msUpload = function (ev) {
        ev.preventDefault();
        const file = document.getElementById('ms-csv').files[0];
        if (!file) { return false; }
        msDoUploadCsv(file, false);
        return false;
    };

    function msDoUploadCsv(file, confirmDropLive) {
        const btn = document.getElementById('ms-upload-btn');
        const msg = document.getElementById('ms-upload-msg');
        const fd = new FormData();
        fd.append('csrf_token', csrfToken);
        fd.append('csv', file);
        if (confirmDropLive) fd.append('confirm_drop_live', '1');
        btn.disabled = true; msg.textContent = 'Validating…';
        fetch('multisite_api.php?action=upload_csv', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(d => {
                btn.disabled = false;
                // A domain fleet.db knows is LIVE would silently vanish from this batch's
                // target list — refused server-side; ask here instead of failing quietly.
                if (!d.stored && d.live_domains_dropped && d.live_domains_dropped.length) {
                    msg.textContent = '';
                    const names = d.live_domains_dropped.join(', ');
                    if (confirm('This file would remove ' + d.live_domains_dropped.length
                        + ' LIVE domain(s) from this batch\'s target list: ' + names
                        + '.\n\nTheir hosting/DNS is untouched, but this batch will stop managing them '
                        + '(Generate/Upload will no longer see them). Continue anyway?')) {
                        msDoUploadCsv(file, true);
                        return;
                    }
                    msg.textContent = 'Not stored — kept the live domain(s) in the target list.';
                    render(d);
                    return;
                }
                msg.textContent = d.stored ? 'Stored.' : (d.error ? '' : 'Reviewed — not stored.');
                render(d);
                if (d.stored) refreshParamsState();
            })
            .catch(e => { btn.disabled = false; msg.textContent = 'Upload failed.'; });
    }

    window.msLoadTestCsv = function () {
        const btn = document.getElementById('ms-test-csv-btn');
        const msg = document.getElementById('ms-upload-msg');
        if (!confirm('Store the placeholder test row? This replaces whatever target list is currently stored.')) return;
        const fd = new FormData();
        fd.append('csrf_token', csrfToken);
        btn.disabled = true; msg.textContent = 'Loading test row…';
        fetch('multisite_api.php?action=load_test_csv', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(d => { btn.disabled = false; msg.textContent = d.stored ? 'Test row stored.' : (d.error ? '' : 'Reviewed — not stored.'); render(d); if (d.stored) refreshParamsState(); })
            .catch(e => { btn.disabled = false; msg.textContent = 'Failed to load test row.'; });
    };

    // ── Test server (FTP) — settings + per-row "deploy to test server" ─────────
    function loadTestDeployConfig() {
        fetch('multisite_api.php?action=test_deploy_get').then(r => r.json()).then(d => {
            if (!d || d.error) return;
            document.getElementById('td-host').value = d.ftp_host || '';
            document.getElementById('td-protocol').value = d.ftp_protocol || 'ftp';
            document.getElementById('td-port').value = d.ftp_port || '';
            document.getElementById('td-user').value = d.ftp_user || '';
            document.getElementById('td-path').value = d.ftp_path || '';
            document.getElementById('td-view-url').value = d.view_url || '';
            document.getElementById('td-passive').checked = d.ftp_passive !== false;
            document.getElementById('td-pass').placeholder = d.has_password ? 'set — leave blank to keep' : 'leave blank to keep';
        }).catch(() => {});
    }
    loadTestDeployConfig();

    window.msTestDeploySave = function () {
        const btn = document.getElementById('td-save-btn');
        const msg = document.getElementById('td-save-msg');
        const fd = new FormData();
        fd.append('csrf_token', csrfToken);
        fd.append('ftp_host', document.getElementById('td-host').value.trim());
        fd.append('ftp_protocol', document.getElementById('td-protocol').value);
        fd.append('ftp_port', document.getElementById('td-port').value.trim());
        fd.append('ftp_user', document.getElementById('td-user').value.trim());
        fd.append('ftp_pass', document.getElementById('td-pass').value);
        fd.append('ftp_path', document.getElementById('td-path').value.trim());
        fd.append('view_url', document.getElementById('td-view-url').value.trim());
        if (document.getElementById('td-passive').checked) fd.append('ftp_passive', '1');
        btn.disabled = true; msg.textContent = 'Saving…';
        fetch('multisite_api.php?action=test_deploy_save', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(d => {
                btn.disabled = false;
                msg.textContent = d.ok ? 'Saved.' : (d.error || 'Save failed.');
                document.getElementById('td-pass').value = '';
                document.getElementById('td-pass').placeholder = d.has_password ? 'set — leave blank to keep' : 'leave blank to keep';
            })
            .catch(() => { btn.disabled = false; msg.textContent = 'Save failed.'; });
    };

    window.msDeployTestServer = function (domain, linkEl) {
        if (!confirm('Deploy "' + domain + '" to the test server? This overwrites whatever is there now.')) return;
        const original = linkEl.textContent;
        linkEl.textContent = 'deploying…';
        const fd = new FormData();
        fd.append('csrf_token', csrfToken);
        fd.append('domain', domain);
        fetch('multisite_api.php?action=deploy_test_server', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(d => {
                if (d.error) { linkEl.textContent = original; alert(d.error); return; }
                const r = d.result || {};
                if ((r.status || '') === 'fatal' || (r.failed || 0) > 0) {
                    linkEl.textContent = original;
                    alert('Deploy had problems: ' + (r.msg || (r.failed + ' file(s) failed')));
                    return;
                }
                linkEl.outerHTML = '<a href="' + esc(d.view_url || '#') + '" target="_blank" rel="noopener">deployed — view it</a>';
            })
            .catch(() => { linkEl.textContent = original; alert('Deploy failed.'); });
    };

    // ── Target list: download-current visibility + saved versions ──────────────
    function fmtStamp(id) {
        const m = /^(\d{4})(\d{2})(\d{2})-(\d{2})(\d{2})(\d{2})/.exec(id || '');
        if (!m) return id;
        const d = new Date(Date.UTC(+m[1], +m[2] - 1, +m[3], +m[4], +m[5], +m[6]));
        return isNaN(d) ? id : d.toLocaleString();
    }
    function renderVersions(data) {
        const el = document.getElementById('ms-versions');
        const vs = (data && data.versions) || [];
        if (!vs.length) { el.innerHTML = '<p class="hint">No saved versions yet — each successful upload is snapshotted here (last 15).</p>'; return; }
        el.innerHTML = '<div style="overflow-x:auto;"><table style="width:100%;font-size:0.85rem;">' +
            '<thead><tr><th>Saved (local time)</th><th>Rows</th><th></th></tr></thead><tbody>' +
            vs.map(v => '<tr>' +
                '<td>' + esc(fmtStamp(v.id)) + '</td>' +
                '<td>' + esc(v.rows) + '</td>' +
                '<td><a href="multisite_api.php?action=download_version&id=' + encodeURIComponent(v.id) + '">download</a> &nbsp;·&nbsp; ' +
                '<a href="#" onclick="msRestore(\'' + esc(v.id) + '\', this);return false;">restore</a></td>' +
                '</tr>').join('') + '</tbody></table></div>';
    }
    function loadVersions() { fetch('multisite_api.php?action=list_versions').then(r => r.json()).then(renderVersions).catch(() => {}); }
    function refreshParamsState() {
        fetch('multisite_api.php?action=status').then(r => r.json()).then(d => {
            document.getElementById('ms-download-row').style.display = (d && d.stored) ? '' : 'none';
        }).catch(() => {});
        loadVersions();
        // Any path that (re)stores params.csv wholesale (upload, restore, preview test)
        // must refresh the per-row editor too — it caches the last row_list() answer,
        // and a whole-file replace is exactly the case that invalidates it.
        msLoadRows();
    }
    window.msRestore = function (id, link) {
        // Guards against a double-click/second click firing a second restore_version
        // POST before the first one's response lands — the two would race, and
        // whichever responded second silently won regardless of which was clicked
        // last. Checked before confirm() so a second click can't even open a second
        // dialog while the first request is still in flight.
        if (link && link.dataset.busy) return;
        if (!confirm('Restore this version as the current target list? (A fresh snapshot is also saved.)')) return;
        // refreshParamsState()'s loadVersions() re-renders this whole link (and its
        // busy state along with it) on success; only the error path needs to
        // explicitly restore it.
        if (link) { link.dataset.busy = '1'; link.style.opacity = '0.5'; }
        const fd = new FormData(); fd.append('csrf_token', csrfToken); fd.append('id', id);
        fetch('multisite_api.php?action=restore_version', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(d => {
                if (d.error) { alert(d.error); if (link) { delete link.dataset.busy; link.style.opacity = ''; } return; }
                render(d); refreshParamsState();
            })
            .catch(() => { if (link) { delete link.dataset.busy; link.style.opacity = ''; } });
    };

    window.msPreflight = async function () {
        const btn = document.getElementById('ms-pf-btn');
        const msg = document.getElementById('ms-pf-msg');
        // reset FTP cells that have creds to a pending dot
        document.querySelectorAll('#ms-table tbody tr').forEach(tr => {
            const cell = tr.querySelector('.ms-ftp-cell');
            if (cell && cell.textContent.trim() === '✓') cell.innerHTML = '<span style="color:#94a3b8;">…</span>';
        });
        btn.disabled = true; msg.textContent = 'Connecting…';
        // A dedicated, single-use, 60s token for this EventSource URL — not the real
        // csrf_token. EventSource can't send a header or a body, so SOME token has to
        // be in the URL where an access log or proxy could see it; this one is good
        // for nothing else and only briefly, unlike the general csrf_token.
        let pfToken;
        try {
            const fd = new FormData(); fd.append('csrf_token', csrfToken);
            const r = await fetch('multisite_api.php?action=preflight_token', { method: 'POST', body: fd });
            const d = await r.json();
            if (!d.token) { btn.disabled = false; msg.textContent = d.error || 'Could not start pre-flight.'; return; }
            pfToken = d.token;
        } catch (e) {
            btn.disabled = false; msg.textContent = 'Could not reach the server — try again.'; return;
        }
        const es = new EventSource('multisite_preflight.php?token=' + encodeURIComponent(pfToken));
        es.onmessage = function (e) {
            const d = JSON.parse(e.data);
            if (d.type === 'row') {
                const tr = document.querySelector('#ms-table tbody tr[data-domain="' + d.domain.replace(/"/g, '\\"') + '"]');
                if (tr) {
                    const cell = tr.querySelector('.ms-ftp-cell');
                    if (cell) cell.innerHTML = d.ok
                        ? '<span style="color:#166534;" title="reachable">✓</span>'
                        : '<span style="color:#991b1b;" title="' + esc(d.msg) + '">✗</span>';
                }
                msg.textContent = 'Checking… ' + d.done + '/' + d.total;
            } else if (d.type === 'done') {
                es.close(); btn.disabled = false;
                msg.textContent = d.total === 0 ? 'No rows with FTP credentials.' : ('FTP: ' + d.ok + ' ok, ' + d.fail + ' failed of ' + d.total);
            } else if (d.type === 'fatal') {
                es.close(); btn.disabled = false; msg.textContent = d.msg;
            }
        };
        es.onerror = function () { es.close(); btn.disabled = false; if (!msg.textContent.startsWith('FTP:')) msg.textContent = 'Pre-flight interrupted.'; };
    };

