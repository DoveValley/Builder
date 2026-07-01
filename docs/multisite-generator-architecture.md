# Multisite Generator — Architecture & Build Plan

## 1. Purpose

Generate and deploy **100+ separate, live websites** from a single master site. Each generated site is fully independent — its own domain, business name, phone number, email, address, city, and state — but is fundamentally the same business and topic, differing only by location. Each site gets unique, SEO-targeted, AI-written city-specific content and lands on its own host via FTP.

This is **distinct** from the in-site city-pages generator (`includes/generation/engine.php`), which produces many *pages within a single site*. The Multisite Generator operates one level up: it produces *whole sites*.

```
Master site (template)
        +
Params table (one row per site — domain, business, phone, city, state, FTP creds)
        ↓
[ for each row ]  generate → build static HTML → deploy via FTP
        ↓
100+ live, independent sites
```

---

## 2. Design Principles (locked)

These were decided up front and constrain the whole design.

1. **Generated sites are never stored as JSON.** There is no `sites/{id}/` tree for the 100+ output sites. Persisting 100 near-identical site trees would be pure bloat — they would differ from the master only in `site_vars` plus a handful of AI-written fields. The master template **is** the structural source of truth for every site.

2. **No per-site edits.** The master site is the *only* place structure, design, or content ever changes. Edit the master → rebuild affected sites → redeploy. There are no per-site override columns and no per-site stored content. The params table is purely factual identity data and carries no content.

3. **AI copy is cached per site, but nothing else is.** Because the master is the only editable surface and AI output is non-deterministic, each site's AI-written copy is generated **once**, frozen in a tiny `generated/{domain}.json` cache (a few KB), and reused on every rebuild. This keeps page copy stable for SEO and makes redeploys free. Regeneration happens only on an explicit refresh. This stores *the words*, not the site.

4. **Builds are ephemeral.** Each site is assembled into a temporary working directory, rendered to static HTML, deployed via FTP, and the temp directory is deleted. Nothing the generator produces persists in `sites/`.

5. **The master stays freely editable without breaking the fleet.** Editing the master — including its AI-targeted blocks on home, landing, and core pages — must never silently misroute, stale, or crash per-site generation. This is guaranteed by the three rules in "Editing the master safely" below; the cache stores *only AI words*, keyed by stable block ID and stamped with a prompt hash, so static/structural edits propagate freely and AI edits self-heal on the next rebuild.

### The complete persisted footprint

| Persisted artifact | Location | Size | Role |
|---|---|---|---|
| Master template | `sites/{master_id}/` | full site | Structural + design source of truth (already exists) |
| Params table | `sites/{master_id}/multisite/params.csv` | tiny | The input — one row per site (holds FTP creds) |
| Per-site AI cache | `sites/{master_id}/multisite/cache/{domain}.json` | a few KB each | AI-written copy, frozen for deterministic/free rebuilds |
| Run logs | `sites/{master_id}/multisite/runs/{run_id}.json` | small | Per-run status/cost |

The entire `sites/*/multisite/` directory is **gitignored** — see Credential handling below. Everything else — full output-site JSON, rendered HTML — is ephemeral or lives only on the FTP target.

### Multisite data lives with its master site

Multisite is a **property of a specific master**, not a global factory function. `granitepmacademy` may run a 100-site PM-training campaign while `pest-template` runs a separate pest-control campaign — different params tables, different AI caches, never a shared namespace. So all campaign data is nested under the master that owns it:

```
sites/{master_id}/
  data/...                     ← the normal site (the template)
  uploads/...
  multisite/                   ← ONLY present if this master runs multisite
    params.csv                 ← this campaign's input table (+ FTP creds)
    cache/{domain}.json        ← per-output-site AI copy cache
    runs/{run_id}.json         ← run logs
```

Consequences:
- **Detection is trivial** — "is this a multisite master?" = does `sites/{id}/multisite/` exist.
- **Lifecycle is automatic** — export/delete a master and its campaign data goes with it.
- **Cloning strips it** — when a master is cloned via `site_api.php`, the `multisite/` directory must NOT be carried into the clone (same treatment already applied to `deploy.json`). A clone is a fresh template, not a campaign.
- **Non-multisite sites have zero footprint** — they simply have no `multisite/` subdir.

### Credential handling

