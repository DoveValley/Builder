<?php
require_once __DIR__ . '/../config.php';
if (empty($_SESSION['admin_logged_in'])) {
    header('Location: login.php'); exit;
}
$q = trim($_GET['q'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Documentation — Site Factory</title>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; font-size: 15px; color: #1e293b; background: #f8fafc; display: flex; min-height: 100vh; }

/* Sidebar */
#sidebar {
    width: 260px; flex-shrink: 0;
    background: #1e3a5f; color: #cbd5e1;
    height: 100vh; position: sticky; top: 0;
    overflow-y: auto; padding: 0 0 40px;
}
#sidebar .logo {
    padding: 20px 20px 14px;
    font-size: 1rem; font-weight: 700; color: #fff;
    border-bottom: 1px solid rgba(255,255,255,0.1);
}
#sidebar .logo span { color: #93c5fd; font-size: 0.75rem; font-weight: 400; display: block; margin-top: 2px; }
#sidebar nav a {
    display: block; padding: 7px 20px;
    color: #94a3b8; text-decoration: none; font-size: 0.85rem;
    border-left: 3px solid transparent;
    transition: all 0.15s;
}
#sidebar nav a:hover { color: #fff; background: rgba(255,255,255,0.05); }
#sidebar nav a.active { color: #fff; border-left-color: #3b82f6; background: rgba(59,130,246,0.15); }
#sidebar nav .nav-group { padding: 18px 20px 6px; font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; color: #475569; }

/* Search */
#sidebar .search-wrap { padding: 12px 16px; border-bottom: 1px solid rgba(255,255,255,0.08); }
#sidebar .search-wrap input {
    width: 100%; padding: 7px 10px; border-radius: 6px;
    border: none; font-size: 0.85rem; background: rgba(255,255,255,0.1);
    color: #fff; outline: none;
}
#sidebar .search-wrap input::placeholder { color: #64748b; }

/* Main content */
#main { flex: 1; min-width: 0; padding: 40px 48px; max-width: 900px; }
#main h1 { font-size: 2rem; font-weight: 800; color: #0f172a; margin-bottom: 8px; }
#main .page-intro { font-size: 1.05rem; color: #475569; margin-bottom: 40px; line-height: 1.6; }

