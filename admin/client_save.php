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

    default:
        header('Location: clients.php?msg=error:Unknown+section');
        exit;
}
