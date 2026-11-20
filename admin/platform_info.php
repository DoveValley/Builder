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
    'who_logged_in' => "Who's Logged In",
    'how_it_works'  => 'How This Works',
];
$tab = $_GET['tab'] ?? 'who_logged_in';
if (!isset($TABS[$tab])) $tab = 'who_logged_in';

function platform_info_fmt_duration(int $seconds): string {
    if ($seconds < 60) return $seconds . 's';
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    return $h > 0 ? sprintf('%dh %dm', $h, $m) : sprintf('%dm', $m);
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

<?php lpc_admin_close(); ?>
