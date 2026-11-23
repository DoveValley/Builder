<?php
/**
 * Renders the 14 locked section types. Originally zero CSS/classes of our own
 * so the page inherited the client's real theme with no risk of clashing —
 * that produced correct but visually bare pages (literally just <h1>/<p>/
 * <ul><li>, no card layout, no styled buttons). Now emits a fixed set of
 * `lpc-*` classes styled by styles.php, which pulls its colors from the
 * client's own captured brand colors (manifest 'branding') rather than
 * hardcoding a look — see styles.php for why that's the chosen middle ground
 * over either bare HTML or guessing at the theme's own CSS variable names
 * (which aren't consistent across theme builders).
 *
 * Heading level is NOT hardcoded, on purpose. Original assumption (found
 * against Twenty Twenty-Five, confirmed against Twenty Twenty-Four/Twenty
 * Twenty-Three too) was "the theme always renders its own site title as H1,
 * so our content should never claim it" — true for all 3 default WP themes
 * tested. Testing against Astra (a real, widely-used theme, 1M+ installs)
 * broke that assumption: Astra only outputs an H1 for the site title on the
 * front page, not on inner pages like these — so a client on Astra would
 * get a page with ZERO H1 tags, a real SEO regression, not a hypothetical
 * one. Fix: detect at render time (lpc_handle_route in rewrite.php) whether
 * get_header() already emitted an H1; if not, the hero headline IS this
 * page's H1. Every other heading stays fixed (H2 sections, H3 FAQ items)
 * regardless, since hierarchy only needs to shift at the very top.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function lpc_render_sections( $sections, $hero_level = 'h2', $faq_style = 'list' ) {
	$html = '';
	foreach ( (array) $sections as $section ) {
		$type = $section['type'] ?? '';
		switch ( $type ) {
			case 'hero':
				$html .= lpc_render_hero( $section, $hero_level );
				break;
			case 'trust_bar':
				$html .= lpc_render_trust_bar( $section );
				break;
			case 'comparison':
				$html .= lpc_render_comparison( $section );
				break;
			case 'text':
				$html .= lpc_render_text( $section );
				break;
			case 'services_list':
			case 'highlights':
				$html .= lpc_render_services_list( $section );
				break;
			case 'steps':
				$html .= lpc_render_steps( $section );
				break;
			case 'faq':
				$html .= lpc_render_faq( $section, $faq_style );
				break;
			case 'safety_toolkit':
				$html .= lpc_render_safety_toolkit( $section );
				break;
			case 'cta':
				$html .= lpc_render_cta( $section );
				break;
			case 'related_links':
				$html .= lpc_render_related_links( $section );
				break;
			case 'service_area':
				$html .= lpc_render_service_area( $section );
				break;
			case 'area_map':
				$html .= lpc_render_area_map( $section );
				break;
			case 'data_chart':
				$html .= lpc_render_data_chart( $section );
				break;
		}
	}
	return $html;
}

/** Side-by-side (text left, real photo right on desktop; stacks on mobile via
 *  styles.php's media query) — matches the reference competitor page's hero
 *  layout (2026-11-15), replacing the original centered/stacked version.
 *  Falls back to a single centered column with no visual gap when there's no
 *  image at all, rather than an empty column sitting next to the text. */
function lpc_render_hero( $s, $level = 'h2' ) {
	$level = in_array( $level, [ 'h1', 'h2' ], true ) ? $level : 'h2';

	$text  = '<' . $level . ' class="lpc-hero-headline">' . esc_html( $s['headline'] ?? '' ) . '</' . $level . '>';
	if ( ! empty( $s['subheading'] ) ) {
		$text .= '<p class="lpc-hero-sub">' . esc_html( $s['subheading'] ) . '</p>';
	}
	if ( ! empty( $s['phone'] ) ) {
		$tel = preg_replace( '/[^0-9+]/', '', $s['phone'] );
		$text .= '<p><a class="lpc-btn lpc-btn-primary" data-lpc-track="tel" href="tel:' . esc_attr( $tel ) . '">' . esc_html( $s['cta_text'] ?? $s['phone'] ) . '</a></p>';
	}

	$image = '';
	if ( ! empty( $s['image'] ) ) {
		// Explicit width/height — a real Lighthouse finding on the first live
		// page audit (2026-11-15): without them, the browser can't reserve
		// layout space before the image loads. Omitted entirely if either
		// dimension is unknown rather than guessing/stretching.
		$dims = '';
		if ( ! empty( $s['image_width'] ) && ! empty( $s['image_height'] ) ) {
			$dims = ' width="' . (int) $s['image_width'] . '" height="' . (int) $s['image_height'] . '"';
		}
		// NOT lazy-loaded, unlike every other image this plugin renders — the
		// hero is section #1 on every page (lpc_default_page_data()), almost
		// always the LCP candidate, and Google explicitly advises against
		// lazy-loading the LCP element since it delays the browser's decision
		// to fetch it. fetchpriority="high" gives the same signal the other
		// direction. Real finding, pre-deployment review, 2026-11-15.
		// lpc_cache_image_locally() — the delivery server has no SSL of its
		// own, and a browser-side mixed-content failure (not a broken
		// image, a silently-never-loaded one) is a real confirmed bug
		// found live, 2026-11-15. See that function's own doc for why.
		$image = '<figure class="lpc-hero-image"><img src="' . esc_url( lpc_cache_image_locally( $s['image'] ) ) . '" alt="' . esc_attr( $s['image_alt'] ?? '' ) . '"' . $dims . ' fetchpriority="high"></figure>';
	}

	$out  = '<section class="lpc-hero' . ( $image === '' ? ' lpc-hero-no-image' : '' ) . '">';
	$out .= '<div class="lpc-hero-text">' . $text . '</div>';
	$out .= $image;
	$out .= '</section>';
	return $out;
}

