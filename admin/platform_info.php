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
    'content_flow'         => 'Content Flow',
    'spec_document'        => 'Spec Document',
    'city_page'            => 'City Page',
    'claude'               => 'Claude',
    'use_cases'            => 'Use Cases',
];
$tab = $_GET['tab'] ?? 'who_logged_in';
if (!isset($TABS[$tab])) $tab = 'who_logged_in';

// Purely a display grouping for the tab bar — $TABS above stays the flat
// key => label map everything else (validation, tab lookups) uses.
$TAB_ROWS = [
    ['label' => 'Settings', 'tabs' => ['who_logged_in']],
    ['label' => 'PlusCities', 'tabs' => ['how_it_works', 'content_workflow', 'client_approval_stage', 'spec_document', 'roadmap', 'city_page', 'claude', 'use_cases']],
    ['label' => 'General Niche/Page Generation', 'tabs' => ['content_flow']],
];

function platform_info_fmt_duration(int $seconds): string {
    if ($seconds < 60) return $seconds . 's';
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    return $h > 0 ? sprintf('%dh %dm', $h, $m) : sprintf('%dm', $m);
}

/**
 * Gap analysis of the live LPC/PlusCities code against the feature/permissions
 * spec Scott supplied 2026-11-20 (5-role model: Platform Admin, Agency Admin,
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
            'title' => 'New: the PlusCities content-engine rewrite itself (as of 2026-11-22, not yet migrated onto the live LPC engine)',
            'rows' => [
                ['cap' => 'Ground-up content engine: research → write → validate → approve → publish → sync → render, every claim traced', 'roles' => 'n/a (the engine itself)', 'status' => 'complete',
                 'simple' => 'This is a whole new way of writing these pages that\'s built to never make things up.',
                 'detail' => 'docs/PLUSCITIES_FEATURE_SPEC.md. Every specific claim in generated content must trace to a cited fact card (real web research, a real .gov-type source, a real extracted quote), a dataset (e.g. Census ACS), or the client\'s own profile — never the model\'s own knowledge. Built specifically to avoid the "scaled content abuse" doorway-page pattern search engines penalize, not as a generic nicety. Proven end-to-end multiple times, including catching and hard-failing two real model-hallucination attempts during testing (an invented second fact, and a client license number wrongly tagged as an external citation) rather than silently publishing them.'],
                ['cap' => 'Real niche library: families, compliance presets, real per-niche image slots, real keyword-validation hook', 'roles' => 'Platform Admin', 'status' => 'complete',
                 'simple' => 'This is the reusable template system for each type of business (HVAC, restoration, etc.).',
                 'detail' => 'includes/pluscities_niche_library.php, admin/pluscities_niches.php. 6 families defined (home-trades, restoration, outdoor-cleaning, legal, medical-wellness, professional-services), 2 populated with a real niche so far: hvac and water-damage-restoration. Family-level inheritance (compliance preset, approved sources, default city-pool blocks) with per-niche overrides. Real keyword validation wired to the same infra_kw_score() the fleet already uses — currently blocked by DataForSEO returning HTTP 402 (account needs funds), not a code gap.'],
                ['cap' => 'Full block pool: both city-pool and service-pool complete (18 block types)', 'roles' => 'n/a', 'status' => 'complete',
                 'simple' => 'This is all the different sections a page can be made of — FAQ, reviews, cost factors, etc.',
                 'detail' => 'Spec Section 5\'s full pools: 4 spine (Hero/Overview/FAQ/Service area) + all 9 city-pool types (climate data, housing stock, neighborhoods, permits, utilities, HOA rules, seasonal guide, hazards, reviews-from-city) + all 9 service-pool types (common problems, cited expert guidance, niche data table, process, cost factors, why choose us, repair-vs-replace, maintenance, before-the-tech-arrives). Most new block types needed zero new code — the generic editable-block writer/render pipeline already covers any new block_type; the one genuinely new shape (niche data table) got its own writer + a real styled <table> render.'],
                ['cap' => 'Proven at real multi-niche, multi-client, multi-city scale', 'roles' => 'n/a', 'status' => 'complete',
                 'simple' => 'This proves the system isn\'t just a one-off demo — it genuinely works for different businesses and cities.',
                 'detail' => '2 real niches, 2 real clients (Elite Air HVAC, Lonestar Restoration & Recovery), 3 real live pages across 2 cities for the same client (Frisco, TX + McKinney, TX) — the first time uniqueness/cannibalization/the manifest endpoint\'s page list were ever exercised against a real sibling page rather than an empty comparison set. Real research against previously-untried cities (Houston, TX; McKinney, TX), including one real finding: McKinney\'s own official city site (mckinneytexas.org) returns HTTP 403 to automated research fetches — a real WAF, not a bug — worked around by researching a different real .gov-equivalent source instead rather than skipping the citation.'],
                ['cap' => 'Real per-niche AI images + per-client branding (logo + color)', 'roles' => 'Platform Admin, Agency Admin, Agency Editor', 'status' => 'complete',
                 'simple' => 'This gives every client a professional-looking photo without needing real photography.',
                 'detail' => 'includes/pluscities_images.php, admin/pluscities_photos.php. One real AI-generated stock photo per niche image slot, shared by every client in that niche; a client\'s own logo + brand color produce a one-time branded version (AI recolor + GD-composited logo badge), cached and reused across every page for that client rather than regenerated per page. A deliberately simple, non-technical "Photos" screen for the client/agency to accept the default or upload their own real photo — no scope/cache/pair vocabulary exposed anywhere in the UI.'],
                ['cap' => 'Real schema/SEO completion: canonical URL + sitemap registered into the client\'s actual SEO plugin', 'roles' => 'n/a', 'status' => 'complete',
                 'simple' => 'This helps a client\'s page actually get found and ranked correctly by Google.',
                 'detail' => 'Self-canonical URL built server-side; a self-served sitemap registered into whichever real SEO plugin a client is running (Rank Math/Yoast confirmed live by reading each plugin\'s installed source and testing against it directly — found and fixed 2 real integration bugs this way, including a plugin-load-order bug that would have silently never worked in production). AIOSEO\'s hook is code-correct but not yet live-verified.'],
                ['cap' => 'Visual page design matching the fleet\'s own proven reference sites', 'roles' => 'n/a', 'status' => 'complete',
                 'simple' => 'This makes the generated pages actually look like a real, professional business website.',
                 'detail' => 'Real CSS (not a generic theme) built directly from studying the fleet\'s own live reference sites (baileyrestoration.com, wrenappliancerepair.com, gannmoldremediation.com): a true two-column hero (heading/body/solid CTA button on the left, photo on the right — not stacked), a dark trust-bar strip under the hero, card-styled sections throughout, a styled data table, and FAQ items with a left accent bar. Brand-color-aware (pulls each client\'s own stored color), so every client\'s pages look personalized without per-client CSS. Expanded 2026-11-22: a real FAQ accordion (native &lt;details&gt;/&lt;summary&gt;, no JS, two-column), a mid-page dark CTA band with a pill call button, and the service-area/related-links block upgraded from a plain bulleted list to an accent-colored band with pill-shaped links. A sticky mobile click-to-call bar was built, then deliberately removed — pages already render inside the client\'s own theme header/footer, and a second, platform-owned sticky bar competed with whatever call-to-action the theme already provides rather than deferring to it. Two real bugs were also found and fixed the same session: FAQ/service-area were rendering 3rd/4th on the page instead of last (contradicted this row\'s own stated rhythm), and a stale sync-revision counter on the live plugin was permanently skipping a page\'s content after publish.'],
                ['cap' => 'Modular per-site color detection (accent + dark, manual override always wins)', 'roles' => 'Platform Admin, Agency Admin, Agency Editor', 'status' => 'complete',
                 'simple' => 'This can automatically pull a client\'s real brand colors from their own website, or let someone just type them in by hand.',
                 'detail' => 'includes/pluscities_color_detect.php — a deliberately small, modular detector list (meta theme-color tag, WordPress block-theme global styles, Astra, Kadence), each reading the client\'s real homepage CSS server-side (plain HTTP fetch + regex, no headless browser or hidden iframe). A manual override on the Photos screen always wins over whatever was auto-detected, and "Detect Now"/"Clear override" are both explicit actions, never silent. A real bug was caught before shipping: the first Kadence detector guessed the wrong CSS variable for the "dark" color and got white back; fixed by reading the theme\'s real rendered values directly, plus a general luminance safety check so a future wrong guess rejects itself instead of shipping unreadable white-on-white text.'],
                ['cap' => 'Known gaps, flagged honestly rather than left silent', 'roles' => 'n/a', 'status' => 'todo', 'gate' => 'sell', 'effort' => 'moderate',
                 'simple' => 'This is the short, honest list of what\'s still missing before this replaces the live engine.',
                 'detail' => "Selection-rule enforcement (spec's real rules — city blocks ≥ 50% of body words, ≤ 50% service-block overlap across a client's cities, fact-support gating, block-order variance — exist in the spec but aren't checked in code yet; only manually respected so far). pluscities_swap_block() doesn't validate pool membership. approved_sources is pure config, never actually checked against a research source_url, and the *.gov-only pattern is too narrow (McKinney's real city site is .org). Page-frame polish, updated 2026-11-22: CTA band done (see the row above); still missing a quote form, in-body images + a data-chart image, and an interactive service-area map embed — a sticky mobile click-to-call bar was considered and explicitly rejected (see above), not merely deferred. 4 of 6 niche families are still empty. This is genuinely the single most load-bearing gap before scaling to more cities/niches — skipping it risks the exact templated-page pattern this whole rewrite exists to avoid."],
            ],
        ],
        [
            'title' => 'Role model & permissions',
            'rows' => [
                ['cap' => '5-role model (Platform Admin / Agency Admin / Agency Editor / Client Approver / Client Viewer)', 'roles' => 'All', 'status' => 'todo', 'gate' => 'retain', 'effort' => 'major',
                 'simple' => 'This means giving different types of users — owner, agency staff, client — their own separate logins and permissions.',
                 'detail' => "Only 3 roles exist on the live LPC engine today: super_admin, agency_admin, end_client — no Agency Editor, no Approver/Viewer split, one shared login per agency/client. Agency Editor is now REAL on the new PlusCities content-engine rewrite (2026-11-21): genuine per-person accounts (includes/pluscities_users.php), a Team admin screen (admin/pluscities_team.php) to add/remove them and assign specific clients, a real agency-level \"can Editors publish\" toggle, and real session/auth wired through the shared agency_login.php. Client Approver/Viewer still don't exist on either engine. Retention, not launch-blocking: a single shared login can carry a first pilot agency through; this matters once agencies have their own staff to onboard."],
                ['cap' => 'Self-Managed Client (client upgraded to Editor rights on own site)', 'roles' => 'Client Approver', 'status' => 'todo', 'gate' => 'differentiate', 'effort' => 'moderate',
                 'simple' => 'This would let a client who wants more control edit their own site directly.',
                 'detail' => 'No such flag exists in the client data model. Needs a self_managed toggle plus a scoped-to-one-client version of Editor permissions. Differentiator: a nice upsell path once a client wants more control, not something a first agency sale needs.'],
                ['cap' => 'Server-side permission checks on every endpoint, not just hidden in the UI', 'roles' => 'All', 'status' => 'complete',
                 'simple' => 'This means the system double-checks who you are before letting you do anything, not just hiding buttons you shouldn\'t see.',
                 'detail' => 'Client/agency scoping (lpc_load_client_for_current_actor, lpc_list_clients_for_current_actor) is applied consistently across the admin — verified in the code, not just assumed from what the UI hides.'],
            ],
        ],
        [
            'title' => 'Pages & content',
            'rows' => [
                ['cap' => 'View pages, including drafts', 'roles' => 'Platform Admin, Agency Admin, Agency Editor, Client Approver, Client Viewer', 'status' => 'todo', 'gate' => 'sell', 'effort' => 'moderate',
                 'simple' => 'This means being able to see a page before it\'s finished, not just after it\'s already live.',
                 'detail' => "Complete for Platform Admin/Agency Admin/Editor — full draft/published matrix in the client workspace. Not started for Client Approver/Viewer: the only End Client screen (client_portal.php) is a reporting rollup, not a page browser — there's nothing for a client to actually look at or approve. Must-have to sell: a client that can't see what they're approving breaks the whole approve-before-live pitch — the Client Approver role is pointless without it."],
                ['cap' => 'Generate pages (new city × service combos)', 'roles' => 'Platform Admin, Agency Admin, Agency Editor', 'status' => 'complete',
                 'simple' => 'This is the system automatically writing a new page for a city and service.',
                 'detail' => 'Adding a target city auto-drafts real AI content for every service at that city.'],
                ['cap' => 'Edit block text, titles, meta', 'roles' => 'Platform Admin, Agency Admin, Agency Editor', 'status' => 'complete',
                 'simple' => 'This is editing the actual words and images on a page by hand.',
                 'detail' => 'Full field editor per block type, plus SEO title/meta/keywords.'],
                ['cap' => 'Lock / unlock blocks or pages', 'roles' => 'Platform Admin, Agency Admin, Agency Editor', 'status' => 'todo', 'gate' => 'retain', 'effort' => 'moderate',
                 'simple' => 'This protects a page\'s content from being accidentally changed.',
                 'detail' => "Whole-page lock exists and blocks Regenerate. No block-level lock — locking a page locks everything in it, so you can't protect just the intro while regenerating the rest. Retention: whole-page lock already protects a finished page; block-level control is a workflow upgrade for agencies already working in the tool, not a launch blocker."],
                ['cap' => 'Regenerate with an instruction field ("make it shorter")', 'roles' => 'Platform Admin, Agency Admin, Agency Editor', 'status' => 'todo', 'gate' => 'retain', 'effort' => 'moderate',
                 'simple' => 'This would let someone tell the system how to rewrite a page instead of it rewriting the same way every time.',
                 'detail' => 'Regenerate (single page) and bulk refresh (checked pages) both exist, but neither takes a free-text instruction — every regenerate reruns the same fixed prompt template. Retention: the fixed-prompt regenerate already produces a usable draft; a steerable instruction field is a quality-of-life upgrade, not a blocker to a first sale.'],
                ['cap' => 'Roll back to a previous version (history + diff)', 'roles' => 'Platform Admin, Agency Admin, Agency Editor', 'status' => 'todo', 'gate' => 'retain', 'effort' => 'major',
                 'simple' => 'This would let someone undo a mistake and go back to an older version of a page.',
                 'detail' => "No version history at all. Every save overwrites in place — there's nothing to roll back to. Retention: a real safety net once agencies are editing live client content daily — a first handful of pilot clients can get by with careful manual edits in the meantime."],
                ['cap' => 'Swap / upload images with alt text', 'roles' => 'Platform Admin, Agency Admin, Agency Editor', 'status' => 'complete',
                 'simple' => 'This is adding or changing the pictures on a page.',
                 'detail' => 'Manual upload or AI-generate per service on the live LPC engine, real alt-text fields, WebP output, lazy-loading below the fold. The new PlusCities rewrite uses a different, cheaper mechanism (2026-11-21): one real AI-generated stock photo per niche image slot, shared by every client in that niche; a client\'s own logo + brand color produce a branded version once per client (AI recolor via openai_images_edit() + the real logo composited with plain GD — the edit API only takes one reference image, so it can\'t reproduce an exact logo), cached and reused on every page rather than regenerated per page. A simple non-technical "Photos" screen (admin/pluscities_photos.php) lets the client accept the branded default or upload their own real photo per slot, with no scope/cache vocabulary exposed.'],
            ],
        ],
        [
            'title' => 'Workflow',
            'rows' => [
                ['cap' => 'Agency approve step', 'roles' => 'Platform Admin, Agency Admin, Agency Editor', 'status' => 'complete',
                 'simple' => 'This is the agency\'s own sign-off before one of its pages is allowed to go live.',
                 'detail' => 'Two-stage gate: pending_platform_review -> pending_agency_review -> approved.'],
                ['cap' => 'Client approve / request-changes step (per-client toggle)', 'roles' => 'Client Approver', 'status' => 'todo', 'gate' => 'sell', 'effort' => 'major',
                 'simple' => 'This would let the actual client approve a page before it goes live.',
                 'detail' => "Doesn't exist — End Client has no approval role today at all. The existing platform+agency gate is a per-agency on/off setting (see the Platform Only section's approval-gate toggle), but that's Platform Admin control over an agency, not a per-client client-review toggle — and the client never participates in any of it regardless. Must-have to sell: this is the core differentiator in the pitch — agency approves, then the client approves, before anything goes live. Without it there's no real client sign-off, just an agency's word."],
                ['cap' => 'Publish / unpublish', 'roles' => 'Platform Admin, Agency Admin, Agency Editor', 'status' => 'todo', 'gate' => 'sell', 'effort' => 'quick',
                 'simple' => 'This is making a page go live, or taking it back down.',
                 'detail' => "Publish exists. There's no per-page unpublish — only deactivating a client's entire API key, which takes every page down at once, not one. Must-have to sell: an agency needs to be able to pull one bad page without killing a client's whole site — the all-or-nothing kill switch isn't a real substitute."],
                ['cap' => 'Bulk approve / publish / unpublish, by selection, city, service, or all', 'roles' => 'Platform Admin, Agency Admin, Agency Editor', 'status' => 'todo', 'gate' => 'retain', 'effort' => 'moderate',
                 'simple' => 'This would let someone handle many pages at once instead of one at a time.',
                 'detail' => 'The page workspace has bulk publish/generate by checkbox selection. The Approvals queue itself is one row at a time — no bulk approve, and nothing filters by city or service. Retention: matters once page volume is real; one-row-at-a-time approval is tolerable for a first few clients.'],
                ['cap' => 'Validator / override flags (banned phrases, NAP mismatch)', 'roles' => 'Platform Admin, Agency Admin', 'status' => 'complete',
                 'simple' => 'This would automatically catch mistakes or forbidden wording before a page is approved.',
                 'detail' => "Doesn't exist on the live LPC engine — nothing there blocks a page for a banned phrase or a wrong phone number. Fully built and proven on the new PlusCities content-engine rewrite (2026-11-19/21): 12 real checks (unsupported claims, near-duplicate content, service radius, wrong city, client-fact consistency, banned phrases, invented prices, invented reviews, misquoted sources, stale facts, cannibalization, FAQ completeness), run for real before Approve is allowed. A real override mechanism exists for Agency Admin/Platform Admin with a hard-exception list that can never be overridden by anyone (e.g. license claims) — includes/pluscities_validator.php. Not yet migrated onto the live LPC engine's own clients."],
                ['cap' => 'Comment on pages', 'roles' => 'All', 'status' => 'todo', 'gate' => 'differentiate', 'effort' => 'moderate',
                 'simple' => 'This would let people leave notes on a page for each other.',
                 'detail' => 'No comment feature anywhere in the product. Differentiator: a collaboration nicety — approve/publish already works end to end without it.'],
            ],
        ],
        [
            'title' => 'Client setup',
            'rows' => [
                ['cap' => 'Create / delete clients and sites', 'roles' => 'Platform Admin, Agency Admin', 'status' => 'complete',
                 'simple' => 'This is adding a brand-new client to the system, or removing one.',
                 'detail' => "5-step onboarding wizard. Delete was a real gap as of 2026-11-21 (a prior pass through this audit claimed it existed; it didn't — no delete action existed anywhere in the panel). Built for real 2026-11-22: a Danger Zone action on the client's own page, type-to-confirm against the exact business name (not just an OK/Cancel), Platform-Admin-only — an Agency Admin can't permanently remove its own client without platform oversight. Soft-delete, not a true wipe: the client's whole directory (data, pages, secrets) moves into a quarantine folder outside the active client list rather than being destroyed, so a wrong deletion is still recoverable by hand."],
                ['cap' => 'Client facts (NAP, licenses, hours, years in business, tone, brand terms)', 'roles' => 'Platform Admin, Agency Admin, Agency Editor', 'status' => 'complete',
                 'simple' => 'This is storing the real business details — like license number and hours — that should show up in the content.',
                 'detail' => 'Has phone, a free-text tone field, and always-mention/never-mention guardrails on the live LPC engine — missing a structured address, license numbers, hours, years-in-business entirely there. All of these are real, structured fields on the new PlusCities content-engine rewrite\'s client record (pluscities_clients/{id}.json): identity.hubs[] (address+phone), licenses[] (number/state/trade), hours{}, years_in_business, banned_phrases[], price_ranges — all genuinely read by the writer and checked by the validator, not just stored. Proven on 2 real clients (Elite Air HVAC, Lonestar Restoration). Not yet migrated onto the live LPC engine\'s own client records.'],
                ['cap' => 'Banned-phrases list + niche compliance presets (toggleable)', 'roles' => 'Platform Admin, Agency Admin', 'status' => 'complete',
                 'simple' => 'This would stop certain words or claims from ever appearing on a page.',
                 'detail' => "always_mention/never_mention on the live LPC engine is free text with no niche-default inheritance and no on/off toggle. Fully built on the new PlusCities rewrite: a real compliance-preset system (compliance_presets/{id}.json) with a banned_phrases list and validator_rule_toggles (e.g. show_license, no_unverifiable_pricing), inherited by every niche in that preset and overridable per-niche, plus a separate per-client banned_phrases list — both genuinely enforced by the validator's banned_phrases check, which distinguishes compliance-preset phrases (never overridable, real regulatory risk) from a client's own stylistic preference (overridable). Not yet migrated onto the live LPC engine."],
                ['cap' => 'Generate / rotate the API key', 'roles' => 'Platform Admin, Agency Admin', 'status' => 'complete',
                 'simple' => 'This is the secret code that connects a client\'s website to the system.',
                 'detail' => 'One-click regenerate.'],
                ['cap' => 'Connect Google Search Console per site', 'roles' => 'Platform Admin, Agency Admin, Client Approver', 'status' => 'todo', 'gate' => 'retain', 'effort' => 'major',
                 'simple' => 'This connects a client\'s real Google search data so you can see how their pages are actually performing.',
                 'detail' => "Works, but isn't OAuth — it's a shared service-account key; the client adds that service-account email as a read-only user on their own GSC property by hand, then an admin pastes the property URL in. Retention: the manual service-account method already works end to end; real OAuth is a polish upgrade, not a launch blocker."],
                ['cap' => 'Connect call tracking (CallRail or agency\'s own number)', 'roles' => 'Platform Admin, Agency Admin', 'status' => 'todo', 'gate' => 'differentiate', 'effort' => 'moderate',
                 'simple' => 'This would give each page its own trackable phone number.',
                 'detail' => "Doesn't exist. Pages just show the hub's real phone number as a tel: link — no CallRail integration, no per-client tracking-number pool. Differentiator: the hub's real phone number already gives a client something to show for the spend; per-page tracking is a later upsell."],
            ],
        ],
        [
            'title' => 'Reporting',
            'rows' => [
                ['cap' => 'View reports', 'roles' => 'All (own scope)', 'status' => 'complete',
                 'simple' => 'This is seeing how a client\'s pages are performing.',
                 'detail' => 'Basic reporting tab (Platform Admin/Agency) and a read-only portal (Client), both working.'],
                ['cap' => 'Automated monthly white-labeled PDF reports, emailed', 'roles' => 'Platform Admin, Agency Admin', 'status' => 'todo', 'gate' => 'retain', 'effort' => 'moderate',
                 'simple' => 'This would automatically email a client a performance report every month.',
                 'detail' => 'No PDF generation and no scheduled email report exist anywhere in the codebase. Retention: explicitly a Tier 2 feature in the spec — keeps agencies paying after launch, doesn\'t block the first sale.'],
                ['cap' => 'View leads, by city and by service', 'roles' => 'Platform Admin, Agency Admin, Agency Editor, Client Approver', 'status' => 'todo', 'gate' => 'retain', 'effort' => 'major',
                 'simple' => 'This would show which cities and services are actually bringing in real leads.',
                 'detail' => '"Tried to Contact" is a raw click counter on tel:/CTA links — no name or contact info is ever captured, so there\'s no actual lead to list by city or service. Retention: needs real lead capture to exist first (see the Tier 2 CTA form row); not a launch blocker on its own.'],
                ['cap' => 'GSC dashboard: impressions, clicks, CTR, position, trend', 'roles' => 'All (own scope)', 'status' => 'todo', 'gate' => 'retain', 'effort' => 'moderate',
                 'simple' => 'This would show a fuller picture of how a client\'s pages are doing in Google search.',
                 'detail' => 'Only a 28-day click total per page is pulled today. No impressions, no CTR, no position, no trend over time, no "impressions but no clicks" view. Retention: the existing 28-day click total is a usable stopgap for a first cohort of agencies.'],
            ],
        ],
        [
            'title' => 'SEO essentials',
            'rows' => [
                ['cap' => 'JSON-LD schema (LocalBusiness/Service/FAQPage), canonical, sitemap', 'roles' => 'n/a (built-in to every page)', 'status' => 'complete',
                 'simple' => 'This is the behind-the-scenes code that helps Google understand and rank a page.',
                 'detail' => 'Confirmed well-built on the live LPC engine: real schema per page, a self-served sitemap.xml linked via robots.txt, and real handling of the Yoast canonical-tag conflict. Independently re-built and re-verified on the new PlusCities rewrite (2026-11-21/22): real LocalBusiness-subtype/Service/FAQPage/BreadcrumbList/AggregateRating schema, a self-canonical URL built server-side, and a sitemap registered into whichever real SEO plugin is active — Rank Math\'s and Yoast\'s actual sitemap-extension hooks confirmed by reading each plugin\'s installed source (not assumed), with 2 real integration bugs found and fixed by testing live against both, plus a universal robots.txt fallback. AIOSEO\'s hook is code-correct but not yet live-verified (needs a setting the test install has never had configured).'],
                ['cap' => 'Cannibalization check, near-duplicate check, slug pattern per site, breadcrumbs', 'roles' => 'Platform Admin, Agency Admin', 'status' => 'complete',
                 'simple' => 'This would stop a client\'s own pages from accidentally competing with each other in search.',
                 'detail' => "Not directly confirmed either way on the live LPC engine in this audit. Fully built AND proven on the new PlusCities rewrite (2026-11-21), the first time any of this was exercised against a real sibling page rather than an empty comparison set: real word-shingle uniqueness check (2.6% similarity measured between two real same-client, same-service pages in different cities), a real cannibalization check (same client+city+service blocked outright), a per-client configurable slug pattern (url_base_path, e.g. \"service-area\"), and breadcrumbs built as two genuinely separate arrays — relative for the visible nav, absolute for BreadcrumbList schema — per this codebase's own documented past bug where reusing one array for both sent visible clicks off-site."],
            ],
        ],
        [
            'title' => 'Agency admin',
            'rows' => [
                ['cap' => 'Invite / remove users, assign roles', 'roles' => 'Agency Admin', 'status' => 'todo', 'gate' => 'retain', 'effort' => 'major',
                 'simple' => 'This would let an agency add its own team members, each with their own login.',
                 'detail' => "Each agency has exactly one shared login. There's no per-person user list or invite flow at all. Retention: becomes necessary once an agency wants to add its own staff; a single shared login works fine for a first pilot."],
                ['cap' => 'White-label: logo, colors, custom domain, plugin name — zero PlusCities/AI visible to a client', 'roles' => 'Agency Admin', 'status' => 'todo', 'gate' => 'sell', 'effort' => 'quick',
                 'simple' => 'This is making sure nothing about the product ever looks like it was built with AI.',
                 'detail' => "Highest-priority gap found in this whole audit, and worse now than when first found: this admin lets an agency save a display name + logo, but nothing shows it anywhere anymore — the one place it was ever displayed (the sidebar brand mark) was deliberately changed this session to always read \"PlusCities,\" so an agency's own branding is now stored but invisible everywhere, not shown anywhere in its own admin experience at all. On top of that, the WordPress plugin shipped into every client's own site is hardcoded \"Landing Page Client\" / \"Site Factory\", can't be renamed, and its own in-admin info popup — visible to anyone with WP admin access on the CLIENT'S OWN SITE — literally says \"real AI content generation,\" \"AI-drafted pages,\" \"the Regenerate button is real now.\" This directly violates the \"never present as an AI product\" rule on the single most client-visible surface that exists. Mostly copy/branding-string edits, not new architecture — fix this before anything else on this page. Must-have to sell: this is the single biggest risk to kill a sale or create a support fire the moment an agency notices it. Partial parallel fix already exists on the new PlusCities rewrite: admin/pluscities_plugin_download.php zips the delivery plugin fresh per download, text-substituting every \"PlusCities\" occurrence for the requesting agency's own branding.display_name — the plugin a client receives already carries the agency's name, not the platform's. Does not yet address the AI-admission popup language or a custom domain."],
                ['cap' => 'Agency billing/plan/invoices + Stripe Connect rebilling to its own clients', 'roles' => 'Agency Admin', 'status' => 'todo', 'gate' => 'differentiate', 'effort' => 'major',
                 'simple' => 'This would let an agency bill its own clients directly through the platform.',
                 'detail' => 'Real Stripe billing exists per agency today — checkout, webhook signatures verified with real HMAC, failed-payment handling. Agency-rebills-its-own-clients via Stripe Connect is explicitly not built; deferred on purpose, noted in the code itself. Differentiator: agencies can already bill their own clients outside the platform; this just saves them a step later.'],
                ['cap' => 'Cancel a client: 301-vs-410 choice, 14-day grace, plugin auto-handles, footer link removed', 'roles' => 'Agency Admin', 'status' => 'todo', 'gate' => 'sell', 'effort' => 'moderate',
                 'simple' => 'This is the proper way to shut a client\'s pages down for good.',
                 'detail' => "Doesn't exist as designed — and the current plugin cache actively works against it. Today \"cancel\" just disables a client's API key, but the plugin falls back to its last-known-good cached copy on any failed fetch and keeps serving those pages indefinitely. Disabling a client does NOT take their pages down. A real redirect/takedown mechanism needs to be built, not just wired to the existing toggle. Must-have to sell: an agency won't trust the platform with real client relationships if canceling doesn't actually work — this is a liability, not a missing nicety."],
                ['cap' => 'Audit log (who did what, when — filterable/exportable)', 'roles' => 'Agency Admin (read)', 'status' => 'todo', 'gate' => 'retain', 'effort' => 'moderate',
                 'simple' => 'This would keep a record of who changed what, and when.',
                 'detail' => "Doesn't exist. Only impersonation start/stop write to the server error log — nothing records edits, publishes, or deletes, and none of it is queryable or exportable. Retention/trust feature once real money and real client relationships are flowing — not something a prospect checks before signing up."],
            ],
        ],
        [
            'title' => 'Platform only',
            'rows' => [
                ['cap' => 'Manage agencies, wholesale pricing, plans', 'roles' => 'Platform Admin', 'status' => 'todo', 'gate' => 'retain', 'effort' => 'quick',
                 'simple' => 'This is where the Platform Admin sets up new agencies and what they pay.',
                 'detail' => "Can create agencies and set a fee, but there's no actual pricing-tier catalog — just a free-text plan field and a number. Retention/ops convenience for the Platform Admin; a free-text plan field works fine for the first handful of agencies."],
                ['cap' => 'Per-agency approval-gate toggle (on/off)', 'roles' => 'Platform Admin', 'status' => 'complete',
                 'simple' => 'This lets the Platform Admin turn the two-stage review on or off for one specific agency, instead of it applying the same way to every agency.',
                 'detail' => "A real per-agency setting (agency.requires_approval_gate, default on), not a hardcoded exemption for one agency. Platform-Admin-only, not agency self-service — the agency itself has no way to flip its own switch."],
                ['cap' => 'Edit niche presets / default banned phrases', 'roles' => 'Platform Admin', 'status' => 'todo', 'gate' => 'sell', 'effort' => 'quick',
                 'simple' => 'This is where the Platform Admin sets the default rules every industry\'s content has to follow.',
                 'detail' => 'Niche Profiles exist (tone, "AI guardrails" per niche) but there\'s no distinct "default banned phrases" concept separate from that free-text guardrail blob. Must-have to sell: the easy half of the validator story — pairs with the Client Setup section\'s banned-phrases row.'],
                ['cap' => 'Impersonate any user — logged, visible banner', 'roles' => 'Platform Admin', 'status' => 'complete',
                 'simple' => 'This lets the Platform Admin see the product exactly as a specific agency or client would see it.',
                 'detail' => 'One-hop-only (no stacking), orange banner always shown, every start/stop logged.'],
                ['cap' => 'Suspend an agency/site or force-unpublish', 'roles' => 'Platform Admin', 'status' => 'todo', 'gate' => 'retain', 'effort' => 'quick',
                 'simple' => 'This would let the Platform Admin shut something down platform-wide if something goes wrong.',
                 'detail' => "Agency suspend/reactivate exists. There's no platform-level per-site or per-page force-unpublish beyond what an agency can already do to itself. Retention: a safety net for when things go wrong at scale, not something needed to close a first sale."],
                ['cap' => 'Global usage, health and revenue dashboard', 'roles' => 'Platform Admin', 'status' => 'todo', 'gate' => 'retain', 'effort' => 'moderate',
                 'simple' => 'This would give the Platform Admin one place to see how the whole business is doing.',
                 'detail' => 'This Platform Information page currently covers login history only. No fleet-wide view of plugin versions/themes/site health, and no revenue rollup beyond reading each agency\'s row individually. Retention/ops visibility for the Platform Admin as the fleet grows; invisible to the agency or client either way.'],
            ],
        ],
        [
            'title' => 'Plugin (cross-cutting — affects every client site)',
            'rows' => [
                ['cap' => 'Pulls only live pages, via signed API-key requests', 'roles' => 'n/a', 'status' => 'todo', 'gate' => 'retain', 'effort' => 'moderate',
                 'simple' => 'This is the client\'s website only ever showing pages that are actually supposed to be public.',
                 'detail' => 'The "only published pages" guarantee is real and correct. "Signed" is not — it\'s a plain bearer token in a URL parameter, no HMAC signature, no replay protection. Retention/security hardening: matters once real client traffic and data are flowing at volume; the current bearer-token approach isn\'t broken, just not hardened.'],
                ['cap' => 'Local cache + offline fallback if the server is down', 'roles' => 'n/a', 'status' => 'complete',
                 'simple' => 'This keeps a client\'s pages working even if our own server goes down.',
                 'detail' => 'Poll-based (not webhook-based), but genuinely verified: pages kept rendering after the delivery server was shut off entirely during testing.'],
                ['cap' => 'Reports health to server (version, theme, SEO plugin, last sync)', 'roles' => 'Platform Admin, Agency Admin', 'status' => 'todo', 'gate' => 'retain', 'effort' => 'quick',
                 'simple' => 'This would let the Platform Admin see which clients are running an outdated version of the plugin.',
                 'detail' => "Only a last-checkin timestamp is sent today. Plugin version, active theme, and active SEO plugin are detected locally for rendering decisions but never phoned home — so there's no way to see from here which clients are running an outdated plugin. Retention/ops: lets the Platform Admin spot an outdated plugin across the fleet; invisible to the agency or client either way."],
                ['cap' => 'Clean uninstall (no orphan data left behind)', 'roles' => 'n/a', 'status' => 'todo', 'gate' => 'retain', 'effort' => 'quick',
                 'simple' => 'This is making sure nothing gets left behind if a client ever removes the plugin.',
                 'detail' => "No custom database tables, so no orphan-table risk — but there's also no uninstall hook at all. Removing the plugin leaves its option rows behind in the client's database forever. Retention/professionalism: only matters once a client actually removes the plugin, which won't happen before or during a first sale."],
            ],
        ],
        [
            'title' => 'Positioning compliance — "never present as an AI product"',
            'rows' => [
                ['cap' => 'No AI/generate/regenerate/prompt language anywhere a client can see it', 'roles' => 'All', 'status' => 'todo', 'gate' => 'sell', 'effort' => 'quick',
                 'simple' => 'This means a client never sees the word "AI" or "generate" anywhere in the product.',
                 'detail' => "Violated in several places today, worst inside the WP plugin's own in-admin popup — visible on the client's own WordPress install (see the White-label row above). Also present throughout the agency-facing admin (client.php, page_editor.php, client_save.php, client_wizard.php — buttons/labels/confirm dialogs saying \"AI\", \"Regenerate\", \"AI-generated\"). Genuinely clean today: the End Client's own portal and the actual page HTML a visitor sees — neither has ever shown AI language. Fix order: the plugin popup first (worst exposure, lives inside the client's own site), then a wording pass over the agency-facing screens toward the spec's own suggested phrase — \"Request an update\" instead of \"Regenerate.\" Must-have to sell: this directly contradicts the core sales pitch — \"never looks like AI\" — the moment an agency or their client actually looks."],
            ],
        ],
        [
            'title' => 'Tier 2 — retention',
            'rows' => [
                ['cap' => 'Lead-capture CTA form (city/service attached, emailed + webhook)', 'roles' => 'Platform Admin, Agency Admin', 'status' => 'todo', 'gate' => 'retain', 'effort' => 'major',
                 'simple' => 'This would let a visitor fill out a real form instead of just tapping a phone number.',
                 'detail' => "Doesn't exist. Only tel:/CTA click counters exist today — no actual form, no email-on-submit, no webhook. Retention: a stronger renewal story later, but \"Tried to Contact\" click counts are enough evidence to close a first sale."],
                ['cap' => 'Bulk expansion: "Add city" / "Add service" / CSV import', 'roles' => 'Platform Admin, Agency Admin, Agency Editor', 'status' => 'todo', 'gate' => 'retain', 'effort' => 'moderate',
                 'simple' => 'This is adding a new city or service to a client\'s site all at once instead of one page at a time.',
                 'detail' => '"Add city" (draft every service for a new city) works and is genuinely complete. Whether "Add service" retroactively expands across every existing city was not confirmed either way in this audit — test directly before relying on it. CSV import of cities/services doesn\'t exist for this product (it exists only for the unrelated niche-fleet system). Retention: matters once an agency is actively growing an existing client\'s page count; "Add city" already works for onboarding a new one.'],
                ['cap' => 'Plan-limit enforcement, overage -> upgrade prompt', 'roles' => 'Platform Admin, Agency Admin', 'status' => 'todo', 'gate' => 'retain', 'effort' => 'moderate',
                 'simple' => 'This would stop a client from generating more pages than they\'re actually paying for.',
                 'detail' => 'Billing today is a flat rate per location, not a page-count tier — there\'s no cap and no overage/upgrade-prompt logic. Retention: becomes necessary once there are enough real agencies to need usage guardrails; nothing stops a first sale from running without it.'],
            ],
        ],
        [
            'title' => 'Tier 3 — differentiators',
            'rows' => [
                ['cap' => 'Scheduled refresh (quarterly/seasonal regeneration)', 'roles' => 'Platform Admin, Agency Admin', 'status' => 'todo', 'gate' => 'differentiate', 'effort' => 'major',
                 'simple' => 'This would automatically freshen up old content on a regular schedule.',
                 'detail' => "Doesn't exist — the only scheduling that exists controls the plugin's own cache refresh interval, not server-side content regeneration. Differentiator: a freshness nicety well after launch, not something a prospect asks about before buying."],
                ['cap' => 'GSC-driven expansion suggestions', 'roles' => 'Platform Admin, Agency Admin', 'status' => 'todo', 'gate' => 'differentiate', 'effort' => 'major', 'simple' => 'This would suggest new cities to add based on real search performance.',
                 'detail' => "Doesn't exist. Differentiator: a nice-to-have upsell prompt once there's real ranking data to act on."],
                ['cap' => 'Spanish /es/ version + hreflang', 'roles' => 'Platform Admin, Agency Admin', 'status' => 'todo', 'gate' => 'differentiate', 'effort' => 'moderate', 'simple' => 'This would offer a Spanish-language version of a client\'s pages.',
                 'detail' => "Doesn't exist for this product. Differentiator: only relevant to agencies with bilingual-market clients — a narrow vertical expansion, not a launch gap."],
                ['cap' => 'Webhooks / Zapier outbound events', 'roles' => 'Platform Admin, Agency Admin', 'status' => 'todo', 'gate' => 'differentiate', 'effort' => 'moderate',
                 'simple' => 'This would let other tools automatically react when something happens in this product.',
                 'detail' => 'Doesn\'t exist — the only webhook in the system is Stripe\'s inbound one. Differentiator: an integration nicety for technically sophisticated agencies, not something a first sale depends on.'],
            ],
        ],
    ];
}

// platform_info_city_page_blocks_data() moved to
// includes/pluscities_block_catalog.php (2026-11-22) — required at the top
// of this file — so the real agency-facing City Service Page picker
// (admin/client.php) and this read-only doc page share one source.

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
                 'what' => 'The onboarding wizard: business identity + niche, services offered, hub metro(s), target cities per hub.',
                 'who' => 'Platform Admin, Agency Admin', 'where' => 'client_wizard.php (5 steps)'],
                ['step' => 'Auto-draft every page', 'status' => 'in_place',
                 'simple' => 'The system automatically writes a first draft of every page for you, the moment the client is created.',
                 'what' => "Finishing the wizard immediately AI-drafts a page for every city \u{d7} service combination (real AI cost per page) — before Content Profile or Research exist yet, so this first pass is generic. Nothing publishes yet.",
                 'who' => 'System — triggered by finishing the wizard', 'where' => 'Automatic, part of the client_wizard.php submit'],
                ['step' => 'Set the Content Profile', 'status' => 'in_place',
                 'simple' => 'This is where you tell the system how the client wants to sound and what to avoid mentioning.',
                 'what' => "Tone, excluded services, and always-mention/never-mention guardrails. Doesn't retroactively change the pages already drafted in the step above — a Regenerate pass (step 6) is what actually applies it. Missing fields: structured address, license numbers, hours, years-in-business (see the Product Roadmap tab's Client Setup section).",
                 'who' => 'Platform Admin, Agency Admin', 'where' => 'client.php → Content Profile tab'],
                ['step' => 'Research a city', 'status' => 'in_place',
                 'simple' => "This pulls in real facts about a city so the content isn't generic.",
                 'what' => 'Real Census population lookup — free, runs once per unique city among the pages checked. Same as Content Profile: existing drafts only pick this up once you Regenerate (step 6).',
                 'who' => 'Platform Admin, Agency Admin', 'where' => 'client.php → Cities & Services tab → "Research Selected"'],
            ],
        ],
        [
            'title' => 'B — Edit, lock & regenerate',
            'rows' => [
                ['step' => 'Edit page content by hand', 'status' => 'in_place',
                 'simple' => 'This is where someone can manually change the words on a page.',
                 'what' => 'A field editor per block (hero, intro, steps, FAQ, etc.) plus SEO title/meta/keywords. Editing an already-published page flips its status to "Needs Update" rather than silently leaving it shown as current. Page-level, not block-level: it\'s one form for the whole page — hitting Save rewrites every block together in one request, there\'s no way to save just one block on its own.',
                 'who' => 'Platform Admin, Agency Admin', 'where' => 'page_editor.php'],
                ['step' => 'Regenerate a page with AI', 'status' => 'in_place',
                 'simple' => "This tells the system to rewrite a page's content from scratch.",
                 'what' => 'Reruns the fixed AI prompt template for one page, or "Generate / Refresh Selected" for many at once — with its own checkboxes for refresh hero image, re-fetch climate data ($), regenerate text ($), and skip locked pages. Page-level, not block-level: its own confirm dialog says "replaces all current section content" (the code\'s own wording still says "section," not yet renamed) — every block on the page gets rewritten together, not one block in isolation.',
                 'who' => 'Platform Admin, Agency Admin', 'where' => 'page_editor.php "Regenerate" button, or client.php → Cities & Services → "Generate / Refresh Selected"'],
                ['step' => 'Steer a page regenerate', 'status' => 'needs_adding',
                 'simple' => "This would let someone tell the system exactly how to change the rewrite, like \"make it shorter.\"",
                 'what' => "Doesn't exist — every regenerate reruns the identical fixed prompt, with no free-text instruction field. Would be page-level like regenerate itself is today, unless built block-by-block alongside block-level locking (see \"Lock one block within a page\" below).",
                 'who' => 'Would be: Platform Admin, Agency Admin', 'where' => 'Would extend the existing Regenerate control in page_editor.php'],
                ['step' => 'Lock a page', 'status' => 'in_place',
                 'simple' => "This protects a finished page so it can't be accidentally overwritten.",
                 'what' => 'Blocks Regenerate from touching that page. Page-level, not block-level — locking a page locks every block in it, there\'s no way to lock just one block.',
                 'who' => 'Platform Admin, Agency Admin', 'where' => 'page_editor.php "Lock" toggle (also shown in the Cities & Services Status column)'],
                ['step' => 'Lock one block within a page', 'status' => 'needs_adding',
                 'simple' => 'This would let someone protect just one part of a page instead of the whole thing.',
                 'what' => "Doesn't exist — there's no way to protect just the intro while regenerating the rest of the same page.",
                 'who' => 'Would be: Platform Admin, Agency Admin', 'where' => 'Would extend page_editor.php'],
                ['step' => 'Roll back a page to a previous version', 'status' => 'needs_adding',
                 'simple' => 'This would let someone undo a change and go back to an earlier version of a page.',
                 'what' => "Doesn't exist — every save overwrites in place. There's no version history and nothing to roll back to. Would be page-level like every other save action today — restoring a whole page's worth of blocks at once, not a single block in isolation.",
                 'who' => 'Would be: Platform Admin, Agency Admin', 'where' => 'Would be a new history/diff view on page_editor.php'],
            ],
        ],
        [
            'title' => 'C — Review & approve',
            'rows' => [
                ['step' => 'Decide whether an agency requires the approval gate', 'status' => 'in_place',
                 'simple' => 'This lets the Platform Admin turn the two-stage review on or off for one specific agency.',
                 'what' => "A real per-agency on/off switch (agency.requires_approval_gate, default on). Platform-Admin-only; an agency has no way to turn this off for itself.",
                 'who' => 'Platform Admin only', 'where' => 'admin/settings.php'],
                ['step' => 'Validate a page\'s content before it can be approved', 'status' => 'needs_adding',
                 'simple' => "This would automatically check a page for mistakes or forbidden wording before it's allowed to go live.",
                 'what' => "Doesn't exist — nothing blocks a page with a banned phrase or a wrong phone number from being approved and published.",
                 'who' => 'Would run automatically at submit/approve time', 'where' => 'Would live inside the approvals.php gate'],
                ['step' => 'Submit for review', 'status' => 'in_place',
                 'simple' => 'This sends a finished page off to be checked before it goes live.',
                 'what' => "Clicking Publish. Enters the same two-stage approval queue (approval_stage: pending_platform_review) — unless the Platform Admin has turned the gate off for that specific agency (see the row above).",
                 'who' => 'Platform Admin, Agency Admin', 'where' => 'page_editor.php "Publish" button, or client.php → Cities & Services → "Publish Selected"'],
                ['step' => 'Platform review — stage 1', 'status' => 'in_place',
                 'simple' => "This is the Platform Admin's first check on a page an agency submitted.",
                 'what' => "The Platform Admin reviews what an agency submitted. \"Approve → Agency\" advances it to pending_agency_review; \"Reject\" sends it back with a note — the page's own content is untouched, just not live. Only reached if that agency's gate is on (see the first row of this section).",
                 'who' => 'Platform Admin only', 'where' => 'approvals.php'],
                ['step' => 'Agency review — stage 2', 'status' => 'in_place',
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
                 'who' => 'Would be: Platform Admin, Agency Admin', 'where' => 'Would extend approvals.php'],
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
                 'who' => 'Platform Admin, Agency Admin send it; the client\'s own WP admin installs it', 'where' => 'client.php → Delivery tab; status shown on clients.php'],
                ['step' => "Check what's actually live", 'status' => 'in_place',
                 'simple' => "This double-checks that what's really showing on the client's site matches what it should.",
                 'what' => "Fetches each page's real public URL and compares it against what the data says it should be (hero image, area map, climate chart) — catches a stale CDN cache that a status badge alone would miss.",
                 'who' => 'Platform Admin, Agency Admin', 'where' => 'client.php → Cities & Services → "Check Live Selected"'],
                ['step' => 'Take a single page down', 'status' => 'needs_adding',
                 'simple' => 'This would let someone remove just one page without touching the rest of the site.',
                 'what' => "Doesn't exist as a per-page action — the only kill switch today deactivates the client's entire API key, taking every page down at once, not one.",
                 'who' => 'Would be: Platform Admin, Agency Admin', 'where' => 'Would extend page_editor.php / Cities & Services'],
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
                 'who' => 'Platform Admin, Agency Admin paste the URL; the client grants access', 'where' => 'client.php → Reporting tab'],
                ['step' => 'Refresh content on a schedule (quarterly/seasonal)', 'status' => 'needs_adding',
                 'simple' => 'This would automatically update page content every so often without anyone having to ask for it.',
                 'what' => "Doesn't exist — nothing regenerates content automatically; every refresh today is manually triggered.",
                 'who' => 'Would be: System, on a schedule', 'where' => 'Would be a new scheduler'],
                ['step' => 'Cancel a client properly', 'status' => 'needs_adding',
                 'simple' => "This would be the correct way to shut down a client's pages for good.",
                 'what' => "Doesn't exist as designed — today \"cancel\" just disables the API key, but the plugin's own cache keeps serving the client's last-known-good pages indefinitely regardless. Disabling a client does NOT actually take their pages down.",
                 'who' => 'Would be: Platform Admin, Agency Admin', 'where' => 'Would extend client.php'],
                ['step' => 'See a record of who did what, when', 'status' => 'needs_adding',
                 'simple' => 'This would keep a history log of every change anyone made and when they made it.',
                 'what' => "Doesn't exist — only impersonation start/stop is logged anywhere; content edits, publishes, and deletes leave no trail.",
                 'who' => 'Would be: Agency Admin (read), Platform Admin', 'where' => 'Would be a new audit log'],
            ],
        ],
    ];
}

/**
 * Niche-to-SEO-page pipeline — how a niche gets created and how that work
 * turns into live, ranking-ready pages, across BOTH products. Verified by
 * reading docs/landing-page-build-process-V1-20260709.md,
 * docs/niche-brief-and-research.md, docs/lpc-vs-site-factory-boundary.md,
 * and the real code they describe (2026-11-21) — not carried over from
 * memory.
 *
 * Two separate pipelines, not one shared system: Site Factory (sections
 * A-G) is the real keyword-research-driven build for niche-fleet sites
 * (water/mold/pest/appliance-style). LPC (section H) is a lighter
 * per-client content pipeline with no keyword research of its own. They
 * share exactly one thing — the Niche Library (section A) — and it's a
 * one-time copy-in, not a live link: editing the library later does not
 * retroactively change a niche that already pulled from it.
 */
