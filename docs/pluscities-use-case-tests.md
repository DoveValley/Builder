# PlusCities — Use Case Test Suite

Real, long walkthroughs run against the live system (`187.127.254.206`, same box this is served from) to
verify `docs/pluscities-system-reference.md`'s claims against actual behavior, not just read them. Fixture
is permanent and reusable, not cleaned up after each run — meant to be re-run as a regression check.

**Fixture:** Agency `QA Test Agency` (`qa-test-agency`), client `QA Test Co` (`qa-test-co`, niche `hvac`).
Test-WordPress install (`/opt/landing-page-clients/test-wordpress`, `localhost:8099`) is permanently
repointed to QA Test Co's API key — this is now the QA delivery test site for this suite going forward.

Status: **All 22 use cases complete and verified** (UC1-14 against the permanent `qa-test-co` fixture;
UC15-18, added 2026-11-24, against the demo-sales fixture instead — see that section for why; UC19-22,
added 2026-11-25/26, against real live clients/config directly — a build-and-verify pass for 5 new
page-furniture pieces plus a block-architecture rework (UC19), a family/niche-level mismatch sweep that
made the service pool family-customizable and fixed 4 real content-fit issues (UC20), a deliberate
5-session panel-completeness walkthrough that found+fixed 3 more real bugs live, shipped 3 new admin
surfaces (Niche Blueprint fields, Business Info tab, per-page SEO + Danger Zone), and published one
brand-new real client entirely through the panel (UC21), then extended real delete-with-safety-checks to
niches and compliance presets (UC22) — none of these a scripted fixture walkthrough, see those sections
for why). UC9-14
were added after a coverage audit
(`docs/pluscities-test-coverage-audit.md`) found UC1-8 only exercised ~43% of the system's real
capabilities — see that doc for the final, exactly-recomputed coverage tally (learned from an earlier
mistake in this same doc: state the real recount, not an estimate). Across UC9-14: 6 more real bugs found
(5 more root-owned-directory permission blockers — 2 of them caught my own earlier claims being wrong and
corrected on the record — plus a real UI gap for editing FAQ content), 2 real UI-coverage gaps documented
(no niche-authoring fields for `niche_table`/`trust_bar_items`/etc., no way to fix one FAQ answer), 1 real
account-funding issue surfaced (DataForSEO 402, not a code bug), and 1 testing-methodology lesson (grab
the real CSRF-protected link a page renders, don't assume a bare form input exists everywhere).

Across UC1-8: 1 use case (UC4) confirmed a real, honest pre-existing gap rather than a working feature;
3 real bugs were found and fixed (`pluscities_rollback_page()`'s dropped `client_grounded` column, 2
root-owned-directory permission blockers, and the Unpublish gap — fixed the same day it was found); and
the session-long missing-sidebar-CSS bug across all 8 `pluscities_*.php` admin pages was caught and fixed.

## Regression check, 2026-11-23 overnight — LPC Stage 3 retirement

Scott asked for a full re-run of all 14 use cases after LPC Stage 3 (the old-LPC codebase retirement,
`docs/lpc-stage3-retirement-scoping.md`) to catch anything it broke. Found:

1. **The QA fixture itself had been accidentally deleted earlier the same night**, during Stage 3's own
   demo-sites cleanup — `/opt/landing-page-clients/test-wordpress` (this doc's permanent fixture, line 8
   above) was mistaken for leftover LPC-plugin dev/test cruft and removed along with it. Rebuilt from
   scratch (fresh WordPress core + SQLite Database Integration + the real `pluscities-delivery` plugin,
   re-registered under QA Test Co's existing API key) and re-verified against every URL this doc's UC1
   documents (`/service-area/`, `/service-area/plano-tx/ac-repair/`, etc.) — all green again.
2. **3 real, live-breaking bugs found while rebuilding the fixture — not hypothetical, confirmed hitting a
   real visitor's session in Apache's error log in real time**: Stage 3's file-deletion step removed
   `includes/landing_page_clients.php` without checking that `includes/agencies.php` (kept, the shared
   billing/session engine) and `admin/agencies.php`/`agencies_save.php`/`impersonate.php` still called
   several of its functions (`lpc_deep_merge_defaults`, `lpc_list_clients`, `lpc_load_client`,
   `lpc_save_client`, `lpc_unique_client_id`, others) — fatal-erroring `includes/agencies.php` on every
   single load. `CLIENTS_DIR` (deleted the same pass on an incorrect "zero remaining references" check)
   needed restoring too. All fixed by moving the needed functions into `includes/agencies.php`/
   `includes/helpers.php` verbatim — see commit `9470f13a`.
3. **1 unrelated pre-existing bug, surfaced (not caused) by the fixture rebuild**: `admin/infra/lib/
   cities.php` called `infra_kw_types()` without requiring `admin/infra/lib/keywords.php` itself, breaking
   `admin/pluscities_sync.php`'s hub-render path the first time anything outside the infra console's own
   bootstrap ever exercised it live. Fixed in the same commit.
4. **UC4 and UC12's literal repro steps are no longer reproducible** (both walked through
   `admin/client_login.php`/`client_portal.php`, deleted in Stage 3) — historical notes added inline at
   each; their conclusions still hold.
5. **Everything else (UC1, 2, 3, 5, 6, 7, 8, 9, 10, 11, 13, 14) re-verified clean** — confirmed via a
   diff of every file touched across all Stage 3 commits against what each use case actually exercises
   (none of the core `pluscities_*.php` screens were touched except `cities.php`, fixed above), direct
   function/DB spot-checks (UC1's page lifecycle state, UC5's cross-agency access function unmodified
   since before Stage 3, UC14's `lpc_pause_agency_clients()` now callable without fatal-erroring), and a
   full HTTP smoke test across every admin screen with zero new Apache error log entries afterward —
   including confirming the real external user's own live session (impersonation, `pluscities_dashboard.php`)
   succeeded cleanly after the fix, not just synthetic checks.

**Real estate note, caught mid-investigation**: tonight's own write-up for this bug initially guessed
`pluscities_actor_can_access_client()`'s signature wrong (passed a second array argument the real function
doesn't take — it reads role/agency state from `$_SESSION` internally) during a quick CLI spot-check, which
produced a false "cross-agency isolation broken" result. Caught by checking the function's real signature
before reporting it — not a product bug, a reminder that CLI spot-checks of session-scoped functions need
the session state actually set, not assumed.

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

> **Historical note, 2026-11-23:** step 3 below logged into `admin/client_login.php`, which was deleted
> the same day as LPC Stage 3 retirement — not reproducible as written anymore. The conclusion still
> holds, and more strongly: there is now no `end_client` login path at all, for PlusCities or old-LPC
> clients, where before only the PlusCities side was missing it. See
> `docs/pluscities-system-reference.md` §5.5.

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
- **Fixed and re-verified live, 2026-11-24**: `pluscities_clients.php`, `pluscities_pages.php`, and
  `pluscities_photos.php` now filter through `pluscities_actor_can_access_client()` per client. Re-ran
  this exact scenario against the same `qa-editor` fixture (password reset via the real Team screen to
  get a fresh direct login, not impersonation): `QA Test Co B` no longer appears on `pluscities_clients.php`
  or `pluscities_pages.php`; `pluscities_photos.php` now auto-selects `qa-test-co` directly (only one
  client visible, where it previously would have shown a chooser between two) and renders its real
  branding; a new negative test — `pluscities_photos.php?client_id=qa-test-co-b` typed directly into the
  URL — correctly falls back to "Choose a client" instead of exposing `QA Test Co B`'s data, the same
  "not found" handling a genuinely invalid id gets.

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
real Agency Editor account (`qa-editor`), `qa-test-agency.editor_can_publish` now `true`. `qa-editor`'s
password was reset 2026-11-24 (via the real Team screen, to `QaTemp2026!`) to get a fresh direct login for
the read-scope re-verification above — record this here so a future run doesn't need to reset it blind.

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

### Unpublish — real gap found, then fixed (2026-11-23)

**Steps:** unpublished page 1, confirmed `status='unpublished'` and `site_rev` bumped in the DB, then
checked the real delivered route.

**Found**: the real delivered page (`localhost:8099/service-area/plano-tx/ac-repair/`) **kept returning
200 with the full old content**, even after forcing a fresh sync (`site_rev_seen` confirmed caught up to
the new `site_rev`). Root cause: the manifest action only ever returns `status='live'` pages — an
unpublished page simply stops appearing in future manifest responses, but **nothing ever tells the plugin
to delete its already-cached copy**. The cached transient has no expiry by design (that's what makes the
offline-fallback work for still-live pages) — but for an unpublished page specifically, this meant the
real site kept serving it to real visitors indefinitely.

**Fix shipped the same day**: `admin/pluscities_sync.php`'s `action=manifest` now also returns
`live_page_ids` — the full current live set, built from the same query that already populates
`changed_pages`, zero extra DB cost. The plugin's `pluscities_delivery_sync_if_needed()` now reconciles
against that ground truth: enumerates its own cached `pluscities_page_url_*` options, and deletes any
whose page_id isn't in the fresh live set (plus the matching transient). Also fixed the function's gate —
it was keyed on `changed_pages` being non-empty, which meant unpublishing a client's *last* remaining live
page (where `changed_pages` legitimately comes back empty even though `site_rev` advanced) would skip
`site_rev_seen` updates AND the reconciliation entirely, leaking that page forever even under a naive
fix. Re-gated on `site_rev` advancing instead.

**Verified live, both scenarios**: unpublished page 1 (of 2 live pages) — confirmed its specific cached
option+transient were deleted, its route now 404s, and **page 2 was completely untouched** (still cached,
still 200). Then unpublished page 2 as well (client now has zero live pages) — confirmed `site_rev_seen`
still advanced (9→10) despite `changed_pages` being empty, and page 2's cache was correctly cleared too.
Re-published both afterward, confirmed both routes work again from a clean fetch — reconciliation doesn't
poison a page against coming back later.

**Deployment note**: this plugin is already installed on real client sites; the fix only reaches a site
once it re-downloads the plugin zip (`admin/pluscities_plugin_download.php` always zips current source) —
it does not retroactively patch already-running installs. No auto-update mechanism exists for this plugin
today; out of scope to build here.

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

---

## UC9 — Validator thoroughness — ✅ PASS (6 of 7 triggered checks confirmed; 1 honest non-failure; 1 real UI gap found)

**Goal:** close every validator-related row realistically triggerable through the real admin — 10 of 14
rows had never been deliberately triggered before this use case.

**Steps + results, each isolated and reverted before moving to the next (same rigor as UC6):**

1. **`service_radius` + `uniqueness`, together** — added a new page (`pg_qatestco_allen-tx_ac-repair`)
   via the real Add Page form: `allen-tx`, 50 min drive (> the client's 30 min `radius_minutes`), not
   confirmed, **same service and identical block picks as page 1** to maximize text similarity.
   - `service_radius` → **✅ failed exactly as expected**: `"This city is 50 min away, outside the 30
     min radius, and not confirmed by the client."`
   - `uniqueness` → **passed, genuinely** — identical topic picks did not produce ≥50% shingle overlap.
     A real, honest finding: the AI writer varies language enough per city (city name, specific phrasing)
     that matching topic selection alone doesn't create duplicate content. Not a test failure — evidence
     the content-diversity design goal actually holds.
   - This page is left in `draft` (service_radius failing) as a realistic "needs attention" fixture page.
2. **`client_facts`** — edited a block's body via the real Edit UI to include a phone number not matching
   the client's real one → **✅ failed**: `"The 'climate_data' block shows a phone number that doesn't
   match the client's real number."` Reverted.
3. **`invented_prices` (hard exception) + override refusal** — edited a block to state a `$350` price not
   in the client's `price_ranges` → **✅ failed**: `"...states a price ($350) the client never
   supplied."` Confirmed the UI renders **no Override control at all** for this check, then sent a raw
   POST attempting to override it anyway — **refused server-side**: `"This check can never be overridden
   — the content needs to actually be fixed."` First time this refusal path has ever been proven (UC6
   only proved the *grant* path, on a soft check). Reverted.
4. **`wrong_city`** — found no block on any QA page currently cites any fact card at all (confirmed: zero
   `fact_ids` populated anywhere in the fixture). No admin UI assigns a fact_id to a block manually (only
   the AI writer does, based on what it actually cites) — set the precondition directly (cited a real
   fact card scoped to `frisco-tx` on a `mckinney-tx` page block), same "no UI exists, document it
   honestly" pattern UC6 used for `banned_phrases` → **✅ failed**: `"Fact fc_frisco-tx_permits_...
   belongs to 'frisco-tx', not this page's city."` Reverted.
5. **`stale_facts`** — same precondition pattern: cited a real, *correctly*-scoped fact card
   (`fc_mckinney-tx_hazards_...`), then flipped its `status` to `stale` directly (no admin UI sets this
   either — `pluscities_fact_card_deactivate()` has zero real call sites, confirmed in the reference doc)
   → **✅ failed**: `"...is now 'stale' — needs re-research before this page should rely on it."`
   Reverted both the citation and the status flip.
6. **`faq`** — **real gap found before even triggering the check**: the generic Edit form's Save button
   is `disabled` for FAQ blocks (confirmed in the UI), because FAQ content is shaped `{"faqs":[...]}`, not
   `{"body":...}` like every other editable block — **there is no real admin path to fix a single bad FAQ
   answer at all**, only full regeneration (which calls the AI writer fresh, not a small correction).
   Blanked one answer via a direct content write to prove the check itself works → **✅ failed**:
   `"One of the FAQ entries is missing a question or an answer."` Reverted.

**Deliberately not attempted** (confirmed structurally blocked via the real admin, not worth forcing):
`quotes` (confirmed dead code — the writer never injects raw `quote` text) and `cannibalization`
(`page_id` is deterministic from client+city+service, so the normal create path can't produce a
duplicate).

**Artifacts:** `qa-test-co` gains a 3rd page (`allen-tx`/`ac-repair`, intentionally left in draft —
failing `service_radius`). Pages 1-2 fully reverted and republished clean.

## UC10 — Photos, branding, the real plugin artifact, hub content, and self-served delivery extras —
✅ PASS (found + fixed 1 more root-owned-dir bug, same silent-failure shape as UC5)

**Goal:** 5 real screens/paths with zero use-case coverage, naturally grouped — all client-facing
delivery/branding surfaces an agency actually touches.

**Steps + results:**

1. **Photos — logo upload, accent/dark colors, a photo-slot upload.** First attempt: the branding save
   reported `"Saved"`, but the logo never actually persisted (`logo_url` empty on disk) — same silent-
   failure shape as UC5's team-save finding, root-caused to `pluscities_uploads/` being **root-owned**,
   blocking www-data (same recurring bug class as UC1/UC5). **Fixed** via targeted `chown` (consistent
   with the standing policy — fix the specific blocking path, not a blanket sweep). Retried: logo, accent
   `#8b1a1a`, dark `#0b1b33`, and a hero photo-slot upload **all genuinely persisted** — confirmed real
   files on disk and `client['branding']`/`client['images']` correctly updated.
   **Correction, found during UC11**: the "refresh every slot's branded image right away" step had
   *also* silently failed — `client['images']['hero']['branded_cache_path']` was set in the JSON, but
   the actual file never existed on disk, because `pluscities_uploads/branded/` is a *separate*
   subdirectory that was **also** root-owned (chowning the parent `pluscities_uploads/` doesn't cascade
   to a pre-existing root-owned child). The original write-up above only checked the JSON field, not the
   real file — a mistake, corrected here rather than left standing. Fixed via the same targeted `chown`;
   regenerated the branded image directly and confirmed the file now genuinely exists on disk.
2. **Color detection** against the client's (fake, non-live) domain — failed gracefully with
   `"Could not detect colors from your site - try setting them manually"`, not a crash. A correct
   negative-path result, not a bug.
3. **The real branded plugin artifact.** Set `QA Test Agency`'s own `display_name`
   ("Lone Star Digital Marketing") and `logo_url` via the real Agencies edit form, downloaded the real
   zip via `pluscities_plugin_download.php`, extracted it, and confirmed: the plugin header reads
   `Plugin Name: Lone Star Digital Marketing Delivery` / a matching Description, the folder is literally
   named `lone-star-digital-marketing-delivery/`, and **zero occurrences of the literal string
   "PlusCities" remain anywhere in the delivered file** — a clean, thorough pass.
4. **Hub content** (`pluscities_hub_edit.php`) — saved new top/footer text with the `{{business_name}}`
   token, triggered a real sync, confirmed the live delivered hub page
   (`localhost:8099/service-area/`) shows the token correctly resolved to `"QA Test Co"`.
5. **Self-served delivery extras** — hit `/pluscities-sitemap.xml` directly: 200, correctly lists exactly
   the 2 real live pages (correctly excludes UC9's draft `allen-tx` page). Hit `/robots.txt`: correctly
   includes `Sitemap: .../pluscities-sitemap.xml` alongside WordPress's own default sitemap line —
   confirms the "additive, not exclusive" design claim from the reference doc, observed live.

**Artifacts:** `qa-test-co` now has a real logo, brand colors, and a hero photo upload; `qa-test-agency`
now has real branding (`display_name`/`logo_url`) that flows correctly into its downloaded plugin.

## UC11 — Niche Library authoring — ✅ PASS (found + fixed 3 more root-owned dirs; real readiness checklist applied)

**Goal:** 4 of 6 niche families are known placeholder stubs — prove whether the real authoring UI works,
independent of what content currently exists, judged against a real readiness checklist (Scott's call,
mid-use-case): **family-level** — real `label`, correct `schema_type_family`, distinct
`default_city_blocks`/`research_topics` (not copy-pasted), a correctly-matched `compliance_preset`.
**Niche-level** — real `schema_subtype`, `services[]` with actual keyword data, a real `niche_table`,
sensible `trust_bar_items`/`blog_topics`.

**Found before any content work**: `compliance_presets/`, `niche_families/`, and `niche_blueprints/` were
all **root-owned** — the very first save (a new compliance preset) reported `"Preset saved"` while
nothing was actually written to disk (confirmed by the file never appearing). Same recurring bug class
as UC1/UC5/UC10 — the 6th real instance this session. **Fixed** via targeted `chown` on all three
directories.

**Steps + results, against the checklist:**

1. Created the real **`medical`** compliance preset the spec names but that never existed on disk
   (`label`, 4 real banned phrases, `show_license` toggle) — ✅.
2. Fleshed out the **`medical-wellness`** family: real label ("Medical & Wellness"), correct
   `schema_type_family` ("MedicalBusiness", not inherited "LocalBusiness"), `compliance_preset` correctly
   switched to the new `medical` preset (not defaulting to `contractor`), and a **genuinely distinct**
   `default_city_blocks`/`research_topics` (dropped `permits`/`hoa_rules`/`seasonal_guide`/`hazards`/
   `climate_data` — not relevant to a medical practice — kept `neighborhoods`/`housing_stock`/`utilities`/
   `reviews_from_city`) — ✅ all 4 family-level checklist items.
3. Authored a real new niche, **`dental-practice`**, under that family: real `schema_subtype` ("Dentist",
   a genuine schema.org type), `service_noun`, `local_angle`, `seasonal_calendar`, and 2 real services
   with primary keywords — ✅ schema_subtype + services exist.
4. Ran the real `validate_keyword` action for one service — **genuine, single paid DataForSEO call, not
   repeated**: the real account returned `402 Payment Required` (out of balance/over a budget cap, not a
   code bug — flagged to Scott directly, worth checking outside this test). Confirmed the handler
   correctly surfaced the real API error to the admin and left `primary_keyword_data` as `null` rather
   than writing garbage on a failed call — the plumbing is proven correct even though the full keyword
   data couldn't be fetched in this environment.
5. **Confirmed a real, concrete limit of the authoring UI**: `pluscities_niches.php`'s save_niche form has
   **no fields at all** for `niche_table`, `trust_bar_items`, `blog_topics`, `custom_research_fields`,
   `approved_sources_extra`, or `image_slots` — every one of these stayed `null`/`[]` on the new niche
   after using every field the real form offers. A niche authored entirely through the real admin today
   cannot reach full "launch ready" status (especially `niche_table`, which the `niche_data_table` block
   type needs to render anything meaningful) — would still need a direct JSON edit, the same "no UI for
   this" shape as `banned_phrases` (UC6) and `radius_minutes` (UC9).

**Readiness checklist result**: family-level fully closeable via the real UI (4/4). Niche-level partially
closeable (`schema_subtype`/`services` yes; `niche_table`/`trust_bar_items`/`blog_topics` no UI path at
all) — an honest, concrete finding about where the authoring tool's real coverage ends.

**Artifacts:** `compliance_presets/medical.json`, a genuinely fleshed-out `niche_families/medical-
wellness.json`, and a new `niche_blueprints/dental-practice.json` (left as real, permanent fixture
content — not cleaned up, consistent with the rest of the QA fixture).

## UC12 — Impersonation — ✅ PASS (confirms both a real structural gap and a real testing lesson)

> **Historical note, 2026-11-23:** step 4 below impersonated `meade-restoration` as an old-LPC
> `type=client` and landed on `client_portal.php`. Both the client record it impersonated
> (`clients/meade-restoration/`) and `client_portal.php` itself were deleted the same day as LPC Stage 3
> retirement — this exact repro is no longer possible, and the `type=client` impersonation path in
> `admin/impersonate.php` now has no client record left anywhere it could ever successfully load. The
> structural-gap finding (no way to impersonate a PlusCities client as `end_client`) still holds, and the
> CSRF-token testing lesson in step 5 remains a generally applicable lesson regardless. Steps 1-3
> (agency impersonation) are unaffected and still fully reproducible.

**Goal:** `admin/impersonate.php`'s `start`/`stop` actions are real, built, and tied directly to the role
model UC5 already explored — never exercised live.

**Found while reading the code, before any live test**: `impersonate.php`'s `type==='client'` path calls
`lpc_load_client()` — the **old** LPC loader (`clients/*/data/client.json`) — not anything PlusCities-
aware. This is the same structural gap UC4 found from a different angle (no PlusCities-native end-client
login path exists at all): you literally cannot impersonate a PlusCities client (`qa-test-co`, etc.) as
`end_client`, only an old-LPC one.

**Steps + results:**

1. As `super_admin`, impersonated `qa-test-agency` (`type=agency`) — confirmed the session correctly
   scoped to exactly that agency's 2 clients (`QA Test Co`, `QA Test Co B`), excluded the other QA agency
   and all 3 real production clients, and the real "Viewing as agency QA Test Agency" banner rendered
   with the correct name.
2. Attempted to impersonate a 2nd time while already impersonating — correctly refused:
   `"Already impersonating - return to your own account first"` (no-stacking rule holds).
3. Stopped via the real banner link, confirmed full `super_admin` access restored.
4. Impersonated a **real old-LPC client** (`meade-restoration`, `type=client`) to prove the mechanism
   genuinely works for what it's actually scoped to (since a PlusCities client can't be impersonated at
   all, per the finding above) — landed correctly on `client_portal.php`, confirmed the restriction (an
   end_client session attempting `pluscities_clients.php` was correctly redirected away).
5. **Real testing-methodology lesson, caught and corrected before trusting a false result**: my first
   attempt to stop this impersonation grabbed a CSRF token from `document.querySelector('input[name=
   "csrf_token"]')` — but `client_portal.php` has no such bare form input anywhere on the page, so the
   stop request silently failed CSRF validation and the session stayed impersonating. This produced an
   alarming false signal (the next check showed the session had "lost" super_admin access) that looked
   exactly like a broken restoration bug. Traced it, found the real cause, and retried using the actual
   token-embedded "Return to your own account" link the banner renders — confirmed **full, clean
   restoration**, no leaked `agency_id`/`client_id` state. Not a product bug; a reminder that a page
   without a generic admin form can still carry its own real CSRF-protected action link, and tests should
   use that real link rather than assume a bare input field exists everywhere.

**Artifacts:** none — read-only investigation + impersonation sessions, immediately stopped, no lasting
state change to any client.

## UC13 — Client-supplied reviews + a brand-new city's first dataset fetch — ✅ PASS (both gaps closed
with a single new page)

**Goal:** `reviews_from_city`/`client-supplied` classification had never been exercised by any use case.
Separately, no use case had ever provisioned a page for a city with no existing `datasets` row, so the
"first fetch" path had never been directly observed.

**Efficient pairing found**: `plano-tx` (page 1's own city) had never triggered a `housing_stock` fetch —
none of its existing picks included that block type — so a single new page for `plano-tx` could close
both gaps at once, no need for an entirely new city.

**Steps + results:**

1. Added real `reviews_by_city.plano-tx` entries to `qa-test-co` (2 real reviews — no admin UI exists for
   this field, same "direct write, documented honestly" pattern as UC6/UC9).
2. Added a new page via the real Add Page form — `plano-tx` / `furnace-repair` (a legitimate new page_id,
   different service than page 1, no collision), picking `housing_stock` and `reviews_from_city`.
3. **Confirmed**: a genuinely new `(plano-tx, census_acs)` row appeared in the `datasets` table for the
   first time, with real Census values (`income_median: 108649`, etc.) — not a pre-existing cache hit.
4. **Confirmed**: the `reviews_from_city` block's content was copied **verbatim** from the client record
   (`origin='client'`, `fact_ids=[]`) — never touched the AI writer at all.
5. Validated, approved, published — `invented_reviews` specifically confirmed `pass` (the real text
   matches the client's own data exactly), **zero failures total**.

**Artifacts:** `qa-test-co` gains a 3rd live page (`plano-tx`/`furnace-repair`), real `reviews_by_city`
data for `plano-tx`, and the system's first-ever genuine `census_acs` fetch for that city.

## UC14 — Agency billing fields + suspend/approve — ✅ PASS (final use case — the full planned suite is
now complete)

**Goal:** `agencies.php`'s billing fields (`spend_cap_usd`, `monthly_fee_usd`) and the suspend/approve
`toggle_status` action have real, no-external-dependency code paths that UC1/UC5 never touched (those
only exercised agency *creation*).

**Steps + results:**

1. Set real `spend_cap_usd=250.00` / `monthly_fee_usd=99.00` on `QA Test Agency 2` via the real Agencies
   edit form — confirmed saved correctly in the agency JSON.
2. Suspended it via the real `toggle_status` action (another `confirm()`-dialog button, same shape as
   UC7's rollback control — needed a dialog-accept handler, not a product issue) — confirmed
   `status='suspended'`.
3. Attempted a real login as that agency's admin — correctly blocked with the exact specific message:
   `"This agency account is currently suspended."` — not a generic bad-credentials error. Proves the
   "credentials right, access wrong" shape `admin/agency_login.php`'s own code comment describes, which
   had never actually been triggered live before this.
4. Reactivated via the same action, confirmed login now succeeds and correctly lands on the agency
   dashboard (built earlier this session) — both pieces of recent work confirmed working together.

**Artifacts:** `QA Test Agency 2` now has real billing fields set; its suspend/reactivate cycle proven
live.

---

**All 14 use cases (the original 5, plus UC6-14) are now complete.** See the coverage audit
(`docs/pluscities-test-coverage-audit.md`) for the final tally against the full capability inventory.

## UC15-17, 2026-11-24 — production-readiness build, run against the demo fixture

Added after a full day of production-launch work (domain/SSL, a real approval workflow, real client
cancellation, white-label branding) — see the Product Roadmap tab's "Go-live operations" section and the
rows it corrected for the full day's scope. These three run against the **demo fixture**
(`northstar-digital-marketing-demo` agency, clients `lone-star-comfort-heating-air-2` and
`clearwater-restoration-pros`), not `qa-test-co` — a deliberate deviation from this suite's usual
convention, since these tests needed to manipulate timestamps (backdating a cancellation) and toggle
settings in ways that would have disrupted the permanent QA fixture's steady state. Worth re-running
against `qa-test-co` in a future pass for full consistency with the rest of this suite.

### UC15 — Real agency + client approval workflow — ✅ PASS

**Goal:** prove the two new approval gates (agency-level: Editor can't self-approve; client-level: a page
needs the actual client's sign-off before publish) are both independently enforced, and that the client's
own portal actually lets them review and approve real content.

**Steps taken (all via the real admin, no CLI shortcuts):**
1. Turned on `requires_client_approval` for `lone-star-comfort-heating-air-2` via the real toggle button
   on `pluscities_clients.php`.
2. Added a new page (`san-marcos-tx`/`ac-repair`), deliberately 35 minutes from the hub (outside the
   default 30-minute radius) to also exercise a real validator failure + override along the way.
3. Validate → got a real `service_radius` failure → used the real Override form with a typed reason →
   re-validated clean → Approve.
4. **Confirmed**: `pages.approval_stage` became `pending_client_review`, not `approved` — the new gate
   routing correctly.
5. Attempted Publish — **correctly rejected**: `"This page hasn't been approved yet."`
6. Confirmed the agency-side page screen's Approval label reads "waiting on client," not the raw
   `approval_stage` string.
7. Impersonated the client (`admin/impersonate.php`, `type=client`) — confirmed `client_portal.php` shows
   a "Needs Your Approval" pill on this exact page, sorted to the top of the list, and that the sidebar
   "who am I" now shows the real business name (a separate bug found and fixed along the way — see UC17).
8. Opened the page in `client_portal_page.php` — confirmed the real page content renders (hero, overview,
   etc.) plus an "Approve" banner with the real content visible above it, not a blind approve.
9. Submitted Approve as the client. **Confirmed**: `approval_stage` became `approved`.
10. **Negative test 1**: re-submitted Approve on the same page — correctly rejected, `"This page isn't
    waiting on your approval."`
11. **Negative test 2**: while still impersonating this client, attempted to approve a *different* demo
    client's page (`coastal-breeze-hvac-services`'s Tampa page) by editing the `page_id` in the request —
    correctly redirected away before the approve logic ever ran; the other client's page was confirmed
    unaffected.
12. Stopped impersonation, published the page as super_admin — **succeeded** this time.
13. Separately confirmed the agency-level gate's logic by direct code read (not a live Editor login this
    session): `pluscities_approve_page()` checks `agency.requires_approval_gate` and refuses an
    `agency_editor` actor exactly the same way `pluscities_publish_page()` already does for
    `editor_can_publish` — same proven shape, not a new untested pattern.

**Artifacts:** `lone-star-comfort-heating-air-2` gains a real 3rd live page
(`san-marcos-tx`/`ac-repair`), a real validator override on record, and `requires_client_approval=true`
left on intentionally as a standing demo asset (shows well to a prospect).

### UC16 — Real client cancellation — ✅ PASS

**Goal:** prove a cancelled client's pages actually stop being servable after a configurable grace period
— the real gap this replaced: before today, no cancellation mechanism of any kind existed, and the WP
plugin's cache was hardcoded to never expire, so even a hypothetical key-revoke wouldn't have taken a
client's pages down.

**Steps taken:**
1. Set platform settings to easy-to-test values: grace period 3 days, plugin cache lifetime 2 hours (via
   the real form on `admin/settings.php`).
2. Cancelled `clearwater-restoration-pros` via the real Cancel button (confirm dialog accepted).
   **Confirmed**: `client.status='canceled'`, `canceled_at` set to the real current timestamp.
3. Hit `pluscities_sync.php?action=manifest` with the client's real API key, immediately after
   cancelling (within the 3-day grace period). **Confirmed**: behaves completely normally —
   `cancelled:false`, both real pages still listed, `cache_ttl_seconds:7200` present.
4. Backdated `canceled_at` to 4 days ago (past the 3-day grace) directly in the client JSON, to simulate
   the grace period elapsing without waiting 3 real days.
5. Re-hit the manifest endpoint. **Confirmed**: `cancelled:true`, `redirect_to:
   "https://clearwaterrestorationpros.com/"`, `live_page_ids:[]` (empty — deliberately reuses the
   plugin's existing cache-reconciliation logic to wipe every cached page, no separate mechanism needed).
6. Hit `pluscities_sync.php?action=page` directly for one of this client's real pages. **Confirmed**: a
   plain 404, not the page content.
7. Confirmed the admin UI (`pluscities_clients.php`) shows "Cancelled — grace period ended, redirecting
   now" on this client's row.
8. Reactivated via the real button. **Confirmed**: manifest immediately returns to normal, both pages
   listed again, `cancelled:false`.
9. Restored platform settings to sensible real-world defaults (14 days / 24 hours) after testing.

**Not independently verified this session**: the WordPress plugin's own side of the redirect (reading
`cancelled`/`redirect_to` off the manifest and actually issuing the 301) — no live WP install was
exercised for this specific use case; the plugin code was read and reasoned through but not run. Real
limitation on record either way: this only protects clients running the *updated* plugin — the 3 real
clients (`elite-air-hvac`, `lonestar-restoration`, `meade-restoration`) are on the pre-2026-11-24 plugin
version and won't get this protection until reinstalled.

**Artifacts:** `clearwater-restoration-pros` restored to `status='active'`, both pages confirmed live
again. Platform settings file (`pluscities_platform_settings.json`) left at real-world defaults.

### UC17 — Agency white-label branding in the shared sidebar — ✅ PASS

**Goal:** prove an agency's own saved branding (display name, logo) actually appears in their own admin
experience, instead of the hardcoded "PlusCities" text found during the day's roadmap audit.

**Steps taken:**
1. Impersonated `northstar-digital-marketing-demo` as its own Agency Admin.
2. **Confirmed**: the sidebar brand mark reads "Northstar Digital Marketing" (this agency's real
   `branding.display_name`), with the stock icon shown since this demo agency has no logo_url set.
3. Stopped impersonation. **Confirmed**: the sidebar correctly reverts to "PlusCities" for the Platform
   Admin's own session — the fix is role-conditional, not a global replacement.
4. Found and fixed 2 adjacent bugs while verifying this: `lpc_actor_branding()`'s `end_client` branch
   called the dead old-LPC `lpc_load_client()` (same bug class as UC1's regression-check section found in
   `admin/impersonate.php`) — fixed to `pluscities_client_load()`; and `agency_editor` was missing from
   the branding check entirely (would have fallen through to "PlusCities" even though an Agency Admin at
   the same agency saw it correctly) — added.

**Artifacts:** none beyond the code fix itself — no data changed, this is a pure display-logic
correction.

## UC18, 2026-11-24 — admin safety & ops features, run against the demo fixture

Covers the remaining items from the same day's "Quick effort" roadmap walkthrough (see the Product Roadmap
tab), run against the demo fixture (`northstar-digital-marketing-demo` agency, client
`clearwater-restoration-pros`) for the same reason UC15-17 did — these needed to manipulate live/suspended
state in ways the permanent `qa-test-co` fixture shouldn't absorb. Grouped into one use case per this
suite's own convention (UC15 covered 13 steps under one roof) rather than one UC per small admin button —
a filter dropdown or a read-only compare view doesn't need its own entry.

**Goal:** prove the day's remaining admin-safety/ops features actually work end-to-end, not just that they
render — especially the one with real security weight (the bulk-approve role-gate fix) and the one with
real blast-radius (the Suspend Site kill switch).

**Steps taken:**
1. **Lock All / Unlock All**: on a real 13-block demo page, Lock All correctly locked all 13 rows in the
   `blocks` table; Unlock All correctly unlocked all 13; a bad `page_id` was correctly rejected by the
   same access check every other page action already uses.
2. **Revision compare/diff view**: with no changes since revision #1, all 13 blocks showed "unchanged" on
   the new compare screen; after editing one block's body text, 12 stayed unchanged and the edited block
   correctly showed "Changed" with the real old and new content side by side; the test edit was rolled
   back and re-approved afterward.
3. **Bulk Unpublish + city/service filters**: filtering the pages table by city correctly narrowed 23 rows
   to 1; bulk-unpublished a real demo page via the new "Unpublish selected" button and confirmed its
   status flipped; republished it afterward.
4. **Bulk-approve role-gate fix — the one with real security weight**: directly exercised the fixed
   `pluscities_approve_page()` call as `agency_editor` against a real draft page under the gated test
   agency (`coastal-marketing`, `requires_approval_gate` on) — correctly blocked (`"This agency requires
   an Agency Admin to approve"`); same call as `super_admin` — correctly approved. This is the exact gate
   bulk Approve previously bypassed silently; now routes through the same check as the single-page button.
5. **Suspend Site kill switch — both roles, not just one**: suspended `clearwater-restoration-pros` as
   `super_admin` — both its live pages unpublished immediately, `site_suspended=true` set. A direct
   publish attempt was then correctly blocked for **both** `super_admin` (own function call) and
   `agency_admin` (via real impersonation of the owning agency, through the actual HTTP save endpoint) —
   confirming the suspension isn't bypassable by the one role that could lift it, nor by the agency that
   owns the client. Un-suspending left both pages unpublished (no auto-republish, by design); both were
   then manually republished to restore the clean demo state.
6. **Plugin health reporting**: a simulated manifest check-in with `plugin_version`/`theme`/`seo_plugin`
   params wrote all three fields plus a real `last_seen_at` onto the client record; an identical repeat
   call within the same hour correctly skipped the write (timestamp unchanged — confirms the throttle);
   a changed plugin version correctly forced an immediate write regardless of the throttle. The Clients
   screen showed the real health line for this client and "Plugin never synced yet" for every other
   untouched demo client.
7. **Clean uninstall**: already verified for real against a loaded WordPress environment as part of
   building it, not re-run here — seeded the plugin's known option/transient shapes (16 rows), ran the new
   `uninstall.php` through `wp-load.php`, confirmed all 16 gone, which also cleaned up 3 genuinely orphaned
   transients already sitting in that install from earlier unrelated testing.
8. **Agency Editor read-scope fix — regression-checked against UC5's original fixture**, not the demo
   fixture: see the addendum added to UC5 above (`qa-editor`'s password reset to get a fresh direct login,
   `QA Test Co B` confirmed gone from all three screens, plus a new URL-manipulation negative test).

**Artifacts:** `clearwater-restoration-pros` restored to 2 live pages, `site_suspended=false`. Gated test
agency `coastal-marketing` unchanged (read-only exercise of its existing gate). No other fixture state
left behind.

## UC19, 2026-11-25/26 — page visual/furniture upgrades + block architecture rework — ✅ PASS (5 new
furniture pieces shipped+backfilled, a real structural gap resolved, 4 real bugs found and fixed)

Not a single scripted walkthrough like UC1-18 — a build-and-verify pass, run against real live clients
(not the `qa-test-co`/demo fixtures), prompted by comparing a real PlusCities page against the fleet's own
proven reference sites (baileyrestoration.com, wrenappliancerepair.com, gannmoldremediation.com) and
finding PlusCities pages were missing whole content types those sites share, not just styling polish.
Recorded here because every piece was verified with the same discipline as the scripted UCs: forge an
admin session file directly on disk → hit the REAL production `admin/pluscities_sync.php` endpoint with
real Bearer auth → confirm the generated HTML/image is actually there → screenshot the real Page Styling
admin screen via headless Chromium/CDP → clean up every forged session + chrome profile + temp screenshot
afterward. Full architecture detail lives in `docs/pluscities-system-reference.md` §2.3a; this entry is
the verification record.

**What shipped, in build order:**
1. **Safety Toolkit** — niche-level furniture, deterministic 3-of-5 org-card pick per client domain
   (crc32 seed), ported from `plugins/safety_toolkit/`, zero AI writing.
2. **Area Map** — real OSM basemap + administrative boundary + a real "nearby towns served" list computed
   via haversine distance against the `cities` table (never researched/guessed).
3. **Housing Snapshot chart** — an owner-vs-renter occupancy bar + 3 stat tiles, drawn from the exact same
   Census ACS dataset the `housing_stock` block already renders as text, no new network dependency.
4. **Items-shaped block styling (Tier 2)** — `process`/`common_problems`/`seasonal_guide` moved off the
   generic heading+paragraph fallback onto dedicated grid/timeline treatments.
5. **City Info** — a real, CC-licensed Wikipedia lead photo + an AI-written narrative adapted from the old
   system's proven `city_spotlight` prompt, grounded only in population + PlusCities' own `neighborhoods`
   fact-card topic.
6. **Always-blocks mechanism** — a new per-family `always_city_blocks[]` field (extends the niche
   library's existing family-inheritance model, doesn't bypass it) plus a global
   `PLUSCITIES_ALWAYS_SERVICE_BLOCKS` constant, closing the real pool-selection-bias gap found earlier
   this session (`housing_stock`/`neighborhoods`/`climate_data` and `common_problems`/`process` were being
   starved by picker position, not low real value).
7. **Climate Chart(s)** — started as one NOAA chart (`rainfall_vs_average`, water-damage-restoration
   only), pluralized to `climate_chart_ids[]` and extended to 6 chart types across both real
   climate-data-carrying niches (water-damage-restoration, hvac): `rainfall_vs_average`/`hot_days`,
   `avg_temp_monthly`, `freeze_days`, `wet_days`, `heavy_rain_days`, `flood_decades`. Reuses the old
   system's `plugins/image-data-chart/` engine directly (already blessed for reuse per
   `docs/lpc-vs-site-factory-boundary.md` §3) rather than rebuilding a NOAA pipeline from scratch.

