# Family → Default Blocks

A per-family **happy-path default**: a recommended, smaller-than-the-full-pool block selection an
agency gets out of the box when creating a page, swappable from the family's real pool the same way
any pick always has been. Started 2026-11-26, one family at a time — reviewed here as each one gets
worked through, not all at once.

## Why this exists (separate from `docs/pluscities-family-niche-blocks.md`)

That doc defines what each family's pool *is*. This doc defines what a page should actually *ship
with by default* — a real gap found while reviewing home-trades: the pickable pool's "choose up to
N" cap had never been revisited after 3 city blocks and 2 service blocks were promoted to always-on
(2026-11-25/26 work). For home-trades specifically, the remaining pickable city pool is exactly 6
items and the cap is also 6 — meaning there's no actual choice happening; every page already
includes all 6 remaining pool blocks whether an agency picks anything or not. Lowering the cap alone
doesn't fix this, because most agencies never touch the picker — they accept whatever's pre-checked.
**The default selection, not the cap, is what determines real-world page composition.**

**Design principle — a ranked preference list, not a fixed set.** A happy path that's one static
trio used verbatim on every page would just recreate the same problem one level down (3 pool blocks
quietly becoming pseudo-always-on, identical page to page). Instead, each family below gets a
**ranked preference list** across its pool, and the actual per-page selection should draw from the
top of that list with **deterministic variance keyed per city** (reusing the exact mechanism Safety
Toolkit already uses — a crc32 hash picking 3 of 5 items per client domain, proven in production) —
real pages differ from each other while still skewing toward the blocks that are genuinely most
valuable. This hasn't been implemented in code yet; this doc is the design reference for when it is.

**Target page shape, established while reviewing home-trades, applies to every family:**
- ~12-16 real content blocks (spine + always-on + happy-path picks), not the full pool
- ~1,200-1,800 words of real unique AI-written prose (excludes furniture — charts/maps/tables/reviews
  aren't competing for the same uniqueness scrutiny as prose)
- Furniture (automatic, data-gated, not a pick) adds page weight but not prose-duplication risk

## Home-trades — ✅ reviewed, 2026-11-26

**City pool ranking** (pool of 6 after always-on subtraction; pick target 3):
1. `reviews_from_city` — real client reviews, inherently unique, highest trust value
2. `hazards` — directly ties the service to a real local reason to need it
3. `seasonal_guide` — maps to real trade demand cycles (freeze-thaw, summer heat, storm season)
4. `permits` — valuable but narrower (matters most for install/electrical-type work)
5. `utilities` — lower direct customer relevance
6. `hoa_rules` — situational, only bites for HOA-heavy markets

**Service pool ranking** (pool of 7 after always-on subtraction; pick target 3):
1. `why_choose_us` — client-grounded (AI-written from the client's own profile facts), unique by
   construction
2. `cost_factors` — client-grounded, unique by construction, highest search-intent match
3. `cited_expert_guidance` — independently AI-generated per page, adds E-E-A-T
4. `repair_vs_replace` — genuinely useful for HVAC/roofing-type decisions, situational by trade
5. `maintenance` — same tier as above
6. `niche_data_table` — valuable but **shared verbatim across every client and city in the niche** —
   deliberately kept pool-only, not a happy-path default, specifically because of that duplicate-
   content exposure (the uniqueness validator only compares a client's own pages against each other,
   never cross-client, so nothing else catches this)
7. `before_the_tech_arrives` — confirmed lowest real-world value (picked on just 1 of 21 real pages
   in the sample that drove the always-on promotions)

**Full render sequence** (spine + always-on + furniture insertion points, as the engine actually
builds it — furniture positions confirmed against `docs/pluscities-system-reference.md` §2.3a's
render-loop table):

```
hero
  → trust_bar (furniture)
overview
[city: housing_stock, neighborhoods, climate_data (always-on) + happy-path picks]
  → Housing Snapshot (furniture, right after housing_stock)
  → Climate Chart(s) (furniture, right after climate_data)
[service: common_problems, process (always-on) + happy-path picks]
  → cta_band (furniture, right before faq)
faq
service_area
  → City Info (furniture)
  → Area Map (furniture)
  → Safety Toolkit (furniture, always last)
```

**Totals:** 4 spine + 5 always-on + 6 happy-path picks = 15 content blocks, plus 7 furniture pieces.
Target real prose: ~1,200-1,800 words.

**Status:** design locked 2026-11-26. Not yet implemented in code — no `happy_path_city_blocks`/
`happy_path_service_blocks` field exists on `niche_families/home-trades.json` yet, and the
deterministic-variance picker mechanism described above hasn't been built. This is the reference for
when that work happens.

## Legal — ⏳ not yet reviewed

## Professional-services — ⏳ not yet reviewed

## Medical-wellness — ⏳ not yet reviewed
