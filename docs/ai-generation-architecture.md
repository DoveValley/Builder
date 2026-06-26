# AI Page Generation — Architecture & Build Plan

## 1. Purpose

Generate unique, SEO-valuable content across entire websites automatically. Starting from a city name and state, the system researches local market data, generates city-specific content blocks, and produces complete pages with no manual writing.

The architecture supports:
- 1 site × 1 city × N pages (single-city site)
- N sites × N cities × N pages (multi-city rollout)
- Any niche (PM training, pest control, law, roofing, etc.) via configurable prompts

---

## 2. The Model

Each site targets one city. A site has three page types:

```
Site (City A)
├── Homepage              1 page — targets city-level keyword
├── Core pages            5–8 pages — About, Contact, FAQs, Locations, etc.
└── Landing pages         10–20 pages — each targets a specific service keyword
```

To expand to City B: clone the site, change the city, run the generator. All content regenerates automatically for the new city.

---

## 3. Architecture Components

### 3.1 The `ai_block` Block Type (PHP)

One block type in PHP that handles all AI-generated content. Registered in `blocks.php`, editable in `editor.php`. All AI block configurations are instances of this single type.

The block has two operating modes:

**Standalone mode** — generates a complete content section. The block exists in the output file with AI-written fields. Renders like an existing block type (text, feature_columns, faq_two_col, etc.) via the `ai_render_as` field.

**Inject mode** — generates content that is merged into an adjacent block's fields. The ai_block itself does not appear in the output file — it is consumed at generation time. Used when AI content belongs inside another block (hero subtext inside hero_split, FAQ items appended to faq_two_col).

### 3.2 The Prompt Registry

A JSON file per site that defines named AI block configurations. Block instances in templates reference a configuration by ID — they do not embed the prompt directly.

```
sites/{site_id}/data/ai_block_types.json
```

Structure:
```json
{
  "city_market_intro": {
    "label": "City Market Intro",
    "ai_mode": "standalone",
    "ai_render_as": "text",
    "ai_model": "claude-haiku-4-5-20251001",
    "ai_prompt": "Write a 170-word section about {service} demand in {city}, {SS}...",
    "ai_output_schema": {
      "heading_text": "string",
      "text": "html_string"
    },
    "default_fields": {
      "heading_text": "",
      "text": "",
      "skin": "light",
      "heading_level": "h2"
    }
  },
  "hero_subtext": { ... },
  "faq_additions": { ... },
  "feature_columns_local": { ... }
}
```

Adding a new AI block configuration = adding one entry to this file. No code changes.

### 3.3 The Four Standard AI Block Configurations

**`hero_subtext`** — inject mode. Targets the `hs_subtext` field of the preceding hero_split block. Writes 2–3 sentences: keyword in sentence 1, local industries or employers referenced, live-virtual delivery mentioned. Block is consumed at generation time.

**`city_market_intro`** — standalone mode. Renders as `text`. Generates a 170–200 word section covering local PM demand, industries, employers, salary range. The primary SEO differentiator — genuinely unique content per city.

**`feature_columns_local`** — standalone mode. Renders as `feature_columns`. Generates 4 columns: 3 consistent product benefits + 1 city-specific column referencing the dominant local sector or employer.

**`faq_additions`** — inject mode. Appends 2–3 city-specific FAQ items to the `fq_items` array of the next faq_two_col block. Targets real questions a local searcher would ask. Block is consumed at generation time.

### 3.4 The `ai_block` Base Schema

Every ai_block instance in a template carries these fields:

```json
{
  "type": "ai_block",
  "ai_type_id": "city_market_intro",
  "ai_prompt_override": null,
  "ai_mode": "standalone",
  "ai_render_as": "text",
  "ai_inject_target": "previous | next | block_index:N",
  "ai_inject_field": "hs_subtext | fq_items | columns",
  "ai_inject_mode": "replace | append | prepend",
  "ai_model": "claude-haiku-4-5-20251001",
  "ai_output_schema": { },
  "ai_context": ["city_vars", "site_vars", "niche_vars", "keyword"],
  "_ai_generated": false,
  "_ai_generated_at": null,
  "_ai_locked": false
}
```

The generator reads these fields universally. No block-type-specific generator logic.

### 3.5 The City Data Schema

```
sites/{site_id}/data/cities.json
```

Two layers of fields:

**Identity fields** (manual, 30 seconds per city):
```json
{
  "id": "dallas-tx",
  "city": "Dallas",
  "state": "Texas",
  "SS": "TX",
  "city_slug": "dallas-tx"
}
```

