<?php
/**
 * The fleet index check — one banner, on the Site Manager, under the fingerprint one.
 *
 * ⚠ ONE PLACE ONLY, same reasoning as _fleet_fingerprint.php: this is a fleet-wide
 * question, not a per-batch one. Do not add it to batch.php or rollout.php.
 *
 * All logic is in infra/lib/index_audit.php, which owns its own table. This file only
 * renders what that module already worked out — no counting, no thresholds, no second
 * copy of the "is this too new to judge" rule living in a template.
 */

require_once __DIR__ . '/infra/lib/index_audit.php';

$fixLast = index_audit_latest();
$fixHas  = $fixLast['at'] !== '';
// Green only once there is something to be green ABOUT. A baseline sweep with nothing
// to compare against is information, not success, and is drawn neutral.
$fixColour = !$fixHas ? '#94a3b8'
    : ($fixLast['down'] > 0 ? '#dc2626' : ($fixLast['prev_at'] === '' ? '#94a3b8' : '#16a34a'));
$fixPct = $fixLast['built'] > 0 ? round($fixLast['indexed'] / $fixLast['built'] * 100) : 0;
?>
<div id="fix" class="card" style="margin:0 0 16px;padding:12px 14px;border-left:3px solid <?= $fixColour ?>">
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
        <strong style="font-size:.92rem">Fleet index check</strong>
        <a href="#" onclick="fixInfo();return false;" title="What this measures, and what it cannot tell you"
           style="text-decoration:none;color:#94a3b8;font-size:1.05rem">&#9432;</a>
        <span style="font-size:.85rem">
            <?php if (!$fixHas): ?>
                <span style="color:#64748b">Never run.</span>
            <?php else: ?>
                <strong><?= (int) $fixLast['indexed'] ?></strong>
                <span style="color:#64748b">of <?= (int) $fixLast['built'] ?> pages indexed (<?= $fixPct ?>%)
                    across <?= (int) $fixLast['measured'] ?> live sites</span>
                <?php if ($fixLast['prev_at'] === ''): ?>
                    <span style="color:#94a3b8">&middot; baseline, nothing to compare yet</span>
                <?php else: ?>
                    <span style="color:<?= $fixLast['net'] >= 0 ? '#16a34a' : '#dc2626' ?>;font-weight:600">
                        <?= $fixLast['net'] >= 0 ? '+' : '' ?><?= (int) $fixLast['net'] ?></span>
                    <span style="color:#64748b">since <?= h(gmdate('j M', strtotime($fixLast['prev_at']))) ?>
                        &middot; <?= (int) $fixLast['up'] ?> up, <?= (int) $fixLast['down'] ?> down</span>
                <?php endif; ?>
                <?php if ($fixLast['unmeasured'] > 0): ?>
                    <span style="color:#b45309">&middot; <?= (int) $fixLast['unmeasured'] ?> could not be measured</span>
                <?php endif; ?>
            <?php endif; ?>
        </span>
        <span style="font-size:.78rem;color:#94a3b8;margin-left:auto">
            <?= $fixHas ? 'last sweep ' . h(gmdate('D j M H:i', strtotime($fixLast['at']))) . ' UTC' : '' ?>
        </span>
        <button type="button" class="btn-sm-outline" id="fix-run" onclick="fixRun()">Run sweep</button>
    </div>

    <?php
    $fixDown = array_values(array_filter($fixLast['rows'], fn($r) => $r['delta'] !== null && $r['delta'] < 0));
    if ($fixDown): ?>
    <div style="margin-top:8px;font-size:.8rem">
        <strong style="color:#dc2626">&#9888; Lost pages since the last sweep</strong>
        <ul style="margin:4px 0 0 18px;color:#4b5563;line-height:1.7">
            <?php foreach (array_slice($fixDown, 0, 8) as $r): ?>
                <li><strong><?= h($r['domain']) ?></strong> <?= (int) $r['prev'] ?> &rarr; <?= (int) $r['indexed'] ?>
                    (<?= (int) $r['delta'] ?>)</li>
            <?php endforeach; ?>
            <?php if (count($fixDown) > 8): ?><li style="color:#94a3b8">&hellip; and <?= count($fixDown) - 8 ?> more</li><?php endif; ?>
        </ul>
    </div>
    <?php endif; ?>

    <?php if ($fixHas): ?>
    <details style="margin-top:8px">
        <summary style="cursor:pointer;font-size:.8rem;color:#64748b">Per domain (<?= count($fixLast['rows']) ?>)</summary>
        <table style="width:100%;border-collapse:collapse;font-size:.78rem;margin-top:6px">
            <thead><tr style="text-align:left;color:#94a3b8">
                <th style="padding:3px 8px 3px 0">Domain</th>
                <th style="padding:3px 8px 3px 0">Indexed</th>
                <th style="padding:3px 8px 3px 0">Built</th>
                <th style="padding:3px 8px 3px 0">%</th>
                <th style="padding:3px 8px 3px 0">Change</th>
                <th style="padding:3px 8px 3px 0">Went live</th>
                <th style="padding:3px 8px 3px 0">First indexed</th>
                <th style="padding:3px 8px 3px 0" title="Days from go-live to the first sweep that saw it indexed">Days to index</th>
                <th style="padding:3px 0">Age</th>
            </tr></thead>
            <tbody>
            <?php
            $rows = $fixLast['rows'];
            usort($rows, fn($a, $b) => $b['indexed'] <=> $a['indexed']);
            foreach ($rows as $r):
                $pct = ($r['indexed'] >= 0 && $r['built'] > 0) ? round($r['indexed'] / $r['built'] * 100) . '%' : '—';
            ?>
                <tr style="border-top:1px solid #f1f5f9">
                    <td style="padding:3px 8px 3px 0"><?= h($r['domain']) ?></td>
                    <td style="padding:3px 8px 3px 0">
                        <?php if ($r['indexed'] < 0): ?>
                            <span style="color:#b45309" title="<?= h($r['note']) ?>">not measured</span>
                        <?php else: ?><?= (int) $r['indexed'] ?><?php endif; ?>
                    </td>
                    <td style="padding:3px 8px 3px 0"><?= (int) $r['built'] ?></td>
                    <td style="padding:3px 8px 3px 0"><?= $pct ?></td>
                    <td style="padding:3px 8px 3px 0">
                        <?php if ($r['delta'] === null): ?><span style="color:#cbd5e1">—</span>
                        <?php elseif ($r['delta'] > 0): ?><span style="color:#16a34a">+<?= (int) $r['delta'] ?></span>
                        <?php elseif ($r['delta'] < 0): ?><span style="color:#dc2626"><?= (int) $r['delta'] ?></span>
                        <?php else: ?><span style="color:#cbd5e1">0</span><?php endif; ?>
                    </td>
                    <td style="padding:3px 8px 3px 0;color:#64748b">
                        <?= $r['went_live'] !== '' ? h(gmdate('j M', strtotime($r['went_live']))) : '—' ?>
                    </td>
                    <td style="padding:3px 8px 3px 0;color:#64748b">
                        <?= $r['first_indexed'] !== '' ? h(gmdate('j M', strtotime($r['first_indexed']))) : '—' ?>
                    </td>
                    <td style="padding:3px 8px 3px 0">
                        <?php if ($r['tti_days'] === null): ?>
                            <?php /* Never seen indexed. The useful number is how long it has been waiting. */ ?>
                            <span style="color:#94a3b8" title="Not seen in the index yet">
                                <?= $r['age_days'] >= 0 ? 'waiting ' . (int) $r['age_days'] . 'd' : '—' ?></span>
                        <?php elseif ($r['tti_is_bound']): ?>
                            <?php /* Already indexed at our earliest sweep — a ceiling, not the answer. */ ?>
                            <span style="color:#94a3b8"
                                  title="Already indexed when we first looked, so this is an upper bound — it may have been indexed sooner">
                                &le; <?= (int) $r['tti_days'] ?>d</span>
                        <?php else: ?>
                            <strong><?= (int) $r['tti_days'] ?>d</strong>
                        <?php endif; ?>
                    </td>
                    <td style="padding:3px 0;color:<?= $r['too_new'] ? '#94a3b8' : '#475569' ?>">
                        <?= $r['age_days'] >= 0 ? (int) $r['age_days'] . 'd' : '?' ?><?= $r['too_new'] ? ' · too new' : '' ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </details>
    <?php endif; ?>
    <div id="fix-log" style="display:none;margin-top:8px;font:.78rem/1.5 ui-monospace,monospace;
         white-space:pre-wrap;color:#475569"></div>
