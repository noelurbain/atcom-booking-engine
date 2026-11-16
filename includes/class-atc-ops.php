<?php
/**
 * What our team does to a booking from Control: give a ride to another provider (handed back, or any
 * reason), decide a reported no-show, unlock a service code after too many wrong tries, refund part or all
 * of the payment (cancelling it when it is all), and send the confirmation again.
 */

defined( 'ABSPATH' ) || exit;

final class ATC_Ops {

	private static function t( string $n ): string {
		return ATC_Schema::table( $n );
	}

	private static function now(): string {
		return current_time( 'mysql', true );
	}

	private static function booking( string $number ) {
		$b = ATC_Checkout::find( $number );
		return $b ?: new WP_Error( 'atc_not_found', 'Booking not found.', array( 'status' => 404 ) );
	}

	private static function leg_of( array $b, int $leg_id ): ?array {
		foreach ( $b['legs'] as $l ) {
			if ( (int) $l['id'] === $leg_id ) {
				return $l;
			}
		}
		return null;
	}

	/* ---------- Change provider ---------- */

	/**
	 * Who else can do this ride: live services at the same airport serving the ride's zone, with what that
	 * provider gets for it (their one-way price, or half their round-trip price on a round trip).
	 */
	public static function candidates( array $b, array $leg ): array {
		global $wpdb;
		$zone = (int) $leg['zone_id'];
		$air  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT airport_id FROM ' . self::t( 'services' ) . ' WHERE id = %d', (int) $leg['service_id'] ) );
		if ( ! $air && $zone ) {
			$air = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT airport_id FROM ' . self::t( 'zones' ) . ' WHERE id = %d', $zone ) );
		}
		if ( ! $air ) {
			return array();
		}
		$round = 'round_trip' === $b['trip_type'];
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT s.id service_id, s.party_id, p.name provider, v.name vehicle, s.max_passengers, pr.provider_cents, pr.retail_cents
				FROM ' . self::t( 'services' ) . ' s
				JOIN ' . self::t( 'parties' ) . " p ON p.id = s.party_id AND p.type = 'provider' AND p.status IN ('live','approved','pending')
				JOIN " . self::t( 'vehicle_types' ) . ' v ON v.id = s.vehicle_type_id
				LEFT JOIN ' . self::t( 'service_zones' ) . ' sz ON sz.service_id = s.id AND sz.zone_id = %d
				LEFT JOIN ' . self::t( 'prices' ) . ' pr ON pr.service_id = s.id AND pr.zone_id = %d AND pr.direction = %s AND pr.valid_from IS NULL
				WHERE s.airport_id = %d AND s.status IN (\'live\',\'draft\') AND s.max_passengers >= %d
				ORDER BY (sz.zone_id IS NULL), v.sort, pr.provider_cents',
				$zone,
				$zone,
				$round ? 'round_trip' : 'one_way',
				$air,
				(int) $b['adults'] + (int) $b['children']
			),
			ARRAY_A
		);
		$out = array();
		foreach ( $rows as $r ) {
			$amount = null === $r['provider_cents'] ? null : (int) $r['provider_cents'];
			if ( null !== $amount && $round ) {
				$amount = intdiv( $amount, 2 );
			}
			$out[] = array(
				'service_id' => (int) $r['service_id'],
				'party_id'   => (int) $r['party_id'],
				'label'      => $r['provider'] . ' · ' . $r['vehicle'] . ' (up to ' . (int) $r['max_passengers'] . ')',
				'suggested'  => $amount,
				'current'    => (int) $r['service_id'] === (int) $leg['service_id'] && (int) $r['party_id'] === (int) $leg['party_id'],
			);
		}
		return $out;
	}

	public static function reassign( string $number, int $leg_id, int $service_id, int $amount_cents, bool $tell ) {
		global $wpdb;
		$b = self::booking( $number );
		if ( is_wp_error( $b ) ) {
			return $b;
		}
		$leg = self::leg_of( $b, $leg_id );
		if ( ! $leg ) {
			return new WP_Error( 'atc_not_found', 'Ride not found.', array( 'status' => 404 ) );
		}
		if ( 'confirmed' !== $b['status'] || ! in_array( $leg['status'], array( 'new', 'accepted', 'released' ), true ) ) {
			return new WP_Error( 'atc_closed', 'Only open rides of a paid booking can change provider.', array( 'status' => 409 ) );
		}
		$svc = $wpdb->get_row( $wpdb->prepare( 'SELECT id, party_id FROM ' . self::t( 'services' ) . ' WHERE id = %d', $service_id ), ARRAY_A );
		if ( ! $svc ) {
			return new WP_Error( 'atc_service', 'Pick a provider from the list.', array( 'status' => 400 ) );
		}
		if ( $amount_cents < 0 || $amount_cents > (int) $leg['retail_cents'] * 3 ) {
			return new WP_Error( 'atc_amount', 'Check the amount the provider gets.', array( 'status' => 400 ) );
		}
		$old = (int) $leg['party_id'];
		$new = (int) $svc['party_id'];
		$wpdb->update(
			self::t( 'booking_legs' ),
			array( 'party_id' => $new, 'service_id' => $service_id, 'provider_cents' => $amount_cents, 'status' => 'new', 'acknowledged_at' => null, 'driver_id' => null, 'vehicle_id' => null ),
			array( 'id' => $leg_id )
		);
		// Money: the old provider's line goes, a new one comes.
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t( 'ledger' ) . " SET status = 'cancelled', note = 'Ride given to another provider', updated_at = %s WHERE leg_id = %d AND role = 'provider' AND status = 'pending'", self::now(), $leg_id ) ); // phpcs:ignore
		$month = $leg['pickup_at'] ? substr( $leg['pickup_at'], 0, 7 ) : null;
		$wpdb->insert( self::t( 'ledger' ), array( 'booking_id' => (int) $b['id'], 'leg_id' => $leg_id, 'party_id' => $new, 'role' => 'provider', 'amount_cents' => $amount_cents, 'currency' => 'USD', 'status' => 'pending', 'service_month' => $month, 'created_at' => self::now(), 'updated_at' => self::now() ) );
		self::rebalance_platform( (int) $b['id'] );
		ATC_Install::log( 'booking', (int) $b['id'], 'provider_changed', array( 'leg' => $leg_id, 'from' => $old, 'to' => $new, 'amount' => $amount_cents ) );
		$b = ATC_Checkout::find( '', (int) $b['id'] );
		if ( $old && $old !== $new && 'released' !== $leg['status'] ) {
			ATC_Mail::provider_removed( $b, $old );
		}
		ATC_Mail::provider_new( $b );
		if ( $tell ) {
			ATC_Mail::traveler_provider_changed( $b );
		}
		return ATC_Bookings::get( $number );
	}

	/** Our share = what the traveler paid, minus refunds, minus what the providers get. */
	private static function rebalance_platform( int $booking_id ): void {
		global $wpdb;
		$l       = self::t( 'ledger' );
		$b       = ATC_Checkout::find( '', $booking_id );
		$paid    = (int) $b['total_cents'] - (int) ( $b['snap']['refunded'] ?? 0 );
		$prov    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(amount_cents),0) FROM {$l} WHERE booking_id = %d AND role = 'provider' AND status NOT IN ('cancelled')", $booking_id ) ); // phpcs:ignore
		$current = $wpdb->get_row( $wpdb->prepare( "SELECT id, status FROM {$l} WHERE booking_id = %d AND role = 'platform' AND status NOT IN ('cancelled') ORDER BY id DESC LIMIT 1", $booking_id ), ARRAY_A ); // phpcs:ignore
		if ( $current ) {
			$wpdb->update( $l, array( 'amount_cents' => $paid - $prov, 'updated_at' => self::now() ), array( 'id' => (int) $current['id'] ) );
		}
	}

	/* ---------- No-show ---------- */

	/** Pay the provider for the no-show, or don't (the ride then counts as not done). */
	public static function no_show_decision( string $number, int $leg_id, bool $pay, string $note ) {
		global $wpdb;
		$b = self::booking( $number );
		if ( is_wp_error( $b ) ) {
			return $b;
		}
		$leg = self::leg_of( $b, $leg_id );
		if ( ! $leg || 'no_show' !== $leg['status'] ) {
			return new WP_Error( 'atc_noshow', 'This ride has no no-show to decide.', array( 'status' => 409 ) );
		}
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t( 'ledger' ) . ' SET status = %s, note = %s, updated_at = %s WHERE leg_id = %d AND role = \'provider\' AND status = \'pending\'', $pay ? 'earned' : 'cancelled', $pay ? 'No-show accepted' : 'No-show rejected', self::now(), $leg_id ) ); // phpcs:ignore
		$wpdb->update( self::t( 'booking_legs' ), array( 'completed_how' => $pay ? 'no_show_paid' : 'no_show_rejected' ), array( 'id' => $leg_id ) );
		self::rebalance_platform( (int) $b['id'] );
		ATC_Install::log( 'booking', (int) $b['id'], $pay ? 'no_show_accepted' : 'no_show_rejected', array( 'leg' => $leg_id, 'note' => mb_substr( sanitize_textarea_field( $note ), 0, 500 ) ) );
		return ATC_Bookings::get( $number );
	}

	/* ---------- Service code ---------- */

	public static function unlock_code( string $number ) {
		global $wpdb;
		$b = self::booking( $number );
		if ( is_wp_error( $b ) ) {
			return $b;
		}
		$wpdb->update( self::t( 'bookings' ), array( 'service_code_attempts' => 0 ), array( 'id' => (int) $b['id'] ) );
		ATC_Install::log( 'booking', (int) $b['id'], 'code_unlocked' );
		return ATC_Bookings::get( $number );
	}

	/* ---------- Refund / cancel ---------- */

	/**
	 * Refund some or all of what the traveler paid. All of it (or $cancel) also cancels the booking:
	 * rides cancelled, provider money cancelled, emails to traveler and provider.
	 */
	public static function refund( string $number, int $amount_cents, bool $cancel, string $reason ) {
		global $wpdb;
		$b = self::booking( $number );
		if ( is_wp_error( $b ) ) {
			return $b;
		}
		if ( ! in_array( $b['status'], array( 'confirmed', 'completed' ), true ) ) {
			return new WP_Error( 'atc_refund', 'Only paid bookings can be refunded.', array( 'status' => 409 ) );
		}
		$left = (int) $b['total_cents'] - (int) ( $b['snap']['refunded'] ?? 0 );
		if ( $amount_cents < 0 || $amount_cents > $left ) {
			return new WP_Error( 'atc_refund', 'You can refund up to ' . ATC_Book::money( $left ) . '.', array( 'status' => 400 ) );
		}
		if ( ! $amount_cents && ! $cancel ) {
			return new WP_Error( 'atc_refund', 'Type an amount to refund, or tick cancel.', array( 'status' => 400 ) );
		}
		if ( $amount_cents ) {
			if ( empty( $b['stripe_payment_intent'] ) ) {
				return new WP_Error( 'atc_refund', 'This booking was not paid through Stripe here, so refund it where it was paid.', array( 'status' => 409 ) );
			}
			$r = ATC_Checkout::stripe( 'POST', 'refunds', array( 'payment_intent' => $b['stripe_payment_intent'], 'amount' => $amount_cents, 'reason' => 'requested_by_customer', 'metadata' => array( 'booking' => $b['number'], 'by' => (string) get_current_user_id() ) ) );
			if ( is_wp_error( $r ) ) {
				return new WP_Error( 'atc_refund', 'Stripe refused the refund: ' . $r->get_error_message(), array( 'status' => 502 ) );
			}
			$b['snap']['refunded'] = (int) ( $b['snap']['refunded'] ?? 0 ) + (int) $r['amount'];
			ATC_Checkout::snapshot( (int) $b['id'], $b['snap'] );
			$wpdb->insert( self::t( 'ledger' ), array( 'booking_id' => (int) $b['id'], 'party_id' => null, 'role' => 'refund', 'amount_cents' => -(int) $r['amount'], 'currency' => 'USD', 'status' => 'paid', 'note' => mb_substr( $reason, 0, 255 ) ?: 'Refund', 'created_at' => self::now(), 'updated_at' => self::now() ) );
			ATC_Install::log( 'booking', (int) $b['id'], 'refunded', array( 'amount' => (int) $r['amount'], 'reason' => $reason, 'stripe' => $r['id'] ?? '' ) );
		}
		$b = ATC_Checkout::find( '', (int) $b['id'] );
		if ( $cancel || (int) $b['snap']['refunded'] >= (int) $b['total_cents'] ) {
			if ( 'confirmed' === $b['status'] ) {
				ATC_Manage::cancelled( $b, $reason ?: 'Cancelled by our team', $amount_cents );
			}
		} else {
			self::rebalance_platform( (int) $b['id'] );
			ATC_Mail::refund_only( $b, $amount_cents );
		}
		return ATC_Bookings::get( $number );
	}

	public static function resend( string $number ) {
		$b = self::booking( $number );
		if ( is_wp_error( $b ) ) {
			return $b;
		}
		if ( ! in_array( $b['status'], array( 'confirmed', 'completed' ), true ) ) {
			return new WP_Error( 'atc_resend', 'Only paid bookings have a confirmation.', array( 'status' => 409 ) );
		}
		ATC_Mail::booking_confirmed( (int) $b['id'], true );
		ATC_Install::log( 'booking', (int) $b['id'], 'confirmation_resent' );
		return ATC_Bookings::get( $number );
	}
}
