<?php
/** admin/pluscities_page_add_save.php — save handler for admin/pluscities_page_add.php. */
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
if (!pluscities_actor_can_access_client($clientId)) {
    header('Location: pluscities_clients.php?msg=error:Client+not+found'); exit;
}
$clients = pluscities_client_ids_for_current_actor();
$client  = $clients[$clientId];

$niche = pluscities_niche_resolved($client['niche_id'] ?? '');
if (!$niche) {
    header('Location: pluscities_page_add.php?client_id=' . urlencode($clientId) . '&msg=error:Niche+has+no+real+blueprint'); exit;
}

$cityId    = trim((string) ($_POST['city_id'] ?? ''));
$serviceKey = trim((string) ($_POST['service_key'] ?? ''));
if ($cityId === '' || $serviceKey === '') {
    header('Location: pluscities_page_add.php?client_id=' . urlencode($clientId) . '&msg=error:Missing+city+or+service'); exit;
}

$actor = lpc_current_actor_role() . ':' . (lpc_current_actor_identity()['name'] ?? 'unknown');

// Same shared whitelist-and-cap validator the client-creation flow uses —
// one real place this logic lives, not copy-pasted per form.
$picks = pluscities_validate_block_picks(
    $niche,
    (array) ($_POST['city_block_picks'] ?? []),
    (array) ($_POST['service_block_picks'] ?? [])
);

$result = pluscities_create_page($clientId, $cityId, $serviceKey, $picks['city'], $picks['service'], $actor);
if (!$result['ok']) {
    header('Location: pluscities_page_add.php?client_id=' . urlencode($clientId) . '&msg=' . urlencode('error:' . $result['msg'])); exit;
}

// Target-city tracking lives on the client record itself (target_cities[]) —
// add this city if it's genuinely new, same shape client creation already
// writes (city_id, drive_minutes, confirmed_by_client).
$alreadyTracked = false;
foreach ($client['target_cities'] ?? [] as $tc) {
    if (($tc['city_id'] ?? '') === $cityId) { $alreadyTracked = true; break; }
}
if (!$alreadyTracked) {
    $client['target_cities'][] = [
        'city_id' => $cityId,
        'drive_minutes' => (int) ($_POST['drive_minutes'] ?? 15),
        'confirmed_by_client' => false,
    ];
    pluscities_atomic_write_json(PLUSCITIES_CLIENTS_DIR . $clientId . '.json', $client);
}

header('Location: pluscities_page.php?page_id=' . urlencode($result['page_id']) . '&msg=success:' . urlencode('Page created')); exit;