FTP passwords live in `params.csv` (and are written transiently into each ephemeral build's `deploy.json` at deploy time). These must never be committed. `.gitignore` rules:

```gitignore
# FTP credentials — never commit
sites/*/data/deploy.json

# Multisite campaign data (holds FTP credentials) — never commit.
sites/*/multisite/
```

**Ignore the whole `multisite/` directory, not the individual secret files.** The directory's purpose is to hold credentials (`params.csv`) and deploy logs (`runs/`), so the correct default is deny-by-default: nothing inside can ever reach git, regardless of what files appear there or how formats evolve later. An allow-by-default folder with a deny-list of named secret files is fail-dangerous — the day the cache format grows a credential-bearing field, or a stray export lands in the folder, it silently commits. A blanket ignore can't fail that way.

The AI cache (`cache/*.json`) is therefore ignored along with everything else. It holds no secrets, but tracking it in git buys almost nothing here: the live factory box is not a git checkout (so nothing is restored from git on it), and git is the wrong tool for preserving a regenerable cache anyway. The downside of not tracking it is cheap and recoverable — re-spend some API money to regenerate — versus the unrecoverable downside of a leaked credential in history (permanent exposure + password rotation across every server). If the cache ever needs preserving off-box, back the folder up out-of-band (tar/rsync), which is the right tool for "keep this data safe."

> Note: prior to this design, the deploy UI claimed `deploy.json` was gitignored, but no such rule existed — `git check-ignore` confirmed it was committable. The rules above close that gap.

---

## 3. Existing Infrastructure (already built)

Every per-site primitive the generator needs already exists in the factory — but each is currently locked inside a session-bound, SSE, admin-authenticated endpoint that acts on the single "active site." The generator does not reinvent these; it extracts their cores and drives them from a different identity source (see the execution model below).

| Capability | Current location | Notes |
|---|---|---|
| Clone a site | `admin/site_api.php` — `action=create` + `clone_from` | Copies `data/` + `uploads/`, rewrites upload paths to the new ID, strips FTP creds |
| Build static HTML | `admin/generate_static.php` | SSE endpoint; renders every page via `ob_start`/`site-template.php`, copies assets + uploads, writes `sitemap.xml` / `robots.txt` / `.htaccess` |
| FTP deploy | `admin/deploy_ftp.php` | Reads per-site `sites/{id}/data/deploy.json`, uploads only new/changed files via `ftp_put` |
| FTP audit / cleanup | `admin/deploy_audit.php`, `admin/deploy_delete_orphaned.php` | Remote file listing + orphan removal (reusable for verification) |
| AI prompt registry | `sites/{id}/data/ai_block_types.json` | Per-site named AI block configurations (the prompt registry) |
| In-site generation engine | `includes/generation/engine.php` | Template × city loop; only the `city_vars` step exists in `includes/generation/steps/` |

### Execution model: one fresh process per site (LOCKED)

**The constraint that dictates everything.** `config.php` derives every path the build/render/deploy layer uses — `DATA_FILE`, `UPLOAD_DIR`, `UPLOAD_URL`, `PAGES_DIR`, `PAGE_INDEX_FILE`, `ACTIVE_SITE_DIR`, etc. — into PHP `define()` **constants**, computed once from `$_SESSION['active_site']`. The entire render layer (`load_data()`, `site-template.php`, `blocks.php`, `shortcodes.php`) reads those constants *globally*, not as parameters. PHP constants are **immutable**: once `DATA_FILE` is defined for site #1, it cannot be re-pointed at site #2 in the same process.

**Consequence:** a single PHP process can build exactly one site. An in-process `for` loop over 100 sites is impossible — the doc's earlier "build each into a temp dir in a loop" phrasing cannot be done literally. Two ways out were considered:

- **A — one fresh process per site (CHOSEN).** A parent *orchestrator* spawns a fresh PHP CLI subprocess per row. That worker's `config.php`, in CLI/batch mode, reads the site identity from an environment variable (e.g. `MULTISITE_SITE_BASE` = absolute path to the ephemeral site dir, `MULTISITE_OUTPUT_BASE` = render target) instead of from the session, defines the constants once for its one site, builds + deploys, and exits. The frozen-constant problem evaporates because each site gets a clean process. **Reuses ~100% of the existing render/build/deploy code untouched** — the only change to `config.php` is *where it sources the identity*. Failures isolate per process; parallelism later is just "spawn N workers." This is the literal embodiment of the locked `process_row()`-is-self-contained decision.
- **B — thread a `$ctx` through the whole render layer (REJECTED).** Remove the constants and pass a site-context object into every render function. "Purer," but an invasive, high-risk rewrite of code that already works for live single sites, and it contradicts "no new logic, only decoupling." Far more complex than A.

**Why A is the *simpler* choice, not the more complex one:** the subprocess machinery is one `exec()` in a loop plus a small `config.php` tweak; the existing engine is reused verbatim. Option B is where the real complexity lives. A contains the project's complexity rather than adding to it.

The "don't store the sites" rule is honored the same way regardless: a run takes one protective **snapshot** of the master up front, each worker clones a cheap **working dir** from that snapshot, builds, deploys, then deletes it; the snapshot is torn down at run end — nothing persists in `sites/`, and the original master is never mutated (see §5 "Two-level cloning").

---

## 4. The Params Table

The input. One row per target site. Purely factual identity data — no content. The `lat`/`lng`/`logo`/`analytics_id` columns exist to make each site a distinct entity in search engines — see §11. Theme/color and per-site images are derived or assigned by the pipeline, not supplied as columns.

| Field | Example | Used for |
|---|---|---|
| `domain` | `pmtraining-dallas.com` | Site identity, cache key, canonical URLs |
| `business` | `Dallas PM Academy` | `site_vars.business` |
| `phone` | `214-555-0100` | Display phone |
| `tel` | `+12145550100` | `tel:` links |
| `email` | `info@pmtraining-dallas.com` | Contact + footer |
| `address` | `123 Main St, Suite 400` | Footer / contact / schema |
| `city` | `Dallas` | `site_vars.city` + AI context |
| `state` | `Texas` | `site_vars.state` |
| `SS` | `TX` | 2-letter abbreviation |
| `zip` | `75201` | Address / schema |
| `lat` | `32.7767` | Geo coordinate → LocalBusiness schema (uniqueness) |
| `lng` | `-96.7970` | Geo coordinate → LocalBusiness schema (uniqueness) |
| `logo` | `dallas-logo.png` | Per-site logo filename (optional — auto-wordmark from `business` if blank) |
| `analytics_id` | `G-XXXX` | Per-site analytics property (optional — blank = none; never share one ID across sites) |
| `ftp_host` | `ftp.pmtraining-dallas.com` | Deploy target |
| `ftp_user` | `deploy@...` | Deploy auth |
| `ftp_pass` | `••••` | Deploy auth |
| `ftp_path` | `/public_html` | Remote root (optional, default `/`) |

**Intake format: CSV upload first.** With 100+ rows and 13+ columns, the data is prepared in a spreadsheet anyway; a CSV upload is far less to build than a browser grid and is painful-free to edit at scale. An admin grid may be added later as a convenience for spot-fixing individual rows. The uploaded CSV is stored as `sites/{master_id}/multisite/params.csv`. FTP credentials in the table are written into each ephemeral build's `deploy.json` at deploy time and never persisted in an output site.

---

## 5. The Generation Pipeline

### Two-level cloning (LOCKED)

The original master is **never touched during a run**. A run takes one protective **snapshot** of the master up front and works off that; each row then gets its own cheap, throwaway **working dir** cloned from the snapshot. This gives three guarantees at once:

- **The original is always intact.** It is read exactly once (to make the snapshot), then hands-off for the whole run. Worst case on any failure: delete temp dirs, fix the cause, restart — the master is untouched.
- **Consistency across the run.** Every row builds from the *same* frozen snapshot, so editing the master mid-run can't make site #5 and site #60 inconsistent.
- **No cross-site state bleed, and parallel-safe.** A *fresh* working dir per row is a clean room — City A's data can't leak into City B (which an in-place-mutated single dir would risk), and parallel workers never share a mutable dir.

> Rejected alternative: reuse **one** mutable temp dir, re-parameterized in place for all 100 rows. It collides with the one-process-per-site model (§3), risks silent field-bleed between iterations, and blocks future parallelism. The cheap per-row clone is the insurance against all three.

**Efficiency:** the per-row clone is *shallow* — copy `data/` (tiny JSON) per row, and **share/symlink the snapshot's `uploads/`** rather than deep-copying it, overlaying only the per-site images that actually differ (favicon, hero, etc.). Clean-room isolation at near-zero copy cost. Exact link mechanism settled when the clone step is built (Phase 0c).

### Run-level steps (once per run)

```
R0. Pre-flight FTP check
    └─ ftp_connect + ftp_login each row (no upload); report bad creds before building.
       Opt-out-able / skippable on reruns.

R1. Snapshot the master  ← protects the original; frozen template for the whole run
    └─ clone master data/ + uploads/ ONCE into a run-scoped snapshot dir; multisite/ is NOT copied.

[ for each row → per-row steps below ]

R2. Tear down
    └─ delete the snapshot.  Nothing persists in sites/.
```

### Per-row steps (one fresh subprocess per row, §3)

```
1. Resolve AI copy
   └─ multisite/cache/{domain}.json exists & prompt-hash matches? → load it (free, deterministic)
      else                                                        → run AI content steps, write cache

2. Clone snapshot → working dir
   └─ shallow copy: data/ copied, uploads/ shared/symlinked from the snapshot;
      keyed by slugified domain (pmtraining-dallas.com → pmtraining-dallas_com)

3. Inject identity + copy + differentiation
   └─ write params into site_vars; merge cached AI copy into block fields (by stable id, §6a);
      override per-site favicon / theme / images (Phase 5)

4. Build static HTML
   └─ render every page → assets → sitemap/robots/.htaccess  (build_static_site)

5. Deploy via FTP
   └─ deploy config from params (not a stored deploy.json) → upload changed files  (deploy_site)

6. Clean up
   └─ delete the working dir.  Nothing persists in sites/.
```

Each row is independent — a failure on one site does not block the rest; it is logged and retried. The master and the snapshot are never mutated by a row.

---

## 6. The AI Content Cache

`sites/{master_id}/multisite/cache/{domain}.json` holds only the AI-written text fields for one site, keyed by **stable block ID** (not array position) and stamped with the **prompt hash** that produced each entry. Example shape:

```json
{
  "domain": "pmtraining-dallas.com",
  "generated_at": "2026-06-30T12:00:00Z",
  "model": "claude-haiku-4-5-20251001",
  "fields": {
    "blk_home_intro":   { "prompt_hash": "a1b2…", "value": { "heading_text": "...", "text": "..." } },
    "blk_home_feats":   { "prompt_hash": "c3d4…", "value": { "columns": [ ... ] } },
    "blk_pmp_hero_sub": { "prompt_hash": "e5f6…", "value": "..." },
    "blk_pmp_faq_add":  { "prompt_hash": "7890…", "value": [ { "q": "...", "a": "..." } ] }
  }
}
```

- **Keyed by stable block ID** — survives reordering/adding/removing other blocks (rule 1 below). The cached words always find their home by ID, never by counting positions.
- **Each entry carries a `prompt_hash`** — on rebuild, a cached entry is reused only if its hash matches the current prompt; otherwise that one field regenerates (rule 2 below). No stale copy is ever served, and there is no manual cache-wiping.
- **Written once**, then **reused** on every rebuild where the hash still matches — no API call, identical output → SEO-stable copy.
- **Read-through + self-healing** (rule 3 below): missing entry → generate; stale (hash mismatch) → regenerate; orphaned (block deleted/renamed) → ignore. A build never crashes on a cache mismatch.
- **Refreshed** wholesale only when explicitly requested (`--refresh` / a "Regenerate copy" action), which rewrites the cache regardless of hash.
- **Not in git** — the whole `multisite/` dir is gitignored (fail-safe against leaking the FTP creds in `params.csv`). The cache lives on disk next to the master; preserve it via out-of-band backup if needed, not git.

The AI content steps themselves (`city_market_intro`, `feature_columns_local`, `hero_subtext`, `faq_additions`) are described in `ai-generation-architecture.md` but are **not yet built** — only `includes/generation/steps/city_vars.php` exists today. Writing them is Phase 2.

---

## 6a. Editing the master safely (LOCKED)

The master is the only editable surface (Principle 2), so it **will** be edited after the fleet is live — copy fixes, new blocks, reworded AI prompts, reordering. None of that may silently misroute, stale, or crash per-site generation. Two facts make most edits safe for free, and three rules make AI-block edits safe.

**Already safe by design:**
- **Static content is never cached.** The cache holds *only* AI-written words. Editing any static block (pricing, headings, images, stats, most copy) involves no cache at all → on the next rebuild every site picks up the change automatically. The majority of edits are in this category and cannot desync or break generation.
- **The master is a complete, working site.** AI-targeted fields hold normal base content in the master (the hero has its own subtext; AI only *replaces* it per-city during the ephemeral build). Editing the master in the admin always shows a real working site, never a broken template.

**The narrow risk:** today blocks have **no stable ID** — they are identified by array position, and AI injection targets by relative position (`ai_inject_target: "next"`). So reordering/adding/removing blocks, reworded prompts, or renamed AI block types could misroute or stale the per-site copy (it would not crash the master — worse, it would silently produce wrong/outdated city copy).

**The three rules that close it:**

1. **AI-targeted blocks get a stable `id`.** The AI step references that ID, not "next" or position. Reordering/adding/removing *other* blocks can then never misroute cached copy — words find their home by ID. Backward-compatible: blocks without an `id` behave exactly as today (`id` is not rendered, so it cannot change output — the regression baseline confirms this).
2. **Each cache entry is stamped with a prompt/config hash.** On rebuild: hash matches → reuse for free; hash differs → regenerate just that field. Editing a prompt auto-refreshes that block's copy across the fleet on the next run — no stale copy served, no manual cache-wiping.
3. **Read-through + self-healing cache.** Missing → generate; stale (hash mismatch) → regenerate; orphaned (block deleted/renamed) → ignore. A build never crashes on a cache mismatch; worst case is a cheap regeneration of only the changed block.

**Resulting workflow:**
- Edit static content or structure → rebuild → propagates everywhere, nothing to think about.
- Edit an AI block's prompt → rebuild → only that block's copy regenerates per site (small API cost); everything else reused.
- Add / remove / reorder blocks → rebuild → copy follows the right blocks by ID; new AI blocks generate, removed ones drop out.

No per-site editing, no manual cache surgery, no silent staleness.

**Phase mapping:** Rule 1's `id` field is a small additive migration on the master's AI-targeted blocks (verify against the regression baseline — `id` must not change rendered output). Rules 2–3 are implemented in Phase 2 (AI steps + cache). In **Phase 0**, the worker's "inject AI copy" step must already look copy up by stable block ID and **tolerate a missing/empty entry** (fall back to the master's base content) — so it is correct the day Phase 2 fills the cache, and never breaks the master (which has no cache).

