<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

if (empty($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
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
$totals = ['clients' => 0, 'pages_live' => 0, 'mrr' => 0.0];

foreach (lpc_list_clients() as $client_id) {
    $client = lpc_load_client($client_id);
    if (!$client) continue;

    $pagesLive = 0;
    $pagesTotal = 0;
    foreach (lpc_list_pages($client_id) as $page_id) {
        $page = lpc_load_page($client_id, $page_id);
        if (!$page) continue;
        $pagesTotal++;
        if ($page['status'] === 'published') $pagesLive++;
    }

    $locations = count($client['target_cities'] ?? []);
    $mrr = $locations * (float) ($client['billing']['monthly_fee_per_location'] ?? 0);

    $rows[] = [
        'id'         => $client_id,
        'name'       => $client['identity']['business_name'] ?: $client_id,
        'domain'     => $client['identity']['domain'],
        'pages_live' => $pagesLive,
        'pages_total'=> $pagesTotal,
        'status'     => lpc_plugin_status($client_id),
        'mrr'        => $mrr,
    ];

    $totals['clients']++;
    $totals['pages_live'] += $pagesLive;
    $totals['mrr'] += $mrr;
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
    <div style="font-weight:700; font-size:1rem; letter-spacing:.01em;">&#9776; Site Factory</div>
    <div style="display:flex; align-items:center; gap:16px;">
        <a href="sites.php" style="color:#94a3b8; font-size:.82rem; text-decoration:none;">&larr; Sites</a>
        <a href="logout.php" style="color:#94a3b8; font-size:.82rem; text-decoration:none;">Log out</a>
    </div>
</div>

<div class="lpc-wrap">

    <?php if ($alert): ?>
        <div class="alert alert-<?= h($alert['type']) ?>"><?= h($alert['text']) ?></div>
    <?php endif; ?>

    <div class="lpc-topbar">
        <h1>Landing Page Clients</h1>
        <a href="client_wizard.php?restart=1" class="btn-open">+ New Client</a>
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
            <div><strong>$<?= number_format($totals['mrr'], 0) ?>/mo</strong><span>Total MRR</span></div>
        </div>

        <table class="lpc-table">
            <tr>
                <th>Client</th>
                <th>Domain</th>
                <th>Pages Live</th>
                <th>Plugin Status</th>
                <th>MRR</th>
            </tr>
            <?php foreach ($rows as $r): ?>
            <tr>
                <td><a class="lpc-name" href="client.php?id=<?= h($r['id']) ?>"><?= h($r['name']) ?></a></td>
                <td><?= h($r['domain']) ?: '—' ?></td>
                <td><?= (int) $r['pages_live'] ?> / <?= (int) $r['pages_total'] ?></td>
                <td>
                    <?php
                    $badgeClass = $r['status'] === 'Connected' ? 'connected' : ($r['status'] === 'Stale' ? 'stale' : 'never');
                    ?>
                    <span class="lpc-badge lpc-badge-<?= $badgeClass ?>"><?= h($r['status']) ?></span>
                </td>
                <td>$<?= number_format($r['mrr'], 0) ?>/mo</td>
            </tr>
            <?php endforeach; ?>
        </table>

    <?php endif; ?>

</div>
</body>
</html>
