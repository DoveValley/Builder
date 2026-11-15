<?php
/**
 * Styles the plugin's own output using the client's REAL captured brand
 * colors (manifest 'branding' — primary_color/accent_color/font, set on the
 * admin Branding tab, usually auto-detected from the client's live site in
 * the first place) rather than either (a) bare unstyled HTML, which looks
 * plain/unfinished, or (b) guessing at the client's theme's own CSS variable
 * names, which aren't consistent across theme builders (confirmed: Kadence
 * uses --global-palette1/2, not the standard WP --wp--preset--color--*
 * naming a block theme would use). Using data we already reliably capture
 * once per client is simpler and more robust than live-reading arbitrary
 * theme CSS. Scoped entirely under .lpc-page so nothing here can leak onto
 * the theme's own header/footer/nav markup that wraps this output.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function lpc_hex_to_rgb( $hex, $fallback = [ 37, 99, 235 ] ) {
	$hex = ltrim( (string) $hex, '#' );
	if ( ! preg_match( '/^[0-9a-f]{6}$/i', $hex ) ) {
		return $fallback;
	}
	return [ hexdec( substr( $hex, 0, 2 ) ), hexdec( substr( $hex, 2, 2 ) ), hexdec( substr( $hex, 4, 2 ) ) ];
}

function lpc_render_styles( $branding ) {
	$primary_hex = $branding['primary_color'] ?? '';
	$accent_hex  = $branding['accent_color'] ?? '';
	$font        = trim( (string) ( $branding['font'] ?? '' ) );

	$primary_rgb = lpc_hex_to_rgb( $primary_hex, [ 37, 99, 235 ] );   // fallback: neutral blue
	$accent_rgb  = lpc_hex_to_rgb( $accent_hex, [ 245, 158, 11 ] );   // fallback: neutral amber
	$primary     = sprintf( 'rgb(%d,%d,%d)', ...$primary_rgb );
	$accent      = sprintf( 'rgb(%d,%d,%d)', ...$accent_rgb );
	$primary_tint = sprintf( 'rgba(%d,%d,%d,0.08)', ...$primary_rgb );
	$accent_tint  = sprintf( 'rgba(%d,%d,%d,0.10)', ...$accent_rgb );

	// This is raw CSS text, not HTML — esc_html() would HTML-entity-encode
	// quotes/ampersands and produce invalid CSS (confirmed: 'Inter' became
	// the literal text &#039;Inter&#039;, which no browser parses as a font
	// name). $primary/$accent/the tints are safe by construction (sprintf
	// '%d' from lpc_hex_to_rgb, numeric only) and need no escaping. $font is
	// the one free-text value here (admin-entered), so it's whitelisted to a
	// safe character set instead — the correct defense for a CSS context,
	// not HTML-entity escaping.
	$font = preg_replace( '/[^a-zA-Z0-9 \-]/', '', $font );

	// Applied as a soft enhancement only — we don't enqueue the actual font
	// file (no Google Fonts loading here, to keep this a pure CSS change),
	// so an unavailable font name just falls back to the theme's own body
	// font with zero visual harm, same as any missing-font browser fallback.
	$font_decl = $font !== '' ? "'" . $font . "', " : '';

	ob_start();
	?>
	<style id="lpc-styles">
		.lpc-page {
			--lpc-primary: <?php echo $primary; ?>;
			--lpc-accent: <?php echo $accent; ?>;
			font-family: <?php echo $font_decl; ?>inherit;
			/* Every background this stylesheet sets (hero/card/faq/cta tints,
			   the plain page background) is a light pastel, regardless of the
			   client's chosen brand color — primary_tint/accent_tint are always
			   rendered at 8-10% opacity. A fixed dark neutral is therefore
			   always correct here and far safer than inheriting the theme's own
			   default text color, which is NOT guaranteed to be dark (a real,
			   reported case, 2026-11-15: some theme/page contexts resolve their
			   inherited default to a light/white color, which against these
			   same-family light backgrounds reads as text "disappearing"). */
			color: #1a1a2e;
		}
		.lpc-page .lpc-hero {
			background: <?php echo $primary_tint; ?>;
			padding: 48px 24px;
			border-radius: 12px;
			margin: 0 0 32px;
			display: grid;
			grid-template-columns: 1.1fr 1fr;
			gap: 36px;
			align-items: center;
			text-align: left;
		}
		/* No image at all: one centered column reads better than an empty
		   second grid cell sitting beside the text. */
		.lpc-page .lpc-hero.lpc-hero-no-image { grid-template-columns: 1fr; text-align: center; }
		.lpc-page .lpc-hero.lpc-hero-no-image .lpc-hero-sub { margin-left: auto; margin-right: auto; }
		@media (max-width: 760px) {
			.lpc-page .lpc-hero { grid-template-columns: 1fr; text-align: center; }
			.lpc-page .lpc-hero-sub { margin-left: auto; margin-right: auto; }
		}
		.lpc-page .lpc-hero-headline { font-size: 2rem; line-height: 1.2; margin: 0 0 12px; }
		.lpc-page .lpc-hero-sub { font-size: 1.1rem; max-width: 640px; margin: 0 0 20px; opacity: .85; }
		.lpc-page .lpc-hero-image img { max-width: 100%; height: auto; border-radius: 10px; display: block; margin: 0; }
		.lpc-page .lpc-trust-bar { background: #111827; margin: 0 0 32px; padding: 14px 24px; border-radius: 10px; }
		.lpc-page .lpc-trust-bar ul { list-style: none; display: flex; flex-wrap: wrap; justify-content: center; gap: 10px 28px; margin: 0; padding: 0; }
		.lpc-page .lpc-trust-bar li { color: #fff; font-weight: 700; font-size: .92rem; white-space: nowrap; }
		.lpc-page .lpc-safety-card { text-align: center; }
		.lpc-page .lpc-safety-icon { width: 44px; height: 44px; margin: 0 auto 10px; padding: 10px; border-radius: 10px; background: var(--lpc-primary); color: #fff; box-sizing: border-box; }
		.lpc-page .lpc-safety-icon svg { width: 100%; height: 100%; display: block; }
		.lpc-page .lpc-safety-link { display: inline-block; margin-top: 8px; font-size: .85rem; font-weight: 700; color: var(--lpc-primary); text-decoration: none; }
		.lpc-page .lpc-safety-link:hover { text-decoration: underline; }
		.lpc-page .lpc-comparison { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 24px; }
		.lpc-page .lpc-comparison-col { background: rgba(0,0,0,.015); border: 1px solid rgba(0,0,0,.08); border-radius: 10px; padding: 18px 20px; }
		.lpc-page .lpc-comparison-col h3 { margin: 0 0 10px; font-size: 1.05rem; }
		.lpc-page .lpc-comparison-col ul { list-style: none; margin: 0; padding: 0; }
		.lpc-page .lpc-comparison-col li { margin: 0 0 8px; padding-left: 22px; position: relative; }
		.lpc-page .lpc-comparison-a h3 { color: var(--lpc-primary); }
		.lpc-page .lpc-comparison-a li:before { content: "\2713"; position: absolute; left: 0; color: var(--lpc-primary); font-weight: 700; }
		.lpc-page .lpc-comparison-b h3 { color: var(--lpc-accent); }
		.lpc-page .lpc-comparison-b li:before { content: "\2715"; position: absolute; left: 0; color: var(--lpc-accent); font-weight: 700; }
		.lpc-page .lpc-area-map img { max-width: 100%; height: auto; border-radius: 10px; display: block; margin: 0 auto; }
		.lpc-page .lpc-data-chart img { max-width: 100%; height: auto; border-radius: 10px; display: block; margin: 0 auto 16px; }
		.lpc-page .chart-table-wrap { overflow-x: auto; }
		.lpc-page .chart-data-table { width: 100%; border-collapse: collapse; font-size: .88rem; }
		.lpc-page .chart-data-table caption { text-align: left; font-weight: 700; margin-bottom: 8px; }
		.lpc-page .chart-data-table th, .lpc-page .chart-data-table td { padding: 6px 10px; border-bottom: 1px solid rgba(0,0,0,.08); text-align: left; }
		.lpc-page .chart-data-table .chart-data-num { text-align: right; }
		.lpc-page .chart-method { font-size: .78rem; opacity: .65; margin: 10px 0 0; }
		.lpc-page .lpc-btn {
			display: inline-block; padding: 14px 28px; border-radius: 8px;
			font-weight: 700; text-decoration: none; color: #fff; transition: opacity .15s;
		}
		.lpc-page .lpc-btn:hover { opacity: .88; color: #fff; }
		.lpc-page .lpc-btn-primary { background: var(--lpc-primary); }
		.lpc-page .lpc-btn-accent { background: var(--lpc-accent); }
		.lpc-page .lpc-section { max-width: 760px; margin: 0 auto 40px; }
		.lpc-page .lpc-section h2 { font-size: 1.5rem; margin: 0 0 16px; border-left: 4px solid var(--lpc-primary); padding-left: 12px; }
		.lpc-page .lpc-card-grid { list-style: none; margin: 0; padding: 0; display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; }
		.lpc-page .lpc-card { background: rgba(0,0,0,.015); border: 1px solid rgba(0,0,0,.08); border-radius: 10px; padding: 18px 20px; }
		.lpc-page .lpc-card strong { display: block; margin-bottom: 6px; color: var(--lpc-primary); }
		.lpc-page .lpc-card span { display: block; font-size: .92rem; opacity: .8; }
		.lpc-page .lpc-faq-list { display: flex; flex-direction: column; gap: 14px; }
		.lpc-page .lpc-faq-item { background: <?php echo $accent_tint; ?>; border-radius: 10px; padding: 16px 20px; }
		.lpc-page .lpc-faq-item h3 { margin: 0 0 6px; font-size: 1.05rem; }
		.lpc-page .lpc-faq-item p { margin: 0; opacity: .85; }
		.lpc-page .lpc-cta { background: <?php echo $primary_tint; ?>; border-radius: 12px; padding: 36px 24px; text-align: center; }
		.lpc-page .lpc-pill-list { list-style: none; display: flex; flex-wrap: wrap; gap: 8px; padding: 0; margin: 0; }
		.lpc-page .lpc-pill { background: rgba(0,0,0,.05); border-radius: 999px; padding: 6px 14px; font-size: .85rem; text-decoration: none; color: #1a1a2e; } /* same missing-explicit-color bug as lpc-hub-item a, found in the same audit */
		.lpc-page .lpc-pill:hover { background: var(--lpc-primary); color: #fff; }
		.lpc-page .lpc-fact-list { display: flex; flex-wrap: wrap; gap: 20px; margin: 0; padding: 0; }
		.lpc-page .lpc-fact-list div { background: rgba(0,0,0,.015); border: 1px solid rgba(0,0,0,.08); border-radius: 10px; padding: 14px 18px; min-width: 180px; }
		.lpc-page .lpc-fact-list dt { font-size: .76rem; text-transform: uppercase; letter-spacing: .03em; opacity: .65; margin: 0 0 4px; }
		.lpc-page .lpc-fact-list dd { margin: 0; font-weight: 600; }
		.lpc-page .lpc-fact-list dd a { color: var(--lpc-primary); text-decoration: underline; }
		.lpc-page .lpc-hub-list { list-style: none; padding: 0; margin: 0; display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 16px; }
		.lpc-page .lpc-hub-item a {
			display: block; border: 1px solid rgba(0,0,0,.1); border-left: 4px solid var(--lpc-primary);
			border-radius: 10px; padding: 18px 20px; text-decoration: none; font-weight: 600;
			/* Explicit color — missing here was a real confirmed bug, found on
			   restore-2's real live hub page (2026-11-15): <a> tags don't
			   simply inherit color from an ancestor like .lpc-page the way
			   other elements do — any explicit rule, even the browser's own
			   default/theme's own global link color, overrides inheritance
			   regardless of specificity, since inherited values are always
			   lowest-priority in the cascade. Every other <a> this plugin
			   renders already sets its own color explicitly (lpc-btn,
			   lpc-safety-link, lpc-fact-list dd a) — this was the one rule
			   that didn't, and it rendered invisible against this theme's
			   own link-color default. */
			color: var(--lpc-primary);
		}
		.lpc-page .lpc-hub-item a:hover { box-shadow: 0 2px 10px rgba(0,0,0,.08); }
	</style>
	<?php
	return ob_get_clean();
}