**Real bugs found and fixed along the way:**
- SQLite TEXT-affinity columns (`lat`/`lng`) silently did STRING comparison against float literals,
  returning zero nearby-towns results until `CAST(... AS REAL)` was added (Area Map).
- Shipping Area Map's generation logic only inside `pluscities_create_page()` left existing pages
  untouched — Scott caught it live ("I don't see it live or on the style page"). Fixed by backfilling all
  15 existing cities + bumping `site_rev`; applied proactively on every subsequent piece without being
  asked again.
- Every AI writer prompt fed the raw `city_id` slug ("bedford-tx") instead of the real label ("Bedford,
  TX") into every generation call — confirmed live, fixed at the one shared context-building call site.
  Not yet backfilled onto already-generated content fleet-wide (a separate, bigger decision left to
  Scott).
- A read-through dataset cache (`pluscities_dataset_get()`) had no way to detect its underlying source
  gained a new field — adding `avg_temp_monthly` to an already-fetched NOAA niche folder left existing
  cities stale until a `$force` parameter was added. Confirmed live this can even change which station
  qualifies for a city (Bedford, TX moved stations once the new data type was required at the same
  station).

**Architecture decision, made via Plan Mode after an explicit correction:** the first draft of the
always-blocks idea considered a blanket global promotion. Scott's correction mid-review — "make sure
we're including the niche library and how it integrates into the niche that is selected... and how that
block library works" — caused a redesign to a per-FAMILY field instead, since `medical-wellness`
(dental-practice) has a genuinely shorter pool with no `climate_data` at all; a global promotion would
have force-added a block the niche library already deliberately excludes for that family. Verified
directly: `pluscities_niche_resolved()` correctly produces `always_city_blocks = [housing_stock,
neighborhoods]` + a 2-item pool for `medical-wellness`, vs. `[housing_stock, neighborhoods, climate_data]`
+ a 6-item pool for the other 5 families; a real test page created with zero block picks got all 5
promoted blocks automatically with real generated content.

**Artifacts:** all 15 live (city, niche) pairs across every real client backfilled for Area Map/Housing
Snapshot/City Info; all (city, niche) pairs with climate data backfilled for all 6 chart types;
`site_rev` bumped on every affected client in the same passes. No fixture state left behind — this pass
touched real live clients directly, each change verified then left in its correct final state.

## UC20, 2026-11-26 — block-pool/niche-content mismatch sweep across all 6 families + 3 real niches —
✅ PASS (made the service pool family-customizable, found+fixed 3 real family-level mismatches and 1 real
niche-level content gap, backfilled everywhere it mattered)

Not a scripted fixture walkthrough either — a direct code/config review and fix pass, prompted by Scott
asking "what is the service-pool-not-family-customizable gap" after it turned up flagged-but-unfixed in
UC19/the architecture reference doc, then "make the service pool family-customizable," then two rounds of
"check \[X\] for similar mismatches." Recorded here for the same reason UC19 was: every change was
verified directly against real code/data, not just read and assumed correct.

**Step 1 — extended the niche library's existing family-inheritance model to the service pool**
(`includes/pluscities_niche_library.php`): added `family.default_service_blocks[]`/
`family.always_service_blocks[]` + `niche.service_blocks_off[]`, resolved by
`pluscities_niche_resolved()` into `service_blocks`/`always_service_blocks` exactly like `city_blocks`
already worked. Removed the 2 global constants (`PLUSCITIES_SERVICE_POOL`/
`PLUSCITIES_ALWAYS_SERVICE_BLOCKS`) that previously forced every business type to share one identical
pool, migrating all 6 real call sites (3 in `includes/pluscities_lifecycle.php`, 4 admin pickers).
Verified via direct function calls: `pluscities_niche_resolved()` produced byte-identical pool contents
for `hvac`/`water-damage-restoration`/`dental-practice` before and after the refactor — confirmed the
mechanism change itself altered zero behavior. Found and fixed a real latent bug along the way: the
Niche Library's `save_family` admin action had no fallback preserving `always_city_blocks`/
`always_service_blocks` (both fields have no form input), so every save through that screen would have
silently wiped both to `[]` — same bug class as the documented `image_slots` data-loss bug (2026-11-23).

**Step 2 — used the new mechanism for real**, once asked "do we need to check dental-practice's city
pool for similar mismatches... is it important" then "do both": reviewed all 4 of `medical-wellness`'s
city blocks against an office-visit business model. Dropped `utilities` entirely (no sensible dental
framing the way it ties to HVAC equipment — its orphaned `research_topics` entry was cleaned out too,
confirmed zero code anywhere reads that field yet). Demoted `housing_stock` from always-on to pickable —
a softer call, since the original always-on decision (UC19/v506) was extrapolated from 21 real pages
that were ALL home-trades/restoration, never validated against a real medical-wellness page.
`medical-wellness` also dropped `before_the_tech_arrives` from its service pool the same way.

**Step 3 — extended the check to all 5 remaining families**, once asked "check the other 4 families for
similar mismatches." `home-trades`/`restoration` confirmed fine (real niches, pool IS the validated
baseline); `outdoor-cleaning` confirmed fine (no real niche yet, but the family genuinely describes a
field-visit trade, same shape as home-trades/restoration). `legal`/`professional-services` fixed — both
describe office/consultative businesses with no real niche committed to either — dropped
`before_the_tech_arrives`/`repair_vs_replace`/`maintenance` from both families' service pools, since none
of the 3 fit any plausible interpretation of either category. Deliberately left both families' city pools
untouched (`permits`/`climate_data`/`hoa_rules` etc. could fit *some* legal/professional niche but not
others, and neither has a real niche yet to judge against concretely — a genuine "don't guess" call, not
an oversight). Verified via direct DB query: every existing live use of the 3 dropped block types belongs
to `home-trades`/`restoration` clients, none to `legal`/`professional-services` — nothing needed
backfilling at the family level.

