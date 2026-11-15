<?php
/**
 * Renders the 12 locked section types. Originally zero CSS/classes of our own
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

function lpc_render_sections( $sections, $hero_level = 'h2' ) {
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
				$html .= lpc_render_faq( $section );
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
		$text .= '<p><a class="lpc-btn lpc-btn-primary" href="tel:' . esc_attr( $tel ) . '">' . esc_html( $s['cta_text'] ?? $s['phone'] ) . '</a></p>';
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
		$image = '<figure class="lpc-hero-image"><img src="' . esc_url( $s['image'] ) . '" alt="' . esc_attr( $s['image_alt'] ?? '' ) . '"' . $dims . ' loading="lazy"></figure>';
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

function lpc_render_text( $s ) {
	$out  = '<section class="lpc-section">';
	if ( ! empty( $s['heading'] ) ) {
		$out .= '<h2>' . esc_html( $s['heading'] ) . '</h2>';
	}
	if ( ! empty( $s['body'] ) ) {
		$out .= '<p>' . esc_html( $s['body'] ) . '</p>';
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
	$out .= '<ol class="lpc-card-grid">';
	foreach ( (array) ( $s['items'] ?? [] ) as $item ) {
		$out .= '<li class="lpc-card"><strong>' . esc_html( $item['title'] ?? '' ) . '</strong>';
		if ( ! empty( $item['text'] ) ) {
			$out .= '<span>' . esc_html( $item['text'] ) . '</span>';
		}
		$out .= '</li>';
	}
	$out .= '</ol></section>';
	return $out;
}

function lpc_render_faq( $s ) {
	$out  = '<section class="lpc-section">';
	if ( ! empty( $s['heading'] ) ) {
		$out .= '<h2>' . esc_html( $s['heading'] ) . '</h2>';
	}
	$out .= '<div class="lpc-faq-list">';
	foreach ( (array) ( $s['items'] ?? [] ) as $item ) {
		$out .= '<div class="lpc-faq-item">';
		$out .= '<h3>' . esc_html( $item['q'] ?? '' ) . '</h3>';
		$out .= '<p>' . esc_html( $item['a'] ?? '' ) . '</p>';
		$out .= '</div>';
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
		$out .= '<p><a class="lpc-btn lpc-btn-accent" href="tel:' . esc_attr( $tel ) . '">' . esc_html( $s['button_text'] ?? $s['phone'] ) . '</a></p>';
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
	$out .= '<figure class="lpc-area-map"><img src="' . esc_url( $s['image'] ) . '" alt="' . esc_attr( $s['image_alt'] ?? '' ) . '"' . $dims . ' loading="lazy"></figure>';
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
		$parts = array_map(
			function ( $nc ) {
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

function lpc_render_schema( $schema_jsonld ) {
	if ( empty( $schema_jsonld ) ) {
		return '';
	}
	return '<script type="application/ld+json">' . wp_json_encode( $schema_jsonld ) . '</script>';
}
