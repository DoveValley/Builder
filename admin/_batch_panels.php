<?php
/**
 * Batch work panels — the "doing a run" half of multisite.
 *
 * Included by admin/batch.php. Everything here operates on the batch currently open
 * (session), via multisite_api.php. Master-level setup (niche brief, theme presets,
 * icons, master health check) deliberately lives with the SITE, not here.
 *
 * Expects: $csrfToken, $researchOn, $masterId, $batchId
 */
if (!isset($csrfToken)) return;
require_once __DIR__ . '/../includes/multisite/page_pool.php';
require_once __DIR__ . '/../includes/multisite/image_ai.php';
require_once __DIR__ . '/../includes/multisite/image_overlay.php'; // ms_image_settings_read() — other per-master settings this file reads
require_once __DIR__ . '/../includes/multisite/batch.php'; // ms_batch_file_read() — per-BATCH settings (rotation pins, checkbox defaults)
require_once __DIR__ . '/../includes/multisite/batch_options.php'; // ms_batch_options_settings() — every checkbox's persisted default
require_once __DIR__ . '/infra/lib/state.php'; // infra_niche() — only used inside the CLI job, not this file directly
require_once __DIR__ . '/infra/lib/registrar.php'; // infra_registrar_buyable()/_config()/_type_def() — Buy Domains card's dropdown

// Buy Domains card: only registrars that can actually complete a NEW purchase over
// the API (infra_registrar_buyable_for_new_purchases() — excludes Cloudflare, see
// its own docblock) AND already have saved credentials — offering one with no
// credentials would just fail immediately on click. Also used by the per-row
// Registrar column's inline <select> (admin/_batch_panels.php's own script), same
// list, same reasoning — one source for both.
$msBuyRegistrars = array_values(array_filter(infra_registrar_buyable_for_new_purchases(),
    fn($n) => (bool) infra_registrar_config($n)));
$msBuyRegistrarLabels = array_combine($msBuyRegistrars,
    array_map(fn($n) => infra_registrar_type_def($n)['label'] ?? $n, $msBuyRegistrars));

// Section-order rotation pin counts, persisted per BATCH (rotation_settings_save.php) — this
// batch's own choice, not shared with any other batch off the same master. Pre-fills the
// number inputs below; the value actually used for a run is whatever's in the box when
// Generate sites is clicked.
$msRotSettings = ms_rotation_settings(ms_batch_file_read($masterId, $batchId, 'section_rotation.json'));

// Strict positional box/Cloudflare-account mode, persisted per BATCH
// (box_assignment_save.php) — read by Create Host (multisite/create_hosts.php) to
// decide whether it trusts each row's own `box` column or picks one itself. Defaults
// ON for any batch that has never saved a choice (ms_box_assignment_defaults()).
require_once __DIR__ . '/../includes/multisite/box_assignment.php';
$msBoxSettings = ms_box_assignment_settings(ms_batch_file_read($masterId, $batchId, 'box_assignment.json'));
// Every checkbox's persisted default (7 parent steps + every sub-switch), per BATCH
// (batch_options_save.php) — same reasoning: two batches off the same master can genuinely
// want different defaults (e.g. a "test" batch vs. the real one). Same "purely pre-fills the
// UI" rule as the rotation numbers above — build_one.php never reads this file; only what's
// checked at click time matters for a run.
$msBatchOptions = ms_batch_options_settings(ms_batch_file_read($masterId, $batchId, 'batch_options.json'));

// The 6 bulk-action cards below (Get numbers / Set routing / Release / Resync / Area code /
// Buy Domains) share identical wrapper, "background job" badge, and output/results footer
// markup — only the description and controls in between actually differ. Pulled the shared
// shell into these two so each card writes just its own distinct body between start()/end().
// Not CTM-specific despite the name — Buy Domains reuses the same shell for the same reason.
function ms_ctm_card_start(string $prefix, string $heading): void {
    ?>
    <div id="ms-<?= h($prefix) ?>-card" style="margin:0 0 10px;padding:10px 12px;background:#f8fafc;border:1px solid #cbd5e1;border-radius:8px;">
        <strong style="font-size:.82rem;color:#1e3a5f;"><?= h($heading) ?></strong>
        <span title="Background job — safe to navigate away or close this tab, it keeps running on the server." style="display:inline-block;background:#1e3a8a;color:#fff;font-weight:800;font-size:.68rem;line-height:1;border-radius:3px;padding:2px 5px;cursor:help;vertical-align:middle;margin-left:6px;">B</span>
    <?php
}
function ms_ctm_card_end(string $prefix): void {
    ?>
        <details id="ms-<?= h($prefix) ?>-details" style="margin-top:8px;">
            <summary style="cursor:pointer;font-size:.78rem;color:#475569;">Output &amp; results</summary>
            <pre id="ms-<?= h($prefix) ?>-out" style="margin-top:8px;background:#0f172a;color:#e2e8f0;padding:10px 12px;border-radius:6px;font-size:.76rem;max-height:220px;overflow:auto;white-space:pre-wrap;"></pre>
            <div id="ms-<?= h($prefix) ?>-results" style="margin-top:8px;font-size:.8rem;"></div>
        </details>
    </div>
    <?php
}
?>
<!-- Shared ⓘ popup for this page and _batch_golive.php (included below) — a native
     title="" tooltip disappears the moment you move toward it, so it can't be read in
     full or clicked into. One instance here covers both files. -->
<div class="bp-info-bg" id="bp-info-bg">
    <div class="bp-info-box">
        <span class="bp-info-close" id="bp-info-close">&times;</span>
        <div id="bp-info-body"></div>
    </div>