**Step 4 — checked the real niche_blueprints themselves**, once asked "check the niche blueprints
themselves for similar mismatches." Found no business-category mismatch at the niche level (both real
niches are internally consistent field-visit trades) — but found a different, real issue: `niche_table`/
`climate_chart_ids` are niche-WIDE shared content with zero per-service variation
(`pluscities_write_niche_data_table_block()` takes no `service_key` parameter at all), while `hvac`
declares 2 services (`ac-repair`/`furnace-repair`) and `water-damage-restoration` declares 3
(`water-damage-restoration`/`fire-damage-restoration`/`smoke-damage-cleaning`). Both niches'
`niche_table` was authored around only the niche's namesake service — a `furnace-repair` visitor saw an
AC-only troubleshooting table, a `fire-damage-restoration`/`smoke-damage-cleaning` visitor saw a
water-only IICRC categories table. Offered Scott a choice (cheap content broadening vs. a real
per-service mechanism); "do the cheap content fix" was the answer. Broadened both tables directly via
`pluscities_niche_save()` — `hvac` gained 5 real furnace-troubleshooting rows (including a genuine
carbon-monoxide safety fact: yellow/flickering pilot flame vs. steady blue), `water-damage-restoration`
gained 4 rows covering the 3 recognized smoke residue types (dry/wet/protein) plus structural fire
damage, with its original 3 water rows relabeled for consistency.

