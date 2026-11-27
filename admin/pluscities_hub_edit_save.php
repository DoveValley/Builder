<?php
/** admin/pluscities_hub_edit_save.php — save handler for admin/pluscities_hub_edit.php. */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

if (!lpc_actor_is_authenticated() || lpc_current_actor_role() === 'end_client') {
    header('Location: login.php'); exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: pluscities_clients.php'); exit; }

$token = $_POST['csrf_token'] ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
    header('Location: pluscities_clients.php?msg=error:Invalid+request+token'); exit;
}

$clientId = trim((string) ($_POST['client_id'] ?? ''));
$back     = 'pluscities_hub_edit.php?client_id=' . urlencode($clientId);
if (!pluscities_actor_can_access_client($clientId)) {
    header('Location: pluscities_clients.php?msg=error:Client+not+found'); exit;
}
$clients = pluscities_client_ids_for_current_actor();
$client  = $clients[$clientId];

$cityFormat = (string) ($_POST['hub_city_format'] ?? 'simple');
if (!in_array($cityFormat, ['simple', 'compact', 'detailed', 'custom'], true)) $cityFormat = 'simple';

// enabled/pill_enabled arrive as '0'/'1' from a hidden+checkbox pair (every
// form on the page includes both, not just the Page Settings one) so an
// unchecked box is still distinguishable from "this form didn't touch it" —
// same ambiguity the city_service_page_settings topics-form bug hit before.
$client['hub_content'] = [
    'top_text'               => (string) ($_POST['top_text'] ?? ''),
    'footer_text'            => (string) ($_POST['footer_text'] ?? ''),
    'hub_city_format'        => $cityFormat,
    'hub_city_format_custom' => (string) ($_POST['hub_city_format_custom'] ?? ''),
    'enabled'                => ($_POST['enabled'] ?? '1') === '1',
    'pill_enabled'           => ($_POST['pill_enabled'] ?? '1') === '1',
    'pill_text'              => (string) ($_POST['pill_text'] ?? ''),
];

$client['url_base_path'] = trim((string) ($_POST['url_base_path'] ?? 'service-area')) ?: 'service-area';
$client['slug_pattern']  = trim((string) ($_POST['slug_pattern'] ?? ''));

// Bump site_rev — same signal pluscities_publish_page()/unpublish_page() use
// (includes/pluscities_lifecycle.php) to tell the delivery plugin's poll loop
// something changed and it needs to re-fetch.
$client['site_rev'] = (int) ($client['site_rev'] ?? 0) + 1;

pluscities_atomic_write_json(PLUSCITIES_CLIENTS_DIR . $clientId . '.json', $client);

$actor = lpc_current_actor_role() . ':' . (lpc_current_actor_identity()['name'] ?? 'unknown');
pluscities_log_event($actor, 'edit_hub_content', 'client:' . $clientId, '');

header('Location: ' . $back . '&msg=success:Saved'); exit;
