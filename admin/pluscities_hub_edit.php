<?php
/**
 * admin/pluscities_hub_edit.php — edits a client's hub/listing page: whether
 * it exists at all (`hub_content.enabled`), where it lives (top-level
 * `url_base_path`), whether/what the "All Service Areas" pill link on other
 * pages says (`hub_content.pill_enabled`/`pill_text`), and its own text/format
 * (`hub_content.top_text`/`footer_text`/`hub_city_format*`). The hub page's
 * city list itself is derived automatically from the client's live pages —
 * this screen only edits the settings/text/format around that list. New
 * 2026-11-22, see project_lpc_to_pluscities_migration in memory ("Project
 * B"); City Area Format + whole-page layout restored 2026-11-24; Page
 * Settings (enabled/slug/pill) added 2026-11-25.
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
<title>Service Area Page — <?= h($client['identity']['business_name'] ?? 'PlusCities') ?></title>
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
    $hub = $client['hub_content'] ?? ['top_text' => '', 'footer_text' => '', 'hub_city_format' => 'simple', 'hub_city_format_custom' => ''];
    $businessName = (string) ($client['identity']['business_name'] ?? $clientId);

    $db = pluscities_db();
    $stmt = $db->prepare('SELECT city_id FROM pages WHERE client_id = ? AND status = "live" GROUP BY city_id ORDER BY city_id');
    $stmt->execute([$clientId]);
    $liveCities = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'city_id');

    $topTextVal    = trim((string) ($hub['top_text'] ?? '')) !== '' ? $hub['top_text'] : pluscities_hub_default_top_text();
    $footerTextVal = trim((string) ($hub['footer_text'] ?? '')) !== '' ? $hub['footer_text'] : pluscities_hub_default_footer_text();
    $cityFormat    = $hub['hub_city_format'] ?? 'simple';
    $cityFormatCustomVal = (string) ($hub['hub_city_format_custom'] ?? '');
    $enabled       = $hub['enabled'] ?? true;
    $pillEnabled   = $hub['pill_enabled'] ?? true;
    $pillTextVal   = (string) ($hub['pill_text'] ?? '');
    $urlBasePath   = (string) ($client['url_base_path'] ?? 'service-area');
    $liveUrl       = 'https://' . trim((string) ($client['domain'] ?? '')) . '/' . trim($urlBasePath, '/') . '/';
    $slugPattern   = (string) ($client['slug_pattern'] ?? '');
    $prefixServicePages = $client['prefix_service_pages'] ?? true;
    pluscities_client_subtabs($clientId, 'hub_edit');
?>
<form method="post" action="pluscities_hub_edit_save.php">
<input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
<input type="hidden" name="client_id" value="<?= h($clientId) ?>">

<div class="pc-card">
    <strong style="font-size:.88rem;">Page Settings</strong>
    <p style="color:var(--pc-muted,#52607a);font-size:.8rem;margin:4px 0 10px;">
        Whether this page exists at all, where it lives, and whether other pages link to it. Turning the
        page off also stops showing the pill link below (nothing left to send visitors to); the pill link
        itself can also be turned off independently while keeping the page live.
    </p>
    <div class="pc-form-row">
        <div class="fg" style="min-width:260px;">
            <label>
                <input type="hidden" name="enabled" value="0">
                <input type="checkbox" name="enabled" value="1" <?= $enabled ? 'checked' : '' ?> style="width:auto;margin-right:6px;vertical-align:middle;">
                Service Area Page enabled
            </label>
            <p style="color:var(--pc-muted,#52607a);font-size:.78rem;margin:4px 0 0;">
                Off means the page stops serving entirely (visitors get a 404) — use this if the page isn't
                ready yet, or you'd rather not expose a hub page for this client at all.
            </p>
        </div>
        <div class="fg" style="min-width:260px;">
            <label>URL slug</label>
            <input type="text" name="url_base_path" value="<?= h($urlBasePath) ?>" style="padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:.84rem;width:100%;">
            <p style="color:var(--pc-muted,#52607a);font-size:.78rem;margin:4px 0 0;">
                e.g. <code>service-area</code> → the page lives at <code>/service-area/</code>. Changing this
                takes effect on every already-live page's link to this page immediately — nothing to
                regenerate or republish.
            </p>
        </div>
        <div class="fg" style="min-width:260px;">
            <label>Slug pattern — new pages only</label>
            <input type="text" name="slug_pattern" value="<?= h($slugPattern) ?>" placeholder="{city}-{state}/{service} (default)" style="padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:.84rem;width:100%;">
            <p style="color:var(--pc-muted,#52607a);font-size:.78rem;margin:4px 0 0;">
                Tokens: <code>{primary_keyword}</code>, <code>{city}</code>, <code>{state}</code>,
                <code>{service}</code> — e.g. <code>{primary_keyword}-{city}-{state}</code> →
                <code>botox-corpus-christi-tx</code>. Only applies to pages built <strong>after</strong> you
                change this — it never changes a page that already exists. To change one existing page's URL,
                edit its Slug field directly on that page.
            </p>
        </div>
        <div class="fg" style="min-width:260px;">
            <label>
                <input type="hidden" name="prefix_service_pages" value="0">
                <input type="checkbox" name="prefix_service_pages" value="1" <?= $prefixServicePages ? 'checked' : '' ?> style="width:auto;margin-right:6px;vertical-align:middle;">
                Service pages live under <code>/<?= h($urlBasePath) ?>/</code>
            </label>
            <p style="color:var(--pc-muted,#52607a);font-size:.78rem;margin:4px 0 0;">
                On (default): a service page's URL is <code>/<?= h($urlBasePath) ?>/botox-corpus-christi-tx/</code>.
                Off: it drops straight to <code>/botox-corpus-christi-tx/</code> — only turn this off if you're
                sure nothing else on this client's real site already uses that exact URL, since there's no
                automatic collision check against the rest of their site. Only applies to pages built
                <strong>after</strong> you change this, same as the Slug Pattern above. The Service Area hub page
                itself always keeps its own URL slug (above), regardless of this setting.
            </p>
        </div>
        <div class="fg" style="min-width:260px;">
            <label>
                <input type="hidden" name="pill_enabled" value="0">
                <input type="checkbox" name="pill_enabled" value="1" <?= $pillEnabled ? 'checked' : '' ?> style="width:auto;margin-right:6px;vertical-align:middle;">
                Show the pill link on other pages
            </label>
            <p style="color:var(--pc-muted,#52607a);font-size:.78rem;margin:4px 0 0;">
                Shows a floating pill in the bottom-right corner of every page on the site, linking back to
                this page — plus a matching link in each city/service page's own "Related Services" band.
                Turn it off here if you'd rather not show either.
            </p>
        </div>
        <div class="fg" style="min-width:260px;">
            <label>Pill text</label>
            <input type="text" name="pill_text" value="<?= h($pillTextVal) ?>" placeholder="All Service Areas (default)" style="padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:.84rem;width:100%;">
        </div>
    </div>
</div>

<div class="pc-card">
    <h3 style="margin-top:0;">Service Area Page — <?= h($businessName) ?></h3>
    <p style="color:var(--pc-muted,#52607a);font-size:.84rem;">
        The list of cities below is automatic — it's every page currently published for this client.
        This screen only edits the text and format around that list. Currently live: <?php
            if ($liveCities) {
                echo h(implode(', ', array_map('pluscities_city_label', $liveCities)));
            } else {
                echo '<em>none yet</em>';
            }
        ?>
        &middot;
        <?php if (!$enabled): ?>
            <strong style="color:#991b1b;">Page is currently disabled</strong>
        <?php elseif (trim((string) ($client['domain'] ?? '')) !== ''): ?>
            <a href="<?= h($liveUrl) ?>" target="_blank" rel="noopener">View the live page &#8599;</a>
        <?php else: ?>
            <em>No domain set for this client yet</em>
        <?php endif; ?>
    </p>

    <button type="button" class="btn-sm-outline" onclick="pcHubTogglePreview()" style="margin-bottom:10px;">Show Live Preview</button>
    <p style="color:var(--pc-muted,#52607a);font-size:.78rem;margin:-6px 0 10px;">
        Updates instantly as you type below — no Save needed. This is NOT the client's real theme (no real
        header/footer/CSS), just a quick check of your actual text and city format choice.
    </p>
    <div id="pc-hub-preview" style="display:none;border:1px solid #e5e7eb;border-radius:10px;padding:20px;margin-bottom:16px;background:#fafafa;">
        <p style="text-align:center;color:#9ca3af;font-size:.72rem;letter-spacing:.05em;text-transform:uppercase;margin:0 0 16px;border-top:1px dashed #d1d5db;padding-top:8px;">&mdash; Top of Page &mdash;</p>
        <div id="pc-hub-preview-top" style="margin:0 0 20px;"></div>
        <div id="pc-hub-preview-cities" style="display:flex;gap:16px;flex-wrap:wrap;margin:0 0 16px;">
            <div id="pc-hub-preview-simple" style="border:1px solid rgba(0,0,0,.1);border-left:4px solid #2563eb;border-radius:10px;padding:14px 16px;min-width:200px;">
                <div style="font-weight:600;margin:0 0 8px;">Example City</div>
                <div style="color:#2563eb;font-weight:600;font-size:.88rem;">Service One</div>
                <div style="color:#2563eb;font-weight:600;font-size:.88rem;margin-top:4px;">Service Two</div>
            </div>
            <div id="pc-hub-preview-compact" style="display:none;border-bottom:1px solid rgba(0,0,0,.08);padding:8px 0;align-items:baseline;gap:10px;min-width:200px;">
                <span style="font-weight:600;">Example City</span>
                <span style="color:#2563eb;font-weight:600;font-size:.88rem;">Service One</span>
                <span style="color:#2563eb;font-weight:600;font-size:.88rem;">Service Two</span>
            </div>
            <div id="pc-hub-preview-detailed" style="display:none;border:1px solid rgba(0,0,0,.1);border-left:4px solid #2563eb;border-radius:10px;padding:14px 16px;min-width:200px;">
                <div style="font-weight:600;margin:0 0 8px;">Example City</div>
                <div style="font-size:.78rem;opacity:.7;margin:0 0 8px;">2 services available</div>
                <div style="color:#2563eb;font-weight:600;font-size:.88rem;">Service One</div>
                <div style="color:#2563eb;font-weight:600;font-size:.88rem;margin-top:4px;">Service Two</div>
            </div>
            <div id="pc-hub-preview-custom" style="display:none;min-width:200px;"></div>
        </div>
        <p style="text-align:center;color:#9ca3af;font-size:.72rem;letter-spacing:.05em;text-transform:uppercase;margin:0 0 16px;border-top:1px dashed #d1d5db;padding-top:8px;">&mdash; Bottom of Page &mdash;</p>
        <div id="pc-hub-preview-footer"></div>
    </div>
</div>

<div class="pc-card">
    <strong style="font-size:.88rem;">1. Top Text</strong>
    <p style="color:var(--pc-muted,#52607a);font-size:.8rem;margin:4px 0 10px;">
        One box for the whole top of the page — type real HTML directly. This page has no heading of its
        own, so put a heading tag around your title to make it bold and sized like a real one
        (<code>&lt;h2&gt;Service Area&lt;/h2&gt;</code> is the default). Leave a blank line between chunks
        for a real paragraph break, and the same basic tags as Footer Text work here too
        (<code>&lt;strong&gt;</code>, <code>&lt;em&gt;</code>, <code>&lt;a href="..."&gt;</code>,
        <code>&lt;ul&gt;&lt;li&gt;</code>), and <code>{{business_name}}</code> always substitutes the current
        business name instead of going stale if it's ever renamed. A plain blank line has no visible height
        on its own — put <code>&amp;nbsp;</code> on its own line instead if you want real vertical space. A
        client with no top text of its own starts with the heading plus a spacer line above and below it,
        already filled in — delete or edit any part of it, same as Footer Text.
    </p>
    <div class="form-group" style="margin-bottom:10px;">
        <textarea id="pc-hub-top-text" name="top_text" rows="6" style="width:100%;font-family:inherit;font-size:.85rem;"
        ><?= h($topTextVal) ?></textarea>
    </div>
</div>

<div class="pc-card">
    <strong style="font-size:.88rem;">2. City Area Format</strong>
    <p style="color:var(--pc-muted,#52607a);font-size:.8rem;margin:4px 0 10px;">
        Controls how each city is shown in the grid below the intro text. <strong>Simple List</strong>
        (the default) is a card per city with its name and a plain list of service links.
        <strong>Compact Grid</strong> packs every city into a single dense row instead of a card, with its
        services running inline — reads better once there are a lot of cities. <strong>Detailed Card</strong>
        is the same card as Simple List, plus a one-line "N services available" count above the links.
        <strong>Custom HTML</strong> lets you write your own markup for the card instead of picking one of
        the three above.
    </p>

    <button type="button" class="btn-sm-outline" onclick="pcHubToggleExamples()" style="margin-bottom:10px;">Show Format Examples</button>
    <div id="pc-hub-format-examples" style="display:none;margin-bottom:14px;gap:16px;flex-wrap:wrap;">
        <div style="flex:1;min-width:200px;">
            <p style="font-size:.75rem;font-weight:600;color:#374151;margin:0 0 6px;">Simple List</p>
            <div style="border:1px solid rgba(0,0,0,.1);border-left:4px solid #2563eb;border-radius:10px;padding:14px 16px;">
                <div style="font-weight:600;margin:0 0 8px;">Example City</div>
                <div style="color:#2563eb;font-weight:600;font-size:.88rem;">Service One</div>
                <div style="color:#2563eb;font-weight:600;font-size:.88rem;margin-top:4px;">Service Two</div>
            </div>
        </div>
        <div style="flex:1;min-width:200px;">
            <p style="font-size:.75rem;font-weight:600;color:#374151;margin:0 0 6px;">Compact Grid</p>
            <div style="border-bottom:1px solid rgba(0,0,0,.08);padding:8px 0;display:flex;align-items:baseline;gap:10px;">
                <span style="font-weight:600;">Example City</span>
                <span style="color:#2563eb;font-weight:600;font-size:.88rem;">Service One</span>
                <span style="color:#2563eb;font-weight:600;font-size:.88rem;">Service Two</span>
            </div>
        </div>
        <div style="flex:1;min-width:200px;">
            <p style="font-size:.75rem;font-weight:600;color:#374151;margin:0 0 6px;">Detailed Card</p>
            <div style="border:1px solid rgba(0,0,0,.1);border-left:4px solid #2563eb;border-radius:10px;padding:14px 16px;">
                <div style="font-weight:600;margin:0 0 8px;">Example City</div>
                <div style="font-size:.78rem;opacity:.7;margin:0 0 8px;">2 services available</div>
                <div style="color:#2563eb;font-weight:600;font-size:.88rem;">Service One</div>
                <div style="color:#2563eb;font-weight:600;font-size:.88rem;margin-top:4px;">Service Two</div>
            </div>
        </div>
    </div>

    <div class="pc-form-row">
        <div class="form-group">
            <label>Format</label>
            <select id="pc-hub-format" name="hub_city_format" style="padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:.84rem;">
                <?php foreach (['simple' => 'Simple List (default)', 'compact' => 'Compact Grid', 'detailed' => 'Detailed Card', 'custom' => 'Custom HTML'] as $val => $lbl): ?>
                    <option value="<?= h($val) ?>" <?= $cityFormat === $val ? 'selected' : '' ?>><?= h($lbl) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group" style="flex:1;min-width:100%;margin-top:10px;">
            <label>Custom HTML (only used when "Custom HTML" is selected above)</label>
            <textarea id="pc-hub-format-custom" name="hub_city_format_custom" rows="4" style="width:100%;font-family:monospace;font-size:.82rem;" placeholder="<div>{{city}}{{services}}</div>"><?= h($cityFormatCustomVal) ?></textarea>
            <p style="color:var(--pc-muted,#52607a);font-size:.78rem;margin:6px 0 0;">
                Write real HTML here. Two placeholders get swapped in for each city when the page renders:
                <code>{{city}}</code> becomes that city's name, and <code>{{services}}</code> becomes the
                ready-made list of service links (you don't have to build the links yourself — just put
                <code>{{services}}</code> wherever you want that list to appear). Left blank, Custom HTML
                falls back to the Simple List layout.
            </p>
        </div>
    </div>
</div>

<div class="pc-card">
    <strong style="font-size:.88rem;">3. Footer Text</strong>
    <p style="color:var(--pc-muted,#52607a);font-size:.8rem;margin:4px 0 10px;">
        Freeform text rendered below the city grid, at the very bottom of the page. Leave a blank line
        between chunks of text for a real paragraph break, and the same basic tags as Top Text work here
        too (<code>&lt;strong&gt;</code>, <code>&lt;em&gt;</code>, <code>&lt;a href="..."&gt;</code>,
        <code>&lt;ul&gt;&lt;li&gt;</code>, and <code>{{business_name}}</code> instead of typing the name
        directly) if you want them. A plain blank line has no visible height on its own — if you actually
        want vertical space here, put <code>&amp;nbsp;</code> on its own line instead of leaving it truly
        empty. A client with no footer text of its own starts with 10 of those spacer lines already in
        place, each with real visible height on the live page — delete any you don't want, or add more the
        same way.
    </p>
    <div class="form-group" style="margin-bottom:10px;">
        <textarea id="pc-hub-footer-text" name="footer_text" rows="8" style="width:100%;font-family:inherit;"><?= h($footerTextVal) ?></textarea>
    </div>
</div>

<div class="pc-card">
    <button type="submit" class="btn-open">Save</button>
</div>
</form>

<script>
function pcHubToggleExamples() {
    var el = document.getElementById('pc-hub-format-examples');
    el.style.display = (el.style.display === 'none') ? 'flex' : 'none';
}
function pcHubTogglePreview() {
    var el = document.getElementById('pc-hub-preview');
    el.style.display = (el.style.display === 'none') ? 'block' : 'none';
    if (el.style.display === 'block') pcHubUpdatePreview();
}
function pcHubFormatText(raw) {
    if (!raw) return '';
    // A paragraph that's only "&nbsp;" (a spacer line) renders as real but
    // invisible height on the real live page — matched here by leaving it
    // out as a bare blank line and showing its own <p>&nbsp;</p> instead.
    return raw.split(/\n\s*\n/).map(function (p) { return p.trim(); }).filter(function (p) { return p !== ''; })
        .map(function (p) {
            if (p.toLowerCase() === '&nbsp;') return '<p>&nbsp;</p>';
            if (/^<(h2|h3|ul|ol|blockquote)\b/i.test(p)) return p;
            return '<p>' + p.replace(/\n/g, '<br>') + '</p>';
        }).join('');
}
function pcHubUpdatePreview() {
    // Same token the real server substitutes — matches its behavior here so
    // the preview doesn't show the literal "{{business_name}}" text.
    var businessName = <?= json_encode(h($businessName)) ?>;

    var topRaw = document.getElementById('pc-hub-top-text').value.trim();
    if (!topRaw) topRaw = <?= json_encode(pluscities_hub_default_top_text()) ?>;
    document.getElementById('pc-hub-preview-top').innerHTML = pcHubFormatText(topRaw.split('{{business_name}}').join(businessName));

    var format = document.getElementById('pc-hub-format').value;
    ['simple', 'compact', 'detailed', 'custom'].forEach(function (f) {
        var el = document.getElementById('pc-hub-preview-' + f);
        el.style.display = (f !== format) ? 'none' : (f === 'compact' ? 'flex' : 'block');
    });
    if (format === 'custom') {
        var tpl = document.getElementById('pc-hub-format-custom').value;
        var filled = tpl.replace(/\{\{city\}\}/g, 'Example City').replace(/\{\{services\}\}/g, '<ul><li>Service One</li><li>Service Two</li></ul>');
        document.getElementById('pc-hub-preview-custom').innerHTML = filled || '<em>(blank — falls back to Simple List)</em>';
    }

    var footerRaw = document.getElementById('pc-hub-footer-text').value.trim();
    if (!footerRaw) footerRaw = <?= json_encode(pluscities_hub_default_footer_text()) ?>;
    document.getElementById('pc-hub-preview-footer').innerHTML = pcHubFormatText(footerRaw.split('{{business_name}}').join(businessName));
}
// Attached here, after every field above has actually been parsed into the
// DOM — this script sits below all three cards for that reason, even though
// the preview panel itself displays up near the top of the tab.
['pc-hub-top-text', 'pc-hub-format', 'pc-hub-format-custom', 'pc-hub-footer-text'].forEach(function (id) {
    var el = document.getElementById(id);
    if (el) { el.addEventListener('input', pcHubUpdatePreview); el.addEventListener('change', pcHubUpdatePreview); }
});
</script>
<?php endif; ?>
<?php lpc_admin_close(); ?>