</div>

<div id="fix-modal-bg" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:60;
     align-items:center;justify-content:center" onclick="fixInfoClose(event)">
    <div style="background:#fff;border-radius:8px;padding:18px 22px;max-width:780px;width:92%;max-height:84vh;
         overflow:auto;box-shadow:0 10px 30px rgba(0,0,0,.2);line-height:1.6;font-size:.86rem;color:#334155">
        <h3 style="margin:0 0 4px;font-size:1.05rem;color:#1e3a5f">Fleet index check</h3>
        <p style="margin:0 0 14px;color:#64748b">
            How many pages of each live site has Google actually taken &mdash; and is that going up or down?
        </p>

        <h4 style="margin:0 0 4px;font-size:.9rem">How it asks</h4>
        <p style="margin:0 0 14px">
            A <code>site:domain.com</code> query per live domain, through DataForSEO's live SERP endpoint rather
            than by fetching Google directly. They absorb the rate limits; this estate has already taken two
            outages from its own request volume, and querying Google a hundred times an hour from one address
            is that same mistake in a new place. <strong>Measured cost: $0.0109 per domain</strong> &mdash; about
            <strong>$1.18</strong> for a full sweep. Weekly is the sensible cadence; indexation does not move
            day to day.
        </p>

        <h4 style="margin:0 0 4px;font-size:.9rem">Why it shows a change and not just a number</h4>
        <p style="margin:0 0 14px">
            &ldquo;17 pages indexed&rdquo; means nothing on its own &mdash; it is good news on a two-week-old
            domain and bad news on one that read 25 last week. Every sweep is kept, so the headline is the
            <strong>delta</strong>. <strong>A site going backwards is the most valuable thing here</strong>: pages
            dropping out of the index is a real problem and no single snapshot can show it. The banner turns red
            on any loss, regardless of how healthy the fleet total looks.
        </p>

        <h4 style="margin:0 0 4px;font-size:.9rem">&ldquo;Not measured&rdquo; is not zero</h4>
        <p style="margin:0 0 14px">
            DataForSEO returns a transient <em>Internal SE Server Error</em> often enough to matter &mdash; 2 of
            the first 6 probes failed, both fine on retry, and one of them was a site that is 81% indexed.
            Recording that as 0 would have invented a deindexing event and ranked it the worst drop on the page.
            Every probe is retried up to three times, and anything still unknown is stored as
            <em>not measured</em> and excluded from every total.
        </p>

        <h4 style="margin:0 0 4px;font-size:.9rem">&ldquo;Too new to judge&rdquo;</h4>
        <p style="margin:0 0 14px">
            A domain under <?= (int) infra_audit_setting("too_new_days") ?> days old is marked rather than counted against the fleet.
            New domains simply are not indexed yet: in the first sample the ~21-day sites were at 26&ndash;81%
            while the 7-day sites had a single page each. Flagging those as failures would make this box
            permanently red and therefore unread.
        </p>

        <h4 style="margin:0 0 4px;font-size:.9rem">&ldquo;Days to index&rdquo;</h4>
        <p style="margin:0 0 14px">
            Days from go-live to the first sweep that saw the site in the index. It is read back out of the
            stored sweep history &mdash; nothing extra is fetched for it.
            <strong>A bare number (<em>14d</em>) is an observed answer</strong>: the site was not indexed at one
            sweep and was at the next.
            <strong>A <em>&le;</em> prefix is a ceiling, not an answer</strong>: that site was already indexed the
            very first time we looked, so it became indexed at some unknown point before that and all we honestly
            know is that it took no longer than this. Every domain live before the first sweep will read that way,
            and the distinction disappears on its own as new sites go live.
            <em>waiting 9d</em> means it has never been seen in the index &mdash; that is the number worth watching.
        </p>

        <h4 style="margin:0 0 4px;font-size:.9rem">Scope</h4>
        <p style="margin:0 0 14px">
            <strong>Live domains only.</strong> A staged domain's nameservers have not been switched, so Google
            cannot have indexed it &mdash; a zero there would carry no information and would only drag the fleet
            total down.
        </p>

        <h4 style="margin:0 0 4px;font-size:.9rem">What it cannot tell you</h4>
        <ul style="margin:0 0 14px 20px">
            <li><strong>Why</strong> a page is missing. A <code>noindex</code>, a broken sitemap and a canonical
                pointing elsewhere all look identical from here. This measures the symptom.</li>
            <li><code>site:</code> counts are <strong>approximate</strong> &mdash; Google's own totals are
                estimates and can move without anything changing.</li>
            <li>It does not check <em>which</em> URLs are missing page-by-page; that would need one query per
                URL, which is neither affordable nor useful at fleet scale.</li>
            <li>For a true per-URL answer &mdash; <em>Indexed</em> vs <em>Crawled, currently not indexed</em> vs
                <em>Discovered, not indexed</em> &mdash; only Search Console will do, and that needs a verified
                property per domain. <strong>0 of 401 domains currently carry a
                <code>gsc_verification</code> token.</strong></li>
        </ul>
        <p style="margin:0 0 14px;color:#64748b">
            <strong>Run sweep</strong> probes every live domain, so it takes a few minutes and spends about
            $1.18. It runs detached and the page refreshes itself when it finishes.
            The same sweep from a shell: <code>php tools/audit_fleet_index.php</code>
            (add <code>--dry-run</code> to see the cost without spending it).
        </p>
        <div style="text-align:right"><button type="button" class="btn-sm-outline" onclick="fixInfoClose()">Close</button></div>
    </div>
