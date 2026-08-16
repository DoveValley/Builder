<?php
/**
 * "Create host" — phase 2, for the whole batch.
 *
 * Creates the vhost and its folder on the chosen box, creates an FTP account scoped to
 * that folder alone, and writes the credentials back into this batch's target list.
 *
 * That write-back is the point. Provisioning stores credentials in fleet.db while the
 * run reads them from params.csv, and nothing joined the two — so a batch would build
 * every site and then log "No FTP creds in row" for every one of them.
 *
 * Expects: $csrfToken.
 */
?>
<!-- ===== CREATE HOSTS ===== -->
<div class="card" id="ms-hosts-card">
    <h3 style="margin-top:0;">3. Create host</h3>
    <p class="hint">
        Builds the home for each site on the servers picked above: the vhost, its folder, and an
        FTP login that can write to that folder and nothing else. The credentials come back and are
        stored on each row, so the run has what it needs to deploy.
        <br><br>
        Safe to press twice &mdash; rows that already have credentials are skipped. The web server
        is restarted <strong>once per box at the end</strong>: until it restarts it serves Hestia's
        own default page with a <code>200</code>, which looks like nothing is wrong.
    </p>

    <div style="display:flex;gap:14px;align-items:center;flex-wrap:wrap;">
        <button type="button" class="btn btn-primary" id="ms-hosts-btn" onclick="msCreateHosts()">Create host areas</button>
        <label class="hint"><input type="checkbox" id="ms-hosts-force"> Force (re-create rows that already have credentials)</label>
        <span id="ms-hosts-msg" class="hint"></span>
    </div>

    <pre id="ms-hosts-out" style="display:none;margin-top:14px;background:#0f172a;color:#e2e8f0;padding:12px;border-radius:6px;font-size:0.8rem;max-height:340px;overflow:auto;white-space:pre-wrap;"></pre>
</div>

<script>
(function () {
    const csrf = <?= json_encode($csrfToken) ?>;
    let hostsTimer = null;

    window.msCreateHosts = async function () {
        const btn = document.getElementById('ms-hosts-btn');
        const msg = document.getElementById('ms-hosts-msg');
        const out = document.getElementById('ms-hosts-out');

        const fd = new FormData();
        fd.append('csrf_token', csrf);
        if (document.getElementById('ms-hosts-force').checked) fd.append('force', '1');

        btn.disabled = true; msg.textContent = 'Starting…'; msg.style.color = '#475569';
        const r = await fetch('multisite_api.php?action=create_hosts', { method: 'POST', body: fd });
        const d = await r.json();
        if (d.error) { btn.disabled = false; msg.textContent = d.error; msg.style.color = '#b91c1c'; return; }

        out.style.display = 'block';
        out.textContent = 'Working…';
        msg.textContent = 'Running…';
        poll(d.run_id);
    };

    // Polls the same way the run panel does: the work is detached, so the page is
    // reading a log rather than holding a request open.
    function poll(runId) {
        clearInterval(hostsTimer);
        hostsTimer = setInterval(async function () {
            const r = await fetch('multisite_api.php?action=create_hosts_status&run_id=' + encodeURIComponent(runId));
            const d = await r.json();
            const out = document.getElementById('ms-hosts-out');
            if (d.error) { clearInterval(hostsTimer); out.textContent = d.error; return; }
            out.textContent = d.log || 'Working…';
            out.scrollTop = out.scrollHeight;
            if (!d.done) return;

            clearInterval(hostsTimer);
            const btn = document.getElementById('ms-hosts-btn');
            const msg = document.getElementById('ms-hosts-msg');
            btn.disabled = false;
            const failed = / (\d+) failed/.exec(d.log || '');
            const bad = failed && parseInt(failed[1], 10) > 0;
            msg.textContent = bad ? 'Finished with failures — read the log.' : 'Done.';
            msg.style.color = bad ? '#b91c1c' : '#166534';
            // The target list and the phase strip both changed; reload so neither is
            // showing the state from before this ran.
            if (!bad) setTimeout(() => window.location.reload(), 1200);
        }, 2000);
    }
})();
</script>
