# Developer Methodology — Homepage Builder
**Version 4 — 2026-06-17**

> **Who this is for:** The person setting up or maintaining the system — a developer,
> the site owner doing technical setup, or anyone deploying a new site.
> Once setup is complete, hand the *User Guidebook* to whoever will enter content.

---

## Multi-Site Architecture

The system supports running multiple independent sites from one codebase. Each site lives under `sites/{site_id}/` and has its own data, uploads, and deploy config.

```
sites/
├── pest-template/
│   ├── data/
│   │   ├── site.json          All site content
│   │   ├── courses.json       Course schedule
│   │   ├── media.json         Media library metadata
│   │   ├── cities.json        City list for city page generator
│   │   ├── page-index.json    Slug → filename map for generated city pages
│   │   ├── templates.json     City page templates
│   │   └── pages/             Generated city page JSON files
│   ├── uploads/               Uploaded images (WebP)
│   ├── deploy.json            FTP credentials (gitignored, never committed)
│   └── deploy_manifest.json   MD5 hash manifest for incremental FTP push
└── granitepmacademy/
    └── (same structure)
```

**Active site** is set via `$_SESSION['active_site']`. `config.php` reads this and defines all path constants (`ACTIVE_SITE_ID`, `ACTIVE_SITE_DIR`, `DATA_FILE`, `UPLOAD_DIR`, `UPLOAD_URL`, etc.) relative to that site. The admin panel redirects to `sites.php` when no site is selected.

`site_api.php` handles site switching via `FormData POST` (`action=select&site_id=...`).

> **Important:** `UPLOAD_URL` in multi-site mode is `sites/{id}/uploads/` — this is a relative path used both in stored image paths and in URL rendering. The static site generator rewrites these to `/uploads/` in the output HTML automatically.

---

## Phase 1 — Build a Site from Scratch

Complete this once per new site installation, in order.

---

### Step 1: Deploy the files

1. Upload the homepage-builder folder to your web server (`public_html` or equivalent)
2. Set folder permissions:
   ```
   chmod 775 data/
   chmod 775 uploads/
   ```
3. Confirm `.htaccess` is active — visit `yoursite.com/test-slug` and confirm it shows a "page not found" message from the builder (not a server 404 error)
4. Confirm PHP 8.0+ and the GD extension are available:
   ```
   php -r "echo phpversion(); echo PHP_EOL; echo (extension_loaded('gd') ? 'GD OK' : 'GD MISSING');"
   ```

### Step 2: Set admin credentials

1. Open `config.php`
2. Change `ADMIN_USERNAME` to your chosen username
3. Generate a bcrypt password hash:
   ```
   php -r "echo password_hash('your-password', PASSWORD_DEFAULT);"
   ```
4. Paste the result into `ADMIN_PASSWORD_HASH`
5. Update `SITE_TITLE` to the site name
6. Set `CONTACT_EMAIL` to the address that should receive contact form submissions (defaults to `hello@yoursite.com` — change this before launch)
7. Confirm `BASE_DIR`, `DATA_FILE`, and `UPLOAD_DIR` path constants point to the correct absolute paths

Test: visit `yoursite.com/admin` — you should see the login screen. Log in.

### Step 3: Set Global Theme (Theme tab)

Work left to right through the color fields. Match to the brand color guide or an existing reference site.

- **Primary Color** — used for buttons, links, and icon highlights
- **Accent Color** — secondary highlights and hover states
- **Header Background / Header Text** — the top navigation bar
- **Content Background / Content Text** — the main page body
- **Footer Background / Footer Text** — the bottom bar
- **Font** — choose from the system font dropdown
- **Button Corner Radius** — `0` = square, `4` = slightly rounded, `24` = pill
- **Analytics & Tracking** — paste the full Google Analytics or GA4 `<script>` block here; it applies to every page automatically

Save → hard-refresh the browser to confirm colors apply.

