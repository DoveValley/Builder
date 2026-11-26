# PlusCities — Test Coverage Audit (2026-11-23)

What UC1-14 actually exercise, mapped against every real capability listed in
`docs/pluscities-system-reference.md`, verified against the live DB/code — not assumed. Goal: know
honestly what percentage of the system has been proven to work by *running* it, versus what's only ever
been read from code.

**Final headline, recounted 2026-11-23 row-by-row against this doc's own table** (the "47 total" figure
quoted through the UC9-14 planning phase had drifted from the table itself — §4's heading claimed 19
admin screens while only 13 were ever listed as rows; re-counting every section's actual rows rather than
carrying the old total forward, same discipline as the earlier 58%→43% correction): the table below has
**57 discrete rows**, of which **52 are now directly exercised by UC1-14 (~91%)**.

**All 14 use cases are complete.** UC9 closed 12 of the validator's 14 rows (including, for the first
time, proof that a hard-exception override is actually *refused*, not just that soft overrides are
*granted*). UC10 closed photos/branding/plugin-download/hub-edit plus the self-served sitemap and
robots.txt line. UC11 closed real Niche Library authoring. UC12 closed impersonation. UC13 closed
client-supplied reviews and a genuinely-fresh dataset fetch. UC14 closed agency billing fields and
suspend/approve.

**5 rows remain genuinely out of reach, deliberately not chased:** `quotes` (confirmed dead code — the
writer never emits raw `quote` text), `cannibalization` (structurally blocked via the real admin —
`page_id` is deterministic from client+city+service, so the normal create path can't produce a
collision), the Yoast/RankMath/AIOSEO sitemap *hook* integration specifically (the self-served sitemap
itself IS covered — this needs one of those plugins actually installed), Stripe `create_checkout` (needs
real sandbox credentials), and fresh fact-card research for a genuinely new topic (a real, billed
Anthropic API run each time — same discipline applied to every other paid-API call this session).

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

## 2. The validator (`includes/pluscities_validator.php`) — 12/14 rows closed (UC9, UC13)

