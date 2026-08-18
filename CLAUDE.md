# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

**This file loads on every turn, so it holds only what is needed to avoid breaking something.**
Task-specific detail lives in `docs/` — read the relevant one when the task calls for it:

| read when | file |
|---|---|
| Building or rebuilding a client site into `site.json` | `docs/site-building.md` |
| A block must go edge-to-edge, or a full-width block leaves a gap above the footer | `docs/content-blocks.md` |
| Course data, the two schedule widgets, or the Schedule tab | `docs/course-schedule.md` |

## Running locally

No build step. Serve with PHP's built-in server from the project root:

```bash
php -S localhost:8080 router.php
```

The `router.php` argument is required for pretty URLs to work locally. Without it, every non-file URL
silently falls back to `index.php` (the homepage) because `php -S` does not process `.htaccess`
mod_rewrite.

Admin panel: `http://localhost:8080/admin/login.php` — default `admin` / `admin123`.
Replacement hash: `php -r "echo password_hash('your-password', PASSWORD_DEFAULT);"` → `config.php`
as `ADMIN_PASSWORD_HASH`.

## Local testing

`php -S` does **not** process `.htaccess`/mod_rewrite, so pretty URLs (`/blog`, `/some-landing-page`)
silently fall back to serving the homepage with HTTP 200 instead of routing or 404ing. This is
expected, not a bug — test with the query-string forms (`page.php?slug=...`, `blog.php?slug=...`,
`blog.php?tag=...`). For visual checks, take a headless Chrome screenshot and view it with the Read
tool.

## Architecture

**No database.** All site content lives in `data/site.json` (single-site) or
`sites/{id}/data/site.json` (multi-site). Course schedule data lives in a parallel file:
`data/courses.json` / `sites/{id}/data/courses.json`. `config.php` defines path constants
(`DATA_FILE`, `COURSES_FILE`, `UPLOAD_DIR`, etc.) and admin credentials.

**Data flow:**
1. `load_data()` reads `site.json` and deep-merges it with `default_data()` so newly-added keys get their defaults automatically.
2. Public pages (`index.php`, `page.php`) call `load_data()`, set `$contentBlocks`, `$seo`, `$pageTitle`, `$assetPathPrefix = '/'`, then `require` `includes/site-template.php`.
3. `site-template.php` renders the full page: shared header, then loops `$contentBlocks` calling `render_content_block($block, $pathPrefix)`, then shared footer.
4. Admin saves go to `admin/save.php` (POST only, keyed by `$_POST['section']`: `header`, `theme`, `content`, `footer`, `pages`, `popups`) or `admin/schedule_save.php`. Both redirect back with `?msg=success:...` or `?msg=error:...`.

**Multi-site:** `$_SESSION['active_site']` selects the active site. `site_api.php` handles switching
via FormData POST (`action=select&site_id=...`). Admin redirects to `sites.php` when none is selected.

**`includes/` structure** — `functions.php` is a loader only; logic lives in focused files:
- `data.php` — `load_data()`, `save_data()`, `default_data()`, `default_post_data()`
- `helpers.php` — `sanitize_url()`, `save_uploaded_file()`, `sanitize_svg()`, `slugify()`, `unique_slug()`
- `theme.php` — `theme_css_vars()`, `resolve_color()`
- `blocks.php` — `allowed_block_types()`, `render_content_block($block, $pathPrefix = '')`
- `editor.php` — `render_content_blocks_editor()`, per-block admin panel UI
- `scripts.php` — JS templates for new-block scaffolding in the admin
- `shortcodes.php` — `apply_shortcodes_to_block()`, `apply_course_shortcodes()`, course data loaders
- `schema.php` — JSON-LD helpers · `seo-editor.php` — SEO admin panel
- `site-template.php` — shared HTML template (head, content loop, footer, inline scripts)

## Content blocks

