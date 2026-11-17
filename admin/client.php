<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/lpc_nav.php';
require_once __DIR__ . '/infra/lib/cities.php';      // infra_cities_init() — shared with client_wizard.php's lpc_city_candidates()
require_once __DIR__ . '/infra/lib/keywords.php';    // cities.php's infra_cn_cols() calls infra_kw_types() internally — not optional despite not using keyword fetch here
require_once __DIR__ . '/../includes/multisite/geocode.php'; // ms_haversine_miles()

if (empty($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}
// Full editing admin page — an End Client gets read-only reporting only
// (client_portal.php), never this, regardless of which client their
// session is scoped to.
if (lpc_current_actor_role() === 'end_client') {
    header('Location: client_portal.php');
    exit;
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

$client_id = $_GET['id'] ?? '';
$client = lpc_load_client_for_current_actor($client_id);
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
            <a href="sites.php" class="btn-sm-outline">&larr; Dashboard</a>
            <button class="btn-sm-outline" onclick="toggleLpcHowItWorks()">How This Works &#9432;</button>
            <a href="preview_site.php?client_id=<?= h($client_id) ?>" class="btn-sm-outline" target="_blank">&#128065; Preview Site</a>
            <?php
            $badgeClass = $status === 'Connected' ? 'connected' : ($status === 'Stale' ? 'stale' : 'never');
            ?>
            <span class="lpc-badge lpc-badge-<?= $badgeClass ?>"><?= h($status) ?></span>
        </div>
    </div>
    <p class="lpc-sub"><?= h($client['identity']['domain']) ?: 'No domain set' ?></p>

    <div id="lpc-how-it-works-card" class="card" style="display:none;background:#f8fafc;border-left:3px solid #7c3aed;margin-bottom:18px;">
        <h3 style="margin-top:0;color:#1e3a5f;">What this is, how it's built, how to use it</h3>
        <p style="font-size:.88rem;line-height:1.7;">
            A multi-location landing-page service sold to external businesses (plumbers, restoration
            companies, etc.) — each client gets one page per city &times; service combo (e.g. "Fire Damage
            Restoration in Plano, TX"), hosted on their own WordPress site via a small plugin we give them.
        </p>
        <h4 style="margin-bottom:4px;">How it's built</h4>
        <ul style="margin:0 0 14px;padding-left:20px;line-height:1.8;font-size:.88rem;">
            <li>Content reuses this site factory's own engine — the same AI content pipeline, real per-city
                Census research (population, income, home values, year built, etc.), a real NOAA climate data
                chart per city, and niche-specific content templates the four live niche sites
                (water/mold/pest/appliance) already use.</li>
            <li>This client's pages are stored here (<code>clients/<?= h($client_id) ?>/data/</code>), then
                published to a separate delivery server their WordPress plugin fetches from — a thin,
                stateless proxy that renders inside <strong>their own theme's header/footer</strong>, so it
                inherits their real site's look with no custom CSS to fight against. A small floating badge
                the plugin injects site-wide links back to a public "Service Locations" hub page, grouped
                State &gt; Hub Area &gt; City &gt; Services, with real nearby-city cross-linking between
                pages.</li>
        </ul>
        <h4 style="margin-bottom:4px;">How to use it</h4>
        <ol style="margin:0;padding-left:20px;line-height:1.85;font-size:.88rem;">
            <li>Set Content Profile (tone/excluded services) <em>before</em> reviewing drafts, then use
                <strong>Locations &amp; Services</strong> — a city-grouped matrix with a checkbox per page
                and four selection-based actions:
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
            <li>Once they paste the key into their WordPress plugin settings, the status badge above flips
                to Connected.</li>
        </ol>
        <p style="font-size:.8rem;color:#6b7280;margin:14px 0 0;">Full step-by-step checklist:
            <code>docs/landing-page-clients-onboarding.md</code>.</p>
    </div>
    <script>
    function toggleLpcHowItWorks() {
        const el = document.getElementById('lpc-how-it-works-card');
        el.style.display = (el.style.display === 'none') ? '' : 'none';
    }
    </script>

    <div class="lpc-stats">
        <div><strong><?= $pagesLive ?> / <?= $pagesTotal ?></strong><span>Pages Live</span></div>
        <div><strong><?= $locations ?></strong><span>Target Locations</span></div>
        <div><strong>$<?= number_format($mrr, 0) ?>/mo</strong><span>MRR</span></div>
    </div>

    <?php lpc_render_nav('', $client_id, $client['identity']['business_name'], $tab); ?>

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

        <?php
        // One-time flash panel — per-page detail from the last bulk Publish
        // or Generate/Refresh run, not just the top banner's bare counts.
        // Unset immediately after reading so it shows exactly once, right
        // after the action that produced it, not on every later visit.
        $bulkResult = $_SESSION['lpc_bulk_result'][$client_id] ?? null;
        unset($_SESSION['lpc_bulk_result'][$client_id]);
        ?>
        <?php if ($bulkResult): ?>
        <div style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:12px 16px;margin:0 0 20px;">
            <strong style="font-size:.86rem;"><?= h($bulkResult['action']) ?> — per-page result</strong>
            <table style="width:100%;border-collapse:collapse;margin-top:8px;font-size:.82rem;">
                <?php foreach ($bulkResult['details'] as $pid => $d): ?>
                <tr style="border-top:1px solid #f1f5f9;">
                    <td style="padding:4px 8px;white-space:nowrap;"><?= h($pid) ?></td>
                    <td style="padding:4px 8px;">
                        <?php if ($d['status'] === 'ok'): ?>
                            <span class="lpc-badge lpc-badge-connected">OK</span>
                        <?php elseif ($d['status'] === 'skipped'): ?>
                            <span class="lpc-badge lpc-badge-never">Skipped (locked)</span>
                        <?php else: ?>
                            <span class="lpc-badge lpc-badge-stale">Warning</span>
                        <?php endif; ?>
                    </td>
                    <td style="padding:4px 8px;color:#6b7280;"><?= h(implode('; ', $d['warnings'])) ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
        <?php endif; ?>

        <h3 style="margin-top:28px;">Services</h3>
        <p style="color:#6b7280;font-size:.82rem;margin:0 0 10px;">
            One image per service, shared by every city's page for it (a photo of "water damage cleanup"
            looks the same regardless of which city it's on — unlike text, there's no real per-city
            variation to capture). Falls back to a placeholder until a real or AI image is set.
        </p>
        <?php foreach ($client['services'] as $s):
            $hasReal = !empty($s['image_real']);
            $hasAi   = !empty($s['image_ai']);
            $active  = $s['image_active'] ?? '';
            $thumb = lpc_resolve_service_image($client_id, $s);
        ?>
            <div class="lpc-row" style="align-items:flex-start;">
                <img src="<?= h($thumb['url']) ?>" alt="" style="width:64px;height:64px;object-fit:cover;border-radius:6px;border:1px solid #e5e7eb;flex-shrink:0;">
                <div style="flex:1;">
                    <strong><?= h($s['label']) ?></strong>
                    <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-top:6px;">
                        <form method="post" action="client_save.php" enctype="multipart/form-data" style="margin:0;display:flex;gap:6px;align-items:center;">
                            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                            <input type="hidden" name="section" value="services">
                            <input type="hidden" name="service_action" value="upload_image">
                            <input type="hidden" name="client_id" value="<?= h($client_id) ?>">
                            <input type="hidden" name="service_id" value="<?= h($s['service_id']) ?>">
                            <input type="file" name="service_image" accept="image/*" style="font-size:.74rem;max-width:160px;">
                            <button type="submit" class="btn-sm-outline" style="font-size:.74rem;padding:3px 8px;">Upload</button>
                        </form>
                        <form method="post" action="client_save.php" style="margin:0;">
                            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                            <input type="hidden" name="section" value="services">
                            <input type="hidden" name="service_action" value="generate_image">
                            <input type="hidden" name="client_id" value="<?= h($client_id) ?>">
                            <input type="hidden" name="service_id" value="<?= h($s['service_id']) ?>">
                            <button type="submit" class="btn-sm-outline" style="font-size:.74rem;padding:3px 8px;" onclick="return confirm('Generate an AI photo for this service? Real OpenAI image cost.');">&#128176; Generate AI</button>
                        </form>
                        <?php if ($hasReal && $hasAi): ?>
                        <form method="post" action="client_save.php" style="margin:0;">
                            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                            <input type="hidden" name="section" value="services">
                            <input type="hidden" name="service_action" value="set_active_image">
                            <input type="hidden" name="client_id" value="<?= h($client_id) ?>">
                            <input type="hidden" name="service_id" value="<?= h($s['service_id']) ?>">
                            <input type="hidden" name="which" value="<?= $active === 'real' ? 'ai' : 'real' ?>">
                            <button type="submit" class="btn-sm-outline" style="font-size:.74rem;padding:3px 8px;">Switch to <?= $active === 'real' ? 'AI' : 'Real' ?> photo</button>
                        </form>
                        <?php endif; ?>
                        <span style="color:#9ca3af;font-size:.72rem;">
                            <?= $active === '' ? 'No image set — showing placeholder' : ('Active: ' . ($active === 'real' ? 'uploaded photo' : 'AI-generated')) ?>
                        </span>
                        <form method="post" action="client_save.php" style="margin:0;" onsubmit="return confirm('Remove this service? It disappears from the city matrix — any already-generated pages stay on disk and come back if you re-add the same service.');">
                            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                            <input type="hidden" name="section" value="services">
                            <input type="hidden" name="service_action" value="remove">
                            <input type="hidden" name="client_id" value="<?= h($client_id) ?>">
                            <input type="hidden" name="service_id" value="<?= h($s['service_id']) ?>">
                            <button type="submit" class="btn-sm-danger" style="font-size:.74rem;padding:3px 8px;margin-left:auto;">Remove Service</button>
                        </form>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
        <?php if (empty($client['services'])): ?>
            <p style="color:#6b7280;font-size:.85rem;">No services yet.</p>
        <?php endif; ?>
        <form method="post" action="client_save.php" class="lpc-form-row" style="margin-top:10px;">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="section" value="services">
            <input type="hidden" name="client_id" value="<?= h($client_id) ?>">
            <div class="form-group"><label>New Service</label><input type="text" name="service_label" placeholder="e.g. Fire Damage Restoration"></div>
            <button type="submit" class="btn-sm-outline">+ Add Service (applies to every existing city)</button>
        </form>

        <h3 style="margin-top:32px;display:flex;align-items:center;gap:10px;">
            Target Cities &times; Services
            <?php $lpcDomain = trim($client['identity']['domain'] ?? ''); if ($lpcDomain !== ''): ?>
            <a href="https://<?= h($lpcDomain) ?>/" target="_blank" rel="noopener" style="font-size:.78rem;font-weight:400;color:#6b7280;">&#8599; <?= h($lpcDomain) ?></a>
            <?php endif; ?>
        </h3>
        <?php if (empty($client['target_cities'])): ?>
            <p style="color:#6b7280;font-size:.88rem;">No target cities yet — add some from the suggestions below.</p>
        <?php else: ?>
            <?php
            $lpcBasePath = trim($client['delivery']['base_path'] ?? '', '/') ?: 'locations';
            // Climate Data (data_chart section) is per CITY, not per page — the
            // chart is the same for every service at a city (a client has one
            // niche, which is what picks it), result shared-cached by
            // city_id+chart id. Computed once here, not per-row, so the loop
            // below just does an array lookup.
            $clientNicheBrief = lpc_ai_niche_brief($client['content_profile']['source'] ?? '');
            $clientChartDef   = lpc_chart_definition_for_niche($clientNicheBrief['niche_id'] ?? '');
            $climateCache     = $clientChartDef ? lpc_climate_cache_load() : [];

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
            <?php
            // Both forms are empty shells — checked rows are injected as
            // hidden inputs into whichever one's button gets clicked
            // (lpcRunSelected() below), since one checkbox can't natively
            // belong to two <form>s. Same "shared tick marks, multiple
            // purpose-specific action buttons" pattern as Batch Simple/
            // Detailed's own checkbox-selection UI (admin/rollout.php,
            // admin/_batch_panels.php) — mirrored here rather than invented
            // fresh, deliberately NOT their JS-state/quick-select machinery
            // (overkill at this scale; a plain checkbox per row is enough).
            ?>
            <form id="lpc-publish-form" method="post" action="client_save.php" style="display:none;">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <input type="hidden" name="section" value="pages">
                <input type="hidden" name="page_action" value="publish">
                <input type="hidden" name="client_id" value="<?= h($client_id) ?>">
            </form>
            <form id="lpc-generate-form" method="post" action="client_save.php" style="display:none;">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <input type="hidden" name="section" value="pages">
                <input type="hidden" name="page_action" value="bulk_generate">
                <input type="hidden" name="client_id" value="<?= h($client_id) ?>">
            </form>
            <form id="lpc-research-form" method="post" action="client_save.php" style="display:none;">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <input type="hidden" name="section" value="pages">
                <input type="hidden" name="page_action" value="bulk_research">
                <input type="hidden" name="client_id" value="<?= h($client_id) ?>">
            </form>
            <form id="lpc-livecheck-form" method="post" action="client_save.php" style="display:none;">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <input type="hidden" name="section" value="pages">
                <input type="hidden" name="page_action" value="bulk_live_check">
                <input type="hidden" name="client_id" value="<?= h($client_id) ?>">
            </form>
            <div style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:10px 14px;margin-bottom:14px;display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
                <label style="font-size:.82rem;display:flex;align-items:center;gap:5px;font-weight:600;">
                    <input type="checkbox" onclick="lpcToggleAll(this)"> Select All
                </label>
                <div style="width:1px;align-self:stretch;background:#e5e7eb;"></div>
                <button type="button" class="btn-open" onclick="return lpcRunSelected('lpc-publish-form');">Publish Selected</button>
                <div style="width:1px;align-self:stretch;background:#e5e7eb;"></div>
                <button type="button" class="btn-open" onclick="return lpcRunSelected('lpc-research-form');" title="Real Census/population lookup — free, runs once per unique city among the pages checked">Research Selected</button>
                <div style="width:1px;align-self:stretch;background:#e5e7eb;"></div>
                <button type="button" class="btn-open" onclick="return lpcGenerateSequential();">Generate / Refresh Selected</button>
                <label style="font-size:.78rem;display:flex;align-items:center;gap:4px;"><input type="checkbox" name="opt_refresh_hero" form="lpc-generate-form" checked> Refresh hero image</label>
                <label style="font-size:.78rem;display:flex;align-items:center;gap:4px;"><input type="checkbox" name="opt_refetch_climate" form="lpc-generate-form"> Re-fetch climate data <span style="color:#9ca3af;">($)</span></label>
                <label style="font-size:.78rem;display:flex;align-items:center;gap:4px;"><input type="checkbox" name="opt_regenerate_text" form="lpc-generate-form"> Regenerate text content <span style="color:#9ca3af;">($)</span></label>
                <label style="font-size:.78rem;display:flex;align-items:center;gap:4px;"><input type="checkbox" name="opt_skip_locked" form="lpc-generate-form" checked> Skip locked pages</label>
                <div style="width:1px;align-self:stretch;background:#e5e7eb;"></div>
                <button type="button" class="btn-open" onclick="return lpcRunSelected('lpc-livecheck-form');" title="Fetches each selected page's REAL live URL and checks what it's actually serving — the one check that looks past our own data">Check Live Selected</button>
            </div>
            <div id="lpc-generate-progress" style="display:none;background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:10px 14px;margin-bottom:14px;font-size:.82rem;"></div>
            <script>
            // Generate/Refresh runs ONE page per request instead of one big
            // blocking multi-page POST — real incident, 2026-11-16: with real
            // AI text regen + a real climate re-fetch checked across 8+
            // pages, the old single blocking request ran for several minutes
            // with the browser showing nothing at all, long enough to risk a
            // server/proxy timeout before it ever finished — indistinguishable
            // from "the button does nothing." This shows live progress after
            // every single page instead of going silent until the whole
            // batch completes.
            async function lpcGenerateSequential() {
                var checked = document.querySelectorAll('.lpc-row-check:checked');
                if (!checked.length) { alert('Select at least one page first.'); return false; }
                if (!confirm('Generate/refresh the selected pages? Checked options below with real external cost (AI text, climate data) will actually spend it.')) return false;

                var pageIds = Array.prototype.map.call(checked, function (cb) { return cb.value; });
                var form = document.getElementById('lpc-generate-form');
                var csrf = form.querySelector('[name="csrf_token"]').value;
                var clientId = form.querySelector('[name="client_id"]').value;
                var optFields = ['opt_refresh_hero', 'opt_refetch_climate', 'opt_regenerate_text', 'opt_skip_locked'];

                var progress = document.getElementById('lpc-generate-progress');
                progress.style.display = 'block';
                var results = [];
                var renderLog = function () {
                    var html = '<ul style="margin:6px 0 0;padding-left:18px;">';
                    results.forEach(function (r) {
                        var label = r.result.skipped ? 'Skipped (locked)'
                            : (r.result.warnings && r.result.warnings.length ? 'Warning: ' + r.result.warnings.join('; ') : 'OK');
                        html += '<li><code>' + r.page_id + '</code> — ' + label + '</li>';
                    });
                    return html + '</ul>';
                };

                for (var i = 0; i < pageIds.length; i++) {
                    progress.innerHTML = '<strong>Processing ' + (i + 1) + ' of ' + pageIds.length + ':</strong> <code>' + pageIds[i] + '</code>&hellip;' + renderLog();
                    var body = new URLSearchParams();
                    body.set('csrf_token', csrf);
                    body.set('section', 'pages');
                    body.set('page_action', 'generate_one');
                    body.set('client_id', clientId);
                    body.set('page_id', pageIds[i]);
                    optFields.forEach(function (name) {
                        if (form.querySelector('[name="' + name + '"]').checked) body.set(name, '1');
                    });
                    try {
                        var resp = await fetch('client_save.php', { method: 'POST', body: body });
                        results.push(await resp.json());
                    } catch (e) {
                        results.push({ page_id: pageIds[i], result: { ok: false, skipped: false, warnings: ['Request failed: ' + e.message] } });
                    }
                }
                progress.innerHTML = '<strong>Done — ' + pageIds.length + ' page(s) processed.</strong>' + renderLog()
                    + '<a href="javascript:location.reload()" class="btn-sm-outline" style="margin-top:6px;display:inline-block;">Reload to see updated status</a>';
                return false;
            }
            </script>
            <script>
            // One shared checkbox per row feeds whichever action's button is
            // clicked — a checkbox can only natively belong to one <form>, so
            // checked row values are injected as hidden inputs into the
            // target form right before it submits, then removed if a
            // different action runs next (no stale page_ids[] left over from
            // a previous click sitting in the other form).
            function lpcRunSelected(formId, confirmMsg) {
                if (confirmMsg && !confirm(confirmMsg)) return false;
                var form = document.getElementById(formId);
                form.querySelectorAll('input[data-lpc-injected]').forEach(function (el) { el.remove(); });
                var checked = document.querySelectorAll('.lpc-row-check:checked');
                if (!checked.length) { alert('Select at least one page first.'); return false; }
                checked.forEach(function (cb) {
                    var hidden = document.createElement('input');
                    hidden.type = 'hidden';
                    hidden.name = 'page_ids[]';
                    hidden.value = cb.value;
                    hidden.setAttribute('data-lpc-injected', '1');
                    form.appendChild(hidden);
                });
                form.submit();
                return false;
            }
            function lpcToggleCity(master, cityId) {
                document.querySelectorAll('.lpc-row-check[data-city="' + cityId + '"]').forEach(function (cb) { cb.checked = master.checked; });
            }
            function lpcToggleAll(master) {
                document.querySelectorAll('.lpc-row-check').forEach(function (cb) { cb.checked = master.checked; });
                document.querySelectorAll('.lpc-city-select-all').forEach(function (cb) { cb.checked = master.checked; });
            }
            </script>
            <?php
            // City-grouped list, not a City x Service grid — a real grid
            // grows WIDER with every new service (cramping status/locked/
            // last-updated/keyword-data all into one narrow cell), which is
            // the wrong direction for a table meant to be scanned. This grows
            // TALLER instead (one row per service, nested under its city),
            // same grouping principle as the public hub page's own State >
            // Hub Area > City > Services redesign earlier this session.
            ?>
            <?php foreach ($client['target_cities'] as $tc): $cityResearched = !empty($tc['research_done']); $kwData = $_SESSION['lpc_kw_city'][$client_id][$tc['city_id']] ?? []; $hasClimate = $clientChartDef ? isset($climateCache[$tc['city_id'] . '__' . $clientChartDef['id']]) : null; ?>
            <div class="lpc-city-group" style="margin-bottom:16px;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;">
                <div style="background:#f9fafb;border-bottom:1px solid #e5e7eb;padding:10px 14px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                    <input type="checkbox" class="lpc-city-select-all" title="Select every page in this city" onclick="lpcToggleCity(this, '<?= h($tc['city_id']) ?>')">
                    <strong style="font-size:.92rem;"><?= h($tc['city']) ?>, <?= h($tc['state']) ?></strong>
                    <span class="lpc-badge <?= $cityResearched ? 'lpc-badge-connected' : 'lpc-badge-never' ?>"><?= $cityResearched ? 'Researched &#10003;' : 'Not Researched' ?></span>
                    <form method="post" action="client_save.php" style="margin:0;display:inline;">
                        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                        <input type="hidden" name="section" value="target_cities">
                        <input type="hidden" name="client_id" value="<?= h($client_id) ?>">
                        <input type="hidden" name="tc_action" value="run_research">
                        <input type="hidden" name="city_id" value="<?= h($tc['city_id']) ?>">
                        <button type="submit" class="btn-sm-outline" style="font-size:.7rem;padding:1px 6px;" title="Real Census/population lookup — free, no external cost"><?= $cityResearched ? 'Re-research' : 'Research' ?></button>
                    </form>
                    <?php if (infra_kw_has_creds('dataforseo')): ?>
                    <form method="post" action="client_save.php" style="margin:0;display:inline;">
                        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                        <input type="hidden" name="section" value="target_cities">
                        <input type="hidden" name="client_id" value="<?= h($client_id) ?>">
                        <input type="hidden" name="tc_action" value="check_demand">
                        <input type="hidden" name="city_id" value="<?= h($tc['city_id']) ?>">
                        <button type="submit" class="btn-sm-outline" style="font-size:.7rem;padding:1px 6px;" onclick="return confirm('Check real keyword demand for every service at this city? Real DataForSEO cost.');">&#128176; Check Demand</button>
                    </form>
                    <?php endif; ?>
                    <?php if ($clientChartDef): ?>
                    <form method="post" action="client_save.php" style="margin:0;display:inline;">
                        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                        <input type="hidden" name="section" value="target_cities">
                        <input type="hidden" name="client_id" value="<?= h($client_id) ?>">
                        <input type="hidden" name="tc_action" value="fetch_climate_data">
                        <input type="hidden" name="city_id" value="<?= h($tc['city_id']) ?>">
                        <span class="lpc-badge <?= $hasClimate ? 'lpc-badge-connected' : 'lpc-badge-never' ?>"><?= $hasClimate ? 'Climate &#10003;' : 'No Climate Data' ?></span>
                        <button type="submit" class="btn-sm-outline" style="font-size:.7rem;padding:1px 6px;">&#9729;&#65039; <?= $hasClimate ? 'Re-fetch' : 'Fetch' ?></button>
                    </form>
                    <?php endif; ?>
                    <form method="post" action="client_save.php" style="margin:0 0 0 auto;" onsubmit="return confirm('Remove this target city?');">
                        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                        <input type="hidden" name="section" value="target_cities">
                        <input type="hidden" name="client_id" value="<?= h($client_id) ?>">
                        <input type="hidden" name="tc_action" value="remove">
                        <input type="hidden" name="city_id" value="<?= h($tc['city_id']) ?>">
                        <button type="submit" class="btn-sm-danger" style="font-size:.74rem;padding:3px 8px;">Remove City</button>
                    </form>
                </div>
                <table style="width:100%;border-collapse:collapse;">
                    <tr style="text-align:left;font-size:.72rem;color:#6b7280;">
                        <th style="padding:6px 14px;width:26px;"></th>
                        <th style="padding:6px 14px;">Service</th>
                        <th style="padding:6px 14px;">Status</th>
                        <th style="padding:6px 14px;">Locked</th>
                        <th style="padding:6px 14px;">Last Updated</th>
                        <th style="padding:6px 14px;">Keyword Data</th>
                        <th style="padding:6px 14px;" title="What the page's REAL live URL is actually serving right now, not just our own data">Live</th>
                        <th style="padding:6px 14px;"></th>
                    </tr>
                    <?php foreach ($client['services'] as $s):
                        $included = in_array($s['service_id'], $tc['services'], true);
                        $kw = $kwData[$s['service_id']] ?? null;
                        $kwLine = $kw ? ('Vol ' . number_format((int) ($kw['volume'] ?? 0)) . ' &middot; KD ' . (int) ($kw['kd'] ?? 0) . ' &middot; $' . number_format((float) ($kw['cpc'] ?? 0), 2)) : null;
                    ?>
                    <?php
                    $pageId = $tc['city_id'] . '__' . $s['service_id'];
                    $page = $included ? lpc_load_page($client_id, $pageId) : null;
                    $pageStatus = $page['status'] ?? null;
                    $liveUrl = 'https://' . $lpcDomain . '/' . $lpcBasePath . '/' . rawurlencode($pageId) . '/';
                    ?>
                    <tr style="border-top:1px solid #f1f5f9;font-size:.85rem;">
                        <td style="padding:6px 14px;">
                            <?php if ($included): ?>
                            <input type="checkbox" class="lpc-row-check" data-city="<?= h($tc['city_id']) ?>" value="<?= h($pageId) ?>">
                            <?php endif; ?>
                        </td>
                        <td style="padding:6px 14px;">
                            <?php if ($pageStatus === 'published' && $lpcDomain !== ''): ?>
                            <a href="<?= h($liveUrl) ?>" target="_blank" rel="noopener" title="Open the live page"><?= h($s['label']) ?></a>
                            <?php else: ?>
                            <?= h($s['label']) ?>
                            <?php endif; ?>
                        </td>
                        <?php if (!$included): ?>
                        <td colspan="5" style="padding:6px 14px;color:#d1d5db;">Not included<?php if ($kwLine): ?> &middot; <span style="color:#9ca3af;"><?= $kwLine ?></span><?php endif; ?></td>
                        <td style="padding:6px 14px;">
                            <form method="post" action="client_save.php" style="margin:0;">
                                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                                <input type="hidden" name="section" value="target_cities">
                                <input type="hidden" name="client_id" value="<?= h($client_id) ?>">
                                <input type="hidden" name="tc_action" value="toggle_service">
                                <input type="hidden" name="city_id" value="<?= h($tc['city_id']) ?>">
                                <input type="hidden" name="service_id" value="<?= h($s['service_id']) ?>">
                                <button type="submit" class="btn-sm-outline" style="font-size:.7rem;padding:1px 6px;">+ Add</button>
                            </form>
                        </td>
                        <?php continue; endif; ?>
                        <?php
                        $editUrl = 'page_editor.php?client_id=' . urlencode($client_id) . '&page_id=' . urlencode($pageId);
                        $pageLocked = !empty($page['locked']);
                        $lastGen = $pageStatus !== null && !empty($page['updated_at']) ? date('M j', strtotime($page['updated_at'])) : null;
                        // Read-only, free — just looks at what's already on
                        // disk (same check Generate/Refresh's own verify step
                        // runs), so a status badge can't quietly lie anymore:
                        // a page can say "Published" and still be missing its
                        // hero/area map/chart, which is the exact gap that
                        // kept costing a manual investigation every time.
                        $completenessWarnings = $page ? lpc_page_completeness_warnings($page) : [];
                    ?>
                        <td style="padding:6px 14px;">
                            <?php if ($pageStatus === null): ?>
                                <span class="lpc-badge lpc-badge-never">Not Created</span>
                            <?php elseif ($pageStatus === 'draft'): ?>
                                <span class="lpc-badge lpc-badge-never">Draft</span>
                            <?php elseif ($pageStatus === 'published'): ?>
                                <span class="lpc-badge lpc-badge-connected">Published</span>
                            <?php else: ?>
                                <span class="lpc-badge lpc-badge-stale">Needs Update</span>
                            <?php endif; ?>
                            <?php if ($completenessWarnings): ?>
                                <span class="lpc-badge lpc-badge-stale" title="<?= h(implode('; ', $completenessWarnings)) ?>">&#9888; <?= count($completenessWarnings) ?></span>
                            <?php endif; ?>
                        </td>
                        <td style="padding:6px 14px;">
                            <?php if ($pageLocked): ?><span title="Locked — Regenerate is blocked">&#128274; Locked</span><?php else: ?><span style="color:#d1d5db;">&mdash;</span><?php endif; ?>
                        </td>
                        <td style="padding:6px 14px;color:#9ca3af;font-size:.8rem;"><?= $lastGen ? h($lastGen) : '&mdash;' ?></td>
                        <td style="padding:6px 14px;color:#9ca3af;font-size:.8rem;"><?= $kwLine ?: '&mdash;' ?></td>
                        <td style="padding:6px 14px;font-size:.78rem;">
                            <?php
                            $liveCheck = $client['live_check'][$pageId] ?? null;
                            $liveAgo = $liveCheck ? date('M j g:ia', strtotime($liveCheck['checked_at'])) : null;
                            ?>
                            <?php if (!$liveCheck): ?>
                                <span style="color:#d1d5db;">Not checked</span>
                            <?php elseif ($liveCheck['stale']): ?>
                                <span class="lpc-badge lpc-badge-stale" title="<?= h(implode('; ', $liveCheck['issues'])) ?>">&#9888; Stale</span>
                            <?php else: ?>
                                <span class="lpc-badge lpc-badge-connected">&#10003; Current</span>
                            <?php endif; ?>
                            <?php if ($liveAgo): ?><div style="color:#9ca3af;font-size:.72rem;margin-top:2px;"><?= h($liveAgo) ?></div><?php endif; ?>
                        </td>
                        <td style="padding:6px 14px;white-space:nowrap;">
                            <?php if ($pageStatus === null): ?>
                                <a href="<?= h($editUrl) ?>" class="btn-sm-outline" style="font-size:.74rem;padding:3px 8px;">+ Create &amp; Edit</a>
                            <?php else: ?>
                                <a href="<?= h($editUrl) ?>" style="font-size:.76rem;">Edit</a>
                                <?php if ($pageStatus !== 'published'): ?>
                                <form method="post" action="client_save.php" style="display:inline;margin:0;">
                                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                                    <input type="hidden" name="section" value="target_cities">
                                    <input type="hidden" name="client_id" value="<?= h($client_id) ?>">
                                    <input type="hidden" name="tc_action" value="toggle_service">
                                    <input type="hidden" name="city_id" value="<?= h($tc['city_id']) ?>">
                                    <input type="hidden" name="service_id" value="<?= h($s['service_id']) ?>">
                                    <button type="submit" class="btn-sm-outline" style="font-size:.68rem;padding:0 5px;" title="Exclude this service from this city — the page file stays on disk" onclick="return confirm('Exclude this service from this city? The page stays on disk (re-adding brings it right back) but it drops off the matrix.');">&times; Exclude</button>
                                </form>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </table>
            </div>
            <?php endforeach; ?>
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
            <p style="color:#6b7280;font-size:.8rem;margin:10px 0;">These feed the AI content pipeline as generation guardrails every time a page is regenerated.</p>
            <button type="submit" class="btn-open">Save Content Profile</button>
        </form>

    <?php elseif ($tab === 'delivery'): ?>

        <p style="color:#6b7280;font-size:.85rem;">Onboarding a client takes 3 steps. Steps 4+ are ongoing controls, used occasionally.</p>

        <div class="card" style="margin-top:14px;">
            <strong style="font-size:.88rem;">1. Download the plugin and give the client their API key</strong>
            <p style="margin:8px 0;">
                <a href="lpc_plugin_download.php" class="btn-open">&#11015; Download Plugin (.zip)</a>
                <?php $pluginVersion = lpc_plugin_source_version(); ?>
                <?php if ($pluginVersion): ?>
                    <span style="color:#6b7280;font-size:.8rem;margin-left:8px;">v<?= h($pluginVersion) ?></span>
                <?php endif; ?>
            </p>
            <div style="display:flex;align-items:center;gap:10px;margin:8px 0;flex-wrap:wrap;">
                <p style="font-family:monospace;background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;padding:10px 14px;margin:0;word-break:break-all;flex:1;min-width:200px;"><?= h($apiKey) ?></p>
                <?php $statusDot = $status === 'Connected' ? '&#128994;' : ($status === 'Stale' ? '&#128993;' : '&#9898;'); ?>
                <span class="lpc-badge lpc-badge-<?= $badgeClass ?>" title="Live check against the delivery server's real last check-in"><?= $statusDot ?> <?= h($status) ?></span>
                <a href="?id=<?= h($client_id) ?>&tab=delivery" class="btn-sm-outline">&#128260; Check Connection</a>
            </div>
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
            <strong style="font-size:.88rem;">Link to the Locations page on their site</strong>
            <p style="color:#6b7280;font-size:.8rem;margin:4px 0 10px;">
                Without this, their location pages exist but are undiscoverable from the rest of their
                site. "Floating badge" (default) is a small fixed bottom-right pill that shows up on
                every page regardless of theme — works everywhere. "Nav menu" looks more native but only
                works on classic (non-block) themes with a menu already assigned to the primary
                location — verify it actually shows up after switching. "Off" if the client would rather
                add their own link by hand.
            </p>
            <form method="post" action="client_save.php" class="lpc-form-row">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <input type="hidden" name="section" value="delivery_settings">
                <input type="hidden" name="client_id" value="<?= h($client_id) ?>">
                <div class="form-group">
                    <label>Placement</label>
                    <select name="nav_link_placement">
                        <?php foreach (['footer' => 'Floating badge', 'nav' => 'Nav menu (primary)', 'off' => 'Off'] as $val => $lbl): ?>
                        <option value="<?= h($val) ?>" <?= $client['delivery']['nav_link_placement'] === $val ? 'selected' : '' ?>><?= h($lbl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group"><label>Link Text</label><input type="text" name="nav_link_label" value="<?= h($client['delivery']['nav_link_label']) ?>"></div>
                <div class="form-group">
                    <label>Refresh Interval (minutes)</label>
                    <input type="number" name="refresh_interval_minutes" min="5" step="1" style="width:100px;" value="<?= h($client['delivery']['refresh_interval_minutes'] ?? 360) ?>">
                </div>
                <button type="submit" class="btn-sm-outline">Save</button>
            </form>
            <p style="color:#6b7280;font-size:.8rem;margin:8px 0 0;">
                How long the plugin caches the manifest/pages before re-checking the delivery server —
                360 (6 hours) by default. Lower means fresher content shows up sooner on its own; higher
                means fewer check-ins hitting the delivery server. "Save &amp; Refresh now" in the
                plugin's own WP Settings page always bypasses this immediately, regardless of the value
                here.
            </p>
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
                <th style="padding:8px 12px;" title="Tap-to-call, directions, CTA, and contact-page button clicks — an intent signal, not a confirmed call or lead.">Tried to Contact</th>
            </tr>
            <?php
            $anyPublished = false;
            $clickTotals = lpc_fetch_click_summary($client_id);
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
                <td style="padding:8px 12px;color:#6b7280;"><?= h(lpc_click_summary_line($clickTotals, $pageId) ?: '—') ?></td>
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
