<?php
/** admin/pluscities_team_save.php — save handler for admin/pluscities_team.php. */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

if (!lpc_actor_is_authenticated() || lpc_current_actor_role() !== 'agency_admin') {
    header('Location: clients.php?msg=error:Team+management+is+for+Agency+Admins+only');
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: pluscities_team.php'); exit; }

$token = $_POST['csrf_token'] ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
    header('Location: pluscities_team.php?msg=error:Invalid+request+token'); exit;
}

$agencyId = lpc_current_actor_agency_id();
$action   = $_POST['action'] ?? '';

if ($action === 'toggle_editor_publish') {
    $agency = lpc_load_agency($agencyId);
    $agency['editor_can_publish'] = !empty($_POST['editor_can_publish']);
    lpc_save_agency($agencyId, $agency);
    header('Location: pluscities_team.php?msg=success:Saved'); exit;
}

if ($action === 'save') {
    $name     = trim($_POST['name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    $existingId = trim($_POST['existing_user_id'] ?? '');
    $isNew = $existingId === '';

    if ($name === '' || $username === '') {
        header('Location: pluscities_team.php?msg=error:Name+and+username+are+required'); exit;
    }
    if ($isNew && $password === '') {
        header('Location: pluscities_team.php?msg=error:A+password+is+required+for+a+new+team+member'); exit;
    }

    // A username must be unique across BOTH agency logins and team-member
    // logins — the login form has no agency_id to disambiguate by (same
    // constraint lpc_find_agency_by_username() already has for agencies).
    $existingUser = pluscities_user_find_by_username($username);
    if ($existingUser && $existingUser['user_id'] !== $existingId) {
        header('Location: pluscities_team.php?msg=error:That+username+is+already+in+use'); exit;
    }
    if (lpc_find_agency_by_username($username)) {
        header('Location: pluscities_team.php?msg=error:That+username+is+already+in+use'); exit;
    }

    $userId = $isNew ? pluscities_user_unique_id($agencyId, $name) : $existingId;
    // An Editor can only ever be assigned clients THIS agency actually owns —
    // filtering here, not trusting whatever ids the form posted, matches the
    // same "never trust client input for scope" rule the page/bulk-action
    // handlers already apply.
    $allowedClientIds = array_keys(pluscities_client_ids_for_current_actor());
    $assigned = array_values(array_intersect($_POST['assigned_client_ids'] ?? [], $allowedClientIds));

    pluscities_user_save($userId, [
        'agency_id'           => $agencyId,
        'name'                => $name,
        'email'               => $email,
        'username'            => $username,
        'role'                => 'agency_editor',
        'assigned_client_ids' => $assigned,
    ]);
    if ($password !== '') pluscities_user_set_password($userId, $password);

    header('Location: pluscities_team.php?msg=success:Saved'); exit;
}

if ($action === 'delete') {
    $userId = trim($_POST['user_id'] ?? '');
    $user = pluscities_user_load($userId);
    // Only ever delete a user who's actually this agency's own — a crafted
    // user_id from outside the agency silently does nothing, not a 403 that
    // confirms whether that id exists at all.
    if ($user && $user['agency_id'] === $agencyId) {
        pluscities_user_delete($userId);
    }
    header('Location: pluscities_team.php?msg=success:Removed'); exit;
}

header('Location: pluscities_team.php?msg=error:Unknown+action');
