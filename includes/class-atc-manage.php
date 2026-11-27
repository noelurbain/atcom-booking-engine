<?php
/**
 * Manage booking, for travelers: find a booking (the link goes to the email on it), change flight and trip
 * details, cancel with a full refund up to the free-cancellation time, and rate the provider afterwards.
 */

defined( 'ABSPATH' ) || exit;

final class ATC_Manage {

	private static function t( string $n ): string {
		return ATC_Schema::table( $n );
	}

	private static function now(): string {
		return current_time( 'mysql', true );
	}

	private static function free_seconds(): int {
		return (int) ATC_Settings::get( 'free_cancel_hours' ) * HOUR_IN_SECONDS;
	}

	/** A ride can still be changed or cancelled online until the free-cancellation time before its pickup. */
	public static function open_leg( array $leg ): bool {
		return ! empty( $leg['pickup_at'] ) && 'cancelled' !== $leg['status'] && strtotime( $leg['pickup_at'] . ' UTC' ) - time() > self::free_seconds();
	}

	public static function can_cancel( array $b ): bool {
		// Old-site reservations were paid there; cancelling and refunds stay with the team.
		return 'confirmed' === $b['status'] && empty( $b['snap']['legacy'] ) && ! empty( $b['legs'] ) && self::open_leg( $b['legs'][0] );
	}

	public static function can_change( array $b ): bool {
		if ( 'confirmed' !== $b['status'] ) {
			return false;
		}
		foreach ( $b['legs'] as $l ) {
			if ( self::open_leg( $l ) ) {
				return true;
			}
		}
		return false;
	}

	/* ---------- Find my booking ---------- */

	/** Number + email: if they match, the link goes to that email. The answer is the same either way. */
	public static function lookup( string $number, string $email ): array {
		if ( ATC_Partner::rate_ok( 'manage_lookup', 10 ) ) {
			$b = ATC_Checkout::find( $number );
			if ( $b && 'held' !== $b['status'] && '' !== $email && 0 === strcasecmp( trim( $email ), (string) $b['email'] ) ) {
				ATC_Mail::manage_link( $b );
			}
		}
		return array( 'ok' => true );
	}

	/* ---------- Change details ---------- */

