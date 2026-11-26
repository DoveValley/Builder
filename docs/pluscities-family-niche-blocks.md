# Family → Niche Blocks

How the Niche Library decides what's on a page, and exactly where that decision stops being the
Niche Library's job and starts being a client's. Written 2026-11-26, verified directly against
the real code and the real family/niche files on disk — not the original design spec.

## The short version

A client never defines its own block set. A client points at a niche (`niche_id`); a niche
belongs to exactly one family (`family_id`); the family is where the real block configuration
lives. Everything below is **100% Niche Library** — `niche_families/*.json` +
`niche_blueprints/*.json`, edited through the admin panel's Niche Library screens. A client
record (`pluscities_clients/*.json`) carries zero fields for any of this — confirmed by reading
a real one (`meade-restoration.json`): just a `niche_id` pointer, plus purely client-specific
data (licenses, hours, reviews, photos, branding, `block_styles` for visual overrides only).

## The 4 layers

Every page is built from four layers, and a given block type lives in exactly one of them —
never two.

1. **Spine** — fixed for every page, every family, no exceptions: `hero`, `overview`, `faq`,
   `service_area`. Not family-specific at all; this doesn't come from the Niche Library.
2. **Always-on blocks** — the owning family guarantees these on every page of that family. Not a
   checkbox, not skippable, not part of the pickable pool. Lives in each family's
   `always_city_blocks` / `always_service_blocks`.
