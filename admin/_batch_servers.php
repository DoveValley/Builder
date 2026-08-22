<?php
/**
 * "Pick deployment servers" — which boxes this batch lands on and how many each takes.
 *
 * No ordering control: the sequence boxes are filled in is decided elsewhere, so a
 * number here would be a second answer to a question this panel does not own.
 *
 * Two columns that look similar and must not be confused: ON IT NOW is read from the
 * box, TAKE is what this batch intends to put there. A plan is not a receipt, and the
 * interesting moment is when the two disagree.
 *
 * Expects: $csrfToken.
 */
?>
<!-- ===== DEPLOYMENT SERVERS ===== -->
<div class="card" id="ms-servers-card">
    <h3 style="margin-top:0;">2. Pick deployment servers</h3>
    <p class="hint">
        Which boxes this batch goes to and how many sites each takes.
        <strong>On it now</strong> is read from the server; <strong>take</strong> is this batch's
        plan. Leave <em>take</em> at 0 to mean &ldquo;whatever is left&rdquo;. Spreading across
        boxes is the point of having several &mdash; stacking one concentrates the blast radius.
    </p>

    <div id="ms-srv-body"><p class="hint">Reading the fleet&hellip;</p></div>

    <div style="margin-top:14px;display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
        <button type="button" class="btn btn-primary" id="ms-srv-save" onclick="msSaveServers()">Save plan</button>
        <button type="button" class="btn" onclick="msLoadServers(true)">&#8635; Re-read fleet</button>
        <span id="ms-srv-msg" class="hint"></span>
    </div>
</div>