section { margin-bottom: 56px; scroll-margin-top: 32px; }
section h2 { font-size: 1.4rem; font-weight: 700; color: #0f172a; padding-bottom: 10px; border-bottom: 2px solid #e2e8f0; margin-bottom: 20px; }
section h3 { font-size: 1.05rem; font-weight: 700; color: #1e3a5f; margin: 24px 0 8px; }
section h4 { font-size: 0.95rem; font-weight: 700; color: #334155; margin: 18px 0 6px; }
section p { margin-bottom: 12px; line-height: 1.65; color: #334155; }
section ul, section ol { margin: 8px 0 12px 20px; line-height: 1.7; color: #334155; }
section li { margin-bottom: 4px; }

code { background: #f1f5f9; border: 1px solid #e2e8f0; padding: 1px 6px; border-radius: 4px; font-family: 'SF Mono', 'Fira Code', monospace; font-size: 0.85em; color: #0f172a; }
pre { background: #0f172a; color: #e2e8f0; padding: 16px 20px; border-radius: 8px; overflow-x: auto; font-size: 0.85rem; margin: 12px 0 16px; line-height: 1.6; }
pre code { background: none; border: none; padding: 0; color: inherit; font-size: inherit; }

.callout { background: #eff6ff; border-left: 4px solid #3b82f6; padding: 12px 16px; border-radius: 0 6px 6px 0; margin: 12px 0 16px; }
.callout.warn { background: #fffbeb; border-left-color: #f59e0b; }
.callout.tip { background: #f0fdf4; border-left-color: #22c55e; }
.callout p { margin: 0; color: #1e3a5f; }

.block-card-doc { background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 20px 24px; margin-bottom: 20px; scroll-margin-top: 32px; }
.block-card-doc h3 { margin: 0 0 6px; font-size: 1rem; color: #1e3a5f; }
.block-card-doc .bc-meta { font-size: 0.8rem; color: #64748b; margin-bottom: 10px; }
.block-card-doc .bc-meta code { font-size: 0.78rem; }
.block-card-doc p { font-size: 0.9rem; margin-bottom: 8px; }
.block-card-doc ul { font-size: 0.9rem; }

.tag { display: inline-block; font-size: 0.72rem; font-weight: 700; padding: 2px 8px; border-radius: 4px; margin-right: 4px; }
.tag-ai { background: #7c3aed; color: #fff; }
.tag-hero { background: #1e3a5f; color: #fff; }
.tag-cta { background: #ea580c; color: #fff; }
.tag-content { background: #0369a1; color: #fff; }
.tag-social { background: #059669; color: #fff; }
.tag-utility { background: #4b5563; color: #fff; }

table { width: 100%; border-collapse: collapse; margin: 12px 0 16px; font-size: 0.88rem; }
th { background: #f1f5f9; text-align: left; padding: 8px 12px; font-weight: 600; border: 1px solid #e2e8f0; }
td { padding: 8px 12px; border: 1px solid #e2e8f0; vertical-align: top; }
tr:nth-child(even) td { background: #f8fafc; }

.back-top { display: inline-block; margin-top: 12px; font-size: 0.8rem; color: #3b82f6; text-decoration: none; }
.back-top:hover { text-decoration: underline; }
</style>
</head>
<body>

<!-- ═══════════════ SIDEBAR ═══════════════ -->
<div id="sidebar">
    <div class="logo">
        Site Factory
        <span>Admin Documentation</span>
    </div>
    <div class="search-wrap">
        <input type="text" id="doc-search" placeholder="Search docs…" oninput="filterNav(this.value)">
    </div>
    <nav id="doc-nav">
        <div class="nav-group">Overview</div>
        <a href="#overview">What is this system?</a>
        <a href="#no-database">No-database philosophy</a>
        <a href="#multi-site">Multi-site</a>

        <div class="nav-group">Technical</div>
        <a href="#architecture">Architecture</a>
        <a href="#data-flow">Data flow</a>
        <a href="#routing">URL routing</a>
        <a href="#file-structure">File structure</a>

        <div class="nav-group">Admin Tabs</div>
        <a href="#tab-header">Header</a>
        <a href="#tab-theme">Theme</a>
        <a href="#tab-content">Content</a>
        <a href="#tab-pages">Pages</a>
        <a href="#tab-blog">Blog</a>
        <a href="#tab-footer">Footer</a>
        <a href="#tab-popups">Popups</a>
        <a href="#tab-media">Media</a>
        <a href="#tab-seo">SEO</a>
        <a href="#tab-schedule">Schedule</a>
        <a href="#tab-starters">Page Starters</a>
        <a href="#tab-templates">Templates</a>
        <a href="#tab-cities">Landing Cities</a>
        <a href="#tab-citypages">City Pages</a>
        <a href="#tab-generate">AI Generation</a>
        <a href="#tab-ai-review">Content Review</a>
        <a href="#tab-ai-blocks">Block Registry</a>
        <a href="#tab-plugins">Plugins</a>
        <a href="#tab-deploy">Deploy</a>

        <div class="nav-group">Block Library</div>
        <a href="#block-hero">Hero</a>
        <a href="#block-hero_split">Hero Split</a>
        <a href="#block-hero_grid">Hero Grid</a>
        <a href="#block-wide_banner">Wide Banner</a>
        <a href="#block-text">Text</a>
        <a href="#block-image_right">Image + Text</a>
        <a href="#block-video">Video</a>
        <a href="#block-custom_html">Custom HTML</a>
        <a href="#block-html_two_col">HTML Two Column</a>
        <a href="#block-feature_split">Feature Split</a>
        <a href="#block-feature_columns">Feature Columns</a>
        <a href="#block-service_cards">Service Cards</a>
        <a href="#block-tab_services">Tab Services</a>
        <a href="#block-steps">Steps</a>
        <a href="#block-stats">Stats</a>
        <a href="#block-image_features">Image Features</a>
        <a href="#block-pricing_cards">Pricing Cards</a>
        <a href="#block-stage_cards">Stage Cards</a>
        <a href="#block-comparison_table">Comparison Table</a>
        <a href="#block-testimonials">Testimonials</a>
        <a href="#block-team">Team</a>
        <a href="#block-logo_bar">Logo Bar</a>
        <a href="#block-cards">Cards Grid</a>
        <a href="#block-gallery">Gallery</a>
        <a href="#block-faq_two_col">FAQ Two Column</a>
        <a href="#block-links_grid">Links Grid</a>
        <a href="#block-cta_banner">CTA Banner</a>
        <a href="#block-cta_card">CTA Card</a>
        <a href="#block-split_cta">Split CTA</a>
        <a href="#block-email_banner">Email Banner</a>
        <a href="#block-cta_button">CTA Button</a>
        <a href="#block-map_info">Map + Info</a>
        <a href="#block-contact_form">Contact Form</a>
        <a href="#block-ai_block">AI Block</a>

        <div class="nav-group">AI System</div>
        <a href="#ai-overview">How AI works</a>
        <a href="#ai-standalone">Standalone mode</a>
        <a href="#ai-enrich">Enrich mode</a>
        <a href="#ai-locking">Locking</a>
        <a href="#ai-workflow">Full workflow</a>

        <div class="nav-group">City Pages</div>
        <a href="#cities-overview">Overview</a>
        <a href="#cities-templates">Templates</a>
        <a href="#cities-generation">Generation steps</a>
        <a href="#cities-slugs">Slugs</a>

        <div class="nav-group">Going Live</div>
        <a href="#deploy-checklist">Pre-launch checklist</a>
        <a href="#deploy-ftp">FTP deploy</a>
        <a href="#deploy-security">Security notes</a>

        <div class="nav-group">How To</div>
        <a href="#howto-new-block">Add a new block type</a>
        <a href="#howto-new-ai-type">Add a new AI type</a>
        <a href="#howto-new-city">Add a city</a>
        <a href="#howto-new-template">Add a template</a>
        <a href="#howto-update-docs">Update this docs page</a>
    </nav>
</div>

<!-- ═══════════════ MAIN ═══════════════ -->
<div id="main">
<h1>Site Factory — Documentation</h1>
<p class="page-intro">Complete reference for the Site Factory admin system. Use the sidebar to navigate, or click any <strong>?</strong> button inside the admin panel to jump directly to the relevant section.</p>

<!-- ═══════════ OVERVIEW ═══════════ -->
<section id="overview">
    <h2>What is this system?</h2>
    <p>Site Factory is a custom PHP CMS designed for building and deploying local-service and training business websites at scale. It is purpose-built — no WordPress, no plugins, no framework overhead.</p>
    <p>The core idea: content lives in flat JSON files, pages are rendered by PHP, and the admin panel edits those JSON files. Everything is fast, portable, and easy to back up — the entire site is a folder of files.</p>
    <p>The system supports multiple client sites from a single admin installation, AI-assisted content generation for city landing pages, a course schedule system, a blog, and one-click FTP deployment.</p>
</section>

<section id="no-database">
    <h2>No-database philosophy</h2>
    <p>There is no MySQL, no SQLite, no ORM. All site content lives in JSON files:</p>
    <ul>
        <li><code>sites/{id}/data/site.json</code> — all page content, header, footer, theme, blog posts</li>
        <li><code>sites/{id}/data/courses.json</code> — course schedule data</li>
        <li><code>sites/{id}/data/templates.json</code> — city page templates</li>
        <li><code>sites/{id}/data/cities.json</code> — city list</li>
        <li><code>sites/{id}/data/pages/</code> — generated city pages (one JSON file per page)</li>
    </ul>
    <p>This means: no database to set up, no migrations, no connection strings. Backup = copy the folder. Deploy = FTP the folder.</p>
    <div class="callout">
        <p><strong>Tradeoff:</strong> JSON files are not suitable for high-traffic transactional data (e.g., thousands of form submissions per hour). For that, use an external service. For content management and page generation, flat files are faster and simpler.</p>
    </div>
</section>

<section id="multi-site">
    <h2>Multi-site</h2>
    <p>The admin manages multiple client sites from one installation. The active site is stored in <code>$_SESSION['active_site']</code>. Switching sites changes which <code>sites/{id}/</code> folder all reads and writes go to.</p>
    <p>Sites are listed at <code>admin/sites.php</code>. If no site is selected, all admin tabs redirect there. Each site has its own data, uploads, and generated pages — completely isolated.</p>
</section>

<!-- ═══════════ TECHNICAL ═══════════ -->
<section id="architecture">
    <h2>Architecture</h2>
    <p>The system is structured around a small set of PHP files that each have a specific responsibility:</p>
    <table>
        <tr><th>File</th><th>Role</th></tr>
        <tr><td><code>config.php</code></td><td>Defines constants (DATA_FILE, UPLOAD_DIR, etc.), starts session, sets admin password hash</td></tr>
        <tr><td><code>includes/functions.php</code></td><td>Loader — requires all includes/* files in order</td></tr>
        <tr><td><code>includes/data.php</code></td><td>load_data(), save_data(), default_data()</td></tr>
        <tr><td><code>includes/blocks.php</code></td><td>render_content_block(), allowed_block_types(), block_thumbnails()</td></tr>
        <tr><td><code>includes/editor.php</code></td><td>Admin block editor UI (render_content_blocks_editor)</td></tr>
        <tr><td><code>includes/scripts.php</code></td><td>All admin JavaScript (content_editor_scripts)</td></tr>
        <tr><td><code>includes/helpers.php</code></td><td>sanitize_url(), save_uploaded_file(), slugify()</td></tr>
        <tr><td><code>includes/site-template.php</code></td><td>Shared HTML template — head, block loop, footer</td></tr>
        <tr><td><code>includes/shortcodes.php</code></td><td>Shortcode resolution, course widgets</td></tr>
        <tr><td><code>includes/theme.php</code></td><td>CSS variable generation from theme settings</td></tr>
        <tr><td><code>admin/save.php</code></td><td>POST handler for all site content saves</td></tr>
        <tr><td><code>admin/schedule_save.php</code></td><td>POST handler for course schedule CRUD</td></tr>
        <tr><td><code>generate.py</code></td><td>AI content generation script (Python, calls Claude API)</td></tr>
        <tr><td><code>includes/generation/engine.php</code></td><td>Structure generation engine (PHP)</td></tr>
        <tr><td><code>router.php</code></td><td>Required for pretty URLs with PHP built-in server</td></tr>
    </table>
</section>

<section id="data-flow">
    <h2>Data flow</h2>
    <h3>Page render (public)</h3>
    <ol>
        <li><code>index.php</code> / <code>page.php</code> calls <code>load_data()</code></li>
        <li><code>load_data()</code> reads <code>site.json</code> and deep-merges with <code>default_data()</code> so new keys always have defaults</li>
        <li>The page sets <code>$contentBlocks</code>, <code>$seo</code>, <code>$pageTitle</code>, <code>$assetPathPrefix</code></li>
        <li><code>site-template.php</code> renders the full HTML: head → block loop → footer</li>
        <li>Each block is rendered by <code>render_content_block($block, $pathPrefix)</code></li>
        <li>Shortcodes (<code>{phone}</code>, <code>{city}</code>, etc.) are resolved inside each block at render time</li>
    </ol>
    <h3>Admin save</h3>
    <ol>
        <li>Admin form POSTs to <code>admin/save.php</code></li>
        <li>CSRF token is verified</li>
        <li>The <code>section</code> field determines which part of site.json to update</li>
        <li><code>save_data()</code> writes the updated array back to site.json atomically (tmp → rename)</li>
        <li>Redirect back with <code>?msg=success:...</code></li>
    </ol>
</section>

<section id="routing">
    <h2>URL routing</h2>
    <p>On Apache (production), <code>.htaccess</code> rewrites clean URLs:</p>
    <ul>
        <li><code>/</code> → <code>index.php</code></li>
        <li><code>/about</code> → <code>page.php?slug=about</code></li>
        <li><code>/blog</code> → <code>blog.php</code></li>
        <li><code>/blog/post-slug</code> → <code>blog.php?slug=post-slug</code></li>
        <li><code>/pmp-certification-dallas-tx</code> → city page router</li>
    </ul>
    <div class="callout warn">
        <p><strong>Local development:</strong> PHP's built-in server does not process .htaccess. Always start with <code>php -S localhost:8080 router.php</code> — the <code>router.php</code> argument is required for pretty URLs to work locally.</p>
    </div>
</section>

<section id="file-structure">
    <h2>File structure</h2>
    <pre><code>homepage-builder-new/
├── admin/               Admin panel PHP files and tabs
│   ├── tabs/            Tab content files (citypages.php, templates.php, etc.)
│   ├── save.php         Content save handler
│   ├── schedule_save.php Course schedule save handler
│   └── docs.php         This documentation page
├── assets/
│   ├── css/style.css    All CSS (public + admin)
│   └── js/              Public JS files
├── includes/            PHP includes (functions, blocks, editor, etc.)
│   └── generation/      City page generation engine + steps
├── sites/
│   └── {site-id}/
│       ├── data/
│       │   ├── site.json
│       │   ├── courses.json
│       │   ├── templates.json
│       │   ├── cities.json
│       │   └── pages/   Generated city page JSON files
│       └── uploads/     Uploaded images for this site
├── config.php           Site config and admin credentials
├── router.php           Local dev URL router
├── generate.py          AI generation script
└── ai_block_types.json  AI block type registry</code></pre>
</section>

<!-- ═══════════ ADMIN TABS ═══════════ -->
<section id="tab-header">
    <h2>Tab: Header</h2>
    <p>Controls the site header — logo, site name, phone number, and navigation menu.</p>
    <h3>Key fields</h3>
    <ul>
        <li><strong>Logo</strong> — upload or pick from Media Library. PNG with transparent background recommended.</li>
        <li><strong>Site name</strong> — used in the &lt;title&gt; tag and as fallback if logo is missing. Always set this.</li>
        <li><strong>Phone</strong> — use the <code>{phone}</code> shortcode to pull from site_vars, or enter directly.</li>
        <li><strong>Menu</strong> — add/remove/reorder nav items. Each item has a label and URL. Supports dropdown sub-menus.</li>
        <li><strong>Layout</strong> — choose between single-row, logo-centered, and other header layouts.</li>
    </ul>
    <div class="callout tip">
        <p><strong>Tip:</strong> Set site_vars first (phone, business name, address) — then use shortcodes in the header so values stay consistent everywhere.</p>
    </div>
</section>

<section id="tab-theme">
    <h2>Tab: Theme</h2>
    <p>Controls global colors, fonts, and button styles. All values are converted to CSS custom properties and injected into every page.</p>
    <h3>Key settings</h3>
    <ul>
        <li><strong>Primary color (header)</strong> — nav background, dark section backgrounds</li>
        <li><strong>Accent color</strong> — buttons, highlights, icons</li>
        <li><strong>Footer color</strong> — footer background</li>
        <li><strong>Font</strong> — Google Font name (loaded automatically)</li>
        <li><strong>Button radius</strong> — controls corner rounding on all buttons site-wide</li>
    </ul>
    <p>Color fields in blocks (heading color, background color, icon color) can reference these global values using the keywords <code>accent</code>, <code>header</code>, or <code>footer</code> — so a single theme change updates the entire site.</p>
</section>

<section id="tab-content">
    <h2>Tab: Content</h2>
    <p>The main page content editor — build the homepage from blocks. Each block is a collapsible card. Drag to reorder (or use ↑ ↓ arrows), clone to duplicate, remove to delete.</p>
    <h3>Adding a block</h3>
    <ol>
        <li>Click <strong>+ Add Block</strong> at the bottom</li>
        <li>Pick a block type from the visual picker</li>
        <li>Fill in the fields</li>
        <li>Click <strong>Save Changes</strong></li>
    </ol>
    <p>Each block header shows the block type, an optional skin badge (colored section variant), an AI badge (purple, if this block is AI-enriched), and a <strong>?</strong> button that opens the relevant docs section.</p>
    <div class="callout">
        <p>The Content tab edits the homepage (<code>index.php</code>). To edit a landing page, go to the <strong>Pages</strong> tab.</p>
    </div>
</section>

<section id="tab-pages">
    <h2>Tab: Pages</h2>
    <p>Manages landing pages — any page other than the homepage or blog. Each page has a slug (URL), title, SEO fields, and its own content blocks.</p>
    <h3>Creating a page</h3>
    <ol>
        <li>Click <strong>New Page</strong></li>
        <li>Set a slug (e.g. <code>about</code> → accessible at <code>/about</code>)</li>
        <li>Add content blocks</li>
        <li>Save</li>
    </ol>
    <p>Slugs are validated against a reserved list and deduplicated automatically. Do not use slugs that conflict with system paths (<code>admin</code>, <code>blog</code>, <code>uploads</code>, etc.).</p>
</section>

<section id="tab-blog">
    <h2>Tab: Blog</h2>
    <p>Manages blog posts. Each post has a title, slug, status (draft/published), published date, author, tags, featured image, excerpt, and its own content blocks — the same block system as pages.</p>
    <h3>Blog routing</h3>
    <ul>
        <li>Listing: <code>/blog</code></li>
        <li>Tag filter: <code>/blog?tag=project-management</code></li>
        <li>Single post: <code>/blog/post-slug</code></li>
        <li>Pagination: <code>/blog?p=2</code></li>
    </ul>
    <p>Posts with <strong>draft</strong> status are not publicly accessible. The listing shows only published posts.</p>
</section>

<section id="tab-footer">
    <h2>Tab: Footer</h2>
    <p>Controls the footer — columns, links, contact info, copyright, and social icons. Like the header, values should use shortcodes (<code>{phone}</code>, <code>{email}</code>, <code>{business}</code>) where possible.</p>
    <p>The copyright year auto-updates — use <code>{year}</code> in the copyright field.</p>
</section>

<section id="tab-popups">
    <h2>Tab: Popups</h2>
    <p>Manages overlay popups that appear on page load or after a delay. Each popup has a headline, body text, image, and optional form or button. Popups can be enabled/disabled globally.</p>
</section>

<section id="tab-media">
    <h2>Tab: Media</h2>
    <p>The media library — all uploaded images for the active site. Images uploaded via block editors appear here automatically.</p>
    <h3>Tips</h3>
    <ul>
        <li>Use the Library button in any image field to pick an existing image instead of uploading again</li>
        <li>Check here before uploading — a topically relevant image may already exist</li>
        <li>Accepted formats: JPEG, PNG, GIF, WebP (max 8 MB)</li>
        <li>SVG files are sanitized on upload (scripts and event handlers are stripped)</li>
    </ul>
</section>

<section id="tab-seo">
    <h2>Tab: SEO</h2>
    <p>Global SEO settings — site-wide meta title format, default meta description, Open Graph image, Google Analytics ID, and schema markup settings.</p>
    <p>Individual pages and blog posts have their own SEO fields (title, description, canonical URL) that override the global defaults.</p>
</section>

<section id="tab-schedule">
    <h2>Tab: Schedule</h2>
    <p>Manages the course schedule — upcoming class dates, delivery method, price, and registration links. This data powers the <code>[course_schedule]</code> and <code>[course_card]</code> shortcodes used inside Custom HTML blocks.</p>
    <h3>Course fields</h3>
    <table>
        <tr><th>Field</th><th>Description</th></tr>
        <tr><td>Course type</td><td>Matches the <code>type="..."</code> attribute in the shortcode</td></tr>
        <tr><td>Delivery</td><td>Live-Virtual or On-Demand</td></tr>
        <tr><td>Dates</td><td>Display string, e.g. "Jul 14–17, 2025"</td></tr>
        <tr><td>Time (EST)</td><td>Time range, e.g. "8:30am–5:00pm" or "Self-paced"</td></tr>
        <tr><td>Price</td><td>Current price</td></tr>
        <tr><td>Old price</td><td>Shown with strikethrough if set</td></tr>
        <tr><td>Register URL</td><td>Link to registration page</td></tr>
        <tr><td>Availability note</td><td>e.g. "Only 3 seats left"</td></tr>
        <tr><td>Guaranteed</td><td>Shows "Guaranteed to run" badge</td></tr>
    </table>
</section>

<section id="tab-starters">
    <h2>Tab: Page Starters</h2>
    <p>Pre-built page layouts — full pages with a curated set of content blocks already configured. Apply a starter to a new page and it immediately has a complete structure you can edit.</p>
    <p>Starters are applied once — applying a starter creates a copy as a regular editable page. The original starter is not modified. Starters are organized by category (Homepage, Service Page, Landing Page, etc.).</p>
</section>

<section id="tab-templates">
    <h2>Tab: Templates</h2>
    <p>Manages city page templates. A template defines the block structure, SEO pattern, and slug pattern for a family of city landing pages. Each template × each city = one generated page.</p>
    <h3>Template fields</h3>
    <ul>
        <li><strong>Template ID</strong> — unique slug, used in filenames (e.g. <code>tpl_pmp_certification_training_city</code>)</li>
        <li><strong>Title pattern</strong> — e.g. "PMP Certification Training in {city}, {SS}"</li>
        <li><strong>Slug pattern</strong> — e.g. <code>pmp-certification-training-{city_slug}</code></li>
        <li><strong>Content blocks</strong> — same block editor as Content/Pages tabs</li>
        <li><strong>Generation steps</strong> — which steps run when pages are generated (city_vars, shortcode substitution, etc.)</li>
    </ul>
    <p>Blocks in a template can be marked for AI enrichment using <code>ai_type_id</code>, <code>ai_inject_field</code>, and <code>ai_inject_mode</code> fields. See the <a href="#ai-enrich">AI Enrich</a> section.</p>
    <div class="callout">
        <p>The purple <strong>✦ AI</strong> badge on a block header means that block will be enriched by AI during generation. The <strong>?</strong> button opens this docs page at the relevant block section.</p>
    </div>
</section>

<section id="tab-cities">
    <h2>Tab: Landing Cities</h2>
    <p>The list of cities used for landing page generation. Each city entry provides the variables that fill city page templates: city name, state, 2-letter abbreviation, ZIP code, and city slug.</p>
    <p>Cities can have tags — use tags to filter which cities are included in a generation run (e.g., run only Texas cities, or only cities in the "priority" tag group).</p>
</section>

<section id="tab-citypages">
    <h2>Tab: City Pages</h2>
    <p>The city list — every city that templates will be generated for. Each city entry has:</p>
    <ul>
        <li><strong>City, State, SS (2-letter abbrev), ZIP</strong> — used in shortcode substitution</li>
        <li><strong>city_slug</strong> — used in URL slug patterns (e.g. <code>dallas-tx</code>)</li>
        <li><strong>Tags</strong> — optional; used to filter which cities get generated in a run</li>
    </ul>
    <p>The status grid shows which pages exist for each template × city combination, when they were last generated, and whether AI has been run on them.</p>
</section>

<section id="tab-generate">
    <h2>Tab: Generate</h2>
    <p>Runs the two-phase generation process for city landing pages.</p>
    <h3>Phase 1 — Structure generation (PHP)</h3>
    <p>Copies the template block structure to each city page JSON file. Resolves slug and title patterns for the city. Preserves blocks marked <code>_ai_locked: true</code> so existing AI content is not overwritten.</p>
    <h3>Phase 2 — AI generation (Python)</h3>
    <p>Runs <code>generate.py</code> which finds all blocks that need AI processing (ai_blocks and enrich blocks) and calls the Claude API to fill them. Generated blocks are marked <code>_ai_locked: true</code>.</p>
    <div class="callout warn">
        <p><strong>Cost warning:</strong> AI generation makes API calls to Anthropic. The system will show an estimated page count and ask for confirmation before running. Each page costs roughly $0.01–$0.05 depending on block count and model.</p>
    </div>
    <div class="callout tip">
        <p><strong>Tip:</strong> Run structure generation first (always free). Review a few pages. Then run AI generation only when the structure looks correct.</p>
    </div>
</section>

<section id="tab-ai-review">
    <h2>Tab: Content Review</h2>
    <p>A review panel showing all AI-generated blocks across every city landing page in one place. For each block you can see the generated content, which city it belongs to, and whether it is currently locked.</p>
    <p>Use this tab to spot-check AI output quality after a generation run and lock specific blocks that you want to protect from future regeneration.</p>
</section>

<section id="tab-ai-blocks">
    <h2>Tab: Block Registry</h2>
    <p>The AI block type registry — each entry defines a named AI block type (e.g., <code>city_market_intro</code>, <code>hero_subtext</code>) with its prompt template, output format, model, and injection behavior.</p>
    <p>This is the configuration layer between your templates and the AI generator. When the generator encounters a block with a given <code>ai_type_id</code>, it looks up the matching registry entry to know what to generate and where to put it.</p>
</section>

<section id="tab-plugins">
    <h2>Tab: Plugins</h2>
    <p>Enable and configure optional site plugins. Currently the primary plugin is the <strong>Course Schedule</strong> — which adds a schedule management panel and enables the <code>[course_schedule]</code> and <code>[course_card]</code> shortcodes inside Custom HTML blocks.</p>
    <p>Each enabled plugin appears as a sub-panel with its own settings and data management tools.</p>
</section>

<section id="tab-deploy">
    <h2>Tab: Deploy</h2>
    <p>Generates a complete static site from the current content and pushes it to a remote server via FTP.</p>
    <h3>How it works</h3>
    <ol>
        <li>The system crawls all public pages (homepage, landing pages, blog, city pages)</li>
        <li>Each page is rendered to a static HTML file</li>
        <li>Static files + assets are pushed via FTP to the configured host</li>
    </ol>
    <h3>FTP configuration</h3>
    <p>Set host, username, password, and remote path in the Deploy tab. The FTP host should not include the <code>ftp://</code> prefix — enter the hostname only (e.g. <code>ftp.yoursite.com</code>).</p>
    <div class="callout warn">
        <p><strong>Never deploy with the <code>.git/</code> folder in the webroot.</strong> The .htaccess blocks direct access to dotfiles, but the safest practice is to not upload .git at all.</p>
    </div>
</section>

<!-- ═══════════ BLOCK LIBRARY ═══════════ -->
<section>
    <h2>Block Library</h2>
    <p>All 34 block types. Click the <strong>?</strong> button on any block in the admin to jump directly to that block's section here.</p>
</section>

<div class="block-card-doc" id="block-hero">
    <h3>Hero <span class="tag tag-hero">Hero</span></h3>
    <div class="bc-meta">Type: <code>hero</code> &nbsp;·&nbsp; Best for: Homepage or primary landing page top</div>
    <p>A full-width hero section with a background image, H1 headline, subtext paragraph, and a primary CTA button. The background image spans the full browser width with an optional dark overlay for text contrast.</p>
    <h4>Fields &amp; tips</h4>
    <ul>
        <li>Use H1 for the headline — only one H1 per page</li>
        <li>Background image should be wide/landscape (min 1400px wide)</li>
        <li>Overlay darkness is adjustable — darker = better text contrast over busy photos</li>
        <li>Paragraph text and button label support shortcodes: <code>{phone}</code>, <code>{business}</code></li>
    </ul>
</div>

<div class="block-card-doc" id="block-hero_split">
    <h3>Hero Split <span class="tag tag-hero">Hero</span> <span class="tag tag-ai">AI Enrich</span></h3>
    <div class="bc-meta">Type: <code>hero_split</code> &nbsp;·&nbsp; Best for: City landing pages and service pages</div>
    <p>A split-layout hero: text (headline, tagline, paragraph, two buttons) on the left, a feature photo on the right. Image side is switchable. The most versatile hero block.</p>
    <h4>Fields &amp; tips</h4>
    <ul>
        <li><strong>Tagline</strong> supports inline HTML — use <code>&lt;span style="color:..."&gt;</code> for a colored word</li>
        <li><strong>Paragraph text</strong> (<code>hs_subtext</code>) also supports HTML</li>
        <li><strong>Image Caption Lines</strong> appear as a badge overlaid on the image corner — useful for a credential or city name</li>
        <li><strong>Button 1</strong> is solid (primary), <strong>Button 2</strong> is outline style</li>
        <li>Mobile stacking order controls whether image or text appears first on small screens</li>
        <li>When AI enrich is active (<code>ai_type_id = hero_subtext</code>), the AI fills the <code>hs_subtext</code> paragraph with city-specific copy</li>
    </ul>
</div>

<div class="block-card-doc" id="block-hero_grid">
    <h3>Hero Grid <span class="tag tag-hero">Hero</span></h3>
    <div class="bc-meta">Type: <code>hero_grid</code> &nbsp;·&nbsp; Best for: Homepage hero with service grid</div>
    <p>Headline and CTA on the left, a 2×3 icon grid on the right, over a colored background. Shows 4–6 services at a glance alongside the main message.</p>
    <h4>Fields &amp; tips</h4>
    <ul>
        <li>Each grid item: icon image, heading, short text</li>
        <li>Works best with exactly 4 or 6 items (fills the grid evenly)</li>
        <li>Background color applies to the full section width</li>
    </ul>
</div>

<div class="block-card-doc" id="block-wide_banner">
    <h3>Wide Banner <span class="tag tag-hero">Hero</span></h3>
    <div class="bc-meta">Type: <code>wide_banner</code> &nbsp;·&nbsp; Best for: Mid-page visual break with CTA</div>
    <p>Full-width background image with a heading and subtext on the left, optional CTA button on the right. Text sits on a semi-transparent overlay.</p>
    <h4>Fields &amp; tips</h4>
    <ul>
        <li>Background image: wide/landscape, min 1400×500px</li>
        <li>Heading and button appear side-by-side at desktop, stacked on mobile</li>
    </ul>
</div>

<div class="block-card-doc" id="block-text">
    <h3>Text <span class="tag tag-content">Content</span></h3>
    <div class="bc-meta">Type: <code>text</code> &nbsp;·&nbsp; Best for: About pages, legal pages, freeform content</div>
    <p>Simple rich-text block inside the standard container. Supports paragraphs, headings (H2–H4), bullet lists, bold, italic, and inline HTML. No images, no columns.</p>
    <h4>Fields &amp; tips</h4>
    <ul>
        <li>Content area accepts raw HTML</li>
        <li>Shortcodes resolve at render time: <code>{phone}</code>, <code>{business}</code>, <code>{city}</code> (city pages only)</li>
    </ul>
</div>

<div class="block-card-doc" id="block-image_right">
    <h3>Image + Text <span class="tag tag-content">Content</span></h3>
    <div class="bc-meta">Type: <code>image_right</code> / <code>image_left</code> &nbsp;·&nbsp; Best for: Service detail sections</div>
    <p>Two-column block: text (heading, paragraph, optional button) on one side and a photo on the other. Image side is selectable via dropdown.</p>
    <h4>Fields &amp; tips</h4>
    <ul>
        <li>Paragraph text supports HTML and shortcodes</li>
        <li>Leave button text blank to hide the button</li>
        <li>Portrait or square photos work best</li>
    </ul>
</div>

<div class="block-card-doc" id="block-video">
    <h3>Video <span class="tag tag-content">Content</span></h3>
    <div class="bc-meta">Type: <code>video</code> &nbsp;·&nbsp; Best for: Course overview, testimonials, explainers</div>
    <p>Embeds a YouTube or Vimeo video in a centered responsive player with optional heading and caption. Uses privacy-enhanced YouTube embed (youtube-nocookie.com).</p>
    <h4>Fields &amp; tips</h4>
    <ul>
        <li>Paste the full YouTube or Vimeo URL — the embed ID is extracted automatically</li>
        <li>Caption field is optional</li>
    </ul>
</div>

<div class="block-card-doc" id="block-custom_html">
    <h3>Custom HTML <span class="tag tag-content">Content</span></h3>
    <div class="bc-meta">Type: <code>custom_html</code> &nbsp;·&nbsp; Best for: Third-party widgets, custom layouts, course schedule shortcodes</div>
    <p>Raw HTML passthrough. The system echoes exactly what you enter — no wrapper is added when the content starts with <code>&lt;div class="content-block"&gt;</code>.</p>
    <h4>Fields &amp; tips</h4>
    <ul>
        <li>To create edge-to-edge (full-width) colored sections, start with <code>&lt;div class="content-block" style="padding:0;margin:0;"&gt;</code> then use the viewport breakout on the inner div: <code>width:100vw; margin-left:calc(-50vw + 50%); flex-shrink:0</code></li>
        <li>Without the <code>content-block</code> wrapper, the block adds 24px padding around your HTML</li>
        <li>Supports course shortcodes: <code>[course_schedule type="PMP Certification"]</code> and <code>[course_card type="PMP Certification"]</code></li>
    </ul>
</div>

<div class="block-card-doc" id="block-html_two_col">
    <h3>HTML Two Column <span class="tag tag-content">Content</span></h3>
    <div class="bc-meta">Type: <code>html_two_col</code> &nbsp;·&nbsp; Best for: Two independent custom widgets side by side</div>
    <p>Two side-by-side raw HTML panels, each independently editable. Columns are equal width (50/50) at desktop and stack on mobile.</p>
</div>

<div class="block-card-doc" id="block-feature_split">
    <h3>Feature Split <span class="tag tag-content">Content</span></h3>
    <div class="bc-meta">Type: <code>feature_split</code> &nbsp;·&nbsp; Best for: "Why choose us" with a visual</div>
    <p>Large feature photo on the left half and a 2×2 icon+text grid on the right half. Optional section heading above both.</p>
    <h4>Fields &amp; tips</h4>
    <ul>
        <li>Each grid cell: icon image, heading, short paragraph</li>
        <li>Icons: square PNG or SVG with transparent background</li>
        <li>Photo: portrait or square works best</li>
    </ul>
</div>

<div class="block-card-doc" id="block-feature_columns">
    <h3>Feature Columns <span class="tag tag-content">Content</span></h3>
    <div class="bc-meta">Type: <code>feature_columns</code> &nbsp;·&nbsp; Best for: Key differentiators right after the hero</div>
    <p>Three or four equal columns, each with an icon/image, heading, and short description. Classic "key differentiators" layout.</p>
    <h4>Fields &amp; tips</h4>
    <ul>
        <li>3 or 4 columns — 5+ gets cramped on desktop</li>
        <li>Icons can be images (PNG, SVG) or left blank</li>
        <li>Description: 1–2 sentences</li>
        <li>Optional section heading above the columns</li>
    </ul>
</div>

<div class="block-card-doc" id="block-service_cards">
    <h3>Service Cards <span class="tag tag-content">Content</span></h3>
    <div class="bc-meta">Type: <code>service_cards</code> &nbsp;·&nbsp; Best for: Service listing pages, 4–8 services</div>
    <p>Centered grid of cards, each with an icon, heading, and short description. White cards with subtle border.</p>
    <h4>Fields &amp; tips</h4>
    <ul>
        <li>Up to 6 cards for clean layout</li>
        <li>Icon images: square PNG with transparent background</li>
        <li>Description supports basic HTML</li>
    </ul>
</div>

<div class="block-card-doc" id="block-tab_services">
    <h3>Tab Services <span class="tag tag-content">Content</span></h3>
    <div class="bc-meta">Type: <code>tab_services</code> &nbsp;·&nbsp; Best for: 3–6 services with individual copy and images</div>
    <p>Vertical tab list on the left, content panel on the right. Keeps the page compact when several services each need their own description and photo.</p>
    <h4>Fields &amp; tips</h4>
    <ul>
        <li>Tab label: 2–4 words</li>
        <li>Each tab: label, heading, paragraph, optional photo</li>
        <li>Paragraph supports HTML and shortcodes</li>
    </ul>
</div>

<div class="block-card-doc" id="block-steps">
    <h3>Steps <span class="tag tag-content">Content</span></h3>
    <div class="bc-meta">Type: <code>steps</code> &nbsp;·&nbsp; Best for: "How it works" or "Our process" section</div>
    <p>Numbered step cards in a horizontal row with a connector line between steps. Best with 3–5 steps.</p>
    <h4>Fields &amp; tips</h4>
    <ul>
        <li>Step numbers are auto-assigned in order — you only provide heading and description</li>
        <li>Descriptions: 1–2 sentences each</li>
    </ul>
</div>

<div class="block-card-doc" id="block-stats">
    <h3>Stats / Counters <span class="tag tag-content">Content</span></h3>
    <div class="bc-meta">Type: <code>stats</code> &nbsp;·&nbsp; Best for: Pass rates, years in business, students trained</div>
    <p>A row of large-number stat counters with a label below each. Designed for impressive metrics that build credibility.</p>
    <h4>Fields &amp; tips</h4>
    <ul>
        <li><strong>Only use real, verifiable numbers.</strong> Invented stats damage credibility and trust</li>
        <li>Value field can include suffix: "98%", "5,000+", "20+"</li>
        <li>Background color is configurable</li>
    </ul>
</div>

<div class="block-card-doc" id="block-image_features">
    <h3>Image Features <span class="tag tag-content">Content</span></h3>
    <div class="bc-meta">Type: <code>image_features</code> &nbsp;·&nbsp; Best for: "What's included" or benefits list with visual proof</div>
    <p>Photo on the left, checklist of feature bullet points on the right, plus a prominent phone CTA below the list.</p>
    <h4>Fields &amp; tips</h4>
    <ul>
        <li>Feature items: one short line each, parallel in structure</li>
        <li>Phone field links as <code>tel:</code> on mobile</li>
        <li>Photo: portrait or square</li>
    </ul>
</div>

<div class="block-card-doc" id="block-pricing_cards">
    <h3>Pricing Cards <span class="tag tag-content">Content</span></h3>
    <div class="bc-meta">Type: <code>pricing_cards</code> &nbsp;·&nbsp; Best for: Pricing tiers, course comparison</div>
    <p>Side-by-side pricing or course cards with a colored header badge, price, feature checklist, and CTA button. One card can be "featured" with an accent border.</p>
    <h4>Fields &amp; tips</h4>
    <ul>
        <li>Price field: any string — "$1,495", "From $995/mo", "Contact us"</li>
        <li>Checklist items support HTML</li>
        <li>Featured card uses the accent color automatically</li>
        <li>CTA URL: http/https/tel/mailto only</li>
    </ul>
</div>

<div class="block-card-doc" id="block-stage_cards">
    <h3>Stage Cards <span class="tag tag-content">Content</span></h3>
    <div class="bc-meta">Type: <code>stage_cards</code> &nbsp;·&nbsp; Best for: Career paths, certification roadmaps, multi-phase programs</div>
    <p>Sequential numbered columns representing stages or phases, each with a heading and a list of sub-items. A progress connector links the stages.</p>
    <h4>Fields &amp; tips</h4>
    <ul>
        <li>Best with 3–5 stages</li>
        <li>Sub-items: short plain text</li>
    </ul>
</div>

<div class="block-card-doc" id="block-comparison_table">
    <h3>Comparison Table <span class="tag tag-content">Content</span></h3>
    <div class="bc-meta">Type: <code>comparison_table</code> &nbsp;·&nbsp; Best for: Showing competitive advantage</div>
    <p>Feature comparison table — your offering highlighted vs. one or more competitors or tiers. Each row is a feature; cells show checkmarks, X marks, or custom text.</p>
    <h4>Fields &amp; tips</h4>
    <ul>
        <li>First column is your offering — highlighted in accent color</li>
        <li>Leftmost column contains the feature names</li>
        <li>Supports ✓ / ✗ symbols or plain text in cells</li>
    </ul>
</div>

<div class="block-card-doc" id="block-testimonials">
    <h3>Testimonials <span class="tag tag-social">Social Proof</span></h3>
    <div class="bc-meta">Type: <code>testimonials</code> &nbsp;·&nbsp; Best for: After pricing or features, before the final CTA</div>
    <p>Review cards with star rating, quote, reviewer name, optional title/company, and optional avatar photo.</p>
    <h4>Fields &amp; tips</h4>
    <ul>
        <li>Quote: plain text, no HTML</li>
        <li>Use real names and real quotes — fabricated testimonials are counterproductive</li>
        <li>Stars display at the configured rating (1–5)</li>
        <li>Photo: square (cropped to circle in some skins)</li>
    </ul>
</div>

<div class="block-card-doc" id="block-team">
    <h3>Team <span class="tag tag-social">Social Proof</span></h3>
    <div class="bc-meta">Type: <code>team</code> &nbsp;·&nbsp; Best for: About pages, instructor listings, staff directories</div>
    <p>Grid of team member cards with circular photo, name, job title, and short bio. Cards stack responsively.</p>
    <h4>Fields &amp; tips</h4>
    <ul>
        <li>Photo: square (cropped to circle)</li>
        <li>Bio: 1–3 sentences plain text</li>
        <li>Title/role appears in accent color below the name</li>
    </ul>
</div>

<div class="block-card-doc" id="block-logo_bar">
    <h3>Logo Bar <span class="tag tag-social">Social Proof</span></h3>
    <div class="bc-meta">Type: <code>logo_bar</code> &nbsp;·&nbsp; Best for: Trust building near the hero or footer</div>
    <p>Horizontal strip of partner/trust/client logos with an optional heading. Each logo can link to an external URL.</p>
    <h4>Fields &amp; tips</h4>
    <ul>
        <li>Logos: PNG or SVG with transparent backgrounds</li>
        <li>Logos display at a uniform height — width varies by aspect ratio</li>
        <li>Adding a URL makes the logo a clickable link (opens in new tab)</li>
    </ul>
</div>

<div class="block-card-doc" id="block-cards">
    <h3>Cards Grid <span class="tag tag-social">Social Proof</span></h3>
    <div class="bc-meta">Type: <code>cards</code> &nbsp;·&nbsp; Best for: Blog teasers, project showcases, service highlights with photos</div>
    <p>Generic cards grid — each card has a photo, heading, body text, and optional link button. More flexible than Service Cards because images are larger.</p>
    <h4>Fields &amp; tips</h4>
    <ul>
        <li>Photo: landscape orientation recommended</li>
        <li>Body text supports HTML</li>
        <li>Leave button text blank to hide the button</li>
    </ul>
</div>

<div class="block-card-doc" id="block-gallery">
    <h3>Gallery <span class="tag tag-social">Social Proof</span></h3>
    <div class="bc-meta">Type: <code>gallery</code> &nbsp;·&nbsp; Best for: Portfolio, project photos, before/after images</div>
    <p>Responsive photo grid — images arranged in rows, clicking opens a full-screen lightbox.</p>
    <h4>Fields &amp; tips</h4>
    <ul>
        <li>Add alt text to each image for SEO</li>
        <li>Square or landscape photos work best in the grid</li>
        <li>6–18 photos is a practical range for load time</li>
    </ul>
</div>

<div class="block-card-doc" id="block-faq_two_col">
    <h3>FAQ Two Column <span class="tag tag-cta">FAQ</span> <span class="tag tag-ai">AI Enrich</span></h3>
    <div class="bc-meta">Type: <code>faq_two_col</code> &nbsp;·&nbsp; Best for: FAQ section near bottom of service/landing pages</div>
    <p>Accordion FAQ in two columns with colored icon, section heading, and background/icon color controls. Helps SEO and reduces pre-sales questions.</p>
    <h4>Fields &amp; tips</h4>
    <ul>
        <li>Each item: Question + Answer (answer supports basic HTML)</li>
        <li>Background color, item box color, heading color, and icon color are all individually configurable</li>
        <li>When AI enrich is active (<code>ai_type_id = faq_additions</code>, inject mode = <code>append</code>), the AI adds additional Q&amp;A items — your base questions are always preserved</li>
    </ul>
</div>

<div class="block-card-doc" id="block-links_grid">
    <h3>Links Grid <span class="tag tag-cta">CTA</span></h3>
    <div class="bc-meta">Type: <code>links_grid</code> &nbsp;·&nbsp; Best for: City index pages, service directory pages</div>
    <p>Grid of text-link buttons over a dark background image with optional heading. Commonly used for internal links to city or service landing pages.</p>
    <h4>Fields &amp; tips</h4>
    <ul>
        <li>Background image: wide/landscape format</li>
        <li>Each item: label (button text) + URL</li>
        <li>Works well with 6–24 links</li>
    </ul>
</div>

<div class="block-card-doc" id="block-cta_banner">
    <h3>CTA Banner <span class="tag tag-cta">CTA</span></h3>
    <div class="bc-meta">Type: <code>cta_banner</code> &nbsp;·&nbsp; Best for: Closing CTA near bottom of any page</div>
    <p>Solid-color full-width strip with centered headline, optional subtext, and CTA button. The simplest and most direct call-to-action block.</p>
    <h4>Fields &amp; tips</h4>
    <ul>
        <li>Background color: global accent, header, or custom hex</li>
        <li>Button style: solid or outline</li>
        <li>Heading and subtext support shortcodes</li>
    </ul>
</div>

<div class="block-card-doc" id="block-cta_card">
    <h3>CTA Card <span class="tag tag-cta">CTA</span></h3>
    <div class="bc-meta">Type: <code>cta_card</code> &nbsp;·&nbsp; Best for: Mid-page CTA without going edge-to-edge</div>
    <p>Wide dark-colored card spanning the full container. Heading and body text on the left, phone number with call button on the right.</p>
    <h4>Fields &amp; tips</h4>
    <ul>
        <li>Phone number is a <code>tel:</code> link on mobile</li>
        <li>Card background color is configurable</li>
        <li>Body text supports shortcodes</li>
    </ul>
</div>

<div class="block-card-doc" id="block-split_cta">
    <h3>Split CTA <span class="tag tag-cta">CTA</span></h3>
    <div class="bc-meta">Type: <code>split_cta</code> &nbsp;·&nbsp; Best for: Prominent contact CTA on service pages</div>
    <p>Two equal columns: colored left panel with heading and text, dark right panel with phone number and call button. High contrast drives both message and action.</p>
    <h4>Fields &amp; tips</h4>
    <ul>
        <li>Left panel: heading + subtext</li>
        <li>Right panel: phone number (use <code>{phone}</code> shortcode) + call button</li>
        <li>Both panel colors individually configurable</li>
    </ul>
</div>

<div class="block-card-doc" id="block-email_banner">
    <h3>Email Banner <span class="tag tag-cta">CTA</span></h3>
    <div class="bc-meta">Type: <code>email_banner</code> &nbsp;·&nbsp; Best for: Newsletter signups, lead magnet downloads</div>
    <p>Split layout: heading and subtext on the left, email input form on the right. Submits to a configured endpoint.</p>
    <h4>Fields &amp; tips</h4>
    <ul>
        <li>Configure the form action URL to your email service provider or CRM</li>
        <li>Heading and subtext support shortcodes</li>
        <li>Button label is customizable</li>
    </ul>
</div>

<div class="block-card-doc" id="block-cta_button">
    <h3>CTA Button <span class="tag tag-cta">CTA</span></h3>
    <div class="bc-meta">Type: <code>cta_button</code> &nbsp;·&nbsp; Best for: Single action between content sections</div>
    <p>A single centered button. Minimal — good for anchor links, downloads, or a standalone register button between sections.</p>
    <h4>Fields &amp; tips</h4>
    <ul>
        <li>URL supports: http/https, <code>tel:</code>, <code>mailto:</code>, and relative paths</li>
        <li>Button uses the global accent color by default</li>
    </ul>
</div>

<div class="block-card-doc" id="block-map_info">
    <h3>Map + Info <span class="tag tag-utility">Utility</span></h3>
    <div class="bc-meta">Type: <code>map_info</code> &nbsp;·&nbsp; Best for: Contact pages, local service pages</div>
    <p>Embedded Google Map on the left and contact/location info on the right — address, hours, phone, and optional photo.</p>
    <h4>Fields &amp; tips</h4>
    <ul>
        <li>Paste the Google Maps embed URL from: Maps → Share → Embed a map → copy the <code>src</code> URL only (not the full iframe)</li>
        <li>Text area supports HTML for structured address and hours</li>
        <li>Shortcodes work in the text field: <code>{phone}</code>, <code>{business}</code></li>
    </ul>
</div>

<div class="block-card-doc" id="block-contact_form">
    <h3>Contact Form <span class="tag tag-utility">Utility</span></h3>
    <div class="bc-meta">Type: <code>contact_form</code> &nbsp;·&nbsp; Best for: Contact page or final CTA</div>
    <p>Standard contact form — Name, Email, Phone, Message. Submissions emailed to <code>CONTACT_EMAIL</code> in config.php. Includes CSRF protection, honeypot spam trap, and rate limiting.</p>
    <h4>Fields &amp; tips</h4>
    <ul>
        <li>Set <code>CONTACT_EMAIL</code> in config.php before going live (defaults to hello@yoursite.com)</li>
        <li>Form heading and button label are editable</li>
        <li>No database required — submissions go straight to email via PHP mail()</li>
    </ul>
</div>

<div class="block-card-doc" id="block-ai_block">
    <h3>AI Block <span class="tag tag-ai">AI Standalone</span></h3>
    <div class="bc-meta">Type: <code>ai_block</code> &nbsp;·&nbsp; Best for: City-specific content unique per page</div>
    <p>A placeholder block that is replaced by AI-generated content during the AI generation step. The AI creates an entire block — type and all fields — based on the registered prompt for the selected AI Type ID. After generation, the block is locked so structure regeneration does not overwrite it.</p>
    <h4>Fields &amp; tips</h4>
    <ul>
        <li><strong>AI Type ID</strong> — must match a key in <code>ai_block_types.json</code></li>
        <li><strong>AI Model</strong> — overrides the default model for this block only</li>
        <li><strong>Prompt override</strong> — customize the instruction for this specific block instance</li>
        <li>After generation, block type changes to whatever the AI produced and <code>_ai_locked: true</code> is set</li>
        <li>To regenerate, use the Force Regenerate option in the Generate tab</li>
    </ul>
</div>

<!-- ═══════════ AI SYSTEM ═══════════ -->
<section id="ai-overview">
    <h2>AI System — Overview</h2>
    <p>The AI system generates city-specific content for landing pages using the Claude API. There are two modes: <strong>Standalone</strong> (the AI creates a whole new block) and <strong>Enrich</strong> (the AI fills one field inside an existing block).</p>
    <p>AI generation is a two-step process that runs separately from structure generation:</p>
    <ol>
        <li><strong>Structure generation (PHP)</strong> — copies template to each city page</li>
        <li><strong>AI generation (Python)</strong> — finds blocks needing AI content and calls Claude</li>
    </ol>
</section>

<section id="ai-standalone">
    <h2>AI System — Standalone Mode</h2>
    <p>The block starts as an <code>ai_block</code> placeholder and the AI replaces it with a complete real block.</p>
    <h3>How it works</h3>
    <ol>
        <li>Add an <code>ai_block</code> to the template and set its <code>ai_type_id</code> (e.g. <code>city_market_intro</code>)</li>
        <li>Structure gen copies the placeholder to each city page</li>
        <li>AI gen finds every <code>ai_block</code>, calls Claude with city context, Claude returns a full block (e.g. a <code>feature_columns</code> block with real headlines and copy)</li>
        <li>The <code>ai_block</code> type changes to the real block type in the JSON</li>
        <li>Block gets <code>_ai_locked: true</code> — next structure gen skips it</li>
    </ol>
    <h3>Example in the PMP template</h3>
    <ul>
        <li>Block 2: <code>ai_block</code> [city_market_intro] → becomes a text block about the city's project management job market</li>
        <li>Block 3: <code>ai_block</code> [feature_columns_local] → becomes a feature_columns block with city-specific differentiators</li>
    </ul>
</section>

<section id="ai-enrich">
    <h2>AI System — Enrich Mode</h2>
    <p>The block keeps its type and most of its content — the AI fills one specific field in place.</p>
    <h3>How it works</h3>
    <ol>
        <li>Take a regular block (e.g. <code>hero_split</code>) and add three fields: <code>ai_type_id</code>, <code>ai_inject_field</code>, <code>ai_inject_mode</code></li>
        <li>Structure gen copies the block with its existing fields to each city page</li>
        <li>AI gen finds blocks where <code>type != ai_block</code> but <code>ai_type_id</code> is set, calls Claude, gets back just the field value</li>
        <li>The value is written into the target field (<code>hs_subtext</code>, <code>fq_items</code>, etc.)</li>
        <li>Block gets <code>_ai_locked: true</code></li>
    </ol>
    <h3>inject_mode options</h3>
    <table>
        <tr><th>Mode</th><th>Behavior</th></tr>
        <tr><td><code>replace</code></td><td>Overwrites the field entirely (used on <code>hs_subtext</code>)</td></tr>
        <tr><td><code>append</code></td><td>Adds AI content to the end (used on <code>fq_items</code> — base FAQs stay, AI adds more)</td></tr>
        <tr><td><code>prepend</code></td><td>Adds AI content to the beginning</td></tr>
    </table>
    <h3>Example in the PMP template</h3>
    <ul>
        <li>Block 1: <code>hero_split</code> [ai_type_id=hero_subtext] → AI fills <code>hs_subtext</code> with city-specific intro paragraph</li>
        <li>Block 8: <code>faq_two_col</code> [ai_type_id=faq_additions, mode=append] → AI adds 2 extra Q&amp;A items to the existing base FAQ list</li>
    </ul>
    <p>The purple <strong>✦ AI: hero_subtext</strong> badge in the block header indicates enrich mode is configured.</p>
</section>

<section id="ai-locking">
    <h2>AI System — Locking</h2>
    <p>Once a block has been AI-generated, it is marked <code>_ai_locked: true</code>. The structure generation engine checks this flag and skips those blocks — so re-running structure gen never wipes AI content.</p>
    <p>Two lock sources are recognized:</p>
    <ul>
        <li><code>_ai_locked: true</code> on the block itself (set by generate.py)</li>
        <li><code>locked_blocks: [0, 3, 7]</code> on the page (explicit index list, set by admin or tooling)</li>
    </ul>
    <p>To force-regenerate locked blocks, use the <strong>Force Regenerate</strong> option in the Generate tab — this ignores both lock sources and replaces all blocks fresh from the template.</p>
</section>

<section id="ai-workflow">
    <h2>AI System — Full Workflow</h2>
    <ol>
        <li>Build/update the template in the Templates tab</li>
        <li>Ensure city list is complete in the City Pages tab</li>
        <li>Run <strong>Structure Generation</strong> — writes city page JSON files from the template</li>
        <li>Preview a few pages to confirm structure looks right</li>
        <li>Run <strong>AI Generation</strong> — confirm the cost warning, then let it run</li>
        <li>Preview a few AI-generated pages to review quality</li>
        <li>If any pages need adjustment, edit the city page JSON directly or re-run AI with Force on specific pages</li>
        <li>Run <strong>Deploy</strong> to push the final static site via FTP</li>
    </ol>
</section>

<!-- ═══════════ CITY PAGES ═══════════ -->
<section id="cities-overview">
    <h2>City Pages — Overview</h2>
    <p>City pages are landing pages generated for each template × city combination. A single template can produce dozens or hundreds of pages — one per city — each with unique content.</p>
    <p>Pages are stored as JSON files in <code>sites/{id}/data/pages/</code> and rendered by the city page router at request time (or pre-rendered to static HTML by the deploy system).</p>
</section>

<section id="cities-templates">
    <h2>City Pages — Templates</h2>
    <p>A template defines: the block structure that every generated page starts from, SEO title and description patterns, and the URL slug pattern. Templates are managed in the <strong>Templates</strong> admin tab.</p>
    <p>Multiple templates can coexist — e.g., one for "PMP Certification Training" and another for "CAPM Certification Training". Each generates its own set of city pages.</p>
</section>

<section id="cities-generation">
    <h2>City Pages — Generation Steps</h2>
    <p>Each template specifies which generation steps run when creating city pages. Steps are PHP files in <code>includes/generation/steps/</code>. The standard step is <code>city_vars</code> which substitutes city shortcodes (<code>{city}</code>, <code>{SS}</code>, <code>{state}</code>, <code>{zip}</code>) into all text fields.</p>
    <p>Steps can modify any part of the page — content blocks, SEO fields, slug — and run in order. Custom steps can be added for special requirements.</p>
</section>

<section id="cities-slugs">
    <h2>City Pages — Slugs</h2>
    <p>The slug pattern in the template uses city tokens to build unique URLs:</p>
    <table>
        <tr><th>Token</th><th>Example output</th></tr>
        <tr><td><code>{city}</code></td><td><code>dallas</code></td></tr>
        <tr><td><code>{SS}</code></td><td><code>tx</code></td></tr>
        <tr><td><code>{state}</code></td><td><code>texas</code></td></tr>
        <tr><td><code>{city_slug}</code></td><td><code>dallas-tx</code></td></tr>
        <tr><td><code>{zip}</code></td><td><code>75201</code></td></tr>
    </table>
    <p>Example slug pattern: <code>pmp-certification-training-{city_slug}</code> → <code>pmp-certification-training-dallas-tx</code></p>
    <div class="callout warn">
        <p><strong>Never use city shortcodes on the homepage.</strong> <code>{city}</code> and <code>{city_state}</code> only resolve inside generated city pages — on the main site they render literally.</p>
    </div>
</section>

<!-- ═══════════ GOING LIVE ═══════════ -->
<section id="deploy-checklist">
    <h2>Pre-Launch Checklist</h2>
    <ul>
        <li>Change the admin password in <code>config.php</code> (never ship with <code>admin123</code>)</li>
        <li>Set <code>CONTACT_EMAIL</code> in <code>config.php</code> to the client's real email</li>
        <li>Set real phone, email, and address in site_vars (Header tab)</li>
        <li>Verify all page slugs, canonical URLs, and SEO meta descriptions</li>
        <li>Test the contact form — confirm email is delivered</li>
        <li>Test on mobile at key breakpoints</li>
        <li>Confirm all city pages render correctly (check a few manually)</li>
        <li>Remove any placeholder images or text</li>
        <li>Do not upload the <code>.git/</code> folder to the live host</li>
    </ul>
</section>

<section id="deploy-ftp">
    <h2>Deploy via FTP</h2>
    <p>The Deploy tab generates a complete static site and pushes it to the live host via FTP.</p>
    <ol>
        <li>Go to Admin → Deploy tab</li>
        <li>Enter FTP credentials: host (no <code>ftp://</code> prefix), username, password, remote path</li>
        <li>Click <strong>Generate &amp; Deploy</strong></li>
        <li>The system crawls all pages, renders static HTML, and uploads via FTP</li>
    </ol>
    <p>The remote path is typically <code>/public_html/</code> or <code>/www/</code> depending on the host. Check with your hosting provider.</p>
</section>

<section id="deploy-security">
    <h2>Security Notes</h2>
    <ul>
        <li><strong>CSRF protection</strong> — all admin POST endpoints verify <code>$_SESSION['csrf_token']</code> via <code>hash_equals()</code></li>
        <li><strong>URL sanitization</strong> — all user-entered URLs go through <code>sanitize_url()</code> — only http/https, tel:, mailto:, and relative links are allowed</li>
        <li><strong>SVG sanitization</strong> — uploaded SVGs have scripts, event handlers, and javascript: URIs stripped</li>
        <li><strong>No .git in webroot</strong> — .htaccess blocks dotfiles, but best practice is to not upload .git at all</li>
        <li><strong>Change admin password</strong> — config.php ships with a bcrypt hash for <code>admin123</code> — change before any live deployment</li>
        <li><strong>Contact form</strong> — includes CSRF token, honeypot field, and rate limiting</li>
    </ul>
</section>

<!-- ═══════════ HOW TO ═══════════ -->
<section id="howto-new-block">
    <h2>How To: Add a New Block Type</h2>
    <p>Adding a new block type requires changes in exactly four files:</p>
    <ol>
        <li><strong><code>includes/blocks.php</code></strong> — add entry to <code>allowed_block_types()</code>, add <code>case</code> in <code>render_content_block()</code></li>
        <li><strong><code>includes/editor.php</code></strong> — add admin panel UI for editing the block's fields</li>
        <li><strong><code>includes/scripts.php</code></strong> — add the new-block JS template (default field values when block is added)</li>
        <li><strong><code>admin/save.php</code></strong> — add the <code>case</code> in the <code>content</code> section that reads <code>$_POST</code> and builds the block array</li>
    </ol>
    <p>Both <code>render_content_block()</code> and <code>render_content_blocks_editor()</code> are large switch statements — each <code>case</code> label matches the block type name exactly. Grep for <code>case 'existing_block_type'</code> to find adjacent code to model from.</p>
    <div class="callout tip">
        <p>After adding the block, also add it to this docs page under the Block Library section with description, best_used_for, and fields notes.</p>
    </div>
</section>

<section id="howto-new-ai-type">
    <h2>How To: Add a New AI Type</h2>
    <ol>
        <li>Add a new entry to <code>ai_block_types.json</code> with a unique key (the type ID), the target block type (for standalone), the prompt template, and optionally <code>ai_inject_field</code> and <code>ai_inject_mode</code> (for enrich)</li>
        <li>Test the prompt using the <strong>AI Prompt Preview</strong> tool in the admin</li>
        <li>Add the <code>ai_type_id</code> to the relevant block in the template editor</li>
        <li>Run structure generation, then AI generation on a small set of cities to verify output quality</li>
    </ol>
</section>

<section id="howto-new-city">
    <h2>How To: Add a City</h2>
    <ol>
        <li>Go to Admin → City Pages tab</li>
        <li>Click <strong>Add City</strong></li>
        <li>Fill in: city name, state, 2-letter abbreviation (SS), ZIP, and city_slug (e.g. <code>dallas-tx</code>)</li>
        <li>Optionally add tags to group the city for filtered generation runs</li>
        <li>Run Structure Generation (optionally filtered to just this new city)</li>
        <li>Run AI Generation for the new city's pages</li>
    </ol>
</section>

<section id="howto-new-template">
    <h2>How To: Add a Template</h2>
    <ol>
        <li>Go to Admin → Templates tab → New Template</li>
        <li>Set a unique Template ID (lowercase, hyphens/underscores only)</li>
        <li>Set the title pattern and slug pattern using city tokens</li>
        <li>Build the content blocks (same editor as Content/Pages tabs)</li>
        <li>For AI-enriched blocks, set <code>ai_type_id</code>, <code>ai_inject_field</code>, and <code>ai_inject_mode</code> directly in the block JSON (or ask for help)</li>
        <li>Run generation for a single test city to verify the output</li>
    </ol>
</section>

<section id="howto-update-docs">
    <h2>How To: Update This Docs Page</h2>
    <p>This file is <code>admin/docs.php</code>. It is a single self-contained HTML file with inline styles — no framework, no build step.</p>
    <p>To update:</p>
    <ul>
        <li>Ask to add or update a section — provide the section name and the content to add</li>
        <li>New sections need an <code>id</code> attribute on the element and a matching entry in the sidebar <code>&lt;nav&gt;</code></li>
        <li>New block types need a <code>.block-card-doc</code> entry with <code>id="block-{type}"</code> — this is what the <strong>?</strong> button in the block header links to</li>
        <li>New admin tabs need a section with <code>id="tab-{tabname}"</code> and a sidebar link</li>
    </ul>
    <p>The <strong>?</strong> button in the block editor opens: <code>/admin/docs.php#block-{block_type}</code>. The anchor must exactly match the block type slug (e.g. <code>block-hero_split</code>, <code>block-faq_two_col</code>).</p>
</section>

</div><!-- /#main -->

<script>
/* Sidebar active link on scroll */
const sections = document.querySelectorAll('[id]');
const navLinks  = document.querySelectorAll('#doc-nav a');
const observer  = new IntersectionObserver(entries => {
    entries.forEach(e => {
        if (e.isIntersecting) {
            navLinks.forEach(a => a.classList.remove('active'));
            const active = document.querySelector('#doc-nav a[href="#' + e.target.id + '"]');
            if (active) {
                active.classList.add('active');
                active.scrollIntoView({ block: 'nearest' });
            }
        }
    });
}, { rootMargin: '-20% 0px -75% 0px' });
sections.forEach(s => observer.observe(s));

/* Sidebar search */
function filterNav(q) {
    q = q.toLowerCase();
    navLinks.forEach(a => {
        a.style.display = (!q || a.textContent.toLowerCase().includes(q)) ? '' : 'none';
    });
    document.querySelectorAll('#doc-nav .nav-group').forEach(g => {
        const next = g.nextElementSibling;
        let hasVisible = false;
        let el = next;
        while (el && !el.classList.contains('nav-group')) {
            if (el.tagName === 'A' && el.style.display !== 'none') hasVisible = true;
            el = el.nextElementSibling;
        }
        g.style.display = hasVisible ? '' : 'none';
    });
}

/* Jump to hash on load */
if (location.hash) {
    setTimeout(() => {
        const el = document.querySelector(location.hash);
        if (el) el.scrollIntoView({ behavior: 'smooth' });
    }, 100);
}
</script>
</body>
</html>
