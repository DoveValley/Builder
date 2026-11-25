<?php
/**
 * admin/pluscities_dashboard.php — the agency-facing home screen. Replaces
 * landing an agency on the old clients.php list: a simple, real "what needs
 * my attention" view instead of one more flat list to scan. Platform-only
 * tooling (Niche Library, Demo Sites, etc.) stays admin-only, unchanged —
 * this is a business-facing capability, not platform tooling.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/lpc_admin_layout.php';

if (!lpc_actor_is_authenticated() || !in_array(lpc_current_actor_role(), ['agency_admin', 'agency_editor'], true)) {
    header('Location: pluscities_clients.php');
    exit;
}

// Scoped correctly from the start: pluscities_client_ids_for_current_actor()
// returns every client in the agency, but an Agency Editor with a real
// assigned_client_ids list should only see those — filter each one through
// pluscities_actor_can_access_client(), the same per-client check the write
// paths already use, rather than repeating the read-scope gap UC5 found on
// pluscities_clients.php/pluscities_pages.php.
$allClients = pluscities_client_ids_for_current_actor();
$clients = [];
foreach ($allClients as $cid => $c) {
    if (pluscities_actor_can_access_client($cid)) $clients[$cid] = $c;
}

$db = pluscities_db();
$liveCount = 0;
$cityIds = [];
$needsAttention = [];

if ($clients) {
    $placeholders = implode(',', array_fill(0, count($clients), '?'));
    $st = $db->prepare("SELECT * FROM pages WHERE client_id IN ($placeholders)");
    $st->execute(array_keys($clients));
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) {
        if ($p['status'] === 'live') $liveCount++;
        $cityIds[$p['city_id']] = true;

        $validation = json_decode($p['validation_json'] ?: '{}', true) ?: [];
        $validationCurrent = ($validation['hash_checked_against'] ?? null) === $p['draft_hash'];
        $needsReview = $p['approval_stage'] !== 'approved' || !$validationCurrent;

        if ($needsReview) {
            $businessName = $clients[$p['client_id']]['identity']['business_name'] ?? $p['client_id'];
            $reason = !$validationCurrent ? 'Validation is stale' : ($p['approval_stage'] === 'none' ? 'Not yet approved' : 'Awaiting review');
            $needsAttention[] = [
                'page_id' => $p['page_id'], 'label' => $businessName . ' — ' . $p['city_id'] . ' / ' . $p['service_key'],
                'reason' => $reason,
            ];
        }
    }
}

$agency = lpc_load_agency(lpc_current_actor_agency_id());
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard — PlusCities</title>
<link rel="stylesheet" href="../assets/css/style.css">
<?php $_adminCssMtime = css_minify_to(__DIR__ . '/../assets/css/admin.src.css', __DIR__ . '/../assets/css/admin.css'); ?>
<link rel="stylesheet" href="../assets/css/admin.css?v=<?= (int) $_adminCssMtime ?>">
<?php lpc_admin_head_assets(); ?>
</head>
<?php
lpc_admin_open('dashboard', 'Dashboard');
?>
<style>
.pc-card{background:#fff;border:1px solid var(--pc-line,#e2e8f2);border-radius:14px;padding:18px 20px;margin-bottom:16px;}
.pc-stat-row{display:flex;gap:16px;flex-wrap:wrap;}
.pc-stat-tile{flex:1;min-width:160px;background:#fff;border:1px solid var(--pc-line,#e2e8f2);border-radius:14px;padding:18px 20px;}
.pc-stat-tile .num{font-size:2rem;font-weight:800;color:var(--pc-navy,#0B1B33);line-height:1;}
.pc-stat-tile .label{font-size:.8rem;color:var(--pc-muted,#52607a);margin-top:4px;}
.pc-row{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:10px 0;border-bottom:1px solid var(--pc-line,#e2e8f2);}
.pc-row:last-child{border-bottom:none;}
</style>

<div class="pc-stat-row" style="margin-bottom:16px;">
    <div class="pc-stat-tile"><div class="num"><?= count($clients) ?></div><div class="label">Clients</div></div>
    <div class="pc-stat-tile"><div class="num"><?= count($cityIds) ?></div><div class="label">Cities</div></div>
    <div class="pc-stat-tile"><div class="num"><?= $liveCount ?></div><div class="label">Live pages</div></div>
    <div class="pc-stat-tile"><div class="num"><?= count($needsAttention) ?></div><div class="label">Needs attention</div></div>
</div>

<div class="pc-card">
    <h3 style="margin-top:0;">Needs attention</h3>
    <?php if (!$needsAttention): ?>
        <p style="color:var(--pc-muted,#52607a);font-size:.84rem;">Nothing needs review right now.</p>
    <?php else: foreach ($needsAttention as $n): ?>
        <div class="pc-row">
            <a href="pluscities_page.php?page_id=<?= h($n['page_id']) ?>"><?= h($n['label']) ?></a>
            <span style="font-size:.8rem;color:#92400e;"><?= h($n['reason']) ?></span>
        </div>
    <?php endforeach; endif; ?>
</div>

<div class="pc-card">
    <h3 style="margin-top:0;"><?= h($agency['name'] ?? 'Your Agency') ?></h3>
    <div style="display:flex;gap:10px;">
        <a href="pluscities_clients.php" class="btn-open">+ New Client</a>
        <a href="pluscities_pages.php" class="btn-sm-outline">All Pages</a>
    </div>
</div>

<?php lpc_admin_close(); ?>
