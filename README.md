# Homepage Builder (PHP)

A lightweight, self-contained PHP application for managing a simple homepage
through an admin panel. No database required — content is stored in a JSON
file.

## Features

- **Public website**:
  - `index.php` is your home page.
  - `page.php?slug=your-slug` (or `/your-slug` with pretty URLs, see below)
    renders any landing pages you've created.
  - Every page shares the same header, navigation menu, footer, and theme
    colors — these are set once and apply site-wide.
  - Each page (home or landing) can have any number of content blocks —
    each one can be plain text, or text paired with an image on the left
    or right.
  - A multi-column footer with an "about" section (logo, tagline, highlight,
    phone, email), any number of link columns (e.g. Courses / Company /
    Locations), and a bottom bar with copyright text and links
    (e.g. Privacy Policy | Terms of Service | Sitemap)
  - Fully customizable, site-wide colors

- **Admin panel** (`admin/index.php`), protected by a login screen, with five
  tabs:
  1. **Header** – upload/remove logo, manage menu items (add/remove as many
     as you like), set the phone number. Shared across every page.
  2. **Theme / Colors** – set the background color, text color, and accent
     color (used for links and buttons). These apply uniformly across the
     header, main content, and footer, and across every page on the site.
     A "Reset to Defaults" button restores the default white background
     with black text
  3. **Home Page** – the content editor for your site's home page (`/`).
     Add, remove, reorder, and edit any number of content blocks. Each
     block can be text-only, or text with an image on the left or right.
     For image blocks, choose a picture shape (Horizontal rectangle,
     Square, or Original size) and a crop focus (Center, Top, Bottom,
     Left, Right) to control how the photo is sized and cropped. This tab
     also includes SEO fields: a meta description, meta keywords, and
     optional schema markup (JSON-LD) for search engines
  4. **Landing Pages** – add any number of additional pages. Each page gets
     its own title, URL slug, content blocks, and SEO fields, using the
     exact same content editor as the Home Page tab. All landing pages
     automatically use the same header, footer, and colors as the home
     page. Click a page's "Edit" link to open its editor, or "preview" to
     view it; use the trash icon to delete a page
  5. **Footer** – footer logo, tagline, highlight text, phone, email, any
     number of link columns (each with its own title and links), copyright
     text, and bottom-bar links. Shared across every page.

## Landing page URLs

Each landing page is reachable at `page.php?slug=your-slug`. For cleaner
URLs like `/your-slug`, this project includes a root `.htaccess` file with a
rewrite rule that maps `/your-slug` to `page.php?slug=your-slug` on Apache
hosts with `mod_rewrite` enabled (which includes most shared hosts,
including Hostinger). If your host doesn't support this, or you'd rather
not use it, you can delete `.htaccess` — landing pages will still work via
`page.php?slug=your-slug`; just link to them using that form (e.g. in your
menu items or footer links).

## Requirements

- PHP 7.4+ (PHP 8.x recommended)
- A web server (Apache or Nginx) with PHP support
- Write permissions on the `data/` and `uploads/` folders

## Setup

1. Upload all files to your web server (e.g. via FTP), keeping the folder
   structure intact.

2. Make sure these folders are writable by the web server:
   ```
   chmod 755 data uploads
   ```

3. Visit `admin/login.php` in your browser.

   **Default login:**
   - Username: `admin`
   - Password: `admin123`

4. **Change the default password immediately.** To generate a new password
   hash, create a temporary PHP file with this content, open it in your
   browser, copy the output, then delete the file:

   ```php
   <?php echo password_hash('your-new-password', PASSWORD_DEFAULT);
   ```

   Paste the result into `config.php`, replacing the value of
   `ADMIN_PASSWORD_HASH`.

5. Log in to the admin panel and fill in your header, footer, and page
   content. Visit `index.php` (your site's homepage) to see the result.

## Folder structure

```
/
├── index.php              # Public home page
├── page.php               # Public landing pages (?slug=your-slug)
├── .htaccess              # Optional pretty-URL rewrite for landing pages
├── config.php             # Admin credentials & site settings
├── includes/
│   ├── functions.php      # Shared helper functions
│   └── site-template.php  # Shared header/footer/page template
├── data/
│   ├── site.json           # All site content (auto-created/updated)
│   └── .htaccess           # Blocks direct access to the data file
├── uploads/                 # Uploaded logo & photo (auto-created)
│   └── .htaccess           # Blocks script execution in this folder
├── assets/
│   └── css/style.css       # Styling for site + admin panel
└── admin/
    ├── login.php
    ├── logout.php
    ├── index.php           # Main admin panel (tabs)
    └── save.php            # Handles form submissions
```

## Notes

- All content (theme colors, header, content blocks, footer info, links) is
  stored in `data/site.json`. You can back this up or edit it directly if
  needed (it's plain JSON).
- Uploaded images are stored in `uploads/` with unique filenames so old
  images aren't overwritten.
- If you're running on Nginx instead of Apache, the `.htaccess` files won't
  apply automatically — add equivalent rules to your Nginx config to block
  access to `data/site.json` and to disable PHP execution inside `uploads/`.
