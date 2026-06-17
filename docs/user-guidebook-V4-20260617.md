# User Guidebook — Homepage Builder Admin Panel
**Version 4 — 2026-06-17**

> **Who this is for:** Anyone entering or editing content in the admin panel —
> the site owner, a virtual assistant, or a Fiverr content helper.
> You do not need to know anything about code to use this.
>
> *If you're setting up the system for the first time, read the **Developer Methodology** first.*

---

## Logging In

1. Go to your site's admin URL: `https://yoursite.com/admin`
2. Enter your username and password
3. Click **Log In**

> If you enter the wrong password 10 times, the account locks for 15 minutes.

---

## The Admin Panel — 11 Tabs

| Tab | What it controls |
|---|---|
| **Header** | Logo, announcement bar, phone number, navigation menu, social links |
| **Theme/Colors** | Colors, fonts, button style, analytics tracking codes |
| **Home Page** | Content blocks on the main homepage |
| **Landing Pages** | All other pages (service pages, city pages, contact us, legal pages, etc.) |
| **Blog** | Blog settings, blog posts (listing + individual post editor) |
| **Footer** | Logo, contact info, footer columns, bottom bar |
| **Popups** | Popup windows shown to visitors |
| **Media Library** | All uploaded images — upload, crop, manage, reuse |
| **SEO / Schema** | Business info and schema markup shown to Google |
| **Schedule** | Course schedule — add, edit, and manage course listings |
| **Deploy** | Generate a static version of the site and push it to your web host via FTP |

Always click **Save** after making changes on any tab.

---

## Header Tab

1. **Logo** — click "Choose File" to upload (PNG with transparent background works best)
2. **Announcement Bar** — thin bar at the top. Type a short message and link (e.g. `tel:+12815550000` for a phone tap)
3. **Phone Number** — displayed prominently in the header
4. **Navigation Menu** — add, edit, or remove links in the top menu
   - Click **Add Item** to add a new link
   - Set the Label (what visitors see) and the URL (where it goes)
   - Click **+ Sub-menu** on any item to add dropdown links under it
5. **Social Links** — paste your Facebook, Instagram, Yelp, or Google Business URLs
6. Click **Save Header**

---

## Theme / Colors Tab

1. Click any color swatch to open the picker, or type a hex code (e.g. `#1a4b2e`)
2. Color fields:
   - **Primary Color** — buttons, links, highlights
   - **Accent Color** — secondary highlights
   - **Header Background / Header Text**
   - **Content Background / Content Text**
   - **Footer Background / Footer Text**
3. **Font** — choose from the dropdown
4. **Button Style** — slide the corner radius to make buttons square, slightly rounded, or pill-shaped
5. **Analytics & Tracking** — paste your Google Analytics or Facebook Pixel code here; it applies to every page automatically
6. Click **Save Theme**

---

## Home Page Tab

1. You'll see a list of content blocks in the order they appear on the page
2. **To add a block:** click **Add Block** at the bottom
3. **To reorder blocks:** click the ▲ ▼ arrows on any block
4. **To delete a block:** click the red ✕ (save first — this cannot be undone)
5. Click **Save Home Page** when done

Each block has a **Block Type** selector. See the Block Types section below for what each one does.

---

## Landing Pages Tab

### Creating a New Page
1. Click **Landing Pages** tab → **New Page**
2. Set the **Page Title** (shown in the browser tab and at the top of the page)
3. Set the **URL Slug** — the end of the URL. Example: `cockroach-exterminator-katy-tx` → `yoursite.com/cockroach-exterminator-katy-tx`
   - Lowercase letters and hyphens only — no spaces
4. Build the page using blocks (same as the Home Page tab)
5. Fill in the **SEO fields** (see Per-Page SEO section below)
6. Click **Save Page**

### Editing an Existing Page
1. Click **Landing Pages** tab → find the page → click **Edit**
2. Make changes → click **Save Page**

### Deleting a Page
- Scroll to the bottom of the page editor → click **Delete This Page**
- This cannot be undone

### Special Pages Built This Way
- **Contact Us** (`/contact-us`) — add a `contact_form` block for the full contact form, or keep it simple with just a phone CTA and email link
- **Privacy Policy** (`/privacy-policy`) and **Terms and Conditions** (`/terms-and-conditions`) — plain landing pages written with `{business}`, `{phone}`, `{website}` shortcodes so they update automatically when you change Local Business Info

---

## Blog Tab

Use this tab for blog posts — it's separate from Landing Pages and has its own settings, post list, and post editor.