---

## 7. Build Phases

### Phase 0 — A working "build + deploy one site" worker (no browser)
**Goal:** prove the per-site worker end-to-end against one real site, run from the CLI. This is the foundation the orchestrator wraps. Per the locked execution model (§3), there is **no in-process loop** — one fresh process builds one site. Four sub-steps, each independently verifiable:

- **0a — `config.php` identity seam.** In CLI/batch mode (env var present, e.g. `MULTISITE_SITE_BASE` / `MULTISITE_OUTPUT_BASE`), derive the path constants from those instead of from `$_SESSION['active_site']`. Must accept an **absolute** base path — the ephemeral build dir is not under `sites/`. *Verify:* existing admin behaves identically when the env var is absent.
- **0b — logger seam (de-SSE the cores).** Replace the inline `sse()` / `ftp_sse()` calls woven through `generate_static.php` and `deploy_ftp.php` with an injected logger. Two implementations: SSE for the admin endpoints (unchanged behavior), stdout JSON-lines for the worker. Build/deploy logic itself is unchanged. *Verify:* admin "Generate Static" + "Deploy" still stream identically.
- **0c — extract the three primitive cores** (now that 0a + 0b removed the session/SSE coupling):
  - `snapshot_master($masterId): string` + `clone_to_working_dir($snapshotDir, $params): string` — from `site_api.php` clone logic (`copy_dir` + `rewrite_upload_paths_in_dir` + strip `deploy.json`). `snapshot_master` runs once per run (protects the original, §5 R1); `clone_to_working_dir` makes the cheap per-row working dir from the snapshot (`data/` copied, `uploads/` shared/symlinked), returns its path. Settle the upload-link mechanism here.
  - `build_static_site(): array` — from `generate_static.php`, driven by the constants 0a set from env; renders all pages, copies assets, writes sitemap/robots/.htaccess; returns a manifest
  - `deploy_site(array $ftp): array` — from `deploy_ftp.php`; FTP creds + canonical domain + web3forms key come from the **params row**, not a `deploy.json` file; uploads changed files; returns a result
