<?php
/**
 * Plugin Name: PlusCities Chrome
 * Description: Custom header/footer, design tokens, and interactive JS for
 * the PlusCities marketing homepage. Deployed as an ADDITIONAL mu-plugin on
 * top of wp-builder-site's shared base chrome (title/meta/schema/SEO infra
 * stays shared — only header/footer are overridden here, via the
 * wpb_custom_header()/wpb_custom_footer() convention the base plugin checks
 * for). Everything below is plain CSS/vanilla JS — no build step, no
 * page-builder plugin, matching the spec's own "Astro or plain HTML/CSS/JS"
 * choice.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function pc_settings(): array {
	return [
		'lead_email'      => get_option( 'wpb_lead_email', '' ),
		'partner_discount' => get_option( 'wpb_partner_discount', '' ),
		'stripe_starter'  => get_option( 'wpb_stripe_starter_url', '' ),
		'stripe_growth'   => get_option( 'wpb_stripe_growth_url', '' ),
		'stripe_dominate' => get_option( 'wpb_stripe_dominate_url', '' ),
	];
}

/** Lime map-pin-with-navy-plus mark, followed by the wordmark. Both header and footer are navy, so "Plus" is always white / "Cities" always lime here — no on-light variant needed yet. */
function pc_logo_svg(): string {
	return '<svg class="pc-logo-mark" width="34" height="34" viewBox="0 0 28 28" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">'
		. '<path d="M14 1C7.9 1 3 5.9 3 12c0 7.8 11 15 11 15s11-7.2 11-15c0-6.1-4.9-11-11-11z" fill="#C8F55A"/>'
		. '<rect x="11" y="5" width="6" height="16" rx="1" fill="#0B1B33"/>'
		. '<rect x="7" y="10" width="14" height="6" rx="1" fill="#0B1B33"/>'
		. '</svg>';
}

function pc_logo_wordmark(): string {
	return pc_logo_svg() . '<span class="pc-wordmark"><span class="pc-wordmark-plus">Plus</span><span class="pc-wordmark-cities">Cities</span></span>';
}

function wpb_custom_header(): void {
	?>
	<header class="pc-header" id="pc-header">
		<div class="pc-container pc-header-inner">
			<a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="pc-logo" aria-label="PlusCities home"><?php echo pc_logo_wordmark(); ?></a>
			<nav class="pc-nav" aria-label="Primary">
				<a href="/how-it-works">How it works</a>
				<a href="/agencies">For agencies</a>
				<a href="/industries">Industries</a>
				<a href="/pricing">Pricing</a>
				<a href="/demos">Live demos</a>
			</nav>
			<div class="pc-header-actions">
				<a href="https://app.pluscities.com/admin/agency_login.php" class="pc-btn pc-btn-outline-navy pc-login-btn" data-pc-event="login_click">
					<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4.4 3.6-8 8-8s8 3.6 8 8"/></svg>
					Log in
				</a>
				<a href="/agencies" class="pc-btn pc-btn-lime pc-header-partner-btn">Become a Partner</a>
				<button type="button" class="pc-hamburger" id="pc-hamburger" aria-label="Open menu" aria-expanded="false" aria-controls="pc-mobile-menu">
					<span></span><span></span><span></span>
				</button>
			</div>
		</div>
		<div class="pc-mobile-menu" id="pc-mobile-menu" hidden>
			<a href="/how-it-works">How it works</a>
			<a href="/agencies">For agencies</a>
			<a href="/industries">Industries</a>
			<a href="/pricing">Pricing</a>
			<a href="/demos">Live demos</a>
			<a href="/agencies" class="pc-btn pc-btn-lime pc-mobile-cta">Become a Partner</a>
		</div>
	</header>
	<?php
}

function wpb_custom_footer(): void {
	$year = gmdate( 'Y' );
	?>
	<footer class="pc-footer">
		<div class="pc-container pc-footer-inner">
			<a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="pc-logo pc-footer-logo" aria-label="PlusCities home"><?php echo pc_logo_wordmark(); ?></a>
			<nav class="pc-footer-links" aria-label="Footer">
				<a href="/how-it-works">How it works</a>
				<a href="/agencies">Partners</a>
				<a href="/docs">Plugin docs</a>
				<a href="https://app.pluscities.com/admin/client_login.php">Client login</a>
				<a href="/terms">Terms</a>
				<a href="/privacy">Privacy</a>
			</nav>
			<div class="pc-footer-copy">&copy; <?php echo esc_html( $year ); ?> PlusCities</div>
		</div>
	</footer>
	<?php
	pc_print_js();
}

