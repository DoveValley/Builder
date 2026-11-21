<?php
/**
 * Platform Information — super_admin-only diagnostics about the platform
 * itself, starting with "Who's Logged In" (live sessions + a daily login
 * history, both sourced from includes/platform_sessions.php). More tabs can
 * be added to $TABS below as they're needed; the tab-bar scaffold already
 * supports it.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/platform_sessions.php';
require_once __DIR__ . '/lpc_admin_layout.php';

if (empty($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}
if (lpc_current_actor_role() !== 'super_admin') {
    header('Location: clients.php?msg=error:Platform+information+is+for+the+platform+operator+only');
    exit;
}

$TABS = [
    'who_logged_in'        => "Who's Logged In",
    'how_it_works'         => 'How This Works',
    'roadmap'              => 'PlusCities Product Roadmap',
    'content_workflow'     => 'Content Workflow',
    'client_approval_stage'=> 'Client Approval Stage',
];
$tab = $_GET['tab'] ?? 'who_logged_in';
if (!isset($TABS[$tab])) $tab = 'who_logged_in';

function platform_info_fmt_duration(int $seconds): string {
    if ($seconds < 60) return $seconds . 's';
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    return $h > 0 ? sprintf('%dh %dm', $h, $m) : sprintf('%dm', $m);
}

/**
 * Gap analysis of the live LPC/PlusCities code against the feature/permissions
 * spec Scott supplied 2026-11-20 (5-role model: Platform Owner, Agency Admin,
 * Agency Editor, Client Approver, Client Viewer). Verified by direct code
 * read, not guessed — a point-in-time snapshot, re-check against the code
 * before trusting an old "Complete" after a big change in that area.
 *
 * Every "to be done" row also carries:
 *   - gate:   'sell' (must-have before selling to a real agency), 'retain'
 *             (matters for keeping agencies paying, not launch-blocking), or
 *             'differentiate' (nice-to-have, can wait indefinitely).
 *   - effort: 'quick' (config/copy/a field — hours), 'moderate' (a contained
 *             new feature — days), or 'major' (architecture-level — the
 *             longest pole, start it early even though it's hard).
 * Both are a judgment call made 2026-11-20, not a measured estimate — re-rank
 * if the underlying code shifts a row's actual difficulty.
 */
