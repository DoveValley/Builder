<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/lpc_nav.php';

if (!lpc_actor_is_authenticated()) {
    header('Location: login.php');
    exit;
}
// An End Client gets read-only reporting only (client_portal.php) — real
// gap found live: scoping which CLIENT they can touch is not the same as
// restricting which PAGES they can reach; this full editing admin list was
// reachable (and so was every other full-admin page) by anyone logged in
// at all, regardless of role, until this check existed.
if (lpc_current_actor_role() === 'end_client') {
    header('Location: client_portal.php');
    exit;
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

$alert = null;
if (!empty($_GET['msg'])) {
    $raw = $_GET['msg'];
    if (strpos($raw, ':') !== false) {
        [$type, $text] = explode(':', $raw, 2);
        if (in_array($type, ['success', 'error', 'warning'], true)) {
            $alert = ['type' => $type, 'text' => $text];
        }
    }
}

$rows = [];
$totals = ['clients' => 0, 'pages_live' => 0, 'mrr' => 0.0, 'pending_review' => 0, 'tried_to_contact' => 0];

foreach (lpc_list_clients_for_current_actor() as $client_id) {
    $client = lpc_load_client($client_id);
    if (!$client) continue;

    $pagesLive = 0;
    $pagesTotal = 0;
    // Pipeline stage, derived from signals that already exist on each page
    // (status + approval_stage) rather than any new stored state — same
    // "rollup, don't invent" approach the component map called for.
    $pagesPendingReview = 0;
    $pagesRejected      = 0;
    foreach (lpc_list_pages($client_id) as $page_id) {
        $page = lpc_load_page($client_id, $page_id);
        if (!$page) continue;
        $pagesTotal++;
        if ($page['status'] === 'published') $pagesLive++;
        if (in_array($page['approval_stage'], ['pending_platform_review', 'pending_agency_review'], true)) $pagesPendingReview++;
        if ($page['approval_stage'] === 'rejected') $pagesRejected++;
    }

    $locations = count($client['target_cities'] ?? []);
    $mrr = $locations * (float) ($client['billing']['monthly_fee_per_location'] ?? 0);

    // One remote fetch per client (same 3s-timeout-fail-open call the
    // Reporting tab already uses) — fine at today's real scale (a handful
    // of clients); if this ever grows into the hundreds, this loop is the
    // first thing that should move to a cached/batched rollup instead of
    // one request per client per page load.
    $triedToContact = array_sum(array_map('array_sum', lpc_fetch_click_summary($client_id)));

    $rows[] = [
        'id'               => $client_id,
        'name'             => $client['identity']['business_name'] ?: $client_id,
        'domain'           => $client['identity']['domain'],
        'pages_live'       => $pagesLive,
        'pages_total'      => $pagesTotal,
        'pending_review'   => $pagesPendingReview,
        'rejected'         => $pagesRejected,
        'status'           => lpc_plugin_status($client_id),
        'mrr'              => $mrr,
        'tried_to_contact' => $triedToContact,
    ];

    $totals['clients']++;
    $totals['pages_live'] += $pagesLive;
    $totals['mrr'] += $mrr;
    $totals['pending_review'] += $pagesPendingReview;
    $totals['tried_to_contact'] += $triedToContact;
}

usort($rows, fn($a, $b) => strcasecmp($a['name'], $b['name']));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Landing Page Clients — <?= h(SITE_TITLE) ?></title>
<link rel="stylesheet" href="../assets/css/style.css">
<?php $_adminCssMtime = css_minify_to(__DIR__ . '/../assets/css/admin.src.css', __DIR__ . '/../assets/css/admin.css'); ?>
<link rel="stylesheet" href="../assets/css/admin.css?v=<?= (int) $_adminCssMtime ?>">
<style>
.lpc-wrap        { max-width: 1100px; margin: 0 auto; padding: 32px 24px 64px; }
.lpc-topbar      { display:flex; align-items:center; justify-content:space-between; margin-bottom:24px; flex-wrap:wrap; gap:12px; }
.lpc-topbar h1   { font-size:1.4rem; font-weight:700; margin:0; }
.lpc-totals      { display:flex; gap:28px; background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:16px 22px; margin-bottom:24px; flex-wrap:wrap; }
.lpc-totals div strong { display:block; font-size:1.3rem; color:#111; }
.lpc-totals div span   { font-size:.78rem; color:#6b7280; }
.lpc-table       { width:100%; border-collapse:collapse; background:#fff; border:1px solid #e5e7eb; border-radius:10px; overflow:hidden; }
.lpc-table th    { text-align:left; font-size:.74rem; text-transform:uppercase; letter-spacing:.03em; color:#6b7280; padding:10px 16px; background:#f9fafb; border-bottom:1px solid #e5e7eb; }
.lpc-table td    { padding:12px 16px; border-bottom:1px solid #f1f5f9; font-size:.88rem; }
.lpc-table tr:last-child td { border-bottom:none; }
.lpc-table a.lpc-name { font-weight:600; color:#111; text-decoration:none; }
.lpc-table a.lpc-name:hover { text-decoration:underline; }
.lpc-badge       { font-size:.72rem; font-weight:700; padding:2px 9px; border-radius:99px; display:inline-block; }
.lpc-badge-connected { background:#dcfce7; color:#166534; }
.lpc-badge-stale      { background:#fef9c3; color:#92400e; }
.lpc-badge-never      { background:#f3f4f6; color:#6b7280; }
.lpc-empty       { text-align:center; padding:64px 24px; background:#fff; border:2px dashed #e5e7eb; border-radius:12px; }
.lpc-empty h2    { font-size:1.3rem; margin:0 0 8px; color:#111; }
.lpc-empty p     { color:#6b7280; margin:0 0 24px; }
</style>
</head>
<body class="admin-body">
<div style="background:#1e293b; color:#fff; padding:14px 24px; display:flex; align-items:center; justify-content:space-between;">
    <?php lpc_render_actor_branding(); ?>
    <div style="display:flex; align-items:center; gap:16px;">
        <?php if (lpc_current_actor_role() === 'agency_admin'): ?>
        <a href="agency_branding.php" style="color:#94a3b8; font-size:.82rem; text-decoration:none;">My Branding</a>
        <?php endif; ?>
        <?php if (lpc_current_actor_role() === 'super_admin'): ?>
        <a href="sites.php" style="color:#94a3b8; font-size:.82rem; text-decoration:none;">&larr; Sites</a>
        <?php endif; ?>
        <a href="logout.php" style="color:#94a3b8; font-size:.82rem; text-decoration:none;">Log out</a>
    </div>
</div>
<?php lpc_render_impersonation_banner(); ?>

<div class="lpc-wrap">

    <?php lpc_render_nav('clients'); ?>

    <?php if ($alert): ?>
        <div class="alert alert-<?= h($alert['type']) ?>"><?= h($alert['text']) ?></div>
    <?php endif; ?>

    <div class="lpc-topbar">
        <h1>Landing Page Clients</h1>
        <div style="display:flex;gap:8px;">
            <button class="btn-sm-outline" onclick="toggleLpcHowItWorks()">How This Works &#9432;</button>
            <?php
            $_approvalStage = lpc_current_actor_role() === 'super_admin' ? 'pending_platform_review' : 'pending_agency_review';
            $_pendingCount  = count(lpc_list_pages_by_approval_stage($_approvalStage, lpc_list_clients_for_current_actor()));
            ?>
            <a href="approvals.php" class="btn-sm-outline">Approvals<?= $_pendingCount ? ' (' . (int) $_pendingCount . ')' : '' ?></a>
            <?php if (lpc_current_actor_role() === 'super_admin'): ?>
            <a href="agencies.php" class="btn-sm-outline">Agencies</a>
            <a href="niche_profiles.php" class="btn-sm-outline">Niche Profiles</a>
            <a href="lpc_demo_sites.php" class="btn-sm-outline">Demo Sites</a>
            <a href="wp_builder.php" class="btn-sm-outline">WP Builder</a>
            <?php endif; ?>
            <a href="client_wizard.php?restart=1" class="btn-open">+ New Client</a>
        </div>
    </div>

    <div id="lpc-how-it-works-card" class="card" style="display:none;background:#f8fafc;border-left:3px solid #7c3aed;margin-bottom:18px;">
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
                the clients it created, pays its own platform bill via Stripe, runs onboarding, and gives the
                second/final stage of content review before a page goes live.</li>
            <li><strong>End Client</strong> — the actual business. Gets a separate read-only login
                (<code>client_login.php</code> &rarr; a stripped-down reporting-only portal, never this
                screen) showing GSC clicks and "Tried to Contact" counts for just their own pages.</li>
            <li><strong>Scott's own direct-sale work</strong> runs through "Scott's Agency," a normal agency
                row like any other — not a separate code path. If you created a client before any of this
                existed, it already belongs to that agency and nothing changed for it.</li>
        </ul>
        <h4 style="margin-bottom:4px;">Content approval — real agencies only</h4>
        <p style="font-size:.88rem;line-height:1.7;margin:0 0 14px;">
            Publishing a page for a REAL agency's client doesn't go live immediately — it queues on the
            <strong>Approvals</strong> screen (top bar, with a pending count badge): Scott approves first, then
            that agency approves, and only then does it actually publish. This never applies to Scott's own
            agency's clients — publishing there works exactly as it always has, no gate, since the whole point
            is quality control over what an outside agency ships, not friction on direct work. The Pipeline
            column on this list shows "N awaiting review"/"N rejected" whenever that's actually happening.
        </p>
        <h4 style="margin-bottom:4px;">Billing &amp; access</h4>
        <ul style="margin:0 0 14px;padding-left:20px;line-height:1.8;font-size:.88rem;">
            <li>The <strong>Agencies</strong> screen (top bar, Scott-only) creates agencies and generates a
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
            <li>A niche profile (Niche Profiles button, above) defines content slots and a default service list
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
            <li>Once they paste the key into their WordPress plugin settings, the status badge on this list
                flips to Connected.</li>
            <li>Once live, the plugin tracks taps on a page's phone number/CTA buttons automatically (no
                setup) — rolled into the "Tried to Contact" total here and the per-page Reporting tab. It's an
                intent signal, not a confirmed call or lead, and is the best evidence to show a client/agency
                the pages are actually doing something.</li>
            <li>On the Reporting tab: have the client add our email as a Restricted (read-only) user on their
                real Google Search Console property, paste the property URL in, and real per-page impressions/
                clicks start showing instead of "Not Connected" — nothing on our end until they actually grant
                that access.</li>
        </ol>
        <p style="font-size:.8rem;color:#6b7280;margin:14px 0 0;">Full step-by-step checklist:
            <code>docs/landing-page-clients-onboarding.md</code>.</p>
    </div>

    <?php if (empty($rows)): ?>
        <div class="lpc-empty">
            <h2>No clients yet</h2>
            <p>Create your first Landing Page Client to get started.</p>
        </div>
    <?php else: ?>

        <div class="lpc-totals">
            <div><strong><?= (int) $totals['clients'] ?></strong><span>Active Clients</span></div>
            <div><strong><?= (int) $totals['pages_live'] ?></strong><span>Pages Live</span></div>
            <?php if ($totals['pending_review'] > 0): ?>
            <div><strong style="color:#92400e;"><?= (int) $totals['pending_review'] ?></strong><span>Awaiting Review</span></div>
            <?php endif; ?>
            <div><strong><?= (int) $totals['tried_to_contact'] ?></strong><span title="Tap-to-call/CTA clicks across every page, last 30 days — an intent signal, not confirmed calls or leads.">Tried to Contact (30d)</span></div>
            <div><strong>$<?= number_format($totals['mrr'], 0) ?>/mo</strong><span>Total MRR</span></div>
        </div>

        <table class="lpc-table">
            <tr>
                <th>Client</th>
                <th>Domain</th>
                <th>Pipeline</th>
                <th title="Tap-to-call/CTA clicks, last 30 days — an intent signal, not confirmed calls or leads.">Tried to Contact</th>
                <th>Plugin Status</th>
                <th>MRR</th>
                <th></th>
            </tr>
            <?php foreach ($rows as $r): ?>
            <tr>
                <td><a class="lpc-name" href="client.php?id=<?= h($r['id']) ?>"><?= h($r['name']) ?></a></td>
                <td><?= h($r['domain']) ?: '—' ?></td>
                <td>
                    <?= (int) $r['pages_live'] ?> / <?= (int) $r['pages_total'] ?> live
                    <?php if ($r['pending_review'] > 0): ?>
                        <br><span style="color:#92400e;font-size:.76rem;"><?= (int) $r['pending_review'] ?> awaiting review</span>
                    <?php endif; ?>
                    <?php if ($r['rejected'] > 0): ?>
                        <br><span style="color:#991b1b;font-size:.76rem;"><?= (int) $r['rejected'] ?> rejected</span>
                    <?php endif; ?>
                </td>
                <td><?= $r['tried_to_contact'] > 0 ? (int) $r['tried_to_contact'] : '—' ?></td>
                <td>
                    <?php
                    $badgeClass = $r['status'] === 'Connected' ? 'connected' : ($r['status'] === 'Stale' ? 'stale' : 'never');
                    ?>
                    <span class="lpc-badge lpc-badge-<?= $badgeClass ?>"><?= h($r['status']) ?></span>
                </td>
                <td>$<?= number_format($r['mrr'], 0) ?>/mo</td>
                <td>
                    <form method="post" action="impersonate.php" style="margin:0;">
                        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                        <input type="hidden" name="action" value="start">
                        <input type="hidden" name="type" value="client">
                        <input type="hidden" name="client_id" value="<?= h($r['id']) ?>">
                        <button type="submit" class="btn-sm-outline">Log in as</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>

    <?php endif; ?>

</div>
<script>
function toggleLpcHowItWorks() {
    const el = document.getElementById('lpc-how-it-works-card');
    el.style.display = (el.style.display === 'none') ? '' : 'none';
}
</script>
</body>
</html>
