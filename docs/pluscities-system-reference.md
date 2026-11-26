# PlusCities — System Reference (as-built, 2026-11-26)

## 0. How to use this doc

This is **as-built ground truth**, verified directly against the real code, the real SQLite DB, and real
client JSON files — last fully re-verified 2026-11-26, after a page-visual/block-architecture pass that
reshaped the block model into 4 real categories (spine/always-blocks/pool/furniture) and shipped 5 new
generated-image/chart furniture pieces (see §2.3a), on top of a 2026-11-24 production-readiness push that
shipped a real approval workflow, real client cancellation, the admin panel's own domain, and more (see
§3.1, §3.2, §3.6, §4) — not the original design intent. For *why* something was designed a
certain way, or what's planned for later phases, read `docs/PLUSCITIES_FEATURE_SPEC.md` (750 lines, the
original Phase 1-4 roadmap). Trust **this** doc for *what's actually there today*; trust the spec for
*intent and roadmap*. In at least two places (noted in §4) the shipped code deliberately diverges from
the spec — that's not drift to "fix," it's a real decision made during the build.

Two other docs exist that describe a **different, older system** sharing this repo: `docs/lpc-vs-site-
factory-boundary.md` and `docs/landing-page-clients-onboarding.md`. Both are about **LPC** ("Landing Page
Clients"), the system PlusCities is replacing. They remain accurate for what they describe — LPC's own
separate delivery server, its own renderer, its own onboarding wizard — but they are not about PlusCities
and should not be read as describing the current product. See §6.

Full history of the LPC→PlusCities discovery and rehoming work is in memory
(`project_lpc_to_pluscities_migration`), not repeated here — this doc is the current-state reference, that
memory is the narrative of how we got here.

## 1. What PlusCities is, in one paragraph

PlusCities is a multi-tenant local-SEO landing-page platform: **Platform → Agency → Client → Page →
Block**. An agency onboards a client (a real local-service business); the client gets one page per
city×service combination, each page built from a fixed set of content blocks drawn from a niche-specific
pool; pages go through a draft → validate → approve → publish lifecycle; published pages are served to
real visitors through a thin WordPress plugin on the client's own site, which fetches pre-rendered HTML
from this platform over a polling API. Real traffic is served today via `admin/pluscities_sync.php` +
`wp-plugin/pluscities-delivery/` — **not** the old LPC file-rsync-to-a-separate-delivery-server pipeline.
The admin panel itself is reachable at `https://app.pluscities.com` as of 2026-11-24 (real Cloudflare
Origin CA cert, `Full (strict)` SSL, raw-IP/plain-HTTP access 301-redirected) — previously raw-IP-only with
a self-signed cert issued under an unrelated niche site's name. `pluscities.com` itself remains a separate,
already-live WordPress marketing site, not this codebase.

## 2. Data model

### 2.1 SQLite — `admin/infra/state/pluscities.db`

Defined in `admin/infra/lib/pluscities_db.php`. Deliberately a **separate** SQLite file from the fleet's
own `fleet.db` — the file's own comment says this split exists specifically to avoid repeating LPC's
"shared database" coupling mistake (see `docs/lpc-vs-site-factory-boundary.md` §2). WAL mode, lazy static
PDO singleton, 5s busy timeout.

**`fact_cards`** — one externally-sourced, cited claim per row.
```
id            TEXT PRIMARY KEY   -- "fc_<scope_key>_<topic>_<hash>"
scope         TEXT               -- city | service | niche (only "city" has real rows today)
scope_key     TEXT               -- e.g. "frisco-tx"
topic         TEXT               -- e.g. "permits", "neighborhoods", "authority"
claim         TEXT
value_json    TEXT
source_url    TEXT
source_title  TEXT
quote         TEXT               -- verbatim substring, <15 words, checked at research time
checked_at    TEXT
expires_at    TEXT               -- per-topic window, e.g. permits=6mo, climate_data=60mo
status        TEXT DEFAULT "active"   -- active | stale | rejected
```
Index: `(scope, scope_key, topic, status)`.

**`datasets`** — uncited provider data, keyed `(city_id, provider)`. No citation fields at all — "the
provider IS the source." Only one real provider wired today: `census_acs`.

**`pages`** — one row per city×service page.
```
page_id          TEXT PRIMARY KEY   -- "pg_<client_id>_<city_id>_<service_key>"
client_id        TEXT               -- -> pluscities_clients/<client_id>.json (file-based, not a DB FK)
city_id          TEXT
service_key      TEXT
slug             TEXT
status           TEXT DEFAULT "draft"     -- draft | approved | live | unpublished
approval_stage   TEXT DEFAULT "none"      -- none | pending_client_review | approved (real values as of 2026-11-24 — see §3.1)
live_revision_id INTEGER                  -- -> revisions.number
draft_hash       TEXT                     -- sha256 of concatenated block content_json, in position order
validation_json  TEXT
index_status     TEXT                     -- declared; empty in every real row today
```

**`blocks`** — one row per content block on a page.
```
id              INTEGER PRIMARY KEY AUTOINCREMENT
page_id         TEXT            -- -> pages.page_id
block_type      TEXT            -- "hero","overview","permits","faq","service_area","niche_data_table"...
position        INTEGER         -- render order
pool            TEXT            -- spine | city | service
classification  TEXT            -- editable | automatic | client-supplied
content_json    TEXT
fact_ids        TEXT DEFAULT "[]"     -- JSON array of fact_cards.id
dataset_refs    TEXT DEFAULT "[]"     -- JSON array of {city_id, provider}
locked          INTEGER DEFAULT 0
origin          TEXT            -- generated | edited | data | client (4 real values; column comment only names 3)
client_grounded INTEGER DEFAULT 0     -- added later via an additive ALTER TABLE, not in the original CREATE
```
Index: `(page_id)`.

**`revisions`** — immutable, insert-only publish snapshots. `(page_id, number)` primary key,
`snapshot_json = {"blocks":[...full block rows...], "slug": "..."}`, `hash` = the page's draft_hash at
publish time.

**`events`** — append-only audit log. `actor` (e.g. `"super_admin:admin"`), `action` (e.g.
`create_page`, `publish_page`, `swap_block`), `target` (e.g. `"page:<id>"`), `detail`, `timestamp`.
Written exclusively via `pluscities_log_event()`.

**`overrides`** — a validator-check exemption, keyed `(page_id, check_name, draft_hash)` — tying it to
the *exact* draft hash it was granted against means any further edit silently stops it applying, no
explicit revoke needed. **0 rows in the live DB today** — built, not yet exercised in practice.

> **The single most important, least obvious mechanic in this whole system:** `pages.status` is a
> **one-way ratchet** — `draft → approved → live → unpublished` — that never regresses on its own. A page
> that has gone live stays `status='live'` forever, even while a brand-new, unapproved edit sits on top
> of it. `pages.approval_stage` is the **separate, resettable** axis: it gets reset to `'none'` on *every*
> block mutation (edit, regenerate, swap, rollback), via one chokepoint function, `pluscities_touch_page()`.
> Real proof from the live DB: `pg_meaderestoration_bedford-tx_water-damage-restoration` has
> `status='live'` **and** `approval_stage='none'` *at the same time* — it was published once, then a block
> was edited afterward, re-requiring Validate → Approve → Publish without the live site going dark. This
> is correct-by-design, not corruption — don't "fix" a page you find in this state.

### 2.2 Client JSON record — `pluscities_clients/<client_id>.json`

File-based, not in SQLite. Built in `admin/pluscities_clients_save.php`. 3 real files exist today:
`elite-air-hvac.json`, `lonestar-restoration.json`, `meade-restoration.json`. Plus 3 demo-only files, built
2026-11-24 specifically for sales demos, deliberately separate from both the real clients above and the QA
suite's fixtures: `lone-star-comfort-heating-air-2.json`, `coastal-breeze-hvac-services.json`,
`clearwater-restoration-pros.json` (under the demo agency `northstar-digital-marketing-demo`).

| Field | Meaning | Notes |
|---|---|---|
| `client_id` | slugified business name, filename stem | join key into `pages.client_id` |
| `agency_id` | owning agency | scopes visibility everywhere |
| `niche_id` | → `niche_blueprints/<niche_id>.json` | resolved via `pluscities_niche_resolved()` |
| `domain` | production domain | used to build canonical/service URLs |
| `site_rev` | bump counter | the **sole** signal the WP plugin's manifest poll compares against |
| `plugin_rev`, `plugin_version`, `last_seen_at` | delivery-plugin handshake state | `plugin_version`/`last_seen_at` are real as of 2026-11-24 — see §3.5's health check-in |
| `theme`, `seo_plugin` | delivery-plugin health, added 2026-11-24 | Active WP theme name and detected SEO plugin (`Yoast SEO`/`Rank Math`/`All in One SEO`/`none`), reported alongside `plugin_version`/`last_seen_at` on every manifest check-in — see §3.5 |
| `site_suspended`, `site_suspended_at` | platform-level kill switch, added 2026-11-24 | Set by Platform Admin's "Suspend Site" action (`admin/pluscities_clients_save.php`); when `site_suspended` is true, `pluscities_publish_page()` refuses to publish for *any* actor role, including `super_admin`, until explicitly un-suspended. Distinct from `status`/`canceled_at` — no grace period, immediate unpublish of every live page. See §3.2/§3.7. |
| `class_prefix` | CSS/asset namespace, `lpc-<slug>` | delivery plugin |
| `radius_minutes` | default service radius | overridable per niche |
| `identity.business_name`, `identity.hubs[]` | NAP data | **only `hubs[0]` is ever read anywhere** — multi-hub is modeled, not consumed |
| `licenses[]`, `years_in_business` | stated directly as trusted client facts (never fact-id-tagged) | |
| `hours` | free text (mon_fri/sat/sun) | not actually read by the writer today |
| `banned_phrases[]` | client-level style denylist | passed into every writer prompt |
| `photos_by_city` | declared, empty `[]` in every real file | **no reader found anywhere** — planned, not implemented |
| `reviews_by_city` | client-supplied only, keyed by city_id | becomes `reviews_from_city`'s entire content, never touches the AI writer |
| `price_ranges` | `null` in every real file | feeds writer prompts if ever populated; enforced by the Invented Prices check |
| `billing.tier` | plan-tier id (e.g. `"50"`), not a dollar figure | |
| `requires_client_approval` | Per-client toggle, off by default | Replaced the old unused `approval_gate_override` placeholder 2026-11-24. When on, `pluscities_approve_page()` routes to `pending_client_review` instead of straight to `approved` — see §3.1/§3.2. Toggled from a button on each client's row in `pluscities_clients.php`, Agency Admin/Platform Admin only. |
| `status` | `active` \| `canceled`, added 2026-11-24 | Drives client cancellation — see §3.6. Default `active` on every existing client (missing key reads as falsy, same effect). |
| `canceled_at` | ISO timestamp or `null`, added 2026-11-24 | Set when `status` flips to `canceled`; the grace-period clock starts here. |
| `services[]` | slugs, must match the niche blueprint's `services[].slug` | |
| `target_cities[]` | `{city_id, drive_minutes, confirmed_by_client}` | |
| `url_base_path` | default `"service-area"` | builds page slugs |
| `branding` | logo/accent/dark + detected-color cache | resolution: override > detected > fallback (`#1C2B45`) |
| `images` | object keyed by slot_id (not an array, despite defaulting to `[]`) | |
| `hub_content` | `{top_text, footer_text}`, `{{business_name}}` token | renders the client's hub/listing page |

**Known drift (real, harmless today):** `lonestar-restoration.json` and `meade-restoration.json` are both
missing `hub_content` entirely, and 2 of 3 files are missing the newer `branding.*_override`/`detected_*`
fields — both were added to the save handler *after* those client files existed. Everything reads these
with `?? [...]` fallbacks, so nothing breaks, but **unlike the SQLite side's `ALTER TABLE` backfill
pattern, client JSON has no backfill mechanism at all** — a field added to the save handler only appears
on client files created after that point. Same pattern again as of 2026-11-24: client files created before
the `approval_gate_override` → `requires_client_approval` rename still carry the old, now-dead field name
as orphaned cruft (confirmed harmless — it was never read anywhere even before the rename) alongside the
new field, which gets added the first time that client's approval toggle is touched.

### 2.3 Niche blueprint model — three-level hierarchy

- **`niche_families/`** — 6 files (`home-trades`, `restoration`, `legal`, `medical-wellness`,
  `outdoor-cleaning`, `professional-services`), fixed and hand-authored, not user-creatable.
- **`niche_blueprints/`** — **only 3 real files exist**: `hvac.json` (family: home-trades),
  `water-damage-restoration.json` (family: restoration), `dental-practice.json` (family:
  medical-wellness, authored in UC11 testing).
- **`compliance_presets/`** — **only 1 file**: `contractor.json`.

Both pools are per-family (as of 2026-11-26 — see §2.3a for the fix): `family.default_city_blocks`
minus the niche's own `blocks_off[]` denylist, and `family.default_service_blocks` minus the niche's own
`service_blocks_off[]` denylist — e.g. home-trades' city pool is `climate_data, housing_stock,
neighborhoods, permits, utilities, hoa_rules, seasonal_guide, hazards, reviews_from_city` (9 types), its
service pool is `common_problems, cited_expert_guidance, niche_data_table, process, cost_factors,
why_choose_us, repair_vs_replace, maintenance, before_the_tech_arrives` (9 types) — shared identically by
`home-trades`, `restoration`, and `outdoor-cleaning` (all 3 describe genuine field-visit trades, real or
plausible). The other 2 placeholder families diverge: `medical-wellness` drops `before_the_tech_arrives`
only (8 types — "a technician arrives at your home" doesn't fit an office-visit business like a dental
practice); `legal` and `professional-services` drop all 3 contractor-specific blocks —
`before_the_tech_arrives`, `repair_vs_replace`, `maintenance` (6 types each — none of the 3 make sense
for an office/consultative business regardless of which specific legal or professional-services niche
eventually gets built). Every removal happened at the FAMILY level, not a per-niche denylist, since each
mismatch is true of the whole business category, not one specific niche — same reasoning
`medical-wellness` already applied to its own city pool (no `climate_data`/`permits`/`hoa_rules`/etc.,
none of which fit a medical office either).

A resolved niche blueprint (`pluscities_niche_resolved()`) contains: `schema_subtype` (real schema.org
type), `services[]` (with keyword-research data per service), `blocks_off[]`, `service_blocks_off[]`,
`research_topics[]`, `niche_table` (the hand-authored shared data table), `trust_bar_items[]`,
`blog_topics[]`, `image_slots[]`, `always_city_blocks[]`, `always_service_blocks[]`, and
`climate_chart_ids[]` (all added 2026-11-26, see §2.3a).

### 2.3a Block architecture: 4 categories, not 2 (added 2026-11-26)

A page-styling pass (2026-11-25/26) compared real PlusCities pages against the fleet's own proven
reference sites (baileyrestoration.com, wrenappliancerepair.com, gannmoldremediation.com) and found
PlusCities pages were missing whole content types those sites share, not just styling polish. What
shipped reshaped "spine blocks + two pickable pools" (§2.3 above) into four real categories:

1. **SPINE — 4, hardcoded, never pool, never swapped.** `hero`, `overview` (front) + `faq`,
   `service_area` (back) — built directly in `pluscities_create_page()`, identical for every niche.
   Unchanged from before this pass.

2. **ALWAYS-BLOCKS — auto-included, not pickable, varies by family, both sides of the pool.** New
   mechanism. A family's `always_city_blocks[]`/`always_service_blocks[]` are each subtracted from their
   respective `default_city_blocks`/`default_service_blocks` by `pluscities_niche_resolved()` before
   either pool is handed to any caller — a block is either always-on or pickable, never both, and the
   subtraction happens once so every caller (Add Page picker, new-client wizard, swap dropdown,
   `pluscities_create_page()` itself) sees an already-correctly-shaped pool. Populated per family from
   real pick-frequency data (21 live pages, gathered before deciding, all of them home-trades/
   restoration — no medical-wellness page had ever existed to validate against): `housing_stock` +
   `neighborhoods` always-on city blocks for the 5 "field visit" families (18/21 and 17/21 picks — safe
   there); `medical-wellness` only keeps `neighborhoods` always-on (`housing_stock` demoted back to
   pickable 2026-11-26 once a direct review found no real content tie-in between Census home-value/
   homeownership stats and a dental visit, unlike the natural fit for home-trades/restoration). `+climate_data`
   always-on only for the 5 families that already carried it in their city pool (not `medical-wellness` —
   its family never had `climate_data` to begin with, so nothing is force-added to a niche the library
   already excludes it from); `common_problems` + `process` always-on service blocks for all 6 families
   (15/21 and 14/21 picks). The service side started 2026-11-26 as one global constant
   (`PLUSCITIES_ALWAYS_SERVICE_BLOCKS`) specifically because the service pool wasn't family-customizable
   yet at all — fixed the same day the gap was flagged (see the closing note below).

3. **POOL — what's left, genuinely optional, capped and pickable.** Same mechanism as before
   (§2.3), just a shorter remaining list now that the near-universal picks moved to always-blocks. A
   real bug fixed in the same pass: the Add Page/new-client pickers rendered the pool in a fixed catalog
   order with a hard "choose up to N" cap enforced by JS — whoever built a page checked top-down until
   the cap, so whatever sat near the end of a long list was starved regardless of real value
   (`before_the_tech_arrives` was picked on just 1 of 21 real pages, literally last in the list). Fixed
   by shuffling the remaining pool's checkbox order per page load in both pickers.

4. **FURNITURE — not `blocks` table rows at all; rendered unconditionally by the production loop,
   keyed on `city_id` (sometimes `+niche_id`), never a pick, never regenerated per-page.** This is
   where all the new image/chart work lives — see the table below. Inserted at fixed points in the
   render loop (`admin/pluscities_sync.php` and `admin/pluscities_page_styling.php`, both updated
   identically): `trust_bar` right after `hero`; `cta_band` right before `faq`; **City Info** then
   **Area Map** right after `service_area`; **Housing Snapshot chart** right after `housing_stock` (when
   present); **Climate Chart(s)** right after `climate_data` (when present); `safety_toolkit` at the very
   end.

**Every furniture piece follows the same generation/render split as the rest of this system**
(§3.2's table) — a `pluscities_ensure_X()` function does the real work (network calls, AI writing, SVG
build, rasterize) and runs ONLY from `pluscities_create_page()` + a one-time backfill script; a
`pluscities_render_X()` function is cache-READ ONLY and is the only one called from
`admin/pluscities_sync.php` (real production) and `admin/pluscities_page_styling.php` (admin preview).
Neither render function ever triggers generation; every piece degrades to `''` gracefully when its data
isn't ready.

| Piece | File | Source | Cache key |
|---|---|---|---|
| Safety Toolkit | `includes/pluscities_writer.php` (`pluscities_render_safety_toolkit`) | niche's own `safety_toolkit_items` (hand-curated) | none — deterministic crc32 pick per client domain, no image |
| Area Map | `includes/pluscities_area_map.php` | OSM tiles + Nominatim boundary + the `cities` table (haversine nearby-towns, no AI guess) | `city_id` only — shared across every client/niche covering that city |
| Housing Snapshot | `includes/pluscities_data_chart.php` | Census ACS (`pluscities_dataset_get('census_acs')`) — the exact same dataset the `housing_stock` block already renders as a bullet list, no new network dependency | `city_id` only |
| Climate Chart(s) | `includes/pluscities_climate_chart.php` | NOAA Climate Normals (station-based monthly) or NOAA Storm Events (county-level lookup), shelled out to the old system's `plugins/image-data-chart/providers/lpc_bridge.py`/`datasets.py`, reusing `charts.php`'s series/caption functions directly | `(city_id, chart_id)` — a niche can carry multiple `climate_chart_ids[]`. Both real niches with climate data carry all 6 of the old system's water-restoration chart types: water-damage-restoration = `rainfall_vs_average, avg_temp_monthly, freeze_days, wet_days, heavy_rain_days, flood_decades`; hvac = `hot_days, avg_temp_monthly, freeze_days, wet_days, heavy_rain_days, flood_decades`. Every chart added past the first required zero PHP changes — a new per-niche-captioned JSON definition only |
| City Info | `includes/pluscities_city_info.php` | Real photo via `plugins/city-image/fetch.php`'s Wikipedia lead-image fetch (CC-licensed, auto-attributed); narrative AI-written, adapted from the old system's `city_spotlight` prompt, grounded only in `population` + PlusCities' own `neighborhoods` fact-card topic — no business framing, pure city-level furniture | `city_id` only — shared across every client/niche |

**Real bugs/limits found building this, worth remembering:**
- SQLite TEXT-affinity columns (`lat`/`lng`) silently do STRING comparison against float literals —
  `CAST(... AS REAL)` required (Area Map).
- A chart's combined source citation can overflow a fixed-width SVG canvas — wrap at logical
  boundaries, size the canvas to the wrapped line count (Climate Chart).
- Wikipedia throttles rapid *different* city lookups with a silent empty-200, not a 429/5xx — no error
  signal to retry on; backfills need unconditional pacing (a flat sleep), not adaptive retry (City Info).
- A read-through dataset cache (`pluscities_dataset_get()`) had no way to know its underlying source
  gained a new field — adding a new chart type to an already-fetched niche folder left existing cities
  stale until a `$force` parameter was added; can even change which NOAA station qualifies, since every
  requested data type must come from the same station.
- Shipping new generation logic only inside `pluscities_create_page()` leaves EXISTING pages untouched —
  every piece above required an explicit one-time backfill of every live (city, niche) pair plus a
  `site_rev` bump on every affected client in the same pass, or the live WordPress sites never refetch.
  Learned the hard way on Area Map (a real gap Scott caught: "I don't see it live"), applied proactively
  on every piece built after.

Items-shaped block styling (Tier 2, same pass): `why_choose_us`, `process`, `common_problems`, and
`seasonal_guide` each moved off the generic heading+paragraph fallback onto one generalized writer,
`pluscities_write_items_block($instruction, ...)` (`includes/pluscities_writer.php`) — one function,
a different instruction string per type, rather than 4 near-duplicate writers. `process` → a connected
numbered-step timeline; `common_problems` → an icon grid (same card pattern as Safety Toolkit);
`seasonal_guide` → a 4-up Spring/Summer/Fall/Winter grid, validated directly against
baileyrestoration.com's own "Seasonal Mitigation Guide". A block falls through to the old generic
render until it's actually regenerated since the shape changed — this pass required an explicit
backfill of 27 already-regenerated blocks across 8 live clients for the first two types.

**Closed, 2026-11-26 (same day it was flagged):** the service pool was a global `PLUSCITIES_SERVICE_POOL`
constant shared by every niche regardless of business type, unlike the city pool which was already
family-customizable — a real asymmetry (e.g. `before_the_tech_arrives` makes sense for an in-home HVAC
visit in a way it doesn't for a water-damage emergency call-out). Fixed by extending the exact same
mechanism the city pool already used: `family.default_service_blocks` + `family.always_service_blocks`
+ `niche.service_blocks_off[]`, resolved into `niche['service_blocks']`/`['always_service_blocks']` by
`pluscities_niche_resolved()` identically to `city_blocks`. The global `PLUSCITIES_SERVICE_POOL`/
`PLUSCITIES_ALWAYS_SERVICE_BLOCKS` constants were removed outright (not deprecated/kept-for-compat) once
every call site (`pluscities_validate_block_picks()`, `pluscities_create_page()`, `pluscities_swap_block()`,
and 4 admin pickers) migrated to the niche-resolved values. Shipped in two steps: first the mechanism
(all 6 families seeded with an identical 9-item service pool, zero behavior change), then a real
customization the same day — `medical-wellness` dropped `before_the_tech_arrives` from its
`default_service_blocks` ("a technician arrives at your home" doesn't fit an office-visit business),
removed at the family level since the mismatch applies to any medical-wellness niche, not
`dental-practice` specifically (see below for `legal`/`professional-services` getting the same
treatment the same day). Also fixed in the same pass: `admin/pluscities_niches_save.php`'s `save_family` action had
no fallback preserving `always_city_blocks`/`always_service_blocks` (both fields have no form input yet) —
every save through that form would have silently wiped them to `[]`, the same bug class `image_slots` hit
on the niche side (2026-11-23). Verified directly: `pluscities_niche_resolved()` produces identical pool
contents for `hvac`/`water-damage-restoration`/`dental-practice` before and after the refactor.

**Same-day follow-on: the CITY pool got a real second look too.** Asked directly whether
`dental-practice`'s city pool had similar mismatches, not just the service pool — found two. `utilities`
("info on the city's utility providers/setup relevant to the service") has no sensible dental framing the
way it ties to HVAC equipment, so it was dropped from `medical-wellness`'s `default_city_blocks` outright
(3 of 4 city blocks now, not 4 of 4) — its now-unused `utilities` research topic was cleaned out of the
family's `research_topics` in the same pass (confirmed zero code anywhere actually reads
`research_topics` yet — pure forward-looking metadata today, so this was a zero-risk cleanup, not a
behavior change). `housing_stock` was a softer call — it renders real Census home-value/income/
homeownership stats, a natural fit for home-trades/restoration (older housing stock → more repair
demand) but just generic city color for a dental practice, with no real content tie-in either way. Rather
than remove it, demoted it from always-on back to pickable for `medical-wellness` specifically, since the
original "always-on for all 6 families" decision was extrapolated from 21 real pages that were ALL
home-trades/restoration — no real medical-wellness page ever existed to validate it belonged there at
all. Verified: `dental-practice` resolves to `city_blocks = [housing_stock, reviews_from_city]`,
`always_city_blocks = [neighborhoods]` only; confirmed no existing live page under any niche had
`utilities`/`housing_stock` picked under a medical-wellness client (every real instance of either belongs
to `hvac`/`water-damage-restoration`), so nothing needed backfilling.

**Same-day follow-on #2: checked the remaining 5 families for the same class of mismatch, not just
`medical-wellness`.** `home-trades`/`restoration` were skipped outright — both have a real niche with
real live pages, so their pool isn't a guess, it's the validated baseline the always-blocks promotions
were themselves derived from. `outdoor-cleaning` was also left alone — no real niche yet, but the family
describes a genuine field-visit trade (pressure washing, lawn care, pool/gutter service), the same
business shape as home-trades/restoration, so the shared template is a reasonable fit rather than a
copy-paste oversight. `legal` and `professional-services` got a real fix: both describe office/
consultative businesses (no real niche committed to either yet), and 3 service blocks are unambiguously
contractor-framed regardless of which specific legal/professional niche eventually gets built —
`before_the_tech_arrives` (no technician visits for a legal consult), `repair_vs_replace` and
`maintenance` (both meaningless outside physical equipment) — dropped from both families'
`default_service_blocks` (6 of 9 now, not 9 of 9). Their CITY pool (`permits`, `utilities`, `hoa_rules`,
`climate_data`, `hazards`, `seasonal_guide`) was deliberately left untouched — those could plausibly fit
*some* legal/professional-services niche (construction law, HOA disputes, weather-related injury claims)
but not others (family law, criminal defense), and unlike `medical-wellness`/`dental-practice`, neither
family has a committed real niche yet to reason about concretely — pruning now would be guessing at a
business that doesn't exist yet, not a confident fix. Confirmed via direct DB query: every existing live
use of `before_the_tech_arrives`/`repair_vs_replace`/`maintenance` belongs to `home-trades`/`restoration`
clients, none to `legal`/`professional-services` (no niche blueprint references either family at all),
so nothing needed backfilling.

**Same-day follow-on #3: a different mismatch category, found at the NICHE level, not the family
level.** Checked the 3 real `niche_blueprints/*.json` for the same business-category-fit mismatch found
at the family level — found none; both real niches are internally consistent field-visit trades.
Instead found a real, different issue: `niche_table` and `climate_chart_ids` are **niche-wide shared
content with no per-service variation** (`pluscities_write_niche_data_table_block()`'s own docblock:
"not per-city at all... shared by every client/city in the niche" — true for every service too, there's
no `service_key` in the function signature at all), but 2 of the 3 real niches declare MULTIPLE distinct
services under one niche: `hvac` has `ac-repair` + `furnace-repair`; `water-damage-restoration` has
`water-damage-restoration` + `fire-damage-restoration` + `smoke-damage-cleaning`. Both niches' shared
`niche_table` was authored around only the niche's namesake service — `hvac`'s table covered AC
problems only (nothing about furnaces), `water-damage-restoration`'s covered IICRC water categories only
(nothing about fire/smoke) — so a visitor on `furnace-repair`/`fire-damage-restoration`/
`smoke-damage-cleaning` saw a reference table about a different service entirely. `dental-practice`
coincidentally avoided this only because it has no `niche_table` authored yet (`null`), not because it
was designed around its 2 services (`teeth-cleaning`/`emergency-dental`) correctly.

**Fixed as a content broadening, not an architecture change** (Scott's call — a per-service
`niche_table`/`climate_chart_ids` mechanism was offered as the "real fix" alternative and declined in
favor of the cheaper option): `hvac`'s table retitled "Common AC & Furnace Problems: Cause & Urgency"
with 5 new furnace-specific rows appended (won't-turn-on, blows-cold-air, yellow/flickering pilot light →
real carbon-monoxide-risk guidance, short-cycling, startup odor); `water-damage-restoration`'s table
retitled "Restoration Reference: Water, Fire & Smoke Damage Categories" with the original 3 IICRC water
rows relabeled `Water — Category N` for consistency, plus 4 new rows covering the 3 recognized smoke
residue types (dry/wet/protein) and structural fire damage. Both edited directly via
`pluscities_niche_save()` (hand-authored content, not AI-written — same path the Niche Library admin
form itself writes through). **Backfilled immediately, same discipline as every furniture piece this
session**: `niche_table` is a cached `content_json` per block, not re-read live, so the content change
alone wouldn't reach existing pages without an explicit regenerate — ran
`pluscities_regenerate_block()` on all 12 existing `niche_data_table` blocks fleet-wide (not just the
3 previously-mismatched fire/smoke pages — the `ac-repair`/`water-damage-restoration` pages also got a
richer table and needed the same refresh) and bumped `site_rev` on all 6 affected clients. Verified
directly: block #202 (`meade-restoration`'s `fire-damage-restoration` page) now contains all 7 rows
including the 3 new fire/smoke entries, confirmed by reading its `content_json` straight from the DB
after regeneration.

**Still open, flagged not fixed:** `dental-practice` correctly has no climate chart option at all (no
old-system folder exists for it, no climate angle applies). Richer per-city economic research
(`market_blurb`/`top_employers`/`industries`) for City Info's narrative isn't wired to any PlusCities
research topic yet — only 2 of the original prompt's 5 inputs are populated, an accepted v1 tradeoff. A
fleet-wide regeneration to apply a separate city-label prompt fix (the AI writer fed the raw `city_id`
slug, "bedford-tx", instead of "Bedford, TX," fixed at the one shared context-building call site) to
already-generated content was flagged and left to Scott's judgment, not done.

### 2.4 Block `content_json` shapes, by classification

**`editable`** (AI-written, fact-grounded) — real `overview` example:
```json
{"heading":"Professional AC Repair in Frisco, TX",
 "body":"...with 12 years of experience and an HVAC Contractor license TACLB123456C (TX)..."}
```
Client facts (license, phone) appear as plain prose; only externally-sourced claims get `[[fact_id]]`
tags, stripped only at render time, never at write time — one render function
(`pluscities_render_block()`) does the stripping, shared by publish and preview, so that rule lives in
exactly one place.

**`automatic`, no AI call** — real `service_area` example (pure template fill):
```json
{"drive_minutes":12,"hub_address":"412 Main St, Frisco, TX 75034",
 "main_service_url":"https://eliteairhvac.com/ac-repair/",
 "other_services_this_city":[{"label":"furnace-repair","url":"/service-area/frisco-tx/furnace-repair/"}],
 "service_area_url":"/service-area/"}
```

**`automatic`, dataset-templated** — real `housing_stock` example (one non-specific AI sentence + raw
numbers, model never touches the actual values):
```json
{"intro":"<one AI-written general sentence, no numbers>",
 "dataset_values":{"income_median":146158,"home_value_median":595400,"year_built_median":2009,"homeownership_pct":66.5}}
```

**`client-supplied`** — `reviews_from_city`'s content is the client's own `reviews_by_city[city_id]`
array, copied straight through, `origin='client'`, never touching the AI writer at all.

## 3. Business logic / lifecycle

### 3.1 The state machine (see §2.1's callout for the real example)

`status`: `draft → approved → live → unpublished`, one-way ratchet.

`approval_stage`: real as of 2026-11-24, replacing the old schema-reserved-but-dead
`pending_platform_review`/`pending_agency_review` concept (see §4) with a simpler, actually-working model —
**two independent, optional gates, both off by default:**
- **Agency-level** (`agency.requires_approval_gate` — the switch already existed on `admin/settings.php`
  but was dead code until 2026-11-24, nothing read it). When on, `pluscities_approve_page()` refuses an
  `agency_editor` actor — only Agency Admin/Platform Admin can approve. Same shape as the pre-existing
  `editor_can_publish` gate on publish.
- **Client-level** (`client.requires_client_approval`, per client not per agency). When on, approving a
  page sets `approval_stage = 'pending_client_review'` instead of `'approved'` directly.
  `pluscities_publish_page()` already refuses anything that isn't the literal string `'approved'`, so this
  blocks publish automatically — no change needed there. The client approves their own way, via the new
  `pluscities_client_approve_page()` (the one write action on the otherwise-read-only End Client portal,
  `admin/client_portal_page.php`), which moves `approval_stage` the rest of the way to `'approved'`.

With both gates off (the default), behavior is unchanged from before: one approve call sets
`approval_stage = 'approved'` directly. Reset to `'none'` by `pluscities_touch_page()` on every
edit/regenerate/swap/rollback, same as always — editing an already-reviewed page re-requires both gates
again, regardless of how many times it cleared them before.

### 3.2 Lifecycle functions — `includes/pluscities_lifecycle.php`

| Function | Does | Touches | Guards |
|---|---|---|---|
| `pluscities_validate_block_picks()` | Whitelists+caps a caller's city/service block picks against the niche's real pools (silently drops invalid, caps 6 city/4 service) | nothing (pure helper) | — |
| `pluscities_create_page()` | Inserts the page row + spine blocks (hero/overview first, faq/service_area last by design) + chosen city/service blocks, then regenerates every block | `pages`, `blocks` | Rejects picks not in the niche's real pool (returns a named error, doesn't silently drop like the validator helper above) |
| `pluscities_regenerate_block()` | Re-writes one block's content via the writer, dispatched by classification | `blocks` | Fails if locked; fails if `client-supplied` |
| `pluscities_swap_block()` | Changes a block's type within its own pool | `blocks` | **Pool-membership validation added 2026-11-22** — previously had none at all despite a doc comment claiming it did |
| `pluscities_set_block_lock()` | Toggles `blocks.locked` | `blocks` | **None** — no existence check, thinnest function in the file |
| `pluscities_edit_block()` | Human edit; auto-locks, sets `origin='edited'`, `client_grounded=1` unconditionally | `blocks` | — |
| `pluscities_approve_page()` | `draft→approved`, or `draft→pending_client_review` if the client's gate is on (never regresses a live page) | `pages` | Validation must be re-run since the last edit (`hash_checked_against` must match current `draft_hash`); all validator checks must pass-or-be-overridden; refuses an `agency_editor` actor if `agency.requires_approval_gate` is on (added 2026-11-24) |
| `pluscities_client_approve_page()` | `pending_client_review→approved` | `pages` | Added 2026-11-24. Re-checks both `client_id` match and that the page is actually `pending_client_review` server-side — doesn't trust the portal UI's button visibility as the real guard |
| `pluscities_publish_page()` | Snapshots `blocks` into `revisions`, sets `live_revision_id`, `status='live'`, bumps client `site_rev` | `pages`, `revisions`, client JSON | Requires `approval_stage='approved'` exactly (so `pending_client_review` blocks it automatically — no separate check added); an `agency_editor` additionally needs the agency's `editor_can_publish` flag — enforced **inside this function**, not just the UI; **refuses publish for every role, including `super_admin`, if the client's `site_suspended` is set (added 2026-11-24)** — a platform-level suspension can't be routed around mid-incident by anyone, not even the admin who could lift it |
| `pluscities_set_block_lock()` loop (no dedicated function) | "Lock All"/"Unlock All" on a page, added 2026-11-24 | `blocks` | Convenience wrapper in `admin/pluscities_page_save.php`'s `lock_all`/`unlock_all` actions — loops the same `pluscities_set_block_lock()` above over every block on a page, not new locking architecture |
| `pluscities_rollback_page()` | Restores an old revision's blocks into the live table | `blocks` | Then re-enters draft via `touch_page()` — **never an instant revert**; must go through Validate→Approve→Publish again, "not even through history" |
| `pluscities_unpublish_page()` | `*→unpublished`, bumps `site_rev` | `pages`, client JSON | Returns bool (inconsistent with the `{ok,msg}` shape everything else uses) |

### 3.3 The validator — `includes/pluscities_validator.php`

Entry point `pluscities_run_validator($page_id)` runs all built checks every time, writes the combined
result to `pages.validation_json` keyed against the page's *current* `draft_hash`.

**12 built checks:** unsupported_claim, uniqueness (shingle-Jaccard vs. this client's other pages for the
same service, ≥50% overlap fails), service_radius, wrong_city, client_facts, banned_phrases (niche preset
= never overridable, client's own list = overridable — the flag is per-result, not per-check-name),
invented_prices, invented_reviews, quotes, stale_facts, cannibalization, faq.

**2 deferred, not built:** block-mix ratio check, Sources check (spec's own "small, deferred per design
rule #1" tier).

**Hard exceptions (never overridable by anyone):** `unsupported_claim, invented_prices,
invented_reviews, wrong_city, client_facts, quotes`.

**Override mechanism** (`pluscities_override_validator_check()`): Agency Admin or Platform Owner only,
requires a typed reason, refused for hard exceptions or an already-passing check, writes to `overrides`
keyed to the exact `draft_hash` (see §2.1) — any further edit silently un-applies it, no revoke step.

### 3.4 Content generation — three real inputs, not one pipeline

- **Fact cards**: one hand-picked real source URL per topic (government/official site), fetched,
  stripped, one Claude call extracts a single factual claim with a verbatim ≤15-word quote check and a
  per-topic expiry window. Shared across every client/city in that scope ("research once, reuse
  everywhere").
- **Datasets**: flat provider values, no citation, no AI call at all. Only `census_acs` is wired (Phase 1).
- **Client facts**: stated plainly in the prompt (business name, phone, license, years in business),
  never bracket-tagged since they're not externally-sourced claims.

**4 concrete generation strategies, by block type:**
1. Hero/Overview — pure AI prose, grounded *only* in client-profile facts, zero fact cards.
2. FAQ — AI Q&A, required to ground at least one answer in a real city-scoped fact card.
3. Niche data table — **not AI-written at all**: the niche blueprint's own hand-authored table, identical
   across every client/city in that niche.
4. Housing stock — hybrid: real Census numbers templated in, with one short non-specific AI sentence
   wrapped around them; the model never touches the actual data values.

### 3.5 Sync/delivery contract

`admin/pluscities_sync.php` ↔ `wp-plugin/pluscities-delivery/pluscities-delivery.php`.

Auth: `Authorization: Bearer <key>` header, matched by iterating `pluscities_clients/*.json` against a
per-client secret file (matched by client_id, not by scanning key content — avoids a collision
authenticating as the wrong client).

- **`action=manifest&site_rev=N`** → `{site_rev, changed_pages:[{page_id,revision,url}], live_page_ids:[...],
  cancelled, cache_ttl_seconds}`. Phase 1 simplification: "changed since N" just means "is `client.site_rev`
  > N" — if so, returns **every** currently-live page, not a true per-page diff (Phase 2 item).
  `cache_ttl_seconds` and `cancelled`/`redirect_to` added 2026-11-24 — see §3.6.
- **Health check-in, added 2026-11-24**: the plugin appends `plugin_version`, `theme`, `seo_plugin` as GET
  params on every manifest request (its own `PLUSCITIES_DELIVERY_VERSION` constant, `wp_get_theme()`'s
  name, and the already-detected SEO plugin reused from the sitemap-registration logic below). The server
  merges these plus a real `last_seen_at` onto the client JSON — but **only writes to disk when something
  changed or an hour has passed**, since this request fires on every single front-end page view (via
  `template_redirect` below), not a slow poll; writing on every hit would be wasteful disk I/O for no
  benefit. Surfaced on `admin/pluscities_clients.php` as a line: plugin version, theme, SEO plugin, last
  synced (or "Plugin never synced yet").
- **`action=page&page_id=X`** → `{html, style, head}`, fully server-rendered (breadcrumbs + blocks, CTA
  band injected before FAQ, trust bar injected after hero — fixed positions, not a rhythm engine).
- **`action=hub`** → the client-level aggregate listing page (every live page grouped by city), smaller
  dedicated stylesheet, "coming soon" state if zero live pages.

Plugin side (`pluscities_delivery_sync_if_needed()`): triggered at request time on `template_redirect`
(not WP-cron — a Phase 1 simplification, same contract either way). Compares
`pluscities_site_rev_seen` against the manifest's `site_rev`; if behind, fetches each changed page + the
hub, caches each as a transient keyed to `cache_ttl_seconds` from the manifest (**no longer a no-expiry
transient as of 2026-11-24** — see §3.6 for why; defaults to `0`/forever only if an old-format manifest
response omits the field). A URL miss (never synced, or expired) 404s. **Invalidation is entirely
`client.site_rev`-driven** for normal unpublish — there's no invalidation finer than "whole client" today —
plus the separate cancellation-driven wipe described in §3.6.

Plugin is currently `0.3.0` (`PLUSCITIES_DELIVERY_VERSION` constant). `wp-plugin/pluscities-delivery/uninstall.php`
(added 2026-11-24) is the WordPress-standard file that runs automatically, and only, when a user deletes the
plugin from the Plugins screen (never on deactivate) — deletes every option the plugin created plus the
dynamic `pluscities_page_url_*` options and their matching transients, verified live against a loaded WP
test install.

### 3.6 Client cancellation — added 2026-11-24

Before this date, **no cancellation mechanism of any kind existed for a PlusCities client** — not even a
crude one. (An earlier version of the Product Roadmap claimed "cancel just disables the API key"; that was
simply untrue — grepped the whole admin for any disable/revoke/cancel action and found none. The only
place an API key is ever written is once, at client creation, in `admin/pluscities_clients_save.php`.) The
WP plugin's cache was also hardcoded to never expire (`set_transient(..., 0)`) — deliberate, for offline
resilience — which meant even a hypothetical key-revoke wouldn't have taken a client's pages down; the
plugin's own "offline, serve whatever's cached" fallback would have kept serving them forever.

**What's built now:**
- Two platform-wide settings (`pluscities_platform_settings()`, `admin/settings.php`, Platform-Admin-only,
  stored in `pluscities_platform_settings.json`): `cancellation_grace_days` (default 14) and
  `plugin_cache_ttl_hours` (default 24, replacing "forever").
- A real Cancel/Reactivate action (`admin/pluscities_clients_save.php`, `action=cancel`/`reactivate`,
  super_admin/agency_admin only) sets `client.status`/`canceled_at` (§2.2).
- `admin/pluscities_sync.php` checks, before dispatching either `manifest` or `page`, whether
  `now - canceled_at > grace_days`. Within grace: behaves completely normally (the client relationship can
  still be resolved without anything changing). Past grace: `manifest` returns `cancelled: true,
  redirect_to: "https://<client's domain>/", live_page_ids: []` — the empty `live_page_ids` deliberately
  reuses the plugin's **existing** cache-reconciliation logic (built for ordinary unpublish) to wipe every
  cached page, rather than inventing a second cache-clearing mechanism; `page` returns a plain 404.
- The plugin stores the redirect target in a WP option and checks it on every PlusCities-URL request
  (`template_redirect`), issuing a real 301 before attempting to serve anything — belt and suspenders with
  the cache wipe above, not relying on the cache alone.

**Verified live end to end** against a real demo client: normal manifest within grace, correct
`cancelled`/`redirect_to`/empty-`live_page_ids` once backdated past grace, direct page fetch 404, full
restoration on reactivate.

**Real, stated-plainly limitation:** this requires the *updated* plugin. The 3 real clients
(`elite-air-hvac`, `lonestar-restoration`, `meade-restoration`) are running the plugin version from before
this date and won't get cancellation protection until their plugin is reinstalled — this protects new
clients going forward, not retroactively.

## 4. Functional scope — what's real vs. scaffolding, today

Honest numbers, not aspirational ones:

- **Niche families: 2 of 6 are real.** `home-trades` and `restoration` have genuine, distinct defaults.
  `legal`, `medical-wellness`, `outdoor-cleaning`, `professional-services` are byte-identical placeholder
  stubs — even `label` is the raw family_id (e.g. `"label": "medical-wellness"`, not "Medical &
  Wellness"), and all 6 families reference the single `contractor` compliance preset, including
  `medical-wellness`, even though the spec's own text names a `medical` preset that doesn't exist on disk.
- **Niche blueprints: 2 real** (`hvac`, `water-damage-restoration`).
- **Compliance presets: 1 real** (`contractor`).
- **Real clients: 3** (`elite-air-hvac`, `lonestar-restoration`, `meade-restoration`), plus 3 demo-only
  clients built 2026-11-24 for sales demos (see §2.2) — never counted as additional proof-at-scale, kept
  deliberately separate.
- **Validator: 12 of 14 spec'd checks built**; block-mix and Sources checks deferred by design, not debt.
- **Approval gates: real and working as of 2026-11-24**, replacing the old schema-reserved-but-dead
  `pending_platform_review`/`pending_agency_review` concept (that one really did exist in the old LPC
  system, `includes/landing_page_clients_approvals.php`, deleted 2026-11-23 Stage 3, never ported — but the
  replacement isn't a straight port, it's a simpler model: two independent off-by-default toggles, agency-
  level and client-level, see §3.1/§3.6). Verified live, not just read in source.
- **Manifest diffing**: whole-client, not true per-page revision diffing yet (Phase 2).
- **Dataset providers**: Census (`census_acs`) and, as of 2026-11-26, NOAA (`noaa_climate:{niche_slug}`,
  both Climate Normals and Storm Events, via the old system's `plugins/image-data-chart/` bridge — see
  §2.3a) are wired. The `climate_data` block type itself is still a normal AI-written Editable block
  grounded in a fact card — the NOAA data drives a separate rendered Climate Chart furniture piece
  alongside it, not the block's own text. FEMA is still unbuilt.
- **Real block-selection algorithm** (spec's ≥50% city-block word ratio, ≤50% service-block overlap
  across a client's cities, varied ordering): not built — `pluscities_create_page()` takes an agency's
  raw picks as-is.
- **Known specific bugs, not yet fixed:**
  - `hoa_rules`/`seasonal_guide` block types are meant to draw on `hoa`/`seasons` fact-card topics, but the
    generic lookup uses the block's own type name as the topic key — so they draw on the wrong topic.
  - `reviews_from_city` is spec'd to default-lock (protecting a real client review from accidental AI
    overwrite); shipped code does not lock it by default.
  - `fact_cards.status` values `stale`/`rejected` are schema- and spec-documented, but the only function
    that can set them (`pluscities_fact_card_deactivate()`) has zero call sites — every live row is
    `active` in practice.
  - ~~No admin UI path exists to correct a single FAQ answer~~ (found 2026-11-23, UC9; **fixed
    2026-11-23**): `admin/pluscities_page.php` now renders a per-question editor for FAQ blocks (one
    question input + one answer textarea per entry) instead of a disabled generic Save button; a new
    `edit_faq` action in `admin/pluscities_page_save.php` rebuilds the `faqs` array and reuses
    `pluscities_edit_block()` unchanged, so the auto-lock/`origin=edited`/`client_grounded=1` behavior is
    identical to every other manual edit. Verified directly against a real FAQ block.
  - ~~The Niche Library's real `save_niche` form has no fields at all for `niche_table`,
    `trust_bar_items`, `blog_topics`, `custom_research_fields`, or `approved_sources_extra`~~ —
    **CLOSED 2026-11-26, panel-coverage walkthrough Session 2.** All 5 now have real, simple form
    fields: `trust_bar_items`/`approved_sources_extra` as comma-separated text inputs (same convention
    `blocks_off`/`approved_sources` already use); `custom_research_fields`/`blog_topics`/`niche_table` as
    pipe-delimited textareas, one line per item (same convention `services_lines` already uses —
    `niche_table`'s first line is the column headers, every line after is one row). Verified live:
    resubmitted `hvac`'s real niche form through all 5 new fields with its exact existing content,
    confirmed a byte-for-byte match (`git diff` showed only the expected `updated_at` timestamp); then
    created and fully populated a brand-new disposable test niche end-to-end through the panel alone —
    zero hand-JSON — including a 2-row reference table and a blog topic with `related_topics`, confirmed
    `pluscities_niche_resolved()` parses the result correctly, then deleted the test file directly (no
    delete action exists in the panel for this — see the gap noted in §5.5). Still genuinely hand-JSON
    only: `radius_minutes_override`, `compliance_preset_override`, `match_keywords`, `climate_chart_ids`,
    `safety_toolkit_items` — deliberately out of scope for a simple text-field treatment (the first two
    are rare per-niche overrides, `climate_chart_ids` is tightly coupled to a hardcoded old-system
    slug mapping that only resolves for 2 real niches today, and `safety_toolkit_items` carries raw SVG
    icon markup that doesn't fit a plain text field cleanly) — flagged as a real but lower-priority gap,
    not silently dropped. **`image_slots` no longer belongs on this list either** — real dedicated UI was
    built for it at some point after UC11 (an "Add Image Slot" picker tied to the real block catalog +
    a per-slot upload form, both verified live 2026-11-26) — this doc's own list had gone stale on that
    point until the walkthrough caught it.
  - **A second round of the same bug class, found via a deliberate panel walkthrough, 2026-11-26**:
    `climate_chart_ids`/`safety_toolkit_items` were missing from `save_niche`'s unexposed-field
    preservation list (the same `$existing[...] ?? ...` fallback pattern that protects `niche_table`/
    `trust_bar_items`/etc.) — confirmed live by resubmitting `hvac`'s real niche form with its current
    visible values unchanged: its 6 real `climate_chart_ids` were silently wiped to `[]`, which would
    have stopped every HVAC client's Climate Chart furniture from rendering. Restored immediately,
    then fixed at the source (both fields added to the preservation list) and re-verified via the same
    resubmit on both `hvac` and `water-damage-restoration` (the only niche with real
    `safety_toolkit_items`) — both now survive a resave unchanged.
  - **`pluscities_rollback_page()` dropped `blocks.client_grounded` on restore** (fixed 2026-11-23, found
    via real use-case testing — see `docs/pluscities-use-case-tests.md` UC7): its INSERT statement omitted
    the column entirely, silently resetting it to 0 regardless of what the revision snapshot actually
    stored, which could fail a hero/overview block's `unsupported_claim` check after any rollback even
    though the content was valid before. Fixed by adding the column to the restore statement.
  - **"Unpublish" didn't take content offline on the real delivered site — fixed 2026-11-23** (found via
    live testing — see `docs/pluscities-use-case-tests.md` UC7): the manifest only ever returned
    `status='live'` pages, so an unpublished page simply stopped appearing in future manifest responses —
    but nothing signaled the WP plugin to delete its already-cached, no-expiry transient. Confirmed live
    before the fix: a real visitor hitting the actual URL kept seeing the old content indefinitely.
    **Fixed** by adding `live_page_ids` (the full current live set) to the manifest response, and having
    the plugin's `pluscities_delivery_sync_if_needed()` reconcile its cached pages against it, deleting
    anything no longer live. Also fixed the function's gate (was keyed on `changed_pages` being non-empty,
    which skipped reconciliation entirely when a client's *last* live page was unpublished). Verified live
    both ways (one-of-several unpublished, and last-remaining-page unpublished) with the other page
    confirmed unaffected. **Deployment note**: this plugin is already installed on real client sites — the
    fix only reaches a site once it re-downloads the plugin zip; no auto-update mechanism exists, so
    already-running installs won't get this automatically.
- **Deferred by design, not debt** (explicitly Phase 3/4 per the spec): Stripe Connect/rebilling,
  **per-agency** custom dashboard domain (every agency still shares `app.pluscities.com` — the admin
  panel's *own* domain is live now, see §1, but a distinct domain per agency is a separate, explicitly
  deferred decision, needs real Cloudflare for SaaS setup), CSV bulk import, GSC indexing-status monitoring,
  white-labeled PDF reports, call tracking, scheduled fact-card refresh, Spanish pages, webhooks, audit-log
  UI, HMAC signing of the sync API, a "Request Changes" action for the client (approve-only for now, see
  §3.1 — no notes/messaging concept exists anywhere in this app yet for a rejection-with-a-note flow).
  **No longer on this list, built 2026-11-24:** a client approval action (not a distinct "Client Approver"
  role in the role model — still just `end_client` — but the actual approve capability, gated by a real
  per-client toggle, is real and working).

## 5. User roles & menu capability

### 5.1 Role model

`lpc_current_actor_role()` returns `$_SESSION['actor_role'] ?? 'super_admin'` — defaults to super_admin
whenever unset, which is every session started via the platform operator's own `login.php` (it never sets
`actor_role` explicitly).

| Role | Set by | Scope | Can / can't |
|---|---|---|---|
| `super_admin` | Default whenever `actor_role` is unset (`login.php`) | Everything | Full access to every PlusCities + platform-only screen (Agencies, Niche Library, Settings, Platform Info, WP Builder, Site Factory). Only role that can impersonate. **Cannot** access `pluscities_team.php` directly (agency_admin-only) except by impersonating that agency. |
| `agency_admin` | `agency_login.php` | Own `agency_id` | Create/manage own agency's clients, pages, photos, hub text, branding, team roster (`editor_can_publish` toggle), client cancellation (§3.6), per-client approval toggle (§3.1). Blocked from every platform-only screen. Sees the agency's **own** branding (display_name/logo) in the shared sidebar as of 2026-11-24 — `lpc_actor_branding()` existed with correct logic since before this date but had zero callers anywhere; the sidebar was simply never wired to it, so every agency saw "PlusCities" regardless of what branding they'd saved. |
| `agency_editor` | `agency_login.php`, real per-person account (`includes/pluscities_users.php`) | Same agency, further narrowed by `assigned_client_ids` (empty = sees all of agency's clients) | Edit/lock/regenerate/swap freely. **Approve is conditionally blocked** as of 2026-11-24 if `agency.requires_approval_gate` is on (default on) — only Agency Admin/Platform Admin can approve; enforced inside `pluscities_approve_page()` itself. **Publish is conditionally blocked** unless the agency's `editor_can_publish` flag is on (off by default) — enforced inside `pluscities_publish_page()` itself, not just the UI. Was missing from the agency-branding sidebar check entirely until 2026-11-24 (fell through to the platform default even though an Agency Admin at the same agency saw it correctly) — now included. |
| `end_client` | **Impersonation only, as of 2026-11-24** — `admin/impersonate.php`'s `type=client` path, reachable via a "Log in as" button on `pluscities_clients.php` (super_admin/agency_admin only). No self-service login exists — a real business owner cannot log in with their own credentials; only a platform/agency operator can view the role's one screen on their behalf. | N/A | Excluded from every other `pluscities_*.php` screen, same as always. Two screens, both rebuilt 2026-11-24 against the current data model: `admin/client_portal.php` lists **every** page regardless of status (draft/approved/live/unpublished, not just live — the old version only showed live pages), with an honest status pill per row; `admin/client_portal_page.php` shows one page's real content block-by-block, strictly read-only, **except** a real Approve button when that specific page is `pending_client_review` (see §3.1) — the one write action anywhere on this role's screens. `admin/impersonate.php` itself was also broken until the same 2026-11-24 fix: it called `lpc_load_client()` against the old (now-empty) LPC client store, so impersonating ANY client — not just reaching the destination page — failed with "Client not found" for every PlusCities client. `client_login.php` (a genuine self-service login) remains deleted and unbuilt — see §5.5. |

Impersonation (`admin/impersonate.php`) overlays role/agency/client without touching the real login flag:
`super_admin` → any agency (as its admin) or any client (as end_client); `agency_admin` → one of its own
clients. One hop only, no stacking.

### 5.2 Admin page inventory — `admin/pluscities_*.php`

| File | Screen | Role gate |
|---|---|---|
| `pluscities_clients.php` / `_save.php` | Client list + "New Client" (identity, hub, licenses, hours, first-page block picker). As of 2026-11-24 also: "Log in as" (impersonate), per-client approval toggle, Cancel/Reactivate (§3.6), platform-level Suspend/Un-suspend Site kill switch (super_admin only, §3.2/§3.7), a live Site: Live/Down/SSL-issue badge (uncached `infra_site_check()`), and a plugin health line (version/theme/SEO plugin/last synced, §3.5) | Auth + role ≠ end_client; Suspend/Un-suspend is super_admin only, the other new action buttons are super_admin/agency_admin only |
| `client_portal.php` / `client_portal_page.php` | End Client's own screens — page list (all statuses) + single-page read-only content view with a conditional Approve button | `end_client` only (reached via impersonation, not direct login — §5.4) |
| `pluscities_page.php` / `_save.php` | Single-page block editor — Edit/Lock/Regenerate/Swap/Validate/Override/Approve/Publish/Rollback/Lock All/Unlock All (added 2026-11-24, loops the existing per-block lock), plus a "Compare to current" link per revision opening `pluscities_page_diff.php` | + `pluscities_actor_can_access_client()` on the page's client |
| `pluscities_page_diff.php` | Read-only revision compare view, added 2026-11-24 — shows unchanged/changed/added/removed per block between an old revision's snapshot and the page's current content | + `pluscities_actor_can_access_client()` on the page's client; not in the nav, reached only from `pluscities_page.php` |
| `pluscities_page_add.php` / `_save.php` | Add a city/service page to an existing client | + access check |
| `pluscities_pages.php` / `_save.php` | Table of all pages — stage-dot matrix, bulk approve/publish/unpublish (unpublish added 2026-11-24)/regenerate, plus City/Service filter dropdowns (added 2026-11-24, server-side via GET params). Bulk Approve had a real gate-bypass bug until 2026-11-24 — it never passed the actor's role into `pluscities_approve_page()`, so it silently skipped the `agency_editor` approval gate (§3.2) that the single-page Approve button already enforced; now passes `lpc_current_actor_role()` the same way | **Fixed 2026-11-24** — now filters through `pluscities_actor_can_access_client()` per client, not just agency-level scoping (see §5.5) |
| `pluscities_photos.php` / `_save.php` | Logo + brand color + per-slot photo upload | **Fixed 2026-11-24** — list view now filters through `pluscities_actor_can_access_client()` per client, not just agency-level scoping (see §5.5); save handler already checked per-client access |
| `pluscities_hub_edit.php` / `_save.php` | Edit a client's hub/listing page text | + access check |
| `pluscities_plugin_download.php` | Zips the delivery plugin, text-substitutes agency branding | Auth only, no role exclusion |
| `pluscities_sync.php` | **Public API endpoint** for the WP plugin | No session/role gate at all — Bearer API-key auth, by design, outside the actor-role system entirely |
| `pluscities_team.php` / `_save.php` | Manage Agency Editor accounts + `editor_can_publish` toggle | **`agency_admin`-only** — excludes super_admin too, not just lower roles |
| `pluscities_niches.php` / `_save.php` | Niche Library — families, niches, compliance presets | **`super_admin`-only** |

### 5.3 Nav structure — `admin/lpc_admin_layout.php`'s `lpc_admin_nav_items()`

Shared by LPC + PlusCities admin pages (not the ~80-page Site Factory, which has its own separate chrome).

- `end_client` has one nav item — Reporting → `client_portal.php`. This item itself was never removed in
  Stage 3 (correcting an earlier version of this doc that claimed it was) — it kept pointing at a file
  that *was* deleted, a real 404 for anyone who ever reached this role, found and fixed 2026-11-24 (see
  §5.1 and §5.5).
- Everyone else sees, in order: **PlusCities Clients** → **PlusCities Pages** → **Photos** →
  *(agency_admin only: My Branding, Team)* → *(super_admin only — split into two sections, "Platform
  Ops" and "Build Tools": Agencies, Niche Library, Platform Information, Settings; WP Builder,
  ← Site Factory)*. The old-LPC items (All Clients, New Client, Approvals, Niche Profiles "(old)", Demo
  Sites) were removed across LPC Stage 3's retirement (nav cleanup 2026-11-23, with Demo Sites removed in
  the final step the same day) — none of them exist anymore, in the nav or on disk.

**The "two parallel niche systems" situation this section used to describe no longer applies.**
`niche_profiles.php` (the old engine) and its reader `landing_page_clients_ai.php` were both deleted in
Stage 3. `pluscities_niches.php` (the real PlusCities Niche Library) is now the only niche system.

Not in the nav at all (reached only via links from other pages): `pluscities_hub_edit.php`,
`pluscities_page_add.php`, `pluscities_page.php`, `pluscities_page_diff.php`, `pluscities_plugin_download.php`.

### 5.4 Auth entry points

| File | Who | Redirects to |
|---|---|---|
| `admin/login.php` | Platform operator | `sites.php` (Site Factory) |
| `admin/agency_login.php` | Agency Admin or Agency Editor | `pluscities_dashboard.php` on successful login (corrected from an earlier version of this doc, which said `clients.php` — that's only the "already logged in" pre-check redirect at the top of the file; `clients.php` itself wasn't deleted in Stage 3, its old client-list content was replaced with a thin redirect stub to `pluscities_clients.php`, since ~19 other files still redirect to it) |
| ~~`admin/client_login.php`~~ | Deleted 2026-11-23 (LPC Stage 3) | N/A — no self-service `end_client` login exists; the role is only reachable via `admin/impersonate.php` (see §5.1) |

`admin/login.php`'s target (`sites.php`, Site Factory) remains the one staff-facing login that doesn't
land on a PlusCities-native screen — a real, already-present inconsistency worth knowing about, not new.

### 5.5 Known gaps (real, not hypothetical — found via direct code read)

1. **No self-service `end_client` login exists — only operator-driven impersonation.** Originally (pre
   Stage 3) this gap meant `client_login.php`/`client_portal.php` existed but were 100% scoped to the old
   `lpc_client_dir()` tree, not `PLUSCITIES_CLIENTS_DIR` — so a PlusCities client could never obtain the
   role. Stage 3 (2026-11-23) then deleted both files entirely, breaking impersonation's destination too —
   confirmed live-broken (every client impersonation attempt failed with "Client not found," because
   `admin/impersonate.php` still called `lpc_load_client()` against the by-then-empty old client store).
   Fixed 2026-11-24: `admin/impersonate.php` now loads PlusCities clients correctly, `admin/client_portal.php`
   was rebuilt against the current data model (real page list, city/service/status, live-pages only), and
   a "Log in as" button was added to `pluscities_clients.php` (super_admin/agency_admin only — no button
   existed anywhere before this). **What's still missing**: a genuine self-service login — a real business
   owner cannot log into their own reporting with their own credentials today; the role is only reachable
   by an operator choosing to view it on their behalf. Building real `end_client` credentials + a login
   form remains unbuilt, for either system.
2. **Fixed 2026-11-24** — Agency Editor's `assigned_client_ids` restriction was enforced on writes but not
   consistently on reads. `pluscities_clients.php`, `pluscities_pages.php`, and `pluscities_photos.php`'s
   list views previously applied only agency-level scoping, not the per-editor restriction — an editor
   could see every client/page in the agency even if assigned to only some (the corresponding `_save.php`
   handlers already checked per-client access, so this was within-agency over-exposure on reads, never a
   cross-agency leak or a write-path hole). All three now filter through `pluscities_actor_can_access_client()`
   per client, same as the write handlers — verified live with a real restricted Agency Editor account
   (created, logged in directly, confirmed scoping, tested a URL-manipulation negative case, then deleted).
3. **No admin UI for the audit log.** A real append-only log (`events` table, `pluscities_log_event()`)
   already records ~20 distinct actions platform-wide (page/block lifecycle, client create/suspend/cancel,
   branding, hub edits, validator overrides) — but nothing anywhere queries or displays it, so it's
   write-only today. Separately, `admin/impersonate.php` writes nothing at all when an impersonation
   session starts or ends — not even an error-log line — a real gap in exactly the action most worth
   logging.
4. **No delete action exists anywhere in the Niche Library screen** (found 2026-11-26, panel-coverage
   walkthrough Session 1) — `admin/pluscities_niches_save.php` has exactly 6 actions
   (`save_preset`/`save_family`/`save_niche`/`upload_niche_image`/`add_image_slot`/`validate_keyword`),
   none of them a delete/remove. A wrongly-created compliance preset, family mistake, niche, or image
   slot can only be fixed by hand-editing the JSON file or the DB directly — there's no "undo creating
   this" path through the panel at all. Not a data-safety bug (nothing gets silently destroyed), but a
   real completeness gap for "can an agency/platform operator do everything through the panel."

## 6. Relationship to the old LPC system — RETIRED 2026-11-23 (historical section)

**The old LPC system no longer exists.** Everything below describes the relationship as it stood before
LPC Stage 3 retirement (`docs/lpc-stage3-retirement-scoping.md`) — kept here as history, since §7 below
still refers back to the shared-database lesson it taught, not because any of it is still live.

What was genuinely shared, and what happened to each piece at retirement: `admin/lpc_admin_layout.php`
(nav/chrome) — **kept**, now serves PlusCities + Site Factory only, all old-LPC nav items removed.
`includes/helpers.php`-style utilities, `admin/infra/lib/http.php`, the image plugins
(`plugins/image-data-chart/*`, `plugins/image-area-map/*`) — **kept**, were never LPC-specific to begin
with, just co-used. `includes/landing_page_clients_agencies.php` (billing/session engine) — **kept**,
renamed to `includes/agencies.php`, now PlusCities' own. `niche_profiles.php` and its reader
`landing_page_clients_ai.php` — **deleted**, not ported; `pluscities_niches.php` is now the only niche
system.

What was fully separate, and what happened: LPC's own standalone delivery server + WordPress renderer —
**deleted** (`wp-plugin/landing-page-client/`, the standalone delivery service on `2.24.196.58`, and its
data); PlusCities' server-side-render-and-sync model is unaffected and was never coupled to it. The
database (`fleet.db`-adjacent LPC state vs. `pluscities.db`) — moot, LPC's side is gone. Billing
(`stripe_client.php`) — was LPC-only at the time this was written, is **now PlusCities'** (via the
renamed `agencies.php`, kept shared on purpose, see the Stage 3 doc §1). The admin client-record shape —
`clients/*/data/client.json` (old-LPC) was **deleted** along with the real client it held
(`meade-restoration`, migrated to `pluscities_clients/meade-restoration.json` first); `pluscities_clients/
*.json` is now the only client-record shape.

`docs/lpc-vs-site-factory-boundary.md` and `docs/landing-page-clients-onboarding.md` — **both now
historical-only** per `CLAUDE.md`'s docs table; the code they describe (LPC's own delivery server, its
own renderer, the old onboarding wizard) has been deleted. Read `docs/lpc-stage3-retirement-scoping.md`
for what was retired and why; read this doc for current PlusCities state.

## 7. Relationship to Site Factory

Checked directly against the real code, 2026-11-23 — not assumed to mirror LPC's situation just because
both share this repo.

**Cleanly separate, confirmed in code:**
- **Database.** `admin/infra/state/pluscities.db` is a deliberately separate SQLite file from the fleet's
  own `fleet.db` — its own code comment in `admin/infra/lib/pluscities_db.php` says this split exists
  specifically to avoid repeating LPC's shared-database mistake (§6, `docs/lpc-vs-site-factory-
  boundary.md` §2). PlusCities got this right from day one; it didn't need a later fix.
- **Delivery/rendering.** PlusCities' server-side-render-and-sync model (`admin/pluscities_sync.php` +
  `wp-plugin/pluscities-delivery/`) vs. Site Factory's own in-house block renderer (`includes/blocks.php`)
  and theme/color system. No shared rendering code, by design — same reasoning LPC's renderer split uses.
- **Billing.** PlusCities touches no billing system at all (`includes/stripe_client.php` is LPC-only;
  agency billing fields exist on `client_agencies/*.json` but nothing wires them to Stripe yet — see
  `docs/pluscities-use-case-tests.md` UC14).
- **Client records.** `pluscities_clients/*.json` vs. Site Factory's own site/client data — intentionally
  never reconciled, same as LPC's `clients/*/data/client.json` split (§6).

**Not actually separate — the real finding, same unresolved problem LPC has:**
- **Anthropic API key.** One `.ai_key` file, one `ANTHROPIC_API_KEY` constant (`config.php`), used by Site
  Factory's content pipeline, PlusCities' writer (`includes/pluscities_writer.php`) and research
  (`includes/pluscities_research.php`), and LPC's own AI calls — all three businesses, one account, one
  quota.
- **OpenAI API key.** Same situation: one `.openai_key` file (`includes/openai_images.php`), shared by Site
  Factory's image generation and PlusCities' image pipeline (`includes/pluscities_images.php`).
- **DataForSEO.** Same situation again (`admin/infra/lib/keywords.php`) — and not hypothetical: PlusCities'
  own UC11 test (`docs/pluscities-use-case-tests.md`) hit a real HTTP 402 Payment Required on a keyword
  validation call, because Site Factory's own usage had drawn down the same shared account.

**The fix, scoped the same way it's already scoped for LPC (`docs/lpc-vs-site-factory-boundary.md` §2):**
give PlusCities (and LPC) their own Anthropic/OpenAI/DataForSEO credentials instead of sharing Site
Factory's. Same module code stays — `includes/anthropic.php`, `includes/openai_images.php`,
`admin/infra/lib/keywords.php` are reused correctly (see next point) — only the underlying account/key
changes per business. Mechanically trivial once someone creates and funds the accounts; not yet done for
either LPC or PlusCities.

**Fine to share, not a violation:** the module code itself. Reusing one well-built API client
(`includes/anthropic.php`, `includes/openai_images.php`, `admin/infra/lib/keywords.php`) across every
business that needs that service is the correct, explicitly endorsed pattern this whole codebase follows
(see CLAUDE.md's "API calls: one module per service, reused everywhere" rule and its own stated reasoning
— duplicated clients drift into different ones). The violation above is sharing the **account/credential/
quota** behind that module, not sharing the module. Also fine to share for the same reason: nav chrome
(`admin/lpc_admin_layout.php`), generic helpers (`includes/helpers.php`), `admin/infra/lib/http.php`, and
the image-rendering plugins (`plugins/image-data-chart/*`, `plugins/image-area-map/*`).

**The one deliberate exception:** the niche library. The underlying facts about a niche — what services
exist, seasonal/local relevance, research fields — are business-model-agnostic and should be written
once, with each system's own framing layer (referral-network vs. direct-operator, same distinction
`docs/lpc-vs-site-factory-boundary.md` §1 already draws for LPC) kept separately on top. This is the same
planned-but-not-yet-built item flagged there (§4) for LPC vs. Site Factory; it applies identically to
PlusCities' niche blueprints (`niche_blueprints/*.json`) vs. Site Factory's `niche_brief.json` — neither
has been reconciled with the other yet, and neither should be merged outright given the framing
difference, just eventually generated from one shared fact source.

## 8. Appendix — source-of-truth file index

| File | What it is |
|---|---|
| `admin/infra/lib/pluscities_db.php` | SQLite schema + connection |
| `includes/pluscities_lifecycle.php` | Page/block state machine, all lifecycle verbs |
| `includes/pluscities_writer.php` | Content generation per block classification |
| `includes/pluscities_research.php` | Fact-card research + dataset fetch |
| `includes/pluscities_validator.php` | The 12 compliance/quality checks |
| `includes/pluscities_niche_library.php` | Niche family/blueprint/preset resolution, incl. `always_city_blocks`/`climate_chart_ids` (§2.3a) |
| `includes/pluscities_schema.php` | JSON-LD assembly, city-label resolution |
| `includes/pluscities_color_detect.php` | Brand color resolution precedence |
| `includes/pluscities_images.php` | Photo slot model |
| `includes/pluscities_area_map.php` | Furniture: real OSM basemap + nearby-towns (§2.3a) |
| `includes/pluscities_data_chart.php` | Furniture: Housing Snapshot chart from Census ACS (§2.3a) |
| `includes/pluscities_climate_chart.php` | Furniture: NOAA climate chart(s), multi-chart-per-niche (§2.3a) |
| `includes/pluscities_city_info.php` | Furniture: real Wikipedia photo + AI city narrative (§2.3a) |
| `includes/pluscities_users.php` | Agency Editor accounts |
| `admin/pluscities_sync.php` | The public delivery API (manifest/page/hub) |
| `wp-plugin/pluscities-delivery/pluscities-delivery.php` | The WP plugin (fetch/cache/place), v0.3.0 — now also self-reports version/theme/SEO plugin |
| `wp-plugin/pluscities-delivery/uninstall.php` | Clean-uninstall cleanup, added 2026-11-24 — deletes every option/transient the plugin ever created |
| `admin/lpc_admin_layout.php` | Shared nav/chrome + role-driven menu |
| `admin/login.php` / `agency_login.php` | The two remaining auth entry points (`client_login.php` deleted 2026-11-23, LPC Stage 3) |
| `admin/pluscities_clients.php` / `_save.php` | Client creation, first-page picker, impersonate/approval-toggle/cancel/suspend-site actions, site-live badge, plugin health line |
| `admin/client_portal.php` / `client_portal_page.php` | End Client's own screens, added/rebuilt 2026-11-24 |
| `admin/pluscities_page.php` / `_save.php` | Single-page block editor + lifecycle actions, Lock All/Unlock All |
| `admin/pluscities_page_diff.php` | Read-only revision compare view, added 2026-11-24 |
| `admin/pluscities_pages.php` / `_save.php` | Bulk page table — approve/publish/unpublish/regenerate, city/service filters |
| `admin/settings.php` / `platform_settings_save.php` | Platform-wide settings — approval-gate toggles, cancellation grace period, plugin cache TTL |
| `config.php` | All `PLUSCITIES_*` path/dir constants |
| `pluscities_platform_settings.json` | The actual stored values for the Settings-page toggles above |
| `docs/PLUSCITIES_FEATURE_SPEC.md` | Original design intent/roadmap (not as-built) |