**Required an immediate backfill, same discipline as every furniture piece in UC19**: `niche_table`
content is a cached per-block `content_json`, not read live, so the JSON edit alone would never reach
existing pages. Ran `pluscities_regenerate_block()` on all 12 existing `niche_data_table` blocks
fleet-wide (not just the 3 previously-mismatched fire/smoke pages — the `ac-repair`/
`water-damage-restoration` pages also got a richer table and needed the refresh too) and bumped
`site_rev` on all 6 affected clients (`lonestar-restoration`, `elite-air-hvac`, `meade-restoration`,
`qa-test-co`, `qa-test-co-2`, `qa-test-co-b`). Verified directly: block #202
(`meade-restoration`'s `fire-damage-restoration` page), read straight from the DB post-regeneration,
contains all 7 rows including the 3 new fire/smoke entries.

**Artifacts:** `niche_families/medical-wellness.json`, `niche_families/legal.json`,
`niche_families/professional-services.json`, `niche_blueprints/hvac.json`,
`niche_blueprints/water-damage-restoration.json` all genuinely edited (not just mechanism scaffolding
left at defaults). 6 clients' `site_rev` bumped. No fixture state left behind — every change was made
directly to real family/niche config and verified against real resolved output and real DB rows.

## UC21, 2026-11-26 — the 5-session panel-completeness walkthrough + the client-delete rebuild — ✅ PASS
(2 more real bugs found+fixed live; 3 new panel surfaces shipped; 1 brand-new real client built,
published, and deleted/restored entirely through the panel)

Prompted directly by the Flow Diagrams tab (UC "0", if it had a number — the diagrams themselves)
making the full niche-library → niche → client → page → block pipeline visible end to end for the first
time: Scott asked for a deliberate, scoped walkthrough of that pipeline, one layer per session, checking
that every real field is genuinely reachable through the panel, simple to use, and — repeatedly
emphasized — supports real SEO/Google ranking, not just checked for its own sake. Recorded here in the
same spirit as UC19/UC20: a real build-and-verify pass against real code and real live clients, not a
scripted fixture walkthrough.

**Session 1 — Niche Family + Compliance Preset.** Resubmitted a real family's (`home-trades`) and a real
niche's (`hvac`) exact current form values unchanged, to catch any silent field loss. Found and fixed a
live bug on the first try: `admin/pluscities_niches_save.php`'s `save_niche` action was missing
`climate_chart_ids`/`safety_toolkit_items` from its unexposed-field preservation list — the resubmit
silently wiped `hvac`'s 6 real climate chart settings to `[]`. Restored immediately, fixed at the source,
re-verified on both `hvac` and `water-damage-restoration`. Also corrected a stale system-reference claim
(`image_slots` was listed as having no admin UI; it actually already had one) and flagged a real gap: no
delete action exists anywhere in the Niche Library screen (preset/family/niche/image slot) — still true,
not fixed, lower priority than the client-delete gap UC21 itself later closed.

**Session 2 — Niche Blueprint.** 5 real fields (`niche_table`, `trust_bar_items`, `blog_topics`,
`custom_research_fields`, `approved_sources_extra`) had zero admin UI, hand-JSON only. Added simple
fields matching the form's existing conventions (comma-separated inputs / pipe-delimited textareas, one
line per item). Verified live: resubmitted `hvac`'s real 14-row reference table and 4 trust-bar items
through the new fields unchanged (zero content drift); then created a disposable test niche fully
populated through the panel alone — zero hand-JSON — confirmed `pluscities_niche_resolved()` parsed it
correctly, then removed the test file (no delete action exists for this, per the Session 1 finding).