- **0d — the per-site worker** (`multisite/build_one.php`): reads identity + params from env/argv, wires 0a–0c for one row, emits JSON-lines progress. Plus a tiny CLI harness to invoke it for one site.

No new render/build logic — only decoupling from session/SSE/auth and re-sourcing identity. Diff the worker's output against the admin-produced output to prove parity.

**Deliverable:** generate + deploy one real site end-to-end from the command line, no browser/SSE.

> **Wrinkle — incremental-deploy manifest (decide during 0c/0d):** `deploy_ftp.php` only uploads changed files by diffing against `deploy_manifest.json`, which lives in the site dir — deleted every run for ephemeral builds. So redeploys would re-upload everything. Persist the manifest per-domain under `sites/{master}/multisite/manifests/{domain}.json` (gitignored with the rest of `multisite/`) so incremental deploys survive across runs. FTP is the slowest step, so this matters.

---

### Phase 1 — Params intake
**Goal:** accept and validate the per-site parameter rows.

- **CSV upload** (admin grid deferred — see Decisions); store as `sites/{master_id}/multisite/params.csv`
- Validation: required fields, domain format/uniqueness
- **FTP pre-flight check** (§5 step 0): `ftp_connect` + `ftp_login` each row, no upload; report bad creds before any build. Reuses connection logic from `deploy_ftp.php`. Skippable on reruns.

