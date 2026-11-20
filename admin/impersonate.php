<?php
/**
 * admin/impersonate.php — "Log in as" controller. Never touches a target
 * account's password; just overlays the current, already-authenticated
 * session's acting identity (see lpc_start_impersonation() for the real
 * mechanism). 'start' is POST-only (a real action, same CSRF convention as
 * every other state-changing action in this app); 'stop' is a plain GET
 * link from the impersonation banner — low-stakes (worst case, someone
 * tricks you into exiting back to your own real account early), so a
 * simple token-checked link is proportionate, not a form.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

if (!lpc_actor_is_authenticated()) { header('Location: login.php'); exit; }

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$token = $_GET['csrf_token'] ?? $_POST['csrf_token'] ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
    header('Location: clients.php?msg=error:Invalid+request+token');
    exit;
}

if ($action === 'stop') {
    lpc_stop_impersonation();
    header('Location: clients.php');
    exit;
}

if ($action === 'start') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: clients.php'); exit; }

    if (lpc_is_impersonating()) {
        header('Location: clients.php?msg=error:Already+impersonating+-+return+to+your+own+account+first');
        exit;
    }

    // Checked against the REAL role, not lpc_current_actor_role() post-overlay —
    // safe here specifically because no-stacking is already enforced above, so
    // nothing has overlaid actor_role yet at this point in the request.
    $realRole = lpc_current_actor_role();
    $type = $_POST['type'] ?? '';

    if ($type === 'agency') {
        if ($realRole !== 'super_admin') {
            header('Location: clients.php?msg=error:Not+authorized');
            exit;
        }
        $agencyId = trim($_POST['agency_id'] ?? '');
        $agency = lpc_load_agency($agencyId);
        if (!$agency) {
            header('Location: agencies.php?msg=error:Agency+not+found');
            exit;
        }
        lpc_start_impersonation('agency_admin', $agencyId, null);
        header('Location: clients.php');
        exit;
    }

    if ($type === 'client') {
        $clientId = trim($_POST['client_id'] ?? '');
        $client = lpc_load_client($clientId);
        if (!$client) {
            header('Location: clients.php?msg=error:Client+not+found');
            exit;
        }
        if ($realRole === 'super_admin') {
            // unrestricted
        } elseif ($realRole === 'agency_admin') {
            if (($client['agency_id'] ?? '') !== lpc_current_actor_agency_id()) {
                header('Location: clients.php?msg=error:Not+authorized');
                exit;
            }
        } else {
            header('Location: clients.php?msg=error:Not+authorized');
            exit;
        }
        lpc_start_impersonation('end_client', $client['agency_id'] ?? null, $clientId);
        header('Location: client_portal.php');
        exit;
    }
}

header('Location: clients.php');
exit;
