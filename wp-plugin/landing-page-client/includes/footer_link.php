<?php
/**
 * Without this, the hub page exists (reachable by URL) but is literally
 * undiscoverable from the client's own site — it's not a real WP page/post,
 * so it can never show up in search, related posts, or any menu on its own.
 *
 * Placement is admin-controlled (synced from the manifest, see remote.php),
 * not hardcoded — 'footer' (default), 'nav', or 'off':
 *   - 'footer': on this plugin's own /locations/ pages, a plain in-flow bar
 *     (lpc_render_footer_link(), called directly from rewrite.php — see its
 *     own comment for why). On the rest of the client's real site, a small
 *     fixed bottom-right badge instead (lpc_render_footer_badge(), via
 *     wp_footer). Real bug found live (2026-11-15) going through TWO rounds
 *     before landing on this: a plain in-flow wp_footer() link is NOT
 *     reliably placed on themes this plugin doesn't control the template
 *     for — Kadence renders its own fixed-position "sticky Call Now bar"
 *     BEFORE firing wp_footer(), so an in-flow link landed dumped at the raw
 *     end of <body>, behind/below that bar, on every real page of the site
 *     (home, services, everything except this plugin's own pages). There is
 *     no DOM position that's safe across every theme's own template — a
 *     fixed badge sidesteps the whole problem by not depending on DOM order
 *     for visibility at all.
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

// Called directly from rewrite.php, as the LAST thing inside .lpc-page
// (before that div closes) — this plugin fully controls its own virtual page
// template, so it can guarantee this lands in normal block flow regardless
// of what the surrounding theme does. NOT hooked to wp_footer (see
// lpc_render_footer_badge() below for the rest of the site).
function lpc_render_footer_link() {
	// On the hub page itself, "View Our Service Locations" points right back
	// at the page already being viewed — real gap found live (2026-11-15):
	// pointless and confusing on that one page, even though it's exactly what
	// every single location page needs.
	if ( get_query_var( 'lpc_route' ) && get_query_var( 'lpc_slug' ) === '' ) {
		return;
	}
	if ( get_option( 'lpc_api_key', '' ) === '' || get_option( 'lpc_nav_link_placement', 'footer' ) !== 'footer' ) {
		return;
	}
	$label = get_option( 'lpc_nav_link_label', 'View Our Service Locations' );
	echo '<p style="text-align:center;margin:0;padding:1em 0;background:#1a1a2e;">'
		. '<a href="' . esc_url( lpc_service_locations_url() ) . '" style="color:#fff;text-decoration:underline;font-weight:600;">' . esc_html( $label ) . '</a></p>';
}

// Site-wide reach for the rest of the client's real site (home, blog,
// service pages) — this plugin doesn't control those templates, so it can't
// guarantee any particular DOM position the way it can on its own pages.
// Real bug, found live (2026-11-15), round two: an in-flow wp_footer() link
// looked right in the raw HTML (correct markup, correct inline colors) but
// landed wherever wp_footer() happened to fire in THAT theme's own
// template — on Kadence, after its own fixed-position "sticky Call Now bar"
// template part, so it rendered dumped at the raw end of <body>, visually
// buried below that bar on every real page of the site. There's no DOM
// position guaranteed safe across arbitrary themes this plugin doesn't own —
// a small fixed badge sidesteps the problem entirely by not depending on DOM
// order for visibility at all. Skipped on this plugin's own /locations/
// pages (lpc_route is set) since lpc_render_footer_link() above already
// covers those with the full in-flow bar; showing both would be a redundant
// second call-to-action on the same page.
add_action( 'wp_footer', 'lpc_render_footer_badge' );
function lpc_render_footer_badge() {
	if ( get_query_var( 'lpc_route' ) ) {
		return;
	}
	if ( get_option( 'lpc_api_key', '' ) === '' || get_option( 'lpc_nav_link_placement', 'footer' ) !== 'footer' ) {
		return;
	}
	$label = get_option( 'lpc_nav_link_label', 'View Our Service Locations' );
	echo '<a href="' . esc_url( lpc_service_locations_url() ) . '" style="position:fixed;right:16px;bottom:16px;z-index:999999;background:#1a1a2e;color:#fff;padding:10px 18px;border-radius:999px;font-weight:600;font-size:14px;line-height:1.2;text-decoration:none;box-shadow:0 2px 10px rgba(0,0,0,.25);">'
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
	$item->title       = get_option( 'lpc_nav_link_label', 'View Our Service Locations' );
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
