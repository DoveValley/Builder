# Family → Default Blocks

A per-family **happy-path default**: a recommended, smaller-than-the-full-pool block selection an
agency gets out of the box when creating a page, swappable from the family's real pool the same way
any pick always has been. Started 2026-11-26, one family at a time — reviewed here as each one gets
worked through, not all at once.

## FINAL DECISION — locked default vs. optional blocks per family (2026-11-26)

**Status: LOCKED, ready for implementation.** This is the one section to build against — everything
below it in this doc is the rationale/history that produced these numbers, kept for context, not a
second source of truth. "Default" means ships on every new page automatically (spine + always-on +
the pre-selected happy-path picks); "Optional" means still in the family's pool and swappable in, just
not pre-checked. Word targets are prose only (spine + always-on + default picks) and exclude furniture
(charts/maps/tables aren't competing for the same uniqueness scrutiny as written prose — see "Why this
exists" below).

### Home-trades
- **Target word count:** ~1,200–1,800 words
- **Total blocks:** 15 (4 spine + 5 always-on + 6 default picks) + 7 furniture pieces
- **DEFAULT** — spine: `hero`, `overview`, `faq`, `service_area` · always-on: `housing_stock`,
  `neighborhoods`, `climate_data`, `common_problems`, `process` · picks: `reviews_from_city`,
  `hazards`, `seasonal_guide` (city) + `why_choose_us`, `cost_factors`, `cited_expert_guidance`
  (service)
- **OPTIONAL** (pool, not pre-selected): `permits`, `utilities`, `hoa_rules` (city) + `repair_vs_replace`,
  `maintenance`, `niche_data_table`, `before_the_tech_arrives` (service)

### Legal
- **Target word count:** ~1,200–1,800 words
- **Total blocks:** 15 (4 spine + 5 always-on + 6 default picks) + 7 furniture pieces
- **DEFAULT** — spine: `hero`, `overview`, `faq`, `service_area` · always-on: `housing_stock`,
  `neighborhoods`, `climate_data`, `common_problems`, `process` · picks: `reviews_from_city`,
  `seasonal_guide`, `hazards` (city) + `why_choose_us`, `cited_expert_guidance`, `cost_factors`
  (service)
- **OPTIONAL** (pool, not pre-selected): `permits`, `utilities`, `hoa_rules` (city, flagged `pool⚠` —
  no real fit against any of the 4 niches actually on the roadmap) + `niche_data_table` (service)
- **Confidence note:** no live niche yet — lower-confidence than home-trades, revisit once personal
  injury (or any of the other 3 planned legal niches) is built. Locking it now doesn't block
  implementation; it just means this family's picks are the first to re-check once real page data
  exists.

### Professional-services
- **Target word count:** ~1,200–1,800 words
- **Total blocks:** 15 (4 spine + 5 always-on + 6 default picks) + 7 furniture pieces
- **DEFAULT** — identical structure to Legal (byte-identical block config): spine: `hero`, `overview`,
  `faq`, `service_area` · always-on: `housing_stock`, `neighborhoods`, `climate_data`,
  `common_problems`, `process` · picks: `reviews_from_city`, `seasonal_guide`, `hazards` (city) +
  `why_choose_us`, `cited_expert_guidance`, `cost_factors` (service)
- **OPTIONAL** (pool, not pre-selected): `permits`, `utilities`, `hoa_rules` (city, `pool⚠`) +
  `niche_data_table` (service)
- **Confidence note:** same as Legal — no live niche yet.

### Medical-wellness
- **Target word count:** ~900–1,300 words — scaled down from the other 3 families' 1,200–1,800
  target in proportion to this family's smaller default block count (11 vs. 15), not a separate guess
- **Total blocks:** 11 (4 spine + 3 always-on + 4 default picks) + 5 furniture pieces (no Housing
  Snapshot/Climate Chart by default — this family never carries `climate_data`)
- **DEFAULT** — spine: `hero`, `overview`, `faq`, `service_area` · always-on: `neighborhoods`,
  `common_problems`, `process` · picks: `reviews_from_city` (city) + `why_choose_us`,
  `cited_expert_guidance`, `cost_factors` (service)
- **OPTIONAL** (pool, not pre-selected): `housing_stock` (city — deliberately excluded from default,
  not just unranked: demoted from always-on this same week for lacking real content tie-in to a
  medical-office visit) + `maintenance`, `repair_vs_replace`, `niche_data_table` (service)

**What implementation needs, to turn this into working code:** a `happy_path_city_blocks[]` /
`happy_path_service_blocks[]` field on each `niche_families/*.json` holding exactly the DEFAULT pick
lists above (always-on and spine are already real fields/hardcoded — only the picks are new), plus the
deterministic per-city variance picker described in "Design principle" below so real pages don't all
draw the identical 3-of-N picks verbatim. Nothing above requires a pool or always-on change — those
stay exactly as they are in `niche_families/*.json` today.

## Matrix — every block × every family, at a glance

Status as of 2026-11-26. Not yet implemented in code — this reflects the design/ranking work in this
doc, not a real `happy_path_*_blocks` field (none exists yet).

**Legend:** `ALWAYS` = auto-included, never pickable · `PICK` = this doc's happy-path default ·
`pool` = in the family's pickable pool (per `niche_families/*.json`), available but not a happy-path
default · `pool⚠` = technically in the pool per the JSON blueprint, but this review found it doesn't
really fit any niche currently on the roadmap for this family — dead weight today, a candidate for a
future niche-level `blocks_off` trim once a real niche exists (see Legal's "Follow-up finding" below)
· `—` = not in this family's pool at all, excluded at the family level because it genuinely doesn't
apply to this kind of business.

| CITY BLOCKS | Home-Trades | Legal | Prof-Services | Medical-Wellness |
|---|---|---|---|---|
| `climate_data` | ALWAYS | ALWAYS | ALWAYS | — |
| `housing_stock` | ALWAYS | ALWAYS | ALWAYS | pool (demoted — see Medical-wellness section) |
| `neighborhoods` | ALWAYS | ALWAYS | ALWAYS | ALWAYS |
| `reviews_from_city` | PICK | PICK | PICK | PICK |
| `seasonal_guide` | PICK | PICK | PICK | — |
| `hazards` | PICK | PICK | PICK | — |
| `permits` | pool | pool⚠ | pool⚠ | — |
| `hoa_rules` | pool | pool⚠ | pool⚠ | — |
| `utilities` | pool | pool⚠ | pool⚠ | — |

| SERVICE BLOCKS | Home-Trades | Legal | Prof-Services | Medical-Wellness |
|---|---|---|---|---|
| `common_problems` | ALWAYS | ALWAYS | ALWAYS | ALWAYS |
| `process` | ALWAYS | ALWAYS | ALWAYS | ALWAYS |
| `why_choose_us` | PICK | PICK | PICK | PICK |
| `cited_expert_guidance` | PICK | PICK | PICK | PICK |
| `cost_factors` | PICK | PICK | PICK | PICK |
| `maintenance` | pool | — | — | pool |
| `repair_vs_replace` | pool | — | — | pool |
| `niche_data_table` | pool (dup-risk) | pool (dup-risk) | pool (dup-risk) | pool (dup-risk) |
| `before_the_tech_arrives` | pool (weakest — 1/21 live-page picks) | — | — | — |

**What jumps out:** `legal`/`professional-services` carry 3 `pool⚠` cells each (`permits`/`hoa_rules`/
`utilities`) — still technically in their pool per the JSON, but this pass found none of them has a
real fit against any niche actually planned for that family (see Legal's section below). `medical-
wellness` is the most trimmed family overall — 6 of 9 city blocks and 1 of 9 service blocks are
excluded outright, not just unranked, because they describe home-visit/field-service concepts
(hazards, permits, utilities, hoa_rules, seasonal trade cycles, a technician arriving) that don't
describe an office-visit business at all. `niche_data_table` is `pool`-only in every family for the
same reason: it's shared verbatim across every client/city in a niche, a deliberate duplicate-content
guard, not an oversight.

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

## Legal — ✅ reviewed, 2026-11-26

**Caveat up front, unlike home-trades:** home-trades' ranking was grounded in 21 real live pages'
pick-frequency data. `legal` has **no live niche built yet** (per `docs/pluscities-family-niche-
blocks.md`), so there's no real page to measure against — the ranking below is a generic logical
best-guess for "some law firm," not an empirical one, and should be revisited once a real legal niche
(personal injury, real estate, estate planning, etc.) gets built and produces real pages. Flagging this
honestly rather than presenting it with the same confidence as home-trades' data-backed picks.

**Follow-up finding, second pass:** the ranking below originally justified `hoa_rules`/`permits` with
real-estate/HOA-dispute examples — but checking against `docs/pluscities-niche-roadmap.md`'s actual
planned legal niches (personal injury attorney, family law attorney, DUI/criminal defense attorney,
estate planning attorney), **none of them is real-estate law.** Re-checked against the real 4:
`hoa_rules`/`permits`/`utilities` have no genuine fit with any of them — marked `pool⚠` in the matrix
above, not just low-ranked. `seasonal_guide` turns out to be the most broadly-applicable pick across
all 4 real niches (personal injury's weather-correlated accidents, family law's custody-schedule
timing around the school year/holidays, DUI's holiday-enforcement spikes, estate planning's year-end
tax timing) — a stronger pick than it first looked. `hazards` stays the best available #3: strong for
personal injury (the first legal niche listed on the roadmap) even though it has no fit for the other
3. The real fix here is a **niche-level**, not family-level, one: once personal injury (or any of the
4) gets built, that niche's own `blocks_off[]` should denylist whichever of
`hoa_rules`/`permits`/`utilities`/`hazards` don't apply to it — the same mechanism
`niche_families/*.json` already uses for family-level exclusions, just applied one level down. A
single family-wide ranking is a structurally weaker fit for `legal` than for `home-trades`, because
home-trades niches (HVAC, plumbing, roofing...) share a genuinely similar relevance pattern with each
other in a way personal injury / family law / DUI / estate planning do not — `legal` may be the one
family where the happy path eventually needs to live at the niche level, not the family level.

**City pool ranking** (pool of 6 after always-on subtraction, identical pool to home-trades — `legal`
doesn't trim the city side, only the service side; pick target 3):
1. `reviews_from_city` — universal trust signal, arguably even higher-stakes for legal than for a
   repair trade (attorney selection is a high-trust, high-stakes decision, and reviews are how most
   people shop for one)
2. `seasonal_guide` — the one city block with plausible relevance across *most* legal practice areas
   (tax deadlines, open-enrollment benefits timing, year-end estate planning, back-to-school custody
   schedule changes) rather than tying to just one
3. `hazards` — strong fit for personal injury specifically (one of the most common, highest-value real
   law-firm niches), weak-to-irrelevant for others — ranked here as a bet on which niche gets built
   first, not a universal fit
4. `hoa_rules` — strong fit for real-estate/HOA-dispute practices, same niche-dependency caveat as
   `hazards`
5. `permits` — real-estate/land-use relevance, narrower than `hoa_rules`
6. `utilities` — weakest general tie-in (eminent-domain/utility-law edge case only)

**Service pool ranking** (pool of 4 after always-on subtraction — smaller than home-trades' 7 because
`legal` already drops `repair_vs_replace`/`maintenance`/`before_the_tech_arrives` at the family level;
pick target 3, which leaves exactly 1 excluded):
1. `why_choose_us` — client-grounded, unique by construction, same reasoning as home-trades
2. `cited_expert_guidance` — E-E-A-T value is higher here than for home-trades: legal advice is
   explicitly YMYL ("Your Money or Your Life") content under Google's own quality guidelines, which
   weight E-E-A-T more heavily than for a repair-trade page
3. `cost_factors` — client-grounded, high search-intent match ("how much does a lawyer cost," "attorney
   fees for X")
4. `niche_data_table` — excluded from the happy path for the same reason as home-trades: shared
   verbatim across every client and city in the niche, deliberately kept pool-only to avoid duplicate-
   content exposure

**Full render sequence** (same spine/always-on/furniture positions as home-trades — furniture
placement is an engine-level loop, not family-configurable, per `docs/pluscities-system-reference.md`
§2.3a; `legal` carries `climate_data` in its always-on set, so Housing Snapshot and Climate Chart both
render here too):

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

**Totals:** 4 spine + 5 always-on + 6 happy-path picks = 15 content blocks, plus 7 furniture pieces —
same totals as home-trades, since both families share the same pool/pick shape (6 city, pick 3;
service pool size differs but pick target is still 3). Target real prose: ~1,200-1,800 words.

**Status:** design locked 2026-11-26, **lower-confidence than home-trades** per the caveat above — no
`happy_path_city_blocks`/`happy_path_service_blocks` field exists on `niche_families/legal.json` yet,
same as home-trades. Revisit this ranking (not just implement it as-is) once a real legal niche exists.

## Professional-services — ✅ reviewed, 2026-11-26

Block config is byte-identical to `legal` (confirmed in `docs/pluscities-family-niche-blocks.md`'s
family-consolidation review — both are untested stubs, kept as two families on the bet that a real
niche in each will eventually diverge). Same caveat as `legal` applies: no live niche yet, so this is
a generic best-guess ranking, not data-backed.

**City pool ranking** — identical pool to `legal`, reasoning reframed for accounting/financial-
advising/consulting businesses instead of law firms:
1. `reviews_from_city` — same universal trust logic, high-stakes purchase decision
2. `seasonal_guide` — the single strongest fit in this family: tax season is the dominant demand cycle
   for the most likely first professional-services niche (accounting/tax prep), a stronger fit than it
   had for `legal`
3. `hazards` — weak fit generically (no obvious professional-services niche ties to physical hazards
   the way personal-injury law does) — kept at the same rank as `legal` only for consistency; flagged
   as the weakest of the three picks here, first thing to re-rank once a real niche exists
4. `hoa_rules` — niche-dependent (property-management consulting)
5. `permits` — niche-dependent (business-licensing consultants)
6. `utilities` — weakest tie-in

Pick target 3: `reviews_from_city`, `seasonal_guide`, `hazards` — pick #3 is the lowest-confidence
single choice of any family's happy path in this doc; expect `permits` or `hoa_rules` to replace it
depending which real niche gets built first.

**Service pool ranking** — identical pool and reasoning to `legal` (same 4 items, byte-identical
config): `why_choose_us`, `cited_expert_guidance`, `cost_factors` as the happy-path picks,
`niche_data_table` pool-only for the same duplicate-content reason. Financial/tax advice is also YMYL
content, so `cited_expert_guidance`'s E-E-A-T value carries over from `legal`'s reasoning unchanged.

**Render sequence / totals:** identical structure to `legal` and `home-trades` (furniture positions are
engine-level, not family-specific) — 4 spine + 5 always-on + 6 happy-path picks = 15 content blocks,
plus 7 furniture pieces.

**Status:** design locked 2026-11-26, same no-live-niche caveat as `legal`.

## Medical-wellness — ✅ reviewed, 2026-11-26

Different shape from the other 3 families — a much smaller city pool (3 types total vs. 9), and the
only family where `housing_stock` is NOT in the always-on set (demoted back to pickable 2026-11-26
after a direct content-fit review found no real tie-in between Census home-value stats and a dental
visit — see `docs/pluscities-system-reference.md` §2.3a). Has one real niche in progress
(`dental-practice`, created 2026-11-23) but it's a stub with no real pages yet either — same
no-empirical-data caveat as `legal`/`professional-services` applies here too, though the
`housing_stock` finding above is itself real signal from a direct review, not a guess.

**City pool ranking** (pool of only 2 after always-on subtraction — `neighborhoods` is the only
always-on city block for this family; pick target **1, not 3** — a pool of 2 can't support a 3-pick
happy path without just including everything, which would recreate the exact "cap equals pool size,
no real choice" problem this doc exists to fix):
1. `reviews_from_city` — same universal trust logic, arguably the single highest-value block in this
   entire family given how review-driven patients already are when choosing a medical/dental provider
2. `housing_stock` — **deliberately left out of the happy path, not just ranked low**: it was demoted
   from always-on to pickable this same week specifically because direct review found no real
   content tie-in to a medical-office visit. Promoting it straight back into the happy-path default
   would quietly undo that finding one layer down — the exact failure mode this doc's "Why this
   exists" section describes. Left pool-only, available if an agency wants it, not pre-selected.

**Service pool ranking** (pool of 6 after always-on subtraction — the biggest practical difference
from home-trades: `before_the_tech_arrives` is the *only* block this family drops at the family level,
so `repair_vs_replace`/`maintenance` both stay, unlike `legal`/`professional-services` which drop all
3; pick target 3):
1. `why_choose_us` — client-grounded, same reasoning as every other family
2. `cited_expert_guidance` — highest E-E-A-T value of any family here: medical/health content is the
   textbook YMYL category under Google's own quality guidelines, more so than legal or financial advice
3. `cost_factors` — high search-intent match ("cost of a dental cleaning," "how much does an implant
   cost")
4. `maintenance` — just below the top 3: fits well generically (ongoing checkups/preventive care
   applies to nearly any medical-wellness niche) but home-trades' own top-3 didn't include its
   `maintenance` either, kept consistent
5. `repair_vs_replace` — fits some medical-wellness niches well (dental: repair a tooth vs. replace it
   with an implant; orthopedic: repair a joint vs. replace it) but not others (a wellness/mental-health
   practice has no equivalent) — niche-dependent, same caveat as the niche-dependent `legal` picks
6. `niche_data_table` — pool-only, same duplicate-content reasoning as every other family

**Render sequence:** same engine-level furniture positions as the other 3 families, **except no
Climate Chart** — this family never carried `climate_data` in its city pool at all (per §2.3a: "not
medical-wellness — its family never had climate_data to begin with"), so that insertion point never
fires here. Housing Snapshot furniture also won't fire by default since `housing_stock` isn't a
happy-path pick — furniture keys off an actual city-block *pick*, not family membership, so it would
still render if an agency manually added `housing_stock` from the pool.

```
hero
  → trust_bar (furniture)
overview
[city: neighborhoods (always-on) + reviews_from_city (happy-path pick)]
  (no Housing Snapshot / Climate Chart furniture by default — see above)
[service: common_problems, process (always-on) + happy-path picks]
  → cta_band (furniture, right before faq)
faq
service_area
  → City Info (furniture)
  → Area Map (furniture)
  → Safety Toolkit (furniture, always last)
```

**Totals:** 4 spine + 3 always-on (1 city + 2 service, vs. the other 3 families' 5) + 4 happy-path
picks (1 city + 3 service) = 11 content blocks, plus 5 furniture pieces (no Housing Snapshot/Climate
Chart by default). Noticeably lighter than the other 3 families' 15-block/7-furniture shape — worth
revisiting once `dental-practice` gets real pages: this family may warrant its own target word/block
count rather than the shared ~12-16 block / ~1,200-1,800-word target set at the top of this doc for
the other 3.

**Status:** design locked 2026-11-26, lowest-confidence of the 4 families — smallest pool, no
empirical data, and a genuinely different shape from the other 3 rather than a smaller copy of the
same pattern.

---

**All four families now reviewed.** Next real step is implementation (the `happy_path_*_blocks`
fields on each `niche_families/*.json` + the deterministic per-city variance picker described in
"Design principle" above), not further design.