### Step 4: Upload brand images to the Media Library (Media Library tab)

Upload all images before building any pages. You cannot pick an image in a block that hasn't been uploaded yet.

1. Drag and drop all images at once — JPG, PNG, or WebP, max 8MB each
2. All images auto-convert to WebP and resize to max 1800px on upload
3. For each image: fill in the **alt text** immediately
4. For any image used in a hero or split block: click **⊕** and set the **focal point** by clicking the most important subject in the image
5. Run **Find Duplicates** after your first batch upload to catch any accidental duplicates

**Downloading a logo from the client's site:**
```bash
curl -L -o sites/{id}/uploads/logo.png "https://client.com/path/to/logo.png"
sips -g pixelWidth -g pixelHeight sites/{id}/uploads/logo.png
```

### Step 5: Build the Header (Header tab)

- **Logo** — upload a transparent PNG (~200px wide)
- **Announcement Bar** — short message at the very top. Link accepts `tel:`, `https://`, and `mailto:` formats
- **Phone Number** — displayed in the header and referenced by the `{phone}` shortcode
- **City / Location** — city name shown in the header
- **Navigation Menu** — add items in order. Use **+ Sub-menu** to add dropdown links. Keep primary nav to 5–7 items. **Do not hardcode city names in nav URLs** — use generic slugs (e.g. `/pmp-certification-training` not `/pmp-certification-training-san-antonio-tx`)
- **Social Links** — Facebook, Instagram, Yelp, Google Business Profile — paste the full profile URL

Save → preview the public home page to confirm the header renders.

### Step 6: Build the Footer (Footer tab)

- **Footer Logo** — upload a white or dark version of the logo as needed
- **Phone / Email** — use `{phone}` shortcode so it pulls from site_vars automatically
- **Footer Columns** — recommended structure:
  - Column 1: **Contact** type — phone + city; add address and hours as extras
  - Column 2: **Links** type — "Services" with links to each service landing page
  - Column 3: **Links** type — "Service Areas" with links to each city page
- **Bottom Bar** — copyright text (`© {year} {business}. All rights reserved.`) and legal links

Save → preview to confirm footer renders correctly.

### Step 7: Fill in LocalBusiness Schema (SEO / Schema tab)

This feeds Google's LocalBusiness schema on every page and powers all `{city}`, `{phone}`, `{business}` shortcodes.

| Field | Example |
|---|---|
| Business Name | Katy Pest Pros |
| Business Type | PestControlService |
| Website URL | https://katypestpros.com |
| Phone | (281) 215-0160 |
| Street Address | (leave blank if service-area only) |
| City | Katy |
| State | TX |
| ZIP | 77494 |
| Latitude / Longitude | 29.7858, -95.8245 |
| Price Range | $$ |
| Opening Hours | Mo-Fr 08:00-18:00, Sa 09:00-13:00 |
| Average Rating | 4.8 |
| Review Count | 534 |

Save → view page source on the home page → search `application/ld+json` → confirm the schema shows correct values.

### Step 8: Build the Home Page — one block at a time

See the **Claude Site-Building Methodology** section below for the required approach. Never dump all blocks at once.

Recommended block order:

| Position | Block Type | Purpose |
|---|---|---|
| 1 | `hero_split` | Main headline + hero photo. Use H1. Use the client's actual headline |
| 2 | `feature_columns` | 3–4 differentiators with icons |
| 3 | `stats` | Real numbers only — never invent stats |
| 4 | `pricing_cards` or `service_cards` | Main offerings |
| 5 | `testimonials` | Real quotes with real names |
| 6 | `faq_two_col` | 6–10 real FAQs |
| 7 | `cta_banner` | Closing call to action |
| 8 | `contact_form` | If a contact form is needed |

**Screenshot after each block.** Compare against the real site before proceeding.

### Step 9: Build Landing Pages (Landing Pages tab)

