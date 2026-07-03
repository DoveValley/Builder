<?php
// Multisite tab — Phase A (intake). Upload + validate + store the params table.
// $tab, $csrfToken available from index.php. Active site = campaign master.
$hasCampaign = is_dir(ACTIVE_SITE_DIR . '/multisite');
?>
<div class="tab-content" style="<?= $tab === 'multisite' ? '' : 'display:none;' ?>">
<?php tab_header('Multisite', 'Generate many separate single-city sites from this master. Step 1: upload and validate your params table (one row per site). See the Multisite documentation for columns and the full workflow.', 'tab-multisite'); ?>

<div class="card">
    <h3 style="margin-top:0;">Campaign master</h3>
    <p class="hint">This site (<code><?= h(ACTIVE_SITE_ID) ?></code>) is the template every generated site is cloned from. Campaign data lives in <code>sites/<?= h(ACTIVE_SITE_ID) ?>/multisite/</code> and is never web-served or committed.</p>
    <p class="hint">Full guide: <a href="docs.php?doc=multisite" target="_blank">Multisite documentation →</a></p>
</div>

<!-- ===== UPLOAD CARD ===== -->
<div class="card">
    <h3 style="margin-top:0;">1. Upload params table (CSV)</h3>
    <p class="hint">Prepare the table in Excel or Google Sheets and <strong>Save As / Export → CSV</strong>. One row per site. Required columns: <code>domain</code>, <code>business</code>. Recommended: <code>city, state, SS, phone, email</code> and FTP credentials (<code>ftp_host, ftp_user, ftp_pass</code>). Optional: <code>lat, lng, rating, review_count, analytics_id, logo</code>.</p>

    <p style="margin:0 0 8px;">
        <a class="btn" href="multisite_api.php?action=sample_csv">&#11015; Download sample CSV</a>
        <span class="hint" style="margin-left:8px;">5 example cities with every column filled in — edit it as a starting point.</span>
    </p>
    <p id="ms-download-row" style="margin:0 0 14px;display:none;">
        <a class="btn" id="ms-download-btn" href="multisite_api.php?action=download_csv">&#11015; Download current table (FTP masked)</a>
        <span class="hint" style="margin-left:8px;">Passwords export as <code>__KEEP__</code>. Edit &amp; re-upload — leave <code>__KEEP__</code> to keep a password, or type a new one.</span>
    </p>

    <form id="ms-upload-form" onsubmit="return msUpload(event)">
        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
        <div class="form-group">
            <input type="file" name="csv" id="ms-csv" accept=".csv,text/csv" required>
        </div>
        <button type="submit" class="btn btn-primary" id="ms-upload-btn">Upload &amp; Validate</button>
        <span id="ms-upload-msg" class="hint" style="margin-left:10px;"></span>
    </form>
    <p class="hint" style="margin-top:10px;">The table is stored only when every row is error-free. Rows with warnings are kept (they build, but a row without FTP credentials won't deploy). Fix any errors and re-upload.</p>
</div>

<!-- ===== RESULTS CARD ===== -->
<div class="card" id="ms-results-card" style="display:none;">
    <h3 style="margin-top:0;">Validation</h3>
    <div style="margin-bottom:12px;">
        <button type="button" class="btn" id="ms-pf-btn" onclick="msPreflight()">Pre-flight FTP</button>
        <span id="ms-pf-msg" class="hint" style="margin-left:10px;"></span>
    </div>
    <div id="ms-summary" style="margin-bottom:12px;"></div>
    <div id="ms-unknown" class="hint" style="margin-bottom:12px;"></div>
    <div style="overflow-x:auto;">
        <table id="ms-table" style="width:100%;">
            <thead><tr>
                <th style="width:44px;">#</th><th style="width:70px;">Status</th>
                <th>Domain</th><th>Business</th><th>City</th>
                <th style="width:52px;">FTP</th><th>Issues</th>
            </tr></thead>
            <tbody></tbody>
        </table>
    </div>
</div>

<!-- ===== RUN CARD ===== -->
<div class="card" id="ms-run-card">
    <h3 style="margin-top:0;">2. Run campaign</h3>
    <p class="hint">Builds and deploys every valid row (up to <em>concurrency</em> at a time). Start with a small <em>limit</em> and review before a full run. AI generation costs roughly $0.02–0.05 per site (free on rebuilds); tick <strong>No AI</strong> for identity + build + deploy only.</p>
    <div style="display:flex;gap:18px;flex-wrap:wrap;align-items:flex-end;">
        <label class="hint">Concurrency<br><input type="number" id="ms-jobs" value="2" min="1" max="16" style="width:70px;"></label>
        <label class="hint">Limit (0 = all)<br><input type="number" id="ms-limit" value="0" min="0" style="width:90px;"></label>
        <label class="hint">Retries<br><input type="number" id="ms-retries" value="1" min="0" max="5" style="width:60px;"></label>
        <label class="hint"><input type="checkbox" id="ms-noai"> No AI (faster)</label>
        <label class="hint"><input type="checkbox" id="ms-force"> Force (refresh AI + full re-upload)</label>
        <button type="button" class="btn btn-primary" id="ms-run-btn" onclick="msRun()">Run Campaign</button>
    </div>
    <div id="ms-run-progress" style="margin-top:16px;"></div>
</div>

<!-- ===== PARAMS VERSIONS CARD ===== -->
<div class="card" id="ms-versions-card">
    <h3 style="margin-top:0;">Saved params versions</h3>
    <p class="hint">The last 15 uploads are snapshotted here (FTP passwords masked on download). <strong>Restore</strong> makes a version the current table.</p>
    <div id="ms-versions"><p class="hint">Loading…</p></div>
</div>

<!-- ===== HISTORY CARD ===== -->
<div class="card" id="ms-history-card">
    <h3 style="margin-top:0;">Recent runs</h3>
    <div id="ms-runs"><p class="hint">Loading…</p></div>
</div>

<script>
(function () {
    const csrfToken = <?= json_encode($csrfToken) ?>;
    let msPollTimer = null;

    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])); }

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
                '<td class="ms-ftp-cell">' + (r.has_ftp ? '✓' : '—') + '</td>' +
                '<td>' + (issues || '<span class="hint">—</span>') + '</td>' +
                '</tr>';
        }).join('');
    }

    window.msUpload = function (ev) {
        ev.preventDefault();
        const btn = document.getElementById('ms-upload-btn');
        const msg = document.getElementById('ms-upload-msg');
        const file = document.getElementById('ms-csv').files[0];
        if (!file) { return false; }
        const fd = new FormData();
        fd.append('csrf_token', csrfToken);
        fd.append('csv', file);
        btn.disabled = true; msg.textContent = 'Validating…';
        fetch('multisite_api.php?action=upload_csv', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(d => { btn.disabled = false; msg.textContent = d.stored ? 'Stored.' : (d.error ? '' : 'Reviewed — not stored.'); render(d); if (d.stored) refreshParamsState(); })
            .catch(e => { btn.disabled = false; msg.textContent = 'Upload failed.'; });
        return false;
    };

    // ── Params: download-current visibility + saved versions ──────────────────
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
                '<a href="#" onclick="msRestore(\'' + esc(v.id) + '\');return false;">restore</a></td>' +
                '</tr>').join('') + '</tbody></table></div>';
    }
    function loadVersions() { fetch('multisite_api.php?action=list_versions').then(r => r.json()).then(renderVersions).catch(() => {}); }
    function refreshParamsState() {
        fetch('multisite_api.php?action=status').then(r => r.json()).then(d => {
            document.getElementById('ms-download-row').style.display = (d && d.stored) ? '' : 'none';
        }).catch(() => {});
        loadVersions();
    }
    window.msRestore = function (id) {
        if (!confirm('Restore this version as the current params table? (A fresh snapshot is also saved.)')) return;
        const fd = new FormData(); fd.append('csrf_token', csrfToken); fd.append('id', id);
        fetch('multisite_api.php?action=restore_version', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(d => { if (d.error) { alert(d.error); return; } render(d); refreshParamsState(); })
            .catch(() => {});
    };

    window.msPreflight = function () {
        const btn = document.getElementById('ms-pf-btn');
        const msg = document.getElementById('ms-pf-msg');
        // reset FTP cells that have creds to a pending dot
        document.querySelectorAll('#ms-table tbody tr').forEach(tr => {
            const cell = tr.querySelector('.ms-ftp-cell');
            if (cell && cell.textContent.trim() === '✓') cell.innerHTML = '<span style="color:#94a3b8;">…</span>';
        });
        btn.disabled = true; msg.textContent = 'Connecting…';
        const es = new EventSource('multisite_preflight.php?token=' + encodeURIComponent(csrfToken));
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

    // ── Run campaign (detached background + polling) ──────────────────────────
    function renderRun(d) {
        const el = document.getElementById('ms-run-progress');
        const btn = document.getElementById('ms-run-btn');
        if (!d || d.none || !d.state) { el.innerHTML = ''; btn.disabled = false; return; }
        const state = d.state;
        const color = { running: '#2563eb', done: '#166534', failed: '#991b1b', stale: '#92400e' }[state] || '#334155';
        const t = d.totals || {};
        const pct = d.total ? Math.round((d.done / d.total) * 100) : 0;
        let html = '<div><strong style="color:' + color + ';">' + state.toUpperCase() + '</strong> — ' +
            (d.done || 0) + '/' + (d.total || 0) + ' done · ' + (d.ok || 0) + ' ok · ' + (d.failed || 0) + ' failed' +
            (t.files_uploaded ? ' · ' + t.files_uploaded + ' files' : '') +
            (t.cost_usd ? ' · $' + Number(t.cost_usd).toFixed(4) : '') +
            (d.params_version ? ' · <span title="params table version used">params ' + esc(d.params_version) + '</span>' : '') + '</div>' +
            '<div style="height:8px;background:#e2e8f0;border-radius:4px;margin:8px 0 12px;overflow:hidden;"><div style="height:100%;width:' + pct + '%;background:' + color + ';transition:width .3s;"></div></div>';
        if (d.results && d.results.length) {
            html += '<div style="max-height:240px;overflow:auto;font-size:0.85rem;line-height:1.7;">' +
                d.results.slice().reverse().map(r => {
                    const mk = r.status === 'ok' ? '<span style="color:#166534;">✓</span>' : '<span style="color:#991b1b;">✗</span>';
                    return '<div>' + mk + ' ' + esc(r.domain) + ' — ' + esc(r.status) +
                        (r.uploaded != null ? ' (' + r.uploaded + ' up)' : '') +
                        (r.cost > 0 ? ' $' + Number(r.cost).toFixed(4) : '') +
                        (r.last ? ' — ' + esc(r.last) : '') + '</div>';
                }).join('') + '</div>';
        }
        el.innerHTML = html;
        if (state === 'running') { btn.disabled = true; }
        else { btn.disabled = false; if (msPollTimer) { clearInterval(msPollTimer); msPollTimer = null; loadRuns(); } }
    }

    // ── Runs history ──────────────────────────────────────────────────────────
    function fmtTime(s) { if (!s) return '—'; const d = new Date(s); return isNaN(d) ? s : d.toLocaleString(); }
    function renderRuns(data) {
        const el = document.getElementById('ms-runs');
        const runs = (data && data.runs) || [];
        if (!runs.length) { el.innerHTML = '<p class="hint">No runs yet.</p>'; return; }
        const stC = { running: '#2563eb', done: '#166534', failed: '#991b1b', stale: '#92400e' };
        el.innerHTML = '<div style="overflow-x:auto;"><table style="width:100%;font-size:0.85rem;">' +
            '<thead><tr><th>Started</th><th>State</th><th>Result</th><th>Cost</th><th></th></tr></thead><tbody>' +
            runs.map(r => {
                const c = stC[r.state] || '#334155';
                const retry = r.failed > 0
                    ? ' <button type="button" class="btn" style="padding:1px 8px;font-size:0.76rem;" onclick="msRetry(\'' + esc(r.run_id) + '\')">retry ' + r.failed + ' failed</button>'
                    : '';
                return '<tr>' +
                    '<td>' + esc(fmtTime(r.started_at)) + '</td>' +
                    '<td><span style="color:' + c + ';font-weight:700;">' + esc(r.state) + '</span></td>' +
                    '<td>' + r.ok + '/' + r.total + ' ok' + (r.failed ? ' · ' + r.failed + ' failed' : '') + '</td>' +
                    '<td>' + (r.cost ? '$' + Number(r.cost).toFixed(4) : '—') + '</td>' +
                    '<td><a href="#" onclick="msView(\'' + esc(r.run_id) + '\');return false;">view</a>' + retry + '</td>' +
                    '</tr>';
            }).join('') + '</tbody></table></div>';
    }
    function loadRuns() { fetch('multisite_api.php?action=list_runs').then(r => r.json()).then(renderRuns).catch(() => {}); }

    window.msView = function (id) {
        pollRun(id);
        document.getElementById('ms-run-progress').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    };
    window.msRetry = function (id) {
        if (!confirm('Re-run only the failed rows from this run?')) return;
        const fd = new FormData(); fd.append('csrf_token', csrfToken); fd.append('run_id', id);
        fetch('multisite_api.php?action=retry_failed', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(d => {
                if (d.error) { alert(d.error); return; }
                if (msPollTimer) clearInterval(msPollTimer);
                msPollTimer = setInterval(() => pollRun(d.run_id), 2500);
                pollRun(d.run_id);
            })
            .catch(() => {});
    };

    function pollRun(runId) {
        const url = 'multisite_api.php?action=run_status' + (runId ? '&run_id=' + encodeURIComponent(runId) : '');
        fetch(url).then(r => r.json()).then(renderRun).catch(() => {});
    }

    window.msRun = function () {
        const btn = document.getElementById('ms-run-btn');
        const fd = new FormData();
        fd.append('csrf_token', csrfToken);
        fd.append('jobs', document.getElementById('ms-jobs').value);
        fd.append('limit', document.getElementById('ms-limit').value);
        fd.append('retries', document.getElementById('ms-retries').value);
        if (document.getElementById('ms-noai').checked) fd.append('no_ai', '1');
        if (document.getElementById('ms-force').checked) fd.append('force', '1');
        btn.disabled = true;
        document.getElementById('ms-run-progress').innerHTML = '<span class="hint">Starting…</span>';
        fetch('multisite_api.php?action=run', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(d => {
                if (d.error) { btn.disabled = false; document.getElementById('ms-run-progress').innerHTML = '<span style="color:#991b1b;">' + esc(d.error) + '</span>'; return; }
                if (msPollTimer) clearInterval(msPollTimer);
                msPollTimer = setInterval(() => pollRun(d.run_id), 2500);
                pollRun(d.run_id);
            })
            .catch(() => { btn.disabled = false; });
    };

    // Load current stored state on tab render.
    fetch('multisite_api.php?action=status').then(r => r.json()).then(d => { if (d && d.stored && d.rows) render(d); }).catch(() => {});
    // Resume any latest/in-progress run.
    fetch('multisite_api.php?action=run_status').then(r => r.json()).then(d => {
        if (d && !d.none && d.state) { renderRun(d); if (d.state === 'running') { if (msPollTimer) clearInterval(msPollTimer); msPollTimer = setInterval(() => pollRun(d.run_id), 2500); } }
    }).catch(() => {});
    loadRuns();            // runs history
    refreshParamsState();  // download-current button + saved versions
})();
</script>
</div>
