<?php
/**
 * Money math. Every amount is an integer number of cents; rates are basis points (2500 = 25%).
 * Rounding remainders always go to us, never to the provider or the affiliate.
 */

defined( 'ABSPATH' ) || exit;

final class ATC_Money {

	const BP = 10000;

	/** Amount the provider gets paid for a Suggested retail price. USD 100.00 at 25% → USD 75.00. */
	public static function provider_from_retail( int $retail_cents, int $commission_bp ): int {
		self::check_rate( $commission_bp );
		return intdiv( $retail_cents * ( self::BP - $commission_bp ), self::BP );
	}

	/**
	 * Suggested retail price for the amount a provider types, rounded UP to whole dollars
	 * so the provider never receives less than what they typed.
	 */
	public static function retail_from_provider( int $provider_cents, int $commission_bp ): int {
		self::check_rate( $commission_bp );
		if ( $commission_bp >= self::BP ) {
			throw new InvalidArgumentException( 'Commission must be below 100%.' );
		}
		$exact = $provider_cents * self::BP / ( self::BP - $commission_bp );
		return (int) ( ceil( round( $exact, 6 ) / 100 ) * 100 );
	}

	/** Round a typed retail price to whole dollars (nearest). */
	public static function round_retail( int $retail_cents ): int {
		return (int) ( round( $retail_cents / 100 ) * 100 );
	}

	/**
	 * Split one sale between the parties.
	 *
	 * @param int    $retail_cents   Suggested retail price of the booking (before any coupon).
	 * @param int    $provider_bp    Our commission on the provider (2500 by default).
	 * @param int    $affiliate_bp   Affiliate share of retail (800), 0 when there is no affiliate.
	 * @param int    $referral_bp    Referrer share of retail (200), 0 when there is no referrer.
	 * @param int    $coupon_cents   Discount given to the traveler.
	 * @param string $coupon_owner   'platform' (comes out of our share) or 'affiliate' (out of the affiliate's share).
	 * @return array{traveler_pays:int,provider:int,affiliate:int,referrer:int,platform:int}
	 */
	public static function split( int $retail_cents, int $provider_bp, int $affiliate_bp = 0, int $referral_bp = 0, int $coupon_cents = 0, string $coupon_owner = 'platform' ): array {
		foreach ( array( $provider_bp, $affiliate_bp, $referral_bp ) as $rate ) {
			self::check_rate( $rate );
		}
		if ( $coupon_cents < 0 || $coupon_cents > $retail_cents ) {
			throw new InvalidArgumentException( 'Coupon out of range.' );
		}

		$provider  = self::provider_from_retail( $retail_cents, $provider_bp );
		$affiliate = intdiv( $retail_cents * $affiliate_bp, self::BP );
		$referrer  = intdiv( $retail_cents * $referral_bp, self::BP );

		if ( 'affiliate' === $coupon_owner ) {
			if ( $coupon_cents > $affiliate ) {
				throw new InvalidArgumentException( 'An affiliate coupon cannot exceed the affiliate share.' );
			}
			$affiliate -= $coupon_cents;
		}

		$traveler_pays = $retail_cents - $coupon_cents;
		$platform      = $traveler_pays - $provider - $affiliate - $referrer;

		if ( $platform < 0 ) {
			throw new RuntimeException( 'Split leaves the platform negative.' );
		}

		return array(
			'traveler_pays' => $traveler_pays,
			'provider'      => $provider,
			'affiliate'     => $affiliate,
			'referrer'      => $referrer,
			'platform'      => $platform,
		);
	}

	/** "USD 75.00" */
	public static function format( int $cents, string $currency = 'USD' ): string {
		$sign = $cents < 0 ? '-' : '';
		return $currency . ' ' . $sign . number_format( abs( $cents ) / 100, 2, '.', ',' );
	}

	private static function check_rate( int $bp ): void {
		if ( $bp < 0 || $bp > self::BP ) {
			throw new InvalidArgumentException( 'Rate must be between 0 and 10000 basis points.' );
		}
	}
}
