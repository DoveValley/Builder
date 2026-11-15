<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

if (empty($_SESSION['admin_logged_in'])) { header('Location: login.php'); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: clients.php'); exit; }

$token = $_POST['csrf_token'] ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
    header('Location: clients.php?msg=error:Invalid+request+token');
    exit;
}

$section = $_POST['section'] ?? '';

switch ($section) {

    /* ---- HUBS (add / edit / delete) ---- */
    case 'hubs':
        $client_id = $_POST['client_id'] ?? '';
        $client = lpc_load_client($client_id);
        if (!$client) { header('Location: clients.php?msg=error:Client+not+found'); exit; }

        $action = $_POST['hub_action'] ?? '';

        if ($action === 'add') {
            $client['hubs'][] = [
                'hub_id'  => $client_id . '-hub-' . (count($client['hubs']) + 1),
                'name'    => trim($_POST['name'] ?? ''),
                'address' => [
                    'street' => trim($_POST['street'] ?? ''),
                    'city'   => trim($_POST['city'] ?? ''),
                    'state'  => trim($_POST['state'] ?? ''),
                    'zip'    => trim($_POST['zip'] ?? ''),
                ],
                'phone' => trim($_POST['phone'] ?? ''),
                'geo'   => ['lat' => null, 'lng' => null],
            ];
        } elseif ($action === 'delete') {
            $hub_id = $_POST['hub_id'] ?? '';
            $stillServing = false;
            foreach ($client['target_cities'] ?? [] as $tc) {
                if (($tc['served_by_hub'] ?? '') === $hub_id) { $stillServing = true; break; }
            }
            if ($stillServing) {
                header('Location: client.php?id=' . urlencode($client_id) . '&msg=error:Cannot+delete+a+hub+that+still+serves+a+target+city');
                exit;
            }
            $client['hubs'] = array_values(array_filter($client['hubs'], fn($h) => $h['hub_id'] !== $hub_id));
        }

        lpc_save_client($client_id, $client);
        header('Location: client.php?id=' . urlencode($client_id) . '&msg=success:Hub+updated');
        exit;

    /* ---- BILLING ---- */
    case 'billing':
        $client_id = $_POST['client_id'] ?? '';
        $client = lpc_load_client($client_id);
        if (!$client) { header('Location: clients.php?msg=error:Client+not+found'); exit; }

        $client['billing'] = [
            'setup_fee_per_location'   => (float) ($_POST['setup_fee_per_location'] ?? 0),
            'monthly_fee_per_location' => (float) ($_POST['monthly_fee_per_location'] ?? 0),
            'start_date'               => trim($_POST['start_date'] ?? ''),
        ];
        lpc_save_client($client_id, $client);
        header('Location: client.php?id=' . urlencode($client_id) . '&msg=success:Billing+updated');
        exit;

    /* ---- SERVICES ---- */
    case 'services':
        $client_id = $_POST['client_id'] ?? '';
        $client = lpc_load_client($client_id);
        if (!$client) { header('Location: clients.php?msg=error:Client+not+found'); exit; }

        $label = trim($_POST['service_label'] ?? '');
        if ($label !== '') {
            $serviceId = slugify($label);
            $exists = false;
            foreach ($client['services'] as $s) { if ($s['service_id'] === $serviceId) $exists = true; }
            if (!$exists) {
                $client['services'][] = ['service_id' => $serviceId, 'label' => $label];
                // "Applied to all cities by default" per the design — a new service
                // column should show up as a Not Created cell for every existing
                // target city, not just future ones.
                foreach ($client['target_cities'] as &$tc) {
                    if (!in_array($serviceId, $tc['services'], true)) {
                        $tc['services'][] = $serviceId;
                    }
                }
                unset($tc);
            }
        }
        lpc_save_client($client_id, $client);
        header('Location: client.php?id=' . urlencode($client_id) . '&tab=locations&msg=success:Service+added');
        exit;

    /* ---- TARGET CITIES (bulk add from the suggestion table / remove) ---- */
    case 'target_cities':
        $client_id = $_POST['client_id'] ?? '';
        $client = lpc_load_client($client_id);
        if (!$client) { header('Location: clients.php?msg=error:Client+not+found'); exit; }

        $action = $_POST['tc_action'] ?? '';

        if ($action === 'add_selected') {
            $hubId = trim($_POST['hub_id'] ?? '');
            foreach ((array) ($_POST['selected'] ?? []) as $packed) {
                [$cityId, $city, $state] = array_pad(explode('|', $packed, 3), 3, '');
                if ($cityId === '') continue;
                $exists = false;
                foreach ($client['target_cities'] as $tc) { if ($tc['city_id'] === $cityId) $exists = true; }
                if (!$exists) {
                    $client['target_cities'][] = [
                        'city_id' => $cityId, 'city' => $city, 'state' => $state,
                        'served_by_hub' => $hubId,
                        'services' => array_column($client['services'], 'service_id'),
                    ];
                }
            }
        } elseif ($action === 'remove') {
            $cityId = $_POST['city_id'] ?? '';
            $client['target_cities'] = array_values(array_filter($client['target_cities'], fn($tc) => $tc['city_id'] !== $cityId));
        }

        lpc_save_client($client_id, $client);
        header('Location: client.php?id=' . urlencode($client_id) . '&tab=locations&msg=success:Target+cities+updated');
        exit;

    /* ---- PAGES (generate a blank draft / publish one or more) ---- */
    case 'pages':
        $client_id = $_POST['client_id'] ?? '';
        $client = lpc_load_client($client_id);
        if (!$client) { header('Location: clients.php?msg=error:Client+not+found'); exit; }

        $action = $_POST['page_action'] ?? '';

        if ($action === 'generate_draft') {
            $pageId = trim($_POST['page_id'] ?? '');
            if ($pageId !== '' && !lpc_load_page($client_id, $pageId)) {
                lpc_save_page($client_id, $pageId, lpc_default_page_data());
            }
        } elseif ($action === 'publish') {
            $pageIds = $_POST['page_ids'] ?? [];
            if (!is_array($pageIds)) $pageIds = [$pageIds];
            foreach ($pageIds as $pageId) {
                lpc_publish_page($client_id, trim($pageId));
            }
        }

        header('Location: client.php?id=' . urlencode($client_id) . '&tab=locations&msg=success:Page+updated');
        exit;

    default:
        header('Location: clients.php?msg=error:Unknown+section');
        exit;
}
