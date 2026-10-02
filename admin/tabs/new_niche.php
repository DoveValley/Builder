<?php
// New Niche tab — a plain reference checklist for standing up a brand-new niche vertical
// and its first master site, zero to ranking-ready, start to finish; it exists so the steps
// live in the panel instead of only in chat history or a doc nobody opens. Keep this in sync
// by hand if the underlying process changes — nothing reads this off disk to keep it honest.
// The one exception is the "rewrite inherited content" card below, which is a real action
// (admin/new_niche_rewrite.php).
?>
<div class="tab-content" style="<?= $tab === 'new_niche' ? '' : 'display:none;' ?>">
<?php tab_header('New Niche/Site Steps', 'Zero to a ranking-ready site: the ordered steps, start to finish.', 'tab-new-niche'); ?>

<div class="card" style="border-left:4px solid #92400e;background:#fffbeb;">
    <h3 style="margin-top:0;margin-bottom:8px;">&#9888; Follow this process, not the other one</h3>
    <p class="hint" style="margin:0;max-width:820px;">This is for a niche vertical with many programmatically-built city
        sites — see <code>docs/landing-page-build-process-V1-20260709.md</code> and
        <code>docs/niche-brief-and-research.md</code> for the full mechanics behind each step.
        <code>docs/site-building.md</code> is a <strong>different, separate</strong> track: one hand-built,
        single-tenant client site. Following the wrong one wastes real work.</p>
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
    <p class="hint" style="margin:0 0 12px;max-width:820px;">A new niche is cloned from an existing one, which carries
        every static homepage block forward word-for-word. This rewrites the whole homepage with AI, grounded in
        <strong>this niche's own Brief</strong> (fill that in first — <a href="?tab=niche_brief">Niche Brief &amp; Research</a>).
        Manual and one-time: running it automatically at clone time would use the OLD niche's still-copied brief.</p>
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
    <h3 style="margin-top:0;margin-bottom:6px;">Zero to a ranking-ready site</h3>
    <p class="hint" style="margin:0 0 10px;">Every tool/plugin named below is real and already in this codebase — none of this is aspirational.</p>

    <h4 style="margin:14px 0 4px;font-size:.82rem;color:#1e3a5f;text-transform:uppercase;letter-spacing:.03em;">Niche setup (one-time)</h4>
    <ol style="margin:0;padding-left:20px;line-height:1.85;">
        <li><strong>Niche ID</strong> — must exactly match a folder under <code>plugins/image-data-chart/niches/{slug}/</code>, or charts silently vanish. (<a href="?tab=niche_brief">Niche Brief</a>)</li>
        <li><strong>Fill the Brief</strong> — service noun, descriptor, tone, local angle, <strong>guardrails</strong> (the only place licensing/advertising rules live).</li>
        <li>Decide if this niche <strong>uses research fields</strong>; write the research prompt + any extra facts archetypes need.</li>
        <li>Pick this niche's <strong>archetypes</strong>; save compiles the registry (a later shared-library edit needs a manual recompile).</li>
        <li><em>Optional:</em> chart definitions, Gen-Visual switcher slot.</li>
    </ol>

    <h4 style="margin:16px 0 4px;font-size:.82rem;color:#1e3a5f;text-transform:uppercase;letter-spacing:.03em;">Build this site</h4>
    <ol style="margin:0;padding-left:20px;line-height:1.85;">
        <li><strong>Clone the master site</strong> — CLI only: <code>clone_site.py --mode template</code>, then <code>chown www-data:www-data</code>.</li>
        <li><strong>Keyword map</strong> (<a href="?tab=keywords">Keywords</a>) — Home/Core/Landing; every page gets a <strong>primary keyword</strong> (drives slug/H1/title) and <strong>secondary keywords</strong> (variant phrasings woven into the page, never separate URLs); plus each service's <strong>Page Pool</strong> bucket (<code>pinned</code>/<code>rotate</code>/<code>skip</code> — needs <code>rotate</code> to vary which domains get it). Fully manual.</li>
        <li><strong>Run Master Lint</strong> on this master before cloning any domains from it (Batch panel's new-batch box, or Multisite API) — catches literal city/state/zip text typed instead of <code>{city}</code>/<code>{state}</code> shortcodes, and self-referential URLs that won't localize.</li>
        <li>Build <strong>one master landing-page template</strong> (Templates tab's GREAT-SEO walkthrough), then <strong>bulk-generate</strong> the rest via find/replace — dry-run first, zero leftover words before committing.</li>
        <li>Real <code>site_vars</code>, header, footer, theme — <strong>no placeholders</strong>, they show up everywhere they're referenced.</li>
        <li><strong>Color Pool + Logo Pool</strong> (Gen-Visual tab) — mark multiple Color Presets "in rotation", and separately mark multiple Logo Library arrangements "in rotation" (independent pool, independent seed). A pool of one never varies across domains.</li>
        <li><strong>Photo variation + hero overlay</strong> (Gen-Image tab) — settings for cropping/tone-shifting every non-hero photo and baking keyword+city onto the hero, so no two domains in the batch share an identical file.</li>
        <li><strong>Block-order variance</strong> (Gen-Mod tab, triggered by the "vary block order per city" toggle on City Pages) — so pages don't all read in the identical structural order.</li>
        <li><strong>Enable this niche's image plugins</strong> (Plugins tab): <strong>Data Chart</strong> + <strong>Area Map</strong> — per-city diagrams, unique by construction (not just a crop of the same stock photo) and fixes duplicate alt text in the same stroke; <strong>City Image</strong> — auto-sourced real per-city photo. Also enable <strong>related_links</strong>/<strong>services_links</strong> (and <strong>landing_links</strong>'s <code>[locations]</code> shortcode if used) for internal linking.</li>
        <li><strong>Build the homepage</strong>, one block at a time, screenshot-verified.</li>
        <li><strong>Build the core pages</strong> (About, Services, etc.), one block at a time, screenshot-verified.</li>
        <li><strong>Build the Contact page</strong> — real form/phone/email, not inherited placeholders.</li>
        <li><strong>Build the Privacy Policy page</strong> — footer ships with a placeholder <code>#</code> link; write real copy, don't carry the clone source's verbatim (a Privacy Policy has shipped with the SAME internal id across four different niche masters before).</li>
        <li><strong>Build the Terms page</strong> — same rule: real copy, not the clone source's verbatim text.</li>
        <li><strong>Re-point the footer</strong> at the real Contact/Privacy/Terms slugs once all three exist.</li>
        <li>Populate <strong>cities</strong>, run research (also geocodes lat/lng — authoritative lookup, never AI-guessed). (<a href="?tab=cities">Landing Cities</a>)</li>
        <li><strong>Generate city pages</strong> — mechanical pass first, then AI fill. (<code>force_locked</code> wipes existing AI content without refilling — don't use it on an already-filled site.)</li>
        <li><strong>Review the AI content</strong> (AI Review tab) — browse generated blocks by city, spot-check quality, lock the good ones.</li>
        <li><strong>Schema type</strong> is AI-suggested per site — not derived from the brief, set it explicitly.</li>
        <li>Images, then run the <strong>homepage rewrite</strong> above, then Deploy.</li>
    </ol>

    <h4 style="margin:16px 0 4px;font-size:.82rem;color:#1e3a5f;text-transform:uppercase;letter-spacing:.03em;">QA before go-live</h4>
    <ol style="margin:0;padding-left:20px;line-height:1.85;">
        <li>Confirm <strong>Page Pool, Color Pool, Logo Pool, block-order, and chart-group</strong> each actually produced different results across this niche's domains — a pool of one is the single most common way this silently fails. Confirm no two sibling domains serve the same city. Resync <code>[services_links]</code> from <code>templates.json</code> (a manual list that drifts).</li>
        <li>Confirm the per-domain <strong>identity rewrite</strong> actually landed: schema's name/URL/tel point at <strong>this</strong> domain, not the master's; no fabricated <code>aggregateRating</code>; analytics ID is unique per domain, never shared across the fleet; <code>areaServed</code> set, no invented street address.</li>
        <li>Colors/logo not byte-copied from the clone source; check <code>theme_presets.json</code> for a duplicate font family (cost mold/appliance 5–12 mobile PSI points, real past bug).</li>
        <li>Legal links real, site_vars real, trust claims match the actual business model (referral network vs. direct provider — shipped wrong fleet-wide before), no fabricated prices/ratings/stats, no burned-in images from the source site, unique title tags and meta descriptions per page.</li>
    </ol>

    <h4 style="margin:16px 0 4px;font-size:.82rem;color:#1e3a5f;text-transform:uppercase;letter-spacing:.03em;">Verify it will actually rank — run these tests, in order</h4>
    <ol style="margin:0;padding-left:20px;line-height:1.85;">
        <li><strong>Run PageSpeed Insights</strong> — mobile + desktop, homepage and at least one page per archetype, 90+ target. Short? Check duplicate fonts and CLS first, then confirm Cloudflare is actually caching (not just proxied).</li>
        <li><strong>Run the SEO gate</strong> (<code>includes/multisite/seo_gate.php</code> — runs automatically on batch builds, read its report) — primary keyword in every H1, one H1 per page, titles/meta descriptions present and not duplicated, schema types unchanged, canonical points at this domain. Reports only, doesn't block yet — treat a failure as real.</li>
        <li><strong>Run Google's Rich Results Test</strong> on the homepage and one page per archetype — the SEO gate only checks schema types are <em>unchanged</em>, not that Google can actually parse them; this is the real validation.</li>
        <li><strong>Google's Mobile-Friendly check</strong> — PSI's mobile pass covers Core Web Vitals, not tap-target sizing/viewport issues; confirm no mobile-usability warnings separately.</li>
        <li><strong>Submit the sitemap and request indexing in Search Console</strong> for the homepage and a sample page per archetype — verification alone (<code>theme.gsc_meta</code>, never <code>theme.head_extra</code>) doesn't get pages crawled any faster.</li>
        <li>Crawl the live site (internal link checker) — confirm no broken links, no redirect chains, no orphan pages.</li>
        <li>After a few days: check Search Console's <strong>Coverage</strong> report for indexing errors and <strong>Manual Actions</strong> for penalties — the only two things visible from outside that a build-time check can't catch.</li>
        <li><strong>Off-page — not covered by any of this.</strong> Backlinks, citations, Google Business Profile: nothing in the site factory touches them, and for local-service ranking they outweigh everything above. Passing every test here means technically ready, not outranking anyone yet.</li>
        <li><strong>Sign-off</strong> — mark the domain ready in the Go-Live grid only once every QA item and every test above passes. Never resolve DNS on a rank-and-rent domain before this.</li>
    </ol>
</div>

<div class="card" style="margin-top:16px;">
    <h3 style="margin-top:0;margin-bottom:6px;">Automatic — nothing to check</h3>
    <p class="hint" style="margin:0;">Three more anti-duplicate-content passes run on every build with no admin step and nothing to verify:
        <strong>class vocabulary</strong> renaming (same CSS, different class names per domain), <strong>schema shape</strong>
        reordering (same JSON-LD facts, different key order — anti-fingerprint only, genuinely zero SEO effect either way),
        and <strong>CSS cache-busting</strong> (content-hashed per domain so a redeploy can't serve a stale cached stylesheet).</p>
</div>

<div class="card" style="margin-top:16px;">
    <h3 style="margin-top:0;margin-bottom:6px;">Worth knowing: where duplicate content actually comes from</h3>
    <p class="hint" style="margin:0 0 8px;">AI blocks get a real, separate API call per service × city — the risk is almost
        entirely in <strong>static</strong> text: one master's hand-written prose gets find/replaced (not rewritten) into
        every service, then cloned byte-identical into every city under it, and carried verbatim into every new niche
        cloned from this one. A two-tier AI-rewrite fix exists for this (per-niche <code>steps_local</code> archetype for
        cross-city, <code>generate.py --rewrite-template</code> for cross-service, the homepage rewrite card above for
        cross-niche) — none of it is on by default, each needs enabling per niche/run.</p>
</div>

</div>
