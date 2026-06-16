# Developer Methodology — Homepage Builder

> **Who this is for:** The person setting up or maintaining the system — a developer,
> the site owner doing technical setup, or anyone deploying a new site.
> Once setup is complete, hand the *User Guidebook* to whoever will enter content.

---

## Phase 1 — Build a Site from Scratch

Complete this once per new site installation, in order. At the end of Phase 1 you will have a live, indexed-ready site with a working home page.

---

### Step 1: Deploy the files

1. Upload the homepage-builder folder to your web server (`public_html` or equivalent)
2. Set folder permissions:
   ```
   chmod 775 data/
   chmod 775 uploads/
   ```
3. Confirm `.htaccess` is active — visit `yoursite.com/test-slug` and confirm it doesn't 404 with a server error (it will show a "page not found" message from the builder, which is correct)
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
6. Confirm `BASE_DIR`, `DATA_FILE`, and `UPLOAD_DIR` path constants point to the correct absolute paths for this server

Test: visit `yoursite.com/admin` — you should see the login screen. Log in.

### Step 3: Set Global Theme (Theme tab)

Work left to right through the color fields. Match these to the brand color guide or to an existing reference site.

- **Primary Color** — used for buttons, links, and icon highlights
- **Accent Color** — secondary highlights and hover states
- **Header Background / Header Text** — the top navigation bar
- **Content Background / Content Text** — the main page body
- **Footer Background / Footer Text** — the bottom bar
- **Font** — choose from the system font dropdown
- **Button Corner Radius** — `0` = square, `4` = slightly rounded, `24` = pill
- **Analytics & Tracking** — paste the full Google Analytics or GA4 `<script>` block; applies to every page automatically

Save → hard-refresh the browser to confirm colors apply.

### Step 4: Upload brand images to the Media Library (Media Library tab)

Upload all images before building any pages. You cannot pick an image in a block that hasn't been uploaded yet.

1. Drag and drop all images at once — JPG, PNG, or WebP, max 8MB each
2. All images auto-convert to WebP and resize to max 1800px on upload
3. For each image: fill in the **alt text** immediately (e.g. "pest control technician spraying in Katy TX kitchen")
4. For any image that will be used in a hero or split block: click **⊕** and set the **focal point** by clicking the most important subject in the image (face, pest, product)
5. Run **Find Duplicates** after your first batch upload to catch any accidental duplicates

### Step 5: Build the Header (Header tab)

- **Logo** — upload a transparent PNG (~200px wide). Upload a separate white version for the footer if the footer is dark
- **Announcement Bar** — short message at the very top (e.g. "Same-Day Service Available — Call Now"). Link field accepts `tel:`, `https://`, and `mailto:` formats
- **Phone Number** — displayed in the header and referenced by the `{phone}` shortcode
- **City / Location** — city name shown in the header
- **Navigation Menu** — add items in order. Use **+ Sub-menu** to add dropdown links under any item. Keep primary nav to 5–7 items
- **Social Links** — Facebook, Instagram, Yelp, Google Business Profile — paste the full profile URL

Save → preview the public home page to confirm the header renders.

### Step 6: Build the Footer (Footer tab)

- **Footer Logo** — upload a white or dark version of the logo as needed
- **Tagline** — one short line under the logo
- **Highlight Text** — bold callout (e.g. "Serving Katy & Surrounding Areas")
- **Phone / Email** — contact info in the footer
- **Footer Columns** — add 2–4 columns. Recommended structure:
  - Column 1: **Contact** type — shows phone + city automatically; add address and hours as extras
  - Column 2: **Links** type — "Services" with links to each service landing page
  - Column 3: **Links** type — "Service Areas" with links to each city page
- **Bottom Bar** — copyright text and legal links (Privacy Policy, Terms of Service)

Save → preview to confirm footer renders correctly.

### Step 7: Fill in LocalBusiness Schema (SEO / Schema tab)

This is the most important SEO step. Everything here feeds Google's LocalBusiness schema on every page and also powers all shortcodes.

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
| Latitude | 29.7858 |
| Longitude | -95.8245 |
| Price Range | $$ |
| Opening Hours | Mo-Fr 08:00-18:00, Sa 09:00-13:00 |
| Logo URL | Full URL to the logo image |
| Business Description | 2–3 sentences about the business |
| Average Rating | 4.8 |
| Review Count | 534 |