**Deliverable:** upload a table of N rows; see them parsed, validated, listed, and FTP-checked.

---

### Phase 2 — AI content steps + cache
**Goal:** generate the city-specific copy and cache it, with the §6a safety rules baked in.

- Add the stable `id` field to the master's AI-targeted blocks (§6a rule 1); verify against the regression baseline that rendered output is unchanged.
- Write the AI generation steps (`city_market_intro`, `feature_columns_local`, `hero_subtext`, `faq_additions`) that call the Claude API using the prompt registry, keyed by stable block ID.
- Cache results to `multisite/cache/{domain}.json`, each entry stamped with its prompt hash (§6a rule 2). Read-through + self-healing on rebuild — missing → generate, stale (hash mismatch) → regenerate, orphaned → ignore (§6a rule 3). `--refresh` regenerates wholesale.
- **Structural variation (Tier 1 — lives here, not in the cosmetic phase).** Rotate block order/selection per site so the network doesn't share one identical block sequence. This is a content-footprint signal (§11 Tier 1) and matters far more than the visual differentiation in Phase 5, so it belongs with the content work. Must be deterministic per domain (same site → same structure across rebuilds, for SEO stability).

**Deliverable:** generate cached copy for one site; confirm (a) a rebuild reuses it with zero API calls and identical output, and (b) editing one AI block's prompt regenerates only that block on the next rebuild while every other field is reused untouched.

