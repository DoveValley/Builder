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
	// Headings get a bold serif display face by default — distinct from the
	// plain body font — for typographic contrast an unbranded client
	// otherwise has none of. A client who HAS set their own font keeps using
	// it for headings too (brand consistency wins over the default pairing),
	// so this only ever changes anything for the common case of no font set.
	$heading_font_decl = $font !== '' ? $font_decl : "Georgia, 'Times New Roman', ";

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
		.lpc-page .lpc-hero-headline,
		.lpc-page .lpc-section h2,
		.lpc-page .lpc-cta h2 {
			font-family: <?php echo $heading_font_decl; ?>serif;
		}
		.lpc-page .lpc-hero {
			background: <?php echo $primary_tint; ?>;
			padding: 48px 24px;
			border-radius: 12px;
			/* Real bug, found live (2026-11-15): every other block on this page
			   (.lpc-section below) must match this block's own max-width and be
			   centered — .lpc-hero was once the one block with no max-width, so
			   it stretched to the theme's full (much wider) content column.
			   Splitting that extra width 1.1fr/1fr pushed the text flush left
			   and blew the image up far larger than anything else on the page,
			   reading as "too big, off to the side" — not a sizing bug in the
			   image itself, a width mismatch against the rest of the page's own
			   established column. (2026-11-22: 1242px — not the theme's raw
			   --global-content-width:1290px, which is BEFORE its own
			   --global-content-edge-padding (1.5rem each side) is subtracted.
			   The real resolved column every normal WP block actually uses is
			   --wp--style--global--content-size, which equals
			   calc(1290px - 1.5rem - 1.5rem) = 1242px — confirmed by reading
			   that chain directly in the theme's own rendered CSS, not assumed
			   from the first variable name that looked plausible. Still kept
			   identical across every block below.) */
			max-width: 1242px;
			margin: 0 auto 32px;
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
		/* Real design problem, found live (2026-11-15): AI-generated service
		   photos are square (1024x1024), which at hero-column width renders
		   as a tall square towering over the much shorter text column,
		   unbalancing the whole hero. First attempt put aspect-ratio +
		   object-fit directly on the <img> — the site factory's own
		   style.src.css (.hs-image, the proven hero_split block this plugin's
		   hero is modeled on) documents that EXACT pattern already causing
		   two real layout-shift bugs this way, one found on
		   baileyrestoration.com itself: the img's own box depends on a ratio
		   the real file doesn't always match at some point in the load
		   sequence. Fixed the same way that file was: the ratio lives on the
		   WRAPPER (.lpc-hero-image, the <figure>), which reserves an
		   already-fixed-size box; the <img> is absolutely positioned to fill
		   it, so its own intrinsic/attribute dimensions never participate in
		   sizing at all. */
		.lpc-page .lpc-hero-image { position: relative; overflow: hidden; border-radius: 10px; aspect-ratio: 4 / 3; }
		.lpc-page .lpc-hero-image img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; display: block; }
		.lpc-page .lpc-trust-bar { background: #111827; max-width: 1242px; margin: 0 auto 32px; padding: 14px 24px; border-radius: 10px; }
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
		.lpc-page .lpc-section { max-width: 1242px; margin: 0 auto 40px; }
		.lpc-page .lpc-section h2 { font-size: 1.5rem; margin: 0 0 16px; border-left: 4px solid var(--lpc-primary); padding-left: 12px; }
		.lpc-page .lpc-card-grid { list-style: none; margin: 0; padding: 0; display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; }
		.lpc-page .lpc-card { background: rgba(0,0,0,.015); border: 1px solid rgba(0,0,0,.08); border-radius: 10px; padding: 18px 20px; }
		.lpc-page .lpc-card strong { display: block; margin-bottom: 6px; color: var(--lpc-primary); }
		.lpc-page .lpc-card span { display: block; font-size: .92rem; opacity: .8; }
		/* Steps get their own identity -- a numbered circle badge (pure CSS
		   counter, independent of the item's own text) + a connecting line
		   between badges on one row, competitive finding 2026-11-23. The
		   line is a per-card ::after reaching toward the next card, hidden
		   on the last card and on mobile (where cards stack to one column
		   and a horizontal line between vertically-stacked cards would read
		   as a layout bug, not a timeline). */
		.lpc-page .lpc-steps-grid { list-style: none; margin: 0; padding: 24px 0 0; display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; }
		.lpc-page .lpc-step-card { position: relative; background: rgba(0,0,0,.015); border: 1px solid rgba(0,0,0,.08); border-radius: 10px; padding: 32px 20px 18px; text-align: center; }
		.lpc-page .lpc-step-card strong { display: block; margin: 8px 0 6px; color: var(--lpc-primary); }
		.lpc-page .lpc-step-card span { display: block; font-size: .92rem; opacity: .8; }
		.lpc-page .lpc-step-card::before {
			counter-increment: lpc-step; content: counter(lpc-step);
			position: absolute; top: -18px; left: 50%; transform: translateX(-50%);
			width: 36px; height: 36px; border-radius: 50%; z-index: 1;
			background: var(--lpc-primary); color: #fff; font-weight: 700; font-size: 1rem;
			display: flex; align-items: center; justify-content: center;
			box-shadow: 0 2px 6px rgba(0,0,0,.2);
		}
		.lpc-page .lpc-steps-grid { counter-reset: lpc-step; }
		.lpc-page .lpc-step-card:not(:last-child)::after {
			content: ''; position: absolute; top: 0; left: calc(50% + 18px); right: calc(-50% + 18px);
			height: 2px; background: var(--lpc-primary); opacity: .25;
		}
		/* Same source-order lesson as the FAQ grid fix above: placed right
		   after the rule it overrides, not folded into an earlier media
		   query block. */
		@media (max-width: 760px) {
			.lpc-page .lpc-step-card::after { content: none; }
		}
		.lpc-page .lpc-faq-list { display: grid; grid-template-columns: repeat(2, 1fr); gap: 14px; align-items: start; }
		/* Placed immediately after the base rule, not folded into the
		   earlier hero media-query block — a real bug found testing this:
		   two rules of equal specificity resolve by SOURCE ORDER regardless
		   of which one sits in a (currently-true) media query, so the
		   override has to come after the rule it overrides or it silently
		   loses the cascade on every viewport, not just wide ones. */
		@media (max-width: 760px) {
			.lpc-page .lpc-faq-list { grid-template-columns: 1fr; }
		}
		.lpc-page .lpc-faq-item { background: <?php echo $accent_tint; ?>; border-radius: 10px; padding: 16px 20px; }
		.lpc-page .lpc-faq-item h3 { margin: 0 0 6px; font-size: 1.05rem; }
		.lpc-page .lpc-faq-item p { margin: 0; opacity: .85; }
		/* Accordion variant (Phase 3, 2026-11-22) — native <details>/<summary>,
		   same .lpc-faq-item background/radius/padding as the list variant
		   above; only the disclosure marker itself needs replacing with a
		   simple rotating indicator in the client's own accent color. */
		.lpc-page .lpc-faq-item summary { margin: 0; font-size: 1.05rem; font-weight: 600; cursor: pointer; list-style: none; display: flex; justify-content: space-between; align-items: center; gap: 12px; }
		.lpc-page .lpc-faq-item summary::-webkit-details-marker { display: none; }
		.lpc-page .lpc-faq-item summary::after { content: '+'; color: var(--lpc-primary); font-size: 1.3rem; line-height: 1; flex-shrink: 0; transition: transform .15s ease; }
		.lpc-page .lpc-faq-item[open] summary::after { transform: rotate(45deg); }
		.lpc-page .lpc-faq-item[open] summary { margin-bottom: 6px; }
		/* Bold solid band, not the usual arbitrary-hex-safe pastel tint every
		   other background on this page uses — a FIXED dark neutral, not
		   derived from the client's own primary/accent color at all, so
		   white text here is always safe regardless of which hex a client
		   picked (the same risk the tint rule exists to avoid elsewhere,
		   just solved by not depending on client color here instead of by
		   staying pastel). Competitive finding, 2026-11-23: every tint on
		   this page read as muted next to a real competitor's solid-color
		   CTA banner; this is the one section where going bold is safe. */
		.lpc-page .lpc-cta { background: #0f1b2d; color: #fff; border-radius: 12px; padding: 36px 24px; text-align: center; }
		.lpc-page .lpc-cta p { opacity: .92; }
		.lpc-page .lpc-pill-list { list-style: none; display: flex; flex-wrap: wrap; gap: 8px; padding: 0; margin: 0; }
		.lpc-page .lpc-pill { background: rgba(0,0,0,.05); border-radius: 999px; padding: 6px 14px; font-size: .85rem; text-decoration: none; color: #1a1a2e; } /* same missing-explicit-color bug as lpc-hub-item a, found in the same audit */
		.lpc-page .lpc-pill:hover { background: var(--lpc-primary); color: #fff; }
		.lpc-page .lpc-fact-list { display: flex; flex-wrap: wrap; gap: 20px; margin: 0; padding: 0; }
		.lpc-page .lpc-fact-list div { background: rgba(0,0,0,.015); border: 1px solid rgba(0,0,0,.08); border-radius: 10px; padding: 14px 18px; min-width: 180px; }
		.lpc-page .lpc-fact-list dt { font-size: .76rem; text-transform: uppercase; letter-spacing: .03em; opacity: .65; margin: 0 0 4px; }
		.lpc-page .lpc-fact-list dd { margin: 0; font-weight: 600; }
		.lpc-page .lpc-fact-list dd a { color: var(--lpc-primary); text-decoration: underline; }
		/* Same real content width every single landing page's .lpc-section
		   uses (see that rule's own comment for how 1242px was verified) —
		   the hub listing has no per-block wrapper to inherit it from
		   otherwise, so it needs its own explicit cap here. */
		.lpc-page .lpc-hub { max-width: 1242px; margin: 0 auto; }
		.lpc-page .lpc-hub-intro { margin: 0 0 20px; opacity: .85; }
		/* auto-fill + a capped max (not auto-fit + 1fr) so a single published
		   page doesn't stretch its one card edge-to-edge into a bare full-width
		   stripe — real look found live on restore-2 (2026-11-15) with only
		   one city published. Cards stay a sane card width regardless of count;
		   leftover grid space becomes trailing gap, not card width. */
		/* State > Hub Area > City > Services grouping (2026-11-15) — replaces
		   a flat grid of every city/service combination, which read as an
		   undifferentiated wall of links once a client had enough cities
		   that the underlying hub structure stopped being obvious from a
		   flat list alone. Same border-left-bar heading style .lpc-section
		   h2 already uses elsewhere on these pages, scaled down a step per
		   level, so the hub page reads as consistent with the rest of the
		   site rather than a one-off layout. */
		.lpc-page .lpc-hub-state { font-size: 1.5rem; margin: 28px 0 16px; border-left: 4px solid var(--lpc-primary); padding-left: 12px; }
		.lpc-page .lpc-hub-state:first-of-type { margin-top: 0; }
		.lpc-page .lpc-hub-area { font-size: 1.15rem; margin: 20px 0 12px; border-left: 3px solid var(--lpc-accent); padding-left: 10px; opacity: .85; }
		/* auto-fill + a capped max (not auto-fit + 1fr) so a single city
		   doesn't stretch its one card edge-to-edge into a bare full-width
		   stripe — same real look found live on restore-2 (2026-11-15) that
		   drove the identical fix on .lpc-hero/.lpc-trust-bar. */
		.lpc-page .lpc-hub-city-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 280px)); gap: 16px; margin: 0 0 8px; }
		.lpc-page .lpc-hub-city-card { border: 1px solid rgba(0,0,0,.1); border-left: 4px solid var(--lpc-primary); border-radius: 10px; padding: 16px 18px; }
		.lpc-page .lpc-hub-city-name { font-size: 1rem; margin: 0 0 10px; }
		.lpc-page .lpc-hub-service-list { list-style: none; margin: 0; padding: 0; }
		.lpc-page .lpc-hub-service-list li { margin: 0 0 6px; }
		.lpc-page .lpc-hub-service-list li:last-child { margin-bottom: 0; }
		.lpc-page .lpc-hub-service-list a {
			text-decoration: none; font-weight: 600;
			/* Explicit color, not inherited — <a> tags don't simply inherit
			   from an ancestor like .lpc-page the way other elements do; a
			   real confirmed bug on restore-2's live hub page (2026-11-15)
			   was exactly this rule missing it on the old flat-list markup
			   this replaces. Every <a> this plugin renders sets its own
			   color explicitly for that reason. */
			color: var(--lpc-primary);
		}
		.lpc-page .lpc-hub-service-list a:hover { text-decoration: underline; }

		/* Compact format — a denser single row per city, no card border,
		   services run inline instead of stacked. */
		.lpc-page .lpc-hub-city-grid.lpc-format-compact { grid-template-columns: 1fr; gap: 4px; }
		.lpc-page .lpc-hub-city-compact { display: flex; align-items: baseline; gap: 10px; padding: 8px 0; border-bottom: 1px solid rgba(0,0,0,.08); }
		.lpc-page .lpc-hub-city-compact .lpc-hub-city-name { margin: 0; font-size: .95rem; min-width: 140px; }
		.lpc-page .lpc-hub-city-compact .lpc-hub-service-list { display: flex; flex-wrap: wrap; gap: 4px 14px; }
		.lpc-page .lpc-hub-city-compact .lpc-hub-service-list li { margin: 0; }

		/* Detailed format — the same card as the default, plus a short
		   "N services available" line above the link list. */
		.lpc-page .lpc-hub-city-detailed-count { font-size: .8rem; opacity: .7; margin: 0 0 8px; }

		/* Mobile-only persistent call bar (Bailey-style competitive finding,
		   2026-11-23) — fixed to the viewport, not .lpc-page, since
		   position:fixed is relative to the viewport regardless of DOM
		   nesting; living inside .lpc-page is only so the existing scoped
		   selector convention still applies. Hidden on desktop: the hero/CTA
		   buttons already on-page cover that case there, a persistent
		   duplicate would just be clutter. */
		.lpc-sticky-call { display: none; }
		@media (max-width: 760px) {
			.lpc-sticky-call {
				display: block; position: fixed; left: 0; right: 0; bottom: 0; z-index: 9999;
				background: var(--lpc-primary); box-shadow: 0 -2px 10px rgba(0,0,0,.15);
			}
			.lpc-sticky-call a {
				display: flex; align-items: center; justify-content: center; gap: 8px;
				padding: 14px 20px; color: #fff; text-decoration: none; font-weight: 700;
			}
			/* Reserves the space the fixed bar occupies so it never covers the
			   page's own last section — a small unconditional cost (also
			   applied on the hub page, which never renders the bar) rather
			   than a :has()-based conditional for a cosmetic amount of
			   trailing whitespace. */
			.lpc-page { padding-bottom: 60px; }
		}
	</style>
	<?php
	return ob_get_clean();
}
