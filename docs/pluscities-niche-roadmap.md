# PlusCities — Niche Buildout Roadmap

Locked 2026-11-26. Ranked by real agency purchase-demand (what a local-SEO/rank-and-rent agency
actually buys repeatedly), not by how cheap a niche is to build. Mapped to the 4-family model from
`docs/pluscities-family-niche-blocks.md`. This is a planning document, not yet reflected in
`niche_blueprints/*.json` — building a row here still means authoring a real niche blueprint
(services, keywords, trust-bar items, niche data table, safety toolkit, image slots) the same way
`hvac`/`water-damage-restoration`/`dental-practice` were.

**Scoped out entirely:** restaurants, retail stores, nurseries, and non-local product companies
(e.g. a national computer manufacturer). PlusCities' whole content model is hub + drive-time radius
+ a page per nearby city — built for service-area businesses (a technician dispatched to the
customer, or a patient driving in from a radius). A single-location storefront business doesn't
have a "service area" in that sense; generating synthetic "[Business] in [Suburb]" pages for 20
nearby towns would be the thin/duplicate-content pattern already flagged as a fleet-wide risk, not
a win. A national product company has no local angle at all — no hub city, no service radius, no
compliance preset that means anything. Building for either is a separate, bigger roadmap item (new
page model, new block vocabulary for menu/inventory/hours-type content), not a Niche Library entry.
The one partial exception worth a future look: nurseries, where `climate_data`/`seasonal_guide`
(planting zones, frost dates) are already-real, non-thin local content — unlike a restaurant or
general retail store.

## Home-trades (by far the largest bucket — this is what agencies buy constantly)

**Tier 1 — build first, highest-volume universal trades:**
1. HVAC — *built* (`hvac`)
2. Plumbing
3. Electrician
4. Roofing
5. Garage door repair
6. Pest control
7. Appliance repair
8. Locksmith
9. Water damage restoration — *built* (`water-damage-restoration`)
10. Mold remediation

**Tier 2 — strong, common demand, build next:**
11. General contractor / remodeling
12. Kitchen & bath remodeling
13. Flooring installation
14. Painting (interior/exterior)
15. Fencing
16. Concrete & paving
17. Window & door replacement
18. Gutters (install/clean)
19. Landscaping & lawn care
20. Tree service
21. Pressure washing
22. Pool service & repair
23. Solar installation
24. Foundation repair / waterproofing

**Tier 3 — real but smaller/more regional demand:**
25. Moving companies
26. Junk removal
27. Carpet cleaning
28. Handyman services
29. Septic tank service
30. Chimney sweep
31. Deck & patio builders
32. Siding installation
33. Snow removal
34. Home security / alarm installation
35. Fire & smoke damage restoration

## Medical-wellness

36. Dental practice — *built* (`dental-practice`)
37. Chiropractic
38. Physical therapy
39. Orthodontics
40. Med spa / aesthetics
41. Veterinary
42. Optometry

## Legal

43. Personal injury attorney
44. Family law attorney
45. DUI / criminal defense attorney
46. Estate planning attorney

## Professional-services

47. Accounting / CPA
48. Financial advisor
49. Insurance agency
50. Real estate agent / team

## Build-first order (not family-by-family)

1. **Plumbing, Electrician, Roofing** — the "big four" with HVAC; every rank-and-rent/local-SEO
   agency already has clients in these. Near-zero marginal build cost since `hvac` already proved
   the home-trades template.
2. **Pest control, Garage door repair, Appliance repair, Locksmith** — same template, same tier of
   universal demand.
3. **Mold remediation** — basically free; `water-damage-restoration` already proved the
   restoration-flavored content works, and it's now the same family as `hvac`.
4. **Dental practice's siblings** (chiropractic, physical therapy) — proves medical-wellness works
   for more than one specialty before leaning on it harder.
5. Everything else in Tier 2/3, medical, legal, professional-services, roughly in the order listed
   above — each tier down is a real but smaller slice of agency demand.

## Why this order, not another

Agencies don't buy niches evenly — the home-trades "big ten" (items 1-10) are the bread-and-butter
verticals every local-SEO/rank-and-rent operation already has clients in, so building those first
maximizes how many existing agency relationships this can be sold into immediately. Medical/legal/
professional-services niches carry real value (especially legal's CPC) but are smaller in volume
and more regulated/litigious — worth having, not worth front-loading ahead of the trades that
already have proven demand and an already-proven template.
