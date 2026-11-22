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
                <a href="pluscities_plugin_download.php?client_id=<?= h($cid) ?>" class="btn-sm-outline">Download Plugin</a>
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

        <div style="flex-basis:100%;">
            <button type="submit" class="btn-open">Create Client</button>
        </div>
    </form>
</div>

<?php lpc_admin_close(); ?>