For each page — one at a time:
1. Fetch the corresponding page on the real site and extract real content
2. Set the URL slug — generic, no city names hardcoded
3. Build content blocks in order
4. Set SEO fields (canonical URL, meta description, service schema)
5. Screenshot via `page.php?slug=the-slug` and review
6. Move to the next page

### Step 10: Pre-Launch Checklist

**Content**
- [ ] Home page H1 includes the primary keyword
- [ ] Every page has a meta description (no blanks)
- [ ] Every image has alt text
- [ ] No nav links point to `localhost` or staging URLs
- [ ] Phone number correct in header, footer, and all CTA blocks
- [ ] `{phone}`, `{business}` shortcodes resolve correctly — spot-check 3 pages
- [ ] No `{city}` shortcodes on the homepage unless this is explicitly a city-specific site
- [ ] `CONTACT_EMAIL` in `config.php` is set to the real address

**Schema**
- [ ] View source → `application/ld+json` → LocalBusiness block shows correct name, address, phone
- [ ] Canonical URLs in page source match the live domain
- [ ] FAQPage schema present on pages with FAQ blocks

**Technical**
- [ ] SSL certificate is active (padlock in browser)
- [ ] Pretty URLs work — visit a landing page slug directly
- [ ] Admin login works at `/admin`
- [ ] Images load — no broken icons
- [ ] Google Analytics fires — check Real-Time report in GA4

**After launch**
- [ ] Submit the sitemap to Google Search Console
- [ ] Request indexing for the home page
- [ ] Set up Google Business Profile if not already active

---

## Claude AI Site-Building Methodology

When building or editing a site's `site.json` directly, always follow these phases in order. **Never generate all content at once** — it produces placeholder-filled, structurally wrong output that takes longer to fix than to build correctly from the start.

### Phase 1 — Research (before touching any JSON)

Fetch the real site and extract:
- Business name, phone (exact format), email, physical address
- Tagline and hero headline — use their actual words
- Nav menu structure and real page slugs
- Real stats and numbers — **never invent stats**
- Testimonials — real names, real quotes
- Brand colors
- Logo — download it immediately with `curl -L`

### Phase 2 — Foundation (site_vars, header, footer)

Set these before writing any content blocks. Every block that uses `{phone}`, `{business}`, etc. depends on `site_vars` being correct first.

**site_vars checklist:**
```json
{
  "business": "Exact Business Name",
  "phone": "555-555-5555",
  "tel": "+15555555555",
  "email": "contact@domain.com",
  "website": "https://domain.com",
  "city": "City Name",
  "state": "State Name",
  "SS": "ST",
  "city_slug": "city-name-st",
  "zip": "00000"
}
```

Set `header.site_name` explicitly — never leave it blank. Set the logo path. Build the real nav with real slugs.

Take a screenshot. Confirm header and footer look correct before touching content blocks.

### Phase 3 — Homepage blocks (one at a time)

Build one block, take a screenshot, confirm, then add the next. The hero must use the client's actual headline — not a generated one. Do NOT use `{city}` or `{city_state}` shortcodes in the homepage hero or feature blocks — those only work inside city landing pages and will render literally on the main site.

Screenshot command:
```bash
/Applications/Google\ Chrome.app/Contents/MacOS/Google\ Chrome --headless=new --disable-gpu --screenshot=/tmp/block_check.png --window-size=1400,900 "http://localhost:PORT/"
```

### Phase 4 — Landing pages (one at a time)

Fetch the real page → write blocks → screenshot via `page.php?slug=the-slug` → review → next page.

### Phase 5 — Images

Check `sites/{id}/uploads/media/` for existing relevant images first. Check dimensions with `sips -g pixelWidth -g pixelHeight <file>`. Download and assign before building the block, not after.

### Phase 6 — Course schedule

Populate `courses.json` last, with real dates, prices, and registration URLs.

### Common mistakes to avoid

