<?php
/**
 * Fetches manifest/page data from the delivery server, with transient caching
 * and a permanent fallback so a slow/down delivery server never breaks the
 * client's live site.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function lpc_delivery_url( $args ) {
	$base = defined( 'LPC_DELIVERY_BASE' ) ? LPC_DELIVERY_BASE : '';
	return add_query_arg( $args, $base );
}

function lpc_fetch_json( $url ) {
	$response = wp_remote_get( $url, [ 'timeout' => 8 ] );
	if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
		return null;
	}
	$body = wp_remote_retrieve_body( $response );
	$data = json_decode( $body, true );
	return is_array( $data ) ? $data : null;
}

/**
 * Applies a freshly-fetched manifest's settings as the plugin's local config —
 * this is how refresh_interval/base_path/support fields change for the client
 * with zero action on their end, carried on the same check-in call that
 * fetches the page list.
 */
function lpc_apply_remote_settings( $manifest ) {
	$settings = $manifest['settings'] ?? [];
	$old_base_path = get_option( 'lpc_base_path', '/locations' );

	update_option( 'lpc_base_path', $settings['base_path'] ?? '/locations' );
	update_option( 'lpc_refresh_interval_minutes', (int) ( $settings['refresh_interval_minutes'] ?? 360 ) );
	update_option( 'lpc_support_phone', $settings['support_phone'] ?? '' );
	update_option( 'lpc_support_message', $settings['support_message'] ?? '' );
	update_option( 'lpc_nav_link_placement', $settings['nav_link_placement'] ?? 'footer' );
	update_option( 'lpc_nav_link_label', $settings['nav_link_label'] ?? 'View Our Service Locations' );
	update_option( 'lpc_last_checkin', time() );

	if ( $old_base_path !== get_option( 'lpc_base_path' ) ) {
		update_option( 'lpc_rewrite_flush_needed', 1 );
	}
}

function lpc_get_manifest( $force = false ) {
	$transient_key = 'lpc_manifest';

	if ( ! $force ) {
		$cached = get_transient( $transient_key );
		if ( $cached !== false ) {
			return $cached;
		}
	}

	$api_key = get_option( 'lpc_api_key', '' );
	if ( $api_key === '' ) {
		return get_option( 'lpc_manifest_permanent_cache', null );
	}

	$manifest = lpc_fetch_json( lpc_delivery_url( [ 'action' => 'list', 'key' => $api_key ] ) );

	if ( $manifest === null ) {
		// Delivery server unreachable — fall back to the last known-good copy
		// rather than breaking the client's live site.
		return get_option( 'lpc_manifest_permanent_cache', null );
	}

	$minutes = (int) ( $manifest['settings']['refresh_interval_minutes'] ?? 360 );
	set_transient( $transient_key, $manifest, max( 1, $minutes ) * MINUTE_IN_SECONDS );
	// autoload=false (3rd arg) — without it this defaults to autoloading on
	// WP versions that don't size-detect it, meaning the full manifest JSON
	// gets pulled into memory via wp_load_alloptions() on EVERY page load of
	// the client's entire site, not just /locations/* routes. Same real gap
	// in the per-page cache just below, which is worse: one of these options
	// per published slug, unbounded as a client's city×service count grows.
	update_option( 'lpc_manifest_permanent_cache', $manifest, false );
	lpc_apply_remote_settings( $manifest );

	return $manifest;
}

/**
 * "Refresh now" forcing just the manifest (lpc_get_manifest(true)) only
 * updates the hub listing — every individual page's own content stays on its
 * own transient/permanent cache until that page's own TTL expires (up to
 * refresh_interval_minutes, 6h by default), regardless of the manifest
 * refresh. Confirmed live: regenerating+republishing a page on our side left
 * its already-cached WordPress copy stuck on the old content. Clears every
 * cached page the manifest currently lists, so "Refresh now" actually means
 * "refresh everything" as the button claims.
 */
function lpc_purge_all_page_caches( $manifest ) {
	foreach ( (array) ( $manifest['pages'] ?? [] ) as $p ) {
		$slug = $p['slug'] ?? '';
		if ( $slug === '' ) continue;
		delete_transient( 'lpc_page_' . md5( $slug ) );
		delete_option( 'lpc_page_cache_' . md5( $slug ) );
	}
}

function lpc_get_page( $slug ) {
	$transient_key   = 'lpc_page_' . md5( $slug );
	$permanent_key   = 'lpc_page_cache_' . md5( $slug );

	$cached = get_transient( $transient_key );
	if ( $cached !== false ) {
		return $cached;
	}

	$api_key = get_option( 'lpc_api_key', '' );
	if ( $api_key === '' ) {
		return get_option( $permanent_key, null );
	}

	$page = lpc_fetch_json( lpc_delivery_url( [ 'action' => 'page', 'key' => $api_key, 'slug' => $slug ] ) );

	if ( $page === null ) {
		return get_option( $permanent_key, null );
	}

	$minutes = (int) get_option( 'lpc_refresh_interval_minutes', 360 );
	set_transient( $transient_key, $page, max( 1, $minutes ) * MINUTE_IN_SECONDS );
	update_option( $permanent_key, $page, false ); // autoload=false — see lpc_get_manifest()'s identical fix just above

	return $page;
}
