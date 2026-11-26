<?php
/**
 * admin/lpc_admin_layout.php — the shared navy/lime sidebar shell for every
 * LPC/PlusCities admin page (clients.php, client.php, client_wizard.php,
 * approvals.php, agency_branding.php, agencies.php, client_portal.php,
 * page_editor.php). Palette/fonts lifted straight from the public
 * PlusCities site (wp-builder-pluscities-chrome.php's own :root vars) so an
 * agency or client logging in after seeing the marketing site and login
 * page gets one consistent product, not a branded front door and a plain
 * internal tool behind it.
 *
 * Prototyped on clients.php alone first, reviewed live, then extracted
 * here for the rest once approved — one shared layout, not 10 copies of
 * the same ~150 lines of CSS/markup to keep in sync by hand.
 *
 * Does NOT touch Site Factory (the other ~80 admin pages) — those keep
 * their existing look. A deliberate scope line, not an oversight.
 */

/** Fonts + the shared shell/sidebar/topbar/stat-tile/table/badge/button CSS. Page-specific extra rules (e.g. client.php's wizard-step styles) stay in that page's own <style> block, called after this. */
function lpc_admin_head_assets(): void {
    ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root{
    --pc-navy:#0B1B33; --pc-navy-2:#12264A; --pc-navy-line:#2B4670;
    --pc-lime:#C8F55A; --pc-lime-soft:#EEF6DA; --pc-lime-ink:#3D6B0E;
    --pc-ink:#1C2B45; --pc-ink-2:#42506A; --pc-muted:#52607A;
    --pc-on-navy:#C9D4E6; --pc-mist:#F4F7FB; --pc-line:#E2E8F2;
}
*{box-sizing:border-box;}
body.admin-body{margin:0;font-family:'Inter',-apple-system,BlinkMacSystemFont,sans-serif;background:var(--pc-mist);color:var(--pc-ink);}
.pcadmin-shell{display:flex;min-height:100vh;}

.pcadmin-sidebar{width:232px;flex:0 0 232px;background:var(--pc-navy);color:var(--pc-on-navy);display:flex;flex-direction:column;position:sticky;top:0;height:100vh;}
.pcadmin-brand{padding:22px 20px;border-bottom:1px solid var(--pc-navy-line);font-family:'Space Grotesk',sans-serif;font-weight:700;font-size:1.05rem;color:#fff;display:flex;align-items:center;gap:10px;}
.pcadmin-nav{flex:1;padding:14px 10px;overflow-y:auto;}
.pcadmin-nav-section{font-size:.68rem;text-transform:uppercase;letter-spacing:.06em;color:var(--pc-muted);padding:14px 12px 6px;}
.pcadmin-nav a{display:flex;align-items:center;gap:10px;padding:9px 12px;border-radius:8px;color:var(--pc-on-navy);text-decoration:none;font-size:.88rem;font-weight:500;margin-bottom:2px;}
.pcadmin-nav a:hover{background:var(--pc-navy-2);color:#fff;}
.pcadmin-nav a.active{background:var(--pc-lime);color:var(--pc-navy);font-weight:700;}
.pcadmin-nav a.active svg{color:var(--pc-navy);}
.pcadmin-nav a svg{flex-shrink:0;color:var(--pc-on-navy);}
.pcadmin-nav-badge{margin-left:auto;background:rgba(255,255,255,.15);color:#fff;font-size:.68rem;font-weight:700;padding:1px 7px;border-radius:99px;}
.pcadmin-nav a.active .pcadmin-nav-badge{background:var(--pc-navy);color:var(--pc-lime);}
.pcadmin-sidebar-bottom{padding:14px 10px;border-top:1px solid var(--pc-navy-line);}
.pcadmin-sidebar-bottom a{display:flex;align-items:center;gap:10px;padding:9px 12px;border-radius:8px;color:var(--pc-on-navy);text-decoration:none;font-size:.88rem;font-weight:500;}
.pcadmin-sidebar-bottom a:hover{background:var(--pc-navy-2);color:#fff;}
.pcadmin-whoami{padding:14px 20px 16px;border-bottom:1px solid var(--pc-navy-line);}
.pcadmin-whoami .role{font-size:.68rem;text-transform:uppercase;letter-spacing:.06em;color:var(--pc-muted);}
.pcadmin-whoami .name{font-size:.86rem;font-weight:700;color:#fff;margin-top:1px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}

.pcadmin-main{flex:1;min-width:0;}
.pcadmin-topbar{background:#fff;border-bottom:1px solid var(--pc-line);padding:18px 32px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;}
.pcadmin-topbar h1{font-family:'Space Grotesk',sans-serif;font-size:1.3rem;font-weight:700;margin:0;color:var(--pc-ink);}
.pcadmin-hamburger{display:none;background:none;border:1px solid var(--pc-line);border-radius:8px;width:36px;height:36px;align-items:center;justify-content:center;cursor:pointer;margin-right:8px;}
.lpc-wrap{max-width:1180px;margin:0 auto;padding:28px 32px 64px;}

.pc-btn{display:inline-flex;align-items:center;gap:6px;border-radius:10px;padding:9px 16px;font-size:.85rem;font-weight:700;text-decoration:none;border:1.5px solid transparent;cursor:pointer;font-family:inherit;}
.pc-btn-lime{background:var(--pc-lime);color:var(--pc-navy);}
.pc-btn-lime:hover{filter:brightness(.96);}
.pc-btn-outline-navy{background:#fff;color:var(--pc-ink);border-color:var(--pc-line);}
.pc-btn-outline-navy:hover{border-color:var(--pc-navy-line);}

/*
 * .btn-open/.btn-sm-outline/.btn-sm-danger are used throughout client.php,
 * client_wizard.php, page_editor.php, agencies.php, and approvals.php (80+
 * call sites — Save/Publish/Remove/Delete/Regenerate/etc.) but have NO CSS
 * definition anywhere in the shared stylesheets — the only real one found
 * anywhere in this codebase is a one-off <style> block local to sites.php
 * alone. Every other page using these classes, on every admin surface (not
 * just these 8), has been rendering them as plain unstyled browser buttons.
 * Pre-existing gap, not something this redesign introduced — fixing it here
 * for the 8 LPC pages specifically (same color roles as sites.php's own
 * version: .btn-open primary, .btn-sm-outline secondary, .btn-sm-danger
 * destructive) rather than renaming 80+ call sites, since the classes
 * already correctly describe each button's role — they just needed colors.
 */
.btn-open{background:var(--pc-lime);color:var(--pc-navy);border:1.5px solid transparent;padding:8px 16px;border-radius:8px;font-size:.84rem;font-weight:700;cursor:pointer;text-decoration:none;display:inline-block;font-family:inherit;}
.btn-open:hover{filter:brightness(.96);}
.btn-sm-outline{background:#fff;border:1px solid var(--pc-line);color:var(--pc-ink);padding:6px 12px;border-radius:7px;font-size:.8rem;font-weight:600;cursor:pointer;text-decoration:none;display:inline-block;font-family:inherit;}
.btn-sm-outline:hover{border-color:var(--pc-navy-line);background:var(--pc-mist);}
.btn-sm-outline:disabled{opacity:.5;cursor:not-allowed;}
.btn-sm-danger{background:#fff;border:1px solid #fca5a5;color:#dc2626;padding:6px 12px;border-radius:7px;font-size:.8rem;font-weight:600;cursor:pointer;font-family:inherit;}
.btn-sm-danger:hover{background:#fef2f2;}

.pcadmin-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:16px;margin-bottom:24px;}
.pcadmin-stat{background:#fff;border:1px solid var(--pc-line);border-radius:14px;padding:18px 20px;}
.pcadmin-stat .num{font-family:'Space Grotesk',sans-serif;font-size:1.6rem;font-weight:700;color:var(--pc-ink);}
.pcadmin-stat .num.warn{color:#b45309;}
.pcadmin-stat .label{font-size:.78rem;color:var(--pc-muted);margin-top:2px;}

.lpc-table{width:100%;border-collapse:collapse;background:#fff;border:1px solid var(--pc-line);border-radius:14px;overflow:hidden;box-shadow:0 1px 2px rgba(11,27,51,.04);}
.lpc-table th{text-align:left;font-size:.72rem;text-transform:uppercase;letter-spacing:.04em;color:var(--pc-muted);padding:12px 16px;background:var(--pc-mist);border-bottom:1px solid var(--pc-line);}
.lpc-table td{padding:13px 16px;border-bottom:1px solid var(--pc-mist);font-size:.88rem;color:var(--pc-ink);}
.lpc-table tr:last-child td{border-bottom:none;}
.lpc-table tr:hover td{background:var(--pc-mist);}
.lpc-table a.lpc-name{font-weight:700;color:var(--pc-ink);text-decoration:none;}
.lpc-table a.lpc-name:hover{color:var(--pc-lime-ink);text-decoration:underline;}
.lpc-badge{font-size:.7rem;font-weight:700;padding:3px 10px;border-radius:99px;display:inline-block;}
.lpc-badge-connected{background:var(--pc-lime-soft);color:var(--pc-lime-ink);}
.lpc-badge-stale{background:#fef9c3;color:#92400e;}
.lpc-badge-never{background:var(--pc-mist);color:var(--pc-muted);}
.lpc-empty{text-align:center;padding:64px 24px;background:#fff;border:2px dashed var(--pc-line);border-radius:14px;}
.lpc-empty h2{font-size:1.3rem;margin:0 0 8px;color:var(--pc-ink);font-family:'Space Grotesk',sans-serif;}
.lpc-empty p{color:var(--pc-muted);margin:0 0 24px;}

/* Per-record sub-tabs (client.php/page_editor.php's "which aspect of THIS client" row) —
   a second-level nav within the content area, distinct from the sidebar's top-level nav. */
.pcadmin-subtabs{display:flex;gap:4px;border-bottom:1px solid var(--pc-line);margin-bottom:22px;flex-wrap:wrap;}
.pcadmin-subtabs a{padding:10px 14px;font-size:.85rem;font-weight:600;color:var(--pc-muted);text-decoration:none;border-bottom:2px solid transparent;margin-bottom:-1px;border-radius:8px 8px 0 0;}
.pcadmin-subtabs a:hover{color:var(--pc-ink);background:var(--pc-mist);}
.pcadmin-subtabs a.active{color:var(--pc-navy);background:var(--pc-lime);border-bottom-color:var(--pc-lime);font-weight:700;}
.pcadmin-subtabs a.active:hover{background:var(--pc-lime);}

@media (max-width:880px){
    .pcadmin-sidebar{position:fixed;left:0;top:0;bottom:0;z-index:40;transform:translateX(-100%);transition:transform .2s ease;}
    .pcadmin-sidebar.open{transform:translateX(0);box-shadow:20px 0 40px rgba(11,27,51,.25);}
    .pcadmin-hamburger{display:flex;}
}
</style>
    <?php
}

/**
 * Nav items vary by real role — not by whatever the current impersonation
 * overlay happens to be, since lpc_current_actor_role() already reflects
 * that overlay correctly (same session fields either way, by design — see
 * lpc_start_impersonation()'s own doc comment). An impersonated session
 * sees exactly the sidebar that identity would really see, which is the
 * whole point of impersonation.
 */
function lpc_admin_nav_items(): array {
    $role = lpc_current_actor_role();

    // An End Client has exactly one screen (this one) and no business on
    // any of the editing/approval/agency pages below — every one of those
    // redirects an end_client session straight back here anyway, so the
    // nav shouldn't offer them in the first place.
    if ($role === 'end_client') {
        return [
            ['key' => 'reporting', 'href' => 'client_portal.php', 'label' => 'Reporting',
                'icon' => '<path d="M3 3v18h18M7 14l4-4 3 3 5-6"/>'],
        ];
    }

    $items = [];

    // Old-LPC screens (All Clients/New Client/Approvals) retired here
    // 2026-11-23 as part of LPC Stage 3 — see docs/lpc-stage3-retirement-
    // scoping.md. super_admin gets no replacement first item (pluscities_
    // dashboard.php explicitly rejects anyone but agency_admin/
    // agency_editor, redirecting to pluscities_clients.php — confirmed
    // before wiring this, not assumed) and just lands on the universal
    // items below like any other screen.
    if ($role !== 'super_admin') {
        // Agencies land on a real PlusCities-native home instead — a simple,
        // genuinely useful capability (what needs attention right now)
        // rather than one more flat list to scan.
        $items[] = ['key' => 'dashboard', 'href' => 'pluscities_dashboard.php', 'label' => 'Dashboard',
            'icon' => '<rect x="3" y="3" width="7" height="9"/><rect x="14" y="3" width="7" height="5"/><rect x="14" y="12" width="7" height="9"/><rect x="3" y="16" width="7" height="5"/>'];
    }

    // Client create/list — the one piece of onboarding that had no panel
    // screen until now; every client before this was hand-authored JSON.
    $items[] = ['key' => 'pluscities_clients', 'href' => 'pluscities_clients.php', 'label' => 'PlusCities Clients',
        'icon' => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/>'];
    // The real table-of-pages UI, spec Section 11 — stage-dot matrix per row,
    // modeled on the fleet's Go-Live grid. Row click drills into
    // pluscities_page.php's block-level detail view.
    $items[] = ['key' => 'pluscities_pages', 'href' => 'pluscities_pages.php',
        'label' => 'PlusCities Pages', 'icon' => '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M7 8h10M7 12h10M7 16h6"/>'];
    // Photos deliberately has no top-level sidebar entry (removed 2026-11-25)
    // — with no client selected it only ever showed a "choose a client"
    // picker, so it was a pure duplicate of Clients -> pick a client ->
    // Photos tab (pluscities_client_subtabs() below), never a real
    // agency-wide view the way Pages' bulk-action table is.

    if ($role === 'agency_admin') {
        $items[] = ['key' => 'branding', 'href' => 'agency_branding.php', 'label' => 'My Branding',
            'icon' => '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4.4 3.6-8 8-8s8 3.6 8 8"/>'];
        // Real per-person Agency Editor accounts (spec Section 1/10) —
        // Agency-Admin-only, same gate as admin/pluscities_team.php itself.
        $items[] = ['key' => 'pluscities_team', 'href' => 'pluscities_team.php', 'label' => 'Team',
            'icon' => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>'];
    }

    if ($role === 'super_admin') {
        $items[] = ['section' => 'Platform Ops'];
        $items[] = ['key' => 'agencies', 'href' => 'agencies.php', 'label' => 'Agencies',
            'icon' => '<path d="M3 21V9l9-6 9 6v12M9 21v-6h6v6"/>'];
        $items[] = ['key' => 'platform_info', 'href' => 'platform_info.php', 'label' => 'Platform Information',
            'icon' => '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/>'];
        $items[] = ['key' => 'settings', 'href' => 'settings.php', 'label' => 'Settings',
            'icon' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>'];

        $items[] = ['section' => 'Build Tools'];
        // "Niche Profiles (old)" removed here 2026-11-23 (LPC Stage 3) —
        // admin/niche_profiles.php was the old engine's own content-slot
        // library, confirmed retiring alongside the rest of LPC. The real
        // niche library below is unaffected — always was a separate system.
        $items[] = ['key' => 'pluscities_niches', 'href' => 'pluscities_niches.php', 'label' => 'Niche Library',
            'icon' => '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20M4 4.5A2.5 2.5 0 0 1 6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15Z"/>'];
        // Read-only per-niche info sheet (2026-11-26) — every real niche's
        // full built-out content (services + keyword data, niche_table,
        // trust bar, safety toolkit, resolved block composition, compliance
        // preset in effect) in one view, grouped by family. Separate from
        // the Niche Library's own edit form on purpose: that screen is for
        // authoring raw fields, this one is for seeing what a niche actually
        // amounts to once built.
        $items[] = ['key' => 'pluscities_niche_info', 'href' => 'pluscities_niche_info.php', 'label' => 'Niche Information',
            'icon' => '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/>'];
        // "Demo Sites" (lpc_demo_sites.php) removed 2026-11-23 (LPC Stage 3,
        // final step) — it only ever rehearsed the old LPC delivery
        // mechanism. The demo-sites box, its WordPress installs, and the SSH
        // plumbing in includes/lpc_demo_box.php are KEPT: confirmed shared
        // with WP Builder below, which is the tool that replaced this screen.
        $items[] = ['key' => 'wp_builder', 'href' => 'wp_builder.php', 'label' => 'WP Builder',
            'icon' => '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>'];
        // Brings back a real link list of every site on the demo box (the old
        // "Demo Sites" screen's one genuinely still-needed job after its
        // onboarding-wizard half was retired 2026-11-23, Stage 3) — 2026-11-26.
        $items[] = ['key' => 'site_demos', 'href' => 'site_demos.php', 'label' => 'Site Demos',
            'icon' => '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/>'];
        $items[] = ['key' => 'sites', 'href' => 'sites.php', 'label' => '&larr; Site Factory',
            'icon' => '<path d="M3 12h18M3 6h18M3 18h18"/>'];
    }

    return $items;
}

function lpc_admin_sidebar(string $activeKey): void {
    $who = lpc_current_actor_identity();
    $brand = lpc_actor_branding();
    ?>
    <aside class="pcadmin-sidebar" id="pcadmin-sidebar">
        <div class="pcadmin-brand">
            <?php if ($brand['logo_url'] !== ''): ?>
                <img src="<?= h($brand['logo_url']) ?>" alt="" style="height:22px;width:auto;display:block;">
            <?php else: ?>
                <svg width="22" height="22" viewBox="0 0 28 28" fill="none"><path d="M14 1C7.9 1 3 5.9 3 12c0 7.8 11 15 11 15s11-7.2 11-15c0-6.1-4.9-11-11-11z" fill="#C8F55A"/><rect x="11" y="5" width="6" height="16" rx="1" fill="#0B1B33"/><rect x="7" y="10" width="14" height="6" rx="1" fill="#0B1B33"/></svg>
            <?php endif; ?>
            <?= h($brand['name']) ?>
        </div>
        <div class="pcadmin-whoami">
            <div class="role"><?= h($who['role_label']) ?></div>
            <div class="name"><?= h($who['name']) ?></div>
        </div>
        <nav class="pcadmin-nav">
            <?php foreach (lpc_admin_nav_items() as $item): ?>
                <?php if (isset($item['section'])): ?>
                    <div class="pcadmin-nav-section"><?= h($item['section']) ?></div>
                <?php else: ?>
                    <a href="<?= h($item['href']) ?>" class="<?= $item['key'] === $activeKey ? 'active' : '' ?>">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><?= $item['icon'] ?></svg>
                        <?= $item['label'] /* may contain &larr; entity, not escaped */ ?>
                        <?php if (!empty($item['badge'])): ?><span class="pcadmin-nav-badge"><?= (int) $item['badge'] ?></span><?php endif; ?>
                    </a>
                <?php endif; ?>
            <?php endforeach; ?>
        </nav>
        <div class="pcadmin-sidebar-bottom">
            <a href="logout.php">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/></svg>
                Log out
            </a>
        </div>
    </aside>
    <div class="pcadmin-overlay" id="pcadmin-overlay" onclick="pcadminToggleSidebar()" style="display:none;position:fixed;inset:0;background:rgba(11,27,51,.35);z-index:30;"></div>
    <?php
}

/** Opens the whole shell: sidebar, overlay, main column, impersonation banner, topbar, then the content wrapper. Pair with lpc_admin_close(). */
function lpc_admin_open(string $activeKey, string $title, string $actionsHtml = ''): void {
    ?>
<body class="admin-body">
<div class="pcadmin-shell">
    <?php lpc_admin_sidebar($activeKey); ?>
    <div class="pcadmin-main">
        <?php lpc_render_impersonation_banner(); ?>
        <header class="pcadmin-topbar">
            <div style="display:flex;align-items:center;">
                <button class="pcadmin-hamburger" onclick="pcadminToggleSidebar()" aria-label="Open menu">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M3 12h18M3 18h18"/></svg>
                </button>
                <h1><?= h($title) ?></h1>
            </div>
            <div style="display:flex;gap:10px;">
                <?= $actionsHtml ?>
            </div>
        </header>
    <div class="lpc-wrap">
    <?php
}

/**
 * Per-client sub-tab bar, reusing the `.pcadmin-subtabs` CSS rule that's
 * existed since this file was written for the old (now-deleted) client.php/
 * page_editor.php's own tab row but had no PlusCities-native caller until
 * 2026-11-25 — every PlusCities client-scoped screen was its own separate
 * full page with no shared navigation between them, which is what pushed
 * every action onto pluscities_clients.php's list row as inline buttons
 * instead. "Download Plugin" is deliberately NOT a tab here — it's a direct
 * file download, not a page, and it's actually agency-scoped (one branded
 * ZIP per agency, not per client) despite living on the per-client list row
 * historically; it stays a plain action link on the Overview tab instead.
 */
function pluscities_client_subtabs(string $clientId, string $activeKey): void {
    $tabs = [
        'overview'    => ['pluscities_client_overview.php', 'Overview'],
        'business_info' => ['pluscities_business_info.php', 'Business Info'],
        'photos'      => ['pluscities_photos.php', 'Photos'],
        'pages'       => ['pluscities_pages.php', 'Pages'],
        'page_add'    => ['pluscities_page_add.php', '+ Add Page'],
        'hub_edit'    => ['pluscities_hub_edit.php', 'Service Area Page'],
        'page_styling'=> ['pluscities_page_styling.php', 'Page Styling'],
    ];
    echo '<div class="pcadmin-subtabs">';
    foreach ($tabs as $key => [$href, $label]) {
        $class = $key === $activeKey ? ' class="active"' : '';
        echo '<a href="' . h($href) . '?client_id=' . h($clientId) . '"' . $class . '>' . h($label) . '</a>';
    }
    echo '</div>';
}

/**
 * Per-agency sub-tab bar, same pattern/reasoning as pluscities_client_
 * subtabs() above — added 2026-11-25 after Scott asked for the same
 * click-into-it-first workspace for agencies.php's rows. Platform-Admin-only
 * (agencies.php itself is super_admin-only), so unlike the client version
 * there's no "the agency views its own tabs" case to worry about.
 */
function pluscities_agency_subtabs(string $agencyId, string $activeKey): void {
    $tabs = [
        'overview' => ['agency_overview.php', 'Overview'],
        'clients'  => ['pluscities_clients.php', 'Clients'],
        'team'     => ['pluscities_team.php', 'Team'],
        'billing'  => ['agency_billing.php', 'Billing'],
    ];
    echo '<div class="pcadmin-subtabs">';
    foreach ($tabs as $key => [$href, $label]) {
        $class = $key === $activeKey ? ' class="active"' : '';
        echo '<a href="' . h($href) . '?agency_id=' . h($agencyId) . '"' . $class . '>' . h($label) . '</a>';
    }
    echo '</div>';
}

function lpc_admin_close(): void {
    ?>
    </div>
    </div>
</div>
<script>
function pcadminToggleSidebar() {
    document.getElementById('pcadmin-sidebar').classList.toggle('open');
    const overlay = document.getElementById('pcadmin-overlay');
    const isOpen = document.getElementById('pcadmin-sidebar').classList.contains('open');
    overlay.style.display = isOpen ? 'block' : 'none';
}
</script>
</body>
</html>
    <?php
}
