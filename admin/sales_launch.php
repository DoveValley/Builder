<?php
/**
 * admin/sales_launch.php — POST-only JSON endpoint: validates the New Search
 * form's inputs and launches includes/sales_prospecting_cli.php detached via
 * sales_launch_job() (includes/sales_prospecting.php). Mirrors admin/save.php's
 * CSRF check exactly.
 *
 * `preview=1` returns the cost estimate instead of launching anything — folded
 * into this same endpoint (same validated inputs either way) rather than a
 * separate file, per the plan's file-count discipline.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/sales_prospecting.php';
header('Content-Type: application/json');

if (empty($_SESSION['admin_logged_in']) || lpc_current_actor_role() !== 'super_admin') {
    http_response_code(403); echo json_encode(['error' => 'Forbidden']); exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'POST only']); exit; }

$token = $_POST['csrf_token'] ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
    http_response_code(403); echo json_encode(['error' => 'Invalid request token']); exit;
}

$niche = trim((string) ($_POST['niche'] ?? ''));
$states = array_values(array_filter(array_map('trim', (array) ($_POST['states'] ?? []))));
$citiesPerState = max(1, min(50, (int) ($_POST['cities_per_state'] ?? 5)));
$businessesPerCity = max(1, min(1000, (int) ($_POST['businesses_per_city'] ?? 20)));
$domainRankEnabled = !empty($_POST['domain_rank']);
$radiusKm = max(1, min(100000, (int) ($_POST['radius_km'] ?? 20)));

if ($niche === '') { echo json_encode(['error' => 'Pick a business category.']); exit; }
if (!sales_category_exists($niche)) { echo json_encode(['error' => '"' . $niche . '" is not a recognized DataForSEO business category — pick one from the list.']); exit; }
if (!$states) { echo json_encode(['error' => 'Pick at least one state.']); exit; }
foreach ($states as $ss) {
    if (!isset(sales_us_states()[strtoupper($ss)])) { echo json_encode(['error' => 'Unrecognized state: ' . $ss]); exit; }
}

if (!empty($_POST['preview'])) {
    echo json_encode(sales_cost_estimate($states, $citiesPerState, $businessesPerCity, $domainRankEnabled));
    exit;
}

$result = sales_launch_job($niche, $states, $citiesPerState, $businessesPerCity, $domainRankEnabled, $radiusKm);
echo json_encode($result);
