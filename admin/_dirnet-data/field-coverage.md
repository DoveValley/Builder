# Field coverage — the facility database behind `/browse`

Measured against the production Supabase database, `niche='recovery' AND active` = **17,827 facilities**,
on 2026-07-30. Read-only queries; nothing was written and no schema was changed.

**Every count below is a distinct-facility count, not a row count.** A facility with three sources saying
"detox" counts once.

---

## Two corrections before the tables

**1. There is no `luxury` flag.** "Luxury 470" is the value `luxury` inside the **`amenities`** category —
`amenities.luxury`, 470 facilities. `price_band` exists but holds nothing called luxury; its five values are
`free-state-funded` (964), `under-10k` (17), `10k-25k` (3), `25k-50k` (2), `over-50k` (1) — 987 facilities
total. So the cross-tab you asked for is against an amenity, not a tier, and the question "of the 470 luxury
facilities, how many have any amenity" is partly tautological: all 470 have one, because `luxury` *is* the
amenity. I answer it below as "any amenity **other than** luxury".

**2. Your numbers come from `nicheData`, and that is the right place to read them.** `detox 6,735` and
`MAT 11,432` match `Listing.nicheData` exactly. The underlying `ListingAttribute` rows give 6,738 and 11,434
— three and two higher. The difference is negation and source precedence, applied when the projection is
built. See §4.

---

## Coverage table

`ANY value` = at least one attribute row in that category, negated or not. `positive` = at least one
surviving positive claim. `pct` is of 17,827.

| field / category | ANY value | pct | positive | distinct values | source (facilities) |
| --- | --- | --- | --- | --- | --- |
| **levels of care** | 17,827 | 100.0% | 17,827 | 18 | SAMHSA import 17,691 · findtreatment.gov 14,985 · scraped 7,732 · recoveryexcellence 236 |
| **clientele** | 17,820 | 100.0% | 17,820 | 17 | SAMHSA 17,686 · findtreatment.gov 10,755 · scraped 5,980 · recoveryexcellence 228 |
| **payment** | 17,751 | 99.6% | 17,749 | 10 | SAMHSA 17,637 · findtreatment.gov 14,877 · scraped 6,694 |
| age groups | 17,721 | 99.4% | 17,721 | 4 | SAMHSA 17,559 · findtreatment.gov 14,979 · scraped 3,103 · recoveryexcellence 31 |
| **therapies** | 17,689 | 99.2% | 17,689 | 18 | SAMHSA 17,550 · findtreatment.gov 14,342 · scraped 4,155 · recoveryexcellence 181 |
| **approaches** | 17,627 | 98.9% | 17,627 | 10 | SAMHSA 17,434 · findtreatment.gov 14,089 · scraped 5,871 · recoveryexcellence 235 |
| conditions | 16,817 | 94.3% | 16,817 | 21 | SAMHSA 16,505 · findtreatment.gov 13,048 · scraped 4,285 · recoveryexcellence 223 |
| **languages** | 15,916 | 89.3% | 15,916 | 24 | findtreatment.gov 14,988 · SAMHSA 8,463 |
| substances | 15,480 | 86.8% | 15,333 | 15 | SAMHSA 15,118 · findtreatment.gov 11,179 · scraped 4,644 · recoveryexcellence 220 |
| **policies** | 14,223 | 79.8% | 14,223 | 7 | findtreatment.gov 14,184 · scraped 127 |
| **gender program** | 12,226 | 68.6% | 12,226 | 4 | SAMHSA 12,000 · findtreatment.gov 5,152 · scraped 732 · recoveryexcellence 11 |
| **accreditations** | 12,167 | 68.3% | 12,167 | 5 | SAMHSA 11,569 · findtreatment.gov 4,915 · scraped 2,109 |
| **insurance** | 11,342 | 63.6% | 11,323 | 31 | SAMHSA 8,333 · scraped 5,448 · centene-qhp 1,760 · recoveryexcellence 233 · elevance-qhp 75 |
| **amenities** | 8,151 | 45.7% | 8,150 | 15 | findtreatment.gov 7,186 · scraped 1,755 · recoveryexcellence 90 |
| **year founded** | 2,993 | 16.8% | 2,993 | 5 | **scraped 100%** |
| **length of stay** | 1,303 | 7.3% | 1,303 | 4 | **scraped 100%** |
| price band | 987 | 5.5% | 987 | 5 | **scraped 100%** |
| **activities** | 554 | 3.1% | 554 | 10 | **scraped 100%** |
| **setting** | 484 | 2.7% | 484 | 10 | **scraped 100%** |
| **bed count** | 449 | 2.5% | 449 | 5 | **scraped 100%** |
| **accessibility** | 319 | 1.8% | 319 | 4 | **scraped 100%** |
| **dietary** | 73 | 0.4% | 73 | 5 | **scraped 100%** |

