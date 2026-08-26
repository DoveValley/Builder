<?php
/**
 * infra/actions/dfinder_send_to_dbuy.php — D.Finder's "Move Shortlist to D.Buy" button.
 *
 * This route's own job is ADD-ONLY into D.Buy: it uses the SAME additive
 * primitive the paste-box loader uses (infra_state_add_new_domain(),
 * admin/infra/actions/domains_load.php), so a domain already tracked in D.Buy
 * is left completely alone — no reset of its progress, no re-triggering a
 * fresh availability check. Only a genuinely NEW domain gets promoted
 * straight to status=ready / ready_to_buy=yes, matching the precedent from
 * the 2026-08-20 shortlist pass (see project_dfinder_tab memory).
 *
 * This PHP route never touches the D.Finder shortlist blob itself — the JSX
 * caller (sendShortlistToDbuy() in domain-workbench.jsx) does that AFTER
 * reading back which domains this route confirms are in D.Buy (added +
 * duplicates), moving only those out of "shortlist" into "purchased". That
 * two-step shape is deliberate: a failed/partial request must never mark a
 * shortlist entry as moved when it never actually reached D.Buy.
 */
require_once __DIR__ . '/../bootstrap.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST only.']);
    exit;
}

if (!infra_check_csrf()) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid request (bad CSRF token).']);
    exit;
}

infra_session_release();

// D.Finder's niche labels -> the four fixed D.Buy slugs (INFRA_NICHES). Anything
// unrecognised falls through to infra_niche()'s own 'other' coercion rather than
// silently dropping the item.
const DFINDER_NICHE_MAP = [
    'appliance repair'  => 'appliance',
    'pest control'      => 'pest',
    'water restoration' => 'restoration',
    'mold remediation'  => 'mold',
];
function dfinder_niche_to_slug(string $label): string
{
    $slug = DFINDER_NICHE_MAP[strtolower(trim($label))] ?? null;
    return $slug ?? infra_niche($label);
}

$raw   = $_POST['items'] ?? '[]';
$items = is_string($raw) ? json_decode($raw, true) : (is_array($raw) ? $raw : null);
if (!is_array($items) || !$items) {
    http_response_code(400);
    echo json_encode(['error' => 'No shortlist items to send.']);
    exit;
}

$added = [];
$duplicates = [];
$invalid = 0;

foreach ($items as $it) {
    $domain = is_array($it) ? strtolower(trim((string) ($it['domain'] ?? ''))) : '';
    if ($domain === '' || !preg_match('/^[a-z0-9][a-z0-9-]*\.com$/', $domain)) {
        $invalid++;
        continue;
    }
    $niche = dfinder_niche_to_slug((string) ($it['niche'] ?? ''));

    if (infra_state_add_new_domain($domain, $niche)) {
        infra_state_upsert_domain(['domain' => $domain, 'status' => 'ready', 'ready_to_buy' => 'yes']);
        $added[] = $domain;
    } else {
        $duplicates[] = $domain;
    }
}

echo json_encode([
    'added'            => $added,
    'duplicates'       => $duplicates,
    'added_count'      => count($added),
    'duplicate_count'  => count($duplicates),
    'invalid_count'    => $invalid,
]);