function platform_info_roadmap_data(): array {
    return [
        [
            'title' => 'Role model & permissions',
            'rows' => [
                ['cap' => '5-role model (Owner / Agency Admin / Agency Editor / Client Approver / Client Viewer)', 'roles' => 'All', 'status' => 'todo', 'gate' => 'retain', 'effort' => 'major',
                 'detail' => "Only 3 roles exist today: super_admin, agency_admin, end_client — no Agency Editor, no Approver/Viewer split. Needs a 4th/5th role plus a real multi-user invite-and-assign system; today each agency and each client has exactly one shared login, not per-person accounts. Retention, not launch-blocking: a single shared login can carry a first pilot agency through; this matters once agencies have their own staff to onboard."],
                ['cap' => 'Self-Managed Client (client upgraded to Editor rights on own site)', 'roles' => 'Client Approver', 'status' => 'todo', 'gate' => 'differentiate', 'effort' => 'moderate',
                 'detail' => 'No such flag exists in the client data model. Needs a self_managed toggle plus a scoped-to-one-client version of Editor permissions. Differentiator: a nice upsell path once a client wants more control, not something a first agency sale needs.'],
                ['cap' => 'Server-side permission checks on every endpoint, not just hidden in the UI', 'roles' => 'All', 'status' => 'complete',
                 'detail' => 'Client/agency scoping (lpc_load_client_for_current_actor, lpc_list_clients_for_current_actor) is applied consistently across the admin — verified in the code, not just assumed from what the UI hides.'],
            ],
        ],
        [
            'title' => 'Pages & content',
            'rows' => [
                ['cap' => 'View pages, including drafts', 'roles' => 'Owner, Agency Admin, Agency Editor, Client Approver, Client Viewer', 'status' => 'todo', 'gate' => 'sell', 'effort' => 'moderate',
                 'detail' => "Complete for Owner/Agency Admin/Editor — full draft/published matrix in the client workspace. Not started for Client Approver/Viewer: the only End Client screen (client_portal.php) is a reporting rollup, not a page browser — there's nothing for a client to actually look at or approve. Must-have to sell: a client that can't see what they're approving breaks the whole approve-before-live pitch — the Client Approver role is pointless without it."],
                ['cap' => 'Generate pages (new city × service combos)', 'roles' => 'Owner, Agency Admin, Agency Editor', 'status' => 'complete',
                 'detail' => 'Adding a target city auto-drafts real AI content for every service at that city.'],
                ['cap' => 'Edit section text, titles, meta', 'roles' => 'Owner, Agency Admin, Agency Editor', 'status' => 'complete',
                 'detail' => 'Full field editor per section type, plus SEO title/meta/keywords.'],
                ['cap' => 'Lock / unlock sections or pages', 'roles' => 'Owner, Agency Admin, Agency Editor', 'status' => 'todo', 'gate' => 'retain', 'effort' => 'moderate',
                 'detail' => "Whole-page lock exists and blocks Regenerate. No section-level lock — locking a page locks everything in it, so you can't protect just the intro while regenerating the rest. Retention: whole-page lock already protects a finished page; section-level control is a workflow upgrade for agencies already working in the tool, not a launch blocker."],
                ['cap' => 'Regenerate with an instruction field ("make it shorter")', 'roles' => 'Owner, Agency Admin, Agency Editor', 'status' => 'todo', 'gate' => 'retain', 'effort' => 'moderate',
                 'detail' => 'Regenerate (single page) and bulk refresh (checked pages) both exist, but neither takes a free-text instruction — every regenerate reruns the same fixed prompt template. Retention: the fixed-prompt regenerate already produces a usable draft; a steerable instruction field is a quality-of-life upgrade, not a blocker to a first sale.'],
                ['cap' => 'Roll back to a previous version (history + diff)', 'roles' => 'Owner, Agency Admin, Agency Editor', 'status' => 'todo', 'gate' => 'retain', 'effort' => 'major',
                 'detail' => "No version history at all. Every save overwrites in place — there's nothing to roll back to. Retention: a real safety net once agencies are editing live client content daily — a first handful of pilot clients can get by with careful manual edits in the meantime."],
                ['cap' => 'Swap / upload images with alt text', 'roles' => 'Owner, Agency Admin, Agency Editor', 'status' => 'complete',
                 'detail' => 'Manual upload or AI-generate per service, real alt-text fields, WebP output, lazy-loading below the fold.'],
            ],
        ],
        [
            'title' => 'Workflow',
            'rows' => [
                ['cap' => 'Agency approve step', 'roles' => 'Owner, Agency Admin, Agency Editor', 'status' => 'complete',
                 'detail' => 'Two-stage gate: pending_platform_review -> pending_agency_review -> approved.'],
                ['cap' => 'Client approve / request-changes step (per-client toggle)', 'roles' => 'Client Approver', 'status' => 'todo', 'gate' => 'sell', 'effort' => 'major',
                 'detail' => "Doesn't exist — End Client has no approval role today at all. The existing approval gate is all-or-nothing per agency (Scott's own agency skips it, every other agency always goes through it), not a per-client toggle, and the client never participates in it. Must-have to sell: this is the core differentiator in the pitch — agency approves, then the client approves, before anything goes live. Without it there's no real client sign-off, just an agency's word."],
                ['cap' => 'Publish / unpublish', 'roles' => 'Owner, Agency Admin, Agency Editor', 'status' => 'todo', 'gate' => 'sell', 'effort' => 'quick',
                 'detail' => "Publish exists. There's no per-page unpublish — only deactivating a client's entire API key, which takes every page down at once, not one. Must-have to sell: an agency needs to be able to pull one bad page without killing a client's whole site — the all-or-nothing kill switch isn't a real substitute."],
                ['cap' => 'Bulk approve / publish / unpublish, by selection, city, service, or all', 'roles' => 'Owner, Agency Admin, Agency Editor', 'status' => 'todo', 'gate' => 'retain', 'effort' => 'moderate',
                 'detail' => 'The page workspace has bulk publish/generate by checkbox selection. The Approvals queue itself is one row at a time — no bulk approve, and nothing filters by city or service. Retention: matters once page volume is real; one-row-at-a-time approval is tolerable for a first few clients.'],
                ['cap' => 'Validator / override flags (banned phrases, NAP mismatch)', 'roles' => 'Owner, Agency Admin', 'status' => 'todo', 'gate' => 'sell', 'effort' => 'moderate',
                 'detail' => "Doesn't exist anywhere in the product — nothing blocks a page from being approved for a banned phrase or a wrong phone number. Must-have to sell: the product's whole value is agencies trusting the content is accurate and compliant before it goes live; nothing today actually enforces that."],
                ['cap' => 'Comment on pages', 'roles' => 'All', 'status' => 'todo', 'gate' => 'differentiate', 'effort' => 'moderate',
                 'detail' => 'No comment feature anywhere in the product. Differentiator: a collaboration nicety — approve/publish already works end to end without it.'],
            ],
        ],
        [
            'title' => 'Client setup',
            'rows' => [
                ['cap' => 'Create / delete clients and sites', 'roles' => 'Owner, Agency Admin', 'status' => 'complete',
                 'detail' => '5-step onboarding wizard; a delete action exists.'],
                ['cap' => 'Client facts (NAP, licenses, hours, years in business, tone, brand terms)', 'roles' => 'Owner, Agency Admin, Agency Editor', 'status' => 'todo', 'gate' => 'sell', 'effort' => 'quick',
                 'detail' => 'Has phone, a free-text tone field, and always-mention/never-mention guardrails. Missing entirely: a structured address, license numbers, hours, years-in-business — none of these fields exist on a client yet. Must-have to sell: license numbers, hours, and years-in-business are exactly the NAP-adjacent facts an agency expects to enter and see reflected in the content — their absence is an obvious gap in a first demo.'],
                ['cap' => 'Banned-phrases list + niche compliance presets (toggleable)', 'roles' => 'Owner, Agency Admin', 'status' => 'todo', 'gate' => 'sell', 'effort' => 'moderate',
                 'detail' => "Doesn't exist. always_mention/never_mention is free text with no niche-default inheritance and no on/off toggle. Must-have to sell: pairs with the Workflow section's Validator row above — without a banned-phrases list there's nothing to actually validate against."],
                ['cap' => 'Generate / rotate the API key', 'roles' => 'Owner, Agency Admin', 'status' => 'complete',
                 'detail' => 'One-click regenerate.'],
                ['cap' => 'Connect Google Search Console per site', 'roles' => 'Owner, Agency Admin, Client Approver', 'status' => 'todo', 'gate' => 'retain', 'effort' => 'major',
                 'detail' => "Works, but isn't OAuth — it's a shared service-account key; the client adds that service-account email as a read-only user on their own GSC property by hand, then an admin pastes the property URL in. Retention: the manual service-account method already works end to end; real OAuth is a polish upgrade, not a launch blocker."],
                ['cap' => 'Connect call tracking (CallRail or agency\'s own number)', 'roles' => 'Owner, Agency Admin', 'status' => 'todo', 'gate' => 'differentiate', 'effort' => 'moderate',
                 'detail' => "Doesn't exist. Pages just show the hub's real phone number as a tel: link — no CallRail integration, no per-client tracking-number pool. Differentiator: the hub's real phone number already gives a client something to show for the spend; per-page tracking is a later upsell."],
            ],
        ],
        [
            'title' => 'Reporting',
            'rows' => [
                ['cap' => 'View reports', 'roles' => 'All (own scope)', 'status' => 'complete',
                 'detail' => 'Basic reporting tab (Owner/Agency) and a read-only portal (Client), both working.'],
                ['cap' => 'Automated monthly white-labeled PDF reports, emailed', 'roles' => 'Owner, Agency Admin', 'status' => 'todo', 'gate' => 'retain', 'effort' => 'moderate',
                 'detail' => 'No PDF generation and no scheduled email report exist anywhere in the codebase. Retention: explicitly a Tier 2 feature in the spec — keeps agencies paying after launch, doesn\'t block the first sale.'],
                ['cap' => 'View leads, by city and by service', 'roles' => 'Owner, Agency Admin, Agency Editor, Client Approver', 'status' => 'todo', 'gate' => 'retain', 'effort' => 'major',
                 'detail' => '"Tried to Contact" is a raw click counter on tel:/CTA links — no name or contact info is ever captured, so there\'s no actual lead to list by city or service. Retention: needs real lead capture to exist first (see the Tier 2 CTA form row); not a launch blocker on its own.'],
                ['cap' => 'GSC dashboard: impressions, clicks, CTR, position, trend', 'roles' => 'All (own scope)', 'status' => 'todo', 'gate' => 'retain', 'effort' => 'moderate',
                 'detail' => 'Only a 28-day click total per page is pulled today. No impressions, no CTR, no position, no trend over time, no "impressions but no clicks" view. Retention: the existing 28-day click total is a usable stopgap for a first cohort of agencies.'],
            ],
        ],
        [
            'title' => 'SEO essentials',
            'rows' => [
                ['cap' => 'JSON-LD schema (LocalBusiness/Service/FAQPage), canonical, sitemap', 'roles' => 'n/a (built-in to every page)', 'status' => 'complete',
                 'detail' => 'Confirmed well-built: real schema per page, a self-served sitemap.xml linked via robots.txt, and real handling of the Yoast canonical-tag conflict.'],
                ['cap' => 'Cannibalization check, near-duplicate check, slug pattern per site, breadcrumbs', 'roles' => 'Owner, Agency Admin', 'status' => 'todo', 'gate' => 'sell', 'effort' => 'moderate',
                 'detail' => "Not directly confirmed either way in this audit — no code reference to any of these four surfaced while checking everything else, so treat as most likely not built until checked directly, not as a confirmed gap. Must-have to sell: an SEO-literate agency will test this on day one; failing it undermines trust in everything else the product claims about SEO."],
            ],
        ],
        [
            'title' => 'Agency admin',
            'rows' => [
                ['cap' => 'Invite / remove users, assign roles', 'roles' => 'Agency Admin', 'status' => 'todo', 'gate' => 'retain', 'effort' => 'major',
                 'detail' => "Each agency has exactly one shared login. There's no per-person user list or invite flow at all. Retention: becomes necessary once an agency wants to add its own staff; a single shared login works fine for a first pilot."],
                ['cap' => 'White-label: logo, colors, custom domain, plugin name — zero PlusCities/AI visible to a client', 'roles' => 'Agency Admin', 'status' => 'todo', 'gate' => 'sell', 'effort' => 'quick',
                 'detail' => "Highest-priority gap found in this whole audit. This admin supports a display name + logo for an agency — that part works. But the WordPress plugin shipped into every client's own site is hardcoded \"Landing Page Client\" / \"Site Factory\", can't be renamed, and its own in-admin info popup — visible to anyone with WP admin access on the CLIENT'S OWN SITE — literally says \"real AI content generation,\" \"AI-drafted pages,\" \"the Regenerate button is real now.\" This directly violates the \"never present as an AI product\" rule on the single most client-visible surface that exists. Mostly copy/branding-string edits, not new architecture — fix this before anything else on this page. Must-have to sell: this is the single biggest risk to kill a sale or create a support fire the moment an agency notices it."],
                ['cap' => 'Agency billing/plan/invoices + Stripe Connect rebilling to its own clients', 'roles' => 'Agency Admin', 'status' => 'todo', 'gate' => 'differentiate', 'effort' => 'major',
                 'detail' => 'Real Stripe billing exists per agency today — checkout, webhook signatures verified with real HMAC, failed-payment handling. Agency-rebills-its-own-clients via Stripe Connect is explicitly not built; deferred on purpose, noted in the code itself. Differentiator: agencies can already bill their own clients outside the platform; this just saves them a step later.'],
                ['cap' => 'Cancel a client: 301-vs-410 choice, 14-day grace, plugin auto-handles, footer link removed', 'roles' => 'Agency Admin', 'status' => 'todo', 'gate' => 'sell', 'effort' => 'moderate',
                 'detail' => "Doesn't exist as designed — and the current plugin cache actively works against it. Today \"cancel\" just disables a client's API key, but the plugin falls back to its last-known-good cached copy on any failed fetch and keeps serving those pages indefinitely. Disabling a client does NOT take their pages down. A real redirect/takedown mechanism needs to be built, not just wired to the existing toggle. Must-have to sell: an agency won't trust the platform with real client relationships if canceling doesn't actually work — this is a liability, not a missing nicety."],
                ['cap' => 'Audit log (who did what, when — filterable/exportable)', 'roles' => 'Agency Admin (read)', 'status' => 'todo', 'gate' => 'retain', 'effort' => 'moderate',
                 'detail' => "Doesn't exist. Only impersonation start/stop write to the server error log — nothing records edits, publishes, or deletes, and none of it is queryable or exportable. Retention/trust feature once real money and real client relationships are flowing — not something a prospect checks before signing up."],
            ],
        ],
        [
            'title' => 'Platform only',
            'rows' => [
                ['cap' => 'Manage agencies, wholesale pricing, plans', 'roles' => 'Owner', 'status' => 'todo', 'gate' => 'retain', 'effort' => 'quick',
                 'detail' => "Can create agencies and set a fee, but there's no actual pricing-tier catalog — just a free-text plan field and a number. Retention/ops convenience for Scott; a free-text plan field works fine for the first handful of agencies."],
                ['cap' => 'Edit niche presets / default banned phrases', 'roles' => 'Owner', 'status' => 'todo', 'gate' => 'sell', 'effort' => 'quick',
                 'detail' => 'Niche Profiles exist (tone, "AI guardrails" per niche) but there\'s no distinct "default banned phrases" concept separate from that free-text guardrail blob. Must-have to sell: the easy half of the validator story — pairs with the Client Setup section\'s banned-phrases row.'],
                ['cap' => 'Impersonate any user — logged, visible banner', 'roles' => 'Owner', 'status' => 'complete',
                 'detail' => 'One-hop-only (no stacking), orange banner always shown, every start/stop logged.'],
                ['cap' => 'Suspend an agency/site or force-unpublish', 'roles' => 'Owner', 'status' => 'todo', 'gate' => 'retain', 'effort' => 'quick',
                 'detail' => "Agency suspend/reactivate exists. There's no platform-level per-site or per-page force-unpublish beyond what an agency can already do to itself. Retention: a safety net for when things go wrong at scale, not something needed to close a first sale."],
                ['cap' => 'Global usage, health and revenue dashboard', 'roles' => 'Owner', 'status' => 'todo', 'gate' => 'retain', 'effort' => 'moderate',
                 'detail' => 'This Platform Information page currently covers login history only. No fleet-wide view of plugin versions/themes/site health, and no revenue rollup beyond reading each agency\'s row individually. Retention/ops visibility for Scott as the fleet grows; invisible to the agency or client either way.'],
            ],
        ],
        [
            'title' => 'Plugin (cross-cutting — affects every client site)',
            'rows' => [
                ['cap' => 'Pulls only live pages, via signed API-key requests', 'roles' => 'n/a', 'status' => 'todo', 'gate' => 'retain', 'effort' => 'moderate',
                 'detail' => 'The "only published pages" guarantee is real and correct. "Signed" is not — it\'s a plain bearer token in a URL parameter, no HMAC signature, no replay protection. Retention/security hardening: matters once real client traffic and data are flowing at volume; the current bearer-token approach isn\'t broken, just not hardened.'],
                ['cap' => 'Local cache + offline fallback if the server is down', 'roles' => 'n/a', 'status' => 'complete',
                 'detail' => 'Poll-based (not webhook-based), but genuinely verified: pages kept rendering after the delivery server was shut off entirely during testing.'],
                ['cap' => 'Reports health to server (version, theme, SEO plugin, last sync)', 'roles' => 'Owner, Agency Admin', 'status' => 'todo', 'gate' => 'retain', 'effort' => 'quick',
                 'detail' => "Only a last-checkin timestamp is sent today. Plugin version, active theme, and active SEO plugin are detected locally for rendering decisions but never phoned home — so there's no way to see from here which clients are running an outdated plugin. Retention/ops: lets Scott spot an outdated plugin across the fleet; invisible to the agency or client either way."],
                ['cap' => 'Clean uninstall (no orphan data left behind)', 'roles' => 'n/a', 'status' => 'todo', 'gate' => 'retain', 'effort' => 'quick',
                 'detail' => "No custom database tables, so no orphan-table risk — but there's also no uninstall hook at all. Removing the plugin leaves its option rows behind in the client's database forever. Retention/professionalism: only matters once a client actually removes the plugin, which won't happen before or during a first sale."],
            ],
        ],
        [
            'title' => 'Positioning compliance — "never present as an AI product"',
            'rows' => [
                ['cap' => 'No AI/generate/regenerate/prompt language anywhere a client can see it', 'roles' => 'All', 'status' => 'todo', 'gate' => 'sell', 'effort' => 'quick',
                 'detail' => "Violated in several places today, worst inside the WP plugin's own in-admin popup — visible on the client's own WordPress install (see the White-label row above). Also present throughout the agency-facing admin (client.php, page_editor.php, client_save.php, client_wizard.php — buttons/labels/confirm dialogs saying \"AI\", \"Regenerate\", \"AI-generated\"). Genuinely clean today: the End Client's own portal and the actual page HTML a visitor sees — neither has ever shown AI language. Fix order: the plugin popup first (worst exposure, lives inside the client's own site), then a wording pass over the agency-facing screens toward the spec's own suggested phrase — \"Request an update\" instead of \"Regenerate.\" Must-have to sell: this directly contradicts the core sales pitch — \"never looks like AI\" — the moment an agency or their client actually looks."],
            ],
        ],
        [
            'title' => 'Tier 2 — retention',
            'rows' => [
                ['cap' => 'Lead-capture CTA form (city/service attached, emailed + webhook)', 'roles' => 'Owner, Agency Admin', 'status' => 'todo', 'gate' => 'retain', 'effort' => 'major',
                 'detail' => "Doesn't exist. Only tel:/CTA click counters exist today — no actual form, no email-on-submit, no webhook. Retention: a stronger renewal story later, but \"Tried to Contact\" click counts are enough evidence to close a first sale."],
                ['cap' => 'Bulk expansion: "Add city" / "Add service" / CSV import', 'roles' => 'Owner, Agency Admin, Agency Editor', 'status' => 'todo', 'gate' => 'retain', 'effort' => 'moderate',
                 'detail' => '"Add city" (draft every service for a new city) works and is genuinely complete. Whether "Add service" retroactively expands across every existing city was not confirmed either way in this audit — test directly before relying on it. CSV import of cities/services doesn\'t exist for this product (it exists only for the unrelated niche-fleet system). Retention: matters once an agency is actively growing an existing client\'s page count; "Add city" already works for onboarding a new one.'],
                ['cap' => 'Plan-limit enforcement, overage -> upgrade prompt', 'roles' => 'Owner, Agency Admin', 'status' => 'todo', 'gate' => 'retain', 'effort' => 'moderate',
                 'detail' => 'Billing today is a flat rate per location, not a page-count tier — there\'s no cap and no overage/upgrade-prompt logic. Retention: becomes necessary once there are enough real agencies to need usage guardrails; nothing stops a first sale from running without it.'],
            ],
        ],
        [
            'title' => 'Tier 3 — differentiators',
            'rows' => [
                ['cap' => 'Scheduled refresh (quarterly/seasonal regeneration)', 'roles' => 'Owner, Agency Admin', 'status' => 'todo', 'gate' => 'differentiate', 'effort' => 'major',
                 'detail' => "Doesn't exist — the only scheduling that exists controls the plugin's own cache refresh interval, not server-side content regeneration. Differentiator: a freshness nicety well after launch, not something a prospect asks about before buying."],
                ['cap' => 'GSC-driven expansion suggestions', 'roles' => 'Owner, Agency Admin', 'status' => 'todo', 'gate' => 'differentiate', 'effort' => 'major', 'detail' => "Doesn't exist. Differentiator: a nice-to-have upsell prompt once there's real ranking data to act on."],
                ['cap' => 'Spanish /es/ version + hreflang', 'roles' => 'Owner, Agency Admin', 'status' => 'todo', 'gate' => 'differentiate', 'effort' => 'moderate', 'detail' => "Doesn't exist for this product. Differentiator: only relevant to agencies with bilingual-market clients — a narrow vertical expansion, not a launch gap."],
                ['cap' => 'Webhooks / Zapier outbound events', 'roles' => 'Owner, Agency Admin', 'status' => 'todo', 'gate' => 'differentiate', 'effort' => 'moderate',
                 'detail' => 'Doesn\'t exist — the only webhook in the system is Stripe\'s inbound one. Differentiator: an integration nicety for technically sophisticated agencies, not something a first sale depends on.'],
            ],
        ],
    ];
}

