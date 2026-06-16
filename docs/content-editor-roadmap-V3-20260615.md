# Content Editor Roadmap — Homepage Builder

> Tracks what's built, what's planned, and how it maps to the reference site (katypestpros.com).
> ✅ = built and tested in current version | ☐ = planned / next phase

---

## Phase 1 — Built (Current Version)

### Admin Panel
- ✅ Nine-tab admin panel: Header, Theme/Colors, Home Page, Landing Pages, Blog, Footer, Popups, Media Library, SEO/Schema
- ✅ Session-based login with bcrypt credentials
- ✅ CSRF protection on all forms
- ✅ Login lockout after 10 failed attempts (per IP, 15-minute window)
- ✅ Session regeneration after login

### Header
- ✅ Logo upload (transparent PNG)
- ✅ Top announcement bar (text + link)
- ✅ Phone number and city/location display
- ✅ Navigation menu with dropdown sub-menus (add/edit/reorder/delete items)
- ✅ Social media links (Facebook, Instagram, Yelp, Google, etc.)

### Theme / Colors
- ✅ Seven-field color system: primary, accent, header bg, header text, content bg, footer bg, footer text
- ✅ Primary font selector
- ✅ Button corner radius control
- ✅ Analytics & Tracking field (Google Analytics / GA4 / Facebook Pixel — site-wide)
- ✅ Cache-busting version parameter on stylesheet links

### Home Page & Landing Pages
- ✅ Repeatable content blocks with up/down reorder controls
- ✅ 26 block types (see full list below)
- ✅ Per-block heading level selector (H1, H2, H3, Paragraph)
- ✅ Per-block padding control (compact / normal / large)
- ✅ Per-block image with alt text, focal point, and crop tool
- ✅ CTA button (label + URL) per block
- ✅ Custom URL slugs for landing pages
- ✅ Pretty URLs via root `.htaccess`
- ✅ Shared global header, footer, and colors across all pages
- ✅ Shortcode system — `{city}`, `{phone}`, `{business}`, etc. auto-resolved on render

### Block Types Available (26 total)
- ✅ `hero` — Hero / Banner (full-width bg image, headline, subtext, CTA button)
- ✅ `hero_split` — Hero Split (text left, image right with caption)
- ✅ `hero_grid` — Hero Grid (image left panel, 2×2 icon grid right)
- ✅ `text` — Text only (heading + body, no image)
- ✅ `image_left` — Image left, text right
- ✅ `image_right` — Text left, image right
- ✅ `image_text` — Image & Text (side by side, flexible)
- ✅ `feature_split` — Feature Split (photo left, icon checklist right)
- ✅ `feature_columns` — Feature Columns (2–4 icon/heading/text columns)
- ✅ `service_cards` — Service Cards Grid (icon + heading + text, centered)
- ✅ `split_cta` — Split CTA (colored left panel + phone button right panel)
- ✅ `cta_banner` — CTA Banner (solid color, centered heading + button)
- ✅ `cta_card` — CTA Card (colored box, heading left, phone button right)
- ✅ `cta_button` — CTA Button (standalone call-to-action)
- ✅ `tab_services` — Tab Services (vertical tabs left, image right)
- ✅ `wide_banner` — Wide Banner (full-width bg image, heading left, button right)
- ✅ `image_features` — Image + Feature List (photo left, checklist + phone right)
- ✅ `links_grid` — Links Grid (bg image, heading, grid of link buttons)
- ✅ `map_info` — Map + Info (Google map left, text + photo right)
- ✅ `faq` — FAQ / Accordion (expandable Q&A pairs)
- ✅ `faq_two_col` — FAQ Two Column (2-col accordion with icon + heading)
- ✅ `steps` — Process Steps (Step 1, 2, 3… — generates HowTo schema)
- ✅ `stats` — Stats / Counters
- ✅ `cards` — Cards Grid (image + heading + text + link)
- ✅ `gallery` — Photo Gallery / Image Grid
- ✅ `custom_html` — Custom HTML (Google Maps embed, review widgets, iframes)

### Footer
- ✅ Footer logo upload
- ✅ Tagline and highlight text
- ✅ Phone and email display
- ✅ Multi-column footer sections — three column types: links, text, contact
- ✅ Bottom bar: copyright text + legal page links (Privacy, Terms, Sitemap)
- ✅ Colors follow global Theme settings

### Popups
- ✅ Pop-up builder with trigger options (on load, on scroll, on exit intent)
- ✅ Popup content blocks (text, image, CTA)

### Media Library
- ✅ Central image library — upload once, reuse across any page or block
- ✅ Auto-conversion to WebP on upload (GD library v2.3.3)
- ✅ Auto-resize to max 1800px on longest edge
- ✅ Drag-and-drop multi-file upload
- ✅ Alt text stored per image in media.json
- ✅ Crop tool (Cropper.js) — free crop, 16:9, 4:3, 3:2, 1:1, 9:16 presets
- ✅ Focal point selector — click to set the subject center; all image blocks apply it automatically via CSS `object-position`
- ✅ Usage tracker — each image card shows which pages/blocks it's used on; deleting a used image shows a warning listing every affected location
- ✅ Duplicate detector — perceptual hashing (dHash 64-bit) + Hamming distance finds near-identical images; side-by-side review with Keep/Delete controls
- ✅ Image variation seed — one-click batch variation for multi-city deployment: flips, brightness shift, and edge crop applied deterministically per image; prevents Google from treating cross-site images as duplicates

