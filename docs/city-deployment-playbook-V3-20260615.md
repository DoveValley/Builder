# City Deployment Playbook

> **Who this is for:** The person deploying a copy of the site to a new city or service area.
> This assumes you already have a working source site (e.g. katypestpros.com) and want to
> replicate it to a new location (e.g. Sugar Land, Pearland, Missouri City).
>
> Time estimate: 30–60 minutes per city once you have the process down.

---

## Before You Start

Have these ready before you touch any files:

- [ ] New city name, state, ZIP code
- [ ] New city latitude and longitude (look up on Google Maps → right-click the city center → copy coords)
- [ ] New domain or subdomain for the city site (e.g. `sugarlandpestpros.com` or `sugarland.yoursite.com`)
- [ ] New hosting account or subdirectory on your server
- [ ] Phone number for the new city (if different)
- [ ] New average rating and review count (if different)
- [ ] New variation seed number (increment by 1 from the last site — write it down so you don't reuse it)

---

## Step 1 — Copy the Source Site Files

### Option A: New hosting account (separate domain)
1. Download the entire source site as a ZIP from your hosting control panel
2. Create a new hosting account for the new city domain
3. Upload and extract the ZIP to the new account's `public_html`
4. Confirm `data/` and `uploads/` are writable (`chmod 775`)

### Option B: Subdirectory on the same server
1. Copy the site folder to a new directory (e.g. `/public_html/sugarland/`)
2. Update `.htaccess` rewrite rules — prefix the `RewriteBase` with the subdirectory path
3. Update `config.php` path constants to point to the new subdirectory

> **Do not share `data/` or `uploads/` between cities.** Each city site must have its own independent copy of both folders.

---

## Step 2 — Update Admin Credentials

Open `config.php` on the new site and change:
- `ADMIN_USERNAME` — use the same or a new username
- `ADMIN_PASSWORD_HASH` — generate a new hash for this deployment:
  ```
  php -r "echo password_hash('your-password', PASSWORD_DEFAULT);"
  ```
- `SITE_TITLE` — update to the new city (e.g. "Sugar Land Pest Pros")
- Confirm `BASE_DIR`, `DATA_FILE`, `UPLOAD_DIR` paths are correct for the new location

---

## Step 3 — Update LocalBusiness Info (SEO/Schema Tab)

Log into the new site's admin → **SEO / Schema** tab → **Local Business Info**.

Update every location-specific field:

| Field | What to change |
|---|---|
| Business Name | New city business name (e.g. "Sugar Land Pest Pros") |
| Website URL | New domain (e.g. `https://sugarlandpestpros.com`) |
| Phone | New city phone number (if different) |
| Street Address | New city address (or leave blank for service-area only) |
| City | New city name (e.g. `Sugar Land`) |
| State | State (e.g. `TX`) |
| ZIP code | New ZIP |
| Latitude / Longitude | New city coords |
| Average Rating | Update if different |
| Review Count | Update if different |

**Click Save.**

> This is the most important step. The city name, phone, rating, and all shortcodes (`{city}`, `{city_state}`, `{phone}`, `{rating}`, `{review_count}`) pull from these fields automatically — updating them here updates every page on the site instantly.

---

## Step 4 — Update the Header

Go to **Header** tab:
- Update the **phone number** field if it's different for this city
- Update the **city/location** display field
- Update any **navigation links** that reference the source city in their URL (e.g. if any nav links point to `https://katypestpros.com/...` — update to the new domain)

**Click Save Header.**

---

## Step 5 — Update Page Slugs and SEO Fields

City names are often baked into page slugs and canonical URLs (e.g. `cockroach-exterminator-katy-tx`). These need to be updated for each landing page.

### Fast method — direct JSON edit
1. Download `data/site.json` from the new site
2. Open it in a text editor with Find & Replace
3. Replace all instances of the old city slug (e.g. `katy`) with the new city slug (e.g. `sugar-land`) — lowercase, hyphenated
4. Replace all instances of the old city name (e.g. `Katy`) with the new city name (e.g. `Sugar Land`) in any hardcoded headings or text that wasn't written with shortcodes
5. Replace all canonical URL domains (e.g. `katypestpros.com`) with the new domain
6. Save and re-upload `data/site.json`

### Thorough method — admin editor
For each landing page in the **Landing Pages** tab:
1. Update the **URL Slug** (e.g. `cockroach-exterminator-katy-tx` → `cockroach-exterminator-sugar-land-tx`)
2. Update the **Canonical URL** in the SEO fields
3. Update the **Service Area** field (e.g. "Katy, TX" → "Sugar Land, TX")
4. Check the **Meta Description** — if it contains the city name as plain text (not a shortcode), update it
5. Repeat for every page

> **Tip:** Content written with shortcodes (`{city}`, `{city_state}`) requires zero changes — it updates automatically. Content written as plain text needs manual find-and-replace. This is why shortcodes pay off on the first clone.

### Blog posts, Contact Us, and legal pages
- **Blog posts** (Blog tab) follow the same rule as landing pages: if a post body uses `{city}`/`{business}`/`{phone}` shortcodes, it updates automatically on clone; any plain-text city or business mentions need manual find-and-replace per post.
- **`/contact-us`** is written entirely with shortcodes (`{phone}`, `{business_domain}`) — it requires no manual edits on clone.
- **`/privacy-policy`** and **`/terms-and-conditions`** are also written with `{business}` / `{business_domain}` / `{phone}` / `{website}` shortcodes throughout, so they update automatically too. Their slug is `/terms-and-conditions` (not `/terms-of-service`).

---

## Step 6 — Apply a New Image Variation Seed

This step ensures Google sees the new city's images as distinct from the source site's images.

1. Go to **Media Library** tab
2. Find the **Site Variation Seed** panel (top of the media library page)
3. Change the seed number to the next unused number (e.g. if source site used seed 1, enter 2)
4. Click **Apply Variation to All Images**
5. Wait for the process to complete — it processes every image in the library

> **Why this matters:** Google's image search uses perceptual fingerprinting. If two sites have pixel-identical images, Google may treat the content as duplicate. The variation applies a unique combination of horizontal flip, brightness shift, and 2–5% edge crop per image — enough to register as different, not enough to notice visually. Use a different seed number for every city site.

---

## Step 7 — Point the Domain and Deploy

1. Point the new domain's DNS to the new hosting account (A record or CNAME)
2. Wait for DNS propagation (up to 24 hours, often faster)
3. Install an SSL certificate if your host doesn't auto-provision one (Let's Encrypt or equivalent)
4. Confirm `.htaccess` pretty URLs are working — visit `yoursite.com/cockroach-exterminator-sugar-land-tx` directly and confirm it loads without a 404