No category has manual entry, facility self-report, or LLM-derived values: **`claimed` = 0, `tier<>free` = 0,
`description` = 0** across all 17,827. Nothing has been claimed or hand-edited. `verified` = 843, but that is
a verification-queue outcome on core fields, not a source of facet values.

### Source meanings

| source | what it is | accuracy character |
| --- | --- | --- |
| `samhsa-directory` | The 2025 N-SUMHSS National Directory workbooks (XLSX), structured service codes | Structured, self-reported annually by the facility to a federal survey. Lags closures. |
| `findtreatment.gov` | SAMHSA's treatment locator API, structured fields | Same survey lineage, structured. |
| `facility-site` | **Scraped from the facility's own website** by a phrase-matching extractor (`lib/sources/packs/recovery.ts`) | **See the accuracy warning below.** |
| `recoveryexcellence` | A small third-party import, 236 facilities max | Lowest-ranked source; loses every precedence tie. |
| `centene-qhp` / `elevance-qhp` | Payer qualified-health-plan network files | Structured, carrier-authored. Insurance only. |

**No LLM-derived values.** The scraper is a deterministic phrase table, not a model. That is better than a
model for auditability, and it has its own failure mode: it matches phrases in page text.

---

## 1. Is a blank stored as NULL, false, or absent?

**Absent — in every category, without exception.** There is no boolean column and no NULL for any facet
value. Facet data lives entirely in `ListingAttribute` rows keyed `(listingId, kind, value, source)`, and
`Listing.nicheData` is a derived JSONB projection of the winners. A facility that is not luxury simply has no
`amenities/luxury` row.

Consequences, and they are the ones you were probing for:

- **"Luxury 470" means 470 of 17,827 facilities carry the claim.** It does *not* mean 470 of some enriched
  subset were assessed and the rest were assessed as not-luxury. The other 17,357 were never asked.
- **Absence is not a negative.** There is one exception worth knowing: `negated = true` records an explicit
  "this source says NO". There are **2,869 negated rows**, concentrated in `substances` (1,962 facilities),
  `insurance` (213), `payment_options` (174). Negated values are excluded from `nicheData`, so a browse filter
  never returns them. Everywhere else, absence carries no information at all.
- **`nicheData` is never empty:** 0 of 17,827 have `{}`. Every facility has at least levels of care.

---

## 2. Fields under 5% coverage, with denominators

The honest denominator is not 17,827. These categories come only from the website crawl, so the population
that could ever have a value is **12,039 facilities that yielded at least one scraped attribute** (of 15,544
with a website at all).

| category | facilities | of 17,827 | **of 12,039 crawled** |
| --- | --- | --- | --- |
| activities | 554 | 3.1% | **4.6%** |
| setting | 484 | 2.7% | **4.0%** |
| bed count | 449 | 2.5% | **3.7%** |
| accessibility | 319 | 1.8% | **2.6%** |
| dietary | 73 | 0.4% | **0.6%** |

Even against the crawl-reached denominator these stay tiny. The constraint is not crawl coverage — it is that
facility websites rarely state these things in matchable words.

Individual values under 5%, with their category denominator — this is the "Golf: 66" question:

| value | facilities | denominator (any value in its category) | share of that denominator |
| --- | --- | --- | --- |
| `activities/golf` | 66 | 554 | 11.9% |
| `activities/hiking` | 192 | 554 | 34.7% |
| `setting/mountain` | 51 | 484 | 10.5% |
| `setting/beachfront` | 29 | 484 | 6.0% |
| `amenities/luxury` | 470 | 8,151 | 5.8% |
| `amenities/private-chef` | 99 | 8,151 | 1.2% |
| `dietary/kosher` | 19 | 73 | 26.0% |
| `accessibility/wheelchair-accessible` | 25 | 319 | 7.8% |
| `bed_count/beds-over-100` | 155 | 449 | 34.5% |