/** A dark checkmark bar right under the hero — static per-niche trust phrases
 *  (e.g. "24/7 Response Line"), never per-page content. Nothing to render if
 *  there are no items at all (shouldn't normally happen — publish-time
 *  filling always falls back to a generic default — but a page that's never
 *  been published since this section existed has an empty array, same "no
 *  data means nothing renders" rule as every other auto-computed section). */
function lpc_render_trust_bar( $s ) {
	if ( empty( $s['items'] ) ) {
		return '';
	}
	$out = '<div class="lpc-trust-bar"><ul>';
	foreach ( (array) $s['items'] as $item ) {
		$out .= '<li>&#10003; ' . esc_html( $item ) . '</li>';
	}
	$out .= '</ul></div>';
	return $out;
}

/** Two-column educational "this vs that" block (e.g. "what survives a flood
 *  vs what doesn't") — what the two columns represent is niche-specific
 *  (AI-authored, like the other text/list slots). Checkmark/cross bullet
 *  styling comes from CSS alone (nth-of-type), not per-item markup, so the
 *  AI's plain item strings never need their own icon field. */
function lpc_render_comparison( $s ) {
	if ( empty( $s['items_a'] ) && empty( $s['items_b'] ) ) {
		return '';
	}
	$out  = '<section class="lpc-section">';
	if ( ! empty( $s['heading'] ) ) {
		$out .= '<h2>' . esc_html( $s['heading'] ) . '</h2>';
	}
	if ( ! empty( $s['intro'] ) ) {
		$out .= '<p>' . esc_html( $s['intro'] ) . '</p>';
	}
	$out .= '<div class="lpc-comparison">';
	foreach ( [ 'a', 'b' ] as $col ) {
		$label = $s[ 'label_' . $col ] ?? '';
		$items = (array) ( $s[ 'items_' . $col ] ?? [] );
		if ( empty( $items ) ) {
			continue;
		}
		$out .= '<div class="lpc-comparison-col lpc-comparison-' . $col . '">';
		if ( $label ) {
			$out .= '<h3>' . esc_html( $label ) . '</h3>';
		}
		$out .= '<ul>';
		foreach ( $items as $item ) {
			$out .= '<li>' . esc_html( $item ) . '</li>';
		}
		$out .= '</ul></div>';
	}
	$out .= '</div></section>';
	return $out;
}

function lpc_render_text( $s ) {
	$out  = '<section class="lpc-section">';
	if ( ! empty( $s['heading'] ) ) {
		$out .= '<h2>' . esc_html( $s['heading'] ) . '</h2>';
	}
	if ( ! empty( $s['body'] ) ) {
		// nl2br() added 2026-11-22 — City Service Page picked-topic bodies
		// can carry real embedded line breaks (e.g. housing_stock's
		// stat-per-line listing); escape first, then convert, so those
		// render as actual line breaks instead of collapsing into one
		// run-on paragraph.
		$out .= '<p>' . nl2br( esc_html( $s['body'] ) ) . '</p>';
	}
	$out .= '</section>';
	return $out;
}

