# PlusCities — Consolidated Feature Spec v2

**Purpose:** The full specification for the PlusCities content engine rebuild, consolidated from a
planning conversation that worked through every major component before any code was written.
**Status:** Specification complete. No code built against this version yet.
**Supersedes:** The original `PLUSCITIES_FEATURE_SPEC` draft (uploaded 2026-11-21) — this version
keeps that doc's structure and design rules, replaces its component designs with the decisions
actually made during review, and adds several components the original left unresolved (menu mapping,
billing scope, override policy, onboarding flow).

---

## How to read this spec

- **Must** = required behavior. **Should** = default unless there's a good reason. **Example** =
  illustration, not a requirement.
- This is a rewrite of the content-generation core, not a retrofit of the current LPC codebase — see
  "What's being rebuilt vs. reused" below. Nothing here needs to account for live client data; build
  the real thing directly.
- Internal implementation choices (libraries, prompts, queue tech, exact AI-call batching) are up to
  the builder unless stated.

---

## Design rules (read first)

1. **Simple first.** If a feature can ship later without hurting SEO, accuracy, or doorway safety, it
   ships later.
2. **Modular.** Block types, data providers, research topics, validator checks, and niche blueprints
   are each self-contained units. Adding one never requires changing the others or the plugin.
3. **Data, not code, for niches.** A niche is a config file. No niche-specific code paths.
4. **Research first, write second.** Every specific claim on a page comes from a cited fact card, a
   dataset, or the client's own profile. The writer phrases facts; it never supplies them.
5. **Research once, reuse everywhere.** City research is shared by every client in that city. Service
   research is shared by every client in that niche.
6. **One place for each fact.** Status lives on pages, locks on blocks, city facts on the shared city,
   service facts on the niche, client facts on the client.
7. **Thin plugin, server renders.** The server renders finished HTML. The plugin only fetches, caches,
   and places it inside the client's theme. New block types never require a plugin update.
8. **Never present as AI.** No "AI-generated" labels or language anywhere: admin UI, plugin, pages,
   emails, reports.
9. **One override mechanism, not many.** Where something needs an exception path (validator checks,
   niche settings, approval gating, billing), use the same default-plus-override pattern everywhere
   rather than inventing a new mechanism per component.
10. **Lock contracts, defer content.** Data shapes, module interfaces, and the plugin wire format must
    be fully specified before any code is written, because changing them later means migrating live
    data or breaking an already-installed plugin. The *number* of niches, block types, validator
    checks, or data providers is content inside those contracts — safe to start minimal and grow with
    zero rework.

---

## What's being rebuilt vs. reused

This is a rewrite of the content-generation core — research, writing, block selection, validation, the
data model, and the plugin sync protocol — not a retrofit of the current `landing_page_clients_*.php`
pipeline, which does single-pass research+write with no citation trail and a hardcoded page structure.

**Reused as-is:**
- Stripe plumbing (checkout, webhook verification, cancellation calls) — scope changes, mechanics don't.
- `plugins/image-data-chart` / `plugins/image-area-map` — already modular, zero fleet coupling.
- Raw Census/NOAA fetch code — the API-calling layer is fine; the citation/sharing layer around it is new.
- The agency/client session-separation fix and the two-stage human approval queue pattern.
- The plugin's offline-cache fallback behavior (serves last-known content if the server is down).

**Rebuilt:** the AI pipeline, page assembly, the page/block/fact-card data shapes, the validator
(doesn't exist today), the plugin sync protocol, and the full role model.

---

## Architecture: three layers, modules

| Layer | What it is | Maintained by |
|---|---|---|
| Engine | Code: data model, modules below, dashboard, plugin | PlusCities, built once |
| Niche blueprints | One shared config per niche, inheriting from a family | Platform-level, ~20–30 min per niche via admin form |
| Client data | Client facts, cities, services; pages and blocks are generated rows | Agency enters facts; system does the rest |

**The niche library sits above both PlusCities and the fleet's Site Factory product — it belongs to
neither one.** It's the shared, business-model-agnostic layer of facts (what services a niche offers,
their validated keywords, the approved research sources, the local/seasonal angle) that both products
draw from, each layering its own framing and its own block-selection logic on top: PlusCities' real-
operator framing and simpler city/service block pools versus Site Factory's referral-network framing and
richer archetype-driven block system. PlusCities is the one building the library first and gets full use
of it immediately; Site Factory adopting it later is a retrofit on Site Factory's side (see Section 2),
not a reason to treat the library itself as a PlusCities-owned component.

**Pipeline for one client:**
1. Agency enters client facts, picks a niche, picks cities and services (two lists, cross-multiplied).
2. Data providers fill any missing datasets for those cities (shared, cached).
3. Research fills any missing/stale fact cards for those cities and services (shared, cached) — after
   a confirm dialog showing time/cost estimate.
4. Page builder selects blocks per page, writes each from fact cards + datasets + client facts, renders
   HTML server-side.
5. Validator checks each page automatically.
6. Agency reviews, edits (auto-locks), regenerates, swaps, approves.
7. Publish snapshots a revision; the plugin pulls it on its next sync.

---

## 1. Data model

Client and site are **merged into one entity** — every client today has exactly one website, so a
separate `sites` table buys nothing and costs a join; split them apart later only if a real multi-site
need appears.

