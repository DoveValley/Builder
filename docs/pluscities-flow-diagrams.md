# PlusCities — Flow Diagrams

Two views of the same system, meant to be read together. The **Component Architecture** diagram
answers "what exists and what does it contain" — read it when you need to know where a given setting
or piece of content actually lives. The **Step-by-Step Flow** diagram answers "what happens, in order,
when a real page gets built" — read it when you need to trace how a change at the top (a family's
config) actually reaches a live page at the bottom.

Every box below names the real file/function it corresponds to, so either diagram can be traced
straight into the code — nothing here is simplified past the point of being checkable. For the full
prose explanation of any piece (why it's shaped this way, what bugs were found building it), see
`docs/pluscities-system-reference.md` §2.3/§2.3a — that doc is the ground truth; these diagrams are a
map onto it, not a replacement for it.

---

## Diagram 1 — Component Architecture

*What each layer of the niche library contains, and how one layer's output becomes the next layer's
input.*

```
┌──────────────────────────────────────────────────────────────────────┐
│  NICHE FAMILY (4, hand-authored — home-trades, legal,                 │
│  medical-wellness, professional-services; consolidated 2026-11-26     │
│  from 6 — outdoor-cleaning/restoration were byte-identical to         │
│  home-trades, see pluscities-family-niche-blocks.md)                  │
│  niche_families/{family_id}.json                                      │
│                                                                        │
│  Sets the BASELINE every niche in this family inherits:                │
│    default_city_blocks[]  ──┐                                         │
│    always_city_blocks[]     │  subtracted from each other by          │
│    default_service_blocks[] │  pluscities_niche_resolved() so a        │
│    always_service_blocks[] ─┘  block is either always-on or pickable  │
│    happy_path_city_blocks[] · happy_path_service_blocks[] — a ranked   │
│      pre-selected subset of the pool (added 2026-11-26); "Optional"    │
│      and "Excluded" are never stored, both derived at display time     │
│    compliance_preset ───────────► COMPLIANCE PRESET (banned phrases,   │
│                                    validator rule toggles)             │
│    approved_sources[] · default_radius_minutes · research_topics[]    │
└────────────────────────────────┬──────────────────────────────────────┘
                                  │ inherits + overrides
                                  ▼
┌──────────────────────────────────────────────────────────────────────┐
│  NICHE BLUEPRINT (3 real: hvac, water-damage-restoration,             │
│  dental-practice)                                                      │
│  niche_blueprints/{niche_id}.json                                     │
│                                                                        │
│  Denylists against the family baseline:                               │
│    blocks_off[]  ·  service_blocks_off[]                              │
│                                                                        │
│  NICHE-WIDE shared content (same for every service/city in the niche): │
│    niche_table · climate_chart_ids[] · safety_toolkit_items[] ·       │
│    trust_bar_items[] · image_slots[]                                  │
│                                                                        │
│  SERVICES — the keyword backbone:                                      │
│    services[] = [{ slug, label, primary_keyword,                      │
│                     primary_keyword_data:{volume,kd,cpc,score},        │
│                     secondary_keywords[] }, …]                        │
│    validated once per niche via infra_kw_score()                      │
│    (the same module the fleet's own domain decisions use)             │
└────────────────────────────────┬──────────────────────────────────────┘
                                  │ pluscities_niche_resolved($niche_id)
                                  │ — the ONE merge function; everything
                                  │   else reads THIS, never raw files
                                  ▼
┌──────────────────────────────────────────────────────────────────────┐
│  CLIENT picks: niche + city + ONE service (from niche.services[])     │
│  pluscities_clients/{client_id}.json                                  │
│                                                                        │
│  primary_keyword + city name → the page's actual target:              │
│    "ac repair" + "Frisco, TX" → "Professional AC Repair in Frisco, TX" │
└────────────────────────────────┬──────────────────────────────────────┘
                                  │ pluscities_create_page()
                                  ▼
┌──────────────────────────────────────────────────────────────────────┐
│  PAGE — 4 block categories, in render order                           │
│  1. SPINE (hardcoded, every niche): hero → overview → … → faq →       │
│     service_area                                                       │
│  2. ALWAYS-BLOCKS (per-family, not pickable): housing_stock,           │
│     neighborhoods, [climate_data] · common_problems, process           │
│  3. POOL (optional, capped, per-family): permits, utilities, …         │
│     cost_factors, why_choose_us, … — shown to the agency split into     │
│     Recommended (the family's happy-path picks, pre-checked) and       │
│     Optional (everything else), not one flat list (added 2026-11-26)  │
│  4. FURNITURE (unconditional, keyed city_id[+niche_id], not a pick):   │
│     trust_bar → City Info + Area Map → Housing Chart → Climate         │
│     Chart(s) → … → safety_toolkit                                      │
│  PLUS: niche_data_table renders niche.niche_table — same table on      │
│     every service's pages in that niche (the thing the 2026-11-26      │
│     mismatch sweep fixed — see §2.3a)                                  │
└──────────────────────────────────────────────────────────────────────┘
```

---

## Diagram 2 — Step-by-Step Flow

*The same components, ordered as "what happens, in sequence" when a real page gets created — each
step names the real function/file that does the work.*

```
STEP 1 — NICHE FAMILY (business-type baseline, hand-authored)
  niche_families/{family_id}.json
  → default_city_blocks[], always_city_blocks[]
  → default_service_blocks[], always_service_blocks[]
  → compliance_preset, approved_sources[], default_radius_minutes
                              │
                              ▼
STEP 2 — NICHE BLUEPRINT (inherits the family, adds what's niche-specific)
  niche_blueprints/{niche_id}.json
  → blocks_off[] / service_blocks_off[]        (denylist against the family's pools)
  → services[]                                  (slug, label, PRIMARY_KEYWORD, kd/volume/score)
  → niche_table, climate_chart_ids[],
    safety_toolkit_items[], trust_bar_items[]    (shared across every service/city in this niche)
                              │
                              ▼
STEP 3 — pluscities_niche_resolved($niche_id)        ◄── the ONE merge point
  includes/pluscities_niche_library.php
  → city_blocks[] = family.default_city_blocks − niche.blocks_off − family.always_city_blocks
  → service_blocks[] = family.default_service_blocks − niche.service_blocks_off − family.always_service_blocks
  → happy_path_city_blocks[]/happy_path_service_blocks[] = family's happy-path lists, CLIPPED to only
    what's still actually in city_blocks[]/service_blocks[] above (a niche's denylist can remove a
    family's happy-path pick — it just disappears from "recommended" too, same as any other pool item)
  → everything from Steps 1-2, flattened into ONE array every caller below reads
                              │
                              ▼
STEP 4 — CLIENT declares: this niche + a TARGET CITY + ONE service from niche.services[]
  e.g. niche=hvac, city_id=frisco-tx, service_key=ac-repair
                              │
                              ▼
STEP 5 — KEYWORD RESOLUTION
  niche.services[ac-repair].primary_keyword ("ac repair")
  + city_id's real label ("Frisco, TX")
  → the page's actual target: "Professional AC Repair in Frisco, TX"
    (becomes the hero/overview headline + the SEO title, via the writer)
                              │
                              ▼
STEP 6 — pluscities_create_page($client_id, $city_id, $service_key, …)
  includes/pluscities_lifecycle.php
  3 real callers as of 2026-11-26: Add Page (1 page), the wizard's Single-page mode (1 page), and
  the wizard's Multiple-pages mode — includes/pluscities_bulk_pages_cli.php, a DETACHED background
  job looping every city x service combo, each one calling this SAME function with happy_path_*_
  blocks[] (no inline picker in bulk mode) — see docs/pluscities-system-reference.md §3.2a
  assembles the page's block list, IN ORDER:
    a) SPINE (hardcoded, every niche)        hero, overview … faq, service_area
    b) ALWAYS-BLOCKS merged in               niche.always_city_blocks + niche.always_service_blocks
    c) POOL picks (agency-chosen, validated  against niche.city_blocks[] / niche.service_blocks[];
                                              the picker pre-checks niche.happy_path_*_blocks[] —
                                              admin/pluscities_page_add.php's and pluscities_clients.
                                              php's "Recommended" group — still freely swappable)
    d) FURNITURE triggered (city-level, not  a blocks-table row):
         pluscities_ensure_city_map() · pluscities_ensure_city_info() ·
         pluscities_ensure_climate_chart() · pluscities_ensure_housing_chart()
                              │
                              ▼
STEP 7 — CONTENT GENERATION, per block
  pluscities_regenerate_block() → includes/pluscities_writer.php
  → grounds each block in: client facts (always) + a city-scoped fact card (editable blocks) +
    niche.niche_table (if block_type = niche_data_table, SAME table for every service in the niche) +
    a Census/NOAA dataset (if housing_stock / climate_data)
                              │
                              ▼
STEP 8 — RENDER (production)
  admin/pluscities_sync.php
  → blocks rendered in stored position order, PLUS furniture spliced in at fixed points:
    service_area → City Info + Area Map · housing_stock → Housing Chart ·
    climate_data → Climate Chart(s)
                              │
                              ▼
STEP 9 — LIVE PAGE
  /service-area/frisco-tx/ac-repair/
  — the niche's inherited block shape + this city's furniture + this service's keyword, assembled

  NOTE (added 2026-11-27): this URL shape is the DEFAULT, not the only one — client.slug_pattern
  (a token template: {primary_keyword}/{city}/{state}/{service}) can produce a flat single segment
  (e.g. /service-area/botox-corpus-christi-tx/) or any nested depth instead. Only applies to pages
  created after the pattern is set; a per-page Slug field can also override one page directly. See
  docs/pluscities-system-reference.md §3.5a/§3.5c (the routing change this required — a catch-all
  rewrite rule + lookup-by-slug, not the fixed two-segment parse this diagram's STEP 9/Render still
  describe as the default case). The /service-area/ segment itself is also optional per client
  (prefix_service_pages, §3.5d) — a page can sit at the site root instead, served through a separate
  404-fallback route rather than the reserved-namespace rule above, since the root can't be claimed
  the same exclusive way.
```

---

## Reading both together — a worked example

Take `hvac` → `elite-air-hvac` → `frisco-tx` → `ac-repair`:

1. **Family** (`home-trades`) says: city pool includes `permits/utilities/hoa_rules/seasonal_guide/
   hazards/reviews_from_city`, always-on `housing_stock/neighborhoods/climate_data`; service pool
   includes `cited_expert_guidance/niche_data_table/cost_factors/why_choose_us/repair_vs_replace/
   maintenance/before_the_tech_arrives`, always-on `common_problems/process`.
2. **Niche** (`hvac`) adds nothing to the denylists, declares 2 services (`ac-repair`/
   `furnace-repair`) each with a real validated `primary_keyword`, and its own `niche_table` ("Common
   AC & Furnace Problems") + 6 `climate_chart_ids`.
3. **Resolved** via `pluscities_niche_resolved('hvac')` — one flat object with everything above merged.
4. **Client** `elite-air-hvac` picks this niche, targets `frisco-tx`, and the agency builds an
   `ac-repair` page.
5. **Keyword**: `"ac repair"` + `"Frisco, TX"` → the real page headline.
6. **Page assembly**: spine (hero/overview/faq/service_area) + always-on (housing_stock, neighborhoods,
   climate_data, common_problems, process) + whatever pool blocks the agency picked + furniture
   (Area Map, City Info, Housing Chart, Climate Chart(s), Safety Toolkit) all queued up.
7. **Generation**: each editable block gets AI-written content grounded in Frisco-scoped fact cards;
   `niche_data_table` gets the SAME "Common AC & Furnace Problems" table every `hvac` page gets,
   regardless of city or which of the 2 services.
8. **Render**: `admin/pluscities_sync.php` assembles the final HTML in the real production response.
9. **Live**: `/service-area/frisco-tx/ac-repair/` — a complete, real page.

---

## Panel editability, as of the 5-session walkthrough (2026-11-26)

This diagram describes what moves through the pipeline; this table is about which of those pieces you
can actually reach through the real admin panel today, after a deliberate session-by-session check of
every layer (Business Info build → Niche Blueprint fields → per-page SEO → client delete). Read alongside
the Claude tab's §2.3a/§5.5 for the full detail behind each row.

| Step | What's now panel-editable | What's still hand-JSON only |
|---|---|---|
| 1. Niche Family | Default/always-on city+service pools, compliance preset, approved sources, research topics, radius | — (nothing left) |
| 2. Niche Blueprint | Denylists, services+keywords (incl. real keyword validation), **trust bar items, blog topics, custom research fields, extra approved sources, the shared reference table** (all 5 added this pass) | `climate_chart_ids` (tied to a hardcoded old-system slug mapping), `safety_toolkit_items` (raw SVG icon markup), `radius_minutes_override`, `compliance_preset_override`, `match_keywords` |
| 4. Client | Identity/niche/domain at creation; **licenses, hours, banned phrases, price ranges, reviews-by-city** (all 5 added this pass, via the new Business Info tab) | Multi-hub support (the writer only ever reads `hubs[0]`, by design — not a gap) |
| 6-9. Page/Block | Block pick/swap/lock/regenerate/edit, FAQ per-question editing, **per-page SEO title/meta description** (added this pass) | A per-service version of the shared reference table (one table is shared across every service in a niche — a deliberate cheap-fix choice, not an oversight) |
| Client lifecycle | Create, cancel/reactivate, suspend/unsuspend, **permanently delete with type-to-confirm + quarantine** (rebuilt this pass after it was found missing) | — (nothing left at the client level) |

**Net result:** a brand-new niche can be fully authored, a brand-new client can be fully onboarded, and
a brand-new page can be built, published, and (if needed) the whole client deleted and restored — all
without touching a JSON file by hand. Verified for real, not just asserted: Session 5 built and published
one complete real client this way, with all 12 validator checks passing on the first try.
