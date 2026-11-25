<?php
/**
 * admin/pluscities_pages.php — the real table-of-pages UI from
 * docs/PLUSCITIES_FEATURE_SPEC.md Section 11: one row per page, checkbox
 * bulk-select, and a stage-dot matrix (Researched/Written/Validated/
 * Approved/Published/Live) instead of one collapsed status — modeled
 * directly on the fleet's own Go-Live grid (admin/rollout.php).
 *
 * Validated is the one dot with real sub-detail (up to 12 checks behind it,
 * spec Section 7) — clicking it expands a popover with the per-check
 * breakdown, same behavior Section 11 already specifies. The rest are plain
 * dots, nothing to click through.
 *
 * Row click drills into admin/pluscities_page.php's block-level detail view.
 * Gated the same as that screen: any authenticated actor except end_client,
 * since Agency Admin/Editor both need to work this screen day to day.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/lpc_admin_layout.php';

if (!lpc_actor_is_authenticated() || lpc_current_actor_role() === 'end_client') {
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
        if (in_array($type, ['success', 'error', 'warning'], true)) $alert = ['type' => $type, 'text' => $text];
    }
}

// pluscities_client_ids_for_current_actor() lives in
// includes/pluscities_lifecycle.php — shared with pluscities_pages_save.php
// so the scoping rule exists exactly once. Further filtered through
// pluscities_actor_can_access_client(), fixed 2026-11-24 — an Agency Editor
// with a real assigned_client_ids list was seeing every page in the agency
// here, not just their assigned clients'; the SQL below only ever queries
// $clients' keys, so narrowing this array also narrows the query itself,
// not just what's displayed afterward.
$allClients = pluscities_client_ids_for_current_actor();
$clients = [];
foreach ($allClients as $cid => $c) {
    if (pluscities_actor_can_access_client($cid)) $clients[$cid] = $c;
}
$db = pluscities_db();

$rows = [];
if ($clients) {
    $placeholders = implode(',', array_fill(0, count($clients), '?'));
    $st = $db->prepare("SELECT * FROM pages WHERE client_id IN ($placeholders) ORDER BY page_id");
    $st->execute(array_keys($clients));
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $page) {
        $client = $clients[$page['client_id']];

        $blockCountStmt = $db->prepare('SELECT COUNT(*) FROM blocks WHERE page_id = ?');
        $blockCountStmt->execute([$page['page_id']]);
        $written = (int) $blockCountStmt->fetchColumn() > 0;

        $factStmt = $db->prepare('SELECT COUNT(*) FROM fact_cards WHERE scope = "city" AND scope_key = ? AND status = "active"');
        $factStmt->execute([$page['city_id']]);
        $datasetStmt = $db->prepare('SELECT COUNT(*) FROM datasets WHERE city_id = ?');
        $datasetStmt->execute([$page['city_id']]);
        $researched = (int) $factStmt->fetchColumn() > 0 || (int) $datasetStmt->fetchColumn() > 0;

        $validation = json_decode($page['validation_json'] ?: '[]', true) ?: [];
        $validationCurrent = ($validation['hash_checked_against'] ?? null) === $page['draft_hash'];
        $validated = $validationCurrent && pluscities_validation_all_pass($validation);

        $rows[] = [
            'page' => $page,
            'client' => $client,
            'dots' => [
                'researched' => $researched,
                'written'    => $written,
                'validated'  => $validated,
                'approved'   => $page['approval_stage'] === 'approved',
                'published'  => $page['live_revision_id'] !== null,
                'live'       => $page['status'] === 'live',
                'unpublished' => $page['status'] === 'unpublished',
            ],
            'validation' => $validation,
            'validation_current' => $validationCurrent,
        ];
    }
}

// Filter dropdowns added 2026-11-24 — options built from the full unfiltered
// $rows before narrowing, so the dropdowns always offer every city/service
// actually present for this actor, not just what's currently shown.
$cityOptions = [];
$serviceOptions = [];
foreach ($rows as $r) {
    $cityOptions[$r['page']['city_id']] = true;
    $serviceOptions[$r['page']['service_key']] = true;
}
ksort($cityOptions);
ksort($serviceOptions);

$filterCity = trim((string) ($_GET['city'] ?? ''));
$filterService = trim((string) ($_GET['service'] ?? ''));
if ($filterCity !== '' || $filterService !== '') {
    $rows = array_values(array_filter($rows, function ($r) use ($filterCity, $filterService) {
        if ($filterCity !== '' && $r['page']['city_id'] !== $filterCity) return false;
        if ($filterService !== '' && $r['page']['service_key'] !== $filterService) return false;
        return true;
    }));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>PlusCities Pages — PlusCities</title>
<link rel="stylesheet" href="../assets/css/style.css">
<?php $_adminCssMtime = css_minify_to(__DIR__ . '/../assets/css/admin.src.css', __DIR__ . '/../assets/css/admin.css'); ?>
<link rel="stylesheet" href="../assets/css/admin.css?v=<?= (int) $_adminCssMtime ?>">
<?php lpc_admin_head_assets(); ?>
</head>
<?php
lpc_admin_open('pluscities_pages', 'PlusCities Pages');
?>
<style>
.pc-pages-table{width:100%;border-collapse:collapse;background:#fff;border:1px solid var(--pc-line,#e2e8f2);border-radius:14px;overflow:hidden;}
.pc-pages-table th{text-align:center;font-size:.68rem;text-transform:uppercase;letter-spacing:.03em;color:var(--pc-muted,#52607a);padding:10px 8px;background:var(--pc-mist,#f4f7fb);border-bottom:1px solid var(--pc-line,#e2e8f2);}
.pc-pages-table th.pc-th-left{text-align:left;}
.pc-pages-table td{padding:10px 8px;border-bottom:1px solid var(--pc-mist,#f4f7fb);font-size:.85rem;text-align:center;vertical-align:middle;}
.pc-pages-table td.pc-td-left{text-align:left;}
.pc-pages-table tr:last-child td{border-bottom:none;}
.pc-pages-table tr:hover td{background:var(--pc-mist,#f4f7fb);}
.pc-dot{display:inline-block;width:12px;height:12px;border-radius:50%;background:#d1d5db;}
.pc-dot.on{background:#15803d;}
.pc-dot.fail{background:#dc2626;cursor:pointer;}
.pc-dot-btn{background:none;border:none;padding:4px;cursor:pointer;}
.pc-page-link{font-weight:700;color:var(--pc-ink,#1c2b45);text-decoration:none;}
.pc-page-link:hover{text-decoration:underline;}
.pc-unpublished-pill{display:inline-block;margin-left:8px;font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.02em;padding:2px 8px;border-radius:10px;background:#fee2e2;color:#991b1b;}
.pc-popover{display:none;position:absolute;z-index:50;background:#fff;border:1px solid var(--pc-line,#e2e8f2);border-radius:10px;box-shadow:0 8px 24px rgba(11,27,51,.15);padding:12px 14px;min-width:280px;text-align:left;font-size:.8rem;}
.pc-popover.open{display:block;}
.pc-popover .pc-check-row{display:flex;gap:6px;padding:3px 0;}
.pc-bulk-bar{display:flex;gap:8px;align-items:center;margin-bottom:12px;}
</style>

<?php if ($alert): ?><div class="alert alert-<?= h($alert['type']) ?>"><?= h($alert['text']) ?></div><?php endif; ?>

<?php
// Plugin download — per-agency branded copy (spec Section 14: the client's
// own WP admin must show the agency's name, never "PlusCities"). Agency
// Admin/Editor see their own agency's download; Platform Owner can fetch any
// agency's via ?agency_id= on the download endpoint directly for now — a
// per-agency picker here is a reasonable later addition, not blocking.
$downloadAgencyId = in_array(lpc_current_actor_role(), ['agency_admin', 'agency_editor'], true) ? lpc_current_actor_agency_id() : null;
if ($downloadAgencyId): ?>
    <p style="margin:0 0 14px;"><a class="btn-sm-outline" href="pluscities_plugin_download.php?agency_id=<?= h($downloadAgencyId) ?>">Download Delivery Plugin</a></p>
<?php endif; ?>

<?php if (!$cityOptions): ?>
    <div class="lpc-empty"><h2>No pages yet</h2><p>Pages appear here once a client has cities and services added.</p></div>
<?php else: ?>

<form method="get" action="pluscities_pages.php" class="pc-bulk-bar" style="margin-bottom:10px;">
    <label style="font-size:.8rem;">City
        <select name="city" onchange="this.form.submit()">
            <option value="">All cities</option>
            <?php foreach (array_keys($cityOptions) as $cid): ?>
                <option value="<?= h($cid) ?>" <?= $filterCity === $cid ? 'selected' : '' ?>><?= h($cid) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label style="font-size:.8rem;">Service
        <select name="service" onchange="this.form.submit()">
            <option value="">All services</option>
            <?php foreach (array_keys($serviceOptions) as $svc): ?>
                <option value="<?= h($svc) ?>" <?= $filterService === $svc ? 'selected' : '' ?>><?= h($svc) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <?php if ($filterCity !== '' || $filterService !== ''): ?>
        <a href="pluscities_pages.php" style="font-size:.8rem;">Clear filter</a>
    <?php endif; ?>
</form>

<?php if (!$rows): ?>
    <div class="lpc-empty"><h2>No pages match this filter</h2><p><a href="pluscities_pages.php">Clear the filter</a> to see all pages.</p></div>
<?php else: ?>

<form method="post" action="pluscities_pages_save.php" id="pc-pages-form">
<input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
<div class="pc-bulk-bar">
    <button type="submit" name="action" value="approve" class="btn-sm-outline">Approve selected</button>
    <button type="submit" name="action" value="publish" class="btn-open">Publish selected</button>
    <button type="submit" name="action" value="unpublish" class="btn-sm-danger" onclick="return confirm('Unpublish all selected pages? They will stop showing on their live sites immediately.');">Unpublish selected</button>
    <button type="submit" name="action" value="regenerate_unlocked" class="btn-sm-outline">Regenerate unlocked blocks</button>
</div>

<table class="pc-pages-table">
<thead><tr>
    <th style="width:28px;"><input type="checkbox" onclick="document.querySelectorAll('.pc-row-check').forEach(c=>c.checked=this.checked)"></th>
    <th class="pc-th-left">Page</th>
    <th>Researched</th><th>Written</th><th>Validated</th><th>Approved</th><th>Published</th><th>Live</th>
</tr></thead>
<tbody>
<?php foreach ($rows as $i => $r): $p = $r['page']; $d = $r['dots']; ?>
    <tr>
        <td><input type="checkbox" name="page_ids[]" value="<?= h($p['page_id']) ?>" class="pc-row-check"></td>
        <td class="pc-td-left">
            <a class="pc-page-link" href="pluscities_page.php?page_id=<?= h($p['page_id']) ?>"><?= h($r['client']['identity']['business_name'] ?? $p['client_id']) ?> — <?= h($p['city_id']) ?> / <?= h($p['service_key']) ?></a>
            <?php if ($d['unpublished']): ?><span class="pc-unpublished-pill">Unpublished</span><?php endif; ?>
        </td>
        <td><span class="pc-dot <?= $d['researched'] ? 'on' : '' ?>"></span></td>
        <td><span class="pc-dot <?= $d['written'] ? 'on' : '' ?>"></span></td>
        <td style="position:relative;">
            <?php if ($d['validated']): ?>
                <span class="pc-dot on"></span>
            <?php else: ?>
                <button type="button" class="pc-dot-btn" onclick="document.getElementById('pc-pop-<?= $i ?>').classList.toggle('open')">
                    <span class="pc-dot fail"></span>
                </button>
                <div class="pc-popover" id="pc-pop-<?= $i ?>">
                    <?php if (!$r['validation_current']): ?>
                        <div>Validation is stale — content changed since it last ran.</div>
                    <?php else: ?>
                        <?php foreach ($r['validation']['results'] ?? [] as $check): ?>
                            <div class="pc-check-row">
                                <strong style="color:<?= $check['result'] === 'pass' ? '#15803d' : '#dc2626' ?>;"><?= $check['result'] === 'pass' ? '✓' : '✗' ?></strong>
                                <span><?= h($check['check']) ?><?= $check['detail'] ? ' — ' . h($check['detail']) : '' ?></span>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    <div style="margin-top:8px;"><a href="pluscities_page.php?page_id=<?= h($p['page_id']) ?>">Open page →</a></div>
                </div>
            <?php endif; ?>
        </td>
        <td><span class="pc-dot <?= $d['approved'] ? 'on' : '' ?>"></span></td>
        <td><span class="pc-dot <?= $d['published'] ? 'on' : '' ?>"></span></td>
        <td><span class="pc-dot <?= $d['live'] ? 'on' : ($d['unpublished'] ? 'fail' : '') ?>" <?= $d['unpublished'] ? 'title="Unpublished — was live, pulled down"' : '' ?>></span></td>
    </tr>
<?php endforeach; ?>
</tbody>
</table>
</form>

<?php endif; ?>
<?php endif; ?>
<?php lpc_admin_close(); ?>