Save → view page source on the home page → search for `application/ld+json` → confirm the schema shows the correct values.

### Step 8: Build the Home Page (Home Page tab)

The home page is the trust anchor for the whole site. Build it first and use it as the content template for all landing pages.

Recommended block order (modeled on katypestpros.com):

| Position | Block Type | Purpose |
|---|---|---|
| 1 | `hero_split` | Main headline + hero photo. Use H1. Include `{city}` in the heading |
| 2 | `feature_columns` | 3–4 service icons — quick overview of what you offer |
| 3 | `feature_split` | "Why choose us" — photo + checklist of differentiators |
| 4 | `image_features` | Photo + checklist of service guarantees + phone CTA |
| 5 | `faq_two_col` | 6–8 common customer questions |
| 6 | `cta_banner` | "Call now for a free inspection" — solid color, centered |
| 7 | `map_info` | Google Maps embed + contact info |

Rules:
- Exactly **one H1** on the page — put it in the first block
- Use `{city}`, `{phone}`, `{business}` shortcodes in all headings and CTAs
- Every image block must have alt text
- Every block should have a CTA button or a phone number visible within 2 blocks — never make a visitor scroll too far to contact you

Save → visit the public home page and review desktop + mobile layouts.

### Step 8b: Set Up the Blog (Blog tab, optional)

The Blog tab is separate from Landing Pages — it has its own list view, post editor, and settings panel.

1. **Blog Settings** (top of the tab): set the blog page heading and intro text (shown at the top of `/blog`, supports shortcodes), and posts-per-page for pagination
2. **Add a New Post**: enter a title (slug auto-generates from it if left blank)
3. In the post editor, fill in: status (draft/published), published date, author, **tag** (single tag per post — readers can click it to filter `/blog?tag=...`), excerpt (falls back to the meta description if blank), featured image + alt text
4. Build the post body using the same content blocks editor as landing pages
5. Fill in the per-post SEO fields (same fields as a landing page)
6. **Preview Post** before publishing

The blog listing page (`/blog`) automatically renders a tag-pill bar (an "All" pill plus one pill per distinct tag in use) under its heading — this is generated automatically from your posts' tags; there's nothing to configure for it.

### Step 9: Build the Core Landing Pages (Landing Pages tab)

Create these pages before any city-specific pages. They become the templates you copy for each city deployment.

**Priority order:**

1. **Main service page** (e.g. `/pest-control-katy-tx`) — general overview of all services
2. **Individual pest pages** (one per pest type):
   - `/cockroach-exterminator-{city_slug}-tx`
   - `/ant-control-{city_slug}-tx`
   - `/termite-treatment-{city_slug}-tx`
   - `/mosquito-control-{city_slug}-tx`
   - `/rodent-control-{city_slug}-tx`
   - `/bed-bug-treatment-{city_slug}-tx`
   - `/wasp-removal-{city_slug}-tx`
   - `/flea-treatment-{city_slug}-tx`
