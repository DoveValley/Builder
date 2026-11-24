<?php
/**
 * admin/pluscities_photos.php — the client-facing "Photos" screen. Deliberately
 * the simplest possible surface over includes/pluscities_images.php: a logo +
 * brand color, and one thumbnail per niche image slot with "Upload your own"
 * or "Use default instead". No scope/pair/propagation/cache vocabulary
 * anywhere — those are this screen's implementation, not its interface (see
 * includes/pluscities_images.php's own doc comment for why that split exists).
 *
 * Re-branding (changing the logo or color) regenerates every slot's default
 * photo synchronously, in the same request — the person using this screen
 * never sees "generate" as a separate step; they just see their new color
 * applied once they save it.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/pluscities_images.php';
require_once __DIR__ . '/lpc_admin_layout.php';

if (!lpc_actor_is_authenticated() || lpc_current_actor_role() === 'end_client') {
    header('Location: login.php');
    exit;
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

// Filtered through pluscities_actor_can_access_client(), fixed 2026-11-24 —
// an Agency Editor with a real assigned_client_ids list was seeing every
// client in the dropdown here, and could reach a non-assigned client's
// photos directly via ?client_id= in the URL (the $client lookup below
// correctly resolves to null for an id outside this filtered array, same
// "not found" handling as a genuinely bad id, not a new code path).
$allClients = pluscities_client_ids_for_current_actor();
$clients = [];
foreach ($allClients as $cid => $c) {
    if (pluscities_actor_can_access_client($cid)) $clients[$cid] = $c;
}
$clientId = $_GET['client_id'] ?? (count($clients) === 1 ? array_key_first($clients) : '');
$client = ($clientId && isset($clients[$clientId])) ? $clients[$clientId] : null;

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
<title>Photos — <?= h(SITE_TITLE) ?></title>
<link rel="stylesheet" href="../assets/css/style.css">
<?php $_adminCssMtime = css_minify_to(__DIR__ . '/../assets/css/admin.src.css', __DIR__ . '/../assets/css/admin.css'); ?>
<link rel="stylesheet" href="../assets/css/admin.css?v=<?= (int) $_adminCssMtime ?>">
<?php lpc_admin_head_assets(); ?>
</head>
<?php
lpc_admin_open('pluscities_photos', 'Photos');
?>
<style>
.pc-card{background:#fff;border:1px solid var(--pc-line,#e2e8f2);border-radius:14px;padding:18px 20px;margin-bottom:16px;}
.pc-photo-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:18px;}
.pc-photo-tile img{width:100%;aspect-ratio:3/2;object-fit:cover;border-radius:10px;border:1px solid var(--pc-line,#e2e8f2);background:var(--pc-mist,#f4f7fb);}
.pc-photo-tile .caption{font-size:.78rem;color:var(--pc-muted,#52607a);margin:8px 0 6px;}
.pc-photo-tile .actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap;}
.pc-color-row{display:flex;align-items:center;gap:14px;flex-wrap:wrap;}
.pc-color-row input[type=color]{width:46px;height:36px;border:1px solid var(--pc-line,#e2e8f2);border-radius:8px;padding:2px;}
.pc-logo-preview{height:40px;width:auto;max-width:140px;object-fit:contain;border:1px solid var(--pc-line,#e2e8f2);border-radius:8px;padding:4px;background:#fff;}
.pc-client-pick{display:block;padding:10px 14px;border:1px solid var(--pc-line,#e2e8f2);border-radius:10px;margin-bottom:8px;text-decoration:none;color:var(--pc-ink,#1c2b45);font-weight:600;}
.pc-client-pick:hover{border-color:var(--pc-navy-line,#2b4670);background:var(--pc-mist,#f4f7fb);}
</style>

<?php if ($alert): ?><div class="alert alert-<?= h($alert['type']) ?>"><?= h($alert['text']) ?></div><?php endif; ?>

<?php if (!$client): ?>
    <?php if (!$clients): ?>
        <div class="lpc-empty"><h2>No clients yet</h2></div>
    <?php else: ?>
        <div class="pc-card">
            <h3 style="margin-top:0;">Choose a client</h3>
            <?php foreach ($clients as $cid => $c): ?>
                <a class="pc-client-pick" href="?client_id=<?= h($cid) ?>"><?= h($c['identity']['business_name'] ?? $cid) ?></a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
<?php else:
    $niche = pluscities_niche_resolved($client['niche_id']);
    $slots = $niche['image_slots'] ?? [];
?>

<?php if (count($clients) > 1): ?>
    <p style="margin-top:-8px;"><a href="pluscities_photos.php" style="font-size:.84rem;color:var(--pc-muted,#52607a);">&larr; Choose a different client</a></p>
<?php endif; ?>

<div class="pc-card">
    <h3 style="margin-top:0;">Your Logo & Color</h3>
    <p style="color:var(--pc-muted,#52607a);font-size:.84rem;margin-top:-6px;">
        We use these to make your photos — and your pages — look like yours.
    </p>
    <form method="post" action="pluscities_photos_save.php" enctype="multipart/form-data" class="pc-color-row">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
        <input type="hidden" name="client_id" value="<?= h($clientId) ?>">
        <input type="hidden" name="action" value="save_branding">
        <?php if (!empty($client['branding']['logo_url'])): ?>
            <img class="pc-logo-preview" src="<?= h($client['branding']['logo_url']) ?>" alt="Your logo">
        <?php endif; ?>
        <label style="font-size:.82rem;">
            Logo<br><input type="file" name="logo_file" accept="image/png,image/jpeg,image/webp">
        </label>
        <?php $colors = pluscities_client_colors($client); ?>
        <label style="font-size:.82rem;">
            Accent color<br><input type="color" name="accent_color" value="<?= h($colors['accent']) ?>">
        </label>
        <label style="font-size:.82rem;">
            Dark color<br><input type="color" name="dark_color" value="<?= h($colors['dark']) ?>">
        </label>
        <button type="submit" class="btn-open" style="align-self:flex-end;">Save (override)</button>
    </form>
    <p style="color:var(--pc-muted,#52607a);font-size:.8rem;margin:10px 0 0;">
        <?php $b = $client['branding']; $hasOverride = !empty($b['accent_override']) || !empty($b['dark_override']); ?>
        <?php if ($hasOverride): ?>
            Using your manual colors above — they always win over your site's own colors.
        <?php elseif (!empty($b['detected_accent']) || !empty($b['detected_dark'])): ?>
            Using colors detected from your site<?= !empty($b['colors_detected_at']) ? ' (checked ' . h(date('M j', strtotime($b['colors_detected_at']))) . ')' : '' ?>.
        <?php else: ?>
            No colors detected yet from your site — showing the default until you detect or set your own.
        <?php endif; ?>
    </p>
    <div style="display:flex;gap:8px;margin-top:8px;">
        <form method="post" action="pluscities_photos_save.php">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="client_id" value="<?= h($clientId) ?>">
            <input type="hidden" name="action" value="detect_colors">
            <button type="submit" class="btn-sm-outline">&#128269; Use my site's colors</button>
        </form>
        <?php if ($hasOverride): ?>
        <form method="post" action="pluscities_photos_save.php">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="client_id" value="<?= h($clientId) ?>">
            <input type="hidden" name="action" value="clear_color_override">
            <button type="submit" class="btn-sm-outline">Clear manual override</button>
        </form>
        <?php endif; ?>
    </div>
</div>

<div class="pc-card">
    <h3 style="margin-top:0;">Photos</h3>
    <?php if (!$slots): ?>
        <p style="color:var(--pc-muted,#52607a);font-size:.84rem;">No photo slots are set up for this business type yet.</p>
    <?php else: ?>
    <div class="pc-photo-grid">
        <?php foreach ($slots as $slot):
            $slotId = $slot['slot_id'];
            $url = pluscities_resolve_image_url($client, $niche, $slotId);
            $hasOwn = !empty($client['images'][$slotId]['uploaded_path']);
        ?>
        <div class="pc-photo-tile">
            <?php if ($url): ?><img src="<?= h($url) ?>" alt="<?= h($slot['label']) ?>"><?php endif; ?>
            <div class="caption"><strong><?= h($slot['label']) ?></strong> — <?= $hasOwn ? 'your photo' : 'default photo' ?></div>
            <div class="actions">
                <form method="post" action="pluscities_photos_save.php" enctype="multipart/form-data" style="display:flex;gap:6px;">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                    <input type="hidden" name="client_id" value="<?= h($clientId) ?>">
                    <input type="hidden" name="action" value="upload_photo">
                    <input type="hidden" name="slot_id" value="<?= h($slotId) ?>">
                    <input type="file" name="photo_file" accept="image/png,image/jpeg,image/webp" required style="font-size:.76rem;max-width:140px;">
                    <button type="submit" class="btn-sm-outline">Upload</button>
                </form>
                <?php if ($hasOwn): ?>
                    <form method="post" action="pluscities_photos_save.php" onsubmit="return confirm('Switch back to the default photo?');">
                        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                        <input type="hidden" name="client_id" value="<?= h($clientId) ?>">
                        <input type="hidden" name="action" value="revert_photo">
                        <input type="hidden" name="slot_id" value="<?= h($slotId) ?>">
                        <button type="submit" class="btn-sm-outline">Use default instead</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>
<?php lpc_admin_close(); ?>
