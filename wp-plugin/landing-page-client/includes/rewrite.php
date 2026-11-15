<?php
/**
 * Routes two virtual URLs to our own renderer, wrapped in the client's real
 * theme chrome: {base_path} (hub list) and {base_path}/{slug} (single page),
 * plus {base_path}/sitemap.xml so the pages are discoverable even though
 * they're not real WP posts in the client's own Yoast/RankMath sitemap.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'init', 'lpc_add_rewrite_rules' );
function lpc_add_rewrite_rules() {
	$base = trim( get_option( 'lpc_base_path', '/locations' ), '/' );
	if ( $base === '' ) {
		return;
	}
	add_rewrite_rule( '^' . $base . '/sitemap\.xml$', 'index.php?lpc_sitemap=1', 'top' );
	add_rewrite_rule( '^' . $base . '/([^/]+)/?$', 'index.php?lpc_route=1&lpc_slug=$matches[1]', 'top' );
	add_rewrite_rule( '^' . $base . '/?$', 'index.php?lpc_route=1&lpc_slug=', 'top' );

	if ( get_option( 'lpc_rewrite_flush_needed' ) ) {
		flush_rewrite_rules();
		delete_option( 'lpc_rewrite_flush_needed' );
	}
}

add_filter( 'query_vars', 'lpc_query_vars' );
function lpc_query_vars( $vars ) {
	$vars[] = 'lpc_route';
	$vars[] = 'lpc_slug';
	$vars[] = 'lpc_sitemap';
	return $vars;
}

add_action( 'template_redirect', 'lpc_handle_route' );
function lpc_handle_route() {
	if ( get_query_var( 'lpc_sitemap' ) ) {
		lpc_output_sitemap();
		exit;
	}

	if ( ! get_query_var( 'lpc_route' ) ) {
		return;
	}

	$slug = get_query_var( 'lpc_slug' );

	// Opportunistic refresh — a visit is as good a moment as any to check in,
	// on top of whatever external scheduled refresh exists.
	$manifest = lpc_get_manifest();
	$page     = $slug !== '' ? lpc_get_page( $slug ) : null;

	// Must register BEFORE get_header() — wp_head() (called from inside
	// get_header()) is what actually echoes <title>, so a filter added any
	// later than this silently has no effect on the title tag.
	if ( ! empty( $page['seo']['page_title'] ) ) {
		// Priority PHP_INT_MAX, not the default 10 — confirmed live against a
		// real client site running Yoast SEO: Yoast hooks pre_get_document_title
		// at priority 15 specifically so it wins over anything registered at the
		// default priority, which silently ate this title on the first real
		// non-bare-theme test (the 3 themes this was originally verified
		// against in Phase 1 all run with no SEO plugin active).
		add_filter(
			'pre_get_document_title',
			function () use ( $page ) {
				return $page['seo']['page_title'];
			},
			PHP_INT_MAX
		);
	}

	status_header( 200 );

	// Buffer the theme's own header instead of echoing it directly — some
	// themes (confirmed: Astra) only emit an H1 for the site title on the
	// front page, not here, which would leave this page with zero H1 tags.
	// Detect that and promote our own top heading to H1 when it happens,
	// rather than assuming every theme behaves like the 3 default WP themes
	// this was originally tested against.
	ob_start();
	get_header();
	$header_html = ob_get_clean();
	echo $header_html; // phpcs:ignore WordPress.Security.EscapeOutput -- theme's own header output, not ours to escape.
	$top_level = ( stripos( $header_html, '<h1' ) === false ) ? 'h1' : 'h2';

	echo lpc_render_styles( $manifest['branding'] ?? [] ); // phpcs:ignore WordPress.Security.EscapeOutput -- styles.php escapes every value itself.
	echo '<div class="lpc-page">';
	if ( $slug === '' ) {
		lpc_render_hub_page( $manifest, $top_level );
	} else {
		lpc_render_single_page( $page, $top_level );
	}
	echo '</div>';

	get_footer();
	exit;
}

function lpc_render_hub_page( $manifest, $top_level = 'h2' ) {
	$top_level = in_array( $top_level, [ 'h1', 'h2' ], true ) ? $top_level : 'h2';

	if ( empty( $manifest['pages'] ) ) {
		echo '<main><' . $top_level . '>' . esc_html( $manifest['business_name'] ?? '' ) . '</' . $top_level . '><p>No locations are published yet.</p></main>';
		return;
	}

	$base = trim( get_option( 'lpc_base_path', '/locations' ), '/' );
	echo '<main>';
	echo '<' . $top_level . '>' . esc_html( $manifest['business_name'] ?? 'Locations' ) . '</' . $top_level . '>';
	echo '<ul class="lpc-hub-list">';
	foreach ( $manifest['pages'] as $page ) {
		$url = home_url( '/' . $base . '/' . rawurlencode( $page['slug'] ) );
		echo '<li class="lpc-hub-item"><a href="' . esc_url( $url ) . '">' . esc_html( $page['title'] ?? $page['slug'] ) . '</a></li>';
	}
	echo '</ul>';
	echo '</main>';
}

function lpc_render_single_page( $page, $top_level = 'h2' ) {
	if ( empty( $page ) ) {
		echo '<main><h2>Page not found</h2></main>';
		return;
	}

	echo '<main>';
	echo lpc_render_sections( $page['sections'] ?? [], $top_level ); // phpcs:ignore WordPress.Security.EscapeOutput -- section renderer escapes each field itself.
	echo '</main>';
	echo lpc_render_schema( $page['schema_jsonld'] ?? null ); // phpcs:ignore WordPress.Security.EscapeOutput -- JSON-LD, not HTML.
}

function lpc_output_sitemap() {
	$manifest = lpc_get_manifest();
	$base     = trim( get_option( 'lpc_base_path', '/locations' ), '/' );

	header( 'Content-Type: application/xml; charset=UTF-8' );
	echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
	echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
	foreach ( (array) ( $manifest['pages'] ?? [] ) as $page ) {
		$url = home_url( '/' . $base . '/' . rawurlencode( $page['slug'] ) );
		echo '<url><loc>' . esc_url( $url ) . '</loc></url>' . "\n";
	}
	echo '</urlset>';
}
