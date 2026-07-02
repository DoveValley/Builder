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
#sidebar nav a:hover { color: #60a5fa; background: rgba(96,165,250,0.08); }
#sidebar nav a.active { color: #fff; border-left-color: #3b82f6; background: rgba(59,130,246,0.15); }
#sidebar nav .nav-group {
    display: block; padding: 18px 20px 6px;
    font-size: 0.75rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.1em;
    color: #cbd5e1; text-decoration: none; border-left: 3px solid transparent;
    transition: color 0.15s;
}
#sidebar nav a.nav-group:hover { color: #fff; background: none; }

/* Search */
#sidebar .search-wrap { padding: 12px 16px; border-bottom: 1px solid rgba(255,255,255,0.08); }
#sidebar .search-wrap input {
    width: 100%; padding: 7px 10px; border-radius: 6px;
    border: none; font-size: 0.85rem; background: rgba(255,255,255,0.1);
    color: #fff; outline: none;
}
#sidebar .search-wrap input::placeholder { color: #64748b; }

/* Doc tabs */
.doc-tabs {
    display: grid; grid-template-columns: 1fr 1fr; gap: 5px;
    padding: 8px; border-bottom: 1px solid rgba(255,255,255,0.1);
}
.doc-tab {
    background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.09);
    border-radius: 6px; cursor: pointer; text-align: center;
    padding: 8px 6px; font-family: inherit; font-size: 0.78rem; font-weight: 700;
    color: #cbd5e1; transition: all 0.15s;
}
.doc-tab:hover { color: #fff; background: rgba(255,255,255,0.08); border-color: rgba(255,255,255,0.2); }
.doc-tab.active { color: #fff; background: #3b82f6; border-color: #3b82f6; }

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

.doc-group-header {
    margin: 48px -48px 40px;
    padding: 14px 48px;
    background: #1e3a5f;
    color: #cbd5e1;
    font-size: 0.75rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.12em;
}
</style>
</head>
<body>

<!-- ═══════════════ SIDEBAR ═══════════════ -->
<div id="sidebar">
    <div class="logo">
        Site Factory
        <span id="doc-subtitle">Concepts</span>
    </div>
    <div class="doc-tabs">
        <button type="button" class="doc-tab active" data-doc="concepts" onclick="switchDoc('concepts')">Concepts</button>
        <button type="button" class="doc-tab" data-doc="reference" onclick="switchDoc('reference')">Admin</button>
        <button type="button" class="doc-tab" data-doc="building" onclick="switchDoc('building')">Building</button>
        <button type="button" class="doc-tab" data-doc="aicity" onclick="switchDoc('aicity')">AI &amp; City</button>
        <button type="button" class="doc-tab" data-doc="multisite" onclick="switchDoc('multisite')">Multisite</button>
        <button type="button" class="doc-tab" data-doc="devenv" onclick="switchDoc('devenv')">DevEnv</button>
        <button type="button" class="doc-tab" data-doc="extending" onclick="switchDoc('extending')">Extending</button>
    </div>
    <div class="search-wrap">
        <input type="text" id="doc-search" placeholder="Search docs…" oninput="filterNav(this.value)">
    </div>
        <nav id="concepts-nav">
        <a class="nav-group" href="#group-overview">Overview</a>
        <a href="#overview">What is this system?</a>
        <a href="#no-database">No-database philosophy</a>
        <a href="#multi-site">Multi-site</a>

        

        <a class="nav-group" href="#group-technical">Technical</a>
        <a href="#architecture">Architecture</a>
        <a href="#data-flow">Data flow</a>
        <a href="#routing">URL routing</a>
        <a href="#file-structure">File structure</a>

        
    </nav>
    <nav id="reference-nav" hidden>
        <a class="nav-group" href="#group-admin">Admin Tabs</a>
        <a href="#tab-header">Header</a>
        <a href="#tab-theme">Theme</a>
        <a href="#tab-content">Content</a>
        <a href="#tab-pages">Pages</a>
        <a href="#tab-blog">Blog</a>
        <a href="#tab-footer">Footer</a>
        <a href="#tab-popups">Popups</a>
        <a href="#tab-media">Media</a>
        <a href="#tab-seo">SEO &amp; Sitemap</a>
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

        

        <a class="nav-group" href="#group-blocks">Block Library</a>
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

        
    </nav>
    <nav id="building-nav" hidden>
        <a class="nav-group" href="#group-init">Workflow — Initial Site Creation</a>
        <a href="#init-overview">Overview</a>
        <a href="#init-new-site">Creating a new site</a>
        <a href="#init-site-vars">Site variables</a>
        <a href="#init-theme">Theme setup</a>
        <a href="#init-header">Header setup</a>
        <a href="#init-footer">Footer setup</a>

        

        <a class="nav-group" href="#group-core">Workflow — Core Page Creation</a>
        <a href="#core-overview">Overview</a>
        <a href="#core-homepage">Building the homepage</a>
        <a href="#core-pages">Landing pages</a>
        <a href="#core-blog">Blog posts</a>
        <a href="#core-starters">Using page starters</a>

        

        <a class="nav-group" href="#group-schema">Workflow — Schema</a>
        <a href="#schema-overview">Schema overview</a>
        <a href="#schema-type-list">Master type list</a>
        <a href="#schema-by-niche">By business niche</a>
        <a href="#schema-workflow">Workflow &amp; checklist</a>
        <a href="#schema-prompts">Sample Claude prompts</a>

        

        <a class="nav-group" href="#group-going-live">Going Live</a>
        <a href="#deploy-checklist">Pre-launch checklist</a>
        <a href="#deploy-ftp">FTP deploy</a>
        <a href="#deploy-security">Security notes</a>

        
    </nav>
    <nav id="aicity-nav" hidden>
        <a class="nav-group" href="#group-ai">Workflow — AI System</a>
        <a href="#ai-overview">How AI works</a>
        <a href="#ai-standalone">Standalone mode</a>
        <a href="#ai-enrich">Enrich mode</a>
        <a href="#ai-locking">Locking</a>
        <a href="#ai-workflow">Full workflow</a>

        

        <a class="nav-group" href="#group-city">Workflow — City Pages</a>
        <a href="#landing-multicity-overview">Landing Pages &amp; Multi-city</a>
        <a href="#cities-overview">City Pages overview</a>
        <a href="#cities-templates">Templates</a>
        <a href="#cities-generation">Generation steps</a>
        <a href="#cities-slugs">Slugs</a>

        
    </nav>
    <nav id="extending-nav" hidden>
        <a class="nav-group" href="#group-howto">How To</a>
        <a href="#howto-new-block">Add a new block type</a>
        <a href="#howto-new-ai-type">Add a new AI type</a>
        <a href="#howto-new-city">Add a city</a>
        <a href="#howto-new-template">Add a template</a>
        <a href="#howto-update-docs">Update this docs page</a>
    
    </nav>

    <nav id="multisite-nav" hidden>
        <a class="nav-group" href="#ms-overview">Overview</a>
        <a href="#ms-what">What it is</a>
        <a href="#ms-howitworks">How it works</a>
        <a href="#ms-vs-insite">Multisite vs city pages</a>

        <a class="nav-group" href="#ms-arch">Architecture</a>
        <a href="#ms-process-model">One process per site</a>
        <a href="#ms-two-level-clone">Two-level cloning</a>
        <a href="#ms-primitives">Reused primitives</a>
        <a href="#ms-footprint">What persists on disk</a>

        <a class="nav-group" href="#ms-params">The Params Table</a>
        <a href="#ms-columns">CSV columns</a>
        <a href="#ms-validation">Validation &amp; pre-flight</a>
        <a href="#ms-rating">Ratings &amp; reviews</a>

        <a class="nav-group" href="#ms-ai">AI Content</a>
        <a href="#ms-niche">Niche Brief &amp; archetypes</a>
        <a href="#ms-aiblocks">AI blocks &amp; the engine</a>
        <a href="#ms-cache">The content cache</a>
        <a href="#ms-editing">Editing the master safely</a>
        <a href="#ms-landing">Per-deploy landing pages</a>

        <a class="nav-group" href="#ms-seo">Differentiation &amp; SEO</a>
        <a href="#ms-differentiation">Per-site differentiation</a>
        <a href="#ms-uniqueness">Uniqueness tiers</a>

        <a class="nav-group" href="#ms-admin">Running from the Admin</a>
        <a href="#ms-admin-multisite">The Multisite tab</a>

        <a class="nav-group" href="#ms-run">Command Line</a>
        <a href="#ms-cli-intake">1 · Prepare &amp; validate</a>
        <a href="#ms-cli-oneshot">2 · Build one site</a>
        <a href="#ms-cli-campaign">3 · Run the campaign</a>
        <a href="#ms-flags">Options &amp; flags</a>

        <a class="nav-group" href="#ms-ops">Operations</a>
        <a href="#ms-observability">Run logs &amp; cost</a>
        <a href="#ms-security">Security &amp; credentials</a>
        <a href="#ms-files">File reference</a>
        <a href="#ms-remaining">Not yet automated</a>
    </nav>

    <nav id="devenv-nav" hidden>
        <a class="nav-group" href="#group-dev-start">Getting Back In</a>
        <a href="#dev-login">General Login</a>
        <a href="#dev-gitflow">Working on the server (git)</a>
        <a href="#dev-saverollback">Saving &amp; rolling back</a>
        <a href="#dev-reboot">Reboot recovery</a>
        <a href="#dev-lost-mac">If I lose my Mac</a>
        <a href="#dev-quickref">Quick reference</a>
        <a href="#dev-credentials">Credentials &amp; access backup</a>

        <a class="nav-group" href="#group-dev-server">Server (VPS)</a>
        <a href="#dev-server-overview">Overview &amp; access</a>
        <a href="#dev-os">OS — Ubuntu 24.04</a>
        <a href="#dev-docroot">Document root &amp; layout</a>
        <a href="#dev-permissions">Users &amp; permissions</a>

        <a class="nav-group" href="#group-dev-apache">Web Server — Apache</a>
        <a href="#dev-apache">Apache overview</a>
        <a href="#dev-vhosts">Virtual hosts (:80 / :443)</a>
        <a href="#dev-https">HTTPS &amp; self-signed cert</a>
        <a href="#dev-rewrite">mod_rewrite &amp; .htaccess</a>
        <a href="#dev-modules">Enabled modules</a>

        <a class="nav-group" href="#group-dev-php">PHP Runtime</a>
        <a href="#dev-php">PHP 8.3 (mod_php)</a>
        <a href="#dev-localserver">Local dev server</a>

        <a class="nav-group" href="#group-dev-app">Application</a>
        <a href="#dev-nodb">No-database / file storage</a>
        <a href="#dev-repo">Repo layout &amp; includes/</a>
        <a href="#dev-config">config.php &amp; constants</a>
        <a href="#dev-auth">Sessions &amp; admin auth</a>

        <a class="nav-group" href="#group-dev-tooling">Tooling</a>
        <a href="#dev-git">Git (repo = live webroot)</a>
        <a href="#dev-chromium">Chromium screenshots</a>
        <a href="#dev-node">Node 18</a>
        <a href="#dev-preview">The preview.php pattern</a>

        <a class="nav-group" href="#group-dev-deploy">Deploy</a>
        <a href="#dev-static">Static generation</a>
        <a href="#dev-ftp">FTP deploy</a>
        <a href="#dev-audit">Deploy audit &amp; tracking</a>

        <a class="nav-group" href="#group-dev-ops">Operations</a>
        <a href="#dev-services">Services (systemctl)</a>
        <a href="#dev-cron">Cron jobs</a>
        <a href="#dev-firewall">Firewall (ufw)</a>
        <a href="#dev-logs">Logs</a>
        <a href="#dev-backups">Backups</a>

        <a class="nav-group" href="#group-dev-security">Security</a>
        <a href="#dev-sec-https">HTTPS / cert notes</a>
        <a href="#dev-sec-password">Admin password</a>
        <a href="#dev-sec-git">Never deploy .git</a>
        <a href="#dev-sec-uploads">Upload sanitization</a>
    </nav>
</div>

<!-- ═══════════════ MAIN ═══════════════ -->
<div id="main">
<div id="doc-concepts">
<h1>Site Factory — Concepts</h1>
<p class="page-intro">How the Site Factory is built and how its pieces fit together — the mental model to read once before building. Use the sidebar to navigate.</p>

<div class="doc-group-header" id="group-overview">Overview</div>
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

<div class="doc-group-header" id="group-technical">Technical</div>
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

</div><!-- /#doc-concepts -->

<div id="doc-reference" hidden>
<h1>Site Factory — Admin Reference</h1>
<p class="page-intro">What each admin screen and content block does. Reference material — look things up as you need them. Clicking a <strong>?</strong> button in the admin panel jumps straight to the relevant entry here.</p>

<div class="doc-group-header" id="group-admin">Admin Tabs</div>
<section id="tab-header">
    <h2>Tab: Header</h2>
    <p>Controls the site header — logo, site name, phone number, navigation menu, and layout style. Changes here appear on every page of the site.</p>
    <div class="callout tip">
        <p><strong>Tip:</strong> Fill in Site Variables first — then use shortcodes like <code>{phone}</code> and <code>{business}</code> everywhere else so values stay consistent automatically.</p>
    </div>

    <h3>Header Layout</h3>
    <p>Choose the overall header structure. Options include a 2-row layout (logo + info bar on top, colored nav bar below) and a single-row layout (logo, nav, and phone all in one bar). All other header settings apply to whichever layout is selected.</p>

    <h3>Site Variables</h3>
    <p>The most important section in the header tab. These values — business name, phone, email, address, city, state, ZIP — are used site-wide via shortcodes. Set them correctly before touching any other field.</p>
    <table>
        <tr><th>Shortcode</th><th>Value</th></tr>
        <tr><td><code>{business}</code></td><td>Business name</td></tr>
        <tr><td><code>{phone}</code></td><td>Display phone (e.g. 555-555-5555)</td></tr>
        <tr><td><code>{tel}</code></td><td>E.164 phone for tel: links (e.g. +15555555555)</td></tr>
        <tr><td><code>{email}</code></td><td>Contact email address</td></tr>
        <tr><td><code>{city}</code></td><td>City name (city pages only)</td></tr>
        <tr><td><code>{SS}</code></td><td>2-letter state abbreviation</td></tr>
        <tr><td><code>{year}</code></td><td>Current year (auto-updates)</td></tr>
    </table>

    <h3>Logo</h3>
    <p>Upload the site logo (top-left position). PNG with a transparent background is recommended. After uploading, check dimensions — very tall logos may need CSS height adjustment. The logo also appears on the site's browser tab (favicon is separate).</p>

    <h3>Menu Items</h3>
    <p>Build the navigation menu. Each item has a label and URL. Drag to reorder. Add a sub-menu by nesting items under a parent — renders as a dropdown on desktop. Menu URLs support relative paths (<code>/about</code>), anchors (<code>#services</code>), and external URLs.</p>

    <h3>Top Announcement Bar</h3>
    <p>An optional thin banner that appears above the main header. Use for promotions, alerts, or a secondary contact link. Can be enabled or disabled without deleting the content.</p>

    <h3>Phone Number &amp; Location</h3>
    <p>The phone number displayed in the header (typically top-right). Use <code>{phone}</code> to pull from site_vars. Location text (city, state) can appear alongside the phone on the 2-row layout.</p>

    <h3>Nav Bar Style</h3>
    <p>Colors, spacing, and alignment for the navigation bar. Background color, text color, hover color, and active link color are all independently configurable. These pull from the theme by default but can be overridden per-site.</p>

    <h3>Nav CTA Button</h3>
    <p>An optional call-to-action button inside the nav bar (e.g. "Get a Quote", "Register Now"). Shows as a colored button, separate from the regular menu links. Set label and URL.</p>

    <h3>Header Info Items</h3>
    <p>Secondary information line displayed in the 2-row header above the nav — typically used for hours, email, or a second phone number. Each item has an icon and text.</p>

    <h3>Social Media Links</h3>
    <p>Social profile icons shown in the header. Each entry is a platform name and URL. Icons are rendered automatically based on the platform. Leave the URL blank to hide that platform's icon.</p>
</section>

<section id="tab-theme">
    <h2>Tab: Theme</h2>
    <p>Controls global colors, fonts, and button styles. All values are converted to CSS custom properties and injected inline into every page. Change a color here and it updates everywhere on the site automatically.</p>
    <div class="callout tip">
        <p><strong>Tip:</strong> Color fields in content blocks accept the keywords <code>accent</code>, <code>header</code>, or <code>footer</code> instead of a hex value — they resolve at render time, so one theme change ripples to every block that uses them.</p>
    </div>

    <h3>Brand Colors</h3>
    <p>The core palette for the site — typically 2–3 colors from the client's brand guide. Sets the named color slots that the rest of the system uses:</p>
    <ul>
        <li><strong>Header / Primary</strong> — nav bar background, dark hero sections, dark text contrast areas</li>
        <li><strong>Accent</strong> — buttons, icon tints, highlighted text, active states</li>
        <li><strong>Footer</strong> — footer background (often the same as or slightly darker than the header)</li>
    </ul>

    <h3>Accent &amp; Buttons</h3>
    <p>Fine-tune the accent color behavior: hover shade (auto-darkened or manually set), button background, button text color, and disabled state. Also controls the outline button border and text color when the button variant is not filled.</p>

    <h3>Skin Variants</h3>
    <p>Some blocks support a "skin" — a named color mode that changes the block's background and text independently of the main content. Skins are defined here as additional named slots (e.g., <code>light</code>, <code>dark</code>, <code>tinted</code>). Once defined, a block can select a skin from a dropdown to apply it.</p>

    <h3>Typography</h3>
    <p>Sets the Google Font used site-wide. Enter a Google Font family name (e.g., <code>Inter</code>, <code>Lato</code>) — the font is loaded automatically. Heading size scale, base font size, and line height are also configurable here.</p>

    <h3>Buttons</h3>
    <p>Button border radius (controls corner rounding site-wide — <code>0</code> for square, <code>4px</code> for slightly rounded, <code>9999px</code> for pill shape). Primary and secondary button colors can also be overridden here independently of the accent color.</p>

    <h3>Analytics &amp; Tracking</h3>
    <p>Google Analytics measurement ID (e.g., <code>G-XXXXXXXXXX</code>) and Google Tag Manager container ID. When set, the appropriate tracking snippet is injected into every page's <code>&lt;head&gt;</code>. Leave blank to disable tracking.</p>
</section>

<section id="tab-content">
    <h2>Tab: Content</h2>
    <p>The homepage content editor — build the homepage block by block. Each block is a collapsible card; the full page is assembled from the block sequence in order.</p>
    <div class="callout">
        <p>The Content tab edits the homepage only. To edit a landing page, go to the <strong>Pages</strong> tab and open that page's block editor.</p>
    </div>

    <h3>Load a Homepage Starter</h3>
    <p>If you are starting from scratch, use this section to pre-populate the homepage with a curated block sequence from the Page Starters library. Applying a starter overwrites the current block list — use only on a blank or throwaway homepage. Once applied, all blocks are editable.</p>

    <h3>Content Blocks Editor</h3>
    <p>The main editing area. Each block is shown as a collapsible panel (click the header to expand/collapse). Within each panel are the block's editable fields — text, images, links, and layout options specific to that block type.</p>
    <p>Block header controls (right side of each block bar):</p>
    <ul>
        <li><strong>↑ ↓</strong> — move block up or down in page order</li>
        <li><strong>Clone</strong> — duplicate this block (appears directly below it)</li>
        <li><strong>✕</strong> — delete this block (no undo — save first if unsure)</li>
        <li><strong>?</strong> — opens documentation for this block type</li>
        <li>Purple <strong>✦ AI</strong> badge — this block has AI enrichment configured; the badge shows the AI type ID</li>
    </ul>
    <p>To add a block: click <strong>+ Add Block</strong> at the bottom, pick from the visual block picker, then fill in the fields and click <strong>Save Changes</strong>. The block picker groups blocks by category (Hero, Content, Cards, etc.).</p>
    <div class="callout tip">
        <p><strong>Save often.</strong> There is no auto-save. Click <strong>Save Changes</strong> at the top or bottom of the editor after making changes.</p>
    </div>
</section>

<section id="tab-pages">
    <h2>Tab: Pages</h2>
    <p>Manages all pages other than the homepage and blog — landing pages, about, contact, legal, etc. Each page has its own URL slug, title, SEO settings, and content blocks.</p>

    <h3>Add a New Page</h3>
    <p>Quick-add form at the top. Enter a page title and a slug (URL path). The slug is validated against a reserved list (<code>admin</code>, <code>blog</code>, <code>uploads</code>, etc.) and deduplicated automatically if it conflicts with an existing page. After adding, click <strong>Edit</strong> to open the page's block editor.</p>

    <h3>Core Pages</h3>
    <p>System-special pages that are always present — typically the homepage (handled in the Content tab) and any framework-level pages. These rows appear at the top of the page list and cannot be deleted.</p>

    <h3>Landing Pages</h3>
    <p>All manually-created pages. Each row shows the page title, slug, and last-saved timestamp. Actions per page:</p>
    <ul>
        <li><strong>Edit</strong> — open the page's block editor and SEO settings</li>
        <li><strong>View</strong> — open the live page in a new tab</li>
        <li><strong>Delete</strong> — permanently removes the page (confirm dialog)</li>
    </ul>

    <h3>Page Settings (Edit View)</h3>
    <p>When editing a page you get the full block editor (same as the Content tab) plus a Page Settings panel at the top with:</p>
    <ul>
        <li><strong>Page title</strong> — H1 / <code>&lt;title&gt;</code> override for this page</li>
        <li><strong>SEO title</strong> — browser tab and Google title (leave blank to use page title)</li>
        <li><strong>Meta description</strong> — search snippet (150–160 characters recommended)</li>
        <li><strong>Canonical URL</strong> — set if this page has a canonical elsewhere</li>
        <li><strong>Slug</strong> — the URL path; changing this changes the page's URL immediately</li>
        <li><strong>Schema Markup (JSON-LD)</strong> — paste the page's complete structured data here. See the <a href="#schema-overview">Schema Workflow</a> section for how to write it. Supports shortcodes; validates live as you type.</li>
    </ul>
</section>

<section id="tab-blog">
    <h2>Tab: Blog</h2>
    <p>Manages blog posts. Each post uses the same block system as landing pages and also has post-specific metadata: title, slug, status, date, author, tags, featured image, and excerpt.</p>
    <p>Blog URLs: listing at <code>/blog</code>, tag filter at <code>/blog?tag=project-management</code>, single post at <code>/blog/post-slug</code>, pagination at <code>/blog?p=2</code>.</p>

    <h3>Blog Settings</h3>
    <p>Top-of-tab settings that apply to the listing page:</p>
    <ul>
        <li><strong>Blog heading</strong> — the H1 shown on the <code>/blog</code> listing page</li>
        <li><strong>Intro paragraph</strong> — optional subtext under the heading</li>
        <li><strong>Posts per page</strong> — number of posts shown per page before pagination kicks in</li>
    </ul>

    <h3>Add a New Post</h3>
    <p>Quick-add form at the top. Enter a post title — the slug is auto-generated from it. After creating the post, click <strong>Edit</strong> to open the full post editor.</p>

    <h3>Posts List</h3>
    <p>Table of all posts (draft and published). Each row shows title, slug, status, published date, and tag. Click <strong>Edit</strong> to open the post editor. Posts marked <strong>draft</strong> are not publicly visible — only admins can see them via the direct URL.</p>

    <h3>Post Settings (Edit View)</h3>
    <p>The post editor shows a Post Settings panel plus the block editor. Settings fields:</p>
    <ul>
        <li><strong>Title</strong> — post headline, appears as H1 and in the listing card</li>
        <li><strong>Slug</strong> — URL path under <code>/blog/</code></li>
        <li><strong>Status</strong> — Draft or Published</li>
        <li><strong>Published date</strong> — shown in the byline and listing card; defaults to save time</li>
        <li><strong>Author</strong> — byline name</li>
        <li><strong>Tag</strong> — single tag string (used for filtering at <code>/blog?tag=...</code>)</li>
        <li><strong>Excerpt</strong> — shown on the listing card; if blank, first paragraph is used</li>
        <li><strong>Featured image</strong> — used as the listing card thumbnail and OG image</li>
        <li><strong>SEO fields</strong> — title and meta description override for this post</li>
    </ul>
    <p>Below the settings panel, the full block editor appears — same system as pages. Add, reorder, and edit content blocks to build the post body.</p>
</section>

<section id="tab-footer">
    <h2>Tab: Footer</h2>
    <p>Controls everything in the site footer — logo, columns, contact info, copyright text, social icons, and the optional sticky mobile bar. Use shortcodes (<code>{phone}</code>, <code>{email}</code>, <code>{business}</code>, <code>{year}</code>) instead of hardcoding values so they stay consistent with site_vars.</p>

    <h3>Footer Logo &amp; Phone</h3>
    <p>The logo image displayed in the footer (often a white/light version of the header logo). Paired with the footer phone number — use <code>{phone}</code> to pull from site_vars. Footer logo size and positioning are controlled by the theme.</p>

    <h3>Social Media Links</h3>
    <p>Icons and URLs for social profiles — same field structure as the header. Platform name → URL. Icons render automatically. Leave a URL blank to hide that platform's icon from the footer.</p>

    <h3>Footer Columns</h3>
    <p>Multi-column layout at the center of the footer. Each column has a heading and a list of links or text items. Common patterns:</p>
    <ul>
        <li>Column 1: address + phone</li>
        <li>Column 2: quick navigation links</li>
        <li>Column 3: social links or certifications</li>
    </ul>
    <p>Columns are added/removed with the + / × controls. Each column item can be plain text or a link (label + URL).</p>

    <h3>Disclaimer Text</h3>
    <p>Optional legal or compliance text below the columns — e.g., certifications, PMI® trademark notices, disclaimers. Supports basic HTML for bold/italic/links.</p>

    <h3>Bottom Bar</h3>
    <p>The very bottom strip of the footer — copyright line and optional policy links (Privacy Policy, Terms of Service). Use <code>{year}</code> for the auto-updating year and <code>{business}</code> for the business name. Example: <code>© {year} {business}. All rights reserved.</code></p>

    <h3>Sticky Bottom Bar</h3>
    <p>An optional fixed bar that stays pinned to the bottom of the browser window on mobile — useful for a persistent phone/CTA. Contains a short message and a button (e.g., "Call Now"). Enable/disable independently; does not affect the main footer.</p>
</section>

<section id="tab-popups">
    <h2>Tab: Popups</h2>
    <p>Manages overlay popups — modal dialogs that appear on page load or after a time delay. Popups are useful for lead capture forms, announcements, or limited-time offers.</p>

    <h3>Popup Settings</h3>
    <p>Global controls for popups on this site:</p>
    <ul>
        <li><strong>Enable/Disable</strong> — master toggle for all popups site-wide. Turn off here to suppress popups without deleting them.</li>
        <li><strong>Delay</strong> — seconds after page load before the popup appears (e.g., <code>3</code> for 3 seconds; <code>0</code> for immediate)</li>
    </ul>

    <h3>Popup Content</h3>
    <p>Each popup has: headline, body text, optional image, optional button (label + URL), and an optional email capture form. The popup renders as a centered modal with a dark overlay. Visitors can dismiss it with the X button or by clicking outside — their dismissal is stored in <code>localStorage</code> so the popup does not reappear on subsequent visits within the same browser.</p>
    <div class="callout tip">
        <p><strong>Tip:</strong> Keep popup copy short and the offer clear. Popups with a single focused CTA (e.g., "Get the free guide") convert better than multi-purpose ones.</p>
    </div>
</section>

<section id="tab-media">
    <h2>Tab: Media</h2>
    <p>The media library — all images uploaded for the active site. Images uploaded via block editors appear here automatically. Before uploading a new image, check here first — a topically relevant image may already exist in the library.</p>

    <h3>Upload Area</h3>
    <p>Drag and drop one or more images onto the upload zone, or click to browse. Files are validated server-side for format and size before saving. Accepted formats: JPEG, PNG, GIF, WebP (max 8 MB each). SVG files are sanitized on upload — <code>&lt;script&gt;</code> tags and <code>on*</code> event handlers are stripped automatically.</p>

    <h3>Image Grid</h3>
    <p>All uploaded images shown as thumbnails. Hover to see the filename and dimensions. Click any image to copy its URL path to the clipboard, or use the <strong>Select</strong> button when a media picker is open from a block editor field. Use the search/filter bar to find images by filename when the library is large.</p>
    <div class="callout tip">
        <p><strong>Tip:</strong> Use the <strong>Library</strong> button inside any image upload field in the block editor to open a picker directly from the media library. This is faster than uploading the same image a second time.</p>
    </div>
</section>

<section id="tab-seo">
    <h2>Tab: SEO</h2>
    <p>Global SEO settings — breadcrumbs, Open Graph defaults, and redirects. Schema markup is written per page in each page's own SEO section, not here.</p>

    <h3>Breadcrumbs</h3>
    <p>Enable or disable the breadcrumb navigation bar on landing pages and blog posts. When enabled, a breadcrumb trail (Home › Page Name) appears below the header with inline schema.org microdata. The BreadcrumbList JSON-LD in structured data comes from each page's manually-written schema (not auto-generated here).</p>

    <h3>Hero Page Background / Open Graph</h3>
    <p>The default Open Graph image used when no page-specific OG image is set — this is the image that appears in social media link previews. Should be at least 1200×630px. Also sets the site-wide fallback meta title format (e.g., <code>{page_title} | {business}</code>) and default meta description.</p>

    <h3>Local Business Info</h3>
    <p>Four fields used as shortcode values across the site: <strong>Business name</strong>, <strong>Business URL</strong>, <strong>Rating</strong>, and <strong>Review count</strong>. The rating and review count values are available as <code>{rating}</code> and <code>{review_count}</code> shortcodes — useful inside manually-written schema markup (e.g. an AggregateRating value). These fields do <em>not</em> auto-generate any JSON-LD; schema markup is written manually per page.</p>
    <div class="callout tip">
        <p><strong>Tip:</strong> Set <strong>Rating</strong> and <strong>Review count</strong> here once, then reference them as <code>{rating}</code> and <code>{review_count}</code> in your schema textareas so the values stay in sync site-wide without editing every page individually.</p>
    </div>

    <h3>Sitemap</h3>
    <p>The sitemap at <code>/sitemap.xml</code> is generated automatically — no configuration needed. It includes the homepage, all landing pages, the blog index, and all published blog posts. When you deploy, the static generator bakes it out to <code>output/{site_id}/sitemap.xml</code>.</p>
    <p><strong>lastmod dates are real, not fake.</strong> Every time you save a page in the Content or Pages tab, the system stamps a <code>last_modified</code> date on that page. The sitemap reads that date and reports it to Google — so crawl budget is not wasted on pages that haven't actually changed. Blog posts use the <code>updated_at</code> field the same way.</p>
    <div class="callout tip">
        <p><strong>Tip:</strong> Submit the sitemap URL to Google Search Console once after launch: <code>https://yourdomain.com/sitemap.xml</code>. After that, Google discovers changes on its own schedule.</p>
    </div>
    <h3>301 Redirects</h3>
    <p>Manage permanent redirects for URLs that have moved — for example when a city page slug changes after Google has already indexed the old URL. Redirects are stored in <code>data/redirects.json</code> and written into <code>.htaccess</code> automatically every time you run Generate Static Site, so they survive re-deploys.</p>
    <ul>
        <li>Click <strong>+ Add Redirect</strong> to add a new row</li>
        <li><strong>From path</strong> — the old URL path, starting with <code>/</code> (e.g. <code>/keller</code>)</li>
        <li><strong>To path</strong> — the new URL path, starting with <code>/</code> (e.g. <code>/keller-tx/</code>)</li>
        <li>Click the <strong>&times;</strong> button on any row to remove it</li>
        <li>Click <strong>Save Redirects</strong> to persist changes</li>
    </ul>
    <div class="callout tip">
        <p><strong>When to use this:</strong> Any time a page URL changes after it has been live — page slug rename, city slug correction, or a page that was deleted but was previously indexed. A missing 301 means Google returns a 404 for the old URL and any ranking signals built on that URL are lost.</p>
    </div>
</section>

<section id="tab-schedule">
    <h2>Tab: Schedule</h2>
    <p>Manages the course schedule — upcoming class dates, delivery method, price, and registration links. This data powers the <code>[course_schedule]</code> and <code>[course_card]</code> shortcodes used inside Custom HTML blocks. Access via <strong>Plugins → Course Schedule</strong>.</p>

    <h3>Course List</h3>
    <p>Table of all scheduled courses sorted by sort order then date. Each row shows: course type, delivery method, dates, price, and availability. Actions per row:</p>
    <ul>
        <li><strong>Edit</strong> — open the edit form for this course entry</li>
        <li><strong>Duplicate</strong> — create a copy (useful for recurring sessions)</li>
        <li><strong>Delete</strong> — remove from the schedule (confirm dialog)</li>
    </ul>

    <h3>Add a New Course</h3>
    <p>Form at the top of the schedule panel. Fill in all fields and click <strong>Add Course</strong>. Course fields:</p>
    <table>
        <tr><th>Field</th><th>Description</th></tr>
        <tr><td>Course type</td><td>Matches the <code>type="..."</code> attribute in the shortcode — must be consistent across entries for the same course (e.g., always "PMP Certification")</td></tr>
        <tr><td>Delivery</td><td>Live-Virtual or On-Demand</td></tr>
        <tr><td>Dates</td><td>Display string, e.g. "Jul 14–17, 2025" — entered as text, not a date picker</td></tr>
        <tr><td>Time (EST)</td><td>Time range, e.g. "8:30am–5:00pm" or "Self-paced" for On-Demand</td></tr>
        <tr><td>Price</td><td>Current price (shown as-is, including $ if desired)</td></tr>
        <tr><td>Old price</td><td>Shown with strikethrough if set — used for sale pricing</td></tr>
        <tr><td>Register URL</td><td>Link to the registration or checkout page for this session</td></tr>
        <tr><td>Availability note</td><td>Optional urgency text, e.g. "Only 3 seats left"</td></tr>
        <tr><td>Guaranteed</td><td>Check to show a "Guaranteed to run" badge on this session</td></tr>
        <tr><td>Sort order</td><td>Numeric; lower numbers appear first in the schedule widget</td></tr>
    </table>
    <div class="callout tip">
        <p><strong>Shortcode usage:</strong> To embed the schedule on a page, use a Custom HTML block containing <code>[course_schedule type="PMP Certification"]</code> for the filterable table widget, or <code>[course_card type="PMP Certification"]</code> for the compact card widget. Use <code>type="All"</code> to show all course types.</p>
    </div>
</section>

<section id="tab-starters">
    <h2>Tab: Page Starters</h2>
    <p>Pre-built page layouts — block sequence skeletons that pre-populate a new page when you apply one. Starters are applied once; the result becomes a regular editable page. The original starter is never modified. Starters are global — shared across all sites in the system.</p>

    <h3>Add a New Starter</h3>
    <p>Quick-add form: enter a name, choose a category (Training, Universal, etc.), and add an optional short description (shown in the picker when applying a starter to a page). Click <strong>Add Starter</strong> — then click <strong>Edit</strong> on the new row to build its block sequence.</p>

    <h3>Page Starters List</h3>
    <p>All starters organized by category tab strips. Each starter row shows its name, description, and the list of block types it contains (shown as colored chips). Actions:</p>
    <ul>
        <li><strong>Edit</strong> — open the block sequence editor for this starter</li>
        <li><strong>Duplicate</strong> — create a copy of the starter in the same category</li>
        <li><strong>✕</strong> — delete the starter (confirm dialog)</li>
    </ul>

    <h3>Starter Editor (Edit View)</h3>
    <p>Two panels: <strong>Starter Settings</strong> (name, category, description) and <strong>Block sequence</strong> (the ordered list of block types). Each block type row has a dropdown to change the type, ↑ ↓ arrows to reorder, and ✕ to remove. Add rows with <strong>+ Add block</strong>. Save with the top or bottom Save button.</p>
    <div class="callout tip">
        <p><strong>Tip:</strong> A starter only defines block types and order — it does not store field content. When applied to a page, blocks are created with their default field values, ready to fill in.</p>
    </div>
</section>

<section id="tab-templates">
    <h2>Tab: Templates</h2>
    <p>Manages city page templates. A template defines the block structure, SEO pattern, and slug pattern for a family of city landing pages. Each template × each city combination = one generated landing page.</p>

    <h3>Add a New Template</h3>
    <p>Quick-add form: enter a template name (human-readable label). A template ID (slug) is auto-generated. Click <strong>Add Template</strong> — then click <strong>Edit</strong> on the new row to build the template's content.</p>

    <h3>Templates List</h3>
    <p>All templates in the system. Each row shows the template name, ID, number of blocks, and last-saved date. Click <strong>Edit</strong> to open the full template editor. Templates can be duplicated or deleted from this list.</p>

    <h3>Prompt Registry</h3>
    <p>The AI prompt templates used by the generator for standalone AI blocks. Each entry is a named prompt (e.g., <code>city_intro</code>) with a system prompt and user prompt template. The prompt registry is global — shared across all site templates. Edit entries here when the AI output quality for a given block type needs tuning.</p>

    <h3>Template Settings (Edit View)</h3>
    <p>When editing a template, the settings panel appears at the top:</p>
    <ul>
        <li><strong>Template name</strong> — human-readable label for the admin list</li>
        <li><strong>Title pattern</strong> — H1 and SEO title pattern for generated pages, supports city shortcodes: e.g. <code>PMP Certification Training in {city}, {SS}</code></li>
        <li><strong>Slug pattern</strong> — URL path pattern: e.g. <code>pmp-certification-training-{city_slug}</code></li>
        <li><strong>Meta description pattern</strong> — search snippet template with city shortcodes</li>
        <li><strong>Generation steps</strong> — which phases run for this template: city variable injection, shortcode substitution, AI standalone generation, AI enrichment</li>
    </ul>
    <p>Below the settings panel: the full content block editor for the template. Blocks with a purple <strong>✦ AI</strong> badge will be AI-enriched during generation. Add, reorder, and edit blocks as normal — the template is the master copy that all generated city pages start from.</p>
    <div class="callout">
        <p>Do not hardcode city names into template block content — use <code>{city}</code>, <code>{SS}</code>, <code>{city_slug}</code> shortcodes so generated pages resolve the correct values for each city.</p>
    </div>
</section>

<section id="tab-cities">
    <h2>Tab: Landing Cities</h2>
    <p>The list of cities used for city landing page generation. Each city provides the variable values that fill template shortcodes when pages are generated. Cities here are the "rows" in the template × city matrix — every city in this list can be paired with every template to produce a landing page.</p>

    <h3>Add a City</h3>
    <p>Manual add form. Enter <strong>City</strong> and <strong>SS</strong> (2-letter state abbreviation) on the first row — the <strong>State</strong> full name and <strong>City slug</strong> auto-fill as you type. The city slug format is <code>city-name-ss</code> (e.g. <code>dallas-tx</code>). You can override the auto-filled value by typing directly into those fields. Tags field is optional — add comma-separated tags (e.g., <code>texas, priority</code>) to group cities for filtered generation runs.</p>

    <h3>Import from CSV</h3>
    <p>Bulk-add cities by uploading a CSV file. The CSV must have a header row with columns: <code>city</code>, <code>state</code>, <code>SS</code>, <code>zip</code>, <code>city_slug</code>, and optionally <code>tags</code>. Existing cities are not duplicated — only new cities (by city_slug) are added.</p>

    <h3>Cities List</h3>
    <p>All cities in the system. Each row shows: city, state, SS, ZIP, slug, and tags. Actions:</p>
    <ul>
        <li><strong>Edit</strong> — open the edit form for this city's fields</li>
        <li><strong>Delete</strong> — remove the city and automatically delete all generated page JSON files for that city from <code>data/pages/</code></li>
    </ul>
    <p>Sort by clicking column headers. Filter by tag using the tag filter dropdown.</p>

    <h3>Edit City (Edit View)</h3>
    <p>All city fields are editable: name, state, SS, ZIP, slug, and tags. The slug is what fills <code>{city_slug}</code> in template URL patterns — changing it after pages have been generated will not rename the files (regenerate to pick up the new slug).</p>
</section>

<section id="tab-citypages">
    <h2>Tab: City Pages</h2>
    <p>Shows the status of all generated city landing pages — which template × city combinations have been generated, when, and what state they are in. Also provides generation controls and a history log.</p>

    <h3>Status Grid</h3>
    <p>A matrix of every template × every city combination. Each cell shows whether a page has been generated (green checkmark), is missing (empty), or has been AI-enriched (AI badge). Click a cell to open the page editor for that specific city page. Use this grid to spot gaps — cities that haven't been generated for a given template yet.</p>

    <h3>Generation Controls</h3>
    <p>Filters and action buttons above the grid let you:</p>
    <ul>
        <li>Filter by template — narrow the grid to one template</li>
        <li>Filter by city tag — run generation only for a tagged subset of cities</li>
        <li>Choose structure-only vs. structure + AI — control which generation phases run</li>
        <li>Run generation for the filtered set</li>
    </ul>
    <p>Structure generation is always free. AI generation makes Anthropic API calls and incurs cost.</p>

    <h3>Generation History</h3>
    <p>A log of all past structure generation runs for this site — timestamp, template, city filter used, number of pages written, and duration. Use this to verify when pages were last regenerated and confirm which template version was used.</p>
</section>

<section id="tab-generate">
    <h2>Tab: AI Generation</h2>
    <p>Runs the two-phase generation process for city landing pages. This is the primary control panel for generating AI content at scale.</p>

    <h3>Run Generator</h3>
    <p>The main action panel. Controls:</p>
    <ul>
        <li><strong>Action</strong> — Generate content, Research only, or Sync templates</li>
        <li><strong>City</strong> — run for all cities or a single city</li>
        <li><strong>Scope</strong> — Landing pages only, or all pages</li>
        <li><strong>Model</strong> — override the Claude model for this run. <em>Per-block setting</em> uses whatever model is stored on each block (default Haiku). Select Sonnet or Opus here to force a higher-quality model for the entire run regardless of per-block settings.</li>
        <li><strong>Research cities first</strong> — run the research step before generating content</li>
        <li><strong>Refresh locked blocks</strong> — regenerate even blocks marked <code>_ai_locked</code></li>
        <li><strong>Dry run</strong> — preview without making API calls or writing files</li>
        <li><strong>Run button</strong> — starts the selected action. Confirms before making API calls.</li>
    </ul>
    <p><strong>Structure generation</strong> copies template block data to each city page JSON file, resolves slug/title patterns, and substitutes city shortcodes. Blocks already marked <code>_ai_locked: true</code> are preserved as-is.</p>
    <p><strong>AI generation</strong> runs the Python generator (<code>generate.py</code>) which finds all blocks that need AI processing and calls the Claude API. Generated blocks are locked automatically.</p>
    <div class="callout warn">
        <p><strong>Cost warning:</strong> AI generation makes Anthropic API calls. Each page costs roughly $0.01–$0.05 depending on block count and model chosen. Review the estimate before confirming.</p>
    </div>

    <h3>City Coverage</h3>
    <p>A table showing how many pages exist for each template × city combination — effectively a summary of the status grid from the City Pages tab. Use this to quickly see which templates have gaps (cities that have not been generated yet).</p>

    <h3>Generation Log</h3>
    <p>A running log of AI generation activity — each entry shows the run timestamp, template and city scope, number of pages processed, tokens used, estimated cost, and model. This is the audit trail for AI spend and output volume. Entries are written after each generation run completes.</p>
</section>

<section id="tab-ai-review">
    <h2>Tab: Content Review</h2>
    <p>A review panel showing all AI-generated blocks across every city landing page in one place. Use this after a generation run to spot-check output quality and manage locks.</p>

    <h3>City Review Cards</h3>
    <p>Each city that has been generated appears as a card group. Within each card, every AI-generated or AI-enriched block is listed with:</p>
    <ul>
        <li>The city name and template it belongs to</li>
        <li>The block type and AI type ID</li>
        <li>The generated content (rendered in a preview area)</li>
        <li>The model that generated it and the timestamp</li>
        <li>A <strong>Lock / Unlock</strong> toggle — locked blocks are protected from regeneration</li>
    </ul>
    <p>Scan through the cards to find poorly-generated blocks. Click <strong>Unlock</strong> to allow a specific block to be regenerated on the next AI run, or <strong>Lock</strong> to protect a block you are happy with.</p>
    <div class="callout tip">
        <p><strong>Tip:</strong> After a generation run, spot-check at least 5–10 cities here before locking the whole batch. Occasionally the AI will produce generic or incorrect copy for specific cities — catch it here rather than after publishing.</p>
    </div>
</section>

<section id="tab-ai-blocks">
    <h2>Tab: Block Registry</h2>
    <p>The AI block type registry — each entry defines a named AI block type (e.g., <code>city_market_intro</code>, <code>hero_subtext</code>) with its prompt template, output format, target model, and injection settings. This is the configuration layer between your templates and the AI generator.</p>

    <h3>Edit Block Type</h3>
    <p>The edit form for an existing registry entry. Fields:</p>
    <ul>
        <li><strong>Type ID</strong> — unique slug that matches the <code>ai_type_id</code> field set on template blocks</li>
        <li><strong>Label</strong> — human-readable name shown in the admin badge and review panel</li>
        <li><strong>Mode</strong> — <code>standalone</code> (block generates its own entire structure) or <code>enrich</code> (AI fills one specific field of an existing block)</li>
        <li><strong>Inject field</strong> (enrich mode) — the block field name to write the AI output into (e.g., <code>hs_subtext</code>)</li>
        <li><strong>Inject mode</strong> (enrich mode) — <code>replace</code>, <code>append</code>, or <code>prepend</code></li>
        <li><strong>Model</strong> — which Claude model to use for this type (overrides the global default)</li>
        <li><strong>System prompt</strong> — the instruction context given to the AI before the user prompt</li>
        <li><strong>User prompt template</strong> — the generation prompt; supports city shortcodes (<code>{city}</code>, <code>{SS}</code>, etc.) and page context variables</li>
    </ul>

    <h3>Prompt Preview</h3>
    <p>A test panel below the edit form. Choose a city, click <strong>Preview</strong>, and the system renders the full prompt (system + user) as it will be sent to the API — with all shortcodes resolved. Use this to verify the prompt is correct before running a full generation.</p>

    <h3>Add Block Type</h3>
    <p>A quick-add form at the bottom of the list to create a new registry entry. Enter the type ID and label — then click <strong>Add</strong> and edit the full fields in the edit form.</p>

    <h3>Registry List</h3>
    <p>Table of all registered AI block types. Each row shows type ID, label, mode, and the model it uses. Click <strong>Edit</strong> to open the edit form for that entry. Delete removes the entry from the registry (it does not affect blocks in templates that reference this type ID — those blocks will just be skipped during generation until a matching entry exists again).</p>
</section>

<section id="tab-plugins">
    <h2>Tab: Plugins</h2>
    <p>Enable and configure optional site plugins. Plugins extend the base CMS with features not needed by every site. Each plugin appears as a card in the directory — click one to open its settings panel.</p>

    <h3>Plugin Directory</h3>
    <p>Grid of all installed plugins, each showing an icon, name, and short description. Click any card to open that plugin's admin panel. Currently installed:</p>
    <ul>
        <li><strong>Course Schedule</strong> — adds schedule management (the Schedule sub-panel) and enables <code>[course_schedule]</code> and <code>[course_card]</code> shortcodes inside Custom HTML blocks. See the <a href="#tab-schedule">Schedule</a> section for field details.</li>
    </ul>
    <p>New plugins drop a folder into <code>plugins/</code> and register via the plugin API — no code changes to the core system required.</p>

    <h3>Plugin Panel</h3>
    <p>When you click a plugin card, the tab switches to that plugin's settings panel. Each plugin defines its own panel UI. The breadcrumb at the top shows <strong>← Plugins / Plugin Name</strong> — click <strong>← Plugins</strong> to return to the directory.</p>
</section>

<section id="tab-deploy">
    <h2>Tab: Deploy</h2>
    <p>Generates a complete static site and pushes it to a remote server via FTP. Two sequential steps — Generate Static Site first, then Push to Server. Also includes an audit tool to reconcile local output with what is live on the server.</p>

    <h3>1. Generate Static Site</h3>
    <p>Renders all public pages to static HTML files in <code>output/{site_id}/</code>:</p>
    <ol>
        <li>Homepage, all landing pages, all city pages, blog listing + posts, 404 page</li>
        <li>Copies <code>assets/</code> and <code>uploads/</code> into the output directory</li>
        <li>Writes <code>sitemap.xml</code>, <code>robots.txt</code>, and <code>.htaccess</code> (including any 301 redirects from the SEO tab)</li>
        <li><strong>Prunes stale directories</strong> — any page or city slug directory in <code>output/</code> that is no longer in the current page/city list is automatically deleted, so deleted cities do not reappear on the next deploy</li>
    </ol>
    <p>Safe to re-run at any time. Always regenerate before deploying after content changes.</p>

    <h3>2. Push to Server (FTP)</h3>
    <p>Uploads changed files from <code>output/{site_id}/</code> to the live host. Configure FTP credentials:</p>
    <ul>
        <li><strong>FTP host</strong> — hostname only, no <code>ftp://</code> prefix (e.g. <code>ftp.yoursite.com</code>)</li>
        <li><strong>Port</strong> — default 21</li>
        <li><strong>Username / Password</strong> — FTP credentials</li>
        <li><strong>Remote path</strong> — server directory to deploy into (e.g. <code>/public_html</code>)</li>
        <li><strong>Passive mode</strong> — enable if behind a firewall or NAT</li>
    </ul>
    <p>The system uses a manifest (<code>deploy_manifest.json</code>) to track file hashes — only new or changed files are uploaded, so incremental pushes are fast.</p>

    <h3>3. Server Audit</h3>
    <p>Click <strong>Audit Server</strong> to compare local output against what is live on the server. The results panel shows:</p>
    <ul>
        <li><strong>Matched</strong> — files present on both sides with the same size</li>
        <li><strong>Missing</strong> — files in local output not yet on the server (will be uploaded on next push)</li>
        <li><strong>Orphaned</strong> — files on the server that are not in local output (stale from deleted pages)</li>
        <li><strong>Changed</strong> — files present on both sides but with different sizes</li>
    </ul>
    <p>When orphaned files are found, a <strong>Delete Orphaned Files</strong> button appears. Use this to clean up deleted city pages or old assets from the server.</p>

    <h3>Danger Zone</h3>
    <ul>
        <li><strong>Force Push All</strong> — re-uploads every file regardless of the manifest. Use after a server-side corruption or if the manifest is out of sync. Requires typing <code>PUSH ALL</code> to confirm.</li>
        <li><strong>Force Delete All</strong> — deletes every file and directory under the configured remote path. Clears the manifest so the next push re-uploads everything. Requires typing <code>DELETE ALL</code> to confirm. Use only when you need a completely clean server state.</li>
    </ul>
    <div class="callout warn">
        <p><strong>Never deploy with <code>.git/</code> in the webroot.</strong> The <code>.htaccess</code> blocks direct access to dotfiles, but the safe practice is to not upload <code>.git/</code> at all — it exposes full commit history including old credential hashes.</p>
    </div>
</section>

<div class="doc-group-header" id="group-blocks">Block Library</div>
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

</div><!-- /#doc-reference -->

<div id="doc-building" hidden>
<h1>Site Factory — Building Sites</h1>
<p class="page-intro">The end-to-end methodology for building a site: initial setup, core pages, schema, and going live. Follow these in order.</p>

<div class="doc-group-header" id="group-init">Workflow — Initial Site Creation</div>
<section id="init-overview">
    <h2>Workflow — Initial Site Creation: Overview</h2>
    <p>Before any content blocks, pages, or city pages are built, a site needs its foundation set correctly. Getting this right first means every shortcode, every page, and every generated city page inherits accurate information automatically.</p>
    <p>The foundation consists of four things, in this order:</p>
    <ol>
        <li><strong>Create the site</strong> — register it in the Sites panel</li>
        <li><strong>Site variables</strong> — business name, phone, email, address (used everywhere via shortcodes)</li>
        <li><strong>Theme</strong> — brand colors and fonts</li>
        <li><strong>Header &amp; footer</strong> — logo, nav, contact info in the frame that wraps every page</li>
    </ol>
    <div class="callout warn">
        <p><strong>Do not skip ahead.</strong> Building content before setting site variables means placeholders like <code>(555) 555-0190</code> or a blank business name will appear throughout the site. Fix the foundation first — content follows.</p>
    </div>
</section>

<section id="init-new-site">
    <h2>Workflow — Creating a New Site</h2>
    <p>Each client gets their own site entry in the system. All data for that site — content, uploads, generated pages — lives under <code>sites/{id}/</code> and is completely isolated from other sites.</p>
    <h3>Steps</h3>
    <ol>
        <li>From the admin, go to the <strong>Sites</strong> screen (shown when no site is selected, or via the site switcher in the top bar)</li>
        <li>Click <strong>New Site</strong> and enter a site ID (short slug, e.g. <code>acme-training</code>) and a display name</li>
        <li>The system creates the folder structure: <code>sites/acme-training/data/</code>, <code>sites/acme-training/uploads/</code>, etc.</li>
        <li>Click the new site to select it — the admin now operates on that site's data</li>
    </ol>
    <h3>Site ID rules</h3>
    <ul>
        <li>Lowercase letters, numbers, and hyphens only</li>
        <li>Cannot be changed after creation without renaming the folder and updating references</li>
        <li>Keep it short and descriptive — it appears in file paths and logs</li>
    </ul>
</section>

<section id="init-site-vars">
    <h2>Workflow — Site Variables</h2>
    <p>Site variables are set in the <strong>Header</strong> tab under the <strong>Site Variables</strong> section. They are the single source of truth for the business's contact details. Set them before anything else.</p>
    <h3>Required fields</h3>
    <table>
        <tr><th>Field</th><th>Format</th><th>Example</th></tr>
        <tr><td><code>business</code></td><td>Exact business name</td><td>Granite PM Academy</td></tr>
        <tr><td><code>phone</code></td><td>Display format</td><td>210-555-0100</td></tr>
        <tr><td><code>tel</code></td><td>E.164 for tel: links</td><td>+12105550100</td></tr>
        <tr><td><code>email</code></td><td>Contact email</td><td>info@granitepm.com</td></tr>
        <tr><td><code>website</code></td><td>Full URL with https://</td><td>https://granitepm.com</td></tr>
        <tr><td><code>city</code></td><td>Primary city name</td><td>San Antonio</td></tr>
        <tr><td><code>state</code></td><td>Full state name</td><td>Texas</td></tr>
        <tr><td><code>SS</code></td><td>2-letter abbreviation</td><td>TX</td></tr>
        <tr><td><code>zip</code></td><td>ZIP code</td><td>78201</td></tr>
    </table>
    <p>Once set, use shortcodes throughout all content — <code>{phone}</code>, <code>{business}</code>, <code>{email}</code>, <code>{city}</code>, <code>{SS}</code> — instead of hardcoding the values. If the client's phone number changes, you update it in one place and it propagates everywhere.</p>
    <div class="callout tip">
        <p><strong>Verify before proceeding.</strong> Check that <code>{phone}</code> in a text field renders the correct number. If it shows literally as <code>{phone}</code>, site_vars has not been saved yet.</p>
    </div>
</section>

<section id="init-theme">
    <h2>Workflow — Theme Setup</h2>
    <p>The <strong>Theme</strong> tab sets the global color palette and typography. Changes here apply instantly to every page on the site — it is the fastest way to make a site look on-brand.</p>
    <h3>Steps</h3>
    <ol>
        <li>Find the client's brand colors — check their existing website CSS, a brand guide, or screenshot and use a color picker</li>
        <li>Set <strong>Header / Primary</strong> color — nav bar, dark section backgrounds</li>
        <li>Set <strong>Accent</strong> color — buttons, icons, highlights</li>
        <li>Set <strong>Footer</strong> color — often the same as or slightly darker than the header</li>
        <li>Set the <strong>Google Font</strong> — enter the exact font family name (e.g. <code>Inter</code>, <code>Lato</code>, <code>Raleway</code>)</li>
        <li>Set <strong>Button radius</strong> — <code>4px</code> for slightly rounded, <code>9999px</code> for pill, <code>0</code> for square</li>
        <li>Save and take a screenshot to verify the palette looks correct</li>
    </ol>
    <div class="callout tip">
        <p><strong>Set theme before building any content blocks.</strong> Block fields that reference <code>accent</code> or <code>header</code> color keywords resolve at render time — but you'll want to see the correct colors while building, not after.</p>
    </div>
</section>

<section id="init-header">
    <h2>Workflow — Header Setup</h2>
    <p>The header appears on every page. Set it up correctly once and it is done. The <strong>Header</strong> tab controls layout, logo, navigation, phone display, and the CTA button.</p>
    <h3>Steps</h3>
    <ol>
        <li><strong>Choose a header layout</strong> — single-row (logo + nav + phone in one bar) or 2-row (info bar above, nav bar below). Pick the layout that matches the client's brand style.</li>
        <li><strong>Upload the logo</strong> — PNG with transparent background preferred. Download it from the client's live site with <code>curl -L -o sites/{id}/uploads/logo.png "https://..."</code>. Check dimensions — very tall logos may need height adjustment.</li>
        <li><strong>Set the site name</strong> — always fill this in; it is used in the <code>&lt;title&gt;</code> tag as a fallback when the logo is missing.</li>
        <li><strong>Set the phone</strong> — use <code>{phone}</code> to pull from site_vars.</li>
        <li><strong>Build the nav menu</strong> — add items for the real pages (Home, About, Services, Contact). Use real slugs, not placeholder city names. Add dropdowns for service sub-pages if needed.</li>
        <li><strong>Add a Nav CTA button</strong> if appropriate — e.g., "Register Now" or "Get a Quote".</li>
        <li>Save and take a screenshot to confirm the header looks correct before moving on.</li>
    </ol>
    <div class="callout warn">
        <p><strong>Always set <code>site_name</code>.</strong> A blank site name means the browser tab and JSON-LD schema will have no business name — fix this before deploying.</p>
    </div>
</section>

<section id="init-footer">
    <h2>Workflow — Footer Setup</h2>
    <p>Like the header, the footer appears on every page. It carries the copyright, contact details, navigation links, and social icons. Set it up once as part of the foundation.</p>
    <h3>Steps</h3>
    <ol>
        <li><strong>Footer logo</strong> — often a white or light version of the header logo. Upload separately if the client has one; otherwise reuse the header logo.</li>
        <li><strong>Phone and email</strong> — use <code>{phone}</code> and <code>{email}</code> shortcodes.</li>
        <li><strong>Footer columns</strong> — typical 3-column layout:
            <ul>
                <li>Column 1: business address + phone</li>
                <li>Column 2: quick links (Home, About, Services, Contact)</li>
                <li>Column 3: certifications, social links, or a short tagline</li>
            </ul>
        </li>
        <li><strong>Social links</strong> — add URLs for LinkedIn, Facebook, etc. Leave blank to hide.</li>
        <li><strong>Copyright line</strong> — use <code>© {year} {business}. All rights reserved.</code> The <code>{year}</code> shortcode auto-updates each year.</li>
        <li><strong>Disclaimer</strong> — add any legal or certification notices (e.g., PMI® trademark language).</li>
        <li>Save and take a screenshot to confirm header + footer together look correct.</li>
    </ol>
    <div class="callout tip">
        <p><strong>After the footer is set, the site frame is complete.</strong> Every page you build from this point will have the correct header and footer automatically — you only need to focus on the page content.</p>
    </div>
</section>

<div class="doc-group-header" id="group-core">Workflow — Core Page Creation</div>
<section id="core-overview">
    <h2>Workflow — Core Page Creation: Overview</h2>
    <p>Every site in Site Factory is built from three page types: the <strong>homepage</strong>, <strong>landing pages</strong>, and <strong>blog posts</strong>. All three use the same block-based content system — you build pages by adding and configuring content blocks in order.</p>
    <p>The typical build sequence for a new site:</p>
    <ol>
        <li>Set up site variables, header, theme, and footer first (these apply everywhere)</li>
        <li>Build the homepage block by block</li>
        <li>Create key landing pages (About, Contact, Services, etc.)</li>
        <li>Add blog posts if needed</li>
        <li>Generate city landing pages if the site targets multiple cities</li>
    </ol>
    <div class="callout tip">
        <p><strong>Build in phases.</strong> Set up site_vars and the header before touching any content — shortcodes like <code>{phone}</code> and <code>{business}</code> won't resolve correctly until those are filled in.</p>
    </div>
</section>

<section id="core-homepage">
    <h2>Workflow — Building the Homepage</h2>
    <p>The homepage (<code>index.php</code>) is edited in the <strong>Content</strong> tab. It is the site's primary page and typically receives the most attention in terms of block count and design.</p>
    <h3>Recommended homepage block order</h3>
    <ol>
        <li><strong>Hero or Hero Split</strong> — headline, subtext, primary CTA, hero image. Use the client's actual headline, not a generated one.</li>
        <li><strong>Feature Columns</strong> — 3–4 key differentiators with icons or images</li>
        <li><strong>Stats</strong> — real numbers only (pass rates, years in business, students trained). Never invent stats.</li>
        <li><strong>Pricing Cards or Service Cards</strong> — main offerings</li>
        <li><strong>Testimonials</strong> — real quotes with real names</li>
        <li><strong>FAQ Two Column</strong> — 6–10 common questions</li>
        <li><strong>CTA Banner</strong> — closing call to action</li>
        <li><strong>Contact Form</strong> — if needed</li>
    </ol>
    <h3>Key rules for the homepage</h3>
    <ul>
        <li>Only one H1 per page — use it in the hero headline</li>
        <li>Do <strong>not</strong> use <code>{city}</code> or <code>{SS}</code> shortcodes on the homepage — they only resolve inside city landing pages and will render literally here</li>
        <li>Save after every block — there is no auto-save</li>
        <li>Take a screenshot after each block to catch layout problems early</li>
    </ul>
</section>

<section id="core-pages">
    <h2>Workflow — Creating Landing Pages</h2>
    <p>Landing pages are any pages other than the homepage and blog — About, Contact, individual service pages, legal pages, etc. They are managed in the <strong>Pages</strong> tab.</p>
    <h3>Creating a page</h3>
    <ol>
        <li>Go to <strong>Pages</strong> tab → <strong>Add a New Page</strong></li>
        <li>Enter a title and slug (e.g. <code>about</code> → URL becomes <code>/about</code>)</li>
        <li>Click <strong>Add Page</strong>, then <strong>Edit</strong> on the new row</li>
        <li>Set Page Settings: SEO title, meta description, and slug (if you want to change it)</li>
        <li>Add content blocks — same system as the homepage</li>
        <li>Click <strong>Save Changes</strong></li>
    </ol>
    <h3>Slug rules</h3>
    <ul>
        <li>Use generic service slugs — <code>pmp-certification-training</code>, not <code>pmp-certification-training-dallas-tx</code> (city-specific slugs belong in city page templates)</li>
        <li>Slugs are validated against a reserved list — <code>admin</code>, <code>blog</code>, <code>uploads</code>, and others cannot be used</li>
        <li>Changing a slug after the page is live will break any existing links to that URL</li>
    </ul>
    <h3>Page SEO fields</h3>
    <p>Each page has its own SEO title (browser tab + Google result), meta description (search snippet), and canonical URL. These override the site-level defaults set in the SEO tab. Best practice: write a unique meta description for every page, 150–160 characters, focused on the page's primary topic and location.</p>
</section>

<section id="core-blog">
    <h2>Workflow — Creating Blog Posts</h2>
    <p>Blog posts are managed in the <strong>Blog</strong> tab. Each post uses the same block system as pages and also has post-specific metadata (author, date, tags, featured image, excerpt).</p>
    <h3>Creating a post</h3>
    <ol>
        <li>Go to <strong>Blog</strong> tab → <strong>Add a New Post</strong></li>
        <li>Enter a post title — the slug is auto-generated from it</li>
        <li>Click <strong>Add Post</strong>, then <strong>Edit</strong> on the new row</li>
        <li>Fill in Post Settings: status (Draft/Published), author, tag, published date, excerpt, and featured image</li>
        <li>Build the post body with content blocks</li>
        <li>Save</li>
    </ol>
    <h3>Draft vs. Published</h3>
    <p>Posts set to <strong>Draft</strong> are not publicly accessible — they do not appear in the listing and cannot be reached at their URL by visitors. Set to <strong>Published</strong> when the post is ready to go live. Published date controls the display order in the listing (newest first).</p>
    <h3>Tags and filtering</h3>
    <p>Each post has a single tag field (a plain text string). The blog listing page automatically shows a tag filter bar with one pill per distinct tag. Visitors can filter to <code>/blog?tag=project-management</code>. Keep tag names consistent — "Project Management" and "project-management" are treated as different tags.</p>
    <h3>Content writing note</h3>
    <p>Do not reproduce another site's content verbatim, even when adapting a competitor or reference page. Write fully original copy covering the same topics and structure in original wording.</p>
</section>

<section id="core-starters">
    <h2>Workflow — Using Page Starters</h2>
    <p>Page Starters are pre-built block sequences you can apply to a new page as a starting point. Instead of building from a blank page, you get a ready-made structure to fill in.</p>
    <h3>Applying a starter to the homepage</h3>
    <ol>
        <li>Go to <strong>Content</strong> tab</li>
        <li>At the top, find the <strong>Load a Homepage Starter</strong> section</li>
        <li>Pick a starter from the list and click <strong>Apply</strong></li>
        <li>The current homepage blocks are replaced with the starter's block sequence</li>
        <li>Fill in each block's fields with real content</li>
    </ol>
    <div class="callout warn">
        <p><strong>Applying a starter overwrites existing blocks.</strong> Only use this on a blank or throwaway homepage — it cannot be undone without re-entering your content.</p>
    </div>
    <h3>Managing starters</h3>
    <p>Starters are created and edited in the <strong>Page Starters</strong> tab. They are global — shared across all sites in the system. A starter only stores block types and order; it does not store field content. When applied, blocks are created with default field values ready to fill in.</p>
    <p>To build a new starter: go to Page Starters → Add a New Starter → give it a name and category → Edit → add blocks in order → Save.</p>
</section>

<div class="doc-group-header" id="group-schema">Workflow — Schema</div>

<section id="schema-overview">
    <h2>Schema Overview</h2>
    <p><strong>Schema markup (JSON-LD)</strong> is structured data you embed in a page's <code>&lt;head&gt;</code> that tells Google exactly what the page is about — in machine-readable form. Google uses it to generate rich results: star ratings in search, course cards, FAQ dropdowns, breadcrumb trails, and event listings. It also helps Google build an accurate knowledge graph entry for your business.</p>

    <h3>How it works in this system</h3>
    <p>Every page editor — Homepage (Content tab), core pages (Pages tab), blog posts (Blog tab), and city templates (Templates tab) — has a <strong>Schema Markup</strong> textarea at the bottom of the SEO section. You paste valid JSON-LD into it and save.</p>
    <p>Schema is written manually. You work out the correct JSON with Claude (using the prompts in the <a href="#schema-prompts">Sample Prompts</a> section), paste it in, and save. This gives you full control over every value — ratings, URLs, names, offers — with no automated guessing.</p>

    <h3>JSON → HTML flow</h3>
    <p>The path from the textarea to the live page differs slightly by context:</p>
    <table style="width:100%;border-collapse:collapse;margin-bottom:16px;font-size:0.88rem;">
        <thead>
            <tr style="background:#f8fafc;border-bottom:2px solid #e2e8f0;">
                <th style="text-align:left;padding:8px 12px;font-weight:700;">Context</th>
                <th style="text-align:left;padding:8px 12px;font-weight:700;">Flow</th>
            </tr>
        </thead>
        <tbody>
            <tr style="border-bottom:1px solid #e5e7eb;">
                <td style="padding:8px 12px;font-weight:600;white-space:nowrap;">Homepage &amp; core pages</td>
                <td style="padding:8px 12px;">JSON saved to <code>site.json</code> → Generate Static Site renders page → JSON embedded in <code>&lt;script type="application/ld+json"&gt;</code> in <code>&lt;head&gt;</code> → HTML deployed to server.</td>
            </tr>
            <tr style="border-bottom:1px solid #e5e7eb;background:#fafafa;">
                <td style="padding:8px 12px;font-weight:600;white-space:nowrap;">City templates</td>
                <td style="padding:8px 12px;">JSON (with shortcodes) saved to <code>templates.json</code> → city generator resolves shortcodes + injects FAQPage → fully-resolved JSON written to each city page file → Generate Static Site embeds it in <code>&lt;script type="application/ld+json"&gt;</code> → HTML deployed to server.</td>
            </tr>
        </tbody>
    </table>
    <p>In all cases the schema you write is exactly what appears in the HTML — no transformation, no guessing. For city pages, open any file in <code>sites/{id}/data/pages/</code> to read the final resolved schema before deploying.</p>

    <h3>The @graph pattern</h3>
    <p>Rather than outputting one <code>&lt;script&gt;</code> tag per schema type, the best practice is to combine multiple types in a single <code>@graph</code> array. Entities reference each other by <code>@id</code> — e.g., a WebPage references the WebSite and Organization by their IDs rather than repeating all their data. This is cleaner for Google to process and avoids duplicate declarations.</p>
    <pre><code>{
  "@context": "https://schema.org",
  "@graph": [
    {
      "@type": "Organization",
      "@id": "https://example.com/#organization",
      "name": "Example Co"
    },
    {
      "@type": "WebPage",
      "@id": "https://example.com/about/#webpage",
      "about": { "@id": "https://example.com/#organization" }
    }
  ]
}</code></pre>

    <h3>Shortcodes in schema</h3>
    <p>The schema textarea supports shortcodes. Supported tokens: <code>{website}</code>, <code>{business}</code>, <code>{business_domain}</code>, <code>{phone}</code>, <code>{tel}</code>, <code>{zip}</code>, <code>{address}</code>, <code>{city}</code>, <code>{state}</code>, <code>{SS}</code>, <code>{city_slug}</code>, <code>{city_state}</code>.</p>
    <p><strong>Homepage and core pages:</strong> shortcodes resolve at render time (when the page is served or when Generate Static Site runs).</p>
    <p><strong>City template pages:</strong> shortcodes resolve at <em>generation time</em> — the moment the city page JSON file is written by the generator. The stored JSON already contains final absolute URLs for every city, so you can open any city page file and read the exact schema that will appear in the HTML. No runtime resolution is needed.</p>

    <h3>City page schema: shortcodes + automatic FAQPage injection</h3>
    <p>City landing pages get their schema in two layers, both resolved at generation time:</p>
    <ol>
        <li><strong>Template schema (you write once).</strong> Stored in the template's SEO → Schema Markup textarea using shortcodes. The generator substitutes real city values and writes the fully resolved JSON into each city page file. One template schema produces correct, absolute-URL schema for every city automatically.</li>
        <li><strong>FAQPage injection (automatic).</strong> After the city page is generated and AI has filled in the FAQ blocks, the generator reads every <code>faq_two_col</code> block, builds a <code>FAQPage</code> entity from the Q&amp;A pairs, and merges it into the @graph — also fully resolved. No manual work needed.</li>
    </ol>
    <p>The result: each city page JSON file contains a complete, self-contained @graph with absolute URLs — Course + WebPage + Credential + BreadcrumbList + FAQPage — all with the correct city name and URL already substituted. Directly inspectable, directly verifiable.</p>
    <p><strong>What this means in practice:</strong> When writing the template schema, <em>do not include a FAQPage</em> — it will be injected and merged automatically. If a city page has no <code>faq_two_col</code> block, or the block has no questions, the FAQPage is simply omitted for that city.</p>

    <h3>Testing your schema</h3>
    <p>Three buttons appear below the Schema textarea in the admin SEO panel. The page must be deployed and the <strong>Canonical URL</strong> field must be filled in for the validator buttons to pre-load the correct URL.</p>
    <ul>
        <li><strong>Format JSON</strong> — re-indents the schema cleanly in the textarea. Use after pasting to make it readable and confirm it is valid JSON. If the JSON is malformed the status indicator turns red with the parse error.</li>
        <li><strong>validator.schema.org ↗</strong> — opens Google's schema validator in a new tab, pre-loaded with the page's canonical URL. Shows every schema type found on the page (WebPage, Course, FAQPage, etc.) with error and warning counts. This is the ground truth for whether your JSON-LD is on the page and structurally valid.</li>
        <li><strong>Rich Results Test ↗</strong> — opens Google's rich results tester in a new tab, pre-loaded with the canonical URL. Shows only schema types that are eligible to appear as visual features in Google Search (star ratings, course cards, breadcrumbs). More selective than validator.schema.org — not all valid schema types produce rich results.</li>
    </ul>
    <div class="callout tip">
        <p><strong>FAQPage note:</strong> Google restricted FAQPage rich results to government and health authority sites in September 2023. FAQPage will not appear in the Rich Results Test for commercial sites — use validator.schema.org instead to confirm it is present and valid. It is still read by Google as structured data.</p>
    </div>
</section>

<section id="schema-type-list">
    <h2>Master Type List</h2>
    <p>Complete reference of all schema types relevant to this system, with priority and placement guidance.</p>

    <h3>🔴 Priority — implement on every applicable site</h3>
    <table style="width:100%;border-collapse:collapse;margin-bottom:20px;font-size:0.88rem;">
        <thead>
            <tr style="background:#fef2f2;border-bottom:2px solid #fca5a5;">
                <th style="text-align:left;padding:8px 12px;font-weight:700;color:#991b1b;">Schema Type</th>
                <th style="text-align:left;padding:8px 12px;font-weight:700;color:#991b1b;">Where</th>
                <th style="text-align:left;padding:8px 12px;font-weight:700;color:#991b1b;">Purpose</th>
            </tr>
        </thead>
        <tbody>
            <tr style="border-bottom:1px solid #e5e7eb;"><td style="padding:8px 12px;font-weight:600;">EducationalOrganization</td><td style="padding:8px 12px;">Homepage</td><td style="padding:8px 12px;">Identifies the training provider to Google's knowledge graph. Use in place of Organization for any business that teaches or certifies.</td></tr>
            <tr style="border-bottom:1px solid #e5e7eb;background:#fafafa;"><td style="padding:8px 12px;font-weight:600;">Organization</td><td style="padding:8px 12px;">Homepage</td><td style="padding:8px 12px;">The base business entity. Use when EducationalOrganization doesn't apply. Includes sameAs for social profile cross-referencing.</td></tr>
            <tr style="border-bottom:1px solid #e5e7eb;"><td style="padding:8px 12px;font-weight:600;">WebSite + SearchAction</td><td style="padding:8px 12px;">Homepage</td><td style="padding:8px 12px;">Registers your site entity and enables sitelinks search box in Google results if your site has internal search.</td></tr>
            <tr style="border-bottom:1px solid #e5e7eb;background:#fafafa;"><td style="padding:8px 12px;font-weight:600;">Course + Offer</td><td style="padding:8px 12px;">All course pages</td><td style="padding:8px 12px;">Unlocks Google's course rich results. Requires name, description, provider, and at least one Offer with price.</td></tr>
            <tr style="border-bottom:1px solid #e5e7eb;"><td style="padding:8px 12px;font-weight:600;">AggregateRating</td><td style="padding:8px 12px;">All course pages</td><td style="padding:8px 12px;">Adds star ratings to search results. Nested inside Course or Organization. Requires ratingValue, reviewCount, bestRating, worstRating.</td></tr>
            <tr style="border-bottom:1px solid #e5e7eb;background:#fafafa;"><td style="padding:8px 12px;font-weight:600;">BreadcrumbList</td><td style="padding:8px 12px;">All interior pages</td><td style="padding:8px 12px;">Shows the breadcrumb path in search results. Requires absolute URLs. Nest inside the page's WebPage schema as breadcrumb property.</td></tr>
            <tr style="border-bottom:1px solid #e5e7eb;"><td style="padding:8px 12px;font-weight:600;">FAQPage</td><td style="padding:8px 12px;">FAQ pages, course pages with FAQs</td><td style="padding:8px 12px;">Unlocks FAQ rich results — expandable Q&amp;A dropdowns in search. Each Question needs acceptedAnswer.text.</td></tr>
            <tr style="border-bottom:1px solid #e5e7eb;background:#fafafa;"><td style="padding:8px 12px;font-weight:600;">EducationalOccupationalCredential</td><td style="padding:8px 12px;">PMP, CAPM, CPMAI pages</td><td style="padding:8px 12px;">Describes the certification the course leads to. Tells Google what credential the student earns and who recognizes it.</td></tr>
        </tbody>
    </table>

    <h3>🟡 High value — implement when content exists</h3>
    <table style="width:100%;border-collapse:collapse;margin-bottom:20px;font-size:0.88rem;">
        <thead>
            <tr style="background:#fefce8;border-bottom:2px solid #fde047;">
                <th style="text-align:left;padding:8px 12px;font-weight:700;color:#854d0e;">Schema Type</th>
                <th style="text-align:left;padding:8px 12px;font-weight:700;color:#854d0e;">Where</th>
                <th style="text-align:left;padding:8px 12px;font-weight:700;color:#854d0e;">Purpose</th>
            </tr>
        </thead>
        <tbody>
            <tr style="border-bottom:1px solid #e5e7eb;"><td style="padding:8px 12px;font-weight:600;">Event</td><td style="padding:8px 12px;">City pages with scheduled dates</td><td style="padding:8px 12px;">Unlocks event rich results in Google Search and Google Events. Requires startDate, endDate, location (VirtualLocation for online).</td></tr>
            <tr style="border-bottom:1px solid #e5e7eb;background:#fafafa;"><td style="padding:8px 12px;font-weight:600;">Article</td><td style="padding:8px 12px;">Blog posts</td><td style="padding:8px 12px;">Marks a page as editorial content. Helps with Google Discover and news-style indexing. Requires headline, datePublished, author.</td></tr>
            <tr style="border-bottom:1px solid #e5e7eb;"><td style="padding:8px 12px;font-weight:600;">HowTo</td><td style="padding:8px 12px;">Step-by-step blog posts</td><td style="padding:8px 12px;">Unlocks rich results for how-to content with numbered steps. Each step needs name and text.</td></tr>
            <tr style="border-bottom:1px solid #e5e7eb;background:#fafafa;"><td style="padding:8px 12px;font-weight:600;">Person</td><td style="padding:8px 12px;">Instructor bios, team pages</td><td style="padding:8px 12px;">Identifies individuals associated with the organization. Useful for instructor credibility signals and author attribution on blog posts.</td></tr>
            <tr style="border-bottom:1px solid #e5e7eb;"><td style="padding:8px 12px;font-weight:600;">ItemList</td><td style="padding:8px 12px;">All courses page, city listing page</td><td style="padding:8px 12px;">Lists multiple items (courses, cities, services) with position and URL. Helps Google understand aggregate pages.</td></tr>
            <tr style="border-bottom:1px solid #e5e7eb;background:#fafafa;"><td style="padding:8px 12px;font-weight:600;">WebPage</td><td style="padding:8px 12px;">Every page</td><td style="padding:8px 12px;">Identifies the page entity and links it to WebSite and Organization via @id. Foundation for cross-referencing.</td></tr>
            <tr style="border-bottom:1px solid #e5e7eb;"><td style="padding:8px 12px;font-weight:600;">SiteNavigationElement</td><td style="padding:8px 12px;">Site-wide (in global schema)</td><td style="padding:8px 12px;">Declares the main nav structure to Google. Useful for large sites where crawlers may miss some pages.</td></tr>
        </tbody>
    </table>

    <h3>🟢 Supplemental — add when applicable</h3>
    <table style="width:100%;border-collapse:collapse;margin-bottom:20px;font-size:0.88rem;">
        <thead>
            <tr style="background:#f0fdf4;border-bottom:2px solid #86efac;">
                <th style="text-align:left;padding:8px 12px;font-weight:700;color:#166534;">Schema Type</th>
                <th style="text-align:left;padding:8px 12px;font-weight:700;color:#166534;">Where</th>
                <th style="text-align:left;padding:8px 12px;font-weight:700;color:#166534;">Purpose</th>
            </tr>
        </thead>
        <tbody>
            <tr style="border-bottom:1px solid #e5e7eb;"><td style="padding:8px 12px;font-weight:600;">VideoObject</td><td style="padding:8px 12px;">Pages with embedded video</td><td style="padding:8px 12px;">Unlocks video rich results. Requires name, description, thumbnailUrl, uploadDate, and either contentUrl or embedUrl.</td></tr>
            <tr style="border-bottom:1px solid #e5e7eb;background:#fafafa;"><td style="padding:8px 12px;font-weight:600;">Speakable</td><td style="padding:8px 12px;">Homepage, key course pages</td><td style="padding:8px 12px;">Marks sections suitable for text-to-speech (Google Assistant). Uses CSS selectors to identify speakable content.</td></tr>
            <tr style="border-bottom:1px solid #e5e7eb;"><td style="padding:8px 12px;font-weight:600;">LocalBusiness</td><td style="padding:8px 12px;">Homepage (if physical location)</td><td style="padding:8px 12px;">Adds NAP (name, address, phone) and hours to Google's local knowledge panel. Only add if there is a real physical address.</td></tr>
        </tbody>
    </table>
</section>

<section id="schema-by-niche">
    <h2>Schema by Business Niche</h2>
    <p>Different business types need different schema priorities. Start with what's most impactful for the specific niche.</p>

    <h3>Training &amp; Education (e.g. Granite PM Academy)</h3>
    <ul>
        <li><strong>Homepage:</strong> EducationalOrganization + WebSite + WebPage. Include aggregateRating on the organization if you have real review data.</li>
        <li><strong>Course pages:</strong> Course + Offer (with price and availability) + AggregateRating + EducationalOccupationalCredential. This unlocks Google's course rich results.</li>
        <li><strong>City pages:</strong> Course (city-specific name/URL via shortcodes) + BreadcrumbList + FAQPage (auto-injected from the <code>faq_two_col</code> block after AI generation — no manual work) + Event (if scheduled dates exist).</li>
        <li><strong>Blog:</strong> Article + BreadcrumbList.</li>
        <li><strong>Skip:</strong> LocalBusiness (unless physical classroom), HowTo (unless genuinely a how-to post).</li>
    </ul>

    <h3>Home Services (pest control, plumbing, HVAC, etc.)</h3>
    <ul>
        <li><strong>Homepage:</strong> LocalBusiness (with address, phone, hours, geo coords) + WebSite + WebPage. AggregateRating if you have Google/Yelp review count.</li>
        <li><strong>Service pages:</strong> Service (name, serviceType, areaServed) + FAQPage (if FAQs) + BreadcrumbList.</li>
        <li><strong>City pages:</strong> Service (city-specific) + BreadcrumbList. Use shortcodes for city name and URL.</li>
        <li><strong>Blog:</strong> Article. HowTo for posts like "How to prevent cockroaches."</li>
        <li><strong>Skip:</strong> Course, EducationalOrganization, Event (unless you run workshops).</li>
    </ul>

    <h3>Professional Services (law, accounting, consulting, real estate)</h3>
    <ul>
        <li><strong>Homepage:</strong> Organization (or LegalService / AccountingService / etc.) + WebSite + WebPage. Person for named principals.</li>
        <li><strong>Service pages:</strong> Service + FAQPage + BreadcrumbList.</li>
        <li><strong>Team/Bio pages:</strong> Person (name, jobTitle, affiliation, sameAs for LinkedIn).</li>
        <li><strong>Blog:</strong> Article with author Person @id cross-reference.</li>
        <li><strong>Skip:</strong> Course (unless you run workshops), Event (unless seminars/webinars).</li>
    </ul>

    <h3>E-commerce</h3>
    <ul>
        <li><strong>Homepage:</strong> Organization + WebSite (with SearchAction) + WebPage.</li>
        <li><strong>Product pages:</strong> Product + Offer (price, availability, currency) + AggregateRating.</li>
        <li><strong>Category pages:</strong> ItemList.</li>
        <li><strong>Blog:</strong> Article.</li>
    </ul>
</section>

<section id="schema-workflow">
    <h2>Schema Workflow &amp; Checklist</h2>
    <p>The approach: work out each page's schema with Claude, paste the JSON into the Schema Markup textarea at the bottom of that page's SEO section, save. No automation, no guessing — you control every value.</p>

    <h3>The three places schema lives</h3>
    <table style="width:100%;border-collapse:collapse;margin-bottom:20px;font-size:0.88rem;">
        <thead>
            <tr style="background:#f8fafc;border-bottom:2px solid #e2e8f0;">
                <th style="text-align:left;padding:8px 12px;font-weight:700;">Area</th>
                <th style="text-align:left;padding:8px 12px;font-weight:700;">Where in admin</th>
                <th style="text-align:left;padding:8px 12px;font-weight:700;">What goes here</th>
            </tr>
        </thead>
        <tbody>
            <tr style="border-bottom:1px solid #e5e7eb;"><td style="padding:8px 12px;font-weight:600;">Homepage</td><td style="padding:8px 12px;">Content tab → SEO section → Schema Markup</td><td style="padding:8px 12px;">Organization/EducationalOrganization + WebSite + WebPage. The site's foundational entity definitions — all other pages reference these @ids.</td></tr>
            <tr style="border-bottom:1px solid #e5e7eb;background:#fafafa;"><td style="padding:8px 12px;font-weight:600;">Core pages</td><td style="padding:8px 12px;">Pages tab → edit a page → SEO section → Schema Markup</td><td style="padding:8px 12px;">Page-specific schema (Course, Service, FAQPage, WebPage, etc.). References homepage entity @ids — does not repeat the organization definition.</td></tr>
            <tr style="border-bottom:1px solid #e5e7eb;"><td style="padding:8px 12px;font-weight:600;">City templates</td><td style="padding:8px 12px;">Templates tab → edit a template → SEO section → Schema Markup</td><td style="padding:8px 12px;">Same as core pages but uses shortcodes (<code>{city}</code>, <code>{SS}</code>, <code>{city_slug}</code>, <code>{website}</code>). Written once — resolves correctly for every generated city page. <strong>Do not add FAQPage here</strong> — the generator injects it automatically from each city's AI-generated <code>faq_two_col</code> block after generation.</td></tr>
        </tbody>
    </table>

    <h3>Build order</h3>
    <ol>
        <li><strong>Homepage first.</strong> Define EducationalOrganization (or Organization), WebSite, and WebPage here. Set the canonical <code>@id</code> values that every other page will reference — e.g., <code>https://domain.com/#organization</code> and <code>https://domain.com/#website</code>.</li>
        <li><strong>Core pages second.</strong> Each core page gets its own schema (Course, Service, etc.) referencing the homepage @ids. Do one page at a time — test in Google Rich Results Test before moving to the next.</li>
        <li><strong>Templates last.</strong> Once the schema pattern for a course page is working, port it to the template with shortcodes substituted for hardcoded values. Do <em>not</em> include FAQPage in the template schema — it is injected automatically from each city's AI-generated <code>faq_two_col</code> block during generation. Regenerate city pages after updating the template schema.</li>
    </ol>

    <h3>Per-page checklist</h3>
    <ul>
        <li>☐ Identify the schema types needed for this specific page (use the master list above)</li>
        <li>☐ Gather the real values: exact URL, canonical URL, business name, phone in E.164, logo full URL, social profile URLs, real rating/review count</li>
        <li>☐ Write the JSON with Claude using a prompt from the section below</li>
        <li>☐ Paste into the Schema Markup textarea — the green border confirms valid JSON</li>
        <li>☐ Save the page</li>
        <li>☐ Deploy and test at <code>search.google.com/test/rich-results</code></li>
        <li>☐ Fix any required-field warnings before moving to the next page</li>
    </ul>

    <h3>Common mistakes</h3>
    <ul>
        <li><strong>Mismatched @id values.</strong> If the homepage defines <code>"@id": "https://domain.com/#organization"</code>, every other page must reference that exact string. A trailing slash difference breaks the cross-reference.</li>
        <li><strong>Relative URLs.</strong> Schema requires absolute URLs everywhere — <code>https://domain.com/logo.png</code> not <code>/logo.png</code>.</li>
        <li><strong>Invented review counts.</strong> Google cross-checks AggregateRating against actual review sources. Use only real numbers from Google, Trustpilot, or your verified review platform.</li>
        <li><strong>Missing required fields.</strong> Course requires name, description, and provider. Offer requires price and priceCurrency. Missing these blocks the rich result entirely.</li>
        <li><strong>Duplicate type declarations.</strong> Don't declare EducationalOrganization on every page — define it once on the homepage and reference it by @id everywhere else.</li>
    </ul>
</section>

<section id="schema-prompts">
    <h2>Sample Claude Prompts</h2>
    <p>Copy and adapt these prompts. Fill in the bracketed values with real data before sending. Ask Claude to output JSON only — no explanation, no markdown code fences.</p>

    <h3>Homepage schema</h3>
    <p>Use this for the Content tab → Schema Markup field. Produces the site's foundational entity definitions.</p>
    <pre><code>Write the complete JSON-LD @graph schema for the homepage of [Business Name].

Business type: [EducationalOrganization / LocalBusiness / Organization]
Website: [https://domain.com]
Business name: [exact legal name]
Phone (E.164): [+15551234567]
Logo (full URL): [https://domain.com/uploads/logo.png]
Social profiles: [LinkedIn URL, Facebook URL, Twitter/X URL]
Rating: [4.8] from [422] reviews [or: no rating data yet]

Include in the @graph:
- [EducationalOrganization or Organization] with @id, name, url, logo, telephone, sameAs array, and aggregateRating (if rating data provided)
- WebSite with @id, url, name, publisher @id reference
- WebPage with @id, url, name, isPartOf @id reference, about @id reference

Use @id cross-references between entities. All URLs must be absolute.
Output valid JSON only — no explanation.</code></pre>

    <h3>Course page schema</h3>
    <p>Use this for the Pages tab → edit a course page → Schema Markup. Run once per course type.</p>
    <pre><code>Write the complete JSON-LD @graph schema for a course page.

Site base: [https://domain.com]
Organization @id already defined on homepage: [https://domain.com/#organization]
WebSite @id already defined on homepage: [https://domain.com/#website]

This page:
- Page URL: [https://domain.com/pmp-certification-training/]
- Page title: [PMP Certification Training | Business Name]
- Course name: [PMP Certification Training]
- Course description: [2-3 sentences]
- Price: [$X,XXX]
- Delivery: [Live Virtual / On-Demand / In-Person]
- Rating: [4.8] from [422] reviews
- Certification earned: [Project Management Professional (PMP)]
- Recognized by: [Project Management Institute (PMI)]
- Has FAQ section on page: [yes/no — if yes, include 3-4 real Q&amp;A pairs]

Include in the @graph:
- WebPage (url, name, isPartOf, about — referencing existing @ids)
- Course (name, description, provider @id, url, offers with price, aggregateRating)
- EducationalOccupationalCredential (name, description, credentialCategory, recognizedBy, url)
- FAQPage with Question/Answer pairs (if FAQ exists on page)
- BreadcrumbList (Home → [Course Name], with absolute URLs)

Output valid JSON only — no explanation.</code></pre>

    <h3>City template schema (with shortcodes)</h3>
    <p>Use this for the Templates tab → edit a template → Schema Markup. The output uses shortcodes so one schema covers every city. <strong>Do not include FAQPage</strong> — the generator builds and injects it automatically from the AI-generated FAQ block for each city after generation runs.</p>
    <pre><code>Write the complete JSON-LD @graph schema for a city landing page template.

This schema will be used as a template — replace hardcoded city names and URLs
with these shortcodes exactly as written:
- {website} — base URL (e.g. https://domain.com)
- {city} — city name (e.g. Austin)
- {SS} — 2-letter state abbreviation (e.g. TX)
- {city_slug} — URL slug (e.g. austin-tx)
- {business} — business name

Organization @id on homepage: [{website}/#organization]
WebSite @id on homepage: [{website}/#website]

This template is for: [course name, e.g. PMP Certification Training]
Page URL pattern: [{website}/pmp-certification-{city_slug}/]
Course description (generic, no city): [2-3 sentences]
Price: [$X,XXX]
Rating: [4.8] from [422] reviews
Certification earned (if applicable): [e.g. Project Management Professional (PMP)]
Recognized by: [e.g. Project Management Institute (PMI)]

Include in the @graph:
- WebPage (url and name using shortcodes, isPartOf and about @id references)
- Course (name with {city} and {SS} shortcodes, provider @id, url with shortcodes, offers with price, aggregateRating)
- EducationalOccupationalCredential (if the course leads to a recognized certification)
- BreadcrumbList (Home → [Course Name] in {city}, {SS})

Do NOT include FAQPage — it is injected automatically from the AI-generated FAQ block during city page generation.
Output valid JSON only — no explanation. Use the shortcode placeholders exactly as listed above wherever city-specific values appear.</code></pre>

    <h3>Service business page schema (home services)</h3>
    <p>For non-education sites — pest control, plumbing, HVAC, etc.</p>
    <pre><code>Write the complete JSON-LD @graph schema for a service page.

Site base: [https://domain.com]
Organization @id on homepage: [https://domain.com/#organization]

This page:
- Page URL: [https://domain.com/cockroach-exterminator-katy-tx/]
- Page title: [Cockroach Exterminator Katy TX | Business Name]
- Service name: [Cockroach Extermination]
- Service area: [Katy, TX]
- Has FAQ section: [yes — include these Q&amp;A pairs: Q1/A1, Q2/A2, Q3/A3]

Include in the @graph:
- WebPage (url, name, isPartOf, about — referencing existing @ids)
- Service (name, serviceType, areaServed, provider @id reference, url)
- FAQPage (if FAQ exists on page)
- BreadcrumbList (Home → [Service Name], absolute URLs)

Output valid JSON only — no explanation.</code></pre>

    <h3>Blog post schema</h3>
    <pre><code>Write the complete JSON-LD @graph schema for a blog post.

Site base: [https://domain.com]
Organization @id on homepage: [https://domain.com/#organization]

This post:
- URL: [https://domain.com/blog/how-to-pass-the-pmp-exam/]
- Title: [How to Pass the PMP Exam on Your First Try]
- Published: [2025-03-15]
- Updated: [2025-03-15]
- Author: [Jane Smith] (if no named author, use: [{Business Name} Team])
- Is this a how-to post with numbered steps? [yes/no]

Include in the @graph:
- WebPage (url, name, isPartOf @id reference)
- Article (headline, datePublished, dateModified, author as Person or Organization, publisher @id reference)
- HowTo with steps (only if this is genuinely a how-to post)
- BreadcrumbList (Home → Blog → [Post Title], absolute URLs)

Output valid JSON only — no explanation.</code></pre>
</section>

<div class="doc-group-header" id="group-going-live">Going Live</div>
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
    <p>The Deploy tab handles the full publish workflow in two steps.</p>
    <ol>
        <li>Go to Admin → Deploy tab</li>
        <li>Click <strong>Generate Static Site</strong> — renders all pages to <code>output/{site_id}/</code>. Wait for it to complete.</li>
        <li>Enter FTP credentials: host (no <code>ftp://</code> prefix), username, password, remote path</li>
        <li>Click <strong>Push to Server</strong> — uploads only new or changed files (incremental)</li>
    </ol>
    <p>The remote path is typically <code>/public_html/</code> or <code>/www/</code> — check with your hosting provider.</p>
    <p>After deleting cities or pages, run <strong>Audit Server</strong> after deploying to find and remove orphaned files still on the server.</p>
    <p>301 redirects set in the SEO tab are automatically included in <code>.htaccess</code> on every Generate.</p>
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

</div><!-- /#doc-building -->

<div id="doc-aicity" hidden>
<h1>Site Factory — AI &amp; City Pages</h1>
<p class="page-intro">The in-site content engine: how AI generation works (standalone, enrich, locking) and the City Pages system that applies it across many cities in one site.</p>

<div class="doc-group-header" id="group-ai">Workflow — AI System</div>
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

<div class="doc-group-header" id="group-city">Workflow — City Pages</div>
<section id="landing-multicity-overview">
    <h2>Overview: Landing Pages &amp; Multi-city Creation</h2>
    <p>This section explains the full picture — what landing pages are, how the multi-city system works, and how all the moving parts (Templates, Cities, AI, Generation) fit together into a repeatable workflow for producing dozens or hundreds of local landing pages from a single master structure.</p>

    <h3>What is a landing page?</h3>
    <p>A landing page is any page on the site that is not the homepage or the blog. It has its own URL slug, its own set of content blocks, and its own SEO title and meta description. Landing pages are created and edited in the <strong>Pages</strong> tab.</p>
    <p>For training and service businesses, the most important landing pages are <strong>city-specific service pages</strong> — e.g., "PMP Certification Training in Dallas, TX" — one per city the business targets. These pages exist to capture local search traffic and can number in the dozens or hundreds.</p>
    <p>Building each page by hand would be impractical. The multi-city system exists to solve exactly this.</p>

    <h3>The multi-city concept</h3>
    <p>Instead of building each city page manually, you define:</p>
    <ol>
        <li>A <strong>Template</strong> — the master block structure, shared by all pages of a given service type</li>
        <li>A <strong>City list</strong> — all the cities you want to target</li>
        <li><strong>Generation</strong> — a one-click process that produces one page per template × city combination</li>
    </ol>
    <p>The math: 1 template × 50 cities = 50 landing pages. 3 templates × 50 cities = 150 pages. Each generated page is unique by city — the city name, state, slug, and any AI content are specific to that city.</p>

    <h3>The four core components</h3>
    <table>
        <tr><th>Component</th><th>Tab</th><th>Purpose</th></tr>
        <tr><td><strong>Templates</strong></td><td>Templates</td><td>Master block structure + SEO/slug patterns for one service type</td></tr>
        <tr><td><strong>Cities</strong></td><td>Landing Cities</td><td>The list of cities, each providing city name, state, SS, ZIP, and city_slug variables</td></tr>
        <tr><td><strong>AI Block Registry</strong></td><td>Block Registry</td><td>Defines what AI generates and how — prompt, field target, model, mode</td></tr>
        <tr><td><strong>Generation</strong></td><td>AI Generation</td><td>The engine that runs structure + AI over every template × city combination</td></tr>
    </table>

    <h3>City shortcodes — how cities inject into pages</h3>
    <p>Templates use city shortcodes as placeholders. When a page is generated for a city, every shortcode is replaced with that city's actual value:</p>
    <table>
        <tr><th>Shortcode</th><th>What it resolves to</th><th>Example</th></tr>
        <tr><td><code>{city}</code></td><td>City name</td><td>Dallas</td></tr>
        <tr><td><code>{state}</code></td><td>Full state name</td><td>Texas</td></tr>
        <tr><td><code>{SS}</code></td><td>2-letter state abbreviation</td><td>TX</td></tr>
        <tr><td><code>{city_slug}</code></td><td>URL-safe city+state slug</td><td>dallas-tx</td></tr>
        <tr><td><code>{zip}</code></td><td>ZIP code</td><td>75201</td></tr>
    </table>
    <div class="callout warn">
        <p><strong>City shortcodes only work inside generated city pages.</strong> On the main site homepage or a manually-created landing page, <code>{city}</code> renders as the literal text "{city}" — it has no city context to resolve from.</p>
    </div>

    <h3>The generation workflow, step by step</h3>
    <ol>
        <li>
            <strong>Build your template</strong> (Templates tab)
            <br>Create a template for your service (e.g., "PMP Certification Training"). Set the slug pattern (<code>pmp-certification-{city_slug}</code>), SEO title pattern (<code>PMP Certification Training in {city}, {SS}</code>), and build the block structure. Use city shortcodes in headings and text. Mark any blocks that need AI enrichment with an <code>ai_type_id</code>.
        </li>
        <li>
            <strong>Add your cities</strong> (Landing Cities tab)
            <br>Enter each city you want to target, or import from CSV. Each city needs: name, state, SS, ZIP, and city_slug. Group cities with tags if you want to run generation on subsets (e.g., "texas" cities only).
        </li>
        <li>
            <strong>Configure AI types</strong> (Block Registry tab)
            <br>For any block marked with <code>ai_type_id</code>, verify the matching registry entry exists with the correct prompt template, target field, and model. Use the Prompt Preview to confirm the output before a full run.
        </li>
        <li>
            <strong>Run structure generation</strong> (AI Generation tab)
            <br>Choose your template and optional city filter, select "Structure only" mode, and click Run. This is free and fast — it creates one JSON file per city under <code>sites/{id}/data/pages/</code>, with city shortcodes resolved and template blocks copied in.
        </li>
        <li>
            <strong>Review a few pages</strong>
            <br>Open the City Pages tab, click a few generated pages, and check that the structure, slug, and shortcode substitution look correct. Fix the template and re-generate if anything is wrong — structure generation always overwrites (except locked blocks).
        </li>
        <li>
            <strong>Run AI generation</strong> (AI Generation tab)
            <br>Select "Structure + AI" mode and enter your Anthropic API key. Confirm the estimated page count and cost, then click Run. The Python generator calls the Claude API for each block that needs AI content and locks those blocks when done.
        </li>
        <li>
            <strong>Review AI output</strong> (Content Review tab)
            <br>Scan generated content across cities. Unlock and re-run any blocks with poor output. Lock everything you are happy with.
        </li>
        <li>
            <strong>Deploy</strong> (Deploy tab)
            <br>Build the static site and push via FTP to the live host.
        </li>
    </ol>

    <h3>How generated pages are stored and served</h3>
    <p>Each generated city page is a JSON file at <code>sites/{id}/data/pages/{slug}.json</code>. At request time, the page router loads that JSON, resolves any remaining shortcodes, and renders it through <code>includes/site-template.php</code> — the same template that renders all other pages. There is no separate rendering system for city pages; they are just pages with city-specific data.</p>
    <p>For deployment, the Deploy tab pre-renders every city page to static HTML and pushes the files via FTP.</p>

    <h3>Protecting AI content from being overwritten</h3>
    <p>When you re-run structure generation (e.g., after editing the template), blocks marked <code>_ai_locked: true</code> are preserved as-is in the generated page — their content is not overwritten by the fresh template copy. This means you can update non-AI parts of the template and regenerate without losing AI-generated copy.</p>
    <p>Blocks are auto-locked after AI generation. You can manually lock or unlock blocks from the Content Review tab or from the block editor on an individual city page.</p>

    <h3>Multiple templates for the same site</h3>
    <p>A site can have multiple templates running against the same city list. Common pattern for a training business:</p>
    <ul>
        <li>Template: "PMP Certification Training" → 50 city pages</li>
        <li>Template: "CAPM Certification Training" → 50 city pages</li>
        <li>Template: "PMI-ACP Training" → 50 city pages</li>
    </ul>
    <p>Total: 150 pages generated from 3 templates × 50 cities. Each template produces completely independent pages with their own slugs, SEO, and block content. Templates share the same city list — you manage cities once, all templates use them.</p>

    <h3>Key rules to keep in mind</h3>
    <ul>
        <li>City shortcodes (<code>{city}</code>, <code>{SS}</code>) on the <strong>homepage</strong> render literally — never use them there</li>
        <li>Slug patterns must be unique across templates — two templates cannot produce the same slug for the same city</li>
        <li>Structure generation overwrites unlocked blocks — lock AI content before re-generating structure</li>
        <li>AI generation costs money — always run structure-only first, review, then run AI</li>
        <li>The city_slug value in the Cities tab must exactly match the pattern expected in the slug pattern (e.g., <code>dallas-tx</code> not <code>Dallas TX</code>)</li>
    </ul>
</section>

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

</div><!-- /#doc-aicity -->

<div id="doc-extending" hidden>
<h1>Site Factory — Extending / Developer</h1>
<p class="page-intro">Developer guides for modifying the codebase — adding block types, AI types, cities, templates, and updating these docs.</p>

<div class="doc-group-header" id="group-howto">How To</div>
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

</div><!-- /#doc-extending -->


<!-- ═══════════════════════════════════════════════════════════════════════ -->
<!-- ═══════════════════════ DEVENV DOCUMENTATION ═══════════════════════════ -->
<!-- ═══════════════════════════════════════════════════════════════════════ -->
<div id="doc-devenv" hidden>
<h1>Site Factory — DevEnv Documentation</h1>
<p class="page-intro">Reference for the server, runtime, and tooling that Site Factory runs on. This documents the live environment — the box itself, Apache, PHP, the deploy pipeline, and day-to-day operations — as distinct from the admin/content workflow covered in the Admin tab.</p>

<!-- ═══════════ GETTING BACK IN ═══════════ -->
<div class="doc-group-header" id="group-dev-start">Getting Back In</div>
<section id="dev-login">
    <h2>General Login</h2>

    <h3>a) VPS login → Claude Code (the command line)</h3>
    <pre><code>ssh root@187.127.254.206          # log into the server (uses your Mac's SSH key, no password typed)
cd /var/www/homepage-builder-new  # go to the project
claude                            # start Claude Code  (or: claude --continue / claude --resume)</code></pre>
    <ul>
        <li><strong>Auth:</strong> SSH key (<code>~/.ssh/id_ed25519</code>), or backup <code>~/.ssh/sitefactory_recovery</code>. No password.</li>
        <li><strong>This is for:</strong> development — running Claude Code, editing files, server config.</li>
    </ul>

    <h3>b) Site Factory admin (the web control panel)</h3>
    <ul>
        <li><strong>URL:</strong> <code>https://187.127.254.206/admin/login.php</code> &nbsp;(self-signed cert → click "proceed")</li>
        <li><strong>User:</strong> <code>admin</code></li>
        <li><strong>Password:</strong> your admin password</li>
        <li><strong>This is for:</strong> building/editing sites, schedule, deploy — in the browser, no SSH needed.</li>
    </ul>

    <div class="callout">
        <p><strong>The difference:</strong> (a) gets you <em>into the machine</em>; (b) gets you <em>into the website's control panel</em>. Different locks, different credentials — full details under Credentials &amp; access backup.</p>
    </div>
</section>

<section id="dev-gitflow">
    <h2>Working on the server (git)</h2>
    <p>The VPS is the <strong>single source of truth</strong> and a real git checkout connected to GitHub (<code>git@github.com:DoveValley/Builder.git</code>). You do all dev <em>on the server</em> — edit, commit, and push from there. The Mac repo is kept as a mirror but is no longer the working copy; don't make divergent edits on the Mac, or the two drift apart (a problem we already had to untangle once).</p>

    <h3>Everyday loop</h3>
    <pre><code>ssh root@187.127.254.206
cd /var/www/homepage-builder-new
claude                            # do your work

git add -A                        # stage changes
git commit -m "what changed"      # save a checkpoint
git push                          # back up to GitHub</code></pre>
    <p>Commit in small, meaningful chunks and push when you pause — every push is an off-site backup and a point you can roll back to.</p>

    <h3>How pushing is authenticated</h3>
    <ul>
        <li>The push uses a dedicated <strong>deploy key</strong> at <code>/root/.ssh/github_deploy</code> (write access), registered on the GitHub repo as "VPS Site Factory (deploy)".</li>
        <li>It's scoped to this repo only via the repo-local setting <code>core.sshCommand</code> — it doesn't affect any other SSH on the box.</li>
        <li>No password or token is typed; <code>git push</code> just works.</li>
    </ul>

    <h3>Handy commands</h3>
    <table>
        <tr><th>What</th><th>Command</th></tr>
        <tr><td>See what's changed</td><td><code>git status</code></td></tr>
        <tr><td>Review a change before committing</td><td><code>git diff</code></td></tr>
        <tr><td>Undo uncommitted edits to a file</td><td><code>git checkout -- path/to/file</code></td></tr>
        <tr><td>See recent history</td><td><code>git log --oneline -10</code></td></tr>
        <tr><td>Confirm you're in sync with GitHub</td><td><code>git status</code> → "up to date with 'origin/main'"</td></tr>
    </table>

    <div class="callout warn">
        <p><strong>Don't edit on the Mac anymore.</strong> The Mac copy is a backup mirror. If you ever do touch it, pull first (<code>git pull</code>) and push right after — but the simple rule is: <em>work only on the server.</em></p>
    </div>
</section>

<section id="dev-saverollback">
    <h2>Saving &amp; rolling back</h2>
    <p>The key fact: on the VPS, <strong>the git folder <em>is</em> the live website</strong>. <code>/var/www/homepage-builder-new</code> is both your git checkout and the folder Apache serves. There's no build or deploy step — plain PHP reads the files as they are.</p>

    <h3>Editing is already "deploying"</h3>
    <p>The moment you save a file on the server, the live site serves the new version. So the change is live <em>before</em> git is involved. That means <code>git</code> here is not a deploy tool — it's your <strong>save points and undo</strong>.</p>
    <table>
        <tr><th>Action</th><th>What it does</th><th>Effect on the live site</th></tr>
        <tr><td>Save a file</td><td>writes it in the webroot</td><td><strong>Live immediately</strong></td></tr>
        <tr><td><code>git add</code> + <code>git commit</code></td><td>snapshots a checkpoint locally</td><td>none — already live</td></tr>
        <tr><td><code>git push</code></td><td>uploads the snapshot to GitHub (off-site backup)</td><td>none — already live</td></tr>
        <tr><td><code>git pull</code> / <code>git checkout</code></td><td>restores a saved version onto the files</td><td><strong>changes the live site</strong></td></tr>
    </table>
    <p>So: <strong>editing</strong> changes the site · <strong>commit/push</strong> backs it up · <strong>pull/checkout</strong> rolls it back.</p>

    <h3>Save a checkpoint</h3>
    <pre><code>git add -A
git commit -m "what changed"
git push                       # now it's backed up on GitHub and rollback-able</code></pre>
    <p>Commit in small, meaningful chunks — each commit is a point you can return to.</p>

    <h3>Roll back a change</h3>
    <p>Undo edits you <em>haven't committed yet</em> (back to the last commit):</p>
    <pre><code>git checkout -- path/to/file        # one file
git restore .                       # everything (uncommitted changes discarded)</code></pre>
    <p>Restore a file to how it was in an <em>earlier commit</em> (takes effect live instantly):</p>
    <pre><code>git log --oneline -10               # find a good earlier version (copy its short hash)
git checkout &lt;hash&gt; -- path/to/file
git commit -m "Roll back path/to/file to &lt;hash&gt;"   # record the rollback
git push</code></pre>
    <p>Undo an entire commit you already made, keeping history honest:</p>
    <pre><code>git revert &lt;hash&gt;                    # makes a new commit that reverses that one
git push</code></pre>

    <div class="callout">
        <p><strong>Why <code>revert</code> over deleting history:</strong> <code>git revert</code> adds a new commit that undoes the old one, so the live site goes back <em>and</em> the trail stays intact. Avoid <code>git reset --hard</code> / force-push on the server — that rewrites history and can desync GitHub and the Mac mirror.</p>
    </div>
    <div class="callout warn">
        <p><strong>Static-site exception.</strong> Sites rendered to static output (<code>generate_static.php</code> → <code>output/</code>) have a generate step that is separate from git — editing the source doesn't update the static copy until you regenerate. This only applies if a given site uses static generation; the normal dynamic admin/site flow is "edit = live" as above.</p>
    </div>
</section>

<section id="dev-reboot">
    <h2>Reboot recovery</h2>
    <p>The key thing: <strong>almost nothing lives on your Mac.</strong> The Site Factory, Apache, PHP, the data, and Claude Code itself all run on the VPS (<code>187.127.254.206</code>). Your Mac is just the terminal you connect through — so rebooting it stops nothing.</p>

    <h3>1. The live site never went down</h3>
    <p>Apache is enabled to start on boot, so the admin and all sites keep serving regardless of your Mac. Just reopen:</p>
    <ul>
        <li><code>https://187.127.254.206/admin/login.php</code> — self-signed cert, click "proceed"</li>
        <li><code>http://187.127.254.206/admin/login.php</code> — plain HTTP fallback</li>
    </ul>
    <p>Log in with <code>admin</code> + your password. Nothing to restart.</p>

    <h3>2. Get back to dev with Claude Code</h3>
    <p>Claude Code runs <em>on the VPS</em>. From a fresh Terminal on your Mac, reconnect over SSH and relaunch it:</p>
    <pre><code>ssh root@187.127.254.206           # your SSH login to the VPS
cd /var/www/homepage-builder-new   # the project / live webroot
claude                             # start Claude Code</code></pre>
    <p>To resume a previous session instead of starting fresh:</p>
    <pre><code>claude --resume      # pick a past session from a list
claude --continue    # jump back into the most recent one</code></pre>
    <p>The whole recovery is: <strong>SSH in → <code>cd</code> to the project → <code>claude</code>.</strong></p>

    <div class="callout">
        <p><strong>If the VPS itself reboots</strong> (not just your Mac): Apache auto-starts because it's <code>enabled</code>, so the live site comes back on its own. The only thing lost is the disposable <code>php -S</code> preview server (port 8099, used for screenshots) — it's not needed for production and is restarted on demand.</p>
    </div>
    <div class="callout warn">
        <p><strong>Your Mac-side specifics may differ.</strong> The SSH command above assumes <code>root@187.127.254.206</code>. If you log in as a different user, with an SSH key, or on a non-standard port, swap those in. If you connect via VS Code Remote-SSH or a saved terminal profile, reopen that instead.</p>
    </div>
</section>

<section id="dev-lost-mac">
    <h2>If I lose my Mac</h2>
    <p>Short version: <strong>you lose nothing, and you can get back in</strong> — as long as the recovery key and your Hostinger login are saved somewhere off the Mac. The Mac is only a terminal and a mirror; everything real lives on the VPS and GitHub.</p>

    <h3>Nothing is lost</h3>
    <ul>
        <li><strong>The live sites</strong> keep serving — they run on the VPS, untouched by anything happening to your Mac.</li>
        <li><strong>All code and data</strong> live on the VPS <em>and</em> on GitHub (<code>DoveValley/Builder</code>). The Mac repo was just a copy.</li>
        <li><strong>The backup pipeline</strong> keeps working — the VPS pushes to GitHub with the deploy key stored <em>on the VPS</em> (<code>/root/.ssh/github_deploy</code>), not on your Mac.</li>
    </ul>
    <p>So there is no data-loss problem. The only question is <strong>access</strong> — getting into the VPS from a new machine.</p>

    <h3>How you get back in (easiest first)</h3>
    <ol>
        <li><strong>Recovery key (if you saved it):</strong> on the new Mac, drop <code>sitefactory_recovery</code> from your password manager into <code>~/.ssh/</code> and connect:
            <pre><code>ssh -i ~/.ssh/sitefactory_recovery root@187.127.254.206</code></pre>
        </li>
        <li><strong>Hostinger console (the safety net):</strong> even if <em>both</em> SSH keys are gone with the Mac, log into <strong>hPanel</strong> (Hostinger account — email + password, nothing to do with your Mac), open the <strong>browser console</strong>, and add a fresh SSH key. Back in. This bypasses lost keys entirely.</li>
        <li><strong>Admin panel:</strong> never depended on your Mac — just a browser and your admin password.</li>
    </ol>

    <h3>First thing on the new machine</h3>
    <pre><code># install the recovery (or a fresh) key, then:
ssh root@187.127.254.206
cd /var/www/homepage-builder-new
claude            # back to work; git pull/commit/push as normal</code></pre>

    <div class="callout warn">
        <p><strong>The one thing that matters.</strong> Both SSH keys (<code>~/.ssh/id_ed25519</code> and <code>~/.ssh/sitefactory_recovery</code>) currently live only on the Mac — lose the Mac and they vanish together. Losing the Mac is a non-event <em>only if</em> these two things are saved off it, in a password manager that syncs to your phone/cloud:</p>
        <p>1. The <strong>recovery SSH key</strong> (<code>~/.ssh/sitefactory_recovery</code>) &nbsp;·&nbsp; 2. Your <strong>Hostinger login + 2FA recovery codes</strong>.</p>
        <p>With those two in your vault, a lost Mac costs you minutes, not data. Without them, your only way back is the Hostinger console — so the Hostinger account becomes your real lifeline. See <a href="#dev-credentials">Credentials &amp; access backup</a>.</p>
    </div>
</section>

<section id="dev-quickref">
    <h2>Quick reference</h2>
    <p>The everyday entry points and health checks in one place.</p>
    <table>
        <tr><th>What</th><th>Where / command</th></tr>
        <tr><td>Admin login (HTTPS)</td><td><code>https://187.127.254.206/admin/login.php</code></td></tr>
        <tr><td>Admin login (HTTP)</td><td><code>http://187.127.254.206/admin/login.php</code></td></tr>
        <tr><td>Your Sites list</td><td><code>/admin/sites.php</code></td></tr>
        <tr><td>SSH into the VPS</td><td><code>ssh root@187.127.254.206</code></td></tr>
        <tr><td>Project root</td><td><code>/var/www/homepage-builder-new</code></td></tr>
        <tr><td>Start Claude Code</td><td><code>claude</code> (or <code>claude --continue</code>)</td></tr>
        <tr><td>Is the site up?</td><td><code>systemctl is-active apache2</code></td></tr>
        <tr><td>Restart the web server</td><td><code>sudo systemctl restart apache2</code></td></tr>
        <tr><td>Local preview server</td><td><code>php -S localhost:8080 router.php</code></td></tr>
    </table>
    <div class="callout tip">
        <p>Reset a forgotten admin password: <code>php -r "echo password_hash('new-pass', PASSWORD_DEFAULT);"</code> and paste the hash into <code>config.php</code> as <code>ADMIN_PASSWORD_HASH</code>.</p>
    </div>
</section>

<section id="dev-credentials">
    <h2>Credentials &amp; access backup</h2>
    <p>The two things that grant access to this environment — the <strong>SSH key</strong> and the <strong>admin password</strong> — must be backed up <em>off this server</em>. Their proper home is a <strong>password manager</strong> on your own devices (1Password, Bitwarden, Apple Passwords, KeePass — any of them).</p>

    <h3>What lives where (and what can't be recovered)</h3>
    <ul>
        <li><strong>SSH private key</strong> — lives on your Mac under <code>~/.ssh/</code>. The server only holds the <em>public</em> half in <code>/root/.ssh/authorized_keys</code> (comment <code>claude-code-scottparr</code>). The private key cannot be read off the VPS.</li>
        <li><strong>Admin password</strong> — stored only as a one-way <strong>bcrypt hash</strong> in <code>config.php</code>. The plaintext is not recoverable by anyone; it can only be reset.</li>
    </ul>

    <div class="callout warn">
        <p><strong>Never store these on the server itself.</strong> A copy on the same VPS dies with the box (or leaks if it's compromised). Writing a private key or plaintext password into the webroot or committing it to Git is a security hole — don't. "Somewhere safe" means a password manager on your own devices.</p>
    </div>

    <h3>Reference note (non-secret) — save this in your vault</h3>
    <p>Connection details that are safe to store alongside the secrets:</p>
    <pre><code>SITE FACTORY — VPS ACCESS
  Host (IPv4):     187.127.254.206
  SSH user:        root
  SSH command:     ssh root@187.127.254.206
  Authorized key:  comment "claude-code-scottparr" (private key on the Mac, ~/.ssh)
  Project root:    /var/www/homepage-builder-new
  Admin (HTTPS):   https://187.127.254.206/admin/login.php   (self-signed → "proceed")
  Admin user:      admin
  Restart server:  sudo systemctl restart apache2</code></pre>

    <h3>Backup recovery SSH key</h3>
    <p>If your Mac holds the only authorized key, losing it locks you out of the VPS. The fix is a second "recovery" key kept in your password manager. Generate it <strong>on your Mac</strong> so the private half never touches the server or any transcript — then only the public half is added here:</p>
    <pre><code># On your Mac:
ssh-keygen -t ed25519 -f ~/.ssh/sitefactory_recovery -C "recovery-key"
cat ~/.ssh/sitefactory_recovery.pub        # the public line to add on the server</code></pre>
    <p>Add that public line to the server's authorized keys (append — don't overwrite):</p>
    <pre><code># On the VPS:
echo "ssh-ed25519 AAAA... recovery-key" &gt;&gt; /root/.ssh/authorized_keys</code></pre>
    <p>Store the private file <code>~/.ssh/sitefactory_recovery</code> in your password manager. Test it once (<code>ssh -i ~/.ssh/sitefactory_recovery root@187.127.254.206</code>) so you know it works before you need it.</p>

    <h3>Admin password backup</h3>
    <ul>
        <li><strong>If you know it</strong> — just save it to your password manager. Nothing to change.</li>
        <li><strong>If it's lost</strong> — reset it to a value you choose, then store that:</li>
    </ul>
    <pre><code>php -r "echo password_hash('your-new-password', PASSWORD_DEFAULT);"
# paste the resulting hash into config.php as ADMIN_PASSWORD_HASH</code></pre>
    <div class="callout tip">
        <p>Checklist for "I can always get back in": (1) SSH private key in a password manager, (2) a tested backup recovery key in the password manager, (3) the admin password in the password manager, (4) the reference note above saved next to them.</p>
    </div>
</section>

<!-- ═══════════ SERVER (VPS) ═══════════ -->
<div class="doc-group-header" id="group-dev-server">Server (VPS)</div>
<section id="dev-server-overview">
    <h2>Overview &amp; access</h2>
    <p>Site Factory runs on a single Linux VPS. The repository at <code>/var/www/homepage-builder-new</code> <em>is</em> the live document root — there is no separate "build" copy on this box. Editing files here changes the live admin immediately.</p>
    <table>
        <tr><th>Property</th><th>Value</th></tr>
        <tr><td>Public IPv4</td><td><code>187.127.254.206</code></td></tr>
        <tr><td>Document root</td><td><code>/var/www/homepage-builder-new</code></td></tr>
        <tr><td>Web server</td><td>Apache 2.4.58</td></tr>
        <tr><td>Runtime</td><td>PHP 8.3.6 (mod_php)</td></tr>
        <tr><td>Admin URL</td><td><code>http://187.127.254.206/admin/login.php</code> (also HTTPS)</td></tr>
    </table>
    <p>Access is over SSH. There is no domain name attached yet — the Apache vhost is a catch-all (<code>ServerName _</code>), so the site answers on the raw IP for any hostname.</p>
    <div class="callout warn">
        <p><strong>No domain = no Let's Encrypt.</strong> Trusted TLS requires a real domain pointed at this IP. Until then, HTTPS uses a self-signed cert (browser warning). See <a class="back-top" href="#dev-https">HTTPS &amp; self-signed cert</a>.</p>
    </div>
</section>

<section id="dev-os">
    <h2>OS — Ubuntu 24.04</h2>
    <p>The host runs <strong>Ubuntu 24.04 LTS (Noble Numbat)</strong>, kernel <code>6.8.x</code>. LTS gives security updates through 2029.</p>
    <pre><code># Confirm OS / kernel
cat /etc/os-release
uname -r

# Apply security updates
sudo apt update &amp;&amp; sudo apt upgrade</code></pre>
    <p>Stock packages from Ubuntu's repos are used throughout (Apache, PHP, Git). The one exception is Chromium, which Ubuntu ships as a <strong>snap</strong> — that has consequences for screenshots (see <a class="back-top" href="#dev-chromium">Chromium screenshots</a>).</p>
</section>

<section id="dev-docroot">
    <h2>Document root &amp; layout</h2>
    <p>Everything lives under <code>/var/www/homepage-builder-new</code>. Top-level shape:</p>
    <pre><code>/var/www/homepage-builder-new/
├── index.php, page.php, blog.php   Public entry points
├── router.php                      Routing for the PHP built-in dev server
├── config.php                      Constants, session, credentials, API key
├── .htaccess                       Rewrites + data-dir protection
├── admin/                          Admin panel (login-gated)
├── includes/                       App logic (data, blocks, template, …)
├── assets/                         CSS/JS (shared + course widgets)
├── sites/{id}/                     Per-site data + uploads (multi-site)
│   ├── data/                       site.json, courses.json, pages/, …
│   └── uploads/                    Images for that site
├── output/{id}/                    Generated static build (deploy source)
└── docs/                           Markdown guidebooks</code></pre>
</section>

<section id="dev-permissions">
    <h2>Users &amp; permissions</h2>
    <p>Apache (and therefore PHP under mod_php) runs as the <code>www-data</code> user. For the admin panel to save JSON and accept uploads, <code>www-data</code> must be able to write to the data and upload directories.</p>
    <pre><code># Make the app writable by the web server
sudo chown -R www-data:www-data /var/www/homepage-builder-new
sudo find /var/www/homepage-builder-new -type d -exec chmod 755 {} \;
sudo find /var/www/homepage-builder-new -type f -exec chmod 644 {} \;</code></pre>
    <div class="callout warn">
        <p><strong>Files written by the admin are owned by <code>www-data</code>.</strong> If you also edit files over SSH as <code>root</code> or another user, you can end up with mixed ownership. Keep writes consistent or fix with <code>chown</code> afterward.</p>
    </div>
</section>

<!-- ═══════════ WEB SERVER — APACHE ═══════════ -->
<div class="doc-group-header" id="group-dev-apache">Web Server — Apache</div>
<section id="dev-apache">
    <h2>Apache overview</h2>
    <p><strong>Apache 2.4.58</strong> serves the site directly from the document root. PHP runs in-process via <code>mod_php</code> (no PHP-FPM, no reverse proxy). Config lives under <code>/etc/apache2/</code>.</p>
    <pre><code># Service control
sudo systemctl status apache2
sudo systemctl reload apache2     # graceful, no dropped connections
sudo systemctl restart apache2    # full restart (needed for new listeners)

# Validate config before reloading
sudo apache2ctl configtest</code></pre>
    <div class="callout tip">
        <p>Always run <code>apache2ctl configtest</code> before a reload. A syntax error on restart takes the whole site down until it's fixed.</p>
    </div>
</section>

<section id="dev-vhosts">
    <h2>Virtual hosts (:80 / :443)</h2>
    <p>Two enabled vhosts, both pointing at the same document root:</p>
    <table>
        <tr><th>File</th><th>Port</th><th>Purpose</th></tr>
        <tr><td><code>sites-enabled/homepage-builder.conf</code></td><td>80</td><td>Plain HTTP</td></tr>
        <tr><td><code>sites-enabled/homepage-builder-ssl.conf</code></td><td>443</td><td>HTTPS (self-signed cert)</td></tr>
    </table>
    <p>Both use <code>ServerName _</code> (catch-all) and grant <code>AllowOverride All</code> on the document root so <code>.htaccess</code> rewrites take effect.</p>
    <pre><code># List / toggle sites
sudo a2ensite homepage-builder-ssl.conf
sudo a2dissite &lt;site&gt;.conf
ls /etc/apache2/sites-enabled/</code></pre>
    <p>The HTTP vhost is <strong>not</strong> redirected to HTTPS — both answer independently. To force HTTPS, add a <code>Redirect</code> / <code>RewriteRule</code> to the port-80 vhost.</p>
</section>

<section id="dev-https">
    <h2>HTTPS &amp; self-signed cert</h2>
    <p>HTTPS is served with a self-signed certificate generated on this box (valid 10 years). Because it isn't issued by a trusted CA, browsers show a "Not secure / proceed anyway" warning — the connection is still encrypted.</p>
    <table>
        <tr><th>File</th><th>Path</th></tr>
        <tr><td>Certificate</td><td><code>/etc/ssl/homepage/selfsigned.crt</code></td></tr>
        <tr><td>Private key</td><td><code>/etc/ssl/homepage/selfsigned.key</code></td></tr>
    </table>
    <p>The <code>ssl</code> module is enabled (<code>a2enmod ssl</code>) and the cert is referenced from the :443 vhost. To regenerate:</p>
    <pre><code>sudo openssl req -x509 -nodes -days 3650 -newkey rsa:2048 \
  -keyout /etc/ssl/homepage/selfsigned.key \
  -out /etc/ssl/homepage/selfsigned.crt \
  -subj "/CN=187.127.254.206"
sudo systemctl restart apache2</code></pre>
    <div class="callout tip">
        <p><strong>Upgrade path:</strong> once a domain points at this IP, install Certbot (<code>sudo apt install certbot python3-certbot-apache</code>) and run <code>sudo certbot --apache</code> for a free, fully-trusted Let's Encrypt cert with auto-renewal — then the browser warning goes away.</p>
    </div>
</section>

<section id="dev-rewrite">
    <h2>mod_rewrite &amp; .htaccess</h2>
    <p>Pretty URLs and security rules live in the root <code>.htaccess</code>, processed by <code>mod_rewrite</code> (enabled, with <code>AllowOverride All</code>). Highlights:</p>
    <ul>
        <li><strong>Pretty URLs</strong> — <code>/some-slug</code> → <code>page.php?slug=some-slug</code>; <code>/blog</code> and <code>/blog/{slug}</code> → <code>blog.php</code></li>
        <li><strong>sitemap.xml / robots.txt</strong> routed through <code>sitemap.php</code> / <code>robots.php</code> so shortcodes resolve</li>
        <li><strong>Data protection</strong> — direct web access to <code>sites/{id}/data/</code>, <code>meta.json</code>, <code>deploy.json</code>, and <code>deploy_manifest.json</code> is denied (<code>[F]</code>)</li>
        <li><strong>Dotfile guard</strong> — any path with a leading dot (e.g. <code>/.git</code>) is forbidden, as a safety net</li>
    </ul>
    <div class="callout warn">
        <p><code>php -S</code> (the built-in dev server) <strong>does not process <code>.htaccess</code></strong>. Pretty URLs silently fall back to the homepage locally. Test with the query-string forms (<code>page.php?slug=…</code>) — this is expected, not a bug.</p>
    </div>
</section>

<section id="dev-modules">
    <h2>Enabled modules</h2>
    <p>The modules this app depends on:</p>
    <table>
        <tr><th>Module</th><th>Why</th></tr>
        <tr><td><code>php_module</code></td><td>Runs PHP in-process (mod_php)</td></tr>
        <tr><td><code>rewrite_module</code></td><td>Pretty URLs + data-dir protection in .htaccess</td></tr>
        <tr><td><code>ssl_module</code></td><td>HTTPS on :443</td></tr>
    </table>
    <pre><code># List loaded modules
apache2ctl -M
# Enable / disable, then reload
sudo a2enmod rewrite &amp;&amp; sudo systemctl reload apache2</code></pre>
</section>

<!-- ═══════════ PHP RUNTIME ═══════════ -->
<div class="doc-group-header" id="group-dev-php">PHP Runtime</div>
<section id="dev-php">
    <h2>PHP 8.3 (mod_php)</h2>
    <p><strong>PHP 8.3.6</strong>, loaded into Apache as a shared module. There is no build step and no Composer — the app is plain PHP requiring files from <code>includes/</code>.</p>
    <pre><code>php -v                         # version
php -m                         # loaded extensions
php -i | grep -i upload        # upload limits (post_max_size, upload_max_filesize)</code></pre>
    <p>Image uploads are capped at 8&nbsp;MB in application code (<code>save_uploaded_file()</code>), so PHP's <code>upload_max_filesize</code> / <code>post_max_size</code> must be at least that. Changing PHP ini settings requires an Apache reload to take effect under mod_php.</p>
</section>

<section id="dev-localserver">
    <h2>Local dev server</h2>
    <p>For local work you can serve the app with PHP's built-in server instead of Apache. The <code>router.php</code> argument is <strong>required</strong> — it reproduces the pretty-URL routing that Apache does via <code>.htaccess</code>.</p>
    <pre><code>php -S localhost:8080 router.php</code></pre>
    <p>Then: admin at <code>http://localhost:8080/admin/login.php</code>.</p>
    <div class="callout">
        <p>Without <code>router.php</code>, every non-file URL falls back to <code>index.php</code> (HTTP 200 homepage) instead of routing. Even <em>with</em> it, behavior can differ slightly from Apache — Apache is the source of truth for production.</p>
    </div>
</section>

<!-- ═══════════ APPLICATION ═══════════ -->
<div class="doc-group-header" id="group-dev-app">Application</div>
<section id="dev-nodb">
    <h2>No-database / file storage</h2>
    <p>There is no MySQL/MariaDB on this box (the services are inactive by design). All content is flat JSON under <code>sites/{id}/data/</code>:</p>
    <ul>
        <li><code>site.json</code> — header, footer, theme, content blocks, blog posts</li>
        <li><code>courses.json</code> — course schedule</li>
        <li><code>templates.json</code>, <code>cities.json</code>, <code>pages/</code> — city-page system</li>
        <li><code>deploy.json</code>, <code>deploy_manifest.json</code> — deploy config + state</li>
    </ul>
    <p>Consequences for ops: <strong>backup = copy the folder</strong>, there are no migrations, and a bad write can corrupt a JSON file — so back up before bulk edits (see <a class="back-top" href="#dev-backups">Backups</a>).</p>
</section>

<section id="dev-repo">
    <h2>Repo layout &amp; includes/</h2>
    <p><code>includes/functions.php</code> is a loader only; logic is split into focused files. Knowing where things live saves grep time:</p>
    <table>
        <tr><th>File</th><th>Responsibility</th></tr>
        <tr><td><code>includes/data.php</code></td><td>load_data(), save_data(), default_data()</td></tr>
        <tr><td><code>includes/blocks.php</code></td><td>render_content_block(), allowed_block_types()</td></tr>
        <tr><td><code>includes/editor.php</code></td><td>Admin block-editor UI</td></tr>
        <tr><td><code>includes/site-template.php</code></td><td>Shared public HTML template</td></tr>
        <tr><td><code>includes/helpers.php</code></td><td>sanitize_url(), save_uploaded_file(), slugify()</td></tr>
        <tr><td><code>admin/save.php</code></td><td>POST handler for all content saves</td></tr>
    </table>
    <p>See the Admin tab's <em>Technical → Architecture</em> section for the full file-by-file breakdown.</p>
</section>

<section id="dev-config">
    <h2>config.php &amp; constants</h2>
    <p><code>config.php</code> is the single source of environment config. It starts the session, defines path constants, holds the admin credentials, and loads the Anthropic API key. Path constants switch between single-site and the active multi-site folder automatically:</p>
    <table>
        <tr><th>Constant</th><th>Meaning</th></tr>
        <tr><td><code>BASE_DIR</code></td><td>Project root (<code>__DIR__</code>)</td></tr>
        <tr><td><code>ACTIVE_SITE_ID</code> / <code>ACTIVE_SITE_DIR</code></td><td>Currently selected site</td></tr>
        <tr><td><code>DATA_FILE</code> / <code>COURSES_FILE</code></td><td>Active site's JSON paths</td></tr>
        <tr><td><code>UPLOAD_DIR</code> / <code>UPLOAD_URL</code></td><td>Active site's uploads</td></tr>
        <tr><td><code>ADMIN_USERNAME</code> / <code>ADMIN_PASSWORD_HASH</code></td><td>Login credentials</td></tr>
        <tr><td><code>CONTACT_EMAIL</code></td><td>Recipient for the contact form</td></tr>
        <tr><td><code>ANTHROPIC_API_KEY</code></td><td>Key for AI generation (loaded into the constant)</td></tr>
    </table>
    <div class="callout warn">
        <p><code>config.php</code> contains secrets (password hash, API key). It must never be world-readable or committed to a public remote.</p>
    </div>
</section>

<section id="dev-auth">
    <h2>Sessions &amp; admin auth</h2>
    <p>Auth is session-based. <code>config.php</code> calls <code>session_start()</code>; every admin page checks <code>$_SESSION['admin_logged_in']</code> and redirects to <code>login.php</code> if unset. The password is verified with <code>password_verify()</code> against the bcrypt hash in <code>config.php</code>.</p>
    <p>All admin POST endpoints additionally require a CSRF token (<code>$_SESSION['csrf_token']</code>, checked with <code>hash_equals()</code>). The SSE deploy/generate endpoints pass that token in the query string since they use GET.</p>
    <pre><code># Generate a replacement password hash, then paste into config.php
php -r "echo password_hash('your-new-password', PASSWORD_DEFAULT);"</code></pre>
</section>

<!-- ═══════════ TOOLING ═══════════ -->
<div class="doc-group-header" id="group-dev-tooling">Tooling</div>
<section id="dev-git">
    <h2>Git (repo = live webroot)</h2>
    <p>The document root is a Git repository on the <code>main</code> branch. Because it's also the live site, committed and working-tree changes are already live — Git here is history/rollback, not a deploy mechanism for this admin box.</p>
    <pre><code>git status
git log --oneline -10
git add &lt;file&gt; &amp;&amp; git commit -m "message"
git checkout -- &lt;file&gt;     # discard a bad working change</code></pre>
    <div class="callout warn">
        <p><strong>Never expose <code>.git/</code> over the web.</strong> The <code>.htaccess</code> dotfile guard blocks it as a safety net, but the generated static build (what actually gets deployed to clients) should never include <code>.git/</code> at all. See <a class="back-top" href="#dev-sec-git">Never deploy .git</a>.</p>
    </div>
</section>

<section id="dev-chromium">
    <h2>Chromium screenshots</h2>
    <p>Chromium (snap build, v149) is used for headless screenshots — to visually verify a site without a desktop browser. Run it headless with the sandbox disabled (required as root in this environment):</p>
    <pre><code>chromium --headless --no-sandbox --disable-gpu --hide-scrollbars \
  --screenshot=shot.png --window-size=1400,3500 \
  "http://localhost:8099/some-page"</code></pre>
    <div class="callout warn">
        <p><strong>Snap confinement gotcha:</strong> snap Chromium cannot write into <code>/tmp</code> — it reports success but no file appears. Write the screenshot under a home-directory path (e.g. run from <code>~</code> and use a relative filename); the file lands in <code>/root/</code>. Then open it to review.</p>
    </div>
</section>

<section id="dev-node">
    <h2>Node 18</h2>
    <p><code>node</code> / <code>npx</code> are available (v18.19.1). The app itself has no Node build step — Node is here for incidental tooling only.</p>
    <div class="callout">
        <p>Node 18 is older than what some current CLI tools expect (e.g. recent Puppeteer wants Node ≥ 22 and will print an <code>EBADENGINE</code> warning). For screenshots, use the system Chromium directly (above) rather than a Node browser wrapper.</p>
    </div>
</section>

<section id="dev-preview">
    <h2>The preview.php pattern</h2>
    <p>To screenshot a specific multi-site without going through the admin login/session flow, drop a tiny shim at the project root that forces the active site, then render <code>index.php</code>:</p>
    <pre><code>&lt;?php
session_start();
$_SESSION['active_site'] = 'site_id_here';
session_write_close();          // commit before index.php reads the session
require __DIR__ . '/index.php';</code></pre>
    <p>Hit it directly (<code>http://localhost:PORT/siteidpreview.php</code>) with headless Chromium. The <code>session_write_close()</code> before <code>require</code> is essential — without it the session isn't committed before <code>index.php</code> reads it.</p>
    <div class="callout tip">
        <p>These are throwaway helpers. Delete them when done (or keep them — several are already committed at the project root). They only ever serve the already-public homepage, so they expose nothing new.</p>
    </div>
</section>

<!-- ═══════════ DEPLOY ═══════════ -->
<div class="doc-group-header" id="group-dev-deploy">Deploy</div>
<section id="dev-static">
    <h2>Static generation</h2>
    <p>Deploy is a two-step pipeline: <strong>generate a static build</strong>, then <strong>FTP it to the client host</strong>. This admin box is the factory; client sites are served as plain static HTML elsewhere.</p>
    <p><code>admin/generate_static.php</code> is an SSE endpoint that renders every page through <code>site-template.php</code> (via <code>ob_start</code>/<code>ob_get_clean</code>), copies <code>assets/</code> and <code>uploads/</code>, and writes <code>sitemap.xml</code>, <code>robots.txt</code>, and a production <code>.htaccess</code> into <code>output/{site_id}/</code>.</p>
    <ul>
        <li>Auth: requires <code>admin_logged_in</code> + a valid CSRF token (passed as <code>?token=</code> because SSE uses GET)</li>
        <li>Requires an active site (<code>ACTIVE_SITE_ID</code>)</li>
        <li>Output goes to <code>output/{site_id}/</code> — this is the deploy source, never served live from this box</li>
    </ul>
    <p>Driven from the admin <em>Deploy</em> tab; progress streams live to the browser.</p>
</section>

<section id="dev-ftp">
    <h2>FTP deploy</h2>
    <p><code>admin/deploy_ftp.php</code> (also SSE) reads <code>output/{site_id}/</code>, compares each file against <code>deploy_manifest.json</code>, and uploads <strong>only new or changed files</strong>, then updates the manifest. FTP credentials live in the per-site <code>deploy.json</code> (saved via <code>admin/deploy_save.php</code>).</p>
    <table>
        <tr><th>File</th><th>Role</th></tr>
        <tr><td><code>sites/{id}/deploy.json</code></td><td>FTP host / user / password / remote path</td></tr>
        <tr><td><code>sites/{id}/deploy_manifest.json</code></td><td>Hashes of last-uploaded files (incremental sync)</td></tr>
    </table>
    <div class="callout warn">
        <p>Both <code>deploy.json</code> (contains FTP credentials) and <code>deploy_manifest.json</code> are blocked from web access by <code>.htaccess</code>. Keep that rule intact.</p>
    </div>
</section>

<section id="dev-audit">
    <h2>Deploy audit &amp; tracking</h2>
    <p>Supporting endpoints around the deploy flow:</p>
    <table>
        <tr><th>Endpoint</th><th>Purpose</th></tr>
        <tr><td><code>admin/deploy_save.php</code></td><td>Save FTP settings into <code>deploy.json</code></td></tr>
        <tr><td><code>admin/deploy_audit.php</code></td><td>Compare local build vs. remote; report drift (POST + CSRF)</td></tr>
        <tr><td><code>admin/deploy_delete_orphaned.php</code></td><td>Remove remote files no longer in the manifest</td></tr>
        <tr><td><code>admin/deploy_force_delete.php</code></td><td>Force-remove remote files (cleanup)</td></tr>
    </table>
    <p>All are login-gated and CSRF-protected. The manifest is what makes deploys incremental — delete it to force a full re-upload on the next run.</p>
</section>

<!-- ═══════════ OPERATIONS ═══════════ -->
<div class="doc-group-header" id="group-dev-ops">Operations</div>
<section id="dev-services">
    <h2>Services (systemctl)</h2>
    <p>Only <strong>Apache</strong> needs to be running for the factory to work. Database services are intentionally inactive (no DB).</p>
    <pre><code>systemctl is-active apache2        # should be: active
systemctl is-enabled apache2       # start on boot?
sudo systemctl restart apache2

# Quick health check
curl -sI http://localhost/admin/login.php | head -1</code></pre>
</section>

<section id="dev-cron">
    <h2>Cron jobs</h2>
    <p>No application-specific cron jobs are configured — content generation and deploys are triggered manually from the admin. The entries under <code>/etc/cron.d/</code> (<code>e2scrub_all</code>, <code>php</code>, <code>sysstat</code>) are stock OS maintenance, not Site Factory.</p>
    <pre><code>crontab -l                 # user crontab (currently none for the app)
ls /etc/cron.d/            # system cron jobs</code></pre>
    <div class="callout tip">
        <p>If you later automate backups or Let's Encrypt renewal, this is where those jobs would go.</p>
    </div>
</section>

<section id="dev-firewall">
    <h2>Firewall (ufw)</h2>
    <p><strong>ufw is currently inactive</strong> — nothing is filtered at the host firewall. If you enable it, allow SSH first or you will lock yourself out.</p>
    <pre><code>sudo ufw status
sudo ufw allow OpenSSH        # do this BEFORE enabling
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw enable</code></pre>
    <div class="callout warn">
        <p>Enabling ufw without an SSH allow rule will drop your current connection on the next login. Always allow OpenSSH first.</p>
    </div>
</section>

<section id="dev-logs">
    <h2>Logs</h2>
    <p>Apache and PHP errors surface in the Apache logs (mod_php writes PHP errors there):</p>
    <pre><code>sudo tail -f /var/log/apache2/error.log    # PHP errors + Apache errors
sudo tail -f /var/log/apache2/access.log   # requests

# Filter for a specific path
sudo grep "admin/save.php" /var/log/apache2/access.log</code></pre>
    <p>The PHP built-in dev server logs to its own stdout/stderr instead — redirect it to a file when running in the background.</p>
</section>

<section id="dev-backups">
    <h2>Backups</h2>
    <p>Because content is flat files, a backup is just a copy of the data. The cheapest safety net before any bulk edit is to snapshot the per-site data folder.</p>
    <pre><code># Snapshot one site's data + uploads
tar czf ~/backup-$(date +%F).tgz \
  /var/www/homepage-builder-new/sites/SITE_ID

# Or rely on Git for the tracked files
git add -A &amp;&amp; git commit -m "snapshot before bulk edit"</code></pre>
    <div class="callout tip">
        <p>Git covers tracked source, but uploaded images and generated <code>output/</code> may be large/ignored — include them in the <code>tar</code> snapshot when they matter.</p>
    </div>
</section>

<!-- ═══════════ SECURITY ═══════════ -->
<div class="doc-group-header" id="group-dev-security">Security</div>
<section id="dev-sec-https">
    <h2>HTTPS / cert notes</h2>
    <p>The self-signed cert encrypts traffic but isn't trusted by browsers, so logging in shows a warning. For anything beyond testing, move to a real domain + Let's Encrypt so the admin login isn't sent over a flagged connection.</p>
    <ul>
        <li>Self-signed = encrypted but unverified (browser warning is expected)</li>
        <li>Plain HTTP (:80) sends the admin password in clear text — prefer HTTPS</li>
        <li>Trusted cert requires a domain → see <a class="back-top" href="#dev-https">HTTPS &amp; self-signed cert</a> for the Certbot path</li>
    </ul>
</section>

<section id="dev-sec-password">
    <h2>Admin password</h2>
    <p>The login password is stored only as a bcrypt hash in <code>config.php</code> — it can't be recovered, only reset. The shipped default (<code>admin123</code>) must be changed before any site goes live.</p>
    <pre><code>php -r "echo password_hash('a-strong-password', PASSWORD_DEFAULT);"
# paste the result into config.php as ADMIN_PASSWORD_HASH</code></pre>
</section>

<section id="dev-sec-git">
    <h2>Never deploy .git</h2>
    <p>The deploy pipeline ships <code>output/{site_id}/</code>, not this repo — so client hosts never receive <code>.git/</code>. Keep it that way: an exposed <code>.git/</code> leaks full history, including old credential hashes.</p>
    <ul>
        <li>This box's <code>.htaccess</code> blocks dotfiles as a safety net — don't rely on it as the only defense</li>
        <li>Never copy the repo wholesale (with <code>.git/</code>) onto a public host</li>
        <li>The static build is the deploy artifact; verify it has no dotfolders before upload</li>
    </ul>
</section>

<section id="dev-sec-uploads">
    <h2>Upload sanitization</h2>
    <p>Image uploads are validated by <code>save_uploaded_file()</code>: MIME must be jpeg/png/gif/webp, max 8&nbsp;MB, written to <code>uploads/</code> with a randomized filename. SVGs are run through <code>sanitize_svg()</code> first — it strips <code>&lt;script&gt;</code>, <code>on*</code> handlers, and <code>javascript:</code> URIs before saving.</p>
    <p>All user-entered URLs pass through <code>sanitize_url()</code>, which only permits <code>http(s):</code>, <code>tel:</code>, <code>mailto:</code>, and relative links — blocking <code>javascript:</code> and similar. Any new save handler that stores a URL must use it.</p>
    <div class="callout">
        <p>These are application-level controls. Combined with the <code>.htaccess</code> data-dir denials and CSRF on every POST, they're the core of the app's defense — keep them in place when adding endpoints.</p>
    </div>
</section>

</div><!-- /#doc-devenv -->

<div id="doc-multisite" hidden>
<p class="page-intro">The <strong>Multisite Generator</strong> turns one master site into 100+ separate, fully independent websites — one per city — each with its own domain, business identity, AI-written local content, and FTP host. This is different from the in-site City Pages system (which makes many <em>pages</em> inside <em>one</em> site); the Multisite Generator operates one level up and produces whole <em>sites</em>.</p>

<!-- ═══════════ OVERVIEW ═══════════ -->
<div class="doc-group-header" id="ms-overview">Overview</div>
<section id="ms-what">
    <h2>What it is</h2>
    <p>A pipeline that takes a single <strong>master site</strong> as a template and a <strong>params table</strong> (one row per target site) and produces many separate, deployed websites — same business and topic, one per city. Each output site is a genuinely distinct entity: its own domain, business name, phone, address, geo coordinates, AI-written city copy, analytics tag, and FTP host.</p>
    <p>It reuses the factory's existing single-site machinery (clone, static build, FTP deploy, the <code>generate.py</code> AI engine) — it does not reinvent them. The generator's job is to drive those primitives across a table of sites, safely and repeatably.</p>
    <div class="callout tip"><strong>One command, many live sites.</strong> Prepare a CSV, then run one campaign command; the generator builds and deploys every site, writes AI copy once and caches it, and produces a run log of exactly what happened, what it cost, and what failed.</div>
</section>

<section id="ms-howitworks">
    <h2>How it works</h2>
    <p>The high-level flow:</p>
    <pre><code>Master site (template, authored once with {shortcodes} + ai_block placeholders)
        +
Params table  (CSV — one row per site: domain, business, phone, city, geo, FTP creds)
        ↓
[ for each row ]  clone → inject identity → differentiate → AI-generate → build → deploy
        ↓
100+ live, independent single-city sites</code></pre>
    <p>Per row, the pipeline:</p>
    <ol>
        <li><strong>Clone</strong> a cheap working copy from a one-time snapshot of the master.</li>
        <li><strong>Inject identity</strong> — write the row's business/phone/city/etc. into <code>site_vars</code>.</li>
        <li><strong>Differentiate</strong> — rewrite schema/URLs to this site, inject a LocalBusiness JSON-LD with geo, isolate analytics.</li>
        <li><strong>AI-generate</strong> the city-specific copy (or reuse it from the cache — free).</li>
        <li><strong>Build</strong> the whole site to static HTML.</li>
        <li><strong>Deploy</strong> over FTP (only changed files), then delete the temp copy.</li>
    </ol>
</section>

<section id="ms-vs-insite">
    <h2>Multisite vs. the in-site City Pages</h2>
    <table>
        <tr><th></th><th>City Pages (in-site)</th><th>Multisite Generator</th></tr>
        <tr><td>Produces</td><td>Many <em>pages</em> in one site</td><td>Many separate <em>sites</em></td></tr>
        <tr><td>Domains</td><td>One shared domain</td><td>One domain per site</td></tr>
        <tr><td>URLs</td><td><code>/pmp-training-dallas</code></td><td><code>pmtraining-dallas.com</code></td></tr>
        <tr><td>Lives in</td><td><code>data/pages/*.json</code></td><td>Ephemeral — nothing stored per site</td></tr>
    </table>
    <p>A multisite output is single-city, so the clone step <strong>drops</strong> the master's in-site <code>data/pages/</code> (the many-cities-in-one-site files) and builds only the homepage + core/landing pages from <code>site.json</code>.</p>
</section>

<!-- ═══════════ ARCHITECTURE ═══════════ -->
<div class="doc-group-header" id="ms-arch">Architecture</div>
<section id="ms-process-model">
    <h2>One fresh process per site</h2>
    <p><code>config.php</code> defines every path (<code>DATA_FILE</code>, <code>UPLOAD_DIR</code>, <code>PAGES_DIR</code>, …) as immutable PHP constants, computed once from the active site. A running PHP process therefore can build only <strong>one</strong> site — the paths cannot be re-pointed mid-run.</p>
    <p>So the generator does not loop in one process. It spawns a <strong>fresh subprocess per site</strong>: the worker's <code>config.php</code>, in CLI mode, reads the site's location from an environment variable (<code>MULTISITE_SITE_BASE</code>) instead of the session. Each site gets a clean process, so the frozen-constant problem disappears and the entire existing render/build code is reused untouched.</p>
    <div class="callout"><strong>Why this is the simpler choice.</strong> The alternative — rewriting the whole render layer to pass paths as parameters — is a large, risky change to code that already works. Process-per-site reuses everything, isolates failures (one bad site can't crash the batch), and makes parallelism trivial.</div>
</section>

<section id="ms-two-level-clone">
    <h2>Two-level cloning</h2>
    <p>The original master is <strong>never touched</strong> during a run. Instead:</p>
    <ul>
        <li><strong>Once per run</strong> — the master is copied into a frozen <em>snapshot</em>.</li>
        <li><strong>Per row</strong> — a cheap throwaway <em>working dir</em> is cloned from the snapshot (data copied, uploads symlinked), built, deployed, then deleted.</li>
    </ul>
    <p>This gives three guarantees at once:</p>
    <ul>
        <li><strong>The original is always intact</strong> — read once, then hands-off. Worst case on failure: delete temp dirs and restart.</li>
        <li><strong>Consistency</strong> — every row builds from the same frozen snapshot, so editing the master mid-run can't make sites inconsistent.</li>
        <li><strong>No cross-site bleed</strong> — a fresh working dir per site is a clean room; parallel workers never share mutable state.</li>
    </ul>
</section>

<section id="ms-primitives">
    <h2>Reused primitives</h2>
    <p>Every per-site operation already existed in the factory; the generator extracted their cores so they run against a path instead of the session's active site:</p>
    <table>
        <tr><th>Core</th><th>Lives in</th><th>Does</th></tr>
        <tr><td><code>snapshot_master()</code> / <code>clone_to_working_dir()</code></td><td><code>includes/multisite/clone.php</code></td><td>Freeze master, clone cheap per-row copy</td></tr>
        <tr><td><code>build_static_site()</code></td><td><code>includes/static_build.php</code></td><td>Render every page → static HTML + sitemap/robots/.htaccess; trailing-slash internal links + canonicals</td></tr>
        <tr><td><code>deploy_site()</code></td><td><code>includes/multisite/deploy.php</code></td><td>Upload changed files over FTP (incremental)</td></tr>
    </table>
    <p>The admin's "Generate Static" and "Deploy" buttons are now thin shells that call these same cores — so the interactive path and the batch path share one implementation.</p>
</section>

<section id="ms-footprint">
    <h2>What persists on disk</h2>
    <p>The 100+ generated sites are <strong>never stored</strong> as site trees — that would be pure bloat. Only three things persist, all under the master that owns the campaign:</p>
    <table>
        <tr><th>Artifact</th><th>Location</th><th>Role</th></tr>
        <tr><td>Master template</td><td><code>sites/{master}/</code></td><td>The one editable source of truth</td></tr>
        <tr><td>Params table</td><td><code>sites/{master}/multisite/params.csv</code></td><td>The input (holds FTP creds)</td></tr>
        <tr><td>AI copy cache</td><td><code>sites/{master}/multisite/cache/{domain}.json</code></td><td>Frozen AI copy for free rebuilds</td></tr>
        <tr><td>Run logs / manifests</td><td><code>sites/{master}/multisite/runs/</code>, <code>/manifests/</code></td><td>Per-run status; incremental-deploy state</td></tr>
    </table>
    <div class="callout warn"><strong>The whole <code>sites/*/multisite/</code> directory is gitignored</strong> — it holds FTP credentials. Everything else (built HTML, output-site JSON) is ephemeral or lives only on the FTP target.</div>
</section>

<!-- ═══════════ PARAMS ═══════════ -->
<div class="doc-group-header" id="ms-params">The Params Table</div>
<section id="ms-columns">
    <h2>CSV columns</h2>
    <p>One row per target site. Purely factual identity data — no content. Prepared in a spreadsheet and uploaded as CSV. <code>master_id</code> is <em>not</em> a column — it's the campaign context, passed on the command line.</p>
    <table>
        <tr><th>Column</th><th>Required?</th><th>Used for</th></tr>
        <tr><td><code>domain</code></td><td>Yes</td><td>Site identity, canonical URLs, cache key</td></tr>
        <tr><td><code>business</code></td><td>Yes</td><td>Business name everywhere (<code>{business}</code>)</td></tr>
        <tr><td><code>phone</code>, <code>tel</code>, <code>email</code></td><td>Recommended</td><td>Display phone, <code>tel:</code> link, contact</td></tr>
        <tr><td><code>address</code>, <code>city</code>, <code>state</code>, <code>SS</code>, <code>zip</code></td><td>Recommended</td><td>NAP + AI context + schema</td></tr>
        <tr><td><code>lat</code>, <code>lng</code></td><td>Optional</td><td>Geo coordinates → LocalBusiness schema</td></tr>
        <tr><td><code>landing_cities</code></td><td>Optional</td><td>Extra service landing pages for this deploy — a <code>;</code>-separated list of "City, ST"</td></tr>
        <tr><td><code>rating</code>, <code>review_count</code></td><td>Optional (paired)</td><td>Real AggregateRating in schema — never invented</td></tr>
        <tr><td><code>logo</code>, <code>analytics_id</code></td><td>Optional</td><td>Per-site logo; per-site analytics (never shared)</td></tr>
        <tr><td><code>ftp_host</code>, <code>ftp_user</code>, <code>ftp_pass</code></td><td>For deploy</td><td>Deploy target + auth</td></tr>
        <tr><td><code>ftp_port</code>, <code>ftp_path</code>, <code>ftp_passive</code></td><td>Optional</td><td>Deploy target details</td></tr>
    </table>
</section>

<section id="ms-validation">
    <h2>Validation &amp; pre-flight</h2>
    <p>The intake step parses and validates the CSV before anything is built:</p>
    <ul>
        <li><strong>Errors</strong> (row skipped): missing <code>domain</code>/<code>business</code>, invalid domain format, duplicate domain, partial FTP credentials, non-numeric geo.</li>
        <li><strong>Warnings</strong> (row still usable): no FTP creds (builds but won't deploy), missing recommended fields.</li>
        <li><strong>Unknown columns</strong> are reported so typos surface.</li>
    </ul>
    <p><strong>FTP pre-flight</strong> optionally connects + logs in to each row's host (no upload) so bad credentials are caught before any build begins.</p>
</section>

<section id="ms-rating">
    <h2>Ratings &amp; reviews</h2>
    <p>Ratings must be <strong>real</strong> and supplied per site — the master's rating is never carried over (that would invent reviews across every site). Provide <code>rating</code> (0–5) and <code>review_count</code> (positive integer) <em>together</em>; a site with both gets a real <code>AggregateRating</code> in its LocalBusiness schema, and a site with neither gets none.</p>
    <div class="callout warn">Providing one without the other is a validation error. Never fabricate ratings, pass rates, or student counts — use only real numbers.</div>
</section>

<!-- ═══════════ AI CONTENT ═══════════ -->
<div class="doc-group-header" id="ms-ai">AI Content</div>
<section id="ms-niche">
    <h2>Niche Brief &amp; archetypes</h2>
    <p>Each master site is <strong>one niche</strong> (pest control, PM training, lawyers, …). The AI copy for that niche is defined in two layers:</p>
    <ul>
        <li><strong>Shared archetypes</strong> (<code>multisite/ai/archetypes.json</code>) — a seed-once, read-only library of block <em>shapes</em> (e.g. <code>city_market_intro</code>, <code>hero_subtext</code>, <code>faq_additions</code>), each with a prompt skeleton, a render mode (<code>standalone</code> vs <code>inject</code>), and shared accuracy guardrails. Not niche-specific.</li>
        <li><strong>The Niche Brief</strong> (<code>sites/{master}/multisite/niche_brief.json</code>) — this master's vocabulary: <code>service_noun</code>, <code>business_descriptor</code>, <code>customer_noun</code>, <code>local_angle</code>, <code>offerings</code>, <code>tone</code>, niche <code>guardrails</code>, and which archetypes are enabled. Edited in the admin <strong>Niche Brief</strong> tab.</li>
    </ul>
    <p><strong>Compiling</strong> merges the two — filling each enabled archetype's <code>[[shared.*]]</code> and <code>[[brief.*]]</code> placeholders — and overwrites the master's <code>data/ai_block_types.json</code> (the prompt registry <code>generate.py</code> reads). Run it from the tab (<em>Save &amp; Compile</em>) or the CLI:</p>
    <pre><code>php multisite/ai/compile.php --master=&lt;master_id&gt;</code></pre>
    <p>Archetypes flagged <code>requires_research</code> are skipped unless the brief has <strong>Uses research fields</strong> on (for data-rich niches whose <code>cities.json</code> carries industries/employers/salary). Runtime tokens like <code>{business}</code>/<code>{city}</code> are left intact for <code>generate.py</code> to resolve per city.</p>
</section>

<section id="ms-aiblocks">
    <h2>AI blocks &amp; the engine</h2>
    <p>The master's pages carry <code>ai_block</code> placeholders wired to the prompt registry (<code>ai_block_types.json</code>). During a build, the Python engine <code>generate.py</code> (the same one the admin's AI Generation uses) fills them with city-specific copy. Two patterns:</p>
    <ul>
        <li><strong>Standalone</strong> (e.g. <code>city_market_intro</code>) — a whole <code>ai_block</code> becomes a section of copy.</li>
        <li><strong>Enrich</strong> (e.g. <code>hero_subtext</code>, <code>faq_additions</code>) — <code>ai_type_id</code> on a real block fills one field in place (the hero subtext, or appends FAQ items).</li>
    </ul>
    <p>The worker runs <code>generate.py --site-dir &lt;working dir&gt; --all</code> for the site's one city. An unfilled placeholder renders nothing, so the master stays a clean, working site.</p>
</section>

<section id="ms-cache">
    <h2>The content cache</h2>
    <p>AI copy is generated <strong>once per site</strong> and frozen in <code>multisite/cache/{domain}.json</code>. On every rebuild it is re-injected and reused — <strong>zero API calls</strong>, identical output (SEO-stable copy, free redeploys). Each cache entry is keyed by a stable block <code>id</code> and stamped with a <strong>prompt hash</strong>.</p>
    <p>The cache is self-healing:</p>
    <ul>
        <li>Missing entry → generate it.</li>
        <li>Prompt changed (hash mismatch) → regenerate just that block.</li>
        <li>Block removed → orphaned entry ignored.</li>
    </ul>
    <p>A first (cold) build of ~4–8 blocks costs roughly <strong>$0.02–0.05</strong>; every rebuild after is free.</p>
</section>

<section id="ms-editing">
    <h2>Editing the master safely</h2>
    <p>The master is the only place anything is ever edited. Two rules keep edits safe:</p>
    <ul>
        <li><strong>Static content propagates automatically.</strong> The cache stores only AI-written words — so editing any static block (pricing, headings, images) flows to every site on the next rebuild, with nothing to invalidate.</li>
        <li><strong>AI-block edits self-heal.</strong> Reword a prompt → only that block regenerates on the next run; add/remove/reorder blocks → copy follows the right block by its stable <code>id</code>. No per-site editing, no manual cache surgery, no stale copy.</li>
    </ul>
    <div class="callout tip">Edit the master → rebuild → redeploy. There are no per-site overrides and no per-site stored content by design.</div>
</section>

<section id="ms-landing">
    <h2>Per-deploy landing pages</h2>
    <p>Besides its single home/core city, a deploy can get extra service <strong>landing pages</strong> — one per nearby city — via the optional <code>landing_cities</code> column. Its value is a <code>;</code>-separated list of <code>City, ST</code> entries:</p>
    <pre><code>landing_cities = "Katy, TX; Fulshear, TX; Richmond, TX"</code></pre>
    <p>During the build, <code>ms_parse_landing_cities()</code> turns that cell into <code>cities.json</code> rows and <code>multisite/generate_landing.php</code> renders a city-targeted service landing page for each, from the master's reusable landing template. Their AI copy is cached per-domain exactly like the home page, so rebuilds stay free. A blank cell = no landing pages (just home + core).</p>
    <div class="callout tip">Landing cities are per-deploy — different sites in one campaign can target different surrounding towns. This is distinct from the master's own in-site <code>data/pages/</code>, which the clone drops.</div>
</section>

<!-- ═══════════ SEO ═══════════ -->
<div class="doc-group-header" id="ms-seo">Differentiation &amp; SEO</div>
<section id="ms-differentiation">
    <h2>Per-site differentiation</h2>
    <p>Because a clone would otherwise carry the master's identity into every site, the differentiation step rewrites each site to be a distinct entity:</p>
    <ul>
        <li><strong>Identity rewrite</strong> — the master's business name, domain/URL, phone, tel and email are replaced with this site's everywhere, including inside the JSON-LD schema. The replacement is word-boundary anchored so it never corrupts substrings. (The master's body copy also uses <code>{business}</code>/<code>{website}</code> shortcodes so most of this resolves automatically.)</li>
        <li><strong>LocalBusiness JSON-LD</strong> — a real <code>LocalBusiness</code> node with <code>PostalAddress</code> + <code>GeoCoordinates</code> is injected on the homepage (the strongest "distinct entity" signal) whenever geo/address/rating is present.</li>
        <li><strong>Fabricated ratings stripped</strong> — the master's carried-over <code>aggregateRating</code> is removed; a real one is emitted only if the row supplies it.</li>
        <li><strong>Analytics isolation</strong> — each site gets its own analytics tag from <code>analytics_id</code>, or none. A shared tag is never used.</li>
        <li><strong>Self-canonical</strong> — canonical URLs point at the site's own domain.</li>
    </ul>
</section>

<section id="ms-uniqueness">
    <h2>Uniqueness tiers</h2>
    <p>Generating many same-topic city sites is the pattern Google's <em>doorway pages</em> and <em>scaled content abuse</em> policies target. Cosmetic differences don't satisfy them — Google evaluates substance. Effort should follow impact:</p>
    <table>
        <tr><th>Tier</th><th>Signal</th><th>Impact</th></tr>
        <tr><td>1</td><td>Substantively different content per city (real local employers, industries, pricing); rotated block order</td><td>Highest</td></tr>
        <tr><td>2</td><td>LocalBusiness JSON-LD w/ real NAP + geo; self-canonical; distinct domains</td><td>High</td></tr>
        <tr><td>3</td><td>Per-site logo, images, theme colors</td><td>Low (most tempting, least useful)</td></tr>
        <tr><td>4</td><td>No shared analytics ID; hosting/IP diversity; WHOIS privacy; no generator fingerprint</td><td>Cheap insurance</td></tr>
    </table>
    <div class="callout warn">The defensible path is genuine local presence per city (real address, phone, ideally a Google Business Profile). Build to maximize real distinctness regardless — it's what protects against penalties and actually serves users.</div>
</section>

<!-- ═══════════ ADMIN UI ═══════════ -->
<div class="doc-group-header" id="ms-admin">Running from the Admin</div>
<section id="ms-admin-multisite">
    <h2>The Multisite tab</h2>
    <p>The whole campaign runs from the admin <strong>Multisite</strong> tab (the active site is the campaign master) — no shell needed. It wraps the same cores documented under Command Line below.</p>
    <ol>
        <li><strong>Upload params</strong> — download the sample CSV, edit it, and upload. The table is validated inline (per-row ok / warn / error, plus an unknown-column report) and stored only when every row is error-free; rows with warnings are kept.</li>
        <li><strong>Pre-flight FTP</strong> — a live, streamed per-row connect + login check, so bad credentials surface before any build begins.</li>
        <li><strong>Run campaign</strong> — set concurrency, limit and retries, optionally toggle <em>No AI</em> / <em>Force</em>, then Run. The run detaches into the background and the page polls a live progress bar (rows done, files uploaded, running cost).</li>
        <li><strong>Recent runs</strong> — a history of past runs with result + cost, each with a <strong>retry failed</strong> button that re-runs only that run's failed rows.</li>
    </ol>
    <p>Backed by <code>admin/multisite_api.php</code> (upload / status / run / run_status / list_runs / retry_failed / sample_csv), <code>admin/multisite_preflight.php</code> (SSE pre-flight), and CSRF-protected save handlers. The <strong>Niche Brief</strong> tab (see AI Content) authors the master's AI vocabulary.</p>
    <div class="callout tip">Start with a small <em>limit</em> (e.g. 2) and review the first sites before scaling to the full table.</div>
</section>

<!-- ═══════════ RUNNING (CLI) ═══════════ -->
<div class="doc-group-header" id="ms-run">Command Line</div>
<section id="ms-cli-intake">
    <h2>1 · Prepare &amp; validate the params</h2>
    <p>Prepare the CSV in a spreadsheet, then validate + store it (optionally pre-flighting FTP):</p>
    <pre><code>php multisite/params_check.php &lt;master_id&gt; path/to/params.csv --preflight</code></pre>
    <p>It prints a per-row report (ok / warn / error) and, if every row is error-free, stores the CSV to <code>sites/{master}/multisite/params.csv</code>. Add <code>--dry-run</code> to validate without storing.</p>
</section>

<section id="ms-cli-oneshot">
    <h2>2 · Build one site (test a row)</h2>
    <p>Before running the whole table, build a single site end-to-end from a one-row JSON file:</p>
    <pre><code>php multisite/build_one.php row.json --keep</code></pre>
    <p><code>--keep</code> leaves the temp working + output dirs so you can inspect them. This is the self-contained per-row unit (<code>process_row</code>) the campaign runner calls for every row.</p>
</section>

<section id="ms-cli-campaign">
    <h2>3 · Run the campaign</h2>
    <p>Run the whole stored params table:</p>
    <pre><code>php multisite/run_campaign.php &lt;master_id&gt; --jobs=4 --retries=1</code></pre>
    <p>It validates the table, pre-flights FTP, snapshots the master once, then builds + deploys every row — up to <code>--jobs</code> at a time — retrying failures, and writes a run log. It exits 0 only if every row succeeded.</p>
    <div class="callout tip">Do a small run first: <code>--limit=2</code> or <code>--only=example.com</code>, and review the output for the first few sites before scaling up.</div>
</section>

<section id="ms-flags">
    <h2>Options &amp; flags</h2>
    <table>
        <tr><th>Flag</th><th>Effect</th></tr>
        <tr><td><code>--jobs=N</code></td><td>Build up to N sites concurrently (default 1). Cuts wall-clock on AI runs.</td></tr>
        <tr><td><code>--retries=N</code></td><td>Re-run a failed row up to N times.</td></tr>
        <tr><td><code>--no-ai</code></td><td>Skip AI generation (identity + build + deploy only) — fast.</td></tr>
        <tr><td><code>--force</code></td><td>Regenerate AI copy (ignore cache) + full FTP re-upload.</td></tr>
        <tr><td><code>--only=DOMAIN</code></td><td>Process just one row.</td></tr>
        <tr><td><code>--limit=N</code></td><td>Process at most N rows.</td></tr>
        <tr><td><code>--no-preflight</code></td><td>Skip the FTP reachability check.</td></tr>
        <tr><td><code>--verbose</code></td><td>Stream each row's raw progress.</td></tr>
    </table>
</section>

<!-- ═══════════ OPERATIONS ═══════════ -->
<div class="doc-group-header" id="ms-ops">Operations</div>
<section id="ms-observability">
    <h2>Run logs &amp; cost</h2>
    <p>Every campaign writes <code>sites/{master}/multisite/runs/{run_id}.json</code> with per-row status, attempts, files uploaded, AI tokens, estimated cost, and duration — plus run totals. The console summary prints the totals (files uploaded, tokens in/out, estimated cost). Incremental deploy state lives per-domain in <code>multisite/manifests/</code>, so redeploys upload only changed files.</p>
</section>

<section id="ms-security">
    <h2>Security &amp; credentials</h2>
    <ul>
        <li>FTP passwords live in <code>params.csv</code> and are written into each ephemeral build's <code>deploy.json</code> only at deploy time — never persisted in an output site.</li>
        <li>The entire <code>sites/*/multisite/</code> directory is <strong>gitignored</strong> (fail-safe: nothing inside can ever be committed), as is any <code>deploy.json</code>.</li>
        <li>Ensure the web server also blocks <code>sites/*/multisite/</code> from direct HTTP access, since it holds credentials.</li>
        <li>Never reuse one analytics/GTM/AdSense ID across sites — it links the whole network.</li>
    </ul>
</section>

<section id="ms-files">
    <h2>File reference</h2>
    <table>
        <tr><th>File</th><th>Role</th></tr>
        <tr><td><code>multisite/run_campaign.php</code></td><td>Orchestrator — runs the whole table (pool, retry, run log)</td></tr>
        <tr><td><code>multisite/build_one.php</code></td><td>Per-row worker (process_row): clone → inject → differentiate → AI → deploy</td></tr>
        <tr><td><code>multisite/render_site.php</code></td><td>Worker-mode child that renders one site to static HTML</td></tr>
        <tr><td><code>multisite/params_check.php</code></td><td>CSV intake: parse, validate, pre-flight, store</td></tr>
        <tr><td><code>includes/multisite/clone.php</code></td><td>Snapshot + working-dir clone</td></tr>
        <tr><td><code>includes/multisite/inject.php</code></td><td>Write params into site_vars</td></tr>
        <tr><td><code>includes/multisite/differentiate.php</code></td><td>Per-site schema/geo/analytics/identity rewrite</td></tr>
        <tr><td><code>includes/multisite/ai_cache.php</code></td><td>Per-domain AI copy cache (prompt-hash, self-healing)</td></tr>
        <tr><td><code>includes/multisite/deploy.php</code></td><td>Incremental FTP deploy core</td></tr>
        <tr><td><code>includes/multisite/params.php</code></td><td>CSV parse + validation + pre-flight helpers</td></tr>
        <tr><td><code>includes/multisite/landing.php</code> · <code>multisite/generate_landing.php</code></td><td>Parse <code>landing_cities</code> → build per-city service landing pages</td></tr>
        <tr><td><code>multisite/ai/archetypes.json</code> · <code>multisite/ai/compile.php</code></td><td>Shared archetype library + niche-brief → registry compiler</td></tr>
        <tr><td><code>admin/tabs/multisite.php</code> · <code>admin/multisite_api.php</code> · <code>admin/multisite_preflight.php</code></td><td>Admin Multisite tab: upload, pre-flight, run, history, retry</td></tr>
        <tr><td><code>admin/tabs/niche_brief.php</code> · <code>admin/niche_brief_save.php</code></td><td>Admin Niche Brief tab: author + compile the AI vocabulary</td></tr>
    </table>
    <p>Full architecture and rationale live in <code>docs/multisite-generator-architecture.md</code> in the repository.</p>
</section>

<section id="ms-remaining">
    <h2>Not yet automated</h2>
    <ul>
        <li><strong>Per-site logo &amp; images</strong> (Tier 3) — auto-wordmark from the business name, per-city image assignment.</li>
        <li><strong>Domain-seeded theme colors</strong> (Tier 3, lowest impact).</li>
        <li><strong>Research step</strong> — cities not already in the master's <code>cities.json</code> need local-market data (run research or pre-populate).</li>
        <li><strong>Master copy polish</strong> — a few brand phrasings still need shortcode-ifying.</li>
    </ul>
</section>
</div><!-- /#doc-multisite -->
</div><!-- /#main -->

<script>
/* Sidebar active link on scroll */
const sections = document.querySelectorAll('[id]');
const navLinks  = document.querySelectorAll('#sidebar nav a');
const observer  = new IntersectionObserver(entries => {
    entries.forEach(e => {
        if (e.isIntersecting) {
            navLinks.forEach(a => a.classList.remove('active'));
            const active = document.querySelector('#sidebar nav:not([hidden]) a[href="#' + e.target.id + '"]');
            if (active) {
                active.classList.add('active');
                active.scrollIntoView({ block: 'nearest' });
            }
        }
    });
}, { rootMargin: '-20% 0px -75% 0px' });
sections.forEach(s => observer.observe(s));

/* Sidebar search — operates on whichever nav is currently visible */
function filterNav(q) {
    q = q.toLowerCase();
    const nav = document.querySelector('#sidebar nav:not([hidden])');
    if (!nav) return;
    nav.querySelectorAll('a').forEach(a => {
        a.style.display = (!q || a.textContent.toLowerCase().includes(q)) ? '' : 'none';
    });
    nav.querySelectorAll('.nav-group').forEach(g => {
        let el = g.nextElementSibling;
        let hasVisible = false;
        while (el && !el.classList.contains('nav-group')) {
            if (el.tagName === 'A' && el.style.display !== 'none') hasVisible = true;
            el = el.nextElementSibling;
        }
        g.style.display = hasVisible ? '' : 'none';
    });
}

/* Tab switcher — swaps sidebar nav + content between Admin and DevEnv */
/* Each doc: tab key → [content id, nav id, subtitle] */
const DOCS = {
    concepts:  ['doc-concepts',  'concepts-nav',  'Concepts'],
    reference: ['doc-reference', 'reference-nav', 'Admin Reference'],
    building:  ['doc-building',  'building-nav',  'Building Sites'],
    aicity:    ['doc-aicity',    'aicity-nav',    'AI & City Pages'],
    multisite: ['doc-multisite', 'multisite-nav', 'Multisite Documentation'],
    devenv:    ['doc-devenv',    'devenv-nav',    'DevEnv Documentation'],
    extending: ['doc-extending', 'extending-nav', 'Extending / Developer'],
};
function switchDoc(which) {
    if (!DOCS[which]) which = 'concepts';
    for (const key in DOCS) {
        const [contentId, navId] = DOCS[key];
        document.getElementById(contentId).hidden = (key !== which);
        document.getElementById(navId).hidden     = (key !== which);
    }
    document.getElementById('doc-subtitle').textContent = DOCS[which][2];
    document.querySelectorAll('.doc-tab').forEach(t => t.classList.toggle('active', t.dataset.doc === which));
    const search = document.getElementById('doc-search');
    if (search) { search.value = ''; filterNav(''); }
    try { history.replaceState(null, '', '?doc=' + which + (location.hash || '')); } catch (e) {}
}

/* Pick the starting tab from ?doc= or from a matching anchor in the hash */
(function initDoc() {
    const params = new URLSearchParams(location.search);
    let which = params.get('doc');
    if (!DOCS[which]) {
        which = 'concepts';
        if (location.hash) {
            for (const key in DOCS) {
                if (document.querySelector('#' + DOCS[key][1] + ' a[href="' + location.hash + '"]')) { which = key; break; }
            }
        }
    }
    if (which !== 'concepts') switchDoc(which);
})();

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
