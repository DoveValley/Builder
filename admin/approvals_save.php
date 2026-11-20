<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

if (!lpc_actor_is_authenticated()) { header('Location: login.php'); exit; }
// Same as approvals.php — an End Client never approves/rejects content.
if (lpc_current_actor_role() === 'end_client') { header('Location: client_portal.php'); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: approvals.php'); exit; }

$token = $_POST['csrf_token'] ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
    header('Location: approvals.php?msg=error:Invalid+request+token');
    exit;
}

$action    = $_POST['action'] ?? '';
$client_id = trim($_POST['client_id'] ?? '');
$page_id   = trim($_POST['page_id'] ?? '');

// Scoped the same way every other per-client action is: an agency can only
// act on pages belonging to clients it actually owns. Scott (super_admin)
// is unrestricted, same default as everywhere else this check is used.
$client = lpc_load_client_for_current_actor($client_id);
if (!$client) {
    header('Location: approvals.php?msg=error:Client+not+found');
    exit;
}

$isSuperAdmin = lpc_current_actor_role() === 'super_admin';

if ($action === 'approve') {
    // Which stage a given actor is even allowed to clear — the platform
    // stage is Scott-only, the agency stage is that agency's own call, same
    // boundary-enforcement-in-the-handler pattern as every other gate in
    // this feature (the mechanical advance functions themselves don't check
    // who's calling, see landing_page_clients_approvals.php's own comment).
    $ok = $isSuperAdmin
        ? lpc_platform_approve_page($client_id, $page_id)
        : lpc_agency_approve_page($client_id, $page_id);
    $msg = $ok
        ? ($isSuperAdmin ? 'success:Approved+-+now+awaiting+the+agency' : 'success:Approved+and+published')
        : 'error:Could+not+approve+-+already+moved+on+by+someone+else%3F';
    header('Location: approvals.php?msg=' . $msg);
    exit;
}

if ($action === 'reject') {
    $note = trim($_POST['note'] ?? '');
    $ok = lpc_reject_page($client_id, $page_id, $note);
    header('Location: approvals.php?msg=' . ($ok ? 'success:Rejected' : 'error:Could+not+reject'));
    exit;
}

header('Location: approvals.php');
exit;
