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

// Where Landing Page Clients' stale-plugin alert cron (tools/lpc_check_alerts.php)
// sends its email — deliberately separate from CONTACT_EMAIL above (that's the
// public contact-form recipient, a different audience/purpose). Empty on purpose
// until a real ops address is set — the alert script checks for this and skips
// sending rather than emailing a placeholder address.
define('LPC_ALERT_EMAIL', '');

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
    define('GENERATION_LOCK_FILE', $_b . '/data/.generation.lock');
    define('AI_REGISTRY_FILE',   $_b . '/data/ai_block_types.json');
    define('REDIRECTS_FILE',     $_b . '/data/redirects.json');
    define('STARTERS_FILE',      $_b . '/data/page_starters.json');
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
    define('GENERATION_LOCK_FILE', BASE_DIR . '/sites/' . $_activeSiteId . '/data/.generation.lock');
    define('AI_REGISTRY_FILE',   BASE_DIR . '/sites/' . $_activeSiteId . '/data/ai_block_types.json');
    define('REDIRECTS_FILE',     BASE_DIR . '/sites/' . $_activeSiteId . '/data/redirects.json');
    define('STARTERS_FILE',      BASE_DIR . '/sites/' . $_activeSiteId . '/data/page_starters.json');
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
    define('GENERATION_LOCK_FILE', BASE_DIR . '/data/.generation.lock');
    define('AI_REGISTRY_FILE',   BASE_DIR . '/data/ai_block_types.json');
    define('REDIRECTS_FILE',     BASE_DIR . '/data/redirects.json');
    define('STARTERS_FILE',      BASE_DIR . '/data/page_starters.json');
}
unset($_activeSiteId, $_msBase);

// The LPC_DELIVERY_* constants that used to live here (remote host/user/path,
// sync key, public status-check base) were removed 2026-11-23 as part of LPC
// Stage 3 — their only consumers (includes/landing_page_clients_publish.php's
// lpc_sync_delivery_to_remote()/lpc_plugin_status(), tools/lpc_check_alerts.php)
// were deleted in the same pass. See docs/lpc-stage3-retirement-scoping.md.
// LPC_DEMO_BOX_* below is unrelated (a different box, kept — see that
// constant's own comment) and LPC_ALERT_EMAIL further below is still used by
// tools/lpc_check_grace_periods.php, also kept.
//
// CLIENTS_DIR itself was wrongly removed in the same pass and restored the
// same day — a real gap in that step's "verified zero remaining references"
// check: includes/agencies.php (kept, the shared billing/session engine)
// still calls lpc_list_clients()/lpc_load_client()/lpc_save_client() etc. to
// account for pre-PlusCities clients billed through an agency. Those
// functions (and the handful of others includes/agencies.php and
// admin/agencies.php/agencies_save.php/impersonate.php still call) were
// restored into includes/agencies.php itself, verbatim from the deleted
// includes/landing_page_clients.php — this constant is what they resolve
// against. The underlying clients/ directory is empty now (its one real
// entry, meade-restoration, was migrated+deleted in the same Stage 3 pass),
// so these functions correctly report zero old-LPC clients rather than
// erroring.
define('CLIENTS_DIR', BASE_DIR . '/clients/');

// The LPC demo-sites box — the 12 rehearsal WordPress installs ("Demo Sites"
// admin tab), entirely separate from the 20-box niche fleet. Reuses
// admin/infra/lib/hestia.php's generic HestiaCP client (same module the
// fleet console uses) for domain create/delete/restart over Hestia's own
// HTTP API — no SSH needed for that. A separate, narrowly-scoped SSH key is
// used ONLY for the WordPress-specific steps the API can't do (cloning the
// golden WP image into a new vhost, running wp-cli, restarting php-fpm).
define('LPC_DEMO_BOX_HOST', '2.24.196.58');
define('LPC_DEMO_BOX_HESTIA_USER', 'lpcadmin');
define('LPC_DEMO_BOX_HESTIA_KEY_FILE', BASE_DIR . '/.lpc_demo_box_api_key.json');
define('LPC_DEMO_BOX_SITE_USER', 'demo'); // the one shared Hestia hosting user all 12 sites are filed under
define('LPC_DEMO_BOX_SSH_KEY', BASE_DIR . '/.lpc_demo_box_ssh_key');
define('LPC_DEMO_BOX_SSH_USER', 'root'); // provisioning-only key, this box only — see comment above