**Research fields** (auto-filled by research pre-processor):
```json
{
  "industries": ["technology", "finance", "healthcare", "energy"],
  "top_employers": ["AT&T", "Toyota", "American Airlines", "Texas Health Resources"],
  "salary_note": "PMP-certified project managers in Dallas average $115,000–$135,000 annually",
  "market_blurb": "Dallas's diverse economy spans technology, finance, and healthcare — sectors that consistently demand certified project managers to lead enterprise initiatives."
}
```

### 3.6 Context Variables Available to Prompts

The generator assembles context from multiple sources before calling the API:

| Variable | Source |
|---|---|
| `{city}` | cities.json |
| `{SS}` | cities.json |
| `{state}` | cities.json |
| `{city_slug}` | cities.json |
| `{industries}` | cities.json (research fields) |
| `{top_employers}` | cities.json (research fields) |
| `{salary_note}` | cities.json (research fields) |
| `{market_blurb}` | cities.json (research fields) |
| `{business}` | site_vars |
| `{phone}` | site_vars |
| `{website}` | site_vars |
| `{service}` | template-level config |
| `{keyword}` | template-level config |
| `{sibling_content}` | adjacent block fields (for inject mode) |

### 3.7 The Generator Script

A Python script that processes an entire site in one command:

```bash
python3 generate.py --site granitepmacademy-dallas --all
```

**Internal execution order:**

**Step 1 — Research pre-processor**
If research fields in cities.json are empty, calls Claude with a research prompt to fill: industries, top_employers, salary_note, market_blurb. Writes results back to cities.json before proceeding.

**Step 2 — Homepage generation**
Scans `site.json` homepage content_blocks for ai_block instances. Processes each per its mode (standalone fills block fields, inject merges into adjacent block). Writes back to site.json.

**Step 3 — Core page generation**
Scans `site.json` pages array for ai_block instances across all core pages (About, FAQs, Contact, etc.). Same processing. Writes back to site.json.

**Step 4 — Landing page generation**
Scans all files in `data/pages/` for ai_block instances. Processes each. Writes back to individual page files. Updates `locked_blocks` per file.

**Step 5 — Done**
All ai_block instances across the entire site have been filled with city-specific content.

### 3.8 Locked Blocks

Each generated page file maintains a `locked_blocks` array. When an ai_block is processed, the block's index is added to this array. On subsequent generation runs, locked blocks are skipped — AI content is preserved.

This means:
- Template structure changes (new CTA, updated schedule widget) propagate to all city pages on regeneration
- AI-generated content survives template updates
- To refresh AI content for a city: clear `locked_blocks` and regenerate

### 3.9 Existing Infrastructure (Already Built)

The following already exists and does not change:

- `templates.json` — landing page templates with block structures and {city} shortcodes
- `page-index.json` — maps landing page slugs to generated files
- `data/pages/` — pre-generated landing page JSON files
- `page.php` — routes requests, merges city_vars, renders blocks
- `includes/blocks.php` — block rendering (gains ai_block case)
- All existing block types (text, hero_split, faq_two_col, feature_columns, etc.)

---

## 4. The Generation Pipeline (Full Flow)

```
INPUT
City name + state (30 seconds manual)

        ↓

STEP 1 — RESEARCH PRE-PROCESSOR
Claude reads: city name, state, service context
Claude writes: industries, top_employers, salary_note, market_blurb
Output: cities.json enriched

        ↓

STEP 2 — CONTEXT ASSEMBLY
Generator merges: cities.json + site_vars + template config + keyword
Result: full context object for prompt variable substitution

        ↓

STEP 3 — AI BLOCK PROCESSING (per page, per block)
For each ai_block instance across all pages:
  - Load registry entry by ai_type_id
  - Apply ai_prompt_override if present
  - Substitute {variables} from context
  - Call Claude API → structured JSON response
  - Standalone: fill block fields, mark _ai_generated, _ai_locked
  - Inject: merge into target block field, remove ai_block from output

        ↓

STEP 4 — OUTPUT
site.json updated (homepage + core pages)
data/pages/*.json updated (landing pages)
locked_blocks updated per file

        ↓

RESULT
Complete site with unique city-specific content
across all pages, ready to serve immediately
```

---

## 5. Page Structure for Maximum SEO Value

11 blocks per landing page. ~1,900–2,100 words of content.

| # | Block | Type | AI? | SEO Purpose |
|---|---|---|---|---|
| 1 | Hero | `hero_split` + `ai_block(hero_subtext)` | Inject | H1 keyword, city-specific subtext above fold |
| 2 | Trust signals | `stats` or `logo_bar` | No | E-E-A-T signals early |
| 3 | Why us | `ai_block(feature_columns_local)` | Standalone | Local relevance in benefit columns |
| 4 | City market | `ai_block(city_market_intro)` | Standalone | Primary unique content — local industries/employers/salary |
| 5 | How it works | `steps` | No | Informational intent |
| 6 | Pricing | `pricing_cards` | No | Helpful content — price transparency |
| 7 | Schedule | `custom_html` (shortcode) | No | Dynamic dates, conversion element |
| 8 | Testimonials | `testimonials` | No | E-E-A-T — social proof |
| 9 | FAQs | `faq_two_col` + `ai_block(faq_additions)` | Inject | FAQPage schema, featured snippet targets |
| 10 | CTA | `cta_banner` | No | Conversion close |
| 11 | Related pages | `links_grid` | No | Internal linking, equity distribution |

