# Building a site into `site.json`

**Read this before building or rebuilding a client site.** Not needed for panel/infra work — that is
why it lives here rather than in `CLAUDE.md`.

Do it in phases, slowly and verifiably. Never generate all content at once — it produces
placeholder-filled, structurally wrong output that takes longer to fix than to build correctly from
the start.

## Phase 1 — Research before touching any JSON

Before writing a single field, fetch the real site and extract:
- Business name, phone (exact format), email, physical address
- Tagline / hero headline (use their actual words, not invented ones)
- Nav menu structure and what pages exist
- Real stats and numbers (pass rates, years in business, students trained, certifications offered) — never invent stats
- Testimonials (real names, real quotes if available, or clearly paraphrased)
- Brand colors (check CSS or screenshot)
- All images needed — logo especially; download it immediately with `curl -L`

```bash
# Download logo directly into the site's uploads folder
curl -L -o sites/{id}/uploads/logo.png "https://client.com/path/to/logo.png"
# Check dimensions before assigning
sips -g pixelWidth -g pixelHeight sites/{id}/uploads/logo.png
```

## Phase 2 — Foundation (site_vars, theme, header, footer)

Set these before any content blocks. Every block that uses `{phone}`, `{business}`, `{website}` etc.
depends on `site_vars` being correct first.

```json
{
  "business": "Exact Business Name",
  "phone": "555-555-5555",          // display format
  "tel": "+15555555555",            // E.164 for tel: links
  "email": "contact@domain.com",
  "website": "https://domain.com",
  "city": "City Name",
  "state": "State Name",
  "SS": "ST",                       // 2-letter state abbrev
  "city_slug": "city-name-st",
  "zip": "00000"
}
```

**header checklist:**
- `site_name` — set explicitly (not left blank)
- `logo` — path to downloaded logo file
- `phone` — use `{phone}` so it pulls from site_vars
- `menu` — the real nav, with real slugs (not hardcoded city names)

**footer checklist:**
- `phone`, `email` — use shortcodes
- `copyright` — `© {year} {business}. All rights reserved.`
- `columns` — address, quick links, contact info

Screenshot and confirm header/footer before proceeding.

## Phase 3 — Homepage, one block at a time

After each block, screenshot and confirm before adding the next.

Order that works for most service businesses:
1. `hero_split` or `hero` — headline, subtext, primary CTA, hero image
2. `feature_columns` — 3–4 key differentiators
3. `stats` — real numbers only
4. `pricing_cards` or `service_cards` — main offerings
5. `testimonials` — real quotes, real names
6. `faq_two_col` — 6–10 real FAQs
7. `cta_banner` — closing call to action
8. `contact_form` — if needed

**Hero rules:** use the client's actual headline. `{city}` / `{city_state}` DO resolve on the
homepage — `resolve_shortcodes()` reads them from `site_vars` at render time on every page, so they
never print literally. But they resolve to the site's single primary city, so hardcoding one city in
a multi-city business's hero is an editorial choice, not a rendering limitation.

**Stats rules:** only numbers found on the real site or verifiable facts. Never invent a pass rate,
years in business, or student count. If a real number isn't available, use a factual claim instead
("PMI Premier ATP", not "98% pass rate").

## Phase 4 — Landing pages, one at a time

Most important page first (usually the primary service). For each: fetch the real page → write
`content_blocks` → set `seo` (title, meta description, canonical) → screenshot via
`page.php?slug=the-slug` → next.

**Never hardcode a city into a slug** unless the site is explicitly city-specific:
`pmp-certification-training`, not `pmp-certification-training-san-antonio-tx`.

## Phase 5 — Images

For each block with a `photo` field: check `sites/{id}/uploads/media/` first, then search/download,
check dimensions with `sips`, then assign the path directly in the JSON. Never leave a photo field
blank on a block that prominently features an image.

**Media library reuse:** `uploads/media/` is pre-populated with scraped images — check it before
uploading anything new. Preview candidates with the Read tool before assigning.

## Phase 6 — Course schedule (training businesses)

Populate `courses.json` last, once the structure is solid. → `docs/course-schedule.md`

## Screenshots and previews

```bash
/Applications/Google\ Chrome.app/Contents/MacOS/Google\ Chrome --headless=new --disable-gpu \
  --screenshot=/tmp/block_check.png --window-size=1400,900 "http://localhost:PORT/"
```

For a specific site without the admin session flow, create `{site_id}preview.php` at the project root:

```php
<?php
session_start();
$_SESSION['active_site'] = 'site_id_here';
session_write_close();
require __DIR__ . '/index.php';
```

`session_write_close()` before `require` is critical — without it the session isn't committed before
`index.php` reads it.

## Common mistakes

- **Assuming `{city}` won't resolve on the homepage** — it does, on every page.
- **Hardcoded city names in slugs** — breaks if the site serves multiple cities or moves.
- **Invented stats** — worse than no stats.
- **Empty `header.site_name`** — used in `<title>` and breadcrumbs.
- **Placeholder phone** — a `(210) 555-0190` in `site_vars` appears everywhere.
- **Skipping screenshots** — problems found after 10 blocks take far longer to untangle.
