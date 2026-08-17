<?php
/**
 * infra/actions/dfinder_check.php — availability, asked directly instead of pasted.
 *
 * D.Finder shipped with a copy-paste round trip: copy the candidate list, run it
 * through a registrar's bulk search in another tab, paste what comes back. That
 * existed for one reason — an artifact cannot hold an API key. This panel can, so
 * the round trip is no longer necessary.
 *
 * No registrar code lives here. infra_registrar_check_availability() already
 * batches, already knows each registrar's shape, and already carries the hard-won
 * details: NameSilo returns two different JSON shapes depending on result count,
 * Porkbun allows one check per ten seconds, and Namecheap reports a non-whitelisted
 * IP as a generic authentication error, so the adapter appends "is <ip> whitelisted"
 * to it. Re-implementing any of that here would be a second registrar client that
 * starts identical and drifts. This route only decides WHICH domains to ask about.
 *
 * GET  → which registrars can check, and which is used by default.
 * POST → check a list, capped.
 */
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/registrar.php';

header('Content-Type: application/json');

/* Namecheap batches 40 per request, NameSilo and Spaceship 50, so 300 is at most
   eight requests — a few seconds. It is a guard against a mis-click on a niche
   with thousands of candidates turning into a very long synchronous request, not
   a limit anyone should meet in normal use. */
const DFINDER_CHECK_MAX = 300;

/* ---- who can check ---------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $checkers = infra_registrar_checkers();
    echo json_encode([
        'checkers' => $checkers,
        // Namecheap by default when it is configured: it is what the workbench's
        // paste instructions always named, so the button does what the panel beside
        // it says. Otherwise fall back to whichever checker is best.
        'default'  => isset($checkers['namecheap']) ? 'namecheap' : infra_default_checker(),
        'max'      => DFINDER_CHECK_MAX,
    ]);
    exit;
}

/* ---- check ------------------------------------------------------------ */
if (!infra_check_csrf()) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid request (bad CSRF token).']);
    exit;
}

// The session has been read (auth + CSRF). Let go of its lock so a slow job
// here does not queue up every other click in the console — see
// infra_session_release() in bootstrap.php.
infra_session_release();

// is_string first: the app posts a JSON string, but a hand-made request can post
// domains[]=… as an array, and casting that to string emits a PHP warning. A
// warning printed before the json_encode below lands INSIDE the response body and
// breaks the client's parse — turning a bad request into an unreadable reply.
$raw     = $_POST['domains'] ?? '[]';
$domains = is_string($raw) ? json_decode($raw, true) : (is_array($raw) ? $raw : null);
if (!is_array($domains) || !$domains) {
    http_response_code(400);
    echo json_encode(['error' => 'No domains to check.']);
    exit;
}

$checkers = infra_registrar_checkers();
$reg      = (string) ($_POST['registrar'] ?? '');
if ($reg === '' || !isset($checkers[$reg])) {
    $reg = isset($checkers['namecheap']) ? 'namecheap' : infra_default_checker();
}
if ($reg === '') {
    http_response_code(400);
    echo json_encode(['error' => 'No registrar is configured that can check availability. Add one on the Registrars tab.']);
    exit;
}

$total   = count($domains);
$domains = array_slice($domains, 0, DFINDER_CHECK_MAX);

$results = infra_registrar_check_availability($domains, $reg);

echo json_encode([
    'registrar' => $reg,
    'label'     => $checkers[$reg]['label'] ?? $reg,
    'results'   => $results,
    // Say what was dropped rather than let a cap look like a complete answer.
    'checked'   => count($domains),
    'skipped'   => max(0, $total - count($domains)),
]);