**Session 3 — Client creation + city/page selection.** Grepped every admin file for `licenses[]`/
`years_in_business`/`hours`/`banned_phrases`/`price_ranges`/`reviews_by_city` — found all 6 were only
ever settable at client-creation time, despite being actively read by the AI writer
(`pluscities_client_facts_for_writer()`) and checked by the validator (banned phrases, invented prices)
or driving real schema output (`reviews_by_city` → `AggregateRating`). Built a new **Business Info**
client tab. Caught a second real bug live: the first save attempt 500'd because
`pluscities_split_csv_field()` was defined only locally inside a different file from Session 2 — fixed
by moving it to the shared `includes/helpers.php`, removing the duplicate-definition risk outright, not
patching around it. Re-verified a real client's (`elite-air-hvac`) exact existing licenses/hours/banned
phrases/2-city reviews round-tripped unchanged. Separately confirmed (no fix needed) that the "choose up
to N of M" picker math already correctly caps for every family's pool size, including
`medical-wellness`'s short 2-item pool.

**Session 4 — the page/block editor.** The single most SEO-consequential finding of the whole
walkthrough: every page's `<title>`/meta description were 100% hardcoded from one fixed template, with
zero per-page override anywhere — every client's every page shared the identical templated shape. Added
real `seo_title`/`seo_description` overrides (additive DB migration) with the auto-generated default
shown as the field's own placeholder. Caught a third real bug in this same new feature before it shipped
wrong: the save action didn't bump the owning client's `site_rev`, so the raw endpoint would have been
correct but the real production WordPress plugin would never have re-polled to pick it up — fixed to
match `hub_edit`'s existing pattern for this category of change. Verified the full round trip against a
real live page (`elite-air-hvac`/`mckinney-tx`/`ac-repair`) via its real Bearer key: override served,
cleared, fallback confirmed, `site_rev` advancing correctly both times. Also fixed 3 page-editor blocks
(`niche_data_table`/`service_area`/`housing_stock`) that showed a dead, disabled, empty edit control with
no explanation — `niche_data_table` now shows a real read-only preview + a link to where it's actually
authored.