	public static function update( string $number, string $key, array $in ) {
		global $wpdb;
		$b = ATC_Checkout::load( $number, $key );
		if ( ! $b ) {
			return new WP_Error( 'atc_not_found', 'We could not open that booking.', array( 'status' => 404 ) );
		}
		if ( ! self::can_change( $b ) ) {
			return new WP_Error( 'atc_closed', 'This booking can no longer be changed online.', array( 'status' => 409 ) );
		}
		$tz    = (string) ( $b['snap']['timezone'] ?? 'UTC' );
		$d     = (array) ( $b['snap']['details'] ?? array() );
		$s     = static fn( $k ) => trim( sanitize_text_field( (string) ( $in[ $k ] ?? '' ) ) );
		$date  = static fn( $v ) => preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ? $v : '';
		$time  = static fn( $v ) => preg_match( '/^\d{1,2}:\d{2}$/', $v ) ? $v : '';
		$flt   = static fn( $v ) => mb_substr( strtoupper( preg_replace( '/\s+/', '', $v ) ), 0, 20 );
		$chg   = array();
		$legs  = array();
		$limit = time() + self::free_seconds();

		// Arrival (airport to place), when the booking has one.
		$arr = ATC_Checkout::leg( $b, 'arrival' );
		$one = $b['legs'][0];
		if ( $arr && self::open_leg( $arr ) ) {
			$new = array(
				'airline'      => mb_substr( $s( 'airline' ), 0, 100 ),
				'flight'       => $flt( $s( 'flight' ) ),
				'landing_date' => $date( $s( 'landing_date' ) ) ?: (string) ( $d['landing_date'] ?? '' ),
				'landing_time' => $time( $s( 'landing_time' ) ) ?: (string) ( $d['landing_time'] ?? '' ),
			);
			$at = self::utc( $new['landing_date'], $new['landing_time'], $tz );
			if ( ! $at ) {
				return new WP_Error( 'atc_change', 'Check the landing date and time.', array( 'status' => 400 ) );
			}
			if ( strtotime( $at . ' UTC' ) < $limit ) {
				return new WP_Error( 'atc_change', 'The new landing time is too soon to change online.', array( 'status' => 400 ) );
			}
			if ( $at !== $arr['pickup_at'] ) {
				$chg[] = 'Landing: ' . ATC_Mail::local( $at, $tz );
			}
			if ( $new['flight'] !== (string) $arr['flight_number'] || $new['airline'] !== (string) $arr['flight_airline'] ) {
				$chg[] = 'Arrival flight: ' . ( trim( $new['airline'] . ' ' . $new['flight'] ) ?: 'not given yet' );
			}
			$legs[ (int) $arr['id'] ] = array( 'pickup_at' => $at, 'flight_time' => $at, 'flight_airline' => $new['airline'] ?: null, 'flight_number' => $new['flight'] ?: null );
			$d                        = array_merge( $d, $new, array( 'no_flight' => '' === $new['flight'] ) );
		}

		// Return.
		$ret = ATC_Checkout::leg( $b, 'departure' );
		if ( $ret && self::open_leg( $ret ) ) {
			$rd   = $date( $s( 'return_date' ) ) ?: (string) ( $d['return_date'] ?? '' );
			$pick = $time( $s( 'return_pickup' ) ) ?: (string) ( $d['return_pickup'] ?? '' );
			$ft   = $time( $s( 'return_flight_time' ) );
			$at   = self::utc( $rd, $pick, $tz );
			if ( ! $at ) {
				return new WP_Error( 'atc_change', 'Check the return date and pickup time.', array( 'status' => 400 ) );
			}
			if ( strtotime( $at . ' UTC' ) < $limit ) {
				return new WP_Error( 'atc_change', 'The new pickup time is too soon to change online.', array( 'status' => 400 ) );
			}
			if ( $arr && isset( $legs[ (int) $arr['id'] ] ) && strtotime( $at . ' UTC' ) <= strtotime( $legs[ (int) $arr['id'] ]['pickup_at'] . ' UTC' ) ) {
				return new WP_Error( 'atc_change', 'The return pickup must be after your arrival.', array( 'status' => 400 ) );
			}
			$from = mb_substr( $s( 'return_from' ), 0, 255 ) ?: (string) $ret['from_place'];
			$ral  = mb_substr( $s( 'return_airline' ), 0, 100 );
			$rfl  = $flt( $s( 'return_flight' ) );
			if ( $at !== $ret['pickup_at'] ) {
				$chg[] = ( $arr ? 'Return pickup: ' : 'Pickup: ' ) . ATC_Mail::local( $at, $tz );
			}
			if ( $from !== $ret['from_place'] ) {
				$chg[] = ( $arr ? 'Return pickup address: ' : 'Pickup address: ' ) . $from;
			}
			if ( $rfl !== (string) $ret['flight_number'] || $ral !== (string) $ret['flight_airline'] ) {
				$chg[] = 'Departure flight: ' . ( trim( $ral . ' ' . $rfl ) ?: 'not given yet' );
			}
			$legs[ (int) $ret['id'] ] = array( 'pickup_at' => $at, 'from_place' => $from, 'flight_airline' => $ral ?: null, 'flight_number' => $rfl ?: null, 'flight_time' => $ft ? self::utc( $rd, $ft, $tz ) : null );
			$d                        = array_merge( $d, array( 'return_date' => $rd, 'return_pickup' => $pick, 'return_flight_time' => $ft, 'return_airline' => $ral, 'return_flight' => $rfl, 'return_from' => $from ) );
		}

		// Shared details.
		$bags  = max( 0, min( 60, (int) ( $in['suitcases'] ?? $one['suitcases'] ) ) );
		$carry = max( 0, min( 60, (int) ( $in['carry_ons'] ?? $one['carry_ons'] ) ) );
		$notes = mb_substr( trim( sanitize_textarea_field( (string) ( $in['notes'] ?? '' ) ) ), 0, 1000 );
		$hname = mb_substr( $s( 'hotel_name' ), 0, 100 );
		if ( $bags !== (int) $one['suitcases'] || $carry !== (int) $one['carry_ons'] ) {
			$chg[] = 'Luggage: ' . $bags . ' suitcases, ' . $carry . ' carry-ons';
		}
		if ( $notes !== (string) ( $d['notes'] ?? '' ) ) {
			$chg[] = 'Notes for the driver: ' . ( $notes ?: '(none)' );
		}
		if ( $hname !== (string) ( $d['hotel_name'] ?? '' ) ) {
			$chg[] = 'Hotel booking name: ' . ( $hname ?: '(lead traveler)' );
		}
		if ( ! $chg ) {
			return array( 'changed' => array() );
		}
		foreach ( $b['legs'] as $l ) {
			$wpdb->update( self::t( 'booking_legs' ), ( $legs[ (int) $l['id'] ] ?? array() ) + array( 'suitcases' => $bags, 'carry_ons' => $carry, 'notes' => $notes ?: null ), array( 'id' => (int) $l['id'] ) );
		}
		$d['suitcases']       = $bags;
		$d['carry_ons']       = $carry;
		$d['notes']           = $notes;
		$d['hotel_name']      = $hname;
		$b['snap']['details'] = $d;
		ATC_Checkout::snapshot( (int) $b['id'], $b['snap'] );
		ATC_Install::log( 'booking', (int) $b['id'], 'changed_by_traveler', array( 'changes' => $chg ) );
		$b = ATC_Checkout::find( '', (int) $b['id'] );
		ATC_Mail::booking_changed( $b, $chg );
		return array( 'changed' => $chg );
	}

