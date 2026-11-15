<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/infra/lib/cities.php';      // infra_cities_init() — shared with client_wizard.php's lpc_city_candidates()
require_once __DIR__ . '/infra/lib/keywords.php';    // cities.php's infra_cn_cols() calls infra_kw_types() internally — not optional despite not using keyword fetch here
require_once __DIR__ . '/../includes/multisite/geocode.php'; // ms_haversine_miles()

if (empty($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

$client_id = $_GET['id'] ?? '';
$client = lpc_load_client($client_id);
if (!$client) {
    header('Location: clients.php?msg=error:Client+not+found');
    exit;
}

$tab = $_GET['tab'] ?? 'overview';
if (!in_array($tab, ['overview', 'locations', 'branding', 'content_profile', 'delivery', 'reporting'], true)) {
    $tab = 'overview';
}

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

$pagesLive = 0;
$pagesTotal = 0;
foreach (lpc_list_pages($client_id) as $page_id) {
    $page = lpc_load_page($client_id, $page_id);
    if (!$page) continue;
    $pagesTotal++;
    if ($page['status'] === 'published') $pagesLive++;
}

$status = lpc_plugin_status($client_id);
$locations = count($client['target_cities'] ?? []);
$mrr = $locations * (float) ($client['billing']['monthly_fee_per_location'] ?? 0);
$apiKey = lpc_get_client_api_key($client_id);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h($client['identity']['business_name']) ?> — <?= h(SITE_TITLE) ?></title>
<link rel="stylesheet" href="../assets/css/style.css">
<?php $_adminCssMtime = css_minify_to(__DIR__ . '/../assets/css/admin.src.css', __DIR__ . '/../assets/css/admin.css'); ?>
<link rel="stylesheet" href="../assets/css/admin.css?v=<?= (int) $_adminCssMtime ?>">
<style>
.lpc-wrap     { max-width: 1000px; margin: 0 auto; padding: 32px 24px 64px; }
.lpc-topbar   { display:flex; align-items:center; justify-content:space-between; margin-bottom:8px; flex-wrap:wrap; gap:12px; }
.lpc-topbar h1{ font-size:1.4rem; font-weight:700; margin:0; }
.lpc-sub      { color:#6b7280; font-size:.85rem; margin:0 0 20px; }
.lpc-stats    { display:flex; gap:28px; background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:16px 22px; margin-bottom:24px; flex-wrap:wrap; }
.lpc-stats div strong { display:block; font-size:1.25rem; color:#111; }
.lpc-stats div span   { font-size:.78rem; color:#6b7280; }
.lpc-badge    { font-size:.72rem; font-weight:700; padding:2px 9px; border-radius:99px; display:inline-block; }
.lpc-badge-connected { background:#dcfce7; color:#166534; }
.lpc-badge-stale      { background:#fef9c3; color:#92400e; }
.lpc-badge-never      { background:#f3f4f6; color:#6b7280; }
.lpc-hub-row  { background:#fff; border:1px solid #e5e7eb; border-radius:8px; padding:14px 18px; margin-bottom:10px; display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; }
.lpc-hub-row .meta { font-size:.82rem; color:#6b7280; }
.lpc-placeholder { background:#f8fafc; border:2px dashed #cbd5e1; border-radius:10px; padding:40px 24px; text-align:center; color:#6b7280; }
.lpc-form-row { display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end; margin-top:14px; }
.lpc-form-row .form-group { margin:0; flex:1; min-width:140px; }
.lpc-form-row label { font-size:.82rem; font-weight:600; color:#374151; display:block; margin-bottom:4px; }
.lpc-form-row input, .lpc-form-row textarea { width:100%; padding:8px 10px; border:1px solid #d1d5db; border-radius:6px; font-size:.85rem; font-family:inherit; }
</style>
</head>
<body class="admin-body">
<div style="background:#1e293b; color:#fff; padding:14px 24px; display:flex; align-items:center; justify-content:space-between;">
    <div style="font-weight:700; font-size:1rem; letter-spacing:.01em;">&#9776; Site Factory</div>
    <div style="display:flex; align-items:center; gap:16px;">
        <a href="clients.php" style="color:#94a3b8; font-size:.82rem; text-decoration:none;">&larr; Landing Page Clients</a>
        <a href="logout.php" style="color:#94a3b8; font-size:.82rem; text-decoration:none;">Log out</a>
    </div>
</div>

<div class="lpc-wrap">

    <?php if ($alert): ?>
        <div class="alert alert-<?= h($alert['type']) ?>"><?= h($alert['text']) ?></div>
    <?php endif; ?>

    <div class="lpc-topbar">
        <h1><?= h($client['identity']['business_name']) ?></h1>
        <div style="display:flex;align-items:center;gap:10px;">
            <a href="preview_site.php?client_id=<?= h($client_id) ?>" class="btn-sm-outline" target="_blank">&#128065; Preview Site</a>
            <?php
            $badgeClass = $status === 'Connected' ? 'connected' : ($status === 'Stale' ? 'stale' : 'never');
            ?>
            <span class="lpc-badge lpc-badge-<?= $badgeClass ?>"><?= h($status) ?></span>
        </div>
    </div>
    <p class="lpc-sub"><?= h($client['identity']['domain']) ?: 'No domain set' ?></p>

    <div class="lpc-stats">
        <div><strong><?= $pagesLive ?> / <?= $pagesTotal ?></strong><span>Pages Live</span></div>
        <div><strong><?= $locations ?></strong><span>Target Locations</span></div>
        <div><strong>$<?= number_format($mrr, 0) ?>/mo</strong><span>MRR</span></div>
    </div>

    <div class="tabs">
        <a class="tab-link <?= $tab === 'overview' ? 'active' : '' ?>" href="?id=<?= h($client_id) ?>&tab=overview">Overview</a>
        <a class="tab-link <?= $tab === 'locations' ? 'active' : '' ?>" href="?id=<?= h($client_id) ?>&tab=locations">Locations &amp; Services</a>
        <a class="tab-link <?= $tab === 'branding' ? 'active' : '' ?>" href="?id=<?= h($client_id) ?>&tab=branding">Branding</a>
        <a class="tab-link <?= $tab === 'content_profile' ? 'active' : '' ?>" href="?id=<?= h($client_id) ?>&tab=content_profile">Content Profile</a>
        <a class="tab-link <?= $tab === 'delivery' ? 'active' : '' ?>" href="?id=<?= h($client_id) ?>&tab=delivery">Delivery</a>
        <a class="tab-link <?= $tab === 'reporting' ? 'active' : '' ?>" href="?id=<?= h($client_id) ?>&tab=reporting">Reporting</a>
    </div>

    <?php if ($tab === 'overview'): ?>

        <h3 style="margin-top:0;">Identity</h3>
        <form method="post" action="client_save.php" class="card">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="section" value="identity">
            <input type="hidden" name="client_id" value="<?= h($client_id) ?>">
            <div class="lpc-form-row">
                <div class="form-group"><label>Business Name *</label><input type="text" name="business_name" required value="<?= h($client['identity']['business_name']) ?>"></div>
                <div class="form-group"><label>Domain</label><input type="text" name="domain" value="<?= h($client['identity']['domain']) ?>"></div>
                <div class="form-group"><label>Industry Category</label><input type="text" name="industry_category" value="<?= h($client['identity']['industry_category']) ?>"></div>
            </div>
            <div class="lpc-form-row">
                <div class="form-group"><label>Contact Name</label><input type="text" name="contact_name" value="<?= h($client['identity']['contact_name']) ?>"></div>
                <div class="form-group"><label>Contact Email</label><input type="email" name="contact_email" value="<?= h($client['identity']['contact_email']) ?>"></div>
                <div class="form-group"><label>Contact Phone</label><input type="text" name="contact_phone" value="<?= h($client['identity']['contact_phone']) ?>"></div>
            </div>
            <button type="submit" class="btn-open" style="margin-top:10px;">Save Identity</button>
            <p style="color:#6b7280;font-size:.78rem;margin:10px 0 0;">Changing the business name here does not rewrite already-generated page content or an already-published manifest by itself — Regenerate affected pages and Publish again to push the correction live.</p>
        </form>

        <h3 style="margin-top:28px;">Hub Offices</h3>
        <?php if (empty($client['hubs'])): ?>
            <p style="color:#6b7280;font-size:.88rem;">No hub offices yet — add the business's real office locations below.</p>
        <?php else: foreach ($client['hubs'] as $hub): ?>
            <div class="lpc-hub-row">
                <div>
                    <strong><?= h($hub['name']) ?></strong>
                    <div class="meta"><?= h($hub['address']['city'] ?? '') ?>, <?= h($hub['address']['state'] ?? '') ?> &middot; <?= h($hub['phone'] ?? '') ?></div>
                </div>
                <form method="post" action="client_save.php" onsubmit="return confirm('Delete this hub?');">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                    <input type="hidden" name="section" value="hubs">
                    <input type="hidden" name="client_id" value="<?= h($client_id) ?>">
                    <input type="hidden" name="hub_action" value="delete">
                    <input type="hidden" name="hub_id" value="<?= h($hub['hub_id']) ?>">
                    <button type="submit" class="btn-sm-danger">Delete</button>
                </form>
            </div>
        <?php endforeach; endif; ?>

        <form method="post" action="client_save.php" class="card" style="margin-top:14px;">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="section" value="hubs">
            <input type="hidden" name="client_id" value="<?= h($client_id) ?>">
            <input type="hidden" name="hub_action" value="add">
            <strong style="font-size:.88rem;">Add a hub office</strong>
            <div class="lpc-form-row">
                <div class="form-group"><label>Name</label><input type="text" name="name" required placeholder="e.g. Dallas Office"></div>
                <div class="form-group"><label>Street</label><input type="text" name="street"></div>
                <div class="form-group"><label>City</label><input type="text" name="city" required></div>
                <div class="form-group"><label>State</label><input type="text" name="state" required></div>
                <div class="form-group"><label>Zip</label><input type="text" name="zip"></div>
                <div class="form-group"><label>Phone</label><input type="text" name="phone"></div>
                <button type="submit" class="btn-open">Add Hub</button>
            </div>
        </form>

        <h3 style="margin-top:32px;">Billing</h3>
        <form method="post" action="client_save.php" class="card">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="section" value="billing">
            <input type="hidden" name="client_id" value="<?= h($client_id) ?>">
            <div class="lpc-form-row">
                <div class="form-group"><label>Setup Fee / Location</label><input type="number" step="0.01" name="setup_fee_per_location" value="<?= h($client['billing']['setup_fee_per_location']) ?>"></div>
                <div class="form-group"><label>Monthly Fee / Location</label><input type="number" step="0.01" name="monthly_fee_per_location" value="<?= h($client['billing']['monthly_fee_per_location']) ?>"></div>
                <div class="form-group"><label>Start Date</label><input type="date" name="start_date" value="<?= h($client['billing']['start_date']) ?>"></div>
                <button type="submit" class="btn-open">Save Billing</button>
            </div>
            <p style="color:#6b7280;font-size:.8rem;margin:10px 0 0;"><?= $locations ?> location<?= $locations === 1 ? '' : 's' ?> &times; $<?= number_format((float)$client['billing']['monthly_fee_per_location'], 2) ?>/mo = $<?= number_format($mrr, 2) ?>/mo</p>
        </form>

    <?php elseif ($tab === 'locations'): ?>

        <h3 style="margin-top:28px;">Services</h3>
        <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:10px;">
            <?php foreach ($client['services'] as $s): ?>
                <span class="lpc-badge" style="background:#eef2ff;color:#3730a3;display:inline-flex;align-items:center;gap:6px;">
                    <?= h($s['label']) ?>
                    <form method="post" action="client_save.php" style="margin:0;display:inline;" onsubmit="return confirm('Remove this service? It disappears from the city matrix — any already-generated pages stay on disk and come back if you re-add the same service.');">
                        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                        <input type="hidden" name="section" value="services">
                        <input type="hidden" name="service_action" value="remove">
                        <input type="hidden" name="client_id" value="<?= h($client_id) ?>">
                        <input type="hidden" name="service_id" value="<?= h($s['service_id']) ?>">
                        <button type="submit" style="background:none;border:none;color:#3730a3;cursor:pointer;font-weight:700;padding:0;line-height:1;" title="Remove service">&times;</button>
                    </form>
                </span>
            <?php endforeach; ?>
            <?php if (empty($client['services'])): ?>
                <span style="color:#6b7280;font-size:.85rem;">No services yet.</span>
            <?php endif; ?>
        </div>
        <form method="post" action="client_save.php" class="lpc-form-row" style="margin-top:0;">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="section" value="services">
            <input type="hidden" name="client_id" value="<?= h($client_id) ?>">
            <div class="form-group"><label>New Service</label><input type="text" name="service_label" placeholder="e.g. Fire Damage Restoration"></div>
            <button type="submit" class="btn-sm-outline">+ Add Service (applies to every existing city)</button>
        </form>

        <h3 style="margin-top:32px;">Target Cities &times; Services</h3>
        <?php if (empty($client['target_cities'])): ?>
            <p style="color:#6b7280;font-size:.88rem;">No target cities yet — add some from the suggestions below.</p>
        <?php else: ?>
            <?php
            // System tier — pure aggregation over data already in target_cities[]/
            // pages on disk, same "totals bar" pattern as the top-level Client List
            // screen. One pass before the table so the bar can render above it.
            $tPages = 0; $tPublished = 0; $tLocked = 0; $tResearched = 0;
            foreach ($client['target_cities'] as $tc) {
                if (!empty($tc['research_done'])) $tResearched++;
                foreach ($tc['services'] as $sid) {
                    $pg = lpc_load_page($client_id, $tc['city_id'] . '__' . $sid);
                    if (!$pg) continue;
                    $tPages++;
                    if (($pg['status'] ?? '') === 'published') $tPublished++;
                    if (!empty($pg['locked'])) $tLocked++;
                }
            }
            ?>
            <p style="color:#6b7280;font-size:.82rem;margin:0 0 10px;">
                <?= count($client['target_cities']) ?> cities &middot; <?= $tPages ?> pages &middot;
                <?= $tPublished ?> published &middot; <?= $tLocked ?> locked &middot;
                <?= $tResearched ?>/<?= count($client['target_cities']) ?> cities researched
            </p>
            <form id="lpc-publish-form" method="post" action="client_save.php" style="margin-bottom:10px;">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <input type="hidden" name="section" value="pages">
                <input type="hidden" name="page_action" value="publish">
            </form>
            <table class="lpc-matrix" style="width:100%;border-collapse:collapse;background:#fff;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;">
                <tr style="text-align:left;font-size:.74rem;color:#6b7280;background:#f9fafb;">
                    <th style="padding:8px 12px;">City</th>
                    <?php foreach ($client['services'] as $s): ?><th style="padding:8px 12px;"><?= h($s['label']) ?></th><?php endforeach; ?>
                    <th style="padding:8px 12px;"></th>
                </tr>
                <?php foreach ($client['target_cities'] as $tc): $cityResearched = !empty($tc['research_done']); ?>
                <tr style="border-top:1px solid #f1f5f9;font-size:.85rem;">
                    <td style="padding:8px 12px;">
                        <?= h($tc['city']) ?>, <?= h($tc['state']) ?><br>
                        <span class="lpc-badge <?= $cityResearched ? 'lpc-badge-connected' : 'lpc-badge-never' ?>" style="margin-top:3px;">
                            <?= $cityResearched ? 'Researched' : 'Not Researched' ?>
                        </span>
                        <form method="post" action="client_save.php" style="display:inline;margin:0;">
                            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                            <input type="hidden" name="section" value="target_cities">
                            <input type="hidden" name="client_id" value="<?= h($client_id) ?>">
                            <input type="hidden" name="tc_action" value="toggle_research">
                            <input type="hidden" name="city_id" value="<?= h($tc['city_id']) ?>">
                            <button type="submit" class="btn-sm-outline" style="font-size:.7rem;padding:1px 6px;"><?= $cityResearched ? 'Unmark' : 'Mark done' ?></button>
                        </form>
                    </td>
                    <?php foreach ($client['services'] as $s):
                        if (!in_array($s['service_id'], $tc['services'], true)) { echo '<td style="padding:8px 12px;color:#d1d5db;">—</td>'; continue; }
                        $pageId = $tc['city_id'] . '__' . $s['service_id'];
                        $page = lpc_load_page($client_id, $pageId);
                        $pageStatus = $page['status'] ?? null;
                    ?>
                        <td style="padding:8px 12px;">
                            <?php $editUrl = 'page_editor.php?client_id=' . urlencode($client_id) . '&page_id=' . urlencode($pageId); ?>
                            <?php $pageLocked = !empty($page['locked']); ?>
                            <?php $lastGen = $pageStatus !== null && !empty($page['updated_at']) ? date('M j', strtotime($page['updated_at'])) : null; ?>
                            <?php if ($pageStatus === null): ?>
                                <a href="<?= h($editUrl) ?>" class="btn-sm-outline" style="font-size:.74rem;padding:3px 8px;">+ Create &amp; Edit</a>
                            <?php elseif ($pageStatus === 'draft'): ?>
                                <input type="checkbox" form="lpc-publish-form" name="page_ids[]" value="<?= h($pageId) ?>">
                                <span class="lpc-badge lpc-badge-never">Draft</span>
                                <?php if ($pageLocked): ?><span title="Locked — Regenerate is blocked">&#128274;</span><?php endif; ?>
                                <a href="<?= h($editUrl) ?>" style="font-size:.76rem;">Edit</a>
                            <?php elseif ($pageStatus === 'published'): ?>
                                <span class="lpc-badge lpc-badge-connected">Published</span>
                                <?php if ($pageLocked): ?><span title="Locked — Regenerate is blocked">&#128274;</span><?php endif; ?>
                                <a href="<?= h($editUrl) ?>" style="font-size:.76rem;">Edit</a>
                            <?php else: ?>
                                <input type="checkbox" form="lpc-publish-form" name="page_ids[]" value="<?= h($pageId) ?>">
                                <span class="lpc-badge lpc-badge-stale">Needs Update</span>
                                <?php if ($pageLocked): ?><span title="Locked — Regenerate is blocked">&#128274;</span><?php endif; ?>
                                <a href="<?= h($editUrl) ?>" style="font-size:.76rem;">Edit</a>
                            <?php endif; ?>
                            <?php if ($lastGen): ?><div style="color:#9ca3af;font-size:.7rem;margin-top:2px;"><?= h($lastGen) ?></div><?php endif; ?>
                        </td>
                    <?php endforeach; ?>
                    <td style="padding:8px 12px;">
                        <form method="post" action="client_save.php" style="margin:0;" onsubmit="return confirm('Remove this target city?');">
                            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                            <input type="hidden" name="section" value="target_cities">
                            <input type="hidden" name="client_id" value="<?= h($client_id) ?>">
                            <input type="hidden" name="tc_action" value="remove">
                            <input type="hidden" name="city_id" value="<?= h($tc['city_id']) ?>">
                            <button type="submit" class="btn-sm-danger" style="font-size:.74rem;padding:3px 8px;">Remove</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </table>
            <button type="submit" form="lpc-publish-form" class="btn-open" style="margin-top:10px;">Publish Checked</button>
        <?php endif; ?>

        <?php foreach ($client['hubs'] as $hub): ?>
            <h3 style="margin-top:32px;">Add a City near <?= h($hub['name']) ?> (<?= (int) $hub['service_radius_miles'] ?> mi)</h3>
            <?php if ($hub['geo']['lat'] === null): ?>
                <p style="color:#b45309;font-size:.85rem;">This hub wasn't geocoded, so there are no automatic suggestions.</p>
            <?php else:
                $candidates = lpc_city_candidates($hub, array_column($client['target_cities'], 'city_id'));
                if (empty($candidates)): ?>
                <p style="color:#6b7280;font-size:.85rem;">No candidate cities found within <?= (int) $hub['service_radius_miles'] ?> miles.</p>
            <?php else: ?>
                <form method="post" action="client_save.php">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                    <input type="hidden" name="section" value="target_cities">
                    <input type="hidden" name="client_id" value="<?= h($client_id) ?>">
                    <input type="hidden" name="tc_action" value="add_selected">
                    <input type="hidden" name="hub_id" value="<?= h($hub['hub_id']) ?>">
                    <table style="width:100%;border-collapse:collapse;margin-bottom:10px;">
                        <tr style="text-align:left;font-size:.74rem;color:#6b7280;">
                            <th></th><th>City</th><th>Pop.</th><th>Dist.</th>
                        </tr>
                        <?php foreach ($candidates as $c): ?>
                        <tr style="border-top:1px solid #f1f5f9;font-size:.85rem;">
                            <td><input type="checkbox" name="selected[]" value="<?= h($c['city_id'] . '|' . $c['city'] . '|' . $c['state']) ?>"></td>
                            <td><?= h($c['city']) ?>, <?= h($c['state']) ?></td>
                            <td><?= number_format($c['population']) ?></td>
                            <td><?= $c['distance'] ?> mi</td>
                        </tr>
                        <?php endforeach; ?>
                    </table>
                    <button type="submit" class="btn-open" onclick="return confirm('Adding these cities auto-generates a real AI draft for every service at each one (real cost per page). Continue?');">Add Selected as Target Cities</button>
                </form>
            <?php endif; endif; ?>
        <?php endforeach; ?>

    <?php elseif ($tab === 'branding'): ?>

        <p style="color:#6b7280;font-size:.85rem;">For your own admin organization only — production delivery inherits the client's real WordPress theme automatically, so none of this affects what visitors see.</p>
        <form method="post" action="client_save.php" class="card">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="section" value="branding">
            <input type="hidden" name="client_id" value="<?= h($client_id) ?>">
            <div class="lpc-form-row">
                <div class="form-group"><label>Logo URL</label><input type="text" name="logo_url" value="<?= h($client['branding']['logo_url']) ?>"></div>
                <div class="form-group"><label>Font</label><input type="text" name="font" value="<?= h($client['branding']['font']) ?>"></div>
            </div>
            <div class="lpc-form-row">
                <div class="form-group"><label>Primary Color</label><input type="color" name="primary_color" value="<?= h($client['branding']['primary_color'] ?: '#2563eb') ?>"></div>
                <div class="form-group"><label>Accent Color</label><input type="color" name="accent_color" value="<?= h($client['branding']['accent_color'] ?: '#f5a623') ?>"></div>
                <button type="submit" class="btn-open">Save Branding</button>
            </div>
        </form>

    <?php elseif ($tab === 'content_profile'): ?>

        <?php
        $profileSource = $client['content_profile']['source'] ?: 'custom';
        $baseProfileLabel = $profileSource === 'custom'
            ? 'Custom (no matching niche)'
            : 'Inherits from ' . str_replace('niche:', '', $profileSource);
        ?>
        <div class="card">
            <strong style="font-size:.88rem;">Base Profile</strong>
            <p style="margin:8px 0 0;color:#374151;"><?= h($baseProfileLabel) ?></p>
            <p style="color:#6b7280;font-size:.8rem;margin:6px 0 0;">Set once, automatically, from the industry category entered during client creation. Not editable here.</p>
        </div>

        <form method="post" action="client_save.php" class="card" style="margin-top:14px;">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="section" value="content_profile">
            <input type="hidden" name="client_id" value="<?= h($client_id) ?>">

            <strong style="font-size:.88rem;">Excluded Services</strong>
            <p style="color:#6b7280;font-size:.8rem;margin:4px 0 10px;">Checked services are left out of this client's content — e.g. a referral-only exclusion for a service handled by a separate licensed entity.</p>
            <?php if (empty($client['services'])): ?>
                <p style="color:#6b7280;font-size:.85rem;">No services defined yet — add some on the Locations &amp; Services tab first.</p>
            <?php else: foreach ($client['services'] as $s): ?>
                <label style="display:block;font-size:.85rem;margin-bottom:6px;">
                    <input type="checkbox" name="excluded_services[]" value="<?= h($s['service_id']) ?>" <?= in_array($s['service_id'], $client['content_profile']['excluded_services'], true) ? 'checked' : '' ?>>
                    <?= h($s['label']) ?>
                </label>
            <?php endforeach; endif; ?>

            <strong style="font-size:.88rem;display:block;margin-top:18px;">Tone</strong>
            <select name="tone" style="padding:7px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:.85rem;margin-top:6px;">
                <?php foreach (['professional', 'friendly', 'casual', 'formal', 'authoritative'] as $t): ?>
                    <option value="<?= h($t) ?>" <?= $client['content_profile']['tone'] === $t ? 'selected' : '' ?>><?= h(ucfirst($t)) ?></option>
                <?php endforeach; ?>
            </select>

            <div class="lpc-form-row">
                <div class="form-group">
                    <label>Always Mention <span style="font-weight:400;color:#6b7280;">(one per line)</span></label>
                    <textarea name="always_mention" style="min-height:80px;"><?= h(implode("\n", $client['content_profile']['always_mention'])) ?></textarea>
                </div>
                <div class="form-group">
                    <label>Never Mention <span style="font-weight:400;color:#6b7280;">(one per line)</span></label>
                    <textarea name="never_mention" style="min-height:80px;"><?= h(implode("\n", $client['content_profile']['never_mention'])) ?></textarea>
                </div>
            </div>
            <p style="color:#6b7280;font-size:.8rem;margin:10px 0;">These feed the AI content pipeline as generation guardrails every time a page is regenerated. (Phase 3 — the AI pipeline itself — isn't wired up yet, so nothing consumes these fields live yet; they're ready for when it is.)</p>
            <button type="submit" class="btn-open">Save Content Profile</button>
        </form>

    <?php elseif ($tab === 'delivery'): ?>

        <p style="color:#6b7280;font-size:.85rem;">Onboarding a client takes 3 steps. Steps 4+ are ongoing controls, used occasionally.</p>

        <div class="card" style="margin-top:14px;">
            <strong style="font-size:.88rem;">1. Download the plugin and give the client their API key</strong>
            <p style="margin:8px 0;"><a href="lpc_plugin_download.php" class="btn-open">&#11015; Download Plugin (.zip)</a></p>
            <p style="font-family:monospace;background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;padding:10px 14px;margin:8px 0;word-break:break-all;"><?= h($apiKey) ?></p>
            <p style="color:#6b7280;font-size:.82rem;margin:0;">They (or you) install the zip under Plugins &rarr; Add New &rarr; Upload Plugin, activate it, then paste this key into Settings &rarr; Landing Page Client. That is their only setup step, ever — one universal plugin, nothing client-specific baked into the zip.</p>
        </div>

        <div class="card" style="margin-top:10px;">
            <strong style="font-size:.88rem;">2. Email it to them</strong>
            <form method="post" action="client_save.php">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <input type="hidden" name="section" value="email_to_customer">
                <input type="hidden" name="client_id" value="<?= h($client_id) ?>">
                <div class="lpc-form-row">
                    <div class="form-group"><label>To</label><input type="email" name="recipient" value="<?= h($client['identity']['contact_email']) ?>"></div>
                </div>
                <div class="lpc-form-row">
                    <label style="font-weight:400;"><input type="checkbox" name="include_key" value="1" checked> Include API key</label>
                    <label style="font-weight:400;"><input type="checkbox" name="include_plugin_link" value="1" checked> Include plugin install instructions</label>
                </div>
                <div class="form-group" style="margin-top:10px;"><label>Message</label><textarea name="message" style="min-height:90px;">Hi,

Here's what you need to get your location pages live on your site. Paste the key below into WordPress under Settings &rarr; Landing Page Client.

Thanks!</textarea></div>
                <button type="submit" class="btn-open" style="margin-top:10px;">Send Email</button>
            </form>
            <?php if ($client['delivery']['last_emailed']): ?>
                <p style="color:#6b7280;font-size:.78rem;margin:10px 0 0;">Last emailed: <?= h($client['delivery']['last_emailed']) ?></p>
            <?php endif; ?>
        </div>

        <div class="card" style="margin-top:10px;">
            <strong style="font-size:.88rem;">3. Confirm they've connected</strong>
            <p style="margin:8px 0 0;"><span class="lpc-badge lpc-badge-<?= $badgeClass ?>"><?= h($status) ?></span></p>
            <p style="color:#6b7280;font-size:.78rem;margin:6px 0 0;">Connected = recently checked in. Stale = overdue, worth a look. Never Connected = key not pasted in yet.</p>
        </div>

        <h3 style="margin-top:32px;">Ongoing Controls</h3>

        <div class="card">
            <strong style="font-size:.88rem;">4. Support message shown in their WordPress settings</strong>
            <p style="color:#6b7280;font-size:.8rem;margin:4px 0 10px;">Reaches them automatically on their next check-in — no re-install, no redistribution.</p>
            <form method="post" action="client_save.php" class="lpc-form-row">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <input type="hidden" name="section" value="delivery_settings">
                <input type="hidden" name="client_id" value="<?= h($client_id) ?>">
                <div class="form-group"><label>Support Phone</label><input type="text" name="support_phone" value="<?= h($client['delivery']['support_phone']) ?>"></div>
                <div class="form-group"><label>Support Message</label><input type="text" name="support_message" value="<?= h($client['delivery']['support_message']) ?>"></div>
                <button type="submit" class="btn-sm-outline">Save</button>
            </form>
        </div>

        <div class="card" style="margin-top:10px;">
            <strong style="font-size:.88rem;">5. <?= $client['delivery']['active'] ? 'Disable this client' : 'Re-enable this client' ?></strong>
            <p style="color:#6b7280;font-size:.8rem;margin:4px 0 10px;">
                <?= $client['delivery']['active']
                    ? 'Rejects their check-ins immediately, regardless of key validity — nothing is deleted, pages/drafts/reporting history all stay intact. Use the Support Message above to explain why before disabling, e.g. a billing issue.'
                    : 'Flips access back on instantly — nothing required from the client.' ?>
            </p>
            <?php if (!$client['delivery']['active']): ?>
                <p><span class="lpc-badge" style="background:#f3e8ff;color:#6b21a8;">Disabled</span></p>
            <?php endif; ?>
            <form method="post" action="client_save.php" onsubmit="return confirm('<?= $client['delivery']['active'] ? 'Disable this client? Their pages will stop responding to their WordPress plugin immediately.' : 'Re-enable this client?' ?>');">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <input type="hidden" name="section" value="toggle_active">
                <input type="hidden" name="client_id" value="<?= h($client_id) ?>">
                <button type="submit" class="<?= $client['delivery']['active'] ? 'btn-sm-danger' : 'btn-open' ?>"><?= $client['delivery']['active'] ? 'Disable Client' : 'Re-enable Client' ?></button>
            </form>
        </div>

        <div class="card" style="margin-top:10px;">
            <strong style="font-size:.88rem;">6. Regenerate the API key</strong>
            <p style="color:#6b7280;font-size:.8rem;margin:4px 0 10px;">The old key stops working immediately. Their plugin shows Stale until you give them the new one (step 2) and they paste it in.</p>
            <form method="post" action="client_save.php" onsubmit="return confirm('Regenerate the API key? The old one stops working immediately.');">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <input type="hidden" name="section" value="regenerate_key">
                <input type="hidden" name="client_id" value="<?= h($client_id) ?>">
                <button type="submit" class="btn-sm-danger">Regenerate Key</button>
            </form>
        </div>

        <div class="card" style="margin-top:10px;">
            <strong style="font-size:.88rem;">Integrations <span style="font-weight:400;color:#6b7280;">(set up when ready — not active yet)</span></strong>
            <p style="color:#6b7280;font-size:.8rem;margin:4px 0 10px;">
                Nothing in either field below does anything yet — no Google Analytics tag is injected, no
                Cloudflare zone is created. These are saved for when a real account exists to wire them
                into, so there's nothing to re-enter later.
            </p>
            <form method="post" action="client_save.php" class="lpc-form-row">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <input type="hidden" name="section" value="integrations">
                <input type="hidden" name="client_id" value="<?= h($client_id) ?>">
                <div class="form-group"><label>Google Analytics Measurement ID</label><input type="text" name="ga_measurement_id" placeholder="e.g. G-XXXXXXXXXX — leave blank until access is confirmed" value="<?= h($client['integrations']['ga_measurement_id']) ?>"></div>
                <div class="form-group"><label>Cloudflare Account</label><input type="text" name="cloudflare_account" placeholder="no dedicated account set up yet" value="<?= h($client['integrations']['cloudflare_account']) ?>"></div>
                <button type="submit" class="btn-sm-outline">Save</button>
            </form>
        </div>

    <?php elseif ($tab === 'reporting'): ?>

        <div class="card">
            <strong style="font-size:.88rem;">Search Console Connection</strong>
            <p style="color:#6b7280;font-size:.82rem;margin:6px 0 10px;">
                Have the client add this email as a <strong>Restricted</strong> (read-only) user on their
                Search Console property, then paste the property URL below.
            </p>
            <?php if (!gsc_has_creds()): ?>
                <p style="color:#b45309;font-size:.85rem;">No Search Console credentials configured on this server yet (<code>config/gsc_service_account.json</code> missing) — GSC Clicks will show "Not Connected" for every client until that's set up.</p>
            <?php else: ?>
                <p style="font-family:monospace;background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;padding:8px 12px;margin:0 0 10px;"><?= h(gsc_service_account_email()) ?></p>
            <?php endif; ?>
            <form method="post" action="client_save.php" class="lpc-form-row">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <input type="hidden" name="section" value="reporting">
                <input type="hidden" name="client_id" value="<?= h($client_id) ?>">
                <div class="form-group"><label>Search Console Property</label><input type="text" name="gsc_property" placeholder="https://clientdomain.com/" value="<?= h($client['reporting']['gsc_property']) ?>"></div>
                <button type="submit" class="btn-sm-outline">Save</button>
            </form>
        </div>

        <div class="lpc-actions-bar">
            <h3 style="margin:0;">Pages</h3>
            <form method="post" action="client_save.php" style="margin:0;">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <input type="hidden" name="section" value="pages">
                <input type="hidden" name="client_id" value="<?= h($client_id) ?>">
                <input type="hidden" name="page_action" value="check_rank">
                <button type="submit" class="btn-sm-outline" onclick="return confirm('This calls DataForSEO and spends real API cost, one call per published page. Continue?');">&#128176; Check Rankings (real cost)</button>
            </form>
        </div>

        <?php
        $gscProperty = $client['reporting']['gsc_property'];
        $gscReady = gsc_has_creds() && $gscProperty !== '';
        $domain = $client['identity']['domain'];
        $basePath = rtrim($client['delivery']['base_path'] ?: '/locations', '/');
        ?>
        <table class="lpc-matrix" style="width:100%;border-collapse:collapse;background:#fff;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;">
            <tr style="text-align:left;font-size:.74rem;color:#6b7280;background:#f9fafb;">
                <th style="padding:8px 12px;">City</th>
                <th style="padding:8px 12px;">Service</th>
                <th style="padding:8px 12px;">Rank Position</th>
                <th style="padding:8px 12px;">GSC Clicks</th>
                <th style="padding:8px 12px;">Form Submissions</th>
            </tr>
            <?php
            $anyPublished = false;
            foreach ($client['target_cities'] as $tc):
                foreach ($tc['services'] as $sid):
                    $pageId = $tc['city_id'] . '__' . $sid;
                    $page = lpc_load_page($client_id, $pageId);
                    if (!$page || $page['status'] !== 'published') continue;
                    $anyPublished = true;
                    $service = lpc_find_by_id($client['services'], 'service_id', $sid);
            ?>
            <tr style="border-top:1px solid #f1f5f9;font-size:.85rem;">
                <td style="padding:8px 12px;"><?= h($tc['city']) ?>, <?= h($tc['state']) ?></td>
                <td style="padding:8px 12px;"><?= h($service['label'] ?? $sid) ?></td>
                <td style="padding:8px 12px;"><?= $page['rank_position'] !== null ? '#' . (int) $page['rank_position'] : '—' ?></td>
                <td style="padding:8px 12px;">
                    <?php
                    if (!$gscReady) {
                        echo '<span style="color:#6b7280;">Not Connected</span>';
                    } else {
                        $pageUrl = 'https://' . $domain . $basePath . '/' . $pageId;
                        $clicks = gsc_fetch_page_clicks($gscProperty, $pageUrl);
                        echo $clicks === null ? '<span style="color:#6b7280;">Not Connected</span>' : h((string) $clicks);
                    }
                    ?>
                </td>
                <td style="padding:8px 12px;color:#6b7280;">Not Tracked</td>
            </tr>
            <?php endforeach; endforeach; ?>
            <?php if (!$anyPublished): ?>
            <tr><td colspan="5" style="padding:16px 12px;color:#6b7280;text-align:center;">No published pages yet.</td></tr>
            <?php endif; ?>
        </table>

    <?php else: ?>

        <div class="lpc-placeholder" style="margin-top:24px;">
            <p>This tab is not built yet.</p>
        </div>

    <?php endif; ?>

</div>
</body>
</html>
