<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/lpc_nav.php';
require_once __DIR__ . '/infra/lib/cities.php';      // infra_cities_init() — the 43,901-row cities table
require_once __DIR__ . '/infra/lib/keywords.php';    // infra_kw_fetch() — optional, spends API quota, opt-in only
require_once __DIR__ . '/../includes/multisite/geocode.php'; // ms_geocode_city(), ms_haversine_miles()

if (!lpc_actor_is_authenticated()) {
    header('Location: login.php');
    exit;
}
// Onboarding is an Agency Admin/Super Admin action — an End Client never
// creates a client record (themselves or anyone else's).
if (lpc_current_actor_role() === 'end_client') {
    header('Location: client_portal.php');
    exit;
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

/**
 * Nothing is written to disk until step 5 ("Review & Create") — all prior
 * steps accumulate into $_SESSION['lpc_wizard'] only. This is deliberate:
 * abandoning the wizard partway through (closing the tab on step 2, say)
 * must never leave an orphaned half-built client directory behind.
 */
if (empty($_SESSION['lpc_wizard']) || isset($_GET['restart'])) {
    $_SESSION['lpc_wizard'] = [
        'identity' => ['business_name' => '', 'domain' => '', 'industry_category' => '', 'contact_name' => '', 'contact_email' => '', 'contact_phone' => ''],
        'branding' => ['logo_url' => '', 'primary_color' => '', 'accent_color' => '', 'font' => ''],
        'niche_id' => '', // explicit "Which niche?" selection (step 1) — see lpc_create_client()
        'services' => [],
        'hubs'     => [],
        'target_cities' => [],
    ];
}
$w = &$_SESSION['lpc_wizard'];

$error = null;
if (!empty($_SESSION['lpc_wizard_err'])) {
    $error = $_SESSION['lpc_wizard_err'];
    unset($_SESSION['lpc_wizard_err']);
}
$notice = null;
if (!empty($_SESSION['lpc_wizard_notice'])) {
    $notice = $_SESSION['lpc_wizard_notice'];
    unset($_SESSION['lpc_wizard_notice']);
}
// Set by auto-detect (step 1) for step 3's "Add a hub office" form to pre-fill from —
// a suggestion to review, never written to the client record on its own.
$detectedHub = $_SESSION['lpc_wizard_detected'] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        $error = 'Invalid request token — please try again.';
    } else {
        $postedStep = (int) ($_POST['step'] ?? 1);
        $wizAction  = $_POST['wiz_action'] ?? 'next';

        if ($postedStep === 1 && $wizAction === 'next') {
            $name = trim($_POST['business_name'] ?? '');
            if ($name === '') {
                $error = 'Business name is required.';
            } else {
                $w['identity'] = [
                    'business_name'     => $name,
                    'domain'            => trim($_POST['domain'] ?? ''),
                    'industry_category' => trim($_POST['industry_category'] ?? ''),
                    'contact_name'      => trim($_POST['contact_name'] ?? ''),
                    'contact_email'     => trim($_POST['contact_email'] ?? ''),
                    'contact_phone'     => trim($_POST['contact_phone'] ?? ''),
                ];
                // Validated against the real list, not trusted blindly from
                // POST — an empty/unrecognized value just means "no niche
                // template," not an error (a client can still exist without
                // one, same as today's auto-match falling back to "custom").
                $submittedNiche = trim($_POST['niche_id'] ?? '');
                $w['niche_id'] = in_array($submittedNiche, lpc_list_niche_profiles(), true) ? $submittedNiche : '';
                header('Location: client_wizard.php?step=2');
                exit;
            }
        } elseif ($postedStep === 1 && $wizAction === 'auto_detect') {
            $domain = trim($_POST['domain'] ?? '');
            if ($domain === '') {
                $error = 'Enter a domain first, then click Auto-detect.';
            } else {
                // Preserve whatever the operator already typed on this step —
                // auto-detect only fills in what's still blank, never overwrites.
                $w['identity']['domain'] = $domain;
                foreach (['business_name', 'industry_category', 'contact_name', 'contact_email', 'contact_phone'] as $f) {
                    if (trim($_POST[$f] ?? '') !== '') $w['identity'][$f] = trim($_POST[$f]);
                }

                $detected = lpc_detect_site_info($domain);
                if (!$detected['ok']) {
                    $error = 'Could not reach or read that site: ' . ($detected['error'] ?? 'unknown error') . ' — fill in the fields manually instead.';
                } else {
                    $found = [];
                    if ($w['identity']['business_name'] === '' && $detected['business_name']) {
                        $w['identity']['business_name'] = $detected['business_name'];
                        $found[] = 'business name';
                    }
                    if ($w['identity']['contact_phone'] === '' && $detected['phone']) {
                        $w['identity']['contact_phone'] = $detected['phone'];
                        $found[] = 'phone';
                    }
                    foreach (['logo_url' => 'logo', 'primary_color' => 'primary color', 'accent_color' => 'accent color', 'font' => 'font'] as $bf => $label) {
                        if (($w['branding'][$bf] ?? '') === '' && !empty($detected[$bf])) {
                            $w['branding'][$bf] = $detected[$bf];
                            $found[] = $label;
                        }
                    }
                    if (!empty($detected['address']['city'])) {
                        $_SESSION['lpc_wizard_detected'] = ['phone' => $detected['phone'], 'address' => $detected['address']];
                        $found[] = 'hub city/address (pre-filled on step 3)';
                    }
                    $_SESSION['lpc_wizard_notice'] = $found
                        ? 'Auto-detected from the site: ' . implode(', ', $found) . '. Double-check before continuing — detection is best-effort, not guaranteed.'
                        : 'Connected to the site, but could not confidently detect anything useful — fill in the fields manually.';
                }
            }
            if ($error) { $_SESSION['lpc_wizard_err'] = $error; }
            header('Location: client_wizard.php?step=1');
            exit;
        } elseif ($postedStep === 2 && $wizAction === 'next') {
            $w['branding'] = [
                'logo_url'      => sanitize_url(trim($_POST['logo_url'] ?? '')),
                'primary_color' => trim($_POST['primary_color'] ?? ''),
                'accent_color'  => trim($_POST['accent_color'] ?? ''),
                'font'          => trim($_POST['font'] ?? ''),
            ];
            header('Location: client_wizard.php?step=3');
            exit;
        } elseif ($postedStep === 3 && $wizAction === 'add_service') {
            $label = trim($_POST['service_label'] ?? '');
            if ($label !== '') {
                $w['services'][] = ['service_id' => slugify($label), 'label' => $label];
            }
            header('Location: client_wizard.php?step=3');
            exit;
        } elseif ($postedStep === 3 && $wizAction === 'add_niche_services') {
            // Checkbox selection, never a blind wholesale copy — Scott's
            // explicit ask: pick which of the niche's default services
            // actually apply, plus the regular add-service form right below
            // still works for anything client-specific beyond the niche list.
            $niche = $w['niche_id'] !== '' ? lpc_load_niche_profile($w['niche_id']) : null;
            $selected = (array) ($_POST['niche_svc'] ?? []);
            if ($niche) {
                $existingIds = array_column($w['services'], 'service_id');
                foreach ($niche['default_services'] as $ds) {
                    if (!in_array($ds['label'], $selected, true)) continue;
                    $sid = slugify($ds['label']);
                    if ($sid === '' || in_array($sid, $existingIds, true)) continue;
                    $w['services'][] = ['service_id' => $sid, 'label' => $ds['label'], 'keyword_pattern' => $ds['keyword_pattern'] ?? ''];
                    $existingIds[] = $sid;
                }
            }
            header('Location: client_wizard.php?step=3');
            exit;
        } elseif ($postedStep === 3 && $wizAction === 'remove_service') {
            $sid = $_POST['service_id'] ?? '';
            $w['services'] = array_values(array_filter($w['services'], fn($s) => $s['service_id'] !== $sid));
            header('Location: client_wizard.php?step=3');
            exit;
        } elseif ($postedStep === 3 && $wizAction === 'add_hub') {
            $name = trim($_POST['name'] ?? '');
            $city = trim($_POST['city'] ?? '');
            $state = trim($_POST['state'] ?? '');
            if ($name === '' || $city === '' || $state === '') {
                $error = 'Hub name, city, and state are required.';
            } else {
                $street = trim($_POST['street'] ?? '');
                $zip    = trim($_POST['zip'] ?? '');
                // Only a city-level geocoder exists in this codebase (no street-address
                // lookup) — good enough for radius-based target-city suggestions, which
                // is all this feeds; not precise enough for anything claiming an exact
                // pin, which this feature never does.
                $geo = ['lat' => null, 'lng' => null];
                $g = ms_geocode_city($city, $state, $state);
                if (is_array($g) && isset($g['lat'], $g['lng'])) {
                    $geo = ['lat' => (float) $g['lat'], 'lng' => (float) $g['lng']];
                }
                $hubId = slugify($name) ?: ('hub-' . (count($w['hubs']) + 1));
                $w['hubs'][] = [
                    'hub_id'  => $hubId,
                    'name'    => $name,
                    'address' => ['street' => $street, 'city' => $city, 'state' => $state, 'zip' => $zip],
                    'phone'   => trim($_POST['phone'] ?? ''),
                    'service_radius_miles' => (int) ($_POST['service_radius_miles'] ?? 45),
                    'geo'     => $geo,
                ];
            }
            header('Location: client_wizard.php?step=3');
            exit;
        } elseif ($postedStep === 3 && $wizAction === 'remove_hub') {
            $hid = $_POST['hub_id'] ?? '';
            $w['hubs'] = array_values(array_filter($w['hubs'], fn($h) => $h['hub_id'] !== $hid));
            header('Location: client_wizard.php?step=3');
            exit;
        } elseif ($postedStep === 3 && $wizAction === 'next') {
            if (empty($w['hubs'])) {
                $error = 'Add at least one hub office before continuing.';
            } else {
                header('Location: client_wizard.php?step=4');
                exit;
            }
        } elseif ($postedStep === 4 && $wizAction === 'add_target_city') {
            $cityId = trim($_POST['city_id'] ?? '');
            $city   = trim($_POST['city'] ?? '');
            $state  = trim($_POST['state'] ?? '');
            $hubId  = trim($_POST['served_by_hub'] ?? '');
            if ($cityId !== '' && $city !== '' && $hubId !== '') {
                $exists = false;
                foreach ($w['target_cities'] as $tc) { if ($tc['city_id'] === $cityId) $exists = true; }
                if (!$exists) {
                    $w['target_cities'][] = [
                        'city_id' => $cityId, 'city' => $city, 'state' => $state,
                        'served_by_hub' => $hubId,
                        'services' => array_column($w['services'], 'service_id'),
                    ];
                }
            }
            header('Location: client_wizard.php?step=4');
            exit;
        } elseif ($postedStep === 4 && $wizAction === 'add_selected_cities') {
            $hubId = trim($_POST['hub_id'] ?? '');
            foreach ((array) ($_POST['selected'] ?? []) as $packed) {
                [$cityId, $city, $state] = array_pad(explode('|', $packed, 3), 3, '');
                if ($cityId === '') continue;
                $exists = false;
                foreach ($w['target_cities'] as $tc) { if ($tc['city_id'] === $cityId) $exists = true; }
                if (!$exists) {
                    $w['target_cities'][] = [
                        'city_id' => $cityId, 'city' => $city, 'state' => $state,
                        'served_by_hub' => $hubId,
                        'services' => array_column($w['services'], 'service_id'),
                    ];
                }
            }
            header('Location: client_wizard.php?step=4');
            exit;
        } elseif ($postedStep === 4 && $wizAction === 'remove_target_city') {
            $cid = $_POST['city_id'] ?? '';
            $w['target_cities'] = array_values(array_filter($w['target_cities'], fn($tc) => $tc['city_id'] !== $cid));
            header('Location: client_wizard.php?step=4');
            exit;
        } elseif ($postedStep === 4 && $wizAction === 'fetch_keywords') {
            $hubId = trim($_POST['hub_id'] ?? '');
            $hub = null;
            foreach ($w['hubs'] as $h) { if ($h['hub_id'] === $hubId) $hub = $h; }
            if ($hub && infra_kw_has_creds('dataforseo')) {
                $candidates = lpc_city_candidates($hub, array_column($w['target_cities'], 'city_id'));
                $serviceLabel = $w['services'][0]['label'] ?? 'services';
                $phraseByCityId = [];
                foreach ($candidates as $c) {
                    // infra_kw_phrase(), not a hand-built string — DataForSEO
                    // rejects a literal comma in the keyword text (found
                    // 2026-11-15 testing the per-service version of this same
                    // call; this city-level call had the identical bug).
                    $phraseByCityId[$c['city_id']] = infra_kw_phrase($serviceLabel . ' in {city} {state}', ['city' => $c['city'], 'state' => $c['state']]);
                }
                $result = infra_kw_fetch('dataforseo', array_values($phraseByCityId));
                if ($result['ok']) {
                    $byPhrase = $result['rows'];
                    $kwData = [];
                    foreach ($phraseByCityId as $cid => $phrase) {
                        // infra_kw_dfs_fetch() keys rows by strtolower($keyword) —
                        // a case-sensitive lookup always silently missed (found
                        // 2026-11-15, see landing_page_clients.php's identical fix).
                        $key = strtolower($phrase);
                        if (isset($byPhrase[$key])) $kwData[$cid] = $byPhrase[$key];
                    }
                    $_SESSION['lpc_wizard_kw'][$hubId] = $kwData;
                } else {
                    $error = 'Keyword lookup failed: ' . ($result['msg'] ?? 'unknown error');
                }
            } else {
                $error = 'No DataForSEO credentials configured — showing population only.';
            }
            if ($error) { $_SESSION['lpc_wizard_err'] = $error; }
            header('Location: client_wizard.php?step=4');
            exit;
        } elseif ($postedStep === 4 && $wizAction === 'next') {
            header('Location: client_wizard.php?step=5');
            exit;
        } elseif ($postedStep === 5 && $wizAction === 'create') {
            $client_id = lpc_create_client($w['identity'], $w['niche_id']);
            if (!$client_id) {
                $error = 'Could not create client — check the business name on step 1.';
            } else {
                $client = lpc_load_client($client_id);
                // Freshly created this same request — nothing to check ownership
                // against yet, so load it unscoped, but stamp the OWNER now: an
                // Agency Admin's new client belongs to THEIR agency, not whatever
                // lpc_default_client_data()'s generic default happens to be.
                if (lpc_current_actor_role() === 'agency_admin') {
                    $client['agency_id'] = lpc_current_actor_agency_id();
                }
                $client['branding']      = $w['branding'];
                $client['services']      = $w['services'];
                $client['hubs']          = $w['hubs'];
                $client['target_cities'] = $w['target_cities'];
                lpc_save_client($client_id, $client);
                unset($_SESSION['lpc_wizard']);

                // Real AI cost, one call per confirmed city/service — the
                // Step 5 button's own confirm() already warned about this
                // before the request was even sent.
                set_time_limit(300);
                $genResult = lpc_generate_drafts_for_client($client_id);

                $msg = 'success:Client+created%2C+' . $genResult['generated'] . '+draft(s)+generated';
                header('Location: client.php?id=' . urlencode($client_id) . '&msg=' . $msg);
                exit;
            }
        }
    }
}