| Mistake | Why it's a problem |
|---|---|
| `{city}` on the homepage | Resolves only inside city landing pages — renders literally on the main site |
| Hardcoded city names in slugs | `/pmp-certification-san-antonio-tx` breaks multi-city or future moves |
| Invented stats | Made-up pass rates or student counts are worse than no stats |
| Empty `header.site_name` | Used in `<title>` and breadcrumbs — always set it |
| Placeholder phone in `site_vars` | `(210) 555-0190` style numbers appear everywhere — set the real phone first |
| Skipping screenshots | Problems found after 10 blocks take far longer to untangle |

---

## Deploy Tab — Static Site Generator + FTP Push

The Deploy tab generates a fully-static copy of the site and pushes it to a web host via FTP. It replaces the need to run PHP on the live server.

### How static generation works (`admin/generate_static.php`)

Runs as a Server-Sent Events (SSE) stream. Renders every page by calling `site-template.php` via `ob_start()`/`ob_get_clean()`, then writes the output to `output/{site_id}/`.

Output structure:
```
output/{site_id}/
├── index.html
├── 404.html
├── sitemap.xml
├── robots.txt
├── .htaccess           (DirectoryIndex index.html, cache headers, gzip)
├── assets/             (copied from /assets/)
├── uploads/            (copied from sites/{id}/uploads/ — always flat /uploads/)
├── blog/
│   ├── index.html
│   └── {post-slug}/index.html
├── {page-slug}/index.html
└── {city-slug}/index.html
```

Upload paths are **always flattened to `/uploads/`** in the generated HTML, regardless of the multi-site `UPLOAD_URL` value. The generator rewrites `sites/{id}/uploads/` → `uploads/` in every HTML file before writing it.

### How FTP push works (`admin/deploy_ftp.php`)

1. Builds a file list from `output/{site_id}/`
2. Compares MD5 hashes against `sites/{id}/deploy_manifest.json`
3. Uploads only new or changed files (incremental)
4. Updates the manifest after a successful upload

### FTP credentials (`sites/{id}/deploy.json`)

```json
{
  "ftp_host": "109.106.248.17",
  "ftp_port": 21,
  "ftp_user": "u682938201.yourdomain.com",
  "ftp_pass": "your-password",
  "ftp_path": "/public_html",
  "ftp_passive": true,
  "canonical_domain": "https://yourdomain.com",
  "web3forms_key": ""
}
```

**FTP host rules:**
- Use the bare IP or hostname — **no** `ftp://` prefix
- `ftp_passive: true` is required on virtually all shared hosts (Hostinger, cPanel, etc.)
- On Hostinger, use the domain-specific FTP user (e.g. `u123456.yourdomain.com`), not the temp-domain user — they point to different directories

**Forcing a full re-upload:**
If you're pushing to a new server or the manifest is out of sync, clear it:
```bash
echo '{}' > sites/{id}/deploy_manifest.json
```
Then run Generate + Push. All files will be treated as new.

**Web3Forms** — if set, the contact form on the static site posts to Web3Forms (free service, no backend needed). Leave blank to omit the form submit button.

### Deploy workflow

1. Set FTP credentials in the admin Deploy tab → **Save FTP Settings**
2. Set the canonical domain → **Save Build Settings**
3. Click **Generate Static Site** — watch the log, confirm it completes
4. Click **Push to Server** — watch the log, confirm upload count matches expectation
5. Visit the live domain and verify

---

## Phase 2 — Per-Page Workflow (repeat for every new page)

1. **Plan the page** — decide what it covers, create it, set the URL slug
2. **Fetch the real page** — `WebFetch` the client's equivalent page and extract real content
3. **Build the content** — assemble blocks in order. Typical flow: `hero_split` → `feature_split` → `faq_two_col` → `cta_banner`
4. **Set heading structure** — exactly one H1 per page (hero block), H2s for each major section
5. **Use shortcodes** — `{phone}`, `{business}` in CTAs. Use `{city}` only on city-specific pages
6. **Fill in per-page SEO fields** — canonical URL, meta description, OG image, Service schema
7. **Screenshot and review** — check desktop and mobile before pushing to production

