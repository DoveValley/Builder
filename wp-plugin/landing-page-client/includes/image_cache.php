<?php
/**
 * Caches a delivery-server image locally, once, so it serves from the
 * client's own HTTPS site instead of the delivery server's plain-HTTP URL.
 *
 * WHY THIS EXISTS. The delivery server (LPC_DELIVERY_BASE) has no SSL
 * certificate of its own (real gap, deliberately deferred — a real custom
 * domain/SSL for it isn't set up yet). Modern Chrome auto-upgrades an
 * http:// <img src> embedded on an https:// page to https:// silently, and
 * when that upgraded request fails (the delivery server has nothing
 * listening on 443), Chrome does NOT fall back to the original http://
 * version — the image simply never loads, with no console error a typical
 * admin would notice. Confirmed live, 2026-11-15: all three of a real
 * page's images (hero/area_map/data_chart) failed this way, even though
 * every one of them is a real, valid, HTTP-200 image when fetched directly
 * (server-to-server fetches, like this file's own wp_remote_get() call,
 * aren't subject to a browser's mixed-content policy at all — that's a
 * browser-only security rule, which is exactly why caching the file
 * server-side sidesteps the problem completely rather than working around
 * browser behavior).
 *
 * Keyed on the FULL remote URL (not just the filename) specifically because
 * area_map/data_chart images already carry a real ?v=<mtime> cache-buster of
 * their own (2026-11-15's cache-busting fix) — hashing the full URL means a
 * changed v= produces a new local filename automatically, so this cache can
 * never serve a stale image once the source changes.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Returns a same-origin HTTPS URL for $remote_url, downloading it into this
 * site's own uploads/lpc-cache/ directory on first use. Returns the
 * ORIGINAL $remote_url unchanged if anything about the download fails —
 * same graceful-degrade behavior as today (might not display due to mixed
 * content), never a broken/missing return value.
 */
function lpc_cache_image_locally( $remote_url ) {
	if ( empty( $remote_url ) || ! is_string( $remote_url ) ) {
		return $remote_url;
	}
	// Already a same-origin (or any https) URL — nothing to do. Guards
	// against double-processing and against ever trying to "cache" a URL
	// that isn't our own delivery server's http:// link in the first place.
	if ( strpos( $remote_url, 'http://' ) !== 0 ) {
		return $remote_url;
	}

	// The real filename is the `file=` QUERY parameter, not the URL path —
	// every image this plugin ever requests goes through
	// api.php?action=image&client=...&file=foo.webp, so the path is always
	// just "/api.php". Real bug, caught testing this live: pathinfo() on the
	// PATH returned "php" (from "/api.php" itself), and a permissive
	// alphanumeric-extension regex accepted it — about to write a file named
	// lpc-<hash>.php into a web-accessible uploads directory. Whitelisted to
	// the exact same safe image extensions api.php's own filename regex
	// already allows, not just "looks like an extension".
	parse_str( (string) parse_url( $remote_url, PHP_URL_QUERY ), $query_args );
	$source_file = (string) ( $query_args['file'] ?? '' );
	$ext = strtolower( pathinfo( $source_file, PATHINFO_EXTENSION ) );
	if ( ! in_array( $ext, [ 'webp', 'jpg', 'jpeg', 'png', 'gif' ], true ) ) {
		return $remote_url;
	}
	$filename = 'lpc-' . md5( $remote_url ) . '.' . $ext;

	$upload_dir = wp_upload_dir();
	if ( ! empty( $upload_dir['error'] ) ) {
		return $remote_url;
	}
	$cache_dir  = trailingslashit( $upload_dir['basedir'] ) . 'lpc-cache/';
	$cache_url  = trailingslashit( $upload_dir['baseurl'] ) . 'lpc-cache/' . $filename;
	$cache_path = $cache_dir . $filename;

	// Already cached under this exact (URL-derived) filename — the v=
	// cache-buster means a changed source always produces a different
	// filename, so "file exists" alone is a safe freshness check here.
	if ( file_exists( $cache_path ) ) {
		return $cache_url;
	}

	if ( ! wp_mkdir_p( $cache_dir ) ) {
		return $remote_url;
	}

	// Server-to-server fetch — not subject to the browser's mixed-content
	// policy that makes this caching necessary in the first place.
	$response = wp_remote_get( $remote_url, [ 'timeout' => 15 ] );
	if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
		return $remote_url;
	}
	$body = wp_remote_retrieve_body( $response );
	if ( empty( $body ) ) {
		return $remote_url;
	}
	if ( file_put_contents( $cache_path, $body ) === false ) {
		return $remote_url;
	}

	return $cache_url;
}
