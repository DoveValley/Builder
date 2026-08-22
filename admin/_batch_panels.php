<?php
/**
 * Batch work panels — the "doing a run" half of multisite.
 *
 * Included by admin/batch.php. Everything here operates on the batch currently open
 * (session), via multisite_api.php. Master-level setup (niche brief, theme presets,
 * icons, master health check) deliberately lives with the SITE, not here.
 *
 * Expects: $csrfToken, $researchOn, $masterId
 */
if (!isset($csrfToken)) return;
?>
<!-- ===== UPLOAD CARD ===== -->
<div class="card" id="ms-upload">
    <h3 style="margin-top:0;">1. Upload target list (CSV)</h3>
    <p class="hint">Prepare the table in Excel or Google Sheets and <strong>Save As / Export &rarr; CSV</strong>. One row per site. Required columns: <code>domain, business, phone, email, city, state, SS</code>. Optional: <code>tel, address, zip, lat, lng, rating, review_count, analytics_id, logo</code>. Add FTP credentials (<code>ftp_host, ftp_user, ftp_pass</code>) to deploy &mdash; omit them and the row still builds.</p>

    <p style="margin:0 0 8px;">
        <a class="btn" href="multisite_api.php?action=sample_csv">&#11015; Download sample CSV</a>
        <span class="hint" style="margin-left:8px;">5 example cities with every column filled in &mdash; edit it as a starting point. Columns marked <code>*</code> must be filled on every row; the <code>*</code> is just a hint and is ignored on upload.</span>
    </p>
    <p id="ms-download-row" style="margin:0 0 14px;display:none;">
        <a class="btn" id="ms-download-btn" href="multisite_api.php?action=download_csv">&#11015; Download current table (FTP masked)</a>
        <span class="hint" style="margin-left:8px;">Passwords export as <code>__KEEP__</code>. Edit &amp; re-upload &mdash; leave <code>__KEEP__</code> to keep a password, or type a new one.</span>
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

<!-- ===== TITLE PREVIEW CARD ===== -->
<div class="card" id="ms-output-card">
    <h3 style="margin-top:0;">Title preview &mdash; what your sites will publish</h3>
    <p class="hint">Each page's title comes from its own SEO panel in the master (using <code>{primary_keyword}</code>, <code>{city}</code>, <code>{SS}</code>, <code>{business}</code> shortcodes). Below is exactly how they resolve for a sample city on a cloned site &mdash; nothing is generated behind the scenes. To change a title, edit that page's SEO on the master.</p>
    <div id="ms-titles-preview"><p class="hint">Loading&hellip;</p></div>
</div>

