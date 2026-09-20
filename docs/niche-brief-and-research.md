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

**A new archetype's `default_fields` must not double as its own output field names.**
`includes/multisite/ai_cache.php`'s `ms_ai_reapply_current_defaults()` re-merges an archetype's
CURRENT `default_fields` on top of every cache-hit restore, so a structural field correction (e.g.
a wrong `heading_level`) propagates to every domain's cache on its next rebuild without a manual
cache wipe. That's correct for fields that are pure config — but `feature_columns_local`,
`seasonal_calendar`, and `why_choose_us` all list their own AI-authored output keys (`columns`,
`steps_items`, etc.) as empty placeholders in `default_fields`, since that's what an ungenerated
block shows in the admin editor. Reapplying those unconditionally silently blanked real generated
content back to that placeholder on every cache-hit rebuild, and then re-cached the now-empty
value — a real bug, permanent and self-perpetuating until caught. Fixed by threading through the
set of keys the cache restore just wrote and skipping reapply for those — but any *new* archetype
whose `default_fields` shape looks like its own output should be tested with a real
rebuild-after-generation (not just a first generation) before trusting it, since the bug is
invisible on a first generate and only shows up one rebuild later.

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

## Blog posts (optional, domain-level — a separate mechanism from the archetype system above)

`blog_topics[]` on the niche brief — `{slug, title, tag, focus_keyword}` objects, ~25 per niche,
hand-written once per niche (no admin UI yet; edit the JSON directly). Unlike everything else on
this page, blog posts are **not** part of the `enabled_archetypes`/compile/`ai_block_types.json`
pipeline — they don't belong to any city, so there's nothing for `[[brief.*]]`/`{city}` token
resolution to run against. They're generated the same way the one-time footer/tagline/disclaimer
rewords are (`generate.py`'s `reword_disclaimer()` etc.): read `niche_brief.json` directly out of
the clone's own `multisite/` folder (already copied there by `clone.php` for exactly this reason —
see `feedback_clone_must_carry_render_time_files`), build the prompt inline in Python, no compile
step involved.

- **Per-domain, not per-city.** Each *domain* (not each landing page) gets `BLOG_POSTS_PER_DOMAIN`
  (3) posts, picked from the pool with a seed derived from the domain's own site id — reproducible
  on a rebuild, different domain → different pick.
- **Stable across rebuilds via the per-domain AI cache**, same file as everything else in
  `includes/multisite/ai_cache.php` (`sites/{master}/multisite/cache/{domainSlug}.json`), under its
  own `blog_posts` key (`ms_blog_inject_from_cache()` / `ms_blog_extract_to_cache()`). A rebuild
  reinjects existing posts for free and only tops up toward 3 if some are still missing (e.g. a
  prior run's API call returned unparseable JSON — logged and skipped, not fatal; the next rebuild
  just tries the still-missing slot again).
- **Auto-publishes only if it clears an automated quality gate** — word count, a cap on how many
  times the focus keyword can appear, and a banned-phrase list drawn from the same overclaim
  language every niche's `guardrails` already bans (licensed/certified/guaranteed/"our team", etc.)
  — there's no human review step before a batch goes live, so this is what stands in for one. A
  post that fails goes to `status: draft` instead, logged with the specific reason, not silently
  dropped or force-published.
- **Batch panel:** "AI content → Blog posts" checkbox (`ai.blog`), same skip-key convention as the
  reword toggles — unticking passes `--no-blog` to `generate.py`.
- **Model:** Haiku (`MODEL_DEFAULT`), deliberately not `REWRITE_MODEL` (Sonnet) like the reword
  functions above it in the same file — Sonnet's default adaptive thinking routinely burned the
  shared 8000-token cap entirely on "thinking" for a 550+-word creative-writing prompt, leaving an
  empty response (measured: 2 of 3 real calls failed this way). Haiku doesn't hit that tradeoff for
  a prompt this size, and it's cheaper.
- Write new topics evergreen and city-agnostic (no local facts, no neighborhood names) — they're
  shared across every domain in the niche, and picking a topic that needs per-city grounding has
  nothing to ground it against. Keep the `focus_keyword` distinct from your landing-page keyword
  map — an informational blog topic competing with your own transactional landing page for the same
  term is self-cannibalization, not incremental reach.

**Internal linking, `link_candidates[]` on each topic.** Each topic can carry 1+ hand-picked
`{text, url}` candidates (same shape `related_links`'s `rl_items` already uses — see
`docs/content-blocks.md`), pointing at the service page that topic is most related to. The AI
never sees or chooses a URL — `generate_blog_posts()` just appends a `related_links` block onto
the post's `content_blocks` verbatim, unresolved, and the **existing** `related_links` render
path (`plugins/related_links/plugin.php`'s `related_links_resolve()`) checks each candidate
against this domain's actual built pages at render time, same as it already does for a hand-typed
`related_links` block anywhere else on the site. No new resolution code — that's what keeps this
reliable: a candidate Page Pool didn't build for this domain just doesn't show, never a guess and
never a 404. Give at least 2 candidates per topic where you can (the block needs 2 real matches to
render anything at all) — a plausible always-built page (the homepage `/`, or a core page like
About Us) as one of them is a reasonable safety net so the section isn't hidden just because Page
Pool skipped the one specific service page you'd have preferred.

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
