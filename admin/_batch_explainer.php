<?php
/**
 * "How a batch works" — plain-English overview at the very top of the batch page.
 *
 * Purely explanatory (no state read, no action triggered) — orients a reader before
 * the six phase cards below start asking for input. Three phases here are not this
 * page's own code at all; they're the Infrastructure console's engine, reached through
 * a batch-scoped button. Saying that plainly here is the point: a click on "Create
 * host" or "Go Live" that fails is failing inside admin/infra/lib/, not this page.
 *
 * Expects: $masterId, $batchId (for the "open in Infrastructure" link).
 */
if (!isset($masterId, $batchId)) return;
?>
<style>
.be-table    { width:100%; border-collapse:collapse; font-size:.88rem; }
.be-table th { text-align:left; padding:7px 10px; border-bottom:1px solid #e2e8f0; font-size:.76rem; text-transform:uppercase; letter-spacing:.04em; color:#64748b; font-weight:700; }
.be-table td { padding:11px 10px; border-bottom:1px solid #f1f5f9; vertical-align:top; }
.be-step     { font-weight:700; color:#0f172a; white-space:nowrap; }
.be-does     { color:#475569; font-size:.83rem; }
.be-where    { color:#64748b; font-size:.82rem; }
.be-btn      { display:inline-block; background:#eef2ff; color:#3730a3; border:1px solid #c7d2fe; border-radius:4px; padding:2px 7px; font-size:.78rem; font-weight:700; }
.be-card     { color:#94a3b8; font-size:.72rem; }
</style>
<details class="card" open style="background:#f8fafc;border-left:3px solid #7c3aed;margin-bottom:16px;">
    <summary style="cursor:pointer;font-weight:700;font-size:1.02rem;color:#1e3a5f;">
        How a batch works &mdash; the six phases, end to end
    </summary>

    <p class="hint" style="margin:12px 0 4px;">
        A batch turns a target list (domains + business data) into live sites. It always moves
        through the same six phases, in order &mdash; the cards further down this page are exactly
        these six, 1-for-1. <strong>Three of them are this page's own content pipeline; three are the
        Infrastructure console's hosting/DNS engine, reached from here through a button</strong> so you
        never have to leave the batch to run them.
    </p>

    <table class="be-table" style="margin-top:10px;">
        <thead>
            <tr>
                <th style="width:16%;">Phase</th>
                <th style="width:22%;">Click this button</th>
                <th style="width:37%;">What it does</th>
                <th style="width:25%;">Runs where</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td class="be-step">1 &middot; Upload target list</td>
                <td class="be-does"><span class="be-btn">Upload &amp; Validate</span><br><span class="be-card">Upload target list card, below</span></td>
                <td class="be-does">Loads the CSV of domains + business data (name, phone, city, FTP creds if you already have them) that every later phase works from.</td>
                <td class="be-where"><strong style="color:#0f172a;">This page.</strong> Stored as this batch's own <code>params.csv</code> &mdash; nothing to do with Infrastructure yet.</td>
            </tr>
            <tr>
                <td class="be-step">2 &middot; Pick servers</td>
                <td class="be-does"><span class="be-btn">Save plan</span><br><span class="be-card">Pick deployment servers card, below</span></td>
                <td class="be-does">Decides which VPS boxes this batch's sites land on, and how many go to each.</td>
                <td class="be-where">This page saves the plan, but <strong style="color:#7c3aed;">reads live "on it now" counts from the Infrastructure fleet</strong> so the plan isn't made blind.</td>
            </tr>
            <tr>
                <td class="be-step">3 &middot; Create host</td>
                <td class="be-does"><span class="be-btn">Create host areas</span><br><span class="be-card">Create host card, below</span></td>
                <td class="be-does">Actually provisions each site's home on its picked box &mdash; the web folder + a scoped FTP login &mdash; and writes those credentials back into the target list.</td>
                <td class="be-where"><strong style="color:#7c3aed;">Infrastructure's own provisioning engine</strong> (the same routine "+ New Site"/Bulk provisioning use on HestiaCP), called from a button on this page.</td>
            </tr>
            <tr>
                <td class="be-step">4 &middot; Generate sites</td>
                <td class="be-does"><span class="be-btn">Generate sites</span><br><span class="be-card">Generate sites card, below</span></td>
                <td class="be-does">Clones the master, injects each row's identity, writes AI copy, and renders every page to static HTML.</td>
                <td class="be-where"><strong style="color:#0f172a;">This page's content pipeline</strong> (<code>run_campaign.php</code>). No Infrastructure involvement &mdash; this only touches files.</td>
            </tr>
            <tr>
                <td class="be-step">5 &middot; Upload sites</td>
                <td class="be-does"><span class="be-btn">Upload sites</span><br><span class="be-card">Upload sites card, below</span></td>
                <td class="be-does">Pushes the generated files to each site's host over FTP/SFTP, using the credentials phase 3 wrote.</td>
                <td class="be-where"><strong style="color:#0f172a;">This page's content pipeline</strong>, same as phase 4 &mdash; still just files, no hosting/DNS changes.</td>
            </tr>
            <tr>
                <td class="be-step">6 &middot; Go Live (DNS)</td>
                <td class="be-does"><span class="be-btn">Schedule rollout</span> / per-row <span class="be-btn">Create zone</span> / <span class="be-btn">▶ all</span><br><span class="be-card">Go Live (DNS) card, below</span></td>
                <td class="be-does">Creates each domain's Cloudflare zone, then switches its nameservers at the registrar so it actually starts serving traffic.</td>
                <td class="be-where"><strong style="color:#7c3aed;">Infrastructure's own pipeline</strong> (Cloudflare + registrar code) &mdash; the identical engine the Infrastructure console's Bulk tab uses. This card is a batch-scoped window onto it, not a separate implementation.</td>
            </tr>
        </tbody>
    </table>

    <p class="hint" style="margin:14px 0 4px;">
        <strong>Getting domains into a batch in the first place</strong> also happens in
        Infrastructure, not here: a domain is bought and owned on the <strong>D.Buy</strong> tab, then
        <strong>"Claim for Batch"</strong> appends it to this batch's target list &mdash; that's what
        populates phase 1 for anything acquired through the console, before you ever open this page.
    </p>

    <p class="hint" style="margin:0 0 4px;">
        <strong>Tearing a domain down</strong> &mdash; deleting its Cloudflare zone, its host, or
        untracking it entirely &mdash; is <em>not</em> a control on this page at all. It lives only in
        Infrastructure's per-domain <strong>Danger Zone</strong>, one domain at a time with a typed
        confirmation, on purpose: this page is about building things, that page is the only place
        that destroys them.
    </p>

    <p class="hint" style="margin:10px 0 0;">
        <a href="infra/index.php?view=bulk&amp;batch=<?= urlencode($batchId) ?>" target="_blank">Open this batch in the Infrastructure console &rarr;</a>
        &nbsp;&middot;&nbsp;
        <a href="docs.php?doc=multisite#ms-batch-lifecycle" target="_blank">Full write-up in the docs &rarr;</a>
    </p>
</details>
