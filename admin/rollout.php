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
require_once __DIR__ . '/../includes/multisite/content_check.php';

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
/* The two times worth finding in this popup — when it was built, and when it was
   actually released — lifted out of the grey note colour. */
.ro-modal .ro-when{color:#166534;font-weight:600}
/* The counterpart: a thing that was supposed to happen and didn't. */
.ro-modal .ro-bad{color:#b91c1c;font-weight:600}
/* Only ever rendered when something actually went wrong, so it is never background
   noise the eye learns to skip — see the freshen alert in render(). */
#ro-freshen-alert{display:none;margin:0 0 10px;padding:8px 10px;border:1px solid #fcd34d;
  background:#fffbeb;color:#92400e;border-radius:6px;font-size:.85rem}
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

    <details id="ro-cc-details" style="margin:0 0 14px;border:1px solid #e2e8f0;border-radius:6px;background:#f8fafc;">
        <summary style="cursor:pointer;padding:8px 12px;font-weight:600;font-size:.92rem;list-style:revert;">Site Final Check</summary>
        <div style="padding:4px 12px 14px;">
            <div class="ro-bar" style="justify-content:space-between;flex-wrap:wrap;">
                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                    <a href="#" class="ro-info" id="ro-cc-info" title="What this checks">&#9432;</a>
                    <select id="ro-cc-mode" style="font-size:.82rem;padding:3px 6px;border-radius:4px;border:1px solid #cbd5e1;">
                        <option value="template">This master's template</option>
                        <option value="built">First built domain</option>
                        <option value="live">First live domain</option>
                    </select>
                    <span class="hint" id="ro-cc-hint">Checks this master's own template pages — not tied to the domains ticked below.</span>
                </div>
                <button type="button" class="btn sec" id="ro-cc-run">Run Final Check</button>
            </div>
            <div id="ro-cc-report" style="margin:0;"></div>
        </div>
    </details>

    <p class="hint" id="ro-settings" style="margin:0 0 14px;">Loading&hellip;</p>

    <div class="ro-bar">
        <label><input type="checkbox" id="ro-do-host" checked> Create host</label>
        <label><input type="checkbox" id="ro-do-build" checked> Build</label>
        <label><input type="checkbox" id="ro-do-upload" checked> Upload</label>
        <label><input type="checkbox" id="ro-force-photos"> Force-regenerate photos <a href="#" class="ro-info" data-static-info="force_photos" title="Details">&#9432;</a></label>
        <label><input type="checkbox" id="ro-skip-ai-photos"> Skip AI photos this run <a href="#" class="ro-info" data-static-info="skip_ai_photos" title="Details">&#9432;</a></label>
        <label>Force photo quality:
            <select id="ro-image-quality" style="font-size:.82rem;padding:3px 6px;border-radius:4px;border:1px solid #cbd5e1;">
                <option value="medium" selected>Medium (current, ~$0.04&ndash;0.05/photo)</option>
                <option value="low">Low (cheaper, ~$0.01/photo)</option>
            </select>
            <a href="#" class="ro-info" data-static-info="image_quality" title="Details">&#9432;</a>
        </label>
        <label><input type="checkbox" id="ro-logo-only"> Colors, fonts, logo &amp; favicon only <a href="#" class="ro-info" data-static-info="logo_only" title="Details">&#9432;</a></label>
        <label><input type="checkbox" id="ro-wipe"> Wipe server files first <a href="#" class="ro-info" data-static-info="wipe_files" title="Details">&#9432;</a></label>
        <button type="button" class="btn" id="ro-build">Run selected</button>
        <a href="#" class="ro-info" data-static-info="file_dates" title="What this does to file dates">&#9432;</a>
        <span class="hint">Runs only the ticked steps, in order, for the ticked domains below &mdash; leave all three checked for the old one-click behavior. Unchecking Build skips the AI generation cost when you only need to redo Upload (e.g. after fixing FTP credentials). Force-regenerate photos and Colors, fonts, logo &amp; favicon only both only do anything while Build is ticked &mdash; see the &#9432; next to each for what ticking both together does. Skip AI photos this run is the opposite of Force-regenerate photos &mdash; the two can't be ticked together. Force photo quality applies to any photo generated during Build, forced or new &mdash; see its &#9432;. Wipe server files first needs a full Build AND Upload (not Colors/fonts/logo/favicon only) and is capped at 5 domains per run.</span>
    </div>
    <div id="ro-job-stage" style="display:none;font-weight:600;margin:0 0 6px;"></div>
    <pre id="ro-job" style="display:none;margin:0 0 12px;background:#0f172a;color:#e2e8f0;padding:10px 12px;border-radius:6px;font-size:.76rem;max-height:220px;overflow:auto;white-space:pre-wrap;"></pre>

    <div class="ro-cc-modal-bg" id="ro-cc-modal-bg" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:60;align-items:center;justify-content:center" onclick="if(event.target.id==='ro-cc-modal-bg')this.style.display='none'">
        <div style="background:#fff;border-radius:8px;padding:18px 22px;max-width:640px;width:92%;max-height:84vh;overflow:auto;box-shadow:0 10px 30px rgba(0,0,0,.2);line-height:1.6;font-size:.86rem;color:#334155">
            <h3 style="margin:0 0 4px;font-size:1.05rem;color:#1e3a5f">Site Final Check — what runs</h3>
            <p style="margin:0 0 14px;color:#64748b">Three separate modes, picked with the dropdown next to the button — each checks
                a different thing and needs a different precondition. All three lists below share ONE fixed numbering (item 5
                is always "images" in every list) — a greyed-out item means this mode doesn't run that check, with the
                reason stated right there, not just a gap.</p>

            <h4 style="margin:0 0 4px;font-size:.88rem;color:#1e3a5f;">This master's template <span style="font-weight:400;color:#64748b;">— instant, free, no domain needed</span></h4>
            <ol class="ro-cc-list" data-mode="template" style="margin:0 0 14px 20px;padding:0;">
                <?php foreach (cc_checklist_popup_rows(cc_registry(), 'template') as $r): ?>
                <?php if ($r['skipped']): ?>
                <li style="margin-bottom:8px;color:#94a3b8;font-style:italic;"><?= h($r['label']) ?> — not run here: <?= h($r['reason']) ?></li>
                <?php else: ?>
                <li style="margin-bottom:8px;"><?= h($r['label']) ?></li>
                <?php endif; ?>
                <?php endforeach; ?>
            </ol>

            <h4 style="margin:0 0 4px;font-size:.88rem;color:#1e3a5f;">First built domain <span style="font-weight:400;color:#64748b;">— needs this batch's first completed Build (not live yet — that's the point: catch it before Go Live)</span></h4>
            <ol class="ro-cc-list" data-mode="built" style="margin:0 0 14px 20px;padding:0;">
                <?php foreach (cc_checklist_popup_rows(cc_registry_built(), 'built') as $r): ?>
                <?php if ($r['skipped']): ?>
                <li style="margin-bottom:8px;color:#94a3b8;font-style:italic;"><?= h($r['label']) ?> — not run here: <?= h($r['reason']) ?></li>
                <?php else: ?>
                <li style="margin-bottom:8px;"><?= h($r['label']) ?></li>
                <?php endif; ?>
                <?php endforeach; ?>
            </ol>
            <p style="margin:0 0 14px;color:#64748b;">Deliberately narrow for now: Google's own on-page SEO gate already runs
                automatically on every build, but can't be re-run here — it needs the build's temporary working directory,
                which is deleted right after the build finishes. Read its report in that build's own run log instead.
                Schema-identity and <code>aggregateRating</code> checks need careful JSON-LD parsing that deserves its own
                pass, not a rushed one — not built yet.</p>

            <h4 style="margin:0 0 4px;font-size:.88rem;color:#1e3a5f;">First live domain <span style="font-weight:400;color:#64748b;">— needs this batch's first domain actually confirmed live (real HTTP requests to the real internet)</span></h4>
            <ol class="ro-cc-list" data-mode="live" style="margin:0 0 14px 20px;padding:0;">
                <?php foreach (cc_checklist_popup_rows(cc_registry_live(), 'live') as $r): ?>
                <?php if ($r['skipped']): ?>
                <li style="margin-bottom:8px;color:#94a3b8;font-style:italic;"><?= h($r['label']) ?> — not run here: <?= h($r['reason']) ?></li>
                <?php else: ?>
                <li style="margin-bottom:8px;"><?= h($r['label']) ?></li>
                <?php endif; ?>
                <?php endforeach; ?>
            </ol>
            <p style="margin:0 0 10px;color:#64748b;">Deliberately narrow here too: broken-internal-link crawling and sitemap
                completeness are real and valuable but need an actual crawler walking the live site — a separate, bigger
                build, not a quick addition. External paid APIs (PageSpeed Insights, Rich Results Test, Search Console) are
                a different thing again — real cost and API-key setup, a decision of its own, not bundled in here.</p>

            <p style="margin:0 0 10px;color:#64748b">Three more were tried or asked for and deliberately left out, not just
                forgotten: a filename mirrors its own page's keyword (AI-generated photos legitimately carry opaque
                cache-style names like <code>ai_bd09e3_8c5ef2.webp</code> on 47&ndash;80% of photos in some niches — not a
                bug, just a different naming convention — so this check's false-positive rate was too high to trust in
                template mode, and a build never renames a file, so the same false positives would show up in built/live
                mode too); legal page links are "real" (the standard legal pages are built directly into every site, not
                stored as checkable page files, so "real" would need to mean something about their actual text content
                instead — not yet scoped); and whether an alt/filename actually describes what's IN the photo (that needs
                a real AI vision call per image, which is exactly what the separate Image Name Cleanup tool already does —
                duplicating it here would break this tool's always-free, always-instant design).</p>
            <p style="margin:0;color:#64748b">What built/live mode's image checks DO cover, for free: any content photo
                (decorative images — logo/icon/favicon/badge/sprite filenames — are exempt) missing alt text at all, and
                alt text that doesn't share a word with its own page's keyword. The hero image specifically (the page's
                lead photo, when the hero block renders a real &lt;img&gt; rather than a background) is held to a
                stricter bar — most of the keyword's words, not just one — since it's the single strongest spot on the
                page for it to land. Built mode checks every page this way for free (local file reads); live mode checks
                only the homepage, to stay at the one request per check this mode is scoped to.</p>
            <div style="text-align:right;margin-top:10px;"><button type="button" class="btn-sm-outline" onclick="document.getElementById('ro-cc-modal-bg').style.display='none'">Close</button></div>
        </div>
    </div>

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
            <button type="button" class="ro-chip" data-n="7">7</button>
            <button type="button" class="ro-chip" data-n="8">8</button>
            <button type="button" class="ro-chip" data-n="9">9</button>
            <button type="button" class="ro-chip" data-n="10">10</button>
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

    <div id="ro-freshen-alert"></div>

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
        + '&bull; <strong>Colors, fonts, logo &amp; favicon only, alone:</strong> the fast, cheap option — touches only colors/fonts/logo/icon/favicon, nothing else.<br><br>'
        + '&bull; <strong>Both ticked together:</strong> updates the colors/logo AND gets fresh photos, but still skips the written content and pages — real OpenAI cost for the photos, no cost for the colors/logo part.'
        + '</div>';
    const STATIC_INFO = {
        force_photos: ['Force-regenerate photos',
            '<div class="ro-step">Regenerates every AI photo slot on each ticked domain from the <strong>master\'s own approved photo</strong> — not from whatever is currently in that slot.</div>'
          + '<div class="ro-step">Normally a photo slot that already has any image is left alone, even if it\'s wrong — this is the one way to force past that and actually fix it.</div>'
          + '<div class="ro-step"><strong>Real OpenAI cost per photo slot, per domain.</strong> Only does anything while Build is also ticked. Use it when a photo is actually wrong, not as a routine option.</div>'
          + '<div class="ro-step">The opposite of <strong>Skip AI photos this run</strong> — the two can\'t be ticked together.</div>'
          + COMBO_NOTE],
        skip_ai_photos: ['Skip AI photos this run',
            '<div class="ro-step">Turns off every AI photo category for this run only (hero/other, home/landing) — no OpenAI call for any photo slot, no cost, regardless of this batch\'s own saved Generate settings. A one-off override for this click, same spirit as Colors/fonts/logo/favicon only\'s one-off skip list — it does not change what the batch\'s normal runs do afterward.</div>'
          + '<div class="ro-step">A slot that already had an AI photo from an earlier run keeps that photo — this only stops NEW AI photos from being made, it does not remove or revert anything already in place.</div>'
          + '<div class="ro-step">A slot with no AI photo yet (a brand-new domain\'s first build, or a slot never approved in Pic Drop) is <strong>not left blank.</strong> Every photo — AI-eligible or not — still goes through the mechanical pass that always runs during a normal Build: an off-centre crop, a small tone jitter, re-compression, and EXIF/GPS stripping, all seed-deterministic per domain. That pass is free (no API call) and exists specifically to stop two domains off the same master from shipping the exact same image file — so a domain built with this ticked still gets a real, already-unique photo, just the master\'s original scene rather than an OpenAI-edited variant of it.</div>'
          + '<div class="ro-step">The opposite of <strong>Force-regenerate photos</strong> — the two can\'t be ticked together. <strong>Force photo quality</strong> has nothing to generate while this is ticked, so it\'s ignored.</div>'
          + '<div class="ro-step">Only does anything while Build is also ticked.</div>'],
        image_quality: ['Force photo quality',
            '<div class="ro-step">Controls the quality tier OpenAI generates at for <strong>every</strong> AI photo made during this run &mdash; a brand-new photo for a new domain just as much as a Force-regenerate photos re-roll. It is not tied to that checkbox; it just lives next to it because that\'s the control most likely to generate a lot of photos at once.</div>'
          + '<div class="ro-step"><strong>Medium</strong> is what every run has always used (~$0.04&ndash;0.05/photo). <strong>Low</strong> is a real, separate OpenAI price tier, not a resize &mdash; roughly 75&ndash;80% cheaper (~$0.01/photo), but it can show actual artifacts (muddier textures, warped small details), not just softer detail. Spot-check a few Low photos on a real page, phone and desktop, before trusting it at fleet scale &mdash; these photos carry real trust-signal weight for a service-area business.</div>'
          + '<div class="ro-step">Applies only while Build is ticked, same as Force-regenerate photos. A domain whose photo slots are already filled won\'t get a new one at any quality unless Force-regenerate photos is also ticked — and no photo gets generated at any quality while <strong>Skip AI photos this run</strong> is ticked.</div>'
          + '<div class="ro-step"><strong>Real cost now shown per domain (added 2026-10-24):</strong> the run log prints <code>+ img $0.0XXX</code> next to each domain\'s line, a real OpenAI estimate for the photos actually generated — separate from the <code>$</code> figure before it, which is Anthropic\'s text-generation cost for that domain\'s AI content and has nothing to do with photos. Before this, there was no way to see whether a quality tier actually changed what you were billed.</div>'],
        logo_only: ['Colors, fonts, logo &amp; favicon only',
            '<div class="ro-step">Rebuilds <strong>only</strong> the visual identity for each ticked domain — color palette, font, palette jitter, logo, icon, and favicon — skips AI text, photos, page pool, and landing pages entirely.</div>'
          + '<div class="ro-step">Colors and the logo are bundled on purpose, not separable: the logo is painted FROM the current theme colors, so regenerating one without the other would leave the logo showing the old colors against a new theme.</div>'
          + '<div class="ro-step"><strong>Why favicon is in this list (added 2026-10-23):</strong> every live domain is a '
            + '<strong>static build</strong> — plain HTML files on the server, no PHP routing. Browsers and crawlers '
            + 'request the exact path <code>/favicon.ico</code> no matter what a page\'s own icon tag says, and until now '
            + 'nothing on these sites ever had a real file sitting at that exact path — so it 404\'d on every domain in '
            + 'the fleet, even ones whose favicon was already set up correctly and showing fine in the browser tab. '
            + 'The fleet crawl check was logging these as "serving errors to Googlebot." The fix (in '
            + '<code>includes/static_build.php</code>) now copies each domain\'s already-configured favicon image to '
            + '<code>favicon.ico</code> at the root of its build, every time a build runs — including this one. '
            + 'Ticking this box is the cheapest way to get that file onto an already-live domain: it forces a real '
            + 'Build + Upload without spending anything on AI text or photos.</div>'
          + '<div class="ro-step">Local rendering only (no AI/OpenAI call), so there\'s no real cost — safe to run as often as you like.</div>'
          + '<div class="ro-step">Does <strong>not</strong> change this batch\'s saved Generate settings — it\'s a one-off override just for this click. Use it after editing a Theme Preset, Logo Config, or icon in Brand Design to push that change out without a full rebuild.</div>'
          + COMBO_NOTE],
        wipe_files: ['Wipe server files first',
            '<div class="ro-step">Deletes <strong>every file already on the live server</strong> for each ticked domain, '
            + 'THEN uploads this run\'s fresh build on top of the now-empty server. Normal uploads never do this — they '
            + 'only add new files or overwrite changed ones, so anything already on the server that a newer build no '
            + 'longer produces is simply left behind, forever, by every ordinary run.</div>'
          + '<div class="ro-step"><strong>Why this exists (added 2026-10-24):</strong> every domain\'s build used to copy '
            + 'its ENTIRE shared master uploads/ folder into its own output — not just the photos that domain actually '
            + 'uses. That folder accumulates every photo ever generated across every build of every domain on that '
            + 'master, and nothing ever pruned it. Measured live: wrenappliancerepair.com\'s uploads/ held 751 files — a '
            + 'near-exact copy of the master\'s then-748-file folder — for a site whose own ~115 pages need a small '
            + 'fraction of that. The build itself is fixed now (it only copies files a domain\'s own pages actually '
            + 'reference), but that fix only stops things from getting WORSE going forward — it does nothing about the '
            + 'hundreds of excess files a domain like Wren already has sitting live on its server from before the fix. '
            + 'Wipe is how those get actually removed.</div>'
          + '<div class="ro-step"><strong>Only selectable together with a full Build AND Upload</strong> (Colors/fonts/logo/favicon '
            + 'only is not enough, and is greyed out automatically the moment this is ticked — unticking Build or Upload '
            + 'does the same). Reason: that cheaper mode skips page-pool and landing-page generation entirely, so its '
            + 'local output is missing most of a live domain\'s real pages, and without Upload ticked wipe never reaches '
            + 'the step that actually does anything. Wiping the server and then uploading an incomplete build would '
            + 'delete a domain\'s actual content and replace it with only the handful of pages that mode touches. A full '
            + 'Build regenerates everything the site needs, so wiping first and uploading second never leaves a gap — '
            + 'the same checked a second and '
            + 'third time, server-side and inside the upload script itself, not just here.</div>'
          + '<div class="ro-step">Capped at <strong>5 explicitly ticked domains per run</strong> — never "whatever\'s '
            + 'checked in the grid" at fleet scale. Run it again for the next few if you have more to clean up.</div>'
          + '<div class="ro-step">Not sticky: this box un-ticks itself the moment a run is confirmed, success or not — '
            + 'it can never silently carry over and wipe a different set of domains on your next, unrelated click.</div>'
          + '<div class="ro-step" style="color:#92400e;"><strong>This cannot be undone.</strong> Use it on a domain you '
            + 'know has accumulated excess files (check its file count against a sibling domain\'s first if unsure), not '
            + 'as a routine option.</div>'],
        file_dates: ['What a run does to file dates',
            '<div class="ro-step">Every file carries the date it was written, and the server tells the whole internet that date in its <code>Last-Modified</code> header. <strong>Uploading stamps every file it sends with the time of that upload.</strong> So a batch run leaves all its domains carrying timestamps minutes — sometimes seconds — apart.</div>'
          + '<div class="ro-step">That is the one thing on these sites that visibly links them to each other. Measured on the live fleet on 15 Oct 2026: four domains released <em>weeks</em> apart all had homepage dates inside the same <strong>98 seconds</strong> of one night, because a later rebuild had overwritten their original staggered dates.</div>'
          + '<div class="ro-step"><strong>What happens now, automatically:</strong> after an upload, any domain that is already live is queued for a file-date refresh at <strong>its own staggered time</strong> — between 20 minutes and about 20 hours later, fixed per domain. The go-live cron does the work: it re-sends each file so the date becomes that moment, then clears Cloudflare\'s cached copy. Nothing to tick, nothing to remember.</div>'
          + '<div class="ro-step">A domain that is <strong>not live yet</strong> is skipped on purpose — nobody can see its dates — and gets the same treatment the moment it is confirmed live instead.</div>'
          + '<div class="ro-step"><strong>You can see whether it worked.</strong> Open the &#9432; next to any domain and look at <em>File dates</em> — it shows when the last refresh ran, whether it succeeded, and when the next one is due. If one fails, an amber warning appears above the calendar naming the domains, because the refresh runs unattended and nobody would otherwise go looking.</div>'
          + '<div class="ro-step"><strong>It can never undo an upload.</strong> The refresh reads each file and writes the same bytes back, so if one of your uploads lands in that gap the old copy could in theory be written over the new one. It checks first: a file is only re-sent while it still matches exactly what the last upload put there, and anything that has changed is left alone. Your upload always wins.</div>'
          + '<div class="ro-step"><strong>Dates you can see on the page</strong> are separate and already handled: blog posts are spread 14–270 days back (a fixed spread per domain, so rebuilds don\'t reshuffle them), photos have their embedded camera/edit dates stripped out, and the sitemap now reports each page and post\'s own date instead of one shared date for every URL.</div>'
          + '<div class="ro-step">Every Cloudflare account\'s API token now carries <strong>Cache Purge</strong> permission '
            + '(confirmed 2026-10-24: 117 of 118 live domains\' last refresh reports "cache: purged," 0 failures — the '
            + 'one exception is a missing deploy manifest, unrelated to Cloudflare). A refreshed date shows publicly '
            + 'right away, not after the old ~4-hour delay.</div>'],
    };
    function showStaticInfo(key) {
        const [title, body] = STATIC_INFO[key] || ['', ''];
        $('ro-modal-title').textContent = title;
        $('ro-modal-body').innerHTML = body;
        $('ro-modal-bg').style.display = 'flex';
    }
    // Scheduled-vs-released, in the popup rather than the grid: the table is already
    // 15 columns wide, and this is reference detail you look up for one domain, not
    // something to scan down a list — the same reason Phone, Business and the Research
    // date live here too. go_live_at is only the DAY it was queued for; released_at is
    // the minute the registrar actually took the nameserver switch. Blank released_at
    // on a domain that IS live means it went out before that was recorded, which is
    // worth saying plainly instead of leaving an empty line.
    function goLiveLine(r) {
        const scheduled = r.go_live_at ? niceDate(r.go_live_at) + (r.go_live_time ? ' at ' + niceTime(r.go_live_time) : '') : '';
        let released = '';
        if (r.released_at) released = r.released_at;
        else if (['live', 'releasing'].includes(r.status)) released = 'not recorded (released before this was tracked)';
        if (!scheduled && !released) return '';
        // Green only when it's a REAL recorded time — "not recorded" is not an
        // achievement to colour like one.
        const relHtml = r.released_at ? '<span class="ro-when">released ' + esc(released) + '</span>'
                                      : 'released ' + esc(released);
        return '<div class="ro-step">' + dot(r.released_at ? 'ok' : '') + ' <strong>Go live:</strong> '
             + (scheduled ? 'scheduled ' + esc(scheduled) : 'not scheduled')
             + (released ? '<div class="ro-step-note">' + relHtml + '</div>' : '')
             + '</div>';
    }

    // The file-date refresh happens in the go-live cron, minutes to hours after the
    // upload that queued it, with nobody watching — so this line is the only place its
    // outcome is ever visible. It gets a red dot on failure on purpose: the queue slot
    // is cleared either way, so without this a refresh that failed looks exactly like
    // one that was never due.
    function fileDatesLine(r) {
        const last = r.freshen_last_at || '', due = r.freshen_due_at || '';
        const ok = r.freshen_last_ok === 'yes';
        // Nothing to say about a domain nobody can reach yet — its dates are not public,
        // and it gets queued the moment it is confirmed live.
        if (!last && !due && !['live', 'releasing'].includes(r.status)) return '';
        let head, state;
        if (last) {
            state = ok ? 'ok' : 'fail';
            head = ok ? '<span class="ro-when">refreshed ' + esc(last) + '</span>'
                      : '<span class="ro-bad">last refresh FAILED</span> &mdash; ' + esc(last);
        } else {
            state = '';
            head = due ? 'queued, not run yet' : 'never refreshed';
        }
        const why  = last && r.freshen_last_note ? '<div class="ro-step-note">' + esc(r.freshen_last_note) + '</div>' : '';
        const next = due ? '<div class="ro-step-note">next refresh due ' + esc(due) + '</div>' : '';
        return '<div class="ro-step">' + dot(state) + ' <strong>File dates:</strong> ' + head + why + next + '</div>';
    }

    async function showInfo(domain) {
        const r = rows.find(x => x.domain === domain);
        if (!r || !r.detail) return;
        $('ro-modal-title').textContent = domain;
        const fields = '<div class="ro-step"><strong>Row:</strong> #' + esc(r.n) + ' in this batch &mdash; CSV line ' + esc(r.line) + '</div>'
                     + '<div class="ro-step">' + dot(r.phone ? 'ok' : '') + ' <strong>Phone:</strong> ' + esc(r.phone || 'none on file') + '</div>'
                     + '<div class="ro-step">' + dot(r.other ? 'ok' : '') + ' <strong>Business:</strong> ' + esc(r.other || 'none on file') + '</div>'
                     + '<div class="ro-step">' + dot(r.steps.research) + ' <strong>Research:</strong> '
                     + (r.steps.research === 'ok' ? 'done' : 'not yet') + ' &mdash; ' + esc(r.research_at || 'no date on file') + '</div>'
                     + goLiveLine(r)
                     + fileDatesLine(r);
        const steps = Object.entries(STEP_LABELS).map(([k, lbl]) => {
            const d = r.detail[k] || {};
            const state = d.state || '';
            const when = d.at ? 'checked ' + new Date(d.at * 1000).toLocaleString() : 'never checked';
            // The Built step's note carries the actual build time ("built 12 Oct 21:11"),
            // which is the time worth finding in here — the step's own "checked" stamp
            // only says when somebody last verified it. Green on the former, not the latter.
            const note = d.note
                ? '<div class="ro-step-note">'
                  + (k === 'built' && /^built /i.test(d.note) ? '<span class="ro-when">' + esc(d.note) + '</span>' : esc(d.note))
                  + '</div>'
                : '';
            return '<div class="ro-step">' + dot(state) + ' <strong>' + lbl + ':</strong> '
                 + esc(STATE_WORDS[state] ?? state) + ' &mdash; ' + esc(when) + note + '</div>';
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

        // A failed file-date refresh is the easiest thing in this panel to never find
        // out about: it happens in a cron, it clears its own queue slot, and the only
        // other record is a log line. So it is surfaced here, over the WHOLE batch
        // rather than the current tab — the failure is just as real on a row you are
        // not looking at. Shown only when there is something wrong, so it stays
        // worth reading.
        const fFailed = rows.filter(r => r.freshen_last_ok === 'no');
        const fBox = $('ro-freshen-alert');
        if (fFailed.length) {
            const names = fFailed.slice(0, 6).map(r => esc(r.domain)).join(', ')
                        + (fFailed.length > 6 ? ' and ' + (fFailed.length - 6) + ' more' : '');
            fBox.innerHTML = '<strong>File dates: the last refresh failed on ' + fFailed.length + ' domain'
                + (fFailed.length === 1 ? '' : 's') + '.</strong> ' + names
                + ' &mdash; the site itself is unaffected, only the dates on its files were not updated. '
                + 'Open the &#9432; next to a domain for the reason. It will be retried after the next upload.';
            fBox.style.display = 'block';
        } else {
            fBox.style.display = 'none';
        }

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
    // tone 'warn' is the third state this needed: a result that half-worked. Plain
    // ok=true went green on "scheduled 4 of 10", which reads as success and is exactly
    // why six skipped domains went unnoticed.
    function say(text, ok, tone) {
        $('ro-msg').textContent = text;
        $('ro-msg').style.color = tone === 'warn' ? '#b45309' : (ok ? '#15803d' : '#b91c1c');
    }
    const why = w => (w && w.length) ? ' Skipped ' + w.length + ': ' + w.slice(0, 3).map(x => x[0] + ' (' + x[1] + ')').join(', ') + (w.length > 3 ? '…' : '') : '';
    // Same data, grouped by REASON rather than listing the first three domains — when
    // every skip shares one cause ("not uploaded yet", the usual case) that cause is
    // the thing worth reading, not three domain names and an ellipsis.
    const whyGrouped = w => {
        if (!w || !w.length) return '';
        const by = {};
        w.forEach(x => { (by[x[1]] = by[x[1]] || []).push(x[0]); });
        return ' Skipped ' + w.length + ' — ' + Object.entries(by).map(([reason, ds]) =>
            reason + ' (' + ds.length + '): ' + ds.slice(0, 3).join(', ') + (ds.length > 3 ? ' +' + (ds.length - 3) + ' more' : '')
        ).join('; ') + '.';
    };

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
        if (e.target.id === 'ro-do-build' || e.target.id === 'ro-logo-only' || e.target.id === 'ro-do-upload') syncForcePhotos();
        // Force-regenerate and Skip-AI-photos are opposites — ticking one live un-ticks
        // the other immediately, not just refused at submit time, so the checkboxes can
        // never visibly disagree with what the run is actually about to do.
        if (e.target.id === 'ro-force-photos' && e.target.checked) $('ro-skip-ai-photos').checked = false;
        if (e.target.id === 'ro-skip-ai-photos' && e.target.checked) $('ro-force-photos').checked = false;
    });
    // Force-regenerate photos and Colors, fonts, logo & favicon only are both meaningless without
    // Build — greyed out and auto-unticked the moment Build is off, not just blocked at
    // submit time, so it's never possible to leave either checked in a state where it
    // would silently do nothing. Tickable together on purpose — see rollout_build's
    // server-side handling of logo_only+force_photos for what that combination means.
    //
    // Wipe is different: it's not meaningless without a full Build + Upload, it's
    // DANGEROUS without one. --logo-only skips landing/pagepool, so its local output is
    // missing most of a live domain's real pages — wipe would delete everything already
    // on the server and replace it with just the handful of pages logo-only touches.
    // Without Upload ticked, wipe never reaches the step that actually does anything, so
    // it would silently no-op. Greyed out and auto-unticked whenever Build is off, Upload
    // is off, or logo-only is on. Re-checked again server-side in rollout_build and again
    // in build_upload.php itself — a destructive flag refuses itself at every layer that
    // can reach it, never trusting the layer above.
    function syncForcePhotos() {
        const buildOn  = $('ro-do-build').checked;
        const uploadOn = $('ro-do-upload').checked;
        const logoOn   = $('ro-logo-only').checked;
        const fp = $('ro-force-photos');
        const lo = $('ro-logo-only');
        const wp = $('ro-wipe');
        const iq = $('ro-image-quality');
        const sa = $('ro-skip-ai-photos');
        fp.disabled = !buildOn;
        lo.disabled = !buildOn;
        wp.disabled = !buildOn || !uploadOn || logoOn;
        iq.disabled = !buildOn;
        sa.disabled = !buildOn;
        if (!buildOn) { fp.checked = false; lo.checked = false; iq.value = 'medium'; sa.checked = false; }
        if (!buildOn || !uploadOn || logoOn) wp.checked = false;
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

        // Scheduling refuses any domain with nothing uploaded to it (pointing DNS at an
        // empty folder gets it crawled empty) — so ticking 10 when only 4 are uploaded
        // schedules 4, which all fit one day, and days two and three never appear. The
        // old confirm promised "10 domains, 5 per day" before any of that was checked,
        // so the result looked like a broken spread rather than six refusals. The row
        // data already carries each domain's upload state; say so BEFORE posting.
        // Both of the backend's own filters, mirrored here: a site must be uploaded, and
        // the status must still be schedulable ('staged'/'queued' — anything already
        // releasing or live is past the point a date can change).
        const picked = [...sel];
        const rowOf  = d => rows.find(x => x.domain === d) || {};
        const notUp  = picked.filter(d => (rowOf(d).steps || {}).upload !== 'ok');
        const notSch = picked.filter(d => !notUp.includes(d) && !['staged', 'queued'].includes(rowOf(d).status));
        const ready  = picked.length - notUp.length - notSch.length;
        if (!ready) {
            return say(notUp.length === picked.length
                ? 'None of the ' + picked.length + ' ticked domain(s) have a site uploaded yet, so none can be scheduled — Build & Upload them first.'
                : 'None of the ' + picked.length + ' ticked domain(s) can be scheduled — they are not uploaded, or are already live.', false);
        }
        const names = list => list.length <= 8 ? list.join('\n') : list.slice(0, 8).join('\n') + '\n…and ' + (list.length - 8) + ' more';
        const days  = Math.ceil(ready / perDay);
        let msg = 'Schedule ' + ready + ' domain(s): ' + perDay + ' per day from ' + niceDate(start) + ' ' + when
                + '\n\nThat is ' + days + ' day' + (days === 1 ? '' : 's') + ' of releases.';
        if (notUp.length) {
            msg += '\n\n' + notUp.length + ' of the ' + picked.length + ' ticked will be SKIPPED — nothing is uploaded to them yet:\n' + names(notUp);
        }
        if (notSch.length) {
            msg += '\n\n' + notSch.length + ' more will be SKIPPED — already live, or past scheduling:\n' + names(notSch);
        }
        if (!confirm(msg + '\n\nGo ahead?')) return;
        const r = await post({ action: 'rollout_schedule', domains: JSON.stringify(picked), per_day: perDay, start, start_time: startTime, interval });
        if (r.error) return say(r.error);
        // Lead with "N of M", and never paint a part-skipped result green.
        const skipped = (r.why || []).length;
        const span = r.first ? ' (' + niceDate(r.first) + (r.last !== r.first ? ' – ' + niceDate(r.last) : '') + ')' : '';
        say('Scheduled ' + r.scheduled + (skipped ? ' of ' + (r.scheduled + skipped) : '') + span + '.' + whyGrouped(r.why),
            r.scheduled > 0 && !skipped,
            (r.scheduled > 0 && skipped) ? 'warn' : '');
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
                 forcePhotos: $('ro-force-photos').checked, logoOnly: $('ro-logo-only').checked, wipe: $('ro-wipe').checked,
                 imageQuality: $('ro-image-quality').value, skipAiPhotos: $('ro-skip-ai-photos').checked };
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
        // r.dead — the job's process is gone without ever having written an exit code:
        // killed, OOM-killed, or the box restarted mid-run. That is not a failure the
        // job itself reported, so it gets its own wording rather than being folded into
        // "Done, with problems" (and, before the liveness check existed, it showed as
        // "Running — step N of 4…" indefinitely instead).
        const ok = !r.dead && r.exit === 0;
        stageEl.style.color = ok ? '#166534' : '#991b1b';
        stageEl.textContent = r.dead
            ? 'Stopped unexpectedly — the job was killed, or the server restarted, before it finished. Nothing past the last line below ran; re-run the same steps to carry on.'
            : (r.exit === 0 ? 'Done — finished.' : 'Done, with problems — see the log below (steps you didn\'t tick are marked "skipped (not selected)", not a failure).');
        if (runId) { say(r.dead ? 'Stopped unexpectedly before finishing — see the log.' : (ok ? 'Finished.' : 'Finished with problems — see the log.'), ok); load(); }
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
        if ((picked.forcePhotos || picked.logoOnly || picked.skipAiPhotos) && !picked.build) {
            return say((picked.forcePhotos ? 'Force-regenerate photos' : picked.logoOnly ? 'Colors, fonts, logo & favicon only' : 'Skip AI photos this run') + ' does nothing unless Build is also ticked.');
        }
        if (picked.forcePhotos && picked.skipAiPhotos) {
            // Shouldn't be reachable — ticking either one live un-ticks the other (see the
            // change handler above) — refused again anyway, same "never trust the layer
            // above" reasoning as every other contradictory-flag guard in this file.
            return say('Force-regenerate photos and Skip AI photos this run are opposites — pick one.');
        }
        if (picked.forcePhotos && picked.logoOnly) {
            msg += '\n\nFORCE-REGENERATE PHOTOS + COLORS, FONTS, LOGO & FAVICON ONLY are both ticked — this run skips AI text, '
                 + 'page pool, and landing pages for ' + sel.size + ' domain(s), but rebuilds the color palette, font, '
                 + 'jitter, logo, icon, favicon, AND regenerates every AI photo slot from the master\'s own photo. '
                 + 'Real OpenAI cost per photo slot. Continue?';
        } else if (picked.forcePhotos) {
            msg += '\n\nFORCE-REGENERATE PHOTOS is ticked — every AI photo slot on ' + sel.size
                 + ' domain(s) will be regenerated from the master\'s own photo, real OpenAI cost per slot. Continue?';
        } else if (picked.logoOnly) {
            msg += '\n\nCOLORS, FONTS, LOGO & FAVICON ONLY is ticked — this run skips AI text, photos, page pool, and landing pages for '
                 + sel.size + ' domain(s), rebuilding just the color palette, font, jitter, logo, icon, and favicon from current settings. No cost (local only). Continue?';
        }
        if (picked.build && picked.skipAiPhotos) {
            msg += '\n\nSKIP AI PHOTOS THIS RUN is ticked — no OpenAI photo call for ' + sel.size + ' domain(s) this run, zero added cost. '
                 + 'Any slot without an AI photo already keeps the master\'s own photo (still run through the usual free per-domain uniqueness pass, not a raw duplicate). Continue?';
        } else if (picked.build && picked.imageQuality === 'low') {
            msg += '\n\nPHOTO QUALITY is set to Low — any photo generated this run (forced re-rolls, or new ones) uses the cheaper, lower-fidelity tier. Spot-check a few before trusting it at scale. Continue?';
        }
        if (picked.wipe) {
            // Checkbox is greyed out + auto-unticked whenever Build is off or logo-only is
            // on (see syncForcePhotos()), so these two should be unreachable from the UI —
            // checked again anyway, same reasoning as the server-side and build_upload.php
            // checks: a destructive flag refuses itself at every layer, never trusts the
            // layer above to have gotten it right.
            if (!picked.build || !picked.upload || picked.logoOnly) return say('Wipe requires a full Build AND Upload in this same run — turn off Colors/fonts/logo/favicon only.');
            if (sel.size > 5) return say('Wipe is limited to 5 domains at a time — tick fewer, or run it again for the rest.');
            msg += '\n\n⚠ WIPE SERVER FILES FIRST is ticked — every file already on the live server for:\n'
                 + [...sel].join('\n')
                 + '\nwill be DELETED, then replaced with this run\'s fresh build. This cannot be undone. Continue?';
        }
        if (!confirm(msg)) return;
        // Never sticky: unticked the instant the run is confirmed (success or failure),
        // so a second, unrelated "Run selected" click later can never silently wipe
        // whatever happens to be ticked in the grid at that point.
        $('ro-wipe').checked = false;
        const r = await post({ action: 'rollout_build', domains: JSON.stringify([...sel]),
            do_host: picked.host ? '1' : '0', do_build: picked.build ? '1' : '0', do_upload: picked.upload ? '1' : '0',
            force_photos: picked.forcePhotos ? '1' : '0', logo_only: picked.logoOnly ? '1' : '0', wipe: picked.wipe ? '1' : '0',
            image_quality: picked.imageQuality, skip_ai_photos: picked.skipAiPhotos ? '1' : '0' });
        if (r.error) return say(r.error);
        say(what + ' started for ' + sel.size + ' domain(s).', true);
        sel.clear(); render(); clearTimeout(jobTimer); watchJob(r.run_id);
    };
    // Site Final Check — ⓘ opens the static "what runs" modal (built server-side from the
    // same PHP registries the run itself uses — cc_registry()/cc_registry_built()/
    // cc_registry_live() — no separate JS copy of any list to drift out of sync). Run
    // posts the currently-selected mode and renders the report in place; each mode is
    // synchronous (no job/poll needed — see content_check.php's docblock for why none of
    // these three modes are slow enough to need it).
    const ccHints = {
        template: 'Checks this master\'s own template pages — not tied to the domains ticked below.',
        built:    'Checks the first domain in this batch with a completed Build — not live yet, that\'s the point.',
        live:     'Checks the first domain in this batch confirmed live — real HTTP requests to the real internet.',
    };
    $('ro-cc-info').onclick = (e) => { e.preventDefault(); $('ro-cc-modal-bg').style.display = 'flex'; };

    function renderContentCheck(payload) {
        const box = $('ro-cc-report');
        if (!payload || !payload.checked_at) { box.innerHTML = ''; return; }
        if (!payload.results || !payload.results.length) {
            const mode = $('ro-cc-mode').value;
            const why = mode === 'built' ? 'no domain in this batch has a completed Build yet'
                      : mode === 'live'  ? 'no domain in this batch is confirmed live yet'
                      : 'nothing to check';
            box.innerHTML = '<div class="card" style="padding:12px 14px;color:#64748b;font-size:.85rem;">Not available yet — ' + esc(why) + '.</div>';
            return;
        }
        const rows = payload.results.map(r => {
            if (r.skipped) {
                return '<div style="display:flex;align-items:center;gap:8px;margin-top:10px;opacity:.6;">'
                    + '<span class="dot" style="background:#cbd5e1;"></span>'
                    + '<span><strong>' + r.n + '. ' + esc(r.label) + '</strong>'
                    + ' <span style="color:#94a3b8;">— not run here: ' + esc(r.reason) + '</span></span></div>';
            }
            const head = '<div style="display:flex;align-items:center;gap:8px;margin-top:10px;">'
                + '<span class="dot ' + (r.ok ? 'ok' : 'fail') + '"></span>'
                + '<strong>' + r.n + '. ' + esc(r.label) + '</strong>'
                + '<span style="margin-left:auto;font-size:.82rem;color:' + (r.ok ? '#166534' : '#991b1b') + '">'
                + (r.ok ? 'Pass' : r.count + ' issue(s)') + '</span></div>';
            if (r.ok) return head;
            const items = r.issues.slice(0, 10).map(iss =>
                '<li>' + esc(iss.page) + ' &mdash; <code>' + esc(iss.field) + '</code>: ' + esc(iss.detail) + '</li>').join('');
            const more = r.count > r.issues.length ? '<li style="color:#94a3b8">&hellip; and ' + (r.count - r.issues.length) + ' more</li>' : '';
            return head
                + '<ul style="margin:6px 0 0 20px;font-size:.82rem;color:#4b5563;">' + items + more + '</ul>'
                + '<p style="margin:4px 0 0 20px;font-size:.82rem;color:#1e3a5f;"><strong>Fix:</strong> ' + esc(r.recommendation) + '</p>';
        }).join('');
        const domainLine = payload.domain ? ' &mdash; checked <strong>' + esc(payload.domain) + '</strong>' : '';
        box.innerHTML = '<div class="card" style="padding:12px 14px;">'
            + '<div style="font-size:.78rem;color:#94a3b8;">Checked ' + esc(payload.checked_at) + domainLine + '</div>'
            + rows + '</div>';
    }

    async function loadLastContentCheck() {
        const mode = $('ro-cc-mode').value;
        renderContentCheck(await (await fetch(API + '?action=content_check_last&mode=' + mode)).json());
    }

    $('ro-cc-mode').onchange = () => { $('ro-cc-hint').textContent = ccHints[$('ro-cc-mode').value]; loadLastContentCheck(); };

    $('ro-cc-run').onclick = async () => {
        const btn = $('ro-cc-run');
        btn.disabled = true; btn.textContent = 'Checking…';
        const r = await post({ action: 'content_check_run', mode: $('ro-cc-mode').value });
        btn.disabled = false; btn.textContent = 'Run Final Check';
        if (r.error) return say(r.error);
        renderContentCheck(r);
    };
    // Show the last stored report for the default mode on load, if one exists, so
    // reopening the page doesn't read as "never checked" when it just hasn't run yet
    // this session.
    loadLastContentCheck();

    load();
    watchJob('');   // a job already running (page reloaded mid-run) shows its log
})();
</script>
</body>
</html>
