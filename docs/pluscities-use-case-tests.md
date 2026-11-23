# PlusCities — Use Case Test Suite

Real, long walkthroughs run against the live system (`187.127.254.206`, same box this is served from) to
verify `docs/pluscities-system-reference.md`'s claims against actual behavior, not just read them. Fixture
is permanent and reusable, not cleaned up after each run — meant to be re-run as a regression check.

**Fixture:** Agency `QA Test Agency` (`qa-test-agency`), client `QA Test Co` (`qa-test-co`, niche `hvac`).
Test-WordPress install (`/opt/landing-page-clients/test-wordpress`, `localhost:8099`) is permanently
repointed to QA Test Co's API key — this is now the QA delivery test site for this suite going forward.

Status: **UC1-4 complete and verified. UC5 not yet run.**

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

## UC5 — Roles & permissions boundary — not yet run

Planned: a 2nd QA agency for cross-agency isolation proof, an Agency Editor scoped to QA Test Co only with
publishing off by default, confirm the publish gate holds server-side, then flip it on. Reproduce the
documented read-scope gap (editor's list views show more than `assigned_client_ids` permits) live.
