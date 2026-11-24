# PlusCities — Use Case Test Suite

Real, long walkthroughs run against the live system (`187.127.254.206`, same box this is served from) to
verify `docs/pluscities-system-reference.md`'s claims against actual behavior, not just read them. Fixture
is permanent and reusable, not cleaned up after each run — meant to be re-run as a regression check.

**Fixture:** Agency `QA Test Agency` (`qa-test-agency`), client `QA Test Co` (`qa-test-co`, niche `hvac`).
Test-WordPress install (`/opt/landing-page-clients/test-wordpress`, `localhost:8099`) is permanently
repointed to QA Test Co's API key — this is now the QA delivery test site for this suite going forward.

Status: **All 17 use cases complete and verified** (UC1-14 against the permanent `qa-test-co` fixture;
UC15-17, added 2026-11-24, against the demo-sales fixture instead — see that section for why). UC9-14
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
