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

    // The small, theme-independent stylesheet from Section 9 — brand colors
    // and the breadcrumb nav for now; the sticky call bar / quote-form CSS is
    // further Phase 2 polish. Every page block already renders as
    // <section class="lpc-{block_type}">, so one attribute-prefix selector
    // styles every current and future block type — no per-type CSS to keep
    // adding as the pool grows.
    // Resolved per pluscities_client_colors()'s own precedence (manual
    // override > detected from the client's live site > fixed fallback) —
    // the one function both this and the branding screen call, so "which
    // color wins" lives in exactly one place.
    $resolvedColors = pluscities_client_colors($client);
    $brandColor = $resolvedColors['accent'];
    $darkColor  = $resolvedColors['dark'];
    $style = '<style>.lpc-' . htmlspecialchars($client['class_prefix']) . '{color:' . htmlspecialchars($darkColor) . ';}'
        . '.lpc-breadcrumbs{font-size:.82rem;color:#52607a;margin:0 0 14px;}'
        . '.lpc-breadcrumbs a{color:#52607a;}'
        . '.lpc-hero-photo{width:100%;max-width:100%;height:auto;border-radius:10px;display:block;}'
        . '.pluscities-page{max-width:1000px;margin:0 auto;}'
        . '.pluscities-page section[class^="lpc-"]{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:24px 28px;margin:0 0 20px;box-shadow:0 1px 3px rgba(0,0,0,.04);}'
        . '.pluscities-page section.lpc-hero{background:transparent;border:none;box-shadow:none;padding:0 0 8px;}'
        // Hero: text left, photo right — matches the fleet's own reference
        // sites (Bailey/Wren/Gann). Stacks on narrow screens since this is a
        // thin plugin bundle with no separate mobile breakpoint system of
        // its own yet; flex-wrap is the simplest thing that degrades safely.
        . '.lpc-hero-row{display:flex;flex-wrap:wrap;gap:36px;align-items:center;}'
        . '.lpc-hero-text{flex:1 1 360px;}'
        . '.lpc-hero-media{flex:1 1 360px;}'
        . '.lpc-cta-button{display:inline-block;background:' . htmlspecialchars($brandColor) . ';color:#fff;font-weight:700;text-decoration:none;padding:14px 28px;border-radius:8px;margin-top:8px;}'
        . '.pluscities-page h1{font-size:2.1rem;font-weight:800;color:' . htmlspecialchars($darkColor) . ';line-height:1.2;margin:0 0 14px;}'
        // Trust bar — a full-width strip right under the hero, same spot as
        // the fleet's own reference sites' checkmark row. Dark, not a card,
        // so it reads as a distinct banner rather than another content block.
        . '.lpc-trust-bar{background:' . htmlspecialchars($darkColor) . ';border-radius:12px;margin:0 0 20px;padding:18px 24px;}'
        . '.lpc-trust-bar ul{list-style:none;margin:0;padding:0;display:flex;flex-wrap:wrap;gap:12px 32px;justify-content:center;}'
        . '.lpc-trust-bar li{color:#fff;font-weight:600;font-size:.92rem;white-space:nowrap;}'
        . '.pluscities-page section[class^="lpc-"] h2,.pluscities-page section[class^="lpc-"] h3{font-size:1.25rem;font-weight:700;color:' . htmlspecialchars($darkColor) . ';margin:0 0 14px;padding-bottom:10px;border-bottom:2px solid ' . htmlspecialchars($brandColor) . ';display:inline-block;}'
        . '.pluscities-page section[class^="lpc-"] p{line-height:1.65;color:#334155;margin:0 0 12px;}'
        . '.pluscities-page section[class^="lpc-"] ul{padding-left:20px;line-height:1.7;color:#334155;}'
        // FAQ — a two-column grid of rounded, tinted boxes, native
        // <details>/<summary> accordion (no JS), matching the fleet's own
        // reference sites rather than a plain left-accent-bar list.
        . '.lpc-faq-grid{display:grid;grid-template-columns:1fr;gap:12px;}'
        . '@media(min-width:720px){.lpc-faq-grid{grid-template-columns:1fr 1fr;}}'
        . '.pluscities-page .lpc-faq-item{background:#f1f5f9;border-radius:10px;padding:16px 18px;margin:0;}'
        . '.pluscities-page .lpc-faq-item summary{font-size:1.02rem;font-weight:700;color:' . htmlspecialchars($darkColor) . ';cursor:pointer;list-style:none;}'
        . '.pluscities-page .lpc-faq-item summary::-webkit-details-marker{display:none;}'
        . '.pluscities-page .lpc-faq-item summary::before{content:"+";display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;border-radius:50%;background:' . htmlspecialchars($brandColor) . ';color:#fff;font-weight:700;margin-right:10px;}'
        . '.pluscities-page .lpc-faq-item[open] summary::before{content:"\\2212";}'
        . '.pluscities-page .lpc-faq-item p{margin:10px 0 0 32px;}'
        // Related Services — full-width accent band, white pill-shaped links.
        . '.pluscities-page section.lpc-service-area{background:' . htmlspecialchars($brandColor) . ';text-align:center;}'
        . '.pluscities-page section.lpc-service-area h2{color:#fff;border-bottom:none;padding-bottom:0;}'
        . '.pluscities-page section.lpc-service-area p{color:#fff;opacity:.9;}'
        . '.lpc-service-area-links{display:flex;flex-wrap:wrap;gap:10px;justify-content:center;margin-top:12px;}'
        . '.lpc-service-area-links a{background:#fff;color:' . htmlspecialchars($brandColor) . ';font-weight:700;text-decoration:none;padding:10px 20px;border-radius:999px;font-size:.9rem;}'
        // CTA band — solid dark, right before FAQ.
        . '.pluscities-page section.lpc-cta-band{background:' . htmlspecialchars($darkColor) . ';text-align:center;padding:36px 28px;}'
        . '.pluscities-page section.lpc-cta-band h2{color:#fff;border-bottom:none;padding-bottom:0;font-size:1.5rem;}'
        . '.lpc-cta-band-button{display:inline-block;background:transparent;border:2px solid #fff;color:#fff;font-weight:700;text-decoration:none;padding:12px 28px;border-radius:999px;margin-top:8px;}'
        // Niche data table — a real styled table instead of a bare browser
        // default: bordered cells, a tinted header row, alternating stripes.
        . '.pluscities-page table{width:100%;border-collapse:collapse;margin-top:4px;}'
        . '.pluscities-page table th,.pluscities-page table td{padding:10px 14px;border:1px solid #e5e7eb;text-align:left;font-size:.92rem;}'
        . '.pluscities-page table th{background:#f8fafc;font-weight:700;color:' . htmlspecialchars($darkColor) . ';}'
        . '.pluscities-page table tbody tr:nth-child(even){background:#fafbfc;}'
        . '</style>';

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
