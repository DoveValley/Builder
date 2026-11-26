<?php
/**
 * admin/pluscities_clients.php — real client create/list for the PlusCities
 * engine. Every client so far (Elite Air HVAC, Lonestar Restoration) was
 * hand-authored as JSON through direct function calls — this is the one
 * missing panel screen that replaces that with a real form, matching
 * everything else in the engine already being driven through the panel
 * (Niche Library, Team, Pages, Photos).
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/lpc_admin_layout.php';
// Self-contained lib, safe to require directly per CLAUDE.md's infra/lib/*
// convention — only infra_site_check()/infra_site_verdict() are used below
// (the live, uncached checks), deliberately NOT infra_site_check_cached(),
// which stores results in Site Factory's fleet.db. A live check per page
// load keeps PlusCities' own data out of that database, same separation
// principle pluscities.db itself was already built around.
require_once __DIR__ . '/infra/lib/uptime.php';

if (!lpc_actor_is_authenticated() || lpc_current_actor_role() === 'end_client') {
    header('Location: login.php');
    exit;
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

$alert = null;
if (!empty($_GET['msg'])) {
    $raw = $_GET['msg'];
    if (strpos($raw, ':') !== false) {
        [$type, $text] = explode(':', $raw, 2);
        if (in_array($type, ['success', 'error', 'warning'], true)) $alert = ['type' => $type, 'text' => $text];
    }
}

// Filtered through pluscities_actor_can_access_client(), not just the
// agency-level list, fixed 2026-11-24 — an Agency Editor with a real
// assigned_client_ids list was seeing every client in the agency here, not
// just their own, even though every write action already correctly blocked
// them from touching the ones they weren't assigned. Same pattern already
// proven correct on pluscities_dashboard.php since it was first built.
$allClients = pluscities_client_ids_for_current_actor();
$clients = [];
foreach ($allClients as $cid => $c) {
    if (pluscities_actor_can_access_client($cid)) $clients[$cid] = $c;
}

// Optional single-agency view, reached via the agency workspace's Clients
// tab (pluscities_agency_subtabs() in lpc_admin_layout.php) — super_admin
// only, since an agency_admin/editor is already scoped to their own agency
// regardless. Absent entirely, behaves exactly as before.
$agencyIdFilter = lpc_current_actor_role() === 'super_admin' ? trim((string) ($_GET['agency_id'] ?? '')) : '';
$filterAgency = null;
if ($agencyIdFilter !== '') {
    $filterAgency = lpc_load_agency($agencyIdFilter);
    $clients = array_filter($clients, function ($c) use ($agencyIdFilter) {
        return ($c['agency_id'] ?? '') === $agencyIdFilter;
    });
}

$agencies = lpc_current_actor_role() === 'super_admin' ? lpc_list_agencies() : [lpc_current_actor_agency_id()];
$niches   = pluscities_niche_list();
$graceDays = pluscities_platform_settings()['cancellation_grace_days'];

// Block Topics catalog — same single source platform_info.php's own
// documentation page reads, so this real picker and that internal doc page
// can never drift into describing a block differently (2026-11-22, moved
// here from the old LPC admin's picker — see project_lpc_to_pluscities_
// migration in memory for why).
$pcCatalog = [];
foreach (platform_info_city_page_blocks_data() as $pcCatSection) {
    foreach ($pcCatSection['rows'] as $pcCatRow) {
        if (!empty($pcCatRow['slug'])) $pcCatalog[$pcCatRow['slug']] = $pcCatRow;
    }
}
// Both pools are real per-niche data (vary by family) — the niche isn't
// chosen until the agency picks it in the form below, so every real niche's
// pools are resolved up front here (cheap at today's scale) and all of them
// render as hidden groups; JS shows only the one matching the current
// selection. Both $pcNiche['city_blocks'] and $pcNiche['service_blocks']
// already exclude their family's always-on blocks (pluscities_niche_
// resolved() does this) — service pool became family-customizable 2026-11-26,
// same mechanism as city blocks, replacing one global constant shared by
// every niche regardless of business type. Each pool is shuffled so a fixed
// catalog order + hard pick-cap stops starving whatever sits near the end of
// a long list (confirmed live: before_the_tech_arrives picked on just 1 of 21
// real pages).
$pcNichePools = [];
$pcNicheServicePools = [];
$pcNicheAlways = [];
$pcNicheHappyCity = [];
$pcNicheHappyService = [];
foreach ($niches as $pcNicheId) {
    $pcNiche = pluscities_niche_resolved($pcNicheId);
    if (!$pcNiche) continue;
    $pcNichePools[$pcNicheId] = $pcNiche['city_blocks'] ?? [];
    $pcNicheServicePools[$pcNicheId] = $pcNiche['service_blocks'] ?? [];
    $pcNicheAlways[$pcNicheId] = array_merge($pcNiche['always_city_blocks'] ?? [], $pcNiche['always_service_blocks'] ?? []);
    $pcNicheHappyCity[$pcNicheId] = $pcNiche['happy_path_city_blocks'] ?? [];
    $pcNicheHappyService[$pcNicheId] = $pcNiche['happy_path_service_blocks'] ?? [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $filterAgency ? h('Clients — ' . ($filterAgency['name'] ?: $agencyIdFilter)) : 'Clients — PlusCities' ?></title>
<link rel="stylesheet" href="../assets/css/style.css">
<?php $_adminCssMtime = css_minify_to(__DIR__ . '/../assets/css/admin.src.css', __DIR__ . '/../assets/css/admin.css'); ?>
<link rel="stylesheet" href="../assets/css/admin.css?v=<?= (int) $_adminCssMtime ?>">
<?php lpc_admin_head_assets(); ?>
</head>
<?php
lpc_admin_open($filterAgency ? 'agencies' : 'pluscities_clients', 'Clients');
?>
<style>
.pc-card{background:#fff;border:1px solid var(--pc-line,#e2e8f2);border-radius:14px;padding:18px 20px;margin-bottom:16px;}
.pc-form-row{display:flex;gap:12px;flex-wrap:wrap;margin-top:10px;}
.pc-form-row .fg{flex:1;min-width:180px;}
.pc-form-row label{font-size:.8rem;font-weight:600;color:#374151;display:block;margin-bottom:4px;}
.pc-form-row input,.pc-form-row select{width:100%;padding:7px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:.84rem;font-family:inherit;}
.pc-row{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:10px 0;border-bottom:1px solid var(--pc-line,#e2e8f2);}
.pc-row:last-child{border-bottom:none;}
.pc-section-label{font-size:.72rem;text-transform:uppercase;letter-spacing:.04em;color:var(--pc-muted,#52607a);margin:18px 0 2px;}
.pc-site-badge{display:inline-block;margin-left:8px;font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.02em;padding:2px 8px;border-radius:10px;}
.pc-site-badge-live{background:#dcfce7;color:#166534;}
.pc-site-badge-warn{background:#fef3c7;color:#92400e;}
.pc-site-badge-down{background:#fee2e2;color:#991b1b;}
.pc-client-name{font-weight:700;color:var(--pc-ink,#1c2b45);text-decoration:none;font-size:.95rem;}
.pc-client-name:hover{color:var(--pc-lime-ink,#3d6b0e);text-decoration:underline;}
.pc-steps{display:flex;gap:4px;margin-bottom:18px;flex-wrap:wrap;}
.pc-step-pill{flex:1;min-width:120px;text-align:center;padding:8px 6px;border-radius:8px;font-size:.76rem;font-weight:700;color:var(--pc-muted,#52607a);background:var(--pc-mist,#f4f7fb);border:1px solid var(--pc-line,#e2e8f2);}
.pc-step-pill.is-active{background:var(--pc-lime,#c8f55a);color:var(--pc-navy,#0b1b33);border-color:var(--pc-lime,#c8f55a);}
.pc-step-pill.is-done{background:#fff;border-color:var(--pc-lime-ink,#3d6b0e);color:var(--pc-lime-ink,#3d6b0e);}
.pc-step-panel{display:none;}
.pc-step-panel.is-active{display:block;}
.pc-step-nav{display:flex;justify-content:space-between;margin-top:18px;flex-basis:100%;}
.pc-review-row{display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--pc-line,#e2e8f2);font-size:.85rem;}
.pc-review-row:last-child{border-bottom:none;}
.pc-review-row span:first-child{color:var(--pc-muted,#52607a);}
.pc-review-row span:last-child{font-weight:600;text-align:right;}
</style>

<?php if ($alert): ?><div class="alert alert-<?= h($alert['type']) ?>"><?= h($alert['text']) ?></div><?php endif; ?>

<?php if ($filterAgency): pluscities_agency_subtabs($agencyIdFilter, 'clients'); endif; ?>

<div class="pc-card">
    <h3 style="margin-top:0;"><?= $filterAgency ? h('Clients — ' . ($filterAgency['name'] ?: $agencyIdFilter)) : 'Clients' ?></h3>
    <?php foreach ($clients as $cid => $c): ?>
        <div class="pc-row">
            <div>
                <a href="pluscities_client_overview.php?client_id=<?= h($cid) ?>" class="pc-client-name"><?= h($c['identity']['business_name'] ?? $cid) ?></a>
                <?php
                $siteCheck = infra_site_check($c['domain'] ?? '');
                if (!empty($siteCheck['up']) && !empty($siteCheck['cert_ok'])) {
                    $siteBadgeClass = 'pc-site-badge-live'; $siteBadgeText = 'Site: Live';
                } elseif (!empty($siteCheck['up'])) {
                    $siteBadgeClass = 'pc-site-badge-warn'; $siteBadgeText = 'Site: Live (SSL issue)';
                } else {
                    $siteBadgeClass = 'pc-site-badge-down'; $siteBadgeText = 'Site: Down';
                }
                ?>
                <span class="pc-site-badge <?= $siteBadgeClass ?>" title="<?= h($siteCheck['error'] ?: ('HTTP ' . $siteCheck['code'])) ?>"><?= h($siteBadgeText) ?></span>
                <div style="font-size:.78rem;color:var(--pc-muted,#52607a);">
                    <?= h($c['domain']) ?> &middot; <?= h($c['niche_id']) ?> &middot; <?= (int) count($c['target_cities'] ?? []) ?> cit(y/ies)
                    &middot; Client approval: <?= !empty($c['requires_client_approval']) ? '<strong>On</strong>' : 'Off' ?>
                    <?php if (($c['status'] ?? 'active') === 'canceled'):
                        $daysLeft = $graceDays - (int) floor((time() - strtotime($c['canceled_at'])) / 86400);
                    ?>
                        &middot; <strong style="color:#991b1b;">
                            <?= $daysLeft > 0 ? 'Cancelled — ' . $daysLeft . ' day(s) left in grace period' : 'Cancelled — grace period ended, redirecting now' ?>
                        </strong>
                    <?php endif; ?>
                    <?php if (!empty($c['site_suspended'])): ?>
                        &middot; <strong style="color:#991b1b;">Suspended by platform admin</strong>
                    <?php endif; ?>
                </div>
                <?php if (!empty($c['last_seen_at'])):
                    $secsAgo = max(0, time() - strtotime($c['last_seen_at']));
                    $agoText = $secsAgo < 3600 ? max(1, (int) ($secsAgo / 60)) . 'm ago'
                        : ($secsAgo < 86400 ? (int) ($secsAgo / 3600) . 'h ago' : (int) ($secsAgo / 86400) . 'd ago');
                ?>
                <div style="font-size:.76rem;color:var(--pc-muted,#52607a);margin-top:2px;">
                    Plugin <?= h($c['plugin_version'] ?: '?') ?> &middot; Theme: <?= h($c['theme'] ?: '?') ?> &middot; SEO: <?= h($c['seo_plugin'] ?: 'none') ?> &middot; last synced <?= h($agoText) ?>
                </div>
                <?php else: ?>
                <div style="font-size:.76rem;color:var(--pc-muted,#52607a);margin-top:2px;">Plugin never synced yet</div>
                <?php endif; ?>
            </div>
            <div>
                <a href="pluscities_client_overview.php?client_id=<?= h($cid) ?>" class="btn-open">Open &rarr;</a>
            </div>
        </div>
    <?php endforeach; ?>
    <?php if (!$clients): ?><p style="color:var(--pc-muted,#52607a);font-size:.84rem;">No clients yet.</p><?php endif; ?>
</div>

<div class="pc-card">
    <h3 style="margin-top:0;">+ New Client</h3>
    <div class="pc-steps">
        <div class="pc-step-pill is-active" data-step-pill="1">1. Business &amp; Niche</div>
        <div class="pc-step-pill" data-step-pill="2">2. Hub &amp; Hours</div>
        <div class="pc-step-pill" data-step-pill="3">3. First Page &amp; Topics</div>
        <div class="pc-step-pill" data-step-pill="4">4. Review &amp; Create</div>
    </div>
    <form method="post" action="pluscities_clients_save.php" id="pc-new-client-form">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="create">

        <div class="pc-step-panel is-active" data-step-panel="1">
            <div class="pc-form-row">
                <div class="pc-section-label" style="flex-basis:100%;">Identity</div>
                <div class="fg"><label>Business name</label><input type="text" name="business_name" required></div>
                <div class="fg"><label>Domain (their real site)</label><input type="text" name="domain" placeholder="example.com" required></div>
                <div class="fg"><label>Agency</label>
                    <select name="agency_id" required>
                        <?php foreach ($agencies as $aid): ?><option value="<?= h($aid) ?>" <?= $agencyIdFilter === $aid ? 'selected' : '' ?>><?= h($aid) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="fg"><label>Niche</label>
                    <select name="niche_id" required>
                        <option value="">— choose —</option>
                        <?php foreach ($niches as $nid): ?><option value="<?= h($nid) ?>"><?= h($nid) ?></option><?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="pc-step-nav">
                <span></span>
                <button type="button" class="btn-open" data-step-next>Next &rarr;</button>
            </div>
        </div>

        <div class="pc-step-panel" data-step-panel="2">
            <div class="pc-form-row">
                <div class="pc-section-label" style="flex-basis:100%;">Hub location</div>
                <div class="fg"><label>Address</label><input type="text" name="hub_address" required placeholder="123 Main St, City, ST 00000"></div>
                <div class="fg"><label>Phone</label><input type="text" name="hub_phone" required placeholder="(555) 555-0100"></div>

                <div class="pc-section-label" style="flex-basis:100%;">Credentials &amp; history</div>
                <div class="fg"><label>License number</label><input type="text" name="license_number"></div>
                <div class="fg"><label>License state</label><input type="text" name="license_state" maxlength="2" placeholder="TX"></div>
                <div class="fg"><label>License trade</label><input type="text" name="license_trade" placeholder="General Contractor"></div>
                <div class="fg"><label>Years in business</label><input type="number" name="years_in_business" min="0"></div>

                <div class="pc-section-label" style="flex-basis:100%;">Hours</div>
                <div class="fg"><label>Mon–Fri</label><input type="text" name="hours_mon_fri" placeholder="7:00-19:00"></div>
                <div class="fg"><label>Sat</label><input type="text" name="hours_sat" placeholder="8:00-14:00"></div>
                <div class="fg"><label>Sun</label><input type="text" name="hours_sun" placeholder="closed"></div>
            </div>
            <div class="pc-step-nav">
                <button type="button" class="btn-sm-outline" data-step-back>&larr; Back</button>
                <button type="button" class="btn-open" data-step-next>Next &rarr;</button>
            </div>
        </div>

        <div class="pc-step-panel" data-step-panel="3">
            <div class="pc-form-row">
                <div class="pc-section-label" style="flex-basis:100%;">First page</div>
                <div class="fg"><label>City ID (from the cities table, e.g. "dallas-tx")</label><input type="text" name="first_city_id" required></div>
                <div class="fg"><label>Drive minutes from hub</label><input type="number" name="first_city_drive_minutes" min="0" value="15"></div>
                <div class="fg"><label>Service radius (minutes)</label><input type="number" name="radius_minutes" min="0" value="30"></div>
                <div class="fg"><label>URL base path</label><input type="text" name="url_base_path" value="service-area"></div>

                <div class="pc-section-label" style="flex-basis:100%;">Block Topics — the first page's content</div>
                <div style="flex-basis:100%;font-size:.8rem;color:var(--pc-muted,#52607a);margin-bottom:8px;" id="pc-topics-niche-hint">
                    City topics for the niche picked in step 1.
                </div>
                <div style="flex-basis:100%;">
                    <?php foreach ($pcNichePools as $pcNicheId => $pcCityPool): ?>
                        <div class="pc-niche-topics" data-niche="<?= h($pcNicheId) ?>" style="display:none;">
                            <?php if (!empty($pcNicheAlways[$pcNicheId])): ?>
                            <p style="color:#6b7280;font-size:.78rem;margin:0 0 6px;">
                                Always included: <?= h(implode(', ', array_map(fn($s) => $pcCatalog[$s]['block'] ?? $s, $pcNicheAlways[$pcNicheId]))) ?>
                                — no pick needed.
                            </p>
                            <?php endif; ?>
                            <strong style="font-size:.84rem;">City topics for "<?= h($pcNicheId) ?>" — choose up to <?= (int) min(6, count($pcCityPool)) ?> of <?= count($pcCityPool) ?></strong>
                            <?= pluscities_render_pool_picker($pcCityPool, $pcNicheHappyCity[$pcNicheId] ?? [], $pcCatalog, 'pc-city-cb', 'city_block_picks', $pcNicheId) ?>

                            <?php $pcServicePool = $pcNicheServicePools[$pcNicheId] ?? []; ?>
                            <strong style="font-size:.84rem;display:block;margin-top:10px;">Service topics for "<?= h($pcNicheId) ?>" — choose up to <?= (int) min(4, count($pcServicePool)) ?> of <?= count($pcServicePool) ?></strong>
                            <?= pluscities_render_pool_picker($pcServicePool, $pcNicheHappyService[$pcNicheId] ?? [], $pcCatalog, 'pc-service-cb', 'service_block_picks', $pcNicheId) ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="pc-step-nav">
                <button type="button" class="btn-sm-outline" data-step-back>&larr; Back</button>
                <button type="button" class="btn-open" data-step-next>Next &rarr;</button>
            </div>
        </div>

        <div class="pc-step-panel" data-step-panel="4">
            <div class="pc-card" style="margin:0 0 14px;">
                <h4 style="margin-top:0;">Review</h4>
                <div id="pc-review-body"></div>
            </div>
            <div class="pc-step-nav">
                <button type="button" class="btn-sm-outline" data-step-back>&larr; Back</button>
                <button type="submit" class="btn-open">Create Client</button>
            </div>
        </div>
    </form>
    <script>
    (function() {
        var form = document.getElementById('pc-new-client-form');
        var nicheSelect = document.querySelector('select[name="niche_id"]');
        var nicheGroups = document.querySelectorAll('.pc-niche-topics');

        function enforceMax(selector, max) {
            var boxes = Array.prototype.slice.call(document.querySelectorAll(selector));
            var checkedCount = boxes.filter(function(b) { return b.checked; }).length;
            boxes.forEach(function(b) { if (!b.checked) b.disabled = (checkedCount >= max); });
        }
        function refreshCityEnforcement() {
            var activeNiche = nicheSelect.value;
            var visibleBoxes = document.querySelectorAll('.pc-city-cb[data-niche="' + activeNiche + '"]');
            var max = Math.min(6, visibleBoxes.length);
            enforceMax('.pc-city-cb[data-niche="' + activeNiche + '"]', max);
        }
        function refreshServiceEnforcement() {
            var activeNiche = nicheSelect.value;
            var visibleBoxes = document.querySelectorAll('.pc-service-cb[data-niche="' + activeNiche + '"]');
            var max = Math.min(4, visibleBoxes.length);
            enforceMax('.pc-service-cb[data-niche="' + activeNiche + '"]', max);
        }
        function onNicheChange() {
            var activeNiche = nicheSelect.value;
            // Reset every box first, not just the ones being hidden —
            // switching niches must never let a different niche's stale
            // picks ride along in the submission (a real bug caught before
            // shipping: hiding a group doesn't uncheck its boxes on its own,
            // and some block slugs exist in more than one niche's pool, so a
            // stray checked box could silently survive the whitelist
            // filter). Service pool joined this same per-niche scoping
            // 2026-11-26 once it became family-customizable.
            //
            // The newly-active niche's own boxes reset to b.defaultChecked
            // (the server-rendered `checked` attribute — happy-path picks
            // render pre-checked, 2026-11-26), not to false — otherwise
            // every niche would always start from a blank picker and this
            // whole feature would silently do nothing in this wizard.
            // .defaultChecked reflects the original markup and never
            // changes once JS starts toggling .checked, so this is safe to
            // call repeatedly as the niche selection changes back and forth.
            document.querySelectorAll('.pc-city-cb, .pc-service-cb').forEach(function(b) {
                b.disabled = false;
                b.checked = (b.getAttribute('data-niche') === activeNiche) ? b.defaultChecked : false;
            });
            nicheGroups.forEach(function(g) {
                g.style.display = (g.getAttribute('data-niche') === activeNiche) ? 'block' : 'none';
            });
            refreshCityEnforcement();
            refreshServiceEnforcement();
        }
        nicheSelect.addEventListener('change', onNicheChange);
        document.querySelectorAll('.pc-city-cb').forEach(function(b) { b.addEventListener('change', refreshCityEnforcement); });
        document.querySelectorAll('.pc-service-cb').forEach(function(b) { b.addEventListener('change', refreshServiceEnforcement); });

        onNicheChange();

        // Step UI — purely client-side, one plain form submitted once (same
        // backend as before). No session state to go stale, no server round
        // trips between steps — just show/hide + native reportValidity() for
        // per-step gating, reusing the browser's own validation UI instead of
        // hand-rolled error messages.
        var panels = Array.prototype.slice.call(document.querySelectorAll('.pc-step-panel'));
        var pills  = Array.prototype.slice.call(document.querySelectorAll('.pc-step-pill'));
        var currentStep = 1;

        function escapeHtml(s) {
            var d = document.createElement('div');
            d.textContent = s;
            return d.innerHTML;
        }
        function showStep(n) {
            currentStep = n;
            panels.forEach(function(p) { p.classList.toggle('is-active', p.getAttribute('data-step-panel') === String(n)); });
            pills.forEach(function(p) {
                var s = parseInt(p.getAttribute('data-step-pill'), 10);
                p.classList.toggle('is-active', s === n);
                p.classList.toggle('is-done', s < n);
            });
            if (n === 3) {
                var nicheLabel = nicheSelect.value || '(none picked in step 1)';
                document.getElementById('pc-topics-niche-hint').textContent = 'City topics for "' + nicheLabel + '" (picked in step 1).';
            }
            if (n === 4) buildReview();
            form.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
        function validateStep(n) {
            var panel = document.querySelector('.pc-step-panel[data-step-panel="' + n + '"]');
            var fields = Array.prototype.slice.call(panel.querySelectorAll('input, select'));
            for (var i = 0; i < fields.length; i++) {
                if (!fields[i].reportValidity()) return false;
            }
            return true;
        }
        function buildReview() {
            var rows = [
                ['Business name', form.querySelector('[name="business_name"]').value],
                ['Domain', form.querySelector('[name="domain"]').value],
                ['Agency', form.querySelector('[name="agency_id"]').value],
                ['Niche', form.querySelector('[name="niche_id"]').value],
                ['First city', form.querySelector('[name="first_city_id"]').value],
                ['City topics picked', String(document.querySelectorAll('.pc-city-cb:checked').length)],
                ['Service topics picked', String(document.querySelectorAll('.pc-service-cb:checked').length)],
            ];
            document.getElementById('pc-review-body').innerHTML = rows.map(function(r) {
                return '<div class="pc-review-row"><span>' + escapeHtml(r[0]) + '</span><span>' + escapeHtml(r[1] || '—') + '</span></div>';
            }).join('');
        }
        document.querySelectorAll('[data-step-next]').forEach(function(btn) {
            btn.addEventListener('click', function() {
                if (!validateStep(currentStep)) return;
                showStep(currentStep + 1);
            });
        });
        document.querySelectorAll('[data-step-back]').forEach(function(btn) {
            btn.addEventListener('click', function() { showStep(currentStep - 1); });
        });
    })();
    </script>
</div>

<?php lpc_admin_close(); ?>