**So "Golf: 66" is 66 of 17,827 — not 66 of 554.** The facet count is an absolute count of facilities
carrying the claim. 554 is the number that have *any* activity value; the remaining 17,273 were never
assessed for golf. Both framings matter: 66/17,827 is what a user's filter returns, 66/554 is what the number
means as evidence.

---

## 3. Relation to the SAMHSA N-SUMHSS import

**Overlapping, neither a superset nor a subset.** The two universes were built by different processes:

| | facilities |
| --- | --- |
| Active listings in this database | 17,827 |
| …carrying at least one `samhsa-directory` attribute | 17,691 |
| …carrying **no** SAMHSA attribute | **136** |
| …carrying no `findtreatment.gov` attribute | 2,839 |
| Inactive listings (excluded from all counts) | 45 |
| Deduped SAMHSA universe in the density pipeline | 18,796 |

Accounting for **18,796 − 17,827 = 969**:

- **17,691 of the 18,796 matched into this database.** The remaining **~1,105 SAMHSA facilities are not here**
  — they failed the name+address join, or were never loaded.
- **136 active listings have no SAMHSA attribute at all**, so they come from findtreatment.gov or the crawl
  alone. That is the "not a subset" half.
- **45 listings are inactive** and excluded, 13 of them confirmed closures.
- The two dedupes differ. The density pipeline keyed on **normalised street + ZIP** over the raw workbooks,
  dropping 325 PO-box and 9 administrative rows, and keeping 1,329 blank-street rows on a name+ZIP fallback.
  This database's identity is **address + city**, applied across a findtreatment.gov spine.

The practical statement: **this database is a findtreatment.gov-spined directory enriched with SAMHSA
attributes**, not a copy of the SAMHSA Directory. The two counts should not be expected to agree, and the
density pipeline's 18,796 is not the denominator for anything on this page.

---

## 4. Reconciling detox and MAT

Both gaps are real and both have the same cause: **the two pipelines map different source code sets to the
same word.** Neither number is wrong; they answer different questions.

### detox — 6,735 here vs 2,560 in the density pipeline

| | facilities |
| --- | --- |
| `nicheData` detox (what browse filters) | **6,735** |
| Attribute rows, any source | 6,738 |
| …from `samhsa-directory` | 5,706 |
| …from `facility-site` (scraped) | 2,141 |
| …from `findtreatment.gov` | 1,962 |
| Density pipeline, SAMHSA codes `DT`/`RD`/`HID`/`OD` | 2,560 |

The density pipeline used four **codes**. The importer (`lib/sources/samhsa.ts`) matches the **label text**
`'detoxification'` across **four different codebook categories** — Service Setting, Type of Opioid Treatment,
Type of Care, and the Detoxification Services category. That sweeps in codes the density pipeline excluded on
purpose, above all:

- `DLC` **Lofexidine or Clonidine detoxification** — 5,602 facilities in the raw workbook
- `DB` Buprenorphine detoxification — 1,884
- `DM` Methadone detoxification — 428

Those are **medication protocols**, not a level of care. `DLC` alone is more than twice the density
pipeline's entire detox count. So `detox` in this database means "does something the workbook labels
detoxification", which is much broader than "runs a detox programme".

### MAT — 11,432 here vs OTP 1,563

| | facilities |
| --- | --- |
| `nicheData` mat | **11,432** |
| …from `samhsa-directory` | 11,061 |
| …from `findtreatment.gov` | 3,534 |
| …from `facility-site` | 2,496 |
| Density pipeline, SAMHSA `OTP` | 1,563 |

**These are not the same claim and should not reconcile.** `OTP` is one code: *Federally-certified Opioid
Treatment Program* — a specific federal certification. `mat` is mapped from **five categories**, including
`UB` "prescribes buprenorphine" (7,202 raw), `NU` "naltrexone used in treatment" (8,047), and the alcohol
medications `ACM` acamprosate (4,297) and `DSF` disulfiram (4,313). A facility that prescribes naltrexone for
alcohol use disorder is `mat` here and is not an OTP anywhere.