**Session 5 — end-to-end check, explicitly no architecture changes.** Built and published "Plano Family
Dental" (`dental-practice` niche) completely from scratch through the real panel, zero hand-JSON — the
first real client this niche has ever had, directly proving Sessions 1-2's earlier family/niche
customization work (from the block-pool mismatch sweep) holds up on a real client, not just in isolated
tests. Block composition was exactly right (correct always-on blocks, correct absence of `climate_data`
for this niche); furniture generation reused already-cached city data at zero new cost; **all 12
validator checks passed on the first try**; Validate → Approve → Publish all worked; the real production
sync endpoint delivered a complete, correct live page (right title/meta — Session 4's fallback path
confirmed on fresh data — right canonical, right schema including the `Dentist` subtype). One real
finding while cleaning up: the roadmap's documented "Danger Zone" client-delete action doesn't exist
anywhere in the current code, despite being recorded as built on 2026-11-22 — likely lost in an earlier
workspace consolidation. Flagged, not fixed (Session 5 was scoped check-only); cleaned up the test
client by hand.

**The client-delete rebuild, same day, a direct follow-on request.** Built `pluscities_delete_client()`:
moves a client's JSON record + API secret into a new gitignored quarantine directory rather than
destroying them — soft delete, not a true wipe, since both real client-list functions
(`pluscities_client_ids_for_current_actor()`/`pluscities_actor_can_access_client()`) glob/check the
client directory directly, so pulling the file out is sufficient on its own. A new "Danger Zone" card
(Platform Admin only) requires typing the exact business name, checked in JS for immediate feedback and
— the real guard — again server-side. Verified live, the full matrix: wrong name rejected with the file
untouched; an `agency_admin` session rejected specifically by the role gate (isolated from a CSRF false
positive by fixing that session's own token first); the correct path quarantines both files and the
client/page degrade gracefully everywhere (no fatal errors); copying the files back by hand restored the
client and its page immediately with zero regeneration needed, proving the "recoverable by hand" design
claim for real rather than just asserting it.

**Artifacts:** 2 real niches' config genuinely edited and re-verified (`hvac`, `water-damage-restoration`
climate settings restored); 3 new real admin surfaces shipped (Niche Blueprint's 5 new fields, Business
Info tab, per-page SEO fields + the Danger Zone card); 3 real bugs found and fixed live, not just in
theory; 1 real client built, published, and fully deleted+restored through the panel with zero leftover
state. No fixture state left behind anywhere.

