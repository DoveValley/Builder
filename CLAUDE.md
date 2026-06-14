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

## Theme / colors

`theme_css_vars()` converts the `theme` section of `site.json` into CSS custom properties injected inline into every page (`--color-header-bg`, `--color-accent`, `--btn-radius`, `--font-primary`, etc.).

Many block fields accept a color mode string (`'accent'`, `'header'`, `'footer'`, `'custom'`) instead of a raw hex value. `resolve_color($which, $customHex)` resolves these to a concrete color at render time by reading the global `$data['theme']`.

## Image uploads

`save_uploaded_file()` validates MIME type (jpeg/png/gif/webp), enforces 8 MB max, and writes to `uploads/` with a time+random filename. The function returns the relative path `uploads/filename.ext`, which is stored in `site.json`. At render time, `$pathPrefix . $photo` resolves to the correct URL (`/uploads/filename.ext`).

## URL routing

Landing pages are at `page.php?slug=your-slug`. An `.htaccess` rewrite maps `/your-slug` → `page.php?slug=your-slug` on Apache. Slugs are validated against a reserved list in `reserved_slugs()` and deduplicated by `unique_slug()`.

## Admin auth

Session-based. `config.php` calls `session_start()`. Every admin page checks `$_SESSION['admin_logged_in']` and redirects to `login.php` if not set. Password is verified with `password_verify()` against the bcrypt hash in `ADMIN_PASSWORD_HASH`.
