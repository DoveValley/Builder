<?php
// Deploy tab — Build (static HTML generator) + Push (FTP deploy).
// $tab, $csrfToken available from index.php.

$deployFile = ACTIVE_SITE_DIR . '/deploy.json';
$deploy = file_exists($deployFile) ? (json_decode(file_get_contents($deployFile), true) ?: []) : [];
?>
<div class="tab-content" style="<?= $tab === 'deploy' ? '' : 'display:none;' ?>">

<h2 style="margin-bottom:6px;">Deploy</h2>
<p class="hint" style="margin-bottom:24px;">Generate a fully-static copy of this site, then push it to your web host via FTP.</p>

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

    <button id="push-btn" class="btn" onclick="startPush()">Push to Server</button>
    <div id="push-log" class="deploy-log" style="display:none;"></div>
</div>

</div><!-- /grid -->
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
})();
</script>
