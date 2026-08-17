<?php
/**
 * infra/actions/fleet_refresh.php — re-read ONE box, live, and report what it found.
 *
 * The console used to sweep the whole fleet on page load: four API calls per box,
 * in series, so twenty boxes meant 124 calls and a measured 64 seconds before the
 * dashboard printed its first byte. Two of the four numbers on that dashboard are
 * local file reads. Sixty-four seconds bought two integers.
 *
 * So the sweep moved here, one box per request, and the page fires them together
 * behind a progress bar (infra_refresh_bar() in bootstrap.php). Same total work,
 * but it happens when someone asks for it, and it happens in parallel — the wait
 * becomes the slowest single box (~6s) rather than the sum of twenty.
 *
 * The result is written straight into the same cache the page reads, so when the
 * sweep finishes the page reloads and renders fresh state through the ordinary
 * path. Nothing here has its own idea of what a box's numbers mean.
 */
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/hestia_fleet.php';

/* The sweep fires one of these per box AT THE SAME TIME. PHP holds an exclusive
   lock on the session file for the whole of a request, so without this close the
   twenty requests queue behind one another and the parallel sweep is the serial
   one with extra steps — the entire point, lost silently. Everything above has
   already read the session; nothing below writes to it. */
session_write_close();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !infra_check_csrf()) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid request (bad CSRF token).']);
    exit;
}

$id = trim((string) ($_POST['id'] ?? ''));

/* A "cf:{account}" id refreshes one Cloudflare account's zone list instead of a
   box. It rides in the same pool because it is the same job from the page's point
   of view: the fleet picture is servers AND zones, and refreshing half of it would
   leave two columns of D.Buy current and two stale, with one bar claiming both. */
if (str_starts_with($id, 'cf:')) {
    require_once __DIR__ . '/../lib/fleet.php';
    $acctId = substr($id, 3);
    foreach (infra_cf_accounts() as $a) {
        if ((string) ($a['id'] ?? '') !== $acctId) continue;
        infra_cache_force(true);                       // probe + zones, live
        $p = infra_cf_probe_cached($a);
        $z = infra_discover_cf_zones($a, 0);
        infra_cache_force(false);
        echo json_encode([
            'id'    => $id,
            'label' => (string) ($a['label'] ?? $acctId),
            'host'  => 'Cloudflare · ' . count($z) . ' zone' . (count($z) === 1 ? '' : 's'),
            'ok'    => !empty($p['ok']),
            'pending' => false,
            'error' => (string) ($p['error'] ?? ''),
        ]);
        exit;
    }
    http_response_code(404);
    echo json_encode(['error' => 'That Cloudflare account is not in the list.']);
    exit;
}

$srv = null;
foreach (infra_hestia_servers() as $s) {
    if ((string) ($s['id'] ?? '') === $id) { $srv = $s; break; }
}
if ($srv === null) {
    http_response_code(404);
    echo json_encode(['error' => 'That server is not in the list.']);
    exit;
}

// ttl 0 — always go and look, and repopulate the cache the pages read.
$b = infra_hestia_shape($srv, infra_discover_hestia($srv, 0));

echo json_encode([
    'id'       => $b['id'],
    'label'    => $b['label'],
    'host'     => $b['host'],
    'ok'       => $b['ok'],
    // Registered but not set up yet: unfinished, not broken. The bar counts these
    // apart so a fleet with new boxes in it does not report faults it does not have.
    'pending'  => $b['pending'],
    'error'    => $b['error'],
    'deployed' => $b['deployed'],
    'calls'    => $b['calls'],
    'ms'       => $b['ms'],
]);