/**
 * The real content lifecycle, start to finish, as it actually works in the
 * live code today — verified by reading client_wizard.php, client.php,
 * page_editor.php, client_save.php, and includes/landing_page_clients_
 * approvals.php directly (2026-11-21), not carried over from memory. Gaps
 * (status 'needs_adding') are interleaved in the sequence at the point
 * they'd actually happen, not listed separately, so the page reads as one
 * real end-to-end flow rather than two disconnected lists.
 */
function platform_info_content_workflow_data(): array {
    return [
        [
            'title' => 'A — Create & draft',
            'rows' => [
                ['step' => 'Create the client & define scope', 'status' => 'in_place',
                 'simple' => 'This is where you set up a brand-new client in the system.',
                 'what' => 'The onboarding wizard: business identity + niche, services offered, hub office(s), target cities per hub.',
                 'who' => 'Owner, Agency Admin', 'where' => 'client_wizard.php (5 steps)'],
                ['step' => 'Auto-draft every page', 'status' => 'in_place',
                 'simple' => 'The system automatically writes a first draft of every page for you.',
                 'what' => "Finishing the wizard immediately AI-drafts a page for every city \u{d7} service combination (real AI cost per page). Nothing publishes yet.",
                 'who' => 'System — triggered by finishing the wizard', 'where' => 'Automatic, part of the client_wizard.php submit'],
                ['step' => 'Set the Content Profile', 'status' => 'in_place',
                 'simple' => 'This is where you tell the system how the client wants to sound and what to avoid mentioning.',
                 'what' => "Tone, excluded services, and always-mention/never-mention guardrails — meant to be set before reviewing drafts. Missing fields: structured address, license numbers, hours, years-in-business (see the Product Roadmap tab's Client Setup section).",
                 'who' => 'Owner, Agency Admin', 'where' => 'client.php → Content Profile tab'],
                ['step' => 'Research a city', 'status' => 'in_place',
                 'simple' => "This pulls in real facts about a city so the content isn't generic.",
                 'what' => 'Real Census population lookup — free, runs once per unique city among the pages checked.',
                 'who' => 'Owner, Agency Admin', 'where' => 'client.php → Locations & Services tab → "Research Selected"'],
            ],
        ],
        [
            'title' => 'B — Edit, lock & regenerate',
            'rows' => [
                ['step' => 'Edit page content by hand', 'status' => 'in_place',
                 'simple' => 'This is where someone can manually change the words on a page.',
                 'what' => 'A field editor per section (hero, intro, steps, FAQ, etc.) plus SEO title/meta/keywords. Editing an already-published page flips its status to "Needs Update" rather than silently leaving it shown as current.',
                 'who' => 'Owner, Agency Admin', 'where' => 'page_editor.php'],
                ['step' => 'Regenerate a page with AI', 'status' => 'in_place',
                 'simple' => "This tells the system to rewrite a page's content from scratch.",
                 'what' => 'Reruns the fixed AI prompt template for one page, or "Generate / Refresh Selected" for many at once — with its own checkboxes for refresh hero image, re-fetch climate data ($), regenerate text ($), and skip locked pages.',
                 'who' => 'Owner, Agency Admin', 'where' => 'page_editor.php "Regenerate" button, or client.php → Locations & Services → "Generate / Refresh Selected"'],
                ['step' => 'Steer a regenerate with an instruction ("make it shorter")', 'status' => 'needs_adding',
                 'simple' => "This would let someone tell the system exactly how to change the rewrite, like \"make it shorter.\"",
                 'what' => "Doesn't exist — every regenerate reruns the identical fixed prompt, with no free-text instruction field.",
                 'who' => 'Would be: Owner, Agency Admin', 'where' => 'Would extend the existing Regenerate control in page_editor.php'],
                ['step' => 'Lock a page', 'status' => 'in_place',
                 'simple' => "This protects a finished page so it can't be accidentally overwritten.",
                 'what' => 'Blocks Regenerate from touching that page. Whole-page only — locking a page locks every section in it.',
                 'who' => 'Owner, Agency Admin', 'where' => 'page_editor.php "Lock" toggle (also shown in the Locations & Services Status column)'],
                ['step' => 'Lock one section within a page', 'status' => 'needs_adding',
                 'simple' => 'This would let someone protect just one part of a page instead of the whole thing.',
                 'what' => "Doesn't exist — there's no way to protect just the intro while regenerating the rest of the same page.",
                 'who' => 'Would be: Owner, Agency Admin', 'where' => 'Would extend page_editor.php'],
                ['step' => 'Roll back to a previous version', 'status' => 'needs_adding',
                 'simple' => 'This would let someone undo a change and go back to an earlier version of a page.',
                 'what' => "Doesn't exist — every save overwrites in place. There's no version history and nothing to roll back to.",
                 'who' => 'Would be: Owner, Agency Admin', 'where' => 'Would be a new history/diff view on page_editor.php'],
                ['step' => 'Validate content before it can be approved', 'status' => 'needs_adding',
                 'simple' => "This would automatically check a page for mistakes or forbidden wording before it's allowed to go live.",
                 'what' => "Doesn't exist — nothing blocks a page with a banned phrase or a wrong phone number from being approved and published.",
                 'who' => 'Would run automatically at submit/approve time', 'where' => 'Would live inside the approvals.php gate'],
            ],
        ],
        [
            'title' => 'C — Review & approve',
            'rows' => [
                ['step' => 'Submit for review', 'status' => 'in_place',
                 'simple' => 'This sends a finished page off to be checked before it goes live.',
                 'what' => "Clicking Publish. For Scott's own agency's clients this publishes immediately, no gate. For a real third-party agency's clients it instead enters a two-stage approval queue (approval_stage: pending_platform_review).",
                 'who' => 'Owner, Agency Admin', 'where' => 'page_editor.php "Publish" button, or client.php → Locations & Services → "Publish Selected"'],
                ['step' => 'Platform review — stage 1, real agencies only', 'status' => 'in_place',
                 'simple' => "This is Scott's first check on a page an agency submitted.",
                 'what' => "Scott reviews what an agency submitted. \"Approve → Agency\" advances it to pending_agency_review; \"Reject\" sends it back with a note — the page's own content is untouched, just not live.",
                 'who' => 'Platform Owner only', 'where' => 'approvals.php'],
                ['step' => 'Agency review — stage 2, real agencies only', 'status' => 'in_place',
                 'simple' => "This is the agency's final sign-off before a page goes live.",
                 'what' => '"Approve & Publish" both clears this stage and actually publishes the page in one step; "Reject" sends it back with a note.',
                 'who' => 'Agency Admin', 'where' => 'approvals.php'],
                ['step' => 'Client review / approval', 'status' => 'needs_adding',
                 'simple' => "This would let the actual business owner give the final okay before their own page goes live.",
                 'what' => "Doesn't exist — the End Client has no role in approval today at all. The gate stops at the agency; the client never sees or signs off on a page before it goes live.",
                 'who' => 'Would be: Client Approver', 'where' => 'Would be a new screen on client_portal.php'],
                ['step' => 'Bulk-approve the review queue, or filter it by city/service', 'status' => 'needs_adding',
                 'simple' => 'This would let someone approve many pages at once instead of one at a time.',
                 'what' => "Doesn't exist — approvals.php is one row at a time, with no bulk action and no city/service filter.",
                 'who' => 'Would be: Platform Owner, Agency Admin', 'where' => 'Would extend approvals.php'],
            ],
        ],
        [
            'title' => 'D — Publish & go live',
            'rows' => [
                ['step' => 'Page actually goes live', 'status' => 'in_place',
                 'simple' => "This is the moment a page becomes visible to real visitors on the client's website.",
                 'what' => "Writes the published page and pushes it to the delivery server the client's WordPress plugin fetches from, rendering inside the client's own theme header/footer.",
                 'who' => 'System — triggered by Publish / Approve & Publish', 'where' => 'Automatic (delivery server)'],
                ['step' => "Connect the client's WordPress site", 'status' => 'in_place',
                 'simple' => "This is how the client's website gets hooked up to actually display the pages.",
                 'what' => "Download the plugin zip and copy the API key (a built-in compose-email form). Once the client pastes the key into their plugin's settings, the Clients list badge flips to Connected.",
                 'who' => 'Owner/Agency Admin send it; the client\'s own WP admin installs it', 'where' => 'client.php → Delivery tab; status shown on clients.php'],
                ['step' => "Check what's actually live", 'status' => 'in_place',
                 'simple' => "This double-checks that what's really showing on the client's site matches what it should.",
                 'what' => "Fetches each page's real public URL and compares it against what the data says it should be (hero image, area map, climate chart) — catches a stale CDN cache that a status badge alone would miss.",
                 'who' => 'Owner, Agency Admin', 'where' => 'client.php → Locations & Services → "Check Live Selected"'],
                ['step' => 'Take a single page down', 'status' => 'needs_adding',
                 'simple' => 'This would let someone remove just one page without touching the rest of the site.',
                 'what' => "Doesn't exist as a per-page action — the only kill switch today deactivates the client's entire API key, taking every page down at once, not one.",
                 'who' => 'Would be: Owner, Agency Admin', 'where' => 'Would extend page_editor.php / Locations & Services'],
            ],
        ],
        [
            'title' => 'E — Ongoing: monitor, update, cancel',
            'rows' => [
                ['step' => 'Track visitor engagement', 'status' => 'in_place',
                 'simple' => 'This counts how many people tap the phone number or call-to-action on a page.',
                 'what' => 'The plugin automatically counts taps on a page\'s phone number/CTA buttons ("Tried to Contact"), no setup required. It\'s an intent signal, not a confirmed lead.',
                 'who' => 'Automatic (plugin)', 'where' => 'client.php → Reporting tab; totals on clients.php'],
                ['step' => 'Connect Google Search Console', 'status' => 'in_place',
                 'simple' => "This connects the client's real Google data so you can see actual search traffic numbers.",
                 'what' => 'The client adds a shared service-account email as a Restricted (read-only) user on their own GSC property by hand; an admin pastes the property URL in and a real 28-day click total per page starts showing. No impressions/CTR/position/trend yet.',
                 'who' => 'Owner/Agency Admin paste the URL; the client grants access', 'where' => 'client.php → Reporting tab'],
                ['step' => 'Refresh content on a schedule (quarterly/seasonal)', 'status' => 'needs_adding',
                 'simple' => 'This would automatically update page content every so often without anyone having to ask for it.',
                 'what' => "Doesn't exist — nothing regenerates content automatically; every refresh today is manually triggered.",
                 'who' => 'Would be: System, on a schedule', 'where' => 'Would be a new scheduler'],
                ['step' => 'Cancel a client properly', 'status' => 'needs_adding',
                 'simple' => "This would be the correct way to shut down a client's pages for good.",
                 'what' => "Doesn't exist as designed — today \"cancel\" just disables the API key, but the plugin's own cache keeps serving the client's last-known-good pages indefinitely regardless. Disabling a client does NOT actually take their pages down.",
                 'who' => 'Would be: Agency Admin', 'where' => 'Would extend client.php'],
                ['step' => 'See a record of who did what, when', 'status' => 'needs_adding',
                 'simple' => 'This would keep a history log of every change anyone made and when they made it.',
                 'what' => "Doesn't exist — only impersonation start/stop is logged anywhere; content edits, publishes, and deletes leave no trail.",
                 'who' => 'Would be: Agency Admin (read), Platform Owner', 'where' => 'Would be a new audit log'],
            ],
        ],
    ];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Platform Information — <?= h(SITE_TITLE) ?></title>
<link rel="stylesheet" href="../assets/css/style.css">
<?php $_adminCssMtime = css_minify_to(__DIR__ . '/../assets/css/admin.src.css', __DIR__ . '/../assets/css/admin.css'); ?>
<link rel="stylesheet" href="../assets/css/admin.css?v=<?= (int) $_adminCssMtime ?>">
<?php lpc_admin_head_assets(); ?>
<style>
.lpc-status-unknown { background:#f1f5f9; color:#64748b; }
.lpc-roadmap-status { font-size:.72rem; font-weight:700; text-transform:uppercase; letter-spacing:.03em; padding:2px 9px; border-radius:10px; white-space:nowrap; display:inline-block; }
.lpc-roadmap-status-complete { background:#dcfce7; color:#166534; }
.lpc-roadmap-status-todo { background:#fef3c7; color:#92400e; }
.lpc-roadmap-table td { vertical-align:top; }
.lpc-roadmap-cap { font-weight:700; }
.lpc-roadmap-roles { font-size:.78rem; color:#6b7280; }
.lpc-roadmap-detail { font-size:.82rem; line-height:1.6; color:#374151; }
.lpc-roadmap-gate, .lpc-roadmap-effort { font-size:.72rem; font-weight:700; text-transform:uppercase; letter-spacing:.03em; padding:2px 9px; border-radius:10px; white-space:nowrap; display:inline-block; }
.lpc-roadmap-gate-sell { background:#fee2e2; color:#991b1b; }
.lpc-roadmap-gate-retain { background:#dbeafe; color:#1e40af; }
.lpc-roadmap-gate-differentiate { background:#f1f5f9; color:#64748b; }
.lpc-roadmap-effort-quick { background:#dcfce7; color:#166534; }
.lpc-roadmap-effort-moderate { background:#fef3c7; color:#92400e; }
.lpc-roadmap-effort-major { background:#ede9fe; color:#5b21b6; }
.lpc-roadmap-na { color:#9ca3af; font-size:.8rem; }
.lpc-roadmap-filters { display:flex; gap:14px; align-items:center; flex-wrap:wrap; margin:14px 0 20px; padding:12px 16px; background:#fff; border:1px solid var(--pc-line); border-radius:12px; }
.lpc-roadmap-filters label { font-size:.78rem; font-weight:700; color:#374151; margin-right:6px; }
.lpc-roadmap-filters select { font-size:.82rem; padding:5px 8px; border-radius:8px; border:1px solid var(--pc-line); }
.lpc-roadmap-filters .lpc-roadmap-count { font-size:.78rem; color:#6b7280; margin-left:auto; }
.lpc-roadmap-section-empty { display:none; }
.lpc-wf-table td { vertical-align:top; }
.lpc-wf-step { font-weight:700; }
.lpc-wf-step-n { color:#9ca3af; font-weight:400; margin-right:4px; }
.lpc-wf-who, .lpc-wf-where { font-size:.78rem; color:#6b7280; }
.lpc-wf-where code { font-size:.76rem; }
.lpc-wf-what { font-size:.82rem; line-height:1.6; color:#374151; }
</style>
</head>
<?php lpc_admin_open('platform_info', 'Platform Information'); ?>
<div class="pcadmin-subtabs">
    <?php foreach ($TABS as $key => $label): ?>
        <a class="<?= $tab === $key ? 'active' : '' ?>" href="platform_info.php?tab=<?= h($key) ?>"><?= h($label) ?></a>
    <?php endforeach; ?>
</div>

<?php if ($tab === 'who_logged_in'):
    $sessions = platform_sessions_load();
    $now = time();

    $rows = [];
    foreach ($sessions as $sid => $s) {
        $online = empty($s['logout_at']) && ($now - ($s['last_seen'] ?? 0)) <= PLATFORM_SESSION_ONLINE_WINDOW;
        $identity = lpc_actor_identity_label($s['role'] ?? 'super_admin', $s['agency_id'] ?? null, $s['client_id'] ?? null);
        $endAt = $s['logout_at'] ?? $s['last_seen'] ?? $s['login_at'];
        $rows[] = [
            'sid'      => $sid,
            'online'   => $online,
            'role'     => $identity['role_label'],
            'name'     => $identity['name'],
            'ip'       => $s['ip'] ?? '',
            'login_at' => (int) ($s['login_at'] ?? 0),
            'last_seen'=> (int) ($s['last_seen'] ?? 0),
            'end_at'   => (int) $endAt,
            'status'   => $online ? 'Online' : (!empty($s['logout_at']) ? 'Logged out' : 'Timed out'),
            'duration' => max(0, (int) $endAt - (int) ($s['login_at'] ?? 0)),
        ];
    }
    usort($rows, fn($a, $b) => $b['login_at'] <=> $a['login_at']);
    $online = array_values(array_filter($rows, fn($r) => $r['online']));
    ?>

    <h3 style="margin-top:0;">Currently Logged In</h3>
    <?php if (!$online): ?>
        <p class="meta">Nobody's actively logged in right now.</p>
    <?php else: ?>
        <table class="lpc-table" style="margin-bottom:28px;">
            <thead><tr><th>Who</th><th>Role</th><th>Logged In Since</th><th>Last Active</th><th>IP</th></tr></thead>
            <tbody>
            <?php foreach ($online as $r): ?>
                <tr>
                    <td><strong><?= h($r['name']) ?></strong></td>
                    <td><?= h($r['role']) ?></td>
                    <td><?= h(date('M j, g:i a', $r['login_at'])) ?></td>
                    <td><?= h(platform_info_fmt_duration($now - $r['last_seen'])) ?> ago</td>
                    <td><?= h($r['ip']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <h3>Login History</h3>
    <p class="meta" style="margin-bottom:12px;">
        Grouped by day, newest first. "Timed out" means the session went quiet without an explicit
        logout — its end time (and duration) is its last recorded activity, not an exact sign-off.
        Kept for 180 days.
    </p>
    <?php
    $byDay = [];
    foreach ($rows as $r) {
        $day = date('Y-m-d', $r['login_at']);
        $byDay[$day][] = $r;
    }
    krsort($byDay);
    if (!$byDay): ?>
        <p class="meta">No login history recorded yet.</p>
    <?php else: foreach ($byDay as $day => $dayRows): ?>
        <h4 style="margin:20px 0 8px;font-family:'Space Grotesk',sans-serif;"><?= h(date('l, F j, Y', strtotime($day))) ?></h4>
        <table class="lpc-table" style="margin-bottom:8px;">
            <thead><tr><th>Who</th><th>Role</th><th>Logged In</th><th>Ended</th><th>Duration</th><th>Status</th><th>IP</th></tr></thead>
            <tbody>
            <?php foreach ($dayRows as $r): ?>
                <tr>
                    <td><strong><?= h($r['name']) ?></strong></td>
                    <td><?= h($r['role']) ?></td>
                    <td><?= h(date('g:i a', $r['login_at'])) ?></td>
                    <td><?= $r['online'] ? '&mdash;' : h(date('g:i a', $r['end_at'])) ?></td>
                    <td><?= h(platform_info_fmt_duration($r['duration'])) ?></td>
                    <td>
                        <span class="lpc-status lpc-status-<?= $r['online'] ? 'running' : ($r['status'] === 'Logged out' ? 'stopped' : 'unknown') ?>">
                            <?= $r['online'] ? 'Online' : h($r['status']) ?>
                        </span>
                    </td>
                    <td><?= h($r['ip']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endforeach; endif; ?>

<?php endif; ?>

<?php if ($tab === 'how_it_works'): ?>
    <h3 style="margin-top:0;color:#1e3a5f;">What this is, how it's built, how to use it</h3>
    <p style="font-size:.88rem;line-height:1.7;">
        A multi-location landing-page service sold to external businesses (plumbers, restoration
        companies, etc.) — each client gets one page per city &times; service combo (e.g. "Fire Damage
        Restoration in Plano, TX"), hosted on their own WordPress site via a small plugin we give them.
        Resold through agencies — an agency buys access and onboards its own clients, scoped to only
        what it owns.
    </p>
    <h4 style="margin-bottom:4px;">Roles</h4>
    <ul style="margin:0 0 14px;padding-left:20px;line-height:1.8;font-size:.88rem;">
        <li><strong>Platform Super Admin</strong> (Scott) — approves every new agency (never self-serve),
            sets each agency's monthly fee and spend cap, owns Niche Profiles (global, shared across every
            agency — so one agency can't silently change AI output for another's clients), platform-wide
            reporting, and the first stage of content review.</li>
        <li><strong>Agency Admin</strong> — logs in separately (<code>agency_login.php</code>), sees only
            the clients under its own agency (not necessarily ones it personally created — Scott can set
            up a client and hand it to an agency too), pays its own platform bill via Stripe, runs
            onboarding, sets its own branding (logo/display name, swapped into this admin UI in place of
            the platform's own), and gives the second/final stage of content review before a page goes
            live.</li>
        <li><strong>End Client</strong> — the actual business. Gets a separate read-only login
            (<code>client_login.php</code> &rarr; a stripped-down reporting-only portal, never this
            screen) showing GSC clicks and "Tried to Contact" counts for just their own pages.</li>
        <li><strong>"Log in as"</strong> (the Clients list's per-row button) — Super Admin can view the
            product as any agency or any client; Agency Admin can view it as one of its own clients only
            (never another agency's). Neither ever touches the target account's real password. One hop
            only — you can't start a second impersonation while already in one, exit back to yourself
            first — and every start/stop is written to the server error log. Note: the Who's Logged In
            tab tracks the REAL account during impersonation, not whoever's currently being viewed as.</li>
        <li><strong>Scott's own direct-sale work</strong> runs through "Scott's Agency," a normal agency
            row like any other — not a separate code path. If you created a client before any of this
            existed, it already belongs to that agency and nothing changed for it.</li>
    </ul>
    <h4 style="margin-bottom:4px;">Content approval — real agencies only</h4>
    <p style="font-size:.88rem;line-height:1.7;margin:0 0 14px;">
        Publishing a page for a REAL agency's client doesn't go live immediately — it queues on the
        <strong>Approvals</strong> screen (sidebar, with a pending count badge): Scott approves first, then
        that agency approves, and only then does it actually publish. This never applies to Scott's own
        agency's clients — publishing there works exactly as it always has, no gate, since the whole point
        is quality control over what an outside agency ships, not friction on direct work. The Pipeline
        column on the Clients list shows "N awaiting review"/"N rejected" whenever that's actually happening.
    </p>
    <h4 style="margin-bottom:4px;">Billing &amp; access</h4>
    <ul style="margin:0 0 14px;padding-left:20px;line-height:1.8;font-size:.88rem;">
        <li>The <strong>Agencies</strong> screen (sidebar, Scott-only) creates agencies and generates a
            real Stripe Checkout link to send them — set a Monthly Platform Fee first. Changing that fee
            later updates an already-paying agency's real live price, not just the next link.</li>
        <li>A failed payment suspends the agency's login immediately (Stripe webhook &rarr;
            <code>status: suspended</code>) <em>and</em> starts a 15-day grace period before their client
            sites actually get paused — a daily cron enforces that automatically
            (<code>tools/lpc_check_grace_periods.php</code>), it isn't instant.</li>
        <li>An agency can set its own logo/display name (Branding fields on its Agencies entry) — swapped
            into this admin UI in place of the platform's own branding when that agency is logged in.</li>
    </ul>
    <h4 style="margin-bottom:4px;">How it's built</h4>
    <ul style="margin:0 0 14px;padding-left:20px;line-height:1.8;font-size:.88rem;">
        <li>Content reuses this site factory's own engine — the same AI content pipeline, real per-city
            Census research (population, income, home values, year built, etc.), a real NOAA climate data
            chart per city, and niche-specific content templates the four live niche sites
            (water/mold/pest/appliance) already use.</li>
        <li>Each client's pages are stored here (<code>clients/{id}/data/</code>), then published to a
            separate delivery server their WordPress plugin fetches from — a thin, stateless proxy that
            renders inside <strong>their own theme's header/footer</strong>, so it inherits their real
            site's look with no custom CSS to fight against. A small floating badge the plugin injects
            site-wide links back to a public "Service Locations" hub page, grouped State &gt; Hub Area
            &gt; City &gt; Services, with real nearby-city cross-linking between pages.</li>
        <li>A niche profile (Niche Profiles, sidebar) defines content slots and a default service list
            per industry vertical — a new client's wizard offers these as checkboxes, never auto-copied.</li>
    </ul>
    <h4 style="margin-bottom:4px;">How to use it</h4>
    <ol style="margin:0;padding-left:20px;line-height:1.85;font-size:.88rem;">
        <li><strong>+ New Client</strong> — the wizard: business identity, industry (matches a niche
            profile), services offered, real hub office(s), target cities per hub.</li>
        <li>Finishing the wizard immediately AI-generates a draft page for every city &times; service
            combination (real cost per page) — nothing publishes yet.</li>
        <li>In the client's workspace: set Content Profile (tone/excluded services) <em>before</em>
            reviewing drafts, then use <strong>Locations &amp; Services</strong> — a city-grouped matrix
            with a checkbox per page and four selection-based actions:
            <ul style="margin:4px 0 0;padding-left:18px;">
                <li><strong>Research Selected</strong> — real Census population lookup, free, runs once
                    per unique city among the pages checked.</li>
                <li><strong>Generate / Refresh Selected</strong> — redraws each checked page one at a time
                    with live progress, with its own checkboxes for what to actually spend on: refresh
                    hero image, re-fetch climate data ($), regenerate text content ($), and skip locked
                    pages.</li>
                <li><strong>Publish Selected</strong> — makes the checked drafts live.</li>
                <li><strong>Check Live Selected</strong> — fetches each page's real public URL and
                    compares what it's actually serving against what the data says it should be (hero
                    image, area map, climate chart) — the one check that catches a host's CDN still
                    serving a stale cached copy.</li>
            </ul>
        </li>
        <li>The Status column is honest, not decorative — a page can say Published and still carry a
            &#9888; warning if something expected (hero image, climate data, etc.) is actually missing.
            Lock any page that's final to block it from being touched by Regenerate.</li>
        <li>Once pages are published, go to the Delivery tab — download the plugin zip and copy the API
            key to email the client (built-in compose form).</li>
        <li>Once they paste the key into their WordPress plugin settings, the status badge on the Clients
            list flips to Connected.</li>
        <li>Once live, the plugin tracks taps on a page's phone number/CTA buttons automatically (no
            setup) — rolled into the "Tried to Contact" total on the Clients list and the per-page
            Reporting tab. It's an intent signal, not a confirmed call or lead, and is the best evidence
            to show a client/agency the pages are actually doing something.</li>
        <li>On the Reporting tab: have the client add our email as a Restricted (read-only) user on their
            real Google Search Console property, paste the property URL in, and real per-page impressions/
            clicks start showing instead of "Not Connected" — nothing on our end until they actually grant
            that access.</li>
    </ol>
    <p style="font-size:.8rem;color:#6b7280;margin:14px 0 0;">Full step-by-step checklist:
        <code>docs/landing-page-clients-onboarding.md</code>.</p>
<?php endif; ?>

<?php if ($tab === 'roadmap'): ?>
    <h3 style="margin-top:0;color:#1e3a5f;">Gap analysis: current code vs. the target feature spec</h3>
    <p style="font-size:.88rem;line-height:1.7;">
        A role &times; capability matrix comparing what's actually built today against a full feature
        and permissions spec Scott supplied (2026-11-20), which defines a 5-role model &mdash;
        <strong>Platform Owner</strong>, <strong>Agency Admin</strong>, <strong>Agency Editor</strong>,
        <strong>Client Approver</strong>, <strong>Client Viewer</strong> &mdash; against what the live
        code today only implements as 3 roles (<code>super_admin</code>, <code>agency_admin</code>,
        <code>end_client</code>). Every row below was verified by reading the actual current code, not
        carried over from an earlier summary. Status is simplified to two states &mdash; a "To Be Done"
        row may already be partially built; the detail column says exactly what's there and what's left.
        This is a point-in-time snapshot: re-check a row against the code before trusting an old
        "Complete" after a big change in that area.
    </p>
    <?php
    $gateLabels   = ['sell' => 'Must-Have to Sell', 'retain' => 'Retention', 'differentiate' => 'Differentiator'];
    $effortLabels = ['quick' => 'Quick', 'moderate' => 'Moderate', 'major' => 'Major'];
    ?>
    <div class="lpc-roadmap-filters">
        <span><label for="rm-filter-gate">Launch gate</label>
            <select id="rm-filter-gate" onchange="lpcRoadmapFilter()">
                <option value="">All</option>
                <?php foreach ($gateLabels as $k => $label): ?>
                    <option value="<?= h($k) ?>"><?= h($label) ?></option>
                <?php endforeach; ?>
            </select>
        </span>
        <span><label for="rm-filter-effort">Effort</label>
            <select id="rm-filter-effort" onchange="lpcRoadmapFilter()">
                <option value="">All</option>
                <?php foreach ($effortLabels as $k => $label): ?>
                    <option value="<?= h($k) ?>"><?= h($label) ?></option>
                <?php endforeach; ?>
            </select>
        </span>
        <span><label for="rm-filter-status">Status</label>
            <select id="rm-filter-status" onchange="lpcRoadmapFilter()">
                <option value="">All</option>
                <option value="todo">To Be Done</option>
                <option value="complete">Complete</option>
            </select>
        </span>
        <span class="lpc-roadmap-count" id="rm-filter-count"></span>
    </div>
    <?php foreach (platform_info_roadmap_data() as $section): ?>
        <h4 class="lpc-roadmap-section" style="margin:22px 0 8px;font-family:'Space Grotesk',sans-serif;color:#1e3a5f;"><?= h($section['title']) ?></h4>
        <table class="lpc-table lpc-roadmap-table" style="margin-bottom:8px;">
            <thead><tr>
                <th style="width:22%;">Capability</th>
                <th style="width:14%;">Role(s)</th>
                <th style="width:9%;">Status</th>
                <th style="width:12%;">Launch gate</th>
                <th style="width:9%;">Effort</th>
                <th>What's needed</th>
            </tr></thead>
            <tbody>
            <?php foreach ($section['rows'] as $r): ?>
                <tr data-gate="<?= h($r['gate'] ?? '') ?>" data-effort="<?= h($r['effort'] ?? '') ?>" data-status="<?= h($r['status']) ?>">
                    <td class="lpc-roadmap-cap"><?= h($r['cap']) ?></td>
                    <td class="lpc-roadmap-roles"><?= h($r['roles']) ?></td>
                    <td>
                        <span class="lpc-roadmap-status lpc-roadmap-status-<?= $r['status'] ?>">
                            <?= $r['status'] === 'complete' ? 'Complete' : 'To Be Done' ?>
                        </span>
                    </td>
                    <td>
                        <?php if (isset($r['gate'])): ?>
                            <span class="lpc-roadmap-gate lpc-roadmap-gate-<?= h($r['gate']) ?>"><?= h($gateLabels[$r['gate']]) ?></span>
                        <?php else: ?>
                            <span class="lpc-roadmap-na">&mdash;</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (isset($r['effort'])): ?>
                            <span class="lpc-roadmap-effort lpc-roadmap-effort-<?= h($r['effort']) ?>"><?= h($effortLabels[$r['effort']]) ?></span>
                        <?php else: ?>
                            <span class="lpc-roadmap-na">&mdash;</span>
                        <?php endif; ?>
                    </td>
                    <td class="lpc-roadmap-detail"><?= h($r['detail']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endforeach; ?>
    <script>
    function lpcRoadmapFilter() {
        var gate = document.getElementById('rm-filter-gate').value;
        var effort = document.getElementById('rm-filter-effort').value;
        var status = document.getElementById('rm-filter-status').value;
        var tables = document.querySelectorAll('.lpc-roadmap-table');
        var shown = 0, total = 0;
        tables.forEach(function (table) {
            var visibleRows = 0;
            table.querySelectorAll('tbody tr').forEach(function (row) {
                total++;
                var match = (!gate || row.dataset.gate === gate)
                    && (!effort || row.dataset.effort === effort)
                    && (!status || row.dataset.status === status);
                row.style.display = match ? '' : 'none';
                if (match) { visibleRows++; shown++; }
            });
            var heading = table.previousElementSibling;
            var show = visibleRows > 0;
            table.style.display = show ? '' : 'none';
            if (heading && heading.classList.contains('lpc-roadmap-section')) {
                heading.style.display = show ? '' : 'none';
            }
        });
        document.getElementById('rm-filter-count').textContent = shown + ' of ' + total + ' rows shown';
    }
    lpcRoadmapFilter();
    </script>
<?php endif; ?>

<?php if ($tab === 'content_workflow'): ?>
    <h3 style="margin-top:0;color:#1e3a5f;">The real content lifecycle, start to finish</h3>
    <p style="font-size:.88rem;line-height:1.7;">
        Every step needed to take a page from "doesn't exist" to "live and being monitored" &mdash;
        who does it and exactly where in the app, in the order it actually happens. Verified by
        reading <code>client_wizard.php</code>, <code>client.php</code>, <code>page_editor.php</code>,
        <code>client_save.php</code>, and <code>includes/landing_page_clients_approvals.php</code>
        directly (2026-11-21), not carried over from memory. Gaps are interleaved at the point they'd
        actually happen in the flow, each marked <strong>Needs to Be Added</strong> with what it would
        take and who'd do it once built &mdash; so this reads as one real end-to-end flow, not a
        built list and a separate wishlist.
    </p>
    <div class="lpc-roadmap-filters">
        <span><label for="wf-filter-status">Status</label>
            <select id="wf-filter-status" onchange="lpcWorkflowFilter()">
                <option value="">All</option>
                <option value="in_place">In Place</option>
                <option value="needs_adding">Needs to Be Added</option>
            </select>
        </span>
        <span class="lpc-roadmap-count" id="wf-filter-count"></span>
    </div>
    <?php $wfStepN = 0; ?>
    <?php foreach (platform_info_content_workflow_data() as $section): ?>
        <h4 class="lpc-wf-section" style="margin:22px 0 8px;font-family:'Space Grotesk',sans-serif;color:#1e3a5f;"><?= h($section['title']) ?></h4>
        <table class="lpc-table lpc-wf-table" style="margin-bottom:8px;">
            <thead><tr>
                <th style="width:3%;">#</th>
                <th style="width:20%;">Step</th>
                <th style="width:9%;">Status</th>
                <th style="width:30%;">What it is</th>
                <th style="width:17%;">Who does it</th>
                <th>Where in the app</th>
            </tr></thead>
            <tbody>
            <?php foreach ($section['rows'] as $r): $wfStepN++; ?>
                <tr data-status="<?= h($r['status']) ?>">
                    <td class="lpc-wf-step-n"><?= $wfStepN ?></td>
                    <td class="lpc-wf-step"><?= h($r['step']) ?></td>
                    <td>
                        <span class="lpc-roadmap-status lpc-roadmap-status-<?= $r['status'] === 'in_place' ? 'complete' : 'todo' ?>">
                            <?= $r['status'] === 'in_place' ? 'In Place' : 'Needs to Be Added' ?>
                        </span>
                    </td>
                    <td class="lpc-wf-what"><strong><?= h($r['simple']) ?></strong> <?= h($r['what']) ?></td>
                    <td class="lpc-wf-who"><?= h($r['who']) ?></td>
                    <td class="lpc-wf-where"><?= h($r['where']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endforeach; ?>
    <script>
    function lpcWorkflowFilter() {
        var status = document.getElementById('wf-filter-status').value;
        var tables = document.querySelectorAll('.lpc-wf-table');
        var shown = 0, total = 0;
        tables.forEach(function (table) {
            var visibleRows = 0;
            table.querySelectorAll('tbody tr').forEach(function (row) {
                total++;
                var match = !status || row.dataset.status === status;
                row.style.display = match ? '' : 'none';
                if (match) { visibleRows++; shown++; }
            });
            var heading = table.previousElementSibling;
            var show = visibleRows > 0;
            table.style.display = show ? '' : 'none';
            if (heading && heading.classList.contains('lpc-wf-section')) {
                heading.style.display = show ? '' : 'none';
            }
        });
        document.getElementById('wf-filter-count').textContent = shown + ' of ' + total + ' steps shown';
    }
    lpcWorkflowFilter();
    </script>
<?php endif; ?>

<?php if ($tab === 'client_approval_stage'): ?>
    <h3 style="margin-top:0;color:#1e3a5f;">Client Approval Stage — design reference, not yet built</h3>
    <p style="font-size:.88rem;line-height:1.7;">
        A closer look at one specific gap from the Content Workflow tab (step 15, "Client review /
        approval") and the Product Roadmap tab (Workflow section, gate: <strong>Must-Have to Sell</strong>,
        effort: <strong>Major</strong>) — how it would actually fit into the existing review pipeline,
        who'd be involved, and exactly where each piece would live in the app. This is a design writeup
        to work from when it's greenlit, not a status page for something already shipped &mdash; nothing
        described below exists in the code yet.
    </p>

    <h4 style="margin-bottom:4px;">Where this fits in the flow today</h4>
    <p style="font-size:.88rem;line-height:1.7;margin:0 0 14px;">
        Right now, publishing a real third-party agency's client page goes through two stages &mdash;
        Scott reviews first (<code>pending_platform_review</code>), then the owning agency gives the
        final sign-off (<code>pending_agency_review</code> &rarr; <code>approved</code> &rarr; live),
        both on <code>approvals.php</code>. Scott's own direct-sale clients skip this gate entirely and
        publish immediately. Either way, the End Client never participates &mdash; they only ever see a
        page after it's already live, on their own read-only Reporting tab.
    </p>

    <h4 style="margin-bottom:4px;">The proposed design</h4>
    <ul style="margin:0 0 14px;padding-left:20px;line-height:1.85;font-size:.88rem;">
        <li><strong>A third, optional stage</strong> &mdash; <code>pending_client_review</code> &mdash;
            sitting after agency approval and before a page actually goes live.</li>
        <li><strong>Per-client toggle, off by default.</strong> Not every agency wants their client in
            the loop at all; this is a switch the Owner or Agency Admin flips per client, not a rule
            forced on everyone. It's also orthogonal to the existing platform/agency gate above &mdash;
            Scott could require client review on one of his own direct clients even though they skip the
            platform/agency stages, and a real agency's client could skip client review even though that
            agency's pages still go through platform + agency review.</li>
        <li><strong>The client sees only what's waiting on them</strong> &mdash; never a draft still being
            worked on internally, never anything mid-edit.</li>
        <li><strong>Two actions, same shape as every other stage:</strong> <em>Approve</em> (publishes
            immediately) or <em>Request Changes</em> (sends it back with a note, mirroring the existing
            Reject flow &mdash; the page's own content is untouched, just not live, until resubmitted).</li>
    </ul>

    <h4 style="margin-bottom:4px;">New pieces this would need</h4>
    <ul style="margin:0 0 14px;padding-left:20px;line-height:1.85;font-size:.88rem;">
        <li><strong>The toggle itself</strong> &mdash; set once per client by the Owner or Agency Admin.
            Would live on <code>client.php</code>, most likely the Overview tab next to Billing, the same
            place the other per-client settings already live.</li>
        <li><strong>A new End Client screen, with its own sidebar tab</strong> &mdash; parallel to the
            client's existing Reporting tab, so the client's sidebar would go from one item to two:
            <em>Reporting</em> (what's already there) and <em>Approvals</em> (new), badge-counted the
            same way the Owner/Agency sidebar already badges their own Approvals queue.</li>
        <li><strong>A save handler scoped strictly to that client's own pages</strong> &mdash;
            CSRF-protected, restricted to <code>end_client</code> sessions, only able to act on pages
            already sitting at <code>pending_client_review</code> for their own <code>client_id</code>.
            Mirrors <code>approvals_save.php</code>'s existing pattern rather than inventing a new one.</li>
        <li><strong>One new value on the page's own approval_stage field</strong> &mdash; today's possible
            values are <code>none / pending_platform_review / pending_agency_review / approved /
            rejected</code>; this adds <code>pending_client_review</code> alongside them.</li>
    </ul>

    <h4 style="margin-bottom:4px;">The one hard constraint</h4>
    <p style="font-size:.88rem;line-height:1.7;margin:0 0 14px;">
        This would be the single most client-visible screen in the entire product &mdash; the end
        client's own eyes land here directly, not an agency's. Per the positioning rule already flagged
        on the Product Roadmap tab ("never present as an AI product"), its wording has to stay completely
        clear of "AI," "regenerate," "generate," or "prompt." Just <em>Approve</em> and
        <em>Request Changes</em> &mdash; the two verbs a client already understands from ordinary
        business software, nothing that hints at how the page was actually made.
    </p>

    <h4 style="margin-bottom:4px;">Where this sits in prior planning</h4>
    <p style="font-size:.88rem;line-height:1.7;margin:0;">
        Same item as the Product Roadmap tab's "Client approve / request-changes step (per-client
        toggle)" row and the Content Workflow tab's step 15 ("Client review / approval") &mdash; this
        page is the detailed design for that one entry, not a new addition to scope.
    </p>
<?php endif; ?>

<?php lpc_admin_close(); ?>
