# PlusCities — Test Coverage Audit (2026-11-23)

What UC1-8 actually exercise, mapped against every real capability listed in
`docs/pluscities-system-reference.md`, verified against the live DB/code — not assumed. Goal: know
honestly what percentage of the system has been proven to work by *running* it, versus what's only ever
been read from code.

**Headline number:** of the ~45 discrete capabilities inventoried below, **26 are directly exercised**
by UC1-8 (~58%). The gaps are concentrated in three areas: the validator (only 2 of 12 checks ever
deliberately triggered), 5 whole admin screens never touched at all, and the agency-business layer
(billing, branding flow-through, suspend/approve).

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

## Recommended next use cases, ranked by value

1. **UC9 — Validator hard-exception + remaining checks.** Deliberately trigger `service_radius`,
   `wrong_city`, `client_facts`, and one hard exception (`invented_prices` or `invented_reviews`) —
   confirm each fails correctly AND confirm the hard exception genuinely refuses an override attempt
   (never proven). Closes the single biggest gap (10 of 12 checks untested).
2. **UC10 — Photos, branding, and the real plugin artifact.** Upload a logo, set/detect brand colors on
   a QA client, then actually download that agency's branded plugin zip and inspect it (confirm the
   agency's own name/logo appears, not "PlusCities"). Three untested screens closed by one real workflow.
3. **UC11 — Niche Library authoring.** Use the real admin to flesh out one of the 4 placeholder-stub
   families (or create a new compliance preset) rather than just reading that they're stubs — proves
   whether the authoring UI itself works, independent of what content currently exists.
4. **UC12 — Impersonation.** super_admin → agency_admin → one of its clients, confirm scope at each
   hop, confirm "stop" cleanly restores the original session with no leaked state.
5. **UC13 — client-supplied content + reviews.** Add `reviews_by_city` data to a QA client, pick
   `reviews_from_city` as a block, confirm it bypasses the AI writer entirely and the `invented_reviews`
   check correctly validates it matches verbatim.

Not recommending as use cases (real but low-value or impractical to test here): Stripe checkout (needs
real sandbox credentials), SEO-plugin sitemap hooks (needs Yoast/RankMath/AIOSEO actually installed on
the test WP box), fresh fact-card research for a genuinely new topic (expensive — spends real Anthropic
API budget each time, same discipline this session already applied to the one real billed run in the
City Service Page work).