<?php if (!empty($researchOn)): ?>
<!-- ===== RESEARCH CARD ===== -->
<div class="card" id="ms-research-card">
    <h3 style="margin-top:0;">Research cities <span class="hint" style="font-weight:400;">(local market data)</span></h3>
    <p class="hint">The master's niche brief has research on. This seeds <code>cities.json</code> with every city in this batch's target list, then looks up real local facts for each new city (using the <a href="index.php?tab=niche_brief">Niche Brief</a>'s research prompt). Run it once before a batch &mdash; results persist and are reused free; already-researched cities are skipped. Do a <strong>dry run</strong> first to preview without API cost.</p>
    <div style="display:flex;gap:14px;flex-wrap:wrap;align-items:center;">
        <button type="button" class="btn" id="ms-research-dry" onclick="msResearch(true)">Dry run (no API)</button>
        <button type="button" class="btn btn-primary" id="ms-research-btn" onclick="msResearch(false)">Research cities</button>
    </div>
    <pre id="ms-research-out" style="display:none;margin-top:14px;background:#0f172a;color:#e2e8f0;padding:12px;border-radius:6px;font-size:0.8rem;max-height:340px;overflow:auto;white-space:pre-wrap;"></pre>
</div>
<?php endif; ?>

<?php include __DIR__ . '/_batch_servers.php'; ?>

<?php include __DIR__ . '/_batch_hosts.php'; ?>

<!-- ===== RUN CARD ===== -->
<div class="card" id="ms-run-card">
    <h3 style="margin-top:0;">4. Generate sites</h3>

    <!-- The steps come FIRST: what a run does is decided before how fast it goes. -->
    <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:14px 16px;margin-bottom:16px;">
        <strong style="font-size:.9rem;color:#1e3a5f;">What this run does</strong>
        <p class="hint" style="margin:4px 0 10px;">
            Untick a step to skip it for this run. Clone, identity and build are not listed
            because they cannot be skipped &mdash; without them a run makes no site, or fifty
            copies of the master.
        </p>
        <div style="display:flex;gap:18px;flex-wrap:wrap;">
            <label class="hint"><input type="checkbox" class="ms-step-opt" value="landing" checked> Landing pages</label>
            <label class="hint"><input type="checkbox" class="ms-step-opt" value="visual"  checked> Visual identity</label>
            <label class="hint"><input type="checkbox" class="ms-step-opt" value="ai"      checked> AI content</label>
            <label class="hint"><input type="checkbox" class="ms-step-opt" value="images"  checked> Images</label>
            <label class="hint"><input type="checkbox" class="ms-step-opt" value="tags"    checked> Site tags <span style="color:#94a3b8;">(analytics, Search Console)</span></label>
        </div>
        <p class="hint" style="margin:10px 0 0;color:#94a3b8;">
            The identity scrub always runs &mdash; it removes the master's own domain, email,
            phone and business name from every clone, so it is not an option.
        </p>
    </div>

    <p class="hint">Builds every valid row and keeps the result, ready to upload. Nothing goes to a
        server in this step &mdash; that is step 5. AI generation costs roughly $0.02&ndash;0.05 per site
        (free on rebuilds).</p>
    <div style="display:flex;gap:18px;flex-wrap:wrap;align-items:flex-end;">
        <label class="hint">Build this many (0 = all)<br><input type="number" id="ms-limit" value="0" min="0" style="width:110px;"></label>
        <label class="hint"><input type="checkbox" id="ms-force"> Force (rebuild everything, refresh AI)</label>
        <button type="button" class="btn btn-primary" id="ms-run-btn" onclick="msRun()">Generate sites</button>
    </div>
    <div id="ms-run-progress" style="margin-top:16px;"></div>
</div>

<?php include __DIR__ . '/_batch_upload.php'; ?>

<?php include __DIR__ . '/_batch_golive.php'; ?>

<!-- ===== PARAMS VERSIONS CARD ===== -->
<div class="card" id="ms-versions-card">
    <h3 style="margin-top:0;">Saved target-list versions</h3>
    <p class="hint">The last 15 uploads to this batch are snapshotted here (FTP passwords masked on download). <strong>Restore</strong> makes a version the current table.</p>
    <div id="ms-versions"><p class="hint">Loading&hellip;</p></div>
</div>

<!-- ===== HISTORY CARD ===== -->
<div class="card" id="ms-history-card">
    <h3 style="margin-top:0;">Recent runs</h3>
    <div id="ms-runs"><p class="hint">Loading&hellip;</p></div>
</div>

<script>
(function () {
    const csrfToken = <?= json_encode($csrfToken) ?>;
    let msPollTimer = null;

    // See admin/_batch_servers.php's esc() for why ' is escaped too — this file also
    // interpolates values into single-quoted JS string literals inside onclick="...".
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

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

    // ── Run batch (detached background + polling) ──────────────────────────────
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
            (d.params_version ? ' · <span title="target list version used">list ' + esc(d.params_version) + '</span>' : '') + '</div>' +
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
        else {
            btn.disabled = false;
            if (msPollTimer) {
                clearInterval(msPollTimer); msPollTimer = null; loadRuns();
                // The Upload card (step 5) reads "how many are ready to upload" once at
                // page load and otherwise never hears about a Generate-sites run finishing
                // — without this it keeps showing a stale "0 ready" until a manual reload.
                if (typeof window.msRefreshUploadState === 'function') window.msRefreshUploadState();
            }
        }
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
                    ? ' <button type="button" class="btn" style="padding:1px 8px;font-size:0.76rem;" onclick="msRetry(\'' + esc(r.run_id) + '\', this)">retry ' + r.failed + ' failed</button>'
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
    window.msRetry = function (id, btn) {
        // Same double-submit guard as msRestore above — checked before confirm() so a
        // second click while the first retry is still in flight can't open a second
        // dialog and fire a second retry_failed for the same run.
        if (btn && btn.disabled) return;
        if (!confirm('Re-run only the failed rows from this run?')) return;
        if (btn) btn.disabled = true;
        const fd = new FormData(); fd.append('csrf_token', csrfToken); fd.append('run_id', id);
        fetch('multisite_api.php?action=retry_failed', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(d => {
                if (d.error) { alert(d.error); if (btn) btn.disabled = false; return; }
                if (msPollTimer) clearInterval(msPollTimer);
                msPollTimer = setInterval(() => pollRun(d.run_id), 2500);
                pollRun(d.run_id);
                // Stays disabled until the retry run finishes — renderRun()'s completion
                // branch calls loadRuns(), which re-renders this whole row anyway.
            })
            .catch(() => { if (btn) btn.disabled = false; });
    };

    let pollRunMisses = 0;   // consecutive failed polls — one blip self-heals silently
    function pollRun(runId) {
        const url = 'multisite_api.php?action=run_status' + (runId ? '&run_id=' + encodeURIComponent(runId) : '');
        fetch(url).then(r => r.json()).then(d => { pollRunMisses = 0; renderRun(d); }).catch(() => {
            // A persistent failure used to leave #ms-run-btn disabled forever with the
            // interval silently retrying and zero feedback — surface it once instead.
            if (++pollRunMisses === 5) {
                const el = document.getElementById('ms-run-progress');
                if (el) el.insertAdjacentHTML('beforeend',
                    '<div style="color:#991b1b;margin-top:6px;">Lost contact with the server — still trying… (the run itself may still be in progress; reload to check)</div>');
            }
        });
    }

    window.msRun = function () {
        const btn = document.getElementById('ms-run-btn');
        const fd = new FormData();
        fd.append('csrf_token', csrfToken);
        fd.append('limit', document.getElementById('ms-limit').value);
        // Generating never uploads now — sending is step 5, and the built sites are
        // kept under the batch so that step has something to send.
        fd.append('no_deploy', '1');
        // Unticked steps become the skip list. 'ai' also sets no_ai so the runner has
        // one mechanism for it rather than two that could disagree.
        const skip = Array.from(document.querySelectorAll('.ms-step-opt'))
            .filter(cb => !cb.checked).map(cb => cb.value);
        if (skip.length) fd.append('skip', skip.join(','));
        if (skip.includes('ai')) fd.append('no_ai', '1');
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

    // ── Research cities — detached; poll the output file ──────────────────────
    var msResearchTimer = null;
    var msResearchMisses = 0;   // consecutive failed polls — one blip self-heals silently
    function msPollResearch(runId) {
        fetch('multisite_api.php?action=research_status&run_id=' + encodeURIComponent(runId))
            .then(r => r.json())
            .then(d => {
                msResearchMisses = 0;
                var out = document.getElementById('ms-research-out');
                if (d.error) { out.textContent = d.error; return; }
                if (d.none)  { out.textContent = 'Starting…'; return; }
                out.textContent = d.output || '';
                out.scrollTop = out.scrollHeight;
                if (d.done) {
                    if (msResearchTimer) clearInterval(msResearchTimer);
                    document.getElementById('ms-research-dry').disabled = false;
                    document.getElementById('ms-research-btn').disabled = false;
                    out.textContent += (d.exit === 0 ? '\n\n✓ Done.' : '\n\n✗ Exited with code ' + d.exit + '.');
                }
            })
            .catch(() => {
                // A persistent failure used to leave both research buttons disabled
                // forever with zero feedback while the job may have finished or died
                // server-side. Give up after a run of misses instead of hanging silently.
                if (++msResearchMisses < 5) return;
                if (msResearchTimer) clearInterval(msResearchTimer);
                document.getElementById('ms-research-dry').disabled = false;
                document.getElementById('ms-research-btn').disabled = false;
                var out = document.getElementById('ms-research-out');
                if (out) out.textContent += '\n\n✗ Lost contact with the server — the job may still be running; reload to check.';
            });
    }
    window.msResearch = function (dry) {
        var dryBtn = document.getElementById('ms-research-dry');
        var runBtn = document.getElementById('ms-research-btn');
        var out = document.getElementById('ms-research-out');
        var fd = new FormData();
        fd.append('csrf_token', csrfToken);
        if (dry) fd.append('dry_run', '1');
        dryBtn.disabled = true; runBtn.disabled = true;
        out.style.display = 'block';
        out.textContent = 'Starting ' + (dry ? 'dry run' : 'research') + '…';
        fetch('multisite_api.php?action=research', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(d => {
                if (d.error) {
                    out.textContent = d.error;
                    // "Research is already running" carries the live run_id — resume
                    // watching it instead of just re-enabling the buttons and leaving
                    // the operator to guess whether anything is actually happening.
                    if (d.run_id) {
                        if (msResearchTimer) clearInterval(msResearchTimer);
                        msResearchTimer = setInterval(() => msPollResearch(d.run_id), 2000);
                        msPollResearch(d.run_id);
                    } else {
                        dryBtn.disabled = false; runBtn.disabled = false;
                    }
                    return;
                }
                if (msResearchTimer) clearInterval(msResearchTimer);
                msResearchTimer = setInterval(() => msPollResearch(d.run_id), 2000);
                msPollResearch(d.run_id);
            })
            .catch(() => { dryBtn.disabled = false; runBtn.disabled = false; });
    };

    // ── Title preview (read-only — resolves the master's real page titles) ────
    function loadTitlePreview() {
        fetch('multisite_api.php?action=preview_titles').then(function (r) { return r.json(); }).then(function (d) {
            var el = document.getElementById('ms-titles-preview');
            if (d.error) { el.innerHTML = '<p class="hint" style="color:#991b1b;">' + esc(d.error) + '</p>'; return; }
            var src = d.is_placeholder ? 'an example city (upload your target list to preview real data)' : d.sample_domain;
            var layoutLine = d.layout
                ? '<p class="hint" style="margin-top:10px;">Section layout for this site: <strong>Layout ' + d.layout.index + ' of ' + d.layout.total + '</strong> (set per page under Content → Layout variations).</p>'
                : '';
            var rows = (d.titles || []).map(function (t) {
                var val = t.has_title
                    ? '<span style="color:#0f172a;font-weight:600;">' + esc(t.resolved) + '</span>'
                    : '<span style="color:#991b1b;">' + esc(t.resolved) + '</span>';
                return '<tr><td style="padding:5px 10px;white-space:nowrap;color:#475569;">' + esc(t.label) + '</td><td style="padding:5px 10px;">' + val + '</td></tr>';
            }).join('');
            el.innerHTML = '<p class="hint">Resolved for <strong>' + esc(src) + '</strong>:</p>' +
                '<div style="overflow-x:auto;"><table style="width:100%;font-size:0.9rem;border-collapse:collapse;">' +
                '<thead><tr><th style="text-align:left;padding:5px 10px;border-bottom:1px solid #e2e8f0;">Page</th>' +
                '<th style="text-align:left;padding:5px 10px;border-bottom:1px solid #e2e8f0;">Title tag on a cloned site</th></tr></thead>' +
                '<tbody>' + rows + '</tbody></table></div>' + layoutLine;
        }).catch(function () {});
    }
    loadTitlePreview();

    // Load the batch's current stored state.
    fetch('multisite_api.php?action=status').then(r => r.json()).then(d => { if (d && d.stored && d.rows) render(d); }).catch(() => {});
    // Resume any latest/in-progress run.
    fetch('multisite_api.php?action=run_status').then(r => r.json()).then(d => {
        if (d && !d.none && d.state) { renderRun(d); if (d.state === 'running') { if (msPollTimer) clearInterval(msPollTimer); msPollTimer = setInterval(() => pollRun(d.run_id), 2500); } }
    }).catch(() => {});
    loadRuns();            // runs history
    refreshParamsState();  // download-current button + saved versions
    // Resume any latest/in-progress research job. Unlike 'run' above, this used to
    // have no bootstrap at all: reloading the page (or leaving and coming back)
    // while a paid, possibly long research job was running left both buttons
    // re-enabled and the output box empty, with no way to tell it was still going
    // short of clicking "Research cities" again and reading the "already running"
    // error's buried run_id.
    fetch('multisite_api.php?action=research_status').then(r => r.json()).then(d => {
        if (!d || d.none || d.error) return;
        document.getElementById('ms-research-out').style.display = 'block';
        if (!d.done) {
            document.getElementById('ms-research-dry').disabled = true;
            document.getElementById('ms-research-btn').disabled = true;
            if (msResearchTimer) clearInterval(msResearchTimer);
            msResearchTimer = setInterval(() => msPollResearch(d.run_id), 2000);
        }
        msPollResearch(d.run_id);
    }).catch(() => {});
})();
</script>