---

### Phase 3 — The orchestrator
**Goal:** run the full pipeline across all rows.

- Per row: resolve cache → clone to temp → inject → build → deploy → clean up
- **Sequential execution** to start (easier to log, reason about, and retry). `process_row()` is a self-contained unit so a parallel pool can wrap it later without restructuring.

**Deliverable:** run the full table → N live sites deployed, no JSON persisted in `sites/`.

---

### Phase 4 — Batch observability
**Goal:** know exactly what ran, what it cost, and what failed.

- Per-site status: generated / built / deployed / failed
- Cost + token tracking per run
- Retry on FTP / API failure
- Run log

**Deliverable:** generate 100+ sites in one batch with full visibility and safe retry.

---

### Phase 5 — Per-site differentiation & uniqueness (visual / identity)
**Goal:** make each generated site a genuinely distinct entity rather than a clone with the same favicon, theme, and images. **Sequencing:** runs *after* Phase 3 (the one-site pipeline works) and *before* the full 100-site go-live (Phase 4's deliverable); Phase 4's observability harness can be built in parallel. The point of building it before mass deploy is that every site is a clone of the master, so without this step they all inherit the *same* favicon/theme/images — exactly the template-farm footprint §11 warns about.

Two kinds of work, very different in cost:

- **Field overrides (cheap — the render layer already supports these).** These ride on the pipeline's existing "inject identity + copy" step (§5 step 3) — just write different values into the cloned site's data before build. No render-layer changes:
  - **Theme/color** → override `data['theme']` (read by `theme_css_vars()`), derived from a domain-seeded palette.
  - **Favicon** → set `header['favicon']` (rendered by `site-template.php` if non-empty) to a per-site file.
  - **OG image / hero image** → point the image fields at per-site files.
- **Asset generation (genuinely new code):**
  - Auto-wordmark logo from `business` (for rows with a blank `logo` column).
  - Derive a favicon from the logo.
  - The domain-seeded color-palette algorithm.
  - Source/assign a pool of per-city images.

**Build in impact order, not by what's flashiest (§11 is explicit that theme/color is the *least* impactful "despite being the most tempting"):**
1. **High value, mostly cheap field-work (Tier 2/4):** LocalBusiness JSON-LD with real geo (`lat`/`lng`), self-canonical per domain, per-site `analytics_id` isolation, per-site logo + favicon + og-image, strip generator fingerprints.
2. **Per-site image assignment (Tier 3):** needs an image pool/source.
3. **Domain-seeded theme/color (Tier 3):** do this **last** — lowest impact.

> Structural variation (rotating block order) is Tier 1, not cosmetic — it lives in Phase 2 with the content work, not here.

**Deliverable:** the same row generates a site whose favicon, theme, images, geo schema, and analytics ID are all distinct from other sites and deterministic per domain across rebuilds.

---

## 8. What Is Manual vs. Automatic

| Manual (per batch) | Automatic (per site) |
|---|---|
| Build/maintain the master site | Clone master |
| Fill the params table (identity + FTP) | Inject identity + AI copy |
| Trigger the run | Generate + cache AI copy |
| Review output for first few sites | Build static HTML |
| DNS / hosting setup per domain | FTP deploy |

---

## 9. What Is Never Automated

- Master site structure, design, and prompt tuning (done once)
- Real factual data — phone numbers, addresses, pricing, course dates (supplied in the params table; never invented)
- Domain registration, DNS, and hosting account creation per site
- Real testimonials (must be genuine — never AI-generated)
- Quality review of generated output (recommended for the first several sites)

---

## 10. Decisions

Resolved 2026-06-30:

| Question | Decision |
|---|---|
| Params intake UI | **CSV upload first.** Admin grid deferred as a later convenience for spot-fixing rows. |
| Execution model | **One fresh PHP process per site** (§3). Forced by `config.php`'s immutable `define()` path constants — a single process can build only one site. Worker reads identity from an env var; reuses ~100% of existing render/build/deploy code. In-process `$ctx` refactor rejected as far more complex and risky. |
| Incremental-deploy manifest | **Persist per-domain** under `sites/{master}/multisite/manifests/{domain}.json` (gitignored). Ephemeral build dirs are deleted each run, so the manifest must live outside them or every redeploy re-uploads everything. |
| Orchestration | **Sequential first**, with `process_row()` (= one worker process) self-contained so parallelism can wrap it later. |
| Where campaign data lives | **Under the owning master:** `sites/{master_id}/multisite/` (params + cache + runs). Not a global dir. Cloning a master does not carry it. |
| FTP reachability pre-check | **Yes** — a cheap opt-out-able pre-flight before any build (§5 step 0, Phase 1). |
| Temp build ID | **Derived** by slugifying the domain (`pmtraining-dallas.com` → `pmtraining-dallas_com`). Not a supplied column. |
| Credentials | Plaintext FTP creds in `params.csv`, mirroring existing `deploy.json` workflow. Gitignored; `multisite/` must also not be web-served. |
| AI cache in git | **Not tracked.** The whole `sites/*/multisite/` dir is gitignored (fail-safe — no file inside can leak creds). Cache is preserved on disk / via out-of-band backup, not git. |

### Still to settle later
- Admin grid for row spot-fixing (post-MVP)
- Limited-parallel deploys (post-MVP, if sequential wall-clock is too slow)
- Web-serving protection for `sites/*/multisite/` (ensure `.htaccess` / server config blocks it, as it holds creds)

---

## 11. Uniqueness & SEO

### The core risk (read this first)

Generating 100+ sites for the same business and topic, differing only by city, is the exact pattern two of Google's spam policies target:

- **Doorway pages** — "multiple sites or pages created to rank for similar queries, funneling users toward a single destination."
- **Scaled content abuse** — "templated content where the primary difference between pages is a swapped keyword or location."

Cosmetic differences (new colors, a different stock photo) do **not** satisfy these. Google evaluates substance. Uniqueness is not decoration applied to the sites — it is whether each site is a **genuinely distinct, useful local entity**.

Two scenarios determine the risk:

- **A) Real local presence per city** — each site is a real (or franchise/partner) business with a real local address, phone, and ideally a Google Business Profile. Uniqueness is natural and legitimate; the generator just scales the plumbing. **This is the defensible path** and the one the params table (real per-site NAP) is built for.
- **B) One business blanketing local search with city sites** — this is the doorway pattern. It can work short-term but carries real deindexing risk that grows with footprint similarity.

