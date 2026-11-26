<?php
/**
 * Plugin settings. Business rules live in one option; Stripe secret keys never touch the
 * database and are read from wp-config.php constants.
 */

defined( 'ABSPATH' ) || exit;

final class ATC_Settings {

	const OPTION = 'atc_settings';

	public static function defaults(): array {
		return array(
			'stripe_mode'            => 'test',   // test | live
			'stripe_test_publishable' => '',
			'stripe_live_publishable' => '',
			'currency'               => 'USD',
			'provider_commission_bp' => 2500,     // 25% off the Suggested retail price
			'affiliate_bp'           => 800,      // 8% of retail
			'referral_bp'            => 200,      // 2% of retail to the referrer
			'payout_day'             => 10,
			'payout_minimum_cents'   => 10000,    // balances under USD 100 roll over
			'autocomplete_hours'     => 24,
			'ack_reminder_minutes'   => 120,
			'ack_urgent_minutes'     => 30,  // when pickup is less than 24 hours away
			'docs_pause_days'        => 0,   // pause a live provider without documents after this many days (0 = never)
			'free_cancel_hours'      => 24,
			'service_code_length'    => 4,
			'service_code_attempts'  => 5,
			'from_email'             => 'hello@airporttransportation.com',
			// While this is set, provider invitations go to this address instead of the providers (test site).
			'invite_test_to'         => 'hello@airporttransportation.com',
			'provider_reminders'     => 0,        // document, expiry and "please confirm" reminders to providers
			// Analytics (all public ids, not secrets).
			'posthog_key'            => '',
			'posthog_region'         => 'us',     // us | eu
			'crisp_id'               => '',       // Crisp website ID: the chat button shows when set
			'crisp_plugin_id'        => '',       // Crisp plugin token (Marketplace): identifier
			'crisp_plugin_key'       => '',       // ... and key (secret)
			'crisp_hook_secret'      => '',       // signing secret of the plugin web hook
			'crisp_tier'             => 'website', // website token (Workspace Settings) or plugin token (Marketplace)
			'crisp_autoreply'        => 1,        // answer reservation numbers in the chat
			'ga4_id'                 => '',
			'ads_id'                 => '',
			'ads_label'              => '',
			// The public traveler count starts from this base.
			'traveler_base'          => 1821,
			// Directory: provider websites show only while they link back to one of these domains.
			'dir_backlink_required'  => 1,
			'auto_live'              => 1,   // a provider goes live by itself once it can take bookings
			'backlink_domains'       => 'airporttransportation.com, airporttransportations.com',
		);
	}

	public static function all(): array {
		$saved = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
	}

	public static function get( string $key ) {
		$all = self::all();
		return $all[ $key ] ?? null;
	}

