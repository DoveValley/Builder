<?php
// Static site generator — SSE endpoint (thin shell).
// The build itself lives in includes/static_build.php (build_static_site), so it
// can be reused by the multisite CLI worker. This file just does auth, opens the
// SSE stream, resolves deploy values + output dir, and calls the build core.

define('STATIC_BUILD', true);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

// ── Auth ──────────────────────────────────────────────────────────────────────
if (empty($_SESSION['admin_logged_in'])) {
    http_response_code(403);
    echo "data: " . json_encode(['type' => 'fatal', 'msg' => 'Not authenticated.']) . "\n\n";
    exit;
}
if (!ACTIVE_SITE_ID) {
    http_response_code(400);
    echo "data: " . json_encode(['type' => 'fatal', 'msg' => 'No active site selected.']) . "\n\n";
    exit;
}
// SSE uses GET so the token is passed in the query string; validate before releasing session.
if (!hash_equals($_SESSION['csrf_token'] ?? '', $_GET['token'] ?? '')) {
    http_response_code(403);
    echo "data: " . json_encode(['type' => 'fatal', 'msg' => 'Invalid security token.']) . "\n\n";
    exit;
}

// Release session lock — generation is read-only after this point.
session_write_close();

// ── SSE headers + unbuffered output ─────────────────────────────────────────────
progress_sse_begin();

// Progress streams via includes/progress.php. With no sink set, it emits the same
// SSE wire format this endpoint always used, so browser behavior is unchanged.

// ── Load deploy config ────────────────────────────────────────────────────────
$deployFile = ACTIVE_SITE_DIR . '/deploy.json';
$deploy = file_exists($deployFile) ? (json_decode(file_get_contents($deployFile), true) ?: []) : [];
$canonicalDomain = rtrim($deploy['canonical_domain'] ?? '', '/');
$web3formsKey    = $deploy['web3forms_key'] ?? '';

// ── Output directory + build ────────────────────────────────────────────────────
$outputBase = BASE_DIR . '/output/' . ACTIVE_SITE_ID . '/';
build_static_site($outputBase, $canonicalDomain, $web3formsKey);
