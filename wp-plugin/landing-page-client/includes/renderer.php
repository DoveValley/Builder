<?php
/**
 * Renders the 6 locked section types as bare semantic HTML with zero CSS/
 * classes of our own, so the page inherits the client's real theme instead
 * of competing with it.
 *
 * Hard rule, found the hard way against a real theme (Twenty Twenty-Five
 * already renders its own site title as H1): our content never claims H1.
 * Everything here starts at H2; FAQ question items are H3.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function lpc_render_sections( $sections ) {
	$html = '';
	foreach ( (array) $sections as $section ) {
		$type = $section['type'] ?? '';
		switch ( $type ) {
			case 'hero':
				$html .= lpc_render_hero( $section );
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

function lpc_render_hero( $s ) {
	$out  = '<section>';
	$out .= '<h2>' . esc_html( $s['headline'] ?? '' ) . '</h2>';
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
