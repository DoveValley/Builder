<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/lpc_nav.php';
require_once __DIR__ . '/lpc_admin_layout.php';

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
<?php lpc_admin_head_assets(); ?>
</head>
<?php
lpc_admin_open('clients', 'Landing Page Clients',
    '<a href="client_wizard.php?restart=1" class="pc-btn pc-btn-lime">+ New Client</a>'
);
?>

    <?php if ($alert): ?>
        <div class="alert alert-<?= h($alert['type']) ?>"><?= h($alert['text']) ?></div>
    <?php endif; ?>

    <?php if (empty($rows)): ?>
        <div class="lpc-empty">
            <h2>No clients yet</h2>
            <p>Create your first Landing Page Client to get started.</p>
        </div>
    <?php else: ?>

        <div class="pcadmin-stats">
            <div class="pcadmin-stat"><div class="num"><?= (int) $totals['clients'] ?></div><div class="label">Active Clients</div></div>
            <div class="pcadmin-stat"><div class="num"><?= (int) $totals['pages_live'] ?></div><div class="label">Pages Live</div></div>
            <?php if ($totals['pending_review'] > 0): ?>
            <div class="pcadmin-stat"><div class="num warn"><?= (int) $totals['pending_review'] ?></div><div class="label">Awaiting Review</div></div>
            <?php endif; ?>
            <div class="pcadmin-stat"><div class="num"><?= (int) $totals['tried_to_contact'] ?></div><div class="label" title="Tap-to-call/CTA clicks across every page, last 30 days — an intent signal, not confirmed calls or leads.">Tried to Contact (30d)</div></div>
            <div class="pcadmin-stat"><div class="num">$<?= number_format($totals['mrr'], 0) ?>/mo</div><div class="label">Total MRR</div></div>
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
                        <button type="submit" class="pc-btn pc-btn-outline-navy" style="padding:5px 12px;font-size:.78rem;">Log in as</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>

    <?php endif; ?>
<?php lpc_admin_close(); ?>