// PlusCities content-engine rewrite (docs/PLUSCITIES_FEATURE_SPEC.md) — was
// built alongside the old LPC engine, not replacing it in place (that old
// engine is now retired, see docs/lpc-stage3-retirement-scoping.md). Its
// own SQLite file (never fleet.db, per docs/lpc-vs-site-factory-boundary.md
// §2) and its own JSON-per-entity directories for the niche library and
// client records, kept separate from the old engine's equivalents so the
// two never collided while both were live.
define('PLUSCITIES_DB_FILE', BASE_DIR . '/admin/infra/state/pluscities.db');
define('PLUSCITIES_NICHE_FAMILIES_DIR', BASE_DIR . '/niche_families/');
define('PLUSCITIES_NICHE_BLUEPRINTS_DIR', BASE_DIR . '/niche_blueprints/');
define('PLUSCITIES_COMPLIANCE_PRESETS_DIR', BASE_DIR . '/compliance_presets/');
define('PLUSCITIES_CLIENTS_DIR', BASE_DIR . '/pluscities_clients/');
// API keys live outside the client JSON on purpose — same reasoning as the
// old engine's clients/*/secrets/ and client_agencies_secrets/: a plaintext
// secret has no business in version control even though the client record
// itself is tracked source. Sibling flat tree, gitignored.
define('PLUSCITIES_CLIENTS_SECRETS_DIR', BASE_DIR . '/pluscities_clients_secrets/');
// Real per-person Agency Editor accounts (spec Section 1/10) — replaces
// today's one-shared-login-per-agency model for that one role. Password
// hash in the sibling secrets dir, same convention as above.
define('PLUSCITIES_AGENCY_USERS_DIR', BASE_DIR . '/pluscities_agency_users/');
define('PLUSCITIES_AGENCY_USERS_SECRETS_DIR', BASE_DIR . '/pluscities_agency_users_secrets/');
// Client logo uploads, their own real photos, and the cached per-client
// branded version of each niche default image — all reuse the existing
// save_uploaded_file() validation (MIME/size/WebP conversion), just pointed
// at this new directory instead of the single-site UPLOAD_DIR.
define('PLUSCITIES_UPLOADS_DIR', BASE_DIR . '/pluscities_uploads/');
define('PLUSCITIES_UPLOADS_URL', '/pluscities_uploads/');

// This platform's own public host — asset URLs handed to a client's WordPress
// site over admin/pluscities_sync.php (hero photos, niche defaults) must be
// absolute: a client site is a different origin, so the root-relative paths
// above resolve to nothing there, same reason BreadcrumbList schema builds
// its own absolute URLs separately from the relative visible breadcrumb nav.
// This repo is the real Apache default vhost on port 80 (confirmed via
// apache2ctl -S — homepage-builder.conf, ServerName _), so the box's own
// public IP reaches it directly; update to a real domain once one exists for
// this platform. The sibling constant in
// wp-plugin/pluscities-delivery/pluscities-delivery.php (pluscities_delivery_base())
// now a real per-install WP option instead of a hardcoded value, since a real
// remote client site and the local test site need genuinely different
// addresses — this one stays a single constant because every client site
// fetches assets from the SAME one platform, never a per-client value.
define('PLUSCITIES_PUBLIC_BASE', 'http://187.127.254.206');

// The legacy SHARED starter library. Page starters used to be global — one file
// for every site on the box, so editing a starter for one site changed it for all
// of them. They are per-site now (STARTERS_FILE, defined per branch above, next to
// its DATA_FILE/TEMPLATES_FILE siblings); this path is kept as a read-only
// fallback so a site that has never edited its starters still inherits the shared
// library instead of dropping to the bare code defaults. The first save on a site
// writes that site's own copy and it stops inheriting. Nothing writes this file
// any more except in legacy single-site mode, where it IS the site's own library.
define('STARTERS_SHARED_FILE', BASE_DIR . '/data/page_starters.json');