3. **Pickable pool** — what's left after subtracting the always-ons. An agency chooses from this
   list, up to a cap, when building a real service-area page. Lives in each family's
   `default_city_blocks` / `default_service_blocks`, minus its own always-on list, minus
   whatever that specific niche further denies (`blocks_off` / `service_blocks_off` — a niche can
   only narrow its family's pool, never add to it).
4. **Furniture** — rendered unconditionally, outside the pick system entirely (Safety Toolkit,
   Area Map, Housing/Climate charts). Gated on whether the niche carries the underlying data for
   it (e.g. `climate_chart_ids`), not on family membership or a page-builder's checkbox.

The real merge happens in `pluscities_niche_resolved()`
(`includes/pluscities_niche_library.php:389`):

```php
$alwaysCityBlocks = $family['always_city_blocks'];
$cityBlocks = array_values(array_diff($family['default_city_blocks'], $niche['blocks_off'], $alwaysCityBlocks));
// same pattern for service blocks
```

Always-on blocks get merged back in — AFTER validation, not before — when a real page is created
(`pluscities_create_page()`, `includes/pluscities_lifecycle.php:217-218`), so a page-builder's
submitted picks are checked only against the pickable pool, and the always-ons can never be
wrongly rejected as "not in the pool" (they were deliberately subtracted out of it one step
earlier).

## The real data, as of 2026-11-26 — 6 families

| Family | Always city | Pickable city pool | Always service | Pickable service pool | Compliance preset |
|---|---|---|---|---|---|
| **home-trades** | housing_stock, neighborhoods, climate_data | permits, utilities, hoa_rules, seasonal_guide, hazards, reviews_from_city | common_problems, process | cited_expert_guidance, niche_data_table, cost_factors, why_choose_us, repair_vs_replace, maintenance, before_the_tech_arrives | contractor |
| **restoration** | housing_stock, neighborhoods, climate_data | permits, utilities, hoa_rules, seasonal_guide, hazards, reviews_from_city | common_problems, process | cited_expert_guidance, niche_data_table, cost_factors, why_choose_us, repair_vs_replace, maintenance, before_the_tech_arrives | contractor |
| **outdoor-cleaning** | housing_stock, neighborhoods, climate_data | permits, utilities, hoa_rules, seasonal_guide, hazards, reviews_from_city | common_problems, process | cited_expert_guidance, niche_data_table, cost_factors, why_choose_us, repair_vs_replace, maintenance, before_the_tech_arrives | contractor |
| **legal** | housing_stock, neighborhoods, climate_data | permits, utilities, hoa_rules, seasonal_guide, hazards, reviews_from_city | common_problems, process | cited_expert_guidance, niche_data_table, cost_factors, why_choose_us | contractor |
| **professional-services** | housing_stock, neighborhoods, climate_data | permits, utilities, hoa_rules, seasonal_guide, hazards, reviews_from_city | common_problems, process | cited_expert_guidance, niche_data_table, cost_factors, why_choose_us | contractor |
| **medical-wellness** | neighborhoods | housing_stock, reviews_from_city | common_problems, process | cited_expert_guidance, niche_data_table, cost_factors, why_choose_us, repair_vs_replace, maintenance | medical |

Read directly out of `niche_families/*.json` — not copied from a spec. Two real things worth
noting in this data, not smoothed over:

- **5 of the 6 families currently share one identical city/service template.** Only
  `medical-wellness` is actually different — no `climate_data` (a dental practice has no reason
  to show NOAA weather data), a much shorter city pool (2 items instead of 6), and a different
  compliance preset (`medical` instead of `contractor`). `legal` and `professional-services` also
  differ from the other three on the service side — no `repair_vs_replace` / `maintenance` /
  `before_the_tech_arrives`, since those don't make sense for a law firm or consultancy.
- **`legal`, `outdoor-cleaning`, and `professional-services` currently have no real niche in them
  yet** (see below) — their block configuration is real and already resolvable, just not
  exercised by a live niche today.

## The 3 real niches today, and which family owns their blocks

| Niche | Family | What that gets it |
|---|---|---|
| `hvac` | home-trades | Full 9-type pool, climate chart always on, contractor compliance rules |
| `water-damage-restoration` | restoration | Same as above — restoration shares home-trades' template |
| `dental-practice` | medical-wellness | The short pool, no climate chart, medical compliance rules |

A niche can only narrow what its family offers — `hvac`'s blueprint could deny `hazards` from its
pickable pool if that stopped being useful, but it could never add a block type `medical-wellness`
doesn't have. The family is the ceiling; the niche is a denylist under that ceiling.

## How it all lines up, end to end

```
 NICHE LIBRARY  (admin-authored, shared, never per-client)
 ───────────────────────────────────────────────────────────
  FAMILY (1 of 6 fixed, hand-authored, not user-creatable)
    always_city_blocks / always_service_blocks   ← guaranteed, never pickable
    default_city_blocks / default_service_blocks ← baseline pool before subtraction
    compliance_preset (default)
         │
         │  inherited by
         ▼
  NICHE  (e.g. "hvac" — belongs to exactly 1 family)
    blocks_off / service_blocks_off  ← can only REMOVE from the family's pool
    compliance_preset_override       ← can only SWAP to a different preset, never invent one
    climate_chart_ids / safety_toolkit_items / image_slots  ← furniture, opt-in per niche
         │
         │  pluscities_niche_resolved() merges family + niche into one real answer:
         │    city_blocks       = family.default_city_blocks − niche.blocks_off − family.always_city_blocks
         │    always_city_blocks = family.always_city_blocks   (unchanged, just passed through)
         │    (same formula for service blocks)
         ▼
 ───────────────────────────────────────────────────────────
  CLIENT  (e.g. "Meade Restoration" — points at 1 niche via niche_id)
    No block configuration of its own. Zero fields for any of the above.
         │
         │  pluscities_create_page() — one real page for one city + one service:
         ▼
  PAGE
    spine            = hero, overview, faq, service_area           (always, no niche involved)
    + always blocks   = niche.always_city_blocks + always_service_blocks   (auto-included)
    + picked blocks   = whatever the agency chose, validated against the pickable pool only
    + furniture       = Safety Toolkit / Area Map / charts, rendered if the niche has the data
```

## Why this split exists (not just how)

Keeping the whole table inside the Niche Library — rather than letting a client override any of
it — is what makes "every client in a niche gets the content that's already proven to work for
that kind of business" true by construction. A page-builder can still get real variety (which
pool items are picked, what the client's own facts/photos/reviews say), but can't accidentally
ship a restoration client a page missing its climate data, or force a dental practice into a
weather chart it has no reason to show. If a block's placement in this table ever needs to
change — promote something to always-on, add a new pickable item, loosen a family's pool — that's
a Niche Library edit in one place, not a per-client fix repeated across every client that niche
has.
