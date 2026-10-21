<?php
/**
 * The fleet calls check — real inbound calls to live sites, via CallTrackingMetrics.
 *
 * ⚠ ONE PLACE ONLY, same reasoning as the other fleet boxes. Do not add it to
 * batch.php or rollout.php.
 *
 * All logic is in infra/lib/calls_audit.php. This renders what that module already
 * worked out.
 *
 * THE HEADLINE IS ENGAGED, NOT TOTAL CALLS. Measured 2026-10-21: 14 of the last 15
 * calls on the one configured account were 0-3 seconds of actual talk time — rang
 * briefly, never connected. A bare call count would report that as 14 leads.
 */

require_once __DIR__ . '/infra/lib/calls_audit.php';

$fclDays = 30;
$fcl = calls_audit_latest($fclDays);
$fclTick = @json_decode((string) @file_get_contents(__DIR__ . '/infra/state/calls_tick.json'), true);
$fclHas = is_array($fclTick) && !empty($fclTick['at']);
$fclAccounts = infra_audit_ctm_account_ids();
$fclConfigured = ctm_configured() && $fclAccounts;
// Red only once there's enough signal to be worried by — zero calls on a fleet this
// young is "too new", not "broken". Green once at least one engaged call has ever
// landed; amber (grey) is the honest "nothing to report yet" state in between.
$fclColour = !$fclConfigured ? '#94a3b8' : ($fcl['engaged'] > 0 ? '#16a34a' : '#94a3b8');
?>
<div id="fcl" class="card" style="margin:0 0 16px;padding:12px 14px;border-left:3px solid <?= $fclColour ?>">
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
        <strong style="font-size:.92rem">Fleet calls check</strong>
        <a href="#" onclick="fclInfo();return false;" title="What this measures, and why a call isn't a lead"
           style="text-decoration:none;color:#94a3b8;font-size:1.05rem">&#9432;</a>
        <span style="font-size:.85rem">
            <?php if (!$fclConfigured): ?>
                <span style="color:#64748b">No CTM account configured (Fleet audit settings, below).</span>
            <?php elseif (!$fclHas): ?>
                <span style="color:#64748b">Never synced.</span>
            <?php else: ?>
                <strong><?= (int) $fcl['calls'] ?></strong>
                <span style="color:#64748b">inbound call<?= $fcl['calls'] === 1 ? '' : 's' ?> in <?= (int) $fclDays ?>d</span>
                <?php if ($fcl['engaged']): ?>
                    <span style="color:#16a34a;font-weight:600">&middot; <?= (int) $fcl['engaged'] ?> engaged (<?= (int) $fcl['engaged_seconds'] ?>s+ talk time)</span>
                <?php else: ?>
                    <span class="fa-warn">&middot; 0 engaged &mdash; all rang <?= (int) $fcl['engaged_seconds'] ?>s or less</span>
                <?php endif; ?>
                <?php if ($fcl['rang']): ?>
                    <span style="color:#64748b">&middot; <?= (int) $fcl['rang'] ?> rang, never connected</span>
                <?php endif; ?>
            <?php endif; ?>
        </span>
        <span style="font-size:.78rem;color:#94a3b8;margin-left:auto">
            <?= $fclHas ? 'last synced ' . h(gmdate('D j M H:i', strtotime($fclTick['at']))) . ' UTC' : '' ?>
        </span>
        <button type="button" class="btn-sm-outline" id="fcl-run" onclick="fclRun()" <?= $fclConfigured ? '' : 'disabled' ?>>Sync now</button>
    </div>

    <?php if ($fclConfigured && $fclHas): ?>
    <details style="margin-top:8px" ontoggle="faLoadRows(this,'calls','fcl-rows')">
        <summary class="fa-sum">Per site (<?= count($fcl['domains']) ?>)</summary>
        <table class="fa-t">
            <thead><tr>
                <th>Site</th>
                <th>Calls <?= (int) $fclDays ?>d</th>
                <th title="Talk time >= the engaged threshold">Engaged</th>
                <th>Last call</th>
            </tr></thead>
            <tbody id="fcl-rows"><tr><td colspan="4" style="color:#94a3b8;padding:8px">Loading&hellip;</td></tr></tbody>
        </table>
    </details>
    <?php endif; ?>
    <div id="fcl-log" style="display:none;margin-top:8px;font:.78rem/1.5 ui-monospace,monospace;
         white-space:pre-wrap;color:#475569;max-height:200px;overflow:auto"></div>
</div>

