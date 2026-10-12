<?php
/**
 * Rollout — the SIMPLE view of one batch. A screen only: every number and every action
 * comes from the core modules through multisite_api.php (rollout_* actions →
 * infra/lib/fleet_view.php, golive.php, fleet_settings.php). The detailed view is
 * batch.php; both work on the same open batch.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/multisite/batch.php';

if (empty($_SESSION['admin_logged_in'])) { header('Location: login.php'); exit; }
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$active = ms_active_batch();
if (!$active) { header('Location: sites.php'); exit; }
$batch = ms_batch_meta($active['master_id'], $active['batch_id']) ?: ['name' => $active['batch_id']];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h($batch['name']) ?> — Rollout — <?= h(SITE_TITLE) ?></title>
<link rel="stylesheet" href="../assets/css/style.css">
<?php $_adminCssMtime = css_minify_to(__DIR__ . '/../assets/css/admin.src.css', __DIR__ . '/../assets/css/admin.css'); ?>
<link rel="stylesheet" href="../assets/css/admin.css?v=<?= (int) $_adminCssMtime ?>">
<style>
.ro-views a{padding:5px 12px;border:1px solid #cbd5e1;border-radius:6px;text-decoration:none;color:#334155;font-size:.85rem;margin-left:4px}
.ro-views a.on{background:#1e3a8a;border-color:#1e3a8a;color:#fff}
.ro-bar{display:flex;flex-wrap:wrap;gap:14px;align-items:center;padding:12px 14px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;margin:0 0 12px}
.ro-chip{border:1px solid #cbd5e1;background:#fff;border-radius:999px;padding:5px 13px;cursor:pointer;font-weight:600;font-size:.9rem}
.ro-chip.on{background:#1e3a8a;color:#fff;border-color:#1e3a8a}
.ro-tabs{display:flex;gap:6px;flex-wrap:wrap;margin:0 0 10px}
.ro-tab{border:none;background:#eef2f7;border-radius:6px;padding:6px 11px;cursor:pointer;font-size:.84rem;color:#334155}
.ro-tab.on{background:#334155;color:#fff}
.ro-cal{display:flex;gap:4px;flex-wrap:wrap;margin:0 0 12px;font-size:.75rem;color:#475569}
.ro-cal div{min-width:58px;text-align:center;padding:5px 4px;border-radius:6px;background:#f1f5f9}
.ro-cal div.has{background:#dbeafe;color:#1e3a8a;font-weight:600}
table.ro{width:100%;border-collapse:collapse;font-size:.84rem}
table.ro th{text-align:left;font-weight:600;color:#475569;border-bottom:2px solid #e2e8f0;padding:6px 7px;position:sticky;top:0;background:#fff}
table.ro td{border-bottom:1px solid #f1f5f9;padding:6px 7px;vertical-align:middle}
table.ro tr:hover td{background:#f8fafc}
.dot{display:inline-block;width:11px;height:11px;border-radius:50%;background:#e2e8f0}
.dot.ok{background:#16a34a}.dot.running{background:#f59e0b}.dot.fail{background:#dc2626}.dot.todo{background:#cbd5e1}
.pill{font-size:.75rem;padding:2px 8px;border-radius:999px;background:#f1f5f9;color:#475569;white-space:nowrap}
.pill.Live{background:#dcfce7;color:#166534}.pill.Scheduled{background:#dbeafe;color:#1e3a8a}.pill.Ready{background:#fef9c3;color:#854d0e}
.ro-msg{font-weight:600}
.muted{color:#94a3b8}
</style>
</head>
<body class="admin-body">
<div class="admin-wrapper">
    <div class="admin-header">
        <h1>
            <a href="sites.php" style="color:inherit;text-decoration:none;font-size:0.75em;opacity:0.7;margin-right:10px;">&#8592; Factory</a>
            <?php if (isset($batch['seq'])): ?><span style="color:#94a3b8;font-weight:400">#<?= (int) $batch['seq'] ?></span> <?php endif; ?>
            <?= h($batch['name']) ?>
        </h1>
        <div class="ro-views"><a href="rollout.php" class="on">Simple</a><a href="batch.php">Detailed</a></div>
    </div>

    <p class="hint" id="ro-settings" style="margin:0 0 14px;">Loading&hellip;</p>

    <div class="ro-bar">
        <span><strong>Go live</strong> per day:
            <button type="button" class="ro-chip" data-n="3">3</button>
            <button type="button" class="ro-chip" data-n="4">4</button>
            <button type="button" class="ro-chip on" data-n="5">5</button>
            <button type="button" class="ro-chip" data-n="6">6</button>
        </span>
        <label>starting <input type="date" id="ro-start"></label>
        <button type="button" class="btn" id="ro-sched">Schedule selected</button>
        <button type="button" class="btn sec" id="ro-unsched">Unschedule selected</button>
        <span class="ro-msg" id="ro-msg"></span>
    </div>

    <div class="ro-cal" id="ro-cal"></div>

    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
        <div class="ro-tabs" id="ro-tabs"></div>
        <div class="hint">Select:
            <a href="#" data-sel="ready">all ready</a> &middot;
            <a href="#" data-sel="scheduled">all scheduled</a> &middot;
            <a href="#" data-sel="none">none</a>
            &nbsp; <strong id="ro-count">0</strong> selected
        </div>
    </div>

    <table class="ro">
        <thead><tr>
            <th style="width:26px;"><input type="checkbox" id="ro-all" title="Select every row shown that can be scheduled"></th>
            <th>Domain</th><th>City</th><th>Box</th><th>Cloudflare</th>
            <th title="Files generated">Built</th><th title="Files on the server">Uploaded</th><th title="Cloudflare zone">Zone</th>
            <th>Go live</th><th title="Answers on its own address with a valid certificate">Live</th><th>Status</th>
        </tr></thead>
        <tbody id="ro-body"><tr><td colspan="11" class="muted">Loading&hellip;</td></tr></tbody>
    </table>
    <p class="hint" style="margin-top:12px;">Dots: <span class="dot ok"></span> done &nbsp;<span class="dot running"></span> running &nbsp;<span class="dot fail"></span> failed &nbsp;<span class="dot todo"></span> checked, not yet &nbsp;<span class="dot"></span> not checked.
        Only uploaded domains can be scheduled. Releases happen automatically at the go-live time (US Central) on each date.</p>
</div>

<script>
(function () {
    const CSRF = <?= json_encode($_SESSION['csrf_token']) ?>;
    const API  = 'multisite_api.php';
    let rows = [], settings = {}, perDay = 5, view = 'all';
    const sel = new Set();
    const $ = id => document.getElementById(id);
    const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));

    function label(r) {
        if (r.status === 'live') return 'Live';
        if (r.status === 'releasing') return 'Switching DNS';
        if (r.status === 'awaiting-ns') return 'Nameservers: manual step';
        if (r.status === 'queued' && r.go_live_at) return 'Scheduled';
        if (r.ready) return 'Ready';
        if (!r.box) return 'No box yet';
        if (r.steps.built !== 'ok') return 'Not built';
        if (r.steps.upload !== 'ok') return 'Not uploaded';
        return r.status || 'Not ready';
    }
    const canTick = r => r.ready || (r.status === 'queued' && r.go_live_at);
    function niceDate(d) {                       // 'YYYY-MM-DD' → 'Thu 6 Nov', no timezone shifts
        if (!d) return '';
        const [y, m, dd] = d.split('-').map(Number), dt = new Date(Date.UTC(y, m - 1, dd));
        return dt.toLocaleDateString('en-US', { weekday: 'short', day: 'numeric', month: 'short', timeZone: 'UTC' });
    }
    const dot = s => '<span class="dot ' + esc(s) + '" title="' + esc(s || 'not checked') + '"></span>';

    const TABS = [['all', 'All', () => true], ['ready', 'Ready', r => label(r) === 'Ready'],
                  ['scheduled', 'Scheduled', r => label(r) === 'Scheduled'], ['live', 'Live', r => r.status === 'live'],
                  ['notready', 'Not ready yet', r => !['Ready', 'Scheduled', 'Live'].includes(label(r))]];

    function render() {
        $('ro-tabs').innerHTML = TABS.map(([k, t, f]) =>
            '<button type="button" class="ro-tab' + (view === k ? ' on' : '') + '" data-view="' + k + '">' + t + ' (' + rows.filter(f).length + ')</button>').join('');
        const f = TABS.find(t => t[0] === view)[2];
        const shown = rows.filter(f);
        $('ro-body').innerHTML = shown.length ? shown.map(r => {
            const lab = label(r), tick = canTick(r);
            return '<tr>'
                + '<td>' + (tick ? '<input type="checkbox" data-d="' + esc(r.domain) + '"' + (sel.has(r.domain) ? ' checked' : '') + '>' : '') + '</td>'
                + '<td><strong>' + esc(r.domain) + '</strong></td><td>' + esc(r.city) + '</td>'
                + '<td>' + (r.box ? esc(r.box) : '<span class="muted">—</span>') + '</td>'
                + '<td>' + (r.cf_account ? esc(r.cf_account) : '<span class="muted">—</span>') + '</td>'
                + '<td>' + dot(r.steps.built) + '</td><td>' + dot(r.steps.upload) + '</td><td>' + dot(r.steps.zone) + '</td>'
                + '<td>' + (r.go_live_at && r.status !== 'live' ? esc(niceDate(r.go_live_at)) + (r.go_live_time ? ' ' + esc(r.go_live_time) : '') : '<span class="muted">—</span>') + '</td>'
                + '<td>' + dot(r.steps.live) + '</td>'
                + '<td><span class="pill ' + esc(lab) + '">' + esc(lab) + '</span></td></tr>';
        }).join('') : '<tr><td colspan="11" class="muted">Nothing here.</td></tr>';
        $('ro-count').textContent = sel.size;

        // Next 14 days, from what is scheduled in this batch.
        const per = {};
        rows.forEach(r => { if (r.status === 'queued' && r.go_live_at) per[r.go_live_at] = (per[r.go_live_at] || 0) + 1; });
        const out = []; const [y, m, d] = (window.RO_TODAY || '').split('-').map(Number);
        for (let i = 0; i < 14 && y; i++) {
            const dt = new Date(Date.UTC(y, m - 1, d + i)), key = dt.toISOString().slice(0, 10), n = per[key] || 0;
            out.push('<div class="' + (n ? 'has' : '') + '">' + esc(niceDate(key)) + '<br>' + (n ? n + ' going live' : '—') + '</div>');
        }
        $('ro-cal').innerHTML = out.join('');
    }

    function renderSettings() {
        $('ro-settings').innerHTML = 'Go-live time <strong>' + esc(settings.golive_time) + '</strong> (US Central) &middot; '
            + 'max <strong>' + esc(settings.cf_max_per_account) + '</strong> domains per Cloudflare account &middot; '
            + '<a href="#" id="ro-edit">change</a>';
        $('ro-edit').onclick = async e => {
            e.preventDefault();
            const t = prompt('Go-live time, 24-hour HH:MM (US Central):', settings.golive_time); if (t === null) return;
            const n = prompt('Max domains per Cloudflare account:', settings.cf_max_per_account); if (n === null) return;
            const r = await post({ action: 'rollout_settings', golive_time: t.trim(), cf_max_per_account: n.trim() });
            if (r.settings) { settings = r.settings; renderSettings(); }
        };
    }

    async function load() {
        const r = await (await fetch(API + '?action=rollout_rows')).json();
        if (r.error) { $('ro-body').innerHTML = '<tr><td colspan="11">' + esc(r.error) + '</td></tr>'; return; }
        rows = r.rows; settings = r.settings; window.RO_TODAY = r.today;
        if (!$('ro-start').value) { $('ro-start').value = r.tomorrow; $('ro-start').min = r.today; }
        [...sel].forEach(d => { const x = rows.find(y => y.domain === d); if (!x || !canTick(x)) sel.delete(d); });
        renderSettings(); render();
    }
    async function post(data) {
        const fd = new FormData(); fd.append('csrf_token', CSRF);
        Object.entries(data).forEach(([k, v]) => fd.append(k, v));
        return (await fetch(API, { method: 'POST', body: fd })).json();
    }
    function say(text, ok) { $('ro-msg').textContent = text; $('ro-msg').style.color = ok ? '#15803d' : '#b91c1c'; }
    const why = w => (w && w.length) ? ' Skipped ' + w.length + ': ' + w.slice(0, 3).map(x => x[0] + ' (' + x[1] + ')').join(', ') + (w.length > 3 ? '…' : '') : '';

    document.addEventListener('click', e => {
        const chip = e.target.closest('.ro-chip');
        if (chip) { perDay = +chip.dataset.n; document.querySelectorAll('.ro-chip').forEach(c => c.classList.toggle('on', c === chip)); return; }
        const tab = e.target.closest('[data-view]');
        if (tab) { view = tab.dataset.view; render(); return; }
        const s = e.target.closest('[data-sel]');
        if (s) {
            e.preventDefault();
            if (s.dataset.sel === 'none') sel.clear();
            else rows.filter(r => label(r) === (s.dataset.sel === 'ready' ? 'Ready' : 'Scheduled')).forEach(r => sel.add(r.domain));
            render();
        }
    });
    document.addEventListener('change', e => {
        if (e.target.matches('#ro-body input[data-d]')) { e.target.checked ? sel.add(e.target.dataset.d) : sel.delete(e.target.dataset.d); $('ro-count').textContent = sel.size; }
        if (e.target.id === 'ro-all') {
            const f = TABS.find(t => t[0] === view)[2];
            rows.filter(f).filter(canTick).forEach(r => e.target.checked ? sel.add(r.domain) : sel.delete(r.domain));
            render();
        }
    });
    $('ro-sched').onclick = async () => {
        if (!sel.size) return say('Tick some domains first.');
        const start = $('ro-start').value;
        if (!confirm('Schedule ' + sel.size + ' domain(s): ' + perDay + ' per day from ' + niceDate(start) + ' at ' + settings.golive_time + '?')) return;
        const r = await post({ action: 'rollout_schedule', domains: JSON.stringify([...sel]), per_day: perDay, start });
        if (r.error) return say(r.error);
        say('Scheduled ' + r.scheduled + (r.first ? ' (' + niceDate(r.first) + (r.last !== r.first ? ' – ' + niceDate(r.last) : '') + ')' : '') + '.' + why(r.why), r.scheduled > 0);
        sel.clear(); load();
    };
    $('ro-unsched').onclick = async () => {
        if (!sel.size) return say('Tick some domains first.');
        if (!confirm('Take ' + sel.size + ' domain(s) off the go-live calendar?')) return;
        const r = await post({ action: 'rollout_unschedule', domains: JSON.stringify([...sel]) });
        if (r.error) return say(r.error);
        say('Unscheduled ' + r.unscheduled + '.' + why(r.why), r.unscheduled > 0);
        sel.clear(); load();
    };
    load();
})();
</script>
</body>
</html>
