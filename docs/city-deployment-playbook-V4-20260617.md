# City Deployment Playbook
**Version 4 — 2026-06-17**

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
- [ ] New domain or subdomain for the city site (e.g. `sugarlandpestpros.com`)
- [ ] New hosting account credentials (FTP host, username, password, remote path)
- [ ] Phone number for the new city (if different)
- [ ] New average rating and review count (if different)
- [ ] New variation seed number (increment by 1 from the last site — write it down so you don't reuse it)

---

## Step 1 — Create the New Site in the Admin Panel

The system supports multiple sites from one codebase. To add a new city site:

1. Log into the admin at `yourbuilder.com/admin`
2. On the **Sites** screen (shown when no site is active), click **New Site**
3. Give the site a name (e.g. "Sugar Land Pest Pros") and a site ID slug (e.g. `sugar-land`)
4. Click **Create** — the system creates `sites/sugar-land/` with blank data and uploads folders

Alternatively, to clone an existing site's data:
1. Copy `sites/source-site/data/` to `sites/new-site/data/`
2. Copy `sites/source-site/uploads/` to `sites/new-site/uploads/`

> **Do not share `data/` or `uploads/` between cities.** Each city site must have its own independent copy of both folders.
>
> **Course data** lives in `data/courses.json` (copied with the site). Clear or update the course listings after cloning — the source site's course dates and URLs will still be in the JSON.

---

## Step 2 — Update Admin Credentials

Open `config.php` on the builder installation and confirm:
- `ADMIN_USERNAME` and `ADMIN_PASSWORD_HASH` are set (see Developer Methodology for hash generation)
- `SITE_TITLE` reflects the default site name
- `CONTACT_EMAIL` is set to the real email for contact form submissions

---

## Step 3 — Update Local Business Info (SEO/Schema Tab)

Log into the admin → select the new city site → **SEO / Schema** tab → **Local Business Info**.

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
- Update the **phone number** field if different for this city
- Update the **city/location** display field
- Confirm **navigation links** use generic slugs (no source-city names in URLs)

**Click Save Header.**

---

## Step 5 — Update Page Slugs and SEO Fields

City names are often baked into page slugs and canonical URLs (e.g. `cockroach-exterminator-katy-tx`). These need to be updated for each landing page.

### Fast method — direct JSON edit
1. Open `sites/{new-site-id}/data/site.json` in a text editor with Find & Replace
2. Replace all instances of the old city slug (e.g. `katy`) with the new city slug (e.g. `sugar-land`) — lowercase, hyphenated
3. Replace all instances of the old city name (e.g. `Katy`) with the new city name (e.g. `Sugar Land`) in any hardcoded headings
4. Replace all canonical URL domains (e.g. `katypestpros.com`) with the new domain
5. Save the file

### Thorough method — admin editor
For each landing page in the **Landing Pages** tab:
1. Update the **URL Slug** (e.g. `cockroach-exterminator-katy-tx` → `cockroach-exterminator-sugar-land-tx`)
2. Update the **Canonical URL** in the SEO fields
3. Update the **Service Area** field
4. Check the **Meta Description** for any hardcoded city names
5. Repeat for every page

> **Tip:** Content written with shortcodes (`{city}`, `{city_state}`) requires zero changes — it updates automatically. Content written as plain text needs manual find-and-replace. This is why shortcodes pay off on the first clone.

### Blog posts, Contact Us, and legal pages
- **Blog posts** — if written with shortcodes they update automatically; plain-text city mentions need manual find-and-replace
- **`/contact-us`** — written entirely with shortcodes, requires no manual edits
- **`/privacy-policy`** and **`/terms-and-conditions`** — written with shortcodes, update automatically. Slug is `/terms-and-conditions` (not `/terms-of-service`)

---

## Step 6 — Apply a New Image Variation Seed

This ensures Google sees the new city's images as distinct from the source site's images.

1. Go to **Media Library** tab
2. Find the **Site Variation Seed** panel
3. Change the seed number to the next unused number (e.g. if source site used seed 1, enter 2)
4. Click **Apply Variation to All Images**
5. Wait for the process to complete

> **Why this matters:** Google's image search uses perceptual fingerprinting. If two sites have pixel-identical images, Google may treat the content as duplicate. The variation applies a unique combination of horizontal flip, brightness shift, and 2–5% edge crop per image — enough to register as different, not enough to notice visually.

---

## Step 7 — Generate Static Site and Push via FTP

The Deploy tab generates a fully-static copy of the site and pushes it to the live server via FTP. No PHP required on the live server.

### Set up FTP credentials

1. Go to the **Deploy** tab
2. In the **Push to Server (FTP)** card, fill in:
   - **FTP Host** — bare IP address or hostname, **no `ftp://` prefix** (e.g. `109.106.248.17` not `ftp://109.106.248.17`)
   - **Username** — the FTP username for this domain on your host. On Hostinger, this is the domain-specific user (e.g. `u682938201.yourdomain.com`) — do not use the temp-domain user, it points to a different directory
   - **Password** — FTP password
   - **Port** — `21` for standard FTP (default)
   - **Remote Path** — the server directory where the site root (`index.html`) should live. On Hostinger: `/public_html`
   - **Passive mode** — leave checked (required by virtually all shared hosts)
3. In the **Build Settings** card, fill in:
   - **Canonical Domain** — full URL of the live site (e.g. `https://sugarlandpestpros.com`). Used in `sitemap.xml` and `robots.txt`
   - **Web3Forms Access Key** — optional; free key from web3forms.com. Powers the static contact form. Leave blank to omit the submit button
4. Click **Save FTP Settings** and **Save Build Settings**

### First-time push to a new server

If this is a fresh server (no files yet), clear the deploy manifest first so all files are treated as new:

```bash
# Run this from the builder's root directory
echo '{}' > sites/{site-id}/deploy_manifest.json
```

Then proceed with Generate → Push.

### Generate and push

1. Click **Generate Static Site** — watch the log until you see "Build complete"
2. Click **Push to Server** — watch the log until you see "Deploy complete"
3. Note the number of files uploaded — a fresh push of a typical site is 200–400 files

### Verify the live site

After a successful push:
- Visit the live domain — confirm your site appears (not Hostinger's placeholder page)
- Check a few internal pages
- Confirm images load
- Confirm the contact form submits (if Web3Forms key is set)

> **If Hostinger's placeholder page shows after a successful push:** The `.htaccess` file sets `DirectoryIndex index.html`. Hostinger's placeholder `default.php` can take priority if `.htaccess` isn't uploaded. Re-run the push and confirm `.htaccess` appears in the log. If it still shows the placeholder, delete `default.php` from Hostinger's file manager.

> **If the site was previously deployed with wrong credentials:** Uploading to a Hostinger temp-domain FTP user and the real-domain FTP user puts files in different directories. Always use the domain-specific FTP user.

---

## Step 8 — Go-Live Checklist

**Content**
- [ ] Home page loads with the correct city name (check hero heading)
- [ ] `{city}` shortcode resolves correctly — spot-check 2–3 pages
- [ ] `{phone}` shortcode shows the correct phone number
- [ ] Logo appears in header and footer
- [ ] Navigation links go to the right pages (no source-city slugs remaining)
- [ ] All landing page slugs match the new city
- [ ] Course schedule updated with new dates, prices, and registration URLs (if applicable)

**SEO**
- [ ] View page source on the home page → search for `application/ld+json` → confirm LocalBusiness schema shows the new city name, address, and phone
- [ ] Canonical URL in page source matches the new domain
- [ ] Meta description is not blank on the home page and main landing pages

**Images**
- [ ] Images load — no broken image icons
- [ ] Image variation was applied (seed number is different from the source site)

**Technical**
- [ ] SSL is active — the padlock shows in the browser (set up via Hostinger hPanel after pointing DNS)
- [ ] Visit a landing page slug directly — confirms static HTML routing works
- [ ] Contact form submits successfully (if using Web3Forms)

---

## Step 9 — Point the Domain

1. Point the new domain's DNS to the hosting account (A record → server IP)
2. Wait for DNS propagation (up to 24 hours, often faster)
3. Install an SSL certificate — Hostinger auto-provisions via Let's Encrypt once DNS propagates

---

## Per-City Tracking Sheet

Keep one row per deployed city so you don't lose track of seeds or domains.

| City | Domain | Site ID | Deployed | Seed # | Notes |
|---|---|---|---|---|---|
| Katy, TX | katypestpros.com | pest-template | ✅ | 1 | Source site |
| Sugar Land, TX | sugarlandpestpros.com | sugar-land | | 2 | |
| Pearland, TX | pearlandpestpros.com | pearland | | 3 | |
| Missouri City, TX | missouricitypestpros.com | missouri-city | | 4 | |
| Cypress, TX | cypresspestpros.com | cypress | | 5 | |

---

*For technical setup of a brand-new site, see the **Developer Methodology**.*
*For entering or editing page content, see the **User Guidebook**.*
*For a full list of available blocks and features, see the **Content Editor Roadmap**.*
