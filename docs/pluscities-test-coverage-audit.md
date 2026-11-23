# PlusCities — Test Coverage Audit (2026-11-23)

What UC1-8 actually exercise, mapped against every real capability listed in
`docs/pluscities-system-reference.md`, verified against the live DB/code — not assumed. Goal: know
honestly what percentage of the system has been proven to work by *running* it, versus what's only ever
been read from code.

**Headline number, corrected 2026-11-23 against an exact row count** (the original write-up quoted ~58%,
which didn't match this doc's own table — fixing that here rather than leaving an inconsistent number on
record): of the **47 discrete rows** below, **20 are directly exercised by UC1-8 (~43%)**. The gaps are
concentrated in three areas: the validator (only 2 of 12 checks ever deliberately triggered), 5 whole
admin screens never touched at all, and the agency-business layer (billing, branding flow-through,
suspend/approve).

**Scott's call: expand the next round to close everything realistically closable, not just the first
narrow pass.** Two real gaps surfaced while projecting the original UC9-13 scope — `pluscities_hub_edit.
php` (missed entirely) and fresh `census_acs` dataset fetch (cheap, just never scoped) — both folded in,
plus a new UC14 for the agency billing/suspend-approve rows UC1/UC5 never touched. See "Recommended next
use cases" below for the final scope. **Net effect: 42/47 (~89%)**, leaving only 5 rows genuinely out of
reach without new infrastructure (Stripe, the SEO-plugin sitemap *hook* integration specifically, two
structurally-unreachable-via-the-real-admin validator checks, and fresh fact-card research's real API
cost).

---

## 1. Lifecycle functions (`includes/pluscities_lifecycle.php`) — 10/10 tested

| Function | Tested in |
|---|---|
| `pluscities_validate_block_picks()` | UC1, UC2 (picker forms) |
| `pluscities_create_page()` | UC1, UC2, UC5, UC8 |
| `pluscities_regenerate_block()` | UC2 (swap), UC3 (bulk) |
| `pluscities_swap_block()` | UC2 (valid + rejected out-of-pool) |
| `pluscities_set_block_lock()` | UC2, UC3, UC7 |
| `pluscities_edit_block()` | UC3, UC6 |
| `pluscities_approve_page()` | UC1-3, UC5-8 |
| `pluscities_publish_page()` | UC1-3, UC5, UC7, UC8 |
| `pluscities_rollback_page()` | UC7 (found+fixed a real bug) |
| `pluscities_unpublish_page()` | UC7 (found+fixed a real bug) |

**Fully covered** — every real state transition has been run for real at least once.

## 2. The validator (`includes/pluscities_validator.php`) — 2/12 checks deliberately triggered

