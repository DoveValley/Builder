# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Running locally

No build step. Serve with PHP's built-in server from the project root:

```bash
php -S localhost:8080
```

Admin panel: `http://localhost:8080/admin/login.php`  
Default credentials: `admin` / `admin123`

To generate a replacement password hash:
```bash
php -r "echo password_hash('your-password', PASSWORD_DEFAULT);"
```
Paste the result into `config.php` as `ADMIN_PASSWORD_HASH`.

## Architecture

**No database.** All site content lives in `data/site.json`. `config.php` defines path constants (`DATA_FILE`, `UPLOAD_DIR`, etc.) and admin credentials.

**Data flow:**
1. `load_data()` reads `data/site.json` and deep-merges it with `default_data()` so any newly-added keys get their defaults automatically.
2. Public pages (`index.php`, `page.php`) call `load_data()`, set `$contentBlocks`, `$seo`, `$pageTitle`, and `$assetPathPrefix = '/'`, then `require` `includes/site-template.php`.
3. `site-template.php` renders the full HTML page: shared header, then loops `$contentBlocks` calling `render_content_block()` for each, then shared footer.
4. Admin saves go to `admin/save.php` (POST only), keyed by `$_POST['section']` (`header`, `theme`, `content`, `footer`, `pages`, `popups`). After saving it redirects back with `?msg=success:...` or `?msg=error:...`.

**Key files:**
- `includes/functions.php` — all business logic: `load_data()`, `save_data()`, `render_content_block()`, `theme_css_vars()`, `resolve_color()`, image upload helpers, `slugify()`, `unique_slug()`
- `includes/site-template.php` — shared HTML template (header, content loop, footer, JS)
- `admin/index.php` — admin UI (tab-based: header, theme, content, pages, footer, popups)
- `admin/save.php` — handles all admin form POSTs
- `assets/css/style.css` — all styles for both public site and admin panel

## Content blocks

All block types are registered in `allowed_block_types()` in `functions.php`. Each block is a PHP associative array stored in `data/site.json`. The `render_content_block()` switch statement in `functions.php` handles rendering every type.

Current block types: `text`, `image_left`, `image_right`, `hero`, `hero_split`, `feature_split`, `split_cta`, `tab_services`, `hero_grid`, `service_cards`, `wide_banner`, `image_features`, `faq_two_col`, `cta_banner`, `links_grid`, `cta_card`, `map_info`, `image_text`, `faq`, `feature_columns`, `custom_html`, `steps`, `stats`, `cards`, `gallery`, `cta_button`.

**Adding a new block type** requires three changes:
1. Add entry to `allowed_block_types()` in `functions.php`
2. Add a `case` in `render_content_block()` in `functions.php`
3. Add the admin editing UI in `admin/index.php` (block editor section)

Both `render_content_block()` and `render_content_blocks_editor()` (in `admin/index.php`'s save path / `functions.php`) are large switch statements — each `case` label matches its block type name 1:1, so grep for `case 'block_type'` to jump straight to it. When a new case needs a link/button URL field, save it through `sanitize_url()` (don't write `trim($_POST[...])` directly) — see "Security notes" below. When it needs a photo upload field, reuse `render_photo_upload_fields()` rather than hand-rolling the markup.

## Theme / colors

`theme_css_vars()` converts the `theme` section of `site.json` into CSS custom properties injected inline into every page (`--color-header-bg`, `--color-accent`, `--btn-radius`, `--font-primary`, etc.).

Many block fields accept a color mode string (`'accent'`, `'header'`, `'footer'`, `'custom'`) instead of a raw hex value. `resolve_color($which, $customHex)` resolves these to a concrete color at render time by reading the global `$data['theme']`.

## Image uploads

`save_uploaded_file()` validates MIME type (jpeg/png/gif/webp), enforces 8 MB max, and writes to `uploads/` with a time+random filename. The function returns the relative path `uploads/filename.ext`, which is stored in `site.json`. At render time, `$pathPrefix . $photo` resolves to the correct URL (`/uploads/filename.ext`).

## URL routing

Landing pages are at `page.php?slug=your-slug`. An `.htaccess` rewrite maps `/your-slug` → `page.php?slug=your-slug` on Apache. Slugs are validated against a reserved list in `reserved_slugs()` and deduplicated by `unique_slug()`.

## Admin auth

