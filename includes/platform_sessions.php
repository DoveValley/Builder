<?php
/**
 * Login activity tracking for the "Platform Information" admin page (Who's
 * Logged In tab) — a single JSON registry keyed by PHP session id, updated
 * once per request by platform_session_heartbeat(), called from config.php
 * itself so every request touches it, not just pages that happen to require
 * functions.php. Deliberately minimal here (no other includes): config.php
 * runs for every public page too, and most of those requests have none of
 * the three *_logged_in flags set, so the common case is a single isset()
 * check and nothing else.
 *
 * Keyed to the REAL identity, not whatever's being impersonated — during
 * impersonation the real_actor_role/real_agency_id/real_client_id fields
 * hold the true logged-in account (see lpc_start_impersonation()'s own doc
 * comment), and that's what an audit trail of "who logged in" should show,
 * not the identity they're temporarily viewing as.
 */

function platform_sessions_file(): string
{
    return BASE_DIR . '/.platform_login_sessions.json';
}

function platform_sessions_load(): array
{
    return json_decode((string) @file_get_contents(platform_sessions_file()), true) ?: [];
}

function platform_sessions_save(array $data): void
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    $tmp = platform_sessions_file() . '.tmp.' . getmypid();
    if (@file_put_contents($tmp, $json, LOCK_EX) !== false) {
        @rename($tmp, platform_sessions_file());
    }
}

/** How stale a session's last heartbeat can be before "Currently Logged In" stops counting it. */
const PLATFORM_SESSION_ONLINE_WINDOW = 600; // 10 minutes

function platform_session_heartbeat(): void
{
    if (empty($_SESSION['admin_logged_in']) && empty($_SESSION['agency_logged_in']) && empty($_SESSION['client_logged_in'])) {
        return; // not authenticated at all — nothing to log
    }
    // Same default-to-super_admin fallback as lpc_current_actor_role(), preferring
    // the real_ shadow field so an impersonated session still logs the real actor.
    $role = $_SESSION['real_actor_role'] ?? $_SESSION['actor_role'] ?? 'super_admin';

    $sessions = platform_sessions_load();
    $sid = session_id();
    $now = time();

    $sessions[$sid] = [
        'role'        => $role,
        'agency_id'   => $_SESSION['real_agency_id'] ?? $_SESSION['agency_id'] ?? null,
        'client_id'   => $_SESSION['real_client_id'] ?? $_SESSION['client_id'] ?? null,
        'ip'          => $_SERVER['REMOTE_ADDR'] ?? '',
        'login_at'    => $sessions[$sid]['login_at'] ?? $now,
        'last_seen'   => $now,
        'logout_at'   => null, // cleared on every authenticated request — a session only ever "logs out" via platform_session_record_logout()
    ];

    // Prune anything older than 180 days so this file doesn't grow forever.
    $cutoff = $now - 180 * 86400;
    foreach ($sessions as $k => $s) {
        if (($s['login_at'] ?? 0) < $cutoff) unset($sessions[$k]);
    }

    platform_sessions_save($sessions);
}

/** Called from logout.php, before the session is destroyed — marks a clean explicit end instead of leaving duration to be inferred from the last heartbeat. */
function platform_session_record_logout(): void
{
    $sid = session_id();
    $sessions = platform_sessions_load();
    if (isset($sessions[$sid])) {
        $sessions[$sid]['logout_at'] = time();
        platform_sessions_save($sessions);
    }
}