// Anthropic API key. Priority: .ai_key file (set via admin AI tab) → env var.
// The explicit, admin-managed .ai_key intentionally wins over ambient env vars:
// a stale env key (e.g. a placeholder in /etc/apache2/envvars exported to the web
// server) would otherwise silently override the key you set and cause
// "invalid x-api-key". The env var remains a fallback when no .ai_key exists.
$_aiKeyFile = __DIR__ . '/.ai_key';
$_aiKey = file_exists($_aiKeyFile) ? trim(file_get_contents($_aiKeyFile)) : '';
if ($_aiKey === '') $_aiKey = getenv('ANTHROPIC_API_KEY') ?: '';
define('ANTHROPIC_API_KEY', $_aiKey);
unset($_aiKey, $_aiKeyFile);

// OpenAI API key (images.php module — AI-generated photo experiments). Same
// file-then-env priority as the Anthropic key above, same reasoning.
$_oaiKeyFile = __DIR__ . '/.openai_key';
$_oaiKey = file_exists($_oaiKeyFile) ? trim(file_get_contents($_oaiKeyFile)) : '';
if ($_oaiKey === '') $_oaiKey = getenv('OPENAI_API_KEY') ?: '';
define('OPENAI_API_KEY', $_oaiKey);
unset($_oaiKey, $_oaiKeyFile);

// Stripe secret key (agency billing — subscriptions, spend caps, the dual-
// lockout mechanism). Same file-then-env priority as the keys above. Test-mode
// key (sk_test_...) during development — never paste a real key into chat;
// set it via the .stripe_secret_key file directly on this box instead.
$_stripeKeyFile = __DIR__ . '/.stripe_secret_key';
$_stripeKey = file_exists($_stripeKeyFile) ? trim(file_get_contents($_stripeKeyFile)) : '';
if ($_stripeKey === '') $_stripeKey = getenv('STRIPE_SECRET_KEY') ?: '';
define('STRIPE_SECRET_KEY', $_stripeKey);
unset($_stripeKey, $_stripeKeyFile);

// Publishable key is not a secret (ships in browser JS by design) but gets
// the same file-then-env treatment for consistency with where it's set.
$_stripePubKeyFile = __DIR__ . '/.stripe_publishable_key';
$_stripePubKey = file_exists($_stripePubKeyFile) ? trim(file_get_contents($_stripePubKeyFile)) : '';
if ($_stripePubKey === '') $_stripePubKey = getenv('STRIPE_PUBLISHABLE_KEY') ?: '';
define('STRIPE_PUBLISHABLE_KEY', $_stripePubKey);
unset($_stripePubKey, $_stripePubKeyFile);

// Webhook signing secret (whsec_...) — verifies an incoming request actually
// came from Stripe before trusting it (admin/stripe_webhook.php). Same
// file-then-env priority as the keys above.
$_stripeWhSecFile = __DIR__ . '/.stripe_webhook_secret';
$_stripeWhSec = file_exists($_stripeWhSecFile) ? trim(file_get_contents($_stripeWhSecFile)) : '';
if ($_stripeWhSec === '') $_stripeWhSec = getenv('STRIPE_WEBHOOK_SECRET') ?: '';
define('STRIPE_WEBHOOK_SECRET', $_stripeWhSec);
unset($_stripeWhSec, $_stripeWhSecFile);

// CallTrackingMetrics agency API credentials (includes/calltrackingmetrics.php).
// Same file-then-env priority as the keys above. Agency-level (Parent Settings),
// not a single-account key, so it can act across every CTM sub-account by id.
$_ctmKeyFile = __DIR__ . '/.ctm_key';
$_ctmKey = file_exists($_ctmKeyFile) ? trim(file_get_contents($_ctmKeyFile)) : '';
if ($_ctmKey === '') $_ctmKey = getenv('CTM_ACCESS_KEY') ?: '';
define('CTM_ACCESS_KEY', $_ctmKey);
unset($_ctmKey, $_ctmKeyFile);

$_ctmSecretFile = __DIR__ . '/.ctm_secret';
$_ctmSecret = file_exists($_ctmSecretFile) ? trim(file_get_contents($_ctmSecretFile)) : '';
if ($_ctmSecret === '') $_ctmSecret = getenv('CTM_SECRET_KEY') ?: '';
define('CTM_SECRET_KEY', $_ctmSecret);

// Login activity tracking for the Platform Information admin page — hooked
// here, not in functions.php, so every authenticated request updates it
// regardless of which admin page it is. No-ops instantly for the vast
// majority of requests (public site visitors, nobody logged in).
require_once __DIR__ . '/includes/platform_sessions.php';
platform_session_heartbeat();
unset($_ctmSecret, $_ctmSecretFile);
