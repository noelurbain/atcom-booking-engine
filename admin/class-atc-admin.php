<?php
/**
 * wp-admin screens for milestone 1: Status and Settings.
 * The day-to-day panels (/control, /provider, /affiliate) live on the site itself, not here.
 */

defined( 'ABSPATH' ) || exit;

final class ATC_Admin {

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
	}

	public static function menu(): void {
		add_menu_page( 'Booking Engine', 'Booking Engine', 'atc_manage', 'atc', array( __CLASS__, 'status_page' ), 'dashicons-car', 56 );
		add_submenu_page( 'atc', 'Status', 'Status', 'atc_manage', 'atc', array( __CLASS__, 'status_page' ) );
		add_submenu_page( 'atc', 'Settings', 'Settings', 'atc_settings', 'atc-settings', array( __CLASS__, 'settings_page' ) );
		global $submenu;
		if ( current_user_can( 'atc_manage' ) ) {
			$submenu['atc'][] = array( 'Control panel ↗', 'atc_manage', home_url( '/control/' ) ); // phpcs:ignore -- link out to /control.
		}
	}

	public static function register_settings(): void {
		register_setting(
			'atc_settings_group',
			ATC_Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'ATC_Settings', 'sanitize' ),
				'default'           => ATC_Settings::defaults(),
			)
		);
	}

	private static function yes_no( bool $ok, string $yes = 'Set', string $no = 'Not set' ): string {
		$color = $ok ? '#1F6B35' : '#9B2C1C';
		return '<strong style="color:' . esc_attr( $color ) . '">' . esc_html( $ok ? $yes : $no ) . '</strong>';
	}

	public static function status_page(): void {
		if ( ! current_user_can( 'atc_manage' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'atcom-booking-engine' ) );
		}
		$s  = ATC_REST::status_data();
		$ex = $s['rules']['example_100_usd'];

		$maps_test = null;
		if ( isset( $_GET['atc_test_maps'] ) && check_admin_referer( 'atc_test_maps' ) ) {
			$maps_test = ATC_Geo::test_server_key();
		}
		$test_url = wp_nonce_url( admin_url( 'admin.php?page=atc&atc_test_maps=1' ), 'atc_test_maps' );
		?>
		<div class="wrap">
			<h1>Booking Engine · Status</h1>
			<p>Version <?php echo esc_html( $s['version'] ); ?> · database version <?php echo esc_html( (string) $s['db_version'] ); ?> · <a href="<?php echo esc_url( home_url( '/control/' ) ); ?>"><strong>Open the control panel →</strong></a></p>
			<?php if ( $maps_test ) : ?>
				<div class="notice <?php echo $maps_test[0] ? 'notice-success' : 'notice-error'; ?>"><p><strong>Server key test:</strong> <?php echo esc_html( $maps_test[1] ); ?></p></div>
			<?php endif; ?>
			<table class="widefat striped" style="max-width:760px">
				<tbody>
					<tr><td>Tables</td><td><?php echo $s['tables_missing'] ? self::yes_no( false, '', 'Missing: ' . implode( ', ', $s['tables_missing'] ) ) : self::yes_no( true, 'All ' . (int) $s['tables_total'] . ' tables present' ); // phpcs:ignore ?></td></tr>
					<tr><td>Airports</td><td><?php echo esc_html( (string) $s['airports'] ); ?></td></tr>
					<tr><td>Vehicle categories</td><td><?php echo esc_html( (string) $s['vehicle_types'] ); ?></td></tr>
					<tr><td>Stripe mode</td><td><strong><?php echo esc_html( strtoupper( $s['stripe']['mode'] ) ); ?></strong></td></tr>
					<tr><td>Stripe test secret key (wp-config.php)</td><td><?php echo self::yes_no( $s['stripe']['test_secret_set'] ); // phpcs:ignore ?></td></tr>
					<tr><td>Stripe publishable key</td><td><?php echo self::yes_no( $s['stripe']['publishable_set'] ); // phpcs:ignore ?></td></tr>
					<tr><td>Stripe webhook secret (wp-config.php)</td><td><?php echo self::yes_no( $s['stripe']['webhook_secret_set'] ); // phpcs:ignore ?></td></tr>
					<tr><td>Google Maps Website key (wp-config.php)</td><td><?php echo self::yes_no( $s['maps']['browser_key_set'] ); // phpcs:ignore ?></td></tr>
					<tr><td>Google Maps Server key (wp-config.php)</td><td><?php echo self::yes_no( $s['maps']['server_key_set'] ); // phpcs:ignore ?><?php if ( $s['maps']['server_key_set'] ) : ?> · <a href="<?php echo esc_url( $test_url ); ?>">Test it with Google</a><?php endif; ?></td></tr>
					<tr><td>Commission on providers</td><td><?php echo esc_html( $s['rules']['provider_commission'] ); ?></td></tr>
					<tr><td>Example: USD 100 sale</td><td>
						Amount the provider gets paid <?php echo esc_html( ATC_Money::format( $ex['provider'] ) ); ?> ·
						we keep <?php echo esc_html( ATC_Money::format( $ex['platform'] ) ); ?>
					</td></tr>
					<tr><td>Affiliate / referral</td><td><?php echo esc_html( $s['rules']['affiliate'] . ' / ' . $s['rules']['referral'] ); ?></td></tr>
				</tbody>
			</table>
			<h2>Stripe keys</h2>
			<p>Secret keys are never stored in the database. Add them to <code>wp-config.php</code> above “That’s all, stop editing”:</p>
			<pre style="background:#fff;padding:12px;border:1px solid #ccd0d4;max-width:760px">define( 'ATC_STRIPE_TEST_SECRET', 'sk_test_…' );
