# PlusCities — Use Case Test Suite

Real, long walkthroughs run against the live system (`187.127.254.206`, same box this is served from) to
verify `docs/pluscities-system-reference.md`'s claims against actual behavior, not just read them. Fixture
is permanent and reusable, not cleaned up after each run — meant to be re-run as a regression check.

**Fixture:** Agency `QA Test Agency` (`qa-test-agency`), client `QA Test Co` (`qa-test-co`, niche `hvac`).
Test-WordPress install (`/opt/landing-page-clients/test-wordpress`, `localhost:8099`) is permanently
repointed to QA Test Co's API key — this is now the QA delivery test site for this suite going forward.

Status: **All 8 use cases complete and verified.** UC6-8 were added after UC1-5 to cover 3 real gaps in
that original coverage (validator override, rollback/unpublish, and a second real niche's full
lifecycle). Across the full suite: 1 use case (UC4) confirmed a real, honest pre-existing gap rather than
a working feature; 2 real bugs were found and fixed (`pluscities_rollback_page()`'s dropped
`client_grounded` column, 2 root-owned-directory permission blockers); 1 significant gap was found and
documented but not yet fixed (Unpublish doesn't actually take content offline); and the session-long
missing-sidebar-CSS bug across all 8 `pluscities_*.php` admin pages was caught and fixed.

---

## UC1 — Happy path, beginning to end — ✅ PASS

**Goal:** prove the full chain — real admin panel → DB → sync endpoint → WP delivery plugin → rendered
page — works end to end for a brand-new client.

**Steps taken (all via the real admin at `187.127.254.206`, not CLI shortcuts):**
1. Created `QA Test Agency` via `admin/agencies.php`'s real create form.
2. Created `QA Test Co` via `admin/pluscities_clients.php` — niche `hvac`, hub in Plano TX, first city
   `plano-tx`, service `ac-repair`, **deliberately non-default** Block Topics picks: city =
   `neighborhoods, permits, hoa_rules, hazards`; service = `niche_data_table, why_choose_us, maintenance`.
3. On the resulting draft page (`pg_qatestco_plano-tx_ac-repair`): Validate → Approve → Publish, each via
   the real buttons on `admin/pluscities_page.php`.
4. Hit `admin/pluscities_sync.php?action=page` directly with QA Test Co's real API key.
5. Repointed the test-WordPress install's delivery-plugin settings to QA Test Co, triggered a real sync,
   loaded both the page route and the bare hub route.

**Verified:**
- `blocks` table has exactly the 11 chosen block types, correctly positioned (spine first/last, city
  blocks before service blocks) — confirms the picker's picks reach the DB unmodified.
- `pages.status='live'`, `approval_stage='approved'`, `live_revision_id=1`, one `revisions` row, client
  `site_rev` bumped to 1 — exactly the lifecycle the reference doc describes.
- `action=page` returns real rendered HTML: `<h1>Professional AC Repair in Plano, TX</h1>`, title
  `AC Repair in Plano, TX | QA Test Co`.
- `http://localhost:8099/service-area/plano-tx/ac-repair/` → 200, real content, through the actual WP
  theme (`get_header()`/`get_footer()`), not just the raw JSON.
- `http://localhost:8099/service-area/` (hub route) → 200, lists `Plano, TX`.

**Real bugs found and fixed along the way (not scripted in advance — genuine findings):**
1. **`pluscities_clients/` directory and `admin/infra/state/pluscities.db` were root-owned**, silently
   blocking the live Apache/www-data process from creating any new client or writing to the DB at all —
   same bug class already tracked in memory (`feedback_stale_root_owned_tmp_dirs_break_builds`, v464's
   fix of 2 other instances). First attempt at creating QA Test Co failed with a fatal
   `attempt to write a readonly database` error. Fixed via `chown www-data:www-data` on the specific
   blocking paths (directory + DB file + one incidentally-found `audit_settings.json`), not a blanket
   sweep — consistent with the standing "fix concretely-identified blockers, don't `chown -R` the whole
   webroot" policy.
2. **Testing-methodology gotcha, not a shipped-code bug:** a bare PHP CLI script that changes
   `pluscities_base_path` and then calls `flush_rewrite_rules()` in the same process does NOT pick up the
   new value — `pluscities_delivery_add_rewrite_rules()` is hooked to `init`, which already fired (with
   the OLD option value) during `wp-load.php`'s own bootstrap, before the option change. A real WordPress
   admin-settings-page save doesn't hit this, since each request is fresh. Fix for test scripts: call
   `pluscities_delivery_add_rewrite_rules()` explicitly before `flush_rewrite_rules(true)` after changing
   `base_path` via CLI.

**Artifacts left in the live system:** `QA Test Agency` (agency), `QA Test Co` (client, 1 live page),
test-WordPress install repointed to QA Test Co.

---

## UC2 — Provisioning options — ✅ PASS

**Goal:** exercise the branches UC1's happy path skips — a second page with a different pick
combination, Swap, Lock, and the negative paths (regenerating a locked block, swapping out of pool).

**Steps taken (all via the real admin, building on UC1's `QA Test Co`):**
1. Added a 2nd page via `admin/pluscities_page_add.php` — `mckinney-tx`, `furnace-repair`, city picks
   `climate_data, housing_stock, utilities, seasonal_guide` / service picks `process, cost_factors,
   repair_vs_replace` — deliberately the *other* half of the niche's pool vs. page 1's picks.
2. Swapped block 114 (`why_choose_us`, service pool) on page 1 to `cost_factors` via the real Swap
   dropdown.
3. Locked block 114, confirmed the Regenerate button is disabled client-side, then sent a **raw POST**
   directly to `pluscities_page_save.php` bypassing the disabled button — confirming the server-side
   guard holds independently of the UI.
4. Sent a raw POST attempting to swap block 113 (`niche_data_table`, service pool) to `permits` (a
   city-pool type) — confirming the pool-membership validation (added earlier this session) rejects it.
5. Unlocked block 114, validated/approved/published page 2.

**Verified:**
- Page 2's blocks in the DB exactly match the chosen picks, in correct spine/city/service order — a
  genuinely different page from page 1, not a copy (proves the picker produces real variety).
- `target_cities[]` on the client JSON gained `mckinney-tx` automatically.
- Block 114 is now `block_type='cost_factors'`, `origin='generated'`, with real regenerated content
  (`"AC Repair Cost Factors in Plano, TX"...`) — the swap's regenerate-immediately behavior confirmed.
- Locked-block regenerate: UI disables the button (`disabled` attribute, confirmed via Playwright), AND
  the raw POST was independently rejected — `302` redirect with
  `error:This+block+is+locked+—+unlock+it+first.` Both layers hold, not just the UI.
- Out-of-pool swap: raw POST rejected — `302` redirect with
  `error:"permits"+is+not+in+this+block's+pool.` Block 113 confirmed unchanged afterward
  (`niche_data_table`, unlocked) — the 2026-11-22 pool-membership fix holds live, not just in the CLI
  test from when it was built.
- Page 2 published cleanly: `status='live'`, `approval_stage='approved'`, 1 revision.

**Bonus finding, not planned but real:** after the UC2 swap, page 1 now shows `status='live'` **and**
`approval_stage='none'` simultaneously — the exact dual-axis mechanic `docs/pluscities-system-reference
.md` §2.1 describes, now demonstrated live on this fixture by an ordinary provisioning action (a swap),
not just the Meade example cited in the reference doc. UC3 will exercise this deliberately via a direct
content edit; this is the same mechanic showing up incidentally first.

**Artifacts:** `QA Test Co` now has 2 live pages. `client.site_rev` at 2.

## UC3 — Monthly maintenance — ✅ PASS

**Goal:** prove the maintenance cycle (edit → re-approve → republish) is safe on an already-live page,
and that the offline-cache-until-republish design claim is real, not just a comment in the code.

**Steps taken (building on UC1/UC2's now-live pages):**
1. Edited block 119 (`overview`, page 2) via the real Edit textarea, with a distinctive marker string.
2. Checked the real WP-delivered page (`localhost:8099/service-area/mckinney-tx/furnace-repair/`) for the
   marker **before** republishing.
3. Re-ran Validate → Approve → Publish on page 2.
4. Checked the real WP-delivered page again **after** republishing.
5. Ran `pluscities_pages.php`'s bulk "Regenerate unlocked blocks" action across both QA Test Co pages via
   the real checkboxes + button, having snapshotted every editable block's content first.

**Verified:**
- Immediately after the edit: `pages.status='live'` (unchanged) + `approval_stage='none'` (reset) +
  client `site_rev` unchanged at 2 + block 119 auto-locked, `origin='edited'` — exactly matching
  `pluscities_edit_block()`'s documented behavior.
- The real delivered page showed **zero** occurrences of the marker text before republishing — the live
  site is genuinely untouched by an edit until it's actually republished, not just theoretically.
- After Validate→Approve→Publish: `site_rev` bumped 2→3, and the marker text now appears **exactly once**
  on the real delivered page — the WP plugin's poll-on-next-request correctly picked up the fresh content
  once `site_rev` advanced. This is the clearest possible proof of the reference doc's "no-expiry
  transient IS the offline-cache fallback, `site_rev` is the only invalidation signal" claim — observed
  directly, not inferred from code.
- Bulk regenerate: every unlocked editable block sampled across both pages showed genuinely different,
  freshly-generated content (new headings/body text, e.g. page 1's hero heading changed from "Professional
  AC Repair in Plano, TX" to "AC Repair in Plano, TX"); block 119 — the one locked block — came out
  **byte-for-byte identical**, confirming the bulk action correctly respects locks. Both pages landed back
  at `status='live'` + `approval_stage='none'` afterward — the dual-axis mechanic observed a *third*
  distinct way this session (single edit, swap, and now bulk regenerate all trigger the same reset).

**Artifacts:** no new pages — both of QA Test Co's existing pages now carry freshly-regenerated content.
`client.site_rev` at 3.

## UC4 — Reporting — ✅ PASS (confirms a real, honest gap — not a feature pass)

**Goal:** find out what "reporting" actually means for a PlusCities client today, concretely, rather than
assuming either "it works" or "it's missing" from reading code alone.

**Steps taken:**
1. Confirmed structurally that the PlusCities client JSON schema (`pluscities_clients/qa-test-co.json`)
   has **no `login` field at all** — not a missing password, a structurally absent concept. Compare to
   the old LPC schema, which has `client['login']['username']`.
2. Called `lpc_find_client_by_username()` directly for `"qa-test-co"` and `"QA Test Co"` — both returned
   `null`. Root cause confirmed in `config.php`: `CLIENTS_DIR` (`clients/`) and `PLUSCITIES_CLIENTS_DIR`
   (`pluscities_clients/`) are genuinely different directories, and `lpc_list_clients()` — what every
   client-login lookup walks — only ever globs the old one.
3. Attempted a real login at `admin/client_login.php` with username `qa-test-co` — the single most
   plausible real-world guess, matching this admin's own id-as-username convention elsewhere. Result:
   `"Invalid username or password. (9 attempts remaining)"` — there is no credential that could ever
   succeed here for a PlusCities client, by construction, not because one hasn't been set up yet.
4. Loaded `admin/pluscities_pages.php` (the closest admin-side equivalent) to see what it actually shows
   for QA Test Co.

**Verified:**
- The client-reporting gap is real and structural, confirmed three independent ways (schema, function
  return value, and a live login attempt) — not a configuration gap that onboarding a password would fix.
- `pluscities_pages.php`'s real columns: Page | Researched | Written | Validated | Approved | Published |
  Live (stage-dot matrix, one row per page, across **every client a super_admin can see**).
- **Additional finding, not previously documented**: this screen has **no per-client filter at all** —
  `?client_id=qa-test-co` is silently ignored (the file never reads that GET param), so there's no way to
  view just one client's pages; an agency scanning for one client's status has to visually find their
  rows in a flat table listing every client's pages together. "Reporting," even on the admin side, is
  thinner than the stage-dot matrix alone suggests.
- Both QA Test Co rows correctly showed **"Validation is stale — content changed since it last ran"** —
  an accurate, honest reflection of UC3's bulk regenerate having touched content after the last Validate
  run. This is a genuinely useful signal, as far as it goes.

**Honest conclusion:** there is no PlusCities-native client reporting portal today, by construction, and
the admin-side stand-in lacks even basic per-client scoping. This use case exists to make that concrete
and reproducible, not to pretend a passing test where none is warranted.

**Artifacts:** none — read-only investigation, no state changed.

## UC5 — Roles & permissions boundary — ✅ PASS

**Goal:** prove the role model's real boundaries hold — cross-agency isolation, the Agency Editor publish
gate — and reproduce the already-documented read-scope gap live rather than citing it from code alone.

**Fixture additions:** `QA Test Agency 2` (+ `QA Test Co 2`, a separate agency entirely) for cross-agency
isolation; `QA Test Co B` under the *original* `qa-test-agency` (a 2nd client in the same agency) so the
read-scope gap had something real to reproduce against; an Agency Editor (`qa-editor`) under
`qa-test-agency`, `assigned_client_ids=['qa-test-co']` only — deliberately excluding `QA Test Co B`.

**Steps taken (all via the real admin, real separate logins):**
1. Logged in as `qa-test-agency`'s agency_admin — checked `pluscities_clients.php`.
2. Created the Agency Editor via the real Team screen, scoped to one client only.
3. Logged in as that editor — checked `pluscities_clients.php` and `pluscities_pages.php`, then attempted
   Validate → Approve → Publish on a `qa-test-co` page.
4. Logged back in as the agency_admin, flipped the "Agency Editors can publish" toggle on.
5. Logged back in as the editor, attempted Publish again.

**Verified:**
- **Cross-agency isolation holds**: the agency_admin and the editor both saw only `qa-test-agency`'s own
  2 clients (`QA Test Co`, `QA Test Co B`) — never `QA Test Co 2` (the other agency) and never any of the
  3 real production clients.
- **The publish gate holds server-side**, cleanly isolated from the "not yet approved" case: with
  `editor_can_publish` off, Validate/Approve succeeded for the editor but Publish was refused with the
  specific message `"This agency hasn't enabled Editor publishing — ask an Agency Admin to publish this
  page."` — not just a generic denial.
- Flipping the toggle on as the agency_admin, then publishing as the *same* editor session, succeeded
  immediately (`"Published"`) — the gate is live-checked per request, not cached at login.
- **The documented read-scope gap reproduced live, exactly as predicted**: despite being assigned only to
  `qa-test-co`, the editor's `pluscities_clients.php` and `pluscities_pages.php` both showed `QA Test Co
  B` too. This confirms it's a real, reproducible over-exposure (not just a code-reading inference) —
  still bounded to the editor's own agency, not a cross-agency leak.

**Real bugs found and fixed along the way, not scripted:**
1. **Every PlusCities-native admin page's sidebar/nav had zero CSS styling, full session, flagged live by
   Scott.** Root cause: `lpc_admin_head_assets()` — the function defining every shared color variable and
   the entire `.pcadmin-sidebar`/`.pcadmin-nav` stylesheet — is called by every old-LPC admin page
   (`clients.php`, `agencies.php`, etc.) but was never called by any of the 8 `pluscities_*.php` view
   files (`pluscities_clients.php`, `_hub_edit.php`, `_niches.php`, `_page.php`, `_page_add.php`,
   `_pages.php`, `_photos.php`, `_team.php`). None of them even had a `<!DOCTYPE html>`/`<head>` wrapper at
   all — PHP output went straight from server-side logic into `<body>`. Fixed by adding the same
   DOCTYPE/head/`lpc_admin_head_assets()` block every old-LPC file already uses, to all 8 files. This had
   been present since these files were first written — nothing in this session's own testing would have
   caught it, since every check so far used `textContent()`/DB state, never a visual screenshot of the
   chrome itself.
2. **`pluscities_agency_users/` was root-owned**, same bug class as UC1's 2 instances — silently blocked
   the Agency Editor account's save. Worse: `pluscities_team_save.php`'s "save" action doesn't check
   `pluscities_atomic_write_json()`'s own return value, so the UI reported **"Saved" even though nothing
   was written** — confirmed by the missing file on disk despite the success message. Fixed the directory
   ownership (same targeted-chown approach as before); the silent-failure-on-write-error gap in the save
   handler itself is noted here but not fixed — out of scope for this use case, a real but separate
   robustness gap.

**Artifacts:** `QA Test Agency 2` + `QA Test Co 2`, `QA Test Co B` (both under the original agency), one
real Agency Editor account (`qa-editor`), `qa-test-agency.editor_can_publish` now `true`.

---

## UC6 — Validator failure + override — ✅ PASS (first real exercise of this mechanism, ever)

**Goal:** the `overrides` table had 0 real rows this entire engagement before this use case — prove the
override mechanism actually works, not just that it's built.

**Finding before the test even started:** there is **no admin UI anywhere to edit a client's
`banned_phrases` after creation** — only the create-client form writes the initial (always-empty) array.
Set it directly via `pluscities_atomic_write_json()` for this test, same as a real admin would have to
today absent a dedicated screen; noted as a real, separate minor gap.

**Steps taken:**
1. Set `qa-test-co.banned_phrases = ["lifetime guarantee"]`.
2. Edited block 108 (`overview`) via the real Edit UI to include that exact phrase.
3. Ran Validate, attempted Approve.
4. Submitted the real Override form with a typed reason.
5. Attempted Approve again.
6. Edited a *different*, unrelated block (109) with a trivial change, re-validated, attempted Approve
   again — to prove the override is tied to an exact `draft_hash`, not the page or the check generally.
7. Removed the banned phrase, re-validated (clean), re-approved, re-published — left the fixture in a
   genuinely compliant, live state, not sitting on overridden content afterward.

**Verified:**
- `validation_json` showed exactly one failing check — `banned_phrases` — with a specific, accurate
  detail message: `'The "overview" block uses "lifetime guarantee" — on this client's own banned-phrase
  list.'` Correctly attributed to the client's own list (not the niche's compliance preset), confirming
  it's the overridable path.
- Approve was refused (`"One or more checks are still failing."`) before the override.
- The override form worked exactly as designed: `"Override recorded"`, then Approve immediately
  succeeded.
- **The `overrides` table's first-ever real row**: `page_id`, `check_name='banned_phrases'`, the real
  typed reason, `actor='super_admin:admin'`, and a `draft_hash` matching the page's hash at that moment.
- **The "tied to an exact draft_hash" claim proven live**: after the unrelated edit changed the page's
  overall hash (the banned phrase was still present in block 108, untouched), Approve failed again with
  the same generic failing-checks message — the override silently stopped applying, exactly as the
  reference doc describes, with zero special-casing needed to make this happen.
- After removing the phrase entirely, the page validated clean with **zero** failing checks and
  published without needing any override at all.

**Artifacts:** `qa-test-co.banned_phrases` now `["lifetime guarantee"]` (left in place — a realistic
client-level denylist entry, not test debris). One permanent row in the `overrides` table (historical
record, correctly never deleted even though it no longer applies to the current draft).

## UC7 — Rollback + Unpublish — ✅ PASS (found + fixed 1 real bug, found + documented 1 real gap)

**Goal:** neither lifecycle verb had been touched by UC1-6. Page 2 had 2 real revisions (UC2's initial
publish, UC3's post-edit republish) — enough to roll back between.

### Rollback — real bug found and fixed

**Steps:** rolled back page 2 to revision 1 via the real "Roll back to this" button (note: this button
has a JS `confirm()` dialog — Playwright auto-dismisses dialogs by default, so the first attempt silently
did nothing; needed an explicit dialog-accept handler).

**Found:** content correctly reverted to the pre-edit (pre-UC3) text, and the dual-axis mechanic held
(`status` stayed `live`, `approval_stage` reset to `none`) — but attempting to re-approve afterward
**failed** with `unsupported_claim` on the `hero` block, which had passed validation before the rollback.

**Root cause, confirmed by reading the code**: `pluscities_rollback_page()`
(`includes/pluscities_lifecycle.php`) restores blocks from the revision snapshot via an explicit
`INSERT INTO blocks (page_id, block_type, position, pool, classification, content_json, fact_ids,
dataset_refs, locked, origin)` — **`client_grounded` is missing from that column list**, even though the
snapshot (built via `SELECT *` in `pluscities_publish_page()`) correctly captures it. Every rollback
silently resets `client_grounded` to 0 for every block, regardless of what the snapshot actually stored —
which breaks the `unsupported_claim` check for any client-fact-only block (hero/overview) that relies on
that flag rather than a `fact_id`.

**Fixed**: added `client_grounded` to both the column list and the bound parameters (reading
`$b['client_grounded'] ?? 0` from the snapshot, defensively, for any older snapshot predating the column).
Re-ran the rollback after the fix: `client_grounded` correctly restored to `1`, and the page validated,
approved, and published cleanly with zero manual workaround.

### Unpublish — real, undocumented gap found

**Steps:** unpublished page 1, confirmed `status='unpublished'` and `site_rev` bumped in the DB, then
checked the real delivered route.

**Found**: the real delivered page (`localhost:8099/service-area/plano-tx/ac-repair/`) **kept returning
200 with the full old content**, even after forcing a fresh sync (`site_rev_seen` confirmed caught up to
the new `site_rev`). Root cause: the manifest action only ever returns `status='live'` pages — an
unpublished page simply stops appearing in future manifest responses, but **nothing ever tells the plugin
to delete its already-cached copy**. The cached transient has no expiry by design (that's what makes the
offline-fallback work for still-live pages) — but for an unpublished page specifically, this means the
real site keeps serving it to real visitors **indefinitely**, with no mechanism to ever stop, short of
manually clearing WordPress options/transients on the client's own site. "Unpublish" does not actually
take content offline today. This is a real, previously-undocumented gap, not something either the feature
spec or the system reference doc's existing gap list called out — flagged here, and added to the
reference doc's own gap list, rather than fixed in this pass (fixing it properly means designing a real
deletion-signal in the manifest protocol — bigger than a one-line fix, a genuine next-priority item).

Re-published page 1 afterward to leave the fixture fully live again.

**Artifacts:** `includes/pluscities_lifecycle.php` fixed (rollback bug). No lasting bad state — page 1 is
back to `status=live`/`approval_stage=approved`, page 2 likewise after its own rollback-and-republish.

## UC8 — Second niche's full lifecycle — ✅ PASS (clean — no new bugs found)

**Goal:** every prior use case ran against `hvac`. Confirm the `water-damage-restoration` niche's
distinct code paths (schema subtype, niche data table, research topics) genuinely produce different,
correct output — not `hvac`-shaped assumptions leaking through — by running a real page through the full
lifecycle on that niche for the first time this session.

**Steps:** Validate → Approve → Publish `pg_qatestcob_carrollton-tx_water-damage-restoration` (`QA Test
Co B`'s existing draft page from UC5's setup, default block picks, never previously touched).

**Verified, with direct hvac-vs-restoration comparison:**
- Schema type genuinely differs: `HomeAndConstructionBusiness` for the restoration niche vs. `HVACBusiness`
  for hvac — confirms `pluscities_assemble_schema()` correctly resolves the niche's own
  `schema_type_family`, not a hardcoded assumption.
- The `niche_data_table` block's content is genuinely different and industry-real: *"IICRC Water Damage
  Categories"* (Category / Water Source / Contamination Level — IICRC is the actual real-world
  restoration-industry certification body) vs. hvac's *"Common AC Problems: Cause & Urgency"* — confirms
  each niche blueprint's own hand-authored table is used correctly, not a shared/generic placeholder.
- Title/meta/H1 all correctly reflect the real niche + city + business name:
  *"Water Damage Restoration in Carrollton, TX | QA Test Co B."*
- The hub route for `QA Test Co B` correctly lists its one live page (`Carrollton, TX`).

**No new bugs found** — a clean, honest pass that's itself informative: the one real, fully-authored
*other* niche blueprint behaves correctly end-to-end, same as `hvac` did across UC1-7.

**Artifacts:** `QA Test Co B` now has 1 live page.
