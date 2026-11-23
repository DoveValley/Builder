# LPC Stage 3 Retirement — Scoping (2026-11-23)

**Why this doc exists:** Meade Restoration (the one real LPC client) was fully cut over to PlusCities on
2026-11-23 — all 6 pages live, old `landing-page-client` WordPress plugin deactivated, hub page verified
working (see memory `project_current_state.md` v487 for the full cutover story). This is the scoping pass
for what comes next: actually retiring the old LPC codebase. This doc started as a scoping pass before
anything was deleted; **as of 2026-11-23 it also records what was actually done** — every step below
verified against the real code (grep/require-chain tracing) and the real infrastructure (SSH), not
assumed from `docs/lpc-vs-site-factory-boundary.md`'s original design intent, which had partially
drifted from current code (see corrections below). Stage 3 is now fully complete — see the status line
below.

**Headline finding: this is not a clean deletion.** One LPC-named file has quietly become the role/session
engine for the entire admin app (PlusCities and Site Factory included). Stage 3 has a required refactor
step before the bulk deletion, not just a file-removal pass.

**Status, 2026-11-23: Stage 3 is fully complete, §5 steps 1-6 all done.** Billing/session decision
answered, agencies-file extracted, nav cleaned up, 27-file bulk deletion done, client data deleted
(commits `511210ff`/`df288b2d`/`8a4ac987`/`8391774e`/`d34e76c5`/`93a58e48`/`4e375bf9`), and step 6's
infrastructure decision executed (box/delivery service — see §4 and §5.6).

---

## 1. The blocking decision — answer this before any code moves

**`admin/agencies.php`'s Stripe billing and `client_agencies/*.json` session model now gate PlusCities-only
agencies too, not just LPC ones.** An agency that has never touched LPC still logs in, gets billed, and
gets suspended through this same code path (`admin/agency_login.php` → `pluscities_dashboard.php`;
`admin/agencies_save.php:84,148` calls `stripe_update_subscription_price()`/`stripe_create_checkout_session()`
against the same agency record `pluscities_dashboard.php`/`pluscities_team.php`/`admin/impersonate.php` all
read). Retiring "LPC" does not retire this coupling — the question is whether PlusCities billing/sessions
stay piggybacked on the same Stripe/agency code, or get split into their own path, as part of this work.
**Decide this first; everything below assumes an answer either way is possible, but the actual refactor in
§3 needs to know which.**

---

## 2. The file inventory

### 2.1 Safe to delete outright — confirmed nothing outside the LPC family references these

**Admin screens:**
`admin/client.php`, `client_save.php`, `client_wizard.php`, `clients.php`, `approvals.php`,
`client_login.php`, `client_portal.php`, `page_editor.php`, `preview_site.php`, `lpc_plugin_download.php`,
`lpc_nav.php`, `niche_profiles.php`, `niche_profiles_save.php`, `lpc_demo_sites.php`,
`lpc_demo_sites_save.php`, `lpc_demo_sites_status.php`.

**Backend includes (`includes/landing_page_clients*.php`, all 13 files except the agencies one):**
`landing_page_clients.php` (core client/page CRUD), `landing_page_clients_ai.php` (AI generation + the
PlusCities-writer bridge), `landing_page_clients_approvals.php` (two-stage approval queue),
`landing_page_clients_area_charts.php` / `_area_images.php` (wrap the shared chart/map plugins),
`landing_page_clients_clicks.php` (click-tracking readback), `landing_page_clients_detect.php` (onboarding
branding scrape), `landing_page_clients_media.php` (per-service image sourcing),
`landing_page_clients_niches.php` (`client_niche_profiles/*.json` CRUD), `landing_page_clients_publish.php`
(schema assembly, publish, `lpc_preview_render()`, remote sync), `landing_page_clients_reporting.php`
(rank/GSC reporting), `landing_page_clients_safety_toolkit.php` (thin reuse wrapper).

**Also:** `includes/lpc_demo_box.php`, `includes/pluscities_lpc_bridge.php` (exists only to serve LPC's
City Service Page picker — confirmed its own docblock and both callers, `landing_page_clients_ai.php:404`
and `admin/client_save.php:421`, are LPC-only; nothing in PlusCities' own `pluscities_create_page()` /
`pluscities_regenerate_block()` path touches it).

**WP plugin:** `wp-plugin/landing-page-client/` in its entirety — its successor's own docblock
(`wp-plugin/pluscities-delivery/pluscities-delivery.php:6-8`) confirms intent: *"Deliberately a NEW
plugin, not a patch to the old 'Landing Page Client' plugin... The old plugin stays installed, deactivated,
for whatever still depends on it."* Nothing else in the repo requires any file under this directory.

