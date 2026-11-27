<?php
/**
 * Plugin Name: WP Builder Site Chrome
 * Description: Renders the simple header, footer, color theme, and basic SEO tags (title, meta description, LocalBusiness schema) for sites created by the WP Builder tool. Reads everything from wp_options set at creation time — no page-builder plugin, no theme customizer, works on any theme since it hooks wp_body_open/wp_footer/wp_head directly.
 * Author: Site Factory
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** One place to read every WP Builder setting, with sane fallbacks. */
function wpb_settings(): array {
	return [
		'business_name' => get_option( 'wpb_business_name', get_bloginfo( 'name' ) ),
		'niche'         => get_option( 'wpb_niche', 'service' ),
		'tagline'       => get_option( 'wpb_tagline', '' ),
		'phone'         => get_option( 'wpb_phone', '' ),
		'email'         => get_option( 'wpb_email', '' ),
		'city'          => get_option( 'wpb_city', '' ),
		'state'         => get_option( 'wpb_state', '' ),
		'services'      => get_option( 'wpb_services', [] ),
		'primary_color' => get_option( 'wpb_primary_color', '#1a2b49' ),
		'accent_color'  => get_option( 'wpb_accent_color', '#2563eb' ),
	];
}

/** The 5 standard pages, in nav order — every site built by this tool uses these exact slugs. */
function wpb_nav_pages(): array {
	return [
		'home'         => 'Home',
		'about'        => 'About',
		'services'     => 'Services',
		'testimonials' => 'Testimonials',
		'contact'      => 'Contact',
	];
}

function wpb_page_url( string $slug ): string {
	if ( $slug === 'home' ) {
		return home_url( '/' );
	}
	$page = get_page_by_path( $slug );
	return $page ? get_permalink( $page ) : home_url( '/' . $slug . '/' );
}

add_action( 'after_setup_theme', function () {
	add_theme_support( 'title-tag' );
} );