Homepage uses 3–4 ai_blocks. Core pages use 1–2 each.

---

## 6. The Multi-Site Workflow

```
1. BUILD master site (City A — San Antonio)
   - Set site_vars: business name, phone, email, theme
   - Build page templates with ai_block placements
   - Configure registry: write prompts for all 4 ai_block types
   - Run: python3 generate.py --site gpma-sanantonio --all
   - Review output quality. Refine prompts if needed.
   - Approve. Site A is live.

2. CLONE for City B (Dallas)
   - Copy site directory → new site ID (gpma-dallas)
   - Update site_vars: city, phone, address
   - Add bare city record to cities.json (name, state, SS, city_slug)
   - Run: python3 generate.py --site gpma-dallas --all
     → Research step fills industries, employers, salary automatically
     → Content step generates all ai_blocks across all pages
   - Review. Approve. Site B is live.

3. REPEAT for City C, D, E...
   - Each city: 30 seconds manual input + 1 command
   - Full site with unique content: ~2–5 minutes total
```

---

## 7. What Is Manual vs. Automatic

| Task | Manual? | Notes |
|---|---|---|
| Build page templates (block structure, order) | Yes — once | Done for master site, cloned for all others |
| Write registry prompts | Yes — once | Done once, applies to all sites and cities |
| Add city name + state to cities.json | Yes — 30 sec/city | Only required input per city |
| Research (industries, employers, salary) | Automatic | Research pre-processor fills this |
| AI content generation (all pages) | Automatic | Generator handles all pages |
| SEO fields (title, meta, og tags) | Automatic | Templates use {city} shortcodes |
| Internal linking | Automatic | links_grid uses {city_slug} shortcodes |
| Schedule widget content | Automatic | Pulls from courses.json dynamically |
| Clone site | Automatic | Script or admin action |
| Quality review | Optional | First few cities only |

---

## 8. Build Phases

---

### Phase 1 — Proof of Concept
**Goal:** Validate that AI-generated content is good enough to rank before building infrastructure.

**What gets built:**
- Enrich San Antonio cities.json manually (one-time, to test content quality before automating research)
- Simple Python script: calls Claude API with a hardcoded city_market_intro prompt, writes output to one field in one page file
- Target: city_market_intro block for San Antonio PMP Certification landing page only

**Deliverable:** One page with a 180-word AI-written city intro block. Preview it. Is the content genuinely good? Would a San Antonio PM find it useful?

**Success criteria:** Output reads like it was written by someone who knows San Antonio's PM market. References real employers and industries. Not generic.

**If output is poor:** Fix the prompt, run again. Iteration is fast and cheap.

**Files touched:**
- `sites/granitepmacademy/data/cities.json` (manual enrichment of San Antonio)
- `generate_poc.py` (new, single-purpose proof of concept script)
- `sites/granitepmacademy/data/pages/tpl_pmp_certification_training_city_san-antonio-tx.json` (output)

---

### Phase 2 — The `ai_block` PHP Type
**Goal:** Build the one block type in PHP that all AI blocks will use.

**What gets built:**
- `ai_block` added to `allowed_block_types()` in `blocks.php`
- Render case in `render_content_block()` — reads `ai_render_as`, delegates to existing renderer
- Editor UI in `editor.php` — ai_type_id selector, prompt override textarea, model selector, generated field display, lock status
- JS default template in `scripts.php`
- Save handler in `admin/save.php` (blocks_from_post)

**Deliverable:** In admin Templates tab — add block — "AI Block" is available. Can be added to a template. Editor UI shows. Saves correctly. Renders in preview via ai_render_as type.

**Files touched:**
- `includes/blocks.php`
- `includes/editor.php`
- `includes/scripts.php`
- `admin/save.php`

---

### Phase 3 — The Prompt Registry
**Goal:** Central prompt management. All prompts in one file, referenced by ID from block instances.

**What gets built:**
- `sites/{id}/data/ai_block_types.json` — registry file with all 4 standard configurations
- Registry loader function (`includes/ai.php` — new file)
- Admin UI section: view and edit registry entries without touching JSON
- Generator reads registry by `ai_type_id`