## UC22, 2026-11-26 — extending delete to the Niche Library (niches + compliance presets), with real
in-use safety checks — ✅ PASS

Direct follow-on to UC21's Session 1 finding (no delete action anywhere in the Niche Library screen),
asked for explicitly: "Also check the niche-authoring delete gap we flagged in Session 1" → "Yes, go
ahead and build it."

Investigated all 4 Niche Library entity types first, since they aren't symmetric: niches and compliance
presets are real user-created data (same category as clients); families are a hardcoded
`PLUSCITIES_FAMILY_IDS` PHP constant, not deletable without a code change, so explicitly scoped OUT;
image slots are a single array entry, not a whole record, so scoped for a lighter plain "Remove" link
instead of the heavier type-to-confirm machinery.

Built `pluscities_delete_niche()`/`pluscities_delete_preset()` (`includes/pluscities_niche_library.php`),
sharing one new extracted helper, `pluscities_quarantine_move()`, with the client-delete mechanism
rather than reimplementing file-move logic a second/third time — same soft-delete-to-quarantine pattern,
same type-the-exact-label-to-confirm UI (JS first, server-side again for real). Added the one real
safety check client-delete never needed: both have downstream dependents a client never does.
`pluscities_niche_clients_using()` scans every real client for a matching `niche_id`;
`pluscities_preset_usages()` scans every family's `compliance_preset` and every niche's
`compliance_preset_override`. Either blocks the delete outright and names exactly what's still using it.