Session-based. `config.php` calls `session_start()`. Every admin page checks `$_SESSION['admin_logged_in']` and redirects to `login.php` if not set. Password is verified with `password_verify()` against the bcrypt hash in `ADMIN_PASSWORD_HASH`.

## Blog system

`data['posts']` is an id-keyed array (`default_post_data()` schema: title, slug, status, published_at, updated_at, author, tag, excerpt, featured_image, featured_image_alt, content_blocks, seo). `data['blog_settings']` holds `blog_heading`, `blog_intro`, `posts_per_page`. `blog.php` is the router: `/blog` (listing, supports `?tag=` and `?p=` pagination) and `/blog/{slug}` (single post). It builds synthetic `$contentBlocks` (a `post_meta` block followed by the post's own blocks for single posts, or a `blog_list` block for the listing) and `require`s `includes/site-template.php`, the same as `page.php`.

`post_meta` and `blog_list` are pseudo block types — handled in `render_content_block()` but deliberately left out of `allowed_block_types()` since they're only ever generated by `blog.php`, never picked from the admin block editor.

The tag is a single string per post; `/blog?tag=slug` filters by `slugify()` match. The blog listing page renders a persistent tag-pill bar (an "All" pill plus one pill per distinct tag) under its heading — this lives on the listing page only, not on individual post pages.

## Writing blog/legal page content

Don't reproduce another site's copyrighted text verbatim, even when adapting a real competitor or reference page (e.g. cloning a business's own blog or legal pages) and even when swapping in `{business}`/`{business_domain}` shortcodes — a find/replace pass over someone else's text is still a copy. Instead, write fully original copy that covers the same topics/structure (same section headings, same substantive points), in original wording.

## Media library reuse

Before uploading anything new, check `uploads/media/` for an existing topically-relevant image — it's pre-populated with scraped images. Check dimensions with `sips -g pixelWidth -g pixelHeight <file>` and preview candidates with the Read tool before assigning a path into a `photo`/`featured_image` field directly in `site.json`.

## Local testing

`php -S localhost:PORT` (PHP's built-in server) does **not** process `.htaccess`/mod_rewrite, so pretty URLs (`/blog`, `/some-landing-page`) silently fall back to serving the homepage with HTTP 200 instead of routing correctly or 404ing. This is expected, not a bug — test with the query-string forms instead (`page.php?slug=...`, `blog.php?slug=...`, `blog.php?tag=...`). For visual verification, take a headless Chrome screenshot and view it with the Read tool:
```bash
/Applications/Google\ Chrome.app/Contents/MacOS/Google\ Chrome --headless=new --disable-gpu --screenshot=/tmp/out.png --window-size=W,H "http://localhost:PORT/path"
```

## Breadcrumbs

`site-template.php` builds two separate breadcrumb arrays whenever `$slug` is set: `$bcItems` (relative URLs, used by the visible `<nav class="breadcrumb-bar">`) and `$bcSchemaItems` (absolute URLs, used only for the `BreadcrumbList` JSON-LD, since schema.org requires absolute URLs there). Keep these separate — reusing one absolute-URL array for both was a past bug that sent visible breadcrumb clicks off-site.

## Security notes

- **All admin AJAX endpoints require CSRF tokens.** `admin/save.php` and `admin/media_api.php` both check `$_SESSION['csrf_token']` against a `csrf_token` field on every POST. If you add a new POST endpoint, give it the same check.
- **All user-entered URLs go through `sanitize_url()`** (`includes/functions.php`) before being stored — it only allows `http(s)://`, `tel:`, `mailto:`, and relative/in-page links, blocking `javascript:` and other dangerous schemes. Every `*_url`/`*_btn_url` field in `admin/save.php` must use it; don't add a new link field that stores `trim($_POST[...])` directly.
- **Uploaded SVGs are sanitized** via `sanitize_svg()` (`includes/functions.php`) — strips `<script>` tags, `on*` event handlers, and `javascript:` URIs before saving. GIFs are still passed through unprocessed (raster format, no script risk).
- **Never deploy this repo with `.git/` present in the webroot.** The root `.htaccess` now blocks direct access to dotfiles/dotfolders as a safety net, but the correct practice is to not upload `.git/` to a live host at all — it would otherwise expose full commit history, including old credential hashes.
- **Change the default admin password before any site goes live** — `config.php` ships with a placeholder bcrypt hash for `admin123`.