</div>
<!-- ===== UPLOAD CARD ===== -->
<div class="card" id="ms-upload">
    <h3 style="margin-top:0;">1. Upload target list (CSV)</h3>
    <p class="hint">Prepare the table in Excel or Google Sheets and <strong>Save As / Export &rarr; CSV</strong>. One row per site. Required columns: <code>domain, business, phone, city, state, SS</code>. Optional: <code>email</code> (blank is a soft warning, not an error — it just shows up blank anywhere the site uses it), <code>tel, address, zip, lat, lng, rating, review_count, analytics_id, logo, years_in_business, mission_statement, business_short</code> (a shorter brand form for page titles, e.g. "Bailey Restoration" — falls back to the full <code>business</code> name when left blank). Add FTP credentials (<code>ftp_host, ftp_user, ftp_pass</code>) to deploy &mdash; omit them and the row still builds.</p>

    <p style="margin:0 0 8px;">
        <a class="btn" href="multisite_api.php?action=sample_csv">&#11015; Download sample CSV</a>
        <span class="hint" style="margin-left:8px;">5 example cities with every column filled in &mdash; edit it as a starting point. Columns marked <code>*</code> must be filled on every row; the <code>*</code> is just a hint and is ignored on upload.</span>
    </p>
    <p id="ms-download-row" style="margin:0 0 14px;display:none;">
        <a class="btn" id="ms-download-btn" href="multisite_api.php?action=download_csv">&#11015; Download current table (FTP masked)</a>
        <span class="hint" style="margin-left:8px;">Passwords export as <code>__KEEP__</code>. Edit &amp; re-upload &mdash; leave <code>__KEEP__</code> to keep a password, or type a new one.</span>
    </p>

    <form id="ms-upload-form" onsubmit="return msUpload(event)">
        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
        <div class="form-group">
            <input type="file" name="csv" id="ms-csv" accept=".csv,text/csv" required>
        </div>
        <button type="submit" class="btn btn-primary" id="ms-upload-btn">Upload &amp; Validate</button>
        <button type="button" class="btn" id="ms-test-csv-btn" onclick="msLoadTestCsv()">Preview test</button>
        <span id="ms-upload-msg" class="hint" style="margin-left:10px;"></span>
    </form>
    <p class="hint" style="margin-top:10px;">The table is stored only when every row is error-free. Rows with warnings are kept (they build, but a row without FTP credentials won't deploy). Fix any errors and re-upload.</p>
    <p class="hint" style="margin-top:4px;"><strong>Preview test</strong> skips all of that — it stores one single placeholder row (no real business data, no FTP, no <code>landing_cities</code>) so you can jump straight to "4. Generate sites" and use its "view" link. One city only, on purpose: an earlier version paired the placeholder with a second, unrelated city as its landing city, and the result made no sense to look at (a Dallas business "serving" a city 150 miles away, with no link to it anywhere on the site). Overwrites whatever target list is currently stored — use <strong>Download CSV</strong> any time to see exactly what's stored, whether from Preview test or a real upload.</p>
</div>

<!-- ===== TARGET LIST (per-row editor) ===== -->
<div class="card" id="ms-rows-card">
    <h3 style="margin-top:0;">Target list — edit rows directly</h3>
    <p class="hint">Add, edit, or remove one domain at a time without re-uploading the whole CSV — a single-row edit here can never drop another row the way a full re-upload can. A <strong>LIVE</strong> row is locked: use <strong>Correct &amp; Regenerate</strong> to fix its data and push the fix live in one guided step. A live row can't be deleted from here — that goes through unclaim/teardown in the Infra console.</p>
    <div class="hint" style="margin-top:6px;background:#f8fafc;border:1px solid #cbd5e1;border-radius:8px;padding:10px 14px;">
        <strong style="color:#1e3a5f;">Order of operations, every batch — city/registrar/box spread and the Cloudflare fingerprint check happen from here, not from Batch Detailed:</strong>
        <ol style="margin:8px 0 0;padding-left:20px;line-height:1.7;">
            <li>Order the rows in your spreadsheet (geo spacing + state de-clustering), then upload the CSV above.</li>
            <li>Check <strong>Strict positional box/Cloudflare account mode</strong> below is ON. Easy to miss — if it's off, everything the next two steps fill in is ignored later by Create Host, which goes back to picking boxes at random instead.</li>
            <li>Press <strong>Auto-fill Box / CF Acct</strong>.</li>
            <li>Press <strong>Auto-fill Registrar</strong>.</li>
            <li>Press <strong>Check Distribution</strong>.</li>
            <li>If step 5 found state hotspots: press <strong>Fix State Clustering</strong>, then run <strong>Check Distribution</strong> again to confirm clean.</li>
            <li>Press <strong>Buy Domains</strong> — it checks registrar funding automatically right before firing and warns you in the confirm dialog if anything looks short.</li>
        </ol>
        <p style="margin:10px 0 0;">
            <strong>Separate, not gated by the above:</strong> Get/Set/Release/Resync CTM Phone Numbers and Insert/verify area code, further down this card — these are about phone tracking numbers, not domains, and can be done anytime, before or after Buy Domains.
        </p>
        <p style="margin:8px 0 0;">Full written checklist: <a href="docs.php?doc=multisite#ms-box-distribution" target="_blank">Box/registrar distribution &amp; fingerprint check &rarr;</a></p>
    </div>
    <div class="hint" style="margin-top:8px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
        <button type="button" class="btn" id="ms-box-autofill-btn" onclick="msBoxAutofill()">Auto-fill Box / CF Acct (strict positional)</button>
        <a href="#" class="hint" style="cursor:pointer;font-size:1.05rem;text-decoration:none;" onclick="bpShowInfo(this);return false;" data-info="Sets every row's Box to 1-20 repeating, in list order, and its CF Acct to the matching Cloudflare account for that round (0-3). Overwrites whatever was already in those two columns, including manual edits — confirmed before it runs.\n\nRows past position 80 get a Box but no CF Acct: only 4 Cloudflare accounts are bound to each box today, so there's no 5th slot to assign automatically. Use the Row 81+ CF Acct field to reuse one of the 4 for those rows instead of leaving them blank, or leave it blank and decide by hand later.\n\nStep 3 of the order of operations above — do this before Auto-fill Registrar, since registrar depends on Box already being set.">&#9432;</a>
        <label style="display:flex;align-items:center;gap:5px;cursor:pointer;">
            Row 81+ CF Acct:
            <input type="number" id="ms-box-overflow" min="0" max="3" placeholder="—" style="width:48px;padding:3px 5px;">
        </label>
        <a href="#" class="hint" style="cursor:pointer;font-size:1.05rem;text-decoration:none;" onclick="bpShowInfo(this);return false;" data-info="Only matters for rows past position 80 (only on a batch bigger than 80 rows). Leave blank to leave those rows' CF Acct blank — the normal 'first open account' pick still applies for them later, at zone-creation time.\n\nType 0-3 to instead reuse one specific Cloudflare account (the one labelled .0 through .3) for every row past position 80. That account then simply carries a bit more zone volume than its siblings on the same box — it does not put two boxes on one account, so it never breaks the one-account-per-box isolation.">&#9432;</a>
        <label style="display:flex;align-items:center;gap:5px;cursor:pointer;">
            <input type="checkbox" id="ms-strict-box-mode"<?= $msBoxSettings['strict_mode'] ? ' checked' : '' ?> onchange="msSaveStrictBoxMode(this.checked)">
            Strict positional box/Cloudflare account mode
        </label>
        <a href="#" class="hint" style="cursor:pointer;font-size:1.05rem;text-decoration:none;" onclick="bpShowInfo(this);return false;" data-info="Create Host uses each row's own box and cf_account columns instead of picking them itself.\n\nON (the default for a new batch): Create Host reads Box and CF Acct straight from each row's own columns — whatever Auto-fill Box / CF Acct wrote, or whatever you've hand-edited since.\n\nOFF: Create Host ignores both columns entirely and goes back to its own randomized, registrar-balanced round-robin picker instead.\n\nStep 2 of the order of operations above — check this BEFORE pressing Auto-fill, since it decides whether that button's work actually gets used later.">&#9432;</a>
        <button type="button" class="btn" id="ms-registrar-autofill-btn" onclick="msRegistrarAutofill()">Auto-fill Registrar (box-aware)</button>
        <a href="#" class="hint" style="cursor:pointer;font-size:1.05rem;text-decoration:none;" onclick="bpShowInfo(this);return false;" data-info="Sets every row's Registrar from a formula over (box, round, this batch's own sequence number) — not random, not a manual rotation to track. The batch's sequence number is assigned automatically when the batch is created, so running this on any future batch just works, with nothing to remember or type.\n\nOverwrites any manual registrar edits already made. Needs Box / CF Acct filled in first (step 3) — rows with no box assigned are skipped and reported, not guessed at.\n\nBuilt to fix a real, confirmed problem: a simpler per-batch rotation offset still let every box see only 3 of the 6 registrars, identically, in every batch. This formula instead varies which ONE registrar a box misses, differently every batch.">&#9432;</a>
        <button type="button" class="btn" id="ms-box-check-btn" onclick="msBoxDistributionCheck()">Check Distribution</button>
        <a href="#" class="hint" style="cursor:pointer;font-size:1.05rem;text-decoration:none;" onclick="bpShowInfo(this);return false;" data-info="Read-only — changes nothing. Reports three things about this batch's current Box/CF Acct/Registrar/State columns:\n\n1. Box and CF Acct totals (should already be perfectly even if Auto-fill was used and nothing's been hand-edited since).\n\n2. Registrar and state CONCENTRATION — a box carrying notably more of one registrar or state than an even spread would predict.\n\n3. Registrar ABSENCE — a box with ZERO domains from a registrar this batch otherwise uses plenty of elsewhere. The sharper, more meaningful version of the concentration check, since the registrar pool is only 6 wide.\n\nRun this after Auto-fill (steps 3-4), and again after Fix State Clustering if you use it, before Buy Domains.">&#9432;</a>
        <button type="button" class="btn" id="ms-fix-state-btn" onclick="msFixStateClustering()">Fix State Clustering</button>
        <a href="#" class="hint" style="cursor:pointer;font-size:1.05rem;text-decoration:none;" onclick="bpShowInfo(this);return false;" data-info="Only does something if Check Distribution found a state hotspot. A state can't be reassigned the way registrar can — it's tied to the row's real city/business identity — so the only fix is swapping which row sits at which position.\n\nSwaps full row content (domain, business, city, state — everything about the row's identity) between an over-represented row and a safe donor elsewhere. Box, CF Acct and Registrar are deliberately left untouched: they belong to the POSITION, not the row, and the position isn't moving.\n\nRun Check Distribution again afterward to confirm. Only makes sense before domains are bought and before any CTM numbers or FTP credentials are assigned to these rows.">&#9432;</a>
        <span id="ms-box-autofill-msg" class="hint"></span>
    </div>
    <div id="ms-box-check-out" style="display:none;margin-top:10px;"></div>
    <?php ms_ctm_card_start('ctm', 'Get CTM Phone Numbers'); ?>
        <span class="hint" style="margin-left:6px;">Isolated capability — not part of the site factory. For each checked row: real area code from its city/state → search &amp; buy a CallTrackingMetrics number → label it in CTM → write the number into Phone.</span>
        <div class="hint" style="margin-top:6px;">
            The CTM dashboard label isn't just the bare domain — it's
            <code>NICHE-B&lt;batch&gt;-&lt;row&gt;-SS-City-domain.com</code>, e.g.
            <code>WAT-B26-002-CA-Glendale-ellisonrestoration.com</code>, so a sub-account holding numbers for several
            batches or niches at once can still be told apart — and placed — at a glance.
            <strong>NICHE</strong> is this site's own first 3 letters (<code>water-site</code> → <code>WAT</code>).
            <strong>B&lt;batch&gt;</strong> is this batch's real sequence number — the same "batch 26"-style number used
            to talk about it, not the internal <code>b5</code>-style id. <strong>&lt;row&gt;</strong> is this domain's
            position in the target list above (the same <strong>#</strong> column), zero-padded to 3 digits so the
            labels still sort correctly in CTM past row 9. <strong>SS</strong> and <strong>City</strong> are this
            row's own State/City fields (City with its spaces stripped, e.g. "Sioux Falls" → "SiouxFalls").
        </div>
        <div style="margin-top:8px;">
            <input type="text" id="ms-ctm-account" placeholder="CTM sub-account ID" value="151459" style="width:160px;">
            <button type="button" class="btn btn-primary" id="ms-ctm-btn" onclick="msCtmGetNumbers()">Get CTM Phone Numbers (<span id="ms-ctm-count">0</span> selected)</button>
            <span class="hint" style="margin-left:8px;">Note: can be kicked off in parallel across multiple batches.</span>
            <a href="#" class="hint" style="cursor:pointer;font-size:1.05rem;text-decoration:none;margin-left:2px;" onclick="bpShowInfo(this);return false;" data-info="Safe to run in several batches at the same time — nothing here is shared, racy state on our side.\n\nEach batch's job lock and params.csv live under that batch's own directory, so two batches buying numbers at once never block each other or write over each other's rows.\n\nThe one thing they share is the CTM sub-account itself — but the actual purchase (ctm_buy_number) is a single real API call per number, and CTM's own purchase endpoint is the arbiter if two batches ever searched up the exact same candidate number at the exact same instant (extremely unlikely across different domains/cities): one buy succeeds, the other comes back as a normal per-row purchase error, same as any other sold-out/unavailable number — never a silent double-buy or a corrupted row.\n\nThis one spends real money regardless of how many batches you run — parallelism doesn't add cost or risk, it just gets the same total spend done sooner.">&#9432;</a>
            <span id="ms-ctm-msg" class="hint" style="margin-left:8px;"></span>
            <div class="hint" style="margin-top:4px;width:100%;">
                Area code already typed in that row? Uses it. Blank? Looks it up. Would have to guess? Does nothing — never buys on a guess.
            </div>
        </div>
    <?php ms_ctm_card_end('ctm'); ?>
    <?php ms_ctm_card_start('route', 'Set Call Routing'); ?>
        <span class="hint" style="margin-left:6px;">Where each checked row's EXISTING tracking number forwards to — a destination phone, not a new tracking number. Never touches the generated site, so this works on already-live domains with no rebuild. Replaces whatever that row routed to before (never two destinations ringing at once).</span>
        <div style="margin-top:8px;">
            <input type="text" id="ms-route-account" placeholder="CTM sub-account ID" value="151459" style="width:160px;">
            <input type="text" id="ms-route-dest" placeholder="Destination phone, e.g. (555) 123-4567" style="width:200px;">
            <button type="button" class="btn btn-primary" id="ms-route-btn" onclick="msCtmSetRouting()">Set Call Routing (<span id="ms-route-count">0</span> selected)</button>
            <span id="ms-route-msg" class="hint" style="margin-left:8px;"></span>
            <div class="hint" style="margin-top:4px;width:100%;">
                Uses the same checkboxes as Get CTM Phone Numbers above. A row with no tracking number yet still saves the destination — it applies automatically the moment that row's number gets bought.
            </div>
        </div>
    <?php ms_ctm_card_end('route'); ?>
    <?php ms_ctm_card_start('release', 'Release CTM Numbers'); ?>
        <span class="hint" style="margin-left:6px;">The inverse of Get CTM Phone Numbers — for each checked row: find its tracking number in CTM by its Phone on file, release it back to the sub-account's pool, then clear Phone and Area Code here. Use for rows that got a number ahead of when they actually needed one (e.g. overflow rows added before their real go-live).</span>
        <div style="margin-top:8px;">
            <input type="text" id="ms-release-account" placeholder="CTM sub-account ID" value="151459" style="width:160px;">
            <button type="button" class="btn btn-danger" id="ms-release-btn" onclick="msCtmReleaseNumbers()">Release CTM Numbers (<span id="ms-release-count">0</span> selected)</button>
            <span id="ms-release-msg" class="hint" style="margin-left:8px;"></span>
            <div class="hint" style="margin-top:4px;width:100%;">
                Uses the same checkboxes as Get CTM Phone Numbers above. Refuses a LIVE row — Phone is site content, and clearing it here never triggers a rebuild. A row with no phone on file is skipped as nothing to release, not an error you need to clear first.
            </div>
        </div>
    <?php ms_ctm_card_end('release'); ?>
    <?php ms_ctm_card_start('resync', 'Resync from CTM'); ?>
        <span class="hint" style="margin-left:6px;">For a number bought by hand directly in CTM (not through Get CTM Phone Numbers) — paste its Phone into the row first, then check it here. Looks up the real number behind that Phone and brings CTM's dashboard label (its "description"), this row's Area Code, and call routing back into agreement with it. Also how a stale or wrong-account Phone gets caught.</span>
        <div style="margin-top:8px;">
            <input type="text" id="ms-resync-account" placeholder="CTM sub-account ID" value="151459" style="width:160px;">
            <button type="button" class="btn btn-primary" id="ms-resync-btn" onclick="msCtmResyncNumbers()">Resync from CTM (<span id="ms-resync-count">0</span> selected)</button>
            <span id="ms-resync-msg" class="hint" style="margin-left:8px;"></span>
            <div class="hint" style="margin-top:4px;width:100%;">
                Never touches Phone itself, so there's no live/not-live distinction — same behavior either way. The most common case: domain NOT live yet, you bought the number directly in CTM and pasted it into Phone yourself, and you just want CTM's label (its "description" in the CTM dashboard) and this row's Area Code to actually match what you bought, without re-buying anything. Uses the same checkboxes as Get CTM Phone Numbers above. A row with a destination on file always gets routing re-applied to whatever number is in Phone now, replacing anything stale.
            </div>
        </div>
    <?php ms_ctm_card_end('resync'); ?>
    <?php ms_ctm_card_start('areacode', 'Insert/verify primary area code selected'); ?>
        <a href="#" class="hint" style="cursor:pointer;font-size:1.05rem;text-decoration:none;" onclick="bpShowInfo(this);return false;" data-info="Real, billed check (Claude with real web search) — for each checked row, finds the true PRIMARY area code for that city, not a newer overlay.\n\nBLANK on file, not live: the primary code is WRITTEN onto the row — the only thing this does to params.csv, and only that one field.\n\nBLANK on file, LIVE row: only suggested, never written — a live row's data changes only through Correct &amp; Regenerate.\n\nSomething already on file (right or wrong): read-only either way. A wrong code is reported, never auto-changed — a human decides what to do with an existing number, same as every other CTM action here.\n\nA city already checked once, by ANY batch or niche, is never re-billed — fleet-wide cache in admin/infra/state/area_code_checks.json.">&#9432;</a>
        <span class="hint" style="margin-left:6px;">Uses the same checkboxes as Get CTM Phone Numbers above. Real API cost (a few cents) per NEW city only — already-checked cities are free.</span>
        <div style="margin-top:8px;">
            <button type="button" class="btn btn-primary" id="ms-areacode-btn" onclick="msAreaCodeCheck()">Insert/verify primary area code selected (<span id="ms-areacode-count">0</span> selected)</button>
            <span class="hint" style="margin-left:8px;">Note: can be kicked off in parallel across multiple batches.</span>
            <a href="#" class="hint" style="cursor:pointer;font-size:1.05rem;text-decoration:none;margin-left:2px;" onclick="bpShowInfo(this);return false;" data-info="Safe to run in several batches at the same time — nothing here is shared, racy state.\n\nEach batch's job lock and params.csv live under that batch's own directory, so two batches running this at once never block each other or write over each other's rows.\n\nThe one thing they DO share — the fleet-wide area-code cache (admin/infra/state/area_code_checks.json) — is read-modify-write under its own lock with an atomic tmp-file rename, built specifically so two batches checking the same city at the same time can't race and silently drop each other's result.\n\nBonus: since the cache is keyed by city (not batch or niche), if two of the batches you kick off happen to share a city, only the first one to finish pays the real AI/web-search cost for it — the other gets it free from cache.">&#9432;</a>
            <span id="ms-areacode-msg" class="hint" style="margin-left:8px;"></span>
        </div>
    <?php ms_ctm_card_end('areacode'); ?>
    <?php ms_ctm_card_start('buy', 'Buy Domains'); ?>
        <span class="hint" style="margin-left:6px;">For each checked row: register the real domain already set on that row. By default each row buys through its OWN <code>registrar</code> column value (fill that in ahead of time, e.g. a pre-planned spread across registrars) — the dropdown is only an OVERRIDE, forcing every checked row through one registrar regardless of its own column. Uses the exact same purchase path as the Infra console's own single-domain Buy — real availability re-check right before buying (a stale "available" flag is never trusted), real money, not a preview.</span>
        <div style="margin-top:8px;">
            <?php if (!$msBuyRegistrars): ?>
                <span class="hint" style="color:#991b1b;">No registrar is both buy-wired and has saved credentials — add one on the Infra console's Servers/Registrars tab first.</span>
            <?php else: ?>
                <select id="ms-buy-registrar" style="width:260px;">
                    <option value="">— use each row's own registrar —</option>
                    <?php foreach ($msBuyRegistrars as $r): ?>
                        <option value="<?= h($r) ?>">Override: all checked → <?= h(infra_registrar_type_def($r)['label'] ?? $r) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="button" class="btn btn-primary" id="ms-buy-btn" onclick="msDomainBuy()">Buy Domains (<span id="ms-buy-count">0</span> selected)</button>
                <a href="#" class="hint" style="cursor:pointer;font-size:1.05rem;text-decoration:none;" onclick="bpShowInfo(this);return false;" data-info="Real money, not a preview. Last step of the order of operations above — do this after Auto-fill Box / CF Acct, Auto-fill Registrar, and Check Distribution (plus Fix State Clustering if that found anything).\n\nBefore it fires: resolves which registrar each checked row will actually use (the override dropdown if one's picked, else that row's own Registrar column), tallies how many land on each, and checks each one's REAL current balance against a conservative flat estimate. Shows a warning right in the confirm dialog if any registrar looks short on funds, or if its balance can't be checked at all (3 of the 6 registrars have no balance API).\n\nThen: real availability re-check per domain right before buying — a stale 'available' flag from the naming step is never trusted. A row with no domain yet, or no registrar assigned and no override selected, is skipped and reported, never guessed at. A domain this console already owns is skipped, not re-bought.">&#9432;</a>
            <?php endif; ?>
            <span id="ms-buy-msg" class="hint" style="margin-left:8px;"></span>
            <div class="hint" style="margin-top:4px;width:100%;">
                Uses the same checkboxes as Get CTM Phone Numbers above. A row with no domain yet is nothing to buy — add one first. A row with no registrar assigned and no override selected is skipped and reported, not guessed at. A domain this console already owns is skipped, not re-bought.
            </div>
        </div>
    <?php ms_ctm_card_end('buy'); ?>
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:6px;">
        <div class="hint">Select:
            <a href="#" onclick="msCtmQuickSelect('nonumber');return false;">no phone number yet</a> &middot;
            <a href="#" onclick="msCtmQuickSelect('noroute');return false;">has number, no routing</a> &middot;
            <a href="#" onclick="msCtmQuickSelect('nodomain');return false;">no domain yet</a> &middot;
            <a href="#" onclick="msCtmQuickSelect('live');return false;">live</a> &middot;
            <a href="#" onclick="msCtmQuickSelect('notlive');return false;">not live</a> &middot;
            <a href="#" onclick="msCtmQuickSelect('none');return false;">none</a>
            &middot; rows
            <input type="number" id="ms-ctm-range-start" min="1" placeholder="start" style="width:58px;padding:2px 4px">
            &ndash;
            <input type="number" id="ms-ctm-range-end" min="1" placeholder="end" style="width:58px;padding:2px 4px">
            <input type="checkbox" id="ms-ctm-range-tick" onchange="msCtmRangeTick(this)" title="Tick to select rows numbered start&ndash;end (the # column); untick to deselect them" style="width:20px;height:20px;cursor:pointer;vertical-align:middle;">
            &nbsp; <strong id="ms-ctm-selbar-count">0</strong> selected
            <span id="ms-ctm-selbar-msg" class="hint" style="margin-left:8px;"></span>
        </div>
    </div>
    <div style="overflow-x:auto;">
        <table id="ms-rows-table" style="width:100%;">
            <thead><tr>
                <th style="width:34px;">#</th>
                <th style="width:26px;"><input type="checkbox" id="ms-ctm-select-all" onclick="msCtmToggleAll(this)" style="width:20px;height:20px;cursor:pointer"></th>
                <th style="width:90px;">Status</th><th>Domain</th><th style="width:100px;">Registrar</th>
                <th style="width:40px;" title="Registered at a registrar yet — Go Live can't switch nameservers on a domain nobody owns">Bought</th>
                <th style="width:50px;">Box</th><th style="width:60px;">CF Acct</th>
                <th>Business</th><th style="width:80px;">Area Code</th><th>Phone</th><th style="width:120px;">Routes to</th><th>City</th><th style="width:110px;">State</th><th style="width:50px;">SS</th><th style="width:190px;">Actions</th>
            </tr></thead>
            <tbody><tr><td colspan="16" class="hint">Loading…</td></tr></tbody>
        </table>
    </div>
    <div style="margin-top:14px;border-top:1px solid #cbd5e1;padding-top:12px;">
        <button type="button" class="btn" id="ms-row-add-toggle" onclick="msRowAddToggle()">+ Add 1 Domain</button>
        <button type="button" class="btn" id="ms-row-addn-toggle" onclick="msRowAddNStart()" style="margin-left:6px;">+ Add X Domains</button>
        <div id="ms-row-add-form" style="display:none;margin-top:10px;">
            <input type="text" id="ms-add-domain" placeholder="domain.com" style="width:160px;">
            <input type="text" id="ms-add-business" placeholder="Business name" style="width:160px;">
            <input type="text" id="ms-add-phone" placeholder="Phone" style="width:120px;">
            <input type="text" id="ms-add-city" placeholder="City" style="width:120px;">
            <input type="text" id="ms-add-state" placeholder="State (full name)" style="width:140px;">
            <input type="text" id="ms-add-ss" placeholder="SS" maxlength="2" style="width:40px;">
            <button type="button" class="btn btn-primary" onclick="msRowAddSave()">Save new row</button>
            <span id="ms-add-msg" class="hint" style="margin-left:8px;"></span>
        </div>
    </div>
</div>

<!-- ===== ADD X OLDEST DOMAINS ===== -->
<dialog id="ms-addn-dialog" style="max-width:640px;width:92%;border:1px solid #cbd5e1;border-radius:12px;padding:0;">
    <div style="padding:18px 22px;">
        <h3 style="margin:0 0 4px;">Add oldest domains</h3>
        <p class="hint" style="margin:0 0 12px;">
            Owned, unclaimed domains for this niche, oldest-purchased first. Uncheck any you don't want —
            only the checked ones get added when you accept.
        </p>
        <div id="ms-addn-msg" class="hint"></div>
        <div id="ms-addn-list" style="max-height:360px;overflow-y:auto;margin:10px 0;"></div>
        <div style="display:flex;align-items:center;gap:12px;border-top:1px solid #cbd5e1;padding-top:12px;">
            <span id="ms-addn-count" class="hint" style="font-weight:600;">0 selected</span>
            <button type="button" class="btn btn-primary" id="ms-addn-accept" onclick="msRowAddNAccept()">Accept</button>
            <button type="button" class="btn" onclick="document.getElementById('ms-addn-dialog').close()">Cancel</button>
        </div>
    </div>
</dialog>

<!-- ===== RESULTS CARD ===== -->
<div class="card" id="ms-results-card" style="display:none;">
    <h3 style="margin-top:0;">Validation</h3>
    <div style="margin-bottom:12px;">
        <button type="button" class="btn" id="ms-pf-btn" onclick="msPreflight()">Pre-flight FTP</button>
        <span id="ms-pf-msg" class="hint" style="margin-left:10px;"></span>
    </div>
    <div id="ms-summary" style="margin-bottom:12px;"></div>
    <div id="ms-unknown" class="hint" style="margin-bottom:12px;"></div>
    <div style="overflow-x:auto;">
        <table id="ms-table" style="width:100%;">
            <thead><tr>
                <th style="width:44px;">#</th><th style="width:70px;">Status</th>
                <th>Domain</th><th>Business</th><th>City</th>
                <th style="width:72px;">Box</th><th>Issues</th>
            </tr></thead>
            <tbody></tbody>
        </table>
    </div>
</div>

<!-- ===== TITLE PREVIEW CARD ===== -->
<div class="card" id="ms-output-card">
    <h3 style="margin-top:0;">Title preview &mdash; what your sites will publish</h3>
    <p class="hint">Each page's title comes from its own SEO panel in the master (using <code>{primary_keyword}</code>, <code>{city}</code>, <code>{SS}</code>, <code>{business}</code> shortcodes). Below is exactly how they resolve for a sample city on a cloned site &mdash; nothing is generated behind the scenes. To change a title, edit that page's SEO on the master.</p>
    <div id="ms-titles-preview"><p class="hint">Loading&hellip;</p></div>
</div>

<?php include __DIR__ . '/_batch_servers.php'; ?>

<?php include __DIR__ . '/_batch_hosts.php'; ?>

<?php include __DIR__ . '/_batch_host_one.php'; ?>

<!-- ===== RUN CARD ===== -->
<div class="card" id="ms-run-card">
    <h3 style="margin-top:0;">4. Generate sites
        <button type="button" onclick="document.getElementById('ms-agreement').showModal()"
                style="background:#4c1d95;color:#fff;border:0;border-radius:6px;padding:4px 13px;font-weight:600;font-size:.76rem;cursor:pointer;margin-left:10px;vertical-align:middle;">&#129309; Scott &amp; Claude agreement</button>
    </h3>

    <!--
        The rules this card is built to. DELIBERATELY RULES ONLY — no counts, no "what's built"
        status. The card already reads that live from the master and from disk; restating it
        here would give it a second copy to drift from. Rules don't go stale, figures do.
    -->
    <dialog id="ms-agreement" style="max-width:780px;border:1px solid #cbd5e1;border-radius:12px;padding:0;">
        <div style="padding:20px 24px;">
            <h3 style="margin:0 0 3px;color:#4c1d95;">&#129309; Scott &amp; Claude agreement</h3>
            <p class="hint" style="margin:0 0 16px;">
                How this card works, agreed between us. Living document &mdash; when we change our
                minds, this changes with it. If the card and this disagree, one of them is a bug.
            </p>

            <div style="display:grid;gap:14px;font-size:.86rem;line-height:1.5;">

                <div>
                    <strong style="color:#1e3a5f;">What this card is</strong>
                    <p style="margin:3px 0 0;color:#334155;">
                        A <strong>control panel</strong> &mdash; it decides what a run actually does.
                        It is not a status board and not a scope document.
                    </p>
                </div>

                <div>
                    <strong style="color:#1e3a5f;">A tick mark is a promise that it does something</strong>
                    <ul style="margin:4px 0 0 18px;padding:0;color:#334155;">
                        <li><strong>Has a checkbox</strong> &rarr; you can turn it on or off, and it genuinely runs or doesn't.</li>
                        <li><strong>No checkbox, &#10003;</strong> &rarr; not separable. It happens whenever its step runs.</li>
                        <li><strong>No checkbox, &#9888;</strong> &rarr; built, but nothing switched on behind it yet.</li>
                        <li><strong>No checkbox, &#128679;</strong> &rarr; not built. Nothing to switch.</li>
                    </ul>
                    <p style="margin:4px 0 0;color:#334155;">
                        A checkbox that changes nothing is worse than no checkbox, so we don't put one
                        there just to show an item exists.
                    </p>
                </div>

                <div>
                    <strong style="color:#1e3a5f;">Parent and child</strong>
                    <p style="margin:3px 0 0;color:#334155;">
                        Top-level step off &rarr; everything under it is off and greyed. Back on &rarr;
                        each child returns to <em>what you had set</em>, not all-on &mdash; toggling a
                        parent must never quietly re-enable something you deliberately turned off.
                    </p>
                </div>

                <div>
                    <strong style="color:#1e3a5f;">Only wire a switch where the code has a real seam</strong>
                    <p style="margin:3px 0 0;color:#334155;">
                        Some pieces sit inside a function that does several things in one pass.
                        Those get a switch when one is genuinely wanted &mdash; one at a time, not ten
                        speculative seams cut into the runner at once.
                    </p>
                </div>

                <div>
                    <strong style="color:#1e3a5f;">Status is read, never hand-maintained</strong>
                    <p style="margin:3px 0 0;color:#334155;">
                        Anything that can be counted &mdash; options in a pool, whether a feature exists
                        &mdash; is read from the master's own data or from disk, so it can't go stale.
                        That is why this agreement holds rules and no figures.
                    </p>
                </div>

                <div>
                    <strong style="color:#1e3a5f;">The three sections</strong>
                    <p style="margin:3px 0 0;color:#334155;">
                        <strong>1 Content</strong> &mdash; what the words say ·
                        <strong>2 Template footprint</strong> &mdash; how the site is built ·
                        <strong>3 Identity &amp; setup</strong> &mdash; who the business is, plus what the run builds.
                        Grouped by what makes a site separate, not by the order the runner happens to work in.
                    </p>
                </div>

                <div style="border-left:3px solid #166534;background:#f0fdf4;padding:9px 12px;border-radius:0 7px 7px 0;">
                    <strong style="color:#166534;">Objective 1 is a gate, not a goal</strong>
                    <p style="margin:3px 0 0;color:#334155;">
                        Primary keyword in the H1, the title and meta patterns, the schema types, the page
                        set. No variance is allowed to move any of it. A change either provably leaves it
                        untouched, or it doesn't ship.
                    </p>
                </div>

                <div>
                    <strong style="color:#1e3a5f;">Objectives 2 and 3, and the honest difference</strong>
                    <p style="margin:3px 0 0;color:#334155;">
                        <strong>2 · Template footprint uniqueness</strong> stops the sites being matched to
                        each other as one operator's network. It is <em>not</em> what makes Google treat a
                        site as original.<br>
                        <strong>3 · Content uniqueness</strong> is what decides that, and it comes after 2
                        by your call &mdash; named here so it can't quietly disappear.
                    </p>
                </div>

                <div>
                    <strong style="color:#1e3a5f;">What this card does not cover</strong>
                    <p style="margin:3px 0 0;color:#334155;">
                        Hosting, nameservers, registrar and launch timing live in the Infrastructure
                        console. Off-page &mdash; links, citations, Google Business Profile &mdash; isn't
                        owned anywhere yet, and outweighs everything on this card.
                    </p>
                </div>
            </div>

            <div style="margin-top:18px;text-align:right;">
                <button type="button" onclick="this.closest('dialog').close()"
                        style="background:#4c1d95;color:#fff;border:0;border-radius:6px;padding:8px 18px;font-weight:600;cursor:pointer;">Close</button>
            </div>
        </div>
    </dialog>

    <?php if (!empty($researchOn)): ?>
    <!--
        Nested here rather than a standalone card — Generate already runs this
        automatically first, for every city it needs (see the AI content note in the
        step list below). This action exists only to preview the data, or top it up,
        before committing to a full run.
    -->
    <details style="background:#f8fafc;border:1px solid #cbd5e1;border-radius:8px;margin-bottom:16px;" id="ms-research-card">
        <summary style="cursor:pointer;padding:10px 16px;font-size:.86rem;font-weight:600;color:#1e3a5f;">&#128269; Research cities (local market data)</summary>
        <div style="padding:2px 16px 14px;">
            <p class="hint" style="margin:0 0 6px;"><strong>Three things this does, in order:</strong></p>
            <ol class="hint" style="margin:0 0 10px 18px;padding:0;">
                <li>Seeds <code>cities.json</code> with every city in this batch's target list.</li>
                <li>Geocodes any city missing lat/lng, from OpenStreetMap — free, and runs regardless of niche.</li>
                <li>Looks up real local facts per city with Claude — industries, employers, neighborhoods, population, plus whatever charts, plugins, and this niche's own custom research fields ask for (see the <a href="index.php?tab=niche_brief">Niche Brief &amp; Research</a> tab).</li>
            </ol>
            <p class="hint" style="margin:0 0 10px;"><strong>Generate sites below already runs all three automatically first</strong> — use this button only to preview the data, or top it up, ahead of a full run.</p>
            <p class="hint" style="margin:0 0 10px;">By default, step 3 only spends money where something's actually missing: a city already fully researched is skipped; a city missing just one field (say, you just added a new custom research field) is asked for only that field, not re-researched from scratch; a field the model has come back empty on twice is left alone for good rather than re-billed forever. The whole step — including the free geocoding — only skips outright when a run turns off both AI content and Images.</p>
            <p class="hint" style="margin:0 0 10px;"><strong>Force</strong> ignores all of that and re-asks every declared field, for every matched city, treating the answer as a fresh record rather than a top-up — use it after rewriting the research prompt, or when facts on file are believed stale or wrong. It still won't accept a figure with no source, or let a shorter re-ask shrink a list that's already longer; force only bypasses the "don't bother, this is already fine" decision, not the checks on what comes back. Tick <strong>Dry run</strong> together with it to preview a full forced pass with no API cost.</p>
            <div style="display:flex;gap:18px;flex-wrap:wrap;align-items:center;">
                <label class="hint" style="display:flex;align-items:center;gap:6px;cursor:pointer;"><input type="checkbox" id="ms-research-dry" style="width:auto;"> Dry run (preview only, no API)</label>
                <label class="hint" style="display:flex;align-items:center;gap:6px;cursor:pointer;"><input type="checkbox" id="ms-research-force" style="width:auto;"> Force (re-research every city, ignore what's already on file)</label>
                <button type="button" class="btn btn-primary" id="ms-research-btn" onclick="msResearch()">Research cities</button>
                <span title="Background job — safe to navigate away or close this tab, it keeps running on the server." style="display:inline-block;background:#1e3a8a;color:#fff;font-weight:800;font-size:.68rem;line-height:1;border-radius:3px;padding:2px 5px;cursor:help;vertical-align:middle;">B</span>
                <span id="ms-research-state" class="ms-state" style="font-size:.82rem;font-weight:600;margin-left:8px;color:#94a3b8;"></span>
                <button type="button" class="btn" id="ms-rr-btn" onclick="msResearchReport()" style="background:#1e3a5f;color:#fff;" title="Free and instant. Reads what is already on file and writes nothing.">Check completeness</button>
                <span id="ms-rr-state" class="ms-state" style="font-size:.82rem;font-weight:600;margin-left:8px;color:#94a3b8;"></span>
            </div>
            <!-- Sits with the thing it describes: both buttons here are about the
                 research data, and this one is read-only - no API key, no cost. -->
            <p class="hint" style="margin:8px 0 0;"><strong>Check completeness</strong> is free and instant &mdash; it reads what is on file and writes nothing. The <em>city research</em> line in the table above counts a city only when it has BOTH neighbourhoods and population; this breaks that down field by field, and splits every gap into <em>retryable</em> (the model has not given up, so another research pass will likely fill it) and <em>settled</em> (declined twice and deliberately abandoned, so a pass changes nothing and costs money).</p>
            <pre id="ms-rr-out" style="display:none;margin-top:12px;background:#0f172a;color:#e2e8f0;padding:12px;border-radius:6px;font-size:0.8rem;max-height:420px;overflow:auto;white-space:pre-wrap;"></pre>
            <!-- Interruption note. A 91-city pass is ~70 min of sequential API calls, so
                 "what happens if this dies halfway" is the first thing anyone asks. Kept
                 next to the button rather than buried in the prose above it. -->
            <p class="hint" style="margin:10px 0 0;padding:10px 12px;background:#fffbeb;border:1px solid #fde68a;border-radius:6px;line-height:1.55;">
                <strong>If this stops partway through, just click &ldquo;Research cities&rdquo; again.</strong>
                Progress is written to <code>cities.json</code> every 10 cities, and each finished city is
                marked done — so a restart resumes from the last save instead of starting over. You lose at
                most the handful of cities since that save; nothing already on file is re-billed, re-asked
                or overwritten.<br>
                <strong>Closing this tab won&rsquo;t stop it.</strong> The job runs on the server and this
                panel just reads its output file — reopen the tab to pick the view back up.<br>
                <strong>A quiet box does not mean it has stalled.</strong> Output is buffered and
                arrives in roughly 8KB blocks, so this can sit unchanged for minutes and then jump several
                pages at once. Low CPU is normal too — research waits on the API rather than computing, and
                a single city can sit up to 2 minutes before it gives up and retries. Judge silence over
                <strong>10 minutes</strong>, not 3.<br>
                <strong>Unlike Generate sites, this panel cannot tell you it died.</strong> It only knows the
                job finished by a marker written after the last city, so a job that was killed never writes
                one and this output just sits there looking unfinished. Judge it by whether new lines are
                still appearing, not by the absence of a finish.<br>
                <strong>And a &ldquo;Research is already running&rdquo; refusal is not proof it is alive.</strong>
                Anything that wrote to its output within the last hour counts as running, so if it died
                mid-pass this button can stay blocked for up to an hour afterwards. That is only a delay, not
                lost work — the cities saved up to the last checkpoint are on file, and the run you start
                later skips them. If you need it sooner, a full <strong>Generate sites</strong> run does the
                same research and is gated on a live process check instead.
            </p>
            <p class="hint" style="margin:6px 0 0;">Research already runs on its own, automatically, every time you generate sites — this button is only a manual trigger to run that same check now. With neither box ticked, it decides on its own, city by city and field by field, what actually needs asking — it never blindly redoes work that's already correct, so clicking it again later is free if nothing's missing. Tick <strong>Dry run</strong> to preview with no API cost, or <strong>Force</strong> to override that self-deciding behavior and redo every city from scratch.</p>
            <pre id="ms-research-out" style="display:none;margin-top:14px;background:#0f172a;color:#e2e8f0;padding:12px;border-radius:6px;font-size:0.8rem;max-height:340px;overflow:auto;white-space:pre-wrap;"></pre>
        </div>
    </details>
    <?php endif; ?>

    <!-- The steps come FIRST: what a run does is decided before how fast it goes. -->
    <div style="background:#f8fafc;border:1px solid #cbd5e1;border-radius:8px;padding:14px 16px;margin-bottom:16px;">
        <strong style="font-size:.9rem;color:#1e3a5f;">What this run does</strong>
        <p class="hint" style="margin:4px 0 10px;">
            Untick a step to skip it for this run. Clone, identity and build are not listed
            because they cannot be skipped &mdash; without them a run makes no site, or fifty
            copies of the master.
            &nbsp;<a href="#" onclick="msSetAllSteps(true);return false;">Select all</a>
            &nbsp;&middot;&nbsp;
            <a href="#" onclick="msSetAllSteps(false);return false;">Unselect all</a>
        </p>
        <?php
        /**
         * Two-level step list. Top level = the run's real skippable steps; each opens to the
         * pieces inside it, so the whole scope of "what a generated site gets" is visible in
         * one place, built or not.
         *
         * ONLY the top-level inputs carry class="ms-step-opt" — the run builds its skip list
         * from every unticked one of those, so a sub-item sharing the class would silently
         * ship as a skip value. Sub-items are inert markers, and the ones that aren't built
         * are disabled as well.
         *
         * A TICK MARK IS A PROMISE THAT IT DOES SOMETHING. Three kinds of sub-item, and the
         * difference is deliberate:
         *   'control' — has its own checkbox and its own skip key. Untick it and that piece
         *               genuinely does not run. Only items with a real seam in the code get
         *               one; a decorative checkbox that changes nothing is worse than no
         *               checkbox at all.
         *   'auto'    — no checkbox. It simply happens when the parent step runs, because it
         *               isn't separable. Shown so the step's contents are visible.
         *   'todo'    — no checkbox. Not built, so there is nothing to switch.
         * Anything shown as running was checked against the master's own data, not assumed.
         */
        /**
         * Grouped by WHAT MAKES A SITE SEPARATE, not by the order the runner happens to do
         * things — the sections are the same A/B/C facets as the "Why these axes?" explainer
         * and uploads/downloads/site-network-five-facets.md, so the card and the write-up can't
         * describe the same work in two different vocabularies.
         */
        // Real numbers for THIS master, not generic copy — same reasoning as every other
        // card here ("checked against the master's own data, not assumed"). Degrades to a
        // plain not-set-up line when a master hasn't adopted page pooling yet.
        $ppCfg   = isset($masterId) ? ms_page_pool_config(BASE_DIR . '/sites/' . $masterId . '/data/keyword_map.json') : null;
        $ppNote  = 'Fewer pages per site instead of every site shipping the master\'s full list — cuts doorway-page risk and stops the fleet looking like identical clones. Which pages are pinned, rotating, or skipped (and how many a site can land on) is set in the <strong>Keywords</strong> tab. <button type="button" onclick="document.getElementById(\'ms-pagepool-why\').showModal()" style="background:#1e3a5f;color:#fff;border:0;border-radius:6px;padding:2px 10px;font-weight:600;font-size:.74rem;cursor:pointer;">How this works &rarr;</button>';
        if ($ppCfg !== null) {
            $ppPinned = count($ppCfg['pinned']);
            $ppRotate = count($ppCfg['rotate']);
            $ppSkip   = count($ppCfg['skip']);
            $ppCounts = implode(' / ', $ppCfg['counts']);
            $ppSubs = [
                ["<strong>{$ppPinned}</strong> page(s) pinned — always included on every site", 'auto'],
                ["<strong>{$ppRotate}</strong> page(s) in the rotation pool — remaining slots filled from these, picked by a hash of the domain", 'auto'],
                ["<strong>{$ppSkip}</strong> page(s) skipped — never built for anyone", 'auto'],
                ["Each site lands on <strong>{$ppCounts}</strong> pages total", 'auto'],
                ["A domain's selection locks in on its first build — later changes to these numbers never move a page under an already-built site", 'auto'],
            ];
        } else {
            $ppSubs = [
                ['Not set up for this master yet — no page_pool.enabled flag in its keyword_map.json', 'todo'],
            ];
        }
        // Same "real numbers for THIS master" rule as page pool above. Cost shown up
        // front rather than discovered on a bill: each locked slot is one real paid
        // call PER DOMAIN the first time it's built, free on every rebuild after.
        // Split into the same four buckets ms_generate_ai_images_for_domain() gates on
        // (ms_image_ai_slot_category()) — hero vs. other, home vs. service page — so
        // each of the four checkboxes below shows an accurate count for exactly what
        // IT controls.
        $aiImgMasterDir = isset($masterId) ? BASE_DIR . '/sites/' . $masterId : '';
        $aiImgPrompts   = $aiImgMasterDir !== '' ? ms_image_ai_prompts_load($aiImgMasterDir) : [];
        $aiImgCounts = ['hero_home' => 0, 'hero_landing' => 0, 'other_home' => 0, 'other_landing' => 0];
        foreach ($aiImgPrompts as $aiImgSlotKey => $aiImgEntry) {
            $aiImgBlockType = is_array($aiImgEntry) ? (string) ($aiImgEntry['block_type'] ?? '') : '';
            $aiImgParts = picdrop_parse_key($aiImgSlotKey);
            if ($aiImgParts === null) continue;
            $aiImgCounts[ms_image_ai_slot_category($aiImgParts, $aiImgBlockType)]++;
        }
        $aiImgSpecs = [
            'hero_home'     => ['AI photo per domain — hero (home)',
                                 'no hero prompts approved yet; go to Pic Drop, generate + confirm an AI photo for the homepage hero, and it appears here automatically'],
            'hero_landing'  => ['AI photo per domain — hero (service pages)',
                                 'no service-page hero prompts approved yet; go to Pic Drop, generate + confirm an AI photo for a service page\'s hero, and it appears here automatically'],
            'other_home'    => ['AI photo per domain — other images (home)',
                                 'no other homepage prompts approved yet; go to Pic Drop, generate + confirm an AI photo for another homepage slot, and it appears here automatically'],
            'other_landing' => ['AI photo per domain — other images (service pages)',
                                 'no other service-page prompts approved yet; go to Pic Drop, generate + confirm an AI photo for another service-page slot, and it appears here automatically'],
        ];
        $aiImgLabels = []; $aiImgModes = [];
        foreach ($aiImgSpecs as $aiImgCat => [$aiImgTitle, $aiImgEmptyNote]) {
            $aiImgN = $aiImgCounts[$aiImgCat];
            $aiImgLabels[$aiImgCat] = $aiImgN > 0
                ? "{$aiImgTitle} — <strong>{$aiImgN}</strong> slot(s) with an approved prompt from Pic Drop, ~\$0.04 each the first time a domain is built, free on every rebuild after"
                : "{$aiImgTitle} — {$aiImgEmptyNote}";
            $aiImgModes[$aiImgCat] = $aiImgN > 0 ? 'control' : 'todo';
        }

        $msTree = [
            ['section' => '1 &middot; Content', 'facet' => 'What the words say &mdash; the facet that actually costs rankings',
             'groups' => [
                ['key' => 'ai', 'label' => 'AI content', 'status' => 'live',
                 'note' => 'Not a toggle here — <strong>Generate sites now runs research itself, automatically, first</strong>, for every city this run needs. Every item below has its own checkpoint deciding whether it actually re-runs or reuses what\'s already on file — <strong>none of them re-does work that\'s already correct</strong>. You can still open "Research cities" above to preview the data, or top it up, ahead of a run.',
                 'subs' => [
                    ['Research — per-city facts: industries, employers, market blurb, foundation/climate note, population, plus this niche\'s own chart figures (flood years, rainfall, etc.) and any custom research fields this niche declares directly &mdash; <em>checkpoint: skipped per FIELD, not per city — an already-researched city is only re-asked for whatever field is still missing or too short; a field the model can\'t find twice is left alone rather than re-billed forever</em>', 'auto'],
                    ['Neighborhood names — asked for in the same research call, with an explicit "only names you\'re sure are real, never invent" instruction &mdash; <em>checkpoint: same per-field top-up/decline rule as Research above</em>', 'auto'],
                    ['Neighborhood name verification against OpenStreetMap, plus a "what actually is this place" Claude check for anything OSM doesn\'t have &mdash; runs automatically after every research pass &mdash; <em>checkpoint: a city already verified is skipped for free on every later run</em>', 'auto'],
                    ['City Spotlight — one AI-written city profile, reused on every page for that city &mdash; <em>checkpoint: per-city cache in cities.json; a locked/inherited copy from the master can no longer leak into a different city\'s page (fixed this session)</em>', 'auto'],
                    ['City photo — one photo per city from Wikimedia; no AI cost, a lookup+cache &mdash; <em>checkpoint: per-city cache, free after the first fetch</em>', 'auto'],
                    ['Content scrub — runs right before the two items below, every time, no exceptions: clears any of the master\'s own content this clone must not inherit as-is (a locked block, the master\'s own City Spotlight) &mdash; <em>checkpoint: none — this is what enforces the checkpoints above are actually checking THIS city\'s data, not leftovers from the master\'s</em>', 'auto'],
                    ['Landing-page blocks — 7 AI block types, now including <code>steps_local</code> — replaced word-for-word-identical process-step text with real per-city copy, and along the way corrected a live guardrail violation (static text was claiming to directly employ technicians on a referral-network niche) &mdash; <em>checkpoint: skipped while locked (unless Force); reused free from the per-domain cache when the fully-resolved prompt — already carrying city/business/keyword — hashes the same as last time</em>', 'auto'],
                    ['Homepage blocks — 3 AI block types (intro, body copy, service-area statement), same per-domain cache/checkpoint as landing pages &mdash; <em>fixed a real bug where the homepage\'s <code>{keyword}</code> token wasn\'t resolving at all</em>', 'auto'],
                    ['About Us — company story &mdash; <em>same two checkpoints as landing-page blocks above</em>', 'auto'],
                    ['Privacy Policy, Terms &amp; Conditions, Contact Us — opening paragraph reworded per domain, one time; the legal meaning and contact details are never changed, only the phrasing &mdash; <em>checkpoint: stamped <code>_legal_reworded</code> after one attempt, success or failure, so it\'s never re-billed on a rebuild</em>', 'control', 'ai.legal_reword'],
                    ['Footer disclaimer — reworded per domain, one time; same legal meaning, only the phrasing changes &mdash; <em>checkpoint: stamped <code>_disclaimer_reworded</code> after one attempt, success or failure, cached per domain so it\'s never re-billed on a routine rebuild</em>', 'control', 'ai.disclaimer_reword'],
                    ['Header info-popup disclosure — the "How Your Calls Are Handled" text shown when a visitor clicks the info icon next to the phone number, reworded per domain, one time; same legal meaning, only the phrasing changes &mdash; <em>checkpoint: stamped <code>_popup_reworded</code> after one attempt, success or failure, cached per domain so it\'s never re-billed on a routine rebuild</em>', 'control', 'ai.popup_reword'],
                    ['Footer logo tagline — reworded per domain, same theme (understand the problem, connect to the right help), different phrasing &mdash; <em>checkpoint: cached per domain like the disclaimer, but Force skips the cache — checking Force on a domain is what gets it a fresh variation; a routine rebuild reuses the last one for free</em>', 'control', 'ai.tagline_reword'],
                    ['Blog posts — 3 informational posts per domain, randomly picked (per-domain, stable across rebuilds) from a 25-topic pool per niche and written fresh by AI; a post only auto-publishes if it passes an automated quality check (word count, target-keyword cap, banned overclaim phrases) &mdash; otherwise it saves as a draft and gets logged instead of shipping &mdash; <em>checkpoint: picked topics and generated posts are cached per domain, so a rebuild tops up toward 3 instead of re-picking or re-billing</em>. Each post\'s <code>&lt;title&gt;</code> now carries the business name too (<code>{post title} | {business_short}</code>) &mdash; previously missing entirely, so the title fell back to the bare editorial headline with no brand in it.', 'control', 'ai.blog'],
                    ['Boilerplate blocks (service cards, CTA, trust bar) — identical fleet-wide', 'todo'],
                 ]],
             ]],
            ['section' => '2 &middot; Template footprint', 'facet' => 'How the site is built &mdash; stops the sites being matched to each other',
             'groups' => [
                ['key' => 'visual', 'label' => 'Colours &amp; fonts', 'status' => 'live',
                 'note' => 'Three SEPARATE axes, each picked by a hash of the domain so a rebuild reproduces it exactly. Independent on purpose: 10 palettes &times; 6 fonts = <strong>60 combinations</strong>, where a font baked into each palette would give 10 with four fonts repeating. Jitter then makes each one mechanically unique. Edit palettes and fonts in <strong>Gen-Visual &rarr; Visual Identity / Font Library</strong>.',
                 'subs' => [
                    ['Colour palette — 10 in rotation, picked per domain', 'control', 'visual.palette'],
                    ['Font — 6 in rotation, picked per domain independently of the palette', 'control', 'visual.font'],
                    ['Palette jitter — every colour nudged a few points per domain, so no two sites share a hex. Contrast-gated: a colour that would drop below WCAG AA reverts', 'control', 'visual.jitter'],
                 ]],
                // key=null (like the SEO gate row below), not 'visual': this card's own
                // sub-items are 'auto'/'off', not 'control' — there's nothing of ITS OWN
                // for a checkbox here to toggle. It used to share the literal key
                // 'visual' with the "Colours & fonts" card above, so unticking either one
                // pushed skip=visual and silently skipped BOTH — including palette/font/
                // jitter, which this card's own checkbox never claimed to touch.
                ['key' => null, 'label' => 'Logo &amp; favicon', 'status' => 'live',
                 'subs' => [
                    ['Logo wordmark, drawn per site', 'auto'],
                    ['Favicon from the preset\'s icon — no preset has one yet', 'off'],
                 ]],
                ['key' => 'structure', 'label' => 'Site structure variance', 'status' => 'live',
                 'note' => 'Section order: each site CAN get a different arrangement of the same sections, computed fresh from the page\'s own current blocks and picked by a hash of its domain, so it never changes on a rebuild. "Don\'t rotate top/bottom" pins that many leading/trailing blocks in place and only shuffles what\'s between them. <strong>Default as of 2026-10-17 is OFF (20/20)</strong> — every page keeps its natural, as-authored block order. This was a deliberate call, not an oversight: block order is the thinnest layer in the fleet-differentiation stack (it changes presentation, not content/images/which-pages-exist, so its footprint benefit is marginal) while a random shuffle has a real small cost — chance can bury a trust signal or sequence two similar sections back to back on any given domain. Lower the pin counts here if you want rotation back for a specific batch; a saved value here always overrides the default. Privacy, Terms, disclaimer and Contact Us are never reordered here regardless of these toggles — their wording already varies per domain (see "AI content" &rarr; Privacy/Terms/Contact Us reword, above), and their opening-paragraph + merged-body structure has too few movable sections for a second ordering to add anything.',
                 'subs' => [
                    ['Section order — Home', 'control', 'structure.home',
                        ['top_field' => 'rot_home_top', 'bottom_field' => 'rot_home_bottom',
                         'top_default' => $msRotSettings['home_top'], 'bottom_default' => $msRotSettings['home_bottom']]],
                    ['Section order — Landing pages', 'control', 'structure.landing',
                        ['top_field' => 'rot_landing_top', 'bottom_field' => 'rot_landing_bottom',
                         'top_default' => $msRotSettings['landing_top'], 'bottom_default' => $msRotSettings['landing_bottom']]],
                    ['Class vocabulary — same layout, different class names', 'control', 'structure.classvocab'],
                    ['Schema shape — same facts, different JSON-LD field order', 'control', 'structure.schemashape'],
                    // HTML nesting was built, measured and DELIBERATELY DROPPED — not left
                    // undone. A tag swap on block wrappers moved the tag skeleton 100% -> 91.6%
                    // between two sites, on the weakest of the signals, after class vocabulary
                    // and schema shape had already taken shared class names to 6.9% and shared
                    // JSON-LD to 6.6%. Not worth a sixth transform touching every built page.
                    // Listing it as 🚧 would read as work still owed.
                 ]],
                ['key' => 'images', 'label' => 'Images', 'status' => 'live',
                 'note' => 'The graphics are drawn from each city\'s own researched figures, so they cannot be shared between sites the way a stock photo can — a 1&ndash;2% crop does not stop Google matching the same photograph, but a chart of a different city\'s rainfall is a different picture. <strong>A city with no researched figures gets no chart</strong>, never one drawn from estimates.',
                 'subs' => [
                    ['Per-city photo differentiation', 'auto'],
                    ['Unique filename per site', 'auto'],
                    ['Hero image text stamp — Home (keyword + city, state)', 'control', 'images.stamp_home'],
                    ['Hero image text stamp — Landing pages (keyword + city, state)', 'control', 'images.stamp_landing'],
                    ['Metadata strip — EXIF, XMP, GPS', 'control', 'images.metadata'],
                    ['Area map — the city and the surrounding towns it serves, any niche', 'auto'],
                    ['Data charts from the research figures — 8 water restoration &middot; 7 pest &middot; 7 mold &middot; 4 appliance', 'auto'],
                    ['Caption under each graphic, phrasing varied per domain', 'auto'],
                    ['Chart rotation — which chart a page gets is picked per domain from its topic group', 'auto'],
                    $aiImgModes['hero_home'] === 'control' ? [$aiImgLabels['hero_home'], 'control', 'images.ai_photos_hero_home'] : [$aiImgLabels['hero_home'], 'todo'],
                    $aiImgModes['hero_landing'] === 'control' ? [$aiImgLabels['hero_landing'], 'control', 'images.ai_photos_hero_landing'] : [$aiImgLabels['hero_landing'], 'todo'],
                    $aiImgModes['other_home'] === 'control' ? [$aiImgLabels['other_home'], 'control', 'images.ai_photos_other_home'] : [$aiImgLabels['other_home'], 'todo'],
                    $aiImgModes['other_landing'] === 'control' ? [$aiImgLabels['other_landing'], 'control', 'images.ai_photos_other_landing'] : [$aiImgLabels['other_landing'], 'todo'],
                 ]],
             ]],
            ['section' => '3 &middot; Identity &amp; setup', 'facet' => 'Who the business is &mdash; already solved &mdash; plus what the run builds',
             'groups' => [
                ['key' => 'tags', 'label' => 'Site tags', 'status' => 'live',
                 'subs' => [
                    ['Analytics tag from analytics_id — never shared between sites', 'auto'],
                    ['Search Console tag from gsc_verification', 'auto'],
                 ]],
                ['key' => 'landing', 'label' => 'Landing pages', 'status' => 'live',
                 'note' => 'Not variance — this is what makes the site exist, not what makes it different.',
                 'subs' => [
                    ['One page per city in the landing_cities column', 'auto'],
                    ['Built from the master\'s landing templates', 'auto'],
                    ['Reuses the master\'s city research, not re-fetched per domain', 'auto'],
                    ['Page title — primary keyword FIRST, every page: <code>{Keyword} in {city_state} | {business_short}</code>, same pattern on the homepage and every landing page, inherited from the master\'s stored <code>seo_title</code> and resolved per domain at render time &mdash; <em>checked post-build by the SEO gate\'s "title leads with keyword" check (section 4, below)</em>', 'auto'],
                    ['Slug, H1, menu/footer/related-links label, Service schema (<code>name</code>/<code>serviceType</code>), and meta description/keywords all stay aligned with the one master-stored primary keyword &mdash; a single shared field, not several separately-authored copies that can quietly drift apart from each other over time', 'auto'],
                    ['301 redirect when a slug changes &mdash; only fires for a master that carries its own <code>slug_redirects.json</code> (today: pest-template\'s 5 renamed pages, water-site\'s 5, and mold-site\'s 1, none for appliance); resolved per domain against that domain\'s own real landing cities and written straight into its <code>.htaccess</code> at build time, same way every other <code>{city_slug}</code> shortcode resolves', 'auto'],
                 ]],
                ['key' => 'pagepool', 'label' => 'Page pool', 'status' => 'live',
                 'note' => $ppNote,
                 'subs' => $ppSubs],
             ]],
            // Not a peer of 1-3. Those are categories of what makes a site separate; this is
            // the constraint over all of them, and it runs last because it checks their output.
            // No checkbox on the group either — a gate you can switch off isn't a gate, same
            // reasoning as the identity scrub.
            ['section' => '4 &middot; SEO gate', 'facet' => 'Checks that 1&ndash;3 didn\'t break anything &mdash; objective 1, and it is a gate, not a goal',
             'groups' => [
                ['key' => null, 'label' => 'SEO gate', 'status' => 'live',
                 'note' => 'Runs after the build, before upload. Always on — a gate you can turn off is not a gate. <strong>Reports but does not stop a row yet</strong>: it warns in the run log until it has seen enough real batches to prove it never fires on a good page.',
                 'subs' => [
                    ['Every page keeps its primary keyword in the H1', 'auto'],
                    ['One H1 per page', 'auto'],
                    ['Titles and meta descriptions match the master', 'auto'],
                    ['Title leads with the primary keyword, not just contains it', 'auto'],
                    ['Schema types unchanged', 'auto'],
                    ['Same set of pages as the master', 'auto'],
                    ['Canonical points at this site, not the master', 'auto'],
                 ]],
             ]],
        ];
        $msPill = [
            'live' => ['&#10003;', '#166534', '#dcfce7', 'running today'],
            'off'  => ['&#9888;',  '#92400e', '#fef3c7', 'built &mdash; switched off'],
            'todo' => ['&#128679;', '#64748b', '#f1f5f9', 'not built yet'],
        ];
        ?>
        <p class="hint" style="margin:0 0 8px;">Every checkbox below is saved for this batch the moment you change it
            <span id="ms-opts-msg" style="font-weight:700;transition:opacity .3s;margin-left:6px;"></span></p>
        <?php foreach ($msTree as $msSecIdx => $msSec): $msSecN = $msSecIdx + 1; ?>
        <div style="margin-bottom:12px;">
            <div style="display:flex;align-items:baseline;gap:9px;margin-bottom:5px;">
                <strong style="font-size:.83rem;color:#1e3a5f;"><?= $msSec['section'] ?></strong>
                <span class="hint" style="color:#94a3b8;"><?= $msSec['facet'] ?></span>
            </div>
            <div style="display:flex;flex-direction:column;gap:5px;">
            <?php foreach ($msSec['groups'] as $msGrpIdx => $msT):
                // A coordinate, not a sequence — "2.4.1" means something stable (section 2,
                // group 4, lever 1) you can reference in conversation or a ticket, not just
                // "the 3rd item down" which shifts the moment a row is added above it.
                $msGrpN = $msSecN . '.' . ($msGrpIdx + 1);
                [$msIcon, $msFg, $msBg, $msWord] = $msPill[$msT['status']]; ?>
                <details style="background:#fff;border:1px solid #cbd5e1;border-radius:7px;">
                    <summary style="cursor:pointer;padding:7px 11px;display:flex;align-items:center;gap:9px;font-size:.86rem;">
                        <?php if ($msT['key'] !== null): ?>
                            <!-- onclick stops the tick from also opening/closing the row -->
                            <input type="checkbox" class="ms-step-opt" value="<?= $msT['key'] ?>"
                                   <?= ($msBatchOptions['steps'][$msT['key']] ?? true) ? 'checked' : '' ?>
                                   onclick="event.stopPropagation()">
                        <?php else: ?>
                            <input type="checkbox" disabled onclick="event.stopPropagation()">
                        <?php endif; ?>
                        <span class="hint" style="font-family:monospace;color:#94a3b8;"><?= $msGrpN ?></span>
                        <span style="font-weight:600;color:<?= $msT['status'] === 'live' ? '#1e3a5f' : '#64748b' ?>;"><?= $msT['label'] ?></span>
                        <span style="background:<?= $msBg ?>;color:<?= $msFg ?>;border-radius:4px;padding:1px 8px;font-size:.7rem;font-weight:700;white-space:nowrap;"><?= $msIcon ?> <?= $msWord ?></span>
                        <span class="hint" style="margin-left:auto;color:#cbd5e1;font-size:.74rem;"><?= count($msT['subs']) ?> items</span>
                    </summary>
                    <div style="padding:2px 11px 10px 34px;">
                        <?php if (!empty($msT['note'])): ?>
                            <p class="hint" style="margin:0 0 6px;color:#94a3b8;"><?= $msT['note'] ?></p>
                        <?php endif; ?>
                        <?php $msSubN = 0; foreach ($msT['subs'] as $msSub):
                            $msLabel   = $msSub[0];
                            $msMode    = $msSub[1];
                            $msKey     = $msSub[2] ?? null;
                            $msRot     = $msSub[3] ?? null;
                            $msChecked = $msMode === 'control' ? ($msBatchOptions['subs'][$msKey] ?? true) : true;
                            // Numbered whether or not the row has a lever — a status tick needs
                            // its own coordinate just as much as a checkbox does, so any row here
                            // can be pointed at ("what's 2.4.3?") in conversation.
                            $msSubN++; $msSubCoord = $msGrpN . '.' . $msSubN;
                            if ($msMode === 'control'): ?>
                                <!-- A real switch: its own skip key, collected by msRun(). Its checked state
                                     also auto-saves per BATCH on change (see msSaveBatchOptions() below) —
                                     purely what it's pre-checked to next load, never what a live run does. -->
                                <label class="hint" style="display:flex;align-items:flex-start;gap:7px;padding:1px 0;color:#334155;">
                                    <input type="checkbox" class="ms-sub-opt" value="<?= htmlspecialchars($msKey, ENT_QUOTES) ?>"
                                           data-parent="<?= htmlspecialchars($msT['key'] ?? '', ENT_QUOTES) ?>"
                                           <?= $msChecked ? 'checked' : '' ?>>
                                    <span style="font-family:monospace;color:#cbd5e1;"><?= $msSubCoord ?></span>
                                    <span><?= $msLabel ?></span>
                                </label>
                                <?php if ($msRot): ?>
                                <!-- Rotation pin counts for this scope — read by msRun() alongside the skip list.
                                     Saved per BATCH on change (rotation_settings_save.php), so a value you set
                                     survives a reload instead of resetting to the 20/20 default. -->
                                <div class="hint" style="display:flex;align-items:center;flex-wrap:wrap;gap:14px;padding:2px 0 6px 22px;">
                                    <label style="display:flex;align-items:center;gap:5px;">Don't rotate top
                                        <input type="number" min="0" max="20" step="1" class="ms-rot-opt"
                                               id="ms-<?= htmlspecialchars($msRot['top_field'], ENT_QUOTES) ?>"
                                               data-field="<?= htmlspecialchars($msRot['top_field'], ENT_QUOTES) ?>"
                                               value="<?= (int) $msRot['top_default'] ?>" style="width:56px;"
                                               onchange="msSaveRotation(this)">
                                    </label>
                                    <label style="display:flex;align-items:center;gap:5px;">Don't rotate bottom
                                        <input type="number" min="0" max="20" step="1" class="ms-rot-opt"
                                               id="ms-<?= htmlspecialchars($msRot['bottom_field'], ENT_QUOTES) ?>"
                                               data-field="<?= htmlspecialchars($msRot['bottom_field'], ENT_QUOTES) ?>"
                                               value="<?= (int) $msRot['bottom_default'] ?>" style="width:56px;"
                                               onchange="msSaveRotation(this)">
                                    </label>
                                    <span class="ms-rot-msg" style="font-weight:700;transition:opacity .3s;"></span>
                                    <!-- How to switch section order on/off with these two numbers. Mirrors
                                         layout_rotate_blocks(): it returns the natural order when top + bottom
                                         >= the page's block count, and layout_generate_variants_for_list() has
                                         nothing to swap with fewer than 2 movable blocks — so "covers all but one"
                                         is the exact off condition. 20 is the input's max and covers any page up
                                         to 21 blocks (water: landing pages have 13, the home page 16). -->
                                    <span style="flex:1;min-width:220px;max-width:460px;color:#64748b;line-height:1.45;">
                                        <strong>On:</strong> 1 / 1 (default) &mdash; the first and last sections stay put and a couple of neighbouring sections in between swap, one fixed order per domain.
                                        <strong>Off:</strong> set <em>Don&rsquo;t rotate top</em> to <strong>20</strong> &mdash; once top + bottom covers all but one section, nothing can move and every site keeps the page&rsquo;s natural order. Unticking the box above turns it off too.
                                    </span>
                                </div>
                                <?php endif; ?>
                            <?php elseif ($msMode === 'todo'): ?>
                                <!-- Not built: nothing to switch, so no checkbox. -->
                                <div class="hint" style="display:flex;align-items:flex-start;gap:7px;padding:1px 0 1px 22px;color:#94a3b8;">
                                    <span>&#128679;</span><span style="font-family:monospace;color:#cbd5e1;"><?= $msSubCoord ?></span><span><?= $msLabel ?></span>
                                </div>
                            <?php elseif ($msMode === 'off'): ?>
                                <!-- Built, but nothing switched on behind it — must not read as
                                     running, which a green tick would. -->
                                <div class="hint" style="display:flex;align-items:flex-start;gap:7px;padding:1px 0 1px 22px;color:#92400e;">
                                    <span>&#9888;</span><span style="font-family:monospace;color:#cbd5e1;"><?= $msSubCoord ?></span><span><?= $msLabel ?></span>
                                </div>
                            <?php else: ?>
                                <!-- Happens whenever the parent step runs; not separable, so no
                                     checkbox — an unclickable tick would only look like a control. -->
                                <div class="hint" style="display:flex;align-items:flex-start;gap:7px;padding:1px 0 1px 22px;color:#334155;">
                                    <span style="color:#166534;">&#10003;</span><span style="font-family:monospace;color:#cbd5e1;"><?= $msSubCoord ?></span><span><?= $msLabel ?></span>
                                </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </details>
            <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>

        <!-- Page pool explainer — same native <dialog> pattern as "Why these axes?" above,
             costs nothing until opened. -->
        <dialog id="ms-pagepool-why" style="max-width:720px;border:1px solid #cbd5e1;border-radius:12px;padding:0;">
            <div style="padding:20px 24px;">
                <h3 style="margin:0 0 4px;color:#1e3a5f;">Page pool — how this works</h3>
                <p class="hint" style="margin:0 0 14px;">
                    Reduces each site to a smaller page count instead of every site shipping the
                    same full list — cuts doorway-page risk and stops the fleet looking like
                    identical clones.
                </p>

                <div style="display:grid;gap:12px;font-size:.86rem;line-height:1.5;">
                    <div><strong style="color:#1e3a5f;">Where it's set up — the Keywords tab</strong>
                        <ul style="margin:4px 0 0 18px;padding:0;">
                            <li>Every service page already carries a value tier. Each one now also gets a
                                <strong>Pinned / Rotate / Skip</strong> setting, defaulted from that tier
                                but editable per page.</li>
                            <li><strong>Pinned</strong> — always built, on every site. <strong>Rotate</strong>
                                — eligible, but not guaranteed; this is the pool sites draw the rest of
                                their pages from. <strong>Skip</strong> — never built for anyone.</li>
                            <li>A "Pages per site" setting holds a small handful of possible totals
                                (e.g. 10 / 12 / 14) a site can land on — never one fixed number, same
                                reasoning as the colour-palette and font pools below.</li>
                        </ul></div>

                    <div><strong style="color:#1e3a5f;">What happens when a site builds</strong>
                        <ul style="margin:4px 0 0 18px;padding:0;">
                            <li>The system picks one of the possible totals for that domain, by a hash of
                                its name — random-looking across the fleet, stable on a rebuild.</li>
                            <li>Every Pinned page is included. Remaining slots are filled by randomly
                                drawing from the Rotate pool, seeded the same way.</li>
                            <li>Only those selected pages are built. Two sites share the Pinned pages but
                                differ on the rest — not an identical inventory fleet-wide.</li>
                        </ul></div>

                    <div><strong style="color:#1e3a5f;">What changes on the pages themselves</strong>
                        <ul style="margin:4px 0 0 18px;padding:0;">
                            <li>The old "Other Services" cross-link box (hardcoded to a fixed page list)
                                is removed from the templates — it would break the moment sites stop
                                having a fixed list of pages.</li>
                            <li>The top navigation menu's Services dropdown needs no new work — it
                                already drops any link to a page that wasn't built for that particular
                                site (built earlier for a different site that only builds some of its
                                pages).</li>
                        </ul></div>

                    <div><strong style="color:#92400e;">The rule that protects an already-live site</strong>
                        <ul style="margin:4px 0 0 18px;padding:0;">
                            <li>A domain's page selection is recorded on its <strong>first</strong> build
                                and reused on every rebuild after — routine maintenance never silently
                                drops or adds a page just because the pool settings changed later.</li>
                            <li>Changing which pages an already-built site carries is a deliberate,
                                separate action, never a side effect of editing the Keywords tab.</li>
                        </ul></div>
                </div>

                <div style="text-align:right;margin-top:16px;">
                    <button type="button" onclick="document.getElementById('ms-pagepool-why').close()"
                            style="background:#1e3a5f;color:#fff;border:0;border-radius:6px;padding:6px 16px;font-weight:600;font-size:.82rem;cursor:pointer;">Close</button>
                </div>
            </div>
        </dialog>

        <script>
        // Parent off -> every switch under it goes off and greys out. Parent back on ->
        // they return to what they were, rather than all springing back to ticked.
        (function () {
            function sync(parent) {
                var on = parent.checked;
                document.querySelectorAll('.ms-sub-opt[data-parent="' + parent.value + '"]').forEach(function (cb) {
                    if (!on) {
                        if (cb.dataset.was === undefined) cb.dataset.was = cb.checked ? '1' : '';
                        cb.checked = false;
                    } else if (cb.dataset.was !== undefined) {
                        cb.checked = cb.dataset.was === '1';
                        delete cb.dataset.was;
                    }
                    cb.disabled = !on;
                    cb.closest('label').style.opacity = on ? '1' : '.45';
                });
            }
            // Auto-save is wired here (not at setup time — sync(p) below runs once per parent
            // on page load and must NOT trigger a save) and defined later in this file
            // (msSaveBatchOptions, near msRun) — safe, since it's only CALLED once a user
            // actually changes something, by which point the whole page has parsed.
            document.querySelectorAll('.ms-step-opt').forEach(function (p) {
                p.addEventListener('change', function () { sync(p); window.msSaveBatchOptions(); });
                sync(p);
            });
            document.querySelectorAll('.ms-sub-opt').forEach(function (cb) {
                cb.addEventListener('change', function () { window.msSaveBatchOptions(); });
            });

            // "Select all" / "Unselect all" — sets every step AND every sub-switch, then
            // re-runs sync() per parent so the disabled/greyed state matches. Clearing
            // data-was first stops sync() from restoring an older per-sub state instead of
            // the all-on/all-off state this just set. Direct .checked assignment fires no
            // native 'change' event, so this saves once at the end itself.
            window.msSetAllSteps = function (on) {
                document.querySelectorAll('.ms-step-opt').forEach(function (p) { p.checked = on; });
                document.querySelectorAll('.ms-sub-opt').forEach(function (cb) {
                    delete cb.dataset.was;
                    cb.checked = on;
                });
                document.querySelectorAll('.ms-step-opt').forEach(function (p) { sync(p); });
                window.msSaveBatchOptions();
            };
        })();
        </script>
        <p class="hint" style="margin:8px 0 0;color:#94a3b8;">
            A tick mark means you can turn that piece on or off. Lines without one aren't
            separable &mdash; they happen whenever their step runs. &#128679; means not built yet.
        </p>
        <p class="hint" style="margin:6px 0 0;color:#94a3b8;">
            <strong>Two things deliberately absent.</strong> Hosting, nameservers, registrar and
            launch timing are handled in the Infrastructure console, not here. Off-page &mdash;
            links, citations, Google Business Profile &mdash; isn't addressed anywhere yet, and
            for local service businesses it outweighs everything on this card.
        </p>
        <p class="hint" style="margin:10px 0 0;color:#94a3b8;">
            The identity scrub always runs &mdash; it removes the master's own domain, email,
            phone and business name from every clone, so it is not an option.
        </p>
        <?php
        // Variance status, shown HERE because this is the card you're on when you care about
        // it — the full table sits above all six phase cards, which is a long way to scroll.
        // NOT a second list: it filters the run table's own items through the shared
        // ms_variance_axis_labels(), so the two cannot drift.
        require_once __DIR__ . '/../includes/multisite/steps.php';
        $vzItems = [];
        if (isset($masterId, $batchId)) {
            $vzReady = ms_step_readiness($masterId, $batchId);
            $vzLabels = ms_variance_axis_labels();
            foreach ($vzReady['differentiate']['items'] ?? [] as $vzIt) {
                if (in_array($vzIt['label'] ?? '', $vzLabels, true)) $vzItems[] = $vzIt;
            }
        }
        $vzStyle = [MS_STEP_OK => ['#166534', '#dcfce7'], MS_STEP_WARN => ['#92400e', '#fef3c7'],
                    MS_STEP_OFF => ['#64748b', '#f1f5f9']];
        ?>
        <div style="margin-top:12px;padding-top:12px;border-top:1px dashed #cbd5e1;">
            <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                <strong style="font-size:.82rem;color:#1e3a5f;">How different each site comes out</strong>
                <button type="button" onclick="document.getElementById('ms-variance-why').showModal()"
                        style="background:#1e3a5f;color:#fff;border:0;border-radius:6px;padding:3px 11px;font-weight:600;font-size:.76rem;cursor:pointer;">Why these axes? &rarr;</button>
            </div>
            <?php if (!$vzItems): ?>
                <p class="hint" style="margin:4px 0 0;">Variance status unavailable &mdash; see <strong>Differentiate</strong> in the run table above.</p>
            <?php else: ?>
            <table style="margin-top:7px;border-collapse:collapse;font-size:.8rem;">
                <?php foreach ($vzItems as $vzIt):
                    [$vzFg, $vzBg] = $vzStyle[$vzIt['state']] ?? $vzStyle[MS_STEP_OFF]; ?>
                    <tr>
                        <td style="padding:2px 12px 2px 0;"><code style="background:#f1f5f9;padding:1px 5px;border-radius:3px;color:#334155;font-size:.76rem;"><?= htmlspecialchars($vzIt['label'], ENT_QUOTES) ?></code></td>
                        <td style="padding:2px 12px 2px 0;color:#64748b;"><?= htmlspecialchars($vzIt['drives'], ENT_QUOTES) ?></td>
                        <td style="padding:2px 0;"><span style="background:<?= $vzBg ?>;color:<?= $vzFg ?>;border-radius:4px;padding:1px 8px;font-weight:700;font-size:.72rem;white-space:nowrap;"><?= htmlspecialchars($vzIt['value'], ENT_QUOTES) ?></span></td>
                    </tr>
                <?php endforeach; ?>
            </table>
            <p class="hint" style="margin:6px 0 0;color:#94a3b8;">
                Plus colours, logo and images, which already vary &mdash; full detail under
                <strong>Differentiate</strong> and <strong>Visual identity</strong> in the run table above.
            </p>
            <?php endif; ?>
        </div>

        <!-- Why the variance axes exist, and which of the five separation facets each one
             actually moves. Native <dialog> so it costs nothing until opened. The long-form
             version with the measurements is uploads/downloads/site-network-five-facets.md. -->
        <dialog id="ms-variance-why" style="max-width:760px;border:1px solid #cbd5e1;border-radius:12px;padding:0;">
            <div style="padding:20px 24px;">
                <h3 style="margin:0 0 4px;color:#1e3a5f;">What this is for &mdash; the three objectives</h3>
                <p class="hint" style="margin:0 0 12px;">
                    In priority order. Everything on this card is judged against these.
                </p>

                <div style="display:grid;gap:9px;margin-bottom:18px;">
                    <div style="border-left:3px solid #166534;background:#f0fdf4;padding:9px 12px;border-radius:0 7px 7px 0;">
                        <strong style="color:#166534;font-size:.88rem;">1 &middot; Great SEO and rankability &mdash; a GATE, not a goal</strong>
                        <div class="hint" style="margin-top:3px;color:#334155;">
                            Every page keeps its primary keyword in the H1, its title and meta pattern,
                            its schema types, and its place in the page set. This isn't something we aim
                            at &mdash; it's something no variance is allowed to break. A change either
                            provably leaves all of it untouched, or it doesn't ship.
                        </div>
                    </div>
                    <div style="border-left:3px solid #1e3a5f;background:#f8fafc;padding:9px 12px;border-radius:0 7px 7px 0;">
                        <strong style="color:#1e3a5f;font-size:.88rem;">2 &middot; Template footprint uniqueness</strong>
                        <div class="hint" style="margin-top:3px;color:#334155;">
                            So the sites <strong>can't be matched to each other as one operator's
                            network</strong> &mdash; by a competitor, a fingerprinting tool, or a manual
                            reviewer. Worth being precise: this is not what makes Google treat a site as
                            original. Google already knows they're different domains. What it judges is
                            whether the <em>content</em> duplicates something it has &mdash; which is
                            objective 3, not this one.
                        </div>
                    </div>
                    <div style="border-left:3px solid #92400e;background:#fffbeb;padding:9px 12px;border-radius:0 7px 7px 0;">
                        <strong style="color:#92400e;font-size:.88rem;">3 &middot; Content uniqueness &mdash; named now, built next</strong>
                        <div class="hint" style="margin-top:3px;color:#334155;">
                            Deliberately sequenced after objective 2, but listed here because it is the
                            one that actually decides whether Google treats these as duplicates. Concretely:
                            the ~190 words a page of boilerplate, plus Privacy, Terms and Contact, which
                            are byte-identical on every site generated today.
                        </div>
                    </div>
                </div>

                <h3 style="margin:0 0 4px;color:#1e3a5f;">Why these axes &mdash; the five facets underneath</h3>
                <p class="hint" style="margin:0 0 14px;">
                    Five separate questions, detected differently and fixed differently. The variance
                    steps on this card move <strong>B</strong>, and the tail of <strong>A</strong>.
                </p>

                <div style="display:grid;gap:12px;font-size:.86rem;line-height:1.5;">
                    <div><strong style="color:#166534;">A &middot; Are the pages the same? &mdash; content</strong>
                        <ul style="margin:4px 0 0 18px;padding:0;">
                            <li><strong>The facet that actually costs rankings.</strong> Near-duplicate text gets clustered and filtered.</li>
                            <li>Measured on water-site's 26 landing pages: <strong>87% of the words are AI-written per site</strong>, 13% static template text. Largely solved already.</li>
                            <li>Open: the ~190 words/page of boilerplate (steps, service cards, CTAs, trust bar), identical fleet-wide; plus homepage, About and the 4 legal pages, which are unmeasured.</li>
                        </ul></div>

                    <div><strong style="color:#1e3a5f;">B &middot; Are the sites built the same? &mdash; template fingerprint</strong>
                        <ul style="margin:4px 0 0 18px;padding:0;">
                            <li>Markup signature: HTML nesting, class names, section order, JSON-LD field order.</li>
                            <li><strong>Not mainly a ranking mechanism</strong> &mdash; millions of sites share a theme. It's about being <em>linkable</em>: a competitor or tool matching your sites to each other.</li>
                            <li>Today a clone differs only by colours, logo, images and identity. <strong>This card's variance steps are the fix, and they're the only thing that touches this facet.</strong></li>
                        </ul></div>

                    <div><strong style="color:#166534;">C &middot; Are they the same business? &mdash; entity signals</strong>
                        <ul style="margin:4px 0 0 18px;padding:0;">
                            <li>Name, phone, address, LocalBusiness schema, canonical, analytics ID.</li>
                            <li>Probably the strongest "one operation" signal &mdash; and <strong>already solved</strong> by the identity scrub + per-site LocalBusiness node + own analytics tag + self-canonical.</li>
                        </ul></div>

                    <div><strong style="color:#92400e;">D &middot; Same operator? &mdash; infrastructure footprint</strong>
                        <ul style="margin:4px 0 0 18px;padding:0;">
                            <li>Hosting IPs, nameservers, registrar, WHOIS, SSL, and <strong>timing</strong>. What network-detection tools lean on most.</li>
                            <li><strong>Handled in the Infrastructure console</strong> &mdash; boxes, Cloudflare zones, registrars &mdash; not on this card. Listed here only so the picture is complete.</li>
                        </ul></div>

                    <div><strong style="color:#b91c1c;">E &middot; Are they worth anything? &mdash; value &amp; off-page</strong>
                        <ul style="margin:4px 0 0 18px;padding:0;">
                            <li>Inbound links, citations, Google Business Profile, real engagement. Google's scaled-content-abuse policy judges <em>usefulness</em>, not technical diversity.</li>
                            <li><strong>The site factory does nothing here</strong>, and for local service businesses it outweighs everything above. The real gap.</li>
                        </ul></div>
                </div>

                <p class="hint" style="margin:14px 0 0;padding-top:12px;border-top:1px solid #cbd5e1;">
                    <strong>Why it matters:</strong> the variance work is insurance against being
                    <em>linked together</em>, not against being <em>filtered out</em>. Buy it cheaply.
                    "Will Google see these as different sites" is mostly decided by A, C and E &mdash;
                    and C is already won.
                    Full version with the measurements: <code>uploads/downloads/site-network-five-facets.md</code>
                    (Test Lab &rarr; Downloads).
                </p>

                <div style="margin-top:16px;text-align:right;">
                    <button type="button" onclick="this.closest('dialog').close()"
                            style="background:#1e3a5f;color:#fff;border:0;border-radius:6px;padding:8px 18px;font-weight:600;cursor:pointer;">Close</button>
                </div>
            </div>
        </dialog>

        <details style="margin-top:10px;">
            <summary class="hint" style="cursor:pointer;color:#1e3a5f;font-weight:600;">What each step actually does</summary>
            <div style="margin-top:8px;display:grid;gap:12px;">
                <div>
                    <strong style="font-size:.85rem;">Landing pages</strong>
                    <ul class="hint" style="margin:4px 0 0;padding-left:18px;">
                        <li>Generates one page per city on this domain's list, using the master's page templates</li>
                        <li>Reuses whatever city research the master's Cities/Niche tab already gathered — doesn't re-fetch it per domain</li>
                        <li>Comes from the <code>landing_cities</code> column in this batch's target list ("City, ST; City, ST")</li>
                        <li>Untick this and a domain gets only its homepage + core pages, no city-specific landers</li>
                    </ul>
                </div>
                <div>
                    <strong style="font-size:.85rem;">Visual identity</strong>
                    <ul class="hint" style="margin:4px 0 0;padding-left:18px;">
                        <li>Assigns each domain a color/font preset so batch-generated sites don't all look identical</li>
                        <li>Reads the <code>theme_preset</code> column if the row specifies one; otherwise rotates through the master's presets automatically</li>
                        <li>Presets are defined once, on the master's Theme tab — this step only applies them, never creates new ones</li>
                        <li>Also generates that domain's logo and favicon in the assigned preset's colors</li>
                    </ul>
                </div>
                <div>
                    <strong style="font-size:.85rem;">AI content</strong>
                    <ul class="hint" style="margin:4px 0 0;padding-left:18px;">
                        <li>Writes the actual page copy — headlines, service descriptions, about text — for each domain</li>
                        <li>Fills in only what's tagged AI-eligible on the master; everything else stays exactly like the master</li>
                        <li>Uses that row's business name, city, and state as the input, so every domain reads uniquely</li>
                        <li>Caches what it generates per domain, so a later rebuild doesn't re-pay for content that hasn't changed</li>
                        <li>Costs roughly $0.02&ndash;0.05 per site (free on a rebuild that hits the cache)</li>
                    </ul>
                </div>
                <div>
                    <strong style="font-size:.85rem;">Images</strong>
                    <ul class="hint" style="margin:4px 0 0;padding-left:18px;">
                        <li>Stamps each domain's city name onto its hero image so photos aren't generic</li>
                        <li>Slightly varies every other photo (renamed, bytes tweaked) so no two domains in the batch share an identical file</li>
                        <li>Fetches one real photo of the city (as part of the AI content step) to use in the site's imagery</li>
                        <li>Removes any uploaded photo the built site doesn't actually use</li>
                    </ul>
                </div>
                <div>
                    <strong style="font-size:.85rem;">Site tags (analytics, Search Console)</strong>
                    <ul class="hint" style="margin:4px 0 0;padding-left:18px;">
                        <li>Writes that row's <code>analytics_id</code> into a real tracking snippet on every page</li>
                        <li>Writes that row's <code>gsc_verification</code> token into the page <code>&lt;head&gt;</code> so Search Console can verify ownership</li>
                        <li>Untick this and BOTH are deliberately cleared, not left alone — so a clone never accidentally inherits the master's own analytics account</li>
                        <li>Comes straight from that domain's own row — nothing shared across the batch</li>
                    </ul>
                </div>
            </div>
        </details>
    </div>

    <p class="hint">Builds every valid row and keeps the result, ready to upload. Nothing goes to a
        server in this step &mdash; that is step 5. A genuinely new domain (never generated before)
        costs roughly $0.35&ndash;0.50 in AI generation, most of it landing/homepage copy plus AI photos;
        re-running a domain that's already built costs only a cent or two, since its cached copy and
        photos are reused instead of regenerated.</p>
    <p class="hint">
        <strong>Build this many</strong> caps how many rows a run touches, in target-list order &mdash;
        0 means every row that still needs building. <strong>Only this domain</strong> narrows a run to
        specific domains instead (comma-separated for more than one) &mdash; this is what a staged
        rollout actually uses: name a few domains to test, look at the result, then clear the field and
        press Generate again to pick up everything that's left. <strong>Force</strong> throws away a
        domain's cache and regenerates its AI content and photos from scratch even though it's already
        built &mdash; a real cost every time, not a free re-check, so it's for when something about that
        domain's build actually needs redoing, not routine re-runs.
    </p>
    <p class="hint" style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:10px 14px;">
        <strong>Most common use:</strong> leave Force unchecked and Build/Only at their defaults, then
        press <strong>Generate sites</strong>. It works through every row that doesn't have a build yet,
        in the target list's own order, skipping anything already built (so pressing it again after a
        partial run just picks up where it left off, at no extra cost for what's already done).
    </p>
    <div style="display:flex;gap:18px;flex-wrap:wrap;align-items:flex-end;">
        <label class="hint">Build this many (0 = all)<br><input type="number" id="ms-limit" value="0" min="0" style="width:110px;"></label>
        <label class="hint">Only this domain (optional, comma-separated for more than one)<br>
            <span style="display:flex;gap:6px;">
                <input type="text" id="ms-run-only" placeholder="example.com" style="width:200px;">
                <select id="ms-run-only-picker" style="max-width:170px;" onchange="msOnlyPick('ms-run-only', this)">
                    <option value="">Add a domain…</option>
                </select>
            </span>
        </label>
        <label class="hint"><input type="checkbox" id="ms-force"> Force (rebuild everything, refresh AI, no research refresh)</label>
        <button type="button" class="btn btn-primary" id="ms-run-btn" onclick="msRun()">Generate sites</button>
    </div>
    <!-- Interruption note. Research runs first inside a generate run and a full pass is
         ~70 min of sequential API calls, so this is where "what if it dies halfway" gets
         asked. Deliberately does NOT repeat the skip-already-built line in the blue box
         above; it covers only what that box leaves out. -->
    <p class="hint" style="margin:12px 0 0;padding:10px 14px;background:#fffbeb;border:1px solid #fde68a;border-radius:8px;line-height:1.55;">
        <strong>If a run stops partway through, just press Generate sites again.</strong>
        Domains that finished stay finished and get skipped, so you resume rather than restart.
        The city research that runs first saves its progress every 10 cities, so an interruption
        during that stage costs at most the few cities since the last save &mdash; never the whole pass.<br>
        <strong>The one exception is Force.</strong> Force exists to ignore &ldquo;already built&rdquo;, so
        restarting an interrupted Force run redoes every domain in scope from the top, at full API cost.
        If a Force run dies halfway, narrow it with <strong>Only this domain</strong> or restart it with
        Force unticked &mdash; unless you genuinely do want everything redone.<br>
        <strong>Closing this tab won&rsquo;t stop it.</strong> The run is detached on the server and this
        panel only polls its progress &mdash; reopen the tab to pick the view back up.<br>
        <strong>A quiet log does not mean it has stalled.</strong> The research stage buffers its
        output and writes it out in roughly 8KB blocks, so this run&rsquo;s log sits unchanged for
        minutes and then jumps several pages at once. Low CPU is normal too &mdash; research spends its
        time waiting on the API, not computing. A single city can also sit up to 2 minutes before it
        gives up and retries. So judge silence over <strong>10 minutes</strong>, not 3, and do not read
        a still log as a dead job.<br>
        <strong>Trust the state label instead</strong> &mdash; it is worked out from whether the
        job&rsquo;s process is actually alive, so unlike the log it cannot be fooled by buffering:
        <span style="color:#2563eb;font-weight:600;">RUNNING</span> alive and working &middot;
        <span style="color:#92400e;font-weight:600;">STALE</span> the process died &mdash; press Generate
        sites again &middot;
        <span style="color:#166534;font-weight:600;">DONE</span> finished &middot;
        <span style="color:#991b1b;font-weight:600;">FAILED</span> finished with errors.
        A job that died turns amber on its own, so you do not have to work that out. The gap is a job that
        is alive but stuck: it honestly still reads RUNNING, and the tell is the <em>x/y done</em> counter
        not moving.<br>
        <strong>Either way, pressing Generate sites again is always safe.</strong> If the run really is
        alive it just refuses with &ldquo;This batch is already running&rdquo; and starts nothing; if it is
        dead it starts a fresh one and skips whatever already built. You never have to diagnose it first.
    </p>
    <div id="ms-run-progress" style="margin-top:16px;"></div>

    <!-- Pre-flight confirmation, shown before a run actually starts — see msRun()/
         msRenderPreflight() below. Hidden until populated; built fresh every click so
         it always reflects whatever's checked in the panel at that exact moment. -->
    <div id="ms-preflight-overlay" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.55);z-index:9000;align-items:center;justify-content:center;">
        <div id="ms-preflight-box" style="background:#fff;border-radius:10px;max-width:640px;width:92%;max-height:85vh;overflow-y:auto;padding:24px 28px;box-shadow:0 20px 50px rgba(0,0,0,0.3);"></div>
    </div>

    <!--
        Test server (FTP) — one fixed, reusable deploy target OUTSIDE the fleet: a real
        host + real domain + real HTTPS you already own, so "deploy to test server" next
        to each result row can push a build there with deploy_site(), the exact same
        code "5. Upload sites" uses. Every deploy overwrites this same slot on purpose —
        it's meant to be reused across totally different sites, not kept per-site.
    -->
    <details style="background:#f8fafc;border:1px solid #cbd5e1;border-radius:8px;margin-top:24px;">
        <summary style="cursor:pointer;padding:10px 16px;font-size:.86rem;font-weight:600;color:#1e3a5f;">&#9881;&#65039; Test server (FTP)</summary>
        <div style="padding:2px 16px 14px;">
            <p class="hint" style="margin:0 0 10px;">
                A deploy target you already control — not one of the fleet boxes. "Deploy to test server"
                next to each result below pushes that build here over FTP/SFTP, overwriting whatever was
                here before. View it at the URL below, with real HTTPS, exactly like a live site.
            </p>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:8px 12px;max-width:820px;">
                <label class="hint">Host<br><input type="text" id="td-host" style="width:100%;"></label>
                <label class="hint">Protocol<br>
                    <select id="td-protocol" style="width:100%;">
                        <option value="ftp">FTP</option>
                        <option value="sftp">SFTP</option>
                    </select>
                </label>
                <label class="hint">Port (blank = default)<br><input type="text" id="td-port" style="width:100%;"></label>
                <label class="hint">Username<br><input type="text" id="td-user" style="width:100%;"></label>
                <label class="hint">Password<br><input type="password" id="td-pass" placeholder="leave blank to keep" style="width:100%;"></label>
                <label class="hint">Remote path (blank = auto-detect)<br><input type="text" id="td-path" style="width:100%;"></label>
                <label class="hint">View URL<br><input type="text" id="td-view-url" placeholder="https://preview2.example.com/" style="width:100%;"></label>
                <label class="hint" style="display:flex;align-items:center;gap:6px;margin-top:18px;">
                    <input type="checkbox" id="td-passive" checked> Passive FTP
                </label>
            </div>
            <p style="margin:10px 0 0;">
                <button type="button" class="btn btn-primary" id="td-save-btn" onclick="msTestDeploySave()">Save</button>
                <span id="td-save-msg" class="hint" style="margin-left:10px;"></span>
            </p>
        </div>
    </details>
</div>

<?php include __DIR__ . '/_batch_upload.php'; ?>

<?php include __DIR__ . '/_batch_golive.php'; ?>

<!-- ===== PARAMS VERSIONS CARD ===== -->
<div class="card" id="ms-versions-card">
    <h3 style="margin-top:0;">Saved target-list versions</h3>
    <p class="hint">The last 15 uploads to this batch are snapshotted here (FTP passwords masked on download). <strong>Restore</strong> makes a version the current table.</p>
    <div id="ms-versions"><p class="hint">Loading&hellip;</p></div>
</div>

<!-- ===== HISTORY CARD ===== -->
<div class="card" id="ms-history-card">
    <h3 style="margin-top:0;">Recent runs</h3>
    <div id="ms-runs"><p class="hint">Loading&hellip;</p></div>
</div>

<script>
window.MSB = <?= json_encode([
    'csrfToken'            => $csrfToken,
    'bpMasterId'           => $masterId ?? '',
    'bpBatchId'            => $batchId ?? '',
    // Same list + labels the Buy Domains card's registrar dropdown uses — reused in
    // batch-detailed-rows.js so a single row's registrar can be set/corrected
    // inline without re-running the fleet-wide spread assignment.
    'msBuyRegistrars'      => $msBuyRegistrars,
    'msBuyRegistrarLabels' => $msBuyRegistrarLabels,
    // Short display labels for the pre-flight confirmation only — deliberately NOT
    // the long descriptive text $msTree carries for the panel itself (that's
    // documentation, this needs to be readable in two seconds). Keyed the same as
    // skip-list values, so there's no separate mapping to keep in sync with which
    // checkbox controls what.
    'msConfirmLabels'      => [
        'ai.legal_reword'      => 'Reword legal pages (Privacy/Terms/Contact)',
        'ai.disclaimer_reword' => 'Reword footer disclaimer',
        'ai.popup_reword'      => 'Reword info-popup disclosure',
        'ai.tagline_reword'    => 'Reword footer tagline',
        'ai.blog'              => 'Blog posts',
        'visual.palette'       => 'Colour palette',
        'visual.font'          => 'Font',
        'visual.jitter'        => 'Palette jitter',
        'structure.home'       => 'Section order — Home',
        'structure.landing'    => 'Section order — Landing pages',
        'structure.classvocab' => 'Class vocabulary',
        'structure.schemashape'=> 'Schema shape',
        'images.stamp_home'    => 'Photo differentiation stamp — Home',
        'images.stamp_landing' => 'Photo differentiation stamp — Landing pages',
        'images.metadata'      => 'Image metadata stripping',
        'images.ai_photos_hero_home'     => 'AI-generated photos — hero (home)',
        'images.ai_photos_hero_landing'  => 'AI-generated photos — hero (service pages)',
        'images.ai_photos_other_home'    => 'AI-generated photos — other images (home)',
        'images.ai_photos_other_landing' => 'AI-generated photos — other images (service pages)',
    ],
]) ?>;
</script>
<!-- The bulk of Batch Detailed's JS lives in these 4 external files now, not
     inline — split out 2026-11-13 since it had grown into one 2064-line file
     covering ~6 separate concerns at once. Each reads its per-request config
     from window.MSB above. ORDER MATTERS: none of the 4 wrap themselves in an
     IIFE on purpose, so they share one global scope the same way these 4
     <script> tags would if they were still one file — upload must load first
     (declares csrfToken/bpMasterId/etc. from window.MSB), ctm must load last
     (its final lines are the page's actual kickoff calls, which need
     everything else already defined). See each file's own header comment. -->
<script src="../assets/admin/batch-detailed-upload.js"></script>
<script src="../assets/admin/batch-detailed-generate.js"></script>
<script src="../assets/admin/batch-detailed-rows.js"></script>
<script src="../assets/admin/batch-detailed-ctm.js"></script>
