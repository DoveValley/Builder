<?php
/**
 * Plugin Name: Landing Page Client
 * Description: Displays location/service landing pages delivered by the Landing Page Clients backend. One setting: paste in your API key.
 * Version: 0.4.24
 * Author: Site Factory
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Delivery server base URL — same for every client site, hardcoded here
// rather than a per-client setting. Now the real, dedicated delivery server
// (Box 1, a standalone component isolated from both the Hestia fleet it
// happens to share a physical machine with and this admin panel — see
// project_landing_page_clients_feature_design memory, 2026-11-15) — no
// longer the admin-panel box this constant originally pointed at.
define( 'LPC_DELIVERY_BASE', 'http://31.97.133.251:8098/api.php' );

require_once __DIR__ . '/includes/remote.php';
require_once __DIR__ . '/includes/image_cache.php';
require_once __DIR__ . '/includes/renderer.php';
require_once __DIR__ . '/includes/styles.php';
require_once __DIR__ . '/includes/rewrite.php';
require_once __DIR__ . '/includes/footer_link.php';

register_activation_hook( __FILE__, 'lpc_activate' );
function lpc_activate() {
	add_option( 'lpc_api_key', '' );
	add_option( 'lpc_base_path', '/locations' );
	add_option( 'lpc_refresh_interval_minutes', 360 );
	add_option( 'lpc_support_phone', '' );
	add_option( 'lpc_support_message', '' );
	add_option( 'lpc_nav_link_placement', 'footer' );
	add_option( 'lpc_nav_link_label', 'View Our Service Locations' );
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

function lpc_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	// One form, one nonce — both buttons below submit the SAME form, so
	// either one always saves whatever's currently in the API Key field
	// first. Previously this was two separate <form> elements (Save API Key
	// posting to options.php, Refresh Now posting to itself); a real
	// confirmed incident: pasting a new key in and clicking Refresh Now
	// without first clicking Save API Key silently left the OLD key in
	// effect, since a browser never submits one form's fields from a
	// different form's submit button. Merged so that trap can't happen again.
	if ( isset( $_POST['lpc_settings_nonce'] ) && check_admin_referer( 'lpc_settings_action', 'lpc_settings_nonce' ) ) {
		update_option( 'lpc_api_key', sanitize_text_field( $_POST['lpc_api_key'] ?? '' ) );
		if ( isset( $_POST['lpc_refresh_now'] ) ) {
			$manifest = lpc_get_manifest( true );
			lpc_purge_all_page_caches( $manifest );
			echo '<div class="notice notice-success"><p>Saved the API key and refreshed from the delivery server — every page will re-fetch fresh content on its next visit.</p></div>';
		} else {
			echo '<div class="notice notice-success"><p>API key saved.</p></div>';
		}
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
		<h1>
			Landing Page Client
			<button type="button" id="lpc-info-toggle" aria-expanded="false" aria-controls="lpc-info-popup" title="About this plugin" style="background:none;border:none;border-radius:50%;width:24px;height:24px;line-height:24px;text-align:center;font-size:16px;color:#2271b1;cursor:pointer;vertical-align:middle;">&#9432;</button>
		</h1>

		<?php lpc_render_info_popup(); ?>

		<form method="post">
			<?php wp_nonce_field( 'lpc_settings_action', 'lpc_settings_nonce' ); ?>
			<table class="form-table">
				<tr>
					<th scope="row">Plugin Version</th>
					<td><?php echo esc_html( get_file_data( __FILE__, [ 'Version' => 'Version' ] )['Version'] ); ?></td>
				</tr>
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
			<?php submit_button( 'Save API Key', 'primary', 'lpc_save_key', false ); ?>
			<button type="submit" name="lpc_refresh_now" class="button" style="margin-left:8px;">Save &amp; Refresh now</button>
			<p style="color:#666;font-size:.85rem;margin-top:6px;">"Save &amp; Refresh now" always saves whatever's in the API Key field above first, then forces an immediate re-fetch — use it any time you've just pasted in a new key.</p>
		</form>
	</div>
	<?php
}

/**
 * Collapsible "how this was built" reference, toggled by the (i) icon next
 * to the page heading. Kept in plain PHP/inline CSS/JS in this one file —
 * not worth a separate asset pipeline for a single admin-only popup.
 */
