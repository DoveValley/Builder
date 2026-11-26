# Building a Niche Blueprint

What a "niche blueprint" actually is, field by field, how it's built, and how it fits into the
Niche Library → client → page chain. Written 2026-11-26, prompted by planning the "Lawyer" niche
(the real legal niche named in `docs/pluscities-niche-roadmap.md`) — that worked example is used
throughout below because it surfaces real judgment calls a home-trades niche never did.

## What it is

A niche blueprint is one real file, `niche_blueprints/{niche_id}.json`, authored through the Niche
Library admin screen (`admin/pluscities_niches.php`, "Niche Blueprints" card — platform-owner only).
It's the middle layer of a 3-level hierarchy:

```
FAMILY (hand-authored, fixed — home-trades/legal/medical-wellness/professional-services)
  sets the baseline every niche in it inherits: block pool, always-on blocks,
  happy-path default picks, compliance preset, approved sources, radius
         │ inherits + overrides
         ▼
NICHE BLUEPRINT  ◄── this doc is about building one of these
  adds what's actually niche-specific: real services with real validated
  keywords, a denylist against the family's pool, a shared reference table,
  research topics, trust-bar items, photo slots
         │ pluscities_niche_resolved($niche_id) — the ONE merge function
         ▼
CLIENT  →  PAGE (one per city × service)
```

Only 3 real niches exist today: `hvac`, `water-damage-restoration` (both `home-trades`), and
`dental-practice` (`medical-wellness`). `legal` and `professional-services` are real, resolvable
families with **zero** niches built in them yet — building "Lawyer" would be the first niche either
family has ever actually had.

## What's inherited for free — zero new work

Everything the family already defines gets inherited automatically the moment
`pluscities_niche_resolved()` runs — no niche-level configuration needed for any of this:
- The block pool (`default_city_blocks`/`default_service_blocks`)
- Always-on blocks (never pickable, auto-included on every page)
- Happy-path default picks (pre-checked "Recommended" blocks — shipped 2026-11-26, see "Family to
  Default Blocks")
- Compliance preset (unless overridden — see below)
- Approved sources, default radius

This is why `hvac` and `water-damage-restoration` were cheap to build: both inherit an already-proven
`home-trades` family shape. `legal` has never had this inheritance actually exercised by a real
niche, so building "Lawyer" is the first real test of whether that family's shape (inherited straight
from home-trades' structure during the 2026-11-26 consolidation) actually fits a law firm.

## The real fields that need authoring (`pluscities_niche_defaults()`, `includes/pluscities_niche_library.php`)

| Field | What it is | For "Lawyer," concretely |
|---|---|---|
| `schema_subtype` | The real schema.org type for JSON-LD | `Attorney` or `LegalService`, not generic `LocalBusiness` |
| `service_noun` / `local_angle` / `seasonal_calendar` | Shared facts used across prompts/content | e.g. "legal representation," a real local-authority angle, real filing-deadline seasonal patterns |
| `services[]` | The keyword backbone — `{slug, label, primary_keyword, primary_keyword_data:{volume,kd,cpc,score}, secondary_keywords}` per service, validated once via `pluscities_validate_service_keyword()` against real Ahrefs/DataForSEO data | The 15 practice areas already planned in the roadmap doc (personal injury, DUI, criminal defense, family law, divorce, traffic tickets, real estate, estate planning, bankruptcy, immigration, workers' comp, employment law, business/corporate, civil litigation, tax) |
| `blocks_off[]` / `service_blocks_off[]` | Denylist trimming this niche's pool below the family default | Family already drops contractor-specific blocks; this niche may want to trim further once real content proves something doesn't fit |
| `niche_table` | ONE shared reference table, same content on every page in the niche | **The hardest field for Lawyer specifically** — home-trades' table worked because every service was mechanically similar (repair cost ranges, timelines). Personal injury, bankruptcy, and tax law don't share a table that means anything across all 15 — may need to be much thinner than other niches', or rethought entirely |
| `research_topics` / `custom_research_fields` | Which family research topics this niche actually uses, plus any niche-specific research questions | |
| `trust_bar_items[]` | Real trust/verification copy shown in the trust bar furniture | Law-firm-appropriate trust signals, not a home-trades license badge |
| `image_slots[]` | One stock photo per slot, shared by every client in the niche until they upload their own | Needs genuinely different concepts than home-trades (no "technician in a van") — attorney/office-type imagery |
| `safety_toolkit_items[]` | 5 trust/verification org cards (3 shown per client, deterministic) | **Questionable fit** — this is a home-trades-shaped concept ("Homeowner Safety Toolkit"); doesn't obviously map to "find a lawyer" at all |
| `climate_chart_ids[]` | Which real NOAA chart types render as furniture | `legal` inherited `climate_data` as an always-on block straight from home-trades — is weather data genuinely relevant to a law firm page, or is this an inherited mismatch nobody's checked yet (same class of issue already found and fixed for `medical-wellness`)? |
| `compliance_preset_override` | Override the family's compliance preset | `legal` currently points at the `contractor` preset (banned phrases/validator rules built for home-trades). A real law firm needs real, different rules — attorney-advertising disclaimers, no-guarantee-of-outcome language — this probably needs a genuinely new preset, not a reused one |
| `radius_minutes_override` | Override the family's default service radius | |
| `blog_topics[]` / `match_keywords[]` | Auto-blog topic pool; LPC-only industry auto-match | |

## How it's actually built (the real sequence, same for every niche)

1. **Author the blueprint** through the Niche Blueprints admin form — `niche_id`, `family_id`,
   `schema_subtype`, and every field above. Saved via `pluscities_niche_save()` (atomic write,
   deep-merged against defaults — never hand-edit the JSON directly).
2. **Validate each service's keyword** — `pluscities_validate_service_keyword()`, one real API call
   per service against a real provider (Ahrefs/DataForSEO), writes `primary_keyword_data` with real
   volume/KD/CPC/score. This is what makes `services[]` real instead of guessed.
3. **Verify the resolved shape** — `pluscities_niche_resolved($niche_id)` should return the expected
   `city_blocks`/`service_blocks`/`always_*`/`happy_path_*` with nothing surprising, before any real
   client is created against it.
4. **Build one real client** in the niche (through the real New Client wizard, not hand-authored) to
   prove the whole chain actually produces a sensible page — this is where mismatches like the ones
   flagged above for Lawyer (climate chart, safety toolkit, niche_table) actually surface, the same
   way `medical-wellness`'s `housing_stock`/`climate_data` mismatches were found by direct review, not
   by inspecting the schema alone.

## How it fits into the client and the page

A client (`pluscities_clients/{client_id}.json`) just points at one `niche_id` — nothing about the
client record itself encodes family/niche-level block configuration; that's deliberate (the Niche
Library owns 100% of it, so changing a niche's shape automatically reshapes every client in it, not
just new ones created after the change). A page is one city × one service from the niche's
`services[]`, built via `pluscities_create_page()`, which reads the fully-resolved niche (spine +
always-on + the agency's picks, now pre-filled from happy-path) and the niche's own `niche_table`/
`image_slots`/etc. directly. Building the blueprint well is what determines whether every single page
in the niche — current and future, one client or a hundred — actually makes sense, since nothing
downstream re-decides any of this per page.
