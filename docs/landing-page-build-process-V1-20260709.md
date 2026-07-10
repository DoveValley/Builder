# Landing-Page Build Process — Keyword Map → 30 Templates → Deploy

**Version:** V1 · 2026-07-09
**Worked example:** pest-template (Elk Pest Pros / elkpestpros.com), 30 pest landing pages, Lufkin TX as the proof city.
**Branch:** `multisite-generator-phase0` (commits `4b92c43` → `b0fcd9d`)

This documents the exact, repeatable process used to go from a keyword map to a
full validated page set — and the final deploy step (held pending site config).
It is **niche-agnostic**: swap the keyword map + master template + AI prompts and
the same steps produce another niche's pages.

---

## The mental model

Three human-authored inputs per niche; everything else is mechanical:

1. **Keyword map** (`keyword_map.json`) — *which* pages exist + each page's target keyword.
2. **Master template(s)** — the reusable block skeleton per archetype.
3. **AI prompts** (`ai_block_types.json` registry) — niche-neutral, `{service}`-driven.

Two generation passes turn a template × city into a live page:

- **Pass A — structure** (`includes/generation/engine.php` → `generate_city_pages()`;
  admin trigger `admin/generate.php` / City Pages tab). Clones the template per
  city, resolves `{city}`/`{SS}`/`{city_slug}` etc., writes
  `data/pages/{templateId}_{cityId}.json`, rebuilds `page-index.json`. **No AI, no cost.**
- **Pass B — AI content** (`generate.py`; admin trigger `admin/ai_generate.php` / AI tab).
  Fills each page's AI blocks from the prompt registry. **Costs ~$0.20/city for 30 pages.**

> ⚠️ Pass A and Pass B are **separate actions** and must run in that order.

---

## Phase 0 — Keyword map (the plan)

Built on the **Keywords tab** → writes `data/keyword_map.json`:

```
services[]: { primary, slug, section: home|core|landing, tier, secondary[] }
```

- `section: landing` entries = the landing pages to build.
- `primary` = target keyword; `slug` = URL base; `secondary[]` = variant phrasings
  that live **on the page**, not as separate URLs (rank-and-rent rule).

**Worked example:** 30 landing services confirmed complete (primary + slug + 2–5
secondaries each). This is the source list for everything downstream.

---

## Phase 1 — Master template: audit & fix

The proven master (cockroach = extermination archetype) block order:
`hero_split → trust bar (custom_html) → AI city_intro → AI feature_columns_local →
steps → mid CTA → AI local_relevance → AI seasonal_calendar → service_cards
(related) → AI faq_local → CTA → [services_links]`.

Key facts confirmed:
- **AI prompts are `{service}`-driven** → AI blocks auto-adapt per service, so
  cloning needs *no* prompt changes and only the *static* blocks need attention.
- **`{service}`/`{keyword}` do NOT resolve in static block text** (only in AI
  prompts). So for static text, **find/replace is the only lever** — keep the
  master's pest words consistent (all "cockroach/roach") so one pair-set cleans a clone.

**Defect fixed:** the Service schema hardcoded `"url":"…cockroach-exterminator-{city_slug}/"`.
A word-swap fixes "cockroach" but not the slug base, so every clone's JSON-LD `url`
would mismatch its real slug (404). **Fix: drop `url` from the Service node** — the
canonical `<link>` already establishes URL identity. (commit `4b92c43`)

Admin: added a **Master Template** section (base-flag partition) to the Templates tab.

---

## Phase 2 — Bulk-generate the noun-service templates (21)

Tool: **Bulk Template Generator** (`admin/templates_save.php` `bulk_generate`).
Row format: `Service | slug-base | primary keyword | find=repl;… | hero|intro|local img | Title`.

- `find=repl` = case-aware whole-word swaps (e.g. `cockroach=termite;cockroaches=termites;roach=termite;roaches=termites`).
- **Dry-run first** — flags ⚠ leftover subject words and ⚠ missing images per row.
  (Verified 0 leftover across all 21 before committing.)