</div>

<script>
var FIX_CSRF = <?= json_encode($csrf ?? ($csrfToken ?? ($_SESSION['csrf_token'] ?? ''))) ?>;

function fixInfo() { document.getElementById('fix-modal-bg').style.display = 'flex'; }
function fixInfoClose(e) {
    if (e && e.type === 'click' && e.target && e.target.id !== 'fix-modal-bg') return;
    document.getElementById('fix-modal-bg').style.display = 'none';
}
document.addEventListener('keydown', function (e) { if (e.key === 'Escape') fixInfoClose(); });

function fixRun() {
    if (!confirm('Probe every live domain at DataForSEO?\n\nThis spends roughly $1.18 and takes a few minutes.')) return;
    var btn = document.getElementById('fix-run'), log = document.getElementById('fix-log');
    btn.disabled = true; btn.textContent = 'Sweeping…';
    log.style.display = 'block';
    log.textContent = 'Asking Google, via DataForSEO, about every live domain…';
    var fd = new FormData();
    fd.append('csrf_token', FIX_CSRF);
    fetch('fleet_audit_api.php?action=run&audit=index', {method: 'POST', body: fd})
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (d.error) { log.textContent = d.error; btn.disabled = false; btn.textContent = 'Run sweep'; return; }
            fixPoll();
        })
        .catch(function (e) { log.textContent = 'Could not start: ' + e; btn.disabled = false; btn.textContent = 'Run sweep'; });
}
function fixPoll() {
    fetch('fleet_audit_api.php?action=status&audit=index')
        .then(function (r) { return r.json(); })
        .then(function (d) {
            // Show the tail so a multi-minute sweep reads as progress, not a hang.
            if (d.log) document.getElementById('fix-log').textContent = d.log;
            if (d.running) { setTimeout(fixPoll, 4000); return; }
            location.reload();
        })
        .catch(function () { setTimeout(fixPoll, 6000); });
}
</script>