**The 4 registry entries written:**
- `hero_subtext` — inject → previous hero `hs_subtext`, replace
- `city_market_intro` — standalone → text block
- `feature_columns_local` — standalone → feature_columns block
- `faq_additions` — inject → next faq_two_col `fq_items`, append

**Deliverable:** All 4 ai_block configurations visible and editable in admin. Templates reference them by ID. Prompts update in one place.

**Files touched:**
- `sites/{id}/data/ai_block_types.json` (new)
- `includes/ai.php` (new)
- `admin/tabs/templates.php`
- `admin/templates_save.php`

---

### Phase 4 — The Generator Script (Content Generation)
**Goal:** Full generator that processes all ai_blocks across an entire site in one command.

**What gets built:**
- `generate.py` Python script
- Scans site.json homepage blocks → processes ai_blocks → writes back
- Scans site.json pages → processes ai_blocks → writes back
- Scans data/pages/*.json → processes ai_blocks → writes back
- Handles standalone mode (fill block fields) and inject mode (merge into target, remove from output)
- Manages `_ai_locked` and `locked_blocks` per file
- CLI flags: `--site`, `--all`, `--page homepage`, `--template tpl_*`, `--refresh` (clears locks)

**Deliverable:** Run against San Antonio site. All ai_blocks across all pages filled. Preview full site. Review content quality across multiple page types.

**Files touched:**
- `generate.py` (new)
- `sites/{id}/data/site.json` (output)
- `sites/{id}/data/pages/*.json` (output)

---

### Phase 5 — Research Pre-Processor
**Goal:** Automate cities.json enrichment. Eliminate manual research per city entirely.

**What gets built:**
- Research step added to `generate.py` (runs before content generation)
- Detects empty research fields in cities.json for the target city
- Calls Claude API with structured research prompt
- Validates response (rejects generic or empty content)
- Writes enriched data back to cities.json
- Optional flag: `--research-only` to run research step alone and review before generating content

**Research prompt returns:** industries (array), top_employers (array), salary_note (string), market_blurb (string)

**Deliverable:** Add bare Dallas record (name, state, SS, city_slug only). Run generator. cities.json fills automatically. Full Dallas site generated from one command with no other manual input.

**Files touched:**
- `generate.py` (research step added)
- `sites/{id}/data/cities.json` (output — research fields written)

---

### Phase 6 — Multi-Site Clone Workflow
**Goal:** Clean, repeatable workflow for spinning up a new city site from the master.

**What gets built:**
- `clone_site.py` script: copies site directory to new site ID, resets `_ai_generated` flags, clears `locked_blocks`
- Generator runs after clone with new city context
- Admin: "Clone Site" button or action

**Deliverable:** Clone San Antonio site to Dallas. Update city in site_vars. Run one command. Full Dallas site with unique content across all pages. Under 5 minutes total from clone to done.

**Files touched:**
- `clone_site.py` (new)
- `admin/tabs/sites.php` (clone action)
- `admin/site_api.php` (clone handler)

---

### Phase 7 — Scale and Polish
**Goal:** Production-ready pipeline for N sites reliably.

**What gets built:**
- Batch generation: `python3 generate.py --all-sites`
- Generation log: what ran, when, which model, errors, token counts
- Error handling: API failures, malformed responses, empty output detection with retry
- Cost tracking: token usage and cost estimate per run logged
- Admin dashboard: generation status per page — has AI content, when generated, lock status
- Prompt versioning: track which prompt version produced each block

**Deliverable:** Generate 10 city sites in one batch command. Full observability. Know exactly what ran, what failed, what it cost.

---

## 9. Technology Stack

| Component | Technology |
|---|---|
| CMS | PHP 8.x, flat-file JSON |
| Generator | Python 3.x |
| AI API | Anthropic Claude API |
| Default model | claude-haiku-4-5-20251001 (fast, low cost) |
| Quality option | claude-sonnet-4-6 (better output, higher cost) |
| Output format | Structured JSON (Claude forced JSON mode) |
| Data storage | JSON files in sites/{id}/data/ |

---

## 10. Cost Estimate

Per city site (1 homepage + 5 core pages + 20 landing pages = 26 pages, ~3 AI blocks per page):

- Research step: 1 API call
- Content generation: ~78 API calls
- Total: ~79 API calls per site

At Haiku pricing: **under $0.10 per full city site**

10 city sites: under $1.00 total.

---

## 11. What Is Never Automated

- Page structure and template design (done once for master site)
- Prompt writing and quality tuning (done once per ai_block type)
- Adding city name + state (30 seconds per city — the only per-city manual step)
- Domain setup and hosting configuration
- Real testimonials (must be genuine — never AI-generated)
- Real pricing, phone numbers, course dates (factual — must be accurate)
- Quality review of output (recommended for first few cities)
