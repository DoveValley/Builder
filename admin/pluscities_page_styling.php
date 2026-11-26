<?php
/**
 * admin/pluscities_page_styling.php — previews a FULL page (not one block at
 * a time) for ONE specific client, using that client's own real branding
 * colors and the same pluscities_render_block()/pluscities_page_style_css()/
 * action=page assembly logic admin/pluscities_sync.php uses — so what's shown
 * here is byte-identical CSS/HTML to what the client's live pages actually
 * get. Rebuilt 2026-11-25 (Scott's call) from an earlier version that only
 * rendered one block type at a time behind a dropdown — ergonomically that
 * meant you couldn't see the real rhythm of a page (spacing, section
 * borders, how a hero reads next to the trust bar above the fold).
 *
 * Two source modes for the page body:
 *   - a REAL page this client already has built (`?page_id=`) — real content,
 *     real block picks, exactly what's live/draft for them today.
 *   - a DEMO page — every block type in the catalog in spine/city/service
 *     order, placeholder content — shown when the client has no real pages
 *     yet, or picked explicitly from the selector.
 *
 * Still a style preview, not a picker — every block type has exactly one
 * style right now (pluscities_render_block() is a fixed switch/if-chain, no
 * `style` column on `blocks`). Once Hero gets real alternate styles built,
 * picking between them per-block belongs on this screen next.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/lpc_admin_layout.php';

if (!lpc_actor_is_authenticated() || lpc_current_actor_role() === 'end_client') {
    header('Location: login.php');
    exit;
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

$clientId = $_GET['client_id'] ?? '';
$clients  = pluscities_client_ids_for_current_actor();
$client   = pluscities_actor_can_access_client($clientId) ? ($clients[$clientId] ?? null) : null;

/**
 * One realistic example $block_row per real block slug (the shared catalog's
 * own 'slug'/'block' fields) — enough to preview pluscities_render_block()'s
 * actual visual treatment for every block type, not just the 6 it special-
 * cases. Clearly placeholder content, not meant to look like real approved
 * copy for this client.
 */
function pluscities_page_styling_sample_block_row(string $slug, string $label, array $client): array {
    $business = $client['identity']['business_name'] ?? 'Your Business';
    $phone    = $client['identity']['hubs'][0]['phone'] ?? '(555) 123-4567';
    $common   = ['block_type' => $slug, 'fact_ids' => '[]'];
    switch ($slug) {
        case 'hero':
            return $common + ['content_json' => json_encode([
                'heading' => 'Example Service in Example City, TX',
                'body' => "When the need strikes, $business provides fast, professional service to help you recover and rebuild.",
            ])];
        case 'faq':
            return $common + ['content_json' => json_encode(['faqs' => [
                ['question' => 'How quickly can you respond?', 'answer' => "$business offers 24/7 emergency response, typically arriving within 60-90 minutes of your call."],
                ['question' => 'Will my homeowners insurance cover this?', 'answer' => 'Most policies cover sudden damage. We provide documentation to support your claim, though final coverage decisions rest with your insurer.'],
            ]])];
        case 'service_area':
            return $common + ['content_json' => json_encode([
                'main_service_url' => '#', 'service_area_url' => '#',
                'other_services_this_city' => [['url' => '#', 'label' => 'Another Service']],
                'hub_address' => $client['identity']['hubs'][0]['address'] ?? '123 Main St, Example City, TX', 'drive_minutes' => 15,
            ])];
        case 'housing_stock':
            return $common + ['content_json' => json_encode([
                'intro' => "Example City's housing stock shapes how this need typically unfolds in local homes.",
                'dataset_values' => ['income_median' => 81393, 'home_value_median' => 322300, 'year_built_median' => 1983, 'homeownership_pct' => 51.5],
            ])];
        case 'niche_data_table':
            return $common + ['content_json' => json_encode([
                'title' => 'Typical Costs',
                'columns' => ['Category', 'Typical Range'],
                'rows' => [['Example line item', '$300 - $600'], ['Another line item', '$500 - $1,500']],
            ])];
        case 'reviews_from_city':
            return $common + ['content_json' => json_encode(['reviews' => [
                ['text' => 'Fast response and kept us informed the whole time.', 'author' => 'J. Martinez', 'rating' => 5],
            ]])];
        default:
            return $common + ['content_json' => json_encode([
                'heading' => $label,
                'body' => "Example body copy for the \"$label\" block — real pages generate this from researched facts about the city and service, not placeholder text like this.",
            ])];
    }
}