	private static function utc( string $date, string $time, string $tz ): ?string {
		if ( '' === $date || '' === $time ) {
			return null;
		}
		try {
			$x = new DateTime( $date . ' ' . $time, new DateTimeZone( $tz ?: 'UTC' ) );
			$x->setTimezone( new DateTimeZone( 'UTC' ) );
			return $x->format( 'Y-m-d H:i:s' );
		} catch ( Exception $e ) {
			return null;
		}
	}

	/* ---------- Cancel ---------- */

	public static function cancel( string $number, string $key ) {
		$b = ATC_Checkout::load( $number, $key );
		if ( ! $b ) {
			return new WP_Error( 'atc_not_found', 'We could not open that booking.', array( 'status' => 404 ) );
		}
		if ( ! self::can_cancel( $b ) ) {
			return new WP_Error( 'atc_closed', 'Free cancellation has ended for this booking, so it can’t be cancelled online.', array( 'status' => 409 ) );
		}
		$refund = 0;
		if ( ! empty( $b['stripe_payment_intent'] ) ) {
			$r = ATC_Checkout::stripe( 'POST', 'refunds', array( 'payment_intent' => $b['stripe_payment_intent'], 'reason' => 'requested_by_customer', 'metadata' => array( 'booking' => $b['number'] ) ) );
			if ( is_wp_error( $r ) ) {
				return new WP_Error( 'atc_refund', 'We could not refund the payment just now. Nothing was cancelled; please try again in a few minutes.', array( 'status' => 502 ) );
			}
			$refund = (int) ( $r['amount'] ?? 0 );
			// Extra payments made later (an upgrade) go back too.
			foreach ( ATC_Charges::all( $b ) as $c ) {
				if ( 'paid' === $c['status'] && '' !== $c['pi'] ) {
					$x = ATC_Checkout::stripe( 'POST', 'refunds', array( 'payment_intent' => $c['pi'], 'reason' => 'requested_by_customer', 'metadata' => array( 'booking' => $b['number'] ) ) );
					$refund += is_wp_error( $x ) ? 0 : (int) ( $x['amount'] ?? 0 );
				}
			}
			$b['snap']['refunded'] = (int) ( $b['snap']['refunded'] ?? 0 ) + $refund;
			ATC_Checkout::snapshot( (int) $b['id'], $b['snap'] );
			global $wpdb;
			$wpdb->insert( self::t( 'ledger' ), array( 'booking_id' => (int) $b['id'], 'party_id' => null, 'role' => 'refund', 'amount_cents' => -$refund, 'currency' => 'USD', 'status' => 'paid', 'note' => 'Cancelled by the traveler', 'created_at' => self::now(), 'updated_at' => self::now() ) );
		}
		self::cancelled( $b, 'Cancelled by the traveler', $refund );
		return array( 'cancelled' => true, 'refund' => $refund );
	}

