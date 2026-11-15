<?php
/**
 * Without this, the hub page exists (reachable by URL) but is literally
 * undiscoverable from the client's own site — it's not a real WP page/post,
 * so it can never show up in search, related posts, or any menu on its own.
 *
 * Deliberately a footer link, not an auto-inserted main-nav item: earlier
 * draft of this fix edited the theme's own primary menu directly, which
 * would mean silently rewriting navigation a real client already curated by
 * hand on their live site. A plain link in wp_footer() shows up on every
 * page of the site with zero risk of touching their actual menu data, and
 * removing it later is just unhooking this function — nothing stored to
 * clean up.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_footer', 'lpc_render_footer_link' );
function lpc_render_footer_link() {
	$api_key = get_option( 'lpc_api_key', '' );
	if ( $api_key === '' ) {
		return;
	}
	$base_path = get_option( 'lpc_base_path', '/locations' );
	$url       = home_url( trailingslashit( $base_path ) );
	echo '<p style="text-align:center;padding:1em 0;"><a href="' . esc_url( $url ) . '">View Our Service Locations</a></p>';
}