All block types are registered in `allowed_block_types()` in `includes/blocks.php`. Each block is a
PHP associative array stored in `site.json`, rendered by the `render_content_block()` switch.

**Current block types (34):** `text`, `image_left`, `image_right`, `hero`, `hero_split`,
`feature_split`, `split_cta`, `tab_services`, `hero_grid`, `service_cards`, `wide_banner`,
`image_features`, `faq_two_col`, `cta_banner`, `links_grid`, `cta_card`, `map_info`, `image_text`,
`faq`, `feature_columns`, `custom_html`, `steps`, `stats`, `cards`, `gallery`, `cta_button`,
`testimonials`, `video`, `buttons_grid`, `html_two_col`, `pricing_cards`, `logo_bar`, `stage_cards`,
`contact_form`

**Adding a new block type requires changes across four files:**
1. `includes/blocks.php` — add to `allowed_block_types()`, add a `case` in `render_content_block()`
2. `includes/editor.php` — admin panel UI for the block's fields
3. `includes/scripts.php` — the new-block JS template (default field values)
4. `admin/save.php` — the `case` in the `content` section that reads `$_POST` and builds the array

Both `render_content_block()` and `render_content_blocks_editor()` are large switch statements — each
`case` label matches the block type name 1:1, so grep for `case 'block_type'`. Link/button URL fields
must be saved through `sanitize_url()` (see Security). Photo upload fields reuse
`render_photo_upload_fields()`.

`post_meta` and `blog_list` are pseudo types — handled in `render_content_block()` but deliberately
left out of `allowed_block_types()`, since only `blog.php` generates them.

`custom_html` is full-width (not wrapped in `.container`) — see `docs/content-blocks.md`.

## Theme / colors

`theme_css_vars()` converts the `theme` section of `site.json` into CSS custom properties injected
inline into every page (`--color-header-bg`, `--color-accent`, `--btn-radius`, `--font-primary`).

Many block fields accept a color mode string (`'accent'`, `'header'`, `'footer'`, `'custom'`) instead
of a raw hex. `resolve_color($which, $customHex)` resolves these at render time from `$data['theme']`.

## Image uploads

`save_uploaded_file()` validates MIME type (jpeg/png/gif/webp), enforces 8 MB max, and writes to
`uploads/` with a time+random filename. It returns the relative path `uploads/filename.ext`, stored
in `site.json`. At render time `$pathPrefix . $photo` resolves to the correct URL.

## URL routing

Landing pages are at `page.php?slug=your-slug`. An `.htaccess` rewrite maps `/your-slug` on Apache.
Slugs are validated against `reserved_slugs()` and deduplicated by `unique_slug()`.

## Admin auth

Session-based. `config.php` calls `session_start()`. Every admin page checks
`$_SESSION['admin_logged_in']` and redirects to `login.php`. Password verified with
`password_verify()` against the bcrypt hash in `ADMIN_PASSWORD_HASH`.

**Admin tabs:** header, theme, content, pages, blog, footer, popups, media, seo, schedule

## Blog system

