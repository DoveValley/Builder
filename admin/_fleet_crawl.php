<?php
/**
 * The fleet crawl check — is Google actually visiting, and what is it getting?
 *
 * ⚠ ONE PLACE ONLY, same reasoning as the other two fleet boxes. Do not add it to
 * batch.php or rollout.php.
 *
 * All logic is in infra/lib/crawl_audit.php. This renders what that module already
 * worked out — no counting, no thresholds, no second copy of the "has Google gone
 * quiet" rule living in a template.
 *
 * THE HEADLINE IS THE EXCEPTIONS, NOT THE TOTAL. "94 of 108 crawled" is reassuring
 * and useless; "11 sites Google has not visited in a week" and "3 serving 404s to
 * Googlebot" are both actionable today.
 */

require_once __DIR__ . '/infra/lib/crawl_audit.php';

$fcrDays = 7;
$fcr = crawl_audit_latest($fcrDays);
$fcrHas = $fcr['collected_at'] !== '';
$fcrStaleDays = (int) infra_audit_setting('stale_crawl_days');
// Red on a real problem only. Domains merely not collected yet are not a problem with
// the fleet — they are a problem with the schedule, and they get their own amber note.
$fcrBad = $fcr['never'] + $fcr['stale'] + $fcr['errors'];
$fcrColour = !$fcrHas ? '#94a3b8' : ($fcrBad > 0 ? '#dc2626' : '#16a34a');
?>
<div id="fcr" class="card" style="margin:0 0 16px;padding:12px 14px;border-left:3px solid <?= $fcrColour ?>">
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
        <strong style="font-size:.92rem">Fleet crawl check</strong>
        <a href="#" onclick="fcrInfo();return false;" title="What this reads, and why it beats asking Google"
           style="text-decoration:none;color:#94a3b8;font-size:1.05rem">&#9432;</a>
        <span style="font-size:.85rem">
            <?php if (!$fcrHas): ?>
                <span style="color:#64748b">Never collected.</span>
            <?php else: ?>
                <?php if ($fcrBad === 0): ?>
                    <span style="color:#16a34a;font-weight:600">&#10003; all clear</span>
                <?php endif; ?>
                <strong><?= (int) $fcr['total_hits'] ?></strong>
                <span style="color:#64748b">verified Googlebot hits in <?= (int) $fcrDays ?>d across
                    <?= (int) $fcr['crawled'] ?> site<?= $fcr['crawled'] === 1 ? '' : 's' ?></span>
                <?php if ($fcr['never']): ?>
                    <span style="color:#dc2626;font-weight:600">&middot; <?= (int) $fcr['never'] ?> never visited</span>
                <?php endif; ?>
                <?php if ($fcr['stale']): ?>
                    <span style="color:#dc2626;font-weight:600">&middot; <?= (int) $fcr['stale'] ?> quiet <?= $fcrStaleDays ?>d+</span>
                <?php endif; ?>
                <?php if ($fcr['errors']): ?>
                    <span style="color:#dc2626;font-weight:600">&middot; <?= (int) $fcr['errors'] ?> serving errors</span>
                <?php endif; ?>
                <?php if ($fcr['uncollected']): ?>
                    <span class="fa-warn">&middot; <?= (int) $fcr['uncollected'] ?> not collected yet</span>
                <?php endif; ?>
            <?php endif; ?>
        </span>
        <span style="font-size:.78rem;color:#94a3b8;margin-left:auto">
            <?= $fcrHas ? 'collected ' . h(gmdate('D j M H:i', strtotime($fcr['collected_at']))) . ' UTC' : '' ?>
        </span>
        <button type="button" class="btn-sm-outline" id="fcr-run" onclick="fcrRun()">Collect now</button>
    </div>

    <?php
    $fcrProblems = array_values(array_filter($fcr['rows'],
        fn($r) => $r['collected'] && ($r['never'] || $r['stale'] || $r['s404'] || $r['s5xx'])));
    if ($fcrProblems): ?>
    <ul style="margin:10px 0 0 18px;font-size:.8rem;color:#4b5563;line-height:1.7">
        <?php foreach (array_slice($fcrProblems, 0, 12) as $r): ?>
            <li><strong><?= h($r['domain']) ?></strong> &mdash;
                <?php if ($r['never']): ?>Googlebot has not appeared in the log window at all
                <?php elseif ($r['stale']): ?>last seen <?= (int) $r['age_days'] ?>d ago
                <?php endif; ?>
                <?php if ($r['s404'] || $r['s5xx']): ?>
                    <span style="color:#dc2626">serving
                        <?= $r['s404'] ? (int) $r['s404'] . '&times;404' : '' ?>
                        <?= $r['s5xx'] ? (int) $r['s5xx'] . '&times;5xx' : '' ?> to Googlebot</span>
                <?php endif; ?>
                <?php if ($r['note'] !== ''): ?><span class="fa-warn"> &middot; <?= h($r['note']) ?></span><?php endif; ?>
            </li>
        <?php endforeach; ?>
        <?php if (count($fcrProblems) > 12): ?>
            <li style="color:#94a3b8">&hellip; and <?= count($fcrProblems) - 12 ?> more</li>
        <?php endif; ?>
    </ul>
    <?php endif; ?>

    <?php if ($fcrHas): ?>
    <details style="margin-top:8px" ontoggle="faLoadRows(this,'crawl','fcr-rows')">
        <summary class="fa-sum">Per domain (<?= count($fcr['rows']) ?>)</summary>
        <table class="fa-t">
            <thead><tr>
                <th>Domain</th>
                <th title="IP-verified Googlebot hits in the window">Hits <?= (int) $fcrDays ?>d</th>
                <th title="Distinct URLs Googlebot has ever fetched">Pages seen</th>
                <th>200</th>
                <th>304</th>
                <th>404</th>
                <th>5xx</th>
                <th title="Requests claiming to be Googlebot from non-Google IPs">Fakes</th>
                <th>Last seen</th>
            </tr></thead>
            <tbody id="fcr-rows"><tr><td colspan="9" style="color:#94a3b8;padding:8px">Loading&hellip;</td></tr></tbody>
        </table>
    </details>
    <?php endif; ?>
    <div id="fcr-log" style="display:none;margin-top:8px;font:.78rem/1.5 ui-monospace,monospace;
         white-space:pre-wrap;color:#475569;max-height:200px;overflow:auto"></div>