/** Design tokens + every component's CSS. One stylesheet, hand-authored — no Tailwind/build step, per the spec's own "plain HTML/CSS/JS" option. */
add_action( 'wp_head', function () {
	?>
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
	<style>
	:root{
		--navy:#0B1B33; --navy-2:#12264A; --navy-line:#2B4670;
		--lime:#C8F55A; --lime-soft:#EEF6DA; --lime-ink:#3D6B0E;
		--ink:#1C2B45; --ink-2:#42506A; --muted:#52607A;
		--on-navy:#C9D4E6; --mist:#F4F7FB; --line:#E2E8F2;
		--pc-max:1240px; --pc-pad:32px;
	}
	@media (max-width:640px){ :root{ --pc-pad:20px; } }

	.site-header,.page-header,.wpb-header,.wpb-footer{display:none !important;}
	/* Hello Elementor's own #content/.site-main wrapper caps at 1140px and auto-centers
	   with a margin — that's what was leaving the full-bleed navy/mist/lime-soft section
	   backgrounds short of the real browser edges, not anything in this stylesheet.
	   Every .pc-section already centers its own content at --pc-max (1240px) via
	   .pc-container, so the backgrounds are free to go edge-to-edge once this is reset. */
	#content,.site-main,.page-content{max-width:none !important;width:100% !important;margin-left:0 !important;margin-right:0 !important;}
	*{box-sizing:border-box;}
	body.pc-body{margin:0;font-family:'Inter',-apple-system,BlinkMacSystemFont,sans-serif;color:var(--ink);line-height:1.6;background:#fff;}
	.pc-container{max-width:var(--pc-max);margin:0 auto;padding:0 var(--pc-pad);}
	h1,h2,h3,.pc-font-head{font-family:'Space Grotesk',sans-serif;font-weight:700;line-height:1.08;margin:0;}
	h1{font-size:68px;letter-spacing:-1px;}
	h2{font-size:48px;letter-spacing:-1px;}
	h3{font-size:23px;}
	p{margin:0;}
	.pc-lead{font-size:20px;line-height:1.5;}
	@media (max-width:640px){ h1{font-size:40px;} h2{font-size:32px;} }

	.pc-section{padding:88px 0;}
	@media (max-width:640px){ .pc-section{padding:56px 0;} }
	.pc-eyebrow{display:inline-block;font-size:13px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--lime-ink);margin-bottom:12px;}
	.pc-eyebrow.on-navy{color:var(--lime);}

	.pc-btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:44px;padding:0 22px;border-radius:11px;font-weight:600;font-size:15px;text-decoration:none;border:none;cursor:pointer;white-space:nowrap;}
	.pc-btn-lime{background:var(--lime);color:var(--navy);}
	.pc-btn-lime:hover{filter:brightness(.95);}
	.pc-btn-outline-navy{background:transparent;color:var(--navy);border:2px solid var(--navy);}
	.pc-btn-outline-onnavy{background:transparent;color:#fff;border:1.5px solid #3A5580;}
	.pc-login-btn{border:2px solid var(--navy);}

	/* Header */
	.pc-header{background:var(--navy);position:sticky;top:0;z-index:40;}
	.pc-header-inner{display:flex;align-items:center;justify-content:space-between;height:76px;gap:24px;}
	.pc-logo{display:inline-flex;align-items:center;gap:8px;text-decoration:none;}
	.pc-wordmark{font-family:'Space Grotesk',sans-serif;font-weight:700;font-size:23px;}
	.pc-wordmark-plus{color:#fff;}
	.pc-wordmark-cities{color:var(--lime);}
	.pc-nav{display:flex;gap:28px;}
	.pc-nav a{color:var(--on-navy);text-decoration:none;font-size:15px;font-weight:500;}
	.pc-nav a:hover{color:#fff;}
	.pc-header-actions{display:flex;align-items:center;gap:12px;}
	.pc-login-btn{color:#fff;border-color:#3A5580;}
	.pc-hamburger{display:none;flex-direction:column;justify-content:center;gap:4px;width:40px;height:40px;background:none;border:none;cursor:pointer;padding:0;}
	.pc-hamburger span{display:block;width:22px;height:2px;background:#fff;margin:0 auto;}
	.pc-mobile-menu{display:none;flex-direction:column;gap:2px;background:var(--navy-2);padding:12px var(--pc-pad) 20px;}
	.pc-mobile-menu a{color:var(--on-navy);text-decoration:none;padding:10px 0;font-size:15px;}
	.pc-mobile-menu.open{display:flex;}
	.pc-mobile-cta{align-self:flex-start;margin-top:8px;}
	@media (max-width:880px){
		.pc-nav{display:none;}
		.pc-header-partner-btn{display:none;}
		.pc-hamburger{display:flex;}
	}
	@media (max-width:360px){ .pc-login-btn span{display:none;} }

	/* Hero */
	.pc-hero{background:var(--navy);color:var(--on-navy);padding:64px 0 0;}
	.pc-hero-grid{display:grid;grid-template-columns:1.1fr 1fr;gap:56px;align-items:start;}
	@media (max-width:980px){ .pc-hero-grid{grid-template-columns:1fr;gap:40px;} }
	.pc-pill{display:inline-flex;align-items:center;gap:8px;background:var(--navy-2);border:1px solid var(--navy-line);border-radius:999px;padding:7px 16px;font-size:13px;color:var(--on-navy);}
	.pc-pill .dot{width:7px;height:7px;border-radius:50%;background:var(--lime);display:inline-block;}
	.pc-hero h1{color:#fff;margin-top:20px;}
	.pc-hero h1 .pc-lime{color:var(--lime);}
	.pc-hero .pc-lead{color:var(--on-navy);margin-top:18px;max-width:46ch;}
	.pc-checks{display:flex;flex-wrap:wrap;gap:18px;margin-top:22px;font-size:14px;}
	.pc-checks span{display:flex;align-items:center;gap:6px;}
	.pc-checks svg{color:var(--lime);flex-shrink:0;}

	.pc-preview-bar{background:#fff;border-radius:16px;padding:14px;display:flex;gap:10px;flex-wrap:wrap;margin-top:26px;box-shadow:0 10px 30px rgba(0,0,0,.18);}
	.pc-preview-bar input{flex:1 1 150px;min-width:120px;border:1px solid var(--line);border-radius:10px;padding:12px 14px;font-size:15px;font-family:inherit;color:var(--ink);}
	.pc-preview-bar button{flex:0 0 auto;}
	@media (max-width:520px){ .pc-preview-bar{flex-direction:column;} .pc-preview-bar button{width:100%;} }

	/* Coverage card */
	.pc-coverage-card{background:#fff;border-radius:22px;padding:22px;position:relative;}
	.pc-coverage-title{font-size:13px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;}
	.pc-badge-added{background:var(--lime);color:var(--navy);font-size:11px;font-weight:700;border-radius:999px;padding:3px 10px;}
	.pc-chip-row{display:flex;flex-wrap:wrap;gap:8px;}
	.pc-chip{font-size:13px;font-weight:600;border-radius:999px;padding:7px 14px;}
	.pc-chip-home{background:var(--mist);border:1px solid var(--line);color:var(--ink);}
	.pc-chip-lime{background:var(--lime);color:var(--navy);opacity:0;transform:scale(.8);}
	.pc-chip-dashed{background:none;border:1.5px dashed var(--line);color:var(--muted);}
	.pc-coverage-card.pc-animate .pc-chip-lime{animation:pcChipIn .35s ease forwards;}
	@keyframes pcChipIn{from{opacity:0;transform:scale(.8);}to{opacity:1;transform:scale(1);}}
	@media (prefers-reduced-motion:reduce){ .pc-chip-lime{opacity:1 !important;transform:none !important;animation:none !important;} }

	.pc-browser{background:var(--navy-2);border:1px solid var(--navy-line);border-radius:14px;margin-top:-30px;position:relative;z-index:2;padding:0;overflow:hidden;box-shadow:0 20px 40px rgba(0,0,0,.3);}
	.pc-browser-bar{display:flex;align-items:center;gap:6px;padding:10px 14px;background:rgba(0,0,0,.2);}
	.pc-browser-bar .pc-dot{width:8px;height:8px;border-radius:50%;background:rgba(255,255,255,.25);}
	.pc-browser-url{background:rgba(255,255,255,.08);border-radius:6px;padding:3px 10px;font-size:11px;color:var(--on-navy);margin-left:8px;}
	.pc-browser-body{padding:18px 20px 24px;}
	.pc-browser-body h4{color:#fff;font-size:17px;margin:4px 0 10px;font-family:'Space Grotesk',sans-serif;}
	.pc-browser-tags{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px;}
	.pc-browser-tags span{font-size:11px;background:var(--navy);border:1px solid var(--navy-line);color:var(--on-navy);border-radius:999px;padding:3px 9px;}
	.pc-browser-line{height:9px;background:rgba(255,255,255,.12);border-radius:5px;margin-bottom:8px;}
	.pc-hero-coverage-wrap{position:relative;}

	/* Video */
	.pc-video-wrap{max-width:1040px;margin:56px auto 0;}
	.pc-video{aspect-ratio:16/9;border-radius:24px;background:var(--navy-2) linear-gradient(135deg,var(--navy-2),var(--navy));position:relative;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:16px;cursor:pointer;border:none;width:100%;overflow:hidden;padding:20px;}
	.pc-video-play{width:76px;height:76px;border-radius:50%;background:var(--lime);display:flex;align-items:center;justify-content:center;flex-shrink:0;}
	.pc-video-caption{color:#fff;font-weight:600;text-align:center;}
	.pc-video-nametag{position:absolute;bottom:14px;left:14px;background:rgba(0,0,0,.4);color:#fff;font-size:12px;padding:5px 11px;border-radius:8px;}
	@media (max-width:480px){ .pc-video-nametag{position:static;margin-top:4px;background:none;padding:0;color:var(--on-navy);} }
	.pc-video-placeholder-msg{color:var(--on-navy);font-size:14px;padding:24px;text-align:center;}
	.pc-video-pad{height:40px;}

	/* How it works */
	.pc-steps{display:grid;grid-template-columns:repeat(3,1fr);gap:24px;margin-top:40px;}
	@media (max-width:880px){ .pc-steps{grid-template-columns:1fr;} }
	.pc-step-card{border:1px solid var(--line);border-radius:18px;padding:28px;background:#fff;}
	/* How It Works detail page: text+screenshot side by side on desktop, stacked on mobile —
	   was a bare inline grid-template-columns:1fr 1fr with no breakpoint, so it stayed 2
	   cramped columns all the way down to 375px. */
	.pc-step-detail{display:grid;grid-template-columns:1fr 1fr;gap:28px;align-items:center;}
	@media (max-width:640px){ .pc-step-detail{grid-template-columns:1fr;} }
	.pc-step-card.pc-navy{background:var(--navy);border-color:var(--navy);color:var(--on-navy);}
	.pc-step-card.pc-navy h3{color:#fff;}
	.pc-step-num{font-family:'Space Grotesk',sans-serif;font-weight:700;font-size:14px;color:var(--lime-ink);margin-bottom:10px;}
	.pc-step-card.pc-navy .pc-step-num{color:var(--lime);}
	.pc-step-card p{color:var(--ink-2);margin-top:8px;font-size:15px;}
	.pc-step-card.pc-navy p{color:var(--on-navy);}

	/* Demos */
	.pc-mist{background:var(--mist);}
	.pc-section-head{display:flex;justify-content:space-between;align-items:flex-end;flex-wrap:wrap;gap:20px;margin-bottom:12px;}
	.pc-demo-cards{display:grid;grid-template-columns:repeat(3,1fr);gap:22px;margin-top:36px;}
	@media (max-width:880px){ .pc-demo-cards{grid-template-columns:1fr;} }
	.pc-demo-card{background:#fff;border:1px solid var(--line);border-radius:16px;overflow:hidden;}
	.pc-demo-shot{aspect-ratio:16/10;background:repeating-linear-gradient(135deg,var(--mist),var(--mist) 10px,#e8edf5 10px,#e8edf5 20px);display:flex;align-items:center;justify-content:center;color:var(--muted);font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.04em;overflow:hidden;}
	.pc-demo-shot-real{background:#fff;}
	/* contain, not cover — uploaded screenshots are arbitrary-aspect-ratio photos (one real example
	   is a 1130x80 footer-strip crop, nowhere near this box's 16:10), and cover was blowing that one
	   up to fill the box, cropping away everything but a sliver of the phone number it exists to show. */
	.pc-demo-shot-real img{width:100%;height:100%;object-fit:contain;object-position:center;display:block;}
	.pc-demo-label{font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:var(--lime-ink);padding:14px 16px 0;}
	.pc-demo-card h4{font-size:16px;padding:6px 16px 0;font-family:'Space Grotesk',sans-serif;}
	.pc-demo-card p{font-size:13.5px;color:var(--ink-2);padding:8px 16px 18px;}

	/* Partner program */
	.pc-navy-section{background:var(--navy);color:var(--on-navy);}
	.pc-partner-grid{display:grid;grid-template-columns:1fr 1fr;gap:56px;align-items:start;margin-top:40px;}
	@media (max-width:980px){ .pc-partner-grid{grid-template-columns:1fr;} }
	.pc-partner-grid h2{color:#fff;}
	.pc-partner-grid h2 .pc-lime{color:var(--lime);}
	.pc-bullets{list-style:none;margin:26px 0 0;padding:0;display:flex;flex-direction:column;gap:16px;}
	.pc-bullets li{display:flex;gap:12px;font-size:15px;}
	.pc-bullets .plus{color:var(--lime);font-weight:700;flex-shrink:0;}
	.pc-bullets strong{color:#fff;}

	.pc-calc-card{background:#fff;color:var(--ink);border-radius:22px;padding:26px;}
	.pc-calc-top{display:flex;justify-content:space-between;align-items:center;font-size:13px;color:var(--muted);font-weight:600;margin-bottom:16px;}
	.pc-calc-label{font-size:12px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;margin:16px 0 8px;}
	.pc-calc-label:first-of-type{margin-top:0;}
	.pc-pill-group{display:flex;gap:8px;flex-wrap:wrap;}
	.pc-pill-btn{border:1.5px solid var(--line);background:#fff;border-radius:999px;padding:8px 14px;font-size:13px;font-weight:600;cursor:pointer;color:var(--ink);}
	.pc-pill-btn[aria-pressed="true"]{background:var(--navy);border-color:var(--navy);color:#fff;}
	.pc-calc-slider-row{display:flex;align-items:center;gap:12px;margin-top:4px;}
	.pc-calc-slider-row input[type=range]{flex:1;}
	.pc-calc-slider-val{font-weight:700;font-size:14px;min-width:28px;text-align:right;}
	.pc-calc-outputs{margin-top:20px;border-top:1px solid var(--line);padding-top:18px;display:flex;flex-direction:column;gap:10px;}
	.pc-calc-row{display:flex;justify-content:space-between;font-size:14px;color:var(--ink-2);}
	.pc-calc-row strong{color:var(--ink);}
	.pc-calc-profit{background:var(--lime-soft);border-radius:12px;padding:14px 16px;margin-top:6px;}
	.pc-calc-profit .pc-calc-row{color:var(--ink);}
	.pc-calc-profit strong{font-size:20px;color:var(--lime-ink);}
	.pc-calc-yearly{font-size:13px;color:var(--muted);margin-top:4px;}

	/* Industries */
	.pc-pill-links{display:flex;flex-wrap:wrap;gap:10px;margin-top:36px;}
	.pc-industry-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:20px;}
	@media (max-width:880px){ .pc-industry-grid{grid-template-columns:1fr 1fr;} }
	@media (max-width:560px){ .pc-industry-grid{grid-template-columns:1fr;} }
	.pc-industry-card{display:block;border:1px solid var(--line);border-radius:16px;padding:22px;text-decoration:none;color:var(--ink);background:#fff;}
	.pc-industry-card h3{font-size:18px;}
	.pc-industry-card p{font-size:14px;color:var(--ink-2);margin-top:8px;}
	.pc-industry-link{display:inline-block;margin-top:12px;font-size:13px;font-weight:700;color:var(--lime-ink);}
	.pc-industry-card-soon{opacity:.6;}
	.pc-industry-soon{display:inline-block;margin-top:12px;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.03em;color:var(--muted);}
	.pc-ind-pill{display:inline-block;padding:10px 18px;border-radius:999px;font-size:14px;font-weight:600;text-decoration:none;border:1.5px solid var(--line);color:var(--ink);}
	.pc-ind-pill.featured{background:var(--navy);color:#fff;border-color:var(--navy);}
	.pc-ind-pill.dashed{border-style:dashed;color:var(--muted);}

	/* Quality */
	.pc-lime-soft{background:var(--lime-soft);}
	.pc-quality-cards{display:grid;grid-template-columns:repeat(4,1fr);gap:18px;margin-top:32px;}
	@media (max-width:980px){ .pc-quality-cards{grid-template-columns:1fr 1fr;} }
	@media (max-width:560px){ .pc-quality-cards{grid-template-columns:1fr;} }
	/* 3-column variant (billing/demo-booking/niche-page info cards) — was being forced via an
	   inline grid-template-columns that overrode the responsive rules above and clipped the third
	   card off-screen on mobile. */
	.pc-quality-cards-3{grid-template-columns:repeat(3,1fr);}
	@media (max-width:980px){ .pc-quality-cards-3{grid-template-columns:1fr 1fr;} }
	@media (max-width:560px){ .pc-quality-cards-3{grid-template-columns:1fr;} }
	.pc-quality-card{background:#fff;border-radius:16px;padding:22px;}
	.pc-quality-card h3{font-size:17px;}
	.pc-quality-card p{font-size:14px;color:var(--ink-2);margin-top:8px;}

	/* Pricing */
	.pc-price-cards{display:grid;grid-template-columns:repeat(3,1fr);gap:22px;margin-top:36px;align-items:stretch;}
	@media (max-width:880px){ .pc-price-cards{grid-template-columns:1fr;} }
	.pc-pricing-tabs{display:inline-flex;gap:6px;background:var(--mist);border-radius:999px;padding:4px;}
	.pc-table{width:100%;border-collapse:collapse;font-size:14px;}
	.pc-table th,.pc-table td{text-align:left;padding:12px 14px;border-bottom:1px solid var(--line);}
	.pc-table th{font-size:12px;text-transform:uppercase;letter-spacing:.03em;color:var(--muted);font-weight:700;}
	@media (max-width:640px){ .pc-table{font-size:13px;} .pc-table th,.pc-table td{padding:9px 8px;} }
	.pc-price-card{border:1px solid var(--line);border-radius:20px;padding:28px;position:relative;background:#fff;}
	.pc-price-card.pc-featured{background:var(--navy);color:#fff;border-color:var(--navy);}
	.pc-price-card.pc-featured .pc-price-sub{color:var(--on-navy);}
	.pc-popular-badge{position:absolute;top:-13px;left:50%;transform:translateX(-50%);background:var(--lime);color:var(--navy);font-size:11px;font-weight:700;padding:4px 12px;border-radius:999px;}
	.pc-price-amt{font-family:'Space Grotesk',sans-serif;font-weight:700;font-size:34px;margin-top:10px;}
	.pc-price-amt span{font-size:15px;font-weight:500;}
	.pc-price-sub{font-size:14px;color:var(--ink-2);margin-top:6px;}
	.pc-price-card .pc-btn{width:100%;margin-top:20px;}

	/* FAQ */
	.pc-faq{max-width:780px;margin:36px auto 0;}
	.pc-faq details{border-bottom:1px solid var(--line);padding:18px 0;}
	.pc-faq summary{cursor:pointer;font-weight:600;font-size:16px;list-style:none;display:flex;justify-content:space-between;}
	.pc-faq summary::-webkit-details-marker{display:none;}
	.pc-faq summary::after{content:"+";font-size:20px;color:var(--muted);}
	.pc-faq details[open] summary::after{content:"\2212";}
	.pc-faq p{margin-top:12px;color:var(--ink-2);font-size:15px;}

	/* Final CTA */
	.pc-final-cta{text-align:center;}
	.pc-final-cta h2{color:#fff;max-width:780px;margin:0 auto;}
	.pc-final-cta p{color:var(--on-navy);margin-top:12px;font-size:18px;}
	.pc-final-cta .pc-btn-row{display:flex;gap:14px;justify-content:center;margin-top:28px;flex-wrap:wrap;}

	/* Footer */
	.pc-footer{background:var(--navy);padding:48px 0;}
	.pc-footer-inner{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:18px;}
	.pc-footer-links{display:flex;flex-wrap:wrap;gap:22px;}
	.pc-footer-links a{color:var(--on-navy);text-decoration:none;font-size:14px;}
	.pc-footer-copy{color:var(--muted);font-size:13px;}

	/* Modal */
	.pc-modal-backdrop{position:fixed;inset:0;background:rgba(11,27,51,.55);display:none;align-items:center;justify-content:center;z-index:100;padding:20px;}
	.pc-modal-backdrop.open{display:flex;}
	.pc-modal{background:#fff;border-radius:18px;padding:28px;max-width:420px;width:100%;}
	.pc-modal h3{font-size:19px;}
	.pc-modal p{font-size:14px;color:var(--ink-2);margin-top:8px;}
	.pc-modal input{width:100%;margin-top:16px;border:1px solid var(--line);border-radius:10px;padding:12px 14px;font-size:15px;font-family:inherit;}
	.pc-apply-form input,.pc-apply-form select,.pc-apply-form textarea{width:100%;border:1px solid var(--line);border-radius:10px;padding:12px 14px;font-size:15px;font-family:inherit;color:var(--ink);box-sizing:border-box;}
	.pc-apply-form textarea{resize:vertical;}
	.pc-modal .pc-btn{width:100%;margin-top:14px;}
	.pc-modal-close{position:absolute;top:14px;right:16px;background:none;border:none;font-size:20px;cursor:pointer;color:var(--muted);}
	.pc-modal{position:relative;}
	.pc-form-msg{font-size:13px;margin-top:10px;color:var(--lime-ink);}
	.pc-form-msg.err{color:#b3261e;}
	.pc-hp-field{position:absolute;left:-9999px;}
	</style>
	<?php
}, 15 );

add_action( 'wp_body_open', function () {
	?><script>document.body.classList.add('pc-body');</script><?php
}, 1 );

/** Loaded only once a Turnstile widget actually exists (wpb_turnstile_site_key set) — an idle script tag on every page otherwise isn't worth the request. */
add_action( 'wp_head', function () {
	if ( get_option( 'wpb_turnstile_site_key', '' ) === '' ) {
		return;
	}
	?><script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script><?php
}, 15 );

function pc_print_js(): void {
	?>
	<div class="pc-modal-backdrop" id="pc-preview-modal">
		<div class="pc-modal" role="dialog" aria-modal="true" aria-labelledby="pc-modal-title">
			<button type="button" class="pc-modal-close" id="pc-modal-close" aria-label="Close">&times;</button>
			<h3 id="pc-modal-title">Where should we send your preview?</h3>
			<p>We'll email you a link once it's ready.</p>
			<form id="pc-modal-form">
				<input type="email" id="pc-modal-email" placeholder="you@agency.com" required>
				<input type="text" class="pc-hp-field" tabindex="-1" autocomplete="off" name="website" id="pc-hp">
				<?php $turnstileSiteKey = get_option( 'wpb_turnstile_site_key', '' ); ?>
				<?php if ( $turnstileSiteKey !== '' ) : ?>
				<div class="cf-turnstile" data-sitekey="<?php echo esc_attr( $turnstileSiteKey ); ?>"></div>
				<?php endif; ?>
				<button type="submit" class="pc-btn pc-btn-lime">Send my preview</button>
				<div class="pc-form-msg" id="pc-modal-msg" role="status"></div>
			</form>
		</div>
	</div>
	<script>
	(function(){
		"use strict";
		var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

		/* Pricing page Direct/Agency tabs */
		document.querySelectorAll('.pc-pricing-tabs').forEach(function (tabs) {
			var buttons = tabs.querySelectorAll('[data-pricing-tab]');
			buttons.forEach(function (btn) {
				btn.addEventListener('click', function () {
					var key = btn.getAttribute('data-pricing-tab');
					buttons.forEach(function (b) { b.setAttribute('aria-pressed', b === btn ? 'true' : 'false'); });
					document.querySelectorAll('[data-pricing-panel]').forEach(function (p) {
						p.hidden = p.getAttribute('data-pricing-panel') !== key;
					});
				});
			});
		});

		/* Mobile menu */
		var burger = document.getElementById('pc-hamburger'), menu = document.getElementById('pc-mobile-menu');
		if (burger && menu) {
			burger.addEventListener('click', function(){
				var open = menu.classList.toggle('open');
				burger.setAttribute('aria-expanded', open ? 'true' : 'false');
				menu.hidden = !open;
			});
		}

		/* Coverage chip animation — once, on first view, skipped under reduced motion */
		var coverage = document.querySelector('.pc-coverage-card');
		if (coverage && !reduceMotion && 'IntersectionObserver' in window) {
			var io = new IntersectionObserver(function(entries){
				entries.forEach(function(e){
					if (e.isIntersecting) {
						var chips = coverage.querySelectorAll('.pc-chip-lime');
						chips.forEach(function(c, i){ c.style.animationDelay = (i * 80) + 'ms'; });
						coverage.classList.add('pc-animate');
						io.disconnect();
					}
				});
			}, { threshold: 0.3 });
			io.observe(coverage);
		} else if (coverage) {
			coverage.classList.add('pc-animate');
		}

		/* Founder video — loads only on click */
		var video = document.getElementById('pc-video');
		if (video) {
			video.addEventListener('click', function(){
				video.innerHTML = '<div class="pc-video-placeholder-msg">[FOUNDER VIDEO] &mdash; add the real 60&ndash;90s video file to enable playback here.</div>';
				if (window.dataLayer) window.dataLayer.push({event:'video_play'});
			});
		}

		/* Margin calculator */
		var PLANS = {25:300, 50:600, 100:1200};
		var calc = document.getElementById('pc-calculator');
		if (calc) {
			var pageBtns = calc.querySelectorAll('[data-plan-pages]');
			var markupBtns = calc.querySelectorAll('[data-markup]');
			var slider = document.getElementById('pc-calc-clients');
			var sliderVal = document.getElementById('pc-calc-clients-val');
			var state = { pages: 25, markup: 2.5, clients: 10 };

			function fmt(n){ return '$' + Math.round(n).toLocaleString('en-US'); }

			function render(){
				var cost = PLANS[state.pages];
				var charge = cost * state.markup;
				var profit = charge - cost;
				var monthly = profit * state.clients;
				var yearly = monthly * 12;
				document.getElementById('pc-calc-cost').textContent = fmt(cost);
				document.getElementById('pc-calc-charge').textContent = fmt(charge);
				document.getElementById('pc-calc-monthly').textContent = fmt(monthly);
				document.getElementById('pc-calc-yearly').textContent = fmt(yearly);
				markupBtns.forEach(function(b){
					var m = parseFloat(b.getAttribute('data-markup'));
					b.querySelector('.pc-markup-amt').textContent = m + '× = ' + fmt(PLANS[state.pages] * m);
				});
				if (window.dataLayer) window.dataLayer.push({event:'calculator_used'});
			}

			pageBtns.forEach(function(b){
				b.addEventListener('click', function(){
					pageBtns.forEach(function(x){ x.setAttribute('aria-pressed','false'); });
					b.setAttribute('aria-pressed','true');
					state.pages = parseInt(b.getAttribute('data-plan-pages'), 10);
					render();
				});
			});
			markupBtns.forEach(function(b){
				b.addEventListener('click', function(){
					markupBtns.forEach(function(x){ x.setAttribute('aria-pressed','false'); });
					b.setAttribute('aria-pressed','true');
					state.markup = parseFloat(b.getAttribute('data-markup'));
					render();
				});
			});
			if (slider) {
				slider.addEventListener('input', function(){
					state.clients = parseInt(slider.value, 10);
					sliderVal.textContent = state.clients;
					render();
				});
			}
			render();
		}

		/* Hero "Preview my page" form -> modal -> leads endpoint */
		var heroForm = document.getElementById('pc-hero-form');
		var modal = document.getElementById('pc-preview-modal');
		var modalForm = document.getElementById('pc-modal-form');
		var modalClose = document.getElementById('pc-modal-close');
		var pendingLead = null;

		function openModal(){ modal.classList.add('open'); document.getElementById('pc-modal-email').focus(); }
		function closeModal(){ modal.classList.remove('open'); }
		if (modalClose) modalClose.addEventListener('click', closeModal);
		if (modal) modal.addEventListener('click', function(e){ if (e.target === modal) closeModal(); });

		if (heroForm) {
			heroForm.addEventListener('submit', function(e){
				e.preventDefault();
				var service = document.getElementById('pc-hero-service').value.trim();
				var city = document.getElementById('pc-hero-city').value.trim();
				var msg = document.getElementById('pc-hero-msg');
				msg.className = 'pc-form-msg';
				if (!service || !city) { msg.textContent = 'Enter a service and a city.'; msg.className = 'pc-form-msg err'; return; }
				if (!/,\s*[A-Za-z]{2}$/.test(city)) { msg.textContent = 'City should look like "Keller, TX".'; msg.className = 'pc-form-msg err'; return; }
				pendingLead = { service: service, city: city, source: 'hero-preview' };
				if (window.dataLayer) window.dataLayer.push({event:'preview_submit'});
				openModal();
			});
		}

		if (modalForm) {
			modalForm.addEventListener('submit', function(e){
				e.preventDefault();
				var email = document.getElementById('pc-modal-email').value.trim();
				var hp = document.getElementById('pc-hp').value;
				var out = document.getElementById('pc-modal-msg');
				var tsField = modalForm.querySelector('[name="cf-turnstile-response"]');
				var lead = Object.assign({}, pendingLead || {}, { email: email, website: hp, 'cf-turnstile-response': tsField ? tsField.value : '' });
				fetch('<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>?action=pluscities_lead', {
					method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify(lead)
				}).then(function(r){ return r.json(); }).then(function(res){
					if (res && res.success) {
						out.textContent = 'Got it — check your inbox shortly.';
						out.className = 'pc-form-msg';
						modalForm.reset();
						setTimeout(closeModal, 1400);
					} else {
						out.textContent = 'Something went wrong — try again.';
						out.className = 'pc-form-msg err';
					}
				}).catch(function(){
					out.textContent = 'Something went wrong — try again.';
					out.className = 'pc-form-msg err';
				});
			});
		}

		/* Generic lead form handler — any <form data-pc-lead-form="source-name"> auto-wires up:
		   serialize its fields, post to the leads endpoint, show an inline message, no reload.
		   Used by the Agencies apply form; written generically so a future form (e.g. the
		   coverage audit) needs no new JS, just the data attribute. An optional
		   data-pc-success-msg overrides the default confirmation text (e.g. /audit's
		   turnaround-specific line) — forms that don't set it keep the plain default. */
		document.querySelectorAll('form[data-pc-lead-form]').forEach(function (form) {
			var msgEl = form.querySelector('.pc-form-msg');
			var successMsg = form.getAttribute('data-pc-success-msg') || 'Got it — we\'ll be in touch shortly.';
			form.addEventListener('submit', function (e) {
				e.preventDefault();
				var data = { source: form.getAttribute('data-pc-lead-form') };
				new FormData(form).forEach(function (value, key) { data[key] = value; });
				if (msgEl) { msgEl.textContent = ''; msgEl.className = 'pc-form-msg'; }
				fetch('<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>?action=pluscities_lead', {
					method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify(data)
				}).then(function (r) { return r.json(); }).then(function (res) {
					if (res && res.success) {
						if (msgEl) { msgEl.textContent = successMsg; msgEl.className = 'pc-form-msg'; }
						form.reset();
						if (window.dataLayer) window.dataLayer.push({ event: data.source + '_submit' });
					} else if (msgEl) {
						msgEl.textContent = 'Something went wrong — try again.';
						msgEl.className = 'pc-form-msg err';
					}
				}).catch(function () {
					if (msgEl) { msgEl.textContent = 'Something went wrong — try again.'; msgEl.className = 'pc-form-msg err'; }
				});
			});
		});
	})();
	</script>
	<?php
}

/**
 * Verifies a Turnstile token against Cloudflare's siteverify endpoint.
 * Returns true (skip verification) if no secret key is configured yet —
 * same "blank = not active yet" convention as every other PlusCities
 * credential, so forms keep working exactly as before until a real key is
 * set. Once a secret key IS set, a missing/invalid token is a hard reject,
 * not a soft warning — this only ever runs for actual form submissions.
 */
function pc_verify_turnstile( string $token ): bool {
	$secret = get_option( 'wpb_turnstile_secret_key', '' );
	if ( $secret === '' ) {
		return true;
	}
	if ( $token === '' ) {
		return false;
	}
	$response = wp_remote_post( 'https://challenges.cloudflare.com/turnstile/v0/siteverify', [
		'timeout' => 10,
		'body'    => [ 'secret' => $secret, 'response' => $token ],
	] );
	if ( is_wp_error( $response ) ) {
		return false;
	}
	$body = json_decode( wp_remote_retrieve_body( $response ), true );
	return ! empty( $body['success'] );
}

/**
 * Leads endpoint — Phase 1 per the spec: capture + email Scott directly from
 * WordPress. No cross-system /api/leads call yet (that app doesn't exist),
 * and no "saved in the app database" half until it does. Honeypot plus
 * Turnstile (once a key is configured — see pc_verify_turnstile()).
 *
 * Generic by design: every form posts whatever fields it collects plus a
 * `source` — the email just lists them, so a new form (apply, audit,
 * contact) needs no change here, only a new `data-pc-lead-form` on its own
 * markup. `email` is the one field every form is expected to supply.
 */
function pc_handle_lead(): void {
	$raw = json_decode( file_get_contents( 'php://input' ), true ) ?: [];
	if ( ! empty( $raw['website'] ) ) { // honeypot tripped
		wp_send_json_success( [ 'ok' => true ] ); // pretend success, drop silently
	}
	if ( ! pc_verify_turnstile( (string) ( $raw['cf-turnstile-response'] ?? '' ) ) ) {
		wp_send_json_error( [ 'message' => 'verification failed' ], 400 );
	}
	$email  = sanitize_email( $raw['email'] ?? '' );
	$source = sanitize_text_field( $raw['source'] ?? 'unknown' );

	if ( ! is_email( $email ) ) {
		wp_send_json_error( [ 'message' => 'invalid email' ], 400 );
	}

	$lines = [];
	foreach ( $raw as $key => $value ) {
		if ( in_array( $key, [ 'website', 'source' ], true ) || ! is_scalar( $value ) ) {
			continue;
		}
		$lines[] = ucfirst( str_replace( '_', ' ', sanitize_key( $key ) ) ) . ': ' . sanitize_textarea_field( (string) $value );
	}

	$s    = pc_settings();
	$to   = $s['lead_email'] !== '' ? $s['lead_email'] : get_option( 'admin_email' );
	$body = "New PlusCities lead (" . $source . ")\n\n" . implode( "\n", $lines ) . "\n";
	wp_mail( $to, 'New PlusCities lead (' . $source . ')', $body );

	wp_send_json_success( [ 'ok' => true ] );
}
add_action( 'wp_ajax_pluscities_lead', 'pc_handle_lead' );
add_action( 'wp_ajax_nopriv_pluscities_lead', 'pc_handle_lead' );
