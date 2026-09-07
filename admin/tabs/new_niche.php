<?php
// New Niche tab — mostly a plain reference checklist for creating a brand-new niche
// vertical and its first master site, start to finish; it exists so the steps live in
// the panel instead of only in chat history or a doc nobody opens. Keep the checklist
// text in sync by hand if the underlying process changes — there is no live/read-off-disk
// data behind it to keep it honest automatically. The one exception is the "rewrite
// inherited content" card below, which is a real action (admin/new_niche_rewrite.php).
?>
<div class="tab-content" style="<?= $tab === 'new_niche' ? '' : 'display:none;' ?>">
<?php tab_header('New Niche/Site', 'Checklist for standing up a brand-new niche vertical and its first master site, start to finish.', 'tab-new-niche'); ?>

<div class="card" style="border-left:4px solid #92400e;background:#fffbeb;">
    <h3 style="margin-top:0;margin-bottom:8px;">&#9888; Follow this process, not the other one</h3>
    <p class="hint" style="margin:0;max-width:820px;">This checklist combines two docs: <code>docs/landing-page-build-process-V1-20260709.md</code>
        (the templates/keyword/deploy mechanics) and <code>docs/niche-brief-and-research.md</code> (AI content,
        per-city research, charts) — a niche vertical with many programmatically-built city sites.
        <code>docs/site-building.md</code> describes a <strong>different, separate</strong> track: hand-building one
        bespoke, single-tenant client site. The two tracks do not overlap, and following the wrong one wastes real work.</p>
</div>