function platform_info_content_flow_data(): array {
    return [
        [
            'title' => 'A — Niche Library (the one shared starting point)',
            'rows' => [
                ['step' => 'Draft a reusable niche starter kit', 'status' => 'in_place',
                 'simple' => 'This is a shared shelf of starter facts about an industry that either product can pull from.',
                 'what' => "Services/slugs/keyword-pattern catalog, trust-bar phrases, local/seasonal angle, blog topic ideas, custom research fields. Deliberately does NOT hold guardrails or business_descriptor — those stay hand-authored separately on each side, since Site Factory frames itself as a referral network (\"never say our team did the repair\") while LPC frames the client as the one actually doing the work — merging the two would produce factually wrong claims about who performs the work.",
                 'who' => 'Platform Admin only', 'where' => 'admin/niche_library.php → niche_library/{slug}.json'],
                ['step' => 'Pull starter facts into a real niche (either product)', 'status' => 'in_place',
                 'simple' => "This copies the library's starter facts into one specific niche, once.",
                 'what' => "A one-time copy-in, not a live link — a later library edit does not retroactively change a niche brief or profile that already pulled from it. Same action on both sides: a \"Pull facts from the Niche Library\" button.",
                 'who' => 'Platform Admin only', 'where' => 'admin/niche_brief_save.php (Site Factory) or admin/niche_profiles_save.php (LPC)'],
            ],
        ],
        [
            'title' => 'B — Site Factory: niche setup',
            'rows' => [
                ['step' => 'Score whether the niche is worth building at all', 'status' => 'needs_adding', 'gate' => 'sell', 'effort' => 'moderate',
                 'simple' => 'This would score whether a whole niche is worth building before any pages exist, not just which cities to expand into once you\'ve already committed.',
                 'what' => "Doesn't exist — the real keyword-scoring formula (Demand × Winnability × Lead-value) only runs once a niche is already being built, to pick cities. Nothing scores the niche's own core services for real keyword volume/competition/CPC before committing 30+ pages and real AI/image cost to it. Already part of the process: the scoring formula and its real data providers (Ahrefs/DataForSEO) exist and work — this would reuse them for a new, earlier decision point, not invent new infrastructure.",
                 'who' => 'Would be: Platform Admin', 'where' => 'Would extend admin/infra/lib/keywords.php (infra_kw_score()), as a new step before the niche brief'],
                ['step' => 'Pick a niche ID', 'status' => 'in_place',
                 'simple' => 'This is naming the industry — and it has to match exactly, or charts silently vanish.',
                 'what' => "Must exactly match the folder slug under plugins/image-data-chart/niches/{slug}/ — a mismatch doesn't error, it just quietly produces no chart at all.",
                 'who' => 'Platform Admin', 'where' => 'plugins/image-data-chart/niches/{slug}/, admin/tabs/niche_brief.php'],
                ['step' => 'Fill out the Niche Brief', 'status' => 'in_place',
                 'simple' => 'This is where you describe the industry: how it talks, what it offers, and what it can never claim.',
                 'what' => "Business descriptor, service/customer nouns, offerings, local angle, tone, guardrails (the only place licensing/advertising-rule language lives), research fields, enabled archetypes.",
                 'who' => 'Platform Admin', 'where' => 'admin/tabs/niche_brief.php → admin/niche_brief_save.php → sites/{id}/multisite/niche_brief.json'],
                ['step' => 'Compile the brief into the AI block registry', 'status' => 'in_place',
                 'simple' => 'This turns the brief into the real instructions the AI uses, automatically, every time you save.',
                 'what' => "Runs on every brief save, so the brief and the registry can never drift out of sync with each other. A shared-library edit to the archetype file does NOT auto-propagate though — each master needs its own manual \"Compile Registry Now.\"",
                 'who' => 'System — triggered by saving the brief', 'where' => 'multisite/ai/compile.php → ms_ai_compile_master() → sites/{id}/ai_block_types.json'],
                ['step' => 'Decide upfront which blocks and differentiators actually make sense for this niche', 'status' => 'needs_adding', 'gate' => 'retain', 'effort' => 'moderate',
                 'simple' => "This would plan what actually makes a page win in THIS niche specifically — not just which blocks to include, since that varies a lot by industry.",
                 'what' => "Today block composition is decided ad hoc while hand-building the master template (next section), and archetypes branch off it when intent clearly differs (inspection vs. category pages) — but there's no deliberate step that asks what actually differentiates a winning page in this specific niche (real licensing/certifications for a regulated trade, real response time for an emergency service, transparent pricing for a commodity service, etc.) and which blocks support that. The niche brief already has a free-text local_angle and guardrails field, but nothing structured for \"what wins in this niche.\" Would turn an implicit choice made while building the template into a real planning step tied to the niche brief itself.",
                 'who' => 'Would be: Platform Admin', 'where' => 'Would extend admin/tabs/niche_brief.php, before the Templates tab'],
            ],
        ],
        [
            'title' => 'C — Site Factory: the keyword map (fully manual, zero AI)',
            'rows' => [
                ['step' => 'Build the keyword map', 'status' => 'in_place',
                 'simple' => "This is deciding, by hand, what every single page is actually trying to rank for.",
                 'what' => "One row per service: a primary keyword (the page's real target, drives the URL/H1/title), secondary keywords (variant phrasings woven into the copy, never given their own URL — the rank-and-rent rule), section, tier. Fully manual: no auto-seed, no auto-fill, no AI.",
                 'who' => 'Platform Admin', 'where' => 'admin/tabs/keywords.php → admin/keywords_save.php → data/keyword_map.json'],
                ['step' => 'Use tiers to actually decide build order', 'status' => 'needs_adding', 'gate' => 'retain', 'effort' => 'quick',
                 'simple' => "This would make the keyword map's existing tier field actually decide which pages get built and polished first.",
                 'what' => "Partially there already: every service in the keyword map already has a tier field, right in data/keyword_map.json — but nothing reads it to decide build order. Every service gets generated and reviewed in the same arbitrary order regardless of tier today.",
                 'who' => 'Would be: Platform Admin', 'where' => 'Would extend admin/generate.php / the AI generation queue order'],
                ['step' => 'Score candidate cities for expansion', 'status' => 'in_place',
                 'simple' => 'This is a formula that scores a city using real demand, real difficulty, and real ad value — not a guess.',
                 'what' => "Demand (search volume, log-scaled) × Winnability (1 − keyword difficulty) × Lead value (CPC), geometric mean, scaled 1-10 — a zero on any one factor sinks the whole score on purpose. Real data from Ahrefs or DataForSEO, separately calibrated per provider since they report very different absolute numbers for the same city. A human decision aid, not an automatic trigger — nothing gets built just because it scored well.",
                 'who' => 'Platform Admin (decides), System (scores)', 'where' => 'admin/infra/lib/keywords.php (infra_kw_score()), admin/infra/views/cities.php'],
                ['step' => 'Check for keyword cannibalization before generating', 'status' => 'needs_adding', 'gate' => 'sell', 'effort' => 'moderate',
                 'simple' => 'This would catch two pages competing for the same real keyword before both go live and hurt each other\'s ranking.',
                 'what' => "Doesn't exist for Site Factory — the same confirmed gap already flagged for LPC on the Product Roadmap tab. Two services' primary keywords, or a service page vs. its own city-hub page, can end up targeting the same real intent — both pages then compete with each other instead of each owning their own ranking, which actively hurts both, not just a missed opportunity. Would run against the finished keyword map before bulk generation, not after 30+ pages already exist.",
                 'who' => 'Would be: Platform Admin', 'where' => 'Would extend admin/keywords_save.php / admin/tabs/keywords.php'],
            ],
        ],
        [
            'title' => 'D — Site Factory: master template + bulk generation',
            'rows' => [
                ['step' => 'Build one master landing-page template', 'status' => 'in_place',
                 'simple' => 'This is designing the one real page that every other page gets cloned from.',
                 'what' => "The real, fully-built template for one service — every other service's page starts as a clone of this one.",
                 'who' => 'Platform Admin', 'where' => 'Templates tab'],
                ['step' => 'Bulk-generate every other service from it', 'status' => 'in_place',
                 'simple' => 'This clones the master page for every other service automatically, and double-checks its own work first.',
                 'what' => "Case-aware find/replace pairs (e.g. cockroach→termite) clone the master to every other service. A dry-run pass flags leftover subject words or missing images before anything is actually committed.",
                 'who' => 'Platform Admin', 'where' => 'admin/templates_save.php (bulk_generate action)'],
                ['step' => 'Branch off real archetypes where intent differs', 'status' => 'in_place',
                 'simple' => "This builds a genuinely different page shape for services that aren't just a word-swap of the master.",
                 'what' => "Inspection pages, category/plans pages, etc. — built directly off the master skeleton for structure, but with their own prompt logic, not forced through the same find/replace as a same-shaped service.",
                 'who' => 'Platform Admin', 'where' => 'multisite/ai/archetypes.json, enabled_archetypes[] on the niche brief'],
                ['step' => 'Sync keywords and cross-links onto every template', 'status' => 'in_place',
                 'simple' => 'This makes sure every cloned page actually got the right keywords and links, not just the master\'s.',
                 'what' => "Secondary keywords from the keyword map get synced onto every template's seo.secondary_keywords; related-service cross-links get re-synced from templates.json. Documented as a recurring drift risk if a template is added later and this step is skipped.",
                 'who' => 'Platform Admin', 'where' => 'Templates tab'],
            ],
        ],
        [
            'title' => 'E — Site Factory: cities & real research',
            'rows' => [
                ['step' => 'Add target cities', 'status' => 'in_place',
                 'simple' => 'This is picking which real towns get a page.',
                 'what' => "Geocoding (lat/lng via OpenStreetMap) always runs first regardless of niche settings — schema needs real coordinates either way.",
                 'who' => 'Platform Admin', 'where' => 'Landing Cities tab, or the Batch tab\'s multisite flow'],
                ['step' => 'Run AI research per city', 'status' => 'in_place',
                 'simple' => "This has the AI go research real facts about each city instead of inventing generic filler.",
                 'what' => "Claude researches real facts per city (industries, top employers, market blurb, local note). Self-deciding: a never-researched city gets a full pass; an already-researched city is only re-asked for what's missing, and a field declined twice is left alone for good. Stored once on the master, reused free by every domain cloned from it.",
                 'who' => 'System (Claude), triggered by Platform Admin', 'where' => 'generate.py --research-only (run_research_step())'],
                ['step' => 'Pull real government climate data', 'status' => 'in_place',
                 'simple' => "This gets real weather data from the government instead of letting the AI guess numbers.",
                 'what' => "NOAA 1991-2020 Climate Normals, fetched directly — never sent to the AI as something to guess, specifically so the model can't fabricate numbers next to a cited government source.",
                 'who' => 'System (automatic)', 'where' => 'noaa_normals.py'],
                ['step' => 'Verify neighborhood names', 'status' => 'in_place',
                 'simple' => "This double-checks real neighborhood names instead of trusting whatever the AI assumes.",
                 'what' => "OpenStreetMap first (free); a targeted Claude follow-up only for whatever OSM couldn't resolve.",
                 'who' => 'System (automatic)', 'where' => 'sync_osm_neighborhoods()'],
            ],
        ],
        [
            'title' => 'F — Site Factory: page generation (two passes, in order)',
            'rows' => [
                ['step' => 'Generate page structure (no AI, no cost)', 'status' => 'in_place',
                 'simple' => 'This creates the empty shell of every page first, before spending anything on AI.',
                 'what' => "Clones the template per city, resolves placeholders like {city}/{state}, writes one page file per city×template, rebuilds the page index.",
                 'who' => 'Platform Admin triggers; System executes', 'where' => 'includes/generation/engine.php (generate_city_pages()), admin/generate.php'],
                ['step' => 'Clean up orphan pages', 'status' => 'in_place',
                 'simple' => 'This deletes leftover page files from templates that no longer exist, before paying to fill them with content.',
                 'what' => "Stale page files from removed templates are cleared before the AI content pass, so they don't pollute the page index or waste AI spend.",
                 'who' => 'System (automatic)', 'where' => 'includes/generation/engine.php'],
                ['step' => 'Generate AI content per page', 'status' => 'in_place',
                 'simple' => 'This is the AI actually writing the real words on every page.',
                 'what' => "Fills every AI block from the niche's own prompt registry — roughly $0.20/city for a 30-page set. force_locked wipes existing content without refilling, so it's not safe to use at scale on an already-filled site.",
                 'who' => 'Platform Admin triggers; System (Claude) executes', 'where' => 'generate.py, admin/ai_generate.php'],
                ['step' => 'Generate blog posts (optional, per domain)', 'status' => 'in_place',
                 'simple' => "This writes real blog posts, but only publishes the ones that actually pass a quality check.",
                 'what' => "About 25 hand-authored topics per niche, 3 posts per domain, Haiku model (Sonnet's default thinking burned the token budget on this prompt in testing). A real quality check (word count, keyword-stuffing cap, banned-overclaim phrases) gates auto-publish — a post that fails stays a draft, never force-published.",
                 'who' => 'System (Claude), triggered by Platform Admin', 'where' => 'generate_blog_posts() in generate.py'],
            ],
        ],
        [
            'title' => 'G — Site Factory: anti-duplicate variance, SEO check, deploy',
            'rows' => [
                ['step' => 'Rotate visuals and structure fleet-wide', 'status' => 'in_place',
                 'simple' => "This makes sure a hundred domains built from the same master don't all look like obvious copies to Google.",
                 'what' => "Color/logo pool, photo variation, block-order variance, CSS class-name rotation, schema key-order reordering — all automatic on every batch build.",
                 'who' => 'System (automatic on batch builds)', 'where' => 'Gen-Visual / Gen-Image / Gen-Mod tabs'],
                ['step' => 'Run the SEO gate', 'status' => 'in_place',
                 'simple' => 'This automatically checks that every page is actually set up to rank before it goes out.',
                 'what' => "Checks: primary keyword in every H1, exactly one H1 per page, title leads with the keyword (not just contains it), titles/meta descriptions present and not duplicated, schema types unchanged, canonical points at the site's own domain. Reports only today — it flags problems, it doesn't block a bad build yet.",
                 'who' => 'System (automatic on batch builds)', 'where' => 'includes/multisite/seo_gate.php'],
                ['step' => 'Build the static site', 'status' => 'in_place',
                 'simple' => "This turns everything above into the real HTML files that actually get served.",
                 'what' => "Real static HTML generation, with schema.org JSON-LD (FAQPage/sameAs/BlogPosting) resolved fresh from the page's actual current content every time — never stale, never a fabricated rating or address.",
                 'who' => 'Platform Admin triggers; System executes', 'where' => 'includes/static_build.php, includes/schema.php'],
                ['step' => 'Deploy to the live host', 'status' => 'in_place',
                 'simple' => 'This pushes the finished site to the real server where visitors and Google actually see it.',
                 'what' => "FTP or SFTP push to the live host, driven by each site's own deploy config.",
                 'who' => 'Platform Admin', 'where' => 'includes/multisite/deploy.php, admin/batch.php'],
                ['step' => 'Real-world QA before go-live', 'status' => 'in_place',
                 'simple' => "This is a human double-checking the page actually works well in the real world, not just in our own code.",
                 'what' => "Manual, not code-enforced: PageSpeed Insights 90+, Google Rich Results Test, Mobile-Friendly check, Search Console sitemap submission + indexing request, internal link crawl. Off-page signals (backlinks, citations, Google Business Profile) are explicitly called out as outweighing everything else here for local-service ranking, and none of this tooling does anything for those.",
                 'who' => 'Platform Admin', 'where' => 'Manual — Google\'s own tools'],
            ],
        ],
        [
            'title' => 'H — LPC: one client\'s niche profile to one live page',
            'rows' => [
                ['step' => 'Create a reusable Niche Profile', 'status' => 'in_place',
                 'simple' => "This is the lighter LPC version of a niche brief: tone, rules, and what each section of a page should cover.",
                 'what' => "Tone, guardrails (industry-accuracy/safety rules — never referral-network framing, since an LPC client does their own work), six content-slot instructions, default service list, trust-bar items. Agencies can't edit this — Platform Admin only.",
                 'who' => 'Platform Admin only', 'where' => 'admin/niche_profiles.php'],
                ['step' => "Match a new client to a niche profile", 'status' => 'in_place',
                 'simple' => "This automatically figures out which industry template a new client belongs to.",
                 'what' => "Plain substring match against whatever industry category the new client typed in the onboarding wizard.",
                 'who' => 'System (automatic)', 'where' => 'lpc_match_niche_profile()'],
                ['step' => 'Pull in real per-city facts', 'status' => 'in_place',
                 'simple' => "This gets real government population/income/housing data for the client's city instead of letting the AI guess.",
                 'what' => "Real US Census data — population, median income, home value, homeownership rate, housing stock — not AI-guessed, not the same per-master research file Site Factory uses (a deliberate, separate data source).",
                 'who' => 'System (automatic)', 'where' => 'lpc_get_city_research(), admin/infra/lib/census.php'],
                ['step' => "Generate one page's content", 'status' => 'in_place',
                 'simple' => "This is the actual moment the AI writes a specific page, using the niche profile and the real city facts together.",
                 'what' => "System prompt (descriptor/guardrails/tone/a hard never-invent-a-fact rule) + user prompt (primary/secondary keywords, the real city fact block, per-slot guidance from the niche profile) sent to Claude. Phone number and image are never AI-generated — both filled from real data afterward, and the page title is forced to lead with the primary keyword.",
                 'who' => 'Platform Admin, Agency Admin trigger it; System (Claude) generates', 'where' => 'lpc_generate_page_content(), includes/landing_page_clients_ai.php'],
            ],
        ],
        [
            'title' => 'I — What actually makes any of this rank',
            'rows' => [
                ['step' => 'Real facts, never invented', 'status' => 'in_place',
                 'simple' => "Both products explicitly tell the AI to use only verified real data, or say plainly that none exists — never make something up.",
                 'what' => "Enforced as a hard rule in both AI prompts: never invent a phone number, address, review count, or statistic. Government/authoritative sources (Census, NOAA) are fetched directly rather than asked of the AI specifically so it can't fabricate a number next to a cited real source.",
                 'who' => 'n/a (built into every prompt)', 'where' => 'Both AI pipelines'],
                ['step' => 'Schema.org structured data resolved fresh, every time', 'status' => 'in_place',
                 'simple' => "The hidden markup that helps Google understand a page is rebuilt from the page's real current content, never left stale.",
                 'what' => "FAQPage/sameAs/BlogPosting schema resolved at render time from the page's actual live content — never a fabricated rating or address, and never out of sync with what a visitor actually sees.",
                 'who' => 'n/a (automatic)', 'where' => 'includes/schema.php'],
                ['step' => 'Internal links only point to pages that actually exist', 'status' => 'in_place',
                 'simple' => "A link to another page only appears if that page is actually real on this exact site — never a guess, never a dead link.",
                 'what' => "Related-service and related-city links are checked against the domain's real, currently-built page set at render time — if a page wasn't built for this domain, the link to it simply doesn't render.",
                 'who' => 'n/a (automatic)', 'where' => 'plugins/related_links, plugins/services_links'],
                ['step' => 'Real, unique images — not stock photos', 'status' => 'in_place',
                 'simple' => "Each city gets a real, correct photo of itself and its own unique data chart, not a generic stock image reused everywhere.",
                 'what' => "A real, geo-correct, freely-licensed city photo (Wikimedia Commons) plus genuinely unique per-city data visualizations — incidentally also avoids duplicate alt text across pages.",
                 'who' => 'n/a (automatic)', 'where' => 'plugins/city-image, plugins/image-data-chart, plugins/image-area-map'],
                ['step' => "Anti-duplicate-content machinery, fleet-wide", 'status' => 'in_place',
                 'simple' => "A hundred sites built from the same master don't read as obvious copies of each other to Google.",
                 'what' => "Color/logo rotation, photo treatment variance, block-order variance, CSS class-name rotation, schema key-order shuffling — all automatic across every batch build.",
                 'who' => 'n/a (automatic)', 'where' => 'Gen-Visual / Gen-Image / Gen-Mod tabs'],
            ],
        ],
        [
            'title' => 'J — Converging LPC toward Site Factory\'s rigor (recommendations from 2026-11-21 discussion, not yet decided)',
            'rows' => [
                ['step' => 'Extract keyword scoring into a shared module both products call', 'status' => 'needs_adding', 'gate' => 'sell', 'effort' => 'major',
                 'simple' => 'This would let LPC use real keyword data too, instead of reinventing its own version of something Site Factory already built.',
                 'what' => "Doesn't exist — Site Factory's real keyword-scoring formula (infra_kw_score(), Demand × Winnability × Lead-value) lives only in admin/infra/lib/keywords.php, used only for Site Factory's city-expansion decision. Recommended: extract it into a shared library both products call — matching this codebase's own \"one module per service, reused everywhere\" principle — rather than LPC building a second, separate version. Real new cost: per-client API spend (DataForSEO/Ahrefs) that doesn't exist in LPC today, so this needs to be opt-in or cost-capped per client, not automatic for every one of potentially hundreds of LPC clients. Prerequisite for the next row.",
                 'who' => 'Would be: Platform Admin (build); Agency Admin (opt in per client later)', 'where' => 'Would extend admin/infra/lib/keywords.php into a shared include'],
                ['step' => 'Give LPC a real primary keyword instead of a fill-in-the-blank pattern', 'status' => 'needs_adding', 'gate' => 'sell', 'effort' => 'moderate',
                 'simple' => "This would check that an LPC page's primary keyword is a real search term people actually use, not just an assumed pattern.",
                 'what' => "Today LPC's primary keyword is just \"{service} in {city}, {state}\" or a simple keyword_pattern substitution — no volume data, no check it's a real target at all. This is the single biggest keyword-quality gap between the two products. Depends on the shared keyword-scoring module above.",
                 'who' => 'Would be: Platform Admin, Agency Admin', 'where' => 'Would extend lpc_default_primary_keyword(), includes/landing_page_clients_ai.php'],
                ['step' => "Check for cannibalization across one client's own pages", 'status' => 'needs_adding', 'gate' => 'sell', 'effort' => 'moderate',
                 'simple' => "This would catch two of the SAME client's pages competing for the same keyword, before both go live.",
                 'what' => "Doesn't exist for LPC — same confirmed gap as Site Factory's version (section C), but scoped to one client's own page set instead of a whole niche fleet, which makes it a much smaller, cheaper check (a handful of pages, not 30+).",
                 'who' => 'Would be: Platform Admin, Agency Admin', 'where' => "Would extend client.php's Cities & Services tab, before Publish"],
                ['step' => 'Let a niche profile turn optional sections on/off per niche', 'status' => 'needs_adding', 'gate' => 'retain', 'effort' => 'moderate',
                 'simple' => 'This would stop giving every single LPC client the exact same page skeleton regardless of whether it actually fits that niche.',
                 'what' => "Site Factory has 39 block types + archetypes that change which blocks appear by page intent. LPC has a fixed set of sections that appear on every page, every niche, with no archetype concept at all — a plumbing client and a dog-walking client get an identical page skeleton today. Recommended as a lighter, scoped version of Site Factory's archetype idea (toggle comparison/steps/seasonal_calendar on or off per niche), not a full 39-type system — LPC's simpler one-client-at-a-time use case doesn't need that much variety.",
                 'who' => 'Would be: Platform Admin', 'where' => "Would extend the niche profile's content_slots, admin/niche_profiles.php"],
                ['step' => 'Add a shared per-(city, niche) local-flavor research layer', 'status' => 'needs_adding', 'gate' => 'retain', 'effort' => 'major',
                 'simple' => 'This would give LPC pages real local color — notable industries, a market blurb — instead of just dry Census numbers.',
                 'what' => "Site Factory has a real AI research step gathering qualitative local facts (industries, top employers, market blurb, local note), cached once per master and reused free across every cloned city. LPC only gets real Census numbers (population/income/home value) — real but dry, with no \"what's actually interesting about this place\" layer at all. Recommended cache key: per (city, niche) pair, SHARED across every LPC client in that niche serving that city — if ten different plumbing clients all serve Dallas, that's one research call, not ten, mirroring Site Factory's own per-master caching pattern exactly.",
                 'who' => 'Would be: Platform Admin (build); System (Claude) generates', 'where' => 'Would be a new shared cache + extend includes/landing_page_clients_ai.php'],
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
.pcadmin-subtabs-row-label { font-size:.68rem; text-transform:uppercase; letter-spacing:.06em; color:var(--pc-muted); margin:10px 0 2px; }
.pcadmin-subtabs-row .pcadmin-subtabs { margin-bottom:10px; border-bottom:none; }
.pcadmin-subtabs-row:last-of-type .pcadmin-subtabs { border-bottom:1px solid var(--pc-line); margin-bottom:22px; }
</style>
</head>
<?php lpc_admin_open('platform_info', 'Platform Information'); ?>
<?php foreach ($TAB_ROWS as $row): ?>
<div class="pcadmin-subtabs-row">
    <div class="pcadmin-subtabs-row-label"><?= h($row['label']) ?></div>
    <div class="pcadmin-subtabs">
        <?php foreach ($row['tabs'] as $key): ?>
            <a class="<?= $tab === $key ? 'active' : '' ?>" href="platform_info.php?tab=<?= h($key) ?>"><?= h($TABS[$key]) ?></a>
        <?php endforeach; ?>
    </div>
</div>
<?php endforeach; ?>

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
        <strong>This is a service that builds and sells local-SEO landing pages to other businesses,
        either directly or through agencies.</strong> A multi-city landing-page service sold to
        external businesses (plumbers, restoration companies, etc.) — each client gets one page per city
        &times; service combo (e.g. "Fire Damage Restoration in Plano, TX"), hosted on their own
        WordPress site via a small plugin we give them. Resold through agencies — an agency buys access
        and onboards its own clients, scoped to only what it owns.
    </p>
    <h4 style="margin-bottom:4px;">Roles</h4>
    <ul style="margin:0 0 14px;padding-left:20px;line-height:1.8;font-size:.88rem;">
        <li><strong>This is Scott — the one person who runs the whole platform.</strong>
            <strong>Platform Admin</strong> — approves every new agency (never self-serve),
            sets each agency's monthly fee and spend cap, owns Niche Profiles (global, shared across every
            agency — so one agency can't silently change AI output for another's clients), platform-wide
            reporting, and the first stage of content review.</li>
        <li><strong>This is an agency reselling the product to its own clients.</strong>
            <strong>Agency Admin</strong> — logs in separately (<code>agency_login.php</code>), sees only
            the clients under its own agency (not necessarily ones it personally created — Scott can set
            up a client and hand it to an agency too), pays its own platform bill via Stripe, runs
            onboarding, can save its own branding (logo/display name) on its Agencies entry — stored but
            not currently shown anywhere in this admin (the sidebar always reads "PlusCities" now), and
            gives the second/final stage of content review before a page goes live.</li>
        <li><strong>This is the actual business whose pages these are.</strong>
            <strong>End Client</strong> — the actual business. Gets a separate read-only login
            (<code>client_login.php</code> &rarr; a stripped-down reporting-only portal, never this
            screen) showing GSC clicks and "Tried to Contact" counts for just their own pages.</li>
        <li><strong>This lets Scott or an agency see the product exactly as someone else would see it,
            without ever touching their password.</strong>
            <strong>"Log in as"</strong> (the Clients list's per-row button) — Platform Admin can view the
            product as any agency or any client; Agency Admin can view it as one of its own clients only
            (never another agency's). Neither ever touches the target account's real password. One hop
            only — you can't start a second impersonation while already in one, exit back to yourself
            first — and every start/stop is written to the server error log. Note: the Who's Logged In
            tab tracks the REAL account during impersonation, not whoever's currently being viewed as.</li>
        <li><strong>Scott's own clients aren't a special case — they're just another agency in the
            system.</strong> <strong>Scott's own direct-sale work</strong> runs through "Scott's Agency,"
            a normal agency row like any other — not a separate code path. If you created a client before
            any of this existed, it already belongs to that agency and nothing changed for it.</li>
    </ul>
    <h4 style="margin-bottom:4px;">Content approval — on by default, per-agency toggle</h4>
    <p style="font-size:.88rem;line-height:1.7;margin:0 0 14px;">
        <strong>A page doesn't go live until two different people have signed off on it — unless the
        Platform Admin has turned that off for a specific agency.</strong> Publishing a page doesn't go
        live immediately — it queues on the <strong>Approvals</strong> screen (sidebar, with a pending
        count badge): the Platform Admin approves first, then the owning agency approves, and only then
        does it actually publish. This defaults on for every agency, including the platform's own, but
        it's a real per-agency switch on the <strong>Settings</strong> screen (sidebar) — not a hardcoded
        rule, and never something an agency can set for itself. The Pipeline column on the Clients list
        shows "N awaiting review"/"N rejected" whenever that's actually happening.
    </p>
    <h4 style="margin-bottom:4px;">Billing &amp; access</h4>
    <ul style="margin:0 0 14px;padding-left:20px;line-height:1.8;font-size:.88rem;">
        <li><strong>This is where Scott sets up a new agency and sends them a real bill.</strong> The
            <strong>Agencies</strong> screen (sidebar, Scott-only) creates agencies and generates a
            real Stripe Checkout link to send them — set a Monthly Platform Fee first. Changing that fee
            later updates an already-paying agency's real live price, not just the next link.</li>
        <li><strong>If an agency stops paying, their login is cut off right away, but their clients' pages
            get a 15-day grace period before anything actually goes down.</strong> A failed payment
            suspends the agency's login immediately (Stripe webhook &rarr;
            <code>status: suspended</code>) <em>and</em> starts a 15-day grace period before their client
            sites actually get paused — a daily cron enforces that automatically
            (<code>tools/lpc_check_grace_periods.php</code>), it isn't instant.</li>
        <li><strong>An agency can save its own logo and name, but nothing displays it anywhere right
            now.</strong> Branding fields on its Agencies entry still exist and still save correctly —
            there's just no consumer left to show them; the sidebar brand mark was changed this session
            to always read "PlusCities," which was the only place this was ever displayed.</li>
    </ul>
    <h4 style="margin-bottom:4px;">How it's built</h4>
    <ul style="margin:0 0 14px;padding-left:20px;line-height:1.8;font-size:.88rem;">
        <li><strong>The content-writing engine is the exact same one the other niche sites already
            use.</strong> Content reuses this site factory's own engine — the same AI content pipeline,
            real per-city Census research (population, income, home values, year built, etc.), a real
            NOAA climate data chart per city, and niche-specific content templates the four live niche
            sites (water/mold/pest/appliance) already use.</li>
        <li><strong>A client's pages show up on their own WordPress site, wrapped in their own
            theme.</strong> Each client's pages are stored here (<code>clients/{id}/data/</code>), then
            published to a separate delivery server their WordPress plugin fetches from — a thin,
            stateless proxy that renders inside <strong>their own theme's header/footer</strong>, so it
            inherits their real site's look with no custom CSS to fight against. A small floating badge
            the plugin injects site-wide links back to a public "Service Cities" hub page, grouped
            State &gt; Hub Area &gt; City &gt; Services, with real nearby-city cross-linking between
            pages.</li>
        <li><strong>Each industry has its own template for what a page should cover.</strong> A niche
            profile (Niche Profiles, sidebar) defines content slots and a default service list
            per industry vertical — a new client's wizard offers these as checkboxes, never auto-copied.</li>
    </ul>
    <h4 style="margin-bottom:4px;">How to use it</h4>
    <ol style="margin:0;padding-left:20px;line-height:1.85;font-size:.88rem;">
        <li><strong>This is where you start: entering who the client actually is.</strong>
            <strong>+ New Client</strong> — the wizard: business identity, industry (matches a niche
            profile), services offered, real hub metro(s), target cities per hub.</li>
        <li><strong>The system immediately writes a first draft of every page for you.</strong> Finishing
            the wizard immediately AI-generates a draft page for every city &times; service
            combination (real cost per page) — nothing publishes yet.</li>
        <li><strong>This is the main screen where you manage everything about a client's pages.</strong>
            In the client's workspace: set Content Profile (tone/excluded services) <em>before</em>
            reviewing drafts, then use <strong>Cities &amp; Services</strong> — a city-grouped matrix
            with a checkbox per page and four selection-based actions:
            <ul style="margin:4px 0 0;padding-left:18px;">
                <li><strong>This pulls in real facts about a city.</strong>
                    <strong>Research Selected</strong> — real Census population lookup, free, runs once
                    per unique city among the pages checked.</li>
                <li><strong>This rewrites the selected pages.</strong>
                    <strong>Generate / Refresh Selected</strong> — redraws each checked page one at a time
                    with live progress, with its own checkboxes for what to actually spend on: refresh
                    hero image, re-fetch climate data ($), regenerate text content ($), and skip locked
                    pages.</li>
                <li><strong>This makes the selected pages go live.</strong>
                    <strong>Publish Selected</strong> — makes the checked drafts live.</li>
                <li><strong>This double-checks that what's live matches what it should be.</strong>
                    <strong>Check Live Selected</strong> — fetches each page's real public URL and
                    compares what it's actually serving against what the data says it should be (hero
                    image, area map, climate chart) — the one check that catches a host's CDN still
                    serving a stale cached copy.</li>
            </ul>
        </li>
        <li><strong>The status shown is always honest about real problems, not just whether a page is
            published.</strong> The Status column is honest, not decorative — a page can say Published
            and still carry a &#9888; warning if something expected (hero image, climate data, etc.) is
            actually missing. Lock any page that's final to block it from being touched by Regenerate.</li>
        <li><strong>This is how you hand the client what they need to connect their own site.</strong>
            Once pages are published, go to the Delivery tab — download the plugin zip and copy the API
            key to email the client (built-in compose form).</li>
        <li><strong>Once the client sets it up correctly, you'll see it marked as working.</strong> Once
            they paste the key into their WordPress plugin settings, the status badge on the Clients
            list flips to Connected.</li>
        <li><strong>This automatically counts real visitor interest on a page.</strong> Once live, the
            plugin tracks taps on a page's phone number/CTA buttons automatically (no
            setup) — rolled into the "Tried to Contact" total on the Clients list and the per-page
            Reporting tab. It's an intent signal, not a confirmed call or lead, and is the best evidence
            to show a client/agency the pages are actually doing something.</li>
        <li><strong>This shows real Google search numbers once the client grants access.</strong> On the
            Reporting tab: have the client add our email as a Restricted (read-only) user on their
            real Google Search Console property, paste the property URL in, and real per-page impressions/
            clicks start showing instead of "Not Connected" — nothing on our end until they actually grant
            that access.</li>
    </ol>
    <p style="font-size:.8rem;color:#6b7280;margin:14px 0 0;">Full step-by-step checklist:
        <code>docs/landing-page-clients-onboarding.md</code>.</p>
<?php endif; ?>

<?php if ($tab === 'city_page'): ?>
    <h3 style="margin-top:0;color:#1e3a5f;">What a City Page is, and how it's put together</h3>

    <h4 style="margin-bottom:4px;">1. What it is</h4>
    <p style="font-size:.88rem;line-height:1.7;">
        <strong>A City Page is the one page a client gets for a single city &times; service combination</strong>
        &mdash; e.g. "Water Damage Restoration in Plano, TX." It's not written as one block of text; it's
        assembled from a fixed set of smaller, independent <strong>blocks</strong> (a hero, an FAQ, a cost
        table, a neighborhoods guide, etc.), each one generated, edited, locked, and regenerated on its own.
        Every page in a client's site is built from the same shared pool of block types, so a plumbing
        client and a restoration client both get real, professional pages without either one needing
        custom code.
    </p>
    <p style="font-size:.88rem;line-height:1.7;">
        <strong>The one rule underneath all of it: nothing specific is ever made up.</strong> Any claim a
        block makes that could actually be checked &mdash; a number, a place name, a rule, a quote, a
        statistic &mdash; has to trace back to a real source: a researched fact about that city, a dataset
        (like Census data), or the client's own profile. General, unverifiable statements don't need a
        source. This is the same engine described on the Spec Document tab (Section 4, "Writer contract")
        &mdash; this tab explains it in plain English; that tab has the full technical spec.
    </p>

    <h4 style="margin-bottom:4px;">2. How it works</h4>
    <p style="font-size:.88rem;line-height:1.7;margin:0 0 8px;">
        <strong>Every page is built from three groups of blocks, in a fixed arrangement:</strong>
    </p>
    <ul style="margin:0 0 14px;padding-left:20px;line-height:1.8;font-size:.88rem;">
        <li><strong>The spine &mdash; always the same 4 blocks, never swapped:</strong> Hero, a
            "[Service] in [City]" overview, an FAQ, and a Service Area block. These exist on every page
            because every page needs them, which also means they look the most similar page to page.</li>
        <li><strong>The city pool &mdash; pick 5 or 6:</strong> things that are true about the
            <em>city</em>, not the service &mdash; climate, housing stock, neighborhoods, local permits/
            rules, utilities, HOA rules, the season, local hazards, and real reviews from that city if the
            client has them. Only an actual 5-6 are used per page, chosen based on what real facts/data
            are actually available for that city.</li>
        <li><strong>The service pool &mdash; pick 3 or 4:</strong> things that are true about the
            <em>service</em> &mdash; common problems, expert guidance, a data table, the process, cost
            factors, why-choose-us, repair-vs-replace, maintenance tips, what-to-expect. These repeat
            across a client's different cities for the same service, but no more than half the choices
            repeat &mdash; they get rotated so the same service in two different cities doesn't read
            identically.</li>
    </ul>
    <p style="font-size:.88rem;line-height:1.7;margin:0 0 8px;">
        <strong>Those counts (5-6 and 3-4) are mandatory, not a suggestion</strong> &mdash; what varies is
        <em>which specific blocks</em> get chosen, never how many. That's deliberate: the city- and
        service-pool blocks are actually where most of a page's real, city-specific content lives &mdash;
        the spine is necessarily similar everywhere since it's just structure. All together, a finished
        page is 12-14 blocks and roughly 900-1,500 words.
    </p>
    <p style="font-size:.88rem;line-height:1.7;margin:0 0 8px;">
        <strong>Each block is one of three types, which decides what you're allowed to do to it:</strong>
    </p>
    <ul style="margin:0 0 14px;padding-left:20px;line-height:1.8;font-size:.88rem;">
        <li><strong>Editable (AI-written)</strong> &mdash; can be fully hand-edited, and Regenerate rewrites
            it from scratch using the real facts, in new wording every time (never locked phrasing).</li>
        <li><strong>Automatic (data-built)</strong> &mdash; e.g. the Census housing-stock stats or the
            niche's own pre-authored data table; you can edit its short intro text where it has one, but
            Regenerate re-fetches the real data rather than rewriting prose.</li>
        <li><strong>Client-supplied</strong> &mdash; content the client provided directly (like their own
            reviews); fully editable, but there's nothing to "regenerate" since it was never AI-written.</li>
    </ul>
    <p style="font-size:.88rem;line-height:1.7;margin:0 0 8px;">
        <strong>Locking a block freezes it completely</strong> &mdash; its content, and for a data block its
        underlying data too &mdash; until someone manually unlocks it, so a Regenerate pass can touch the
        rest of the page without disturbing a block that's already finished. Spine blocks can't be swapped
        for anything else (there's no alternative to swap to); city- and service-pool blocks can be swapped
        for a different block from the same pool as long as they're unlocked.
    </p>
    <p style="font-size:.88rem;line-height:1.7;margin:0;">
        <strong>The page itself also carries real SEO and conversion plumbing</strong>: a configurable URL
        pattern per client, schema markup (LocalBusiness/Service/FAQPage/breadcrumbs, plus real star-rating
        schema if the client has real reviews), a hero image plus an interactive service-area map, and two
        call-to-action banners offering both click-to-call and a short quote form. One real difference from
        the written spec worth flagging: the spec (Spec Document tab, Section 5) calls for a sticky
        mobile click-to-call bar, but it was built and then deliberately removed in the actual pages
        &mdash; pages render inside the client's own WordPress theme, and Scott's rule is that a page should
        only ever use whatever header/footer/call-to-action that theme already provides, not compete with
        it. See the Product Roadmap tab's "Visual page design" row for the full reasoning.
    </p>
    <p style="font-size:.8rem;color:#6b7280;margin:14px 0 0 0;">
        Full technical spec (pools, writer contract, lock/edit/regenerate/swap table, page-frame details):
        Spec Document tab, Sections 4-6 of <code>docs/PLUSCITIES_FEATURE_SPEC.md</code>. The visual/
        component-level design (FAQ accordion, CTA band, stat tiles, etc.) lives in
        <code>docs/PLUSCITIES_PAGE_DESIGN_SIMPLE.md</code> (not currently surfaced in this admin).
    </p>

    <h4 style="margin:28px 0 4px;">3. Every block, one row each</h4>
    <p style="font-size:.88rem;line-height:1.7;margin:0 0 8px;">
        All 22 block types (4 spine + 9 city-pool + 9 service-pool), verified directly against the shipped
        code (<code>includes/pluscities_lifecycle.php</code>, <code>pluscities_writer.php</code>,
        <code>pluscities_research.php</code>), not the spec's prose alone &mdash; two places where the two
        actually disagree are called out in their own row's Notes column rather than silently picked one
        way.
    </p>
    <div style="display:flex;gap:14px;flex-wrap:wrap;font-size:.78rem;color:#374151;margin:0 0 10px;">
        <span><span class="lpc-city-block-pill lpc-city-block-editable">Editable</span> AI-written from facts</span>
        <span><span class="lpc-city-block-pill lpc-city-block-automatic">Automatic</span> data-built, no AI</span>
        <span><span class="lpc-city-block-pill lpc-city-block-client">Client-supplied</span> never AI-written</span>
    </div>
    <style>
        .lpc-city-block-pill { display:inline-block; padding:2px 9px; border-radius:999px; font-size:.72rem; font-weight:600; }
        .lpc-city-block-editable  { background:#e0e7ff; color:#3730a3; }
        .lpc-city-block-automatic { background:#d1fae5; color:#065f46; }
        .lpc-city-block-client    { background:#fde7f3; color:#9d174d; }
    </style>
    <?php
    $classLabels = ['editable' => 'Editable', 'automatic' => 'Automatic', 'client' => 'Client-supplied'];
    foreach (platform_info_city_page_blocks_data() as $section): ?>
        <h4 class="lpc-roadmap-section" style="margin:22px 0 8px;font-family:'Space Grotesk',sans-serif;color:#1e3a5f;"><?= h($section['title']) ?></h4>
        <table class="lpc-table" style="margin-bottom:8px;">
            <thead><tr>
                <th style="width:15%;">Block</th>
                <th style="width:10%;">Classification</th>
                <th style="width:22%;">What it is</th>
                <th style="width:28%;">How it works</th>
                <th style="width:25%;">Notes</th>
            </tr></thead>
            <tbody>
            <?php foreach ($section['rows'] as $r): ?>
                <tr>
                    <td><strong><?= h($r['block']) ?></strong></td>
                    <td><span class="lpc-city-block-pill lpc-city-block-<?= h($r['class']) ?>"><?= h($classLabels[$r['class']]) ?></span></td>
                    <td><?= h($r['what']) ?></td>
                    <td><?= h($r['how']) ?></td>
                    <td><?= $r['note'] !== '' ? h($r['note']) : '<span style="color:#9ca3af;">&mdash;</span>' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endforeach; ?>
<?php endif; ?>

<?php if ($tab === 'roadmap'): ?>
    <h3 style="margin-top:0;color:#1e3a5f;">Gap analysis: current code vs. the target feature spec</h3>
    <p style="font-size:.88rem;line-height:1.7;">
        A role &times; capability matrix comparing what's actually built today against a full feature
        and permissions spec Scott supplied (2026-11-20), which defines a 5-role model &mdash;
        <strong>Platform Admin</strong>, <strong>Agency Admin</strong>, <strong>Agency Editor</strong>,
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
                    <td class="lpc-roadmap-detail"><strong><?= h($r['simple']) ?></strong> <?= h($r['detail']) ?></td>
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

<?php if ($tab === 'content_flow'): ?>
    <h3 style="margin-top:0;color:#1e3a5f;">From "niche doesn't exist yet" to live, ranking-ready pages</h3>
    <p style="font-size:.88rem;line-height:1.7;">
        Every step needed to create a niche with real research and real keywords, and turn that into
        live pages built to actually rank &mdash; across <strong>both</strong> products. Verified by
        reading <code>docs/landing-page-build-process-V1-20260709.md</code>,
        <code>docs/niche-brief-and-research.md</code>, <code>docs/lpc-vs-site-factory-boundary.md</code>,
        and the real code they describe (2026-11-21), not carried over from memory.
    </p>
    <p style="font-size:.88rem;line-height:1.7;">
        <strong>These are two separate pipelines, not one shared system.</strong> Site Factory (sections
        B-G) is the real keyword-research-driven build for niche-fleet sites. LPC (section H) is a
        lighter per-client content pipeline with no keyword research of its own. They share exactly one
        thing &mdash; the Niche Library (section A) &mdash; and it's a one-time copy-in, not a live link:
        editing the library later does not retroactively change a niche that already pulled from it.
    </p>
    <p style="font-size:.88rem;line-height:1.7;">
        <strong>Four real additions surfaced 2026-11-21</strong> while reviewing this tab for what it'd
        take to build the best possible city&times;service pages for a niche, each tagged with a
        <strong>Priority</strong> (how directly it protects/improves whether pages actually rank) and
        <strong>Effort</strong>, the same two-axis approach used on the Product Roadmap tab &mdash; just
        labeled for this context instead of "sell to an agency," which doesn't apply here.
    </p>
    <p style="font-size:.88rem;line-height:1.7;">
        <strong>A second round of recommendations, same day, from Scott directly asking how to bring
        LPC's keyword/content rigor up toward Site Factory's</strong> &mdash; captured in section J
        below. Deliberately scoped, not a wholesale copy: niche-viability scoring and tier-driven build
        order were discussed and explicitly <strong>not</strong> recommended for LPC, since neither
        solves a real problem LPC has today &mdash; there's no "which cities should we expand into"
        decision in LPC the way there is in Site Factory (a client is already paying, already has a
        defined footprint), and clients are built one at a time, not as a fleet needing build-order
        prioritization. Same reasoning ruled out a separate blog pipeline and AI model-tiering by task
        for LPC &mdash; neither addresses something LPC is actually missing. The five items that
        <strong>did</strong> make the cut are in section J, same Priority/Effort tagging as everything
        else on this tab. Not yet decided to build &mdash; a design discussion, not committed scope.
    </p>
    <?php
    $cfGateLabels   = ['sell' => 'Core to Ranking', 'retain' => 'Quality Improvement', 'differentiate' => 'Nice to Have'];
    $cfEffortLabels = ['quick' => 'Quick', 'moderate' => 'Moderate', 'major' => 'Major'];
    $cfAllSections  = platform_info_content_flow_data();
    $cfGaps = [];
    $cfTotalSteps = 0;
    foreach ($cfAllSections as $section) {
        foreach ($section['rows'] as $r) {
            $cfTotalSteps++;
            if ($r['status'] === 'needs_adding') $cfGaps[] = $r;
        }
    }
    ?>
    <h4 style="margin:22px 0 8px;font-family:'Space Grotesk',sans-serif;color:#1e3a5f;">Where this stands</h4>
    <p style="font-size:.88rem;line-height:1.7;">
        <?= $cfTotalSteps - count($cfGaps) ?> of <?= $cfTotalSteps ?> steps
        are already real and built &mdash; both pipelines work end to end today, from niche research
        through live, ranking-verified pages. The <?= count($cfGaps) ?> gaps below are specifically what
        it'd take to make the pages this produces the <em>best</em> they can be, not what's needed to make
        the pipeline work at all. None of them require major new infrastructure &mdash; the two Core to
        Ranking items both reuse scoring/checking logic that's already real and built elsewhere in the
        pipeline, just not yet applied at this point.
    </p>
    <table class="lpc-table lpc-wf-table" style="margin-bottom:22px;">
        <thead><tr>
            <th style="width:24%;">Gap</th>
            <th style="width:12%;">Priority</th>
            <th style="width:10%;">Effort</th>
            <th>In one line</th>
        </tr></thead>
        <tbody>
        <?php foreach ($cfGaps as $r): ?>
            <tr>
                <td class="lpc-wf-step"><?= h($r['step']) ?></td>
                <td><span class="lpc-roadmap-gate lpc-roadmap-gate-<?= h($r['gate']) ?>"><?= h($cfGateLabels[$r['gate']]) ?></span></td>
                <td><span class="lpc-roadmap-effort lpc-roadmap-effort-<?= h($r['effort']) ?>"><?= h($cfEffortLabels[$r['effort']]) ?></span></td>
                <td class="lpc-wf-what"><?= h($r['simple']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <div class="lpc-roadmap-filters">
        <span><label for="cf-filter-gate">Priority</label>
            <select id="cf-filter-gate" onchange="lpcContentFlowFilter()">
                <option value="">All</option>
                <?php foreach ($cfGateLabels as $k => $label): ?>
                    <option value="<?= h($k) ?>"><?= h($label) ?></option>
                <?php endforeach; ?>
            </select>
        </span>
        <span><label for="cf-filter-effort">Effort</label>
            <select id="cf-filter-effort" onchange="lpcContentFlowFilter()">
                <option value="">All</option>
                <?php foreach ($cfEffortLabels as $k => $label): ?>
                    <option value="<?= h($k) ?>"><?= h($label) ?></option>
                <?php endforeach; ?>
            </select>
        </span>
        <span><label for="cf-filter-status">Status</label>
            <select id="cf-filter-status" onchange="lpcContentFlowFilter()">
                <option value="">All</option>
                <option value="in_place">In Place</option>
                <option value="needs_adding">Needs to Be Added</option>
            </select>
        </span>
        <span class="lpc-roadmap-count" id="cf-filter-count"></span>
    </div>
    <?php $cfStepN = 0; ?>
    <?php foreach ($cfAllSections as $section): ?>
        <h4 class="lpc-cf-section" style="margin:22px 0 8px;font-family:'Space Grotesk',sans-serif;color:#1e3a5f;"><?= h($section['title']) ?></h4>
        <table class="lpc-table lpc-wf-table lpc-cf-table" style="margin-bottom:8px;">
            <thead><tr>
                <th style="width:3%;">#</th>
                <th style="width:16%;">Step</th>
                <th style="width:8%;">Status</th>
                <th style="width:11%;">Priority</th>
                <th style="width:8%;">Effort</th>
                <th style="width:27%;">What it is</th>
                <th style="width:14%;">Who does it</th>
                <th>Where in the app</th>
            </tr></thead>
            <tbody>
            <?php foreach ($section['rows'] as $r): $cfStepN++; ?>
                <tr data-status="<?= h($r['status']) ?>" data-gate="<?= h($r['gate'] ?? '') ?>" data-effort="<?= h($r['effort'] ?? '') ?>">
                    <td class="lpc-wf-step-n"><?= $cfStepN ?></td>
                    <td class="lpc-wf-step"><?= h($r['step']) ?></td>
                    <td>
                        <span class="lpc-roadmap-status lpc-roadmap-status-<?= $r['status'] === 'in_place' ? 'complete' : 'todo' ?>">
                            <?= $r['status'] === 'in_place' ? 'In Place' : 'Needs to Be Added' ?>
                        </span>
                    </td>
                    <td>
                        <?php if (isset($r['gate'])): ?>
                            <span class="lpc-roadmap-gate lpc-roadmap-gate-<?= h($r['gate']) ?>"><?= h($cfGateLabels[$r['gate']]) ?></span>
                        <?php else: ?>
                            <span class="lpc-roadmap-na">&mdash;</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (isset($r['effort'])): ?>
                            <span class="lpc-roadmap-effort lpc-roadmap-effort-<?= h($r['effort']) ?>"><?= h($cfEffortLabels[$r['effort']]) ?></span>
                        <?php else: ?>
                            <span class="lpc-roadmap-na">&mdash;</span>
                        <?php endif; ?>
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
    function lpcContentFlowFilter() {
        var gate = document.getElementById('cf-filter-gate').value;
        var effort = document.getElementById('cf-filter-effort').value;
        var status = document.getElementById('cf-filter-status').value;
        var tables = document.querySelectorAll('.lpc-cf-table');
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
            if (heading && heading.classList.contains('lpc-cf-section')) {
                heading.style.display = show ? '' : 'none';
            }
        });
        document.getElementById('cf-filter-count').textContent = shown + ' of ' + total + ' steps shown';
    }
    lpcContentFlowFilter();
    </script>
<?php endif; ?>

<?php if ($tab === 'client_approval_stage'): ?>
    <h3 style="margin-top:0;color:#1e3a5f;">Client Approval Stage — design reference, not yet built</h3>
    <p style="font-size:.88rem;line-height:1.7;">
        <strong>This is the plan for a feature that doesn't exist yet: letting the actual client approve
        their own pages before they go live.</strong> A closer look at one specific gap from the Content
        Workflow tab (step 16, "Client review / approval") and the Product Roadmap tab (Workflow section,
        gate: <strong>Must-Have to Sell</strong>, effort: <strong>Major</strong>) — how it would actually
        fit into the existing review pipeline, who'd be involved, and exactly where each piece would live
        in the app. This is a design writeup to work from when it's greenlit, not a status page for
        something already shipped &mdash; nothing described below exists in the code yet.
    </p>

    <h4 style="margin-bottom:4px;">Where this fits in the flow today</h4>
    <p style="font-size:.88rem;line-height:1.7;margin:0 0 14px;">
        <strong>Right now, the client never gets a say before their own pages go live.</strong> Publishing
        a page goes through two stages &mdash; the Platform Admin reviews first
        (<code>pending_platform_review</code>), then the owning agency gives the final sign-off
        (<code>pending_agency_review</code> &rarr; <code>approved</code> &rarr; live), both on
        <code>approvals.php</code> &mdash; unless the Platform Admin has turned this gate off for that
        specific agency (Settings, per-agency, defaults on). Either way, whether the gate ran or not, the
        End Client never participates &mdash; they only ever see a page after it's already live, on their
        own read-only Reporting tab.
    </p>

    <h4 style="margin-bottom:4px;">The proposed design</h4>
    <ul style="margin:0 0 14px;padding-left:20px;line-height:1.85;font-size:.88rem;">
        <li><strong>This adds one more checkpoint, right before a page goes live.</strong> A third,
            optional stage &mdash; <code>pending_client_review</code> &mdash; sitting after agency
            approval and before a page actually goes live.</li>
        <li><strong>Agencies get to choose whether their own clients are involved at all.</strong>
            Per-client toggle, off by default — not every agency wants their client in
            the loop; this is a switch the Platform Admin or Agency Admin flips per client, not a rule
            forced on everyone. It stacks on top of whatever that agency's own approval-gate setting is
            (Settings) rather than assuming the platform + agency review above always ran first: if that
            agency's gate is on, client review (when turned on for a client) is a third stage after agency
            review; if the Platform Admin has turned that agency's gate off, client review would be the
            only check left before the page goes live.</li>
        <li><strong>The client never sees anything still being worked on.</strong> The client sees only
            what's waiting on them &mdash; never a draft still being worked on internally, never anything
            mid-edit.</li>
        <li><strong>The client gets exactly two choices: approve it, or send it back with a note.</strong>
            Two actions, same shape as every other stage: <em>Approve</em> (publishes
            immediately) or <em>Request Changes</em> (sends it back with a note, mirroring the existing
            Reject flow &mdash; the page's own content is untouched, just not live, until resubmitted).</li>
    </ul>

    <h4 style="margin-bottom:4px;">New pieces this would need</h4>
    <ul style="margin:0 0 14px;padding-left:20px;line-height:1.85;font-size:.88rem;">
        <li><strong>Someone needs a simple on/off switch for this, set once per client.</strong> The
            toggle itself &mdash; set once per client by the Platform Admin or Agency Admin.
            Would live on <code>client.php</code>, most likely the Overview tab next to Billing, the same
            place the other per-client settings already live.</li>
        <li><strong>The client needs an actual place to go do their review.</strong> A new End Client
            screen, with its own sidebar tab &mdash; parallel to the
            client's existing Reporting tab, so the client's sidebar would go from one item to two:
            <em>Reporting</em> (what's already there) and <em>Approvals</em> (new), badge-counted the
            same way the Platform Admin/Agency sidebar already badges their own Approvals queue.</li>
        <li><strong>The system needs a safe, locked-down way to record the client's decision.</strong>
            A save handler scoped strictly to that client's own pages &mdash;
            CSRF-protected, restricted to <code>end_client</code> sessions, only able to act on pages
            already sitting at <code>pending_client_review</code> for their own <code>client_id</code>.
            Mirrors <code>approvals_save.php</code>'s existing pattern rather than inventing a new one.</li>
        <li><strong>The system needs a way to mark a page as "waiting on the client" specifically.</strong>
            One new value on the page's own approval_stage field &mdash; today's possible
            values are <code>none / pending_platform_review / pending_agency_review / approved /
            rejected</code>; this adds <code>pending_client_review</code> alongside them.</li>
    </ul>

    <h4 style="margin-bottom:4px;">The one hard constraint</h4>
    <p style="font-size:.88rem;line-height:1.7;margin:0 0 14px;">
        <strong>This screen can never sound like it was made by AI.</strong> It would be the single most
        client-visible screen in the entire product &mdash; the end
        client's own eyes land here directly, not an agency's. Per the positioning rule already flagged
        on the Product Roadmap tab ("never present as an AI product"), its wording has to stay completely
        clear of "AI," "regenerate," "generate," or "prompt." Just <em>Approve</em> and
        <em>Request Changes</em> &mdash; the two verbs a client already understands from ordinary
        business software, nothing that hints at how the page was actually made.
    </p>

    <h4 style="margin-bottom:4px;">Where this sits in prior planning</h4>
    <p style="font-size:.88rem;line-height:1.7;margin:0;">
        <strong>This isn't a brand-new idea &mdash; it's already written down elsewhere, just explained in
        more depth here.</strong> Same item as the Product Roadmap tab's "Client approve / request-changes step (per-client
        toggle)" row and the Content Workflow tab's step 16 ("Client review / approval") &mdash; this
        page is the detailed design for that one entry, not a new addition to scope.
    </p>
<?php endif; ?>

<?php if ($tab === 'spec_document'):
    $specPath = __DIR__ . '/../docs/PLUSCITIES_FEATURE_SPEC.md';
    $specText = is_file($specPath) ? file_get_contents($specPath) : null;
    ?>
    <h3 style="margin-top:0;color:#1e3a5f;">PlusCities consolidated feature spec</h3>
    <p style="font-size:.88rem;line-height:1.7;">
        The full component-by-component specification worked through before any of the rebuild is
        built &mdash; data model, niche library, research &amp; fact cards, writer contract, block
        library, validator, page lifecycle, publishing &amp; plugin sync, roles, billing, white-label,
        and onboarding, plus the phased build order. Source file:
        <code><?= h(str_replace(__DIR__ . '/../', '', $specPath)) ?></code><?php if ($specText !== null): ?>,
        last updated <?= h(date('Y-m-d H:i', filemtime($specPath))) ?><?php endif; ?>.
    </p>
    <?php if ($specText === null): ?>
        <div class="lpc-empty"><h2>Spec file not found</h2>
            <p>Expected it at <code>docs/PLUSCITIES_FEATURE_SPEC.md</code>.</p></div>
    <?php else: ?>
        <div style="background:#fff;border:1px solid var(--pc-line, #e2e8f2);border-radius:14px;
                    padding:24px 28px;font-family:ui-monospace,'SF Mono',Menlo,Consolas,monospace;
                    font-size:.82rem;line-height:1.6;white-space:pre-wrap;word-wrap:break-word;
                    max-height:none;color:#1C2B45;"><?= h($specText) ?></div>
    <?php endif; ?>
<?php endif; ?>

<?php if ($tab === 'claude'):
    $claudeDocs = [
        ['path' => __DIR__ . '/../docs/pluscities-system-reference.md',
         'heading' => 'PlusCities — System Reference (as-built)',
         'intro' => 'As-built ground truth for the current PlusCities system — data model, business logic, functional scope, and user roles/menu capability — verified directly against code, the live DB, and real client files. Written for a fresh Claude session to read first.'],
    ];
    ?>
    <h3 style="margin-top:0;color:#1e3a5f;">Documents for Claude</h3>
    <p style="font-size:.88rem;line-height:1.7;">
        Reference material written specifically so a fresh Claude session can get oriented on PlusCities
        quickly, without re-deriving it from scratch each time. Scott's plan: this tab also gets a set of
        long, real use-case walkthroughs (verifying the claims below against the live system) once those
        are written.
    </p>
    <?php foreach ($claudeDocs as $doc):
        $docText = is_file($doc['path']) ? file_get_contents($doc['path']) : null;
    ?>
        <h4 style="color:#1e3a5f;margin:28px 0 4px;"><?= h($doc['heading']) ?></h4>
        <p style="font-size:.88rem;line-height:1.7;">
            <?= h($doc['intro']) ?> Source file:
            <code><?= h(str_replace(__DIR__ . '/../', '', $doc['path'])) ?></code><?php if ($docText !== null): ?>,
            last updated <?= h(date('Y-m-d H:i', filemtime($doc['path']))) ?><?php endif; ?>.
        </p>
        <?php if ($docText === null): ?>
            <div class="lpc-empty"><h2>File not found</h2>
                <p>Expected it at <code><?= h(str_replace(__DIR__ . '/../', '', $doc['path'])) ?></code>.</p></div>
        <?php else: ?>
            <div style="background:#fff;border:1px solid var(--pc-line, #e2e8f2);border-radius:14px;
                        padding:24px 28px;font-family:ui-monospace,'SF Mono',Menlo,Consolas,monospace;
                        font-size:.82rem;line-height:1.6;white-space:pre-wrap;word-wrap:break-word;
                        max-height:none;color:#1C2B45;"><?= h($docText) ?></div>
        <?php endif; ?>
    <?php endforeach; ?>
<?php endif; ?>

<?php if ($tab === 'use_cases'):
    $useCaseDocs = [
        ['path' => __DIR__ . '/../docs/pluscities-use-case-tests.md',
         'heading' => 'PlusCities — Use Case Test Suite',
         'intro' => 'Long, real walkthroughs run against the live system to verify the system reference doc\'s claims, not just read them.'],
        ['path' => __DIR__ . '/../docs/pluscities-test-coverage-audit.md',
         'heading' => 'PlusCities — Test Coverage Audit',
         'intro' => 'What percentage of the real system UC1-8 actually exercise, mapped capability by capability — and which use cases to run next to close the biggest gaps.'],
    ];
    ?>
    <h3 style="margin-top:0;color:#1e3a5f;">Use Case Test Suite</h3>
    <p style="font-size:.88rem;line-height:1.7;">
        Same idea as the Claude tab, one step further: instead of describing what the system should do,
        each use case is a real end-to-end run through the live admin, with the actual outcome recorded.
    </p>
    <?php foreach ($useCaseDocs as $doc):
        $docText = is_file($doc['path']) ? file_get_contents($doc['path']) : null;
    ?>
        <h4 style="color:#1e3a5f;margin:28px 0 4px;"><?= h($doc['heading']) ?></h4>
        <p style="font-size:.88rem;line-height:1.7;">
            <?= h($doc['intro']) ?> Source file:
            <code><?= h(str_replace(__DIR__ . '/../', '', $doc['path'])) ?></code><?php if ($docText !== null): ?>,
            last updated <?= h(date('Y-m-d H:i', filemtime($doc['path']))) ?><?php endif; ?>.
        </p>
        <?php if ($docText === null): ?>
            <div class="lpc-empty"><h2>Not written yet</h2>
                <p>Expected at <code><?= h(str_replace(__DIR__ . '/../', '', $doc['path'])) ?></code> once the use cases are scoped and run.</p></div>
        <?php else: ?>
            <div style="background:#fff;border:1px solid var(--pc-line, #e2e8f2);border-radius:14px;
                        padding:24px 28px;font-family:ui-monospace,'SF Mono',Menlo,Consolas,monospace;
                        font-size:.82rem;line-height:1.6;white-space:pre-wrap;word-wrap:break-word;
                        max-height:none;color:#1C2B45;"><?= h($docText) ?></div>
        <?php endif; ?>
    <?php endforeach; ?>
<?php endif; ?>

<?php lpc_admin_close(); ?>