	/** Validate and clean a settings array coming from the settings form. */
	public static function sanitize( $input ): array {
		$d   = self::defaults();
		$in  = is_array( $input ) ? $input : array();
		$out = array();

		$out['stripe_mode'] = ( isset( $in['stripe_mode'] ) && 'live' === $in['stripe_mode'] ) ? 'live' : 'test';

		foreach ( array( 'stripe_test_publishable' => 'pk_test_', 'stripe_live_publishable' => 'pk_live_' ) as $key => $prefix ) {
			$val         = isset( $in[ $key ] ) ? trim( sanitize_text_field( $in[ $key ] ) ) : '';
			$out[ $key ] = ( '' === $val || str_starts_with( $val, $prefix ) ) ? $val : '';
		}

		$out['currency'] = 'USD';

		$ints = array(
			'provider_commission_bp' => array( 0, 9000 ),
			'affiliate_bp'           => array( 0, 3000 ),
			'referral_bp'            => array( 0, 1000 ),
			'payout_day'             => array( 1, 28 ),
			'payout_minimum_cents'   => array( 0, 10000000 ),
			'autocomplete_hours'     => array( 1, 168 ),
			'ack_reminder_minutes'   => array( 5, 1440 ),
			'ack_urgent_minutes'     => array( 5, 240 ),
			'docs_pause_days'        => array( 0, 365 ),
			'free_cancel_hours'      => array( 0, 168 ),
			'service_code_length'    => array( 4, 6 ),
			'service_code_attempts'  => array( 3, 10 ),
		);
		foreach ( $ints as $key => [ $min, $max ] ) {
			$val         = isset( $in[ $key ] ) ? (int) $in[ $key ] : $d[ $key ];
			$out[ $key ] = max( $min, min( $max, $val ) );
		}

		$email             = isset( $in['from_email'] ) ? sanitize_email( $in['from_email'] ) : '';
		$out['from_email'] = is_email( $email ) ? $email : $d['from_email'];

		$test                  = isset( $in['invite_test_to'] ) ? sanitize_email( $in['invite_test_to'] ) : '';
		$out['invite_test_to'] = is_email( $test ) ? $test : '';
		$out['provider_reminders'] = empty( $in['provider_reminders'] ) ? 0 : 1;

		$out['posthog_key']    = isset( $in['posthog_key'] ) && preg_match( '/^phc_[A-Za-z0-9]{20,}$/', trim( (string) $in['posthog_key'] ) ) ? trim( (string) $in['posthog_key'] ) : '';
		$out['posthog_region'] = isset( $in['posthog_region'] ) && 'eu' === $in['posthog_region'] ? 'eu' : 'us';
		// Secrets: an empty field keeps what is saved.
		$old = get_option( self::OPTION, array() );
		foreach ( array( 'crisp_plugin_id', 'crisp_plugin_key', 'crisp_hook_secret' ) as $k ) {
			$v         = isset( $in[ $k ] ) ? trim( sanitize_text_field( (string) $in[ $k ] ) ) : '';
			$out[ $k ] = '' !== $v ? mb_substr( $v, 0, 200 ) : (string) ( $old[ $k ] ?? '' );
		}
		$out['crisp_tier']      = ( isset( $in['crisp_tier'] ) && 'plugin' === $in['crisp_tier'] ) ? 'plugin' : 'website';
		$out['crisp_autoreply'] = empty( $in['crisp_autoreply'] ) ? 0 : 1;
		$out['crisp_id']       = isset( $in['crisp_id'] ) && preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', strtolower( trim( (string) $in['crisp_id'] ) ) ) ? strtolower( trim( (string) $in['crisp_id'] ) ) : '';
		$out['ga4_id']         = isset( $in['ga4_id'] ) && preg_match( '/^G-[A-Z0-9]{4,}$/', strtoupper( trim( (string) $in['ga4_id'] ) ) ) ? strtoupper( trim( (string) $in['ga4_id'] ) ) : '';
		$out['ads_id']         = isset( $in['ads_id'] ) && preg_match( '/^AW-\d{6,}$/', strtoupper( trim( (string) $in['ads_id'] ) ) ) ? strtoupper( trim( (string) $in['ads_id'] ) ) : '';
		$out['ads_label']      = isset( $in['ads_label'] ) ? substr( preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $in['ads_label'] ), 0, 60 ) : '';
		$out['traveler_base']  = isset( $in['traveler_base'] ) ? max( 0, min( 10000000, (int) $in['traveler_base'] ) ) : $d['traveler_base'];
		$out['dir_backlink_required'] = empty( $in['dir_backlink_required'] ) ? 0 : 1;
		$out['auto_live']             = empty( $in['auto_live'] ) ? 0 : 1;
		$doms = array();
		foreach ( preg_split( '/[\s,]+/', strtolower( (string) ( $in['backlink_domains'] ?? $d['backlink_domains'] ) ) ) as $dom ) {
			$dom = preg_replace( '#^(https?://)?(www\.)?#', '', trim( $dom ) );
			$dom = preg_replace( '#/.*$#', '', $dom );
			if ( preg_match( '/^[a-z0-9.-]+\.[a-z]{2,}$/', $dom ) ) {
				$doms[] = $dom;
			}
		}
		$out['backlink_domains'] = $doms ? implode( ', ', array_unique( $doms ) ) : $d['backlink_domains'];

		// Live mode is refused until a live secret key exists in wp-config.php.
		if ( 'live' === $out['stripe_mode'] && ! self::has_secret( 'live' ) ) {
			$out['stripe_mode'] = 'test';
			add_settings_error( self::OPTION, 'atc_live_refused', __( 'Live mode needs ATC_STRIPE_LIVE_SECRET in wp-config.php. Staying in test mode.', 'atcom-booking-engine' ) );
		}

		return $out;
	}

	/** Stripe secret key from wp-config.php: ATC_STRIPE_TEST_SECRET / ATC_STRIPE_LIVE_SECRET. */
	public static function stripe_secret( ?string $mode = null ): string {
		$mode  = $mode ?? self::get( 'stripe_mode' );
		$const = 'live' === $mode ? 'ATC_STRIPE_LIVE_SECRET' : 'ATC_STRIPE_TEST_SECRET';
		return defined( $const ) ? (string) constant( $const ) : '';
	}

	/** Stripe publishable key for the current mode (public by design). */
	public static function stripe_publishable(): string {
		return (string) self::get( 'live' === self::get( 'stripe_mode' ) ? 'stripe_live_publishable' : 'stripe_test_publishable' );
	}

	public static function has_secret( string $mode ): bool {
		return '' !== self::stripe_secret( $mode );
	}

	/** Google Maps keys from wp-config.php. The browser key is public by design and limited to our domains. */
	public static function maps_browser_key(): string {
		return defined( 'ATC_MAPS_BROWSER_KEY' ) ? (string) ATC_MAPS_BROWSER_KEY : '';
	}

	public static function maps_server_key(): string {
		return defined( 'ATC_MAPS_SERVER_KEY' ) ? (string) ATC_MAPS_SERVER_KEY : '';
	}

	public static function webhook_secret(): string {
		$const = 'live' === self::get( 'stripe_mode' ) ? 'ATC_STRIPE_LIVE_WEBHOOK_SECRET' : 'ATC_STRIPE_TEST_WEBHOOK_SECRET';
		return defined( $const ) ? (string) constant( $const ) : '';
	}
}