### Blog Settings
- **Blog Heading** and **Blog Intro** — shown at the top of the `/blog` listing page (supports shortcodes)
- **Posts Per Page** — how many posts show before pagination kicks in

### Adding a Post
1. In the **Add a New Post** box, type a title (the URL slug auto-generates from it, or you can set your own)
2. Click **Add Post** — this opens the post in the editor

### Editing a Post
Each post has:
- **Status** — Draft or Published (drafts don't appear on the public `/blog` listing)
- **Published Date**
- **Author**
- **Tag** — a single tag per post. Visitors can click a post's tag to see other posts with the same tag at `/blog?tag=...`
- **Excerpt** — short summary shown on the blog listing card (if left blank, falls back to the meta description)
- **Featured Image** + **Featured Image Alt Text** — shown on the listing card and at the top of the post
- **Content Blocks** — build the post body using the same block types as landing pages
- **SEO fields** — same fields as a landing page

Click **Preview Post** to see it before publishing, then **Save**.

### The Blog Listing Page
`/blog` automatically shows a tag filter bar (an "All" pill plus one pill per tag in use) above the post list — this is generated automatically, nothing to configure.

### Deleting a Post
Click **Delete** next to the post in the Posts list. This cannot be undone.

---

## Schedule Tab

Use this tab to manage course listings. Courses can be displayed on any page using the `[course_schedule]` or `[course_card]` shortcodes inside a **Custom HTML** block.

### Adding a Course
1. Click **Schedule** tab → **Add New Course**
2. Fill in the fields:
   - **Course Type** — the name of the course (e.g. "PMP Certification")
   - **Delivery** — Live-Virtual or On-Demand
   - **Dates** — date or date range to display (e.g. "Jul 7, 8, 9, 10" or "Available Now")
   - **Time** — start and end time, or check **Self-paced** for on-demand courses
   - **Price** — current price
   - **Sale Price** — original price (shown with strikethrough if set)
   - **Register URL** — link to the registration page
   - **Availability Note** — optional short note (e.g. "4 seats left")
   - **Guaranteed** — check if the class is guaranteed to run
   - **Sort Order** — controls the display order; lower numbers appear first
3. Click **Save Course**

### Editing or Duplicating a Course
- Click **Edit** next to any course to update its details
- Click **Copy** to duplicate a course (useful for a recurring session with the same details)
- Click **Delete** to remove a course — this cannot be undone

### Displaying Courses on a Page
Add a **Custom HTML** block to any page and paste one of these shortcodes:

| Shortcode | What it shows |
|---|---|
| `[course_schedule type="PMP Certification"]` | Full filterable table — visitors can filter by course type, delivery mode, and timezone |
| `[course_card type="PMP Certification" start_tab="1"]` | Compact card widget with Live-Virtual and On-Demand tabs |
| `[course_schedule type="All"]` | Table showing all course types |
| `[course_card type="All"]` | Card widget showing all course types |

---

## Deploy Tab

The Deploy tab generates a fully-static copy of your site and pushes it to your web host via FTP. This is how you publish the site to a live domain without needing PHP on the web server.

### Step 1 — Save Build Settings
- **Canonical Domain** — the full URL of your live site (e.g. `https://yourdomain.com`). This appears in `sitemap.xml` and `robots.txt`
- **Web3Forms Access Key** — optional. Get a free key at web3forms.com to power the contact form on the static site. Leave blank to omit the submit button

Click **Save Build Settings**.

### Step 2 — Save FTP Settings
- **FTP Host** — your server's IP address or hostname (e.g. `109.106.248.17`). Do not include `ftp://`
- **Username** — your FTP username for this domain
- **Password** — your FTP password
- **Port** — leave at `21` unless your host requires a different port
- **Remote Path** — the folder on the server where the site root should go (e.g. `/public_html`)
- **Passive mode** — leave checked (recommended)

Click **Save FTP Settings**.

### Step 3 — Generate Static Site
Click **Generate Static Site**. A log panel shows progress. Wait for "Build complete" before proceeding. This typically takes 10–30 seconds.

### Step 4 — Push to Server
Click **Push to Server**. A log panel shows each file being uploaded. Only new or changed files are uploaded — a second push right after the first will say "Nothing to upload — all files are up to date."

### After the push
Visit your live domain in a browser. You should see your site, not a hosting provider placeholder page.

---

## Block Types Reference

When you click **Add Block** or change an existing block's type, choose from these 34 layouts:

### Hero / Banner Blocks
| Type | What it looks like |
|---|---|
| **Hero / Banner** | Full-width background image with a large headline, subtext, and a CTA button centered over it |
| **Hero Split** | Text on the left, large photo on the right with an optional caption below the image |
| **Hero Grid** | Large photo or background on the left panel, 2×2 icon grid on the right panel |

### Text & Image Blocks
| Type | What it looks like |
|---|---|
| **Text Only** | Just a heading and body text — no image |
| **Image Left** | Photo on the left, text + optional button on the right |
| **Image Right** | Text + optional button on the left, photo on the right |
| **Image & Text** | Photo and text side by side (flexible layout) |
| **Feature Split** | Photo on the left, icon checklist with phone button on the right |
| **Image + Feature List** | Photo on the left, checklist of features with a phone CTA on the right |

### Grid & Column Blocks
| Type | What it looks like |
|---|---|
| **Feature Columns** | 2–4 columns, each with an icon, heading, and short description |
| **Service Cards Grid** | Icon + heading + text cards, centered, typically 3 across |
| **Cards Grid** | Image + heading + text + link cards in a grid |
| **Tab Services** | Vertical tab list on the left; clicking a tab shows its image on the right |
| **Process Steps** | Numbered steps (Step 1, Step 2…) — also generates HowTo schema for Google |
| **Stats / Counters** | Large numbers with labels (e.g. "534 Reviews", "10+ Years") |
| **Photo Gallery** | Grid of photos |

### Call-to-Action Blocks
| Type | What it looks like |
|---|---|
| **Split CTA** | Colored panel on the left with text + colored panel on the right with a phone button |
| **CTA Banner** | Full-width solid-color band with a centered heading and button |
| **CTA Card** | Colored box, heading on the left, phone button on the right |
| **CTA Button** | A single standalone button, centered |
| **Wide Banner** | Full-width background image with a heading on the left and a button on the right |
| **Links Grid** | Background image with a heading and a grid of link buttons below |

### FAQ Blocks
| Type | What it looks like |
|---|---|
| **FAQ / Accordion** | Expandable Q&A pairs stacked vertically |
| **FAQ Two Column** | Two-column FAQ accordion with an icon and section heading |

Both FAQ block types automatically generate FAQPage schema for Google.

### Contact & Form Blocks
| Type | What it looks like |
|---|---|
| **Contact Form** | Full contact form with name, email, phone, and message fields. Sends to the email address set in the system config. Includes spam protection |

### Utility Blocks
| Type | What it looks like |
|---|---|
| **Map + Info** | Google map embed on the left, text + photo on the right |
| **Custom HTML** | Paste any embed code — Google Maps, review widgets, iframes, course schedule shortcodes |

### Testimonial & Media Blocks
| Type | What it looks like |
|---|---|
| **Testimonials** | Customer review cards — name, quote, star rating |
| **Video Embed** | A centered YouTube or Vimeo video player |

### Additional Layout Blocks
| Type | What it looks like |
|---|---|
| **Buttons Grid** | A grid of plain link buttons (no background image) |
| **Two-Column HTML** | Two side-by-side columns where you paste custom HTML into each |
| **Pricing / Course Cards** | Cards with a badge, feature checklist, and a CTA button — used for course or service pricing |
| **Logo / Trust Bar** | A row of partner or certification logos, each optionally linked |
| **Stage / Career Path Cards** | Numbered columns with item lists — used for program stages or career tracks |

---

## Block Fields

Most blocks share these standard fields. Some blocks have additional fields (shown in the admin when you select that type).

| Field | What it does |
|---|---|
| **Heading** | The section title (H1, H2, H3, or Paragraph — set with the level selector) |
| **Body Text** | Main content for the block. Supports basic HTML and shortcodes |
| **Image** | Click the photo picker button to choose from the Media Library, or upload a new image |
| **Image Alt Text** | A short description of the photo — important for SEO and accessibility |
| **Button Label + URL** | Optional CTA button. See link format tips below |
| **Padding** | Compact / Normal / Large — controls vertical spacing above and below the block |
| **Color Mode** | For colored blocks — choose Primary, Accent, Header, Footer, or Custom color |

### Link Format Tips
- Phone number: `tel:+12815550000` (country code, no spaces or dashes)
- Another page on your site: `/mosquito-control`
- External site: `https://google.com/maps/...`

---

## Shortcodes

Shortcodes are tokens you type in any heading or body text field. They get replaced automatically with your real business info when the page loads.

| Token | What it outputs |
|---|---|
| `{city}` | City name — e.g. Katy |
| `{state}` | Full state name — e.g. Texas |
| `{SS}` | State abbreviation — e.g. TX |
| `{city_state}` | City and state — e.g. Katy, TX |
| `{city_slug}` | URL-safe city — e.g. katy |
| `{business}` | Business name — e.g. Katy Pest Pros |
| `{phone}` | Phone number — e.g. (281) 215-0160 |
| `{zip}` | ZIP code — e.g. 77494 |
| `{website}` | Website URL |
| `{rating}` | Average star rating — e.g. 4.8 |
| `{review_count}` | Total review count — e.g. 534 |
| `{year}` | Current year — e.g. 2026 |

**Example:** Type `Cockroach Exterminator in {city}, {SS}` in a heading field → the page displays "Cockroach Exterminator in Katy, TX"

Shortcode values come from the **SEO / Schema tab → Local Business Info** section.

> **Note on `{city}` and the homepage:** City shortcodes like `{city}` and `{city_state}` resolve to the value you set in Local Business Info on the main site. They only show different city names automatically inside *city landing pages* generated by the city page system — not on the regular homepage or standard landing pages.

---

## Footer Tab

1. **Footer Logo** — upload a logo for the footer (optional — often a white version)
2. **Tagline** — a short phrase under the logo
3. **Highlight Text** — a bold callout line
4. **Phone & Email** — contact info in the footer (use `{phone}` shortcode)
5. **Footer Columns** — add columns of links, text, or contact info
   - **Links column** — a heading with a list of links
   - **Text column** — a heading with a paragraph of text
   - **Contact column** — shows phone + city automatically; add extra items (hours, email icon, etc.)
6. **Bottom Bar** — copyright text and legal page links (Privacy Policy, Terms)
7. Click **Save Footer**

---

## Media Library Tab

The Media Library is your central image store. Upload an image once, then reuse it on any page or block.

### Uploading Images
- Drag and drop files onto the library grid, or click **Upload Images**
- Multiple files can be uploaded at once
- JPG, PNG, GIF, and WebP are accepted (max 8MB each)
- Images are automatically converted to WebP and resized to max 1800px on the longest edge

### Setting Alt Text
- Each image card has an alt text input field
- Fill this in immediately after uploading — it's the description Google reads when crawling images
- Example: "cockroach exterminator spraying in Katy TX kitchen"

### Using an Image on a Page
- When adding an image to a block, click the photo picker button → the Media Library opens
- Click any image to select it — it fills in the block's image field automatically

### Crop Tool (✂ button)
- Click ✂ on any image card to open the crop tool
- Drag the crop box to the area you want to keep
- Use the aspect ratio buttons (Free, 16:9, 4:3, 3:2, 1:1, 9:16) for standard proportions
- Click **Apply Crop** to save — this overwrites the original file

### Focal Point (⊕ button)
- Click ⊕ on any image card to set the focal point
- Click the subject of the image (the most important part — a face, a pest, a product)
- A dot appears where you clicked — this becomes the anchor point
- Click **Save Focal Point** to confirm
- From then on, whenever that image appears in a block, the focal point stays visible even if the image is cropped by the layout

### Usage Tracker
- Each image card shows a badge: "Unused" or "N places ▾"
- Click the badge to expand a list of every page and block where that image is used
- If you try to delete a used image, the system shows you exactly where it's being used

### Duplicate Detector
- Click **Find Duplicates** in the toolbar
- The system scans all images using perceptual fingerprinting and groups near-identical images
- Each group shows the images side by side with their usage counts
- Click **Delete** on duplicates to remove them

### Image Variation Seed (Multi-City Deployment)
- When you deploy the same site images to many city pages, Google may treat them as duplicate content
- The variation seed tool applies a unique set of small transforms to each image (horizontal flip, slight brightness shift, tiny edge crop)
- Go to Media Library → **Site Variation Seed** panel → click **Apply Variation to All Images**
- Increase the seed number to create a new variation set for each new city deployment

---

## Per-Page SEO Fields (Home Page & Landing Pages)

At the bottom of the Home Page tab and each Landing Page editor you'll find SEO fields:

| Field | What it does |
|---|---|
| **Canonical URL** | The preferred URL for this page — use the live site URL (e.g. `https://yoursite.com/cockroach-exterminator-katy-tx/`) |
| **Meta Description** | 1–2 sentence summary shown in Google search results — aim for 120–160 characters |
| **Meta Keywords** | Comma-separated keywords |
| **Social Share Title** | Title shown when someone shares the page on Facebook, iMessage, etc. |
| **Social Share Description** | Description shown in social share previews |
| **Social Share Image** | Image shown in social share previews — recommended 1200×630px |
| **Service Name** | Name of the service on this page — generates Service schema |
| **Service Type** | The type of service |
| **Area Served** | The location this service covers |
| **Service Description** | A short description of what the service includes |
| **Breadcrumb Label** | Label for the current page in the breadcrumb trail |
| **Middle Crumb Label** | Optional middle step in the breadcrumb |
| **Middle Crumb URL** | URL for the middle crumb |
| **Custom Schema JSON-LD** | Advanced — paste custom structured data |

---

## Global SEO / Schema Tab

This tab sets your **LocalBusiness schema** — information about your company that Google shows in local search results. Fill this in once; it applies to every page on the site.

| Field | What to enter |
|---|---|
| **Business Name** | Your full business name |
| **Business Type** | Choose from the dropdown (LocalBusiness, PestControlService, etc.) |
| **Website URL** | Your full site URL including https:// |
| **Phone** | Your main phone number |
| **Street Address** | Your street address (or leave blank if service-area only) |
| **City / State / ZIP** | Your business location |
| **Latitude / Longitude** | For map pins in schema (look up on Google Maps) |
| **Price Range** | `$`, `$$`, or `$$$` |
| **Opening Hours** | Schema format: `Mo-Fr 08:00-18:00, Sa 09:00-13:00` |
| **Business Description** | A paragraph about your business |
| **Average Rating** | Your star rating (1–5, e.g. 4.8) — also powers the `{rating}` shortcode |
| **Review Count** | Total number of reviews — also powers the `{review_count}` shortcode |

---

## Content Tips for Landing Pages

- Include the city name naturally in headings and body text (helps local SEO)
- Use shortcodes (`{city}`, `{city_state}`) in headings so the same block template works across all city pages
- Every image must have alt text describing the photo + the service + the city name
- Add a CTA button after every 2–3 content blocks — give visitors many chances to call
- Use FAQ blocks with real customer questions — these appear in Google's "People also ask" box
- Aim for at least 500–700 words of content per landing page

### Suggested Block Order for a Service Landing Page
1. **Hero Split** — main headline + CTA button + hero photo
2. **Feature Columns** — top 3–4 reasons to choose you
3. **Feature Split** — about your process or your team, with a photo
4. **Image Features** — checklist of what's included, phone CTA
5. **FAQ Two Column** — 6–10 common questions
6. **CTA Banner** — "Call Now for a Free Inspection" with phone button
7. **Map + Info** or **Contact Form** — contact details or form

---

## Frequently Asked Questions

**My changes aren't showing on the live site.**
Make sure you clicked Save for that tab. Then hard-refresh your browser: **Ctrl+Shift+R** (Windows) or **Cmd+Shift+R** (Mac). If you use the Deploy tab, you also need to re-generate and re-push after saving changes.

**I uploaded an image but it's not showing in the block.**
Use the photo picker button on the block to select the image from the Media Library. Pasting a URL manually doesn't work.

**I accidentally deleted a block.**
There is no undo. Save the page first before making big changes. Re-add the block manually if needed.

**How do I link to another page on my site?**
Use the slug with a leading slash: `/mosquito-control` or `/termite-treatment`.

**How do I add a phone number as a button link?**
Use the format `tel:+12812150160` — `tel:` then `+1` then your 10-digit number, no spaces or dashes.

**How do I embed a Google Map?**
Add a **Custom HTML** block, then paste the Google Maps embed `<iframe>` code into it.

**Why do some images look cropped differently on the page than in the library?**
Block layouts crop images to fit their container. Set the **Focal Point** on the image so the most important part always stays visible no matter how the layout crops it.

**The Deploy Push says "Nothing to upload" but the site looks old.**
This means the deploy manifest thinks everything is up to date. If you're pushing to a new server or something went wrong, ask your developer to clear the manifest file (`deploy_manifest.json` inside the site's folder) and re-push.

**The site still shows Hostinger's placeholder page after a successful push.**
The `.htaccess` file tells the server to serve `index.html` first. If Hostinger's `default.php` is still there, it may show instead. Go to Hostinger's File Manager, navigate to `public_html`, and delete `default.php`.

---

*For technical setup and deployment, see the **Developer Methodology**.*
*For a full list of what's built and what's planned, see the **Content Editor Roadmap**.*
