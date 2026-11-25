<?php
/** admin/pluscities_page_styling_save.php — save handler for admin/pluscities_page_styling.php's
 * Hero style picker. A CLIENT-level setting (block_styles.hero), not a per-page one — style never
 * varies by city or service in this engine, only content does. */
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
$back     = 'pluscities_page_styling.php?client_id=' . urlencode($clientId)
    . (!empty($_POST['page_id']) ? '&page_id=' . urlencode($_POST['page_id']) : '');
if (!pluscities_actor_can_access_client($clientId)) {
    header('Location: pluscities_clients.php?msg=error:Client+not+found'); exit;
}
$clients = pluscities_client_ids_for_current_actor();
$client  = $clients[$clientId];

$heroStyle = (string) ($_POST['hero_style'] ?? 'two_column');
if (!in_array($heroStyle, ['two_column', 'full_bleed'], true)) $heroStyle = 'two_column';

$client['block_styles'] = array_merge($client['block_styles'] ?? [], ['hero' => $heroStyle]);

// Bump site_rev — same signal pluscities_publish_page()/unpublish_page() use
// (includes/pluscities_lifecycle.php) to tell the delivery plugin's poll loop
// something changed and it needs to re-fetch.
$client['site_rev'] = (int) ($client['site_rev'] ?? 0) + 1;

pluscities_atomic_write_json(PLUSCITIES_CLIENTS_DIR . $clientId . '.json', $client);

$actor = lpc_current_actor_role() . ':' . (lpc_current_actor_identity()['name'] ?? 'unknown');
pluscities_log_event($actor, 'edit_block_style', 'client:' . $clientId, 'hero=' . $heroStyle);

header('Location: ' . $back . '&msg=success:Saved'); exit;
