<?php
/**
 * The fleet rank check — where each live site sits in Google for its own keywords.
 *
 * ⚠ ONE PLACE ONLY, same reasoning as the other fleet boxes. Do not add it to
 * batch.php or rollout.php.
 *
 * All logic is in infra/lib/rank_audit.php. This renders what that module worked out.
 *
 * ── THE HEADLINE IS A DISTRIBUTION, NOT AN AVERAGE ───────────────────────────────
 * An "average position" across a fleet where most sites rank nowhere is a number with
 * no meaning — it moves when a site enters the top 100 at #98 and moves the same
 * amount when one falls off page one. So the headline counts keywords by band
 * (top 10 / top 20 / top 100 / nowhere), which is both honest and actionable.
 */

require_once __DIR__ . '/infra/lib/rank_audit.php';

$frk = rank_audit_latest();
$frkHas = $frk['at'] !== '';
$frkTooNew = (int) infra_audit_setting('too_new_days');
$frkDue = rank_audit_due();
// Red on a drop. Green only once there is a comparison to be green about — a baseline
// sweep is information, not success.
$frkColour = !$frkHas ? '#94a3b8'
    : ($frk['down'] > $frk['up'] ? '#dc2626' : ($frk['prev_at'] === '' ? '#94a3b8' : '#16a34a'));
?>
<div id="frk" class="card" style="margin:0 0 16px;padding:12px 14px;border-left:3px solid <?= $frkColour ?>">
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
        <strong style="font-size:.92rem">Fleet rank check</strong>
        <a href="#" onclick="frkInfo();return false;" title="What this measures, and why most of it reads 'nowhere' today"
           style="text-decoration:none;color:#94a3b8;font-size:1.05rem">&#9432;</a>
        <span style="font-size:.85rem">
            <?php if (!$frkHas): ?>
                <span style="color:#64748b">Never run.</span>
            <?php else: ?>
                <strong><?= (int) $frk['top10'] ?></strong>
                <span style="color:#64748b">keyword<?= $frk['top10'] === 1 ? '' : 's' ?> in the top 10 ·
                    <?= (int) $frk['top20'] ?> top 20 · <?= (int) $frk['top100'] ?> top 100 ·
                    <?= (int) $frk['nowhere'] ?> nowhere</span>
                <?php if ($frk['in_pack']): ?>
                    <span style="color:#16a34a;font-weight:600">&middot; <?= (int) $frk['in_pack'] ?> in the map pack</span>
                <?php endif; ?>
                <?php if ($frk['prev_at'] !== ''): ?>
                    <span style="color:#64748b">&middot;</span>
                    <span style="color:#16a34a;font-weight:600"><?= (int) $frk['up'] ?> up</span>
                    <span style="color:<?= $frk['down'] ? '#dc2626' : '#94a3b8' ?>;font-weight:600"><?= (int) $frk['down'] ?> down</span>
                    <span style="color:#64748b">since <?= h(gmdate('j M', strtotime($frk['prev_at']))) ?></span>
                <?php else: ?>
                    <span class="fa-dim">&middot; baseline</span>
                <?php endif; ?>
                <?php if ($frk['unmeasured']): ?>
                    <span class="fa-warn">&middot; <?= (int) $frk['unmeasured'] ?> not measured</span>
                <?php endif; ?>
            <?php endif; ?>
        </span>
        <span style="font-size:.78rem;color:#94a3b8;margin-left:auto">
            <?= $frkHas ? 'last sweep ' . h(gmdate('D j M H:i', strtotime($frk['at']))) . ' UTC &middot; ' : '' ?>
            next automatic sweep <?= $frkDue['next_due_at'] === '' ? 'due now' : h(gmdate('D j M', strtotime($frkDue['next_due_at']))) ?>
            (every <?= (int) $frkDue['cadence_weeks'] ?> week<?= $frkDue['cadence_weeks'] === 1 ? '' : 's' ?>)
        </span>
        <button type="button" class="btn-sm-outline" id="frk-run" onclick="frkRun()">Run sweep</button>
    </div>

    <?php
    // Biggest falls first — a keyword losing ground is the thing worth a look.
    $frkMoves = [];
    foreach ($frk['domains'] as $d) foreach ($d['rows'] as $r) {
        if ($r['delta'] !== null && $r['delta'] !== 0) $frkMoves[] = $r + ['domain' => $d['domain']];
    }
    usort($frkMoves, fn($a, $b) => $a['delta'] <=> $b['delta']);
    $frkFalls = array_values(array_filter($frkMoves, fn($r) => $r['delta'] < 0));
    if ($frkFalls): ?>
    <div style="margin-top:8px;font-size:.8rem">
        <strong style="color:#dc2626">&#9888; Lost ground since the last sweep</strong>
        <ul style="margin:4px 0 0 18px;color:#4b5563;line-height:1.7">
            <?php foreach (array_slice($frkFalls, 0, 8) as $r): ?>
                <li><strong><?= h($r['domain']) ?></strong> &mdash; &ldquo;<?= h($r['keyword']) ?>&rdquo;
                    <?= $r['prev'] === 0 ? '&mdash;' : (int) $r['prev'] ?> &rarr;
                    <?= $r['position'] === 0 ? 'off the top 100' : (int) $r['position'] ?>
                    (<?= (int) $r['delta'] ?>)</li>
            <?php endforeach; ?>
            <?php if (count($frkFalls) > 8): ?><li style="color:#94a3b8">&hellip; and <?= count($frkFalls) - 8 ?> more</li><?php endif; ?>
        </ul>
    </div>
    <?php endif; ?>

    <?php if ($frkHas): ?>
    <details style="margin-top:8px" ontoggle="faLoadRows(this,'rank','frk-rows')">
        <summary class="fa-sum">Per site (<?= count($frk['domains']) ?>)</summary>
        <table class="fa-t">
            <thead><tr>
                <th>Site</th>
                <th title="Its highest-ranking keyword">Best</th>
                <th>Top 10</th>
                <th>Top 100</th>
                <th title="Keywords tracked for this site">Keywords</th>
                <th title="Appearances in the local map pack">Map pack</th>
                <th>Up</th>
                <th>Down</th>
                <th>Age</th>
            </tr></thead>
            <tbody id="frk-rows"><tr><td colspan="9" style="color:#94a3b8;padding:8px">Loading&hellip;</td></tr></tbody>
        </table>
    </details>
    <?php endif; ?>
    <div id="frk-log" style="display:none;margin-top:8px;font:.78rem/1.5 ui-monospace,monospace;
         white-space:pre-wrap;color:#475569;max-height:200px;overflow:auto"></div>