This document does not decide which scenario applies — but the generator should be built to maximize genuine distinctness regardless, because that is what both protects against penalties and actually serves users.

### Uniqueness signals, tiered by weight

**Tier 1 — Content (the overwhelming majority of what matters)**
- AI copy must be *substantively* different per city — real local employers, industries, landmarks, regulations, pricing, neighborhoods — not one phrase swapped. The AI cache (§6) must produce this, and output should be spot-reviewed for the first several sites.
- **Structural variation** — identical block order + identical headings across all sites is a detectable footprint even with unique words. The generator should rotate block order/selection per site.
- Unique, non-formulaic `<title>` + meta description per page.

**Tier 2 — Identity & structured data**
- **LocalBusiness JSON-LD** per site with real NAP + geo coordinates (`lat`/`lng`) + opening hours. Strong "distinct entity" signal.
- Self-canonical to the site's own domain.
- Distinct domains (already core to the design).

**Tier 3 — Visual / brand (real but secondary)**
- **Per-site logo** — a shared logo across 100 "different businesses" is a giveaway. Supplied via the `logo` column, or auto-generated as a wordmark from `business`.
- **Distinct images** — duplicate hero/section photos are detectable by image hashing; assign different (ideally city-relevant) images per site via a pipeline step.
- **Theme/color variation** — low direct SEO weight, but stops the network looking like a template farm to a human reviewer. Derived from a domain-seeded palette; no manual input.
- Favicon + og-image **per site**. Correction: the static build does **not** generate these. `site-template.php` *renders* them from stored fields (`header['favicon']`, `seo['og_image']` — the latter falls back to the hero photo), so by default a clone inherits the master's. Differentiating them = overriding those fields per site (cheap) plus optionally generating the asset from the logo (new work). Built in Phase 5.