</div>

<div id="fcr-modal-bg" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:60;
     align-items:center;justify-content:center" onclick="fcrInfoClose(event)">
    <div style="background:#fff;border-radius:8px;padding:18px 22px;max-width:780px;width:92%;max-height:84vh;
         overflow:auto;box-shadow:0 10px 30px rgba(0,0,0,.2);line-height:1.6;font-size:.86rem;color:#334155">
        <h3 style="margin:0 0 4px;font-size:1.05rem;color:#1e3a5f">Fleet crawl check</h3>
        <p style="margin:0 0 14px;color:#64748b">
            Is Google actually visiting each live site, how often, and what is it getting when it does?
        </p>

        <h4 style="margin:0 0 4px;font-size:.9rem">It reads our own logs, not Google</h4>
        <p style="margin:0 0 14px">
            Every site's access log, pulled through the same Hestia API the console already uses. That makes it
            <strong>free, exact, and invisible to Google</strong> &mdash; nothing is asked of them, nothing is
            exposed. Compare the index box, which depends on Google's <code>site:</code> estimate: that returned
            <strong>62, then 10, then nothing</strong> for one query ninety seconds apart.
        </p>

        <h4 style="margin:0 0 4px;font-size:.9rem">Why it is the better early signal</h4>
        <p style="margin:0 0 14px">
            <strong>Crawling comes before indexing, by weeks.</strong> For a fleet where most sites are days old,
            &ldquo;Googlebot came 48 times yesterday and got clean 200s&rdquo; says far more than any index count.
            It also catches things no index check can: pages Google is fetching that <strong>404</strong>, pages it
            has never fetched at all, and server errors served only to the crawler.
        </p>

        <h4 style="margin:0 0 4px;font-size:.9rem">Googlebot is verified by IP, never by name</h4>
        <p style="margin:0 0 14px">
            On one site, <strong>30 of 78 requests claiming to be Googlebot were impostors</strong> &mdash;
            scanners probing for <code>.env</code> files and <code>/proc/self/environ</code> while wearing the name.
            Counting by user-agent reported that site as &ldquo;36% of Googlebot requests failing&rdquo; when real
            Googlebot had <strong>zero</strong> failures. Verification uses Google's own published IP ranges,
            cached and refreshed weekly. <strong>If those ranges cannot be established the collector refuses to
            run</strong> rather than writing scanner traffic into the history as crawl. The <em>Fakes</em> column
            shows how many were turned away.
        </p>

        <h4 style="margin:0 0 4px;font-size:.9rem">Why it is scheduled and not just a button</h4>
        <p style="margin:0 0 14px">
            The log is a <strong>rolling window</strong>. Asking for 10,000 lines of one site returned 3,320 &mdash;
            the entire file &mdash; covering about 3.5 days, and a busier site holds far less.
            <strong>Anything that falls out of the window before a run is gone permanently; there is no backfill.</strong>
            So it runs daily, which gives roughly 3&times; headroom against a missed run, and
            <em>Collect now</em> is for when you want it sooner. Each run records the window it actually saw, so a
            site whose log only reached back 6 hours is flagged as such instead of looking like Google stopped visiting.
        </p>

        <h4 style="margin:0 0 4px;font-size:.9rem">Three states that are not the same</h4>
        <ul style="margin:0 0 14px 20px">
            <li><strong>Not collected yet</strong> &mdash; we have never read this site's log. Not a crawl problem;
                the fix is to run the collector. Greyed, sorted last, never counted as a failure.</li>
            <li><strong>Never</strong> &mdash; collected, and no verified Googlebot appeared in the window at all.</li>
            <li><strong>Quiet <?= $fcrStaleDays ?>d+</strong> &mdash; it was being crawled and has stopped. That is the
                one worth investigating.</li>
        </ul>
        <p style="margin:0 0 14px;color:#64748b">
            Thresholds come from the Fleet audit settings card below. The same collection from a shell:
            <code>php tools/collect_fleet_crawl.php</code> (<code>--report</code> shows the numbers without
            re-reading the logs).
        </p>
        <div style="text-align:right"><button type="button" class="btn-sm-outline" onclick="fcrInfoClose()">Close</button></div>
    </div>