### SEO / Schema (Global — SEO tab)
- ✅ LocalBusiness JSON-LD schema on every page (name, address, phone, lat/lng, hours, rating)
- ✅ Business type selector (LocalBusiness, PestControlService, Plumber, Electrician, etc.)
- ✅ AggregateRating included when rating + review count are filled
- ✅ `{rating}` and `{review_count}` shortcodes pull from global LocalBusiness settings

### SEO / Schema (Per-Page — each landing page)
- ✅ Canonical URL field
- ✅ Meta description (shown in Google search results)
- ✅ Meta keywords
- ✅ Open Graph title, description, and image (social share preview)
- ✅ Service schema — auto-generated with service name, type, area served, description
- ✅ FAQPage schema — auto-generated from any `faq` or `faq_two_col` block on the page
- ✅ HowTo schema — auto-generated from any `steps` block on the page
- ✅ BreadcrumbList schema — auto-generated; supports optional middle crumb
- ✅ Visual breadcrumb trail rendered at top of page content
- ✅ Custom JSON-LD schema field (freeform override)

### Shortcodes (resolved automatically on render and in schema)
- ✅ `{city}` — city name (e.g. Katy)
- ✅ `{state}` — full state name (e.g. Texas)
- ✅ `{SS}` — state abbreviation (e.g. TX)
- ✅ `{city_state}` — city + state (e.g. Katy, TX)
- ✅ `{city_slug}` — URL-safe city (e.g. katy)
- ✅ `{business}` — business name
- ✅ `{phone}` — phone number
- ✅ `{zip}` — ZIP code
- ✅ `{website}` — website URL
- ✅ `{rating}` — aggregate rating score
- ✅ `{review_count}` — total number of reviews

### Blog System
- ✅ Dedicated **Blog** admin tab — Blog Settings (heading, intro, posts per page), Add New Post, Posts list, full per-post editor
- ✅ Post schema: title, slug, status (draft/published), published date, author, tag, excerpt, featured image + alt text, content blocks, per-post SEO
- ✅ `/blog` listing page with pagination and an auto-generated tag filter bar (one pill per distinct tag in use)
- ✅ `/blog/{slug}` single-post pages, sharing the same content-block rendering and SEO/schema system as landing pages
- ✅ Tag filtering via `/blog?tag=...`

### Contact & Legal Pages
- ✅ `/contact-us` — built as a plain landing page: short intro, phone CTA button, email link (no form, since there's no mail-sending backend)
- ✅ `/privacy-policy` and `/terms-and-conditions` — plain landing pages, written in original wording using `{business}`, `{business_domain}`, `{phone}`, `{website}` shortcodes so they update automatically on every city clone. (Never reproduce another site's legal text verbatim, even when adapting your own other site — write original copy covering the same topics instead.)

### Data & Storage
- ✅ All site content in `data/site.json` (flat file, no database)
- ✅ All media metadata in `data/media.json`
- ✅ Variation seed log in `data/variation.json`
- ✅ Images in `uploads/` as WebP with unique filenames
- ✅ `data/.htaccess` blocks direct web access to JSON files

---

## Phase 2 — Planned

### Admin Features
- ☐ Duplicate page — clone an existing landing page as a starting point
- ☐ Page status (Published / Draft) — build pages before they go live
- ☐ Bulk page operations (duplicate, export, status change)

### AI Content Assist
- ☐ "AI Assist" button on text blocks (prompt: "make more persuasive", "shorten", "rewrite for SEO")
- ☐ Server-side PHP endpoint calls Claude API, returns rewritten text
- ☐ Preview with Apply/Discard before overwriting
- ☐ Requires Anthropic API key in `config.php`

### SEO & Performance
- ☐ Sitemap.xml auto-generator (lists home + all active landing pages)
- ☐ robots.txt editor in admin
- ☐ Image lazy-load for blocks below the fold (performance)

### Block Types
- ☐ Testimonials / Reviews block — name, quote, star rating
- ☐ Before/After image slider
- ☐ Video embed block (YouTube / Vimeo URL)
- ☐ Team / Staff profiles block

---

## Reference Site Mapping (katypestpros.com)

| Section | Block Type | Status |
|---|---|---|
| Announcement bar | Header setting | ✅ |
| Logo + nav | Header setting | ✅ |
| Hero/banner | `hero` block | ✅ |
| Hero with image right | `hero_split` block | ✅ |
| Services overview (icon grid) | `feature_columns` block | ✅ |
| About text + image | `image_left` / `image_right` block | ✅ |
| Why choose us (icon list) | `feature_split` block | ✅ |
| Service area map | `map_info` or `custom_html` block | ✅ |
| Reviews / testimonials | Testimonials block | ☐ Phase 2 |
| FAQ | `faq` or `faq_two_col` block | ✅ |
| CTA section | `cta_banner` or `cta_card` block | ✅ |
| Service links grid | `links_grid` block | ✅ |
| Phone CTA strip | `split_cta` block | ✅ |
| Full-width image banner | `wide_banner` block | ✅ |
| Footer columns | Footer setting | ✅ |
| Footer bottom bar | Footer setting | ✅ |
| Blog | Blog tab (posts + listing/single routing) | ✅ |
| Contact Us page | Landing page (intro + phone CTA + email, no form) | ✅ |
| Privacy Policy / Terms and Conditions | Landing pages (original wording, shortcoded) | ✅ |

18 of 19 reference sections are fully supported in the current version.

---

*For technical setup and deployment, see the **Developer Methodology**.*
*For day-to-day content entry, see the **User Guidebook**.*