define( 'ATC_STRIPE_TEST_WEBHOOK_SECRET', 'whsec_…' );
define( 'ATC_MAPS_BROWSER_KEY', 'AIza…' );
define( 'ATC_MAPS_SERVER_KEY', 'AIza…' );</pre>
		</div>
		<?php
	}

	public static function settings_page(): void {
		if ( ! current_user_can( 'atc_settings' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'atcom-booking-engine' ) );
		}
		$o = ATC_Settings::all();
		$n = ATC_Settings::OPTION;

		$pct = static function ( string $key, string $label, string $help ) use ( $o, $n ) {
			printf(
				'<tr><th scope="row"><label for="%1$s">%2$s</label></th><td><input type="number" id="%1$s" name="%3$s[%1$s]" value="%4$s" min="0" step="1" class="small-text"> <span>basis points (%5$s%%)</span><p class="description">%6$s</p></td></tr>',
				esc_attr( $key ),
				esc_html( $label ),
				esc_attr( $n ),
				esc_attr( (string) $o[ $key ] ),
				esc_html( (string) ( $o[ $key ] / 100 ) ),
				esc_html( $help )
			);
		};
		$num = static function ( string $key, string $label, string $unit ) use ( $o, $n ) {
			printf(
				'<tr><th scope="row"><label for="%1$s">%2$s</label></th><td><input type="number" id="%1$s" name="%3$s[%1$s]" value="%4$s" min="0" class="small-text"> %5$s</td></tr>',
				esc_attr( $key ),
				esc_html( $label ),
				esc_attr( $n ),
				esc_attr( (string) $o[ $key ] ),
				esc_html( $unit )
			);
		};
		?>
		<div class="wrap">
			<h1>Booking Engine · Settings</h1>
			<?php settings_errors( $n ); ?>
			<form method="post" action="options.php">
				<?php settings_fields( 'atc_settings_group' ); ?>
				<h2>Stripe</h2>
				<table class="form-table" role="presentation">
					<tr><th scope="row">Mode</th><td>
						<label><input type="radio" name="<?php echo esc_attr( $n ); ?>[stripe_mode]" value="test" <?php checked( $o['stripe_mode'], 'test' ); ?>> Test</label>
						&nbsp; <label><input type="radio" name="<?php echo esc_attr( $n ); ?>[stripe_mode]" value="live" <?php checked( $o['stripe_mode'], 'live' ); ?>> Live</label>
						<p class="description">Live is refused until the live secret key is in wp-config.php.</p>
					</td></tr>
					<tr><th scope="row"><label for="stripe_test_publishable">Test publishable key</label></th><td><input type="text" id="stripe_test_publishable" class="regular-text code" name="<?php echo esc_attr( $n ); ?>[stripe_test_publishable]" value="<?php echo esc_attr( $o['stripe_test_publishable'] ); ?>" placeholder="pk_test_…"></td></tr>
					<tr><th scope="row"><label for="stripe_live_publishable">Live publishable key</label></th><td><input type="text" id="stripe_live_publishable" class="regular-text code" name="<?php echo esc_attr( $n ); ?>[stripe_live_publishable]" value="<?php echo esc_attr( $o['stripe_live_publishable'] ); ?>" placeholder="pk_live_…"></td></tr>
				</table>
				<h2>Money rules</h2>
				<table class="form-table" role="presentation">
					<?php
					$pct( 'provider_commission_bp', 'Commission on providers', 'Taken off the Suggested retail price. 2500 = 25%: a USD 100 sale pays the provider USD 75.' );
					$pct( 'affiliate_bp', 'Affiliate commission', 'Share of retail for the affiliate who sent the booking.' );
					$pct( 'referral_bp', 'Referral commission', 'Share of retail for the affiliate who invited that affiliate.' );
					$num( 'payout_day', 'Payout day', 'of each month' );
					$num( 'payout_minimum_cents', 'Minimum payout', 'cents (10000 = USD 100); smaller balances roll over' );
					?>
				</table>
				<h2>Booking rules</h2>
				<table class="form-table" role="presentation">
					<?php
					$num( 'free_cancel_hours', 'Free cancellation', 'hours before pickup' );
					$num( 'ack_reminder_minutes', 'Remind provider to acknowledge after', 'minutes' );
					$num( 'ack_urgent_minutes', '… when pickup is less than 24 hours away, after', 'minutes' );
					$num( 'docs_pause_days', 'Pause a live provider without documents after (0 = never)', 'days' );
					$num( 'autocomplete_hours', 'Complete trips automatically', 'hours after pickup' );
					$num( 'service_code_length', 'Service code length', 'characters (4–6)' );
					$num( 'service_code_attempts', 'Service code tries before locking', '' );
					?>
					<tr><th scope="row"><label for="invite_test_to">Test mode: send provider invitations to</label></th><td><input type="email" id="invite_test_to" class="regular-text" name="<?php echo esc_attr( $n ); ?>[invite_test_to]" value="<?php echo esc_attr( $o['invite_test_to'] ); ?>"><p class="description">While this has an address, invitations go there instead of to the providers. Empty it on the live site to invite providers for real.</p></td></tr>
					<tr><th scope="row"><label for="from_email">Send emails from</label></th><td><input type="email" id="from_email" class="regular-text" name="<?php echo esc_attr( $n ); ?>[from_email]" value="<?php echo esc_attr( $o['from_email'] ); ?>"></td></tr>
				</table>
				<h2>Analytics</h2>
				<table class="form-table" role="presentation">
					<tr><th scope="row"><label for="posthog_key">PostHog project key</label></th><td><input type="text" id="posthog_key" class="regular-text code" name="<?php echo esc_attr( $n ); ?>[posthog_key]" value="<?php echo esc_attr( $o['posthog_key'] ); ?>" placeholder="phc_…">
						<select name="<?php echo esc_attr( $n ); ?>[posthog_region]"><option value="us" <?php selected( $o['posthog_region'], 'us' ); ?>>US cloud</option><option value="eu" <?php selected( $o['posthog_region'], 'eu' ); ?>>EU cloud</option></select></td></tr>
					<tr><th scope="row"><label for="crisp_id">Crisp website ID</label></th><td><input type="text" id="crisp_id" class="regular-text code" name="<?php echo esc_attr( $n ); ?>[crisp_id]" value="<?php echo esc_attr( $o['crisp_id'] ); ?>" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx"><p class="description">Shows our chat button. Crisp only loads when a visitor taps it.</p></td></tr>
					<tr><th scope="row">Crisp plugin</th><td>
						<p><label>Identifier<br><input type="password" class="regular-text code" autocomplete="off" name="<?php echo esc_attr( $n ); ?>[crisp_plugin_id]" placeholder="<?php echo $o['crisp_plugin_id'] ? esc_attr__( 'Saved. Paste a new one to change it.' ) : 'From the plugin’s Tokens page'; ?>"></label></p>
						<p><label>Key<br><input type="password" class="regular-text code" autocomplete="off" name="<?php echo esc_attr( $n ); ?>[crisp_plugin_key]" placeholder="<?php echo $o['crisp_plugin_key'] ? esc_attr__( 'Saved. Paste a new one to change it.' ) : 'From the plugin’s Tokens page'; ?>"></label></p>
						<p><label>Web hook signing secret<br><input type="password" class="regular-text code" autocomplete="off" name="<?php echo esc_attr( $n ); ?>[crisp_hook_secret]" placeholder="<?php echo $o['crisp_hook_secret'] ? esc_attr__( 'Saved. Paste a new one to change it.' ) : 'Shown when you create the web hook'; ?>"></label></p>
						<p><label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[crisp_autoreply]" value="1" <?php checked( ! empty( $o['crisp_autoreply'] ) ); ?>> Answer reservation numbers in the chat automatically</label></p>
						<p class="description">Web hook address for Crisp (event <code>message:send</code>):<br><input type="text" readonly class="large-text code" onclick="this.select()" value="<?php echo esc_attr( ATC_Crisp::hook_url() ); ?>"></p>
						<p class="description"><?php echo ATC_Crisp::ready() ? '✅ Connected.' : 'Not connected yet: fill in the Website ID, Identifier and Key.'; ?></p>
					</td></tr>
					<tr><th scope="row"><label for="ga4_id">GA4 Measurement ID</label></th><td><input type="text" id="ga4_id" class="regular-text code" name="<?php echo esc_attr( $n ); ?>[ga4_id]" value="<?php echo esc_attr( $o['ga4_id'] ); ?>" placeholder="G-…"></td></tr>
					<tr><th scope="row"><label for="ads_id">Google Ads conversion</label></th><td><input type="text" id="ads_id" class="regular-text code" name="<?php echo esc_attr( $n ); ?>[ads_id]" value="<?php echo esc_attr( $o['ads_id'] ); ?>" placeholder="AW-…"> / <input type="text" class="regular-text code" name="<?php echo esc_attr( $n ); ?>[ads_label]" value="<?php echo esc_attr( $o['ads_label'] ); ?>" placeholder="conversion label"><p class="description">Sent once per paid booking, with its value in USD.</p></td></tr>
					<?php $num( 'traveler_base', 'Traveler count base', 'people (the public figure starts here and grows with real searches)' ); ?>
				</table>
				<h2>Directory</h2>
				<table class="form-table" role="presentation">
					<tr><th scope="row">New providers</th><td><input type="hidden" name="<?php echo esc_attr( $n ); ?>[auto_live]" value="0"><label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[auto_live]" value="1" <?php checked( ! empty( $o['auto_live'] ) ); ?>> Go live automatically once company, vehicles, zones and prices are done and the agreement is accepted</label><p class="description">Documents and payout details do not hold a provider back (payouts wait until they add their details). Unticked, you approve each provider in Control.</p></td></tr>
					<tr><th scope="row">Provider websites</th><td><input type="hidden" name="<?php echo esc_attr( $n ); ?>[dir_backlink_required]" value="0"><label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[dir_backlink_required]" value="1" <?php checked( ! empty( $o['dir_backlink_required'] ) ); ?>> Show a provider's website on its directory profile only while their site links back to us</label><p class="description">Checked automatically once a day, and right away when a provider changes its website. Unticked, every website shows. Per provider, Control can still force always or never.</p></td></tr>
					<tr><th scope="row"><label for="backlink_domains">A link to any of these counts</label></th><td><input type="text" id="backlink_domains" class="regular-text" name="<?php echo esc_attr( $n ); ?>[backlink_domains]" value="<?php echo esc_attr( $o['backlink_domains'] ); ?>"><p class="description">Domains, separated by commas. Subdomains count too.</p></td></tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