function lpc_render_info_popup() {
	?>
	<div id="lpc-info-popup" style="display:none;max-width:760px;margin:12px 0;padding:16px 20px;background:#fff;border:1px solid #c3c4c7;border-left:4px solid #2271b1;box-shadow:0 1px 1px rgba(0,0,0,.04);">

		<h2 style="margin-top:0;border-bottom:1px solid #dcdcde;padding-bottom:6px;">Summary</h2>
		<p>This plugin shows location/service landing pages on this site without storing any of that
		content here. It asks a central delivery server for the current page list and content on a
		schedule, then renders it wrapped in this site's own theme. One setting (an API Key)
		identifies which client's pages to show.</p>

		<h2 style="border-bottom:1px solid #dcdcde;padding-bottom:6px;">Objective</h2>
		<p>Give local-service businesses (starting with water/mold/fire/general restoration
		companies) professional, SEO-structured landing pages for every city and service they
		offer — without them needing to touch WordPress beyond pasting in one key — sold as an
		ongoing monthly service. It reuses this project's already-proven site-factory engine (AI
		content generation, schema.org structured data, the city/keyword research tooling) instead of
		building a second, separate content pipeline from scratch.</p>

		<h2 style="border-bottom:1px solid #dcdcde;padding-bottom:6px;">How to Use This</h2>
		<ol>
			<li><strong>Paste in the API Key</strong> above and click Save — that is the only setup
			step. It is issued per client from the admin panel (not from this screen).</li>
			<li><strong>Everything else configures itself.</strong> The locations path, refresh
			interval, and support message are all pushed from the delivery server automatically on
			each check-in — nothing else to set here, ever.</li>
			<li><strong>Your pages are live immediately</strong> at
			<code>yoursite.com<?php echo esc_html( get_option( 'lpc_base_path', '/locations' ) ); ?></code>
			(the hub list) and one URL per location/service underneath it — no separate publish step
			on this side.</li>
			<li><strong>Check Status</strong> above: <em>Connected</em> means a recent check-in,
			<em>Stale</em> means it's overdue and worth investigating, <em>Never Connected</em> means
			the API key hasn't successfully reached the server yet.</li>
			<li><strong>"Refresh now"</strong> forces an immediate check-in instead of waiting for the
			next automatic one — useful right after pasting in a new key.</li>
			<li>If a Support phone/message appears above, that's a message from the delivery side (for
			example, a billing issue) — contact them using that information.</li>
		</ol>

		<h2 style="border-bottom:1px solid #dcdcde;padding-bottom:6px;">How It Was Built</h2>
		<p><strong>Phase 0 — Infrastructure</strong></p>
		<ul style="margin-top:0;">
			<li>Persistent test WordPress site (this install) — real WP core on SQLite, served by its
			own systemd service so it survives reboots/disconnects.</li>
			<li>Delivery server — a thin, API-key-gated file-read backend. Originally co-located on the
			admin-panel box; now a real dedicated server (its own unprivileged system user, its own
			directory, its own firewall rule — isolated from everything else on the shared machine it
			happens to run on) that the admin panel pushes to automatically on every publish.</li>
		</ul>
		<p><strong>Phase 1 — This plugin</strong></p>
		<ul style="margin-top:0;">
			<li>One settings field (API Key) — every other setting is pushed from the delivery server
			automatically, no redistribution needed when those change.</li>
			<li>Fetches the page list ("manifest") and individual pages, caches them, and falls back to
			the last good copy if the delivery server is ever slow or down — verified by shutting the
			delivery server off entirely and confirming pages kept rendering.</li>
			<li>Renders 6 fixed content types as plain HTML with zero styling of our own, so the page
			always looks like <em>this</em> theme, never a foreign one.</li>
			<li>Hard rule: our content never outputs an H1 — only this theme's own site title is H1.
			Found by testing against a real theme, not assumed.</li>
		</ul>
		<p><strong>Phase 2 — The real client/page data backend</strong></p>
		<ul style="margin-top:0;">
			<li>Every client gets a real <code>client.json</code> + one JSON file per page, read/written
			by the admin panel — the hand-authored test client from Phase 1 was retired once this
			existed to create one properly instead.</li>
			<li>Schema.org (LocalBusiness + Service + areaServed + FAQPage) and <code>related_links</code>
			(sibling services at the same city, only ever linking to a page that's actually published)
			are both assembled automatically at publish time — never hand-authored, never AI-generated
			either, pure templated code pulling from verified client fields.</li>
		</ul>
		<p><strong>Phase 4 — The admin panel</strong> (everything Scott actually clicks through)</p>
		<ul style="margin-top:0;">
			<li>A 5-step kickoff wizard: identity, branding, hub offices + services (each hub
			geocoded for real), target cities (real population-ranked suggestions within a hub's
			radius, an optional paid keyword-data lookup), then one Review &amp; Create step — nothing
			touches disk before that last step, so quitting partway through never leaves a half-built
			client behind.</li>
			<li>The Locations &amp; Services matrix — target cities &times; services, per-cell status
			(Not Created / Draft / Published / Needs Update), bulk publish.</li>
			<li>A single-page editor for the 5 authored section types, a Preview toggle showing exactly
			what this plugin will render, and a status badge that correctly flips to Needs Update if a
			published page gets edited again before republishing.</li>
			<li>Branding, Content Profile (tone/excluded services/always-never-mention — now actually
			consumed by Phase 3, below), and Reporting (rank position via a real paid SERP check, real
			Google Search Console clicks once a client grants access, click-to-call form submissions
			deliberately left as "Not Tracked" — no contact-form section type exists).</li>
			<li>The operational layer on this very screen's counterpart in the admin panel: Disable/
			Re-enable (a real flag the delivery server enforces, not just a UI label — proven by
			flipping it and watching this exact API key get a real 403), Regenerate Key, and
			Email-to-Customer. Multi-user access was considered and deliberately not built — every
			client is single-operator (Scott) today, so it would be unused scaffolding.</li>
			<li>Integrations tab: a Google Analytics measurement ID field and a Cloudflare account
			field, both placeholders only — stored, shown in the admin panel, but genuinely wired into
			nothing yet (confirmed by grepping the whole codebase). They exist so that turning on
			GA/CDN later for a client is "fill in a field that's already there," not a new build.</li>
			<li>A cron-based alert (<code>tools/lpc_check_alerts.php</code>, every 30 minutes) emails
			when this plugin's check-in goes Stale — once per stale episode, not once per tick, and it
			resets the moment a client recovers so a later, unrelated episode alerts immediately rather
			than waiting out an old window. Building this surfaced and fixed a real bug: after the
			delivery server moved to its own dedicated machine (Box 1), the admin panel's Plugin Status
			badge (Connected/Stale/Never Connected) had been silently reading a frozen local copy of the
			check-in record instead of the real one — fixed by adding a small status endpoint to the
			delivery API that the admin side now calls live over HTTP.</li>
		</ul>
		<p><strong>Phase 3 — Real AI-generated content</strong></p>
		<ul style="margin-top:0;">
			<li>A reusable Niche Profile library (its own admin screen) — label, matching keywords,
			business descriptor, tone, and AI guardrails per industry. Adding a new industry as new
			clients come in is a form, not code: Scott fills it in once, every future client in that
			industry inherits it automatically by matching their own industry category text.</li>
			<li>Seeded from the 4 niches this project already knows well (water/mold/pest/appliance) —
			but deliberately NOT a copy-paste of the niche fleet's own guardrails, which assume a
			referral-network business model ("never write 'our team' doing the repair"). A real Landing
			Page Clients customer usually performs the work themselves, so only the genuinely
			industry-general safety rules (don't guarantee drying times, don't promise insurance
			approval, etc.) were kept.</li>
			<li>Confirming a target city (kickoff wizard's final step, or the matrix's "Add a City")
			immediately drafts real AI-written content for every service at that city — the same
			one-click-for-a-whole-batch behavior the original design called for, gated behind an explicit
			real-cost confirmation first.</li>
			<li>The editor's Regenerate button is real now, not disabled — one page, one click, real cost,
			same confirmation pattern.</li>
			<li>Phone numbers are never AI-written — auto-filled from the hub's real, verified number
			every time, confirmed live rather than assumed.</li>
		</ul>

		<p><strong>Where everything lives</strong></p>
		<table style="width:100%;border-collapse:collapse;">
			<tr><td style="padding:4px 8px 4px 0;white-space:nowrap;"><code>wp-plugin/landing-page-client/</code></td><td style="padding:4px 0;">This plugin's source code, in the site-factory repo.</td></tr>
			<tr><td style="padding:4px 8px 4px 0;white-space:nowrap;"><code>admin/client*.php</code>, <code>includes/landing_page_clients*.php</code></td><td style="padding:4px 0;">The admin panel + its data/publish/reporting backend, in the same repo.</td></tr>
			<tr><td style="padding:4px 8px 4px 0;white-space:nowrap;"><code>/opt/landing-page-clients/test-wordpress/</code></td><td style="padding:4px 0;">This WordPress install.</td></tr>
			<tr><td style="padding:4px 8px 4px 0;white-space:nowrap;"><code>/opt/landing-page-clients/delivery/</code></td><td style="padding:4px 0;">The delivery server this plugin talks to (source also in-repo, <code>delivery-server/</code>).</td></tr>
		</table>

		<h2 style="border-bottom:1px solid #dcdcde;padding-bottom:6px;">Current Status</h2>
		<p><strong>Every phase of the original design is done</strong> — Phases 0 through 4, verified
		against real tooling throughout: real geocoding, real DataForSEO SERP/keyword calls, real Google
		Search Console, real WordPress check-ins like this one, and real AI-generated page content. A
		brand-new client can now go from the kickoff wizard to real, live, AI-drafted pages with zero
		hand-authoring required (though the single-page editor is still there for anyone who wants to
		adjust the result by hand).</p>
		<p style="color:#6b7280;">This section will be updated again the next time something meaningfully
		changes — if you're reading stale info here, ask for it to be refreshed.</p>

	</div>
	<script>
	(function () {
		var btn = document.getElementById( 'lpc-info-toggle' );
		var box = document.getElementById( 'lpc-info-popup' );
		if ( ! btn || ! box ) { return; }
		btn.addEventListener( 'click', function () {
			var open = box.style.display !== 'none';
			box.style.display = open ? 'none' : 'block';
			btn.setAttribute( 'aria-expanded', open ? 'false' : 'true' );
		} );
	})();
	</script>
	<?php
}
