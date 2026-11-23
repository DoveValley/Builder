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
    $livePageIds = [];
    // Phase 1 has one client/page, so "changed since rev N" is simply "is the
    // client's current site_rev ahead of what the plugin already has" — the
    // real per-page diff (Phase 2) would instead track which revision each
    // page's live_revision_id moved to since the plugin's last-seen rev.
    if ((int) $client['site_rev'] > $sinceRev) {
        $st = $db->prepare('SELECT page_id, live_revision_id, slug FROM pages WHERE client_id = ? AND status = "live"');
        $st->execute([$client['client_id']]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $changed[] = ['page_id' => $p['page_id'], 'revision' => (int) $p['live_revision_id'], 'url' => $p['slug']];
            $livePageIds[] = $p['page_id'];
        }
    }
    // live_page_ids is the full current live set (not just what changed) --
    // the plugin's ground truth for reconciling away anything it cached
    // earlier that's no longer live (e.g. unpublished), since nothing else
    // in this protocol ever signals a removal.
    echo json_encode(['site_rev' => (int) $client['site_rev'], 'changed_pages' => $changed, 'live_page_ids' => $livePageIds]);
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
        // CTA band sits right before FAQ — design doc rhythm rule, fixed
        // position rather than a rhythm engine deciding where to put it.
        if ($b['block_type'] === 'faq') {
            $html .= pluscities_render_cta_band($client);
        }
        $html .= pluscities_render_block($b, $client, $niche);
        // Trust bar sits right under the hero, same position as the fleet's
        // own reference sites (Bailey/Wren/Gann) — niche-level content
        // (generically true for any real client in the niche), not a page
        // block of its own.
        if ($b['block_type'] === 'hero' && !empty($niche['trust_bar_items'])) {
            $html .= pluscities_render_trust_bar($niche['trust_bar_items']);
        }
    }

    $businessName = $client['identity']['business_name'] ?? '';
    $serviceLabel = $page['service_key'];
    foreach (($niche['services'] ?? []) as $svc) {
        if ($svc['slug'] === $page['service_key']) { $serviceLabel = $svc['label']; break; }
    }
    // Real "Frisco, TX" label — city_id is an internal slug, never shown to
    // anyone (spec Section 5's Title/H1/schema all need the real name).
    $cityLabel = pluscities_city_label($page['city_id']);

    // Factored into pluscities_page_style_css() (includes/pluscities_writer.
    // php, 2026-11-23) so this endpoint and any admin-side preview render
    // from the exact same CSS, not a drifting second copy.
    $style = pluscities_page_style_css($client);

    $schema = pluscities_assemble_schema($page, $client, $niche, $blocks, $page['slug']);
    $breadcrumbHtml = pluscities_breadcrumb_nav_html($page, $client, $cityLabel, $serviceLabel);

    echo json_encode([
        'html'  => $breadcrumbHtml . $html,
        'style' => $style,
        'head'  => [
            'title'            => $serviceLabel . ' in ' . $cityLabel . ' | ' . $businessName,
            'meta_description' => 'Professional ' . $serviceLabel . ' in ' . $cityLabel . ' from ' . $businessName . '.',
            'schema'           => $schema,
            // Built here, not on the plugin side — the client's own domain is
            // already known server-side (same source BreadcrumbList's absolute
            // URLs already use), and per Section 9 the plugin only places what
            // it's given rather than deriving URLs itself.
            'canonical'        => 'https://' . $client['domain'] . $page['slug'],
        ],
    ]);
    exit;
}

if ($action === 'hub') {
    $niche = pluscities_niche_resolved($client['niche_id']);
    $businessName = $client['identity']['business_name'] ?? '';
    $resolvedColors = pluscities_client_colors($client);
    $brandColor = $resolvedColors['accent'];
    $darkColor  = $resolvedColors['dark'];

    // Deliberately a small, dedicated stylesheet — not the full page style
    // string from `action=page`, which carries ~70 lines of FAQ/trust-bar/
    // table CSS the hub never uses.
    $style = '<style>'
        . '.pluscities-page{max-width:1000px;margin:0 auto;}'
        . '.pluscities-page h1{font-size:2.1rem;font-weight:800;color:' . htmlspecialchars($darkColor) . ';line-height:1.2;margin:0 0 14px;}'
        . '.lpc-hub-intro{line-height:1.65;color:#334155;margin:0 0 20px;}'
        . '.lpc-hub-empty{color:#6b7280;font-style:italic;}'
        . '.lpc-hub-grid{display:grid;grid-template-columns:1fr;gap:16px;}'
        . '@media(min-width:720px){.lpc-hub-grid{grid-template-columns:1fr 1fr 1fr;}}'
        . '.lpc-hub-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:20px 22px;box-shadow:0 1px 3px rgba(0,0,0,.04);}'
        . '.lpc-hub-card h3{font-size:1.1rem;font-weight:700;color:' . htmlspecialchars($darkColor) . ';margin:0 0 10px;padding-bottom:8px;border-bottom:2px solid ' . htmlspecialchars($brandColor) . ';}'
        . '.lpc-hub-card ul{list-style:none;margin:0;padding:0;}'
        . '.lpc-hub-card li{margin:0 0 6px;}'
        . '.lpc-hub-card a{color:' . htmlspecialchars($brandColor) . ';font-weight:600;text-decoration:none;}'
        . '.lpc-hub-footer{margin-top:24px;color:#52607a;font-size:.9rem;}'
        . '</style>';

    $basePath = '/' . trim($client['url_base_path'] ?? 'service-area', '/') . '/';

    echo json_encode([
        'html'  => '<h1>Service Areas</h1>' . pluscities_render_hub_blocks($client, $niche),
        'style' => $style,
        'head'  => [
            'title'            => 'Service Areas | ' . $businessName,
            'meta_description' => $businessName . ' serves the following areas.',
            'schema'           => [],
            'canonical'        => 'https://' . $client['domain'] . $basePath,
        ],
    ]);
    exit;
}

pluscities_sync_fail(400, 'Unknown action.');
