<?php
/** admin/pluscities_business_info_save.php — save handler for admin/pluscities_business_info.php. */
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
$back     = 'pluscities_business_info.php?client_id=' . urlencode($clientId);
if (!pluscities_actor_can_access_client($clientId)) {
    header('Location: pluscities_clients.php?msg=error:Client+not+found'); exit;
}
$clients = pluscities_client_ids_for_current_actor();
$client  = $clients[$clientId];

$client['identity']['business_name'] = trim((string) ($_POST['business_name'] ?? ''));
$client['identity']['hubs'][0] = [
    'address' => trim((string) ($_POST['hub_address'] ?? '')),
    'phone'   => trim((string) ($_POST['hub_phone'] ?? '')),
];
$client['years_in_business'] = (int) ($_POST['years_in_business'] ?? 0) ?: null;
$client['hours'] = [
    'mon_fri' => trim((string) ($_POST['hours_mon_fri'] ?? '')),
    'sat'     => trim((string) ($_POST['hours_sat'] ?? '')),
    'sun'     => trim((string) ($_POST['hours_sun'] ?? '')),
];

// Licenses: "number|state|trade" per line.
$licenses = [];
foreach (explode("\n", (string) ($_POST['licenses_lines'] ?? '')) as $line) {
    $line = trim($line);
    if ($line === '') continue;
    $parts = array_map('trim', explode('|', $line, 3));
    if (($parts[0] ?? '') === '') continue;
    $licenses[] = ['number' => $parts[0], 'state' => strtoupper($parts[1] ?? ''), 'trade' => $parts[2] ?? ''];
}
$client['licenses'] = $licenses;

$client['banned_phrases'] = pluscities_split_csv_field((string) ($_POST['banned_phrases'] ?? ''));
$client['price_ranges']   = pluscities_split_csv_field((string) ($_POST['price_ranges'] ?? ''));

// Reviews by city: one "reviews_{city_id}" textarea per target city, each
// line "text|author|rating". Only touches cities this client actually has a
// field for — never wipes a city that isn't in target_cities (e.g. one just
// removed from the form between page loads).
$reviewsByCity = $client['reviews_by_city'] ?? [];
foreach (($client['target_cities'] ?? []) as $tc) {
    $cid = $tc['city_id'];
    $field = 'reviews_' . $cid;
    if (!array_key_exists($field, $_POST)) continue;
    $reviews = [];
    foreach (explode("\n", (string) $_POST[$field]) as $line) {
        $line = trim($line);
        if ($line === '') continue;
        $parts = array_map('trim', explode('|', $line, 3));
        if (($parts[0] ?? '') === '') continue;
        $reviews[] = ['text' => $parts[0], 'author' => $parts[1] ?? '', 'rating' => (int) ($parts[2] ?? 5)];
    }
    $reviewsByCity[$cid] = $reviews;
}
$client['reviews_by_city'] = $reviewsByCity;

// These facts feed directly into every regenerated block's content (client
// profile grounding) and the invented_prices/banned_phrases validator checks
// — bump site_rev same as every other client-data edit, so the delivery
// plugin knows to re-fetch once pages are actually regenerated against them.
$client['site_rev'] = (int) ($client['site_rev'] ?? 0) + 1;

pluscities_atomic_write_json(PLUSCITIES_CLIENTS_DIR . $clientId . '.json', $client);

$actor = lpc_current_actor_role() . ':' . (lpc_current_actor_identity()['name'] ?? 'unknown');
pluscities_log_event($actor, 'edit_business_info', 'client:' . $clientId, '');

header('Location: ' . $back . '&msg=success:Saved'); exit;
