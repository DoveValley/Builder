<?php
/**
 * admin/sales_launch.php — JSON endpoint for the New Search form, five actions:
 *   - GET  action=search_cities: read-only city-picker lookup (no CSRF needed,
 *     same reasoning admin/sales_export.php's GET already uses).
 *   - GET  action=cities_near: the N nearest cities to a given lat/lng — the
 *     "pick an anchor, see what's around it, pick some, repeat with a new
 *     anchor" round-trip for building up one big multi-city search.
 *   - POST action=backfill_offices: launches includes/sales_detect_offices_cli.php
 *     detached via sales_launch_office_detect_job() — backfills office_cities for
 *     every saved lead matching the current results-table filters that's missing one.
 *   - POST preview=1: validates inputs and returns the cost estimate, nothing launched.
 *   - POST (default): validates inputs and launches includes/sales_prospecting_cli.php
 *     detached via sales_launch_job(). Mirrors admin/save.php's CSRF check exactly.
 * All five folded into one file rather than five, per the plan's file-count discipline.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/sales_prospecting.php';
header('Content-Type: application/json');

if (empty($_SESSION['admin_logged_in']) || lpc_current_actor_role() !== 'super_admin') {
    http_response_code(403); echo json_encode(['error' => 'Forbidden']); exit;
}

if (($_GET['action'] ?? '') === 'search_cities') {
    $query = trim((string) ($_GET['q'] ?? ''));
    $state = trim((string) ($_GET['state'] ?? ''));
    $limit = max(1, min(50, (int) ($_GET['limit'] ?? 20)));
    // A blank query + a real state is the "Add top N by population" quick-add button,
    // not a text search — same underlying data (sales_resolve_cities()), reused rather
    // than adding a second endpoint for what's really the same "list some cities" need.
    if ($query === '' && $state !== '' && isset(sales_us_states()[strtoupper($state)])) {
        $rows = sales_resolve_cities([$state], $limit);
    } else {
        $rows = sales_search_cities($query, $state, $limit);
    }
    foreach ($rows as &$row) {
        $row['history'] = sales_city_search_history($row['city'], $row['ss']);
    }
    unset($row);
    echo json_encode($rows);
    exit;
}

if (($_GET['action'] ?? '') === 'cities_near') {
    $lat = (float) ($_GET['lat'] ?? 0);
    $lng = (float) ($_GET['lng'] ?? 0);
    $limit = max(1, min(50, (int) ($_GET['limit'] ?? 10)));
    if ($lat === 0.0 && $lng === 0.0) { echo json_encode(['error' => 'Missing anchor coordinates']); exit; }
    $rows = sales_cities_near($lat, $lng, $limit);
    foreach ($rows as &$row) {
        $row['history'] = sales_city_search_history($row['city'], $row['ss']);
    }
    unset($row);
    echo json_encode($rows);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'POST only']); exit; }

$token = $_POST['csrf_token'] ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
    http_response_code(403); echo json_encode(['error' => 'Invalid request token']); exit;
}

if (($_POST['action'] ?? '') === 'backfill_offices') {
    $filters = [
        'niche'        => trim((string) ($_POST['niche'] ?? '')),
        'state'        => trim((string) ($_POST['state'] ?? '')),
        'status'       => trim((string) ($_POST['status'] ?? '')),
        'is_wordpress' => trim((string) ($_POST['is_wordpress'] ?? '')),
    ];
    echo json_encode(sales_launch_office_detect_job(array_filter($filters, fn($v) => $v !== '')));
    exit;
}

$niche = trim((string) ($_POST['niche'] ?? ''));
$businessesPerCity = max(1, min(1000, (int) ($_POST['businesses_per_city'] ?? 20)));
$domainRankEnabled = !empty($_POST['domain_rank']);

$cities = json_decode((string) ($_POST['cities'] ?? ''), true);
$cities = is_array($cities) ? $cities : [];
$cleanCities = [];
foreach ($cities as $city) {
    $ss = strtoupper(trim((string) ($city['ss'] ?? '')));
    $name = trim((string) ($city['city'] ?? ''));
    $lat = (string) ($city['lat'] ?? '');
    $lng = (string) ($city['lng'] ?? '');
    if ($name === '' || !isset(sales_us_states()[$ss]) || $lat === '' || $lng === '') continue;
    $cleanCities[] = [
        'city'      => $name,
        'state'     => (string) ($city['state'] ?? $ss),
        'ss'        => $ss,
        'lat'       => $lat,
        'lng'       => $lng,
        'radius_km' => max(1, min(100000, (int) ($city['radius_km'] ?? 20))),
    ];
}

if ($niche === '') { echo json_encode(['error' => 'Enter a business category or keyword.']); exit; }
// Not every real niche has a matching DataForSEO category (confirmed live:
// "foundation repair" has none, Google's own taxonomy doesn't either) — if it
// doesn't match a real category slug, it's used as a free-text title/keyword
// search instead of being rejected (sales_dataforseo_business_search()'s
// $isCategory flag, resolved the same way in the CLI worker).
if (!$cleanCities) { echo json_encode(['error' => 'Add at least one city.']); exit; }

if (!empty($_POST['preview'])) {
    echo json_encode(sales_cost_estimate($cleanCities, $businessesPerCity, $domainRankEnabled));
    exit;
}

$result = sales_launch_job($niche, $cleanCities, $businessesPerCity, $domainRankEnabled);
echo json_encode($result);