**Delivery-side:** `delivery-server/public/api.php` and whatever is deployed on the standalone delivery
box (see §4).

**Data:** `clients/meade-restoration/` (the one real LPC client, now reconciled into PlusCities per commit
`25dac361`), `clients_deleted/*` (two already-quarantined test clients: `mr-test-restore-20261122T020341`,
`restore-2-20261122T020330`), `client_niche_profiles/*.json` (LPC-only niche data, distinct from the
shared `niche_library/` and PlusCities' own `niche_blueprints/`).

**Cron jobs:** `tools/lpc_check_alerts.php` (30-min stale-plugin alert — `LPC_ALERT_EMAIL` is currently
unset in `config.php`, so it has never actually sent anything; safe to remove outright),
`tools/lpc_check_grace_periods.php` (daily agency-billing grace-period enforcement — LPC-purposed, but
calls into the agencies file, see §1/§3 — needs to move with it, not just vanish).

### 2.2 Must keep — genuinely shared, confirmed by direct call-site tracing

- **`includes/agencies.php`** (renamed 2026-11-23 from `includes/landing_page_clients_agencies.php`, see
  §3 — this bullet already reflects the new name). `lpc_current_actor_role()` alone is called from ~90
  files including almost every `pluscities_*.php` screen and every Site Factory/infra page.
  `admin/infra/bootstrap.php` requires this file directly for exactly that function, with a comment
  noting a past production outage (commit `47cbb498`) from under-accounting this dependency.
- **`admin/agency_login.php`** — shared login for both legacy LPC agency passwords and new per-person
  PlusCities editor accounts (`pluscities_user_find_by_username()` tried first, falls back to
  `lpc_find_agency_by_username()`); both success paths redirect to `pluscities_dashboard.php`.
- **`includes/stripe_client.php`, `admin/stripe_webhook.php`** — bill/gate *agencies*, a concept now
  shared with PlusCities per §1. `stripe_request()` itself is a thin wrapper over the already-shared
  `admin/infra/lib/http.php`'s `infra_http()` — the HTTP client is correctly shared, only the billing
  *target* (agencies) is the open design question.
- **`client_agencies/*.json`, `client_agencies_secrets/*`** — the agency records themselves.
- **Every forward dependency LPC code calls into, all confirmed still genuinely shared, not candidates
  for any change:** `admin/infra/lib/state.php`'s `fleet.db` (cities/city_data tables — **note: the
  boundary doc's planned fix, giving LPC its own copy, was never implemented; LPC still reads the fleet's
  live `fleet.db` today, a real unresolved risk independent of Stage 3**), `includes/anthropic.php`,
  `includes/openai_images.php`, `admin/infra/lib/keywords.php`, `plugins/image-data-chart/*`,
  `plugins/image-area-map/*`, `plugins/safety_toolkit/*` (also used by the fleet's own
  `includes/editor.php`), `includes/helpers.php`, `includes/google_search_console.php` (also used by
  `multisite/*` and several fleet tabs), and the new shared `includes/niche_library.php` /
  `niche_library/*.json` (built since the boundary doc was written — a copy-in source, not a live link,
  so no coupling risk either way).

### 2.3 Needs a human decision beyond §1

- Final go-ahead to actually delete `clients/meade-restoration/` and the two quarantined clients —
  likely fine, but irreversible customer data, worth an explicit confirmation separate from "the code is
  safe to delete."
- Whether the LPC demo-sites box/feature (`admin/lpc_demo_sites*.php`, the 12 rehearsal WordPress installs
  on `2.24.196.58`) is still wanted for anything else before its admin screens are deleted — nothing in
  current code suggests PlusCities uses it, but this is a product call, not something grep can settle.
- What happens to the standalone delivery server process and its 5 orphaned test-client directories (see
  §4) — independent of the code-repo deletion, since it's live infrastructure on a box PlusCities doesn't
  touch.

---

## 3. The required refactor — extract before you delete — ✅ DONE 2026-11-23

`includes/landing_page_clients_agencies.php` could not simply move into the "delete" pile. §1 was
answered first (keep billing/sessions on the shared path — `includes/stripe_client.php`,
`admin/stripe_webhook.php`, `admin/agencies.php` needed no change at all, confirmed already agency-level,
not LPC-specific).

**What was actually done**, matching the plan below:
1. **Renamed** `includes/landing_page_clients_agencies.php` → `includes/agencies.php` via `git mv`
   (history preserved), content unchanged.