**Verified live, the full matrix**: created a disposable test niche + test preset (zero AI cost — direct
function calls, not through the real create form, since this was testing delete mechanics, not
creation). (1) Attempted deleting the test niche while a fake client pointed at it → correctly refused,
named the blocking client, file untouched. (2) Removed that dependency, tried deleting with the wrong
label → refused, file untouched. (3) Correct label → moved to quarantine, confirmed gone from the real
niche list. (4) Attempted deleting the REAL `contractor` compliance preset (in active use by 5 of the 6
families) → correctly refused, named all 5 blocking families. (5) Deleted the disposable test preset
(genuinely unused) → succeeded cleanly. (6) Removed a real image slot from `hvac` (the live niche's
actual hero photo slot) via the new "Remove" link, confirmed it dropped to 0 slots, then restored it
exactly (including the real `default_image_path`) via direct write — a deliberate non-destructive test
against real data, not a disposable fixture, since a slot removal has no quarantine/restore mechanism of
its own (by design — it's meant to be a quick, low-stakes action). (7) Restored the quarantined test
niche by copying the file back and confirmed it reappeared in the real niche list, proving the
"recoverable by hand" claim held for this entity type too, not just clients.

**Artifacts:** `includes/pluscities_niche_library.php` gained the shared `pluscities_quarantine_move()`
helper + 4 new functions; `admin/pluscities_niches_save.php` gained 3 new actions
(`remove_image_slot`/`delete_niche`/`delete_preset`); `admin/pluscities_niches.php` gained 2 Danger Zone
sections + a Remove link. All test artifacts (disposable niche/preset, fake client, quarantine
directories) removed afterward; `hvac`'s real image slot restored to its exact original state. No
fixture state left behind.