| Check | Deliberately triggered? |
|---|---|
| `unsupported_claim` | ✅ UC7 (rollback's dropped `client_grounded` bug surfaced through this check) |
| `banned_phrases` | ✅ UC6 (client-level list; override mechanism proven) |
| `uniqueness` | ❌ never forced a failure — passes by default at this fixture's scale |
| `service_radius` | ❌ never added an out-of-radius city to trigger it |
| `wrong_city` | ❌ never cited a fact card scoped to the wrong city |
| `client_facts` | ❌ never injected a phone/license mismatch |
| `invented_prices` | ❌ never triggered — also a **hard exception**, never overridable |
| `invented_reviews` | ❌ never triggered — hard exception |
| `quotes` | ❌ never triggered — confirmed dead code in practice per the reference doc |
| `stale_facts` | ❌ never triggered — would need manipulating `fact_cards.expires_at` |
| `cannibalization` | ❌ never triggered — structurally near-impossible via the normal create path |
| `faq` | ❌ always passed; never deliberately broken |
| **Override — soft check** | ✅ UC6 |
| **Override — hard exception refusal** | ❌ never attempted overriding `invented_prices`/`invented_reviews`/etc. to confirm the refusal actually holds |

**Real gap.** The override mechanism's *grant* path is proven; its *refusal* path (hard exceptions) never
has been. 10 of 12 individual checks have never been deliberately forced to fail even once.

## 3. Block classifications / generation paths — 3/4 tested

| Classification | Tested? |
|---|---|
| `editable` (AI-written, fact-grounded) | ✅ extensively — hero, overview, faq, permits, etc. |
| `automatic`, dataset-templated (`housing_stock`) | ✅ UC1, UC2, UC8 |
| `automatic`, niche-shared table (`niche_data_table`) | ✅ UC8 (hvac vs. restoration content compared directly) |
| `client-supplied` (`reviews_from_city`) | ❌ **never exercised by any use case.** The 3 real `reviews_from_city` blocks in the live DB all belong to pre-existing production clients (`elite-air-hvac` ×2, `lonestar-restoration`) — none from the QA fixture. None of our QA clients' picks ever selected it, by coincidence of which slugs got chosen. |

## 4. Admin screens — 14/19 touched, 5 completely untested

| Screen | Tested? |
|---|---|
| `pluscities_clients.php` / `_save.php` | ✅ UC1, UC5 |
| `pluscities_page.php` / `_save.php` | ✅ UC1-3, UC6-8 |
| `pluscities_page_add.php` / `_save.php` | ✅ UC2, UC5 |
| `pluscities_pages.php` / `_save.php` | ✅ UC3 (bulk), UC4 |
| `pluscities_team.php` / `_save.php` | ✅ UC5 |
| `pluscities_sync.php` | ✅ throughout |
| `pluscities_dashboard.php` | ✅ verified during build (2026-11-23), not yet as a formal use case |
| `agencies.php` / `agency_login.php` | ✅ creation + login tested; suspend/approve/billing fields not (see §6) |
| `pluscities_hub_edit.php` / `_save.php` | ❌ **never tested** — built in Project B, never exercised since |
| `pluscities_photos.php` / `_save.php` | ❌ **never tested** — logo upload, brand color detection/override entirely unexercised |
| `pluscities_plugin_download.php` | ❌ **never tested** — the actual artifact a real agency hands to a real client, never downloaded or inspected once |
| `pluscities_niches.php` / `_save.php` | ❌ **never tested** — the real Niche Library (family/niche/compliance-preset CRUD, keyword validation) has zero use-case coverage, despite 4 of 6 families being known placeholder stubs that a real admin would need this screen to fix |
| `admin/impersonate.php` | ❌ **never tested** — `start`/`stop` actions, directly relevant to the role model UC5 already explored |

## 5. Delivery protocol & plugin — core proven, auxiliary paths untested

| Capability | Tested? |
|---|---|
| `action=manifest` / `action=page` / `action=hub` | ✅ extensively, including the Unpublish fix |
| WP plugin poll/cache/reconcile | ✅ UC1, UC3, UC7, the Unpublish fix |
| `pluscities-sitemap.xml` self-served sitemap | ❌ never hit |
| Yoast/RankMath/AIOSEO sitemap hook integration | ❌ never exercised (would need one of those plugins active on the test WP install) |
| `robots.txt` fallback line | ❌ never checked |

## 6. Agency business layer — creation only

| Capability | Tested? |
|---|---|
| Agency creation | ✅ UC1, UC5 |
| Agency login | ✅ UC1, UC5 |
| `editor_can_publish` toggle | ✅ UC5 |
| `spend_cap_usd` / `monthly_fee_usd` billing fields | ❌ never set or checked against anything |
| Agency branding (`display_name`/`logo_url`) flowing into a downloaded plugin | ❌ never tested — ties directly to `pluscities_plugin_download.php` (§4), also untested |
| Agency suspend/approve (`agencies.php`'s `toggle_status`) | ❌ never tested |
| `create_checkout` (Stripe) action | ❌ never tested (and likely needs real Stripe sandbox creds — may not be practically testable here) |

## 7. Niche/content pipeline — resolution proven, authoring untested

| Capability | Tested? |
|---|---|
| Niche family/blueprint resolution (`pluscities_niche_resolved()`) | ✅ hvac + restoration, directly compared in UC8 |
| Niche/family/preset CRUD via the real admin | ❌ never tested (ties to `pluscities_niches.php`, §4) |
| Fresh fact-card research (`pluscities_research_run()`) for a brand-new topic/city | ❌ never triggered — every fact card used this session already existed from prior work |
| Fresh dataset fetch (`census_acs`) for a city with no cached data | ❌ never triggered — `housing_stock` blocks this session all hit already-cached Census data |

---

## Recommended next use cases — final scope, Scott approved expanding to maximize coverage

Full scope for each written into `docs/pluscities-use-case-tests.md` directly (not repeated here). Net
effect of running all six as scoped: **42 of 47 rows closed (~89%)**, up from 20/47 (~43%) today.

1. **UC9 — Validator thoroughness.** Expanded beyond the original 3 checks to trigger `service_radius`,
   `wrong_city`, `client_facts`, `uniqueness`, `stale_facts`, `faq`, and one hard exception + refusal
   proof — 8 of the 10 untested validator-related rows, every one that's realistically triggerable
   through the real admin.
2. **UC10 — Photos, branding, plugin artifact, hub content, and self-served delivery extras.** Expanded
   to also cover `pluscities_hub_edit.php` (missed in the first pass) and the self-served
   `/pluscities-sitemap.xml` + `/robots.txt` (realized these need zero 3rd-party SEO plugin — only the
   Yoast/RankMath/AIOSEO *hook* integration does, which stays excluded). Five real gaps closed together.
3. **UC11 — Niche Library authoring.** Unchanged from the original proposal.
4. **UC12 — Impersonation.** Unchanged from the original proposal.
5. **UC13 — Client-supplied reviews + a brand-new city's first dataset fetch.** Expanded to also
   provision a page for a city never used by any QA client, closing the "first datasets-table row for
   this city" gap alongside the reviews-content gap — a natural pairing, not a separate use case.
6. **UC14 — Agency billing fields + suspend/approve** (new, not in the original 5-item list). Closes 2
   more real, no-external-dependency rows in the agency-business layer that UC1/UC5 only partially
   touched (they tested creation, never billing fields or the suspend/approve lifecycle).

**Only 5 rows remain genuinely out of reach without new infrastructure, deliberately not scoped as use
cases:** Stripe checkout (needs real sandbox credentials), the Yoast/RankMath/AIOSEO sitemap *hook*
integration specifically (needs those plugins actually installed — the self-served sitemap itself is
covered by UC10), `quotes` (confirmed dead code — the writer never injects raw `quote` text, nothing to
trigger), `cannibalization` (structurally blocked via the real admin — `page_id` is deterministic from
client+city+service, the normal create path can't produce a duplicate), and fresh fact-card research for
a genuinely new topic (expensive — spends real Anthropic API budget each time, same discipline this
session already applied to the one real billed run in the City Service Page work).
