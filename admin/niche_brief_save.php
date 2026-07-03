<?php
// Save the per-niche brief (sites/{master}/multisite/niche_brief.json) and/or
// compile it into the master's ai_block_types.json.
// POST only. CSRF protected. Active site = the campaign master (one niche).

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

if (empty($_SESSION['admin_logged_in'])) { header('Location: login.php'); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: index.php?tab=niche_brief'); exit; }

$token = $_POST['csrf_token'] ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
    header('Location: index.php?tab=niche_brief&msg=error:Invalid+request+token');
    exit;
}
if (!ACTIVE_SITE_ID) { header('Location: sites.php'); exit; }

$action    = $_POST['action'] ?? 'save';
$briefFile = ACTIVE_SITE_DIR . '/multisite/niche_brief.json';

function _nb_redirect(string $type, string $text): void {
    header('Location: index.php?tab=niche_brief&msg=' . $type . ':' . rawurlencode($text));
    exit;
}

/** Atomic JSON write. */
function _nb_write(string $file, array $data): bool {
    $dir = dirname($file);
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) return false;
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) return false;
    $tmp = $file . '.tmp.' . getmypid();
    if (file_put_contents($tmp, $json) === false) return false;
    return rename($tmp, $file);
}

// ── Compile: regenerate ai_block_types.json from the saved brief ──────────────
if ($action === 'compile') {
    if (!file_exists($briefFile)) _nb_redirect('error', 'Save a brief before compiling.');
    require_once BASE_DIR . '/multisite/ai/compile.php';
    $res = ms_ai_compile_master(BASE_DIR, ACTIVE_SITE_ID);
    if (empty($res['ok'])) {
        _nb_redirect('error', 'Compile failed: ' . implode('; ', $res['errors'] ?: ['unknown error']));
    }
    $msg = 'Compiled ' . count($res['written']) . ' block type(s) into the registry.';
    if (!empty($res['skipped'])) $msg .= ' Skipped: ' . count($res['skipped']) . '.';
    _nb_redirect('success', $msg);
}

// ── Save the brief from the form ──────────────────────────────────────────────
$offerings = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $_POST['offerings'] ?? '')), 'strlen'));

// Only accept archetype ids that actually exist in the shared registry.
$archAll = json_decode((string)@file_get_contents(BASE_DIR . '/multisite/ai/archetypes.json'), true) ?: [];
$validArch = array_values(array_filter(array_keys($archAll), fn($k) => is_string($k) && $k !== '' && $k[0] !== '_'));
$enabled = array_values(array_intersect($validArch, (array)($_POST['enabled_archetypes'] ?? [])));

$brief = [
    'niche'               => trim($_POST['niche'] ?? ''),
    'master_site'         => ACTIVE_SITE_ID,
    'business_descriptor' => trim($_POST['business_descriptor'] ?? ''),
    'service_noun'        => trim($_POST['service_noun'] ?? ''),
    'customer_noun'       => trim($_POST['customer_noun'] ?? ''),
    'offerings'           => $offerings,
    'local_angle'         => trim($_POST['local_angle'] ?? ''),
    'tone'                => trim($_POST['tone'] ?? ''),
    'guardrails'          => trim($_POST['guardrails'] ?? ''),
    'uses_research_fields' => !empty($_POST['uses_research_fields']),
    'research_prompt'     => trim($_POST['research_prompt'] ?? ''),
    'enabled_archetypes'  => $enabled,
];

if ($brief['service_noun'] === '') _nb_redirect('error', 'Service noun is required.');
if (!$brief['enabled_archetypes'])  _nb_redirect('error', 'Enable at least one archetype.');

if (!_nb_write($briefFile, $brief)) _nb_redirect('error', 'Could not write the niche brief file.');

// Save + compile in one click when requested.
if (($_POST['then_compile'] ?? '') === '1') {
    require_once BASE_DIR . '/multisite/ai/compile.php';
    $res = ms_ai_compile_master(BASE_DIR, ACTIVE_SITE_ID);
    if (!empty($res['ok'])) {
        _nb_redirect('success', 'Brief saved and compiled ' . count($res['written']) . ' block type(s).');
    }
    _nb_redirect('error', 'Brief saved, but compile failed: ' . implode('; ', $res['errors'] ?: ['unknown']));
}

_nb_redirect('success', 'Niche brief saved. Click Compile to regenerate the registry.');
