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

.pri { display: inline-block; font-size: 0.68rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; padding: 1px 6px; border-radius: 4px; vertical-align: middle; margin-left: 4px; }
.pri.must { background: #fee2e2; color: #b91c1c; }
.pri.should { background: #fef3c7; color: #b45309; }
.pri.maybe { background: #dbeafe; color: #1d4ed8; }

.where { float: right; font-size: 0.6rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.03em; padding: 1px 6px; border: 1px solid currentColor; border-radius: 4px; margin-left: 8px; opacity: 0.85; }
.where.preauthor { color: #7c3aed; }
.where.campaign { color: #0891b2; }
.where.perrow { color: #475569; }
.where.operational { color: #9ca3af; }

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
        <button type="button" class="doc-tab" data-doc="building" onclick="switchDoc('building')">SingleSite Gen</button>
        <button type="button" class="doc-tab" data-doc="aicity" onclick="switchDoc('aicity')">AI &amp; City</button>
        <button type="button" class="doc-tab" data-doc="multisite" onclick="switchDoc('multisite')">MultiSite Gen</button>
        <button type="button" class="doc-tab" data-doc="devenv" onclick="switchDoc('devenv')">DevEnv</button>
        <button type="button" class="doc-tab" data-doc="extending" onclick="switchDoc('extending')">Extending</button>
        <button type="button" class="doc-tab" onclick="location.href='playground.php'" style="background:#fd783b;color:#fff;">🧪 Test Lab</button>
    </div>
    <div class="search-wrap">
        <input type="text" id="doc-search" placeholder="Search docs…" oninput="filterNav(this.value)">
    </div>
        <nav id="concepts-nav">
        <a class="nav-group" href="#group-overview">Overview</a>
        <a href="#overview">What is this system?</a>
        <a href="#operate">Three ways to operate</a>
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
        <a href="#tab-citypages">Landing City Page Gen</a>
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
        <a href="#schema-ai-generate">AI schema generator</a>
        <a href="#schema-prompts">Sample Claude prompts</a>

        

        <a class="nav-group" href="#group-going-live">Going Live</a>
        <a href="#deploy-checklist">Pre-launch checklist</a>
        <a href="#deploy-ftp">FTP deploy</a>
        <a href="#deploy-security">Security notes</a>

        
    </nav>
    <nav id="aicity-nav" hidden>
        <a class="nav-group" href="#group-ai">Workflow — AI System</a>
        <a href="#ai-overview">How AI works</a>
        <a href="#ai-single">↳ Single-site flow</a>
        <a href="#ai-multi">↳ MultiSite flow</a>
        <a href="#ai-niche">The Niche Brief</a>
        <a href="#ai-research">Local market research</a>
        <a href="#ai-standalone">Standalone mode</a>
        <a href="#ai-enrich">Enrich mode</a>
        <a href="#ai-locking">Locking</a>
        <a href="#ai-concurrency">Concurrent generation (workers)</a>
        <a href="#ai-workflow">Full workflow</a>

        

        <a class="nav-group" href="#group-city">Workflow — City Pages</a>
        <a href="#landing-multicity-overview">Landing Pages &amp; Multi-city</a>
        <a href="#cities-overview">City Pages overview</a>
        <a href="#cities-templates">Templates</a>
        <a href="#cities-generation">Generation steps</a>
        <a href="#cities-differentiation">Per-city differentiation</a>
        <a href="#cities-slugs">Slugs</a>

        
    </nav>
    <nav id="extending-nav" hidden>
        <a class="nav-group" href="#group-howto">How To</a>
        <a href="#howto-new-block">Add a new block type</a>
        <a href="#howto-new-ai-type">Add a new AI type</a>
        <a href="#howto-new-city">Add a city</a>
        <a href="#howto-new-template">Add a template</a>
        <a href="#howto-plugin">Add a plugin / hooks</a>
        <a href="#howto-update-docs">Update this docs page</a>
    
    </nav>

    <nav id="multisite-nav" hidden>
        <a class="nav-group" href="#ms-overview">Overview</a>
        <a href="#ms-what">What it is</a>
        <a href="#ms-howitworks">How it works</a>
        <a href="#ms-master-state">State of the SingleSite</a>
        <a href="#ms-vs-insite">MultiSite vs city pages</a>

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
        <a href="#ms-repro">Reproducibility</a>
        <a href="#ms-editing">Editing the master safely</a>
        <a href="#ms-landing">Per-deploy landing pages</a>

        <a class="nav-group" href="#ms-seo">Site Differentiation &amp; SEO</a>
        <a href="#ms-differentiation">Per-site differentiation</a>
        <a href="#ms-visual-identity">Visual identity</a>
        <a href="#ms-axes">Differentiation axes &amp; status</a>
        <a href="#ms-specs">Build specs</a>
        <a href="#ms-roadmap">Build roadmap</a>
        <a href="#ms-variation">Deterministic variation</a>
        <a href="#ms-hosting">Cloudflare &amp; origin IP</a>

        <a class="nav-group" href="#ms-admin">Running from the Admin</a>
        <a href="#ms-admin-multisite">The MultiSite tab</a>

        <a class="nav-group" href="#ms-run">Command Line</a>
        <a href="#ms-cli-intake">1 · Prepare &amp; validate</a>
        <a href="#ms-cli-oneshot">2 · Build one site</a>
        <a href="#ms-cli-campaign">3 · Run the campaign</a>
        <a href="#ms-flags">Options &amp; flags</a>

        <a class="nav-group" href="#ms-ops">Operations</a>
        <a href="#ms-observability">Run logs &amp; cost</a>
        <a href="#ms-security">Security &amp; credentials</a>
        <a href="#ms-files">File reference</a>
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

<section id="operate">
    <h2>Three ways to operate the factory</h2>
    <p>The factory has <strong>no database</strong> — everything is files (<code>sites/{id}/data/*.json</code> + <code>uploads/</code>). Both interfaces below read and write those same files, so they're fully interoperable; pick per task, or mix them.</p>

    <h3>1 · Admin panel</h3>
    <p><em>The web UI (<code>/admin</code>, session auth, runs as <code>www-data</code>).</em> Click-to-edit blocks, media uploads, AI generation, and one-click static build + FTP deploy — plus running whole multisite campaigns from the browser. <strong>Best for:</strong> non-technical operators, content tweaks, review, and firing a campaign without a shell. <strong>Bounded by</strong> what the UI exposes; bulk or structural work is tedious.</p>

    <h3>2 · Claude Code</h3>
    <p><em>The CLI agent — edits JSON and runs scripts directly, as <code>root</code>.</em> Builds sites from scratch via the phased methodology (research → foundation → homepage block-by-block → landing pages → images), edits <code>site.json</code> precisely, runs the CLI tools (<code>generate.py</code>, <code>multisite/run_campaign.php</code>, <code>params_check.php</code>), and does git, screenshots, and code changes (new block types, etc.). <strong>Best for:</strong> new sites, large / structural / repeatable edits, batch multisite, anything scriptable. <strong>Caveats:</strong> runs as <code>root</code> — build to <code>/tmp</code> or <code>chown</code> afterward so the admin (<code>www-data</code>) can still write; and direct JSON edits bypass the UI's <code>sanitize_url</code> / <code>sanitize_svg</code>, so follow the save-handler conventions.</p>

    <h3>3 · Hybrid <span style="font-weight:400;color:#64748b;font-size:.85em;">(recommended for most real work)</span></h3>
    <p>Claude Code scaffolds structure and bulk content; a human uses the admin panel to refine copy, drop in images, review, and hit deploy. Or: the CLI authors and edits the multisite <em>master</em>, and the admin panel runs the <em>campaign</em>. Because both touch the same files, no sync step is needed — just don't edit the same file simultaneously, and mind the <code>root</code> vs <code>www-data</code> ownership line.</p>

    <div class="callout tip">All three operate on the same flat JSON — there is no import/export or sync. The only thing to manage when mixing is file <strong>ownership</strong> (CLI writes as root, the admin writes as www-data).</div>
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
    <p>Spacing and alignment for the navigation bar. The nav bar <strong>background and text colors have moved to the <a href="#tab-theme">Theme / Colors</a> tab</strong> — the old nav_bg / nav_text pickers were removed here, and a pointer sends you there. Set the "Header bar color" and "Header bar text" on Theme / Colors; the header container and menu links follow automatically.</p>

    <h3>Nav CTA Button</h3>
    <p>An optional call-to-action button inside the nav bar (e.g. "Get a Quote", "Register Now"). Shows as a colored button, separate from the regular menu links. Set label and URL.</p>

    <h3>Header Info Items</h3>
    <p>Secondary information line displayed in the 2-row header above the nav — typically used for hours, email, or a second phone number. Each item has an icon and text.</p>

    <h3>Social Media Links</h3>
    <p>Social profile icons shown in the header. Each entry is a platform name and URL. Icons are rendered automatically based on the platform. Leave the URL blank to hide that platform's icon.</p>
</section>

<section id="tab-theme">
    <h2>Tab: Theme / Colors</h2>
    <p>Controls global colors, fonts, and button styles. All values are converted to CSS custom properties and injected inline into every page. Change a color here and it updates everywhere on the site automatically.</p>
    <p>The tab reads top-to-bottom as a hierarchy, each block with its own "when to use" guidance: <strong>① Theme Preset</strong> → <strong>② Brand colors</strong> → <strong>③ Header &amp; Footer</strong> → <strong>④ Section moods (Block Skins)</strong> → <strong>⑤ Page background &amp; borders</strong> → <strong>⑥ Typography &amp; Buttons</strong> → <strong>⑦ Tracking</strong>. Start at the top and work down.</p>
    <div class="callout tip">
        <p><strong>Tip:</strong> Color fields in content blocks accept the keywords <code>accent</code>, <code>header</code>, or <code>footer</code> instead of a hex value — they resolve at render time, so one theme change ripples to every block that uses them.</p>
    </div>

    <h3>① Theme Preset</h3>
    <p>A functional <strong>Theme Preset</strong> picker at the top of the tab: pick a preset, click <em>Apply</em> to load its colors / font / button radius into the form below, review, then <em>Save</em>. A Theme Preset is a whole-site bundle of theme values (in multisite it also carries a bug icon that drives the generated logo — see <a href="#ms-visual-identity">Visual identity</a>). Fastest way to reskin the whole site in one move.</p>

    <h3>② Brand colors</h3>
    <p>The core palette — typically 2–3 colors from the client's brand guide:</p>
    <ul>
        <li><strong>Accent</strong> — buttons, icon tints, highlighted text, active states.</li>
        <li><strong>Highlight</strong> — the secondary brand color for accents and emphasis.</li>
        <li><strong>Button text</strong> — the text color that sits on filled buttons.</li>
    </ul>

    <h3>③ Header &amp; Footer</h3>
    <p>One <strong>Header bar color</strong> control drives the visible nav bar. It stores <code>header.nav_bg</code> as a <em>mode</em> — <em>"Match brand accent"</em> (<code>nav_bg="accent"</code>) or a custom hex. The header container and dropdowns (<code>--color-header-bg</code>) now <strong>auto-follow</strong> it: <code>site-template.php</code> resolves <code>nav_bg</code> and sets the theme's header background to match, so you set the bar color once.</p>
    <p>One <strong>Header bar text</strong> control drives both the bar text (<code>nav_text</code>) and the menu links (<code>theme.header_text</code>). Footer background and footer text colors are set here too.</p>
    <div class="callout">The old nav-bar background / text pickers were removed from the <a href="#tab-header">Header tab</a> — that tab now points here. Set the header bar color and text on this tab.</div>

    <h3>④ Section moods — Block Skins</h3>
    <p>A <strong>Block Skin</strong> is the per-section palette a block wears — <code>light</code>, <code>subtle</code>, <code>accent</code>, or <code>dark</code>. Each skin is a named background/text/heading slot defined here; a block then picks a skin from a dropdown to change its mood independently of the main content. (Internally these are <code>theme['skins']</code> / <code>block['skin']</code> / <code>.skin-*</code> / <code>--skin-*</code>.)</p>
    <p>The <strong>Light Block Skin's heading color is the site-wide heading color</strong> — headings render from it, so the tab frames the Light skin as where you set heading color. (The standalone <code>heading_color</code> field is vestigial.)</p>

    <h3>⑤ Page background &amp; borders</h3>
    <p>The page background color and default border/divider color used between sections and around cards.</p>

    <h3>⑥ Typography &amp; Buttons</h3>
    <p>Sets the Google Font used site-wide (enter a family name like <code>Inter</code> or <code>Lato</code> — loaded automatically; heading font, base size, and line height configurable), plus the button border radius (<code>0</code> = square, <code>4px</code> = slightly rounded, <code>9999px</code> = pill).</p>

    <h3>⑦ Tracking</h3>
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
    <h2>Tab: Landing City Page Gen</h2>

    <h3>What it is</h3>
    <p>Takes this site's <strong>landing templates</strong> and a list of <strong>cities</strong> and produces many <strong>city landing pages inside this one site</strong> — same business and topic, one page per city. Each page is a distinct URL with city-localized content: <code>{city}</code>/<code>{state}</code>/<code>{business}</code> shortcodes resolved, AI-written city-specific copy, and (opt-in) per-city images and section order.</p>
    <p>Unlike <strong>MultiSite Gen</strong> — which deploys many <em>separate</em> single-city <em>websites</em> (one domain each) — this stays <strong>one website, one domain, one deploy</strong>, serving many cities through landing pages. It reuses the factory's existing machinery (templates, the generation engine, the <code>generate.py</code> AI engine, the shared block renderer, and the per-city image/layout cores) rather than reinventing it — the generator's job is to drive those primitives across a table of template × city combinations.</p>

    <h3>How it works</h3>
    <p>The high-level flow:</p>
    <pre><code>Landing Templates  (authored once: blocks + {shortcodes} + ai_block placeholders + slug pattern)
        +
Landing Cities     (one row per city: name, state, slug, geo…)
        ↓
[ generation ]     loop every template × city
        ↓
[ per page ]       build → fill shortcodes + AI copy → (opt-in) per-city images
                   → (opt-in) vary block order → auto FAQ + breadcrumb schema → write at city slug
        ↓
Many city landing pages, all in one site   (/{slug})</code></pre>
    <p>Two inputs — <a href="?tab=templates">Landing Templates</a> (the reusable page skeletons) × <a href="?tab=cities">Landing Cities</a> (the target cities). <code>generate_city_pages()</code> loops every combination and, for each, runs the per-page pipeline:</p>
    <ol>
        <li><strong>Build</strong> the page from the template.</li>
        <li><strong>Fill</strong> — resolve <code>{city}</code>/<code>{state}</code>/<code>{business}</code>… shortcodes and generate the AI archetype blocks' city-specific copy (locked blocks are preserved on re-generate).</li>
        <li><strong>Per-city images</strong> (opt-in) — bake the keyword + "City, ST" onto the hero; in Full mode also give each city a unique, city-renamed copy of every photo.</li>
        <li><strong>Vary block order</strong> (opt-in) — a slightly different section order per city (hero first, closing block last, a couple of middle swaps).</li>
        <li><strong>Schema</strong> — auto-inject FAQ (from the page's FAQ blocks) + breadcrumb.</li>
        <li><strong>Write</strong> the page at its city slug (from the template's slug pattern) and update the page index.</li>
    </ol>
    <p>No cloning, identity rewrite, or FTP deploy — it is <strong>one site</strong>. Pages live in <code>data/pages/</code> and are served by <code>page.php</code> at <code>/{slug}</code>. Regeneration is idempotent (the hero overlay is cached), and the Status Grid below shows Generated / Stale / Missing.</p>

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
    <p>Two opt-in <strong>per-city differentiation</strong> controls sit in this panel — so a single site's 40 city pages don't all ship identical images and structure:</p>
    <ul>
        <li><strong>Per-city images</strong> dropdown — <em>Off</em> (share the template's images), <em>Hero text overlay</em> (bake the keyword + "City, ST" onto each hero), or <em>Full</em> (hero overlay plus a unique, city-renamed copy of every content photo). Non-destructive; deterministic per city; needs ImageMagick.</li>
        <li><strong>Vary block order per city</strong> checkbox — gives each city page a slightly different section order (hero first, closing block last, a couple of middle sections swap). Needs 4+ blocks.</li>
        <li><strong>"tune hero style ↗"</strong> link — opens the <a href="playground.php#hero-overlay">Test Lab</a> hero-overlay panel to set and lock the overlay text position/size/colors.</li>
    </ul>
    <p>Full detail: <a href="#cities-differentiation">City Pages — Per-city differentiation</a>.</p>

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

    <h3>Live progress &amp; worker bars</h3>
    <p>Landing pages generate <strong>concurrently</strong> (8 at a time by default). While a run is in flight the panel shows a global <em>Block X of Y</em> bar plus a grid of <strong>per-worker bars</strong> — one per worker slot, each naming the page it is building and its progress through that page's blocks. The bars build themselves from the run's own output; there is nothing to switch on. See <a href="#ai-concurrency">Concurrent generation (workers)</a> for how it works and when to drop to <code>--workers 1</code>.</p>

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
        <li><strong>City Image</strong> (&#127957;) — auto-fetches a scenic Wikipedia/Wikimedia photo of the site's city, self-hosts it as webp, and exposes <code>{city_image}</code> / <code>{city_image_alt}</code> / <code>{city_image_credit}</code> tokens plus a <code>[city_image]</code> shortcode (SEO alt + CC credit). See <a href="#cities-differentiation">Per-city images</a>.</li>
        <li><strong>Course Schedule</strong> (&#128197;) — adds schedule management (the Schedule sub-panel) and enables <code>[course_schedule]</code> and <code>[course_card]</code> shortcodes inside Custom HTML blocks. See the <a href="#tab-schedule">Schedule</a> section for field details.</li>
        <li><strong>Service Links: 1-City</strong> (&#128279;) — manage a service-page list; <code>[services_links]</code> renders a city-resolved grid of service links in a Custom HTML block.</li>
        <li><strong>Service Links: Multi-City</strong> (&#127758;) — <code>[locations]</code> lists all generated city landing pages, grouped by city and state.</li>
        <li><strong>Info Popup</strong> (&#8505;) — shows an info popup when visitors click the &#8505; button in the nav or sticky bottom bar (call-handling disclosures, service-area info).</li>
    </ul>
    <p>New plugins drop a folder into <code>plugins/</code> and register via the plugin API — no code changes to the core system required. See <a href="#howto-plugin">Add a Plugin (hooks &amp; custom tokens)</a> for authoring.</p>

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
        <li><strong>AI-block target:</strong> an <a href="#block-ai_block">AI Block</a> with <code>ai_render_as: "image_text"</code> renders here too — when <code>it_heading</code> / <code>it_text</code> are empty it falls back to the AI's <code>heading_text</code> / <code>text</code>, so AI copy shows with an optional static photo (<code>it_photo</code> + <code>it_image_side</code>). Leave <code>it_photo</code> empty for text-only.</li>
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
    <h4>Render type (<code>ai_render_as</code>)</h4>
    <p>A standalone <code>ai_block</code> renders as the block type named in <code>ai_render_as</code> (e.g. <code>text</code>, <code>feature_columns</code>). Set it to <code>image_text</code> to give the block an <strong>optional photo</strong> beside the AI copy — one block then covers all three modes:</p>
    <ul>
        <li><code>it_photo</code> empty → <strong>text only</strong></li>
        <li><code>it_photo</code> set + <code>it_image_side: "left"</code> → <strong>text + image left</strong></li>
        <li><code>it_photo</code> set + <code>it_image_side: "right"</code> → <strong>text + image right</strong></li>
    </ul>
    <p>The AI writes into <code>heading_text</code> / <code>text</code>; the <code>image_text</code> renderer falls back to those when its own <code>it_heading</code> / <code>it_text</code> are empty (<code>includes/blocks.php</code>), so the AI copy shows and the photo is a static field you set per template/page (<code>it_photo</code>, <code>it_image_side</code>, <code>it_alt</code>). Example: <strong>City Intro</strong> (<code>city_intro</code>) in the pest cockroach template renders as <code>image_text</code> with an empty photo (text-only) until a real photo is added, then flips to image-left.</p>
</div>

</div><!-- /#doc-reference -->

<div id="doc-building" hidden>
<h1>Site Factory — SingleSite Generation</h1>
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
        <tr><td><code>city_slug</code></td><td>URL-safe city + state slug</td><td>san-antonio-tx</td></tr>
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
        <li><strong>Choose a header layout</strong> — <strong>Single Row</strong> (logo, nav, and phone in one bar) or <strong>Standard</strong> (2-row: logo + info bar on top, colored nav below). Pick the layout that matches the client's brand style.</li>
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
        <li><code>{city}</code> / <code>{SS}</code> resolve to the site's <strong>primary</strong> city/state (from Site Variables) — fine for a single-city local business, but avoid them on a <em>national or multi-city</em> homepage, where stamping one city is misleading. (They never render literally; that only happens if site_vars hasn't been saved.)</li>
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
    <p>The schema textarea supports shortcodes — the same tokens that resolve everywhere else (<code>resolve_shortcodes()</code>). Supported: <code>{website}</code>, <code>{business}</code>, <code>{business_domain}</code>, <code>{phone}</code>, <code>{tel}</code>, <code>{zip}</code>, <code>{address}</code>, <code>{city}</code>, <code>{state}</code>, <code>{SS}</code>, <code>{city_slug}</code>, <code>{city_state}</code>, <code>{lat}</code>, <code>{lng}</code>, <code>{rating}</code>, <code>{review_count}</code>, and <code>{primary_keyword}</code> (alias <code>{service}</code>). <code>{rating}</code> / <code>{review_count}</code> feed <code>aggregateRating</code>. When the <strong>City Image</strong> plugin is enabled, <code>{city_image}</code>, <code>{city_image_alt}</code>, and <code>{city_image_credit}</code> are available too.</p>
    <div class="callout warn"><strong>Keep the business node's <code>@id</code> consistent.</strong> Every node that references the business (a WebPage's <code>about</code>, a Course's <code>provider</code>, breadcrumbs, etc.) must point at the <em>same</em> <code>@id</code>. The AI Schema Generator anchors the business node at <code>{website}/#localbusiness</code>; the hand-written examples in this doc use <code>#organization</code> for readability. If you mix AI-generated and hand-authored schema on one site, standardize on <code>#localbusiness</code> so the cross-references resolve.</div>
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

<section id="schema-ai-generate">
    <h2>AI Schema Generator</h2>
    <p>Every SEO section has an <strong>✨ AI generate this schema</strong> panel below the Schema Markup box. It drafts the JSON-LD with Claude, drops it into the box, and validates it — so you don't hand-write schema. You still review and Save; nothing is auto-committed.</p>

    <h3>One button, four areas — each with its own prompt</h3>
    <p>The panel appears in four places, and the prompt it uses is <strong>specific to that area</strong> (different schema shapes) while being <strong>shared globally</strong> across all pages of that area on the site:</p>
    <table style="width:100%;border-collapse:collapse;margin-bottom:20px;font-size:0.88rem;">
        <thead>
            <tr style="background:#f8fafc;border-bottom:2px solid #e2e8f0;">
                <th style="text-align:left;padding:8px 12px;font-weight:700;">Area</th>
                <th style="text-align:left;padding:8px 12px;font-weight:700;">Where</th>
                <th style="text-align:left;padding:8px 12px;font-weight:700;">What its prompt produces</th>
            </tr>
        </thead>
        <tbody>
            <tr style="border-bottom:1px solid #e5e7eb;"><td style="padding:8px 12px;font-weight:600;">Home</td><td style="padding:8px 12px;">Content tab → SEO</td><td style="padding:8px 12px;">The foundational <code>LocalBusiness</code> + <code>WebSite</code> + <code>WebPage</code> nodes. Their <code>@id</code>s (<code>{website}/#localbusiness</code>, <code>/#website</code>, <code>/#webpage</code>) are the anchors every other page references.</td></tr>
            <tr style="border-bottom:1px solid #e5e7eb;background:#fafafa;"><td style="padding:8px 12px;font-weight:600;">Core</td><td style="padding:8px 12px;">Pages tab → edit → SEO</td><td style="padding:8px 12px;">Schema for the <strong>Page type</strong> you pick — Contact, About, Service/Course, Collection/Listing, or General/Legal. Each type has its own prompt. The type is auto-detected from the page title; override it if the guess is wrong. References the home <code>@id</code>s.</td></tr>
            <tr style="border-bottom:1px solid #e5e7eb;"><td style="padding:8px 12px;font-weight:600;">Landing</td><td style="padding:8px 12px;">Templates tab → edit → SEO</td><td style="padding:8px 12px;"><code>Service</code> + <code>areaServed</code>, with <code>provider</code> referencing the home business by <code>@id</code>. City values stay as shortcodes (<code>{city}</code>, <code>{city_slug}</code>) and resolve per generated city. <code>FAQPage</code> is excluded — it is injected automatically.</td></tr>
            <tr style="border-bottom:1px solid #e5e7eb;background:#fafafa;"><td style="padding:8px 12px;font-weight:600;">Blog</td><td style="padding:8px 12px;">Blog tab → edit → SEO</td><td style="padding:8px 12px;"><code>BlogPosting</code> using this post's title, excerpt, date and image, with <code>publisher</code> referencing the home business <code>@id</code>.</td></tr>
        </tbody>
    </table>

    <h3>Global vs. this-generation edits</h3>
    <ul>
        <li>The prompt box is <strong>editable</strong>. Editing it changes only the <em>next</em> generation.</li>
        <li><strong>Save prompt as default</strong> persists your edit for that area on this site — stored as an override in <code>sites/{id}/data/schema_prompts.json</code>. Built-in defaults live in code; you only store the diff.</li>
        <li><strong>↺ Reset to built-in</strong> discards the override and restores the shipped default.</li>
        <li>For Core, each page type has its own saved prompt — switching the Page type swaps the prompt.</li>
    </ul>

    <h3>What it always enforces</h3>
    <p>Regardless of prompt edits, the generator appends hard rules server-side:</p>
    <ul>
        <li><strong>Shortcodes, not hardcoded values.</strong> Business identity is emitted as <code>{website}</code>, <code>{business}</code>, <code>{tel}</code>, etc. — so one schema works across every generated/cloned site.</li>
        <li><strong>Shared business <code>@id</code>.</strong> The business node is always <code>{website}/#localbusiness</code>; other pages reference it rather than redefining the business — the whole site becomes one connected graph.</li>
        <li><strong>No fabrication.</strong> Addresses, geo, ratings, review counts, prices, dates and authors are omitted unless real values exist — never invented.</li>
        <li><strong>Clean JSON only.</strong> Output is stripped of any code fences/prose and must parse as JSON before it is handed back.</li>
    </ul>
    <p class="hint">Model: <code>claude-sonnet-5</code>, one call per click. Requires <code>ANTHROPIC_API_KEY</code> (Admin → AI). It never saves the page for you — the green border confirms valid JSON, then you Save as usual.</p>
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
    <p>The same AI engine powers <strong>single-site</strong> and <strong>multisite</strong> builds. Understanding four pieces explains all of it:</p>
    <table>
        <tr><th>Piece</th><th>What it is</th></tr>
        <tr><td><strong>AI block</strong></td><td>The unit of AI content. A block in a page/template marked for generation — either a <code>type: "ai_block"</code> placeholder, or any block carrying an <code>ai_type_id</code>. <strong>If a page has no AI block, the AI generates nothing there.</strong></td></tr>
        <tr><td><strong>Registry</strong> (<code>data/ai_block_types.json</code>)</td><td>The recipe book. Each block's <code>ai_type_id</code> points to an entry giving its prompt, model, output schema, and mode.</td></tr>
        <tr><td><strong><code>generate.py</code></strong></td><td>The <em>content</em> engine. Finds AI blocks, resolves the prompt, calls Claude, writes the result. The only thing that spends tokens.</td></tr>
        <tr><td><strong><code>includes/generation/engine.php</code></strong></td><td>The <em>structure</em> engine. Fans a template out to one page per city and resolves shortcodes. <strong>Never calls an LLM.</strong></td></tr>
    </table>
    <p><strong>Content AI = AI blocks.</strong> <code>generate.py</code> only touches blocks that qualify as AI blocks; every other block is rendered verbatim. The <a href="#ai-research">research step</a>, the SEO <a href="#group-schema">schema generator</a>, and keyword suggestions are <em>separate</em> AI features — they are not block-driven. Research in particular is a pre-step that fills <code>cities.json</code> with facts (industries, employers, neighborhoods, population) that a block's prompt then references via tokens like <code>{city}</code> / <code>{neighborhoods}</code> — it <em>feeds</em> Content AI rather than being an AI block itself.</p>

    <h3>Two modes</h3>
    <ul>
        <li><strong>Standalone</strong> — an <code>ai_block</code> placeholder is filled and rendered as its <code>ai_render_as</code> type (e.g. <code>local_relevance</code> → a text block; set it to <code>image_text</code> to add an optional photo beside the copy). See <a href="#ai-standalone">Standalone mode</a>.</li>
        <li><strong>Enrich</strong> — an existing real block carries an <code>ai_type_id</code>; the AI fills one field in place (e.g. <code>faq_local</code> fills a <code>faq_two_col</code>'s items) and leaves the rest of the block alone. See <a href="#ai-enrich">Enrich mode</a>.</li>
    </ul>

    <h3>The two-stage pipeline (both build modes)</h3>
    <p>Structure and content are always separate stages, in this order:</p>
    <ol>
        <li><strong>Structure (PHP, no cost)</strong> — <code>engine.php</code> builds the page skeleton, including empty AI-block placeholders, and resolves slug/canonical/SEO shortcodes.</li>
        <li><strong>Content (Python, costs tokens)</strong> — <code>generate.py</code> fills the AI blocks per city and stamps each filled block <code>_ai_locked</code> so later runs skip it (unless <em>Force</em> / <code>--refresh</code>). See <a href="#ai-locking">Locking</a>.</li>
    </ol>
    <p>The <strong>FAQPage</strong> JSON-LD is <em>derived at render</em> from the page's current FAQ blocks (not stored at structure time), so it always matches the AI-filled FAQ and can never go stale.</p>

    <h3 id="ai-single">How it works — single site</h3>
    <ul>
        <li><strong>Home &amp; core pages:</strong> the AI blocks live directly in <code>site.json</code> (edited on the Content tab — there is no "template"). Run AI generation on the <a href="#tab-generate">AI Generation</a> tab (scope Homepage / Core) to fill them. Don't lean on per-city tokens here — <code>{city}</code>/<code>{neighborhoods}</code> resolve to the site's home city, not a per-page one.</li>
        <li><strong>Landing (per-city) pages:</strong> author AI blocks once in a <strong>template</strong> (Templates tab); the <a href="#tab-cities">Landing City Page Gen</a> tab materializes one page file per city (structure, <code>engine.php</code>); then AI generation fills them per city.</li>
        <li><strong>Research:</strong> the <em>Research with AI</em> button on the Cities tab fills that city's row in <code>cities.json</code>. Grounds the copy. See <a href="#ai-research">Local market research</a>.</li>
        <li><strong>Registry:</strong> for a pure single site you author block types by hand in the <a href="#tab-ai-blocks">Block Type Registry</a> tab. (A site with no registry silently skips all AI blocks.)</li>
        <li><strong>Deploy:</strong> Generate Static Site → the static build renders every page (through <code>site-template.php</code>) and FTP-deploys it.</li>
    </ul>

    <h3 id="ai-multi">How it works — multisite</h3>
    <p>A <strong>master</strong> site is cloned into many deployed sites, one per row of the <a href="#ms-params">params table</a>. <code>run_campaign.php</code> runs each row through <code>build_one.php</code>, which per site does:</p>
    <ol>
        <li><strong>Clone</strong> the master snapshot into an ephemeral working dir.</li>
        <li><strong>Inject identity</strong> — rewrite business name / domain / phone / schema to this deploy.</li>
        <li><strong>Scope landing cities</strong> to the row's <code>landing_cities</code> — <em>merging in the master's research</em> for those cities (so neighborhoods, industries, etc. reach the deployed page; a city the master never researched stays generic).</li>
        <li><strong>Structure</strong> the landing pages (<code>engine.php</code>), then <strong>fill AI content</strong> (<code>generate.py --all</code>) — home, core, and landing.</li>
        <li><strong>Differentiate images</strong>, <strong>build static</strong> (the same render path as single-site), and <strong>FTP-deploy</strong> (skipped if the row has no FTP credentials — build-only).</li>
    </ol>
    <p><strong>Registry:</strong> a master's registry is <em>compiled</em> from the shared archetype library (<code>multisite/ai/archetypes.json</code>) + the master's <a href="#ai-niche">Niche Brief</a>, via <code>compile.php</code>. Compile is <strong>non-destructive</strong> — it never overwrites or deletes a hand-authored block type. See <a href="#ai-hardening">AI hardening</a>.</p>
    <p><strong>Per-domain cache:</strong> generated copy is frozen per deployed domain and re-used on rebuild — zero API calls, identical output — <em>unless</em> an input actually changed. Staleness is keyed on a hash of the <strong>fully-resolved prompt + model</strong>, so a change to a city's research, the prompt, or the model regenerates just the affected blocks. See <a href="#ms-cache">the content cache</a>.</p>

    <h3>Shared vs. multisite-only</h3>
    <table>
        <tr><th></th><th>Single-site</th><th>MultiSite</th></tr>
        <tr><td>Content engine (<code>generate.py</code>)</td><td>✅</td><td>✅ (same binary)</td></tr>
        <tr><td>Registry + render path</td><td>✅</td><td>✅ (shared)</td></tr>
        <tr><td>Registry origin</td><td>hand-authored</td><td>compiled from archetypes</td></tr>
        <tr><td>Per-city scoping + research merge</td><td>—</td><td>✅ (<code>build_one</code>)</td></tr>
        <tr><td>Per-domain content cache</td><td>—</td><td>✅</td></tr>
        <tr><td>FTP deploy fan-out</td><td>one site</td><td>N sites</td></tr>
    </table>

    <h3>What keeps the content good &amp; safe</h3>
    <ul>
        <li><strong>Research grounding</strong> — real per-city facts, not invented ones. <a href="#ai-research">Details</a>.</li>
        <li><strong>Neighborhoods gate</strong> — real neighborhood names woven into prose (never a list), and only auto-published above a population threshold so AI can't put a fake subdivision on a small-town page. <a href="#ai-neighborhoods">Details</a>.</li>
        <li><strong>FAQ schema is a projection of content</strong> — derived at render, never stale.</li>
        <li><strong>One model catalog</strong> — <code>includes/models.json</code> is the single source for which models exist, their labels, and pricing; every editor dropdown, validator, and the cost table read it.</li>
    </ul>
</section>

<section id="ai-niche">
    <h2>The Niche Brief</h2>
    <p>The <strong>Niche Brief</strong> (admin <strong>Niche Brief</strong> tab → <code>sites/{site}/multisite/niche_brief.json</code>) captures a business's vocabulary <em>once</em> so every AI-generated block sounds like the same company. It is the layer that turns the generic, shared archetype library into <em>this</em> site's prompts.</p>
    <h3>What's in it</h3>
    <ul>
        <li><code>business_descriptor</code>, <code>service_noun</code>, <code>customer_noun</code> — how the AI refers to the business, what it sells, and who it serves.</li>
        <li><code>offerings</code> — the concrete products/services (e.g. "PMP Certification Training", "PMP Bootcamp").</li>
        <li><code>local_angle</code>, <code>tone</code>, <code>guardrails</code> — how to localize, how to sound, and the hard rules (trademark usage, "never invent stats", etc.).</li>
        <li><code>enabled_archetypes</code> — which of the shared block <em>shapes</em> (<code>city_intro</code>, <code>hero_subtext</code>, <code>feature_columns_local</code>, <code>faq_local</code>, …) this niche uses.</li>
        <li><code>uses_research_fields</code> + <code>research_prompt</code> — whether the niche wants real per-city market data, and what to ask for (see <a href="#ai-research">Local market research</a>).</li>
    </ul>
    <h3>How it feeds generation</h3>
    <p>The brief is <strong>compiled</strong> (Niche Brief tab → <em>Save &amp; Compile</em>, or <code>php multisite/ai/compile.php --master=&lt;site&gt;</code>) — merging each enabled archetype's shared prompt skeleton with the brief's vocabulary and overwriting the site's <code>data/ai_block_types.json</code>, which is the prompt registry <code>generate.py</code> reads. Then AI generation (Standalone / Enrich, below) fills every <code>ai_block</code> using those compiled prompts plus each city's row in <code>cities.json</code>.</p>
    <p>Need to tune a specific archetype's wording or model <em>for this niche</em>? The Niche Brief tab's collapsible <em>"Prompt templates (archetypes) — advanced"</em> panel edits each archetype's Label / Model / Description / Prompt skeleton per master, stored as diffs against the shared library and merged in at compile time (with a per-archetype <em>Reset to shared default</em>). See <a href="#ms-niche">MultiSite → Niche Brief &amp; archetypes</a>.</p>
    <div class="callout">
        <p><strong>The same brief drives two very different build modes:</strong></p>
        <ul style="margin:8px 0 0;">
            <li><strong>One site, many landing pages (this workflow).</strong> A single deployed site (e.g. Granite PM Academy) publishes one landing page per city × offering (PMP&nbsp;Tampa, PMP&nbsp;Charlotte, CAPM&nbsp;Tampa…). Structure gen copies the template to each page; AI gen fills the <code>ai_block</code>s so no two city pages read alike — all under <strong>one domain</strong>. This is the Templates → City Pages → Generate flow.</li>
            <li><strong>MultiSite: many separate sites.</strong> The <em>same</em> master (brief + compiled registry + templates) is <strong>cloned</strong> into many independent single-city sites, each on its <strong>own domain</strong>, and each clone runs <code>generate.py</code> to fill its <code>ai_block</code>s for its one city. Nothing extra to author — the brief is reused verbatim. See <a href="#ms-niche">MultiSite → Niche Brief &amp; archetypes</a>.</li>
        </ul>
        <p style="margin:8px 0 0;">Mechanically identical: <strong>brief → compile → <code>ai_block</code>s filled by <code>generate.py</code> from <code>cities.json</code></strong>. The only difference is whether the output is many pages on one site or many separate sites.</p>
    </div>
</section>

<section id="ai-research">
    <h2>Local market research</h2>
    <p>AI copy reads as generic filler unless it's grounded in real local facts. When the brief has <strong>Uses research fields</strong> on, the <strong>research step</strong> populates each city's row in <code>cities.json</code> with real data the block prompts can reference — the difference between substance and thin, doorway-style content.</p>
    <h3>Niche-defined, not hard-coded</h3>
    <p>The fields are whatever the brief's <code>research_prompt</code> asks for — a PM academy wants <code>industries</code> / <code>top_employers</code> / <code>salary_note</code> / <code>market_blurb</code>; a groomer might want neighborhoods. Tokens <code>{city}</code>/<code>{state}</code>/<code>{SS}</code> resolve per city; <code>{business_descriptor}</code>/<code>{service_noun}</code> come from the brief. Leave the prompt blank for a generic local-market default.</p>
    <h3>Running it</h3>
    <ul>
        <li><strong>CLI:</strong> <code>php multisite/research_cities.php &lt;site&gt; [--dry-run]</code> — seeds <code>cities.json</code> from the params table (city|SS unique) then looks up each new city via <code>claude-sonnet-5</code>.</li>
        <li><strong>Admin:</strong> the <em>Research cities</em> card on the MultiSite tab (shown only when the brief has research on) — <strong>Dry run</strong> (no API cost) or live, streamed.</li>
        <li><strong>Engine:</strong> <code>generate.py --research-only</code> loads the brief's prompt via <code>_load_research_prompt()</code>, validates softly, and stamps <code>_researched</code> so re-runs skip finished cities. Results persist in <code>cities.json</code> and are reused free.</li>
    </ul>
    <p>Because it just fills <code>cities.json</code>, research grounds copy in <strong>both</strong> build modes — the many-landing-pages site and every multisite clone read the same enriched rows. Guardrail: never let the prompt invent pass rates, salaries, or employer lists — request only verifiable facts and keep figures qualitative unless certain.</p>

    <h3 id="ai-neighborhoods">Neighborhoods &amp; the auto-publish threshold</h3>
    <p>The default research prompt also collects <code>neighborhoods</code> (6–10 real subdivisions/districts) and <code>population</code> per city. On landing pages the <code>local_relevance</code> block <strong>weaves 2–3 names into a sentence — never a list</strong> (a "we serve X, Y, Z" list is a doorway/spam signal, and repeating names across every block is worse). The token is <code>{neighborhoods}</code>; when it's empty the block stays generic and names nothing.</p>
    <p><strong>Why the gate:</strong> neighborhood names are factual claims, and AI hallucinates them far more often for small, thinly-documented towns than for big cities. A fake subdivision on a local page is an E-E-A-T negative — worse than no name. So names only publish when a city is <em>auto-eligible</em>:</p>
    <ul>
        <li><strong>population ≥ threshold</strong> (default <strong>14,000</strong>, editable at the top of the <a href="#tab-cities">Landing Cities</a> tab, stored in <code>sites/&lt;id&gt;/data/neighborhoods.json</code>), <em>or</em></li>
        <li>the per-city <strong>“Always auto-publish”</strong> checkbox is ticked (overrides the threshold — this is also how you approve a reviewed small town).</li>
    </ul>
    <p>Otherwise the names are <strong>held</strong>: the page renders generic (never a fake name) until you open that city's <em>Edit</em> screen, eyeball the researched names, and tick the box. Each city's Edit screen shows a live status badge — <span style="background:#d1fae5;color:#065f46;padding:1px 7px;border-radius:6px;">✓ Auto-publishing</span>, <span style="background:#fef3c7;color:#92400e;padding:1px 7px;border-radius:6px;">⚠ Held for review</span>, or <span style="background:#f3f4f6;color:#6b7280;padding:1px 7px;border-radius:6px;">— none yet</span>. Note the threshold flags well-known small suburbs too (e.g. Katy ~21.9k publishes, but Cinco Ranch ~18.3k as a CDP would be held) — review those once and tick Always-auto.</p>
    <p><strong>Mechanics:</strong> the gate lives in <code>_effective_neighborhoods()</code> in <code>generate.py</code>, called from <code>build_context()</code>; a held city resolves <code>{neighborhoods}</code> to an empty string, which <code>substitute_vars()</code> leaves blank — so it's fully backward-compatible and no already-built page changes until you re-materialize. Note the default prompt override applies: a master with its own <code>research_prompt</code> (e.g. the PM academy) won't collect neighborhoods unless you add the field to its brief prompt.</p>
</section>

<section id="ai-hardening">
    <h2>AI hardening backlog</h2>
    <p>From the 2026-07 cross-system AI audit. Single-site and multisite share the engine (<code>generate.py</code>), the registry/compiler, and the render path (<code>site-template.php</code> → <code>build_static_site()</code>), so <strong>shared fixes land once and benefit both</strong>; a few issues are multisite-only and have no single-site analog.</p>

    <h3>Done</h3>
    <ul>
        <li><strong>Neighborhoods feature</strong> — population-gated, woven per city (see above).</li>
        <li><strong><code>call_claude</code> crash</strong> — fixed the <code>ThinkingBlock</code> error that blocked all generation on current models.</li>
        <li><strong>P1 · multisite research-strip</strong> <em>(multisite-only)</em> — <code>build_one.php</code> was overwriting the working <code>cities.json</code> with bare landing rows, discarding master research (neighborhoods/industries/employers) → generate.py saw bare rows → generic copy on every deployed landing page. Fixed via <code>ms_merge_research_into_landing()</code>.</li>
    </ul>

    <h3>Tier 1 — correctness (ships wrong output)</h3>
    <ul>
        <li><strong>P6 · FAQ schema stale</strong> <em>(shared)</em> — FAQPage JSON-LD is built at structure time (<code>engine.php</code>) before the AI fills the FAQ, so deployed structured data doesn't match the visible FAQ. Fix: derive FAQPage at render in <code>site-template.php</code> and drop the early injection.</li>
        <li><strong>AI cache research-blindness</strong> <em>(multisite-only)</em> — the per-domain cache staleness stamp hashes the prompt <em>template</em>, not the resolved research, so data-only changes (a new neighborhood, a freshly-researched city) don't invalidate it; re-deploys serve stale copy. Workaround today: rebuild with <code>--force</code>. Fix: fold the city's research into the stamp.</li>
    </ul>

    <h3>Tier 2 — latent footgun</h3>
    <ul>
        <li><strong>P2 · registry clobber + granite drift</strong> <em>(shared / masters)</em> — <strong>✅ fixed.</strong> Compile is now <strong>non-destructive</strong>: <code>ms_ai_merge_registry()</code> preserves hand-authored (unstamped) block types and never overwrites or deletes an entry it didn't create (ownership keyed on <code>_compiled_from</code>). It still (re)compiles enabled archetypes and drops disabled ones. The Block Type Registry editor now shows a banner on compiled blocks warning that edits are overwritten on recompile. <strong>The granite landmine is defused</strong> — all its hand-authored block types survive a compile — so the "don't Save &amp; Compile on granite" guard is lifted. (Optional tidy: uncheck the two archetypes in granite's brief that don't match its registry, so a compile doesn't add unused entries.)</li>
    </ul>

    <h3>Tier 3 — hygiene / polish</h3>
    <ul>
        <li><strong>P3 · model config drift</strong> <em>(shared)</em> — <strong>✅ fixed.</strong> One shared catalog (<code>includes/models.json</code>) is now the single source of truth, read by both PHP (<code>includes/models.php</code>: <code>model_options()</code> / <code>model_is_valid()</code> / <code>model_or_default()</code>) and Python (<code>generate.py</code> derives <code>MODEL_PRICING</code> + default from it). All five validation whitelists and three editor dropdowns reference it; the pricing table is complete (Sonnet 5 + Opus were missing, and Haiku's rate was stale — costs were under-reported); models standardized on <code>haiku-4-5</code> / <code>sonnet-5</code> / <code>opus-4-8</code> (legacy <code>sonnet-4-6</code> migrated everywhere). Add or drop a model in one JSON file and every editor, validator, and the cost table update together.</li>
        <li><strong>P4 · <code>{SS}</code> leaks</strong> <em>(shared, narrow)</em> — <strong>✅ fixed.</strong> <code>substitute_vars</code> now allows uppercase, so <code>{SS}</code> resolves in AI/research prompts (render-side already handled it). Unknown ALLCAPS tokens still pass through untouched.</li>
        <li><strong>P5 · carve-out order</strong> <em>(shared, cosmetic)</em> — <strong>✅ fixed.</strong> Moved the neighborhoods carve-out <em>after</em> the shared guardrail in the <code>local_relevance</code> skeleton (read last = authoritative). Haiku now weaves 2–3 real names on 5/5 test runs (was 4/5).</li>
    </ul>
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

<section id="ai-concurrency">
    <h2>Concurrent generation (workers)</h2>
    <p>Landing-page generation runs <strong>in parallel</strong>. Since every city landing page is independent — each owns its own JSON file and shares nothing mutable with the others — <code>generate.py</code> spreads them across a pool of worker threads instead of processing one page at a time. On a large site this is the difference between roughly an hour and under ten minutes; the API cost is unchanged (the same blocks are generated either way).</p>

    <h3>The <code>--workers</code> flag</h3>
    <ul>
        <li><code>--workers N</code> — number of landing pages generated at once. <strong>Default 8.</strong></li>
        <li><code>--workers 1</code> — old sequential behaviour (one page at a time). Use it if you ever need to read the raw log linearly or suspect a concurrency issue.</li>
        <li>Concurrency only kicks in with more than one page in scope; a single-page run is effectively sequential regardless of the flag.</li>
        <li>Blocks <em>within</em> a single page still generate one after another (~30s/page). Only pages run in parallel — that is the layer that scales.</li>
    </ul>
    <div class="callout"><strong>Why this is safe.</strong> Pages never write to the same file, so there is no output contention. The three shared resources are explicitly locked: token/cost accounting (<code>_usage_lock</code>), the progress counter (<code>_progress_lock</code>, so the live bar stays monotonic), and the Anthropic client (<code>_client_lock</code>, created once and reused across threads). Transient/429 errors are retried with backoff inside each worker, so one flaky call doesn't fail the batch.</div>

    <h3>Live progress in the AI Generation tab</h3>
    <p>When a run starts, the panel shows two things at once:</p>
    <ul>
        <li>A <strong>global bar</strong> — <em>Block X of Y</em> across the whole run, with a remaining count.</li>
        <li>A <strong>grid of per-worker bars</strong> — one small bar per worker slot, each showing the page that worker is currently building and its block progress within that page. Bars appear as soon as the run reports how many workers it launched, update as each block finishes, and clear when the run ends.</li>
    </ul>
    <p>This is driven by a small stdout protocol that <code>generate.py</code> prints and <code>admin/ai_generate.php</code> translates into a live NDJSON event stream for the browser:</p>
    <table>
        <tr><th>Line printed by generate.py</th><th>UI event</th><th>Drives</th></tr>
        <tr><td><code>__WORKERS__ N</code></td><td><code>workers_init</code></td><td>How many per-worker bars to draw</td></tr>
        <tr><td><code>__PROGRESS__ done/total</code></td><td><code>progress</code></td><td>The global bar</td></tr>
        <tr><td><code>__WBAR__ slot done total page</code></td><td><code>worker</code></td><td>One worker's current page + block progress</td></tr>
    </table>
    <p>Each worker's slot number (0…N-1) is derived from its thread name, and a thread-local per-block callback fires once per completed block — advancing both that worker's own bar and the global bar. Nothing extra to configure: open the AI Generation tab, run a multi-page scope, and the worker grid appears automatically.</p>
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
        <li>City shortcodes (<code>{city}</code>, <code>{SS}</code>) on the <strong>homepage</strong> resolve to the site's <em>primary</em> city — fine for a single-city business, but wrong on a national / multi-city homepage</li>
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

<section id="cities-differentiation">
    <h2>City Pages — Per-city differentiation</h2>
    <p>A single site generating 40 city pages faces the same intra-site <strong>"doorway cluster"</strong> risk as a multisite network — the pages carry the same template structure, the same photos, and (before AI) the same copy. Two opt-in toggles in the <strong>City Pages</strong> tab's Generate controls let single-site city pages differentiate the structural and image signals; the existing per-city <a href="#ai-standalone">archetype AI</a> already handles content. Together they cover all three: <strong>content</strong> (AI, already), <strong>images</strong> (new), and <strong>structure</strong> (new).</p>

    <h3>Per-city images</h3>
    <p>A <strong>"Per-city images"</strong> dropdown with three modes:</p>
    <ul>
        <li><strong>Off</strong> — every city page uses the template's images unchanged (fastest; 40 pages sharing identical photos reads as thin).</li>
        <li><strong>Hero text overlay</strong> — bakes the page keyword + <code>"City, ST"</code> onto the hero image, so each city page ships a genuinely different, on-topic hero (one generated hero per city).</li>
        <li><strong>Full</strong> — the hero overlay <em>plus</em> a byte-perturbed, city-renamed copy of every content photo, so no two city pages share an image file (beats exact <em>and</em> perceptual duplicate detection). Adds the most images to <code>uploads/</code>.</li>
    </ul>
    <p>It reuses the shared multisite image core <code>ms_process_blocks_images()</code> — the same function the multisite build uses. It is <strong>non-destructive</strong>: it adds city-named variants alongside the originals and never prunes or deletes. It runs as the admin (www-data) user into <code>sites/{id}/uploads/</code>, is <strong>opt-in</strong> and <strong>deterministic per city</strong> (same city → same files → SEO-stable and reproducible), and <strong>no-ops</strong> on Dry Run, without ImageMagick, and for the multisite build (which does its own image pass). Requires ImageMagick.</p>
    <p>The hero overlay <strong>style</strong> (text position, size, colors) is set and locked in the <a href="playground.php#hero-overlay">Test Lab</a> (Docs → 🧪 Test Lab → hero-overlay panel); the <strong>"tune hero style ↗"</strong> link next to the dropdown opens it. Sensible defaults work without any tuning.</p>

    <h3>Real per-city scenic photo — the City Image plugin</h3>
    <p>Distinct from the overlay/perturb differentiation above, the <strong>City Image</strong> plugin (Plugins tab) sources a genuine scenic photo of the site's city from the Wikipedia/Wikimedia API, self-hosts it as webp, and derives an SEO <em>alt</em> string plus a CC credit line. It exposes them as render-time tokens — <code>{city_image}</code> (drop into any photo field, e.g. a <code>map_info</code> block), <code>{city_image_alt}</code>, <code>{city_image_credit}</code> — plus a <code>[city_image]</code> shortcode that renders a captioned <code>&lt;figure&gt;</code> inside a Custom HTML block. Values are written into <code>site_vars</code> by the fetch step (the plugin's admin panel, <code>plugins/city-image/cli.php</code>, or the MultiSite generator); the tokens are contributed through a <code>shortcode_tokens</code> filter hook, so they resolve everywhere the standard city tokens do. Before anything is fetched, the tokens resolve to empty and <code>[city_image]</code> renders nothing.</p>

    <h3>Vary block order per city</h3>
    <p>A <strong>"Vary block order per city"</strong> checkbox gives each city page a slightly different <strong>section order</strong> — the hero stays pinned first, the closing block stays pinned last, and a couple of middle sections swap — so the pages aren't structurally identical (an intra-site template-footprint signal). Same blocks, same content, different sequence.</p>
    <p>It reuses the shared <code>ms_variant()</code> + <code>layout_generate_variants()</code> + <code>layout_apply()</code> helpers — the same ones the multisite build uses per domain, here keyed per city (salt <code>citylayout</code>, index 0 = natural order). It is <strong>deterministic per city</strong> and <strong>opt-in</strong>, and <strong>no-ops</strong> on Dry Run, for templates with fewer than 4 blocks, and for the multisite build.</p>

    <h3>Idempotent hero overlay (efficiency)</h3>
    <p>The hero overlay's output filename now carries a short hash of its render inputs (keyword + city + style), so re-running Generate is a <strong>cache hit</strong> (skips ImageMagick) unless the keyword, city, or style changed — in which case it regenerates. This makes frequent single-site regenerations cheap, and benefits the multisite build too.</p>
    <p><strong>Idempotent content photos too.</strong> In <em>Full</em> mode the shared core records each differentiated field's original path as <code>_&lt;field&gt;_orig</code> and always re-derives the city variant from that recorded original — never from the already-perturbed copy. So a re-run is non-destructive and idempotent: perturbations never compound and the hero never burns text over text; a city always resolves to the same variant of the same source image. The MultiSite build strips <code>_orig</code> before its prune (<code>ms_unset_orig_keys()</code>), keeping its output byte-identical to before; the single-site path keeps <code>_orig</code> so a persistent store re-differentiates cleanly. (<code>ms_process_blocks_images()</code>, <code>includes/multisite/image_overlay.php</code>.)</p>

    <div class="callout tip"><strong>One shared core, two callers.</strong> Single-site city generation reuses the multisite differentiation <em>cores</em> (<code>ms_process_blocks_images</code> and the <code>ms_variant</code> / layout helpers) but <strong>never</strong> the multisite-only destructive parts — the orchestrator <code>ms_differentiate_site_images()</code> or <code>ms_prune_unreferenced_uploads()</code>, which delete uploads. The non-destructive core is shared; the pruning is not.</div>
    <p style="color:#64748b;font-size:.9rem;">Backing files: <code>includes/generation/engine.php</code> (the per-city hook in <code>generate_city_pages</code>), <code>admin/generate.php</code> (reads the <code>image_diff</code> and <code>vary_layout</code> options), <code>admin/tabs/citypages.php</code> (the dropdown + checkbox), reusing <code>includes/multisite/image_overlay.php</code> and <code>includes/layout_variations.php</code>.</p>
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
        <p><strong>Homepage caution.</strong> <code>{city}</code> / <code>{city_state}</code> resolve to the site's <em>primary</em> city (from Site Variables) — correct for a single-city local business, but on a national / multi-city homepage they'd stamp just one city. They don't render literally; that only happens if site_vars hasn't been saved.</p>
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

<section id="howto-plugin">
    <h2>How To: Add a Plugin (hooks &amp; custom tokens)</h2>
    <p>A plugin is a folder in <code>plugins/{id}/</code>, auto-loaded at startup (<code>includes/functions.php</code> globs <code>plugins/*/plugin.php</code>). Its <code>plugin.php</code> registers the plugin and attaches to the system through hooks — no core edits required.</p>
    <h3>Register</h3>
    <pre><code>// plugins/my_id/plugin.php
register_plugin('my_id', 'My Plugin', 'One-line description.', '&amp;#128268;', __DIR__);</code></pre>
    <p>That makes it appear as a card on the <a href="#tab-plugins">Plugins</a> tab. Add <code>panel.php</code> (admin settings UI) and <code>save.php</code> (POST handler — dispatched through <code>admin/plugin_save.php</code>, which verifies auth + CSRF first) if the plugin needs configuration.</p>
    <h3>Hooks (<code>includes/hooks.php</code>)</h3>
    <p>From <code>plugin.php</code>, attach listeners with <code>add_hook('name', $fn, $priority = 10)</code>. The core fires them either as <em>actions</em> (<code>run_hook</code>) or <em>filters</em> (<code>filter_hook</code> — each listener receives a value and returns a transformed one). The hooks a plugin can use:</p>
    <table>
        <tr><th>Hook</th><th>Type / signature</th><th>Use</th></tr>
        <tr><td><code>shortcode_content</code></td><td>filter <code>(string $html, string $pathPrefix)</code></td><td>Replace a bracket shortcode inside a Custom HTML block with rendered HTML — e.g. <code>[services_links]</code>, <code>[city_image]</code>.</td></tr>
        <tr><td><code>shortcode_tokens</code></td><td>filter <code>(array $map)</code></td><td>Contribute render-time <code>{token}</code> values. Return the map with your keys added; they resolve everywhere <code>resolve_shortcodes()</code> runs — titles, schema, block fields.</td></tr>
        <tr><td><code>head_styles</code></td><td>action <code>(string $pathPrefix)</code></td><td>Emit extra CSS or markup into the page <code>&lt;head&gt;</code>.</td></tr>
    </table>
    <h3>Example — a plugin that adds a token</h3>
    <pre><code>add_hook('shortcode_tokens', function (array $map): array {
    global $data;
    $map['{my_token}'] = $data['site_vars']['my_value'] ?? '';
    return $map;
});</code></pre>
    <p>The <strong>City Image</strong> plugin is the canonical example: it registers <code>{city_image}</code> / <code>{city_image_alt}</code> / <code>{city_image_credit}</code> via <code>shortcode_tokens</code> and a <code>[city_image]</code> renderer via <code>shortcode_content</code> (<code>plugins/city-image/plugin.php</code>). For performance, <code>resolve_shortcodes()</code> only invokes the <code>shortcode_tokens</code> filter when at least one listener is registered — so the hot path costs nothing when no plugin uses it.</p>
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
<p class="page-intro">The <strong>MultiSite Generator</strong> turns one master site into 100+ separate, fully independent websites — one per city — each with its own domain, business identity, AI-written local content, and FTP host. This is different from the in-site City Pages system (which makes many <em>pages</em> inside <em>one</em> site); the MultiSite Generator operates one level up and produces whole <em>sites</em>.</p>

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
[ campaign ]  lock → validate → pre-flight → snapshot master ONCE → per-row loop → teardown
        ↓
[ per row ]   clone → inject → differentiate → visual identity → AI-generate → build → deploy → delete temp
        ↓
100+ live, independent single-city sites</code></pre>

    <p>There are two levels: the <strong>campaign</strong> (<code>run_campaign.php</code>) runs the whole table once; the <strong>per-row pipeline</strong> (<code>build_one.php</code>) turns one row into one deployed site. The campaign wraps the pipeline in a loop.</p>

    <h3>Campaign level — the outer loop</h3>
    <ol>
        <li><strong>Start</strong> — acquire a single-run lock (<code>flock</code> on <code>run.lock</code>) so two campaigns can't run for the same master at once, across every launch path (admin, retry, manual CLI).</li>
        <li><strong>Read &amp; validate</strong> the <a href="#ms-columns">params CSV</a> — parse, drop invalid rows, apply <code>--only</code> / <code>--limit</code>.</li>
        <li><strong>Pre-flight</strong> — check FTP reachability for each row up front, so a bad credential fails fast instead of mid-build.</li>
        <li><strong>Snapshot the master once</strong> to a temp dir, shared by every row (<code>--snapshot=</code>). The original is frozen for the whole run — never re-read per row — so edits to the master mid-campaign can't corrupt output. This is why a big run stays cheap and internally consistent. See <a href="#ms-two-level-clone">Two-level cloning</a>.</li>
        <li><strong>Open the run log</strong> (<code>runs/{run_id}.json</code>, state <code>running</code>), written incrementally so the admin UI can poll a live run. This records <em>what happened</em> — status, files uploaded, tokens, cost per row.</li>
        <li><strong>Process the rows</strong> through a bounded process pool (<code>--jobs=N</code> at a time, each row a <code>build_one.php</code> subprocess, failed rows re-tried up to <code>--retries</code>). Each row runs the per-row pipeline below.</li>
        <li><strong>Teardown</strong> — delete the shared snapshot, write the final run log (<code>done</code> / <code>failed</code>) with the campaign cost summary.</li>
    </ol>
    <div class="callout tip"><strong>Reproducibility is the cache's job, not the run log's.</strong> A rerun produces the same output <em>for free</em> because the city-specific AI copy is frozen per domain and reused — see <a href="#ms-cache">The content cache</a>. The run log above only records what happened; <code>--force</code> is what busts the cache and pays for fresh AI.</div>

    <h3>Per-row pipeline — one row → one site</h3>
    <ol>
        <li><strong>Clone</strong> a cheap working copy from the one-time master snapshot.</li>
        <li><strong>Inject identity</strong> — write the row's business/phone/city/etc. into <code>site_vars</code>.</li>
        <li><strong>Differentiate</strong> — rewrite schema/URLs to this site, inject a LocalBusiness JSON-LD with geo, isolate analytics. See <a href="#ms-differentiation">Per-site differentiation</a>.</li>
        <li><strong>Visual identity</strong> — apply the row's <a href="#ms-visual-identity">Theme Preset</a> (colors + font + radius) and generate a per-site logo + favicon in those colors, replacing the master's wordmark. See <a href="#ms-visual-identity">Visual identity</a>.</li>
        <li><strong>AI-generate</strong> the city-specific copy, or reuse it from <a href="#ms-cache">the cache</a> — free.</li>
        <li><strong>Build</strong> the whole site to static HTML.</li>
        <li><strong>Deploy</strong> over FTP (only changed files), then delete the temp copy.</li>
    </ol>
</section>

<section id="ms-master-state">
    <h2>REQUIRED State of the SingleSite entering MultiSite</h2>
    <p>MultiSite doesn't build sites — it <strong>replicates</strong> one. Before you run a campaign, the master must already be a <strong>finished, single-city site</strong>, because every generated site is a copy of it. Concretely:</p>
    <ul>
        <li><strong>Every home &amp; core page already exists in the master.</strong> Homepage and all core pages are authored in the admin panel (in <code>site.json</code>), exactly like a normal single site — each with its own SEO and keyword focus.</li>
        <li><strong>MultiSite never authors new home or core pages.</strong> It takes the master's existing home/core pages, localizes them, and deploys — if a home/core page should exist on the generated sites, it must exist in the master first. It <em>does</em>, however, drop the master's pre-generated in-site city pages and — when the deploy row's <code>landing_cities</code> column is set — regenerate one service landing page per city from the master's landing template (via <code>generate_landing.php</code>).</li>
        <li><strong>The pipeline is clone → inject → (regenerate landing pages) → differentiate → AI-fill → build → deploy.</strong> No home/core page is authored during the run; only the master's existing pages are localized, plus any per-deploy city landing pages built from the master's landing template. (See <a href="#ms-howitworks">How it works</a>.)</li>
        <li><strong>Shortcodes are already throughout the master.</strong> Titles, headings, body copy, and schema use <code>{city}</code>, <code>{SS}</code>, <code>{business}</code>, <code>{primary_keyword}</code>, and the rest. "Adjust" is really just <em>setting this site's <code>site_vars</code></em> (city, business, phone, geo) — and every shortcode across the whole site then resolves to that city.</li>
        <li><strong>Titles come from each page's own SEO panel — the single source of truth.</strong> MultiSite doesn't assemble titles behind the scenes. A master page titled <code>{primary_keyword} {city_state} | {business}</code> renders as "Pest Control Dallas, TX | Dallas Pest Pros" on the Dallas clone. The <a href="#ms-admin-multisite">Title preview</a> card shows exactly what each clone will publish — verify it there before running.</li>
        <li><strong>The keyword is one field.</strong> Each page's <em>Keyword focus → Primary keyword</em> feeds <code>{primary_keyword}</code> into the title, H1, and schema, so the keyword stays consistent and can't drift.</li>
        <li><strong>Per-city AI copy fills only the marked blocks.</strong> Blocks tagged as <code>ai_block</code> are (re)written per city during the run; everything else clones verbatim and localizes through shortcodes. (See <a href="#ms-aiblocks">AI blocks &amp; the engine</a>.)</li>
        <li><strong>A landing template exists in the master</strong> (only needed if you'll use <code>landing_cities</code>). Per-deploy city landing pages are rendered from the master's reusable landing template (<code>data/templates.json</code>) — one page per listed city — not authored one-by-one. The master's own in-site <code>data/pages/</code> are dropped and rebuilt fresh per deploy; home + core come from <code>site.json</code>. (See <a href="#ms-landing">Per-deploy landing pages</a> and <a href="#ms-vs-insite">MultiSite vs. in-site City Pages</a>.)</li>
    </ul>
    <div class="callout"><strong>Pre-flight lint.</strong> A master lint (<code>ms_lint_master()</code>, run from the MultiSite tab) checks this state before a campaign: it flags the master's own city / state / SS / zip typed as <em>literal text</em> (they should be <code>{city}</code> / <code>{state}</code> / <code>{SS}</code> / <code>{zip}</code> shortcodes) and master-domain URLs that carry a path (the domain is rewritten per clone, but the path won't survive). It deliberately does <em>not</em> flag <code>site_vars</code>, <code>local_business</code>, the business name, phone, website, email, or image filenames — those are overwritten or renamed automatically per clone.</div>
    <div class="callout tip"><strong>Rule of thumb:</strong> get the master perfect as one finished single-city site — real home/core pages, a landing template if you'll serve nearby cities, shortcodes everywhere, a keyword set per page — then MultiSite makes localized copies of it. Nothing appears on a generated site that the master doesn't already define.</div>
</section>

<section id="ms-vs-insite">
    <h2>MultiSite vs. the in-site City Pages</h2>
    <table>
        <tr><th></th><th>City Pages (in-site)</th><th>MultiSite Generator</th></tr>
        <tr><td>Produces</td><td>Many <em>pages</em> in one site</td><td>Many separate <em>sites</em></td></tr>
        <tr><td>Domains</td><td>One shared domain</td><td>One domain per site</td></tr>
        <tr><td>URLs</td><td><code>/pmp-training-dallas</code></td><td><code>pmtraining-dallas.com</code></td></tr>
        <tr><td>Lives in</td><td><code>data/pages/*.json</code></td><td>Ephemeral — nothing stored per site</td></tr>
    </table>
    <p>A MultiSite output serves one home/core city; the clone step <strong>drops</strong> the master's in-site <code>data/pages/</code> and rebuilds them fresh — home + core from <code>site.json</code>, plus any per-deploy city landing pages generated from the master's landing template (the deploy's <code>landing_cities</code> column). See <a href="#ms-landing">Per-deploy landing pages</a>.</p>
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
        <tr><td><code>gsc_verification</code></td><td>Optional</td><td>Per-site Google Search Console verification meta token (blank = none)</td></tr>
        <tr><td><code>web3forms_key</code></td><td>Optional</td><td>Per-site contact-form (Web3Forms) access key</td></tr>
        <tr><td><code>theme_preset</code></td><td>Optional</td><td>Which <a href="#ms-visual-identity">Theme Preset</a> (colors + font + logo) to apply — id, name, or 1-based index; blank = deterministic hash rotation off the domain</td></tr>
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
<section>
    <p class="callout" style="margin-top:0;"><strong>The big picture:</strong> the AI engine, registry, and render path are <em>shared</em> with single-site — for the unified explanation of how Content AI works and how the single-site and multisite flows compare, see <a href="#ai-overview">AI System → Overview</a> (and <a href="#ai-multi">↳ MultiSite flow</a>). The sections below cover the multisite-specific mechanics: the compiled registry, per-domain cache, and reproducibility.</p>
</section>
<section id="ms-niche">
    <h2>Niche Brief &amp; archetypes</h2>
    <p class="callout" style="margin-top:0;"><strong>Same brief as the single-site workflow.</strong> The Niche Brief is not a multisite-only concept — it's the same file, tab, and compile step described in <a href="#ai-niche">AI System → The Niche Brief</a>, where one site publishes many city landing pages. MultiSite simply <em>reuses it verbatim</em>: instead of many pages on one domain, the master (brief + registry + templates) is cloned into many separate single-city sites. Authoring happens once; read this section for the multisite-specific mechanics.</p>
    <p>Each master site is <strong>one niche</strong> (pest control, PM training, lawyers, …). The AI copy for that niche is defined in two layers:</p>
    <ul>
        <li><strong>Shared archetypes</strong> (<code>multisite/ai/archetypes.json</code>) — a seed-once, read-only library of block <em>shapes</em> (e.g. <code>city_market_intro</code>, <code>hero_subtext</code>, <code>faq_additions</code>), each with a prompt skeleton, a render mode (<code>standalone</code> vs <code>inject</code>), and shared accuracy guardrails. Not niche-specific.</li>
        <li><strong>The Niche Brief</strong> (<code>sites/{master}/multisite/niche_brief.json</code>) — this master's vocabulary: <code>service_noun</code>, <code>business_descriptor</code>, <code>customer_noun</code>, <code>local_angle</code>, <code>offerings</code>, <code>tone</code>, niche <code>guardrails</code>, and which archetypes are enabled. Edited in the admin <strong>Niche Brief</strong> tab.</li>
    </ul>
    <p><strong>Compiling</strong> merges the two — filling each enabled archetype's <code>[[shared.*]]</code> and <code>[[brief.*]]</code> placeholders — and overwrites the master's <code>data/ai_block_types.json</code> (the prompt registry <code>generate.py</code> reads). Run it from the tab (<em>Save &amp; Compile</em>) or the CLI:</p>
    <pre><code>php multisite/ai/compile.php --master=&lt;master_id&gt;</code></pre>
    <p>Archetypes flagged <code>requires_research</code> are skipped unless the brief has <strong>Uses research fields</strong> on (for data-rich niches whose <code>cities.json</code> carries industries/employers/salary). Fill that data with the <a href="#ai-research">research step</a> — the <em>Research cities</em> card on the MultiSite tab seeds <code>cities.json</code> from the params table and looks up each city. Runtime tokens like <code>{business}</code>/<code>{city}</code> are left intact for <code>generate.py</code> to resolve per city.</p>
    <h3>Per-master prompt overrides</h3>
    <p>The shared archetype library used to be editable only by hand-editing files + a CLI compile. Now the <strong>Niche Brief</strong> tab has a collapsible <em>"Prompt templates (archetypes) — advanced"</em> panel that edits, <strong>per master</strong>, each archetype's <strong>Label / Model / Description / Prompt skeleton</strong>. Overrides are stored as <strong>diffs against the shared library</strong> in <code>sites/{master}/multisite/archetypes.json</code>; at compile time <code>multisite/ai/compile.php</code> (<code>ms_ai_compile_master</code>) merges each override over the shared <code>multisite/ai/archetypes.json</code> before filling placeholders — so per-master prompt tuning <strong>survives recompiling and never affects another niche</strong>. Only <code>label</code> / <code>description</code> / <code>ai_model</code> / <code>prompt_skeleton</code> are overridable; structural fields stay shared. A <em>Reset to shared default</em> control per archetype drops the override. Files: <code>admin/tabs/niche_brief_archetypes.php</code>, <code>admin/archetypes_save.php</code>. This closes the last file-only multisite config.</p>
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
    <p>AI copy is generated <strong>once per site</strong> and frozen in <code>multisite/cache/{domain}.json</code>. On every rebuild the cached copy is offered back and <code>generate.py</code> reuses it — <strong>zero API calls</strong>, identical output (SEO-stable copy, free redeploys) — as long as the inputs haven't changed. Each entry is keyed by a stable block <code>id</code> (home/core) or page-file + type + occurrence (landing), and stamped with an <strong><code>input_hash</code> = hash(resolved prompt + model)</strong>.</p>
    <p><strong>Staleness is owned by the resolver, not the cache.</strong> The stamp hashes the <em>fully resolved</em> prompt — the actual string sent to the model, already carrying business / city / service / keyword / gated-neighborhoods / industries. <code>generate.py</code> re-resolves each block and compares; if the hash still matches it reuses the cached copy, otherwise it regenerates just that block. So the cache invalidates automatically on <strong>any</strong> input change, with no hand-maintained list of "which fields matter". The cache does no hashing itself.</p>
    <p>The cache is self-healing:</p>
    <ul>
        <li>Missing entry → generate it.</li>
        <li>Prompt, model, <strong>or research/data changed</strong> (resolved-prompt hash mismatch) → regenerate just that block.</li>
        <li>Block removed → orphaned entry ignored.</li>
    </ul>
    <div class="callout warn">One-time cost: this changed the stamp format, so the first rebuild after upgrading re-generates every domain's blocks once (old <code>prompt_hash</code> entries won't match the new <code>input_hash</code>), then it's free again.</div>
    <p>A first (cold) build of ~4–8 blocks costs roughly <strong>$0.02–0.05</strong>; every rebuild after is free.</p>
</section>

<section id="ms-repro">
    <h2>Reproducibility — running a campaign twice</h2>
    <p>Run the same campaign again and you get the <strong>same sites</strong> — because the per-domain <a href="#ms-cache">content cache</a> freezes the AI copy, <em>not</em> because the model is deterministic (it isn't). On a rerun every cached block is offered back and <code>generate.py</code> reuses it (the resolved-prompt hash still matches), so it makes zero calls and the copy is identical. The build path has no random IDs or filenames, and the sitemap's <code>&lt;lastmod&gt;</code> no longer stamps the build date (it uses the site's own <code>last_modified</code>, or is omitted), so the deployed files are byte-stable and an incremental deploy re-uploads nothing.</p>
    <div class="callout warn"><strong>Reproducibility depends on the cache surviving.</strong> The frozen copy lives at <code>sites/{master}/multisite/cache/{domain}.json</code> — gitignored, so it exists only on the box you run from. It breaks if you:
        <ul style="margin:6px 0 0;">
            <li>pass <code>--force</code> — busts the cache, AI reruns, different copy;</li>
            <li>delete the cache or run on a <strong>fresh machine</strong> without it — AI reruns;</li>
            <li>edit a block's AI prompt, change its model, or change a city's research/data — its resolved-prompt <code>input_hash</code> changes, so just that block regenerates.</li>
        </ul>
        To guarantee identical results across machines, <strong>back up the cache directory</strong> — it's the source of truth for the frozen copy, and the AI can't be re-derived identically without it.</div>
    <p>The <a href="#ms-variation">variation items</a> (block order, schema, CSS, copy templates) are keyed by <code>crc32(domain)</code>, so they preserve this once built — the same domain always selects the same variant on every rebuild.</p>
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
<div class="doc-group-header" id="ms-seo">Site Differentiation &amp; SEO</div>
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
    <div class="callout tip">The non-destructive image and layout differentiation <em>cores</em> here (<code>ms_process_blocks_images</code>, the <code>ms_variant</code> / layout helpers) are now shared with single-site city generation — see <a href="#cities-differentiation">City Pages — Per-city differentiation</a>. The multisite-only destructive parts (the image orchestrator + the unreferenced-uploads prune) are not. The image core is <strong>original-tracking</strong>: every differentiated field records a sibling <code>_&lt;field&gt;_orig</code> and always re-derives from that recorded original, so runs are idempotent (no compounding perturbation, no hero text-over-text). The MultiSite build strips <code>_orig</code> before its prune (<code>ms_unset_orig_keys</code>), keeping build output byte-identical.</div>
</section>

<section id="ms-visual-identity">
    <h2>Visual identity — Theme Presets, logo, favicon</h2>
    <p>A clone otherwise carries the master's <em>look</em> — same colors, same font, and (worst) the master's <strong>wordmark logo</strong> baked into pixels — onto every site. The <strong>visual-identity step</strong> gives each site a coordinated, distinct visual brand: a color/font <em>Theme Preset</em>, plus a generated logo and favicon in those colors. It runs in <code>build_one.php</code> right after <a href="#ms-differentiation">differentiation</a> and before the <a href="#spec-image-assign">4c</a> image prune (<code>includes/multisite/visual.php</code>).</p>

    <h3>Theme Presets</h3>
    <p>A <strong>Theme Preset</strong> is a named bundle of theme values — accent + highlight colors, brand/heading fonts, button radius, header/footer colors, and a <strong>bug icon</strong> (the mark used to build the logo). Presets are stored <strong>per master</strong> under the <code>presets</code> key of <code>sites/{master}/multisite/theme_presets.json</code> (up to <strong>10</strong>; ~6 recommended). Each entry is <code>{ name, note, icon, theme:{accent_color, header_bg, footer_bg, heading_color, header_text, footer_text, header_top_bg, primary_font, heading_font, button_radius}, header:{nav_bg} }</code>.</p>
    <p>The pest master ships four:</p>
    <table>
        <tr><th>Preset</th><th>Colors</th><th>Font · radius</th><th>Bug</th></tr>
        <tr><td><strong>Classic</strong></td><td>indigo <code>#120575</code> + orange <code>#fd783b</code></td><td>Inclusive Sans · 5</td><td>cockroach</td></tr>
        <tr><td><strong>Bold</strong></td><td>charcoal <code>#1f2937</code> + red <code>#dc2626</code></td><td>Inter · 2</td><td>ant</td></tr>
        <tr><td><strong>Fresh</strong></td><td>forest <code>#14532d</code> + amber <code>#f59e0b</code></td><td>Nunito · 24</td><td>spider</td></tr>
        <tr><td><strong>Trust</strong></td><td>teal-navy <code>#0f3d5c</code> + teal <code>#0d9488</code></td><td>Poppins · 10</td><td>mosquito</td></tr>
    </table>
    <p><strong>Assignment.</strong> The params <code>theme_preset</code> column names a preset (id or name) per row. <strong>Blank</strong> = a deterministic hash rotation off the domain (<code>ms_variant</code>, salt <code>'theme'</code>), so every site still gets a distinct preset that's stable across rebuilds.</p>

    <h3>The generated logo</h3>
    <p>The step applies the chosen preset (merges <code>preset.theme</code> → <code>data['theme']</code> and <code>preset.header</code> → <code>data['header']</code>), then <strong>generates a logo per site</strong> — a bug mark (the preset's icon as an accent-colored silhouette on a dark rounded tile) to the left of a two-tone wordmark built from <code>{business}</code>: the first word in the accent color on line 1, the remaining words in the dark brand color on line 2, left-justified (DejaVu Sans Bold) — the same lockup shape as the master's "KATY / PEST PROS." It's written to <code>uploads/</code> and set as <code>header.logo</code>. Colors come from the applied theme (accent = <code>accent_color</code>; dark = <code>heading_color</code> → <code>footer_bg</code> → <code>header_bg</code> fallback).</p>
    <p>This <strong>replaces the master's baked-in wordmark per site</strong>, fixing the "KATY PEST PROS" identity leak: the master's logo file goes unreferenced and the 4c prune removes it. Verified end-to-end. See <a href="#spec-logo">4b</a>.</p>

    <h3>The generated favicon</h3>
    <p>The same bug tile is rendered at 128px and set as <code>header.favicon</code> — so no two sites ship a byte-identical favicon. See <a href="#spec-favicon">4a</a>.</p>

    <h3>Bug icons</h3>
    <p>19 bug SVGs live in <code>sites/{master}/multisite/icons/</code>: ant, cockroach, spider, mosquito, fly, beetle, ladybug, cricket, bee, caterpillar, butterfly, scorpion, worm, snail, rat, mouse, bat, lizard, snake. Source is <strong>Noto Emoji (Apache 2.0)</strong> — no attribution required; codepoints are recorded in <code>icons/LICENSE.txt</code>. Each colorizes to a preset's accent-on-dark-tile for the logo and favicon.</p>

    <h3>Editing presets in the admin</h3>
    <p>The MultiSite tab has a <strong>"Visual Identity — Theme Presets"</strong> panel to view / edit / add / remove / save presets (up to 10) with <strong>live logo + favicon previews</strong>. Per preset: name, accent + dark color pickers, bug-icon dropdown, font, and button radius. Files: <code>admin/tabs/multisite_visual.php</code> (panel), <code>admin/visual_preview.php</code> (streams the live preview PNG), <code>admin/visual_presets_save.php</code> (CSRF save).</p>
    <div class="callout tip">Preview a preset, its four sample logos, real headers, favicons and the bug icons in the <a href="playground.php">Test Lab</a> (docs nav → 🧪 Test Lab).</div>
</section>

<section id="ms-axes">
    <h2>Differentiation axes &amp; status</h2>
    <p>Generating many same-topic city sites is the pattern Google's <em>doorway pages</em> and <em>scaled content abuse</em> policies target. Cosmetic differences don't satisfy them — Google evaluates substance. The six areas below run from highest SEO impact down: <strong>do the top well before spending time on the bottom.</strong> Areas 1–4 are <strong>what the generator produces</strong> per site (ordered by impact); areas 5–6 are <strong>local presence and operational infrastructure</strong> that mostly live outside the tool. Each area is both a status snapshot and the build backlog. Every per-site output must be <strong>deterministic per domain</strong> — stable across rebuilds, so SEO signals don't churn.</p>

    <h3>1 · Content <span style="font-weight:400;color:#64748b;font-size:.85em;">(highest impact)</span></h3>
    <ol type="a">
        <li><span class="where perrow">Per-row</span>✅ Unique AI city copy on home + core (Niche Brief → <code>generate.py</code>) <span class="pri must">Must</span></li>
        <li><span class="where perrow">Per-row</span>✅ Per-deploy service landing pages (<code>landing_cities</code>) <span class="pri should">Should</span></li>
        <li><span class="where perrow">Per-row</span>✅ Unique title tag + meta description per site/page — the page's own title uses a <code>{primary_keyword}</code> shortcode + <code>{city_state}</code>/<code>{business}</code>; city + business make each site's title unique &amp; keyword-focused (no rotation needed). Read-only preview on the MultiSite tab. <span class="pri must">Must</span></li>
        <li><span class="where perrow">Per-row</span>◐ Localized, unique image alt text — per-city alt strings, not one repeated caption <span class="pri should">Should</span> <span style="color:#64748b;">(render mechanism built — <code>*_alt</code> fields resolve shortcodes; needs authoring + fallback)</span></li>
        <li><span class="where campaign">Campaign</span>✅ Real local market data — niche-defined facts per city <span class="pri should">Should</span> <span style="color:#64748b;">(editable <code>research_prompt</code> in the Niche Brief; <em>Research cities</em> button on the MultiSite tab seeds <code>cities.json</code> from params + looks up each city via <code>claude-sonnet-5</code>, cached &amp; reused free)</span></li>
        <li><span class="where preauthor">Pre-authoring</span>✅ Finish master authoring — <span style="color:#64748b;"><strong>reclassified: not a build item.</strong> The "leaks" are single-site authoring errors, caught by the <a href="#spec-finish-authoring">master-lint guardrail</a>; the residual is normal authoring QA, not multisite work.</span></li>
        <li><span class="where preauthor">Pre-authoring</span>◐ Vary generated-copy templates — rotate 3–4 sentence-structure patterns per block, not one fill-in-the-blank line (AI copy is already unique; this hardens any templated fallback) <span class="pri must">Must</span> <span style="color:#64748b;">(see <a href="#ms-variation">Deterministic variation</a>)</span></li>
    </ol>

    <h3>2 · Structural <span style="font-weight:400;color:#64748b;font-size:.85em;">(highest-value unbuilt item)</span></h3>
    <ol type="a">
        <li><span class="where preauthor">Pre-authoring</span>✅ Block-order / layout variations per domain — per-page <em>Layout variations</em> panel (Content/Pages): Generate 4 subtle orderings (hero + last pinned), enable + save; each cloned site gets one by domain hash. <span class="pri must">Must</span> <span style="color:#64748b;">(MultiSite preview shows which — see <a href="#ms-variation">Deterministic variation</a>)</span></li>
        <li><span class="where preauthor">Pre-authoring</span>⏭️ Vary JSON-LD schema shape — <strong>decided against</strong>: schema already varies per site (identity + injected LocalBusiness node), and JSON-LD key order is meaningless to Google — near-zero value. <span class="pri must">Must</span> <span style="color:#64748b;">(kept for the record)</span></li>
        <li><span class="where preauthor">Pre-authoring</span>☐ Vary CSS class vocabulary — 3–4 "skins": identical rules, different class names <span class="pri should">Should</span> <span style="color:#64748b;">(see <a href="#ms-variation">Deterministic variation</a>)</span></li>
        <li><span class="where perrow">Per-row</span>✅ Randomize image filename structure — site city appended + master city stripped on every image (folded into 4c) <span class="pri maybe">Maybe</span> <span style="color:#64748b;">(done)</span></li>
    </ol>

    <h3>3 · Identity &amp; SEO signals <span style="font-weight:400;color:#64748b;font-size:.85em;">(the SEO backbone — mostly automated)</span></h3>
    <ol type="a">
        <li><span class="where perrow">Per-row</span>✅ Business / phone / email / domain rewritten per site <span class="pri must">Must</span></li>
        <li><span class="where perrow">Per-row</span>✅ LocalBusiness JSON-LD with real address + geo <span class="pri must">Must</span> <span style="color:#64748b;">(needs <code>lat</code>/<code>lng</code>)</span></li>
        <li><span class="where perrow">Per-row</span>✅ Real ratings only — <code>rating</code> + <code>review_count</code>, paired, never invented <span class="pri must">Must</span></li>
        <li><span class="where perrow">Per-row</span>✅ Self-referential canonical per domain <span class="pri must">Must</span></li>
        <li><span class="where perrow">Per-row</span>✅ Per-site <code>sitemap.xml</code> + <code>robots.txt</code> — written for each domain at build by <code>build_static_site()</code> (from the required <code>domain</code>; <code>deploy_site()</code> only uploads them) <span class="pri must">Must</span></li>
        <li><span class="where perrow">Per-row</span>✅ Per-site analytics — <strong>never share one across sites</strong> (the big DON'T). Each site gets its own GA4 tag from <code>analytics_id</code>. <span style="color:#64748b;">◐ distinct GTM container IDs not yet emitted</span> <span class="pri must">Must</span></li>
        <li><span class="where perrow">Per-row</span>✅ Unique Search Console verification per site — per-site <code>&lt;meta google-site-verification&gt;</code> from the <code>gsc_verification</code> CSV column (blank = none); never verify every site through one shared GTM property <span class="pri should">Should</span></li>
        <li><span class="where perrow">Per-row</span>✅ No generator fingerprint emitted <span class="pri should">Should</span></li>
    </ol>

    <h3>4 · Visual <span style="font-weight:400;color:#64748b;font-size:.85em;">(most tempting, least SEO value — do last)</span></h3>
    <ol type="a">
        <li><span class="where perrow">Per-row</span>✅ Per-site favicon — generated per site (the <a href="#ms-visual-identity">Theme Preset</a>'s bug icon as a 128px colored tile), so no two sites ship a byte-identical favicon <span class="pri maybe">Maybe</span> <span style="color:#64748b;">(done — visual-identity step)</span></li>
        <li><span class="where perrow">Per-row</span>✅ Per-site logo — generated per site: bug mark + two-tone wordmark from <code>{business}</code> in the preset's colors, replacing the master's baked-in "KATY PEST PROS" wordmark (honors a <code>logo</code> column override) <span class="pri maybe">Maybe</span> <span style="color:#64748b;">(done — kills the master-logo identity leak)</span></li>
        <li><span class="where perrow">Per-row</span>✅ Per-site hero differentiation — keyword + "City, ST" baked onto each hero image (text overlay), so every site's hero is a genuinely different file <span class="pri should">Should</span> <span style="color:#64748b;">(style tuned/locked in the <a href="playground.php">Test Lab</a>; no image pool needed)</span></li>
        <li><span class="where perrow">Per-row</span>✅ Per-site theme colors — a curated <a href="#ms-visual-identity">Theme Preset</a> (palette + font + button radius) per site, by column or domain hash; also drives the logo/favicon <span class="pri maybe">Maybe</span> <span style="color:#64748b;">(done as Theme Presets — see <a href="#spec-theme-colors">4d</a>)</span></li>
    </ol>

    <h3>5 · Local &amp; off-site signals <span style="font-weight:400;color:#64748b;font-size:.85em;">(where local city sites win or lose)</span></h3>
    <p style="color:#64748b;font-size:.9em;margin:2px 0 6px;">For local search these often outweigh on-page tweaks. Mostly done outside the tool — Google Business Profile, directory citations, earned links; the embedded map is the one on-page piece.</p>
    <ol type="a">
        <li><span class="where operational">Operational</span>📋 Google Business Profile per city — real address, correct categories, photos, posts; the single biggest local ranking + distinct-entity signal <span class="pri must">Must</span></li>
        <li><span class="where operational">Operational</span>📋 Local citations / NAP consistency — same name / address / phone across directories (Yelp, BBB, industry lists) <span class="pri should">Should</span></li>
        <li><span class="where perrow">Per-row</span>✅ Embedded map + service-area on each site — homepage <code>map_info</code> with a shortcode-driven <code>q={city},+{SS}</code> embed, localized per city <span class="pri should">Should</span> <span style="color:#64748b;">(done on the pest master)</span></li>
        <li><span class="where operational">Operational</span>📋 Earn a distinct backlink profile — real local links per site; never a shared PBN / cross-site link network <span class="pri should">Should</span></li>
    </ol>

    <h3>6 · Site Hosting &amp; Footprint <span style="font-weight:400;color:#64748b;font-size:.85em;">(operational — outside the tool)</span></h3>
    <p style="color:#64748b;font-size:.9em;margin:2px 0 6px;">The generator can't do these — the operator sets them up at the registrar and host. They matter <em>because</em> you're mass-generating similar sites; genuine local substance (areas 1–2) is the real defense, this is insurance.</p>
    <ol type="a">
        <li><span class="where operational">Operational</span>📋 Domain registration diversity — vary registrars; don't bulk-buy every domain in one account <span class="pri should">Should</span></li>
        <li><span class="where operational">Operational</span>📋 Hosting / IP diversity — spread across hosts or IP ranges; avoid one C-class block of same-owner sites <span class="pri must">Must</span> <span style="color:#64748b;">(and keep origins behind <a href="#ms-hosting">Cloudflare's proxy</a> so a DNS lookup can't reveal the network)</span></li>
        <li><span class="where operational">Operational</span>📋 Cloudflare / CDN — a shared CF account is itself a footprint; isolate where it matters, and confirm every <code>A</code> record is <a href="#ms-hosting">proxied, not grey-cloud</a> <span class="pri must">Must</span></li>
        <li><span class="where operational">Operational</span>📋 WHOIS privacy on every domain <span class="pri should">Should</span></li>
        <li><span class="where operational">Operational</span>📋 Vary registrars / nameservers where practical — split across accounts (e.g. Namecheap / Porkbun / Cloudflare Registrar) once past ~20 sites; Cloudflare nameservers alone aren't a distinguishing signal <span class="pri maybe">Maybe</span></li>
        <li><span class="where operational">Operational</span>📋 No cross-site link hub / footer link network (the classic PBN tell) <span class="pri must">Must</span></li>
    </ol>

    <p style="color:#64748b;font-size:.9em;margin-top:6px;">✅ automated &nbsp;·&nbsp; ◐ partial / needs input &nbsp;·&nbsp; ☐ not built &nbsp;·&nbsp; 📋 operational</p>
    <p style="color:#64748b;font-size:.9em;margin-top:2px;"><strong>Where</strong> (right-hand pill) — at which moment the work happens: <span class="where preauthor" style="float:none;margin-left:0;">Pre-authoring</span> author into the master first &nbsp;·&nbsp; <span class="where campaign" style="float:none;margin-left:0;">Campaign</span> once-per-run prep &nbsp;·&nbsp; <span class="where perrow" style="float:none;margin-left:0;">Per-row</span> automatic per site in <code>build_one</code> &nbsp;·&nbsp; <span class="where operational" style="float:none;margin-left:0;">Operational</span> outside the tool</p>

    <div class="callout warn">The defensible path is genuine local presence per city (real address, phone, ideally a Google Business Profile). Build to maximize real distinctness regardless — it's what protects against penalties and actually serves users. Mechanisms: see <a href="#ms-differentiation">Per-site differentiation</a>, <a href="#ms-aiblocks">AI blocks &amp; the engine</a>, and <a href="#ms-landing">Per-deploy landing pages</a>.</div>
</section>

<section id="ms-specs">
    <h2>Differentiation build specs</h2>
    <p>One card per <a href="#ms-axes">axes</a> item — description, priority, where it executes, and how to build it (or how it works today, for ✅ items). Same six areas as the checklist; that list is the scan view, this is the build backlog.</p>

    <h3 id="ms-roadmap" style="margin-top:26px;border-top:2px solid #0f172a;padding-top:14px;color:#0f172a;">Build roadmap — suggested phases</h3>
    <p>Grouped by <strong>shared infrastructure and priority, not by area</strong>. Phase 1 first builds a deterministic variant-selector helper — <code>variants[ crc32(domain) % n ]</code> — that the structural items reuse; the visual/asset items cluster in Phase 3. Each variation item is <strong>code + authoring</strong> (the 3–4 variants must be written into the master); budget the authoring separately, in parallel with the code. <strong>Verify after each phase</strong> by building 2–3 sample domains and diffing their output — it must differ <em>and</em> be rebuild-stable (deterministic per domain).</p>

    <div class="callout" style="border-left:4px solid #16a34a;"><strong>At a glance (updated 2026-07-04):</strong> Phase 1 ✅ complete · Phase 2 done (1e, 1d, 5c; 1f = QA guardrail) · Phase 3 done (4c, 2d, and now the whole <a href="#ms-visual-identity">visual-identity step</a> — <a href="#spec-logo">4b</a> logo, <a href="#spec-favicon">4a</a> favicon, <a href="#spec-theme-colors">4d</a> theme colors). <strong>The differentiation build is complete.</strong> What shipped as a coordinated <strong>visual-identity step</strong>: a per-master library of <a href="#ms-visual-identity">Theme Presets</a> (palette + font + radius + bug icon), assigned per site (column or domain hash), that generates a per-site logo + favicon in those colors. This closed <a href="#spec-logo">4b</a> — the real one: the master logo is a <em>wordmark</em> ("KATY PEST PROS"), so leaving it identical leaked the master brand on every clone (identity issue, not cosmetic) — and folded in the cosmetic <a href="#spec-theme-colors">4d</a>/<a href="#spec-favicon">4a</a> as a bonus. Still <strong>⏭️ decided against:</strong> <a href="#spec-css-skins">2c</a> CSS class-vocabulary rotation and <a href="#spec-vary-copy">1g</a> copy templates — pure fingerprint obfuscation Google's doorway detection doesn't weight. <strong>Real remaining leverage is off this tool:</strong> (1) validate a small batch — ship 3–5 sites, watch indexation; (2) network / hosting / IP diversity; (3) content substance.</div>

    <p style="margin:14px 0 2px;"><strong>Phase 1 — Variation engine + structural Musts</strong> <span style="color:#64748b;">· the anti-fingerprint core · ✅ COMPLETE (2b intentionally skipped)</span></p>
    <ul>
        <li>✅ <strong>Foundation</strong> — the <code>ms_variant(domain, n, salt)</code> selector (salted per axis) lives in <code>includes/layout_variations.php</code>. <span style="color:#64748b;">Decision: the planned <code>data/variation.json</code> "menu" was dropped — titles resolve via shortcodes, layouts store their config on the page.</span></li>
        <li>✅ <a href="#spec-titles-metas">1c</a> · Unique title tag + meta description — <span style="color:#64748b;"><strong>done a different way than planned:</strong> not variant-rotated — a per-page <code>{primary_keyword}</code> shortcode + the page's own title (e.g. <code>{primary_keyword} {city_state} | {business}</code>). City + business already make each site's title unique while keeping keyword focus, so no rotation. Read-only preview on the MultiSite tab.</span></li>
        <li>✅ <a href="#spec-search-console">3g</a> · Search Console verification — <code>gsc_verification</code> CSV column → per-site meta tag (blank = none).</li>
        <li>⏭️ <a href="#spec-schema">2b</a> · Schema-shape variation — <span style="color:#64748b;"><strong>decided against.</strong> Schema already varies per site (identity rewrite + injected LocalBusiness node), and JSON-LD key order is meaningless to Google — near-zero value. Kept here for the record.</span></li>
        <li>✅ <a href="#spec-layout-skeletons">2a</a> · Block-order / layout variations — per-page panel (Content/Pages): Generate 4 subtle orderings (ends pinned), rotated by domain hash. Preview on the MultiSite tab.</li>
    </ul>

    <p style="margin:14px 0 2px;"><strong>Phase 2 — Content uniqueness &amp; authoring polish</strong> <span style="color:#64748b;">· cheap, high-SEO, mostly Per-row / authoring · <strong>4 of 6 done</strong> (1f reclassified as QA, not a build item); remaining ~2–4 dev-days</span></p>
    <ul>
        <li>✅ <a href="#spec-market-data">1e</a> · Real local market data — niche-aware research step, wired into the MultiSite tab.</li>
        <li>✅ <a href="#spec-alt-text">1d</a> · Localized alt text — <span style="color:#64748b;">the master authors alt with <code>{city}</code> shortcodes (they resolve per site) and the <code>4c</code> overlay leaves alt fields untouched; render mechanism was already present. No build needed.</span></li>
        <li>✅ <a href="#spec-map">5c</a> · Embedded map + service-area — homepage <code>map_info</code> with a shortcode-driven <code>q={city},+{SS}</code> embed (done on the pest master; authoring, no code).</li>
        <li>✅ <a href="#spec-finish-authoring">1f</a> · Finish master authoring — <span style="color:#64748b;"><strong>reclassified: authoring QA, not a build item.</strong> The leaks are single-site authoring errors; the <strong>master-lint guardrail</strong> (MultiSite tab / <code>lint_master.php</code>) finds them, and correcting them is normal site hygiene. No multisite build work.</span></li>
        <li>⏭️ <a href="#spec-css-skins">2c</a> · CSS skins — <strong>decided against (2026-07-04)</strong> <span style="color:#64748b;">— visual/theme variation is a human-perception signal; Google weighs content/links/structure/network, not how "different" the design looks. Goal is avoiding algorithmic detection, not fooling a human reviewer. Kept for the record.</span></li>
        <li>⏭️ <a href="#spec-vary-copy">1g</a> · Vary copy templates — <strong>decided against</strong> <span style="color:#64748b;">— AI copy is already unique per city; varying the templated scaffold around it is negligible value.</span></li>
    </ul>

    <p style="margin:14px 0 2px;"><strong>Phase 3 — Visual / asset pipeline</strong> <span style="color:#64748b;">· lowest SEO value, do last; all touch the asset subsystem · <strong>✅ COMPLETE</strong> (4c, 2d, plus the visual-identity step — 4b, 4a, 4d)</span></p>
    <ul>
        <li>✅ <a href="#spec-image-assign">4c</a> · Per-site image differentiation — keyword + "City, ST" baked onto heroes, plus byte-perturb + city-rename of every other photo (also covers 2d); style tuned/locked in the <a href="playground.php">Test Lab</a>.</li>
        <li>✅ <a href="#spec-logo">4b</a> · Per-site logo — <strong>BUILT (2026-07-04) — the real one.</strong> <span style="color:#64748b;">The <a href="#ms-visual-identity">visual-identity step</a> generates a bug mark + two-tone wordmark from <code>{business}</code> per site (in the applied preset's colors), replacing the master's baked-in "KATY PEST PROS" wordmark and killing the identity leak. Runs after differentiate, before the 4c prune.</span></li>
        <li>✅ <a href="#spec-favicon">4a</a> · Per-site favicon — <strong>BUILT (2026-07-04)</strong> <span style="color:#64748b;">— the preset's bug icon as a 128px colored tile, set per site; derived from the same mark as the logo.</span></li>
        <li>✅ <a href="#spec-theme-colors">4d</a> · Per-site theme colors — <strong>BUILT (2026-07-04) as <a href="#ms-visual-identity">Theme Presets</a></strong> <span style="color:#64748b;">— a per-master library of palette/font/radius bundles, assigned per site by column or domain hash; also drives the logo/favicon.</span></li>
        <li>✅ <a href="#spec-image-paths">2d</a> · Randomize image filename — folded into 4c (site city appended, master city stripped, on every image).</li>
    </ul>

    <div class="callout tip"><strong>Status:</strong> The build is <strong>done</strong>. Shipped: 1c, 3g, 2a (Phase 1); 1e, 1d, 5c + the 1f authoring-lint guardrail (Phase 2); 4c, 2d + the <a href="#ms-visual-identity">visual-identity step</a> (4b logo, 4a favicon, 4d Theme Presets) (Phase 3). <strong>Decided against (⏭️, 2026-07-04):</strong> 2c CSS class-vocabulary rotation, 1g copy templates, 2b schema-shape — pure fingerprint obfuscation Google's doorway detection doesn't weight (it looks at content, links, structure, network). <strong>Only remaining leverage is off this tool:</strong> validate a batch (ship 3–5, watch indexation), network/hosting/IP diversity, and content substance. There is no meaningful on-page differentiation code left to write.</div>

    <h3 style="margin-top:26px;border-top:2px solid #e2e8f0;padding-top:14px;color:#0f172a;">Area 1 · Content</h3>

    <div class="block-card-doc" id="spec-ai-copy">
        <h3>1a · Unique AI city copy (home + core) <span class="pri must">Must</span> <span class="where perrow" style="float:none;margin-left:6px;">Per-row</span></h3>
        <p class="bc-meta">✅ built</p>
        <p><strong>Description.</strong> Each site's home and core pages get city-specific body copy written by the AI generator from the Niche Brief + shared archetypes, so no two cities read the same.</p>
        <p><strong>Build (today).</strong> In <code>build_one.php</code>, after inject + differentiate, <code>generate.py</code> fills every <code>ai_block</code>. Prior copy is re-injected from the per-domain cache via <code>ms_ai_inject_from_cache()</code> (<code>includes/multisite/ai_cache.php</code>) so only missing/stale blocks regenerate — rebuilds are free. Cache: <code>sites/{master}/multisite/cache/{domain}.json</code>; <code>--force</code> regenerates.</p>
        <p><strong>Per-master prompt tuning.</strong> Each archetype's Label / Model / Description / Prompt skeleton can now be overridden <strong>per master</strong> in the admin — the <em>Prompt templates (archetypes)</em> panel on the <a href="#ai-niche">Niche Brief</a> tab. Overrides are stored as diffs against the shared library and merged at compile time, so tuning one niche's prompts survives recompiling and never touches another. See <a href="#ms-niche">Niche Brief &amp; archetypes</a>.</p>
    </div>

    <div class="block-card-doc" id="spec-landing">
        <h3>1b · Per-deploy service landing pages <span class="pri should">Should</span> <span class="where perrow" style="float:none;margin-left:6px;">Per-row</span></h3>
        <p class="bc-meta">✅ built</p>
        <p><strong>Description.</strong> Each deploy can target extra nearby towns; a city-targeted service landing page is rendered for each from the master's reusable landing template.</p>
        <p><strong>Build (today).</strong> <code>ms_parse_landing_cities()</code> (<code>includes/multisite/landing.php</code>) turns the row's <code>landing_cities</code> cell into city rows; <code>multisite/generate_landing.php</code> renders one page per city, AI-cached per domain like the home page. Blank cell → home + core only.</p>
    </div>

    <div class="block-card-doc" id="spec-titles-metas">
        <h3>1c · Unique title tag + meta description <span class="pri must">Must</span> <span class="where perrow" style="float:none;margin-left:6px;">Per-row (+ Pre-authoring)</span></h3>
        <p class="bc-meta">✅ built — via the <code>{primary_keyword}</code> shortcode (deterministic, no rotation)</p>
        <p><strong>Description.</strong> Every page's <code>&lt;title&gt;</code> and meta description must genuinely differ across sites, not one pattern with <code>{city}</code> swapped.</p>
        <p><strong>Build (today) — solved without variant rotation.</strong> Each page's title is its own shortcode string authored in the master SEO panel, e.g. <code>{primary_keyword} {city_state} | {business}</code>. <code>{primary_keyword}</code>/<code>{service}</code> resolve per page from the <em>Keyword focus</em> field (added in <code>includes/shortcodes.php</code>, set in <code>site-template.php</code>); <code>{city}</code>/<code>{business}</code> come from each clone's <code>site_vars</code>. Because city + business differ per site, every title is unique <em>and</em> keyword-focused — no 3–4-variant rotation needed (which would dilute keyword focus). The MultiSite tab shows a read-only title preview resolved for a sample city; it also flags pages with no title. See <a href="#ms-master-state">State of the SingleSite</a>.</p>
    </div>

    <div class="block-card-doc" id="spec-alt-text">
        <h3>1d · Localized, unique image alt text <span class="pri should">Should</span> <span class="where perrow" style="float:none;margin-left:6px;">Per-row</span></h3>
        <p class="bc-meta">✅ DONE — master authors alt with <code>{city}</code>; localizes per site</p>
        <p><strong>Description.</strong> Image <code>alt</code> attributes should carry per-city, unique text rather than one repeated caption — cheap uniqueness plus accessibility/SEO.</p>
        <p><strong>How it works.</strong> The render layer localizes alts: <code>apply_shortcodes_to_block()</code> (<code>includes/shortcodes.php</code>) resolves <code>{city}</code>/<code>{business}</code> shortcodes in <em>any</em> field ending <code>_alt</code> (bypassing the image <code>skipKeys</code>), including nested ones. The master already authors alts with shortcodes — e.g. pest hero <code>"Pest control services in {city} {SS}"</code>, cockroach template <code>"Cockroach exterminator treating a {city_state} home"</code> — so each site's alts differ. The <code>4c</code> hero overlay only repoints the image <em>path</em>, never the <code>_alt</code> field, so the localized alt survives on stamped heroes.</p>
        <p><strong>Optional future polish (not blocking):</strong> a <code>generate.py</code> fallback that derives an alt from the block heading + <code>{city}</code> when none is authored, so a forgotten alt still localizes.</p>
    </div>

    <div class="block-card-doc" id="spec-market-data">
        <h3>1e · Real local market data <span class="pri should">Should</span> <span class="where campaign" style="float:none;margin-left:6px;">Campaign</span></h3>
        <p class="bc-meta">✅ DONE (2026-07-03) — niche-aware research step, wired into the MultiSite tab</p>
        <p><strong>Description.</strong> City-specific facts that ground the AI copy in reality instead of generic filler — the line between substance and thin content. The fields are <em>niche-defined</em> (a groomer wants neighborhoods; a PM trainer wants employers/salary), not hard-coded to project management.</p>
        <p><strong>Built.</strong> The <strong>Niche Brief</strong> carries an editable <code>research_prompt</code> (tokens <code>{city}</code>/<code>{state}</code>/<code>{SS}</code> per city, <code>{business_descriptor}</code>/<code>{service_noun}</code> from the brief); blank = a generic local-market default. <code>generate.py --research-only</code> loads that prompt via <code>_load_research_prompt()</code>, calls <code>claude-sonnet-5</code>, validates softly (any non-empty dict), and stamps <code>_researched</code> so re-runs skip done cities (niche-agnostic — no hard-coded field checks). <code>multisite/research_cities.php</code> seeds <code>cities.json</code> from the params table (city|SS unique), then runs research; results persist in <code>cities.json</code> and are reused free. On the <strong>MultiSite tab</strong>, a <em>Research cities</em> card (shown only when the brief has <em>Uses research fields</em> on) runs it detached with a <strong>Dry run</strong> (no API cost) or live, streaming output. Gated off for niches that localize via angle + shortcodes. <strong>Effort:</strong> done.</p>
    </div>

    <div class="block-card-doc" id="spec-finish-authoring">
        <h3>1f · Finish master authoring <span class="where preauthor" style="float:none;margin-left:6px;">Pre-authoring</span></h3>
        <p class="bc-meta">✅ resolved — reclassified as authoring QA (guardrail built); <strong>not a multisite build item</strong></p>
        <p><strong>What it really is.</strong> The "leaks" (a literal <code>Texas</code> instead of <code>{state}</code>, a logo URL on the master's domain) are <em>authoring mistakes</em>, not a build item. If the master is shortcoded correctly there's no 1f work at all. So don't treat it as a feature — treat it as QA.</p>
        <p><strong>Why you can't just auto-fix it.</strong> The clone-time rewrite safely replaces <em>distinctive</em> strings (business name, phone, website, domain). It deliberately does NOT rewrite the master <strong>city/state/zip</strong>, because those are common words — auto-replacing every "Katy" or "TX" would corrupt a customer's name, a street, "TXT," etc. That ambiguity is exactly why <code>{city}</code>/<code>{state}</code> shortcodes exist: the explicit marker is the fix, and there's no safe way to machine-repair a missing one after the fact.</p>
        <p><strong>The guardrail (built).</strong> <code>ms_lint_master()</code> (<code>includes/multisite/master_lint.php</code>) scans a master's authored sources (<code>site.json</code> + <code>templates.json</code>) and flags literal city/state/SS/zip in text plus master-domain asset URLs — skipping what the pipeline auto-handles (<code>site_vars</code>, <code>local_business</code>, business/phone/website, image filenames) and the regenerated <code>data/pages/</code> outputs. Run it from the <strong>MultiSite tab → "Check master for authoring leaks"</strong>, or <code>php multisite/lint_master.php &lt;master&gt;</code>. Advisory — a human reviews each hit (some are legitimate, e.g. a governing-law state). This makes 1f self-service and permanent instead of a manual hunt every time.</p>
        <p><strong>Remaining (authoring, small).</strong> Fix whatever the validator flags. On the pest master that's ~3 items: the <code>wb_badge</code> "TEXAS" → <code>{state}</code>, and a decision on the Katy-specific blog post (exclude from clones or genericize). The "mark every block <code>ai_block</code>" half only applies to AI-generated masters (e.g. PM) — a shortcode-driven master like pest localizes without it. <strong>Effort:</strong> ~1 hour for a well-authored master; the validator tells you exactly what's left.</p>
    </div>

    <div class="block-card-doc" id="spec-vary-copy">
        <h3>1g · Vary generated-copy templates <span class="pri must">Must</span> <span class="where preauthor" style="float:none;margin-left:6px;">Pre-authoring (+ Per-row)</span></h3>
        <p class="bc-meta">◐ partial — AI copy is already unique; the scaffolding isn't varied</p>
        <p><strong>Description.</strong> Rotate 3–4 sentence-structure patterns per block instead of one fill-in-the-blank template, so the structure around the AI copy isn't identical site-to-site.</p>
        <p><strong>Build.</strong> Author 3–4 phrasings per templated block in the master (Pre-authoring); select per domain by stable hash (Per-row). Where the AI writes the block, pass a rotated prompt skeleton so structure varies. See <a href="#ms-variation">Deterministic variation</a>; shares the hash helper. <strong>Effort:</strong> ~1–2 days.</p>
    </div>

    <h3 style="margin-top:26px;border-top:2px solid #e2e8f0;padding-top:14px;color:#0f172a;">Area 2 · Structural</h3>

    <div class="block-card-doc" id="spec-layout-skeletons">
        <h3>2a · Block-order / layout variations <span class="pri must">Must</span> <span class="where preauthor" style="float:none;margin-left:6px;">Pre-authoring (+ Per-row)</span></h3>
        <p class="bc-meta">✅ built (Home + Core/Landing pages)</p>
        <p><strong>Description.</strong> Assign one of up to 4 whole-page block orderings per domain, so sites aren't structurally identical top to bottom.</p>
        <p><strong>Build (today).</strong> Each page (Content / Pages) has a <em>Layout variations</em> panel: <strong>Generate</strong> makes up to 3 subtle alternate orderings (hero pinned first, last block pinned last, a couple of swaps in the middle — <code>includes/layout_variations.php</code>); enable + Save stores them on the page (block ids auto-assigned). At build, <code>differentiate.php</code> calls <code>layout_apply_for_domain()</code> — <code>ms_variant(domain, n, 'layout')</code> picks one ordering (0 = natural) and reorders <code>content_blocks</code> for the homepage + each page. The MultiSite tab preview shows which layout a sample domain gets. City Templates (landing-only generation) come later.</p>
    </div>

    <div class="block-card-doc" id="spec-schema">
        <h3>2b · Vary JSON-LD schema shape <span class="pri must">Must</span> <span class="where preauthor" style="float:none;margin-left:6px;">Pre-authoring (+ Per-row)</span></h3>
        <p class="bc-meta">⏭️ decided against — kept for the record</p>
        <p><strong>Description.</strong> Rotate 3–4 JSON-LD variants per page type that differ in field order and boilerplate phrasing, so structured data isn't byte-identical across the network.</p>
        <p><strong>Decision (not building).</strong> Under the clone model this is near-zero value: (1) each site's schema <em>already</em> differs — identity rewrite swaps business/domain/city, fabricated ratings are stripped, and a <strong>unique LocalBusiness node</strong> (address + geo) is injected per site; (2) JSON-LD is <strong>order-agnostic to Google</strong> — it parses the doc into a graph, so reordering fields changes bytes but not what Google sees, and identical schema <em>shape</em> across same-industry sites is normal, not a doorway signal. So rotating field order/boilerplate is effort for ~no SEO gain. Revisit only if a concrete need appears; the real anti-clone lever (visible section order) is handled by <a href="#spec-layout-skeletons">2a</a>.</p>
    </div>

    <div class="block-card-doc" id="spec-css-skins">
        <h3>2c · CSS skins / vary class vocabulary <span class="where preauthor" style="float:none;margin-left:6px;">Pre-authoring (+ Per-row)</span></h3>
        <p class="bc-meta">⏭️ class-vocabulary rotation decided against — but "different palette per site" now shipped as <a href="#ms-visual-identity">Theme Presets</a></p>
        <p><strong>Superseded in part.</strong> The "give each site a different color scheme + font" goal is now <strong>built</strong> as <a href="#ms-visual-identity">Theme Presets</a> — a per-master library of color/font/radius bundles, assigned per site (column or domain hash), that also drives a generated logo + favicon. What remains ⏭️ decided-against below is the narrower <em>class-name-vocabulary</em> rotation, which is pure fingerprint obfuscation with no user-facing value.</p>
        <p><strong>Decision (class rotation).</strong> Considered building a per-site "skin" system (color scheme + fonts + component styling as a few designed presets) so sites look like different themes. The <em>looks-different</em> part is now Theme Presets. Rotating <em>class names</em> under identical CSS was <strong>decided against:</strong> visual/theme variation is a <em>human-perception</em> signal — it changes what a person sees, not what Google's doorway/scaled-content detection measures (content, links, structure, network). The goal here is avoiding <em>algorithmic</em> classification, not fooling a manual reviewer, so this is a dead end for the objective. (Random per-site CSS jitter would also read as auto-generated/spammy — worse, not better.) Kept for the record.</p>
        <p><strong>Description.</strong> 3–4 "skins" — the same visual layout and CSS rules under different class-name vocabularies — rotated per site.</p>
        <p><strong>Build.</strong> Author skin maps (canonical name → skin class names) in the master. At render, rewrite class attributes and the matching selectors in the emitted stylesheet using the skin selected by domain hash; ensure markup + CSS use the same skin. <strong>Effort:</strong> ~2 days.</p>
    </div>

    <div class="block-card-doc" id="spec-image-paths">
        <h3>2d · Randomize image directory / filename structure <span class="pri maybe">Maybe</span> <span class="where perrow" style="float:none;margin-left:6px;">Per-row</span></h3>
        <p class="bc-meta">✅ DONE — folded into the <a href="#spec-image-assign">4c</a> image pass (city-in-filename)</p>
        <p><strong>Description.</strong> Vary image filenames per site so asset paths aren't a uniform fingerprint (and don't leak the master's city).</p>
        <p><strong>Built.</strong> The 4c image pass renames every content image with the site city appended and the master city stripped — <code>ms_city_image_path()</code> (<code>…-katy.webp</code> → <code>…-dallas-tx.webp</code>), rewriting the block field (and HTML-embedded refs). Deterministic, so rebuilds/incremental deploys stay stable. We kept the <em>directory</em> structure as-is (renaming folders adds risk for no extra signal); filename + byte differentiation is what removes the fingerprint. See <a href="#spec-image-assign">4c</a>.</p>
    </div>

    <h3 style="margin-top:26px;border-top:2px solid #e2e8f0;padding-top:14px;color:#0f172a;">Area 3 · Identity &amp; SEO signals</h3>

    <div class="block-card-doc" id="spec-identity-rewrite">
        <h3>3a · Business / phone / email / domain rewritten <span class="pri must">Must</span> <span class="where perrow" style="float:none;margin-left:6px;">Per-row</span></h3>
        <p class="bc-meta">✅ built</p>
        <p><strong>Description.</strong> The master's identity is replaced with this site's everywhere, including inside the JSON-LD, via word-boundary-anchored replacement.</p>
        <p><strong>Build (today).</strong> <code>ms_differentiate_working_dir()</code> (<code>includes/multisite/differentiate.php</code>) runs the identity rewrite over <code>site.json</code> + rendered schema; body copy uses <code>{business}</code>/<code>{website}</code> shortcodes so most resolves at render.</p>
    </div>

    <div class="block-card-doc" id="spec-localbusiness">
        <h3>3b · LocalBusiness JSON-LD with real address + geo <span class="pri must">Must</span> <span class="where perrow" style="float:none;margin-left:6px;">Per-row</span></h3>
        <p class="bc-meta">✅ built — needs <code>lat</code>/<code>lng</code> in the row</p>
        <p><strong>Description.</strong> A real <code>LocalBusiness</code> node with <code>PostalAddress</code> + <code>GeoCoordinates</code> injected on the homepage — the strongest "distinct entity" signal — when geo/address is present.</p>
        <p><strong>Build (today).</strong> <code>differentiate.php</code> injects the node from the row's address/lat/lng/rating. Supply <code>lat</code>/<code>lng</code> in the params row to enable it.</p>
    </div>

    <div class="block-card-doc" id="spec-ratings">
        <h3>3c · Real ratings only <span class="pri must">Must</span> <span class="where perrow" style="float:none;margin-left:6px;">Per-row</span></h3>
        <p class="bc-meta">✅ built</p>
        <p><strong>Description.</strong> <code>aggregateRating</code> is emitted only when the row supplies real <code>rating</code> + <code>review_count</code>; the master's carried-over rating is stripped, never invented.</p>
        <p><strong>Build (today).</strong> <code>differentiate.php</code> removes any inherited <code>aggregateRating</code> and re-adds one only from the paired row fields.</p>
    </div>

    <div class="block-card-doc" id="spec-canonical">
        <h3>3d · Self-referential canonical per domain <span class="pri must">Must</span> <span class="where perrow" style="float:none;margin-left:6px;">Per-row</span></h3>
        <p class="bc-meta">✅ built</p>
        <p><strong>Description.</strong> Canonical URLs point at the site's own domain, not the master's.</p>
        <p><strong>Build (today).</strong> Derived at render from the row's required <code>domain</code> — <code>build_one.php</code> passes it via the <code>MULTISITE_CANONICAL</code> env var into <code>build_static_site()</code>. (There is no separate <code>canonical_domain</code> column.)</p>
    </div>

    <div class="block-card-doc" id="spec-sitemap">
        <h3>3e · Per-site <code>sitemap.xml</code> + <code>robots.txt</code> <span class="pri must">Must</span> <span class="where perrow" style="float:none;margin-left:6px;">Per-row</span></h3>
        <p class="bc-meta">✅ built</p>
        <p><strong>Description.</strong> A sitemap and robots file written for each domain at build.</p>
        <p><strong>Build (today).</strong> <code>build_static_site()</code> (<code>includes/static_build.php</code>) writes <code>sitemap.xml</code>, <code>robots.txt</code>, and <code>.htaccess</code> (including the SEO-tab 301s) during the render step; <code>deploy_site()</code> (<code>includes/multisite/deploy.php</code>) then only uploads them. The sitemap is written whenever a canonical domain is present — and since <code>domain</code> is required, that's always the case for a MultiSite build.</p>
    </div>

    <div class="block-card-doc" id="spec-analytics">
        <h3>3f · Per-site analytics (GA4) <span class="pri must">Must</span> <span class="where perrow" style="float:none;margin-left:6px;">Per-row</span></h3>
        <p class="bc-meta">✅ built (GA4) · ◐ distinct GTM containers not yet emitted</p>
        <p><strong>Description.</strong> Each site gets its own GA4 tag from <code>analytics_id</code>; a shared tag is never used.</p>
        <p><strong>Build (today).</strong> <code>ms_ga4_snippet($id)</code> in <code>differentiate.php</code> writes a per-site <code>gtag</code> into <code>theme.analytics_head</code>. To add GTM: emit a container snippet when <code>analytics_id</code> matches <code>GTM-*</code>. <strong>Effort (GTM option):</strong> ~½ day.</p>
    </div>

    <div class="block-card-doc" id="spec-search-console">
        <h3>3g · Unique Search Console verification <span class="pri should">Should</span> <span class="where perrow" style="float:none;margin-left:6px;">Per-row</span></h3>
        <p class="bc-meta">✅ built (meta-tag method)</p>
        <p><strong>Description.</strong> Each domain verified independently (own meta tag) — never all through one shared GTM property.</p>
        <p><strong>Build (today).</strong> A <code>gsc_verification</code> column holds each site's Search Console token. In <code>differentiate.php</code>, <code>ms_gsc_meta()</code> emits a per-site <code>&lt;meta name="google-site-verification"&gt;</code> into <code>theme.head_extra</code> (blank cell → nothing), echoed in <code>site-template.php</code> next to analytics. Operator still creates each property + pastes its token; DNS-TXT is the alternative (done at the registrar, outside the tool).</p>
    </div>

    <div class="block-card-doc" id="spec-fingerprint">
        <h3>3h · No generator fingerprint emitted <span class="pri should">Should</span> <span class="where perrow" style="float:none;margin-left:6px;">Per-row</span></h3>
        <p class="bc-meta">✅ built</p>
        <p><strong>Description.</strong> No meta-generator tag or tool signature in the output that would cluster the sites.</p>
        <p><strong>Build (today).</strong> The render emits no generator tag; keep the template free of tool-identifying comments/markers.</p>
    </div>

    <h3 style="margin-top:26px;border-top:2px solid #e2e8f0;padding-top:14px;color:#0f172a;">Area 4 · Visual</h3>

    <div class="block-card-doc" id="spec-favicon">
        <h3>4a · Per-site favicon <span class="where perrow" style="float:none;margin-left:6px;">Per-row</span></h3>
        <p class="bc-meta">✅ BUILT (2026-07-04) — generated per site as part of the <a href="#ms-visual-identity">visual-identity step</a></p>
        <p><strong>Description.</strong> Auto-set per site so no two sites ship a byte-identical favicon.</p>
        <p><strong>Built.</strong> The visual-identity step (<code>includes/multisite/visual.php</code>) renders the applied <a href="#ms-visual-identity">Theme Preset</a>'s bug icon as a colored tile at 128px and sets it as <code>header.favicon</code>. Colors follow the preset (accent-on-dark-tile), so each site's favicon differs. Shipped alongside the generated logo (<a href="#spec-logo">4b</a>).</p>
    </div>

    <div class="block-card-doc" id="spec-logo">
        <h3>4b · Per-site logo <span class="where perrow" style="float:none;margin-left:6px;">Per-row</span></h3>
        <p class="bc-meta">✅ BUILT (2026-07-04) — per-site generated wordmark + bug mark; kills the master-logo leak</p>
        <p><strong>Why this was real, not cosmetic.</strong> The pest master's header logo is a <strong>wordmark</strong> — the image literally reads "KATY PEST PROS." Logos were excluded from the 4c image pass as "brand furniture," which is right for a generic mark but wrong for a wordmark: every clone (Dallas, Plano, …) displayed a header logo saying the <em>master's</em> business name and city, baked into the pixels, contradicting the rewritten page text — the same class of leak as the business-name text (which we <em>do</em> rewrite), visible and byte-identical across all sites.</p>
        <p><strong>Built.</strong> The <a href="#ms-visual-identity">visual-identity step</a> (<code>includes/multisite/visual.php</code>, run in <code>build_one.php</code> right after <code>ms_differentiate_working_dir()</code> and before the 4c prune) generates a logo per site: a <strong>bug mark</strong> (the applied <a href="#ms-visual-identity">Theme Preset</a>'s icon, accent-colored on a dark rounded tile) to the left of a <strong>two-tone wordmark</strong> from <code>{business}</code> — first word in the accent color on line 1, remaining words in the dark brand color on line 2, left-justified (DejaVu Sans Bold) — the same lockup as the master's "KATY / PEST PROS." Written to <code>uploads/</code> and set as <code>header.logo</code>; colors come from the applied theme (accent = <code>accent_color</code>; dark = <code>heading_color</code> → <code>footer_bg</code> → <code>header_bg</code> fallback). This <strong>replaces the master's baked-in logo per site</strong> — the master's file goes unreferenced and the 4c prune removes it. Verified end-to-end. The params <code>logo</code> column stays a manual override, and the favicon (<a href="#spec-favicon">4a</a>) is derived from the same bug tile.</p>
    </div>

    <div class="block-card-doc" id="spec-image-assign">
        <h3>4c · Per-site image differentiation (hero overlay + full image pass) <span class="pri should">Should</span> <span class="where perrow" style="float:none;margin-left:6px;">Per-row</span></h3>
        <p class="bc-meta">✅ DONE — hero text overlay + byte/filename differentiation of every image (also covers <a href="#spec-image-paths">2d</a>)</p>
        <p><strong>Description.</strong> Every generated site shared the master's photos, byte-identical across the whole network — a duplicate-image footprint. Two parts: <strong>(a) heroes</strong> get text baked on (line 1 = the page's <code>primary_keyword</code>, line 2 = "City, ST") so the local keyword is in the pixels and each city's hero is genuinely different; <strong>(b) every other content photo</strong> is byte- and name-differentiated so no image matches across sites. Chosen over curated image pools — one master photo yields a unique image per city with zero photo-gathering.</p>
        <p><strong>Built</strong> (<code>includes/multisite/image_overlay.php</code>, run from <code>build_one.php</code> after AI, before the static build). First it <strong>materialises the shared-uploads symlink into a per-site directory</strong> so nothing touches the snapshot, then per page (home, core, each landing with its own city):</p>
        <ul>
            <li><strong>Hero overlay</strong> — <code>ms_hero_overlay_render()</code> bakes the two lines via ImageMagick, format-preserving (webp→webp), sizes scaled to each hero.</li>
            <li><strong>Byte perturbation</strong> — every other content photo gets <code>-strip</code> + an off-centre crop ~1–2% + ±2% tone + re-compress (<code>ms_perturb_image()</code>), all <strong>seed-deterministic</strong> so rebuilds are byte-identical. Beats exact <em>and</em> perceptual hashing; invisible to a person.</li>
            <li><strong>Filename</strong> — the site city is appended and the <em>master</em> city stripped (<code>ms_city_image_path()</code>): <code>…-katy.webp</code> → <code>…-dallas-tx.webp</code>. Kills the master-city leak + makes paths unique. <strong>This is item <a href="#spec-image-paths">2d</a>.</strong></li>
            <li><strong>Raw-text sweep</strong> catches images hardcoded in <code>custom_html</code>; <strong>prune</strong> drops every unreferenced file (originals we replaced + the master's unused media library — pest went 273 → 37 media files per site). Logos / icons / favicons are left identical (brand furniture).</li>
        </ul>
        <p><strong>Where it runs (architecture decision).</strong> The pass is a <strong>post-generation sweep</strong> in <code>build_one.php</code> (<code>ms_differentiate_site_images()</code>, after AI + landing generation, before the static build) — <em>deliberately not</em> baked into the shared generation engine (<code>includes/generation/engine.php</code>). That keeps the <strong>destructive orchestrator</strong> (with its prune) multisite-only: the same engine generates a live single site's city pages, and wiring the <em>orchestrator</em> there would mutate that site's real <code>uploads/</code> and — via the prune — <strong>delete the media library</strong>. The orchestrator sweep only ever runs against a throwaway per-site working dir, so a live site is never touched. (The non-destructive <em>core</em>, <code>ms_process_blocks_images()</code>, <strong>is</strong> reused by the engine for single-site city pages — see below.)</p>
        <p><strong>Reused for single-site city pages today.</strong> The per-page core <code>ms_process_blocks_images($blocks, $ctx)</code> takes a plain context (site_dir, city, seed, keyword, style…) and is already wired into the single-site generation engine (<code>includes/generation/engine.php</code>) — opt-in per city via the <a href="#cities-differentiation">City Pages</a> "Per-city images" dropdown. <strong>Rule still holds:</strong> single-site calls the non-destructive <em>core</em>, never <code>ms_differentiate_site_images()</code> / <code>ms_prune_unreferenced_uploads()</code> (those delete uploads and are MultiSite-clone-only). <strong>Original-tracking (idempotent):</strong> the core records a sibling <code>_&lt;field&gt;_orig</code> per differentiated field and always re-derives from that original, so re-runs never compound (no double-perturb, no hero text-over-text); the MultiSite build strips <code>_orig</code> before its prune (<code>ms_unset_orig_keys</code>) while the single-site path keeps it. <strong>Requires</strong> <code>primary_keyword</code> per page for hero line 1 (else city-only).</p>
        <p><strong>Tuning &amp; locking the style.</strong> The <a href="playground.php">Test Lab</a> (docs nav → 🧪 Test Lab) previews the overlay on any image with live controls, then <em>Lock this style into the build</em> writes <code>multisite/hero_style.json</code> (position, colours, sizes + reference dims). The build reads it — a per-master <code>sites/{master}/multisite/hero_style.json</code> overrides the global one — and scales the locked sizes to each hero. The Lab shares the exact render core, so the preview matches production.</p>
    </div>

    <div class="block-card-doc" id="spec-theme-colors">
        <h3>4d · Per-site theme colors (Theme Presets) <span class="where perrow" style="float:none;margin-left:6px;">Per-row</span></h3>
        <p class="bc-meta">✅ BUILT (2026-07-04) — as <a href="#ms-visual-identity">Theme Presets</a> (palette + font + button radius per site)</p>
        <p><strong>Description.</strong> Each site gets a distinct palette, font, and button radius — not just a hero color, a coordinated look that also feeds the generated logo + favicon.</p>
        <p><strong>Built.</strong> Rather than a raw <code>crc32(domain)</code> color hash, this shipped as curated <strong><a href="#ms-visual-identity">Theme Presets</a></strong> — a per-master library (<code>sites/{master}/multisite/theme_presets.json</code>, up to 10) of named bundles (accent + highlight colors, brand/heading fonts, button radius, header/footer colors, bug icon). The visual-identity step picks one per site from the <code>theme_preset</code> column, or — when blank — by deterministic domain hash (<code>ms_variant</code>, salt <code>'theme'</code>), and merges it into <code>data['theme']</code> before <code>theme_css_vars()</code> renders. The pest master ships four (Classic / Bold / Fresh / Trust). Edited in the admin via the Visual Identity panel with live previews.</p>
    </div>

    <h3 style="margin-top:26px;border-top:2px solid #e2e8f0;padding-top:14px;color:#0f172a;">Area 5 · Local &amp; off-site signals</h3>

    <div class="block-card-doc" id="spec-gbp">
        <h3>5a · Google Business Profile per city <span class="pri must">Must</span> <span class="where operational" style="float:none;margin-left:6px;">Operational</span></h3>
        <p class="bc-meta">📋 operational — outside the tool</p>
        <p><strong>Description.</strong> A real GBP per location — address, correct categories, photos, posts. The single biggest local ranking + distinct-entity signal.</p>
        <p><strong>Build.</strong> Operational: create/verify a GBP per city (needs a real address + phone per location); keep NAP identical to the site. <strong>Effort:</strong> operational — per-site setup + ongoing.</p>
    </div>

    <div class="block-card-doc" id="spec-citations">
        <h3>5b · Local citations / NAP consistency <span class="pri should">Should</span> <span class="where operational" style="float:none;margin-left:6px;">Operational</span></h3>
        <p class="bc-meta">📋 operational — outside the tool</p>
        <p><strong>Description.</strong> The same name / address / phone across directories (Yelp, BBB, industry lists).</p>
        <p><strong>Build.</strong> Operational: submit and maintain citations per site; ensure NAP matches the site and GBP exactly. <strong>Effort:</strong> operational — per-site + ongoing.</p>
    </div>

    <div class="block-card-doc" id="spec-map">
        <h3>5c · Embedded map + service-area <span class="pri should">Should</span> <span class="where perrow" style="float:none;margin-left:6px;">Per-row</span></h3>
        <p class="bc-meta">✅ DONE (pest master) — shortcode-driven map embed; authoring, no code</p>
        <p><strong>Description.</strong> A localized map / directions / service-area block on each site, on the homepage.</p>
        <p><strong>Built.</strong> No new code — the existing <code>map_info</code> block already renders <code>mi_map_embed</code>, and that field isn't in the shortcode skip-list, so it localizes at render. The fix was <em>authoring</em>: the master's homepage map was hard-pinned to the master's city (a Google <code>pb=</code> place embed), so every clone showed the same town. Replaced it with a keyless query embed — <code>https://maps.google.com/maps?q={city},+{SS}&amp;z=11&amp;output=embed</code> — so each site maps its own city (verified: Dallas → <code>q=Dallas,+TX</code>). For pinpoint accuracy, <code>q={lat},{lng}</code> also works since the params table carries geo. Kept home-only (landing pages are already hyper-local); adding it to a landing template is a trivial repeat. <strong>Effort:</strong> done.</p>
    </div>

    <div class="block-card-doc" id="spec-backlinks">
        <h3>5d · Earn a distinct backlink profile <span class="pri should">Should</span> <span class="where operational" style="float:none;margin-left:6px;">Operational</span></h3>
        <p class="bc-meta">📋 operational — outside the tool</p>
        <p><strong>Description.</strong> Real local links per site; never a shared PBN / cross-site link network.</p>
        <p><strong>Build.</strong> Operational: local outreach / partnerships per site; explicitly avoid interlinking your own network. <strong>Effort:</strong> operational — ongoing.</p>
    </div>

    <h3 style="margin-top:26px;border-top:2px solid #e2e8f0;padding-top:14px;color:#0f172a;">Area 6 · Site Hosting &amp; Footprint</h3>

    <div class="block-card-doc" id="spec-domain-reg">
        <h3>6a · Domain registration diversity <span class="pri should">Should</span> <span class="where operational" style="float:none;margin-left:6px;">Operational</span></h3>
        <p class="bc-meta">📋 operational — outside the tool</p>
        <p><strong>Description.</strong> Vary registrars; don't bulk-buy every domain in one account.</p>
        <p><strong>Build.</strong> Operational: spread registrations across registrars / accounts. <strong>Effort:</strong> operational — setup-time choice.</p>
    </div>

    <div class="block-card-doc" id="spec-ip-diversity">
        <h3>6b · Hosting / IP diversity <span class="pri must">Must</span> <span class="where operational" style="float:none;margin-left:6px;">Operational</span></h3>
        <p class="bc-meta">📋 operational — outside the tool</p>
        <p><strong>Description.</strong> Spread across hosts or IP ranges; avoid one C-class block of same-owner sites; keep origins behind Cloudflare's proxy so a DNS lookup can't reveal the network.</p>
        <p><strong>Build.</strong> Operational: distribute across hosts/IPs and proxy every origin (see <a href="#ms-hosting">Cloudflare &amp; origin IP</a>). <strong>Effort:</strong> operational.</p>
    </div>

    <div class="block-card-doc" id="spec-cloudflare">
        <h3>6c · Cloudflare / CDN <span class="pri must">Must</span> <span class="where operational" style="float:none;margin-left:6px;">Operational</span></h3>
        <p class="bc-meta">📋 operational — outside the tool</p>
        <p><strong>Description.</strong> A shared CF account is itself a footprint; confirm every <code>A</code> record is proxied (orange-cloud), not grey.</p>
        <p><strong>Build.</strong> Operational: see the <a href="#ms-hosting">Cloudflare &amp; origin-IP</a> section for the per-domain audit and fix. <strong>Effort:</strong> operational.</p>
    </div>

    <div class="block-card-doc" id="spec-whois">
        <h3>6d · WHOIS privacy <span class="pri should">Should</span> <span class="where operational" style="float:none;margin-left:6px;">Operational</span></h3>
        <p class="bc-meta">📋 operational — outside the tool</p>
        <p><strong>Description.</strong> WHOIS privacy enabled on every domain.</p>
        <p><strong>Build.</strong> Operational: enable registrar WHOIS privacy per domain. <strong>Effort:</strong> operational.</p>
    </div>

    <div class="block-card-doc" id="spec-registrars">
        <h3>6e · Vary registrars / nameservers <span class="pri maybe">Maybe</span> <span class="where operational" style="float:none;margin-left:6px;">Operational</span></h3>
        <p class="bc-meta">📋 operational — outside the tool</p>
        <p><strong>Description.</strong> Split across accounts once past ~20 sites; Cloudflare nameservers alone aren't a distinguishing signal.</p>
        <p><strong>Build.</strong> Operational: mix registrars / nameservers as you scale. <strong>Effort:</strong> operational.</p>
    </div>

    <div class="block-card-doc" id="spec-link-hub">
        <h3>6f · No cross-site link hub <span class="pri must">Must</span> <span class="where operational" style="float:none;margin-left:6px;">Operational</span></h3>
        <p class="bc-meta">📋 operational — outside the tool</p>
        <p><strong>Description.</strong> No footer link network / cross-site linking — the classic PBN tell.</p>
        <p><strong>Build.</strong> Operational: never interlink the sites; keep each site's link graph internal. <strong>Effort:</strong> operational.</p>
    </div>
</section>

<section id="ms-variation">
    <h2>Deterministic variation (anti-fingerprint)</h2>
    <p>Four checklist items above — <strong>schema shape, body copy, CSS class names, and DOM order</strong> — are the same move: instead of one template repeated across every site, build <strong>3–4 variants</strong> and assign one per domain. The assignment must be by a <strong>stable hash of the domain</strong>, never random — so a rebuild always picks the same variant and SEO signals don't churn. Random-per-build is the one way to get this wrong.</p>

    <div class="callout tip"><strong>The rule:</strong> <code>variant = variants[ hash(domain) % count ]</code> — same domain picks the same variant forever; different domains spread evenly across the set.</div>

    <h3>Schema shape <span class="pri must">Must</span></h3>
    <p>Build 3–4 JSON-LD variants per page type that differ in field order and boilerplate phrasing — same facts, different shape.</p>
    <p style="color:#475569;"><em>Example:</em> Variant 1 leads <code>serviceType → provider → description</code>; Variant 2 leads <code>description → name → areaServed</code>.</p>

    <h3>Body copy <span class="pri must">Must</span></h3>
    <p>Write 3–4 sentence-structure patterns per content block instead of one fill-in-the-blank line repeated hundreds of times. The AI generator already produces unique per-city copy; this hardens any templated fallback.</p>
    <p style="color:#475569;"><em>Example:</em> "Struggling with {pest} in {city}? Our licensed techs…" vs. "{pest} problems need fast action — we offer same-week service in {city}…"</p>

    <h3>CSS class vocabulary <span class="pri should">Should</span></h3>
    <p>Create 3–4 "skins": the same visual layout and CSS rules under different class-name vocabularies, rotated per site.</p>
    <p style="color:#475569;"><em>Example:</em> Skin 1 uses <code>.hero-wrap</code> / <code>.service-card</code>; Skin 2 uses <code>.banner-section</code> / <code>.offering-tile</code>.</p>

    <h3>DOM section order <span class="pri should">Should</span></h3>
    <p>Build each section as an independent block, then define 3–4 orderings and assign one per site.</p>
    <p style="color:#475569;"><em>Example:</em> Skeleton A: Header → Hero → Services → Testimonials → FAQ → CTA → Footer. Skeleton B: Header → Hero → FAQ → Services → Testimonials → CTA → Footer.</p>
</section>

<section id="ms-hosting">
    <h2>Cloudflare proxy — hiding your origin IP</h2>
    <p>This expands on <a href="#ms-axes">area 5 (Site Hosting &amp; Footprint)</a>. When many of your sites share one VPS, the single biggest footprint leak is a DNS <code>A</code> record that points straight at the server's real IP. Route every domain through Cloudflare's proxy instead, so public DNS only ever returns a Cloudflare address.</p>

    <h3>Why it matters</h3>
    <p>If a domain's <code>A</code> record resolves to your VPS IP directly, anyone who runs <code>dig yoursite.com</code> (or any DNS-lookup tool) sees that origin IP. From there a free reverse-IP lookup lists every other domain on the same IP — so <strong>one exposed domain reveals the whole network</strong>. If 30 of 50 sites sit on one VPS IP, exposing one exposes the other 29. That is precisely the same-owner-network fingerprint area 5 is trying to avoid.</p>

    <h3>What correct looks like</h3>
    <p>With the domain proxied through Cloudflare, a DNS lookup returns a Cloudflare range (e.g. <code>104.21.x.x</code> or <code>172.67.x.x</code>), never your server IP. Cloudflare sits in front and forwards traffic to your VPS privately; the real origin stays out of public DNS entirely.</p>

    <h3>How to check &amp; fix (per domain)</h3>
    <ol>
        <li>Cloudflare dashboard → the domain → <strong>DNS</strong>.</li>
        <li>Find the <code>A</code> record and look at the cloud icon beside it:
            <ul>
                <li><strong>Orange cloud (Proxied)</strong> — good; the origin IP is hidden.</li>
                <li><strong>Grey cloud (DNS only)</strong> — bad; this leaks the real VPS IP.</li>
            </ul>
        </li>
        <li>Click the icon to flip any grey cloud to orange.</li>
        <li>Verify with <code>dig yourdomain.com</code> (or an online lookup) — it should now return a Cloudflare IP, not your VPS IP.</li>
        <li>Repeat for <strong>every</strong> domain. It's easy to miss one — especially sites set up in a hurry or migrated in with default (grey-cloud) settings.</li>
    </ol>

    <div class="callout warn"><strong>Caveat — flipping to orange doesn't un-leak the past.</strong> If the origin IP was ever public — before Cloudflare was set up, or during initial DNS propagation — it may already be recorded in DNS-history databases (SecurityTrails, DNS history tools, etc.), and those keep the old record even after you fix the live one. When footprint really matters, check whether your VPS IP still shows up in DNS-history lookups tied to your domains; if it does, the durable fix is to <strong>move the origin to a fresh IP</strong> and keep that one proxied from day one so it's never exposed.</div>
</section>

<!-- ═══════════ ADMIN UI ═══════════ -->
<div class="doc-group-header" id="ms-admin">Running from the Admin</div>
<section id="ms-admin-multisite">
    <h2>The MultiSite tab</h2>
    <p>The whole campaign runs from the admin <strong>MultiSite</strong> tab (the active site is the campaign master) — no shell needed. It wraps the same cores documented under Command Line below.</p>
    <p>A collapsible <strong>"How a multisite run works"</strong> card sits at the top of the tab — a setup/verify checklist (Master site, Niche Brief, Keywords, <a href="#ms-visual-identity">Visual Identity</a>, Block/layout order, Params CSV, each linked to its tab), the per-row pipeline (clone → identity → landing → differentiate → visual → AI → build → deploy), and the finish. Read it once to see how the pieces fit before your first run.</p>
    <ol>
        <li><strong>Set up the master</strong> — before uploading params, author the master, the <a href="#ai-niche">Niche Brief</a> + keywords, and the <a href="#ms-visual-identity">Visual Identity — Theme Presets</a> panel (view / edit / add / remove presets, up to 10, with live logo + favicon previews). Each site draws its palette + generated logo/favicon from these presets.</li>
        <li><strong>Upload params</strong> — download the sample CSV, edit it, and upload. The table is validated inline (per-row ok / warn / error, plus an unknown-column report) and stored only when every row is error-free; rows with warnings are kept.</li>
        <li><strong>Download &amp; edit the current table</strong> — once a table is stored, <em>Download current table (FTP masked)</em> exports it with every FTP password shown as <code>__KEEP__</code> (safe to store/email). Edit any fields and re-upload: leave <code>__KEEP__</code> to keep a password, or type a new one — real passwords are re-hydrated by domain, so you never retype what you didn't change. A brand-new row that still has <code>__KEEP__</code> is rejected as partial-FTP, forcing a real password.</li>
        <li><strong>Saved params versions</strong> — every successful upload (and restore) is snapshotted to <code>sites/{master}/multisite/params_versions/</code>; the newest <strong>15</strong> are kept. Each can be downloaded (masked) or <strong>restored</strong> as the current table. The whole dir is gitignored, so real passwords never reach git.</li>
        <li><strong>Pre-flight FTP</strong> — a live, streamed per-row connect + login check, so bad credentials surface before any build begins.</li>
        <li><strong>Run campaign</strong> — set concurrency, limit and retries, optionally toggle <em>No AI</em> / <em>Force</em>, then Run. The run detaches into the background and the page polls a live progress bar (rows done, files uploaded, running cost).</li>
        <li><strong>Recent runs</strong> — a history of past runs with result + cost, each with a <strong>retry failed</strong> button that re-runs only that run's failed rows.</li>
    </ol>
    <div class="callout"><strong>Two levels of concurrency.</strong> The tab's <em>concurrency</em> setting (the CLI <code>--jobs=N</code>) controls how many <strong>sites</strong> build at the same time — each is a separate <a href="#ms-process-model">process</a>. <em>Inside</em> each site, <code>build_one.php</code> invokes <code>generate.py</code> with its default <strong>8 landing-page workers</strong> (see <a href="#ai-concurrency">Concurrent generation</a>), so that site's pages generate in parallel too. The multisite progress bar tracks the <em>site</em> level — the per-worker bars from the AI Generation tab are not surfaced here, because each site is a detached background process whose page-level output is folded into its row's log. Effective peak API concurrency is roughly <code>jobs × 8</code>, so raise <em>jobs</em> gradually and watch for rate-limit backoff in the logs.</div>
    <p>Backed by <code>admin/multisite_api.php</code> (upload / status / run / run_status / list_runs / retry_failed / sample_csv / download_csv / list_versions / download_version / restore_version), <code>admin/multisite_preflight.php</code> (SSE pre-flight), the <a href="#ms-visual-identity">Visual Identity</a> panel (<code>admin/tabs/multisite_visual.php</code> + <code>admin/visual_preview.php</code> + <code>admin/visual_presets_save.php</code>), and CSRF-protected save handlers. The <strong>Niche Brief</strong> tab (see AI Content) authors the master's AI vocabulary.</p>
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
        <tr><td><code>--jobs=N</code></td><td>Build up to N <strong>sites</strong> concurrently (default 1). Cuts wall-clock on AI runs. Separate from the 8 landing-page workers each site build uses internally — see the "Two levels of concurrency" note under <a href="#ms-admin-multisite">The MultiSite tab</a>.</td></tr>
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
        <tr><td><code>multisite/build_one.php</code></td><td>Per-row worker: clone → inject → landing → differentiate → visual → AI → images → build → deploy</td></tr>
        <tr><td><code>multisite/render_site.php</code></td><td>Worker-mode child that renders one site to static HTML</td></tr>
        <tr><td><code>multisite/params_check.php</code></td><td>CSV intake: parse, validate, pre-flight, store</td></tr>
        <tr><td><code>includes/multisite/clone.php</code></td><td>Snapshot + working-dir clone</td></tr>
        <tr><td><code>includes/multisite/inject.php</code></td><td>Write params into site_vars</td></tr>
        <tr><td><code>includes/multisite/differentiate.php</code></td><td>Per-site schema/geo/analytics/identity rewrite</td></tr>
        <tr><td><code>includes/multisite/ai_cache.php</code></td><td>Per-domain AI copy cache (resolved-prompt <code>input_hash</code>, self-healing)</td></tr>
        <tr><td><code>includes/multisite/deploy.php</code></td><td>Incremental FTP deploy core</td></tr>
        <tr><td><code>includes/multisite/params.php</code></td><td>CSV parse + validation + pre-flight helpers</td></tr>
        <tr><td><code>includes/multisite/landing.php</code> · <code>multisite/generate_landing.php</code></td><td>Parse <code>landing_cities</code> → build per-city service landing pages</td></tr>
        <tr><td><code>multisite/ai/archetypes.json</code> · <code>multisite/ai/compile.php</code></td><td>Shared archetype library + niche-brief → registry compiler</td></tr>
        <tr><td><code>admin/tabs/multisite.php</code> · <code>admin/multisite_api.php</code> · <code>admin/multisite_preflight.php</code></td><td>Admin MultiSite tab: upload, pre-flight, run, history, retry</td></tr>
        <tr><td><code>admin/tabs/niche_brief.php</code> · <code>admin/niche_brief_save.php</code></td><td>Admin Niche Brief tab: author + compile the AI vocabulary</td></tr>
    </table>
    <p>Full architecture and rationale live in <code>docs/multisite-generator-architecture.md</code> in the repository.</p>
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
    multisite: ['doc-multisite', 'multisite-nav', 'MultiSite Documentation'],
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
