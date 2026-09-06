<?php
/**
 * Batch work panels — the "doing a run" half of multisite.
 *
 * Included by admin/batch.php. Everything here operates on the batch currently open
 * (session), via multisite_api.php. Master-level setup (niche brief, theme presets,
 * icons, master health check) deliberately lives with the SITE, not here.
 *
 * Expects: $csrfToken, $researchOn, $masterId
 */
if (!isset($csrfToken)) return;
?>
<!-- ===== UPLOAD CARD ===== -->
<div class="card" id="ms-upload">
    <h3 style="margin-top:0;">1. Upload target list (CSV)</h3>
    <p class="hint">Prepare the table in Excel or Google Sheets and <strong>Save As / Export &rarr; CSV</strong>. One row per site. Required columns: <code>domain, business, phone, email, city, state, SS</code>. Optional: <code>tel, address, zip, lat, lng, rating, review_count, analytics_id, logo</code>. Add FTP credentials (<code>ftp_host, ftp_user, ftp_pass</code>) to deploy &mdash; omit them and the row still builds.</p>

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
    <p class="hint" style="margin-top:4px;"><strong>Preview test</strong> skips all of that — it stores one placeholder row (no real business data, no FTP) so you can jump straight to "4. Generate sites" and use its "view" link. Its <code>landing_cities</code> is set to whatever city the master already has real research for, so service pages actually get built too — deliberately a DIFFERENT city than the row's own, so a bug that silently reuses the master's city has something to disagree with. Overwrites whatever target list is currently stored.</p>
</div>

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
                <th style="width:52px;">FTP</th><th>Issues</th>
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