/** Colors + base typography/layout — one small stylesheet, no separate file to manage. */
add_action( 'wp_head', function () {
	$s = wpb_settings();
	?>
	<style>
		:root { --wpb-primary: <?php echo esc_html( $s['primary_color'] ); ?>; --wpb-accent: <?php echo esc_html( $s['accent_color'] ); ?>; }
		body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Helvetica, Arial, sans-serif; color: #1f2937; line-height: 1.6; margin: 0; }
		.wpb-wrap { max-width: 1100px; margin: 0 auto; padding: 0 24px; }
		.wpb-header { display: flex; align-items: center; justify-content: space-between; padding: 18px 24px; background: #fff; border-bottom: 1px solid #e5e7eb; }
		.wpb-logo { font-weight: 700; font-size: 1.15rem; color: #1f2937; text-decoration: none; }
		.wpb-nav a { color: #374151; text-decoration: none; margin-left: 22px; font-size: .92rem; }
		.wpb-nav a:hover { color: var(--wpb-accent); }
		.wpb-section { padding: 56px 24px; }
		.wpb-section-alt { background: #f8fafc; }
		.wpb-cols { display: flex; gap: 40px; align-items: center; max-width: 1100px; margin: 0 auto; flex-wrap: wrap; }
		.wpb-cols > div { flex: 1 1 360px; }
		.wpb-cols img { width: 100%; height: auto; border-radius: 8px; display: block; }
		.wpb-grid3 { display: flex; gap: 28px; max-width: 1100px; margin: 0 auto; flex-wrap: wrap; }
		.wpb-grid3 > div { flex: 1 1 280px; }
		.wpb-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 24px; flex: 1 1 280px; }
		.wpb-btn { display: inline-block; background: var(--wpb-accent); color: #fff !important; padding: 12px 26px; border-radius: 6px; text-decoration: none; font-weight: 600; }
		.wpb-cta-band { background: var(--wpb-accent); color: #fff; text-align: center; padding: 48px 24px; }
		.wpb-cta-band h2 { margin: 0 0 8px; }
		.wpb-cta-band a { color: #fff; }
		.wpb-avatar { width: 52px; height: 52px; border-radius: 50%; background: var(--wpb-accent); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 700; flex-shrink: 0; }
		.wpb-footer { background: var(--wpb-primary); color: rgba(255,255,255,.85); padding: 48px 24px 24px; }
		.wpb-footer h5 { color: #fff; margin: 0 0 12px; }
		.wpb-footer a { color: rgba(255,255,255,.85); text-decoration: none; }
		.wpb-footer-cols { display: flex; gap: 32px; max-width: 1100px; margin: 0 auto; flex-wrap: wrap; }
		.wpb-footer-cols > div { flex: 1 1 220px; }
		.wpb-footer-bottom { max-width: 1100px; margin: 32px auto 0; padding-top: 16px; border-top: 1px solid rgba(255,255,255,.15); font-size: .82rem; display: flex; flex-wrap: wrap; gap: 12px; justify-content: space-between; }
		@media (max-width: 640px) { .wpb-header { flex-direction: column; gap: 10px; } .wpb-nav a { margin: 0 10px; } }
		/* Hello Elementor's own header (site title) and page-title both duplicate what our hero block + wpb-header already show — hide both, ours is the only header/title that should render.
		   Astra (2026-11-26) additionally ships its own real Footer Builder output (.site-footer/#colophon) with its own
		   leftover customizer content — hide it too, ours is the only footer that should render. */
		.site-header, .page-header, .site-footer { display: none; }
	</style>
	<?php
}, 20 );

/**
 * Simple header: business name + nav to the 5 pages. Works on any theme since
 * it's not a template part. A profile with its own custom header ships a
 * second mu-plugin file (its "chrome_dir") defining wpb_custom_header() —
 * when that function exists, it wins and this generic one never runs. This is
 * the only local-business-specific chrome in this file; every other hook
 * below (title, meta description, schema, Settings page) is shared by every
 * profile regardless of which header/footer it uses.
 */
add_action( 'wp_body_open', function () {
	if ( function_exists( 'wpb_custom_header' ) ) {
		wpb_custom_header();
		return;
	}
	$s = wpb_settings();
	echo '<header class="wpb-header"><a class="wpb-logo" href="' . esc_url( home_url( '/' ) ) . '">' . esc_html( $s['business_name'] ) . '</a><nav class="wpb-nav">';
	foreach ( wpb_nav_pages() as $slug => $label ) {
		echo '<a href="' . esc_url( wpb_page_url( $slug ) ) . '">' . esc_html( $label ) . '</a>';
	}
	echo '</nav></header>';
} );

/** Simple 4-column footer, same pattern already proven on the restoration demo site. See the header hook above for how a profile overrides this. */
add_action( 'wp_footer', function () {
	if ( function_exists( 'wpb_custom_footer' ) ) {
		wpb_custom_footer();
		return;
	}
	$s = wpb_settings();
	$year = get_option( 'wpb_created_year', gmdate( 'Y' ) );
	?>
	<footer class="wpb-footer">
		<div class="wpb-footer-cols">
			<div>
				<h5><?php echo esc_html( $s['business_name'] ); ?></h5>
				<p><?php echo esc_html( $s['tagline'] ); ?></p>
			</div>
			<div>
				<h5>Services</h5>
				<?php foreach ( array_slice( (array) $s['services'], 0, 6 ) as $service ) : ?>
					<div><a href="<?php echo esc_url( wpb_page_url( 'services' ) ); ?>"><?php echo esc_html( $service ); ?></a></div>
				<?php endforeach; ?>
			</div>
			<div>
				<h5>Contact</h5>
				<p>
					<?php echo esc_html( $s['city'] . ', ' . $s['state'] ); ?><br>
					<?php echo esc_html( $s['phone'] ); ?><br>
					<?php echo esc_html( $s['email'] ); ?>
				</p>
			</div>
			<div>
				<h5>Service Area</h5>
				<p><?php echo esc_html( $s['city'] . ' and the surrounding area' ); ?></p>
			</div>
		</div>
		<div class="wpb-footer-bottom">
			<span>&copy; <?php echo esc_html( $year ); ?> <?php echo esc_html( $s['business_name'] ); ?></span>
			<nav>
				<?php foreach ( wpb_nav_pages() as $slug => $label ) : ?>
					<a href="<?php echo esc_url( wpb_page_url( $slug ) ); ?>" style="margin-left:14px;"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>
		</div>
	</footer>
	<?php
} );

/**
 * Simple Settings page (Settings → Site Settings) so the business name, phone,
 * tagline, colors, and services that drive the header/footer/SEO can be edited
 * right here in this site's own wp-admin — no need to go back to the tool that
 * created the site.
 */
add_action( 'admin_menu', function () {
	add_options_page( 'Site Settings', 'Site Settings', 'manage_options', 'wpb-site-settings', 'wpb_render_settings_page' );
} );

function wpb_settings_fields(): array {
	return [
		'wpb_business_name' => 'Business Name',
		'wpb_tagline'       => 'Tagline',
		'wpb_phone'         => 'Phone',
		'wpb_email'         => 'Email',
		'wpb_city'          => 'City',
		'wpb_state'         => 'State',
		'wpb_primary_color' => 'Primary Color (footer background)',
		'wpb_accent_color'  => 'Accent Color (buttons, links)',
	];
}

function wpb_render_settings_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( isset( $_POST['wpb_settings_nonce'] ) && wp_verify_nonce( $_POST['wpb_settings_nonce'], 'wpb_save_settings' ) ) {
		foreach ( wpb_settings_fields() as $key => $label ) {
			if ( isset( $_POST[ $key ] ) ) {
				update_option( $key, sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) );
			}
		}
		if ( isset( $_POST['wpb_services'] ) ) {
			$services = array_values( array_filter( array_map( 'trim', explode( "\n", wp_unslash( $_POST['wpb_services'] ) ) ) ) );
			update_option( 'wpb_services', $services );
		}
		echo '<div class="notice notice-success"><p>Saved.</p></div>';
	}
	$s = wpb_settings();
	?>
	<div class="wrap">
		<h1>Site Settings</h1>
		<p>These fields drive the header, footer, and SEO tags on every page — editing them here updates the whole site at once.</p>
		<form method="post">
			<?php wp_nonce_field( 'wpb_save_settings', 'wpb_settings_nonce' ); ?>
			<table class="form-table">
				<?php foreach ( wpb_settings_fields() as $key => $label ) : ?>
					<tr>
						<th><label for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
						<td>
							<?php if ( strpos( $key, 'color' ) !== false ) : ?>
								<input type="color" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( get_option( $key, '' ) ); ?>">
							<?php else : ?>
								<input type="text" class="regular-text" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( get_option( $key, '' ) ); ?>">
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				<tr>
					<th><label for="wpb_services">Services (one per line)</label></th>
					<td><textarea name="wpb_services" id="wpb_services" rows="6" class="large-text"><?php echo esc_textarea( implode( "\n", (array) $s['services'] ) ); ?></textarea></td>
				</tr>
			</table>
			<?php submit_button( 'Save Settings' ); ?>
		</form>
	</div>
	<?php
}

/**
 * Our own per-page title, saved at creation time — WordPress's default
 * title-tag logic shows just the site name on the static front page
 * regardless of that page's post_title, which left every WP Builder site
 * showing the same title on every page. This overrides it everywhere a
 * custom title was actually saved, front page included.
 */
add_filter( 'pre_get_document_title', function ( $title ) {
	if ( is_singular() ) {
		$custom = get_post_meta( get_queried_object_id(), '_wpb_seo_title', true );
		if ( $custom ) {
			return $custom;
		}
	}
	return $title;
}, 20 );

/**
 * Builds the site's JSON-LD schema object. Which shape to build is profile
 * data (the `wpb_schema_type` option, set once at creation time from the
 * profile's own site_options — see wpb_lb_site_options() for local-business),
 * not hardcoded here. A future profile just needs a new case, not a new
 * mechanism: adding e.g. `SoftwareApplication` for a SaaS marketing site
 * means one more branch below, nothing structural.
 */
function wpb_build_schema( array $s ): array {
	$type = get_option( 'wpb_schema_type', 'LocalBusiness' );
	switch ( $type ) {
		case 'SoftwareApplication':
			return [
				'@context'      => 'https://schema.org',
				'@type'         => 'SoftwareApplication',
				'name'          => $s['business_name'] ?: get_bloginfo( 'name' ),
				'applicationCategory' => 'BusinessApplication',
				'url'           => home_url( '/' ),
			];
		case 'LocalBusiness':
		default:
			return [
				'@context'  => 'https://schema.org',
				'@type'     => 'LocalBusiness',
				'name'      => $s['business_name'],
				'telephone' => $s['phone'],
				'email'     => $s['email'],
				'url'       => home_url( '/' ),
				'address'   => [
					'@type'           => 'PostalAddress',
					'addressLocality' => $s['city'],
					'addressRegion'   => $s['state'],
				],
			];
	}
}

/**
 * Meta description, canonical URL, Open Graph/Twitter tags, and schema — no
 * SEO plugin needed. Entirely generic (reads whatever title/description was
 * saved per-page plus a couple of site-wide options) so every profile gets
 * this for free; nothing here is local-business-specific.
 */
add_action( 'wp_head', function () {
	if ( ! is_singular() ) {
		return;
	}
	global $post;
	$s = wpb_settings();

	$desc = get_post_meta( $post->ID, '_wpb_meta_description', true );
	if ( $desc ) {
		echo '<meta name="description" content="' . esc_attr( $desc ) . '">' . "\n";
	}

	// Canonical URL is NOT added here — WordPress core already outputs one via
	// its own rel_canonical() hook on wp_head (confirmed live: adding a second
	// one here produced a visible duplicate <link rel="canonical"> tag).
	$permalink = get_permalink( $post->ID );

	$ogTitle = get_post_meta( $post->ID, '_wpb_seo_title', true ) ?: get_the_title( $post->ID );
	$ogImage = has_post_thumbnail( $post->ID )
		? get_the_post_thumbnail_url( $post->ID, 'large' )
		: get_option( 'wpb_og_image', '' );

	echo '<meta property="og:type" content="website">' . "\n";
	echo '<meta property="og:site_name" content="' . esc_attr( get_bloginfo( 'name' ) ) . '">' . "\n";
	echo '<meta property="og:title" content="' . esc_attr( $ogTitle ) . '">' . "\n";
	if ( $desc ) {
		echo '<meta property="og:description" content="' . esc_attr( $desc ) . '">' . "\n";
	}
	echo '<meta property="og:url" content="' . esc_url( $permalink ) . '">' . "\n";
	if ( $ogImage ) {
		echo '<meta property="og:image" content="' . esc_url( $ogImage ) . '">' . "\n";
	}
	echo '<meta name="twitter:card" content="' . ( $ogImage ? 'summary_large_image' : 'summary' ) . '">' . "\n";

	$schema = wpb_build_schema( $s );
	echo '<script type="application/ld+json">' . wp_json_encode( $schema ) . '</script>' . "\n";
}, 25 );
