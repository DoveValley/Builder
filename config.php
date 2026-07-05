<?php
/**
 * Configuration file
 * --------------------------------------------------
 * Change ADMIN_USERNAME and ADMIN_PASSWORD_HASH to set
 * your own admin login.
 *
 * Default login is:
 *   username: admin
 *   password: admin123
 *
 * To generate a new password hash, run this on your server
 * (e.g. via a temporary PHP file or the command line):
 *
 *   <?php echo password_hash('your-new-password', PASSWORD_DEFAULT);
 *
 * Then paste the result below.
 */

session_start();

define('ADMIN_USERNAME', 'admin');
define('ADMIN_PASSWORD_HASH', '$2y$12$jIZzYPu.23R2UNj82kw91.eOsHZJVv9bjpnsijj7kMxBhKoXU4Fga');

// Site name shown in the admin panel
define('SITE_TITLE', 'Elk Pest Pros');

// Email address where contact form submissions are delivered
define('CONTACT_EMAIL', 'hello@yoursite.com');

// File paths
define('BASE_DIR', __DIR__);

// Multi-site routing, in priority order:
//   1. Multisite worker mode — CLI only, when MULTISITE_SITE_BASE points at a valid site dir.
//      Used by the multisite generator's per-row worker, which builds an ephemeral cloned
//      site that lives OUTSIDE sites/ (an absolute temp path). It mirrors the legacy
//      single-site layout (data/ + uploads/ at the base, UPLOAD_URL = 'uploads/'), just
//      rooted at $_msBase instead of BASE_DIR. CLI guard ensures a web request can never
//      redirect paths via an env var.
//   2. Session active site — the admin's currently selected multi-site.
//   3. Legacy single-site fallback (data/ + uploads/ at the project root).
$_msBase       = (PHP_SAPI === 'cli') ? (getenv('MULTISITE_SITE_BASE') ?: '') : '';
$_activeSiteId = $_SESSION['active_site'] ?? '';
if ($_msBase !== '' && is_dir($_msBase) && is_dir(rtrim($_msBase, '/') . '/data')) {
    $_b = rtrim($_msBase, '/');
    define('ACTIVE_SITE_ID',   basename($_b));
    define('ACTIVE_SITE_DIR',  $_b);
    define('DATA_FILE',        $_b . '/data/site.json');
    define('COURSES_FILE',     $_b . '/data/courses.json');
    define('UPLOAD_DIR',       $_b . '/uploads/');
    define('UPLOAD_URL',       'uploads/');
    define('TEMPLATES_FILE',    $_b . '/data/templates.json');
    define('CITIES_FILE',       $_b . '/data/cities.json');
    define('PAGE_INDEX_FILE',   $_b . '/data/page-index.json');
    define('PAGES_DIR',         $_b . '/data/pages/');
    define('GEN_LOG_FILE',       $_b . '/data/generation_log.json');
    define('STRUCTURE_LOG_FILE', $_b . '/data/structure_log.json');
    define('AI_REGISTRY_FILE',   $_b . '/data/ai_block_types.json');
    define('REDIRECTS_FILE',     $_b . '/data/redirects.json');
    unset($_b);
} elseif ($_activeSiteId
    && preg_match('/^[a-z0-9][a-z0-9-]{0,59}$/', $_activeSiteId)
    && is_dir(BASE_DIR . '/sites/' . $_activeSiteId)) {
    define('ACTIVE_SITE_ID',   $_activeSiteId);
    define('ACTIVE_SITE_DIR',  BASE_DIR . '/sites/' . $_activeSiteId);
    define('DATA_FILE',        BASE_DIR . '/sites/' . $_activeSiteId . '/data/site.json');
    define('COURSES_FILE',     BASE_DIR . '/sites/' . $_activeSiteId . '/data/courses.json');
    define('UPLOAD_DIR',       BASE_DIR . '/sites/' . $_activeSiteId . '/uploads/');
    define('UPLOAD_URL',       'sites/' . $_activeSiteId . '/uploads/');
    define('TEMPLATES_FILE',    BASE_DIR . '/sites/' . $_activeSiteId . '/data/templates.json');
    define('CITIES_FILE',       BASE_DIR . '/sites/' . $_activeSiteId . '/data/cities.json');
    define('PAGE_INDEX_FILE',   BASE_DIR . '/sites/' . $_activeSiteId . '/data/page-index.json');
    define('PAGES_DIR',         BASE_DIR . '/sites/' . $_activeSiteId . '/data/pages/');
    define('GEN_LOG_FILE',       BASE_DIR . '/sites/' . $_activeSiteId . '/data/generation_log.json');
    define('STRUCTURE_LOG_FILE', BASE_DIR . '/sites/' . $_activeSiteId . '/data/structure_log.json');
    define('AI_REGISTRY_FILE',   BASE_DIR . '/sites/' . $_activeSiteId . '/data/ai_block_types.json');
    define('REDIRECTS_FILE',     BASE_DIR . '/sites/' . $_activeSiteId . '/data/redirects.json');
} else {
    define('ACTIVE_SITE_ID',   '');
    define('ACTIVE_SITE_DIR',  '');
    define('DATA_FILE',        BASE_DIR . '/data/site.json');
    define('COURSES_FILE',     BASE_DIR . '/data/courses.json');
    define('UPLOAD_DIR',       BASE_DIR . '/uploads/');
    define('UPLOAD_URL',       'uploads/');
    define('TEMPLATES_FILE',    BASE_DIR . '/data/templates.json');
    define('CITIES_FILE',       BASE_DIR . '/data/cities.json');
    define('PAGE_INDEX_FILE',   BASE_DIR . '/data/page-index.json');
    define('PAGES_DIR',         BASE_DIR . '/data/pages/');
    define('GEN_LOG_FILE',       BASE_DIR . '/data/generation_log.json');
    define('STRUCTURE_LOG_FILE', BASE_DIR . '/data/structure_log.json');
    define('AI_REGISTRY_FILE',   BASE_DIR . '/data/ai_block_types.json');
    define('REDIRECTS_FILE',     BASE_DIR . '/data/redirects.json');
}
unset($_activeSiteId, $_msBase);

// Page starters are global (shared across all sites)
define('STARTERS_FILE', BASE_DIR . '/data/page_starters.json');

// Anthropic API key — set via admin AI tab, env var, or hardcode here.
// Priority: env var → .ai_key file → empty string.
$_aiKey = getenv('ANTHROPIC_API_KEY') ?: '';
if (!$_aiKey) {
    $_aiKeyFile = __DIR__ . '/.ai_key';
    if (file_exists($_aiKeyFile)) $_aiKey = trim(file_get_contents($_aiKeyFile));
    unset($_aiKeyFile);
}
define('ANTHROPIC_API_KEY', $_aiKey);
unset($_aiKey);