<?php if (!empty($researchOn)): ?>
<!-- ===== RESEARCH CARD ===== -->
<div class="card" id="ms-research-card">
    <h3 style="margin-top:0;">Research cities <span class="hint" style="font-weight:400;">(local market data)</span></h3>
    <p class="hint">The master's niche brief has research on. This seeds <code>cities.json</code> with every city in this batch's target list, then looks up real local facts for each new city (using the <a href="index.php?tab=niche_brief">Niche Brief</a>'s research prompt). Run it once before a batch &mdash; results persist and are reused free; already-researched cities are skipped. Do a <strong>dry run</strong> first to preview without API cost.</p>
    <div style="display:flex;gap:14px;flex-wrap:wrap;align-items:center;">
        <button type="button" class="btn" id="ms-research-dry" onclick="msResearch(true)">Dry run (no API)</button>
        <button type="button" class="btn btn-primary" id="ms-research-btn" onclick="msResearch(false)">Research cities</button>
    </div>
    <pre id="ms-research-out" style="display:none;margin-top:14px;background:#0f172a;color:#e2e8f0;padding:12px;border-radius:6px;font-size:0.8rem;max-height:340px;overflow:auto;white-space:pre-wrap;"></pre>
</div>
<?php endif; ?>

<?php include __DIR__ . '/_batch_servers.php'; ?>

<?php include __DIR__ . '/_batch_hosts.php'; ?>

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

    <!--
        Test server (FTP) — one fixed, reusable deploy target OUTSIDE the fleet: a real
        host + real domain + real HTTPS you already own, so "deploy to test server" next
        to each result row can push a build there with deploy_site(), the exact same
        code "5. Upload sites" uses. Every deploy overwrites this same slot on purpose —
        it's meant to be reused across totally different sites, not kept per-site.
    -->
    <details style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;margin-bottom:16px;">
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

    <!-- The steps come FIRST: what a run does is decided before how fast it goes. -->
    <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:14px 16px;margin-bottom:16px;">
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
        $msTree = [
            ['section' => '1 &middot; Content', 'facet' => 'What the words say &mdash; the facet that actually costs rankings',
             'groups' => [
                ['key' => 'ai', 'label' => 'AI content', 'status' => 'live',
                 'note' => 'Not a toggle here — <strong>Generate sites never runs research itself</strong>, it only reads whatever research already exists. Research is a separate action: the "Research cities" card further down this page. Runs once per city; an already-researched city is skipped and reused free. Everything below reads from what it produces.',
                 'subs' => [
                    ['Research — per-city facts (climate stats, market data, employers, chart figures) plus neighborhood-name verification', 'auto'],
                    ['Landing-page blocks — 8 AI block types', 'auto'],
                    ['About Us — company story', 'auto'],
                    ['Privacy Policy — static today, identical on every site', 'todo'],
                    ['Terms &amp; Conditions — static today, identical on every site', 'todo'],
                    ['Contact Us — static today, identical on every site', 'todo'],
                    ['Disclaimer — no such page on the master yet', 'todo'],
                    ['Boilerplate blocks (steps, service cards, CTA, trust bar) — ~190 words/page, identical fleet-wide', 'todo'],
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
                ['key' => 'visual', 'label' => 'Logo &amp; favicon', 'status' => 'live',
                 'subs' => [
                    ['Logo wordmark, drawn per site', 'auto'],
                    ['Favicon from the preset\'s icon — no preset has one yet', 'off'],
                 ]],
                ['key' => 'structure', 'label' => 'Site structure variance', 'status' => 'live',
                 'note' => 'Section order: each site gets a different arrangement of the same sections, picked by a hash of its domain so it never changes on a rebuild. The hero stays first and the last block stays last, so no H1 moves.',
                 'subs' => [
                    ['Section order — home and core pages', 'control', 'structure.home'],
                    ['Section order — Privacy and Terms', 'control', 'structure.legal'],
                    ['Section order — landing pages', 'control', 'structure.landing'],
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
                    ['Metadata strip — EXIF, XMP, GPS', 'control', 'images.metadata'],
                    ['Area map — the city and the surrounding towns it serves, any niche', 'auto'],
                    ['Data charts from the research figures — 8 water restoration &middot; 7 pest &middot; 7 mold &middot; 4 appliance', 'auto'],
                    ['Caption under each graphic, phrasing varied per domain', 'auto'],
                    ['Chart rotation — which chart a page gets is picked per domain from its topic group', 'auto'],
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
                 ]],
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
        <?php foreach ($msTree as $msSec): ?>
        <div style="margin-bottom:12px;">
            <div style="display:flex;align-items:baseline;gap:9px;margin-bottom:5px;">
                <strong style="font-size:.83rem;color:#1e3a5f;"><?= $msSec['section'] ?></strong>
                <span class="hint" style="color:#94a3b8;"><?= $msSec['facet'] ?></span>
            </div>
            <div style="display:flex;flex-direction:column;gap:5px;">
            <?php foreach ($msSec['groups'] as $msT):
                [$msIcon, $msFg, $msBg, $msWord] = $msPill[$msT['status']]; ?>
                <details style="background:#fff;border:1px solid #e2e8f0;border-radius:7px;">
                    <summary style="cursor:pointer;padding:7px 11px;display:flex;align-items:center;gap:9px;font-size:.86rem;">
                        <?php if ($msT['key'] !== null): ?>
                            <!-- onclick stops the tick from also opening/closing the row -->
                            <input type="checkbox" class="ms-step-opt" value="<?= $msT['key'] ?>" checked
                                   onclick="event.stopPropagation()">
                        <?php else: ?>
                            <input type="checkbox" disabled onclick="event.stopPropagation()">
                        <?php endif; ?>
                        <span style="font-weight:600;color:<?= $msT['status'] === 'live' ? '#1e3a5f' : '#64748b' ?>;"><?= $msT['label'] ?></span>
                        <span style="background:<?= $msBg ?>;color:<?= $msFg ?>;border-radius:4px;padding:1px 8px;font-size:.7rem;font-weight:700;white-space:nowrap;"><?= $msIcon ?> <?= $msWord ?></span>
                        <span class="hint" style="margin-left:auto;color:#cbd5e1;font-size:.74rem;"><?= count($msT['subs']) ?> items</span>
                    </summary>
                    <div style="padding:2px 11px 10px 34px;">
                        <?php if (!empty($msT['note'])): ?>
                            <p class="hint" style="margin:0 0 6px;color:#94a3b8;"><?= $msT['note'] ?></p>
                        <?php endif; ?>
                        <?php foreach ($msT['subs'] as $msSub):
                            $msLabel = $msSub[0];
                            $msMode  = $msSub[1];
                            $msKey   = $msSub[2] ?? null;
                            if ($msMode === 'control'): ?>
                                <!-- A real switch: its own skip key, collected by msRun(). -->
                                <label class="hint" style="display:flex;align-items:center;gap:7px;padding:1px 0;color:#334155;">
                                    <input type="checkbox" class="ms-sub-opt" value="<?= htmlspecialchars($msKey, ENT_QUOTES) ?>"
                                           data-parent="<?= htmlspecialchars($msT['key'] ?? '', ENT_QUOTES) ?>" checked>
                                    <span><?= $msLabel ?></span>
                                </label>
                            <?php elseif ($msMode === 'todo'): ?>
                                <!-- Not built: nothing to switch, so no checkbox. -->
                                <div class="hint" style="display:flex;align-items:center;gap:7px;padding:1px 0 1px 22px;color:#94a3b8;">
                                    <span>&#128679;</span><span><?= $msLabel ?></span>
                                </div>
                            <?php elseif ($msMode === 'off'): ?>
                                <!-- Built, but nothing switched on behind it — must not read as
                                     running, which a green tick would. -->
                                <div class="hint" style="display:flex;align-items:center;gap:7px;padding:1px 0 1px 22px;color:#92400e;">
                                    <span>&#9888;</span><span><?= $msLabel ?></span>
                                </div>
                            <?php else: ?>
                                <!-- Happens whenever the parent step runs; not separable, so no
                                     checkbox — an unclickable tick would only look like a control. -->
                                <div class="hint" style="display:flex;align-items:center;gap:7px;padding:1px 0 1px 22px;color:#334155;">
                                    <span style="color:#166534;">&#10003;</span><span><?= $msLabel ?></span>
                                </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </details>
            <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
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
            document.querySelectorAll('.ms-step-opt').forEach(function (p) {
                p.addEventListener('change', function () { sync(p); });
                sync(p);
            });

            // "Select all" / "Unselect all" — sets every step AND every sub-switch, then
            // re-runs sync() per parent so the disabled/greyed state matches. Clearing
            // data-was first stops sync() from restoring an older per-sub state instead of
            // the all-on/all-off state this just set.
            window.msSetAllSteps = function (on) {
                document.querySelectorAll('.ms-step-opt').forEach(function (p) { p.checked = on; });
                document.querySelectorAll('.ms-sub-opt').forEach(function (cb) {
                    delete cb.dataset.was;
                    cb.checked = on;
                });
                document.querySelectorAll('.ms-step-opt').forEach(function (p) { sync(p); });
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

                <p class="hint" style="margin:14px 0 0;padding-top:12px;border-top:1px solid #e2e8f0;">
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
        server in this step &mdash; that is step 5. AI generation costs roughly $0.02&ndash;0.05 per site
        (free on rebuilds).</p>
    <div style="display:flex;gap:18px;flex-wrap:wrap;align-items:flex-end;">
        <label class="hint">Build this many (0 = all)<br><input type="number" id="ms-limit" value="0" min="0" style="width:110px;"></label>
        <label class="hint">Only this domain (optional)<br><input type="text" id="ms-run-only" placeholder="example.com" style="width:200px;"></label>
        <label class="hint"><input type="checkbox" id="ms-force"> Force (rebuild everything, refresh AI)</label>
        <button type="button" class="btn btn-primary" id="ms-run-btn" onclick="msRun()">Generate sites</button>
    </div>
    <div id="ms-run-progress" style="margin-top:16px;"></div>
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
(function () {
    const csrfToken = <?= json_encode($csrfToken) ?>;
    const bpMasterId = <?= json_encode($masterId ?? '') ?>;
    const bpBatchId  = <?= json_encode($batchId ?? '') ?>;
    let msPollTimer = null;

    // See admin/_batch_servers.php's esc() for why ' is escaped too — this file also
    // interpolates values into single-quoted JS string literals inside onclick="...".
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

    function badge(status) {
        const map = { ok: ['#166534', '#dcfce7', 'ok'], warn: ['#92400e', '#fef3c7', 'warn'], error: ['#991b1b', '#fee2e2', 'error'] };
        const [fg, bg, label] = map[status] || map.error;
        return '<span style="font-size:0.72rem;font-weight:700;padding:2px 8px;border-radius:4px;color:' + fg + ';background:' + bg + ';">' + label + '</span>';
    }

    function render(data) {
        const card = document.getElementById('ms-results-card');
        if (!data || (!data.rows && !data.error)) { card.style.display = 'none'; return; }
        card.style.display = '';

        if (data.error) {
            document.getElementById('ms-summary').innerHTML = '<span style="color:#991b1b;font-weight:600;">' + esc(data.error) + '</span>';
            document.querySelector('#ms-table tbody').innerHTML = '';
            document.getElementById('ms-unknown').innerHTML = '';
            return;
        }

        const s = data.summary || { total: 0, ok: 0, warn: 0, error: 0 };
        const storedNote = data.stored ? ' &nbsp;·&nbsp; <strong style="color:#166534;">stored ✓</strong>'
                                       : (s.error > 0 ? ' &nbsp;·&nbsp; <strong style="color:#991b1b;">not stored — fix errors</strong>' : '');
        document.getElementById('ms-summary').innerHTML =
            '<strong>' + s.total + '</strong> rows &nbsp; ' + badge('ok') + ' ' + s.ok + ' &nbsp; ' +
            badge('warn') + ' ' + s.warn + ' &nbsp; ' + badge('error') + ' ' + s.error + storedNote;

        document.getElementById('ms-unknown').innerHTML =
            (data.unknown_columns && data.unknown_columns.length)
                ? 'Unknown columns (ignored): <code>' + data.unknown_columns.map(esc).join('</code> <code>') + '</code>' : '';

        const tb = document.querySelector('#ms-table tbody');
        tb.innerHTML = (data.rows || []).map(r => {
            const issues = (r.errors || []).map(e => '<div style="color:#991b1b;">✗ ' + esc(e) + '</div>').join('')
                         + (r.warnings || []).map(w => '<div style="color:#92400e;">· ' + esc(w) + '</div>').join('');
            return '<tr data-domain="' + esc(r.domain) + '">' +
                '<td>' + esc(r.line) + '</td>' +
                '<td>' + badge(r.status) + '</td>' +
                '<td>' + esc(r.domain) + '</td>' +
                '<td>' + esc(r.business) + '</td>' +
                '<td>' + esc(r.city) + '</td>' +
                '<td class="ms-ftp-cell">' + (r.has_ftp ? '✓' : '—') + '</td>' +
                '<td>' + (issues || '<span class="hint">—</span>') + '</td>' +
                '</tr>';
        }).join('');
    }

    window.msUpload = function (ev) {
        ev.preventDefault();
        const btn = document.getElementById('ms-upload-btn');
        const msg = document.getElementById('ms-upload-msg');
        const file = document.getElementById('ms-csv').files[0];
        if (!file) { return false; }
        const fd = new FormData();
        fd.append('csrf_token', csrfToken);
        fd.append('csv', file);
        btn.disabled = true; msg.textContent = 'Validating…';
        fetch('multisite_api.php?action=upload_csv', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(d => { btn.disabled = false; msg.textContent = d.stored ? 'Stored.' : (d.error ? '' : 'Reviewed — not stored.'); render(d); if (d.stored) refreshParamsState(); })
            .catch(e => { btn.disabled = false; msg.textContent = 'Upload failed.'; });
        return false;
    };

    window.msLoadTestCsv = function () {
        const btn = document.getElementById('ms-test-csv-btn');
        const msg = document.getElementById('ms-upload-msg');
        if (!confirm('Store the placeholder test row? This replaces whatever target list is currently stored.')) return;
        const fd = new FormData();
        fd.append('csrf_token', csrfToken);
        btn.disabled = true; msg.textContent = 'Loading test row…';
        fetch('multisite_api.php?action=load_test_csv', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(d => { btn.disabled = false; msg.textContent = d.stored ? 'Test row stored.' : (d.error ? '' : 'Reviewed — not stored.'); render(d); if (d.stored) refreshParamsState(); })
            .catch(e => { btn.disabled = false; msg.textContent = 'Failed to load test row.'; });
    };

    // ── Test server (FTP) — settings + per-row "deploy to test server" ─────────
    function loadTestDeployConfig() {
        fetch('multisite_api.php?action=test_deploy_get').then(r => r.json()).then(d => {
            if (!d || d.error) return;
            document.getElementById('td-host').value = d.ftp_host || '';
            document.getElementById('td-protocol').value = d.ftp_protocol || 'ftp';
            document.getElementById('td-port').value = d.ftp_port || '';
            document.getElementById('td-user').value = d.ftp_user || '';
            document.getElementById('td-path').value = d.ftp_path || '';
            document.getElementById('td-view-url').value = d.view_url || '';
            document.getElementById('td-passive').checked = d.ftp_passive !== false;
            document.getElementById('td-pass').placeholder = d.has_password ? 'set — leave blank to keep' : 'leave blank to keep';
        }).catch(() => {});
    }
    loadTestDeployConfig();

    window.msTestDeploySave = function () {
        const btn = document.getElementById('td-save-btn');
        const msg = document.getElementById('td-save-msg');
        const fd = new FormData();
        fd.append('csrf_token', csrfToken);
        fd.append('ftp_host', document.getElementById('td-host').value.trim());
        fd.append('ftp_protocol', document.getElementById('td-protocol').value);
        fd.append('ftp_port', document.getElementById('td-port').value.trim());
        fd.append('ftp_user', document.getElementById('td-user').value.trim());
        fd.append('ftp_pass', document.getElementById('td-pass').value);
        fd.append('ftp_path', document.getElementById('td-path').value.trim());
        fd.append('view_url', document.getElementById('td-view-url').value.trim());
        if (document.getElementById('td-passive').checked) fd.append('ftp_passive', '1');
        btn.disabled = true; msg.textContent = 'Saving…';
        fetch('multisite_api.php?action=test_deploy_save', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(d => {
                btn.disabled = false;
                msg.textContent = d.ok ? 'Saved.' : (d.error || 'Save failed.');
                document.getElementById('td-pass').value = '';
                document.getElementById('td-pass').placeholder = d.has_password ? 'set — leave blank to keep' : 'leave blank to keep';
            })
            .catch(() => { btn.disabled = false; msg.textContent = 'Save failed.'; });
    };

    window.msDeployTestServer = function (domain, linkEl) {
        if (!confirm('Deploy "' + domain + '" to the test server? This overwrites whatever is there now.')) return;
        const original = linkEl.textContent;
        linkEl.textContent = 'deploying…';
        const fd = new FormData();
        fd.append('csrf_token', csrfToken);
        fd.append('domain', domain);
        fetch('multisite_api.php?action=deploy_test_server', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(d => {
                if (d.error) { linkEl.textContent = original; alert(d.error); return; }
                const r = d.result || {};
                if ((r.status || '') === 'fatal' || (r.failed || 0) > 0) {
                    linkEl.textContent = original;
                    alert('Deploy had problems: ' + (r.msg || (r.failed + ' file(s) failed')));
                    return;
                }
                linkEl.outerHTML = '<a href="' + esc(d.view_url || '#') + '" target="_blank" rel="noopener">deployed — view it</a>';
            })
            .catch(() => { linkEl.textContent = original; alert('Deploy failed.'); });
    };

    // ── Target list: download-current visibility + saved versions ──────────────
    function fmtStamp(id) {
        const m = /^(\d{4})(\d{2})(\d{2})-(\d{2})(\d{2})(\d{2})/.exec(id || '');
        if (!m) return id;
        const d = new Date(Date.UTC(+m[1], +m[2] - 1, +m[3], +m[4], +m[5], +m[6]));
        return isNaN(d) ? id : d.toLocaleString();
    }
    function renderVersions(data) {
        const el = document.getElementById('ms-versions');
        const vs = (data && data.versions) || [];
        if (!vs.length) { el.innerHTML = '<p class="hint">No saved versions yet — each successful upload is snapshotted here (last 15).</p>'; return; }
        el.innerHTML = '<div style="overflow-x:auto;"><table style="width:100%;font-size:0.85rem;">' +
            '<thead><tr><th>Saved (local time)</th><th>Rows</th><th></th></tr></thead><tbody>' +
            vs.map(v => '<tr>' +
                '<td>' + esc(fmtStamp(v.id)) + '</td>' +
                '<td>' + esc(v.rows) + '</td>' +
                '<td><a href="multisite_api.php?action=download_version&id=' + encodeURIComponent(v.id) + '">download</a> &nbsp;·&nbsp; ' +
                '<a href="#" onclick="msRestore(\'' + esc(v.id) + '\', this);return false;">restore</a></td>' +
                '</tr>').join('') + '</tbody></table></div>';
    }
    function loadVersions() { fetch('multisite_api.php?action=list_versions').then(r => r.json()).then(renderVersions).catch(() => {}); }
    function refreshParamsState() {
        fetch('multisite_api.php?action=status').then(r => r.json()).then(d => {
            document.getElementById('ms-download-row').style.display = (d && d.stored) ? '' : 'none';
        }).catch(() => {});
        loadVersions();
    }
    window.msRestore = function (id, link) {
        // Guards against a double-click/second click firing a second restore_version
        // POST before the first one's response lands — the two would race, and
        // whichever responded second silently won regardless of which was clicked
        // last. Checked before confirm() so a second click can't even open a second
        // dialog while the first request is still in flight.
        if (link && link.dataset.busy) return;
        if (!confirm('Restore this version as the current target list? (A fresh snapshot is also saved.)')) return;
        // refreshParamsState()'s loadVersions() re-renders this whole link (and its
        // busy state along with it) on success; only the error path needs to
        // explicitly restore it.
        if (link) { link.dataset.busy = '1'; link.style.opacity = '0.5'; }
        const fd = new FormData(); fd.append('csrf_token', csrfToken); fd.append('id', id);
        fetch('multisite_api.php?action=restore_version', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(d => {
                if (d.error) { alert(d.error); if (link) { delete link.dataset.busy; link.style.opacity = ''; } return; }
                render(d); refreshParamsState();
            })
            .catch(() => { if (link) { delete link.dataset.busy; link.style.opacity = ''; } });
    };

    window.msPreflight = async function () {
        const btn = document.getElementById('ms-pf-btn');
        const msg = document.getElementById('ms-pf-msg');
        // reset FTP cells that have creds to a pending dot
        document.querySelectorAll('#ms-table tbody tr').forEach(tr => {
            const cell = tr.querySelector('.ms-ftp-cell');
            if (cell && cell.textContent.trim() === '✓') cell.innerHTML = '<span style="color:#94a3b8;">…</span>';
        });
        btn.disabled = true; msg.textContent = 'Connecting…';
        // A dedicated, single-use, 60s token for this EventSource URL — not the real
        // csrf_token. EventSource can't send a header or a body, so SOME token has to
        // be in the URL where an access log or proxy could see it; this one is good
        // for nothing else and only briefly, unlike the general csrf_token.
        let pfToken;
        try {
            const fd = new FormData(); fd.append('csrf_token', csrfToken);
            const r = await fetch('multisite_api.php?action=preflight_token', { method: 'POST', body: fd });
            const d = await r.json();
            if (!d.token) { btn.disabled = false; msg.textContent = d.error || 'Could not start pre-flight.'; return; }
            pfToken = d.token;
        } catch (e) {
            btn.disabled = false; msg.textContent = 'Could not reach the server — try again.'; return;
        }
        const es = new EventSource('multisite_preflight.php?token=' + encodeURIComponent(pfToken));
        es.onmessage = function (e) {
            const d = JSON.parse(e.data);
            if (d.type === 'row') {
                const tr = document.querySelector('#ms-table tbody tr[data-domain="' + d.domain.replace(/"/g, '\\"') + '"]');
                if (tr) {
                    const cell = tr.querySelector('.ms-ftp-cell');
                    if (cell) cell.innerHTML = d.ok
                        ? '<span style="color:#166534;" title="reachable">✓</span>'
                        : '<span style="color:#991b1b;" title="' + esc(d.msg) + '">✗</span>';
                }
                msg.textContent = 'Checking… ' + d.done + '/' + d.total;
            } else if (d.type === 'done') {
                es.close(); btn.disabled = false;
                msg.textContent = d.total === 0 ? 'No rows with FTP credentials.' : ('FTP: ' + d.ok + ' ok, ' + d.fail + ' failed of ' + d.total);
            } else if (d.type === 'fatal') {
                es.close(); btn.disabled = false; msg.textContent = d.msg;
            }
        };
        es.onerror = function () { es.close(); btn.disabled = false; if (!msg.textContent.startsWith('FTP:')) msg.textContent = 'Pre-flight interrupted.'; };
    };

    // ── Run batch (detached background + polling) ──────────────────────────────
    function renderRun(d) {
        const el = document.getElementById('ms-run-progress');
        const btn = document.getElementById('ms-run-btn');
        if (!d || d.none || !d.state) { el.innerHTML = ''; btn.disabled = false; return; }
        const state = d.state;
        const color = { running: '#2563eb', done: '#166534', failed: '#991b1b', stale: '#92400e' }[state] || '#334155';
        const t = d.totals || {};
        const pct = d.total ? Math.round((d.done / d.total) * 100) : 0;
        let html = '<div><strong style="color:' + color + ';">' + state.toUpperCase() + '</strong> — ' +
            (d.done || 0) + '/' + (d.total || 0) + ' done · ' + (d.ok || 0) + ' ok · ' + (d.failed || 0) + ' failed' +
            (t.files_uploaded ? ' · ' + t.files_uploaded + ' files' : '') +
            (t.cost_usd ? ' · $' + Number(t.cost_usd).toFixed(4) : '') +
            (d.params_version ? ' · <span title="target list version used">list ' + esc(d.params_version) + '</span>' : '') + '</div>' +
            '<div style="height:8px;background:#e2e8f0;border-radius:4px;margin:8px 0 12px;overflow:hidden;"><div style="height:100%;width:' + pct + '%;background:' + color + ';transition:width .3s;"></div></div>';
        if (d.results && d.results.length) {
            html += '<div style="max-height:240px;overflow:auto;font-size:0.85rem;line-height:1.7;">' +
                d.results.slice().reverse().map(r => {
                    const mk = r.status === 'running' ? '<span style="color:#2563eb;">⋯</span>'
                             : r.status === 'ok'       ? '<span style="color:#166534;">✓</span>'
                             :                           '<span style="color:#991b1b;">✗</span>';
                    // In-flight rows show the current STEP (short, stable) plus the most
                    // recent progress line (changes every tick) — this is the whole point:
                    // a still-running row used to show nothing at all until it finished.
                    const stepLabel = r.status === 'running' && r.step ? ' [' + esc(r.step) + ']' : '';
                    // "view" only once this row has actually generated output on disk — a
                    // failed row has nothing under batches/{id}/output/ for batch_preview.php to serve.
                    const viewLink = r.status === 'ok' && r.domain
                        ? ' &nbsp;·&nbsp; <a href="batch_preview.php?master=' + encodeURIComponent(bpMasterId) +
                          '&batch=' + encodeURIComponent(bpBatchId) + '&domain=' + encodeURIComponent(r.domain) +
                          '" target="_blank" rel="noopener">view</a>'
                        : '';
                    // Public preview — same output, no login, reachable by Google's own tools.
                    // Slug must match ms_batch_output_dir()'s folder-naming exactly (lowercase,
                    // any run of non a-z0-9 becomes one underscore, no leading/trailing underscore).
                    const slug = r.status === 'ok' && r.domain
                        ? r.domain.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '') : '';
                    const publicLink = slug
                        ? ' &nbsp;·&nbsp; <a href="http://' + bpMasterId + '--' + bpBatchId + '--' + slug +
                          '.preview.q111.xyz/" target="_blank" rel="noopener">public preview</a>'
                        : '';
                    // Real deploy to the fixed test server — see the settings panel above.
                    const deployLink = r.status === 'ok' && r.domain
                        ? ' &nbsp;·&nbsp; <a href="#" onclick="msDeployTestServer(\'' + esc(r.domain) + '\', this);return false;">deploy to test server</a>'
                        : '';
                    return '<div>' + mk + ' ' + esc(r.domain) + ' — ' + esc(r.status) + stepLabel +
                        (r.uploaded != null ? ' (' + r.uploaded + ' up)' : '') +
                        (r.cost > 0 ? ' $' + Number(r.cost).toFixed(4) : '') +
                        (r.last ? ' — ' + esc(r.last) : '') + viewLink + publicLink + deployLink + '</div>';
                }).join('') + '</div>';
        }
        el.innerHTML = html;
        if (state === 'running') { btn.disabled = true; }
        else {
            btn.disabled = false;
            if (msPollTimer) {
                clearInterval(msPollTimer); msPollTimer = null; loadRuns();
                // The Upload card (step 5) reads "how many are ready to upload" once at
                // page load and otherwise never hears about a Generate-sites run finishing
                // — without this it keeps showing a stale "0 ready" until a manual reload.
                if (typeof window.msRefreshUploadState === 'function') window.msRefreshUploadState();
            }
        }
    }

    // ── Runs history ──────────────────────────────────────────────────────────
    function fmtTime(s) { if (!s) return '—'; const d = new Date(s); return isNaN(d) ? s : d.toLocaleString(); }
    function renderRuns(data) {
        const el = document.getElementById('ms-runs');
        const runs = (data && data.runs) || [];
        if (!runs.length) { el.innerHTML = '<p class="hint">No runs yet.</p>'; return; }
        const stC = { running: '#2563eb', done: '#166534', failed: '#991b1b', stale: '#92400e' };
        el.innerHTML = '<div style="overflow-x:auto;"><table style="width:100%;font-size:0.85rem;">' +
            '<thead><tr><th>Started</th><th>State</th><th>Result</th><th>Cost</th><th></th></tr></thead><tbody>' +
            runs.map(r => {
                const c = stC[r.state] || '#334155';
                const retry = r.failed > 0
                    ? ' <button type="button" class="btn" style="padding:1px 8px;font-size:0.76rem;" onclick="msRetry(\'' + esc(r.run_id) + '\', this)">retry ' + r.failed + ' failed</button>'
                    : '';
                return '<tr>' +
                    '<td>' + esc(fmtTime(r.started_at)) + '</td>' +
                    '<td><span style="color:' + c + ';font-weight:700;">' + esc(r.state) + '</span></td>' +
                    '<td>' + r.ok + '/' + r.total + ' ok' + (r.failed ? ' · ' + r.failed + ' failed' : '') + '</td>' +
                    '<td>' + (r.cost ? '$' + Number(r.cost).toFixed(4) : '—') + '</td>' +
                    '<td><a href="#" onclick="msView(\'' + esc(r.run_id) + '\');return false;">view</a>' + retry + '</td>' +
                    '</tr>';
            }).join('') + '</tbody></table></div>';
    }
    function loadRuns() { fetch('multisite_api.php?action=list_runs').then(r => r.json()).then(renderRuns).catch(() => {}); }

    window.msView = function (id) {
        pollRun(id);
        document.getElementById('ms-run-progress').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    };
    window.msRetry = function (id, btn) {
        // Same double-submit guard as msRestore above — checked before confirm() so a
        // second click while the first retry is still in flight can't open a second
        // dialog and fire a second retry_failed for the same run.
        if (btn && btn.disabled) return;
        if (!confirm('Re-run only the failed rows from this run?')) return;
        if (btn) btn.disabled = true;
        const fd = new FormData(); fd.append('csrf_token', csrfToken); fd.append('run_id', id);
        fetch('multisite_api.php?action=retry_failed', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(d => {
                if (d.error) { alert(d.error); if (btn) btn.disabled = false; return; }
                if (msPollTimer) clearInterval(msPollTimer);
                msPollTimer = setInterval(() => pollRun(d.run_id), 2500);
                pollRun(d.run_id);
                // Stays disabled until the retry run finishes — renderRun()'s completion
                // branch calls loadRuns(), which re-renders this whole row anyway.
            })
            .catch(() => { if (btn) btn.disabled = false; });
    };

    let pollRunMisses = 0;   // consecutive failed polls — one blip self-heals silently
    function pollRun(runId) {
        const url = 'multisite_api.php?action=run_status' + (runId ? '&run_id=' + encodeURIComponent(runId) : '');
        fetch(url).then(r => r.json()).then(d => { pollRunMisses = 0; renderRun(d); }).catch(() => {
            // A persistent failure used to leave #ms-run-btn disabled forever with the
            // interval silently retrying and zero feedback — surface it once instead.
            if (++pollRunMisses === 5) {
                const el = document.getElementById('ms-run-progress');
                if (el) el.insertAdjacentHTML('beforeend',
                    '<div style="color:#991b1b;margin-top:6px;">Lost contact with the server — still trying… (the run itself may still be in progress; reload to check)</div>');
            }
        });
    }

    window.msRun = function () {
        const btn = document.getElementById('ms-run-btn');
        const fd = new FormData();
        fd.append('csrf_token', csrfToken);
        fd.append('limit', document.getElementById('ms-limit').value);
        // Generating never uploads now — sending is step 5, and the built sites are
        // kept under the batch so that step has something to send.
        fd.append('no_deploy', '1');
        // Unticked steps become the skip list. 'ai' also sets no_ai so the runner has
        // one mechanism for it rather than two that could disagree.
        const skip = Array.from(document.querySelectorAll('.ms-step-opt'))
            .filter(cb => !cb.checked).map(cb => cb.value);
        // Sub-switches use the same skip list, keyed "parent.child". A sub-switch under a
        // parent that's already off is NOT added — the parent skip already covers it, and
        // sending both would just be noise.
        Array.from(document.querySelectorAll('.ms-sub-opt')).forEach(cb => {
            if (skip.includes(cb.dataset.parent)) return;
            if (!cb.checked) skip.push(cb.value);
        });
        if (skip.length) fd.append('skip', skip.join(','));
        if (skip.includes('ai')) fd.append('no_ai', '1');
        if (document.getElementById('ms-force').checked) fd.append('force', '1');
        const only = document.getElementById('ms-run-only').value.trim();
        if (only) fd.append('only', only);
        btn.disabled = true;
        document.getElementById('ms-run-progress').innerHTML = '<span class="hint">Starting…</span>';
        fetch('multisite_api.php?action=run', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(d => {
                if (d.error) { btn.disabled = false; document.getElementById('ms-run-progress').innerHTML = '<span style="color:#991b1b;">' + esc(d.error) + '</span>'; return; }
                if (msPollTimer) clearInterval(msPollTimer);
                msPollTimer = setInterval(() => pollRun(d.run_id), 2500);
                pollRun(d.run_id);
            })
            .catch(() => { btn.disabled = false; });
    };

    // ── Research cities — detached; poll the output file ──────────────────────
    var msResearchTimer = null;
    var msResearchMisses = 0;   // consecutive failed polls — one blip self-heals silently
    function msPollResearch(runId) {
        fetch('multisite_api.php?action=research_status&run_id=' + encodeURIComponent(runId))
            .then(r => r.json())
            .then(d => {
                msResearchMisses = 0;
                var out = document.getElementById('ms-research-out');
                if (d.error) { out.textContent = d.error; return; }
                if (d.none)  { out.textContent = 'Starting…'; return; }
                out.textContent = d.output || '';
                out.scrollTop = out.scrollHeight;
                if (d.done) {
                    if (msResearchTimer) clearInterval(msResearchTimer);
                    document.getElementById('ms-research-dry').disabled = false;
                    document.getElementById('ms-research-btn').disabled = false;
                    out.textContent += (d.exit === 0 ? '\n\n✓ Done.' : '\n\n✗ Exited with code ' + d.exit + '.');
                }
            })
            .catch(() => {
                // A persistent failure used to leave both research buttons disabled
                // forever with zero feedback while the job may have finished or died
                // server-side. Give up after a run of misses instead of hanging silently.
                if (++msResearchMisses < 5) return;
                if (msResearchTimer) clearInterval(msResearchTimer);
                document.getElementById('ms-research-dry').disabled = false;
                document.getElementById('ms-research-btn').disabled = false;
                var out = document.getElementById('ms-research-out');
                if (out) out.textContent += '\n\n✗ Lost contact with the server — the job may still be running; reload to check.';
            });
    }
    window.msResearch = function (dry) {
        var dryBtn = document.getElementById('ms-research-dry');
        var runBtn = document.getElementById('ms-research-btn');
        var out = document.getElementById('ms-research-out');
        var fd = new FormData();
        fd.append('csrf_token', csrfToken);
        if (dry) fd.append('dry_run', '1');
        dryBtn.disabled = true; runBtn.disabled = true;
        out.style.display = 'block';
        out.textContent = 'Starting ' + (dry ? 'dry run' : 'research') + '…';
        fetch('multisite_api.php?action=research', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(d => {
                if (d.error) {
                    out.textContent = d.error;
                    // "Research is already running" carries the live run_id — resume
                    // watching it instead of just re-enabling the buttons and leaving
                    // the operator to guess whether anything is actually happening.
                    if (d.run_id) {
                        if (msResearchTimer) clearInterval(msResearchTimer);
                        msResearchTimer = setInterval(() => msPollResearch(d.run_id), 2000);
                        msPollResearch(d.run_id);
                    } else {
                        dryBtn.disabled = false; runBtn.disabled = false;
                    }
                    return;
                }
                if (msResearchTimer) clearInterval(msResearchTimer);
                msResearchTimer = setInterval(() => msPollResearch(d.run_id), 2000);
                msPollResearch(d.run_id);
            })
            .catch(() => { dryBtn.disabled = false; runBtn.disabled = false; });
    };

    // ── Title preview (read-only — resolves the master's real page titles) ────
    function loadTitlePreview() {
        fetch('multisite_api.php?action=preview_titles').then(function (r) { return r.json(); }).then(function (d) {
            var el = document.getElementById('ms-titles-preview');
            if (d.error) { el.innerHTML = '<p class="hint" style="color:#991b1b;">' + esc(d.error) + '</p>'; return; }
            var src = d.is_placeholder ? 'an example city (upload your target list to preview real data)' : d.sample_domain;
            var layoutLine = d.layout
                ? '<p class="hint" style="margin-top:10px;">Section layout for this site: <strong>Layout ' + d.layout.index + ' of ' + d.layout.total + '</strong> (set per page under Content → Layout variations).</p>'
                : '';
            var rows = (d.titles || []).map(function (t) {
                var val = t.has_title
                    ? '<span style="color:#0f172a;font-weight:600;">' + esc(t.resolved) + '</span>'
                    : '<span style="color:#991b1b;">' + esc(t.resolved) + '</span>';
                return '<tr><td style="padding:5px 10px;white-space:nowrap;color:#475569;">' + esc(t.label) + '</td><td style="padding:5px 10px;">' + val + '</td></tr>';
            }).join('');
            el.innerHTML = '<p class="hint">Resolved for <strong>' + esc(src) + '</strong>:</p>' +
                '<div style="overflow-x:auto;"><table style="width:100%;font-size:0.9rem;border-collapse:collapse;">' +
                '<thead><tr><th style="text-align:left;padding:5px 10px;border-bottom:1px solid #e2e8f0;">Page</th>' +
                '<th style="text-align:left;padding:5px 10px;border-bottom:1px solid #e2e8f0;">Title tag on a cloned site</th></tr></thead>' +
                '<tbody>' + rows + '</tbody></table></div>' + layoutLine;
        }).catch(function () {});
    }
    loadTitlePreview();

    // Load the batch's current stored state.
    fetch('multisite_api.php?action=status').then(r => r.json()).then(d => { if (d && d.stored && d.rows) render(d); }).catch(() => {});
    // Resume any latest/in-progress run.
    fetch('multisite_api.php?action=run_status').then(r => r.json()).then(d => {
        if (d && !d.none && d.state) { renderRun(d); if (d.state === 'running') { if (msPollTimer) clearInterval(msPollTimer); msPollTimer = setInterval(() => pollRun(d.run_id), 2500); } }
    }).catch(() => {});
    loadRuns();            // runs history
    refreshParamsState();  // download-current button + saved versions
    // Resume any latest/in-progress research job. Unlike 'run' above, this used to
    // have no bootstrap at all: reloading the page (or leaving and coming back)
    // while a paid, possibly long research job was running left both buttons
    // re-enabled and the output box empty, with no way to tell it was still going
    // short of clicking "Research cities" again and reading the "already running"
    // error's buried run_id.
    fetch('multisite_api.php?action=research_status').then(r => r.json()).then(d => {
        if (!d || d.none || d.error) return;
        document.getElementById('ms-research-out').style.display = 'block';
        if (!d.done) {
            document.getElementById('ms-research-dry').disabled = true;
            document.getElementById('ms-research-btn').disabled = true;
            if (msResearchTimer) clearInterval(msResearchTimer);
            msResearchTimer = setInterval(() => msPollResearch(d.run_id), 2000);
        }
        msPollResearch(d.run_id);
    }).catch(() => {});
})();
</script>