<script>
(function () {
    const csrf = <?= json_encode($csrfToken) ?>;
    let msFleet = [], msPlan = [], msTargets = 0;

    // Escapes ' too: several callers interpolate this into a single-quoted JS string
    // literal inside a double-quoted onclick="..." attribute — a value containing an
    // apostrophe (e.g. a hand-edited server_id) would otherwise break out of that
    // string literal and inject markup/script into the admin page.
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

    window.msLoadServers = async function (refresh) {
        const box = document.getElementById('ms-srv-body');
        if (refresh) box.innerHTML = '<p class="hint">Reading the fleet…</p>';
        const r = await fetch('multisite_api.php?action=servers' + (refresh ? '&refresh=1' : ''));
        const d = await r.json();
        if (d.error) { box.innerHTML = '<p class="hint" style="color:#b91c1c;">' + esc(d.error) + '</p>'; return; }
        msFleet = d.fleet || []; msPlan = d.plan || []; msTargets = d.targets || 0;
        renderServers();
    };

    function planFor(id) { return msPlan.find(p => p.server_id === id) || null; }

    function renderServers() {
        const box = document.getElementById('ms-srv-body');
        if (!msFleet.length) {
            box.innerHTML = '<p class="hint">No servers registered. Add one in the Infrastructure console.</p>';
            return;
        }
        let h = '<table style="width:100%;font-size:.88rem;border-collapse:collapse;">'
              + '<thead><tr>'
              + '<th style="width:42px;"></th><th style="text-align:left;">Server</th>'
              + '<th style="text-align:right;">On it now</th>'
              + '<th style="text-align:right;">Take</th>'
              + '</tr></thead><tbody>';

        msFleet.forEach(function (s) {
            const p   = planFor(s.server_id);
            const on  = p !== null;
            // A box that is down or unfinished cannot be planned into a batch — the
            // checkbox is disabled rather than hidden, so it is clear it exists and why
            // it cannot be used.
            const usable = s.up && !s.pending;
            const state = s.pending ? '<span style="color:#92400e;">not set up yet</span>'
                        : (s.up ? '<span style="color:#166534;">up</span>'
                                : '<span style="color:#b91c1c;">cannot reach it</span>');
            h += '<tr style="border-top:1px solid #f1f5f9;' + (usable ? '' : 'opacity:.55;') + '">'
              +  '<td style="padding:9px 6px;"><input type="checkbox" class="ms-srv-use" data-id="' + esc(s.server_id) + '"'
              +      (on ? ' checked' : '') + (usable ? '' : ' disabled') + '></td>'
              +  '<td style="padding:9px 6px;"><strong>' + esc(s.label) + '</strong> &nbsp;<code style="font-size:.78rem;">' + esc(s.host) + '</code>'
              +      '<div class="hint" style="margin-top:2px;">' + state + (s.error ? ' &mdash; ' + esc(s.error) : '') + '</div></td>'
              +  '<td style="padding:9px 6px;text-align:right;font-weight:700;">' + (s.sites | 0) + '</td>'
              +  '<td style="padding:9px 6px;text-align:right;"><input type="number" min="0" class="ms-srv-count" data-id="' + esc(s.server_id) + '"'
              +      ' value="' + (p ? (p.count | 0) : 0) + '" style="width:80px;text-align:right;"' + (usable ? '' : ' disabled') + '></td>'
              +  '</tr>';
        });
        // A plan can outlive the server it names — a box removed from the console, or
        // an id that never matched. Rendering only what the fleet still has would drop
        // it silently while it stays in the file, so the count on screen and the count
        // on disk would disagree and neither would say so.
        const known = new Set(msFleet.map(f => f.server_id));
        const orphans = msPlan.filter(p => !known.has(p.server_id));
        orphans.forEach(function (p) {
            h += '<tr style="border-top:1px solid #f1f5f9;background:#fef2f2;">'
              +  '<td style="padding:9px 6px;text-align:center;">&#9888;</td>'
              +  '<td style="padding:9px 6px;"><strong>' + esc(p.label || p.server_id) + '</strong>'
              +      ' <code style="font-size:.78rem;">' + esc(p.host || '') + '</code>'
              +      '<div class="hint" style="color:#b91c1c;margin-top:2px;">planned, but no longer in the console'
              +      ' &mdash; <a href="#" onclick="msDropServer(\'' + esc(p.server_id) + '\');return false;">remove from plan</a></div></td>'
              +  '<td style="padding:9px 6px;text-align:right;color:#94a3b8;">&mdash;</td>'
              +  '<td style="padding:9px 6px;text-align:right;color:#b91c1c;">' + (p.count | 0) + '</td>'
              +  '</tr>';
        });
        h += '</tbody></table><div id="ms-srv-tally" class="hint" style="margin-top:10px;"></div>';
        box.innerHTML = h;
        box.querySelectorAll('.ms-srv-use, .ms-srv-count').forEach(el => el.addEventListener('input', tally));
        tally();
    }

    // Says what the plan adds up to against the target list, because "20 + 20" against
    // 45 rows is the mistake this panel exists to make visible before a run, not after.
    function tally() {
        const rows = collect();
        const known = new Set(msFleet.map(f => f.server_id));
        const orphanCount = msPlan.filter(p => !known.has(p.server_id)).length;
        let sum = 0, anyRest = false;
        rows.forEach(r => { if (r.count > 0) sum += r.count; else anyRest = true; });
        const el = document.getElementById('ms-srv-tally');
        if (!el) return;
        if (!rows.length) { el.innerHTML = 'No servers picked — the batch has nowhere to go.'; el.style.color = '#b91c1c'; return; }
        let msg = '<strong>' + rows.length + '</strong> server' + (rows.length === 1 ? '' : 's') + ' picked · '
                + 'allocated <strong>' + sum + '</strong> of <strong>' + msTargets + '</strong> targets';
        if (anyRest)            { msg += ' · the rest go to the boxes set to 0'; el.style.color = '#475569'; }
        else if (sum < msTargets) { msg += ' — <strong>' + (msTargets - sum) + ' would have nowhere to go</strong>'; el.style.color = '#b91c1c'; }
        else if (sum > msTargets) { msg += ' — more room than targets, which is fine'; el.style.color = '#475569'; }
        else                      { msg += ' — exact'; el.style.color = '#166534'; }
        if (orphanCount) msg += ' · <strong style="color:#b91c1c;">' + orphanCount
                              + ' planned server' + (orphanCount === 1 ? '' : 's') + ' no longer exist'
                              + (orphanCount === 1 ? 's' : '') + '</strong>';
        el.innerHTML = msg;
    }

    function collect() {
        const out = [];
        document.querySelectorAll('.ms-srv-use').forEach(function (cb) {
            if (!cb.checked) return;
            const id = cb.dataset.id;
            const s  = msFleet.find(f => f.server_id === id) || {};
            const cEl = document.querySelector('.ms-srv-count[data-id="' + id + '"]');
            out.push({
                server_id: id, host: s.host || '', label: s.label || id,
                count: Math.max(0, parseInt(cEl && cEl.value, 10) || 0),
            });
        });
        return out;
    }

    // Drop a planned server the fleet no longer has, and persist immediately — the
    // point of surfacing it is to be able to clear it.
    window.msDropServer = async function (id) {
        msPlan = msPlan.filter(p => p.server_id !== id);
        const fd = new FormData();
        fd.append('csrf_token', csrf);
        fd.append('plan', JSON.stringify(msPlan));
        const r = await fetch('multisite_api.php?action=save_servers', { method: 'POST', body: fd });
        const d = await r.json();
        if (!d.error) { msPlan = d.plan || []; renderServers(); }
    };

    window.msSaveServers = async function () {
        const msg = document.getElementById('ms-srv-msg');
        const fd = new FormData();
        fd.append('csrf_token', csrf);
        fd.append('plan', JSON.stringify(collect()));
        msg.textContent = 'Saving…';
        const r = await fetch('multisite_api.php?action=save_servers', { method: 'POST', body: fd });
        const d = await r.json();
        if (d.error) { msg.textContent = d.error; msg.style.color = '#b91c1c'; return; }
        msPlan = d.plan || [];
        msg.textContent = 'Saved.'; msg.style.color = '#166534';
        renderServers();
    };

    msLoadServers(false);
})();
</script>