| Entity | Key fields |
|---|---|
| **agencies** | status, billing plan tier, branding (logo/display_name), `requires_approval_gate` (default) |
| **users** | `user_id, email, role (platform_owner\|agency_admin\|agency_editor\|client_viewer), agency_id or client_id` — per-person accounts, not shared logins |
| **clients** (= sites) | identity (business name, domain, niche_id, contact), `api_key, site_rev, plugin_rev, plugin_version, last_seen_at, class_prefix, radius_minutes`; NAP (per hub), `licenses[]`, `years_in_business`, `hours`, `banned_phrases[]` (client-preference), `photos_by_city`, `reviews_by_city` (client-supplied only), `price_ranges` (optional); billing tier + Platform-Owner-only custom override; `approval_gate_override` (yes/no, inherits agency default if unset); `services[]`, `target_cities[]` (each with `drive_minutes`, `confirmed_by_client`) |
| **cities** (shared) | name, state, slug, lat/lng, population. `neighbors` computed at request time (haversine), not stored |
| **datasets** (shared) | one row per `(city_id, provider)` — Census ACS, NOAA climate, FEMA flood zones. Flat cached values, no citation — the provider *is* the source |
| **fact_cards** (shared) | `id, scope (city\|service\|niche), scope_key, topic, claim, value, source_url, source_title, quote, checked_at, expires_at, status (active\|stale\|rejected)` |
| **pages** | `page_id, client_id, city_id, service_key, slug, status (draft\|approved\|live\|unpublished), approval_stage (none\|pending_platform_review\|pending_agency_review\|approved\|rejected), live_revision_id, draft_hash, validation (per-check results incl. overrides), index_status` |
| **blocks** | `page_id, block_type, position, pool (spine\|city\|service), classification (editable\|automatic\|client-supplied), content (json), fact_ids[], dataset_refs[], locked, origin (generated\|edited\|data)` |
| **revisions** (immutable) | `page_id, number, snapshot, hash, author, created_at` |
| **events** (audit log) | `actor, action, target, detail, timestamp` |

Niche/family/compliance-preset configs are reference data, not transactional — authored rarely by
Platform Owner, referenced by ID, kept out of this relational model on purpose.

`status` on a page tracks the **highest stage ever reached**, not the current editing state — once
live, a page stays `live` even while a new draft is being worked on top of it. Everything else
(unpublished changes, validation current, synced, facts current) is a **derived flag**, not a new
status value — this keeps the state space from exploding into "live-but-editing" style variants.

---

## 2. Niche library

**A platform-level asset, not a PlusCities-only one** — see the architecture note above. Everything
below is genuinely shared (the niche definition, family inheritance, the keyword profile, approved
sources, research topics); what's specifically PlusCities' own layer on top is the compliance preset's
real-operator framing and the city/service block-pool selection in Section 5 — Site Factory would keep
its own archetype-driven block system and referral-network framing rather than adopting those two parts.

Two levels: **Family** (6: home trades, outdoor & cleaning, restoration, legal, medical & wellness,
professional services) sets defaults; **Niche** inherits and overrides.

**Family sets:** schema-type family, default city-block pool, a compliance preset, a baseline
approved-source list, default radius, which research topics are relevant.

