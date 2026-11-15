<?php
/**
 * Without this, the hub page exists (reachable by URL) but is literally
 * undiscoverable from the client's own site — it's not a real WP page/post,
 * so it can never show up in search, related posts, or any menu on its own.
 *
 * Placement is admin-controlled (synced from the manifest, see remote.php),
 * not hardcoded — 'footer' (default), 'nav', or 'off':
 *   - 'footer': a plain wp_footer() link. Works on nearly every theme,
 *     including block-based/FSE ones, since wp_footer() is core WP plumbing
 *     every theme calls, completely independent of how the theme builds its
 *     navigation. Zero risk of touching a menu the client curated by hand.
 *   - 'nav': injects into the theme's primary nav menu via
 *     wp_nav_menu_objects, so it's styled exactly like the theme's other
 *     menu items. Only works on classic (non-block-based) themes that have
 *     an actual menu assigned to the 'primary' location — a block-based/FSE
 *     theme's Navigation block never fires this filter at all, so this mode
 *     can silently do nothing on some themes. Not fail-proof; 'footer' is
 *     the safer default for exactly this reason.
 *   - 'off': this plugin adds no link anywhere; the client (or whoever
 *     manages their site) handles discovery themselves, e.g. a manual
 *     Appearance > Menus custom link.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function lpc_service_locations_url() {
	$base_path = get_option( 'lpc_base_path', '/locations' );
	return home_url( trailingslashit( $base_path ) );
}

// NOT hooked to wp_footer. Real bug, found live on restore-2 (2026-11-15):
// themes vary wildly in WHERE wp_footer() actually fires relative to their
// own markup. Kadence here closes its #wrapper div and renders its own
// "sticky Call Now bar" template part BEFORE calling wp_footer() — so a
// wp_footer-hooked link landed dumped at the raw end of <body>, after the
// theme's fixed-position sticky bar, outside the page wrapper entirely.
// Dark-on-light styling isn't the issue (confirmed present in the raw HTML);
// DOM placement is. This plugin already fully controls its own virtual page
// template (get_header()/content/get_footer() in rewrite.php), so instead of
// trusting a theme's wp_footer timing, rewrite.php calls this function
// directly, right after our own content and before get_footer() — guaranteed
// to land inside the theme's real wrapper, directly below the page content,
// regardless of what any given theme does afterward.
function lpc_render_footer_link() {
	if ( get_option( 'lpc_api_key', '' ) === '' || get_option( 'lpc_nav_link_placement', 'footer' ) !== 'footer' ) {
		return;
	}
	$label = get_option( 'lpc_nav_link_label', 'View Our Service Locations' );
	// Fully self-contained inline styling, not just plain text — real bug
	// found on restore-2's live site (2026-11-15): this renders via
	// wp_footer(), completely outside .lpc-page's scoped <style> block, so
	// it has zero explicit color of its own and was invisible, inheriting
	// whatever the surrounding theme context happened to resolve to at that
	// exact point in the DOM (here: right after a <script> tag, before a
	// hidden mobile-nav drawer — not inside any obviously-styled footer
	// wrapper). A fixed, guaranteed-contrasting background+color pair, same
	// "always control both colors together, never just one" lesson already
	// applied inside .lpc-page, fixes it regardless of theme context.
	echo '<p style="text-align:center;margin:0;padding:1em 0;background:#1a1a2e;">'
		. '<a href="' . esc_url( lpc_service_locations_url() ) . '" style="color:#fff;text-decoration:underline;font-weight:600;">' . esc_html( $label ) . '</a></p>';
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
