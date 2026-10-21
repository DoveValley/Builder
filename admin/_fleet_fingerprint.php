<?php
/**
 * The fleet fingerprint check — one banner, on the Site Manager, under "Your
 * Multisite Batches".
 *
 * ⚠ THIS BELONGS IN EXACTLY ONE PLACE, AND THIS IS IT. The question it answers is a
 * property of the whole FLEET — every live and staged domain across every batch — not
 * of whichever batch somebody happens to have open. It sat on the Rollout screen
 * briefly and that was wrong twice over: it implied a per-batch answer, and a
 * fleet-level check repeated on a per-batch page invites the reading that the other
 * batches are somebody else's problem. Do not re-add it to batch.php or rollout.php.
 *
 * Reads admin/infra/state/fingerprint.json, written by
 * tools/audit_fleet_fingerprints.php — the same script the button runs, so the shell
 * and the browser can never give different answers.
 */

require_once __DIR__ . '/infra/lib/fingerprint_audit.php';

// Both facts come from the module. The panel decides nothing — no thresholds, no file
// paths, no second copy of "is this result still trustworthy" living in a template.
$ffpLast  = fingerprint_last();
$ffpStale = fingerprint_is_stale($ffpLast);
$ffpColour = $ffpLast === null ? '#94a3b8' : (($ffpLast['clean'] && !$ffpStale) ? '#16a34a' : '#dc2626');
?>
<div id="ffp" class="card" style="margin:0 0 16px;padding:12px 14px;border-left:3px solid <?= $ffpColour ?>">
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
        <strong style="font-size:.92rem">Fleet fingerprint check</strong>
        <a href="#" onclick="ffpInfo();return false;" title="What this checks, and what it does not"
           style="text-decoration:none;color:#94a3b8;font-size:1.05rem">&#9432;</a>
        <span style="font-size:.85rem">
            <?php if ($ffpLast === null): ?>
                <span style="color:#64748b">Never run.</span>
            <?php elseif (!$ffpLast['clean']): ?>
                <span style="color:#dc2626;font-weight:600">&#9888; <?= count($ffpLast['problems']) ?> problem(s)</span>
                <span style="color:#64748b">across <?= (int) $ffpLast['checked'] ?> <?= h((string) $ffpLast['scope']) ?> domains</span>
            <?php elseif ($ffpStale): ?>
                <span style="color:#dc2626;font-weight:600">&#9888; out of date</span>
                <span style="color:#64748b">&mdash; a domain has gone live since this ran</span>
            <?php else: ?>
                <span style="color:#16a34a;font-weight:600">&#10003; clean</span>
                <span style="color:#64748b"><?= (int) $ffpLast['checked'] ?> <?= h((string) $ffpLast['scope']) ?> domains, every batch &middot;
                    zone, account, box and A record aligned &middot; no cross-box fingerprint</span>
            <?php endif; ?>
        </span>
        <span style="font-size:.78rem;color:#94a3b8;margin-left:auto">
            <?= $ffpLast ? 'last run ' . h(gmdate('D j M H:i', strtotime((string) $ffpLast['at']))) . ' UTC' : '' ?>
        </span>
        <button type="button" class="btn-sm-outline" id="ffp-run" onclick="ffpRun()">Run check</button>
    </div>
    <?php if ($ffpLast && !$ffpLast['clean']): ?>
    <ul style="margin:10px 0 0 18px;font-size:.8rem;color:#4b5563;line-height:1.7">
        <?php foreach (array_slice($ffpLast['problems'], 0, 12) as $p): ?>
            <li><strong><?= h((string) $p['domain']) ?></strong> &mdash; <?= h((string) $p['what']) ?>: <?= h((string) $p['detail']) ?></li>
        <?php endforeach; ?>
        <?php if (count($ffpLast['problems']) > 12): ?>
            <li style="color:#94a3b8">&hellip; and <?= count($ffpLast['problems']) - 12 ?> more &mdash; run
                <code>php tools/audit_fleet_fingerprints.php</code> for the full list</li>
        <?php endif; ?>
    </ul>
    <?php endif; ?>
    <div id="ffp-log" style="display:none;margin-top:8px;font:.78rem/1.5 ui-monospace,monospace;color:#475569"></div>
