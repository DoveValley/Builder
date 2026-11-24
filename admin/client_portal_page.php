<?php
/**
 * admin/client_portal_page.php — the End Client's read-only view of ONE
 * page's actual content, added 2026-11-24 alongside client_portal.php's
 * "show drafts too" update. Strictly read-only: no edit, approve, publish,
 * or regenerate action anywhere on this screen — that's deliberate, matching
 * the "End Client has no business on any editing/approval screen" rule this
 * admin already follows for every other end_client-adjacent page.
 *
 * Not a clone of the real public page template — this renders each block's
 * plain text directly from the database, the same content an agency would
 * see in the editor, just without any of the editor controls. Good enough
 * for a client to read what their page actually says; not meant to look
 * exactly like the live site.
 *
 * One write action added 2026-11-24: Approve, shown only when this page is
 * actually in pending_client_review (only reachable at all when the agency
 * has requires_client_approval on for this client) — pluscities_client_
 * approve_page() re-checks both the client_id match and the stage itself
 * server-side, not trusting the button's visibility as the real guard.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/lpc_admin_layout.php';

if (!lpc_actor_is_authenticated() || lpc_current_actor_role() !== 'end_client') {
    header('Location: login.php');
    exit;
}

$clientId = lpc_current_actor_client_id();
$client = $clientId ? pluscities_client_load($clientId) : null;
if (!$client) {
    header('Location: login.php');
    exit;
}

$pageId = $_GET['page_id'] ?? '';
$db = pluscities_db();
$st = $db->prepare('SELECT * FROM pages WHERE page_id = ?');
$st->execute([$pageId]);
$page = $st->fetch(PDO::FETCH_ASSOC);

// The page must actually belong to this client — otherwise one client could
// view another client's page just by guessing/editing a page_id in the URL.
if (!$page || $page['client_id'] !== $clientId) {
    header('Location: client_portal.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'approve') {
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        header('Location: client_portal_page.php?page_id=' . urlencode($pageId) . '&msg=error:Invalid+request+token');
        exit;
    }
    $result = pluscities_client_approve_page($pageId, $clientId, lpc_current_actor_identity()['name'] ?? $clientId);
    header('Location: client_portal_page.php?page_id=' . urlencode($pageId) . '&msg=' . ($result['ok'] ? 'success:Approved' : 'error:' . urlencode($result['msg'])));
    exit;
}

$alert = null;
if (!empty($_GET['msg']) && strpos($_GET['msg'], ':') !== false) {
    [$msgType, $msgText] = explode(':', $_GET['msg'], 2);
    if (in_array($msgType, ['success', 'error'], true)) $alert = ['type' => $msgType, 'text' => $msgText];
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

// Page may have just changed stage above — re-read so the page reflects it.
$st->execute([$pageId]);
$page = $st->fetch(PDO::FETCH_ASSOC);

$blockSt = $db->prepare('SELECT * FROM blocks WHERE page_id = ? ORDER BY position');
$blockSt->execute([$pageId]);
$blocks = $blockSt->fetchAll(PDO::FETCH_ASSOC);

$statusLabels = ['draft' => 'Draft', 'approved' => 'Approved — not live yet', 'live' => 'Live', 'unpublished' => 'Unpublished'];
$businessName = $client['identity']['business_name'] ?? $clientId;
$cityLabel = pluscities_city_label($page['city_id']);
$serviceLabel = ucwords(str_replace('-', ' ', $page['service_key']));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h($serviceLabel) ?> in <?= h($cityLabel) ?> — <?= h($businessName) ?></title>
<link rel="stylesheet" href="../assets/css/style.css">
<?php $_adminCssMtime = css_minify_to(__DIR__ . '/../assets/css/admin.src.css', __DIR__ . '/../assets/css/admin.css'); ?>
<link rel="stylesheet" href="../assets/css/admin.css?v=<?= (int) $_adminCssMtime ?>">
<?php lpc_admin_head_assets(); ?>
</head>
<?php lpc_admin_open('reporting', 'Reporting'); ?>
<style>
.pc-card{background:#fff;border:1px solid var(--pc-line,#e2e8f2);border-radius:14px;padding:18px 20px;margin-bottom:16px;}
.pc-back-link{font-size:.82rem;color:var(--pc-muted,#52607a);text-decoration:none;}
.pc-back-link:hover{text-decoration:underline;}
.pc-block{padding:14px 0;border-top:1px solid var(--pc-line,#e2e8f2);}
.pc-block:first-child{border-top:none;padding-top:0;}
.pc-block h4{margin:0 0 6px;font-size:1rem;color:#0B1B33;}
.pc-block p{margin:0 0 8px;font-size:.88rem;line-height:1.6;color:#374151;}
.pc-faq-item{margin-bottom:10px;}
.pc-faq-item strong{display:block;font-size:.88rem;color:#0B1B33;}
.pc-approve-banner{background:#fef3c7;border:1px solid #f59e0b;border-radius:10px;padding:14px 18px;margin-bottom:16px;display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;}
.pc-approve-banner p{margin:0;font-size:.86rem;color:#92400e;}
</style>

<p><a class="pc-back-link" href="client_portal.php">&larr; Back to all pages</a></p>

<?php if ($alert): ?><div class="alert alert-<?= h($alert['type']) ?>"><?= h($alert['text']) ?></div><?php endif; ?>

<?php if ($page['approval_stage'] === 'pending_client_review'): ?>
<div class="pc-approve-banner">
    <p>This page is ready for your review. If it looks good, approve it so your agency can publish it.</p>
    <form method="post" style="margin:0;">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="approve">
        <button type="submit" class="btn-open">Approve</button>
    </form>
</div>
<?php endif; ?>

<div class="pc-card">
    <h3 style="margin-top:0;"><?= h($serviceLabel) ?> in <?= h($cityLabel) ?></h3>
    <p style="color:var(--pc-muted,#52607a);font-size:.84rem;">
        Status: <?= h($statusLabels[$page['status']] ?? ucfirst($page['status'])) ?> — read-only, reach out
        to your agency for anything you'd like changed.
    </p>
</div>

<div class="pc-card">
    <?php foreach ($blocks as $b):
        $content = json_decode($b['content_json'] ?: '{}', true) ?: [];
        $heading = $content['heading'] ?? null;
        $body = $content['body'] ?? null;
        $intro = $content['intro'] ?? null;
        $faqs = $content['faqs'] ?? null;
        if (!$heading && !$body && !$intro && !$faqs) continue; // nothing reviewable in this block (e.g. service_area's structural data)
    ?>
    <div class="pc-block">
        <?php if ($heading): ?><h4><?= h($heading) ?></h4><?php endif; ?>
        <?php if ($body): ?><p><?= nl2br(h($body)) ?></p><?php endif; ?>
        <?php if ($intro): ?><p><?= nl2br(h($intro)) ?></p><?php endif; ?>
        <?php if ($faqs): foreach ($faqs as $faq): ?>
            <div class="pc-faq-item">
                <strong><?= h($faq['question'] ?? '') ?></strong>
                <p><?= h($faq['answer'] ?? '') ?></p>
            </div>
        <?php endforeach; endif; ?>
    </div>
    <?php endforeach; ?>
</div>

<?php lpc_admin_close(); ?>
