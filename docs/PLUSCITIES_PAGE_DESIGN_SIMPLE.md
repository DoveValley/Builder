# PlusCities — Page Design, Simple Path

**Purpose:** make a PlusCities city/service page look genuinely good and convert, without building a
theme-detection/token-capture system first. This replaces `uploads/convo/..._PLUSCITIES_DESIGN_SYSTEM.md`
as the approach — that doc solved cross-theme fit before solving "does this page make someone call,"
and is bigger than the problem actually in front of us right now.
**Read with:** `docs/PLUSCITIES_FEATURE_SPEC.md` (content/research/validation — unchanged by this doc).
**Last updated:** 2026-11-22

## The principle

**Hand-design one genuinely good look, proven against real reference sites — don't auto-detect a
theme's style, and don't ship multiple style variants.** The only things that vary per client are the
things that already vary today: accent color, logo, phone number, and the client's own photos. Every
other visual decision (spacing, fonts, card shape, button style, what a stat tile looks like) is fixed,
hand-crafted CSS shipped once, the same for every client.

**Why this over the token-detection design system:** that approach spends its complexity on matching
a client's exact theme pixel-for-pixel, which affects "does this look bolted-on" more than "does this
convert." The things that actually move a local-service visitor to call — an always-reachable CTA,
trust signals high on the page, fast/mobile-clean rendering, genuinely specific local content (already
handled by the writer/research pipeline) — don't require per-theme detection at all. Build those first;
revisit theme-matching only if a real client's site visibly clashes with it.

**The client's own theme header/footer stay exactly as-is — not in scope, not touched.** Confirmed in
the real delivery plugin (`wp-plugin/pluscities-delivery/pluscities-delivery.php`'s `template_redirect`
handler): every PlusCities page calls WordPress's own `get_header()` before our HTML and `get_footer()`
after it, wrapping our content in `<div class="pluscities-page">`. Everything below is about what
renders *inside* that div — the client's real site chrome (nav, logo, footer links, any other pages on
their site) is untouched by any of this, same as today.

## What's already built (reuse, don't rebuild)

- Two-column hero (`lpc-hero-row` / `lpc-hero-text` / `lpc-hero-media`) — text left, photo right,
  modeled on Bailey/Wren/Gann. `pluscities_render_block()` in `includes/pluscities_writer.php`.
- Trust bar (`pluscities_render_trust_bar()`) — checkmark strip, niche-level `trust_bar_items`.
- One bundled stylesheet, built server-side in `admin/pluscities_sync.php`, sent with every page fetch.
- Dedicated renders for `reviews_from_city` (blockquotes), `niche_data_table` (real table),
  `housing_stock` (labeled Census stat list + source line), `faq` (flat Q/A), `service_area` (links).
- Everything else (`climate_data`, `neighborhoods`, `permits`, `utilities`, `hoa_rules`,
  `seasonal_guide`, `hazards`, `common_problems`, `cited_expert_guidance`, `process`, `cost_factors`,
  `why_choose_us`, `repair_vs_replace`, `maintenance`, `before_the_tech_arrives`) falls through to one
  generic heading+paragraph+source-line shape today — this is the actual "wall of text" problem.

## What this adds (new or upgraded components — 8, not 18)

Each is a small addition to the existing `pluscities_render_block()` switch plus a bit more CSS in the
one shared stylesheet. No new build pipeline, no new files per component, no per-site CSS output.

1. **~~Sticky call bar~~ — decided against.** The page already renders inside the client's own theme
   header/footer (`get_header()`/`get_footer()`, confirmed in the real plugin) — a separate PlusCities-
   owned sticky bar competes with whatever the theme's own header/footer already does, and on a real
   client site it's not ours to add on top of their existing layout. Use whatever call-to-action the
   theme's own header/footer already provides instead of introducing a second one.
2. **Trust bar placement** — no new code, just confirm it renders directly under the hero on every
   page (it already can; just needs to actually be wired into the page-create block list consistently).
3. **FAQ accordion** — upgrade `faq`'s existing flat Q/A into real `<details>/<summary>` (no JS,
   first item open), matching the feature spec's accessibility rule.
4. **Stat tiles** — new dedicated render for blocks that are fundamentally "a few numbers with
   labels": `housing_stock` (already has the data, just reskin it) and `climate_data` **once it's
   backed by a real dataset** — today `climate_data` is plain AI-written prose, not dataset-driven, so
   reskinning it alone would just be the same generic text wearing a stat-tile costume. Note this as a
   content-pipeline follow-up, not a blocker for the other 7 components.
5. **Card grid** — new dedicated render for the "a few short items" shaped blocks: `neighborhoods`,
   `hazards`, `cost_factors`, `why_choose_us`, `common_problems`. 2–3 columns, each card a short
   title + 1–2 sentences, stacks to 1 column on mobile.
6. **Callout box** — new dedicated render for single-topic advisory blocks: `permits`, `hoa_rules`,
   `maintenance`, `before_the_tech_arrives`. A bordered/tinted box (left accent border), not a plain
   paragraph — cheap to build, visually breaks up the page.
7. **Steps** — new dedicated render for `process`: numbered sequence, vertical on mobile.
8. **CTA band** — new, inserted once mid-page (after roughly the 3rd-4th block) and once again right
   before the FAQ — accent-colored band, headline + phone button. Matches the proven reference-site
   pattern, not auto-placed by a rhythm engine; a fixed position in the existing block order is enough.
9. **Review cards** — light upgrade of the existing `reviews_from_city` blockquotes into a card shape
   with stars, still 100% client-supplied content, no new data.

## What this explicitly does NOT include (the difference from the bigger spec)

- No theme-token auto-detection (no hidden iframe / `getComputedStyle` scraping of a client's site).
- No per-site CSS build pipeline, no `css_hash`/`css_url` manifest fields, no per-site output files —
  one shared stylesheet, same as today.
- No style variants (Clean/Bold/Classic) or per-page hero rotation.
- No new page types (Service Area hub, City hub) — those are a separate, later decision; this doc only
  touches the existing landing-page block set.
- No new validator checks, no cross-theme/screenshot QA matrix — verify the same way tonight's hero
  work was verified: real client, real screenshot, real gut-check against the fleet reference sites.

## Build order

1. FAQ accordion (`<details>/<summary>` upgrade to the existing `faq` render).
2. CTA band (fixed mid-page + pre-FAQ placement).
3. Card grid (`neighborhoods`, `hazards`, `cost_factors`, `why_choose_us`, `common_problems`).
4. Callout box (`permits`, `hoa_rules`, `maintenance`, `before_the_tech_arrives`).
5. Steps (`process`).
6. Stat tiles reskin for `housing_stock` (real data already exists); hold `climate_data` until it has
   a real dataset behind it.
7. Review-card reskin for `reviews_from_city`.

## Done when

A real client page (Meade Restoration, Southlake or Bedford) has a real FAQ accordion, at least one
card-grid block, one callout, one steps block if the niche uses `process`, and one CTA band before the
FAQ — verified by an actual screenshot, compared side by side against Bailey/Wren/Gann, and
the honest answer to "would this make someone call" is yes. No new data model fields, no new plugin
capability, no per-theme testing required to call it done.
