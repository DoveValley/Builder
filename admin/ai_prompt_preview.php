<?php
// Returns a fully-resolved prompt for a given block type + city.
// POST only. Returns JSON: {success, resolved, context}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

if (empty($_SESSION['admin_logged_in'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Not authenticated.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'POST required.']);
    exit;
}

if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token.']);
    exit;
}

if (!ACTIVE_SITE_ID) {
    echo json_encode(['success' => false, 'error' => 'No active site.']);
    exit;
}

$blockTypeId = trim($_POST['block_type_id'] ?? '');
$cityId      = trim($_POST['city_id']       ?? '');
$service     = trim($_POST['service']       ?? '');

// ── Load block type ───────────────────────────────────────────────────────────
$registry = [];
if (file_exists(AI_REGISTRY_FILE)) {
    $raw = json_decode(file_get_contents(AI_REGISTRY_FILE), true);
    if (is_array($raw)) $registry = $raw;
}
if (!isset($registry[$blockTypeId])) {
    echo json_encode(['success' => false, 'error' => 'Block type not found.']);
    exit;
}
$prompt = $registry[$blockTypeId]['ai_prompt'] ?? '';

// ── Load city ─────────────────────────────────────────────────────────────────
$city = null;
if (file_exists(CITIES_FILE)) {
    $raw = json_decode(file_get_contents(CITIES_FILE), true);
    foreach (is_array($raw) ? $raw : [] as $c) {
        if (($c['id'] ?? '') === $cityId) { $city = $c; break; }
    }
}
if (!$city) {
    echo json_encode(['success' => false, 'error' => $cityId ? 'City not found.' : 'No city selected.']);
    exit;
}

// ── Business name from site.json ──────────────────────────────────────────────
$business = ACTIVE_SITE_ID;
if (file_exists(DATA_FILE)) {
    $site = json_decode(file_get_contents(DATA_FILE), true);
    $business = $site['site_vars']['business']
             ?? $site['header']['site_name']
             ?? $business;
}

// ── Build context (mirrors generate.py build_context()) ───────────────────────
$ctx = [
    'business'      => $business,
    'city'          => $city['city']          ?? '',
    'SS'            => $city['SS']            ?? '',
    'service'       => $service ?: '(service — set via template seo.service_name)',
    'industries'    => implode(', ', $city['industries']    ?? []) ?: '(none)',
    'top_employers' => implode(', ', $city['top_employers'] ?? []) ?: '(none)',
    'salary_note'   => $city['salary_note']   ?? '',
    'market_blurb'  => $city['market_blurb']  ?? '',
];

// ── Resolve variables ─────────────────────────────────────────────────────────
$unresolved = [];
$resolved = preg_replace_callback('/\{([a-zA-Z_]+)\}/', function ($m) use ($ctx, &$unresolved) {
    if (array_key_exists($m[1], $ctx)) return $ctx[$m[1]];
    $unresolved[] = $m[0];
    return $m[0]; // leave unknown vars in place
}, $prompt);

echo json_encode([
    'success'     => true,
    'resolved'    => $resolved,
    'context'     => $ctx,
    'unresolved'  => array_values(array_unique($unresolved)),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
exit;
