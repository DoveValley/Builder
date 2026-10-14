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
table.ro th.ro-vert{writing-mode:vertical-rl;transform:rotate(180deg);white-space:nowrap;height:90px;padding:6px 4px;text-align:left}
table.ro td{border-bottom:1px solid #f1f5f9;padding:6px 7px;vertical-align:middle}
table.ro tr:hover td{background:#f8fafc}
table.ro input[type="checkbox"]{width:20px;height:20px;cursor:pointer}
.dot{display:inline-block;width:11px;height:11px;border-radius:50%;background:#e2e8f0}
.dot.ok{background:#16a34a}.dot.running{background:#f59e0b}.dot.fail{background:#dc2626}.dot.todo{background:#cbd5e1}
.pill{font-size:.75rem;padding:2px 8px;border-radius:999px;background:#f1f5f9;color:#475569;white-space:nowrap}
.pill.Live{background:#dcfce7;color:#166534}.pill.Scheduled{background:#dbeafe;color:#1e3a8a}.pill.Ready{background:#fef9c3;color:#854d0e}
a.pill.ro-confirm{cursor:pointer;text-decoration:underline;text-decoration-style:dotted}
.ro-msg{font-weight:600}
.muted{color:#94a3b8}
.ro-info{color:#94a3b8;text-decoration:none;font-size:.9rem}
.ro-info:hover{color:#1e3a8a}
.ro-modal-bg{display:none;position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:50;align-items:center;justify-content:center}
.ro-modal{background:#fff;border-radius:8px;padding:16px 18px;max-width:480px;width:90%;max-height:80vh;overflow:auto;box-shadow:0 10px 30px rgba(0,0,0,.2)}
.ro-modal h3{margin:0 0 10px;font-size:1rem}
.ro-modal .ro-step{padding:7px 0;border-bottom:1px solid #f1f5f9;font-size:.85rem}
.ro-modal .ro-step:last-child{border-bottom:none}
.ro-modal .ro-step-note{color:#64748b;font-size:.8rem;margin-top:2px}
.ro-modal .ro-close{float:right;cursor:pointer;color:#94a3b8;font-size:1.1rem;line-height:1}
.ro-modal .ro-close:hover{color:#1e293b}
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
        <label><input type="checkbox" id="ro-do-host" checked> Create host</label>
        <label><input type="checkbox" id="ro-do-build" checked> Build</label>
        <label><input type="checkbox" id="ro-do-upload" checked> Upload</label>
        <label><input type="checkbox" id="ro-force-photos"> Force-regenerate photos <a href="#" class="ro-info" data-static-info="force_photos" title="Details">&#9432;</a></label>
        <label><input type="checkbox" id="ro-logo-only"> Colors, fonts &amp; logo only <a href="#" class="ro-info" data-static-info="logo_only" title="Details">&#9432;</a></label>
        <button type="button" class="btn" id="ro-build">Run selected</button>
        <span class="hint">Runs only the ticked steps, in order, for the ticked domains below &mdash; leave all three checked for the old one-click behavior. Unchecking Build skips the AI generation cost when you only need to redo Upload (e.g. after fixing FTP credentials). Force-regenerate photos and Colors, fonts &amp; logo only both only do anything while Build is ticked &mdash; see the &#9432; next to each for what ticking both together does.</span>
    </div>
    <div id="ro-job-stage" style="display:none;font-weight:600;margin:0 0 6px;"></div>
    <pre id="ro-job" style="display:none;margin:0 0 12px;background:#0f172a;color:#e2e8f0;padding:10px 12px;border-radius:6px;font-size:.76rem;max-height:220px;overflow:auto;white-space:pre-wrap;"></pre>

    <div class="ro-bar">
        <label>Cloudflare account
            <select id="ro-cf-slot">
                <option value="0">Auto (first open)</option>
                <option value="1">1st</option>
                <option value="2">2nd</option>
                <option value="3">3rd</option>
                <option value="4">4th</option>
            </select>
        </label>
        <button type="button" class="btn sec" id="ro-cf-set">Set for selected</button>
        <button type="button" class="btn" id="ro-cf-zone">Create zone(s) for selected</button>
        <span class="hint">Each domain uses whichever account is in that position on <em>its own</em> box &mdash; a batch can span several boxes. No account in that slot clears the pick back to Auto. Domains with a zone already created are skipped; Cloudflare can't move a zone to a different account.</span>
    </div>

    <div class="ro-bar">
        <span><strong>Go live</strong> per day:
            <button type="button" class="ro-chip" data-n="3">3</button>
            <button type="button" class="ro-chip" data-n="4">4</button>
            <button type="button" class="ro-chip on" data-n="5">5</button>
            <button type="button" class="ro-chip" data-n="6">6</button>
        </span>
        <label>starting <input type="date" id="ro-start"></label>
        <label>starting at <input type="time" id="ro-start-time"></label>
        <label>every <input type="number" id="ro-interval" min="1" step="1" style="width:56px"> min</label>
        <button type="button" class="btn" id="ro-sched">Schedule selected</button>
        <button type="button" class="btn sec" id="ro-unsched">Unschedule selected</button>
        <span class="ro-msg" id="ro-msg"></span>
    </div>

    <div class="ro-bar">
        <button type="button" class="btn btn-danger" id="ro-golive-now">Go live now for selected</button>
        <span class="hint">Switches nameservers at the registrar immediately &mdash; the real public cutover, not a schedule. Same checks as a scheduled release: refuses anything not uploaded, or already live.</span>
    </div>

    <div class="ro-cal" id="ro-cal"></div>

    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
        <div class="ro-tabs" id="ro-tabs"></div>
        <div class="hint">Select:
            <a href="#" data-sel="ready">all ready</a> &middot;
            <a href="#" data-sel="scheduled">all scheduled</a> &middot;
            <a href="#" data-sel="notup">all not uploaded</a> &middot;
            <a href="#" data-sel="none">none</a>
            &nbsp; <strong id="ro-count">0</strong> selected
        </div>
    </div>

    <table class="ro">
        <thead><tr>
            <th style="width:26px;"><input type="checkbox" id="ro-all" title="Select every row shown that can be scheduled"></th>
            <th>Domain</th><th>City</th>
            <th class="ro-vert" title="Phone number on file — see the info popup for the number">Phone</th>
            <th class="ro-vert" title="Business name on file — see the info popup for the name">Business</th>
            <th class="ro-vert">Box</th><th class="ro-vert">Cloudflare</th>
            <th class="ro-vert" title="City research done — see the info popup for the date">Research</th>
            <th class="ro-vert" title="Box + FTP account created">Host</th>
            <th class="ro-vert" title="Files generated">Built</th>
            <th class="ro-vert" title="Files on the server">Uploaded</th>
            <th class="ro-vert" title="Cloudflare zone">Zone</th>
            <th>Go live</th>
            <th class="ro-vert" title="Answers on its own address with a valid certificate">Live</th>
            <th>Status</th>
        </tr></thead>
        <tbody id="ro-body"><tr><td colspan="15" class="muted">Loading&hellip;</td></tr></tbody>
    </table>
    <p class="hint" style="margin-top:12px;">Dots: <span class="dot ok"></span> done &nbsp;<span class="dot running"></span> running &nbsp;<span class="dot fail"></span> failed &nbsp;<span class="dot todo"></span> checked, not yet &nbsp;<span class="dot"></span> not checked.
        Only uploaded domains can be scheduled. Releases happen automatically at the go-live time (US Central) on each date.</p>
</div>

<div class="ro-modal-bg" id="ro-modal-bg">
    <div class="ro-modal">
        <span class="ro-close" id="ro-modal-close">&times;</span>
        <h3 id="ro-modal-title"></h3>
        <div id="ro-modal-body"></div>
    </div>
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
    const canTick = r => true;   // the core decides what each action may touch, and says why
    function niceDate(d) {                       // 'YYYY-MM-DD' → 'Thu 6 Nov', no timezone shifts
        if (!d) return '';
        const [y, m, dd] = d.split('-').map(Number), dt = new Date(Date.UTC(y, m - 1, dd));
        return dt.toLocaleDateString('en-US', { weekday: 'short', day: 'numeric', month: 'short', timeZone: 'UTC' });
    }
    function niceTime(t) {                        // 'HH:MM' (24h) → 'H:MM AM/PM'
        if (!t || !/^\d{2}:\d{2}$/.test(t)) return t || '';
        let [h, m] = t.split(':').map(Number);
        const ampm = h >= 12 ? 'PM' : 'AM';
        h = h % 12 || 12;
        return h + ':' + String(m).padStart(2, '0') + ' ' + ampm;
    }
    const dot = s => '<span class="dot ' + esc(s) + '" title="' + esc(s || 'not checked') + '"></span>';

    const STEP_LABELS = { host: 'Host', built: 'Built', upload: 'Uploaded', zone: 'Zone', live: 'Live' };
    // The stored timestamp is "last checked", not "last succeeded" — the DB column is
    // literally called checked_at (pipeline.php's infra_pipeline_set() stamps it on
    // every check, pass or fail). Wording it as "checked" rather than bare "— <time>"
    // stops a failed/not-yet step from reading like it finished at that time.
    const STATE_WORDS = { ok: 'done', fail: 'failed', running: 'running', todo: 'not yet', '': 'not checked' };
    // Shown on BOTH popups (whichever one is opened first) so the combination is never a
    // surprise — plain-English, not a repeat of the technical description above it.
    const COMBO_NOTE = '<div class="ro-step" style="margin-top:10px;padding-top:10px;border-top:1px solid #e2e8f0;">'
        + '<strong>What each combination actually does:</strong><br><br>'
        + '&bull; <strong>Tick nothing extra:</strong> a normal, full rebuild — new text, new photos if needed, new pages. The usual thing.<br><br>'
        + '&bull; <strong>Force-regenerate photos, alone:</strong> same full rebuild as above, plus every photo gets replaced with a fresh one, even the ones that were already fine.<br><br>'
        + '&bull; <strong>Colors, fonts &amp; logo only, alone:</strong> the fast, cheap option — touches only colors/fonts/logo/icon, nothing else.<br><br>'
        + '&bull; <strong>Both ticked together:</strong> updates the colors/logo AND gets fresh photos, but still skips the written content and pages — real OpenAI cost for the photos, no cost for the colors/logo part.'
        + '</div>';
    const STATIC_INFO = {
        force_photos: ['Force-regenerate photos',
            '<div class="ro-step">Regenerates every AI photo slot on each ticked domain from the <strong>master\'s own approved photo</strong> — not from whatever is currently in that slot.</div>'
          + '<div class="ro-step">Normally a photo slot that already has any image is left alone, even if it\'s wrong — this is the one way to force past that and actually fix it.</div>'
          + '<div class="ro-step"><strong>Real OpenAI cost per photo slot, per domain.</strong> Only does anything while Build is also ticked. Use it when a photo is actually wrong, not as a routine option.</div>'
          + COMBO_NOTE],
        logo_only: ['Colors, fonts &amp; logo only',
            '<div class="ro-step">Rebuilds <strong>only</strong> the visual identity for each ticked domain — color palette, font, palette jitter, logo, icon, and favicon — skips AI text, photos, page pool, and landing pages entirely.</div>'
          + '<div class="ro-step">Colors and the logo are bundled on purpose, not separable: the logo is painted FROM the current theme colors, so regenerating one without the other would leave the logo showing the old colors against a new theme.</div>'
          + '<div class="ro-step">Local rendering only (no AI/OpenAI call), so there\'s no real cost — safe to run as often as you like.</div>'
          + '<div class="ro-step">Does <strong>not</strong> change this batch\'s saved Generate settings — it\'s a one-off override just for this click. Use it after editing a Theme Preset, Logo Config, or icon in Brand Design to push that change out without a full rebuild.</div>'
          + COMBO_NOTE],
    };
    function showStaticInfo(key) {
        const [title, body] = STATIC_INFO[key] || ['', ''];
        $('ro-modal-title').textContent = title;
        $('ro-modal-body').innerHTML = body;
        $('ro-modal-bg').style.display = 'flex';
    }
    async function showInfo(domain) {
        const r = rows.find(x => x.domain === domain);
        if (!r || !r.detail) return;
        $('ro-modal-title').textContent = domain;
        const fields = '<div class="ro-step"><strong>Row:</strong> #' + esc(r.n) + ' in this batch &mdash; CSV line ' + esc(r.line) + '</div>'
                     + '<div class="ro-step">' + dot(r.phone ? 'ok' : '') + ' <strong>Phone:</strong> ' + esc(r.phone || 'none on file') + '</div>'
                     + '<div class="ro-step">' + dot(r.other ? 'ok' : '') + ' <strong>Business:</strong> ' + esc(r.other || 'none on file') + '</div>'
                     + '<div class="ro-step">' + dot(r.steps.research) + ' <strong>Research:</strong> '
                     + (r.steps.research === 'ok' ? 'done' : 'not yet') + ' &mdash; ' + esc(r.research_at || 'no date on file') + '</div>';
        const steps = Object.entries(STEP_LABELS).map(([k, lbl]) => {
            const d = r.detail[k] || {};
            const state = d.state || '';
            const when = d.at ? 'checked ' + new Date(d.at * 1000).toLocaleString() : 'never checked';
            return '<div class="ro-step">' + dot(state) + ' <strong>' + lbl + ':</strong> '
                 + esc(STATE_WORDS[state] ?? state) + ' &mdash; ' + esc(when)
                 + (d.note ? '<div class="ro-step-note">' + esc(d.note) + '</div>' : '') + '</div>';
        }).join('');
        // Build cost isn't in `rows` (it would mean scanning every run.json for every
        // row on every page load) — fetched here, just for the one domain being opened.
        const costLine = '<div class="ro-step" id="ro-cost-line"><strong>Last build cost:</strong> Loading&hellip;</div>';
        $('ro-modal-body').innerHTML = fields + steps + costLine;
        $('ro-modal-bg').style.display = 'flex';
        const cr = await (await fetch(API + '?action=rollout_last_cost&domain=' + encodeURIComponent(domain))).json();
        const line = $('ro-cost-line');
        if (line) {
            line.innerHTML = cr.cost != null
                ? '<strong>Last build cost:</strong> $' + cr.cost.toFixed(4) + (cr.at ? ' &mdash; ' + esc(new Date(cr.at).toLocaleString()) : '')
                : '<strong>Last build cost:</strong> no build on record';
        }
    }

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
                + '<td><span style="color:#0f172a" title="CSV line ' + esc(r.line) + ' in this batch\'s target list">' + esc(r.n) + '.</span> <strong>' + esc(r.domain) + '</strong> <a href="#" class="ro-info" data-info="' + esc(r.domain) + '" title="Details">&#9432;</a></td><td>' + esc(r.city) + '</td>'
                + '<td>' + dot(r.phone ? 'ok' : '') + '</td>'
                + '<td>' + dot(r.other ? 'ok' : '') + '</td>'
                + '<td>' + (r.box ? esc(r.box) : '<span class="muted">—</span>') + '</td>'
                + '<td>' + (r.cf_account ? esc(r.cf_account) : '<span class="muted">—</span>') + '</td>'
                + '<td>' + dot(r.steps.research) + '</td>'
                + '<td>' + dot(r.steps.host) + '</td>'
                + '<td>' + dot(r.steps.built) + '</td><td>' + dot(r.steps.upload) + '</td><td>' + dot(r.steps.zone) + '</td>'
                + '<td>' + (r.go_live_at && r.status !== 'live' ? esc(niceDate(r.go_live_at)) + (r.go_live_time ? ' ' + esc(niceTime(r.go_live_time)) : '') : '<span class="muted">—</span>') + '</td>'
                + '<td>' + (r.status === 'live'
                    ? '<a href="https://' + esc(r.domain) + '" target="_blank" rel="noopener" title="Open the live site">' + dot(r.steps.live) + '</a>'
                    : dot(r.steps.live)) + '</td>'
                + '<td>' + (lab === 'Switching DNS'
                    ? '<a href="#" class="ro-confirm pill ' + esc(lab) + '" data-confirm="' + esc(r.domain) + '" title="Check now instead of waiting for the hourly cron">' + esc(lab) + '</a>'
                    : '<span class="pill ' + esc(lab) + '">' + esc(lab) + '</span>') + '</td></tr>';
        }).join('') : '<tr><td colspan="15" class="muted">Nothing here.</td></tr>';
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
        if (r.error) { $('ro-body').innerHTML = '<tr><td colspan="15">' + esc(r.error) + '</td></tr>'; return; }
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
        const info = e.target.closest('.ro-info');
        if (info) { e.preventDefault(); info.dataset.staticInfo ? showStaticInfo(info.dataset.staticInfo) : showInfo(info.dataset.info); return; }
        const confirmLink = e.target.closest('.ro-confirm');
        if (confirmLink) {
            e.preventDefault();
            const dom = confirmLink.dataset.confirm;
            const was = confirmLink.textContent;
            confirmLink.textContent = 'Checking…';
            confirmLink.style.pointerEvents = 'none';
            post({ action: 'rollout_confirm_live', domain: dom }).then(r => {
                if (r.error) { say(r.error); confirmLink.textContent = was; confirmLink.style.pointerEvents = ''; return; }
                say(r.confirmed ? dom + ' is now Live.' : dom + ' not confirmed yet — DNS/Cloudflare still catching up, try again shortly.', !!r.confirmed);
                load();
            });
            return;
        }
        if (e.target.id === 'ro-modal-close' || e.target.id === 'ro-modal-bg') { $('ro-modal-bg').style.display = 'none'; return; }
        const chip = e.target.closest('.ro-chip');
        if (chip) { perDay = +chip.dataset.n; document.querySelectorAll('.ro-chip').forEach(c => c.classList.toggle('on', c === chip)); return; }
        const tab = e.target.closest('[data-view]');
        if (tab) { view = tab.dataset.view; render(); return; }
        const s = e.target.closest('[data-sel]');
        if (s) {
            e.preventDefault();
            if (s.dataset.sel === 'none') sel.clear();
            else rows.filter(r => s.dataset.sel === 'notup' ? (r.steps.upload !== 'ok' && r.status !== 'live')
                                  : label(r) === (s.dataset.sel === 'ready' ? 'Ready' : 'Scheduled')).forEach(r => sel.add(r.domain));
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
        if (e.target.id === 'ro-do-build') syncForcePhotos();
    });
    // Force-regenerate photos and Colors, fonts & logo only are both meaningless without
    // Build — greyed out and auto-unticked the moment Build is off, not just blocked at
    // submit time, so it's never possible to leave either checked in a state where it
    // would silently do nothing. Tickable together on purpose — see rollout_build's
    // server-side handling of logo_only+force_photos for what that combination means.
    function syncForcePhotos() {
        const buildOn = $('ro-do-build').checked;
        const fp = $('ro-force-photos');
        const lo = $('ro-logo-only');
        fp.disabled = !buildOn;
        lo.disabled = !buildOn;
        if (!buildOn) { fp.checked = false; lo.checked = false; }
    }
    syncForcePhotos();
    $('ro-sched').onclick = async () => {
        if (!sel.size) return say('Tick some domains first.');
        // A native time input reports an empty .value both when it's genuinely untouched
        // AND when it's partially filled (hour/minute set but AM/PM never picked) — the
        // two look identical to this code, but only validity.badInput catches the second
        // case. Without this, a half-filled "05:00 --" silently sends nothing and the
        // schedule falls back to the flat default time with zero indication anything was
        // wrong — confirmed as the actual cause of a real scheduling mixup.
        if ($('ro-start-time').validity.badInput) {
            return say('The "starting at" time isn\'t fully set — pick AM or PM too, or clear the field entirely for the default time.');
        }
        const start = $('ro-start').value, startTime = $('ro-start-time').value, interval = $('ro-interval').value || 0;
        const when = startTime ? 'starting at ' + startTime + ', ' + interval + ' min apart' : 'at ' + settings.golive_time;
        if (!confirm('Schedule ' + sel.size + ' domain(s): ' + perDay + ' per day from ' + niceDate(start) + ' ' + when + '?')) return;
        const r = await post({ action: 'rollout_schedule', domains: JSON.stringify([...sel]), per_day: perDay, start, start_time: startTime, interval });
        if (r.error) return say(r.error);
        say('Scheduled ' + r.scheduled + (r.first ? ' (' + niceDate(r.first) + (r.last !== r.first ? ' – ' + niceDate(r.last) : '') + ')' : '') + '.' + why(r.why), r.scheduled > 0);
        sel.clear(); load();
    };
    $('ro-cf-set').onclick = async () => {
        if (!sel.size) return say('Tick some domains first.');
        const slot = $('ro-cf-slot').value, label = $('ro-cf-slot').selectedOptions[0].textContent;
        if (!confirm('Set the Cloudflare account to "' + label + '" for ' + sel.size + ' domain(s)?')) return;
        const r = await post({ action: 'rollout_set_cf_slot', domains: JSON.stringify([...sel]), slot });
        if (r.error) return say(r.error);
        say('Set ' + r.set + (r.cleared ? ', cleared ' + r.cleared : '') + '.' + why(r.why), r.set + r.cleared > 0);
        load();
    };
    $('ro-cf-zone').onclick = async () => {
        if (!sel.size) return say('Tick some domains first.');
        if (!confirm('Create the Cloudflare zone for ' + sel.size + ' domain(s) now?')) return;
        const r = await post({ action: 'rollout_create_zones', domains: JSON.stringify([...sel]) });
        if (r.error) return say(r.error);
        say('Zone created for ' + r.ok + '.' + why(r.why), r.ok > 0);
        load();
    };
    $('ro-unsched').onclick = async () => {
        if (!sel.size) return say('Tick some domains first.');
        if (!confirm('Take ' + sel.size + ' domain(s) off the go-live calendar?')) return;
        const r = await post({ action: 'rollout_unschedule', domains: JSON.stringify([...sel]) });
        if (r.error) return say(r.error);
        say('Unscheduled ' + r.unscheduled + '.' + why(r.why), r.unscheduled > 0);
        sel.clear(); load();
    };
    $('ro-golive-now').onclick = async () => {
        if (!sel.size) return say('Tick some domains first.');
        // Always the full list, never a bare count — this is the one button on this
        // page that touches the outside world and can't be undone with another click
        // the way Unschedule undoes Schedule, so a misclick here costs real propagation
        // time, not just a re-run.
        if (!confirm('Go LIVE right now for ' + sel.size + ' domain(s)? This switches nameservers at the registrar immediately — not reversible with a click.\n\n' + [...sel].join('\n'))) return;
        const r = await post({ action: 'rollout_golive_now', domains: JSON.stringify([...sel]) });
        if (r.error) return say(r.error);
        // 'confirmed' is often 0 right after release — DNS propagation and Cloudflare
        // noticing it both take real time, so that's normal, not a failure. Status will
        // keep showing "Switching DNS" for those until a later check catches up.
        let msg = 'Released ' + r.released + '.';
        if (r.released > 0) msg += r.confirmed > 0 ? ' Confirmed live: ' + r.confirmed + '.' : ' Not confirmed live yet — DNS is still propagating, this is normal.';
        say(msg + why(r.why), r.released > 0);
        sel.clear(); load();
    };
    // Run selected (Create host / Build / Upload, any mix) — one background job; the box
    // below follows its log until it ends.
    let jobTimer = null;
    function stepsPicked() {
        return { host: $('ro-do-host').checked, build: $('ro-do-build').checked, upload: $('ro-do-upload').checked,
                 forcePhotos: $('ro-force-photos').checked, logoOnly: $('ro-logo-only').checked };
    }
    function stepsLabel(p) {
        const names = [p.host && 'Create host', p.build && 'Build', p.upload && 'Upload'].filter(Boolean);
        if (names.length === 3) return 'Create host, Build & Upload';
        return names.join(' & ') || 'nothing';
    }
    async function watchJob(runId) {
        const r = await (await fetch(API + '?action=rollout_build_status' + (runId ? '&run_id=' + encodeURIComponent(runId) : ''))).json();
        if (r.none) return;
        $('ro-job').style.display = ''; $('ro-job').textContent = r.log || 'Starting…';
        $('ro-job').scrollTop = $('ro-job').scrollHeight;
        const stageEl = $('ro-job-stage');
        stageEl.style.display = '';
        if (!r.done) {
            $('ro-build').disabled = true;
            stageEl.style.color = '#2563eb';
            let stageTxt = r.stage ? 'Running — step ' + r.stage + ' of ' + r.stage_total + ': ' + r.stage_label : 'Starting';
            if (r.detail) stageTxt += ' — ' + r.detail;
            stageEl.textContent = stageTxt + '…';
            jobTimer = setTimeout(() => watchJob(r.run_id), 3000);
            return;
        }
        $('ro-build').disabled = false;
        stageEl.style.color = r.exit === 0 ? '#166534' : '#991b1b';
        stageEl.textContent = r.exit === 0 ? 'Done — finished.' : 'Done, with problems — see the log below (steps you didn\'t tick are marked "skipped (not selected)", not a failure).';
        if (runId) { say(r.exit === 0 ? 'Finished.' : 'Finished with problems — see the log.', r.exit === 0); load(); }
    }
    $('ro-build').onclick = async () => {
        if (!sel.size) return say('Tick some domains first.');
        const picked = stepsPicked();
        if (!picked.host && !picked.build && !picked.upload) return say('Tick at least one step — Create host, Build, or Upload.');
        const live = [...sel].filter(d => (rows.find(x => x.domain === d) || {}).status === 'live').length;
        const what = stepsLabel(picked);
        // Named explicitly, not just counted — a bare "1 domain(s)?" gives no chance to
        // catch a one-row-off misclick (e.g. ticking the row right below the intended
        // one) before the job launches. Past 8 the list is its own kind of unreadable,
        // so it falls back to a count there instead.
        let msg = sel.size <= 8 ? what + ' now?\n\n' + [...sel].join('\n') : what + ' for ' + sel.size + ' domain(s) now?';
        if (picked.build && live) msg += '\n\n' + live + ' of them are LIVE — their sites will be replaced with the new build.';
        if ((picked.forcePhotos || picked.logoOnly) && !picked.build) {
            return say((picked.forcePhotos ? 'Force-regenerate photos' : 'Colors, fonts & logo only') + ' does nothing unless Build is also ticked.');
        }
        if (picked.forcePhotos && picked.logoOnly) {
            msg += '\n\nFORCE-REGENERATE PHOTOS + COLORS, FONTS & LOGO ONLY are both ticked — this run skips AI text, '
                 + 'page pool, and landing pages for ' + sel.size + ' domain(s), but rebuilds the color palette, font, '
                 + 'jitter, logo, icon, favicon, AND regenerates every AI photo slot from the master\'s own photo. '
                 + 'Real OpenAI cost per photo slot. Continue?';
        } else if (picked.forcePhotos) {
            msg += '\n\nFORCE-REGENERATE PHOTOS is ticked — every AI photo slot on ' + sel.size
                 + ' domain(s) will be regenerated from the master\'s own photo, real OpenAI cost per slot. Continue?';
        } else if (picked.logoOnly) {
            msg += '\n\nCOLORS, FONTS & LOGO ONLY is ticked — this run skips AI text, photos, page pool, and landing pages for '
                 + sel.size + ' domain(s), rebuilding just the color palette, font, jitter, logo, icon, and favicon from current settings. No cost (local only). Continue?';
        }
        if (!confirm(msg)) return;
        const r = await post({ action: 'rollout_build', domains: JSON.stringify([...sel]),
            do_host: picked.host ? '1' : '0', do_build: picked.build ? '1' : '0', do_upload: picked.upload ? '1' : '0',
            force_photos: picked.forcePhotos ? '1' : '0', logo_only: picked.logoOnly ? '1' : '0' });
        if (r.error) return say(r.error);
        say(what + ' started for ' + sel.size + ' domain(s).', true);
        sel.clear(); render(); clearTimeout(jobTimer); watchJob(r.run_id);
    };
    load();
    watchJob('');   // a job already running (page reloaded mid-run) shows its log
})();
</script>
</body>
</html>
