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
			'free_cancel_hours'      => 24,
			'service_code_length'    => 4,
			'service_code_attempts'  => 5,
			'from_email'             => 'hello@airporttransportation.com',
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

	public static function has_secret( string $mode ): bool {
		return '' !== self::stripe_secret( $mode );
	}

	public static function webhook_secret(): string {
		$const = 'live' === self::get( 'stripe_mode' ) ? 'ATC_STRIPE_LIVE_WEBHOOK_SECRET' : 'ATC_STRIPE_TEST_WEBHOOK_SECRET';
		return defined( $const ) ? (string) constant( $const ) : '';
	}
}
