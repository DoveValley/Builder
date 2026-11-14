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
	update_option( 'lpc_manifest_permanent_cache', $manifest );
	lpc_apply_remote_settings( $manifest );

	return $manifest;
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
	update_option( $permanent_key, $page );

	return $page;
}