	/** Mark a booking cancelled (once): rides, money lines, emails. Also used when a refund is made in Stripe. */
	public static function cancelled( array $b, string $why, int $refund = -1 ): void {
		global $wpdb;
		$done = $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t( 'bookings' ) . " SET status = 'cancelled', updated_at = %s WHERE id = %d AND status = 'confirmed'", self::now(), (int) $b['id'] ) ); // phpcs:ignore
		if ( ! $done ) {
			return;
		}
		$wpdb->update( self::t( 'booking_legs' ), array( 'status' => 'cancelled' ), array( 'booking_id' => (int) $b['id'] ) );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t( 'ledger' ) . " SET status = 'cancelled', updated_at = %s WHERE booking_id = %d AND status = 'pending'", self::now(), (int) $b['id'] ) ); // phpcs:ignore
		$refund = $refund < 0 ? (int) $b['total_cents'] : $refund;
		ATC_Install::log( 'booking', (int) $b['id'], 'cancelled', array( 'why' => $why, 'refund' => $refund ) );
		$b = ATC_Checkout::find( '', (int) $b['id'] );
		ATC_Mail::booking_cancelled( $b, $refund );
	}

	/* ---------- Reviews ---------- */

	public static function reviewed( int $booking_id ): bool {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::t( 'reviews' ) . ' WHERE booking_id = %d', $booking_id ) );
	}

	/** Can rate once the first ride's pickup time has passed. */
	public static function can_review( array $b ): bool {
		$first = $b['legs'][0]['pickup_at'] ?? null;
		return in_array( $b['status'], array( 'confirmed', 'completed' ), true ) && $first && strtotime( $first . ' UTC' ) < time() && ! self::reviewed( (int) $b['id'] );
	}

	/** The traveler reports a problem, or answers in a ticket they already have. */
	public static function ticket( string $number, string $key, array $p ) {
		$b = ATC_Checkout::load( $number, $key );
		if ( ! $b || 'held' === $b['status'] ) {
			return new WP_Error( 'atc_not_found', 'We could not open that booking.', array( 'status' => 404 ) );
		}
		$body = (string) ( $p['body'] ?? '' );
		if ( ! empty( $p['id'] ) ) {
			$id = (int) $p['id'];
			if ( ATC_Tickets::booking_of( $id ) !== (int) $b['id'] ) {
				return new WP_Error( 'atc_not_found', 'Conversation not found.', array( 'status' => 404 ) );
			}
			$r = ATC_Tickets::reply( $id, 'traveler', $body );
			return is_wp_error( $r ) ? $r : array( 'ok' => true );
		}
		$allowed = array( 'no_show', 'wrong_vehicle', 'overcharge', 'refund', 'schedule', 'lost_item', 'complaint', 'other' );
		$id      = ATC_Tickets::open(
			array(
				'booking_id'  => (int) $b['id'],
				'type'        => in_array( $p['type'] ?? '', $allowed, true ) ? $p['type'] : 'other',
				'body'        => $body,
				'by'          => 'traveler',
				'author_name' => trim( $b['first_name'] . ' ' . $b['last_name'] ),
			)
		);
		return is_wp_error( $id ) ? $id : array( 'ok' => true, 'id' => $id );
	}

	public static function review( string $number, string $key, int $rating, string $comment ) {
		global $wpdb;
		$b = ATC_Checkout::load( $number, $key );
		if ( ! $b ) {
			return new WP_Error( 'atc_not_found', 'We could not open that booking.', array( 'status' => 404 ) );
		}
		if ( ! self::can_review( $b ) ) {
			return new WP_Error( 'atc_review', 'This booking can’t be rated now.', array( 'status' => 409 ) );
		}
		$rating = max( 1, min( 5, $rating ) );
		$pid    = (int) $b['legs'][0]['party_id'];
		$wpdb->insert( self::t( 'reviews' ), array( 'booking_id' => (int) $b['id'], 'party_id' => $pid, 'rating' => $rating, 'comment' => mb_substr( trim( sanitize_textarea_field( $comment ) ), 0, 1000 ) ?: null, 'status' => 'published', 'created_at' => self::now() ) );
		$avg = $wpdb->get_row( $wpdb->prepare( 'SELECT AVG(rating) a, COUNT(*) n FROM ' . self::t( 'reviews' ) . " WHERE party_id = %d AND status = 'published'", $pid ), ARRAY_A );
		$wpdb->update( self::t( 'parties' ), array( 'rating_avg' => round( (float) $avg['a'], 2 ), 'rating_count' => (int) $avg['n'] ), array( 'id' => $pid ) );
		ATC_Install::log( 'booking', (int) $b['id'], 'reviewed', array( 'rating' => $rating ) );
		if ( $rating <= 2 ) {
			ATC_Tickets::low_rating( $b, $rating, $comment );
		}
		$invite = self::public_review( $b, $rating );
		if ( $invite ) {
			ATC_Mail::public_review_invite( $b, $invite );
		}
		return array( 'ok' => true );
	}

	/**
	 * After a provider rating: where we invite the traveler to review Airport Transportation in public
	 * (Google or TripAdvisor, alternating by booking), or null when the rating is under the setting.
	 * @return array{site:string,url:string}|null
	 */
	public static function public_review( array $b, int $rating ): ?array {
		if ( $rating < (int) ATC_Settings::get( 'review_invite_min' ) ) {
			return null;
		}
		$g     = (string) ATC_Settings::get( 'review_google_url' );
		$t     = (string) ATC_Settings::get( 'review_tripadvisor_url' );
		$sites = array_values( array_filter( array( $g ? array( 'site' => 'Google', 'url' => $g ) : null, $t ? array( 'site' => 'Tripadvisor', 'url' => $t ) : null ) ) );
		return $sites ? $sites[ (int) $b['id'] % count( $sites ) ] : null;
	}

	/** The traveler's rating for this booking, or 0. */
	public static function rating_of( int $booking_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT rating FROM ' . self::t( 'reviews' ) . ' WHERE booking_id = %d', $booking_id ) );
	}
}
