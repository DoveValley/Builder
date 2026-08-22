<?php
/**
 * "Go Live (DNS)" — phase 6, the only card that touches the outside world.
 *
 * Deliberately does not reimplement anything: every button here calls straight into
 * the Infra console's own per-domain pipeline (admin/infra/lib/pipeline.php +
 * golive.php) — the same Cloudflare zone/A-record and registrar nameserver-switch
 * code the Bulk tab uses. This card is a thin, batch-scoped VIEW onto that pipeline,
 * filtered by the "batch" tag create_hosts.php writes onto each domain
 * (admin/infra/lib/state.php's `domains.batch` column). It talks to it through new
 * JSON actions on multisite_api.php (golive_status / golive_do / golive_run /
 * golive_offline) rather than admin/infra/actions/pipeline_golive.php, which is built
 * to redirect back to the Bulk tab's own page.
 *
 * Expects: $csrfToken.
 */
?>
<!-- ===== GO LIVE (DNS) ===== -->
<div class="card" id="ms-golive-card">
    <h3 style="margin-top:0;">6. Go Live (DNS)</h3>
    <p class="hint">
        Creates the Cloudflare zone for each domain, then switches its nameservers at the
        registrar &mdash; the moment a domain becomes publicly reachable. <strong>Go Live</strong> is
        disabled until a domain's zone and upload are both confirmed done. <strong>Take offline</strong>
        removes the domain's Cloudflare record (not its nameservers) so you can pull a
        site back &mdash; usually within seconds &mdash; without waiting hours for DNS to
        re-propagate; pressing Create zone again restores it.
    </p>

    <div id="ms-golive-state" class="hint" style="margin-bottom:10px;">Loading&hellip;</div>

    <div id="ms-golive-body"><p class="hint">Loading&hellip;</p></div>
</div>

