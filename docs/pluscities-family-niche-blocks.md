# Family → Niche Blocks

How the Niche Library decides what's on a page, and exactly where that decision stops being the
Niche Library's job and starts being a client's. Written 2026-11-26, verified directly against
the real code and the real family/niche files on disk — not the original design spec.

## What a family is

A **family** is a fixed, hand-authored grouping of niches that share one block template —
same always-on blocks, same pickable pool, same compliance preset. A family exists so that a new
niche can be onboarded by pointing at an already-proven template instead of designing one from
scratch. There are **4**, defined in `niche_families/*.json`; none are user-creatable from the
admin panel today — `PLUSCITIES_FAMILY_IDS` is a hardcoded PHP constant
(`includes/pluscities_niche_library.php`), so adding, removing, or merging a family is a code
change, not an admin-panel action. `niche_families/*.json` itself carries no description field
yet — the definitions below are written from what each family's real block configuration implies,
not copied from an existing field:

| Family | What it's for | Real niches in it today |
|---|---|---|
| **home-trades** | Residential trade/contractor services performed at the customer's property — repair, install, maintenance, *or* emergency/disaster-response (water, fire, mold, storm damage), *or* exterior property services (pressure washing, lawn/landscape). All three used to be separate families (`home-trades`, `restoration`, `outdoor-cleaning`) until the 2026-11-26 consolidation found they were byte-identical in every field that matters — see "The consolidation" below. | `hvac`, `water-damage-restoration` |
| **legal** | Attorneys / law firms serving a local area. Keeps the housing/neighborhood/climate always-on set (a firm's local-authority content still benefits from it) but drops the service-side blocks that only make sense for physical repair work (`repair_vs_replace`, `maintenance`, `before_the_tech_arrives`). Not yet used by a live niche — kept as its own family on the bet that a real law-firm niche will need genuinely different content from professional-services once built, not because the two configs differ today. | *(none yet)* |
| **professional-services** | Local professional/consultative services that aren't medical or legal (accounting, financial advising, and similar) — same trimmed service-side pool as `legal`. Not yet used by a live niche. | *(none yet)* |
| **medical-wellness** | Licensed medical/dental/wellness practices. The one family that's actually different in shape, not just in compliance text: no climate chart (a dental practice has no reason to show NOAA weather data), a much shorter always-on/pickable pool, and the `medical` compliance preset instead of `contractor`. | `dental-practice` |

`legal` and `professional-services` have a real, resolvable block configuration today but no live
niche exercising it yet, and are currently byte-identical to each other — see "The real data"
below.

## The consolidation (2026-11-26): 6 families → 4

Scott flagged that `restoration` looked a lot like `home-trades`, and `professional-services` a
lot like `legal`. A field-by-field diff of the raw family JSON confirmed it, but the two pairs
turned out to be different *kinds* of duplicate:

- **`home-trades` / `restoration` / `outdoor-cleaning` were true duplication, merged.**
  `outdoor-cleaning` was byte-identical to `home-trades` (only `label`/`family_id` differed).
  `restoration` differed by exactly two fields: `default_radius_minutes` (45 vs 25) and
  `schema_type_family` (`HomeAndConstructionBusiness` vs `LocalBusiness`) — and that second field
  turned out to be dead code, resolved into `pluscities_niche_resolved()`'s output but never read
  by any renderer (the real JSON-LD `@type` comes from the niche-level `schema_subtype` field
  instead, which `water-damage-restoration.json` already sets correctly). The radius difference is
  a one-number override already supported per-niche (`radius_minutes_override`). Neither family had
  a principled reason to stay separate, and `outdoor-cleaning` never had a real niche in it at all.
- **`legal` / `professional-services` were judged NOT the same kind of duplicate, kept separate.**
  They're configured identically today, but for a different reason: neither has a real niche built
  in it yet, so nothing has forced either to diverge. A real law-firm niche (case results, practice
  areas, bar-admission disclaimers) plausibly needs different content from a real
  accounting/consulting niche (services offered, certifications, fiduciary language) — unlike
  home-trades/restoration, there's a real business-shape argument for keeping these apart, just not
  yet evidence either way.

Mechanically: `water-damage-restoration.json`'s `family_id` was repointed from `restoration` to
`home-trades` (via the real `pluscities_niche_save()` path), with `radius_minutes_override` set to
`45` so its effective behavior is unchanged. The two orphaned family files were quarantined to
`niche_families_deleted/`. Verified `pluscities_niche_resolved('water-damage-restoration')` returns
identical `city_blocks`/`service_blocks`/`always_*_blocks` before and after — zero content impact
on the real clients in this niche. See `docs/pluscities-niche-roadmap.md` for the ~50-niche
buildout list this review fed into, prioritized by real agency demand rather than build order.

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

## The real data, as of 2026-11-26 — 4 families

| Family | Always city | Pickable city pool | Always service | Pickable service pool | Compliance preset |
|---|---|---|---|---|---|
| **home-trades** | housing_stock, neighborhoods, climate_data | permits, utilities, hoa_rules, seasonal_guide, hazards, reviews_from_city | common_problems, process | cited_expert_guidance, niche_data_table, cost_factors, why_choose_us, repair_vs_replace, maintenance, before_the_tech_arrives | contractor |
| **legal** | housing_stock, neighborhoods, climate_data | permits, utilities, hoa_rules, seasonal_guide, hazards, reviews_from_city | common_problems, process | cited_expert_guidance, niche_data_table, cost_factors, why_choose_us | contractor |
| **professional-services** | housing_stock, neighborhoods, climate_data | permits, utilities, hoa_rules, seasonal_guide, hazards, reviews_from_city | common_problems, process | cited_expert_guidance, niche_data_table, cost_factors, why_choose_us | contractor |
| **medical-wellness** | neighborhoods | housing_stock, reviews_from_city | common_problems, process | cited_expert_guidance, niche_data_table, cost_factors, why_choose_us, repair_vs_replace, maintenance | medical |

Read directly out of `niche_families/*.json` — not copied from a spec. Two real things worth
noting in this data, not smoothed over:

- **`legal` and `professional-services` are still byte-identical to each other.** Kept as two
  families anyway (see "The consolidation" above) — unlike the home-trades trio, there's a real
  argument these should diverge once a real niche exists in either, just not yet evidence either
  way. `medical-wellness` is the only family that's actually different in shape today: no
  `climate_data`, a much shorter city pool (2 items instead of 6), and the `medical` compliance
  preset instead of `contractor`.
- **`legal` and `professional-services` currently have no real niche in them yet** — their block
  configuration is real and already resolvable, just not exercised by a live niche today.

## The 3 real niches today, and which family owns their blocks

| Niche | Family | What that gets it |
|---|---|---|
| `hvac` | home-trades | Full 9-type pool, climate chart always on, contractor compliance rules |
| `water-damage-restoration` | home-trades | Same as above — repointed here from the old `restoration` family in the 2026-11-26 consolidation, with its 45-minute radius preserved via `radius_minutes_override` |
| `dental-practice` | medical-wellness | The short pool, no climate chart, medical compliance rules |

A niche can only narrow what its family offers — `hvac`'s blueprint could deny `hazards` from its
pickable pool if that stopped being useful, but it could never add a block type `medical-wellness`
doesn't have. The family is the ceiling; the niche is a denylist under that ceiling.

## How it all lines up, end to end

```
 NICHE LIBRARY  (admin-authored, shared, never per-client)
 ───────────────────────────────────────────────────────────
  FAMILY (1 of 4 fixed, hand-authored, not user-creatable)
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
