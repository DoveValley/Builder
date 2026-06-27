<?php
// Deploy tab — Build (static HTML generator) + Push (FTP deploy).
// $tab, $csrfToken available from index.php.

$deployFile = ACTIVE_SITE_DIR . '/deploy.json';
$deploy = file_exists($deployFile) ? (json_decode(file_get_contents($deployFile), true) ?: []) : [];
?>
<div class="tab-content" style="<?= $tab === 'deploy' ? '' : 'display:none;' ?>">
<?php tab_header('Deploy', 'Generate a fully-static copy of this site and push it to your web host via FTP. Run structure and AI generation first before deploying.', 'tab-deploy'); ?>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;align-items:start;">

<!-- ===== BUILD CARD ===== -->
<div class="card">
    <h3 style="margin-top:0;">1. Build Static Site</h3>
    <p class="hint">Generates all pages as static HTML files in <code>output/<?= h(ACTIVE_SITE_ID) ?>/</code>. Assets and uploads are copied in. Blog and city pages are included.</p>

    <form method="post" action="deploy_save.php">
        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
        <input type="hidden" name="section" value="build">

        <div class="form-group">
            <label>Canonical Domain</label>
            <input type="url" name="canonical_domain" value="<?= h($deploy['canonical_domain'] ?? '') ?>" placeholder="https://example.com">
            <div class="hint">Used in <code>sitemap.xml</code> and <code>robots.txt</code>. No trailing slash.</div>
        </div>

        <div class="form-group">
            <label>Web3Forms Access Key <span style="font-weight:normal;color:#9ca3af;">(optional)</span></label>
            <input type="text" name="web3forms_key" value="<?= h($deploy['web3forms_key'] ?? '') ?>" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx">
            <div class="hint">Free key from <strong>web3forms.com</strong>. Powers the contact form on the static site. Leave blank to omit the form submit button.</div>
        </div>

        <button type="submit" class="btn btn-secondary">Save Build Settings</button>
    </form>

    <hr style="margin:20px 0;border:none;border-top:1px solid #e5e7eb;">

    <button id="gen-btn" class="btn" onclick="startGenerate()">Generate Static Site</button>
    <div id="gen-log" class="deploy-log" style="display:none;"></div>
</div>

<!-- ===== PUSH CARD ===== -->
<div class="card">
    <h3 style="margin-top:0;">2. Push to Server (FTP)</h3>
    <p class="hint">Uploads only new or changed files. Credentials are stored in <code>sites/<?= h(ACTIVE_SITE_ID) ?>/deploy.json</code> — that file is gitignored and never committed.</p>

    <form method="post" action="deploy_save.php">
        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
        <input type="hidden" name="section" value="ftp">

        <div class="form-group">
            <label>FTP Host</label>
            <input type="text" name="ftp_host" value="<?= h($deploy['ftp_host'] ?? '') ?>" placeholder="ftp.yourhost.com" autocomplete="off">
        </div>

        <div style="display:flex;gap:12px;">
            <div class="form-group" style="flex:1;">
                <label>Username</label>
                <input type="text" name="ftp_user" value="<?= h($deploy['ftp_user'] ?? '') ?>" autocomplete="off">
            </div>
            <div class="form-group" style="flex:0 0 70px;">
                <label>Port</label>
                <input type="number" name="ftp_port" value="<?= (int)($deploy['ftp_port'] ?? 21) ?>" min="1" max="65535">
            </div>
        </div>

        <div class="form-group">
            <label>Password</label>
            <input type="password" name="ftp_pass" value="" autocomplete="new-password"
                   placeholder="<?= !empty($deploy['ftp_pass']) ? '(saved — leave blank to keep)' : 'FTP password' ?>">
        </div>

        <div class="form-group">
            <label>Remote Path</label>
            <input type="text" name="ftp_path" value="<?= h($deploy['ftp_path'] ?? '/public_html') ?>" placeholder="/public_html">
            <div class="hint">Server directory where the site root (<code>index.html</code>) should live.</div>
        </div>

        <div class="form-group">
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:normal;">
                <input type="checkbox" name="ftp_passive" value="1" <?= !empty($deploy['ftp_passive']) ? 'checked' : '' ?>>
                Passive mode <span style="color:#9ca3af;font-size:.82rem;">(recommended — required by most shared hosts)</span>
            </label>
        </div>

        <button type="submit" class="btn btn-secondary">Save FTP Settings</button>
    </form>

    <hr style="margin:20px 0;border:none;border-top:1px solid #e5e7eb;">

    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
        <button id="push-btn" class="btn" onclick="startPush()">Push to Server</button>
        <button id="audit-btn" class="btn btn-secondary" onclick="startAudit()">Audit Server</button>
    </div>
    <div id="push-log" class="deploy-log" style="display:none;"></div>
</div>

</div><!-- /grid -->

