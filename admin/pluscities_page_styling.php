<?php
/**
 * admin/pluscities_page_styling.php — previews each real block type's actual
 * styling for ONE specific client, using that client's own real branding
 * colors (not a generic placeholder) — same pluscities_render_block()/
 * pluscities_page_style_css() production functions the real delivery
 * endpoint (admin/pluscities_sync.php) uses, so what's shown here is
 * byte-identical CSS to what this client's live pages actually get.
 *
 * Content itself is still placeholder example text (no block has more than
 * one real style today, so this is a preview surface, not a picker yet) —
 * only the colors are real. Moved here from Platform Information's "Page
 * Styling" tab (2026-11-23, Scott's call) since a per-client preview using
 * that client's real branding is more useful than one generic unbranded
 * example — lives next to Service Area Page in the per-client action row
 * (admin/pluscities_clients.php), not in the platform-wide reference docs.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/pluscities_block_catalog.php';
require_once __DIR__ . '/../includes/pluscities_writer.php';
require_once __DIR__ . '/../includes/pluscities_color_detect.php';
require_once __DIR__ . '/lpc_admin_layout.php';

if (!lpc_actor_is_authenticated() || lpc_current_actor_role() === 'end_client') {
    header('Location: login.php');
    exit;
}

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
    $catalog = platform_info_city_page_blocks_data();
    $selectedSlug = $_GET['block'] ?? 'hero';
    $selectedLabel = 'Hero';
    foreach ($catalog as $section) {
        foreach ($section['rows'] as $r) {
            if ($r['slug'] === $selectedSlug) { $selectedLabel = $r['block']; break 2; }
        }
    }
    $blockRow = pluscities_page_styling_sample_block_row($selectedSlug, $selectedLabel, $client);
    if ($selectedSlug === 'trust_bar') {
        $previewHtml = pluscities_render_trust_bar(['Fast Local Response', '24/7 Emergency Line', 'Licensed & Insured']);
    } elseif ($selectedSlug === 'cta_band') {
        $previewHtml = pluscities_render_cta_band($client);
    } else {
        $previewHtml = pluscities_render_block($blockRow, $client, null);
    }
    $previewCss = pluscities_page_style_css($client);
    $previewDoc = '<!DOCTYPE html><html><head><meta charset="utf-8">' . $previewCss
        . '<style>body{margin:0;padding:24px;font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;}</style>'
        . '</head><body><div class="pluscities-page">' . $previewHtml . '</div></body></html>';
?>
<?php pluscities_client_subtabs($clientId, 'page_styling'); ?>
<div class="pc-card">
    <h3 style="margin-top:0;">Page Styling — <?= h($client['identity']['business_name'] ?? $clientId) ?></h3>
    <p style="color:var(--pc-muted,#52607a);font-size:.84rem;">
        Renders a real block through the exact same functions production uses
        (<code>pluscities_render_block()</code> + <code>pluscities_page_style_css()</code>) with
        placeholder example content, but <strong>this client's own real brand colors</strong> — byte-
        identical CSS to what <?= h($client['domain'] ?? 'this client\'s site') ?>'s live pages actually
        get. This is a style <strong>preview</strong> only today, not a picker — every block has exactly
        one style right now. Once a block has more than one real style built, picking between them
        belongs here next.
    </p>
    <form method="get" style="margin:0 0 16px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
        <input type="hidden" name="client_id" value="<?= h($clientId) ?>">
        <label style="font-size:.85rem;font-weight:600;color:#374151;">Block type:
            <select name="block" onchange="this.form.submit()" style="margin-left:8px;padding:6px 10px;border-radius:6px;border:1px solid #d1d5db;font-size:.85rem;">
                <?php foreach ($catalog as $section): ?>
                    <optgroup label="<?= h($section['title']) ?>">
                        <?php foreach ($section['rows'] as $r): ?>
                            <option value="<?= h($r['slug']) ?>" <?= $r['slug'] === $selectedSlug ? 'selected' : '' ?>><?= h($r['block']) ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                <?php endforeach; ?>
                <optgroup label="Platform furniture — always-on, not part of the pick-N pools">
                    <option value="trust_bar" <?= $selectedSlug === 'trust_bar' ? 'selected' : '' ?>>Trust bar</option>
                    <option value="cta_band" <?= $selectedSlug === 'cta_band' ? 'selected' : '' ?>>CTA band</option>
                </optgroup>
            </select>
        </label>
        <noscript><button type="submit" class="btn-open">Preview</button></noscript>
    </form>
    <div style="border:1px solid #e5e7eb;border-radius:10px;overflow:hidden;background:#f8fafc;">
        <iframe srcdoc="<?= htmlspecialchars($previewDoc) ?>" style="width:100%;height:600px;border:0;display:block;background:#fff;"></iframe>
    </div>
</div>
<?php endif; ?>
<?php lpc_admin_close(); ?>
