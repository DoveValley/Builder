<?php
/**
 * infra/actions/dfinder_state.php — D.Finder's persistence.
 *
 * The workbench came from a Claude.ai artifact, where it kept everything in
 * window.storage. That object only exists inside the artifact host, so this is
 * what replaces it: GET returns the saved blob, POST writes it.
 *
 * The state is stored OPAQUE — one JSON string, not a schema. The workbench owns
 * its own shape and already repairs older ones in normalize() on every load.
 * Modelling its niches, patterns, candidates and registry as tables here would put
 * the same structure in two places, and every change to the UI would become a
 * migration. There is one consumer; it can keep its own shape.
 *
 * Keyed 'default' rather than by user: this panel has a single admin login. If it
 * ever grows accounts, key by user id here and nothing else needs to move.
 */
require_once __DIR__ . '/../bootstrap.php';

header('Content-Type: application/json');

const DFINDER_KEY = 'default';
// Generous but finite. A real workbench with thousands of candidates is well under
// a megabyte; anything past this is a runaway loop or a paste gone wrong, and
// silently storing it would turn one bad write into a page that cannot load.
const DFINDER_MAX = 4194304;   // 4 MB

/* ---- read ------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $stmt = infra_state_db()->prepare('SELECT v, ts FROM dfinder WHERE k = ?');
    $stmt->execute([DFINDER_KEY]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        // Nothing saved yet is not an error. The workbench reads null as "start
        // from the seed niches", which is exactly right on a first visit.
        echo json_encode(['state' => null, 'at' => null]);
        exit;
    }
    $state = json_decode((string) $row['v'], true);
    echo json_encode(['state' => is_array($state) ? $state : null, 'at' => (int) $row['ts']]);
    exit;
}

/* ---- write ------------------------------------------------------------ */
if (!infra_check_csrf()) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid request (bad CSRF token).']);
    exit;
}

$raw = (string) ($_POST['state'] ?? '');
if ($raw === '') {
    http_response_code(400);
    echo json_encode(['error' => 'No state posted.']);
    exit;
}
if (strlen($raw) > DFINDER_MAX) {
    http_response_code(413);
    echo json_encode(['error' => 'State is too large to save (' . round(strlen($raw) / 1048576, 1) . ' MB).']);
    exit;
}

// Parse before storing. An unparseable blob written here would come back on the
// next load and the workbench would fall through to a fresh, empty state — losing
// the real one, with nothing said. Refuse the write instead.
$parsed = json_decode($raw, true);
if (!is_array($parsed) || !isset($parsed['niches']) || !is_array($parsed['niches'])) {
    http_response_code(400);
    echo json_encode(['error' => 'That does not look like workbench state (no niches).']);
    exit;
}

infra_state_db()
    ->prepare('REPLACE INTO dfinder (k, v, ts) VALUES (?, ?, ?)')
    ->execute([DFINDER_KEY, $raw, time()]);

echo json_encode(['ok' => true, 'bytes' => strlen($raw), 'at' => time()]);