/**
 * Builds the ordered list of rendered sections for one page, given its block
 * rows (either real `blocks` table rows or synthetic sample rows — both
 * shapes satisfy pluscities_render_block()'s $block_row contract). Mirrors
 * admin/pluscities_sync.php's action=page loop exactly (trust bar right
 * after hero, CTA band right before FAQ) so a real page previewed here reads
 * identically to its live rendering.
 */
function pluscities_page_styling_build_sections(array $blockRows, array $client, ?array $niche, array $slugLabels, ?string $cityId = null): array {
    $sections = [];
    $i = 0;
    foreach ($blockRows as $row) {
        if ($row['block_type'] === 'faq') {
            $sections[] = ['id' => 'blk-' . $i++, 'label' => 'CTA band', 'html' => pluscities_render_cta_band($client)];
        }
        $label = $slugLabels[$row['block_type']] ?? ucwords(str_replace('_', ' ', $row['block_type']));
        $sections[] = ['id' => 'blk-' . $i++, 'label' => $label, 'html' => pluscities_render_block($row, $client, $niche, $i)];
        if ($row['block_type'] === 'hero' && !empty($niche['trust_bar_items'])) {
            $sections[] = ['id' => 'blk-' . $i++, 'label' => 'Trust bar', 'html' => pluscities_render_trust_bar($niche['trust_bar_items'])];
        }
        // City Info + Area Map — only resolvable for a REAL page (needs a
        // real city_id); the demo page (every block type, no specific city)
        // never shows either, same as they'd never show on a page that
        // doesn't exist yet. City Info first, matching the fleet's own
        // reference sites' observed ordering.
        if ($row['block_type'] === 'service_area' && $cityId) {
            $cityInfoHtml = pluscities_render_city_info($cityId);
            if ($cityInfoHtml !== '') {
                $sections[] = ['id' => 'blk-' . $i++, 'label' => 'City Info', 'html' => $cityInfoHtml];
            }
            $areaMapHtml = pluscities_render_area_map($cityId, $client['domain'] ?? '');
            if ($areaMapHtml !== '') {
                $sections[] = ['id' => 'blk-' . $i++, 'label' => 'Area Map', 'html' => $areaMapHtml];
            }
        }
        if ($row['block_type'] === 'housing_stock' && $cityId) {
            $chartHtml = pluscities_render_housing_chart($cityId, $client['domain'] ?? '');
            if ($chartHtml !== '') {
                $sections[] = ['id' => 'blk-' . $i++, 'label' => 'Housing Chart', 'html' => $chartHtml];
            }
        }
        if ($row['block_type'] === 'climate_data' && $cityId) {
            $climateHtml = pluscities_render_climate_chart($cityId, $client['niche_id'] ?? '', $client['domain'] ?? '');
            if ($climateHtml !== '') {
                $sections[] = ['id' => 'blk-' . $i++, 'label' => 'Climate Chart', 'html' => $climateHtml];
            }
        }
    }
    $safetyToolkitHtml = pluscities_render_safety_toolkit($niche ?? [], $client['domain'] ?? '');
    if ($safetyToolkitHtml !== '') {
        $sections[] = ['id' => 'blk-' . $i++, 'label' => 'Safety Toolkit', 'html' => $safetyToolkitHtml];
    }
    return $sections;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Page Styling — <?= h($client['identity']['business_name'] ?? 'PlusCities') ?></title>
<link rel="stylesheet" href="../assets/css/style.css">
<?php $_adminCssMtime = css_minify_to(__DIR__ . '/../assets/css/admin.src.css', __DIR__ . '/../assets/css/admin.css'); ?>
<link rel="stylesheet" href="../assets/css/admin.css?v=<?= (int) $_adminCssMtime ?>">
<?php lpc_admin_head_assets(); ?>
</head>
<?php
lpc_admin_open('pluscities_clients', 'Page Styling');
?>
<style>
.pc-card{background:#fff;border:1px solid var(--pc-line,#e2e8f2);border-radius:14px;padding:18px 20px;margin-bottom:16px;}
</style>

<?php if (!$client): ?>
    <div class="lpc-empty"><h2>Client not found</h2><p>No client with id <code><?= h($clientId) ?></code> (or you don't have access to it).</p></div>
<?php else:
    $niche = pluscities_niche_resolved($client['niche_id'] ?? '');
    $catalog = platform_info_city_page_blocks_data();
    $slugLabels = [];
    $citySlugs = [];
    $serviceSlugs = [];
    foreach ($catalog as $section) {
        foreach ($section['rows'] as $r) { $slugLabels[$r['slug']] = $r['block']; }
        if (stripos($section['title'], 'city pool') !== false)    { foreach ($section['rows'] as $r) $citySlugs[] = $r['slug']; }
        if (stripos($section['title'], 'service pool') !== false) { foreach ($section['rows'] as $r) $serviceSlugs[] = $r['slug']; }
    }

    $db = pluscities_db();
    $pagesStmt = $db->prepare('SELECT page_id, city_id, service_key, status FROM pages WHERE client_id = ? ORDER BY (status = "live") DESC, city_id, service_key');
    $pagesStmt->execute([$clientId]);
    $realPages = $pagesStmt->fetchAll(PDO::FETCH_ASSOC);

    $requestedPageId = $_GET['page_id'] ?? '';
    $selectedPage = null;
    if ($requestedPageId !== '' && $requestedPageId !== 'demo') {
        foreach ($realPages as $p) { if ($p['page_id'] === $requestedPageId) { $selectedPage = $p; break; } }
    } elseif ($requestedPageId === '' && $realPages) {
        $selectedPage = $realPages[0];
    }

    if ($selectedPage) {
        $blocksStmt = $db->prepare('SELECT * FROM blocks WHERE page_id = ? ORDER BY position');
        $blocksStmt->execute([$selectedPage['page_id']]);
        $blockRows = $blocksStmt->fetchAll(PDO::FETCH_ASSOC);
        $cityLabel = pluscities_city_label($selectedPage['city_id']);
        $serviceLabel = $selectedPage['service_key'];
        foreach (($niche['services'] ?? []) as $svc) {
            if ($svc['slug'] === $selectedPage['service_key']) { $serviceLabel = $svc['label']; break; }
        }
        $heading = $serviceLabel . ' in ' . $cityLabel . ' — ' . ucfirst($selectedPage['status']);
    } else {
        $orderedSlugs = array_merge(['hero', 'overview'], $citySlugs, $serviceSlugs, ['faq', 'service_area']);
        $blockRows = [];
        foreach ($orderedSlugs as $slug) {
            $blockRows[] = pluscities_page_styling_sample_block_row($slug, $slugLabels[$slug] ?? $slug, $client);
        }
        $heading = $realPages ? 'Demo page — every block type in the catalog' : 'Demo page — this client has no real pages built yet';
    }

    $sections = pluscities_page_styling_build_sections($blockRows, $client, $niche, $slugLabels, $selectedPage['city_id'] ?? null);
    $previewCss = pluscities_page_style_css($client);

    // A horizontal chip strip, not a second vertical rail — a vertical list
    // of bold nav-style links sitting directly beside the real PlusCities
    // left sidebar reads as a duplicate menu at a glance (Scott's real
    // confusion testing this live, 2026-11-25). Jump-to-block still works
    // the same way (plain #anchor links), just laid out so it can't be
    // mistaken for site navigation.
    $bodyHtml = '<div class="pps-jumpbar">Jump to block: ';
    foreach ($sections as $s) {
        $bodyHtml .= '<a href="#' . htmlspecialchars($s['id']) . '">' . htmlspecialchars($s['label']) . '</a>';
    }
    $bodyHtml .= '</div><div class="pluscities-page pps-content">';
    $blockNum = 0;
    foreach ($sections as $s) {
        $blockNum++;
        $bodyHtml .= '<div id="' . htmlspecialchars($s['id']) . '">'
            . '<div class="pps-blocknum">Block ' . $blockNum . '</div>'
            . $s['html'] . '</div>';
    }
    $bodyHtml .= '</div>';

    $previewDoc = '<!DOCTYPE html><html><head><meta charset="utf-8">' . $previewCss
        . '<style>
            body{margin:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;}
            .pps-jumpbar{position:sticky;top:0;z-index:1;background:#f8fafc;border-bottom:1px solid #e5e7eb;padding:10px 24px;font-size:.78rem;font-weight:700;color:#52607a;text-transform:uppercase;letter-spacing:.03em;}
            .pps-jumpbar a{display:inline-block;margin:4px 6px 0 0;padding:5px 12px;border-radius:999px;background:#e2e8f2;font-size:.78rem;font-weight:600;text-transform:none;letter-spacing:0;color:#334155;text-decoration:none;}
            .pps-jumpbar a:hover{background:#cbd5e1;}
            .pps-content{padding:24px;}
            .pps-blocknum{font-size:.72rem;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.05em;margin:0 0 6px;}
          </style>'
        // A fragment-only <a href="#blk-N"> inside a srcdoc iframe resolves
        // against the EMBEDDING page's URL, not "about:srcdoc" (a documented
        // iframe-srcdoc base-URL quirk) — since that doesn't match this
        // document's own URL, the browser treats a click as a real cross-
        // document navigation instead of an in-page scroll, so the iframe
        // actually navigates to the admin page's own URL and loads a second
        // full copy of this whole screen inside itself (reproduced live,
        // 2026-11-25 — Scott caught it, screenshots confirmed the nested
        // copy). Intercepting the click and scrolling manually sidesteps the
        // quirk entirely, since the browser never attempts that navigation.
        . '<script>
            document.addEventListener("click", function(e){
              var a = e.target.closest(".pps-jumpbar a");
              if (!a) return;
              e.preventDefault();
              var el = document.getElementById(a.getAttribute("href").slice(1));
              if (el) el.scrollIntoView({behavior: "smooth", block: "start"});
            });
          </script>'
        . '</head><body>' . $bodyHtml . '</body></html>';
?>
<?php pluscities_client_subtabs($clientId, 'page_styling'); ?>
<div class="pc-card">
    <h3 style="margin-top:0;">Page Styling — <?= h($client['identity']['business_name'] ?? $clientId) ?></h3>
    <p style="color:var(--pc-muted,#52607a);font-size:.84rem;">
        Renders a full page through the exact same functions production uses
        (<code>pluscities_render_block()</code> + <code>pluscities_page_style_css()</code>) with
        <strong>this client's own real brand colors</strong> — byte-identical CSS to what
        <?= h($client['domain'] ?? 'this client\'s site') ?>'s live pages actually get. Pick one of this
        client's real pages below to see exactly what's live/draft for them, or the demo page if none
        exist yet. Still a style <strong>preview</strong> for most blocks — only Hero has more than one
        real style so far; picking between the rest belongs here once they're built too.
    </p>
    <form method="get" style="margin:0 0 10px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
        <input type="hidden" name="client_id" value="<?= h($clientId) ?>">
        <label style="font-size:.85rem;font-weight:600;color:#374151;">Page:
            <select name="page_id" onchange="this.form.submit()" style="margin-left:8px;padding:6px 10px;border-radius:6px;border:1px solid #d1d5db;font-size:.85rem;">
                <?php foreach ($realPages as $p): ?>
                    <option value="<?= h($p['page_id']) ?>" <?= $selectedPage && $selectedPage['page_id'] === $p['page_id'] ? 'selected' : '' ?>>
                        <?= h(pluscities_city_label($p['city_id'])) ?> / <?= h($p['service_key']) ?> (<?= h($p['status']) ?>)
                    </option>
                <?php endforeach; ?>
                <option value="demo" <?= !$selectedPage ? 'selected' : '' ?>>— Demo: every block type —</option>
            </select>
        </label>
        <span style="font-size:.84rem;color:#52607a;"><?= h($heading) ?></span>
        <noscript><button type="submit" class="btn-open">Preview</button></noscript>
    </form>
    <form method="post" action="pluscities_page_styling_save.php" style="margin:0 0 14px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
        <input type="hidden" name="client_id" value="<?= h($clientId) ?>">
        <?php if ($selectedPage): ?><input type="hidden" name="page_id" value="<?= h($selectedPage['page_id']) ?>"><?php endif; ?>
        <label style="font-size:.85rem;font-weight:600;color:#374151;">Hero style:
            <?php $heroStyle = $client['block_styles']['hero'] ?? 'two_column'; ?>
            <select name="hero_style" onchange="this.form.submit()" style="margin-left:8px;padding:6px 10px;border-radius:6px;border:1px solid #d1d5db;font-size:.85rem;">
                <option value="two_column" <?= $heroStyle === 'two_column' ? 'selected' : '' ?>>Two-column (text + photo)</option>
                <option value="full_bleed" <?= $heroStyle === 'full_bleed' ? 'selected' : '' ?>>Full-bleed (photo background)</option>
            </select>
        </label>
        <span style="font-size:.78rem;color:#52607a;">Applies to every page this client has — style isn't picked per-page.</span>
        <noscript><button type="submit" class="btn-open">Save</button></noscript>
    </form>
    <div style="border:1px solid #e5e7eb;border-radius:10px;overflow:hidden;background:#f8fafc;">
        <iframe srcdoc="<?= htmlspecialchars($previewDoc) ?>" style="width:100%;height:80vh;border:0;display:block;background:#fff;"></iframe>
    </div>
</div>
<?php endif; ?>
<?php lpc_admin_close(); ?>