---

## Technical Reference

### File Structure (multi-site)

```
/
├── index.php                     Public homepage
├── page.php                      Landing page renderer
├── blog.php                      Blog router (listing + single post)
├── config.php                    Credentials, path constants, session_start()
├── .htaccess                     Pretty URL routing (Apache)
├── includes/
│   ├── functions.php             Loader only
│   ├── data.php                  load_data(), save_data(), default_data()
│   ├── helpers.php               sanitize_url(), save_uploaded_file(), sanitize_svg(),
│   │                             slugify(), unique_slug()
│   ├── theme.php                 theme_css_vars(), resolve_color()
│   ├── blocks.php                allowed_block_types(), render_content_block()
│   ├── editor.php                render_content_blocks_editor(), block admin panel UI
│   ├── scripts.php               JS templates for new-block scaffolding in admin
│   ├── shortcodes.php            apply_shortcodes_to_block(), apply_course_shortcodes(),
│   │                             course_shortcode_inline_script(), load_courses()
│   ├── schema.php                JSON-LD schema helpers
│   ├── seo-editor.php            SEO admin panel rendering
│   └── site-template.php         Shared HTML page template
├── admin/
│   ├── index.php                 Admin dashboard (11-tab panel)
│   ├── save.php                  Handles section POSTs: header, theme, content, footer, pages, popups
│   ├── schedule_save.php         Course schedule CRUD (save, delete, duplicate)
│   ├── media_api.php             Media library API
│   ├── site_api.php              Multi-site switching
│   ├── deploy_save.php           Saves deploy.json (FTP credentials + build settings)
│   ├── generate_static.php       SSE endpoint — static site generator
│   ├── deploy_ftp.php            SSE endpoint — FTP push
│   ├── login.php
│   ├── logout.php
│   └── tabs/
│       ├── deploy.php            Deploy tab UI (Build + Push cards)
│       ├── schedule.php          Course schedule list/add/edit UI
│       └── (other tab partials)
├── sites/
│   └── {site_id}/
│       ├── data/
│       │   ├── site.json
│       │   ├── courses.json
│       │   ├── media.json
│       │   ├── cities.json
│       │   ├── page-index.json
│       │   ├── templates.json
│       │   └── pages/
│       ├── uploads/
│       ├── deploy.json           (gitignored)
│       └── deploy_manifest.json
├── output/
│   └── {site_id}/               Generated static site
├── assets/
│   ├── css/
│   │   ├── style.css
│   │   ├── schedule.css
│   │   └── card.css
│   └── js/
│       ├── schedule.js
│       └── card.js
└── docs/                         Guidebooks (this file and siblings)
```

### Data Storage

- `sites/{id}/data/site.json` — all content: header, theme, home page blocks, landing pages, footer, popups, local business info, per-page SEO, site_vars
- `sites/{id}/data/courses.json` — course schedule records
- `sites/{id}/data/media.json` — media library: url, alt, focal_x, focal_y, width, height, size, dhash, varied_seed
- `sites/{id}/data/cities.json` — city list for city page generator
- `sites/{id}/data/page-index.json` — slug → filename map for generated city pages
- `sites/{id}/data/templates.json` — city page content templates
- `sites/{id}/deploy.json` — FTP credentials and build settings (**gitignored — never committed**)
- `sites/{id}/deploy_manifest.json` — MD5 hashes of last-uploaded static output

### Shortcodes System

`resolve_shortcodes()` replaces tokens from `site_vars` (multi-site) or `local_business` fields.

| Token | Example output |
|---|---|
| `{city}` | Katy |
| `{state}` | Texas |
| `{SS}` | TX |
| `{city_state}` | Katy, TX |
| `{city_slug}` | katy |
| `{business}` | Katy Pest Pros |
| `{phone}` | (281) 215-0160 |
| `{zip}` | 77494 |
| `{website}` | https://katypestpros.com |
| `{rating}` | 4.8 |
| `{review_count}` | 534 |
| `{year}` | Current year |