`data['posts']` is an id-keyed array (`default_post_data()`: title, slug, status, published_at,
updated_at, author, tag, excerpt, featured_image, featured_image_alt, content_blocks, seo).
`data['blog_settings']` holds `blog_heading`, `blog_intro`, `posts_per_page`. `blog.php` routes
`/blog` (listing, `?tag=` and `?p=`) and `/blog/{slug}`. It builds synthetic `$contentBlocks` (a
`post_meta` block plus the post's own blocks, or a `blog_list` block) and requires
`includes/site-template.php`, same as `page.php`.

The tag is a single string per post; `/blog?tag=slug` filters by `slugify()` match. The listing page
renders a persistent tag-pill bar — listing only, not individual posts.

## Breadcrumbs

`site-template.php` builds two separate arrays whenever `$slug` is set: `$bcItems` (relative URLs,
for the visible `<nav class="breadcrumb-bar">`) and `$bcSchemaItems` (absolute URLs, for the
`BreadcrumbList` JSON-LD, which schema.org requires). **Keep these separate** — reusing one
absolute-URL array for both was a past bug that sent visible breadcrumb clicks off-site.

## Writing blog/legal page content

Don't reproduce another site's copyrighted text verbatim, even when adapting a real competitor or
reference page and even when swapping in `{business}`/`{business_domain}` shortcodes — a
find/replace pass over someone else's text is still a copy. Write fully original copy covering the
same topics and structure, in original wording.

## API calls: one module per service, reused everywhere

**Every call to an outside service goes through a single module for that service. No page, action
handler or plugin talks to an API directly.** When something needs a service that has no module yet,
write the module — do not inline "just this once".

| Service | Module |
|---|---|
| HestiaCP | `admin/infra/lib/hestia.php` (client) + `hestia_fleet.php` (registry, discovery, fleet-wide reads) |
| Cloudflare | `admin/infra/lib/cloudflare.php` |
| Registrars | `admin/infra/lib/registrar.php` |
| Keyword/SERP providers | `admin/infra/lib/keywords.php`, `lib/serp.php` |
| Uptime checks | `admin/infra/lib/uptime.php` |
| Shared HTTP + call counting | `admin/infra/lib/http.php` |
| FTP/SFTP upload | `includes/multisite/deploy.php` |
| Geocoding | `includes/multisite/geocode.php` |

**Why, from this codebase.** Five places each ran their own "list the boxes, discover each, dig out
the facts" loop and dug slightly differently — one counted the panel's own hostname vhost as a
deployed site, so eight empty boxes reported eight sites. One `infra_hestia_fleet()` fixed every
consumer at once. The same is still true of Anthropic: `admin/keyword_suggest.php`,
`admin/schema_suggest.php` and `plugins/recovery/enrich.php` each hand-roll the API and have already
drifted to different models. **Duplicated clients do not stay duplicates; they become different.**

A service module owns, so callers never repeat it: base URL and auth, retry/timeout, error shape,
response parsing, and any rule about the data (e.g. "the box's own hostname vhost is not a site"
lives in `hestia_is_infra_vhost()`, not in three pages).

Cross-boundary note: `admin/infra/lib/*` is deliberately self-contained, so other parts of the panel
may `require_once` a lib directly. Do **not** require `admin/infra/bootstrap.php` from outside the
console — it redirects when it cannot see a session, which turns a JSON endpoint into a 302.

## Security notes

- **All admin POST endpoints require CSRF tokens.** `admin/save.php`, `admin/media_api.php`, and `admin/schedule_save.php` all check `$_SESSION['csrf_token']` against a `csrf_token` field via `hash_equals()`. Any new POST endpoint gets the same check.
- **All user-entered URLs go through `sanitize_url()`** (`includes/helpers.php`) before being stored — it allows only `http(s)://`, `tel:`, `mailto:`, and relative/in-page links, blocking `javascript:`. Every `*_url`/`*_btn_url` field in any save handler must use it; don't store `trim($_POST[...])` directly.
- **Uploaded SVGs are sanitized** via `sanitize_svg()` — strips `<script>`, `on*` handlers, and `javascript:` URIs. GIFs pass through unprocessed (raster, no script risk).
- **Never deploy this repo with `.git/` present in the webroot.** The root `.htaccess` blocks dotfiles as a safety net, but the correct practice is not uploading `.git/` to a live host at all — it would expose full commit history, including old credential hashes.
- **Change the default admin password before any site goes live** — `config.php` ships with a placeholder bcrypt hash for `admin123`.
- **Set `CONTACT_EMAIL` in `config.php`** before deploying the contact form — it defaults to `hello@yoursite.com`. The `contact_form` block renders via `contact_send.php` (public POST handler with CSRF, honeypot, rate limiting). Its session token is `$_SESSION['cf_csrf_token']`, separate from the admin token.
