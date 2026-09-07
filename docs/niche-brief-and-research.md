# Niche Brief, Research, and Charts

**Read this before configuring a new niche's AI content or per-city research.** This is the
layer that decides *what a niche's sites say and know*, distinct from the mechanical
templates/keyword/deploy pipeline in `docs/landing-page-build-process-V1-20260709.md` and the
single-client-site build process in `docs/site-building.md`. All three are real, current, and
cover different layers — see "How this fits with the other docs" at the bottom.

## The core file: `niche_brief.json`

Each niche is one master site (`sites/{master_id}/`). Its vocabulary and AI configuration live
in `sites/{master_id}/multisite/niche_brief.json`, edited on the **Niche Brief & Research**
admin tab (`admin/tabs/niche_brief.php`, saved by `admin/niche_brief_save.php`).

| Field | What it does |
|---|---|
| `niche` | **Not just a label.** Slugified and must exactly match a folder name under `plugins/image-data-chart/niches/{slug}/`. Reword it later and every chart for this niche silently disappears — no error. |
| `business_descriptor`, `service_noun`, `customer_noun`, `offerings[]`, `local_angle`, `tone` | Prose vocabulary substituted into AI prompts (`{business_descriptor}`, `{service_noun}`, etc.). |
| `guardrails` | Free text appended to every prompt's shared accuracy rules. The only place regulated-niche language (advertising/licensing rules) lives — nothing validates it. |
| `uses_research_fields` | Gates the entire per-city research step (see below). Off = pure AI-from-geography, no per-city fact lookup. Archetypes marked `requires_research` are skipped when this is off. |
| `research_prompt` | The per-city research question set. **Replaces** the generic default entirely if non-blank — it is not additive, so a custom prompt must still ask for whatever base fields your archetypes expect. |
| `custom_research_fields[]` | `{key, ask}` pairs — niche-specific facts to research that aren't tied to any chart. See "Three ways to add a research field" below. |
| `enabled_archetypes[]` | Which shared content-block archetypes (from `multisite/ai/archetypes.json`) this niche uses. Per-master wording overrides live in `admin/tabs/niche_brief_archetypes.php`, stored as diffs in `sites/{id}/multisite/archetypes.json`. |

**Compiling:** saving the brief always recompiles it into `sites/{id}/ai_block_types.json` (via
`multisite/ai/compile.php`, `ms_ai_compile_master()`) — the two can never drift apart from a
normal save. But an edit to the **shared** `multisite/ai/archetypes.json` library does not
auto-propagate to every niche; each master needs a manual "Compile Registry Now" click on its
own Niche Brief tab to pick it up.

## Three ways to add a research field — pick the right one

| Need | Mechanism | Scope |
|---|---|---|
| A fact only this niche needs, never charted | `custom_research_fields` on the brief | This niche only |
| A fact that will be drawn as a chart image | A chart definition, `plugins/image-data-chart/niches/{slug}/*.json`, with a `research.ask` | This niche only |
| A fact every niche with a given plugin needs (e.g. Area Map's surrounding towns) | The plugin's own `research.json` | **Every niche** with that plugin installed — not niche-scoped |

All three funnel into `generate.py`'s `research_fields()`, which merges them into one list in a
uniform `(key, source_key, ask, source_ask, min_items)` shape — so top-up, decline-tracking, and
prompt assembly treat all three identically regardless of source. Field names in
`custom_research_fields` must match what your archetypes actually reference in their prompts —
nothing validates a mismatch, it just silently never resolves.

## How research actually runs

Triggered from the **Landing Cities tab** (solo site) or the **Batch tab's "Research cities"**
sub-section (multisite) — both call the same engine, `generate.py --research-only`
(`run_research_step()`), and behave identically:

- **Self-deciding by default.** A city never researched gets a full pass. An already-researched
  city is only re-asked for fields that are genuinely missing or "thin" (below a chart's
  declared `min_items`) — never blindly redone. A field the model has returned empty on twice is
  left alone permanently (no repeat billing chasing data that doesn't exist for that city).
- **A top-up only merges what it was asked to fill** — the model re-answers the whole prompt
  regardless, but only the previously-missing keys get written back, so an unrelated top-up can
  never silently overwrite already-good data with a fresh, worse roll.
- **A chart figure with no declared source is discarded.** A shorter re-ask can't shrink an
  existing longer list — the longer one always wins.
- **Force** (a checkbox next to the research button, both places) bypasses the two "don't
  bother" gates above and treats the call as a fresh first-time pass for every matched city —
  use it after rewriting the research prompt, or when facts on file are believed stale/wrong. It
  does **not** bypass the no-source / shorter-can't-win safety checks.
- **Dry run** previews the prompt and cost with zero API calls — combine with Force to preview
  what a full forced pass would ask.
- Geocoding (city lat/lng from OpenStreetMap) always runs first, for every niche, regardless of
  `uses_research_fields` — the schema needs coordinates either way.
- Research is stored once, persistently, in the master's own `sites/{id}/data/cities.json` — never
  per ephemeral multisite clone. A domain built off that master reuses it for free; see
  `ms_merge_research_into_landing()` for how a per-domain worker's scoped city list still carries
  the master's already-paid-for research forward.

**Neighborhood verification — wired in.** `sync_osm_neighborhoods()` runs automatically right
after every `--research` pass: OpenStreetMap confirms whatever it has for free, and anything left
over gets a second Claude pass asking not "list neighborhoods" (a question the model answers
badly) but "can you describe what this specific place actually is" (a question it answers well) —
same two-source design as [[feedback_names_must_be_describable]]. Self-deciding like everything
else here: a city already verified (`neighborhoods_source` set) is skipped for free on every later
run. Fixed two real bugs while wiring it up: the function had never actually been called from
anywhere despite being fully written, and its own "already verified, skip" check compared against
a value (`'OpenStreetMap'`) the function never actually writes (it writes `'OpenStreetMap +
verified'` or `'verified'`) — so once wired up as-is, it would have silently re-verified (and
re-billed) every city on every single run, forever. Verified live: correctly expanded a real
city's list from 9 names to 15 (found 6 more via OSM), and a second run correctly skipped with
zero cost. A population threshold (Landing Cities tab, default 14,000) still independently gates
whether verified neighborhood names actually render on a page at all — that gate and this
verification are complementary, not substitutes for each other.

## Charts (optional)

`plugins/image-data-chart/niches/{slug}/*.json`, one file per chart. A niche folder that doesn't
exist means no charts for that niche — a valid, fully supported state, not an error (confirmed by
the Gen-Chart panel's own empty-state message). Each definition can declare a `research.ask`,
optional `source_key`/`source_ask`/`benchmarks`/`min_items`, and a `groups[]` so a page can ask
for a topic ("weather") and get whichever chart in that group this domain's hash picks — see
`ms_variant()` — reproducible per rebuild, not random.

## How this fits with the other docs

- **`docs/site-building.md`** — a different, separate track: hand-building ONE bespoke,
  single-tenant client site (its own business, its own domain, no city-cloning). Doesn't use
  `niche_brief.json`, research, or charts at all.
- **`docs/landing-page-build-process-V1-20260709.md`** — the mechanical layer for a niche's page
  set: keyword map → master template → bulk-generate → images → cross-links → schema → Pass
  A/Pass B → deploy. Predates this system and doesn't mention it — read both, they're
  complementary, not competing: that doc gets pages built and structured; this one decides what
  those pages' AI content actually knows about each city.
- **The "New Niche/Site" admin tab** (`?tab=new_niche`) is the operational checklist that ties
  both docs together into one ordered sequence for standing up a brand-new niche end to end.
