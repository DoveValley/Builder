# PlusCities — System Reference (as-built, 2026-11-22)

## 0. How to use this doc

This is **as-built ground truth**, verified directly against the real code, the real SQLite DB, and real
client JSON files on 2026-11-22 — not the original design intent. For *why* something was designed a
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
approval_stage   TEXT DEFAULT "none"      -- none | pending_platform_review | pending_agency_review | approved | rejected
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
`elite-air-hvac.json`, `lonestar-restoration.json`, `meade-restoration.json`.

| Field | Meaning | Notes |
|---|---|---|
| `client_id` | slugified business name, filename stem | join key into `pages.client_id` |
| `agency_id` | owning agency | scopes visibility everywhere |
| `niche_id` | → `niche_blueprints/<niche_id>.json` | resolved via `pluscities_niche_resolved()` |
| `domain` | production domain | used to build canonical/service URLs |
| `site_rev` | bump counter | the **sole** signal the WP plugin's manifest poll compares against |
| `plugin_rev`, `plugin_version`, `last_seen_at` | delivery-plugin handshake state | |
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
| `approval_gate_override` | `null` in all 3 real files (inherits agency default) | Phase-2 gate logic, not wired yet |
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
on client files created after that point.

### 2.3 Niche blueprint model — three-level hierarchy

- **`niche_families/`** — 6 files (`home-trades`, `restoration`, `legal`, `medical-wellness`,
  `outdoor-cleaning`, `professional-services`), fixed and hand-authored, not user-creatable.
- **`niche_blueprints/`** — **only 2 real files exist**: `hvac.json` (family: home-trades) and
  `water-damage-restoration.json` (family: restoration).
- **`compliance_presets/`** — **only 1 file**: `contractor.json`.