---

## Step 8 — Go-Live Checklist

Work through this before calling the site done:

**Content**
- [ ] Home page loads with the correct city name (check breadcrumb and hero heading)
- [ ] `{city}` shortcode resolves correctly — spot-check 2–3 pages
- [ ] `{phone}` shortcode shows the correct phone number
- [ ] Logo appears in header and footer
- [ ] Navigation links go to the right pages on this domain (not the source site)
- [ ] All landing page slugs match the new city (no `katy-tx` slugs remaining)

**SEO**
- [ ] View page source on the home page → search for `application/ld+json` → confirm the LocalBusiness schema shows the new city name, address, and phone
- [ ] Canonical URL in page source matches the new domain
- [ ] Breadcrumb trail shows the correct page names
- [ ] Meta description is not blank on at least the home page and main landing pages
- [ ] Open Graph image is set on key pages (check by pasting the URL into the [Facebook Sharing Debugger](https://developers.facebook.com/tools/debug/))

**Images**
- [ ] Images load — no broken image icons
- [ ] Image variation was applied (check Media Library — images should look slightly different from source site)
- [ ] Focal points are visible — hero images and split blocks should show the subject, not cropped to an empty area

**Technical**
- [ ] Admin login works at `https://newdomain.com/admin`
- [ ] SSL is active — the padlock shows in the browser
- [ ] Hard 404s return properly (visit `yoursite.com/fake-page` — it should 404, not 500)
- [ ] Google Analytics or GA4 tracking code is in the Theme tab (or update it if you're using a property per city)

---

## Per-City Tracking Sheet

Keep one row per deployed city so you don't lose track of seeds or domains.

| City | Domain | Deployed | Seed # | Notes |
|---|---|---|---|---|
| Katy, TX | katypestpros.com | ✅ | 1 | Source site |
| Sugar Land, TX | sugarlandpestpros.com | | 2 | |
| Pearland, TX | pearlandpestpros.com | | 3 | |
| Missouri City, TX | missouricitypestpros.com | | 4 | |
| Cypress, TX | cypresspestpros.com | | 5 | |

---

*For technical setup of a brand-new site, see the **Developer Methodology**.*
*For entering or editing page content, see the **User Guidebook**.*
*For a full list of available blocks and features, see the **Content Editor Roadmap**.*
