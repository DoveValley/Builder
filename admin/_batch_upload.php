<?php
/**
 * "Upload sites" — phase 5, sending what phase 4 generated.
 *
 * Generating and uploading used to be one act: build to a temp folder, push it, delete
 * it. Splitting them is what makes "build fifty and look at them before anything goes
 * live" possible, and it means a failed upload costs the upload rather than the build —
 * a re-run sends the same files again without regenerating a thing.
 *
 * Expects: $csrfToken.
 */
?>
<!-- ===== UPLOAD SITES ===== -->
<div class="card" id="ms-upload-card">
    <h3 style="margin-top:0;">5. Upload sites</h3>
    <p class="hint">
        Sends the generated sites to the hosts created in step 3. Uploads are
        <strong>incremental</strong> &mdash; a re-run sends only the files that changed, so
        pressing this again after a failure is cheap and safe. Tick <em>force</em> to send
        everything regardless.
    </p>

    <div id="ms-up-state" class="hint" style="margin-bottom:12px;">Checking what is ready&hellip;</div>

    <div style="display:flex;gap:18px;flex-wrap:wrap;align-items:flex-end;">
        <label class="hint">Limit (0 = all)<br><input type="number" id="ms-up-limit" value="0" min="0" style="width:90px;"></label>
        <label class="hint">Only this domain (optional)<br><input type="text" id="ms-up-only" placeholder="example.com" style="width:200px;"></label>
        <label class="hint"><input type="checkbox" id="ms-up-force"> Force (send every file)</label>
        <button type="button" class="btn btn-primary" id="ms-up-btn" onclick="msUpload()">Upload sites</button>
        <span id="ms-up-msg" class="hint"></span>
    </div>

    <pre id="ms-up-out" style="display:none;margin-top:14px;background:#0f172a;color:#e2e8f0;padding:12px;border-radius:6px;font-size:0.8rem;max-height:340px;overflow:auto;white-space:pre-wrap;"></pre>
</div>

<script>
(function () {
    const csrf = <?= json_encode($csrfToken) ?>;
    let upTimer = null;

    // Says what is actually uploadable before the button is pressed, and separates the
    // two reasons a row is not: never generated, or generated with nowhere to send it.
    async function upState() {
        const el = document.getElementById('ms-up-state');
        const r = await fetch('multisite_api.php?action=built');
        const d = await r.json();
        if (d.error) { el.textContent = d.error; return; }
        const bits = ['<strong>' + (d.ready | 0) + '</strong> ready to upload'];
        if (d.no_build | 0) bits.push((d.no_build | 0) + ' not generated yet');
        if (d.no_creds | 0) bits.push((d.no_creds | 0) + ' without credentials (run step 3)');
        el.innerHTML = bits.join(' &middot; ') + ' &middot; ' + (d.total | 0) + ' in the target list';
        el.style.color = (d.ready | 0) > 0 ? '#166534' : '#94a3b8';
        document.getElementById('ms-up-btn').disabled = (d.ready | 0) === 0;
    }

    window.msUpload = async function () {
        const btn = document.getElementById('ms-up-btn');
        const msg = document.getElementById('ms-up-msg');
        const out = document.getElementById('ms-up-out');

        const fd = new FormData();
        fd.append('csrf_token', csrf);
        const lim = parseInt(document.getElementById('ms-up-limit').value, 10) || 0;
        const only = document.getElementById('ms-up-only').value.trim();
        if (lim > 0) fd.append('limit', String(lim));
        if (only)    fd.append('only', only);
        if (document.getElementById('ms-up-force').checked) fd.append('force', '1');

        btn.disabled = true; msg.textContent = 'Starting…'; msg.style.color = '#475569';
        const r = await fetch('multisite_api.php?action=upload', { method: 'POST', body: fd });
        const d = await r.json();
        if (d.error) { btn.disabled = false; msg.textContent = d.error; msg.style.color = '#b91c1c'; return; }

        out.style.display = 'block'; out.textContent = 'Working…'; msg.textContent = 'Uploading…';
        clearInterval(upTimer);
        upTimer = setInterval(async function () {
            const s = await (await fetch('multisite_api.php?action=upload_status&run_id=' + encodeURIComponent(d.run_id))).json();
            if (s.error) { clearInterval(upTimer); out.textContent = s.error; btn.disabled = false; return; }
            out.textContent = s.log || 'Working…';
            out.scrollTop = out.scrollHeight;
            if (!s.done) return;
            clearInterval(upTimer);
            btn.disabled = false;
            const failed = /(\d+) failed/.exec(s.log || '');
            const bad = failed && parseInt(failed[1], 10) > 0;
            msg.textContent = bad ? 'Finished with failures — read the log.' : 'Done.';
            msg.style.color = bad ? '#b91c1c' : '#166534';
            upState();
        }, 2000);
    };

    upState();
})();
</script>
