<?php
/**
 * Without this, the hub page exists (reachable by URL) but is literally
 * undiscoverable from the client's own site — it's not a real WP page/post,
 * so it can never show up in search, related posts, or any menu on its own.
 *
 * Placement is admin-controlled (synced from the manifest, see remote.php),
 * not hardcoded — 'footer' (default), 'nav', or 'off':
 *   - 'footer': the SAME small fixed bottom-right badge on every page of the
 *     site — this plugin's own /locations/ pages included, no special case
 *     for them. Took three rounds to land here (2026-11-15): first an in-flow
 *     wp_footer() link everywhere, then a split (in-flow bar on this plugin's
 *     own pages via a direct call, badge everywhere else via the hook) once
 *     the in-flow version was found landing wherever wp_footer() happened to
 *     fire in a given theme's own template (Kadence renders its own
 *     fixed-position "sticky Call Now bar" BEFORE firing wp_footer(), so an
 *     in-flow link rendered buried below it). Scott's call: just use the
 *     fixed badge everywhere, including our own pages — one rendering path,
 *     not two, and it sidesteps DOM placement entirely rather than solving
 *     it per-theme.
 *   - 'nav': injects into the theme's primary nav menu via
 *     wp_nav_menu_objects, so it's styled exactly like the theme's other
 *     menu items. Only works on classic (non-block-based) themes that have
 *     an actual menu assigned to the 'primary' location — a block-based/FSE
 *     theme's Navigation block never fires this filter at all, so this mode
 *     can silently do nothing on some themes. Not fail-proof; 'footer' is
 *     the safer default for exactly this reason.
 *   - 'off': this plugin adds no link anywhere — e.g. the client wants to add
 *     their own footer link by hand instead. Scott flips this per-client from
 *     the admin panel's Delivery tab (Placement dropdown).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function lpc_service_locations_url() {
	$base_path = get_option( 'lpc_base_path', '/locations' );
	return home_url( trailingslashit( $base_path ) );
}

// One rendering path, every page, unconditionally, via wp_footer —
// including this plugin's own /locations/ pages (which already call
// get_header()/get_footer() same as any other page, so wp_footer() fires
// there too with no special wiring needed) and the hub page itself. Scott's
// call (2026-11-15): simpler to just always show it than to special-case the
// one page it points at — fixed positioning means DOM placement never
// matters, on any theme, and one unconditional rule is simpler than one
// unconditional rule plus an exception.
add_action( 'wp_footer', 'lpc_render_footer_badge' );
function lpc_render_footer_badge() {
	if ( get_option( 'lpc_api_key', '' ) === '' || get_option( 'lpc_nav_link_placement', 'footer' ) !== 'footer' ) {
		return;
	}
	$label = get_option( 'lpc_nav_link_label', 'Service Area' );
	// On this plugin's own single-page routes, a mobile-only sticky call bar
	// (renderer.php's lpc_render_sticky_call_bar()) occupies the bottom edge
	// of the viewport under 760px -- real collision found live (2026-11-23):
	// this badge's fixed bottom:16px put it floating on top of that bar's
	// text instead of above it. Lifted only here, only under that same
	// breakpoint, via a small self-contained <style> block (this file has no
	// dependency on styles.php by design -- it must work on every page of
	// the site, not just this plugin's own routes, so it can't rely on that
	// stylesheet having been printed).
	if ( get_query_var( 'lpc_route' ) ) {
		echo '<style>@media (max-width:760px){.lpc-footer-badge{bottom:76px !important;}}</style>';
	}
	echo '<a href="' . esc_url( lpc_service_locations_url() ) . '" class="lpc-footer-badge" style="position:fixed;right:16px;bottom:16px;z-index:999999;background:#1a1a2e;color:#fff;padding:10px 18px;border-radius:999px;font-weight:600;font-size:14px;line-height:1.2;text-decoration:none;box-shadow:0 2px 10px rgba(0,0,0,.25);">'
		. esc_html( $label ) . '</a>';
}

add_filter( 'wp_nav_menu_objects', 'lpc_inject_nav_menu_link', 10, 2 );
function lpc_inject_nav_menu_link( $items, $args ) {
	if ( get_option( 'lpc_api_key', '' ) === '' || get_option( 'lpc_nav_link_placement', 'footer' ) !== 'nav' ) {
		return $items;
	}
	// Only the theme's registered 'primary' location, not every menu on the
	// site — avoids duplicating into a secondary/social/mobile menu the
	// theme may render from a separate wp_nav_menu() call with no location
	// set at all (those pass theme_location === '' here, not 'primary').
	if ( ( $args->theme_location ?? '' ) !== 'primary' ) {
		return $items;
	}

	$item              = new stdClass();
	$item->ID          = 0;
	$item->db_id       = 0;
	$item->title       = get_option( 'lpc_nav_link_label', 'Service Area' );
	$item->url         = lpc_service_locations_url();
	$item->menu_item_parent = 0;
	$item->object      = 'custom';
	$item->object_id    = 0;
	$item->type        = 'custom';
	$item->type_label  = 'Custom Link';
	$item->target      = '';
	$item->attr_title  = '';
	$item->description = '';
	$item->classes     = [ 'lpc-nav-link' ];
	$item->xfn         = '';
	$item->current     = false;

	$items[] = $item;
	return $items;
}
