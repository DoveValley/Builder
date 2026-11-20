<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

if (empty($_SESSION['admin_logged_in'])) { header('Location: login.php'); exit; }
if (lpc_current_actor_role() !== 'agency_admin') {
    header('Location: clients.php');
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: agency_branding.php'); exit; }

$token = $_POST['csrf_token'] ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
    header('Location: agency_branding.php?msg=error:Invalid+request+token');
    exit;
}

$agency_id = lpc_current_actor_agency_id();
$agency = lpc_load_agency($agency_id);
if (!$agency) {
    header('Location: clients.php?msg=error:Agency+not+found');
    exit;
}

// Self-service is deliberately scoped to ONLY the two visual branding
// fields — name, username, password, fee, spend cap and status stay
// Scott-only on agencies.php, same principle niche_profiles.php already
// applies to keep agencies from touching shared/billing-sensitive settings.
$agency['branding']['display_name'] = trim($_POST['display_name'] ?? '');
$agency['branding']['logo_url']     = sanitize_url(trim($_POST['logo_url'] ?? ''));

lpc_save_agency($agency_id, $agency);
header('Location: agency_branding.php?msg=success:Branding+saved');
exit;
