<?php
/**
 * includes/pluscities_users.php — the real per-person users entity from
 * docs/PLUSCITIES_FEATURE_SPEC.md Section 1/10, replacing today's "one
 * shared login per agency" with real Agency Editor accounts.
 *
 * Deliberately EXTENDS the existing auth mechanism
 * (includes/agencies.php's $_SESSION['agency_logged_in']
 * / actor_role / agency_id) rather than building a parallel login system —
 * auth is shared infrastructure between the old and new engines, unlike
 * rendering/content, which stay deliberately separate. An Agency Editor logs
 * in through the exact same admin/agency_login.php form; see the hook added
 * there.
 *
 * Only 'agency_editor' is stored here — Agency Admin stays the agency's own
 * existing shared login (no change), since that identity already exists and
 * nothing in the spec asks for multiple Agency Admins per agency yet.
 *
 * Same conventions as every other entity in this codebase: atomic writes,
 * deep-merge-against-defaults, password kept in a sibling secrets file
 * (never in the tracked JSON), same as client_agencies_secrets/.
 */

function pluscities_user_defaults(): array
{
    return [
        'user_id'             => '',
        'agency_id'           => '',
        'name'                => '',
        'email'               => '',
        'username'            => '',
        'role'                => 'agency_editor',
        // Empty = sees all of the agency's clients (spec Section 10's
        // default), per CLAUDE.md's denylist-over-allowlist instinct applied
        // here as "restrict by exception," not "grant by exception."
        'assigned_client_ids' => [],
        'created_at'          => '',
    ];
}

function pluscities_user_valid_id($id)
{
    return is_string($id) && preg_match('/^[a-z0-9][a-z0-9-]{0,80}$/', $id) === 1;
}

function pluscities_user_file(string $user_id): ?string
{
    if (!pluscities_user_valid_id($user_id)) return null;
    return PLUSCITIES_AGENCY_USERS_DIR . $user_id . '.json';
}

function pluscities_user_secret_file(string $user_id): ?string
{
    if (!pluscities_user_valid_id($user_id)) return null;
    return PLUSCITIES_AGENCY_USERS_SECRETS_DIR . $user_id . '.txt';
}

function pluscities_user_load(string $user_id): ?array
{
    $file = pluscities_user_file($user_id);
    if ($file === null || !file_exists($file)) return null;
    $data = json_decode((string) file_get_contents($file), true);
    if (!is_array($data)) return null;
    return pluscities_deep_merge_defaults($data, pluscities_user_defaults());
}

function pluscities_user_save(string $user_id, array $data): bool
{
    $file = pluscities_user_file($user_id);
    if ($file === null) return false;
    $data['user_id'] = $user_id;
    if (empty($data['created_at'])) {
        $existing = pluscities_user_load($user_id);
        $data['created_at'] = $existing['created_at'] ?? gmdate('c');
    }
    return pluscities_atomic_write_json($file, pluscities_deep_merge_defaults($data, pluscities_user_defaults()));
}

function pluscities_user_delete(string $user_id): bool
{
    $file = pluscities_user_file($user_id);
    if ($file === null || !file_exists($file)) return false;
    $secret = pluscities_user_secret_file($user_id);
    if ($secret && file_exists($secret)) unlink($secret);
    return unlink($file);
}

/** @return array user_id => user record, for one agency. */
function pluscities_users_for_agency(string $agency_id): array
{
    $out = [];
    foreach (glob(PLUSCITIES_AGENCY_USERS_DIR . '*.json') ?: [] as $f) {
        $u = pluscities_user_load(basename($f, '.json'));
        if ($u && $u['agency_id'] === $agency_id) $out[$u['user_id']] = $u;
    }
    return $out;
}

/** Scans every user for a username match — login has no agency_id to
 *  disambiguate by, same constraint lpc_find_agency_by_username() has. */
function pluscities_user_find_by_username(string $username): ?array
{
    $username = trim($username);
    if ($username === '') return null;
    foreach (glob(PLUSCITIES_AGENCY_USERS_DIR . '*.json') ?: [] as $f) {
        $u = pluscities_user_load(basename($f, '.json'));
        if ($u && strcasecmp($u['username'], $username) === 0) return $u;
    }
    return null;
}

/** Derived from name, not a random id — a describable id, not an opaque one. */
function pluscities_user_unique_id(string $agency_id, string $name): string
{
    $base = $agency_id . '-' . (slugify($name) ?: 'editor');
    $id = $base;
    $i = 2;
    while (pluscities_user_load($id) !== null) {
        $id = $base . '-' . $i;
        $i++;
    }
    return $id;
}

function pluscities_user_set_password(string $user_id, string $password): bool
{
    $file = pluscities_user_secret_file($user_id);
    if ($file === null) return false;
    $dir = dirname($file);
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) return false;
    return file_put_contents($file, password_hash($password, PASSWORD_DEFAULT)) !== false;
}

function pluscities_user_verify_password(string $user_id, string $password): bool
{
    $file = pluscities_user_secret_file($user_id);
    if ($file === null || !file_exists($file)) return false;
    return password_verify($password, (string) file_get_contents($file));
}
