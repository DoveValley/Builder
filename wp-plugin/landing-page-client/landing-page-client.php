<?php
/**
 * Plugin Name: Landing Page Client
 * Description: Displays location/service landing pages delivered by the Landing Page Clients backend. One setting: paste in your API key.
 * Version: 0.1.0
 * Author: Site Factory
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Delivery server base URL — same for every client site, hardcoded here
// rather than a per-client setting. Temporary address while the delivery
// server lives on the admin-panel box; bumping this (and the plugin
// version) is the only change needed when it moves to a dedicated server.
define( 'LPC_DELIVERY_BASE', 'http://187.127.254.206:8098/api.php' );

require_once __DIR__ . '/includes/remote.php';
require_once __DIR__ . '/includes/renderer.php';
require_once __DIR__ . '/includes/rewrite.php';

register_activation_hook( __FILE__, 'lpc_activate' );
function lpc_activate() {
	add_option( 'lpc_api_key', '' );
	add_option( 'lpc_base_path', '/locations' );
	add_option( 'lpc_refresh_interval_minutes', 360 );
	add_option( 'lpc_support_phone', '' );
	add_option( 'lpc_support_message', '' );
	add_option( 'lpc_last_checkin', 0 );
	lpc_add_rewrite_rules();
	flush_rewrite_rules();
}

register_deactivation_hook( __FILE__, 'lpc_deactivate' );
function lpc_deactivate() {
	flush_rewrite_rules();
}

add_action( 'admin_menu', 'lpc_add_settings_page' );
function lpc_add_settings_page() {
	add_options_page(
		'Landing Page Client',
		'Landing Page Client',
		'manage_options',
		'landing-page-client',
		'lpc_render_settings_page'
	);
}

add_action( 'admin_init', 'lpc_register_settings' );
function lpc_register_settings() {
	register_setting( 'lpc_settings', 'lpc_api_key', [ 'sanitize_callback' => 'sanitize_text_field' ] );
}

function lpc_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	if ( isset( $_POST['lpc_refresh_now'] ) && check_admin_referer( 'lpc_refresh_now_action' ) ) {
		lpc_get_manifest( true );
		echo '<div class="notice notice-success"><p>Refreshed from the delivery server.</p></div>';
	}

	$api_key   = get_option( 'lpc_api_key', '' );
	$last      = (int) get_option( 'lpc_last_checkin', 0 );
	$interval  = (int) get_option( 'lpc_refresh_interval_minutes', 360 );
	$status    = 'Never Connected';
	if ( $last > 0 ) {
		$age_minutes = ( time() - $last ) / 60;
		$status      = $age_minutes <= ( $interval * 2 ) ? 'Connected' : 'Stale';
	}
	?>
	<div class="wrap">
		<h1>Landing Page Client</h1>

		<form method="post" action="options.php">
			<?php settings_fields( 'lpc_settings' ); ?>
			<table class="form-table">
				<tr>
					<th scope="row"><label for="lpc_api_key">API Key</label></th>
					<td><input type="text" id="lpc_api_key" name="lpc_api_key" value="<?php echo esc_attr( $api_key ); ?>" class="regular-text"></td>
				</tr>
				<tr>
					<th scope="row">Status</th>
					<td><?php echo esc_html( $status ); ?><?php if ( $last > 0 ) : ?> (last check-in <?php echo esc_html( human_time_diff( $last ) ); ?> ago)<?php endif; ?></td>
				</tr>
				<tr>
					<th scope="row">Locations path</th>
					<td><code><?php echo esc_html( get_option( 'lpc_base_path', '/locations' ) ); ?></code> — set from the admin panel, not here</td>
				</tr>
				<?php if ( get_option( 'lpc_support_phone' ) || get_option( 'lpc_support_message' ) ) : ?>
				<tr>
					<th scope="row">Support</th>
					<td>
						<?php echo esc_html( get_option( 'lpc_support_phone' ) ); ?><br>
						<?php echo esc_html( get_option( 'lpc_support_message' ) ); ?>
					</td>
				</tr>
				<?php endif; ?>
			</table>
			<?php submit_button( 'Save API Key' ); ?>
		</form>

		<form method="post">
			<?php wp_nonce_field( 'lpc_refresh_now_action' ); ?>
			<button type="submit" name="lpc_refresh_now" class="button">Refresh now</button>
		</form>
	</div>
	<?php
}
