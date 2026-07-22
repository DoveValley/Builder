<?php
// FTP deploy — SSE endpoint (thin shell).
// The upload itself lives in includes/multisite/deploy.php (deploy_site), so it
// can be reused by the multisite CLI worker. This file does auth, opens the SSE
// stream, loads deploy.json, and calls the core. Progress streams via
// includes/progress.php; with no sink set it emits the same SSE as before.

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/multisite/deploy.php';

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

session_write_close();

// ── SSE headers + unbuffered output ─────────────────────────────────────────────
progress_sse_begin();

// ── Load config ───────────────────────────────────────────────────────────────
$deployFile = ACTIVE_SITE_DIR . '/deploy.json';
if (!file_exists($deployFile)) {
    echo "data: " . json_encode(['type' => 'fatal', 'msg' => 'No deploy.json found — save FTP settings first.']) . "\n\n";
    exit;
}
$cfg = json_decode(file_get_contents($deployFile), true) ?: [];

$outputBase   = BASE_DIR . '/output/' . ACTIVE_SITE_ID . '/';
$manifestFile = ACTIVE_SITE_DIR . '/deploy_manifest.json';
$forceAll     = !empty($_GET['force']);

deploy_site($cfg, $outputBase, $manifestFile, $forceAll);