$step = (int) ($_GET['step'] ?? 1);
if ($step < 1 || $step > 5) $step = 1;
// Enforce linear progress — don't let a direct URL jump skip required data.
if ($step >= 2 && $w['identity']['business_name'] === '') $step = 1;
if ($step >= 4 && empty($w['hubs'])) $step = 3;

$stepLabels = [1 => 'Identity', 2 => 'Branding', 3 => 'Hub Cities & Services', 4 => 'Target Cities', 5 => 'Review & Create'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>New Client — <?= h(SITE_TITLE) ?></title>
<link rel="stylesheet" href="../assets/css/style.css">
<?php $_adminCssMtime = css_minify_to(__DIR__ . '/../assets/css/admin.src.css', __DIR__ . '/../assets/css/admin.css'); ?>
<link rel="stylesheet" href="../assets/css/admin.css?v=<?= (int) $_adminCssMtime ?>">
<style>
.lpc-wrap      { max-width: 760px; margin: 0 auto; padding: 32px 24px 64px; }
.lpc-steps     { display:flex; gap:6px; margin-bottom:28px; }
.lpc-step-pill { flex:1; text-align:center; padding:8px 6px; border-radius:6px; font-size:.76rem; font-weight:600; background:#f1f5f9; color:#94a3b8; }
.lpc-step-pill.done   { background:#dcfce7; color:#166534; }
.lpc-step-pill.active { background:#2563eb; color:#fff; }
.lpc-form-row  { display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end; margin-top:14px; }
.lpc-form-row .form-group { margin:0; flex:1; min-width:150px; }
.lpc-form-row label { font-size:.82rem; font-weight:600; color:#374151; display:block; margin-bottom:4px; }
.lpc-form-row input, .lpc-form-row select { width:100%; padding:8px 10px; border:1px solid #d1d5db; border-radius:6px; font-size:.85rem; }
.lpc-row       { background:#fff; border:1px solid #e5e7eb; border-radius:8px; padding:12px 16px; margin-bottom:8px; display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; }
.lpc-row .meta { font-size:.8rem; color:#6b7280; }
.lpc-actions   { display:flex; justify-content:space-between; margin-top:28px; }
</style>
</head>
<body class="admin-body">
<div style="background:#1e293b; color:#fff; padding:14px 24px; display:flex; align-items:center; justify-content:space-between;">
    <?php lpc_render_actor_branding(); ?>
    <a href="clients.php" style="color:#94a3b8; font-size:.82rem; text-decoration:none;">Cancel</a>
</div>

<div class="lpc-wrap">

    <?php lpc_render_nav('new_client'); ?>

    <h1 style="font-size:1.3rem;">New Landing Page Client</h1>

    <div class="lpc-steps">
        <?php foreach ($stepLabels as $n => $label): ?>
            <div class="lpc-step-pill <?= $n === $step ? 'active' : ($n < $step ? 'done' : '') ?>"><?= $n ?>. <?= h($label) ?></div>
        <?php endforeach; ?>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-error"><?= h($error) ?></div>
    <?php endif; ?>
    <?php if ($notice): ?>
        <div class="alert alert-success"><?= h($notice) ?></div>
    <?php endif; ?>

    <?php if ($step === 1): ?>

        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="step" value="1">
            <div class="lpc-form-row">
                <div class="form-group"><label>Business Name *</label><input type="text" name="business_name" required value="<?= h($w['identity']['business_name']) ?>"></div>
                <div class="form-group" style="display:flex; gap:8px; align-items:flex-end;">
                    <div style="flex:1;"><label>Domain</label><input type="text" name="domain" placeholder="e.g. mrrestore.com" value="<?= h($w['identity']['domain']) ?>"></div>
                    <button type="submit" name="wiz_action" value="auto_detect" formnovalidate class="btn-sm-outline" title="Fetch the live site and pre-fill what we can — business name, logo, colors, font, phone, hub city. Always double-check the results.">&#128269; Auto-detect</button>
                </div>
            </div>
            <p style="color:#6b7280;font-size:.78rem;margin:-4px 0 0;">Fill these in by hand, or enter the domain and click Auto-detect to have us pull what we can from the live site first — either way, review before moving on.</p>
            <div class="lpc-form-row">
                <div class="form-group"><label>Industry Category</label><input type="text" name="industry_category" placeholder="e.g. restoration" value="<?= h($w['identity']['industry_category']) ?>"></div>
                <?php
                $nicheOptions = [];
                foreach (lpc_list_niche_profiles() as $nid) {
                    $np = lpc_load_niche_profile($nid);
                    if ($np) $nicheOptions[$nid] = $np['label'] ?: $nid;
                }
                $selectedNiche = $w['niche_id'] !== '' ? $w['niche_id'] : lpc_match_niche_profile($w['identity']['industry_category']);
                if (strpos($selectedNiche, 'niche:') === 0) $selectedNiche = substr($selectedNiche, 6);
                ?>
                <div class="form-group">
                    <label>Niche <span style="font-weight:400;color:#6b7280;font-size:.78rem;">(pulls in that niche's content guardrails + a suggested service list in step 3)</span></label>
                    <select name="niche_id">
                        <option value="">— None / Custom —</option>
                        <?php foreach ($nicheOptions as $nid => $label): ?>
                            <option value="<?= h($nid) ?>" <?= $selectedNiche === $nid ? 'selected' : '' ?>><?= h($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group"><label>Contact Name</label><input type="text" name="contact_name" value="<?= h($w['identity']['contact_name']) ?>"></div>
            </div>
            <div class="lpc-form-row">
                <div class="form-group"><label>Contact Email</label><input type="email" name="contact_email" value="<?= h($w['identity']['contact_email']) ?>"></div>
                <div class="form-group"><label>Contact Phone</label><input type="text" name="contact_phone" value="<?= h($w['identity']['contact_phone']) ?>"></div>
            </div>
            <div class="lpc-actions">
                <span></span>
                <button type="submit" name="wiz_action" value="next" class="btn-open">Next &rarr;</button>
            </div>
        </form>

    <?php elseif ($step === 2): ?>

        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="step" value="2">
            <input type="hidden" name="wiz_action" value="next">
            <p style="color:#6b7280;font-size:.85rem;">For your own admin organization only — production delivery inherits the client's real WordPress theme automatically, so none of this affects what visitors see.</p>
            <div class="lpc-form-row">
                <div class="form-group"><label>Logo URL</label><input type="text" name="logo_url" value="<?= h($w['branding']['logo_url']) ?>"></div>
                <div class="form-group"><label>Font</label><input type="text" name="font" value="<?= h($w['branding']['font']) ?>"></div>
            </div>
            <div class="lpc-form-row">
                <div class="form-group"><label>Primary Color</label><input type="color" name="primary_color" value="<?= h($w['branding']['primary_color'] ?: '#2563eb') ?>"></div>
                <div class="form-group"><label>Accent Color</label><input type="color" name="accent_color" value="<?= h($w['branding']['accent_color'] ?: '#f5a623') ?>"></div>
            </div>
            <div class="lpc-actions">
                <a href="client_wizard.php?step=1" class="btn-sm-outline">&larr; Back</a>
                <button type="submit" class="btn-open">Next &rarr;</button>
            </div>
        </form>

    <?php elseif ($step === 3): ?>

        <h3>Services Offered</h3>
        <p style="color:#6b7280;font-size:.85rem;margin-top:0;">The full service list this client offers — target cities in the next steps default to all of these, with per-city overrides later.</p>
        <?php foreach ($w['services'] as $s): ?>
            <div class="lpc-row">
                <strong><?= h($s['label']) ?></strong>
                <form method="post" style="margin:0;">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                    <input type="hidden" name="step" value="3">
                    <input type="hidden" name="wiz_action" value="remove_service">
                    <input type="hidden" name="service_id" value="<?= h($s['service_id']) ?>">
                    <button type="submit" class="btn-sm-danger">Remove</button>
                </form>
            </div>
        <?php endforeach; ?>

        <?php
        $wizNiche = $w['niche_id'] !== '' ? lpc_load_niche_profile($w['niche_id']) : null;
        $existingLabels = array_column($w['services'], 'label');
        $suggestable = $wizNiche ? array_filter($wizNiche['default_services'], fn($ds) => !in_array($ds['label'], $existingLabels, true)) : [];
        ?>
        <?php if ($suggestable): ?>
        <form method="post" style="margin-top:14px;">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="step" value="3">
            <input type="hidden" name="wiz_action" value="add_niche_services">
            <strong style="font-size:.88rem;">Suggested Services — <?= h($wizNiche['label']) ?></strong>
            <p style="color:#6b7280;font-size:.8rem;margin:4px 0 8px;">Check the ones that apply — nothing is added automatically.</p>
            <?php foreach ($suggestable as $ds): ?>
                <label style="display:block;font-size:.85rem;font-weight:400;margin-bottom:4px;">
                    <input type="checkbox" name="niche_svc[]" value="<?= h($ds['label']) ?>" checked> <?= h($ds['label']) ?>
                </label>
            <?php endforeach; ?>
            <button type="submit" class="btn-sm-outline" style="margin-top:6px;">+ Add Selected</button>
        </form>
        <?php endif; ?>

        <form method="post" class="lpc-form-row" style="margin-top:14px;">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="step" value="3">
            <input type="hidden" name="wiz_action" value="add_service">
            <div class="form-group"><label>Service</label><input type="text" name="service_label" placeholder="e.g. Water Damage Restoration"></div>
            <button type="submit" class="btn-sm-outline">+ Add Service</button>
        </form>

        <h3 style="margin-top:28px;">Hub Offices</h3>
        <p style="color:#6b7280;font-size:.85rem;margin-top:0;">The business's real offices — feeds the LocalBusiness schema and the target-city suggestions in the next step.</p>
        <?php foreach ($w['hubs'] as $hub): ?>
            <div class="lpc-row">
                <div>
                    <strong><?= h($hub['name']) ?></strong>
                    <div class="meta">
                        <?= h($hub['address']['city']) ?>, <?= h($hub['address']['state']) ?> &middot; <?= h($hub['phone']) ?>
                        &middot; <?= (int) $hub['service_radius_miles'] ?> mi radius
                        <?php if ($hub['geo']['lat'] !== null): ?> &middot; city-center geocoded &#10003;<?php else: ?> &middot; <span style="color:#b45309;">not geocoded — add target cities manually in the next step</span><?php endif; ?>
                    </div>
                </div>
                <form method="post" style="margin:0;" onsubmit="return confirm('Remove this hub?');">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                    <input type="hidden" name="step" value="3">
                    <input type="hidden" name="wiz_action" value="remove_hub">
                    <input type="hidden" name="hub_id" value="<?= h($hub['hub_id']) ?>">
                    <button type="submit" class="btn-sm-danger">Remove</button>
                </form>
            </div>
        <?php endforeach; ?>

        <form method="post" class="card" style="margin-top:10px;">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="step" value="3">
            <input type="hidden" name="wiz_action" value="add_hub">
            <strong style="font-size:.88rem;">Add a hub office</strong>
            <?php if ($detectedHub): ?>
                <p style="color:#166534;font-size:.78rem;">Pre-filled from the auto-detected site — double-check before adding.</p>
            <?php endif; ?>
            <div class="lpc-form-row">
                <div class="form-group"><label>Name *</label><input type="text" name="name" required></div>
                <div class="form-group"><label>Street</label><input type="text" name="street" value="<?= h($detectedHub['address']['street'] ?? '') ?>"></div>
                <div class="form-group"><label>City *</label><input type="text" name="city" required value="<?= h($detectedHub['address']['city'] ?? '') ?>"></div>
                <div class="form-group"><label>State *</label><input type="text" name="state" required value="<?= h($detectedHub['address']['state'] ?? '') ?>"></div>
                <div class="form-group"><label>Zip</label><input type="text" name="zip" value="<?= h($detectedHub['address']['zip'] ?? '') ?>"></div>
            </div>
            <div class="lpc-form-row">
                <div class="form-group"><label>Phone</label><input type="text" name="phone" value="<?= h($detectedHub['phone'] ?? '') ?>"></div>
                <div class="form-group"><label>Service Radius (mi)</label><input type="number" name="service_radius_miles" value="45"></div>
                <button type="submit" class="btn-open">Add Hub</button>
            </div>
        </form>

        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="step" value="3">
            <input type="hidden" name="wiz_action" value="next">
            <div class="lpc-actions">
                <a href="client_wizard.php?step=2" class="btn-sm-outline">&larr; Back</a>
                <button type="submit" class="btn-open">Next &rarr;</button>
            </div>
        </form>

    <?php elseif ($step === 4): ?>

        <h3>Target Cities Added So Far</h3>
        <?php if (empty($w['target_cities'])): ?>
            <p style="color:#6b7280;font-size:.85rem;">None yet — pick some from the suggestions below, per hub.</p>
        <?php else: foreach ($w['target_cities'] as $tc): ?>
            <div class="lpc-row">
                <span><?= h($tc['city']) ?>, <?= h($tc['state']) ?> <span class="meta">&middot; served by <?= h($tc['served_by_hub']) ?></span></span>
                <form method="post" style="margin:0;">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                    <input type="hidden" name="step" value="4">
                    <input type="hidden" name="wiz_action" value="remove_target_city">
                    <input type="hidden" name="city_id" value="<?= h($tc['city_id']) ?>">
                    <button type="submit" class="btn-sm-danger">Remove</button>
                </form>
            </div>
        <?php endforeach; endif; ?>

        <?php foreach ($w['hubs'] as $hub): ?>
            <h3 style="margin-top:28px;">Suggestions near <?= h($hub['name']) ?> (<?= (int) $hub['service_radius_miles'] ?> mi)</h3>

            <?php if ($hub['geo']['lat'] === null): ?>
                <p style="color:#b45309;font-size:.85rem;">This hub wasn't geocoded, so there are no automatic suggestions. Add a target city manually:</p>
                <form method="post" class="lpc-form-row">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                    <input type="hidden" name="step" value="4">
                    <input type="hidden" name="wiz_action" value="add_target_city">
                    <input type="hidden" name="served_by_hub" value="<?= h($hub['hub_id']) ?>">
                    <div class="form-group"><label>City</label><input type="text" name="city" id="manual-city-<?= h($hub['hub_id']) ?>" oninput="document.getElementById('manual-cid-<?= h($hub['hub_id']) ?>').value=this.value.toLowerCase().replace(/[^a-z0-9]+/g,'-')+'-'+document.getElementById('manual-state-<?= h($hub['hub_id']) ?>').value.toLowerCase()"></div>
                    <div class="form-group"><label>State</label><input type="text" name="state" id="manual-state-<?= h($hub['hub_id']) ?>"></div>
                    <input type="hidden" name="city_id" id="manual-cid-<?= h($hub['hub_id']) ?>">
                    <button type="submit" class="btn-sm-outline">Add</button>
                </form>
                <?php continue; ?>
            <?php endif; ?>

            <?php
            $candidates = lpc_city_candidates($hub, array_column($w['target_cities'], 'city_id'));
            $kwData = $_SESSION['lpc_wizard_kw'][$hub['hub_id']] ?? [];
            ?>

            <?php if (empty($candidates)): ?>
                <p style="color:#6b7280;font-size:.85rem;">No candidate cities found within <?= (int) $hub['service_radius_miles'] ?> miles.</p>
            <?php else: ?>

                <?php if (infra_kw_has_creds('dataforseo')): ?>
                <form method="post" style="margin-bottom:8px;">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                    <input type="hidden" name="step" value="4">
                    <input type="hidden" name="wiz_action" value="fetch_keywords">
                    <input type="hidden" name="hub_id" value="<?= h($hub['hub_id']) ?>">
                    <button type="submit" class="btn-sm-outline" onclick="return confirm('This calls DataForSEO and spends real API quota/cost for ' + <?= (int) count($candidates) ?> + ' cities. Continue?');">&#128176; Fetch Keyword Data (real cost)</button>
                </form>
                <?php endif; ?>

                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                    <input type="hidden" name="step" value="4">
                    <input type="hidden" name="wiz_action" value="add_selected_cities">
                    <input type="hidden" name="hub_id" value="<?= h($hub['hub_id']) ?>">
                    <table class="lpc-table" style="width:100%;border-collapse:collapse;margin-bottom:10px;">
                        <tr style="text-align:left;font-size:.74rem;color:#6b7280;">
                            <th></th><th>City</th><th>Pop.</th><th>Dist.</th>
                            <?php if ($kwData): ?><th>Volume</th><th>CPC</th><th>KD</th><?php endif; ?>
                        </tr>
                        <?php foreach ($candidates as $i => $c): $kw = $kwData[$c['city_id']] ?? null; ?>
                        <tr style="border-top:1px solid #f1f5f9;font-size:.85rem;">
                            <td><input type="checkbox" name="selected[]" value="<?= h($c['city_id'] . '|' . $c['city'] . '|' . $c['state']) ?>" <?= $i < 10 ? 'checked' : '' ?>></td>
                            <td><?= h($c['city']) ?>, <?= h($c['state']) ?></td>
                            <td><?= number_format($c['population']) ?></td>
                            <td><?= $c['distance'] ?> mi</td>
                            <?php if ($kwData): ?>
                                <td><?= $kw ? number_format((int) ($kw['volume'] ?? 0)) : '—' ?></td>
                                <td><?= $kw ? '$' . number_format((float) ($kw['cpc'] ?? 0), 2) : '—' ?></td>
                                <td><?= $kw ? (int) ($kw['kd'] ?? 0) : '—' ?></td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                    </table>
                    <button type="submit" class="btn-open">Add Selected as Target Cities</button>
                </form>

            <?php endif; ?>
        <?php endforeach; ?>

        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="step" value="4">
            <input type="hidden" name="wiz_action" value="next">
            <div class="lpc-actions">
                <a href="client_wizard.php?step=3" class="btn-sm-outline">&larr; Back</a>
                <button type="submit" class="btn-open">Next &rarr;</button>
            </div>
        </form>

    <?php elseif ($step === 5): ?>

        <h3>Review</h3>
        <div class="card">
            <strong><?= h($w['identity']['business_name']) ?></strong>
            <p style="color:#6b7280;font-size:.85rem;"><?= h($w['identity']['domain']) ?: 'No domain' ?> &middot; <?= h($w['identity']['industry_category']) ?: 'No category' ?></p>
            <p style="font-size:.85rem;"><?= count($w['hubs']) ?> hub office(s) &middot; <?= count($w['services']) ?> service(s) &middot; <?= count($w['target_cities']) ?> target location(s)</p>
        </div>
        <p style="color:#6b7280;font-size:.82rem;">Creating this client immediately generates a real AI draft for every confirmed city/service combination (real cost per page, may take a minute for many pages).</p>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="step" value="5">
            <input type="hidden" name="wiz_action" value="create">
            <div class="lpc-actions">
                <a href="client_wizard.php?step=4" class="btn-sm-outline">&larr; Back</a>
                <button type="submit" class="btn-open" onclick="return confirm('Create this client and generate real AI drafts for all confirmed target cities/services? This spends real API cost.');">Create Client</button>
            </div>
        </form>

    <?php endif; ?>

</div>
</body>
</html>