**Which field populates what a user sees: `Listing.nicheData`.** `/browse` filters with a JSONB containment
test (`nicheData @> '{"levels_of_care":["detox"]}'`) against the GIN index; the facet counts on the page come
from the same column. `ListingAttribute` is the record, `nicheData` is the cache the UI reads. That is exactly
why your figures are 6,735 and 11,432 rather than 6,738 and 11,434 — the projection applies source precedence
and drops negated claims, removing three detox and two MAT facilities.

**This is the finding I would act on first.** `detox` as currently defined will put a marketing page in front
of a searcher looking for supervised withdrawal and list facilities whose only qualifying attribute is
clonidine. It is a mapping decision, changeable in one file, and it affects the largest page surface you have.

---

## 5. Coordinates

**Yes — every facility carries `lat`/`lng`, plus a `geoPrecision` recording how it was obtained.**
17,823 of 17,827 are placed (99.98%); 4 have none.

| precision | facilities | what it is |
| --- | --- | --- |
| `exact` | 15,514 | Rooftop / street match |
| `interpolated` | 2,045 | Geocoder estimate along a street range |
| `zip` | 200 | **ZIP centroid, shared by every facility in the ZIP** |
| `city` | 64 | **City centroid, shared** |
| none | 4 | — |

Source: the **free US Census geocoder** (`geocoding.geo.census.gov`) in batch, with a second pass on a
different TIGER vintage, then ZIP/city centroids from the Census gazetteer for the residue. No paid API.

The schema comment states the rule and it is worth repeating: **only `exact` may drive a "X mi from {city}"
label.** A centroid is fine for deciding radius membership and cannot state a distance — 264 facilities would
otherwise display invented distances.

---

## 6. The five enriched categories

All five are **100% `facility-site`** — scraped — except `amenities`, which is mostly structured.

| category | ANY value | of 17,827 | of 12,039 crawled | what defines the subset |
| --- | --- | --- | --- | --- |
| amenities | 8,151 | 45.7% | 67.7% | **Mostly NOT the crawl.** findtreatment.gov supplies 7,186 across four structured values only (`wellness` 3,727, `school` 3,296, `transportation` 2,270, `childcare` 510). The crawl adds 1,755, and the other 11 values exist *only* from the crawl. |
| activities | 554 | 3.1% | 4.6% | Crawl success **and** the site using a matchable word. SAMHSA has no concept of activities. |
| setting | 484 | 2.7% | 4.0% | Same. |
| accessibility | 319 | 1.8% | 2.6% | Same. |
| dietary | 73 | 0.4% | 0.6% | Same. |

**The subset is not luxury, not claiming, not manual entry, and not a batch job with a cutoff.** There are
zero claimed facilities and zero manual attributes in the whole database. It is: *the facility had a website
(15,544), the crawl fetched and parsed something from it (12,039), and the page happened to contain a phrase
in the extractor's table.* The last condition is what makes these numbers small — dietary at 0.6% of crawled
sites is not a coverage failure, it is that rehab websites rarely say "kosher".

### Luxury cross-tab

`amenities.luxury` = **470** facilities (428 scraped, 90 recoveryexcellence, overlapping). Non-luxury = 17,357.

| | luxury (470) | rate | non-luxury (17,357) | rate |
| --- | --- | --- | --- | --- |
| any amenity **other than** `luxury` | 328 | **69.8%** | 7,681 | 44.3% |
| any activity | 132 | **28.1%** | 422 | 2.4% |
| any setting | 80 | **17.0%** | 404 | 2.3% |
| any dietary | 31 | **6.6%** | 42 | 0.24% |
| any accessibility | 34 | **7.2%** | 285 | 1.6% |

**Luxury facilities are 12× more likely to have an activity and 7× more likely to have a setting.** That is
not because luxury gates enrichment — it does not, and there is no such gate. It is that expensive facilities
write richer marketing copy, so the extractor finds more on their pages. The bias is real and it runs in the
direction that flatters premium facilities: an `activities` or `setting` filter surfaces a
disproportionately luxury result set. Worth knowing before either is sold as a filter.

---

## Accuracy risk on scraped fields

Nine categories are wholly or largely scraped: `year_founded`, `length_of_stay`, `price_band`, `activities`,
`setting`, `bed_count`, `accessibility`, `dietary`, and 11 of 15 `amenities` values. You named the hazard
exactly:

> a structured SAMHSA code and a model reading "nestled in the mountains" off a marketing page are not the
> same claim.

