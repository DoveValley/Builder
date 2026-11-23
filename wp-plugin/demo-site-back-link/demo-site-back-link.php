<?php
/**
 * Plugin Name: Demo Site Back Link
 * Description: Internal-only fixed badge linking back to Site Factory. Deployed on demo/rehearsal
 * WordPress installs so reviewing a rebuilt site doesn't require manually navigating back to the admin
 * panel. Never ship this on a real client site.
 * Author: Site Factory
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_footer', 'sf_demo_render_back_link_badge' );
function sf_demo_render_back_link_badge() {
	// Target updated 2026-11-23 (LPC Stage 3): admin/lpc_demo_sites.php, the
	// previous destination, was deleted -- it only ever rehearsed the old
	// LPC delivery mechanism, which no longer exists. sites.php (Site
	// Factory's own entry point) matches this badge's own label and isn't
	// going anywhere. The earlier mobile-collision CSS override tied to
	// get_query_var('lpc_route') is gone too -- that query var is never set
	// by anything anymore now that the LPC plugin itself is deleted.
	echo '<a href="http://srv1793661.hstgr.cloud/admin/sites.php" class="sf-demo-backlink" style="position:fixed;left:16px;bottom:16px;z-index:999999;background:#1e293b;color:#fff;padding:8px 14px;border-radius:999px;font-weight:600;font-size:12px;line-height:1.2;text-decoration:none;box-shadow:0 2px 10px rgba(0,0,0,.25);">'
		. '&larr; Site Factory' . '</a>';
}