</div>

<div id="frk-modal-bg" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:60;
     align-items:center;justify-content:center" onclick="frkInfoClose(event)">
    <div style="background:#fff;border-radius:8px;padding:18px 22px;max-width:780px;width:92%;max-height:84vh;
         overflow:auto;box-shadow:0 10px 30px rgba(0,0,0,.2);line-height:1.6;font-size:.86rem;color:#334155">
        <h3 style="margin:0 0 4px;font-size:1.05rem;color:#1e3a5f">Fleet rank check</h3>
        <p style="margin:0 0 14px;color:#64748b">
            Where does each live site actually sit in Google for the keywords it was built to win?
        </p>

        <h4 style="margin:0 0 4px;font-size:.9rem">Measured from each site's own city</h4>
        <p style="margin:0 0 14px">
            A local business does not have &ldquo;a ranking&rdquo; &mdash; it has a ranking <em>somewhere</em>.
            The same query answered differently depending on where it was asked from:
            <code>water damage restoration Provo, UT</code> returned <strong>66 organic results with the site at
            #66 nationally</strong>, and <strong>98 results with it at #72 from Provo</strong>. So every probe is
            located to the site's own city, and <strong>the location used is stored with the result</strong> —
            a rank of 72 is meaningless if a later reader cannot tell where it was measured from. If a city
            cannot be resolved the sweep falls back to national and labels the row as such, so two sweeps are
            never silently compared across different measurements.
        </p>

        <h4 style="margin:0 0 4px;font-size:.9rem">It looks 100 deep, on purpose</h4>
        <p style="margin:0 0 14px">
            The oldest site in the fleet sits at <strong>#72</strong>. A depth-10 or depth-20 sweep would have
            reported it as &ldquo;not ranking&rdquo; &mdash; false, and discouraging for no reason. Depth costs
            nothing extra.
        </p>

        <h4 style="margin:0 0 4px;font-size:.9rem">Three values that are not the same</h4>
        <ul style="margin:0 0 14px 20px">
            <li><strong>A number</strong> &mdash; measured, and that is where it sits.</li>
            <li><strong>nowhere</strong> &mdash; measured, and not in the top 100.</li>
            <li><strong>not measured</strong> &mdash; the probe failed after three attempts. Excluded from every
                total. Folding this in with &ldquo;nowhere&rdquo; would turn an API hiccup into a reported
                ranking collapse.</li>
        </ul>

        <h4 style="margin:0 0 4px;font-size:.9rem">The map pack is a separate prize</h4>
        <p style="margin:0 0 14px">
            Most of these searches show a three-result local pack above the organic results. That is a different
            surface with a different route in &mdash; it needs a Google Business Profile, which the fleet does not
            yet have. It is tracked here because it comes free in the same response, and because it shows what
            that gap is costing.
        </p>

        <h4 style="margin:0 0 4px;font-size:.9rem">Why most rows read &ldquo;nowhere&rdquo; today</h4>
        <p style="margin:0 0 14px">
            Because the fleet is young, not because anything is broken. At the time of writing
            <strong>93 of 108 live sites were under a week old</strong> and the oldest was 34 days. New domains
            do not rank yet. Sites under <?= $frkTooNew ?> days are greyed and marked <em>too new</em> rather than
            counted as failures. <strong>Treat this box as a trend, not a scoreboard</strong>, for the first
            couple of months &mdash; and expect noise: one site moved 63 &rarr; 85 &rarr; 65 within minutes of
            testing, so small movements deep in the results mean very little.
        </p>

        <h4 style="margin:0 0 4px;font-size:.9rem">Cost and cadence</h4>
        <p style="margin:0 0 14px">
            Tracked keywords are the homepage term plus the money keywords from each niche's own keyword map &mdash;
            <strong>567 keywords across 108 sites, about $6.18 a sweep</strong> at the measured rate. The depth of
            tiers, and therefore the bill, is set on the Fleet audit settings card. Button-driven, not scheduled:
            weekly is sensible once sites mature, monthly while they are this new.
            From a shell: <code>php tools/audit_fleet_rank.php --dry-run</code> shows the cost without spending it.
        </p>
        <div style="text-align:right"><button type="button" class="btn-sm-outline" onclick="frkInfoClose()">Close</button></div>
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
var FRK_CSRF = <?= json_encode($csrf ?? ($csrfToken ?? ($_SESSION['csrf_token'] ?? ''))) ?>;
function frkInfo() { document.getElementById('frk-modal-bg').style.display = 'flex'; }
function frkInfoClose(e) {
    if (e && e.type === 'click' && e.target && e.target.id !== 'frk-modal-bg') return;
    document.getElementById('frk-modal-bg').style.display = 'none';
}
document.addEventListener('keydown', function (e) { if (e.key === 'Escape') frkInfoClose(); });

function frkRun() {
    if (!confirm('Probe every tracked keyword at DataForSEO?\n\nThis spends roughly $6.50, up to about $13 if keywords need retrying, and takes 15-25 minutes.')) return;
    var btn = document.getElementById('frk-run'), log = document.getElementById('frk-log');
    btn.disabled = true; btn.textContent = 'Sweeping…';
    log.style.display = 'block';
    log.textContent = 'Checking every tracked keyword from its own city…';
    var fd = new FormData();
    fd.append('csrf_token', FRK_CSRF);
    fetch('fleet_audit_api.php?action=run&audit=rank', {method: 'POST', body: fd})
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (d.error) { log.textContent = d.error; btn.disabled = false; btn.textContent = 'Run sweep'; return; }
            frkPoll();
        })
        .catch(function (e) { log.textContent = 'Could not start: ' + e; btn.disabled = false; btn.textContent = 'Run sweep'; });
}
function frkPoll() {
    fetch('fleet_audit_api.php?action=status&audit=rank')
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (d.log) document.getElementById('frk-log').textContent = d.log;
            if (d.running) { setTimeout(frkPoll, 4000); return; }
            location.reload();
        })
        .catch(function () { setTimeout(frkPoll, 6000); });
}
</script>
