<?php
/**
 * admin/pluscities_hub_edit.php — edits a client's hub/listing page text
 * (`hub_content.top_text` / `footer_text`). The hub page itself (which cities
 * it lists) is derived automatically from the client's live pages — this
 * screen only edits the configurable text around that list. New 2026-11-22,
 * see project_lpc_to_pluscities_migration in memory ("Project B").
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

$alert = null;
if (!empty($_GET['msg'])) {
    $raw = $_GET['msg'];
    if (strpos($raw, ':') !== false) {
        [$type, $text] = explode(':', $raw, 2);
        if (in_array($type, ['success', 'error', 'warning'], true)) $alert = ['type' => $type, 'text' => $text];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Service Area Page — <?= h(SITE_TITLE) ?></title>
<link rel="stylesheet" href="../assets/css/style.css">
<?php $_adminCssMtime = css_minify_to(__DIR__ . '/../assets/css/admin.src.css', __DIR__ . '/../assets/css/admin.css'); ?>
<link rel="stylesheet" href="../assets/css/admin.css?v=<?= (int) $_adminCssMtime ?>">
<?php lpc_admin_head_assets(); ?>
</head>
<?php
lpc_admin_open('pluscities_clients', 'Service Area Page');
?>
<style>
.pc-card{background:#fff;border:1px solid var(--pc-line,#e2e8f2);border-radius:14px;padding:18px 20px;margin-bottom:16px;}
.pc-form-row{display:flex;gap:12px;flex-wrap:wrap;margin-top:10px;}
.pc-form-row .fg{flex:1;min-width:280px;}
.pc-form-row label{font-size:.8rem;font-weight:600;color:#374151;display:block;margin-bottom:4px;}
.pc-form-row textarea{width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:.84rem;font-family:inherit;min-height:90px;}
</style>

<?php if ($alert): ?><div class="alert alert-<?= h($alert['type']) ?>"><?= h($alert['text']) ?></div><?php endif; ?>

<?php if (!$client): ?>
    <div class="lpc-empty"><h2>Client not found</h2><p>No client with id <code><?= h($clientId) ?></code> (or you don't have access to it).</p></div>
<?php else:
    $hub = $client['hub_content'] ?? ['top_text' => '', 'footer_text' => ''];

    $db = pluscities_db();
    $stmt = $db->prepare('SELECT city_id FROM pages WHERE client_id = ? AND status = "live" GROUP BY city_id ORDER BY city_id');
    $stmt->execute([$clientId]);
    $liveCities = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'city_id');
?>
<div class="pc-card">
    <h3 style="margin-top:0;">Service Area Page — <?= h($client['identity']['business_name'] ?? $clientId) ?></h3>
    <p style="color:var(--pc-muted,#52607a);font-size:.84rem;">
        The list of cities below is automatic — it's every page currently published for this client.
        This screen only edits the text around that list.
        Currently live: <?php
            if ($liveCities) {
                echo h(implode(', ', array_map('pluscities_city_label', $liveCities)));
            } else {
                echo '<em>none yet</em>';
            }
        ?>
    </p>

    <form method="post" action="pluscities_hub_edit_save.php" class="pc-form-row">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
        <input type="hidden" name="client_id" value="<?= h($clientId) ?>">

        <div class="fg">
            <label>Top text (shown above the city list — supports <code>{{business_name}}</code>)</label>
            <textarea name="top_text"><?= h($hub['top_text'] ?? '') ?></textarea>
        </div>
        <div class="fg">
            <label>Footer text (shown below the city list — supports <code>{{business_name}}</code>)</label>
            <textarea name="footer_text"><?= h($hub['footer_text'] ?? '') ?></textarea>
        </div>

        <div style="flex-basis:100%;">
            <button type="submit" class="btn-open">Save</button>
        </div>
    </form>
</div>
<?php endif; ?>
<?php lpc_admin_close(); ?>
