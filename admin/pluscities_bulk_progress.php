<?php
/**
 * admin/pluscities_bulk_progress.php — progress screen for the New Client
 * wizard's "Multiple pages" mode (2026-11-26). Polls admin/pluscities_bulk_
 * status.php (JSON) and renders live state — queued/running/done/failed/
 * stale — plus a running list of each city x service combo's result, from
 * the moment the background job starts until it finishes. See includes/
 * pluscities_bulk_pages_cli.php for what's actually doing the work.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/lpc_admin_layout.php';

if (!lpc_actor_is_authenticated() || lpc_current_actor_role() === 'end_client') {
    header('Location: login.php');
    exit;
}
$clientId = $_GET['client_id'] ?? '';
$runId    = $_GET['run_id'] ?? '';
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
<title>Building Pages — <?= h($client['identity']['business_name'] ?? 'PlusCities') ?></title>
<link rel="stylesheet" href="../assets/css/style.css">
<?php $_adminCssMtime = css_minify_to(__DIR__ . '/../assets/css/admin.src.css', __DIR__ . '/../assets/css/admin.css'); ?>
<link rel="stylesheet" href="../assets/css/admin.css?v=<?= (int) $_adminCssMtime ?>">
<?php lpc_admin_head_assets(); ?>
</head>
<?php
lpc_admin_open('pluscities_clients', 'Building Pages');
?>
<style>
.pc-card{background:#fff;border:1px solid var(--pc-line,#e2e8f2);border-radius:14px;padding:18px 20px;margin-bottom:16px;}
.pc-bulk-bar{height:10px;border-radius:5px;background:#f1f5f9;overflow:hidden;margin:10px 0;}
.pc-bulk-bar-fill{height:100%;background:var(--pc-lime,#c8f55a);transition:width .3s;}
.pc-bulk-row{display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid #f1f5f9;font-size:.84rem;}
.pc-bulk-row:last-child{border-bottom:none;}
.pc-bulk-pill{font-size:.68rem;font-weight:700;text-transform:uppercase;padding:2px 8px;border-radius:10px;}
.pc-bulk-pill-ok{background:#dcfce7;color:#166534;}
.pc-bulk-pill-failed{background:#fee2e2;color:#991b1b;}
</style>

<?php if ($alert): ?><div class="alert alert-<?= h($alert['type']) ?>"><?= h($alert['text']) ?></div><?php endif; ?>

<?php if (!$client || $runId === ''): ?>
    <div class="lpc-empty"><h2>Nothing to show</h2><p>No client or job found.</p></div>
<?php else: ?>
<div class="pc-card">
    <h3 style="margin-top:0;">Building pages — <?= h($client['identity']['business_name'] ?? $clientId) ?></h3>
    <p id="pc-bulk-state" style="color:var(--pc-muted,#52607a);font-size:.88rem;">Starting…</p>
    <div class="pc-bulk-bar"><div class="pc-bulk-bar-fill" id="pc-bulk-bar-fill" style="width:0%;"></div></div>
    <div id="pc-bulk-results"></div>
    <div id="pc-bulk-done-actions" style="display:none;margin-top:14px;">
        <a href="pluscities_client_overview.php?client_id=<?= h($clientId) ?>" class="btn-open">Go to client &rarr;</a>
    </div>
</div>
<script>
(function() {
    var clientId = <?= json_encode($clientId) ?>;
    var runId = <?= json_encode($runId) ?>;
    var stateEl = document.getElementById('pc-bulk-state');
    var barFill = document.getElementById('pc-bulk-bar-fill');
    var resultsEl = document.getElementById('pc-bulk-results');
    var doneActions = document.getElementById('pc-bulk-done-actions');
    var terminal = {done: true, failed: true, stale: true};

    function render(s) {
        var pct = s.total ? Math.round((s.done / s.total) * 100) : 0;
        barFill.style.width = pct + '%';
        var label = {
            queued: 'Queued…', running: 'Building ' + s.done + ' of ' + s.total + ' pages…',
            done: 'Done — ' + s.ok + ' of ' + s.total + ' pages created.',
            failed: 'Finished with errors — ' + s.ok + ' of ' + s.total + ' pages created, ' + s.failed + ' failed.',
            stale: 'The background job stopped unexpectedly (' + s.done + ' of ' + s.total + ' completed before it died). Safe to retry via Add Page for whatever is missing.',
            pending: 'Starting…'
        }[s.state] || s.state;
        stateEl.textContent = label;
        resultsEl.innerHTML = (s.results || []).map(function(r) {
            var pillClass = r.status === 'ok' ? 'pc-bulk-pill-ok' : 'pc-bulk-pill-failed';
            var text = r.city_id + ' × ' + r.service_key + (r.status !== 'ok' && r.msg ? ' — ' + r.msg : '');
            return '<div class="pc-bulk-row"><span>' + text.replace(/</g, '&lt;') + '</span>'
                 + '<span class="pc-bulk-pill ' + pillClass + '">' + r.status + '</span></div>';
        }).join('');
        if (terminal[s.state]) {
            doneActions.style.display = 'block';
            return true;
        }
        return false;
    }

    function poll() {
        fetch('pluscities_bulk_status.php?client_id=' + encodeURIComponent(clientId) + '&run_id=' + encodeURIComponent(runId))
            .then(function(r) { return r.json(); })
            .then(function(s) {
                var finished = render(s);
                if (!finished) setTimeout(poll, 2000);
            })
            .catch(function() { setTimeout(poll, 3000); });
    }
    poll();
})();
</script>
<?php endif; ?>
<?php lpc_admin_close(); ?>