<!-- ===== DANGER ZONE ===== -->
<div class="card" style="margin-top:24px;border:2px solid #fecaca;">
    <h3 style="margin-top:0;color:#dc2626;">&#9888; Danger Zone</h3>
    <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-start;">
        <div>
            <button id="force-push-btn" class="btn" style="background:#dc2626;border-color:#dc2626;" onclick="startForcePush()">Force Push All</button>
            <div class="hint" style="margin-top:4px;">Re-uploads every local file regardless of what&rsquo;s on the server.</div>
        </div>
        <div>
            <button id="force-delete-btn" class="btn" style="background:#7f1d1d;border-color:#7f1d1d;" onclick="startForceDelete()">Force Delete All</button>
            <div class="hint" style="margin-top:4px;">Deletes every file from the server. Irreversible.</div>
        </div>
    </div>
    <div id="force-log" class="deploy-log" style="display:none;margin-top:14px;"></div>
</div>

<!-- ===== AUDIT RESULTS ===== -->
<div id="audit-panel" style="display:none;margin-top:24px;" class="card">
    <h3 style="margin-top:0;">Server Audit Results</h3>
    <div id="audit-body"></div>
</div>
</div><!-- /tab-deploy -->

<style>
.deploy-log {
    margin-top: 14px;
    background: #0f172a;
    color: #e2e8f0;
    border-radius: 6px;
    padding: 14px 16px;
    font-family: 'Menlo', 'Consolas', monospace;
    font-size: .80rem;
    line-height: 1.55;
    max-height: 340px;
    overflow-y: auto;
}
.deploy-log .log-done  { color: #4ade80; font-weight: 600; }
.deploy-log .log-error { color: #f87171; }
.deploy-log .log-warn  { color: #fbbf24; }
</style>

<script>
(function() {
    function runSSE(url, btn, logEl) {
        btn.disabled = true;
        const origText = btn.textContent;
        btn.textContent = 'Working…';
        logEl.style.display = 'block';
        logEl.innerHTML = '';

        const es = new EventSource(url);

        es.onmessage = function(e) {
            let d;
            try { d = JSON.parse(e.data); } catch(ex) { return; }

            const line = document.createElement('div');
            if (d.type === 'done')  line.className = 'log-done';
            if (d.type === 'error') line.className = 'log-error';
            if (d.type === 'warn')  line.className = 'log-warn';
            line.textContent = d.msg;
            logEl.appendChild(line);
            logEl.scrollTop = logEl.scrollHeight;

            if (d.type === 'done' || d.type === 'fatal') {
                es.close();
                btn.disabled = false;
                btn.textContent = origText;
            }
        };

        es.onerror = function() {
            es.close();
            btn.disabled = false;
            btn.textContent = origText;
            const line = document.createElement('div');
            line.className = 'log-error';
            line.textContent = 'Connection closed.';
            logEl.appendChild(line);
        };
    }

    const csrfToken = <?= json_encode($csrfToken) ?>;

    window.startGenerate = function() {
        runSSE('generate_static.php?token=' + encodeURIComponent(csrfToken), document.getElementById('gen-btn'), document.getElementById('gen-log'));
    };

    window.startPush = function() {
        runSSE('deploy_ftp.php?token=' + encodeURIComponent(csrfToken), document.getElementById('push-btn'), document.getElementById('push-log'));
    };

    window.startForcePush = function() {
        var word = prompt('This will overwrite every file on the server.\n\nType PUSH ALL to confirm:');
        if (!word || word.trim() !== 'PUSH ALL') { alert('Cancelled.'); return; }
        runSSE('deploy_ftp.php?token=' + encodeURIComponent(csrfToken) + '&force=1',
            document.getElementById('force-push-btn'),
            document.getElementById('force-log'));
    };

    window.startForceDelete = function() {
        var word = prompt('WARNING: This permanently deletes every file from the server.\n\nType DELETE ALL to confirm:');
        if (!word || word.trim() !== 'DELETE ALL') { alert('Cancelled.'); return; }
        runSSE('deploy_force_delete.php?token=' + encodeURIComponent(csrfToken),
            document.getElementById('force-delete-btn'),
            document.getElementById('force-log'));
    };

    window.startAudit = function() {
        const btn    = document.getElementById('audit-btn');
        const panel  = document.getElementById('audit-panel');
        const body   = document.getElementById('audit-body');
        btn.disabled = true;
        btn.textContent = 'Auditing…';
        panel.style.display = 'none';
        body.innerHTML = '';

        const fd = new FormData();
        fd.append('csrf_token', csrfToken);

        fetch('deploy_audit.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            btn.disabled = false;
            btn.textContent = 'Audit Server';
            panel.style.display = 'block';

            if (!data.success) {
                body.innerHTML = '<p style="color:#dc2626;">' + escH(data.error || 'Audit failed.') + '</p>';
                return;
            }

            const missing  = data.missing  || [];
            const orphaned = data.orphaned || [];
            const changed  = data.changed  || [];
            const matched  = data.matched  || 0;

            // Summary bar
            let summary = '<div style="display:flex;gap:20px;flex-wrap:wrap;margin-bottom:18px;padding:12px 16px;background:#f8fafc;border-radius:6px;font-size:.88rem;">';
            summary += '<span style="color:#166534;font-weight:600;">&#10003; ' + matched + ' up to date</span>';
            summary += '<span style="color:#dc2626;font-weight:600;">&#8593; ' + missing.length + ' missing on server</span>';
            summary += '<span style="color:#b45309;font-weight:600;">&#9888; ' + orphaned.length + ' orphaned on server</span>';
            summary += '<span style="color:#1d4ed8;font-weight:600;">&#8635; ' + changed.length + ' size mismatch</span>';
            summary += '<span style="color:#6b7280;margin-left:auto;">' + (data.local_total||0) + ' local / ' + (data.remote_total||0) + ' remote files detected</span>';
            summary += '</div>';
            body.innerHTML = summary;

            function makeTable(title, color, rows, cols) {
                if (!rows.length) return '';
                let h = '<div style="margin-bottom:18px;">';
                h += '<div style="font-size:.8rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:' + color + ';margin-bottom:6px;">' + escH(title) + ' (' + rows.length + ')</div>';
                h += '<div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;font-size:.82rem;">';
                h += '<thead><tr style="background:#f1f5f9;">';
                cols.forEach(function(c) { h += '<th style="padding:6px 10px;text-align:left;color:#6b7280;font-size:.72rem;text-transform:uppercase;">' + escH(c) + '</th>'; });
                h += '</tr></thead><tbody>';
                rows.forEach(function(r, i) {
                    h += '<tr style="border-bottom:1px solid #f3f4f6;' + (i % 2 === 0 ? '' : 'background:#fafafa;') + '">';
                    r.forEach(function(cell) { h += '<td style="padding:6px 10px;font-family:monospace;font-size:.79rem;color:#374151;">' + escH(String(cell)) + '</td>'; });
                    h += '</tr>';
                });
                h += '</tbody></table></div></div>';
                return h;
            }

            body.innerHTML += makeTable('Missing on server (needs upload)', '#dc2626',
                missing.map(function(f) { return [f.path, fmtSize(f.size)]; }), ['File', 'Local size']);
            body.innerHTML += makeTable('Size mismatch (needs update)', '#1d4ed8',
                changed.map(function(f) { return [f.path, fmtSize(f.local_size), fmtSize(f.remote_size)]; }), ['File', 'Local size', 'Remote size']);
            body.innerHTML += makeTable('Orphaned on server (not in local build)', '#b45309',
                orphaned.map(function(f) { return [f.path, fmtSize(f.size)]; }), ['File', 'Remote size']);

            if (orphaned.length) {
                var delBtn = document.createElement('button');
                delBtn.className = 'btn btn-secondary';
                delBtn.style.cssText = 'background:#fff7ed;color:#92400e;border-color:#f59e0b;margin-top:4px;';
                delBtn.textContent = 'Delete ' + orphaned.length + ' Orphaned File' + (orphaned.length > 1 ? 's' : '') + ' from Server';
                delBtn.onclick = function() { deleteOrphaned(orphaned, delBtn); };
                body.appendChild(delBtn);
                var delResult = document.createElement('div');
                delResult.id = 'del-result';
                delResult.style.marginTop = '10px';
                body.appendChild(delResult);
            }

            panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        })
        .catch(function(err) {
            btn.disabled = false;
            btn.textContent = 'Audit Server';
            panel.style.display = 'block';
            body.innerHTML = '<p style="color:#dc2626;">Request failed: ' + escH(err.message) + '</p>';
        });
    };

    window.deleteOrphaned = function(orphaned, btn) {
        if (!confirm('Delete ' + orphaned.length + ' orphaned file' + (orphaned.length > 1 ? 's' : '') + ' from the server?\n\nThis cannot be undone.')) return;
        btn.disabled = true;
        btn.textContent = 'Deleting…';
        var result = document.getElementById('del-result');
        result.innerHTML = '';

        var fd = new FormData();
        fd.append('csrf_token', csrfToken);
        orphaned.forEach(function(f) { fd.append('paths[]', f.path); });

        fetch('deploy_delete_orphaned.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            btn.disabled = false;
            if (data.success) {
                btn.style.display = 'none';
                result.innerHTML = '<div style="color:#166534;font-weight:600;">&#10003; Deleted ' + (data.deleted || 0) + ' file' + ((data.deleted || 0) !== 1 ? 's' : '') + '.'
                    + (data.failed && data.failed.length ? ' <span style="color:#dc2626;">' + data.failed.length + ' failed.</span>' : '') + '</div>';
            } else {
                btn.textContent = 'Delete Orphaned Files';
                result.innerHTML = '<div style="color:#dc2626;">' + escH(data.error || 'Delete failed.') + '</div>';
            }
        })
        .catch(function(err) {
            btn.disabled = false;
            btn.textContent = 'Delete Orphaned Files';
            result.innerHTML = '<div style="color:#dc2626;">Request failed: ' + escH(err.message) + '</div>';
        });
    };

    function escH(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
    function fmtSize(b) { if (!b) return '—'; return b > 1048576 ? (b/1048576).toFixed(1)+' MB' : Math.round(b/1024)+' KB'; }
})();
</script>
