<?php
/**
 * Extra payments asked of a traveler after booking: an upgrade to a bigger or better vehicle, an extra stop,
 * waiting time. Our team asks from Control; the traveler gets an email and pays on Stripe's own page.
 * Paid amounts add to the booking total and to our share; the provider's pay is set separately.
 *
 * Kept in the booking snapshot under 'charges': id, amount, reason, leg, status (open/paid/cancelled),
 * session (last Stripe Checkout id), pi, created, paid_at.
 */

defined( 'ABSPATH' ) || exit;

final class ATC_Charges {

	const MIN = 100;

	private static function t( string $n ): string {
		return ATC_Schema::table( $n );
	}

	private static function now(): string {
		return current_time( 'mysql', true );
	}

	public static function all( array $b ): array {
		return array_values( (array) ( $b['snap']['charges'] ?? array() ) );
	}

	public static function open_total( array $b ): int {
		$sum = 0;
		foreach ( self::all( $b ) as $c ) {
			if ( 'open' === $c['status'] ) {
				$sum += (int) $c['amount'];
			}
		}
		return $sum;
	}

	public static function paid_total( array $b ): int {
		$sum = 0;
		foreach ( self::all( $b ) as $c ) {
			if ( 'paid' === $c['status'] ) {
				$sum += (int) $c['amount'];
			}
		}
		return $sum;
	}

	private static function find_charge( array $b, string $id ): ?array {
		foreach ( self::all( $b ) as $c ) {
			if ( $c['id'] === $id ) {
				return $c;
			}
		}
		return null;
	}

	private static function put( array &$b, array $charge ): void {
		$list = self::all( $b );
		$hit  = false;
		foreach ( $list as $i => $c ) {
			if ( $c['id'] === $charge['id'] ) {
				$list[ $i ] = $charge;
				$hit        = true;
			}
		}
		if ( ! $hit ) {
			$list[] = $charge;
		}
		$b['snap']['charges'] = $list;
		ATC_Checkout::snapshot( (int) $b['id'], $b['snap'] );
	}

	public static function can_charge( array $b ): bool {
		return in_array( $b['status'], array( 'confirmed', 'completed' ), true ) && empty( $b['snap']['legacy'] ) && '' !== (string) $b['email'];
	}

	/** Our team asks the traveler to pay more. The email goes out right away. */
	public static function request( string $number, int $amount, string $reason, int $leg_id = 0 ) {
		$b = ATC_Checkout::find( $number );
		if ( ! $b ) {
			return new WP_Error( 'atc_not_found', 'Booking not found.', array( 'status' => 404 ) );
		}
		if ( ! self::can_charge( $b ) ) {
			return new WP_Error( 'atc_charge', 'Only paid bookings made on this site can get an extra payment.', array( 'status' => 409 ) );
		}
		if ( $amount < self::MIN || $amount > 1000000 ) {
			return new WP_Error( 'atc_charge', 'Type an amount of at least ' . ATC_Book::money( self::MIN ) . '.', array( 'status' => 400 ) );
		}
		$reason = mb_substr( trim( sanitize_text_field( $reason ) ), 0, 160 );
		if ( '' === $reason ) {
			return new WP_Error( 'atc_charge', 'Write what the payment is for. The traveler sees it.', array( 'status' => 400 ) );
		}
		$charge = array(
			'id'      => 'c' . ( count( self::all( $b ) ) + 1 ),
			'amount'  => $amount,
			'reason'  => $reason,
			'leg'     => $leg_id,
			'status'  => 'open',
			'session' => '',
			'pi'      => '',
			'created' => self::now(),
			'by'      => get_current_user_id(),
			'paid_at' => null,
		);
		self::put( $b, $charge );
		ATC_Install::log( 'booking', (int) $b['id'], 'charge_requested', array( 'charge' => $charge['id'], 'amount' => $amount, 'reason' => $reason ) );
		ATC_Mail::charge_request( $b, $charge );
		return ATC_Bookings::get( $number );
	}

	public static function cancel( string $number, string $id ) {
		$b = ATC_Checkout::find( $number );
		$c = $b ? self::find_charge( $b, $id ) : null;
		if ( ! $c || 'open' !== $c['status'] ) {
			return new WP_Error( 'atc_charge', 'This payment request is not open.', array( 'status' => 409 ) );
		}
		if ( self::sync( $b, $id ) ) {
			return new WP_Error( 'atc_charge', 'The traveler has just paid it.', array( 'status' => 409 ) );
		}
		$c['status'] = 'cancelled';
		self::put( $b, $c );
		ATC_Install::log( 'booking', (int) $b['id'], 'charge_cancelled', array( 'charge' => $id ) );
		return ATC_Bookings::get( $number );
	}

