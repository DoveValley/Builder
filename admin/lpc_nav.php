<?php
/**
 * One persistent nav shared across every Landing Page Clients admin page
 * (clients.php, client.php, client_wizard.php, page_editor.php) — before
 * this, each page hardcoded its own one-off topbar independently, with no
 * way to jump directly between pages; page_editor.php in particular only
 * linked back to the Locations & Services tab, so reaching Branding/Delivery
 * from the page editor meant detouring back through Locations first every
 * time. Mirrors the Infrastructure console's own infra_header()
 * (admin/infra/bootstrap.php) — a proven "shared nav function called from
 * every separate file" pattern already used elsewhere in this panel — reusing
 * the existing .tabs/.tab-link CSS client.php's own tab bar already
 * established, rather than inventing a new visual style.
 *
 * $activeTop:       'clients' | 'new_client' — which top-row item is active.
 * $client_id/$business_name: pass both (or neither) to show the second,
 *                   per-client row; null on clients.php/client_wizard.php,
 *                   which have no single client in context yet.
 * $activeClientTab: one of overview/locations/branding/content_profile/
 *                   delivery/reporting. page_editor.php has no tab query var
 *                   of its own — callers pass 'locations' there, since
 *                   editing a page's content IS part of that tab's job.
 */
function lpc_render_nav($activeTop, $client_id = null, $business_name = null, $activeClientTab = null) {
    echo '<div class="tabs" style="margin-bottom:' . ($client_id !== null ? '10px' : '24px') . ';">';
    echo '<a class="tab-link ' . ($activeTop === 'clients' ? 'active' : '') . '" href="clients.php">All Clients</a>';
    echo '<a class="tab-link ' . ($activeTop === 'new_client' ? 'active' : '') . '" href="client_wizard.php?restart=1">+ New Client</a>';
    echo '</div>';

    if ($client_id === null) {
        return;
    }

    echo '<p style="margin:0 0 10px;color:#6b7280;font-size:.82rem;">' . h($business_name) . '</p>';
    echo '<div class="tabs" style="margin-bottom:24px;">';
    foreach ([
        'overview'         => 'Overview',
        'locations'        => 'Locations &amp; Services',
        'branding'         => 'Branding',
        'content_profile'  => 'Content Profile',
        'delivery'         => 'Delivery',
        'reporting'        => 'Reporting',
    ] as $key => $label) {
        $cls = $activeClientTab === $key ? 'active' : '';
        echo '<a class="tab-link ' . $cls . '" href="client.php?id=' . urlencode($client_id) . '&tab=' . $key . '">' . $label . '</a>';
    }
    echo '</div>';
}
