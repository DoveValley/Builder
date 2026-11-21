<?php
/**
 * admin/pluscities_sync.php — the publish/plugin sync endpoint from
 * docs/PLUSCITIES_FEATURE_SPEC.md Section 9: a public, API-key-authenticated
 * endpoint (not session-based — the caller is a WordPress plugin on a
 * client's own site, never a logged-in admin).
 *
 * Rendering lives on THIS side, server-side (the architecture reversal from
 * Section 9) — the plugin only fetches, caches, and places finished HTML. Key
 * goes in a request header, not the URL, per Section 9's auth fix.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

function pluscities_sync_fail(int $code, string $msg): void
{
    http_response_code($code);
    echo json_encode(['error' => $msg]);
    exit;
}

// Header auth, not URL — Section 9's fix for today's bare-key-in-URL plugin.
$headers = function_exists('getallheaders') ? getallheaders() : [];
$authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
if (!preg_match('/^Bearer\s+(.+)$/i', trim($authHeader), $m)) {
    pluscities_sync_fail(401, 'Missing or malformed Authorization header.');
}
$apiKey = $m[1];

// API keys live outside client.json (PLUSCITIES_CLIENTS_SECRETS_DIR, gitignored)
// — see config.php's comment. Match by client_id, not by scanning key content
// across every secret file, so a key collision can't accidentally authenticate
// as the wrong client.
$client = null;
foreach (glob(PLUSCITIES_CLIENTS_DIR . '*.json') as $f) {
    $clientId   = basename($f, '.json');
    $secretFile = PLUSCITIES_CLIENTS_SECRETS_DIR . $clientId . '.txt';
    if (!is_file($secretFile)) continue;
    if (hash_equals(trim((string) file_get_contents($secretFile)), $apiKey)) {
        $client = json_decode((string) file_get_contents($f), true);
        break;
    }
}
if ($client === null) pluscities_sync_fail(401, 'Invalid API key.');

$action = $_GET['action'] ?? '';
$db     = pluscities_db();

if ($action === 'manifest') {
    $sinceRev = (int) ($_GET['site_rev'] ?? 0);
    $changed  = [];
    // Phase 1 has one client/page, so "changed since rev N" is simply "is the
    // client's current site_rev ahead of what the plugin already has" — the
    // real per-page diff (Phase 2) would instead track which revision each
    // page's live_revision_id moved to since the plugin's last-seen rev.
    if ((int) $client['site_rev'] > $sinceRev) {
        $st = $db->prepare('SELECT page_id, live_revision_id, slug FROM pages WHERE client_id = ? AND status = "live"');
        $st->execute([$client['client_id']]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $changed[] = ['page_id' => $p['page_id'], 'revision' => (int) $p['live_revision_id'], 'url' => $p['slug']];
        }
    }
    echo json_encode(['site_rev' => (int) $client['site_rev'], 'changed_pages' => $changed]);
    exit;
}

if ($action === 'page') {
    $pageId = $_GET['page_id'] ?? '';
    $st = $db->prepare('SELECT * FROM pages WHERE page_id = ? AND client_id = ? AND status = "live"');
    $st->execute([$pageId, $client['client_id']]);
    $page = $st->fetch(PDO::FETCH_ASSOC);
    if (!$page) pluscities_sync_fail(404, 'Page not found or not live.');

    $niche = pluscities_niche_resolved($client['niche_id']);

    $blocksStmt = $db->prepare('SELECT * FROM blocks WHERE page_id = ? ORDER BY position');
    $blocksStmt->execute([$pageId]);
    $blocks = $blocksStmt->fetchAll(PDO::FETCH_ASSOC);
    $html = '';
    foreach ($blocks as $b) {
        $html .= pluscities_render_block($b, $client, $niche);
    }

    $businessName = $client['identity']['business_name'] ?? '';
    $serviceLabel = $page['service_key'];
    foreach (($niche['services'] ?? []) as $svc) {
        if ($svc['slug'] === $page['service_key']) { $serviceLabel = $svc['label']; break; }
    }
    // Real "Frisco, TX" label — city_id is an internal slug, never shown to
    // anyone (spec Section 5's Title/H1/schema all need the real name).
    $cityLabel = pluscities_city_label($page['city_id']);

    // The small, theme-independent stylesheet from Section 9 — brand colors
    // and the breadcrumb nav for now; the sticky call bar / quote-form CSS is
    // further Phase 2 polish.
    $style = '<style>.lpc-' . htmlspecialchars($client['class_prefix']) . '{color:#1C2B45;}'
        . '.lpc-breadcrumbs{font-size:.82rem;color:#52607a;margin:0 0 14px;}'
        . '.lpc-breadcrumbs a{color:#52607a;}'
        . '.lpc-hero-photo{width:100%;max-width:100%;height:auto;border-radius:10px;margin:0 0 16px;display:block;}</style>';

    $schema = pluscities_assemble_schema($page, $client, $niche, $blocks, $page['slug']);
    $breadcrumbHtml = pluscities_breadcrumb_nav_html($page, $client, $cityLabel, $serviceLabel);

    echo json_encode([
        'html'  => $breadcrumbHtml . $html,
        'style' => $style,
        'head'  => [
            'title'            => $serviceLabel . ' in ' . $cityLabel . ' | ' . $businessName,
            'meta_description' => 'Professional ' . $serviceLabel . ' in ' . $cityLabel . ' from ' . $businessName . '.',
            'schema'           => $schema,
        ],
    ]);
    exit;
}

pluscities_sync_fail(400, 'Unknown action.');
