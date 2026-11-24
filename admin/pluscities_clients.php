<?php
/**
 * admin/pluscities_clients.php — real client create/list for the PlusCities
 * engine. Every client so far (Elite Air HVAC, Lonestar Restoration) was
 * hand-authored as JSON through direct function calls — this is the one
 * missing panel screen that replaces that with a real form, matching
 * everything else in the engine already being driven through the panel
 * (Niche Library, Team, Pages, Photos).
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

$clients  = pluscities_client_ids_for_current_actor();
$agencies = lpc_current_actor_role() === 'super_admin' ? lpc_list_agencies() : [lpc_current_actor_agency_id()];
$niches   = pluscities_niche_list();

// Block Topics catalog — same single source platform_info.php's own
// documentation page reads, so this real picker and that internal doc page
// can never drift into describing a block differently (2026-11-22, moved
// here from the old LPC admin's picker — see project_lpc_to_pluscities_
// migration in memory for why).
$pcCatalog = [];
foreach (platform_info_city_page_blocks_data() as $pcCatSection) {
    foreach ($pcCatSection['rows'] as $pcCatRow) {
        if (!empty($pcCatRow['slug'])) $pcCatalog[$pcCatRow['slug']] = $pcCatRow;
    }
}
// City pool is real per-niche data (varies by family) — the niche isn't
// chosen until the agency picks it in the form below, so every real niche's
// pool is resolved up front here (cheap at today's scale) and all of them
// render as hidden groups; JS shows only the one matching the current
// selection. Service pool is the one fixed, niche-independent constant.
$pcNichePools = [];
foreach ($niches as $pcNicheId) {
    $pcNiche = pluscities_niche_resolved($pcNicheId);
    if ($pcNiche) $pcNichePools[$pcNicheId] = $pcNiche['city_blocks'] ?? [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Clients — <?= h(SITE_TITLE) ?></title>
<link rel="stylesheet" href="../assets/css/style.css">
<?php $_adminCssMtime = css_minify_to(__DIR__ . '/../assets/css/admin.src.css', __DIR__ . '/../assets/css/admin.css'); ?>
<link rel="stylesheet" href="../assets/css/admin.css?v=<?= (int) $_adminCssMtime ?>">
<?php lpc_admin_head_assets(); ?>
</head>
<?php
lpc_admin_open('pluscities_clients', 'Clients');
?>
<style>
.pc-card{background:#fff;border:1px solid var(--pc-line,#e2e8f2);border-radius:14px;padding:18px 20px;margin-bottom:16px;}
.pc-form-row{display:flex;gap:12px;flex-wrap:wrap;margin-top:10px;}
.pc-form-row .fg{flex:1;min-width:180px;}
.pc-form-row label{font-size:.8rem;font-weight:600;color:#374151;display:block;margin-bottom:4px;}
.pc-form-row input,.pc-form-row select{width:100%;padding:7px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:.84rem;font-family:inherit;}
.pc-row{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:10px 0;border-bottom:1px solid var(--pc-line,#e2e8f2);}
.pc-row:last-child{border-bottom:none;}
.pc-section-label{font-size:.72rem;text-transform:uppercase;letter-spacing:.04em;color:var(--pc-muted,#52607a);margin:18px 0 2px;}
</style>

<?php if ($alert): ?><div class="alert alert-<?= h($alert['type']) ?>"><?= h($alert['text']) ?></div><?php endif; ?>

<div class="pc-card">
    <h3 style="margin-top:0;">Clients</h3>
    <?php foreach ($clients as $cid => $c): ?>
        <div class="pc-row">
            <div>
                <strong><?= h($c['identity']['business_name'] ?? $cid) ?></strong>
                <div style="font-size:.78rem;color:var(--pc-muted,#52607a);">
                    <?= h($c['domain']) ?> &middot; <?= h($c['niche_id']) ?> &middot; <?= (int) count($c['target_cities'] ?? []) ?> cit(y/ies)
                </div>
            </div>
            <div style="display:flex;gap:8px;">
                <a href="pluscities_photos.php?client_id=<?= h($cid) ?>" class="btn-sm-outline">Photos</a>
                <a href="pluscities_pages.php?client_id=<?= h($cid) ?>" class="btn-sm-outline">Pages</a>
                <a href="pluscities_page_add.php?client_id=<?= h($cid) ?>" class="btn-sm-outline">+ Add Page</a>
                <a href="pluscities_hub_edit.php?client_id=<?= h($cid) ?>" class="btn-sm-outline">Service Area Page</a>
                <a href="pluscities_page_styling.php?client_id=<?= h($cid) ?>" class="btn-sm-outline">Page Styling</a>
                <a href="pluscities_plugin_download.php?client_id=<?= h($cid) ?>" class="btn-sm-outline">Download Plugin</a>
                <?php if (in_array(lpc_current_actor_role(), ['super_admin', 'agency_admin'], true)): ?>
                <form method="post" action="impersonate.php" style="margin:0;">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                    <input type="hidden" name="action" value="start">
                    <input type="hidden" name="type" value="client">
                    <input type="hidden" name="client_id" value="<?= h($cid) ?>">
                    <button type="submit" class="btn-sm-outline">Log in as</button>
                </form>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
    <?php if (!$clients): ?><p style="color:var(--pc-muted,#52607a);font-size:.84rem;">No clients yet.</p><?php endif; ?>
</div>

<div class="pc-card">
    <h3 style="margin-top:0;">+ New Client</h3>
    <form method="post" action="pluscities_clients_save.php" class="pc-form-row">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="create">

        <div class="pc-section-label" style="flex-basis:100%;">Identity</div>
        <div class="fg"><label>Business name</label><input type="text" name="business_name" required></div>
        <div class="fg"><label>Domain (their real site)</label><input type="text" name="domain" placeholder="example.com" required></div>
        <div class="fg"><label>Agency</label>
            <select name="agency_id" required>
                <?php foreach ($agencies as $aid): ?><option value="<?= h($aid) ?>"><?= h($aid) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="fg"><label>Niche</label>
            <select name="niche_id" required>
                <option value="">— choose —</option>
                <?php foreach ($niches as $nid): ?><option value="<?= h($nid) ?>"><?= h($nid) ?></option><?php endforeach; ?>
            </select>
        </div>

        <div class="pc-section-label" style="flex-basis:100%;">Hub location</div>
        <div class="fg"><label>Address</label><input type="text" name="hub_address" required placeholder="123 Main St, City, ST 00000"></div>
        <div class="fg"><label>Phone</label><input type="text" name="hub_phone" required placeholder="(555) 555-0100"></div>

        <div class="pc-section-label" style="flex-basis:100%;">Credentials &amp; history</div>
        <div class="fg"><label>License number</label><input type="text" name="license_number"></div>
        <div class="fg"><label>License state</label><input type="text" name="license_state" maxlength="2" placeholder="TX"></div>
        <div class="fg"><label>License trade</label><input type="text" name="license_trade" placeholder="General Contractor"></div>
        <div class="fg"><label>Years in business</label><input type="number" name="years_in_business" min="0"></div>

        <div class="pc-section-label" style="flex-basis:100%;">Hours</div>
        <div class="fg"><label>Mon–Fri</label><input type="text" name="hours_mon_fri" placeholder="7:00-19:00"></div>
        <div class="fg"><label>Sat</label><input type="text" name="hours_sat" placeholder="8:00-14:00"></div>
        <div class="fg"><label>Sun</label><input type="text" name="hours_sun" placeholder="closed"></div>

        <div class="pc-section-label" style="flex-basis:100%;">First page</div>
        <div class="fg"><label>City ID (from the cities table, e.g. "dallas-tx")</label><input type="text" name="first_city_id" required></div>
        <div class="fg"><label>Drive minutes from hub</label><input type="number" name="first_city_drive_minutes" min="0" value="15"></div>
        <div class="fg"><label>Service radius (minutes)</label><input type="number" name="radius_minutes" min="0" value="30"></div>
        <div class="fg"><label>URL base path</label><input type="text" name="url_base_path" value="service-area"></div>

        <div class="pc-section-label" style="flex-basis:100%;">Block Topics — the first page's content</div>
        <div style="flex-basis:100%;font-size:.8rem;color:var(--pc-muted,#52607a);margin-bottom:8px;">
            Pick the topics for this client's first page. City topics depend on the niche selected above —
            choose a niche first.
        </div>
        <div style="flex-basis:100%;">
            <?php foreach ($pcNichePools as $pcNicheId => $pcCityPool): ?>
                <div class="pc-niche-topics" data-niche="<?= h($pcNicheId) ?>" style="display:none;">
                    <strong style="font-size:.84rem;">City topics for "<?= h($pcNicheId) ?>" — choose up to <?= (int) min(6, count($pcCityPool)) ?> of <?= count($pcCityPool) ?></strong>
                    <?php foreach ($pcCityPool as $pcSlug): $pcRow = $pcCatalog[$pcSlug] ?? null; if (!$pcRow) continue; ?>
                        <label style="display:block;padding:6px 0;border-bottom:1px solid #f1f5f9;cursor:pointer;">
                            <input type="checkbox" class="pc-city-cb" name="city_block_picks[]" value="<?= h($pcSlug) ?>" data-niche="<?= h($pcNicheId) ?>">
                            <strong style="font-size:.82rem;"><?= h($pcRow['block']) ?></strong>
                            <span style="margin-left:6px;font-size:.7rem;color:#6b7280;background:#f1f5f9;border-radius:4px;padding:2px 6px;"><?= h(pluscities_block_class_label($pcRow['class'])) ?></span>
                            <p style="color:#6b7280;font-size:.75rem;margin:2px 0 0 22px;"><?= h($pcRow['what']) ?></p>
                        </label>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>

            <strong style="font-size:.84rem;">Service topics — choose up to <?= (int) min(4, count(PLUSCITIES_SERVICE_POOL)) ?> of <?= count(PLUSCITIES_SERVICE_POOL) ?></strong>
            <?php foreach (PLUSCITIES_SERVICE_POOL as $pcSlug): $pcRow = $pcCatalog[$pcSlug] ?? null; if (!$pcRow) continue; ?>
                <label style="display:block;padding:6px 0;border-bottom:1px solid #f1f5f9;cursor:pointer;">
                    <input type="checkbox" class="pc-service-cb" name="service_block_picks[]" value="<?= h($pcSlug) ?>">
                    <strong style="font-size:.82rem;"><?= h($pcRow['block']) ?></strong>
                    <span style="margin-left:6px;font-size:.7rem;color:#6b7280;background:#f1f5f9;border-radius:4px;padding:2px 6px;"><?= h(pluscities_block_class_label($pcRow['class'])) ?></span>
                    <p style="color:#6b7280;font-size:.75rem;margin:2px 0 0 22px;"><?= h($pcRow['what']) ?></p>
                </label>
            <?php endforeach; ?>
        </div>

        <div style="flex-basis:100%;">
            <button type="submit" class="btn-open">Create Client</button>
        </div>
    </form>
    <script>
    (function() {
        var nicheSelect = document.querySelector('select[name="niche_id"]');
        var nicheGroups = document.querySelectorAll('.pc-niche-topics');

        function enforceMax(selector, max) {
            var boxes = Array.prototype.slice.call(document.querySelectorAll(selector));
            var checkedCount = boxes.filter(function(b) { return b.checked; }).length;
            boxes.forEach(function(b) { if (!b.checked) b.disabled = (checkedCount >= max); });
        }
        function refreshCityEnforcement() {
            var activeNiche = nicheSelect.value;
            var visibleBoxes = document.querySelectorAll('.pc-city-cb[data-niche="' + activeNiche + '"]');
            var max = Math.min(6, visibleBoxes.length);
            enforceMax('.pc-city-cb[data-niche="' + activeNiche + '"]', max);
        }
        function onNicheChange() {
            var activeNiche = nicheSelect.value;
            // Clear every city checkbox first, not just the ones being hidden —
            // switching niches must never let a different niche's stale picks
            // ride along in the submission (a real bug caught before shipping:
            // hiding a group doesn't uncheck its boxes on its own, and some
            // block slugs exist in more than one niche's pool, so a stray
            // checked box could silently survive the whitelist filter).
            document.querySelectorAll('.pc-city-cb').forEach(function(b) { b.checked = false; b.disabled = false; });
            nicheGroups.forEach(function(g) {
                g.style.display = (g.getAttribute('data-niche') === activeNiche) ? 'block' : 'none';
            });
            refreshCityEnforcement();
        }
        nicheSelect.addEventListener('change', onNicheChange);
        document.querySelectorAll('.pc-city-cb').forEach(function(b) { b.addEventListener('change', refreshCityEnforcement); });

        var serviceBoxes = document.querySelectorAll('.pc-service-cb');
        function refreshServiceEnforcement() { enforceMax('.pc-service-cb', Math.min(4, serviceBoxes.length)); }
        serviceBoxes.forEach(function(b) { b.addEventListener('change', refreshServiceEnforcement); });

        onNicheChange();
        refreshServiceEnforcement();
    })();
    </script>
</div>

<?php lpc_admin_close(); ?>
