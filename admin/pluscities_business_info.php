<?php
/**
 * admin/pluscities_business_info.php — edits the client-level facts the AI
 * writer and validator actually read (pluscities_client_facts_for_writer(),
 * includes/pluscities_writer.php; pluscities_check_banned_phrases()/
 * pluscities_check_invented_prices(), includes/pluscities_validator.php) and
 * the reviews that drive both the reviews_from_city block and the
 * AggregateRating schema (includes/pluscities_schema.php). None of these had
 * any admin UI before this screen — only settable at client-creation time or
 * by hand-editing pluscities_clients/{id}.json — found during the
 * panel-coverage walkthrough, 2026-11-26 (Session 3).
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
<title>Business Info — <?= h($client['identity']['business_name'] ?? 'PlusCities') ?></title>
<link rel="stylesheet" href="../assets/css/style.css">
<?php $_adminCssMtime = css_minify_to(__DIR__ . '/../assets/css/admin.src.css', __DIR__ . '/../assets/css/admin.css'); ?>
<link rel="stylesheet" href="../assets/css/admin.css?v=<?= (int) $_adminCssMtime ?>">
<?php lpc_admin_head_assets(); ?>
</head>
<?php
lpc_admin_open('pluscities_clients', 'Business Info');
?>
<style>
.pc-card{background:#fff;border:1px solid var(--pc-line,#e2e8f2);border-radius:14px;padding:18px 20px;margin-bottom:16px;}
.pc-form-row{display:flex;gap:12px;flex-wrap:wrap;margin-top:10px;}
.pc-form-row .fg{flex:1;min-width:220px;}
.pc-form-row label{font-size:.8rem;font-weight:600;color:#374151;display:block;margin-bottom:4px;}
.pc-form-row input,.pc-form-row textarea{width:100%;padding:7px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:.84rem;font-family:inherit;}
.pc-form-row textarea{min-height:60px;}
</style>

<?php if ($alert): ?><div class="alert alert-<?= h($alert['type']) ?>"><?= h($alert['text']) ?></div><?php endif; ?>

<?php if (!$client): ?>
    <div class="lpc-empty"><h2>Client not found</h2><p>No client with id <code><?= h($clientId) ?></code> (or you don't have access to it).</p></div>
<?php else:
    pluscities_client_subtabs($clientId, 'business_info');
    $hub = $client['identity']['hubs'][0] ?? ['address' => '', 'phone' => ''];
    $hours = $client['hours'] ?? ['mon_fri' => '', 'sat' => '', 'sun' => ''];
?>
<p style="font-size:.84rem;color:var(--pc-muted,#52607a);margin:0 0 14px;">
    Real facts the AI writer is allowed to state directly and the validator checks content against —
    a wrong license number or phone here is wrong on every page this client has. Only one hub/location
    is used today (the writer reads <code>hubs[0]</code> only); multiple locations aren't wired up yet.
</p>
<form method="post" action="pluscities_business_info_save.php">
<input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
<input type="hidden" name="client_id" value="<?= h($clientId) ?>">

<div class="pc-card">
    <h3 style="margin-top:0;">Identity &amp; Hub</h3>
    <div class="pc-form-row">
        <div class="fg"><label>Business Name</label>
            <input type="text" name="business_name" value="<?= h($client['identity']['business_name'] ?? '') ?>"></div>
        <div class="fg"><label>Hub Address</label>
            <input type="text" name="hub_address" value="<?= h($hub['address'] ?? '') ?>"></div>
        <div class="fg"><label>Hub Phone</label>
            <input type="text" name="hub_phone" value="<?= h($hub['phone'] ?? '') ?>"></div>
        <div class="fg"><label>Years in Business</label>
            <input type="number" min="0" name="years_in_business" value="<?= h((string) ($client['years_in_business'] ?? '')) ?>"></div>
    </div>
    <div class="pc-form-row">
        <div class="fg"><label>Hours — Mon-Fri</label>
            <input type="text" name="hours_mon_fri" placeholder="8am-6pm" value="<?= h($hours['mon_fri'] ?? '') ?>"></div>
        <div class="fg"><label>Hours — Sat</label>
            <input type="text" name="hours_sat" placeholder="9am-2pm" value="<?= h($hours['sat'] ?? '') ?>"></div>
        <div class="fg"><label>Hours — Sun</label>
            <input type="text" name="hours_sun" placeholder="Closed" value="<?= h($hours['sun'] ?? '') ?>"></div>
    </div>
</div>

<div class="pc-card">
    <h3 style="margin-top:0;">Licenses</h3>
    <p style="font-size:.78rem;color:var(--pc-muted,#52607a);margin:0 0 8px;">
        One per line: <code>number|state|trade</code>. Shown as "[trade] license [number] ([state])" in
        generated content — this is a real claim the validator will fail content over if it's missing
        or wrong.
    </p>
    <textarea name="licenses_lines" placeholder="TACLB123456C|TX|HVAC Contractor"><?php
        foreach (($client['licenses'] ?? []) as $lic) {
            echo h(($lic['number'] ?? '') . '|' . ($lic['state'] ?? '') . '|' . ($lic['trade'] ?? '')) . "\n";
        }
    ?></textarea>
</div>

<div class="pc-card">
    <h3 style="margin-top:0;">Content Rules &amp; Pricing</h3>
    <div class="pc-form-row">
        <div class="fg" style="flex-basis:100%;"><label>Banned Phrases (comma-separated — on top of whatever the niche's compliance preset already bans)</label>
            <input type="text" name="banned_phrases" value="<?= h(implode(', ', $client['banned_phrases'] ?? [])) ?>"></div>
        <div class="fg" style="flex-basis:100%;"><label>Price Ranges (comma-separated, e.g. "AC Repair: $150-$400")</label>
            <input type="text" name="price_ranges" value="<?= h(implode(', ', array_map(fn($r) => is_array($r) ? implode(' ', $r) : (string) $r, $client['price_ranges'] ?? []))) ?>"></div>
    </div>
</div>

<div class="pc-card">
    <h3 style="margin-top:0;">Reviews by City</h3>
    <p style="font-size:.78rem;color:var(--pc-muted,#52607a);margin:0 0 8px;">
        Real reviews this client has actually received for a specific city — never written by the AI,
        copied straight through to the <code>reviews_from_city</code> block and this page's
        AggregateRating schema. One per line per city: <code>text|author|rating (1-5)</code>.
    </p>
    <?php if (empty($client['target_cities'])): ?>
        <p style="font-size:.84rem;color:var(--pc-muted,#52607a);">No target cities yet — add one from the
            <a href="pluscities_page_add.php?client_id=<?= h($clientId) ?>">+ Add Page</a> tab first.</p>
    <?php else: foreach ($client['target_cities'] as $tc): $cid = $tc['city_id']; ?>
        <div class="fg" style="margin-bottom:12px;"><label><?= h($cid) ?></label>
            <textarea name="reviews_<?= h($cid) ?>" placeholder="Fast, professional service.|Jane D.|5"><?php
                foreach (($client['reviews_by_city'][$cid] ?? []) as $rev) {
                    echo h(($rev['text'] ?? '') . '|' . ($rev['author'] ?? '') . '|' . ($rev['rating'] ?? '')) . "\n";
                }
            ?></textarea>
        </div>
    <?php endforeach; endif; ?>
</div>

<button type="submit" class="btn-open">Save Business Info</button>
</form>
<?php endif; ?>
<?php lpc_admin_close(); ?>