`PLUSCITIES_SERVICE_POOL` (`includes/pluscities_lifecycle.php`) is **one shared pool of 9 service-block
types for every niche**: `common_problems, cited_expert_guidance, niche_data_table, process,
cost_factors, why_choose_us, repair_vs_replace, maintenance, before_the_tech_arrives`. The **city-block**
pool is per-family instead: `family.default_city_blocks` minus the niche's own `blocks_off[]` denylist —
e.g. home-trades' pool is `climate_data, housing_stock, neighborhoods, permits, utilities, hoa_rules,
seasonal_guide, hazards, reviews_from_city` (9 types).

A resolved niche blueprint (`pluscities_niche_resolved()`) contains: `schema_subtype` (real schema.org
type), `services[]` (with keyword-research data per service), `blocks_off[]`, `research_topics[]`,
`niche_table` (the hand-authored shared data table), `trust_bar_items[]`, `blog_topics[]`, `image_slots[]`.

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
`approval_stage`: `none → approved` in practice today (`pending_platform_review`/`pending_agency_review`
are schema-reserved, read for dashboard counts, but no code path ever sets them — see §4). Reset to
`none` by `pluscities_touch_page()` on every edit/regenerate/swap/rollback.

### 3.2 Lifecycle functions — `includes/pluscities_lifecycle.php`

| Function | Does | Touches | Guards |
|---|---|---|---|
| `pluscities_validate_block_picks()` | Whitelists+caps a caller's city/service block picks against the niche's real pools (silently drops invalid, caps 6 city/4 service) | nothing (pure helper) | — |
| `pluscities_create_page()` | Inserts the page row + spine blocks (hero/overview first, faq/service_area last by design) + chosen city/service blocks, then regenerates every block | `pages`, `blocks` | Rejects picks not in the niche's real pool (returns a named error, doesn't silently drop like the validator helper above) |
| `pluscities_regenerate_block()` | Re-writes one block's content via the writer, dispatched by classification | `blocks` | Fails if locked; fails if `client-supplied` |
| `pluscities_swap_block()` | Changes a block's type within its own pool | `blocks` | **Pool-membership validation added 2026-11-22** — previously had none at all despite a doc comment claiming it did |
| `pluscities_set_block_lock()` | Toggles `blocks.locked` | `blocks` | **None** — no existence check, thinnest function in the file |
| `pluscities_edit_block()` | Human edit; auto-locks, sets `origin='edited'`, `client_grounded=1` unconditionally | `blocks` | — |
| `pluscities_approve_page()` | `draft→approved` (never regresses a live page) | `pages` | Validation must be re-run since the last edit (`hash_checked_against` must match current `draft_hash`); all validator checks must pass-or-be-overridden |
| `pluscities_publish_page()` | Snapshots `blocks` into `revisions`, sets `live_revision_id`, `status='live'`, bumps client `site_rev` | `pages`, `revisions`, client JSON | Requires `approval_stage='approved'`; an `agency_editor` additionally needs the agency's `editor_can_publish` flag — enforced **inside this function**, not just the UI |
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

- **`action=manifest&site_rev=N`** → `{site_rev, changed_pages:[{page_id,revision,url}]}`. Phase 1
  simplification: "changed since N" just means "is `client.site_rev` > N" — if so, returns **every**
  currently-live page, not a true per-page diff (Phase 2 item).
- **`action=page&page_id=X`** → `{html, style, head}`, fully server-rendered (breadcrumbs + blocks, CTA
  band injected before FAQ, trust bar injected after hero — fixed positions, not a rhythm engine).
- **`action=hub`** → the client-level aggregate listing page (every live page grouped by city), smaller
  dedicated stylesheet, "coming soon" state if zero live pages.

Plugin side (`pluscities_delivery_sync_if_needed()`): triggered at request time on `template_redirect`
(not WP-cron — a Phase 1 simplification, same contract either way). Compares
`pluscities_site_rev_seen` against the manifest's `site_rev`; if behind, fetches each changed page + the
hub, caches each as a **no-expiry transient** — this absence of expiry *is* the offline-cache fallback:
last-known-good content keeps serving indefinitely if the server later becomes unreachable. A URL miss
(never synced) 404s. **Invalidation is entirely `client.site_rev`-driven** — there's no invalidation
finer than "whole client" today.

## 4. Functional scope — what's real vs. scaffolding, today

Honest numbers, not aspirational ones:

- **Niche families: 2 of 6 are real.** `home-trades` and `restoration` have genuine, distinct defaults.
  `legal`, `medical-wellness`, `outdoor-cleaning`, `professional-services` are byte-identical placeholder
  stubs — even `label` is the raw family_id (e.g. `"label": "medical-wellness"`, not "Medical &
  Wellness"), and all 6 families reference the single `contractor` compliance preset, including
  `medical-wellness`, even though the spec's own text names a `medical` preset that doesn't exist on disk.
- **Niche blueprints: 2 real** (`hvac`, `water-damage-restoration`).
- **Compliance presets: 1 real** (`contractor`).
- **Real clients: 3** (`elite-air-hvac`, `lonestar-restoration`, `meade-restoration`).
- **Validator: 12 of 14 spec'd checks built**; block-mix and Sources checks deferred by design, not debt.
- **Two-stage approval gate**: schema-reserved (`pending_platform_review`/`pending_agency_review`), **zero
  code ever sets these values** — `pluscities_approve_page()` always takes the direct no-gate path. Fully
  exists in the *old* LPC system (`includes/landing_page_clients_approvals.php`) but not ported.
- **Manifest diffing**: whole-client, not true per-page revision diffing yet (Phase 2).
- **Dataset providers**: only Census (`census_acs`) wired. NOAA/FEMA named in the spec as remaining work,
  not built — concretely, the `climate_data` block type is spec'd as Automatic/NOAA-sourced but is
  shipped as a normal AI-written Editable block grounded in a fact card instead, because no NOAA provider
  exists.
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
- **Deferred by design, not debt** (explicitly Phase 3/4 per the spec): Stripe Connect/rebilling, custom
  dashboard domain, CSV bulk import, GSC indexing-status monitoring, white-labeled PDF reports, call
  tracking, a Client Approver role, scheduled fact-card refresh, Spanish pages, webhooks, audit-log UI,
  HMAC signing of the sync API.

## 5. User roles & menu capability

### 5.1 Role model

`lpc_current_actor_role()` returns `$_SESSION['actor_role'] ?? 'super_admin'` — defaults to super_admin
whenever unset, which is every session started via the platform operator's own `login.php` (it never sets
`actor_role` explicitly).

| Role | Set by | Scope | Can / can't |
|---|---|---|---|
| `super_admin` | Default whenever `actor_role` is unset (`login.php`) | Everything | Full access to every PlusCities + platform-only screen (Agencies, Niche Library, Settings, Platform Info, Demo Sites, WP Builder, Site Factory). Only role that can impersonate. **Cannot** access `pluscities_team.php` directly (agency_admin-only) except by impersonating that agency. |
| `agency_admin` | `agency_login.php` | Own `agency_id` | Create/manage own agency's clients, pages, photos, hub text, branding, team roster (`editor_can_publish` toggle). Blocked from every platform-only screen. |
| `agency_editor` | `agency_login.php`, real per-person account (`includes/pluscities_users.php`) | Same agency, further narrowed by `assigned_client_ids` (empty = sees all of agency's clients) | Edit/lock/regenerate/swap/approve freely. **Publish is conditionally blocked** unless the agency's `editor_can_publish` flag is on (off by default) — enforced inside `pluscities_publish_page()` itself, not just the UI. |
| `end_client` | `client_login.php` | Exactly one client | Excluded from every `pluscities_*.php` screen. Only reaches `client_portal.php` (read-only reporting) — **and that portal is 100% wired to the OLD LPC client schema, not PlusCities** (see §5.5). |

Impersonation (`admin/impersonate.php`) overlays role/agency/client without touching the real login flag:
`super_admin` → any agency (as its admin) or any client (as end_client); `agency_admin` → one of its own
clients. One hop only, no stacking.

### 5.2 Admin page inventory — `admin/pluscities_*.php`

| File | Screen | Role gate |
|---|---|---|
| `pluscities_clients.php` / `_save.php` | Client list + "New Client" (identity, hub, licenses, hours, first-page block picker) | Auth + role ≠ end_client |
| `pluscities_page.php` / `_save.php` | Single-page block editor — Edit/Lock/Regenerate/Swap/Validate/Override/Approve/Publish/Rollback | + `pluscities_actor_can_access_client()` on the page's client |
| `pluscities_page_add.php` / `_save.php` | Add a city/service page to an existing client | + access check |
| `pluscities_pages.php` / `_save.php` | Table of all pages — stage-dot matrix, bulk approve/publish/regenerate | Agency-level scope only on the list (see §5.5 gap) |
| `pluscities_photos.php` / `_save.php` | Logo + brand color + per-slot photo upload | List view agency-level only (see §5.5 gap); save handler does check per-client access |
| `pluscities_hub_edit.php` / `_save.php` | Edit a client's hub/listing page text | + access check |
| `pluscities_plugin_download.php` | Zips the delivery plugin, text-substitutes agency branding | Auth only, no role exclusion |
| `pluscities_sync.php` | **Public API endpoint** for the WP plugin | No session/role gate at all — Bearer API-key auth, by design, outside the actor-role system entirely |
| `pluscities_team.php` / `_save.php` | Manage Agency Editor accounts + `editor_can_publish` toggle | **`agency_admin`-only** — excludes super_admin too, not just lower roles |
| `pluscities_niches.php` / `_save.php` | Niche Library — families, niches, compliance presets | **`super_admin`-only** |

### 5.3 Nav structure — `admin/lpc_admin_layout.php`'s `lpc_admin_nav_items()`

Shared by LPC + PlusCities admin pages (not the ~80-page Site Factory, which has its own separate chrome).

- `end_client` sees exactly one item: Reporting → `client_portal.php`.
- Everyone else sees, in order: All Clients (old-LPC, shared) → New Client (old-LPC, shared) → Approvals
  (old-LPC, shared) → **PlusCities Clients** → **PlusCities Pages** → **Photos** → *(agency_admin only:
  My Branding, Team)* → *(super_admin only, "Platform" section: Agencies, Niche Profiles "(old)", Niche
  Library, Demo Sites, WP Builder, Platform Information, Settings, ← Site Factory)*.

**Two deliberately parallel niche systems exist simultaneously**: `niche_profiles.php` (old engine,
labeled "(old)" in the nav itself, kept alive because `landing_page_clients_ai.php` still reads it) and
`pluscities_niches.php` (the real PlusCities Niche Library). This is intentional during migration, not
drift — but a fresh reader needs to know both exist.

Not in the nav at all (reached only via links from other pages): `pluscities_hub_edit.php`,
`pluscities_page_add.php`, `pluscities_page.php`, `pluscities_plugin_download.php`.

### 5.4 Auth entry points

| File | Who | Redirects to |
|---|---|---|
| `admin/login.php` | Platform operator | `sites.php` (Site Factory) |
| `admin/agency_login.php` | Agency Admin or Agency Editor | `clients.php` (old-LPC shared list) |
| `admin/client_login.php` | End client of an **old-LPC** client | `client_portal.php` |

**None of the three staff-facing logins land directly on a PlusCities-native screen** — worth knowing
before assuming a login flow needs fixing; this is a real, already-present inconsistency, not new.

### 5.5 Known gaps (real, not hypothetical — found via direct code read)

1. **No PlusCities-native `end_client` login path exists.** `client_login.php`/`client_portal.php` are
   100% scoped to the old `lpc_client_dir()` tree, not `PLUSCITIES_CLIENTS_DIR`. Every `pluscities_*.php`
   file dutifully excludes `role === 'end_client'`, but there's currently no way for a PlusCities client
   to ever obtain that role in the first place — a real client-facing reporting portal for PlusCities
   clients is effectively unbuilt.
2. **Agency Editor's `assigned_client_ids` restriction is enforced on writes but not consistently on
   reads.** `pluscities_clients.php` (client list) and `pluscities_pages.php` (pages table) only apply
   agency-level scoping, not the per-editor restriction — an editor sees every client/page in the agency
   even if assigned to only some. `pluscities_photos.php`'s list view has the same gap. The corresponding
   `_save.php` handlers DO check per-client access, so this is within-agency over-exposure on reads, not a
   cross-agency leak or a write-path hole.

## 6. Relationship to the old LPC system

Genuinely shared: `admin/lpc_admin_layout.php` (nav/chrome for both), `includes/helpers.php`-style
utilities (`h()`, `sanitize_url()`, `slugify()`, CSRF pattern), `admin/infra/lib/http.php`, the image
plugins (`plugins/image-data-chart/*`, `plugins/image-area-map/*`), and `niche_profiles.php`'s library
(still read by `landing_page_clients_ai.php`, kept alive deliberately).

Fully separate: delivery mechanism (LPC's own standalone server + WordPress renderer vs. PlusCities'
server-side-render-and-sync model), the database (`fleet.db`/LPC vs. `pluscities.db`), billing
(`stripe_client.php` is LPC-only), the whole admin client-record shape (`clients/*/data/client.json` vs.
`pluscities_clients/*.json` — intentionally never reconciled).

`docs/lpc-vs-site-factory-boundary.md` and `docs/landing-page-clients-onboarding.md` describe the old
system specifically — real and still accurate for what they describe (LPC's own separate delivery
server, its own renderer, the old onboarding wizard), but **not** current PlusCities process. Read this
doc for current state; read those two only when working on the old system itself.

## 7. Appendix — source-of-truth file index

| File | What it is |
|---|---|
| `admin/infra/lib/pluscities_db.php` | SQLite schema + connection |
| `includes/pluscities_lifecycle.php` | Page/block state machine, all lifecycle verbs |
| `includes/pluscities_writer.php` | Content generation per block classification |
| `includes/pluscities_research.php` | Fact-card research + dataset fetch |
| `includes/pluscities_validator.php` | The 12 compliance/quality checks |
| `includes/pluscities_niche_library.php` | Niche family/blueprint/preset resolution |
| `includes/pluscities_schema.php` | JSON-LD assembly, city-label resolution |
| `includes/pluscities_color_detect.php` | Brand color resolution precedence |
| `includes/pluscities_images.php` | Photo slot model |
| `includes/pluscities_users.php` | Agency Editor accounts |
| `admin/pluscities_sync.php` | The public delivery API (manifest/page/hub) |
| `wp-plugin/pluscities-delivery/pluscities-delivery.php` | The WP plugin (fetch/cache/place) |
| `admin/lpc_admin_layout.php` | Shared nav/chrome + role-driven menu |
| `admin/login.php` / `agency_login.php` / `client_login.php` | The three auth entry points |
| `admin/pluscities_clients.php` / `_save.php` | Client creation + first-page picker |
| `admin/pluscities_page.php` / `_save.php` | Single-page block editor + lifecycle actions |
| `admin/pluscities_pages.php` / `_save.php` | Bulk page table |
| `config.php` | All `PLUSCITIES_*` path/dir constants |
| `docs/PLUSCITIES_FEATURE_SPEC.md` | Original design intent/roadmap (not as-built) |