</div>

<div id="ffp-modal-bg" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:60;
     align-items:center;justify-content:center" onclick="ffpInfoClose(event)">
    <div style="background:#fff;border-radius:8px;padding:18px 22px;max-width:780px;width:92%;max-height:84vh;
         overflow:auto;box-shadow:0 10px 30px rgba(0,0,0,.2);line-height:1.6;font-size:.86rem;color:#334155">
        <h3 style="margin:0 0 4px;font-size:1.05rem;color:#1e3a5f">Fleet fingerprint check</h3>
        <p style="margin:0 0 14px;color:#64748b">
            Does the fleet still hold together as isolated islands, or does something link one box's
            domains to another's?
        </p>

        <h4 style="margin:0 0 4px;font-size:.9rem">What a &ldquo;fingerprint&rdquo; means here</h4>
        <p style="margin:0 0 10px">
            Every domain in one Cloudflare account shares <strong>one nameserver pair</strong>, so that pair ties
            it to that account's other domains. Its A record ties it to everything else on its <strong>box</strong>.
            The fleet binds one account to exactly one box so that both ties land on the <em>same twenty
            domains</em> &mdash; an island: linkable inside, linkable to nothing outside.
        </p>
        <p style="margin:0 0 14px">
            So a fingerprint is <strong>not</strong> &ldquo;two domains look related&rdquo; &mdash; inside one island
            that is the design. It is a link that <strong>crosses</strong> an island boundary, because that is what
            chains islands into a single web: A shares nameservers with B, B shares an IP with C, and one
            discovered site unravels the fleet.
        </p>

        <h4 style="margin:0 0 4px;font-size:.9rem">What it checks</h4>
        <p style="margin:0 0 2px"><em>Per domain &mdash; asked live at Cloudflare, never read from cache, because
            the record is what we <strong>believe</strong> and the point is to find where that is wrong:</em></p>
        <ol style="margin:0 0 10px 20px">
            <li>The account that actually holds the zone == the account on record</li>
            <li>That account's bound box == the box on record</li>
            <li>Live A records, apex <em>and</em> <code>www</code>, == that box's IP</li>
            <li>Live nameservers == the pair on record</li>
            <li>The record is proxied (a grey cloud exposes the origin IP), and the zone is active if the domain is live</li>
        </ol>
        <p style="margin:0 0 2px"><em>Across domains &mdash; the island graph:</em></p>
        <ol start="6" style="margin:0 0 10px 20px">
            <li>One nameserver pair maps to exactly one box</li>
            <li>One box IP maps to exactly one nameserver pair</li>
            <li>No account spans two boxes; no box carries two accounts</li>
        </ol>
        <p style="margin:0 0 2px"><em>Content &mdash; what survives any amount of DNS hygiene:</em></p>
        <ol start="9" style="margin:0 0 14px 20px">
            <li>An analytics id, Search Console token, phone, address or business name reused on domains sitting
                on <strong>different boxes</strong>. A shared analytics property is a harder link than a shared IP
                will ever be. The same value <em>within</em> one island is ignored &mdash; that is the design.</li>
        </ol>

        <h4 style="margin:0 0 4px;font-size:.9rem">Scope</h4>
        <p style="margin:0 0 14px">
            Every <strong>live</strong> domain plus every <strong>staged</strong> one that already has a zone,
            across <strong>all batches</strong> &mdash; the islands are a property of the fleet, not of a batch.
            Staged is included deliberately: the value is catching a misalignment <em>before</em> it is public.
            On 2026-10-21 eleven domains sat on the wrong box while staged, and a live-only check would have
            read green throughout, turning red only once the damage was on the internet.
            Domains marked <code>owned</code> (bought, never provisioned) are excluded, so one standing issue
            cannot leave the banner permanently red and therefore unread.
        </p>

        <h4 style="margin:0 0 4px;font-size:.9rem">What it does <strong>not</strong> check</h4>
        <ul style="margin:0 0 14px 20px">
            <li><strong>Cross-site linking in the built HTML</strong> &mdash; the strongest fingerprint there is.
                If one site links to another, the islands are joined at the content layer and none of the DNS
                work matters.</li>
            <li><strong>Hosting-network concentration</strong> &mdash; two islands on one provider's ASN, or in
                adjacent IP ranges.</li>
            <li><strong>Registrar account and WHOIS identity</strong> &mdash; shared registrant details or a shared
                payment method are invisible here and would outweigh everything above.</li>
            <li><strong>Shared image assets</strong> &mdash; the same Wikimedia city photo on two sites.</li>
            <li><strong>Boilerplate similarity</strong> across islands.</li>
            <li><strong><code>sameAs</code> / Google Business Profile</strong> &mdash; absent today; a shared social
                account would link everything the moment it is added.</li>
        </ul>

        <h4 style="margin:0 0 4px;font-size:.9rem">Reading the banner</h4>
        <ul style="margin:0 0 14px 20px">
            <li><strong>&#10003; clean</strong> &mdash; with the count, the scope, and when it ran.</li>
            <li><strong>&#9888; out of date</strong> &mdash; something has gone live since the last run.</li>
            <li><strong>&#9888; N problems</strong> &mdash; listed inline; the full set is in the CLI output.</li>
        </ul>
        <p style="margin:0 0 14px;color:#64748b">
            <strong>Run check</strong> asks Cloudflare about every domain in scope, so it takes a minute or two.
            It runs detached and the page refreshes itself when it finishes. It is read-only and changes nothing.
            The same check from a shell: <code>php tools/audit_fleet_fingerprints.php</code>.
        </p>
        <div style="text-align:right"><button type="button" class="btn-sm-outline" onclick="ffpInfoClose()">Close</button></div>
    </div>
