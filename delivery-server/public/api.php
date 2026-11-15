<?php
/**
 * Landing Page Clients — delivery API.
 *
 * Deliberately thin: validate the API key, read the matching file, echo it.
 * No render engine runs here — that happens once at publish time, not per
 * request. This file is temporary scaffolding for Phase 1 (hand-authored
 * _keys.json lookup); Phase 2 replaces _keys.json with a real client.json-
 * backed lookup, same two actions.
 */

header('Content-Type: application/json');

$dataDir = dirname(__DIR__) . '/data';

$keysFile = $dataDir . '/_keys.json';
$keys = json_decode(file_get_contents($keysFile), true) ?: [];

$key = $_GET['key'] ?? '';
$action = $_GET['action'] ?? '';

// Not API-key-gated, unlike every action below: an image only ever gets
// attached to a page once that page is already public, so requiring the
// same secret used to fetch draft/list content would just expose it in
// public <img src> markup for no real confidentiality benefit. '_shared' is
// the one reserved client id for the cross-client placeholder image.
if ($action === 'image') {
    $clientParam = $_GET['client'] ?? '';
    $file = $_GET['file'] ?? '';
    if (!preg_match('/^[a-z0-9_][a-z0-9_-]{0,59}$/', $clientParam) || !preg_match('/^[a-z0-9_-]+\.(webp|jpe?g|png|gif)$/i', $file)) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'invalid_request']);
        exit;
    }
    $imgPath = $dataDir . '/clients/' . $clientParam . '/images/' . $file;
    if (!is_file($imgPath)) {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'image_not_found']);
        exit;
    }
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $mime = ['webp' => 'image/webp', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif'][$ext];
    header('Content-Type: ' . $mime);
    header('Cache-Control: public, max-age=86400');
    readfile($imgPath);
    exit;
}

if ($key === '' || !isset($keys[$key]) || empty($keys[$key]['active'])) {
    http_response_code(403);
    echo json_encode(['error' => 'invalid_or_inactive_key']);
    exit;
}

$clientId = $keys[$key]['client_id'];
$clientDir = $dataDir . '/clients/' . $clientId;

if ($action === 'status') {
    // Admin-side introspection only — must NOT bump last_checkin itself,
    // or every status check would look like a real plugin check-in and
    // staleness could never actually be detected.
    $statusPath = $clientDir . '/_status.json';
    echo is_file($statusPath) ? file_get_contents($statusPath) : json_encode(['last_checkin' => 0]);
    exit;
}

// Record that this client's key was actually used — the admin panel's
// Plugin Status column (Connected/Stale/Never Connected) reads this back
// via the 'status' action above, over HTTP, now that delivery genuinely
// lives on a separate machine (Box 1) from the admin panel. Only the real
// plugin actions (list/page) below reach this, not the status check above.
@file_put_contents($clientDir . '/_status.json', json_encode(['last_checkin' => time()]), LOCK_EX);

if ($action === 'list') {
    $manifestPath = $clientDir . '/manifest.json';
    if (!is_file($manifestPath)) {
        http_response_code(404);
        echo json_encode(['error' => 'manifest_not_found']);
        exit;
    }
    echo file_get_contents($manifestPath);
    exit;
}

if ($action === 'page') {
    $slug = $_GET['slug'] ?? '';
    if (!preg_match('/^[a-z0-9_-]+$/i', $slug)) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid_slug']);
        exit;
    }
    $pagePath = $clientDir . '/pages/' . $slug . '.json';
    if (!is_file($pagePath)) {
        http_response_code(404);
        echo json_encode(['error' => 'page_not_found']);
        exit;
    }
    echo file_get_contents($pagePath);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'unknown_action']);