**Tier 4 — Technical footprint (where site networks usually get caught, regardless of content)**
- **Never reuse one Google Analytics / Tag Manager / AdSense ID** across sites — a shared property ID directly links them as one network. Unique per site (`analytics_id`) or none.
- **Hosting/IP diversity** — 100 sites on one IP is a footprint. Per-site FTP hosts already point toward separate hosting.
- **WHOIS privacy** on all domains; identical registrant details are linkable.
- **Strip generator fingerprints** — no identical `<meta name="generator">` or telltale identical HTML comments across sites.
- **No obvious hub-and-spoke cross-linking** between the sites.

### What this adds to the generator

| Uniqueness item | Mechanism | Source |
|---|---|---|
| Geo coordinates | LocalBusiness schema | `lat`, `lng` params columns |
| Per-site logo | header/favicon/og-image | `logo` column, or auto-wordmark from `business` |
| Per-site images | pipeline assignment step | per-city image pool / source |
| Theme/color | domain-seeded palette | derived (no input) |
| Analytics isolation | per-site tag | `analytics_id` column (or blank) |
| Structural variation | rotate block order/selection | generator logic |

**Effort priority:** Tier 1 (content quality) and Tier 2 (structured data) are where the needle actually moves. Tier 3 color-swapping is the *least* impactful despite being the most tempting first move. Tier 4 is cheap insurance against network-level detection.