<script>
(function () {
    const csrf = <?= json_encode($csrfToken) ?>;
    let glRows = [];

    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])); }

    function ago(ts) {
        if (!ts) return '';
        const s = Math.max(0, Math.floor(Date.now() / 1000) - ts);
        if (s < 60) return s + 's ago';
        if (s < 3600) return Math.floor(s / 60) + 'm ago';
        if (s < 86400) return Math.floor(s / 3600) + 'h ago';
        return Math.floor(s / 86400) + 'd ago';
    }

    const STYLE = {
        ok:      ['#166534', '#dcfce7', '#86efac', '✓'],
        fail:    ['#991b1b', '#fee2e2', '#fca5a5', '✗'],
        running: ['#1d4ed8', '#dbeafe', '#93c5fd', '●'],
    };
    function badge(cell) {
        const st = (cell && cell.state) || '';
        const [fg, bg, bd, glyph] = STYLE[st] || ['#94a3b8', '#f1f5f9', '#e2e8f0', '○'];
        const note = cell && cell.note ? esc(cell.note) : '';
        const when = cell && cell.at ? ago(cell.at) : '';
        return '<div style="display:inline-flex;align-items:center;gap:5px;padding:2px 8px;border-radius:4px;' +
            'color:' + fg + ';background:' + bg + ';border:1px solid ' + bd + ';font-weight:700;font-size:0.78rem;" ' +
            (note ? 'title="' + note + '"' : '') + '>' + glyph + (when ? ' <span style="font-weight:400;opacity:.8;">' + when + '</span>' : '') + '</div>';
    }

    function fd(extra) {
        const f = new FormData();
        f.append('csrf_token', csrf);
        for (const k in extra) f.append(k, extra[k]);
        return f;
    }

    async function post(action, extra) {
        try {
            const r = await fetch('multisite_api.php?action=' + action, { method: 'POST', body: fd(extra) });
            return await r.json();
        } catch (e) {
            // Centralized so every caller (msGoLiveZone/Offline/Release/RunColumn) gets
            // this for free instead of leaving its button stuck on "Working…" forever.
            return { error: 'Could not reach the server — try again.' };
        }
    }

    window.msGoLiveZone = async function (domain, btn) {
        btn.disabled = true; btn.textContent = 'Working…';
        const r = await post('golive_do', { step: 'zone', domain: domain });
        if (r.error) alert(r.error);
        await loadGoLive();
    };

    window.msGoLiveOffline = async function (domain, btn) {
        if (!confirm(domain + ' will stop resolving within seconds. Its Cloudflare zone and nameservers are left in place, so pressing Create zone brings it straight back. Continue?')) return;
        btn.disabled = true; btn.textContent = 'Working…';
        const r = await post('golive_offline', { domain: domain });
        if (r.error || r.ok === false) alert(r.message || r.error || 'Could not take it offline.');
        await loadGoLive();
    };

    window.msGoLiveRelease = async function (domain, btn) {
        if (!confirm('This switches ' + domain + '’s nameservers now — it will become PUBLICLY REACHABLE. Continue?')) return;
        btn.disabled = true; btn.textContent = 'Working…';
        const r = await post('golive_do', { step: 'golive', domain: domain });
        if (r.error) alert(r.error);
        else if (!r.ok) alert(r.msg || 'Go Live did not complete — see the status column.');
        await loadGoLive();
    };

    window.msGoLiveRefreshLive = async function (domain, btn) {
        btn.disabled = true;
        try {
            await fetch('multisite_api.php?action=golive_refresh&step=live&domain=' + encodeURIComponent(domain));
        } catch (e) {
            // Fall through to loadGoLive() regardless — it fully re-renders this row
            // (a fresh button included), so a dropped request here doesn't leave the
            // ↻ button stuck disabled with no way to retry short of a page reload.
        }
        await loadGoLive();
    };

    window.msGoLiveRunColumn = async function (step) {
        const n = glRows.length;
        const label = step === 'zone' ? 'Create zone' : 'Go Live';
        const warn = step === 'golive'
            ? 'This releases EVERY eligible domain in this batch (up to ' + n + ') right now — each one becomes publicly reachable. Continue?'
            : 'Run "' + label + '" for every domain in this batch that still needs it (up to ' + n + ')?';
        if (!confirm(warn)) return;
        const el = document.getElementById('ms-golive-state');
        el.textContent = 'Running ' + label + ' for the whole batch… this can take a while for many domains.';
        const r = await post('golive_run', { step: step });
        if (r.error) alert(r.error);
        else el.textContent = label + ': ran ' + r.ran + ', ' + r.ok + ' ok'
            + (r.failed ? ', ' + r.failed + ' failed' : '')
            + (r.blocked ? ', ' + r.blocked + ' blocked by an earlier step' : '') + '.';
        await loadGoLive(false);
    };

    function renderRow(r) {
        const zoneOk = r.zone.state === 'ok';
        const zoneBtn = zoneOk
            ? '<button type="button" class="btn" style="padding:1px 8px;font-size:0.76rem;" onclick="msGoLiveOffline(\'' + esc(r.domain) + '\', this)">Take offline</button>'
            : '<button type="button" class="btn" style="padding:1px 8px;font-size:0.76rem;" onclick="msGoLiveZone(\'' + esc(r.domain) + '\', this)">Create zone</button>';

        const canRelease = zoneOk && r.upload_ok && r.golive.state !== 'ok';
        let goLiveCell = badge(r.golive);
        if (r.golive.state !== 'ok') {
            const why = !zoneOk ? 'needs the Cloudflare zone first' : (!r.upload_ok ? 'needs Upload sites (card 5) first' : '');
            goLiveCell += ' <button type="button" class="btn" style="padding:1px 8px;font-size:0.76rem;"' +
                (canRelease ? '' : ' disabled title="' + esc(why) + '"') +
                ' onclick="msGoLiveRelease(\'' + esc(r.domain) + '\', this)">Go Live</button>';
        }

        let liveCell;
        if (r.live.state === 'ok') {
            liveCell = '<a href="https://' + esc(r.domain) + '" target="_blank" rel="noopener" style="color:#166534;font-weight:700;">' +
                esc(r.domain) + ' ↗</a>';
        } else {
            liveCell = badge(r.live) +
                ' <button type="button" class="btn" style="padding:1px 6px;font-size:0.72rem;" title="Check now" onclick="msGoLiveRefreshLive(\'' + esc(r.domain) + '\', this)">↻</button>';
        }

        return '<tr>' +
            '<td>' + esc(r.domain) + '</td>' +
            '<td>' + badge(r.zone) + ' ' + zoneBtn + '</td>' +
            '<td>' + goLiveCell + '</td>' +
            '<td>' + badge(r.dns) + '</td>' +
            '<td>' + liveCell + '</td>' +
            '</tr>';
    }

    function render(rows) {
        glRows = rows;
        const box = document.getElementById('ms-golive-body');
        if (!rows.length) {
            box.innerHTML = '<p class="hint">Nothing to show yet — run Create host (card 3) first so a domain has something to tag.</p>';
            return;
        }
        const live = rows.filter(r => r.live.state === 'ok').length;
        document.getElementById('ms-golive-state').textContent = live + ' of ' + rows.length + ' live.';
        box.innerHTML = '<div style="overflow-x:auto;"><table style="width:100%;font-size:0.85rem;border-collapse:collapse;">' +
            '<thead><tr>' +
            '<th style="text-align:left;">Domain</th>' +
            '<th style="text-align:left;">CF Zone <button type="button" class="btn" style="padding:0 6px;font-size:0.7rem;" onclick="msGoLiveRunColumn(\'zone\')">▶ all</button></th>' +
            '<th style="text-align:left;">Go Live <button type="button" class="btn" style="padding:0 6px;font-size:0.7rem;" onclick="msGoLiveRunColumn(\'golive\')">▶ all</button></th>' +
            '<th style="text-align:left;">DNS</th>' +
            '<th style="text-align:left;">Live</th>' +
            '</tr></thead><tbody>' + rows.map(renderRow).join('') + '</tbody></table></div>';
    }

    async function loadGoLive(showLoading) {
        if (showLoading !== false) document.getElementById('ms-golive-body').innerHTML = '<p class="hint">Loading…</p>';
        const r = await fetch('multisite_api.php?action=golive_status').then(x => x.json()).catch(() => null);
        if (!r || r.error) { document.getElementById('ms-golive-body').innerHTML = '<p class="hint" style="color:#991b1b;">' + esc((r && r.error) || 'Could not load.') + '</p>'; return; }
        render(r.rows || []);
    }

    window.msGoLiveRefreshAll = () => loadGoLive();
    loadGoLive();
})();
</script>