</div>

<script>
// Shared by all four fleet-audit panels: fetch a <details>'s per-domain table body
// only the first time it is opened, instead of shipping every row on every page load.
if (typeof faLoadRows !== 'function') {
    window.faLoadRows = function (details, audit, tbodyId) {
        if (!details.open) return;
        var tbody = document.getElementById(tbodyId);
        if (!tbody || tbody.dataset.loaded === '1') return;
        tbody.dataset.loaded = '1';
        fetch('fleet_audit_api.php?action=rows&audit=' + audit)
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d.error) { tbody.innerHTML = '<tr><td colspan="99" style="color:#dc2626">' + d.error + '</td></tr>'; return; }
                tbody.innerHTML = d.html;
            })
            .catch(function () {
                tbody.innerHTML = '<tr><td colspan="99" style="color:#dc2626">Could not load — reload the page to retry.</td></tr>';
                tbody.dataset.loaded = '0';
            });
    };
}
var FCR_CSRF = <?= json_encode($csrf ?? ($csrfToken ?? ($_SESSION['csrf_token'] ?? ''))) ?>;
function fcrInfo() { document.getElementById('fcr-modal-bg').style.display = 'flex'; }
function fcrInfoClose(e) {
    if (e && e.type === 'click' && e.target && e.target.id !== 'fcr-modal-bg') return;
    document.getElementById('fcr-modal-bg').style.display = 'none';
}
document.addEventListener('keydown', function (e) { if (e.key === 'Escape') fcrInfoClose(); });

function fcrRun() {
    var btn = document.getElementById('fcr-run'), log = document.getElementById('fcr-log');
    btn.disabled = true; btn.textContent = 'Collecting…';
    log.style.display = 'block';
    log.textContent = 'Reading every live site’s access log…';
    var fd = new FormData();
    fd.append('csrf_token', FCR_CSRF);
    fetch('fleet_audit_api.php?action=run&audit=crawl', {method: 'POST', body: fd})
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (d.error) { log.textContent = d.error; btn.disabled = false; btn.textContent = 'Collect now'; return; }
            fcrPoll();
        })
        .catch(function (e) { log.textContent = 'Could not start: ' + e; btn.disabled = false; btn.textContent = 'Collect now'; });
}
function fcrPoll() {
    fetch('fleet_audit_api.php?action=status&audit=crawl')
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (d.log) document.getElementById('fcr-log').textContent = d.log;
            if (d.running) { setTimeout(fcrPoll, 3000); return; }
            location.reload();
        })
        .catch(function () { setTimeout(fcrPoll, 5000); });
}
</script>