- The generator overrides `service` + SEO service fields and forces hero H1 to
  `"{service} in {city_state}"`.

**Archetype split discovered:** of the 30, ~21 are single-pest nouns that fit the
extermination master; the other ~9 (inspections, category/plans) are different
*intents* needing their own archetype — do **not** force them through one master.

---

## Phase 3 — Images

Media library (`uploads/media/`) held a full katypestpros scrape with
`{service}` / `about-{service}` / `best-{service}` triads + per-pest bug photos.

- Coverage vs 21: **16 exact matches, 5 on a neutral pest fallback**
  (scorpion/cricket/stink bug/bee/centipede — flagged for future custom art).
- Assigned 3 slots per template: hero (`hs_photo`), city-intro `it_photo`,
  local-relevance `it_photo`. Alt text auto-inherited the swapped service noun.

---

## Phase 4 — Inspection archetype (3)

pest / termite / WDI inspection — built **directly off the master skeleton** (only
3, closely related), termite-inspection flagged as the inspection master. (commit `cbe14a1`)

Static changes (AI blocks reused unchanged — they adapt to `{service}`):
- steps → **"How Our {service} Works": Schedule → On-Site Inspection → Written Report**
- trust bar → Trained Inspectors / Detailed Written Report / Fast Scheduling
- seasonal AI block → **static "What We Inspect" `feature_columns`** (per-service coverage)
- schema serviceType per service, no `url`

Validated: termite-inspection × Lufkin renders correct end-to-end.

---

## Phase 5 — Category / plans archetype (5)

residential (master) / commercial / emergency / restaurant pest control + pest
control plans. (commit `f219360`)

- **Category pages:** broad multi-pest framing, general treatment steps, "Pests We
  Treat" grid, seasonal kept. AI blocks adapt to `{service}`.
- **Plans page:** `pricing_cards` — 3 tiers (One-Time / Quarterly / Monthly),
  feature lists + **"Call for a quote"** and **NO invented prices** (never fabricate
  pricing/stats); seasonal dropped in favor of pricing.

---

## Phase 6 — Cross-link fix (all 30)

The master's `service_cards` "related services" linked to **old slugs**
(`/ant-control`, `/bed-bug-treatment`, `/flea-control`, `/rat-control`) that no
longer matched template slugs → 404 on every page. Rewrote **120 links** across all
30 to the real slugs (`ant-exterminator`, etc.). (commit `f219360`)

> Recurring lesson: **manually-maintained lists drift from templates.** Same class
> of bug hit the `[services_links]` grid (Phase 8).

---

## Phase 7 — Generate the full city page set (Lufkin)