To be precise about what this extractor is: **a deterministic phrase table, not a model.** That removes
hallucination and makes every claim traceable to the sentence it came from — `ListingAttribute.evidence`
stores the phrase and `evidenceUrl` the page. It does not remove the core problem. `setting/mountain` (51
facilities) fires on mountain wording in page text; whether the building is in the mountains is a different
question. The extractor's own comments show the team already fighting this — `mat` excluded as a bare word
because it is a yoga mat, `php` because it is a programming language, `pet-friendly` because it would fire on
"alcohol-free".

Two specific risks:

1. **Chain contamination.** Claims are written **per domain**, so every facility on a chain website inherits
   that site's claims. This is a known, measured problem in this data: 52.7% of crawled carrier rows came
   from sites shared by 5+ listings. The same mechanism applies to amenities and setting. A 60-location chain
   whose site mentions a pool gives all 60 a pool.
2. **Marketing voice, not fact.** `luxury` (428 scraped) is a self-description lifted from a sales page. It
   is honest as "this facility calls itself luxury" and not as an assessment.

`policies` deserves a note in the other direction: 14,223 facilities, 79.8%, and it is almost entirely
findtreatment.gov structured data — but four of its seven values are smoking and vaping permitted/free, which
is genuinely structured. The three lifestyle values are crawl-fed and tiny (`laptop-allowed` 27,
`cell-phone-allowed` 25, `pet-friendly` 12). The category looks well covered and the interesting values are
not.

---

## Verdict per field

Everything under 20% coverage, with a recommendation.

| field | coverage | verdict | reasoning |
| --- | --- | --- | --- |
| year_founded | 16.8% | **buildable, as a display field only** | 2,993 facilities, scraped. Not a filter — "Established 2010s" over a fifth of the data is a broken filter. Fine on a profile. |
| length_of_stay | 7.3% | **needs enrichment** | Genuine searcher intent (30-day vs 90-day). 1,303 facilities is too thin to filter, and SAMHSA has no equivalent, so enrichment means more crawling or asking facilities. |
| price_band | 5.5% | **needs enrichment — and 98% of it is one value** | 964 of 987 are `free-state-funded`. The paid bands total 23 facilities. Useless as a price filter today; the free/state-funded signal alone is worth keeping. |
| activities | 3.1% | **buildable for a narrow, honest surface** | The schema comment already says every value is `off` and crawl-fed. Golf/hiking are real luxury signals people choose on, but 554 facilities cannot support a national filter. Build as a profile badge or a filter on a curated city list, not a page type. |
| setting | 2.7% | **needs enrichment** | High intent (beachfront, mountain) and high accuracy risk together — the worst combination to ship as a filter. |
| bed_count | 2.5% | **drop as a filter, keep as display** | 449 facilities. "Facility size" is weak intent anyway. |
| accessibility | 1.8% | **needs enrichment — and treat as a correctness issue** | 319 facilities. Under-reporting accessibility is worse than under-reporting golf: a wheelchair user filtering on 25 facilities gets a wrong answer about the other 17,802. Either enrich properly or do not offer it. |
| dietary | 0.4% | **drop** | 73 facilities, 5 values, two of them in single digits. Not recoverable from crawling; would need facility self-report. |

Above 20% but worth flagging:

- **amenities 45.7%** — buildable, but only the four structured values (`wellness`, `school`,
  `transportation`, `childcare`) have real coverage. The 11 crawl-only values run from 470 down to 33 and
  carry the chain-contamination and marketing-voice risks. Split the category in the UI rather than
  presenting all 15 as equals.
- **insurance 63.6%** — already known to be the contaminated dimension; carrier-level claims are
  disproportionately chain-site noise.
- **gender_program 68.6%** — 11,343 of 12,226 are `co-ed`. The useful values are `men-only` 594 and
  `women-only` 468, and `lgbtq-affirming` is 12 facilities, which is effectively nothing.

The eight categories above 79% (**levels of care, clientele, payment, age groups, therapies, approaches,
conditions, languages, policies**) are all structured, SAMHSA/findtreatment-derived, and buildable now.
`levels_of_care` is the strongest — 100% coverage, licensing-derived, 18 values — with the caveat from §4
that `detox` and `mat` are defined more loosely than their names suggest.
