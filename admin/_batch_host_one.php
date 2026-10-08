<?php
/**
 * "Create host — one domain" — a manual override of a single row's box.
 *
 * Card 2 (Pick deployment servers) only sets each box's quota; card 3A (Create host
 * — multiple domains) spreads the whole batch across those boxes round-robin, with no say over which
 * specific domain lands where. This card is for the one time that's wrong: a
 * specific domain needs a specific box. It calls the exact same engine Create host
 * (and the Infra console's own Host button) call — infra_provision_one(), via the
 * locked wrapper — for just the one domain named here, on the box you pick instead
 * of whatever the plan/round-robin would have assigned, then writes its FTP
 * credentials back into this row the same way Create host does, so every later
 * step (Generate, Upload, Go Live) sees it as already hosted.
 *
 * Expects: $csrfToken.
 */
?>
<!-- ===== CREATE HOST — ONE DOMAIN ===== -->
<div class="card" id="ms-hostone-card">
    <h3 style="margin-top:0;">3B. Create host &mdash; one domain</h3>
    <p class="hint">
        Send a single domain to a specific box instead of letting the plan above spread
        it automatically. Pick the domain, pick the box, press the button &mdash;
        everything else (vhost, FTP login, writing credentials back into this row,
        restarting the box) happens exactly the same way Create host does it, just for
        one row instead of the whole batch.
    </p>
    <div style="display:flex;gap:14px;align-items:flex-end;flex-wrap:wrap;">
        <label class="hint">Domain<br>
            <select id="ms-hostone-domain" style="min-width:220px;padding:7px 10px;border:1px solid #d1d5db;border-radius:8px;">
                <option value="">Pick a domain&hellip;</option>
            </select>
        </label>
        <label class="hint">Box<br>
            <select id="ms-hostone-box" style="min-width:200px;padding:7px 10px;border:1px solid #d1d5db;border-radius:8px;">
                <option value="">Loading&hellip;</option>
            </select>
        </label>
        <label class="hint"><input type="checkbox" id="ms-hostone-force"> Force (recreate if it already has a host)</label>
        <button type="button" class="btn btn-primary" id="ms-hostone-btn" onclick="msCreateHostOne()">Create host on this box</button>
    </div>
    <div id="ms-hostone-msg" class="hint" style="margin-top:10px;"></div>
</div>

<script>
(function () {
    const csrf = <?= json_encode($csrfToken) ?>;

    // The domain list is the same one Create host's and Upload's "only these domains"
    // pickers already fetch (multisite_api.php?action=domains) — this is its own
    // separate fetch of that same endpoint rather than reaching into their closures.
    fetch('multisite_api.php?action=domains').then(r => r.json()).then(d => {
        const sel = document.getElementById('ms-hostone-domain');
        (d.domains || []).forEach(x => {
            const opt = document.createElement('option');
            opt.value = x.domain;
            opt.textContent = x.domain + (x.live ? ' (live)' : '');
            sel.appendChild(opt);
        });
    }).catch(() => {});

    // The box list is this card's own fetch of the same fleet card 2 shows — not
    // wired to card 2's plan/quota state, since this is a one-off override of a
    // single row, not a change to the saved plan.
    fetch('multisite_api.php?action=servers').then(r => r.json()).then(d => {
        const sel = document.getElementById('ms-hostone-box');
        sel.innerHTML = '<option value="">Pick a box&hellip;</option>';
        (d.fleet || []).forEach(s => {
            const usable = s.up && !s.pending;
            const opt = document.createElement('option');
            opt.value = s.server_id;
            opt.textContent = s.label + (usable ? '' : ' (not usable)');
            if (!usable) opt.disabled = true;
            sel.appendChild(opt);
        });
    }).catch(() => {
        document.getElementById('ms-hostone-box').innerHTML = '<option value="">Could not load boxes</option>';
    });

    window.msCreateHostOne = async function () {
        const domain   = document.getElementById('ms-hostone-domain').value;
        const serverId = document.getElementById('ms-hostone-box').value;
        const force    = document.getElementById('ms-hostone-force').checked;
        const btn = document.getElementById('ms-hostone-btn');
        const msg = document.getElementById('ms-hostone-msg');
        if (!domain)   { msg.textContent = 'Pick a domain first.'; msg.style.color = '#b91c1c'; return; }
        if (!serverId) { msg.textContent = 'Pick a box first.';    msg.style.color = '#b91c1c'; return; }

        if (force && !confirm('Force will re-create the host/FTP account for ' + domain
            + ' even though it already has one. Continue?')) return;

        const fd = new FormData();
        fd.append('csrf_token', csrf);
        fd.append('domain', domain);
        fd.append('server_id', serverId);
        if (force) fd.append('force', '1');

        btn.disabled = true; msg.textContent = 'Working…'; msg.style.color = '#475569';
        try {
            const r = await fetch('multisite_api.php?action=create_host_one', { method: 'POST', body: fd });
            const d = await r.json();
            btn.disabled = false;
            if (d.error) { msg.textContent = d.error; msg.style.color = '#b91c1c'; return; }
            msg.textContent = (d.ok ? '✓ ' : '✗ ') + domain + ' on ' + d.box + ' — ' + d.message;
            msg.style.color = d.ok ? '#166534' : '#b91c1c';
            if (d.ok) setTimeout(() => window.location.reload(), 1200);
        } catch (e) {
            btn.disabled = false;
            msg.textContent = 'Could not reach the server — try again.';
            msg.style.color = '#b91c1c';
        }
    };
})();
</script>