	/** The traveler clicked Pay: a fresh Stripe Checkout page (they last 24 hours, the request does not). */
	public static function checkout_url( array $b, string $key, string $id ) {
		$c = self::find_charge( $b, $id );
		if ( ! $c || 'open' !== $c['status'] ) {
			return new WP_Error( 'atc_charge', 'Nothing to pay.', array( 'status' => 409 ) );
		}
		$back = ATC_Checkout::reservation_url( $b['number'], $key );
		$meta = array( 'kind' => 'extra', 'booking' => $b['number'], 'booking_id' => (string) (int) $b['id'], 'charge' => $id );
		$s    = ATC_Checkout::stripe(
			'POST',
			'checkout/sessions',
			array(
				'mode'                => 'payment',
				'customer_email'      => $b['email'],
				'client_reference_id' => $b['number'],
				'line_items'          => array(
					array(
						'quantity'   => 1,
						'price_data' => array(
							'currency'     => 'usd',
							'unit_amount'  => (int) $c['amount'],
							'product_data' => array( 'name' => $c['reason'] . ' · booking ' . $b['number'] ),
						),
					),
				),
				'metadata'            => $meta,
				'payment_intent_data' => array( 'metadata' => $meta, 'description' => 'Extra payment ' . $b['number'] . ' · ' . $c['reason'], 'receipt_email' => $b['email'] ),
				'success_url'         => add_query_arg( array( 'extra_back' => $id ), $back ),
				'cancel_url'          => $back,
			)
		);
		if ( is_wp_error( $s ) ) {
			return $s;
		}
		$c['session'] = (string) $s['id'];
		self::put( $b, $c );
		return (string) $s['url'];
	}

	/** Back from Stripe, or asked again: is the open request paid now? */
	public static function sync( array $b, string $id ): bool {
		$c = self::find_charge( $b, $id );
		if ( ! $c || 'open' !== $c['status'] || '' === $c['session'] ) {
			return false;
		}
		$s = ATC_Checkout::stripe( 'GET', 'checkout/sessions/' . rawurlencode( $c['session'] ) );
		if ( is_wp_error( $s ) || 'paid' !== ( $s['payment_status'] ?? '' ) ) {
			return false;
		}
		return self::mark_paid( $b, $id, (string) ( $s['payment_intent'] ?? '' ), (int) ( $s['amount_total'] ?? 0 ) );
	}

	/** Paid (from the return page or the Stripe webhook): the total and our share grow. Safe to call twice. */
	public static function mark_paid( array $b, string $id, string $pi, int $amount ): bool {
		global $wpdb;
		$b = ATC_Checkout::find( '', (int) $b['id'] );
		$c = $b ? self::find_charge( $b, $id ) : null;
		if ( ! $c || 'open' !== $c['status'] || $amount < (int) $c['amount'] ) {
			return false;
		}
		$c['status']  = 'paid';
		$c['pi']      = $pi;
		$c['paid_at'] = self::now();
		self::put( $b, $c );
		$amount = (int) $c['amount'];
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t( 'bookings' ) . ' SET total_cents = total_cents + %d, retail_cents = retail_cents + %d, updated_at = %s WHERE id = %d', $amount, $amount, self::now(), (int) $b['id'] ) );
		if ( $c['leg'] ) {
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t( 'booking_legs' ) . ' SET retail_cents = retail_cents + %d WHERE id = %d AND booking_id = %d', $amount, (int) $c['leg'], (int) $b['id'] ) );
		}
		ATC_Ops::rebalance_platform( (int) $b['id'] );
		ATC_Install::log( 'booking', (int) $b['id'], 'charge_paid', array( 'charge' => $id, 'amount' => $amount, 'payment_intent' => $pi ) );
		ATC_Mail::team_note( 'Extra payment received: ' . $b['number'], 'The traveler paid ' . ATC_Book::money( $amount ) . '. It is added to the booking total and to our share.', $b, 'For: ' . $c['reason'] );
		return true;
	}

	/**
	 * Where a refund can come from, newest payment first: [payment_intent => refundable cents].
	 */
	public static function refund_sources( array $b ): array {
		$done = (array) ( $b['snap']['pi_refunded'] ?? array() );
		$out  = array();
		foreach ( array_reverse( self::all( $b ) ) as $c ) {
			if ( 'paid' === $c['status'] && '' !== $c['pi'] ) {
				$out[ $c['pi'] ] = (int) $c['amount'] - (int) ( $done[ $c['pi'] ] ?? 0 );
			}
		}
		if ( ! empty( $b['stripe_payment_intent'] ) ) {
			$main = (int) $b['total_cents'] - self::paid_total( $b );
			// Older refunds were only counted in total, all against the first payment.
			$prev = isset( $b['snap']['pi_refunded'] ) ? (int) ( $done[ $b['stripe_payment_intent'] ] ?? 0 ) : (int) ( $b['snap']['refunded'] ?? 0 );
			$out[ $b['stripe_payment_intent'] ] = $main - $prev;
		}
		return array_filter( $out, static fn( $v ) => $v > 0 );
	}
}
