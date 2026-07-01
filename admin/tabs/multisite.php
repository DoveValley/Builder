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

<script>
(function () {
    const csrfToken = <?= json_encode($csrfToken) ?>;

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
            return '<tr>' +
                '<td>' + esc(r.line) + '</td>' +
                '<td>' + badge(r.status) + '</td>' +
                '<td>' + esc(r.domain) + '</td>' +
                '<td>' + esc(r.business) + '</td>' +
                '<td>' + esc(r.city) + '</td>' +
                '<td>' + (r.has_ftp ? '✓' : '—') + '</td>' +
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
            .then(d => { btn.disabled = false; msg.textContent = d.stored ? 'Stored.' : (d.error ? '' : 'Reviewed — not stored.'); render(d); })
            .catch(e => { btn.disabled = false; msg.textContent = 'Upload failed.'; });
        return false;
    };

    // Load current stored state on tab render.
    fetch('multisite_api.php?action=status').then(r => r.json()).then(d => { if (d && d.stored && d.rows) render(d); }).catch(() => {});
})();
</script>
</div>