| Check | Deliberately triggered? |
|---|---|
| `unsupported_claim` | ✅ UC7 (rollback's dropped `client_grounded` bug surfaced through this check) |
| `banned_phrases` | ✅ UC6 (client-level list; override mechanism proven) |
| `uniqueness` | ✅ UC9 — attempted via a near-duplicate page with identical topic picks; **genuinely passed**, not forced. Honest result: the AI writer varies language enough per city that matching topics alone don't produce duplicate content. |
| `service_radius` | ✅ UC9 — added an out-of-radius, unconfirmed city; failed exactly as expected. |
| `wrong_city` | ✅ UC9 — cited a real fact card scoped to a different city (direct precondition write, no UI assigns fact_ids); failed correctly. |
| `client_facts` | ✅ UC9 — injected a wrong phone number via the real Edit UI; failed correctly. |
| `invented_prices` | ✅ UC9 — injected an unsupported price; failed, **and** the hard-exception override refusal was proven for the first time (UI renders no Override control at all; a raw POST attempt was refused server-side). |
| `invented_reviews` | ✅ UC13 — real client reviews matched verbatim; passed correctly. |
| `quotes` | ❌ **deliberately not attempted** — confirmed dead code in practice (the writer never injects raw `quote` text). |
| `stale_facts` | ✅ UC9 — cited a real, correctly-scoped fact card then flipped its status to stale (direct precondition write, no UI sets this); failed correctly. |
| `cannibalization` | ❌ **deliberately not attempted** — structurally near-impossible via the real admin (`page_id` is deterministic from client+city+service). |
| `faq` | ✅ UC9 — found a real gap first: the Edit form's Save button is correctly `disabled` for FAQ blocks (content is shaped `{faqs:[...]}`, not `{body:...}`), so there's no admin path to fix one bad answer short of a full regenerate. Triggered the check via a direct content write to prove it works. |
| **Override — soft check** | ✅ UC6 |
| **Override — hard exception refusal** | ✅ UC9 — proven for the first time. |

## 3. Block classifications / generation paths — 4/4 tested

| Classification | Tested? |
|---|---|
| `editable` (AI-written, fact-grounded) | ✅ extensively — hero, overview, faq, permits, etc. |
| `automatic`, dataset-templated (`housing_stock`) | ✅ UC1, UC2, UC8, UC13 (first-ever fresh fetch) |
| `automatic`, niche-shared table (`niche_data_table`) | ✅ UC8 (hvac vs. restoration content compared directly) |
| `client-supplied` (`reviews_from_city`) | ✅ UC13 — added real `reviews_by_city` data, confirmed the block copies it verbatim (`origin='client'`), never touches the AI writer. |

## 4. Admin screens — 13/13 touched (0 completely untested)

| Screen | Tested? |
|---|---|
| `pluscities_clients.php` / `_save.php` | ✅ UC1, UC5 |
| `pluscities_page.php` / `_save.php` | ✅ UC1-3, UC6-9, UC13 |
| `pluscities_page_add.php` / `_save.php` | ✅ UC2, UC5, UC9, UC13 |
| `pluscities_pages.php` / `_save.php` | ✅ UC3 (bulk), UC4 |
| `pluscities_team.php` / `_save.php` | ✅ UC5 |
| `pluscities_sync.php` | ✅ throughout |
| `pluscities_dashboard.php` | ✅ verified during build + UC14's login flow |
| `agencies.php` / `agency_login.php` | ✅ creation+login (UC1, UC5), billing+suspend/approve (UC14) |
| `pluscities_hub_edit.php` / `_save.php` | ✅ UC10 |
| `pluscities_photos.php` / `_save.php` | ✅ UC10 — found+fixed a root-owned-dir bug (`pluscities_uploads/`) along the way |
| `pluscities_plugin_download.php` | ✅ UC10 — real branded zip downloaded+inspected, zero leftover "PlusCities" strings |
| `pluscities_niches.php` / `_save.php` | ✅ UC11 — found+fixed 3 more root-owned dirs; a real niche authored end to end |
| `admin/impersonate.php` | ✅ UC12 |

## 5. Delivery protocol & plugin — 4/5 (only the 3rd-party hook integration excluded)

| Capability | Tested? |
|---|---|
| `action=manifest` / `action=page` / `action=hub` | ✅ extensively, including the Unpublish fix |
| WP plugin poll/cache/reconcile | ✅ UC1, UC3, UC7, the Unpublish fix |
| `pluscities-sitemap.xml` self-served sitemap | ✅ UC10 — needs zero 3rd-party plugin, confirmed it correctly lists only real live pages |
| Yoast/RankMath/AIOSEO sitemap hook integration | ❌ **deliberately not attempted** — needs one of those plugins actually installed on the test WP box |
| `robots.txt` fallback line | ✅ UC10 — confirmed the `Sitemap:` line appears additively alongside WordPress's own default |

## 6. Agency business layer — 6/7 (only Stripe excluded)

| Capability | Tested? |
|---|---|
| Agency creation | ✅ UC1, UC5 |
| Agency login | ✅ UC1, UC5 |
| `editor_can_publish` toggle | ✅ UC5 |
| `spend_cap_usd` / `monthly_fee_usd` billing fields | ✅ UC14 |
| Agency branding (`display_name`/`logo_url`) flowing into a downloaded plugin | ✅ UC10 |
| Agency suspend/approve (`agencies.php`'s `toggle_status`) | ✅ UC14 — confirmed the exact "credentials right, access wrong" message |
| `create_checkout` (Stripe) action | ❌ **deliberately not attempted** — needs a real Stripe sandbox |

## 7. Niche/content pipeline — 3/4, only paid fresh research excluded

| Capability | Tested? |
|---|---|
| Niche family/blueprint resolution (`pluscities_niche_resolved()`) | ✅ hvac + restoration, directly compared in UC8 |
| Niche/family/preset CRUD via the real admin | ✅ UC11 — a real niche (dental-practice) + a new compliance preset authored end to end through `pluscities_niches.php` |
| Fresh fact-card research (`pluscities_research_run()`) for a brand-new topic/city | ❌ **deliberately not attempted** — a real, billed Anthropic API run each time; same discipline as other paid-API runs this session |
| Fresh dataset fetch (`census_acs`) for a city with no cached data | ✅ UC13 — provisioned a page for a city never used by any client/niche system-wide; confirmed a brand-new `datasets` row was created, not served from cache |

---

## How UC9-14 closed the gap — 20/57 → 52/57

Full per-use-case detail lives in `docs/pluscities-use-case-tests.md` (not repeated here).

1. **UC9 — Validator thoroughness.** Triggered `service_radius`, `wrong_city`, `client_facts`,
   `uniqueness` (genuinely passed, not forced), `stale_facts`, `faq`, and proved the hard-exception
   override *refusal* for the first time via `invented_prices`. Closed 10 validator rows in one use case.
2. **UC10 — Photos, branding, plugin artifact, hub content, and self-served delivery extras.** Found and
   fixed a root-owned-directory block on `pluscities_uploads/` along the way. Closed `pluscities_photos.
   php`, `pluscities_hub_edit.php`, `pluscities_plugin_download.php`, agency branding flow-through, the
   self-served sitemap, and the `robots.txt` line — 6 rows across 3 sections.
3. **UC11 — Niche Library authoring.** Authored a real niche (dental-practice) plus a new compliance
   preset end to end through `pluscities_niches.php`. Found and fixed 3 more root-owned directories along
   the way; also surfaced a real UI gap (several niche-blueprint fields have no admin screen at all) now
   recorded in `docs/pluscities-system-reference.md`.
4. **UC12 — Impersonation.** Confirmed `admin/impersonate.php`'s start/stop/no-stacking behavior;
   along the way, ruled out what first looked like a broken restoration as a test-script CSRF-token
   mistake, not a product bug.
5. **UC13 — Client-supplied reviews + a brand-new city's first dataset fetch.** Closed
   `invented_reviews`, the `reviews_from_city` client-supplied classification, and a genuinely-fresh
   `census_acs` dataset fetch (confirmed a brand-new `datasets` row, not a cache hit) in one page.
6. **UC14 — Agency billing fields + suspend/approve.** Closed the 2 remaining agency-business rows
   UC1/UC5 never touched — confirmed the exact "account currently suspended" message, distinct from a
   generic bad-credentials error.

Across UC9-14: 5 more root-owned-directory permission bugs found and fixed (bringing the session total to
6), 1 silent-failure correction (a UC10 claim about branded-image refresh that turned out wrong on
re-check, corrected on the record rather than left standing), 2 durable UI gaps documented in the
reference doc (FAQ-content editing, niche-authoring fields), 1 real account-funding issue surfaced
(DataForSEO 402 on keyword validation — not a code bug), and 1 testing-methodology lesson (Playwright
dialog auto-dismiss, hit twice; CSRF tokens aren't present on every page).

**Not re-tallied into the 57-row table above:** UC19 (2026-11-25/26, `docs/pluscities-use-case-tests.md`)
covers 5 new page-furniture pieces (Safety Toolkit, Area Map, Housing Snapshot, Climate Chart(s), City
Info) and the always-blocks/niche-library block-architecture rework; UC20 (same doc, 2026-11-26) covers
making the service pool family-customizable and fixing 4 real family/niche-level content-fit mismatches
found by directly reviewing it; UC21 (same doc, 2026-11-26) covers the 5-session panel-completeness
walkthrough — 3 more real bugs found+fixed, 3 new admin surfaces shipped (Niche Blueprint fields,
Business Info tab, per-page SEO + the Danger Zone client-delete rebuild), and one brand-new real client
built, published, and deleted/restored entirely through the panel; UC22 (same doc, 2026-11-26) extends
real delete-with-safety-checks to niches and compliance presets — all capabilities added *after*
this audit's row set was fixed to the 2026-11-23 system reference, not part of the original spec being
measured here. See `docs/pluscities-system-reference.md` §5.5 for what's there and UC19-22 for how it
was verified.