2. **Updated the real call sites** — a repo-wide grep *before* touching anything found only **2** direct
   `require` sites, not ~90: `includes/functions.php` (the central loader everything else gets it
   through) and `admin/infra/bootstrap.php` (its own direct require, by design — "deliberately
   self-contained"). Both updated. The ~90 actual *callers* of `lpc_current_actor_role()` etc. needed
   zero changes — they call functions by name, not by file path. (The "~90 files to update" framing
   below, from the original scoping pass, overstated the mechanical blast radius — worth re-verifying
   call-site counts before estimating effort next time rather than assuming every caller needs editing.)
3. **Smoke-tested**: lint-clean on all 6 touched files; `lpc_current_actor_role()`/`lpc_load_agency()`
   both callable after the rename; real HTTP requests against `admin/infra/index.php` (the documented
   prior-outage page), `admin/pluscities_clients.php`, and `admin/client.php` (still-present LPC page)
   all returned clean redirects with zero fatal-error output.
4. Also updated 3 stale comment references to the old filename (`landing_page_clients.php`,
   `pluscities_users.php`, `tools/lpc_check_grace_periods.php`) and the file's own header to explain why
   it moved.

Commits: `511210ff` (the rename itself), `df288b2d` (require-path + comment updates).

**Next**: §5 steps 3-6 (nav cleanup, bulk file deletion, data cleanup, infra decisions) — not yet done.

---

## 4. Infrastructure side (not in this repo, found via direct SSH)

- **Correction to `docs/lpc-vs-site-factory-boundary.md`:** that doc cites the standalone LPC delivery
  server at `31.97.133.251`. That address is stale — `config.php:124-133`'s own comment says the server
  was *"migrated 2026-11-18 off fleet Box 1 onto its own dedicated box (2.24.196.58)"*, and
  `LPC_DELIVERY_REMOTE_HOST` is defined as `2.24.196.58` — confirmed live and load-bearing.
  `31.97.133.251` appears nowhere else in the repo. Treat `2.24.196.58` as current truth.
- **That box is the same one hosting the LPC demo-sites rehearsal WordPress installs** — two logically
  separate services (`standalone-lpc-delivery.service`, confirmed active/running, and the 12 demo sites)
  on one physical box, not two servers to separately decommission.
- **The delivery service's real client data is mostly orphaned test fixtures**, not live customers —
  `ls /opt/standalone-lpc-delivery/data/clients/` shows `client-for-grace-test-c` (8K),
  `meade-restoration` (3.5M, now migrated), `mr-test-restore` (2M), `restore-2` (20M),
  `test-approval-client` (16K), `test-billing-client` (8K) — none of these except `meade-restoration` has
  a matching admin-side client record (`clients/` only has `meade-restoration/`); the rest are leftovers
  from past QA work, never cleaned up. Safe to delete independent of the Stage 3 timeline.
- **`tools/lpc_check_alerts.php`'s cron currently fires every 30 minutes with no real consequence** —
  `LPC_ALERT_EMAIL` is unset in `config.php`, confirmed via its own log
  (`/var/log/lpc-alerts.log`: *"LPC_ALERT_EMAIL not configured... skipping, nothing sent"* on every run).
  No false alert went out when Meade's plugin was deactivated.
- **Stripe billing is only half-configured** — `.stripe_secret_key` exists (real key), but
  `.stripe_webhook_secret` does not, so `admin/stripe_webhook.php` cannot verify any incoming webhook
  today regardless of what Stage 3 does to it.

---

## 5. Recommended sequence

1. ✅ Answer §1 (billing/session coupling design) — done, kept shared.
2. ✅ Do §3's agencies-file extraction, verified fleet-wide before moving on — done, commits `511210ff`/
   `df288b2d`.
3. ✅ Clean up `admin/lpc_admin_layout.php`'s nav — done, commit `8a4ac987`. Removed "All Clients"/"New
   Client"/"Approvals"/"Niche Profiles (old)"; deliberately left "Demo Sites" alone since its fate is
   still the open §2.3 decision below, not resolved as a side effect of nav cleanup. Verified all 4 real
   roles via direct CLI call to `lpc_admin_nav_items()` — super_admin's nav clean with no dead links,
   every other role byte-for-byte unchanged. Caught a near-bug before shipping: first attempt pointed
   super_admin at `pluscities_dashboard.php`, which explicitly rejects anyone but agency_admin/
   agency_editor — checked before assuming, not after.
4. ✅ Delete §2.1's file list — done, commit `8391774e`. Found and fixed a real gap in this doc's own
   §2.1 before deleting: it only traced require/include chains, not `header('Location: ...')` redirect
   targets. ~19 other admin files (`impersonate.php` alone has 5) redirect to `clients.php` as a generic
   error/auth-failure fallback — deleting it outright would have 404'd every one. Replaced it with a thin
   redirect stub to `pluscities_clients.php` instead of deleting (preserves `?msg=...` query-string
   passthrough). Also found `admin/approvals_save.php` was missing from §2.1's inventory entirely and
   included it in the batch. Verified: full repo-wide `php -l` sweep clean; a final require-chain grep for
   all 25 deleted filenames found zero remaining references; full HTTP smoke test against every PlusCities
   screen, Site Factory/infra, the new `clients.php` stub, and the still-kept `lpc_demo_sites.php` all
   returned clean 302s with zero fatal-error output. `CLAUDE.md`'s docs table updated to mark the two docs
   describing now-deleted code (`landing-page-clients-onboarding.md`, `city-service-page-block-matrix.md`)
   as historical-only, commit `d34e76c5`.
5. ✅ Explicit go-ahead given, data deleted — commit `4e375bf9`. `clients/meade-restoration/` (both the
   tracked `data/` files and the gitignored `secrets/api_key.txt`), `clients_deleted/*` (both quarantined
   test clients, gitignored, disk-only), `client_niche_profiles/*.json` (its only reader already
   deleted). Also cleaned up `config.php`: removed `CLIENTS_DIR` and all 6 now-dead `LPC_DELIVERY_*`
   constants (verified zero remaining references first) — `LPC_DEMO_BOX_*` and `LPC_ALERT_EMAIL`
   deliberately kept (still used). Verified: `config.php` lints clean, full HTTP smoke test across every
   core screen clean. **This is the last repo-level step** — everything else is infrastructure.
6. ✅ §2.3's infrastructure items decided and executed, 2026-11-23 — commits `9db14c7b`/`58b35b87` for the
   repo-side pieces, plus direct infra changes on two boxes (no repo commit, by nature). Three-way split:
   - **Kept**: the demo-sites box (`2.24.196.58`) itself, its 12 WordPress installs, Hestia, and
     `includes/lpc_demo_box.php`'s SSH/API plumbing — confirmed genuinely shared with WP Builder
     (~15 real call sites). Verified Hestia still answers (`v-list-web-domains` style check via
     `curl https://localhost:8083/` → 302, service `running`) after everything else below was removed.
   - **Deleted (repo)**: `admin/lpc_demo_sites.php`, `lpc_demo_sites_save.php`, `lpc_demo_sites_status.php`
     — only ever rehearsed the old LPC delivery mechanism; nothing else referenced them except the nav
     entry and each other. Nav entry removed from `admin/lpc_admin_layout.php`.
     `wp-plugin/demo-site-back-link/demo-site-back-link.php`'s badge pointed at the now-deleted
     `lpc_demo_sites.php` — repointed to `sites.php`; also dropped a dead CSS override gated on a query
     var (`lpc_route`) nothing sets anymore since the LPC plugin itself is gone.
   - **Retired (infra, not a repo change)**: the standalone delivery service on `2.24.196.58`
     (`standalone-lpc-delivery.service`) — stopped, disabled, unit file removed, data directory renamed to
     `/opt/standalone-lpc-delivery.retired-2026-11-23` (moved rather than `rm -rf`'d, so it's recoverable
     if anything surfaces later). **Real finding before touching it**: the service was still getting live
     traffic (`click_summary`/`status` calls every ~15 min) — traced to `wptest-landing-clients.service`,
     a persistent local test WordPress install on *this* box (port 8099) still running the deleted
     `landing-page-client` plugin via its own wp-cron, left over from building/testing that plugin earlier
     this session. Not a real client dependency. Stopped+disabled that service and
     `landing-page-delivery.service` (an already-inactive sibling unit on this box), removed both unit
     files, and moved `/opt/landing-page-clients/` aside to
     `/opt/landing-page-clients.retired-2026-11-23`. Confirmed the remote service went fully quiet
     (`journalctl --since '5 minutes ago'` → no entries) before stopping it for real. Client data in the
     retired delivery dir matched the expected list exactly: 5 orphaned QA fixtures
     (`client-for-grace-test-c`, `mr-test-restore`, `restore-2`, `test-approval-client`,
     `test-billing-client`) plus the already-migrated `meade-restoration`.
   - Verified after all of the above: full local `php -l` sweep clean on every touched file, HTTP smoke
     test (`wp_builder.php`, `pluscities_clients.php`, `sites.php`, `login.php`) all clean redirects/200,
     `lpc_demo_sites.php` now correctly 404s.

**How to apply:** re-verify file/line references above before executing any step — this was scoped from a
point-in-time read of the code on 2026-11-23; if time has passed, re-run the same grep/require-chain
tracing rather than trusting these line numbers blindly.
