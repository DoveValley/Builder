<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

if (empty($_SESSION['admin_logged_in'])) { header('Location: login.php'); exit; }
// Same Scott-only gate as agencies.php — keep both in sync.
if (lpc_current_actor_role() !== 'super_admin') {
    header('Location: clients.php?msg=error:Agencies+are+managed+by+the+platform+only');
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: agencies.php'); exit; }

$token = $_POST['csrf_token'] ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
    header('Location: agencies.php?msg=error:Invalid+request+token');
    exit;
}

$action = $_POST['action'] ?? '';

if ($action === 'save') {
    $name = trim($_POST['name'] ?? '');
    if ($name === '') {
        header('Location: agencies.php?msg=error:Agency+name+is+required');
        exit;
    }
    $username = trim($_POST['username'] ?? '');
    if ($username === '') {
        header('Location: agencies.php?msg=error:Login+username+is+required');
        exit;
    }
    $password = (string) ($_POST['password'] ?? '');

    $existingId = trim($_POST['existing_agency_id'] ?? '');
    $isNew = $existingId === '';

    if ($isNew && $password === '') {
        header('Location: agencies.php?msg=error:Password+is+required+for+a+new+agency');
        exit;
    }

    // Editing keeps its id (relabeling shouldn't orphan existing clients'
    // agency_id references); creating derives a fresh slug, same pattern as
    // lpc_unique_client_id().
    $agency_id = $isNew ? lpc_unique_agency_id($name) : $existingId;
    if (!lpc_valid_agency_id($agency_id)) {
        header('Location: agencies.php?msg=error:Could+not+derive+a+valid+id+from+that+name');
        exit;
    }

    // A username must be unique across agencies — the login form looks one
    // up with no agency_id to disambiguate (lpc_find_agency_by_username()).
    $clash = lpc_find_agency_by_username($username);
    if ($clash && $clash['agency_id'] !== $agency_id) {
        header('Location: agencies.php?msg=error:That+username+is+already+in+use+by+another+agency');
        exit;
    }

    $agency = $isNew ? lpc_default_agency_data() : (lpc_load_agency($agency_id) ?? lpc_default_agency_data());
    $oldFee = (float) ($agency['billing']['monthly_fee_usd'] ?? 0);
    $newFee = (float) ($_POST['monthly_fee_usd'] ?? 0);
    $existingSubId = $agency['billing']['stripe_subscription_id'] ?? '';

    $agency['name']                     = $name;
    $agency['login']['username']       = $username;
    $agency['billing']['spend_cap_usd'] = (float) ($_POST['spend_cap_usd'] ?? 0);
    $agency['billing']['monthly_fee_usd'] = $newFee;
    $agency['branding']['display_name'] = trim($_POST['display_name'] ?? '');
    $agency['branding']['logo_url']     = sanitize_url(trim($_POST['logo_url'] ?? ''));

    // Creating this screen IS the approval — no external signup request to
    // separately review, so a brand-new agency goes straight to active.
    if ($isNew) {
        $agency['status']      = 'active';
        $agency['approved_at'] = gmdate('c');
    }

    $priceUpdateError = '';
    // Closes a gap this feature shipped with originally: if the fee changed
    // on an agency that already has a REAL active subscription, that
    // subscription's actual price needs updating too — not just the number
    // used to build the NEXT checkout link for an unpaid agency.
    if (!$isNew && $existingSubId !== '' && abs($newFee - $oldFee) > 0.001) {
        $r = stripe_update_subscription_price($existingSubId, $newFee);
        if (!$r['ok']) $priceUpdateError = $r['error'];
    }

    $ok = lpc_save_agency($agency_id, $agency);
    if ($ok && $password !== '') {
        lpc_set_agency_password($agency_id, $password);
    }
    $msg = $priceUpdateError !== ''
        ? 'error:Agency+saved,+but+updating+the+live+Stripe+price+failed:+' . urlencode($priceUpdateError)
        : 'success:Agency+saved';
    // A brand-new agency lands straight in its own Overview (same parity as
    // client creation's redirect into the new client's first page) — editing
    // an existing one returns to whichever tab's form was actually submitted
    // (Overview vs Billing both post here), defaulting to Overview if that
    // hidden field is somehow missing.
    $returnTo = $isNew ? 'agency_overview.php' : (in_array($_POST['return_to'] ?? '', ['agency_overview.php', 'agency_billing.php'], true) ? $_POST['return_to'] : 'agency_overview.php');
    header('Location: ' . $returnTo . '?agency_id=' . urlencode($agency_id) . '&msg=' . $msg);
    exit;
}