function lpc_render_services_list( $s ) {
	$out  = '<section class="lpc-section">';
	if ( ! empty( $s['heading'] ) ) {
		$out .= '<h2>' . esc_html( $s['heading'] ) . '</h2>';
	}
	$out .= '<ul class="lpc-card-grid">';
	foreach ( (array) ( $s['items'] ?? [] ) as $item ) {
		$out .= '<li class="lpc-card"><strong>' . esc_html( $item['title'] ?? '' ) . '</strong>';
		if ( ! empty( $item['text'] ) ) {
			$out .= '<span>' . esc_html( $item['text'] ) . '</span>';
		}
		$out .= '</li>';
	}
	$out .= '</ul></section>';
	return $out;
}

/** Same card-grid visual as services_list/highlights, just an <ol> instead of
 *  <ul> — this is a real sequence (the service process), not an unordered
 *  set, so the semantic distinction is worth keeping even though the CSS
 *  (display:grid) doesn't care which list element it's applied to. */
function lpc_render_steps( $s ) {
	$out  = '<section class="lpc-section">';
	if ( ! empty( $s['heading'] ) ) {
		$out .= '<h2>' . esc_html( $s['heading'] ) . '</h2>';
	}
	// Own class, not the generic lpc-card-grid/lpc-card every other card
	// section shares -- a sequence of steps reads better with a numbered
	// badge + connecting line (competitive finding, 2026-11-23) than as a
	// set of otherwise-identical feature cards, which is all the shared
	// class could express. Pure CSS counter, independent of whatever text
	// is in $item['title'] -- works whether or not the AI-written title
	// happens to also say "Step 1:", no writer/content change needed.
	$out .= '<ol class="lpc-steps-grid">';
	foreach ( (array) ( $s['items'] ?? [] ) as $item ) {
		$out .= '<li class="lpc-step-card"><strong>' . esc_html( $item['title'] ?? '' ) . '</strong>';
		if ( ! empty( $item['text'] ) ) {
			$out .= '<span>' . esc_html( $item['text'] ) . '</span>';
		}
		$out .= '</li>';
	}
	$out .= '</ol></section>';
	return $out;
}

/**
 * $style: 'list' (default — plain h3/p pairs, original behavior) | 'accordion'
 * (native <details>/<summary>, Phase 3, 2026-11-22 — not custom JS: content
 * stays in the DOM regardless of open/closed state, so there's no
 * crawlability risk, and it works with zero JavaScript). Same outer
 * .lpc-faq-list/.lpc-faq-item classes either way, so styles.php's existing
 * FAQ rules apply to both variants — only `<summary>` needs its own small
 * addition there.
 */
function lpc_render_faq( $s, $style = 'list' ) {
	$out  = '<section class="lpc-section">';
	if ( ! empty( $s['heading'] ) ) {
		$out .= '<h2>' . esc_html( $s['heading'] ) . '</h2>';
	}
	$out .= '<div class="lpc-faq-list">';
	foreach ( (array) ( $s['items'] ?? [] ) as $item ) {
		if ( 'accordion' === $style ) {
			$out .= '<details class="lpc-faq-item">';
			$out .= '<summary>' . esc_html( $item['q'] ?? '' ) . '</summary>';
			$out .= '<p>' . esc_html( $item['a'] ?? '' ) . '</p>';
			$out .= '</details>';
		} else {
			$out .= '<div class="lpc-faq-item">';
			$out .= '<h3>' . esc_html( $item['q'] ?? '' ) . '</h3>';
			$out .= '<p>' . esc_html( $item['a'] ?? '' ) . '</p>';
			$out .= '</div>';
		}
	}
	$out .= '</div></section>';
	return $out;
}

/** "Verify your provider" trust cards — real national organizations (NADCA/
 *  BBB/RIA-style), reused from plugins/safety_toolkit's own per-niche data.
 *  Icons are raw inline SVG, already sanitized server-side at publish time
 *  (sanitize_svg(), this codebase's standard rule for stored SVG markup) —
 *  echoed directly here, not esc_html()'d, which would just show literal
 *  angle-bracket text instead of rendering the icon. */