<div id="fcl-modal-bg" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:60;
     align-items:center;justify-content:center" onclick="fclInfoClose(event)">
    <div style="background:#fff;border-radius:8px;padding:18px 22px;max-width:780px;width:92%;max-height:84vh;
         overflow:auto;box-shadow:0 10px 30px rgba(0,0,0,.2);line-height:1.6;font-size:.86rem;color:#334155">
        <h3 style="margin:0 0 4px;font-size:1.05rem;color:#1e3a5f">Fleet calls check</h3>
        <p style="margin:0 0 14px;color:#64748b">
            Of everyone who dialed a tracking number this fleet bought, who actually talked to someone?
        </p>

        <h4 style="margin:0 0 4px;font-size:.9rem">The one real signal, not a proxy</h4>
        <p style="margin:0 0 14px">
            Every other fleet audit infers interest from a proxy — a crawl, a SERP position, an index count. A
            call is a real human who dialed a number this fleet bought and labelled at purchase time. The join
            is already solved: CallTrackingMetrics' own <code>tracking_label</code> field is exactly what gets
            written when a number is bought (<code>WAT-B26-002-CA-Glendale-ellisonrestoration.com</code>), so
            resolving a call back to a domain is a string split, not a lookup table that can drift.
        </p>

        <h4 style="margin:0 0 4px;font-size:.9rem">A call is not a lead</h4>
        <p style="margin:0 0 14px">
            Measured 2026-10-21: <strong>14 of the last 15 calls were 0-3 seconds of actual talk time</strong> —
            rang briefly, never connected. CTM reports <code>duration</code> (ring-to-hangup) and
            <code>talk_time</code> (actual conversation) separately; this box leads with
            <strong>engaged</strong> &mdash; talk time at or above the threshold set on the Fleet audit
            settings card &mdash; because a bare call count would turn ring-and-hangup noise into a success
            metric.
        </p>

        <h4 style="margin:0 0 4px;font-size:.9rem">The configured account is not fleet-exclusive</h4>
        <p style="margin:0 0 14px">
            A real pull against the one account configured today turned up call history back to 2021 for
            unrelated businesses that pre-date this site factory entirely. CTM has no notion of "which labels
            are ours" &mdash; every call is resolved against the real domains table, and anything that doesn't
            match a known fleet domain is counted in the sync log (so nothing is hidden) but never stored as
            fleet data.
        </p>

        <h4 style="margin:0 0 4px;font-size:.9rem">Free, so it's a daily cron, not a cost gate</h4>
        <p style="margin:0 0 14px">
            Unlike the rank sweep, pulling calls costs nothing extra beyond the CTM subscription itself — no
            confirm dialog, no cadence toggle. <em>Sync now</em> is for seeing today's calls immediately; the
            daily cron keeps history current without you having to remember to click it.
            From a shell: <code>php tools/audit_fleet_calls.php</code>.
        </p>
        <div style="text-align:right"><button type="button" class="btn-sm-outline" onclick="fclInfoClose()">Close</button></div>
    </div>
</div>

<script>
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
var FCL_CSRF = <?= json_encode($csrf ?? ($csrfToken ?? ($_SESSION['csrf_token'] ?? ''))) ?>;
function fclInfo() { document.getElementById('fcl-modal-bg').style.display = 'flex'; }
function fclInfoClose(e) {
    if (e && e.type === 'click' && e.target && e.target.id !== 'fcl-modal-bg') return;
    document.getElementById('fcl-modal-bg').style.display = 'none';
}
document.addEventListener('keydown', function (e) { if (e.key === 'Escape') fclInfoClose(); });

function fclRun() {
    var btn = document.getElementById('fcl-run'), log = document.getElementById('fcl-log');
    btn.disabled = true; btn.textContent = 'Syncing…';
    log.style.display = 'block';
    log.textContent = 'Pulling calls for every configured CTM account…';
    var fd = new FormData();
    fd.append('csrf_token', FCL_CSRF);
    fetch('fleet_audit_api.php?action=run&audit=calls', {method: 'POST', body: fd})
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (d.error) { log.textContent = d.error; btn.disabled = false; btn.textContent = 'Sync now'; return; }
            fclPoll();
        })
        .catch(function (e) { log.textContent = 'Could not start: ' + e; btn.disabled = false; btn.textContent = 'Sync now'; });
}
function fclPoll() {
    fetch('fleet_audit_api.php?action=status&audit=calls')
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (d.log) document.getElementById('fcl-log').textContent = d.log;
            if (d.running) { setTimeout(fclPoll, 3000); return; }
            location.reload();
        })
        .catch(function () { setTimeout(fclPoll, 5000); });
}
</script>