if ($action === 'toggle_status') {
    $agency_id = trim($_POST['agency_id'] ?? '');
    if ($agency_id === 'scotts-agency') {
        // Scott operates through this agency himself — suspending it would
        // lock him out of his own direct-sale clients. The button itself is
        // NOT hidden for this agency (no UI special-casing, 2026-11-25) --
        // this is the one real guard left, so it still needs to exist here.
        header('Location: agency_overview.php?agency_id=scotts-agency&msg=error:Cannot+suspend+Scotts+own+agency');
        exit;
    }
    $agency = lpc_load_agency($agency_id);
    if (!$agency) {
        header('Location: agencies.php?msg=error:Agency+not+found');
        exit;
    }
    $agency['status'] = $agency['status'] === 'suspended' ? 'active' : 'suspended';
    lpc_save_agency($agency_id, $agency);
    $msg = $agency['status'] === 'suspended' ? 'success:Agency+suspended' : 'success:Agency+reactivated';
    $returnTo = in_array($_POST['return_to'] ?? '', ['agency_overview.php', 'agency_billing.php'], true) ? $_POST['return_to'] : 'agency_overview.php';
    header('Location: ' . $returnTo . '?agency_id=' . urlencode($agency_id) . '&msg=' . $msg);
    exit;
}

if ($action === 'toggle_approval_gate') {
    $agency_id = trim($_POST['agency_id'] ?? '');
    $agency = lpc_load_agency($agency_id);
    if (!$agency) {
        header('Location: settings.php?msg=error:Agency+not+found');
        exit;
    }
    $agency['requires_approval_gate'] = empty($agency['requires_approval_gate']);
    lpc_save_agency($agency_id, $agency);
    $msg = $agency['requires_approval_gate'] ? 'success:Approval+gate+turned+on' : 'success:Approval+gate+turned+off';
    header('Location: settings.php?msg=' . $msg);
    exit;
}

if ($action === 'create_checkout') {
    $agency_id = trim($_POST['agency_id'] ?? '');
    $agency = lpc_load_agency($agency_id);
    if (!$agency) {
        header('Location: agencies.php?msg=error:Agency+not+found');
        exit;
    }
    $fee = (float) ($agency['billing']['monthly_fee_usd'] ?? 0);
    if ($fee <= 0) {
        header('Location: agency_billing.php?agency_id=' . urlencode($agency_id) . '&msg=error:Set+a+monthly+fee+before+creating+a+billing+link');
        exit;
    }
    $scheme  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $baseUrl = $scheme . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['SCRIPT_NAME']);
    $r = stripe_create_checkout_session(
        $agency,
        $fee,
        $baseUrl . '/agency_billing.php?agency_id=' . urlencode($agency_id) . '&checkout=success',
        $baseUrl . '/agency_billing.php?agency_id=' . urlencode($agency_id) . '&checkout=cancelled'
    );
    if (!$r['ok']) {
        header('Location: agency_billing.php?agency_id=' . urlencode($agency_id) . '&msg=error:' . urlencode($r['error']));
        exit;
    }
    $agency['billing']['last_checkout_url'] = $r['data']['url'];
    lpc_save_agency($agency_id, $agency);
    header('Location: agency_billing.php?agency_id=' . urlencode($agency_id) . '&msg=success:Billing+link+created');
    exit;
}

header('Location: agencies.php');
exit;
