<?php
/**
 * Plugin Name: Demo Site Back Link
 * Description: Internal-only fixed badge linking back to the Site Factory's Demo Sites panel. Deployed on LPC demo/rehearsal WordPress installs so reviewing a rebuilt site doesn't require manually navigating back to the admin panel. Never ship this on a real client site.
 * Author: Site Factory
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_footer', 'sf_demo_render_back_link_badge' );
function sf_demo_render_back_link_badge() {
	// Same collision/fix as the LPC plugin's own "Service Area" badge
	// (includes/footer_link.php) -- the LPC plugin's mobile-only sticky call
	// bar sits at the bottom of the viewport under 760px on this plugin's
	// own routes, found overlapping this badge live on the Meade Restoration
	// test site, 2026-11-23.
	if ( get_query_var( 'lpc_route' ) ) {
		echo '<style>@media (max-width:760px){.sf-demo-backlink{bottom:76px !important;}}</style>';
	}
	echo '<a href="http://srv1793661.hstgr.cloud/admin/lpc_demo_sites.php" class="sf-demo-backlink" style="position:fixed;left:16px;bottom:16px;z-index:999999;background:#1e293b;color:#fff;padding:8px 14px;border-radius:999px;font-weight:600;font-size:12px;line-height:1.2;text-decoration:none;box-shadow:0 2px 10px rgba(0,0,0,.25);">'
		. '&larr; Site Factory' . '</a>';
}
