<?php
/**
 * admin/pluscities_page_add.php — adds a page to an EXISTING client. Did not
 * exist anywhere before 2026-11-22: `pluscities_create_page()` had exactly
 * one call site in the whole admin tree (client creation's hardcoded first
 * page) — there was no way to add city #2, #3, #4+ for a real agency at all.
 * See project_lpc_to_pluscities_migration in memory for the full context.
 *
 * Simpler than the client-creation form's picker: this client's niche is
 * already fixed, so only ONE real city-pool is ever resolved/shown here, no
 * multi-niche JS toggling needed the way the create-client form requires.
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

$clientId = $_GET['client_id'] ?? '';
$clients  = pluscities_client_ids_for_current_actor();
$client   = pluscities_actor_can_access_client($clientId) ? ($clients[$clientId] ?? null) : null;

$alert = null;
if (!empty($_GET['msg'])) {
    $raw = $_GET['msg'];
    if (strpos($raw, ':') !== false) {
        [$type, $text] = explode(':', $raw, 2);
        if (in_array($type, ['success', 'error', 'warning'], true)) $alert = ['type' => $type, 'text' => $text];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Add Page — <?= h($client['identity']['business_name'] ?? 'PlusCities') ?></title>
<link rel="stylesheet" href="../assets/css/style.css">
<?php $_adminCssMtime = css_minify_to(__DIR__ . '/../assets/css/admin.src.css', __DIR__ . '/../assets/css/admin.css'); ?>
<link rel="stylesheet" href="../assets/css/admin.css?v=<?= (int) $_adminCssMtime ?>">
<?php lpc_admin_head_assets(); ?>
</head>
<?php
lpc_admin_open('pluscities_clients', 'Add Page');
?>
<style>
.pc-card{background:#fff;border:1px solid var(--pc-line,#e2e8f2);border-radius:14px;padding:18px 20px;margin-bottom:16px;}
.pc-form-row{display:flex;gap:12px;flex-wrap:wrap;margin-top:10px;}
.pc-form-row .fg{flex:1;min-width:180px;}
.pc-form-row label{font-size:.8rem;font-weight:600;color:#374151;display:block;margin-bottom:4px;}
.pc-form-row input,.pc-form-row select{width:100%;padding:7px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:.84rem;font-family:inherit;}
.pc-section-label{font-size:.72rem;text-transform:uppercase;letter-spacing:.04em;color:var(--pc-muted,#52607a);margin:18px 0 2px;}
</style>

<?php if ($alert): ?><div class="alert alert-<?= h($alert['type']) ?>"><?= h($alert['text']) ?></div><?php endif; ?>

<?php if (!$client): ?>
    <div class="lpc-empty"><h2>Client not found</h2><p>No client with id <code><?= h($clientId) ?></code> (or you don't have access to it).</p></div>
<?php else:
    $niche = pluscities_niche_resolved($client['niche_id'] ?? '');
    if (!$niche) {
        echo '<div class="alert alert-error">This client\'s niche ("' . h($client['niche_id'] ?? '') . '") has no real blueprint — cannot add a page until one exists.</div>';
        lpc_admin_close();
        exit;
    }
    // $niche['city_blocks'] already excludes always_city_blocks —
    // pluscities_niche_resolved() subtracts them before this ever sees the
    // pool (includes/pluscities_niche_library.php). Service pool needs the
    // same subtraction applied here since PLUSCITIES_SERVICE_POOL itself is
    // still the full known-type catalog (see pluscities_validate_block_
    // picks()'s own comment for why it stays that way).
    $cityPool    = $niche['city_blocks'] ?? [];
    $servicePool = array_values(array_diff(PLUSCITIES_SERVICE_POOL, PLUSCITIES_ALWAYS_SERVICE_BLOCKS));
    $alwaysBlocks = array_merge($niche['always_city_blocks'] ?? [], PLUSCITIES_ALWAYS_SERVICE_BLOCKS);

    $pcCatalog = [];
    foreach (platform_info_city_page_blocks_data() as $pcCatSection) {
        foreach ($pcCatSection['rows'] as $pcCatRow) {
            if (!empty($pcCatRow['slug'])) $pcCatalog[$pcCatRow['slug']] = $pcCatRow;
        }
    }

    // Randomize pick-order per page load — a fixed catalog order plus a
    // hard pick-cap meant whoever builds a page checks top-down until the
    // limit, starving whatever sits near the end of a long list regardless
    // of real value (confirmed live: before_the_tech_arrives picked on just
    // 1 of 21 real pages). Shuffling removes position as the deciding
    // factor for what's left after the always-on promotions above.
    shuffle($cityPool);
    shuffle($servicePool);
?>
<?php pluscities_client_subtabs($clientId, 'page_add'); ?>
<div class="pc-card">
    <h3 style="margin-top:0;">Add Page — <?= h($client['identity']['business_name'] ?? $clientId) ?></h3>
    <p style="color:var(--pc-muted,#52607a);font-size:.84rem;">Niche: <?= h($client['niche_id']) ?></p>

    <form method="post" action="pluscities_page_add_save.php" class="pc-form-row">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
        <input type="hidden" name="client_id" value="<?= h($clientId) ?>">

        <div class="pc-section-label" style="flex-basis:100%;">Where</div>
        <div class="fg"><label>City ID (e.g. "dallas-tx")</label><input type="text" name="city_id" required></div>
        <div class="fg"><label>Drive minutes from hub</label><input type="number" name="drive_minutes" min="0" value="15"></div>
        <div class="fg"><label>Service</label>
            <select name="service_key" required>
                <?php foreach ($niche['services'] ?? [] as $svc): ?>
                    <option value="<?= h($svc['slug']) ?>"><?= h($svc['label'] ?? $svc['slug']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <?php if ($alwaysBlocks): ?>
        <div class="pc-section-label" style="flex-basis:100%;">Always included</div>
        <p style="flex-basis:100%;color:#6b7280;font-size:.8rem;margin:0 0 4px;">
            <?= h(implode(', ', array_map(fn($s) => $pcCatalog[$s]['block'] ?? $s, $alwaysBlocks))) ?>
            — every page gets these automatically, no pick needed.
        </p>
        <?php endif; ?>

        <div class="pc-section-label" style="flex-basis:100%;">City topics — choose up to <?= (int) min(6, count($cityPool)) ?> of <?= count($cityPool) ?></div>
        <div style="flex-basis:100%;">
            <?php foreach ($cityPool as $pcSlug): $pcRow = $pcCatalog[$pcSlug] ?? null; if (!$pcRow) continue; ?>
                <label style="display:block;padding:6px 0;border-bottom:1px solid #f1f5f9;cursor:pointer;">
                    <input type="checkbox" class="pc-city-cb" name="city_block_picks[]" value="<?= h($pcSlug) ?>">
                    <strong style="font-size:.82rem;"><?= h($pcRow['block']) ?></strong>
                    <span style="margin-left:6px;font-size:.7rem;color:#6b7280;background:#f1f5f9;border-radius:4px;padding:2px 6px;"><?= h(pluscities_block_class_label($pcRow['class'])) ?></span>
                    <p style="color:#6b7280;font-size:.75rem;margin:2px 0 0 22px;"><?= h($pcRow['what']) ?></p>
                </label>
            <?php endforeach; ?>
        </div>

        <div class="pc-section-label" style="flex-basis:100%;">Service topics — choose up to <?= (int) min(4, count($servicePool)) ?> of <?= count($servicePool) ?></div>
        <div style="flex-basis:100%;">
            <?php foreach ($servicePool as $pcSlug): $pcRow = $pcCatalog[$pcSlug] ?? null; if (!$pcRow) continue; ?>
                <label style="display:block;padding:6px 0;border-bottom:1px solid #f1f5f9;cursor:pointer;">
                    <input type="checkbox" class="pc-service-cb" name="service_block_picks[]" value="<?= h($pcSlug) ?>">
                    <strong style="font-size:.82rem;"><?= h($pcRow['block']) ?></strong>
                    <span style="margin-left:6px;font-size:.7rem;color:#6b7280;background:#f1f5f9;border-radius:4px;padding:2px 6px;"><?= h(pluscities_block_class_label($pcRow['class'])) ?></span>
                    <p style="color:#6b7280;font-size:.75rem;margin:2px 0 0 22px;"><?= h($pcRow['what']) ?></p>
                </label>
            <?php endforeach; ?>
        </div>

        <div style="flex-basis:100%;">
            <button type="submit" class="btn-open">Create Page</button>
        </div>
    </form>
</div>
<script>
(function() {
    function enforceMax(selector, max) {
        var boxes = Array.prototype.slice.call(document.querySelectorAll(selector));
        var checkedCount = boxes.filter(function(b) { return b.checked; }).length;
        boxes.forEach(function(b) { if (!b.checked) b.disabled = (checkedCount >= max); });
    }
    var cityBoxes = document.querySelectorAll('.pc-city-cb');
    var cityMax = Math.min(6, cityBoxes.length);
    cityBoxes.forEach(function(b) { b.addEventListener('change', function() { enforceMax('.pc-city-cb', cityMax); }); });

    var serviceBoxes = document.querySelectorAll('.pc-service-cb');
    var serviceMax = Math.min(4, serviceBoxes.length);
    serviceBoxes.forEach(function(b) { b.addEventListener('change', function() { enforceMax('.pc-service-cb', serviceMax); }); });
})();
</script>
<?php endif; ?>
<?php lpc_admin_close(); ?>
