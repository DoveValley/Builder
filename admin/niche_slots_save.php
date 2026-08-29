<?php
// Rename one of the 10 niche-slot labels shown on Gen-Visual's Niche Identity
// switcher (multisite/niche_slots.json). Deliberately separate from
// niche_brief_save.php: this title is a cosmetic switcher label only, never
// the AI content-generation `niche` vocabulary — renaming here must never
// touch any master site's niche_brief.json.
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

if (empty($_SESSION['admin_logged_in'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST only']);
    exit;
}
$token = $_POST['csrf_token'] ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid request token']);
    exit;
}

$slotsFile = BASE_DIR . '/multisite/niche_slots.json';

function niche_slots_load(string $file): array {
    $doc = @json_decode((string)@file_get_contents($file), true) ?: [];
    $slots = is_array($doc['slots'] ?? null) ? $doc['slots'] : [];
    // Self-healing: always exactly 10 slots, so a missing/short file never breaks the switcher.
    while (count($slots) < 10) $slots[] = ['site_id' => null, 'title' => ''];
    $slots = array_slice($slots, 0, 10);
    $doc['slots'] = array_values($slots);
    if (empty($doc['_about'])) {
        $doc['_about'] = 'The 10 niche slots shown on Gen-Visual, in display order. `title` is a COSMETIC label for '
            . 'the switcher pill only — it is independent of niche_brief.json\'s `niche` field (the AI '
            . 'content-generation vocabulary) and editing it here never touches that file.';
    }
    return $doc;
}

function niche_slots_write(string $file, array $doc): bool {
    $dir = dirname($file);
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) return false;
    $json = json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) return false;
    $tmp = $file . '.tmp.' . getmypid();
    if (file_put_contents($tmp, $json) === false) return false;
    return rename($tmp, $file);
}

$action = $_POST['action'] ?? 'rename';

if ($action === 'rename') {
    $index = (int)($_POST['index'] ?? -1);
    $title = trim((string)($_POST['title'] ?? ''));
    if ($index < 0 || $index > 9) { echo json_encode(['error' => 'Invalid slot index']); exit; }
    if ($title === '') { echo json_encode(['error' => 'Name cannot be blank']); exit; }
    if (mb_strlen($title) > 80) { echo json_encode(['error' => 'Name is too long']); exit; }

    $doc = niche_slots_load($slotsFile);
    $doc['slots'][$index]['title'] = $title;
    if (!niche_slots_write($slotsFile, $doc)) {
        echo json_encode(['error' => 'Could not write niche_slots.json']);
        exit;
    }
    echo json_encode(['success' => true, 'title' => $title]);
    exit;
}

echo json_encode(['error' => 'Unknown action']);