<?php
$nnrMeta = json_decode((string)@file_get_contents(ACTIVE_SITE_DIR . '/meta.json'), true) ?: [];
$nnrClonedFrom = trim((string)($nnrMeta['cloned_from'] ?? ''));
$nnrBaseGuess = '';
if ($nnrClonedFrom !== '') {
    $srcBrief = json_decode((string)@file_get_contents(BASE_DIR . '/sites/' . $nnrClonedFrom . '/multisite/niche_brief.json'), true) ?: [];
    $nnrBaseGuess = trim((string)($srcBrief['service_noun'] ?? ''));
}
?>
<div class="card" style="margin-top:16px;border-left:4px solid #4c1d95;">
    <h3 style="margin-top:0;margin-bottom:6px;">&#128260; Rewrite inherited content for this niche</h3>
    <p class="hint" style="margin:0 0 12px;max-width:820px;">A new niche is born by cloning an existing one, which carries every
        static (non-AI) homepage block forward <strong>word-for-word</strong> from whatever niche it was cloned from.
        This runs the same AI rewrite the Templates tab uses for service pages, but once for this niche's whole
        homepage — grounded in <strong>this niche's own Brief</strong> (fill that in first, on the
        <a href="?tab=niche_brief">Niche Brief &amp; Research</a> tab, before running this). Manual and one-time on
        purpose — running it automatically at clone time would rewrite using the OLD niche's still-copied brief,
        which is exactly the content this is meant to replace.</p>
    <form action="new_niche_rewrite.php" method="post" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
        <div class="form-group" style="margin:0;">
            <label>Cloned from <span class="hint" style="font-weight:400;">(that niche's service, e.g. "Pest Control")</span></label>
            <input type="text" name="base_service" value="<?= h($nnrBaseGuess) ?>" placeholder="Pest Control" style="width:260px;" required>
            <?php if ($nnrClonedFrom !== ''): ?>
                <span class="hint">Detected from <code><?= h($nnrClonedFrom) ?></code> — edit if that's wrong.</span>
            <?php else: ?>
                <span class="hint">Not recorded for this master — enter it yourself.</span>
            <?php endif; ?>
        </div>
        <button type="submit" class="btn btn-primary"
            onclick="return confirm('Rewrite this niche\'s homepage static content with AI, grounded in its own Brief? This costs a small amount of real API usage and overwrites the current homepage text.');">Rewrite homepage now</button>
    </form>
</div>

<div class="card" style="margin-top:16px;">
    <h3 style="margin-top:0;margin-bottom:6px;">A &middot; Niche-level setup <span class="hint" style="font-weight:400;">(one-time, reused by every future site in this niche)</span></h3>
    <ol style="margin:0;padding-left:20px;line-height:1.8;">
        <li><strong>Choose the Niche ID carefully.</strong> It is not just a label — it gets slugified and must exactly
            match a folder name under <code>plugins/image-data-chart/niches/{slug}/</code>. Reword it later and every
            chart for this niche silently disappears, with no error. (<a href="?tab=niche_brief">Niche Brief &amp; Research</a>)</li>
        <li>Fill in the Brief — service noun, business descriptor, customer noun, offerings, tone, local angle, and
            <strong>guardrails</strong>. Guardrails is the only place regulated-niche language (licensing, advertising
            rules) lives — free text, nothing checks it for you.</li>
        <li>Decide <strong>&ldquo;Uses research fields&rdquo;</strong> on or off. This gates the whole per-city research
            step, and archetypes marked &ldquo;needs research&rdquo; are skipped entirely when it's off.</li>
        <li>Write the research prompt (leave blank for the generic default), plus any <strong>extra research
            facts</strong> this niche needs beyond charts or plugins. Field names must match what your archetypes
            reference — nothing validates a mismatch, it just silently never resolves.</li>
        <li>Tick which shared <strong>archetypes</strong> this niche uses; override wording per-master if needed.</li>
        <li>Saving the Brief auto-compiles the registry — but a later edit to the <strong>shared</strong> archetype
            library needs a manual &ldquo;Compile Registry Now&rdquo; click to reach this niche. Easy to forget.</li>
        <li><em>Optional:</em> add chart definitions — one JSON file per chart, under this niche's own folder. Skipping
            this entirely is a normal, fully supported state, not a gap.</li>
        <li><em>Optional:</em> claim a free slot in the Gen-Visual niche switcher — a cosmetic label only, with no
            effect on content generation either way.</li>
    </ol>
</div>

<div class="card" style="margin-top:16px;">
    <h3 style="margin-top:0;margin-bottom:6px;">B &middot; First-site-level setup <span class="hint" style="font-weight:400;">(building this niche's actual first master site)</span></h3>
    <ol style="margin:0;padding-left:20px;line-height:1.8;">
        <li><strong>Create the master site — CLI only, no admin button does this today.</strong> Run
            <code>clone_site.py --mode template</code> from an existing master, as root, then
            <code>chown www-data:www-data</code> the new site's files before the admin panel can write to them.</li>
        <li><strong>Build the keyword map first.</strong> Fully manual by design, no auto-fill — feed a real keyword
            export through the provided prompt template, then hand-enter the clustered Home/Core/Landing structure.
            (<a href="?tab=keywords">Keywords</a>)</li>
        <li>Build <strong>one master landing-page template</strong> using the Templates tab's own GREAT-SEO walkthrough
            and downloadable prompt. No starter kit exists for a new niche beyond that process doc.
            (<a href="?tab=templates">Landing Templates</a>)</li>
        <li><strong>Bulk-generate</strong> the rest of the service templates from that one master template, via
            find/replace.</li>
        <li>Site foundation — real <code>site_vars</code>, header, footer, theme. No placeholders: a placeholder phone
            number or logo shows up everywhere it's referenced.</li>
        <li>Homepage and core pages, one content block at a time, screenshot-verified before adding the next.</li>
        <li><strong>Legal pages</strong> (Privacy, Terms, Contact) — the footer ships with placeholder <code>#</code>
            links; nothing creates the real pages automatically. Build them, then re-point the footer at the real
            slugs.</li>
        <li>Populate <strong>cities</strong> and run research. (<a href="?tab=cities">Landing Cities</a>)</li>
        <li><strong>Generate the city landing pages</strong> — a mechanical clone pass first, then the AI content
            fill.</li>
        <li><strong>Schema type</strong> (e.g. Plumber, PestControlService) is AI-suggested and saved per site — it is
            <strong>not</strong> derived from the niche brief. Don't assume setting the brief also sets this.</li>
        <li>Images, then Deploy (FTP/hosting) — infrastructure, unrelated to the niche itself, so it belongs last.</li>
    </ol>
</div>

<div class="card" style="margin-top:16px;">
    <h3 style="margin-top:0;margin-bottom:6px;">C &middot; How content actually gets created</h3>
    <p class="hint" style="margin:0 0 14px;">A page is a mix of hand-written static text and AI-generated text. Knowing which is
        which — and where each one runs — matters for cost, for uniqueness, and for knowing what a rebuild will and
        won't re-charge you for.</p>

    <h4 style="margin:0 0 4px;font-size:.86rem;color:#1e3a5f;">Non-AI (static) text</h4>
    <ul style="margin:0 0 14px;padding-left:20px;line-height:1.8;">
        <li>Most of a page's actual prose (trust bar, steps/process text, CTA banners, service cards) is hand-typed
            <strong>once</strong> into one master template, on the Templates tab.</li>
        <li>The Bulk Template Generator clones that master into every other service via <strong>find/replace word-swap
            only</strong> ("roach"&rarr;"termite") — not a rewrite. Its own dry-run reports leftover base-niche words
            when the swap misses something.</li>
        <li>Pass A then clones that <strong>same static text into every city</strong> under a service, completely
            unchanged — today, every city's version of a given service page shares byte-identical static prose.</li>
        <li>Cloning a whole new master (<code>clone_site.py</code>) carries static text forward <strong>verbatim</strong>
            from whatever site it was cloned from — legal pages, trust bar, steps, all of it — until a human rewrites
            it. Confirmed real: the Privacy Policy page carries the same internal id across four different niche
            masters today.</li>
        <li>&#9888; <strong>This is where real duplicate-content risk actually concentrates</strong> — not in the AI
            blocks, which already get a genuine, separate API call per (service &times; city) combination.</li>
    </ul>

    <h4 style="margin:0 0 4px;font-size:.86rem;color:#1e3a5f;">AI generation &mdash; solo site</h4>
    <ul style="margin:0 0 14px;padding-left:20px;line-height:1.8;">
        <li>Triggered from the AI Generation tab (<code>admin/ai_generate.php</code>); streams output live to the
            browser as it runs.</li>
        <li>Same <code>generate.py</code> engine as batch: pick a scope (homepage/core/landing/all), optionally
            research first, then fill each <code>ai_block</code>/enrich field.</li>
        <li><strong>No separate cache layer.</strong> <code>_ai_locked</code> lives permanently on the site's own
            stored blocks — once locked, a block stays skipped on every future run until an explicit Refresh, which
            redoes every locked block at once, not selectively.</li>
    </ul>

    <h4 style="margin:0 0 4px;font-size:.86rem;color:#1e3a5f;">AI generation &mdash; multisite batch</h4>
    <ul style="margin:0 0 14px;padding-left:20px;line-height:1.8;">
        <li>Never per-service — it's <strong>per-domain</strong>, running automatically inside a batch run
            (<code>build_one.php</code>), one domain at a time.</li>
        <li><strong>Research runs once, master-wide, before any domain is cloned</strong> — every domain in the batch
            reuses the same already-researched <code>cities.json</code>, never re-researching independently.</li>
        <li>Each domain has its own <strong>persistent cache</strong>
            (<code>sites/{master}/multisite/cache/{domain}.json</code>), because a domain's working copy is thrown
            away and rebuilt from scratch every run. The sequence: scrub anything inherited-locked from the master
            &rarr; inject a candidate value + a hash of the prompt it was generated under &rarr;
            <code>generate.py</code> recomputes today's prompt hash and reuses for free on a match, or pays for a
            real call only if something (keyword, research data, &hellip;) actually changed &rarr; the result is
            written back to that domain's cache for next time.</li>
        <li>This cache is <strong>why rebuilding fifty domains repeatedly stays cheap</strong> — a solo site never
            needs it because it's never thrown away and rebuilt.</li>
    </ul>

    <h4 style="margin:0 0 4px;font-size:.86rem;color:#1e3a5f;">Solo vs. batch, side by side</h4>
    <div style="overflow-x:auto;margin-bottom:14px;">
    <table style="width:100%;border-collapse:collapse;font-size:.85rem;">
        <thead><tr style="text-align:left;border-bottom:1px solid #e2e8f0;">
            <th style="padding:5px 10px;">&nbsp;</th><th style="padding:5px 10px;">Solo site</th><th style="padding:5px 10px;">Multisite batch</th>
        </tr></thead>
        <tbody>
            <tr><td style="padding:5px 10px;color:#475569;">Trigger</td><td style="padding:5px 10px;">Manual, AI Generation tab</td><td style="padding:5px 10px;">Automatic, once per domain in a batch run</td></tr>
            <tr><td style="padding:5px 10px;color:#475569;">Idempotency</td><td style="padding:5px 10px;"><code>_ai_locked</code> only, permanent</td><td style="padding:5px 10px;">Per-domain cache, hash-validated each rebuild</td></tr>
            <tr><td style="padding:5px 10px;color:#475569;">Research scope</td><td style="padding:5px 10px;">This site's own cities</td><td style="padding:5px 10px;">Whole master, shared by every domain</td></tr>
            <tr><td style="padding:5px 10px;color:#475569;">Rebuild cost</td><td style="padding:5px 10px;">N/A &mdash; nothing is rebuilt from scratch</td><td style="padding:5px 10px;">Free unless the resolved prompt actually changed</td></tr>
        </tbody>
    </table>
    </div>

    <h4 style="margin:0 0 4px;font-size:.86rem;color:#1e3a5f;">Two-tier duplicate-content fix &mdash; <strong>built</strong></h4>
    <p class="hint" style="margin:0 0 6px;">Closes the duplicate-content gap described above. Neither is on by default for
        an existing niche — Tier 1 needs enabling per niche, Tier 2 is an opt-in checkbox per bulk-generate run.</p>
    <ul style="margin:0;padding-left:20px;line-height:1.8;">
        <li><strong>Tier 1 — fixes cross-<em>city</em> duplication.</strong> A new <code>steps_local</code> archetype
            (<code>multisite/ai/archetypes.json</code>) promotes the "steps"/process block to a real per-city AI
            enrichment, same engine and cache as every other AI block. To use it on a niche: enable
            <code>steps_local</code> in that niche's Brief &amp; Research tab, compile, and set
            <code>ai_type_id: steps_local</code> on the template's <code>steps</code> block (already done for all 30
            pest-template templates as the working example). Verified live: correctly grounds the primary keyword,
            and correctly picked up this niche's own guardrails (a referral network, not the crew doing the work) —
            something the old static text got wrong.</li>
        <li><strong>Tier 2 — fixes cross-<em>service</em> duplication.</strong> A new <code>generate.py
            --rewrite-template</code> mode, wired into the Bulk Template Generator (Templates tab) as an "AI-rewrite
            the static content for uniqueness" checkbox. Field-scoped — only prose fields are ever sent to the model;
            structural fields, slugs, and <code>{shortcode}</code> tokens are never touched, and any field whose
            rewrite drops a token is automatically reverted to its original text rather than risking a broken page.
            Runs <strong>once per service, not per city</strong> — 30 services means 30 calls regardless of how many
            cities the niche ever grows to. Verified live: rewrote 89/89 fields for a real row, passed the existing
            leftover-word scan with zero leftover base-service words.</li>
        <li><strong>Homepage — same cross-<em>domain</em> fix as Tier 1, applied to the homepage's own static blocks.</strong>
            Three new archetypes (<code>homepage_feature_intro</code>, <code>homepage_grid_intro</code>,
            <code>homepage_service_area</code>) plus reusing <code>steps_local</code> where a niche's homepage has its
            own steps block. Rolled out to all five real niches, 0 errors. Along the way this also fixed a real bug —
            <code>{keyword}</code> wasn't token-resolved for the homepage the way <code>{secondary_keywords}</code>
            already was — and caught a live guardrail violation (static copy claiming to directly employ technicians
            on a referral-network niche), now corrected.</li>
        <li><strong>Homepage — cross-<em>niche</em> fix, the "Rewrite inherited content" card above.</strong> Same
            engine as Tier 2, pointed at the homepage instead of a service template, triggered manually per new niche
            rather than automatically at clone time (the new niche's Brief must be filled in first, or the rewrite
            would use the OLD niche's still-copied brief).</li>
    </ul>
</div>

<div class="card" style="margin-top:16px;">
    <h3 style="margin-top:0;margin-bottom:6px;">Known gaps &amp; recurring gotchas</h3>
    <ul style="margin:0;padding-left:20px;line-height:1.8;">
        <li>There is no admin-UI way to create a new master site today — it's a root CLI step every time.</li>
        <li>The related-service cross-link list (<code>services_links</code>) is a manually-maintained list that drifts
            from <code>templates.json</code> by design — treat re-syncing it as an ongoing chore, not a one-time box to
            check.</li>
        <li>Course Schedule (<code>courses.json</code>) only applies to training/certification niches — skip it
            entirely for home-service niches.</li>
        <li>Running the mechanical city-page pass with <code>force_locked</code> wipes already-filled AI content
            <strong>without</strong> refilling it. Know this before touching it on a first build.</li>
    </ul>
</div>

</div>