1. **Pass A** — all 30 templates × Lufkin → 30 pages, 0 errors.
2. **Orphan cleanup** — deleted 11 stale page files from previously-removed
   templates (they'd pollute the page index, `[services_links]`, and Pass B cost).
   Rebuilt page-index to a clean 30.
3. **Pass B** — AI content for all 30 (~$0.20, 76 blocks). 30/30, 0 errors.

Gotchas:
- **`force_locked` in Pass A wipes existing AI content** — don't force-lock over
  already-filled pages at scale (re-run costs AI again).
- CLI runs as **root**; page files must be `chown www-data:www-data` afterward or
  the admin can't rewrite them.
- Pass B has a 10-min foreground cap here — run it **in the background** for a full set.

---

## Phase 8 — Schema & services-grid polish

- **SAB homepage LocalBusiness node** added to `seo.schema`: `PestControlService`
  with `@id {website}/#localbusiness` (matches the landing pages' `provider @id`),
  `areaServed` only — **no street address, no geo, no aggregateRating** (the
  `local_business` config's 4.8/534 is fabricated and must never enter live schema).
  Correct service-area modeling ("we work in your area"). (commit `fe79515`)
- **`[services_links]` grid** was showing only 10 of 30: the plugin hides links to
  pages that don't exist, and its config was a stale 21-item list (old slugs +
  orphan services). **Regenerated the list from templates.json** → 30 links, 0
  broken, everywhere the grid renders. (commit `3c56b1d`)

---

## Phase 9 — Wire keyword-map secondaries into content

Closed the gap where `keyword_map.secondary[]` was written but never consumed. (commit `b0fcd9d`)

1. **Plumbing:** synced `seo.secondary_keywords` on all 30 templates from the map by slug.
2. **`generate.py`:** exposed `{secondary_keywords}` in `build_context`
   (from the page's `seo.secondary_keywords`).
3. **Prompts:** city_intro / feature_columns_local / local_relevance / faq_local now
   weave the variants in **naturally, with an explicit anti-stuffing guardrail**
   (skip terms that don't fit); the FAQ is told to cover the distinct intents.

Validated on termite: "termite control/exterminator/removal" surfaced; "termite
fumigation"/"tent fumigation" correctly omitted (don't fit standard treatment) —
guardrail working. Note: modern `<meta name="keywords">` is ignored by Google, so
`secondary_keywords` is a **data carrier into generation**, not an SEO tag.

---

## Phase 10 — Deploy to elkpestpros.com  ⏳ HELD

**Model:** one multi-city site under elkpestpros.com (all cities as landing pages,
one domain, one deploy). Infra is ready: `deploy.json` has FTP + `canonical_domain:
https://elkpestpros.com`; static build does trailing-slash canonicalization + sitemap.

**Blockers (parked — must fix before go-live, they hit every page):**
- **Logo** — still the Katy Pest Pros image (`header.logo`); body copy says Elk Pest Pros.
- **Phone** — placeholder `(281) 888-8888` display vs. real `936-201-5555` tel;
  `site_vars.phone`/`tel` disagree.

**Deploy steps (when unblocked):**
1. Fix `header.logo` + `site_vars.phone`/`tel` (+ `local_business.lb_logo`).
2. Static build (30 Lufkin pages + homepage).
3. FTP deploy → overwrites the 2 stale pages currently live and adds the other 28.
4. Verify live: 200s on all slugs, `sitemap.xml`, resolved schema, trailing-slash
   canonicals, no 301 chains.

**Current live state (checked 2026-07-09):** elkpestpros.com serves only a stale
2-page prior deploy (termite-treatment + cockroach-exterminator) with broken internal
links. **None of this session's 30-page set is deployed yet.**

---

## Commit checkpoints (this branch)

| Commit | What |
|---|---|
| `4b92c43` | Master Template section + 21 bulk templates + schema-url fix + images |
| `cbe14a1` | Inspection archetype (3) |
| `f219360` | Category/plans archetype (5) + 120 cross-link fixes |
| `fe79515` | SAB homepage LocalBusiness schema |
| `3c56b1d` | services_links grid synced to 30 |
| `b0fcd9d` | keyword-map secondaries wired into generation |

Generated city pages are kept **uncommitted as artifacts** (regenerable per real city).

---

## Repeatable checklist for a NEW niche

1. Build the **keyword map** on the Keywords tab (primary + slug + secondaries, by section).
2. Build/adapt the **master template(s)** — one per archetype the niche needs.
3. Fill the **prompt registry** with niche-neutral `{service}`-driven prompts.
4. Sync `secondary_keywords` from the map onto the templates.
5. Bulk-generate noun-service templates; build divergent archetypes directly.
6. Assign images; sync `[services_links]` + related-service cross-links to the templates.
7. Add the SAB homepage LocalBusiness node.
8. Pass A → orphan cleanup → Pass B (background); validate one page per archetype.
9. Set site config (logo, phone, `deploy.json`), build, FTP deploy, verify live.

### Standing gotchas
- Manual lists (services_links, related-service cards) **drift** — always re-sync from templates.
- Never fabricate prices/ratings/stats; model service-area businesses with `areaServed`, no address.
- `force_locked` on Pass A wipes AI content; `chown www-data` after any root-run generation.
- Keep the master's static pest words consistent so one find/replace pair-set cleans a clone.