</div>

<script>
// Every POST to multisite_api.php is CSRF-checked. A fetch with no body sends no token
// and returns 403 — which, on a button, looks like "nothing happened" rather than an
// error. sites.php exposes the token as $csrf, batch.php as $csrfToken; the session
// covers both, so this include works wherever it is placed.
var FFP_CSRF = <?= json_encode($csrf ?? ($csrfToken ?? ($_SESSION['csrf_token'] ?? ''))) ?>;

function ffpInfo() { document.getElementById('ffp-modal-bg').style.display = 'flex'; }
function ffpInfoClose(e) {
    // Click-outside closes. A click INSIDE the dialog must not, or the panel shuts the
    // moment anybody tries to select the text they opened it to read.
    if (e && e.type === 'click' && e.target && e.target.id !== 'ffp-modal-bg') return;
    document.getElementById('ffp-modal-bg').style.display = 'none';
}
document.addEventListener('keydown', function (e) { if (e.key === 'Escape') ffpInfoClose(); });

function ffpRun() {
    var btn = document.getElementById('ffp-run'), log = document.getElementById('ffp-log');
    btn.disabled = true; btn.textContent = 'Running…';
    log.style.display = 'block';
    log.textContent = 'Asking Cloudflare about every live and staged domain — this takes a minute or two.';
    var fd = new FormData();
    fd.append('csrf_token', FFP_CSRF);
    fetch('fleet_audit_api.php?action=run&audit=fingerprint', {method: 'POST', body: fd})
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (d.error) { log.textContent = d.error; btn.disabled = false; btn.textContent = 'Run check'; return; }
            ffpPoll();
        })
        .catch(function (e) { log.textContent = 'Could not start: ' + e; btn.disabled = false; btn.textContent = 'Run check'; });
}
function ffpPoll() {
    fetch('fleet_audit_api.php?action=status&audit=fingerprint')
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (d.running) { setTimeout(ffpPoll, 3000); return; }
            // Reload rather than redraw: the banner is then rendered by the same PHP
            // that draws it on a normal page load, not a second copy of it in JS.
            location.reload();
        })
        .catch(function () { setTimeout(ffpPoll, 5000); });
}
</script>