**Niche specifies:** family reference, schema subtype (real schema.org vocabulary, e.g.
`HVACBusiness`), a **keyword-validated service list** (see below), `blocks_off[]` (a **denylist**
against the family's default pool — not an allowlist; if the same block gets turned off across every
niche in a family, that's a sign the family default was wrong, not that each niche needs its own
exception), extra approved sources beyond the family baseline, which research topics it uses plus
`custom_research_fields[]` for anything that doesn't fit the standard topic list, an optional
`niche_table` (named columns + which research topic/fact-card group supplies the rows — kept flexible
since every niche's data table is structurally different), and a radius override if different from the
family.

**Each service carries a real keyword profile, not a fill-in-the-blank pattern.** This was flagged
earlier as the single biggest keyword-quality gap between LPC and the fleet's own Site Factory product,
and it's resolved the same way Site Factory already does it — a real `keyword_map.json`-style profile
per service, not a template string:
- **Primary keyword** — the actual term this service should rank for (e.g. "AC repair," not "{service}
  in {city}"), checked once against real search volume/difficulty/CPC data through the same
  `infra_kw_score()` formula (Demand × Winnability × LeadValue) the fleet already uses for its own
  city-expansion decisions. One shared scoring module, reused by both products, per CLAUDE.md's "one
  module per service, reused everywhere" rule — not a second, parallel keyword system built just for LPC.
- **Secondary keywords** — a short list of real, related terms (long-tail and semantically related
  variants) the writer contract can naturally work into body copy and FAQ questions where a fact
  actually supports it. These guide *phrasing*, not content — they never count as a source a claim can
  be grounded in; the writer contract's grounding rule (Section 4) is unaffected.

**Validated once per niche, not once per page.** The expensive part — the real search-data lookup — runs
a single time when a niche's service is authored, not per city or per client. Every client's city pages
for that service then reuse the same validated primary/secondary keywords, with the city name appended
at generation time (e.g. "AC repair" → "AC repair in Frisco, TX"). This keeps the real-data cost bounded
to the handful of niches in the library, not the thousands of pages built from them.

**Compliance presets** are their own named, reusable config (banned-phrase lists + validator-rule
toggles, e.g. "contractor" = show license/no unverifiable pricing, "medical" = no outcome claims) —
referenced by family, swappable per niche if one is a genuine outlier.

**Authoring:** a structured Platform-Owner-only admin form (not raw YAML editing), with a
**"duplicate nearest niche"** button as the concrete version of "copy the nearest niche to start a new
one." Lives at the existing `admin/niche_profiles.php` nav destination.

---

## 3. Research & fact-card system

**Two distinct input types**, not one unified concept:

- **Fact cards** — for anything needing a citation. Shape per the data model above. Topics are fixed
  lists: 7 city topics (neighborhoods 24mo, permits 6mo, utilities 12mo, hoa 12mo, hazards 12mo,
  seasons 24mo, civic 24mo) and 7 service topics per niche (authority, problems, cost-drivers, options,
  maintenance, urgent-steps, niche-table).
- **Datasets** — flat provider output (Census, NOAA, FEMA), no citation ceremony, cached per
  `(city, provider)`.

**Sharing key:** `(scope, scope_key, topic)` — e.g. `(city, frisco-tx, permits)` shared across every
client serving Frisco; `(service, hvac, authority)` shared across every HVAC client. Research is never
run speculatively — only triggered when a real client's city+service combination actually needs it.

**Lifecycle:** a card stops being `active` for one of three reasons — expiry, Platform Owner rejection,
or an agency flag — and all three produce the same downstream effect: excluded from new-page reuse, and
any already-published page referencing it gets its "facts current" flag flipped. One mechanism, three
human-facing reasons. Refresh is **lazy** (re-researched on next real demand), not a proactive
background sweep — a scheduled sweep is a reasonable later addition, not needed day one.

**Source priority:** official (.gov/city/county/utility/state board) → the niche blueprint's approved
list → reputable local sources. Never competitors, lead-reselling directories, or AI content farms.

**Minimum-facts gating** (3+ for neighborhoods, 2+ for permits/utilities/hoa/hazards, 2+ for authority)
is what feeds the block-selection rule that a city block only appears if its facts actually exist.

---

## 4. Writer contract

**Inputs per block:** the block's writing instructions, its relevant fact cards, any datasets it needs,
the client's profile facts, and the page's city + service.

**The one rule:** every specific, checkable claim — a place name, a number, a date, a rule, a quote, an
organization, a statistic — traces back to a fact card, a dataset, or the client's profile. General
statements that assert nothing specific need no reference. Nothing comes from the model's own general
knowledge, ever.

**Output:** block text plus which `fact_ids` backed which claims.

**At render:** fact-id tags are stripped before display; the block's source line is built from the
cited cards' titles and URLs.

Two validator/lifecycle behaviors are direct consequences of this rule, not separate design: the
**Wrong city** check is mechanical (every cited fact's `scope_key` must match the page's own city), and
**Regenerate** produces different wording each time since the contract only requires correct facts,
never repeated phrasing.

---

## 5. Block library & page structure

**Pools:**
- **Spine (always present, no swap — not drawn from a pool):** Hero, [Service] in [City] overview
  (links to the client's main service page), FAQ, Service area + links.
- **City pool (use 5–6):** climate data, housing stock, neighborhoods guide, permits/local rules,
  utilities, HOA rules, seasonal guide, local hazards, reviews from [City] (client-supplied).
- **Service pool (use 3–4):** common problems, cited expert guidance, niche data table, process, cost
  factors, why choose us, repair-vs-replace, maintenance, before-the-tech-arrives.

**"Optional" describes selection, not importance.** City-pool and service-pool blocks are optional only
in *which specific ones* get chosen for a given page — the counts themselves (5–6 city, 3–4 service)
are mandatory on every page, never reduced. These blocks typically carry more of a page's unique,
ranking-relevant content than the fixed spine, not less: the spine is necessarily similar page to page
since it's structural, while pool blocks are where the real city-specific differentiation lives.

**Classification:** Editable (AI-written), Automatic (data-built), Client-supplied.

**Selection rules:** city blocks provide ≥50% of body words; the same service across a site's cities
shares ≤50% of its service-block choices (rotated); a city block only appears if its facts/data support
it; middle-block order varies per page.

**Page frame:** URL `/service-area/[city]/[service]/`; title/meta/H1 templates; breadcrumbs with
`BreadcrumbList` schema (**currently missing — needs building**, alongside LocalBusiness/Service/FAQPage
schema and, where the client has real reviews, `AggregateRating` schema built directly from
`reviews_by_city` — not just displayed as prose, since structured rating data is what actually earns a
star-rating snippet in search results); 12–14 blocks (4 spine + 5–6 city + 3–4 service); 900–1,500 body
words; a source line on every research/data-fed block; images (hero + 1–2 in-body + data chart + an
**interactive service-area map embed** rather than a static rendered image, WebP, lazy-loaded except
hero, client photos first); two CTA banners (after the 3rd middle block, and before the FAQ), each
offering **both click-to-call and a short quote-request form** (name, phone, one-line job description) —
not phone-only, since a meaningful share of visitors prefer not to call; a **sticky mobile click-to-call
bar**, pinned to the bottom of the screen regardless of scroll position — theme-independent, shipped in
the same bundle as the page's HTML and stylesheet (Section 9), since most WordPress themes won't provide
this natively and it's one of the highest-converting elements on a local-service page; a **self-canonical
URL** on every page, and inclusion in the client's actual sitemap — respecting whichever SEO plugin
they're already running (Yoast, RankMath, AIOSEO, core WP) rather than fighting it, with the plugin's own
sitemap as a fallback when the client has none of those installed.

---

## 6. Block/page functionality — generate, lock, edit

**Lock has one universal meaning:** freezes everything about the block — content, and for an Automatic
block, its data snapshot too — until manually unlocked. No per-type special cases.

| Action | Editable | Automatic | Client-supplied |
|---|---|---|---|
| Lock | ✓ freezes content | ✓ freezes content + data | ✓ freezes content |
| Edit | ✓ full text | ✓ intro text only | ✓ full content |
| Regenerate | ✓ rewrites from facts | ✓ re-fetches data + rewrites intro | ✗ nothing to regenerate |
| Swap (same pool) | ✓ if unlocked | ✓ if unlocked | ✓ if unlocked |

Spine blocks have no Swap at all — nothing to swap to.

**Generation is never silent.** Adding cities/services to a client opens a confirm dialog first —
*"Generate N pages?"* with a breakdown of how many cities already have cached research (faster) vs. need
new research, a time estimate, a cost estimate, and Cancel/Generate. The same dialog pattern applies to
any bulk AI action (e.g. bulk regenerate), not just first-time generation.

**Progress feedback** during the run is a live per-page status list — stage name only (Queued →
Researching → Writing → Validating → Done/Needs review), no fact-level detail — plus an overall counter
and a final summary that calls out partial failures by name rather than burying them. Dismissible and
backgroundable; a long job never traps the user on the page.

---

## 7. Validator

14 checks, triaged by actual severity:

| Tier | Checks |
|---|---|
| **Large** (real legal/trust/platform-wide SEO risk) | Unsupported claim, Wrong city, Client facts mismatch, Banned phrases, Invented prices, Invented reviews, Quotes, Uniqueness, Service radius |
| **Medium** (real but recoverable, contained to one page) | Stale facts, Cannibalization, FAQ schema mismatch |
| **Small** (deferred per design rule #1 — not v1) | Sources, Block mix |

Runs automatically on every `draft_hash` change; results feed the page-status popover.

**One override mechanism:** Agency Admin only, per-check, requires a typed reason, logged to the audit
trail, tied to the specific `draft_hash` it was granted against — any further edit invalidates it.

**Hard exception — never overridable by anyone, because there's no legitimate reason to want to:**
Unsupported claim, Invented prices, Invented reviews, Wrong city, Client facts mismatch, Quotes,
compliance-preset banned phrases. Everything else uses the one uniform override rule above.

Service radius and Stale facts usually resolve the easy way (confirm the city; re-run research) rather
than needing an override at all.

---

## 8. Page / content lifecycle

**Actions:**
- **Edit** a block → saves, **auto-locks the block**, `origin = edited`, recomputes `draft_hash`.
- **Regenerate / Swap** — unlocked blocks only, recomputes `draft_hash`.
- **Validate** — automatic, on every `draft_hash` change.
- **Override** — per the validator rule above.
- **Approve** — requires validation current + all checks pass/overridden. If the effective
  approval-gate setting is on for this client, triggers `pending_platform_review →
  pending_agency_review`; if off, flips straight to `approved`.
- **Publish** — only from `approved`. Snapshots into a new immutable revision, updates
  `live_revision_id`, bumps `site_rev`, status → `live`.
- **Roll back** — copies an old revision's blocks into the draft, then must go through
  Validate → Approve → Publish again. Never an instant revert — there's no path to live that skips
  validation, not even through history.
- **Unpublish** — status → `unpublished`, `site_rev += 1`. Nothing is deleted.

**Editing an already-live page follows the exact same rules as a brand-new page** — no special case.
The live revision is frozen and separate from the draft; an edit just makes `draft_hash` diverge from
it (lighting up the "unpublished changes" flag). Getting those edits live goes through the same
Validate → Approve → Publish sequence, including re-entering the human approval gate if it's on for
that client — the fiftieth edit follows the same path as the first publish.

**Approval gate scope:** `requires_approval_gate` defaults at the agency level, with a **per-client
yes/no override** (no finer granularity) — an agency can run the gate for most clients but turn it off
for one simple, trusted client, or vice versa. Agency-Admin-only setting, lives on the client's own
Overview tab.

---

## 9. Publishing & plugin sync

**Rendering moves server-side** — this reverses the current architecture, where the WordPress plugin
itself contains the block renderer. The server now computes finished HTML (plus a small stylesheet
carrying brand colors, the sticky mobile call bar, and the quote-request form from Section 5 — all
theme-independent, none of it relying on the client's WordPress theme to provide it) per page; the
plugin's job shrinks to fetch, cache, and place it via its existing URL rewrite. This is what makes "add
a block type without updating any client's site" literally true, and it minimizes the risk from
already-installed plugin copies nobody controls the version of.

**The boundary between what the theme controls and what we control is deliberate, not incidental.**
Plain content — headings, paragraphs, lists, the bulk of every block — ships with zero custom CSS
specifically so it inherits the client's theme completely: their fonts, colors, spacing, and heading
styles apply automatically, the same as any other page on their site. Only the small, named exception
set above (sticky call bar, CTA buttons, quote-request form, trust bar, map embed) gets the engine's own
scoped, brand-colored CSS, because no generic theme could be expected to style those correctly on its
own. Most of a page's look comes from the client's real site; a handful of functional elements come from
us.

**Sync is poll-based, not push-dependent.** The plugin checks in periodically (WP-cron) carrying its
last-known `site_rev`; if the server's current `site_rev` is higher, it returns only the pages changed
since then. Push-on-publish is an optional fire-and-forget "wake up now" ping — if it fails (site down,
firewalled), nothing breaks, because the next scheduled poll always catches up. The system's
correctness depends only on the poll.

**Offline fallback stays as-is** — already serves last-cached content correctly when the server is
unreachable.

**Health reporting, kept lightweight:** plugin version, last successful sync time/`site_rev`, a
serve-success flag — enough for a dashboard tile, not a telemetry platform.

**Auth:** move the API key from the URL into a request header over HTTPS. HMAC signing deferred.

**Uninstall:** a standard WP hook clears all plugin options on removal.

**Drip publishing** is a rate limit on the *Publish* action upstream, not separate sync-layer logic.

---

## 10. Roles & capabilities

Four roles, **per-person user accounts** (not shared logins per agency/client, which is today's model).

| Capability | Platform Owner | Agency Admin | Agency Editor | Client Viewer |
|---|---|---|---|---|
| Edit / lock / regenerate / swap a block | any client | own agency's clients | assigned clients (default: all, unless restricted) | — |
| Approve a page | ✓ | ✓ | ✓ | — |
| Publish a page | ✓ | ✓ | only if agency allows (default **off** — Admin must opt in) | — |
| Override a validator check (outside the hard exceptions) | ✓ | ✓ | — | — |
| Roll back to a prior revision | ✓ | ✓ | assigned clients | — |
| Flag a fact card as wrong | ✓ | ✓ | ✓ | — |
| Review / edit / reject a fact card | ✓ only | — | — | — |
| Manage niche library (blueprints, families) | ✓ only | — | — | — |
| Manage other agencies | ✓ only | — | — | — |
| Manage own agency's users / client assignment | ✓ | ✓ | — | — |
| Set per-client approval-gate override | — | ✓ | — | — |
| Set whether Editors can publish | — | ✓ | — | — |
| White-label settings / billing | — | ✓ | — | — |
| Set a client's custom billing override | ✓ only | — | — | — |
| Impersonate (logged, bannered) | ✓ only | — | — | — |
| View pages / reports | all | own agency | assigned clients | own site only |
| Comment / "Request an update" (same mechanism, different tag) | ✓ | ✓ | ✓ | own site's pages |

Client Viewer never sees generate/regenerate/prompt/research/AI language anywhere, and doesn't see
revision history at all (not even read-only) — it's an internal mechanism, not something a client needs.

Both the per-client approval-gate override and Editor-publish eligibility can also be set from the
onboarding wizard's Review speed choice (Section 15) at client creation — same underlying fields, a
second entry point for convenience at the moment it's most relevant, not a separate setting to keep in
sync with the Team/Overview-tab controls.

### Mapping onto the existing admin nav

Most of this slots into navigation that already exists (`admin/lpc_admin_layout.php`,
`admin/lpc_nav.php`) rather than requiring a new app:

- **Unchanged:** All Clients, New Client, Approvals (badge already keyed to approval_stage by role),
  My Branding (Admin-only), the entire Platform section (Agencies, **Niche Profiles** — already a real
  destination, Settings, Platform Information), Client Viewer's single Reporting screen, and the
  per-client sub-tabs — **Locations & Services** is where the page/block table UI lives.
- **New additions needed:** a **Team** page (Agency-Admin-only, manage Editors + client assignment); a
  Platform-Owner-only **Fact Cards** review queue under the Platform section; field-level restriction on
  the existing New Client wizard's billing fields (hidden/disabled for Editors, same pattern as
  Automatic blocks' "edit intro only"); the per-client approval-gate override lives inside the client's
  own Overview tab, not a new nav item.

---

## 11. UI by role

**Operational roles (Platform Owner, Agency Admin, Agency Editor):** one row per page in a table, with
checkbox bulk-select — modeled directly on the fleet's own Go-Live grid (`admin/rollout.php`), which
already proves this pattern at scale: a dot-column per pipeline stage instead of one collapsed status,
plus quick-filter buttons across the top (All / Ready / Needs Review / Live, mirroring that grid's
All/Ready/Scheduled/Live/Not-ready-yet). Since PlusCities' pipeline is genuinely linear (research →
written → validated → approved → published → live, spec Section 8), each stage gets its own dot:
Researched, Written, Validated, Approved, Published, Live — scanning 50 pages to see exactly where
things are stuck becomes instant, instead of opening 50 individual popovers.

Most dots are plain — binary, nothing more to say. **Validated is the one exception**, since it isn't
one thing but up to 12 checks (Section 7); that dot alone stays clickable, opening the same **popover**
behavior as before: which specific check failed, in plain language, facts-current flag, sync status, and
quick actions (Approve, Override, link to revision history). Matrix for fast scanning, click-through only
where there's real sub-detail to see — not an either/or with the popover, both apply.

Clicking the row itself opens that page's block list — same row pattern one level down — where each
block row exposes Edit/Lock/Regenerate/Swap per the classification-based action matrix, "where it makes
sense." Blocks don't get the dot-matrix treatment: a block doesn't move through a multi-stage pipeline
the way a page does, it just has a locked/unlocked state and a classification, already compact as a text
badge next to the row. Bulk actions (approve/publish/regenerate by selection, city, service, or all) use
the same checkbox-row pattern throughout.

**Client Viewer gets a different, lighter surface** — not the operational table. A simple list or card
view of their own live pages, with engagement info where available (e.g. GSC clicks) and a "Request an
update" action. They are never shown the management console.

---

## 12. SEO & doorway safeguards

The product's core external risk: Google's "scaled content abuse" policy exists specifically to target
templated, AI-written pages generated at volume across many sites — the downside isn't lower ranking,
it's a sitewide penalty. Treat this section as load-bearing, not a quality nice-to-have.

**Already built into the design above, and why each one matters here specifically:**
- Fact-grounded writing (writer contract) is the actual substance-over-manipulation signal.
- Uniqueness, Cannibalization, Service radius, and no-fake-local-presence are the direct technical
  countermeasures to the doorway-page pattern.
- Rendering inside each client's own real WordPress theme/domain (not a shared template farm or
  lookalike-domain network) is a structural advantage most programmatic-SEO products don't have.

**Additional recommendations:**
- Build `BreadcrumbList` schema (currently missing).
- Treat the 50% uniqueness threshold as a floor, not a target — real differentiation comes from having
  enough fact cards per city, not from tuning the percentage.
- Stage new pages `noindex` until they clear validation, then let them become indexable as part of the
  normal publish drip.
- Prioritize the GSC "crawled – currently not indexed" monitor earlier than strict Tier 2 — it's the
  cheapest early signal that something is being treated as low-value before it spreads across pages.
- NAP consistency and real reviews matter more for local ranking than on-page content and are an
  agency/client responsibility (matching their real Google Business Profile), not something the engine
  alone can produce — make this explicit in onboarding, not assumed.
- Backlinks/off-page authority are outside what this engine can produce — set agency expectations
  accordingly.

---

## 13. Billing

**Scoped per client/site, not per agency** — fixes a real bug in the current system, where
agency-level billing means non-payment takes down *every* client that agency has at once.

- **Real tier catalog**, not free text: $300/25 pages, $600/50, $1200/100 (per client/month).
- **Wholesale/volume pricing** is one discount field on the agency, applied automatically to every site
  they bill — not a separate pricing system.
- **Stripe Connect / rebilling is deferred**, not built in v1 — agencies invoice their own end-clients
  however they already do today; the platform only collects from the agency per client site.
- **Overage** is a hard stop with an upgrade prompt — no metering or proration.
- **Cancellation** is per-client: the agency picks 301 (to the main service page) or 410 for that
  client's pages, applied on the plugin's next scheduled sync, with the Service Area footer link removed
  at the same time. The existing 15-day grace period is kept, rescoped from agency-wide to per-client.
- **Platform Owner override:** a custom price/page-limit/comp field settable only by Platform Owner on
  any individual client, which replaces the standard tier/wholesale calculation outright when set.

---

## 14. White-label

**Must-fix, non-negotiable** (anything a client — not the agency — actually sees):
- The WP plugin's name and description shown in the client's own WordPress admin (today hardcoded
  "Landing Page Client," unrenameable) — baked from the agency's branding when the plugin is generated.
- Any emails sent to the client.
- The plugin's current "real AI content generation" string — a compliance bug against design rule #8,
  fixed regardless of white-labeling.

**Wiring job:** swap the admin sidebar's hardcoded "PlusCities" for the agency's already-stored
logo/display_name on agency- and client-facing screens.

**Deferred:** a custom dashboard domain for the agency's own internal admin login — real DNS/SSL
infrastructure work, and not a branding leak the way client-facing plugin text is.

---

## 15. Onboarding / import flow

A short linear wizard, reusing components already specified above rather than inventing new mechanics:

1. **Business identity & NAP** — name, domain, contact, per-hub address/phone, license, years in
   business, hours.
2. **Pick a niche** — one dropdown; determines schema type, compliance preset, services, research
   topics automatically.
3. **Pick cities and services as two separate lists**, cross-multiplied by default — this covers
   "bulk expansion" without it needing to be a separate feature. Cities outside the service radius get
   the same confirm-to-override as the Service Radius check, not a silent block.
4. **Billing tier**, auto-suggested from the city×service count against the tier catalog.
5. **Review speed — Standard or Fast-track.** A single per-client choice. Standard routes every page
   through the full `pending_platform_review → pending_agency_review` sequence (Section 8). Fast-track
   sets this client's approval-gate override to off and, if the agency has generally allowed it, grants
   this client's pages Editor-publish eligibility (Section 10) — a passing validation goes straight to
   Approved. This changes only the human-review path. It never changes block counts or page depth
   (Section 5), and never changes the validator's checks or its hard-exception list (Section 7) — those
   are what actually protect ranking, and they run identically either way. This is also settable later
   from the client's Overview tab / agency Team settings — the wizard is just a second, better-timed
   entry point to the same fields.
6. **Review & generate** — the confirm-with-estimate-then-live-progress flow from Section 6; nothing new
   here, onboarding just hands off into it.
7. **A soft checklist, shown but not enforced:** link the client's main service pages to their new city
   pages; match their Google Business Profile service areas to the site's cities.

**Deferred, not v1:** CSV bulk import. Manual two-list entry is enough until an agency's scale makes
typing a real burden. Adding a city/service to an *existing* client later reuses the exact same
two-list mechanism — no separate "add city" feature.

---

## Worked example — one scenario traced through every contract

Per design rule #10, a contract isn't locked until it has a real worked example, not just prose. This
is the example that completes Phase 0: one client, one city, one page, traced through every component
above, with consistent IDs that actually reference each other.

**Scenario:** Elite Air HVAC (an HVAC client in the `home-trades` family), Frisco TX, AC Repair.

**1. Fact card** (city scope, cited claim):
```json
{
  "id": "fc_frisco-tx_permits_001",
  "scope": "city", "scope_key": "frisco-tx", "topic": "permits",
  "claim": "Frisco requires a mechanical permit before replacing a residential air conditioning system.",
  "value": { "permit_required": true, "office": "Frisco Building Inspections" },
  "source_url": "https://www.friscotexas.gov/237/Building-Inspections",
  "source_title": "Building Inspections — City of Frisco",
  "quote": "A mechanical permit is required for HVAC replacement",
  "checked_at": "2026-11-18", "expires_at": "2027-05-18", "status": "active"
}
```

**2. Dataset** (shared, no citation — the provider is the source):
```json
{ "city_id": "frisco-tx", "provider": "census_acs", "fetched_at": "2026-09-14",
  "values": { "income_median": 129482, "home_value_median": 512300, "year_built_median": 2008 } }
```

**3. Client record excerpt** (NAP/license/billing/approval fields actually populated):
```json
{
  "client_id": "elite-air-hvac", "agency_id": "agy_coastal-marketing", "niche_id": "hvac",
  "domain": "eliteairhvac.com", "site_rev": 13, "plugin_rev": 13, "radius_minutes": 30,
  "identity": { "business_name": "Elite Air HVAC",
    "hubs": [{ "address": "412 Main St, Frisco, TX 75034", "phone": "(972) 555-0148" }] },
  "licenses": [{ "number": "TACLB123456C", "state": "TX", "trade": "HVAC Contractor" }],
  "years_in_business": 12, "banned_phrases": ["24/7"],
  "billing": { "tier": "50", "custom_override": null },
  "approval_gate_override": null,
  "target_cities": [{ "city_id": "frisco-tx", "drive_minutes": 12, "confirmed_by_client": false }]
}
```

**4. Block content — one of each classification, on the same page.**

Editable, as generated (fact tag inline) vs. as rendered (tag stripped, source line built):
```json
// generated
{ "block_type": "permits", "pool": "city", "classification": "editable", "locked": false,
  "origin": "generated", "fact_ids": ["fc_frisco-tx_permits_001"],
  "content": { "heading": "Permits for AC Repair in Frisco",
    "body": "If your AC repair in Frisco turns into a full replacement, the city's Building Inspections office requires a mechanical permit first [[fc_frisco-tx_permits_001]]. Elite Air HVAC pulls this permit on your behalf." } }
```
```html
<!-- rendered -->
<h3>Permits for AC Repair in Frisco</h3>
<p>If your AC repair in Frisco turns into a full replacement, the city's Building Inspections office
requires a mechanical permit first. Elite Air HVAC pulls this permit on your behalf.</p>
<p class="lpc-source">Source: Building Inspections — City of Frisco</p>
```

Automatic (data-built, dataset reference instead of a fact card):
```json
{ "block_type": "climate_data", "pool": "city", "classification": "automatic", "locked": false,
  "origin": "data", "dataset_refs": [{ "city_id": "frisco-tx", "provider": "noaa_climate" }],
  "content": { "intro": "Frisco's long, hot summers put real strain on AC systems.", "chart_ref": "charts/frisco-tx_noaa_climate.png" } }
```

Client-supplied (locked by default — nothing for the writer to ever touch):
```json
{ "block_type": "reviews_from_city", "pool": "city", "classification": "client-supplied", "locked": true,
  "origin": "edited",
  "content": { "reviews": [{ "author": "Dana R.", "rating": 5, "text": "Fixed our AC same day during the July heat wave." }] } }
```

**5. Page record** — the two status axes side by side:
```json
{
  "page_id": "pg_eliteairhvac_frisco-tx_ac-repair", "client_id": "elite-air-hvac",
  "city_id": "frisco-tx", "service_key": "ac-repair", "slug": "/service-area/frisco-tx/ac-repair/",
  "status": "approved", "approval_stage": "approved",
  "draft_hash": "a71f9c3e...",
  "validation": { "hash_checked_against": "a71f9c3e...",
    "results": [{ "check": "unsupported_claim", "result": "pass" }, { "check": "service_radius", "result": "pass" }, { "check": "uniqueness", "result": "pass" }] }
}
```

**6. Override event** (an agency judgment call, logged per the validator rule):
```json
{ "actor": "agency_admin:maria@coastalmarketing.com", "action": "override_validator_check",
  "target": "pg_eliteairhvac_frisco-tx_ac-repair / cannibalization",
  "detail": "Frisco and the neighboring Prosper page cover genuinely different territory per the client's own service map.",
  "timestamp": "2026-11-21T15:05:00Z" }
```

**7. Publish → revision:**
```json
{ "page_id": "pg_eliteairhvac_frisco-tx_ac-repair", "number": 1, "hash": "a71f9c3e...",
  "author": "agency_admin:maria@coastalmarketing.com", "created_at": "2026-11-21T15:10:00Z",
  "snapshot": { "title": "AC Repair in Frisco, TX | Elite Air HVAC", "blocks": ["...13 blocks..."] } }
```
`site_rev` on the client record moves 13 → 14.

**8. Plugin sync exchange:**
```
GET /lpc-sync/manifest?site_rev=13
Authorization: Bearer <api_key>        ← header, not URL

→ 200 { "site_rev": 14, "changed_pages": [
    { "page_id": "pg_eliteairhvac_frisco-tx_ac-repair", "revision": 1, "url": "/service-area/frisco-tx/ac-repair/" }
  ]}

GET /lpc-sync/page/pg_eliteairhvac_frisco-tx_ac-repair
→ 200 { "html": "<section>...finished HTML...</section>", "style": "<style>...brand colors...</style>",
        "head": { "title": "...", "meta_description": "...", "schema": [...] } }
```
Plugin caches it by `page_id`, serves it on the next matching request, updates its own stored
`site_rev` to 14.

**The narrative, end to end:** Agency adds Frisco + AC Repair for Elite Air HVAC → confirm dialog shows
Frisco needs new research (no prior client's used it) → Researching produces the fact card and dataset
above → Writing produces the three blocks above, citing them → Validating runs all 12 built checks;
Cannibalization flags against a nearby Prosper page, Agency Admin reviews and overrides it with a
reason → Approve (agency's gate is on) walks through platform review then agency review → Publish
snapshots revision #1, `site_rev` 13→14 → the plugin's next poll sees the bump, fetches just this one
changed page, caches and serves it → live at `eliteairhvac.com/service-area/frisco-tx/ac-repair/`.

---

## Build order — stepping stones to the final product

**Phase 0 — Lock the contracts (documents only).** Data model shapes and the status/revision model;
the fact-card schema; the block content-JSON contract; the module interfaces (research → writer →
validator → publish); the plugin↔server wire format; the role/capability verb list. Each needs one real
worked example, not just prose.

**Phase 1 — Walking skeleton.** One niche (HVAC), one city, every module, minimum content: one data
provider + one research topic producing real cited fact cards; the writer contract against them; the 4
spine blocks + 2–3 city/service blocks; the validator's first checks (Unsupported claim, Uniqueness,
Block mix — though Block mix is now deferred per Section 7, substitute another Large check); manual
edit/lock/regenerate/swap; draft → approved → live, one revision, one rollback exercised; publish →
plugin sync onto a real WordPress install, confirmed live. **Done when:** one real page has gone
research → written → validated → approved → published → rendered live, every claim traceable.

**Phase 2 — Scale the content inside the proven contracts.** Remaining data providers (NOAA to prose,
FEMA); remaining block types toward the full pools; the 6 families + remaining niches; remaining
validator checks; the full role set + UI by role; SEO completion (BreadcrumbList, images); doorway
safeguards enforced, not just intended. Each new block type's actual HTML structure is decided
individually during this phase, against real rendered examples on a real client theme — not fully
pre-specified in Phase 0's contracts, which only fix the block's *content* shape (Section 1's `content`/
`fact_ids` fields), not its markup.

**Phase 3 — Business layer.** White-label fixes (Section 14); the real billing catalog and correct
cancellation behavior (Section 13); agency dashboard reporting; onboarding polish (Section 15).

**Phase 4 — Tier 2/3 differentiators**, deferred by design, not by debt: GSC indexing-status monitoring
beyond click count, white-labeled PDF reports, **lead attribution**, CSV bulk expansion, a Client Approver
role, scheduled fact-card refresh, expansion suggestions, Spanish pages, webhooks, audit-log UI. Because
every module here was built modular from Phase 0, adding any one of these later is "add a file," not a
rebuild.

**Lead attribution, mechanism (deferred to this phase, decided now so it's not re-derived later):** an
opt-in, per-client tracking number (real recurring cost — never on by default) plus an agency-configured
attribution window in days. A visitor landing on a PlusCities page sets a cookie; for the window's
duration, the client's phone number displays as the tracking number anywhere on their WordPress site, not
just the landing page — which means this is the one feature that needs the plugin to act site-wide rather
than only on its own rendered pages. The swap stays a theme-independent display snippet (same pattern as
the sticky call bar, Section 5/9), never a PHP-level rewrite of the theme's own output. **Two rules that
must hold regardless of implementation:** (1) reuse the fleet's existing CallTrackingMetrics integration
for number provisioning — not a second call-tracking client, per CLAUDE.md's "one module per service"
rule; (2) the tracking number is display-only and must never appear in the page's schema/JSON-LD or any
citation-facing data — the real number stays authoritative everywhere NAP consistency actually matters
(Section 12), since that's a real local-ranking factor this feature must not quietly undermine.