3. **Legal & utility pages**:
   - `/contact-us` — built as a plain landing page (Landing Pages tab), not a special page type. Keep it simple: a short intro, a phone CTA button, and an email link — no contact form is required since there's no mail-sending backend to support one
   - `/privacy-policy`
   - `/terms-and-conditions`

   Write the privacy policy and terms in your own original wording covering the standard topics (information collected, cookies, third-party/advertiser sharing, data retention, children's privacy, your rights, liability limits, arbitration, IP/license terms, governing law). Don't copy another site's legal text verbatim and swap in your business name — that's still a copy. Use `{business}`, `{business_domain}`, `{phone}` shortcodes throughout so the content updates automatically on every city clone.

For each landing page:
1. Set URL slug
2. Build blocks (see User Guidebook for recommended block order)
3. Write all headings with `{city}` and `{city_state}` shortcodes — never hardcode the city name in body text
4. Fill in per-page SEO fields: canonical URL, meta description, Service schema fields
5. Save + preview

### Step 10: Pre-Launch Checklist

Before pointing the live domain, verify:

**Content**
- [ ] Home page H1 includes the city and primary keyword
- [ ] Every page has a meta description (no blanks)
- [ ] Every image has alt text
- [ ] No nav links point to `localhost` or staging URLs
- [ ] Phone number correct in header, footer, and all CTA blocks
- [ ] `{city}` resolves correctly — spot-check 3 pages in the browser

**Schema**
- [ ] View source → `application/ld+json` → LocalBusiness block shows correct name, address, phone
- [ ] Canonical URLs in page source match the live domain
- [ ] FAQPage schema present on pages with FAQ blocks (view source → search `FAQPage`)

**Technical**
- [ ] SSL certificate is active (padlock in browser)
- [ ] Pretty URLs work — visit `/cockroach-exterminator-katy-tx` directly
- [ ] Admin login works at `/admin`
- [ ] Images load — no broken icons
- [ ] Google Analytics fires — check Real-Time report in GA4

**After launch**
- [ ] Submit the sitemap to Google Search Console: `https://yoursite.com/sitemap.xml` (if generated)
- [ ] Request indexing for the home page in Google Search Console
- [ ] Set up Google Business Profile if not already active

---

## Phase 2 — Per-Page Workflow (repeat for every new page)

1. **Plan the page** — decide what the page covers, then create it and set its URL slug
2. **Build the content** — assemble blocks in order. Typical flow: `hero_split` → `feature_split` → `faq_two_col` → `cta_banner`
3. **Set heading structure** — exactly one H1 per page (the hero or first block), H2s for each major section
4. **Use shortcodes** — use `{city}`, `{phone}`, `{business}` in all headings and CTAs — never hardcode the city name
5. **Fill in per-page SEO fields** — canonical URL, meta description, OG image, Service schema fields
6. **Breadcrumbs** — middle crumb is optional; current page label defaults to the page title if left blank
7. **Preview the page** — check desktop and mobile before pushing to production

---

## Technical Reference

### File Structure
```
/
├── index.php                     Public homepage
├── page.php                      Landing page renderer
├── config.php                    Credentials, path constants, session_start()
├── .htaccess                     Pretty URL routing (Apache)
├── includes/
│   ├── functions.php             All business logic — load_data(), save_data(),
│   │                             render_content_block(), allowed_block_types(),
│   │                             resolve_shortcodes(), get_focal_point(),
│   │                             render_local_business_editor(), render_seo_editor(),
│   │                             schema generation, theme CSS vars
│   └── site-template.php         Shared HTML page template (header, block loop, footer)
├── admin/
│   ├── index.php                 Admin dashboard (9-tab panel incl. Blog + all JS)
│   ├── save.php                  Handles all admin form POSTs
│   ├── media_api.php             Media library API (upload, crop, focal, usage,
│   │                             duplicates, variation, dHash)
│   ├── login.php
│   └── logout.php
├── assets/
│   └── css/style.css             All styles — public site + admin panel
├── data/
│   ├── site.json                 All site content (back this up!)
│   ├── media.json                Media library metadata (url, alt, focal_x, focal_y,
│   │                             width, height, size, dhash, varied_seed)
│   ├── variation.json            Variation seed log (seed, date, count)
│   └── .htaccess                 Blocks direct web access to JSON files
└── uploads/                      Uploaded images (WebP)
```

### Data Storage
- `data/site.json` — all content: header, theme, home page blocks, landing pages, footer, popups, local business info, per-page SEO
- `data/media.json` — media library: one record per image with url, alt, focal_x, focal_y, width, height, file size, dhash fingerprint, varied_seed
- `data/variation.json` — seed log for the image variation system: `{seed, date, count}`
- Images are stored in `uploads/` as WebP with unique timestamped filenames
- Back up `data/` regularly — restoring it restores all content and media metadata

### Data Flow
1. `load_data()` reads `data/site.json` and deep-merges with `default_data()` so any new keys get defaults automatically
2. Public pages (`index.php`, `page.php`) call `load_data()`, set `$contentBlocks`, `$seo`, `$pageTitle`, and `$assetPathPrefix = '/'`, then `require includes/site-template.php`
3. `site-template.php` renders the full HTML: shared header, then loops `$contentBlocks` calling `render_content_block()` for each, then shared footer + all schema JSON-LD
4. Admin saves go to `admin/save.php` (POST only), keyed by `$_POST['section']` (header, theme, content, footer, pages, popups, local_business)
5. Media operations (upload, crop, focal point, variation, duplicates) go through `admin/media_api.php` as `action=` POST or GET requests

### Adding a New Block Type
Three changes required — all in `includes/functions.php`:
1. Add an entry to `allowed_block_types()` array — key is the type slug, value is the admin label
2. Add a `case 'type_slug':` in `render_content_block()` with the HTML rendering logic
3. Add the admin editor UI in `admin/index.php` inside `content_editor_scripts()` / the block card template

Block admin UI lives in `admin/index.php`. Block rendering lives in `functions.php`. Keep them separate.

### Image Processing Pipeline
All image processing uses PHP's GD library (v2.3.3, WebP support confirmed).

**On upload (`media_api.php` action=upload):**
1. Validate MIME type (jpeg/png/gif/webp) and size (max 8MB)
2. `img_optimize()` — decode source, resize if longest edge > 1800px (preserves aspect ratio), re-encode as WebP at quality 82
3. Compute `dhash` — 64-bit perceptual fingerprint stored in media.json
4. Store record in `data/media.json` (url, alt, focal_x, focal_y, width, height, size, dhash)

**Crop tool (`media_api.php` action=crop):**
- Receives x, y, width, height in pixels from Cropper.js
- `imagecrop()` on the source file, then `img_optimize()` to re-encode WebP
- Updates dimensions and size in media.json

**Focal point (`media_api.php` action=focal):**
- Stores `focal_x`, `focal_y` (0–100 percentage) in the image's media.json record
- `get_focal_point(string $url): string` looks up the values and returns `"50% 50%"` format
- Applied as CSS `object-position` on `<img>` tags and `background-position` on background-image divs in 7 block types

**dHash fingerprinting:**
- 64-bit difference hash: resize source to 9×8 grayscale, compare adjacent horizontal pixels, encode as 16-char hex
- Stored as `dhash` field in media.json on upload (or via `action=hash_all` for backfill)
- `action=dupes` does O(n²) pairwise Hamming distance comparison with threshold 10/64 (≈84% similarity)

### Image Variation Seed (Multi-City Deployment)
Used when the same images are deployed across 1000s of city landing pages so Google sees each as distinct.

**How it works:**
- `data/variation.json` stores the current seed (integer), date applied, and count
- `action=vary_batch` iterates all images, skips any whose `varied_seed` already matches the current seed (idempotent)
- For each image, `get_variation_params(int $seed, string $filename)` derives parameters via CRC32:
  - `flip` (horizontal mirror) — 50% probability
  - `brightness` — −8 to +8 GD brightness units
  - `crop_side` — 0=none, 1=top, 2=bottom, 3=left, 4=right
  - `crop_pct` — 2–5% of edge removed
  - Guarantee: if no flip and no crop, flip is forced (ensures Hamming distance > 10 from original)
- `apply_variation()` applies transforms in GD and saves as WebP
- After applying, `varied_seed` is written to that image's media.json record
- On next deployment (new seed), only images with a different `varied_seed` are re-varied

To vary a batch: go to Media Library tab → "Site Variation Seed" panel → click "Apply Variation to All Images".

### Shortcodes System
`resolve_shortcodes(string $text): string` replaces tokens from global site.json fields.

| Token | Source field | Example output |
|---|---|---|
| `{city}` | `local_business.lb_city` | Katy |
| `{state}` | `local_business.lb_state` (full name lookup) | Texas |
| `{SS}` | `local_business.lb_state` (2-char) | TX |
| `{city_state}` | city + state | Katy, TX |
| `{city_slug}` | slugified city | katy |
| `{business}` | `local_business.lb_name` | Katy Pest Pros |
| `{phone}` | `local_business.lb_phone` | (281) 215-0160 |
| `{zip}` | `local_business.lb_zip` | 77494 |
| `{website}` | `local_business.lb_url` | https://katypestpros.com |
| `{rating}` | `local_business.lb_rating` | 4.8 |
| `{review_count}` | `local_business.lb_review_count` | 534 |

Shortcodes are resolved in:
- All block text fields via `apply_shortcodes_to_block()` before rendering
- Schema fields (Service schema, FAQPage, HowTo) via direct `resolve_shortcodes()` calls
- Not resolved in raw HTML blocks (`custom_html`) — intentional

### Schema / SEO System
All schema is rendered as inline `<script type="application/ld+json">` tags in the `<head>`.

| Schema type | Trigger | Source |
|---|---|---|
| `LocalBusiness` | Every page | SEO/Schema tab — Local Business Info section |
| `Service` | Pages with `service_name` filled in | Per-page SEO fields |
| `FAQPage` | Pages with `faq` or `faq_two_col` blocks | Block content, questions + answers |
| `HowTo` | Pages with `steps` blocks | Block heading = HowTo name, each step item = HowToStep |
| `BreadcrumbList` | Every page | Page title + optional middle crumb from per-page SEO |

Open Graph tags (`og:title`, `og:description`, `og:image`) are in the HTML `<head>` on every page.

### Blog System
- `data['posts']` is an id-keyed array (`default_post_data()` schema): title, slug, status, published_at, updated_at, author, tag, excerpt, featured_image, featured_image_alt, content_blocks, seo
- `data['blog_settings']` holds `blog_heading`, `blog_intro`, `posts_per_page`
- `blog.php` is the router: `/blog` (listing, supports `?tag=` filter and `?p=` pagination) and `/blog/{slug}` (single post) — it builds synthetic content blocks (`post_meta` + the post's own blocks for a single post, or `blog_list` for the listing) and shares `includes/site-template.php` with `page.php`
- `post_meta` and `blog_list` are pseudo block types — handled in `render_content_block()` but deliberately left out of `allowed_block_types()` since `blog.php` generates them automatically; they're never picked from the admin block editor
- Tag is a single string per post; `/blog?tag=slug` filters by `slugify()` match. The blog listing page automatically renders a tag-pill bar (an "All" pill plus one pill per distinct tag) under its heading — listing page only, not single-post pages
- Admin UI lives in its own **Blog tab** (`?tab=blog` in `admin/index.php`) — Blog Settings form, Add New Post form, Posts list, and a full per-post editor (same content-block and SEO editors as landing pages)

### URL Routing
- Landing pages render via `page.php?slug=your-slug`
- `.htaccess` rewrites `/your-slug` → `page.php?slug=your-slug` on Apache
- Slugs are validated against a reserved list in `reserved_slugs()` and de-duplicated by `unique_slug()`
- Reserved slugs include `admin`, `uploads`, `data`, `assets`, `page`, `index`

### Breadcrumbs
`site-template.php` builds two separate breadcrumb arrays: `$bcItems` (relative URLs, used by the visible breadcrumb nav) and `$bcSchemaItems` (absolute URLs, used only for the `BreadcrumbList` JSON-LD, since schema.org requires absolute URLs there). Keep these separate when editing breadcrumb code — reusing one absolute-URL array for both previously caused visible breadcrumb clicks to navigate off-site.

### Security
- Session-based login with bcrypt-hashed credentials in `config.php`
- CSRF tokens on every admin form (`$_SESSION['csrf_token']`)
- Session regeneration on login (prevents session fixation)
- Login lockout: 10 failed attempts per IP → 15-minute block (stored in session)
- File uploads: MIME type validation + 8MB cap + WebP re-encoding strips embedded malware
- `topbar_link` accepts only `https`, `http`, `tel`, `mailto` URL schemes
- `data/.htaccess` blocks direct HTTP access to all JSON files

### Caching (Hostinger)
Stylesheet and script links include `?v=filemtime(...)` for automatic cache-busting. If CSS changes aren't showing on the live site, hard-refresh with **Ctrl+Shift+R** (Windows) or **Cmd+Shift+R** (Mac), or clear the cache from Hostinger hPanel.

---

*For a full list of what's built and what's planned, see the **Content Editor Roadmap**.*
*For day-to-day content entry, hand the **User Guidebook** to your content person.*
