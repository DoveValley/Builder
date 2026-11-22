<?php
/**
 * The per-client sub-tab row shared by client.php and page_editor.php — a
 * second-level nav for "which aspect of THIS ONE client am I viewing,"
 * distinct from the top-level sidebar nav (lpc_admin_layout.php) that
 * replaced this function's own old top-row ('All Clients'/'+ New Client')
 * once every LPC page moved onto the shared sidebar shell. Kept as its own
 * function/file rather than folded into lpc_admin_layout.php since it's
 * record-scoped, not page-scoped — the sidebar doesn't know which client
 * is open, this does.
 *
 * $activeClientTab: one of overview/locations/branding/content_profile/
 *                   delivery/service_area_page/city_service_page/reporting.
 *                   page_editor.php has no tab query var of its own —
 *                   callers pass 'locations' there, since editing a page's
 *                   content IS part of that tab's job.
 */
function lpc_render_nav($client_id, $business_name, $activeClientTab) {
    echo '<p style="margin:0 0 10px;color:var(--pc-muted);font-size:.82rem;">' . h($business_name) . '</p>';
    echo '<div class="pcadmin-subtabs">';
    foreach ([
        'overview'           => 'Overview',
        'locations'          => 'Cities &amp; Services',
        'branding'           => 'Branding',
        'content_profile'    => 'Content Profile',
        'delivery'           => 'Delivery',
        'service_area_page'  => 'Service Area Page',
        'city_service_page'  => 'City Service Page',
        'reporting'          => 'Reporting',
    ] as $key => $label) {
        $cls = $activeClientTab === $key ? 'active' : '';
        echo '<a class="' . $cls . '" href="client.php?id=' . urlencode($client_id) . '&tab=' . $key . '">' . $label . '</a>';
    }
    echo '</div>';
}
