<?php
/**
 * admin/clients.php — retired 2026-11-23 as part of LPC Stage 3 (see
 * docs/lpc-stage3-retirement-scoping.md). Kept as a thin redirect, not
 * deleted outright: ~19 other admin files still redirect here as their
 * generic "go back to a safe default" fallback (auth failures, invalid
 * IDs, impersonation errors, etc.) — a real dependency the original
 * scoping pass missed by only tracing require/include chains, not
 * header('Location: ...') targets. Forwards to the real current
 * equivalent instead of 404ing every one of those callers.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

if (!lpc_actor_is_authenticated()) {
    header('Location: login.php');
    exit;
}
$qs = $_SERVER['QUERY_STRING'] ?? '';
header('Location: pluscities_clients.php' . ($qs !== '' ? '?' . $qs : ''));
exit;
