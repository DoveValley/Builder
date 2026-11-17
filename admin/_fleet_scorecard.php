<?php
/**
 * The fleet scorecard — one row per live domain, rank + index + crawl + calls
 * joined side by side.
 *
 * ⚠ ONE PLACE ONLY, same reasoning as the other fleet boxes. Do not add it to
 * batch.php or rollout.php.
 *
 * Deliberately has no "run" button, unlike crawl/index/rank/calls — there is no
 * sweep of its own to trigger. All logic is in infra/lib/scorecard_audit.php, which
 * only joins what those four cards' own *_latest() already computed; refreshing any
 * of them refreshes this too. Sits above them so the one-line-per-domain answer to
 * "how is this site doing" comes before the four separate detail tables that can
 * explain WHY, not after.
 */

require_once __DIR__ . '/infra/lib/scorecard_audit.php';

$fsc = scorecard_latest($faScope ?? null);
$fscColour = $fsc['flagged'] > 0 ? '#dc2626' : '#16a34a';
?>
<div id="fsc" class="card" style="margin:0 0 16px;padding:12px 14px;border-left:3px solid <?= $fscColour ?>">
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
        <strong style="font-size:.92rem">Fleet scorecard</strong>
        <a href="#" onclick="fscInfo();return false;" title="What counts as a flag, and why some rows are greyed"
           style="text-decoration:none;color:#94a3b8;font-size:1.05rem">&#9432;</a>
        <span style="font-size:.85rem">
            <strong><?= (int) $fsc['flagged'] ?></strong>
            <span style="color:#64748b">of <?= (int) $fsc['total'] ?> live domain<?= $fsc['total'] === 1 ? '' : 's' ?>
                flagged for something worth a look</span>
        </span>
    </div>

    <details style="margin-top:8px" ontoggle="faLoadRows(this,'scorecard','fsc-rows')">
        <summary class="fa-sum">Per site (<?= (int) $fsc['total'] ?>)</summary>
        <table class="fa-t">
            <thead><tr>
                <th>Site</th>
                <th>Age</th>
                <th title="Best measured keyword position">Rank</th>
                <th title="Indexed / built pages">Index</th>
                <th title="Googlebot hits, last 7 days">Crawl</th>
                <th title="Calls / engaged (90s+), last 30 days">Calls</th>
                <th>Status</th>
            </tr></thead>
            <tbody id="fsc-rows"><tr><td colspan="7" style="color:#94a3b8;padding:8px">Loading&hellip;</td></tr></tbody>
        </table>
    </details>
</div>

<div id="fsc-modal-bg" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:60;
     align-items:center;justify-content:center" onclick="fscInfoClose(event)">
    <div style="background:#fff;border-radius:8px;padding:18px 22px;max-width:640px;width:92%;max-height:84vh;
         overflow:auto;box-shadow:0 10px 30px rgba(0,0,0,.2);line-height:1.6;font-size:.86rem;color:#334155">
        <h3 style="margin:0 0 4px;font-size:1.05rem;color:#1e3a5f">Fleet scorecard</h3>
        <p style="margin:0 0 14px;color:#64748b">
            Rank, index, crawl and calls, one row per domain — a shortcut to the four cards below this
            one, not a replacement for them. This never collects anything itself; it only reads what
            those four already have on hand, so it's exactly as fresh as whichever of them last ran.
        </p>
        <h4 style="margin:0 0 4px;font-size:.9rem">What flags a row</h4>
        <ul style="margin:0 0 14px 20px">
            <li><strong>crawl error</strong> — a real (non-cosmetic) 404 or 5xx Googlebot hit in the
                last 7 days.</li>
            <li><strong>nothing indexed</strong> — pages built, zero of them indexed, and the site is
                old enough that "still too new" no longer explains it.</li>
            <li><strong>not ranking</strong> — keywords measured, none in the top 100, same
                old-enough-to-judge gate.</li>
            <li><strong>crawl gone quiet</strong> — Google used to visit and has stopped recently.</li>
        </ul>
        <h4 style="margin:0 0 4px;font-size:.9rem">Greyed rows</h4>
        <p style="margin:0">
            A domain younger than the "too new" threshold (Fleet audit settings, below) never flags on
            rank or index, no matter what the numbers say — there's nothing to judge yet, only
            something to wait on.
        </p>
        <div style="text-align:right;margin-top:14px"><button type="button" class="btn-sm-outline" onclick="fscInfoClose()">Close</button></div>
    </div>
</div>

<script>
if (typeof faLoadRows !== 'function') {
    window.faLoadRows = function (details, audit, tbodyId) {
        if (!details.open) return;
        var tbody = document.getElementById(tbodyId);
        if (!tbody || tbody.dataset.loaded === '1') return;
        tbody.dataset.loaded = '1';
        fetch('fleet_audit_api.php?action=rows&audit=' + audit + window.location.search.replace('?', '&'))
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
function fscInfo() { document.getElementById('fsc-modal-bg').style.display = 'flex'; }
function fscInfoClose(e) {
    if (e && e.type === 'click' && e.target && e.target.id !== 'fsc-modal-bg') return;
    document.getElementById('fsc-modal-bg').style.display = 'none';
}
document.addEventListener('keydown', function (e) { if (e.key === 'Escape') fscInfoClose(); });
</script>