function lpc_render_safety_toolkit( $s ) {
	if ( empty( $s['items'] ) ) {
		return '';
	}
	$out  = '<section class="lpc-section">';
	if ( ! empty( $s['heading'] ) ) {
		$out .= '<h2>' . esc_html( $s['heading'] ) . '</h2>';
	}
	if ( ! empty( $s['intro'] ) ) {
		$out .= '<p>' . esc_html( $s['intro'] ) . '</p>';
	}
	$out .= '<ul class="lpc-card-grid">';
	foreach ( (array) $s['items'] as $item ) {
		$out .= '<li class="lpc-card lpc-safety-card">';
		if ( ! empty( $item['icon'] ) ) {
			$out .= '<div class="lpc-safety-icon">' . $item['icon'] . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- sanitized server-side before delivery, see function doc.
		}
		$out .= '<strong>' . esc_html( $item['heading'] ?? '' ) . '</strong>';
		if ( ! empty( $item['text'] ) ) {
			$out .= '<span>' . esc_html( $item['text'] ) . '</span>';
		}
		if ( ! empty( $item['link'] ) ) {
			$out .= '<a class="lpc-safety-link" href="' . esc_url( $item['link'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $item['btn_text'] ?? 'Learn More' ) . '</a>';
		}
		$out .= '</li>';
	}
	$out .= '</ul></section>';
	return $out;
}

function lpc_render_cta( $s ) {
	$out  = '<section class="lpc-section lpc-cta">';
	if ( ! empty( $s['heading'] ) ) {
		$out .= '<h2>' . esc_html( $s['heading'] ) . '</h2>';
	}
	if ( ! empty( $s['body'] ) ) {
		$out .= '<p>' . esc_html( $s['body'] ) . '</p>';
	}
	if ( ! empty( $s['phone'] ) ) {
		$tel = preg_replace( '/[^0-9+]/', '', $s['phone'] );
		$out .= '<p><a class="lpc-btn lpc-btn-accent" data-lpc-track="tel" href="tel:' . esc_attr( $tel ) . '">' . esc_html( $s['button_text'] ?? $s['phone'] ) . '</a></p>';
	}
	$out .= '</section>';
	return $out;
}

/** A real map image (plugins/image-area-map's own engine, reused server-side
 *  at publish time — see includes/landing_page_clients_area_images.php) —
 *  nothing to render if there was no real data to draw the map from. */
function lpc_render_area_map( $s ) {
	if ( empty( $s['image'] ) ) {
		return '';
	}
	$dims = '';
	if ( ! empty( $s['image_width'] ) && ! empty( $s['image_height'] ) ) {
		$dims = ' width="' . (int) $s['image_width'] . '" height="' . (int) $s['image_height'] . '"';
	}
	$out  = '<section class="lpc-section">';
	if ( ! empty( $s['heading'] ) ) {
		$out .= '<h2>' . esc_html( $s['heading'] ) . '</h2>';
	}
	$out .= '<figure class="lpc-area-map"><img src="' . esc_url( lpc_cache_image_locally( $s['image'] ) ) . '" alt="' . esc_attr( $s['image_alt'] ?? '' ) . '"' . $dims . ' loading="lazy"></figure>';
	$out .= '</section>';
	return $out;
}

/** Real climate chart (NOAA data), reused from plugins/image-data-chart's own
 *  engine — see includes/landing_page_clients_area_charts.php in the admin
 *  backend. table_html is pre-built HTML from that same trusted server-side
 *  code (city_chart_table_html()), already escaping every value itself —
 *  echoed directly here, not esc_html()'d, which would just show literal
 *  markup instead of a real table. Nothing to render until an admin has
 *  explicitly run "Fetch Climate Data" for this city at least once. */
function lpc_render_data_chart( $s ) {
	if ( empty( $s['image'] ) ) {
		return '';
	}
	$dims = '';
	if ( ! empty( $s['image_width'] ) && ! empty( $s['image_height'] ) ) {
		$dims = ' width="' . (int) $s['image_width'] . '" height="' . (int) $s['image_height'] . '"';
	}
	$out  = '<section class="lpc-section">';
	if ( ! empty( $s['heading'] ) ) {
		$out .= '<h2>' . esc_html( $s['heading'] ) . '</h2>';
	}
	$out .= '<figure class="lpc-data-chart"><img src="' . esc_url( lpc_cache_image_locally( $s['image'] ) ) . '" alt="' . esc_attr( $s['image_alt'] ?? '' ) . '"' . $dims . ' loading="lazy"></figure>';
	if ( ! empty( $s['table_html'] ) ) {
		$out .= $s['table_html']; // phpcs:ignore WordPress.Security.EscapeOutput -- pre-escaped server-side, see function doc.
	}
	$out .= '</section>';
	return $out;
}

/** Plain facts — state, office city, nearby cities. Nearby cities now link to
 *  the same service's page in that city when one is actually published
 *  (added 2026-11-15, was deliberately plain text at first — see
 *  lpc_compute_service_area() in the admin backend for the "never link to a
 *  page that doesn't exist" safety rule) and fall back to plain text
 *  otherwise, same "real info even before every page exists" reasoning. */
function lpc_render_service_area( $s ) {
	if ( empty( $s['state'] ) && empty( $s['office_city'] ) && empty( $s['nearby_cities'] ) ) {
		return '';
	}
	$out  = '<section class="lpc-section">';
	if ( ! empty( $s['heading'] ) ) {
		$out .= '<h2>' . esc_html( $s['heading'] ) . '</h2>';
	}
	if ( ! empty( $s['intro'] ) ) {
		$out .= '<p>' . esc_html( $s['intro'] ) . '</p>';
	}
	$out .= '<dl class="lpc-fact-list">';
	if ( ! empty( $s['state'] ) ) {
		$out .= '<div><dt>State</dt><dd>' . esc_html( $s['state'] ) . '</dd></div>';
	}
	if ( ! empty( $s['office_city'] ) ) {
		$out .= '<div><dt>Office City</dt><dd>' . esc_html( $s['office_city'] ) . '</dd></div>';
	}
	if ( ! empty( $s['nearby_cities'] ) ) {
		// Backward-compat for pages published before 2026-11-15's real-linking
		// change: nearby_cities used to be a plain array of strings
		// ('Frisco, TX'), not {label,url} pairs. A real already-published
		// page hit this live — $nc['label'] on a plain string silently
		// evaluates to '' (PHP's ?? suppresses the illegal-string-offset
		// warning entirely), rendering a blank "Nearby Cities" row instead of
		// falling back to the plain city name. Self-heals without requiring
		// every already-published page to be manually republished first.
		$parts = array_map(
			function ( $nc ) {
				if ( ! is_array( $nc ) ) {
					return esc_html( (string) $nc );
				}
				$label = esc_html( $nc['label'] ?? '' );
				return ! empty( $nc['url'] ) ? '<a href="' . esc_url( $nc['url'] ) . '">' . $label . '</a>' : $label;
			},
			(array) $s['nearby_cities']
		);
		$out .= '<div><dt>Nearby Cities</dt><dd>' . implode( ', ', $parts ) . '</dd></div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- each part already escaped above.
	}
	$out .= '</dl></section>';
	return $out;
}

function lpc_render_related_links( $s ) {
	$links = (array) ( $s['links'] ?? [] );
	if ( empty( $links ) ) {
		return '';
	}
	$out  = '<section class="lpc-section">';
	if ( ! empty( $s['heading'] ) ) {
		$out .= '<h2>' . esc_html( $s['heading'] ) . '</h2>';
	}
	$out .= '<ul class="lpc-pill-list">';
	foreach ( $links as $link ) {
		$out .= '<li><a class="lpc-pill" href="' . esc_url( $link['url'] ?? '#' ) . '">' . esc_html( $link['label'] ?? '' ) . '</a></li>';
	}
	$out .= '</ul></section>';
	return $out;
}

/** Fixed-position mobile call bar, shown only under styles.php's 760px
 *  breakpoint (desktop gets the hero/CTA buttons already on the page, which
 *  don't need a persistent duplicate). Reads the phone straight off the
 *  page's own hero section rather than adding a new manifest/client field —
 *  every page already carries its own correctly-resolved phone number there,
 *  no new plumbing needed. Returns '' (renders nothing) if the hero has no
 *  phone, same "no data means nothing renders" rule every other
 *  auto-computed section already follows. */
function lpc_render_sticky_call_bar( $sections ) {
	$phone = '';
	foreach ( (array) $sections as $s ) {
		if ( ( $s['type'] ?? '' ) === 'hero' && ! empty( $s['phone'] ) ) {
			$phone = $s['phone'];
			break;
		}
	}
	if ( $phone === '' ) {
		return '';
	}
	$tel = preg_replace( '/[^0-9+]/', '', $phone );
	$out  = '<div class="lpc-sticky-call">';
	$out .= '<a href="tel:' . esc_attr( $tel ) . '" data-lpc-track="tel">';
	$out .= '<svg aria-hidden="true" viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M6.6 10.8c1.4 2.8 3.8 5.1 6.6 6.6l2.2-2.2c.3-.3.7-.4 1-.2 1.1.4 2.3.6 3.6.6.6 0 1 .4 1 1V20c0 .6-.4 1-1 1C10.4 21 3 13.6 3 4.6c0-.6.4-1 1-1H7.6c.6 0 1 .4 1 1 0 1.3.2 2.5.6 3.6.1.3 0 .7-.2 1z"/></svg>';
	$out .= '<span>Call Now — ' . esc_html( $phone ) . '</span>';
	$out .= '</a></div>';
	return $out;
}

function lpc_render_schema( $schema_jsonld ) {
	if ( empty( $schema_jsonld ) ) {
		return '';
	}
	return '<script type="application/ld+json">' . wp_json_encode( $schema_jsonld ) . '</script>';
}
