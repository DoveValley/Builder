<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/lpc_admin_layout.php';

if (!lpc_actor_is_authenticated()) {
    header('Location: client_login.php');
    exit;
}
// Read-only reporting only — an End Client has no business on the full
// admin (that's what the agency/Scott use). Anyone else lands back on
// their own real view rather than a page built for a different role.
if (lpc_current_actor_role() !== 'end_client') {
    header('Location: clients.php');
    exit;
}

$client_id = lpc_current_actor_client_id();
$client = lpc_load_client_for_current_actor($client_id);
if (!$client) {
    header('Location: client_login.php');
    exit;
}

$gscProperty = $client['reporting']['gsc_property'];
$gscReady = gsc_has_creds() && $gscProperty !== '';
$domain = $client['identity']['domain'];
$basePath = rtrim($client['delivery']['base_path'] ?: '/locations', '/');
$clickTotals = lpc_fetch_click_summary($client_id);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reporting — <?= h($client['identity']['business_name'] ?: $client_id) ?></title>
<link rel="stylesheet" href="../assets/css/style.css">
<?php $_adminCssMtime = css_minify_to(__DIR__ . '/../assets/css/admin.src.css', __DIR__ . '/../assets/css/admin.css'); ?>
<link rel="stylesheet" href="../assets/css/admin.css?v=<?= (int) $_adminCssMtime ?>">
<?php lpc_admin_head_assets(); ?>
<style>
.lpc-wrap { max-width: 900px; }
</style>
</head>
<?php lpc_admin_open('reporting', 'Reporting'); ?>
    <p style="color:#6b7280;font-size:.85rem;">
        How your landing pages are doing — read-only. "Tried to Contact" counts anyone who tapped
        a phone number or call-to-action button on one of your pages; it's an intent signal, not a
        confirmed call or lead.
    </p>

    <table class="lpc-matrix" style="width:100%;border-collapse:collapse;background:#fff;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;">
        <tr style="text-align:left;font-size:.74rem;color:#6b7280;background:#f9fafb;">
            <th style="padding:8px 12px;">City</th>
            <th style="padding:8px 12px;">Service</th>
            <th style="padding:8px 12px;">GSC Clicks</th>
            <th style="padding:8px 12px;">Tried to Contact</th>
        </tr>
        <?php
        $anyPublished = false;
        foreach ($client['target_cities'] as $tc):
            foreach ($tc['services'] as $sid):
                $pageId = $tc['city_id'] . '__' . $sid;
                $page = lpc_load_page($client_id, $pageId);
                if (!$page || $page['status'] !== 'published') continue;
                $anyPublished = true;
                $service = lpc_find_by_id($client['services'], 'service_id', $sid);
        ?>
        <tr style="border-top:1px solid #f1f5f9;font-size:.85rem;">
            <td style="padding:8px 12px;"><?= h($tc['city']) ?>, <?= h($tc['state']) ?></td>
            <td style="padding:8px 12px;"><?= h($service['label'] ?? $sid) ?></td>
            <td style="padding:8px 12px;">
                <?php
                if (!$gscReady) {
                    echo '<span style="color:#6b7280;">Not Connected</span>';
                } else {
                    $pageUrl = 'https://' . $domain . $basePath . '/' . $pageId;
                    $clicks = gsc_fetch_page_clicks($gscProperty, $pageUrl);
                    echo $clicks === null ? '<span style="color:#6b7280;">Not Connected</span>' : h((string) $clicks);
                }
                ?>
            </td>
            <td style="padding:8px 12px;color:#6b7280;"><?= h(lpc_click_summary_line($clickTotals, $pageId) ?: '—') ?></td>
        </tr>
        <?php endforeach; endforeach; ?>
        <?php if (!$anyPublished): ?>
        <tr><td colspan="4" style="padding:16px 12px;color:#6b7280;text-align:center;">No published pages yet.</td></tr>
        <?php endif; ?>
    </table>
<?php lpc_admin_close(); ?>
