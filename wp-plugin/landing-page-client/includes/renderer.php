<?php
/**
 * Renders the 6 locked section types as bare semantic HTML with zero CSS/
 * classes of our own, so the page inherits the client's real theme instead
 * of competing with it.
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
			case 'text':
				$html .= lpc_render_text( $section );
				break;
			case 'services_list':
				$html .= lpc_render_services_list( $section );
				break;
			case 'faq':
				$html .= lpc_render_faq( $section );
				break;
			case 'cta':
				$html .= lpc_render_cta( $section );
				break;
			case 'related_links':
				$html .= lpc_render_related_links( $section );
				break;
		}
	}
	return $html;
}

function lpc_render_hero( $s, $level = 'h2' ) {
	$level = in_array( $level, [ 'h1', 'h2' ], true ) ? $level : 'h2';
	$out   = '<section>';
	$out  .= '<' . $level . '>' . esc_html( $s['headline'] ?? '' ) . '</' . $level . '>';
	if ( ! empty( $s['subheading'] ) ) {
		$out .= '<p>' . esc_html( $s['subheading'] ) . '</p>';
	}
	if ( ! empty( $s['image'] ) ) {
		$out .= '<figure><img src="' . esc_url( $s['image'] ) . '" alt="" loading="lazy"></figure>';
	}
	if ( ! empty( $s['phone'] ) ) {
		$tel = preg_replace( '/[^0-9+]/', '', $s['phone'] );
		$out .= '<p><a href="tel:' . esc_attr( $tel ) . '">' . esc_html( $s['cta_text'] ?? $s['phone'] ) . '</a></p>';
	}
	$out .= '</section>';
	return $out;
}

function lpc_render_text( $s ) {
	$out  = '<section>';
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
	$out  = '<section>';
	if ( ! empty( $s['heading'] ) ) {
		$out .= '<h2>' . esc_html( $s['heading'] ) . '</h2>';
	}
	$out .= '<ul>';
	foreach ( (array) ( $s['items'] ?? [] ) as $item ) {
		$out .= '<li><strong>' . esc_html( $item['title'] ?? '' ) . '</strong>';
		if ( ! empty( $item['text'] ) ) {
			$out .= ' — ' . esc_html( $item['text'] );
		}
		$out .= '</li>';
	}
	$out .= '</ul></section>';
	return $out;
}

function lpc_render_faq( $s ) {
	$out  = '<section>';
	if ( ! empty( $s['heading'] ) ) {
		$out .= '<h2>' . esc_html( $s['heading'] ) . '</h2>';
	}
	foreach ( (array) ( $s['items'] ?? [] ) as $item ) {
		$out .= '<h3>' . esc_html( $item['q'] ?? '' ) . '</h3>';
		$out .= '<p>' . esc_html( $item['a'] ?? '' ) . '</p>';
	}
	$out .= '</section>';
	return $out;
}

function lpc_render_cta( $s ) {
	$out  = '<section>';
	if ( ! empty( $s['heading'] ) ) {
		$out .= '<h2>' . esc_html( $s['heading'] ) . '</h2>';
	}
	if ( ! empty( $s['body'] ) ) {
		$out .= '<p>' . esc_html( $s['body'] ) . '</p>';
	}
	if ( ! empty( $s['phone'] ) ) {
		$tel = preg_replace( '/[^0-9+]/', '', $s['phone'] );
		$out .= '<p><a href="tel:' . esc_attr( $tel ) . '">' . esc_html( $s['button_text'] ?? $s['phone'] ) . '</a></p>';
	}
	$out .= '</section>';
	return $out;
}

function lpc_render_related_links( $s ) {
	$links = (array) ( $s['links'] ?? [] );
	if ( empty( $links ) ) {
		return '';
	}
	$out  = '<section>';
	if ( ! empty( $s['heading'] ) ) {
		$out .= '<h2>' . esc_html( $s['heading'] ) . '</h2>';
	}
	$out .= '<ul>';
	foreach ( $links as $link ) {
		$out .= '<li><a href="' . esc_url( $link['url'] ?? '#' ) . '">' . esc_html( $link['label'] ?? '' ) . '</a></li>';
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