Shortcodes resolve in all block text fields. Not resolved in `custom_html` blocks — intentional.

### Adding a New Block Type

Four changes required across four files:
1. `includes/blocks.php` — add to `allowed_block_types()` + add `case` in `render_content_block()`
2. `includes/editor.php` — add admin panel UI for the new block's fields
3. `includes/scripts.php` — add the new-block JS template (default field values when block is first added)
4. `admin/save.php` — add `case` in the `content` section that reads `$_POST` and builds the block array

All URL/button-URL fields must go through `sanitize_url()`. All photo upload fields should reuse `render_photo_upload_fields()`.

### Security

- Session-based login with bcrypt-hashed credentials in `config.php`
- CSRF tokens on every admin form and every POST endpoint (`hash_equals()` check)
- Session regeneration on login (prevents session fixation)
- Login lockout: 10 failed attempts per IP → 15-minute block
- File uploads: MIME type validation + 8MB cap + WebP re-encoding
- All user-entered URLs go through `sanitize_url()` — only `http(s)://`, `tel:`, `mailto:`, and relative links allowed
- SVG uploads are sanitized (strips `<script>`, `on*` handlers, `javascript:` URIs)
- `data/.htaccess` blocks direct HTTP access to all JSON files
- Root `.htaccess` blocks access to dotfiles/dotfolders (`.git`, `.env`, etc.)
- **Never deploy with `.git/` in the webroot** — it exposes full commit history
- **Change the default admin password** before any site goes live
- **Set `CONTACT_EMAIL`** in `config.php` before deploying the contact form

### Breadcrumbs

`site-template.php` builds two separate breadcrumb arrays: `$bcItems` (relative URLs, for the visible nav) and `$bcSchemaItems` (absolute URLs, for the `BreadcrumbList` JSON-LD). Keep them separate — reusing one absolute-URL array for both previously caused visible breadcrumb clicks to navigate off-site.

### Blog System

- `data['posts']` is id-keyed: title, slug, status, published_at, updated_at, author, tag, excerpt, featured_image, featured_image_alt, content_blocks, seo
- `blog.php` is the router: `/blog` (listing + pagination + `?tag=` filter) and `/blog/{slug}` (single post)
- Tag is a single string per post. The listing page auto-renders a tag-pill bar — no configuration needed
- `post_meta` and `blog_list` are pseudo block types handled in `render_content_block()` but excluded from `allowed_block_types()` — `blog.php` generates them automatically

### Course Schedule System

Course data in `courses.json`. Each record: `id`, `course_type`, `delivery` (Live-Virtual / On-Demand), `dates`, `time_est`, `price`, `old_price`, `register_url`, `availability_note`, `guaranteed`, `sort_order`.

Shortcodes resolved inside `custom_html` blocks:
- `[course_schedule type="PMP Certification"]` — Widget 1: filterable table
- `[course_card type="PMP Certification" start_tab="1"]` — Widget 2: compact card widget
- Both accept `type="All"` to show all course types

**DOMContentLoaded ordering** — test page filter trigger scripts must be registered as `DOMContentLoaded` listeners placed *after* the `<script src="schedule.js">` tag. An inline IIFE immediately after the tag fires before `initAll()` runs.

### Caching (Hostinger)

Stylesheet and script links include `?v=filemtime(...)` for automatic cache-busting. If CSS changes aren't showing on the live site, hard-refresh with **Cmd+Shift+R** (Mac) or **Ctrl+Shift+R** (Windows).

---

*For a full list of what's built and what's planned, see the **Content Editor Roadmap**.*
*For day-to-day content entry, hand the **User Guidebook** to your content person.*
*For deploying a copy to a new city, see the **City Deployment Playbook**.*
