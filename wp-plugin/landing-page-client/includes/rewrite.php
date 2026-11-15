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

/**
 * Without this, {base_path}/sitemap.xml exists but nothing tells a crawler
 * it's there — an unlinked sitemap is nearly as invisible as no sitemap at
 * all. robots_txt is WP core's standard filter for WordPress's own virtual
 * robots.txt (the one it generates when no real robots.txt file exists on
 * disk); other SEO plugins append their own Sitemap: lines to this same
 * filter, so this is additive by convention, not a conflict the way
 * duplicate meta tags were. The one real gap: a site with an actual
 * physical robots.txt file on disk bypasses WP's virtual one — and this
 * filter — entirely, with no way for us to detect that from here.
 */
add_filter( 'robots_txt', 'lpc_add_sitemap_to_robots', 10, 1 );
function lpc_add_sitemap_to_robots( $output ) {
	$base = trim( get_option( 'lpc_base_path', '/locations' ), '/' );
	if ( $base === '' ) {
		return $output;
	}
	$sitemap_url = home_url( '/' . $base . '/sitemap.xml' );
	return rtrim( $output ) . "\n\nSitemap: " . $sitemap_url . "\n";
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
	if ( ! get_query_var( 'lpc_sitemap' ) && ! get_query_var( 'lpc_route' ) ) {
		return;
	}

	// Real incident, 2026-11-15: this content is dynamic (changes whenever a
	// page is regenerated/republished, or the API key changes), but nothing
	// told the HOST's own full-page cache that — Kinsta (and most hosts with
	// server-level/edge caching) treats an uncached-looking WP response like
	// any ordinary static page and caches the whole rendered HTML, on top of
	// this plugin's own WP-level transient cache. That left TWO independent
	// caches that could each serve stale content, and clearing them in the
	// wrong order (or only one of them) silently undid the fix. WP's own
	// nocache_headers() + the widely-recognized DONOTCACHEPAGE constant tell
	// virtually every host-level cache (Kinsta included, confirmed in their
	// docs) to skip caching this route entirely — collapsing this back down
	// to just the one cache we actually control (lpc_get_manifest()/
	// lpc_get_page()'s own transients, refreshed by "Save & Refresh now").
	if ( ! defined( 'DONOTCACHEPAGE' ) ) {
		define( 'DONOTCACHEPAGE', true );
	}
	nocache_headers();

	if ( get_query_var( 'lpc_sitemap' ) ) {
		lpc_output_sitemap();
		exit;
	}

	$slug = get_query_var( 'lpc_slug' );

	// All in One SEO (AIOSEO) — a third distinct behavior found testing it
	// (2026-11-15): unlike Yoast (field-level duplication, fixed via its own
	// per-field filters below) or RankMath (prints nothing extra at all on
	// this route), AIOSEO prints a FULL set of its own tags here —
	// canonical, meta description, og:title, og:description — all using
	// the site's generic tagline as a fallback since there's no real post
	// to derive page-specific values from, and exposes no clean per-field
	// override filter for most of them. Rather than chase down a separate
	// filter per field, aioseo_disable (checked by AIOSEO's own
	// Meta\Included::isIncluded() gate before any of wp_head's meta/social/
	// schema output runs) cleanly suppresses AIOSEO's output entirely for
	// this route, letting our own plain wp_head echo below (the same path
	// already used when no SEO plugin is active) take over uncontested.
	if ( defined( 'AIOSEO_VERSION' ) ) {
		add_filter( 'aioseo_disable', '__return_true' );
	}

	// Opportunistic refresh — a visit is as good a moment as any to check in,
	// on top of whatever external scheduled refresh exists.
	$manifest = lpc_get_manifest();
	$page     = $slug !== '' ? lpc_get_page( $slug ) : null;

	// The hub page ($slug === '') used to fall straight through every block
	// below with nothing to show — real gap found pre-deployment review,
	// 2026-11-15: it got no custom title, no meta description, no canonical
	// at all, worse on a Yoast-active site, which (per the canonical finding
	// further down) prints its own wrong home_url() canonical whenever we
	// don't override it — meaning the hub page, likely the most internally-
	// linked page this plugin creates, could tell Google its content lives
	// at the site root instead. $seo unifies both cases so every block below
	// only has to read one shape, hub or single page alike.
	if ( $slug === '' ) {
		$business = $manifest['business_name'] ?? '';
		$seo = [
			'page_title'        => $business !== '' ? $business . ' — Locations' : '',
			'meta_description'  => $business !== '' ? 'Explore ' . $business . '\'s service locations.' : '',
			'og_image'          => '',
		];
	} else {
		$seo = $page['seo'] ?? [];
	}

	// Must register BEFORE get_header() — wp_head() (called from inside
	// get_header()) is what actually echoes <title>, so a filter added any
	// later than this silently has no effect on the title tag.
	if ( ! empty( $seo['page_title'] ) ) {
		// Priority PHP_INT_MAX, not the default 10 — confirmed live against a
		// real client site running Yoast SEO: Yoast hooks pre_get_document_title
		// at priority 15 specifically so it wins over anything registered at the
		// default priority, which silently ate this title on the first real
		// non-bare-theme test (the 3 themes this was originally verified
		// against in Phase 1 all run with no SEO plugin active).
		add_filter(
			'pre_get_document_title',
			function () use ( $seo ) {
				return $seo['page_title'];
			},
			PHP_INT_MAX
		);
	}

	// Meta description + OG tags — these were stored in the page data from the
	// start but never actually output anywhere, a real silent gap (found
	// 2026-11-15). First attempt just echoed them in wp_head at PHP_INT_MAX —
	// reproduced locally against a real Yoast SEO install and found that's
	// NOT enough: wp_head is an action, not a filter, so Yoast's own (generic,
	// wrong-for-this-virtual-page) description/og:title tags still print too —
	// both end up in the markup, duplicated, and since "first tag wins" is the
	// de facto behavior crawlers use for duplicates, Yoast's generic text
	// would likely be what actually gets indexed, not ours. Fixed properly:
	// when Yoast is active, hook its own public filters to inject our values
	// so YOAST ITSELF outputs the correct tag, zero duplication — the
	// standard way a plugin is meant to integrate with Yoast, not race it.
	// Falls back to the plain wp_head approach (already proven correct, see
	// Phase 1 verification) when no SEO plugin is active to override instead.
	if ( ! empty( $seo['meta_description'] ) || ! empty( $seo['og_image'] ) ) {
		if ( defined( 'WPSEO_VERSION' ) ) {
			add_filter( 'wpseo_metadesc', function () use ( $seo ) {
				return $seo['meta_description'] ?: '';
			} );
			add_filter( 'wpseo_opengraph_desc', function () use ( $seo ) {
				return $seo['meta_description'] ?: '';
			} );
			add_filter( 'wpseo_opengraph_title', function () use ( $seo ) {
				return $seo['page_title'] ?: '';
			} );
			if ( ! empty( $seo['og_image'] ) ) {
				add_action( 'wpseo_add_opengraph_images', function ( $image ) use ( $seo ) {
					$image->add_image( $seo['og_image'] );
				} );
			}
		} else {
			add_action(
				'wp_head',
				function () use ( $seo ) {
					if ( ! empty( $seo['meta_description'] ) ) {
						echo '<meta name="description" content="' . esc_attr( $seo['meta_description'] ) . '">' . "\n";
						echo '<meta property="og:description" content="' . esc_attr( $seo['meta_description'] ) . '">' . "\n";
					}
					if ( ! empty( $seo['page_title'] ) ) {
						echo '<meta property="og:title" content="' . esc_attr( $seo['page_title'] ) . '">' . "\n";
					}
					if ( ! empty( $seo['og_image'] ) ) {
						echo '<meta property="og:image" content="' . esc_url( $seo['og_image'] ) . '">' . "\n";
					}
					echo '<meta property="og:type" content="website">' . "\n";
				},
				PHP_INT_MAX
			);
		}
	}

	// Canonical tag — a real gap found testing RankMath, then a real
	// duplication bug found testing Yoast (2026-11-15). WP core's own
	// rel_canonical() only fires for a real singular post/page object, and
	// this route is neither, so by default nothing prints one. The three
	// scenarios turned out to NOT behave the same, confirmed by direct test:
	//   - No SEO plugin: nothing prints a canonical — safe to echo our own.
	//   - RankMath: also prints nothing for this route (its canonical code
	//     path never runs here) — safe to echo our own directly too.
	//   - Yoast: DOES still run its own canonical logic here, same as it
	//     does for meta description/og tags — but with no real post to
	//     anchor to, it falls back to home_url() and prints THAT, which is
	//     wrong for this page. A first unconditional-echo attempt produced
	//     TWO canonical tags (Yoast's wrong one first, ours second) — a real
	//     duplicate, and since crawlers generally take the first tag as
	//     authoritative, Yoast's incorrect one would likely have won.
	// Fix: hook Yoast's own wpseo_canonical filter (confirmed to actually
	// fire on this route, unlike RankMath's filter) so Yoast itself prints
	// the correct value with zero duplication; echo directly everywhere else.
	// Now also covers the hub page ($slug === ''): it used to be skipped
	// entirely here, which on a Yoast-active site left Yoast's own wrong
	// home_url() canonical (see comment block above) as the only one
	// printed on what's likely the most internally-linked page this
	// plugin creates. Skipped only for a genuine 404 ($slug set but no
	// page found) — nothing to canonicalize a non-existent URL to.
	if ( $slug === '' || ! empty( $page ) ) {
		$canonical_url = home_url( trim( get_option( 'lpc_base_path', '/locations' ), '/' ) . ( $slug !== '' ? '/' . $slug : '' ) . '/' );
		if ( defined( 'WPSEO_VERSION' ) ) {
			add_filter( 'wpseo_canonical', function () use ( $canonical_url ) {
				return $canonical_url;
			} );
		} else {
			add_action(
				'wp_head',
				function () use ( $canonical_url ) {
					echo '<link rel="canonical" href="' . esc_url( $canonical_url ) . '">' . "\n";
				},
				PHP_INT_MAX
			);
		}
	}

	// A stale/unpublished/mistyped slug must 404, not 200 — without this, a
	// removed or never-existed /locations/{slug}/ stayed indexable as a
	// 200 "Page not found" page (real finding, pre-deployment review,
	// 2026-11-15): Google's own soft-404 detection flags exactly this
	// pattern, and it reads as thin near-duplicate content across every
	// such URL on the same site.
	if ( $slug !== '' && empty( $page ) ) {
		global $wp_query;
		$wp_query->set_404();
		status_header( 404 );
	} else {
		status_header( 200 );
	}

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
	// Called directly here (not via wp_footer — see the comment on
	// lpc_render_footer_link() in footer_link.php for why), and BEFORE
	// .lpc-page's closing </div>, not after it. Real bug, found live
	// (2026-11-15): themes commonly wrap their main content area in a flex
	// or grid container (confirmed: Astra's .ast-container). Echoing the
	// link as a sibling AFTER .lpc-page closed made it a second flex/grid
	// item next to the page content instead of a line below it — it
	// rendered as a full-height vertical strip down the right side of the
	// page, not a footer bar, which is why it never looked like "a footer
	// link" no matter how many times this got checked. Keeping it INSIDE
	// .lpc-page (which has no flex/grid of its own) keeps it in normal
	// block flow regardless of what the surrounding theme container does.
	lpc_render_footer_link();
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
	// Without this the page is just a heading and a bare list — fine with a
	// full roster of cities, but a real, confirmed-bad look with only 1-2
	// published pages (restore-2's exact situation, 2026-11-15): a single
	// stretched-full-width card and a wall of empty space below it, reading
	// as broken rather than just "early."
	echo '<p class="lpc-hub-intro">' . esc_html( $manifest['business_name'] ?? 'We' ) . ' proudly serves the following service area' . ( count( $manifest['pages'] ) === 1 ? '' : 's' ) . ':</p>';
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
